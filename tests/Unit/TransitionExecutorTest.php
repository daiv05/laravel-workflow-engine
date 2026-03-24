<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionValidationException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class TransitionExecutorTest extends TestCase
{
    public function test_execute_delegates_to_execute_with_listeners(): void
    {
        $stateMachine = $this->createMock(StateMachine::class);
        $policy = $this->createMock(PolicyEngine::class);
        $storage = $this->createMock(StorageRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $executor = $this->getMockBuilder(TransitionExecutor::class)
            ->setConstructorArgs([$stateMachine, $policy, $storage, $events])
            ->onlyMethods(['executeWithListeners'])
            ->getMock();

        $instance = ['instance_id' => 'iid-1', 'state' => 'draft'];
        $definition = ['name' => 'wf'];

        $executor->expects($this->once())
            ->method('executeWithListeners')
            ->with($instance, $definition, 'approve', ['roles' => ['HR']], [])
            ->willReturn(['instance_id' => 'iid-1', 'state' => 'approved']);

        $result = $executor->execute($instance, $definition, 'approve', ['roles' => ['HR']]);

        $this->assertSame('approved', $result['state']);
    }

    public function test_execute_with_listeners_uses_workflow_name_fallback_for_diagnostics(): void
    {
        $functions = new FunctionRegistry();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $stateMachine = new StateMachine();
        $storage = new InMemoryWorkflowRepository();
        $events = new Dispatcher('workflow.event.');
        $diagnostics = new RecordingDiagnosticsEmitter();

        $definitionId = $storage->activateDefinition('legacy_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [],
        ]);

        $instance = $storage->createInstance([
            'instance_id' => 'iid-dx',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-default',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
        ]);

        $definition = [
            'workflow_name' => 'legacy_flow',
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transition_index' => ['draft::finish' => [
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
            ]],
            'transitions' => [[
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
            ]],
        ];

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, $diagnostics);

        $updated = $executor->executeWithListeners($instance, $definition, 'finish', []);

        $this->assertSame('done', $updated['state']);
        $this->assertCount(1, $diagnostics->events);
        $this->assertSame('transition.executed', $diagnostics->events[0]['event']);
        $this->assertSame('legacy_flow', $diagnostics->events[0]['payload']['workflow_name']);
    }

    public function test_execute_with_listeners_swallows_inline_listener_failures_when_fail_silently_enabled(): void
    {
        $functions = new FunctionRegistry();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $stateMachine = new StateMachine();
        $storage = new InMemoryWorkflowRepository();
        $events = new Dispatcher('workflow.event.');

        $definitionId = $storage->activateDefinition('listener_silent', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [],
        ]);

        $instance = $storage->createInstance([
            'instance_id' => 'iid-listener-silent',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-default',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
        ]);

        $definition = [
            'name' => 'listener_silent',
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transition_index' => ['draft::finish' => [
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
                'effects' => [['event' => 'finished']],
            ]],
            'transitions' => [[
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
                'effects' => [['event' => 'finished']],
            ]],
        ];

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, null, true);

        $updated = $executor->executeWithListeners($instance, $definition, 'finish', [], [
            'any' => [
                static function (): void {
                    throw new \RuntimeException('should be swallowed');
                },
            ],
        ]);

        $this->assertSame('done', $updated['state']);
        $this->assertCount(1, $events->dispatchedEvents());
        $this->assertSame('workflow.event.finished', $events->dispatchedEvents()[0]->fullEventName('workflow.event.'));
    }

    public function test_execute_with_listeners_rejects_required_field_when_merged_value_is_null(): void
    {
        $functions = new FunctionRegistry();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $stateMachine = new StateMachine();
        $storage = new InMemoryWorkflowRepository();
        $events = new Dispatcher('workflow.event.');

        $definitionId = $storage->activateDefinition('required_guard', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [],
        ]);

        $instance = $storage->createInstance([
            'instance_id' => 'iid-required',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-default',
            'state' => 'draft',
            'data' => ['comment' => null],
            'version' => 0,
        ]);

        $definition = [
            'name' => 'required_guard',
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transition_index' => ['draft::finish' => [
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
                'validation' => ['required' => ['comment']],
            ]],
            'transitions' => [[
                'from' => 'draft',
                'to' => 'done',
                'action' => 'finish',
                'transition_id' => 'tr_finish',
                'allowed_if' => [],
                'validation' => ['required' => ['comment']],
            ]],
        ];

        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $this->expectException(InvalidTransitionValidationException::class);

        try {
            $executor->executeWithListeners($instance, $definition, 'finish', []);
        } finally {
            $persisted = $storage->getInstance('iid-required');
            $this->assertSame('draft', $persisted['state']);
            $this->assertSame(0, $persisted['version']);
            $this->assertSame([], $storage->getHistory('iid-required'));
        }
    }
}

class RecordingDiagnosticsEmitter implements DiagnosticsEmitterInterface
{
    /** @var array<int, array{event: string, payload: array<string, mixed>}> */
    public array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload = []): void
    {
        $this->events[] = [
            'event' => $event,
            'payload' => $payload,
        ];
    }
}
