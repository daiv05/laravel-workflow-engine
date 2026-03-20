<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;
use Daiv05\LaravelWorkflowEngine\DataMapping\DataMapper;
use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class WorkflowEngineDataMappingTest extends TestCase
{
    public function test_execute_applies_mappings_and_stores_safe_history_payload(): void
    {
        $functions = new FunctionRegistry();
        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $mapper = new DataMapper([
            'documents' => [
                'handler' => IntegrationDocumentMapper::class,
                'query_handler' => IntegrationDocumentMapper::class,
            ],
        ]);

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, null, false, $mapper);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions,
            $events,
            null,
            true,
            300,
            $mapper
        );

        $engine->activateDefinition('mapping_flow', [
            'dsl_version' => 2,
            'name' => 'mapping_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish',
                    'allowed_if' => [],
                    'mappings' => [
                        'comment' => ['type' => 'attribute'],
                        'documents' => ['type' => 'relation', 'target' => 'documents'],
                        'document_ids' => ['type' => 'attach', 'target' => 'documents'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('mapping_flow');

        $result = $engine->execute($instance['instance_id'], 'finish', [
            'actor' => 'user-1',
            'roles' => ['reviewer'],
            'data' => [
                'comment' => 'approved',
                'documents' => [
                    ['id' => 100],
                    ['id' => 101],
                ],
                'document_ids' => [100, 101],
            ],
            'user' => ['id' => 77],
        ]);

        $this->assertSame('done', $result['state']);
        $this->assertSame('approved', $result['data']['comment']);
        $this->assertSame([100, 101], $result['data']['documents']);
        $this->assertSame([100, 101], $result['data']['document_ids']);

        $history = $engine->history($instance['instance_id']);
        $this->assertCount(1, $history);

        $payload = $history[0]['payload'];
        $this->assertSame('tr_finish', $payload['transition_id']);
        $this->assertSame(true, $payload['context']['has_data']);
        $this->assertSame(['comment', 'documents', 'document_ids'], $payload['context']['data_keys']);
        $this->assertArrayHasKey('mapping_summary', $payload);
        $this->assertArrayNotHasKey('user', $payload['context']);

        $resolved = $engine->resolveMappedData($instance['instance_id'], 'finish');
        $this->assertSame([
            ['id' => 100, 'label' => 'doc-100'],
            ['id' => 101, 'label' => 'doc-101'],
        ], $resolved['documents']);
    }

    public function test_execute_requires_context_data_when_transition_has_mappings(): void
    {
        $functions = new FunctionRegistry();
        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $mapper = new DataMapper();

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, null, false, $mapper);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions,
            $events,
            null,
            true,
            300,
            $mapper
        );

        $engine->activateDefinition('mapping_flow', [
            'dsl_version' => 2,
            'name' => 'mapping_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish',
                    'allowed_if' => [],
                    'mappings' => [
                        'comment' => ['type' => 'attribute'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('mapping_flow');

        $this->expectException(ContextValidationException::class);
        $engine->execute($instance['instance_id'], 'finish', ['actor' => 'user-1']);
    }

    public function test_execute_rolls_back_when_mapping_handler_fails(): void
    {
        $functions = new FunctionRegistry();
        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $mapper = new DataMapper([
            'documents' => [
                'handler' => FailingDocumentMapper::class,
            ],
        ]);

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, null, false, $mapper);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions,
            $events,
            null,
            true,
            300,
            $mapper
        );

        $engine->activateDefinition('mapping_flow', [
            'dsl_version' => 2,
            'name' => 'mapping_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish',
                    'allowed_if' => [],
                    'mappings' => [
                        'documents' => ['type' => 'relation', 'target' => 'documents'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('mapping_flow');

        try {
            $engine->execute($instance['instance_id'], 'finish', [
                'data' => [
                    'documents' => [['id' => 22]],
                ],
            ]);
            $this->fail('Expected WorkflowException was not thrown');
        } catch (WorkflowException $exception) {
            $this->assertSame('forced mapping failure', $exception->getMessage());
        }

        $persisted = $storage->getInstance($instance['instance_id']);
        $this->assertSame('draft', $persisted['state']);
        $this->assertSame(0, $persisted['version']);
        $this->assertCount(0, $engine->history($instance['instance_id']));
    }
}

class IntegrationDocumentMapper implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        if (!is_array($value)) {
            return ['references' => []];
        }

        $references = [];

        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('id', $item)) {
                $references[] = $item['id'];
            }
        }

        return ['references' => $references];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        $references = $context['value'] ?? [];

        if (!is_array($references)) {
            return [];
        }

        $result = [];

        foreach ($references as $id) {
            $result[] = [
                'id' => $id,
                'label' => 'doc-' . $id,
            ];
        }

        return $result;
    }
}

class FailingDocumentMapper implements MappingHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        throw new WorkflowException('forced mapping failure');
    }
}
