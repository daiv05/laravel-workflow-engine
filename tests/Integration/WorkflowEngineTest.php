<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class WorkflowEngineTest extends TestCase
{
    public function test_start_can_execute_end_to_end(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved', 'rejected'],
            'states' => ['draft', 'hr_review', 'approved', 'rejected'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'hr_review',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
                [
                    'from' => 'hr_review',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => ['fn' => 'isHR'],
                    'effects' => [
                        ['event' => 'request_approved'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('termination_request');

        $this->assertSame('draft', $instance['state']);
        $this->assertSame(0, $instance['version']);
        $this->assertTrue($engine->can($instance['instance_id'], 'submit', ['roles' => []]));

        $afterCan = $storage->getInstance($instance['instance_id']);
        $this->assertSame('draft', $afterCan['state']);
        $this->assertSame(0, $afterCan['version']);

        $afterSubmit = $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);
        $this->assertSame('hr_review', $afterSubmit['state']);
        $this->assertSame(1, $afterSubmit['version']);

        $this->assertFalse($engine->can($instance['instance_id'], 'approve', ['roles' => []]));
        $this->assertTrue($engine->can($instance['instance_id'], 'approve', ['roles' => ['HR']]));

        $afterApprove = $engine->execute($instance['instance_id'], 'approve', ['roles' => ['HR']]);
        $this->assertSame('approved', $afterApprove['state']);
        $this->assertSame(2, $afterApprove['version']);
    }

    public function test_final_state_cannot_transition_even_if_definition_contains_outgoing_transition(): void
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
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('closure_flow', [
            'dsl_version' => 2,
            'name' => 'closure_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved', 'reopened'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                ],
                [
                    'from' => 'approved',
                    'to' => 'reopened',
                    'action' => 'reopen',
                    'transition_id' => 'tr_reopen',
                    'allowed_if' => [],
                    'fields' => [
                        'visible' => ['reason'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('closure_flow');
        $approved = $engine->execute($instance['instance_id'], 'approve');

        $this->assertSame('approved', $approved['state']);
        $this->assertFalse($engine->can($instance['instance_id'], 'reopen'));
        $this->assertSame([], $engine->visibleFields($instance['instance_id']));

        $this->expectException(InvalidTransitionException::class);
        $engine->execute($instance['instance_id'], 'reopen');
    }

    public function test_it_exposes_available_actions_and_history_for_instance(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'hr_review', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'hr_review',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
                [
                    'from' => 'hr_review',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => ['fn' => 'isHR'],
                ],
            ],
        ]);

        $instance = $engine->start('termination_request');

        $this->assertSame(['submit'], $engine->availableActions($instance['instance_id'], ['roles' => []]));

        $afterSubmit = $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);
        $this->assertSame('hr_review', $afterSubmit['state']);

        $this->assertSame([], $engine->availableActions($instance['instance_id'], ['roles' => []]));
        $this->assertSame(['approve'], $engine->availableActions($instance['instance_id'], ['roles' => ['HR']]));

        $history = $engine->history($instance['instance_id']);

        $this->assertCount(1, $history);
        $this->assertSame('submit', $history[0]['action']);
        $this->assertSame('draft', $history[0]['from_state']);
        $this->assertSame('hr_review', $history[0]['to_state']);
    }

    public function test_it_emits_diagnostics_for_transition_success_and_failure(): void
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
        $diagnostics = new RecordingDiagnosticsEmitter();
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, $diagnostics);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('diagnostic_flow', [
            'dsl_version' => 2,
            'name' => 'diagnostic_flow',
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
                ],
            ],
        ]);

        $instance = $engine->start('diagnostic_flow');
        $engine->execute($instance['instance_id'], 'finish');

        try {
            $engine->execute($instance['instance_id'], 'reopen');
            $this->fail('Expected InvalidTransitionException was not thrown');
        } catch (InvalidTransitionException) {
            $this->assertTrue(true);
        }

        $this->assertCount(2, $diagnostics->events);
        $this->assertSame('transition.executed', $diagnostics->events[0]['event']);
        $this->assertSame('transition.failed', $diagnostics->events[1]['event']);
        $this->assertSame(3001, $diagnostics->events[1]['payload']['exception']['exception_code']);
    }

    public function test_execution_builder_runs_inline_listeners_and_lifecycle_hooks(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new InMemoryWorkflowRepository();
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $events = new Dispatcher('workflow.event.');
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'hr_review', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'hr_review',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
                [
                    'from' => 'hr_review',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => ['fn' => 'isHR'],
                    'effects' => [
                        ['event' => 'request_approved'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('termination_request');
        $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);

        $beforeCalls = [];
        $namedEvents = [];
        $anyEvents = [];
        $afterCalls = [];

        $result = $engine->execution($instance['instance_id'])
            ->before(static function (string $action, array $context, string $instanceId) use (&$beforeCalls): void {
                $beforeCalls[] = [$action, $context['roles'] ?? [], $instanceId];
            })
            ->on('request_approved', static function (array $payload) use (&$namedEvents): void {
                $namedEvents[] = $payload['transition_id'] ?? '';
            })
            ->onAny(static function (string $event, array $payload) use (&$anyEvents): void {
                $anyEvents[] = [$event, $payload['action'] ?? ''];
            })
            ->after(static function (string $action, array $context, array $updated, string $instanceId) use (&$afterCalls): void {
                $afterCalls[] = [$action, $context['roles'] ?? [], $updated['state'] ?? '', $instanceId];
            })
            ->execute('approve', ['roles' => ['HR']]);

        $this->assertSame('approved', $result['state']);
        $this->assertCount(1, $beforeCalls);
        $this->assertCount(1, $namedEvents);
        $this->assertCount(1, $anyEvents);
        $this->assertCount(1, $afterCalls);
        $this->assertSame('tr_approve', $namedEvents[0]);
        $this->assertSame('request_approved', $anyEvents[0][0]);
    }

    public function test_inline_listener_exception_can_be_silenced(): void
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
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events, null, true);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('simple_flow', [
            'dsl_version' => 2,
            'name' => 'simple_flow',
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
                    'effects' => [
                        ['event' => 'finished'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('simple_flow');

        $result = $engine->execution($instance['instance_id'])
            ->on('finished', static function (): void {
                throw new \RuntimeException('listener exploded');
            })
            ->execute('finish');

        $this->assertSame('done', $result['state']);
        $this->assertCount(1, $events->dispatchedEvents());
        $this->assertSame('workflow.event.finished', $events->dispatchedEvents()[0]['name']);
    }

    public function test_inline_listener_exception_bubbles_by_default(): void
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
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('simple_flow', [
            'dsl_version' => 2,
            'name' => 'simple_flow',
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
                    'effects' => [
                        ['event' => 'finished'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('simple_flow');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('listener exploded');

        $engine->execution($instance['instance_id'])
            ->on('finished', static function (): void {
                throw new \RuntimeException('listener exploded');
            })
            ->execute('finish');
    }

    public function test_effect_meta_and_context_are_available_in_inline_and_global_event_payload(): void
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
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $events);

        $engine = new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions
        );

        $engine->activateDefinition('meta_flow', [
            'dsl_version' => 2,
            'name' => 'meta_flow',
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
                    'effects' => [
                        [
                            'event' => 'finished',
                            'meta' => [
                                'integration' => [
                                    'stream' => 'audit',
                                    'version' => 1,
                                ],
                                'tags' => ['workflow', 'sync'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('meta_flow');
        $capturedInlinePayload = null;

        $context = [
            'roles' => ['HR'],
            'trace_id' => 'trace-123',
            'actor' => 'u-1',
        ];

        $engine->execution($instance['instance_id'])
            ->on('finished', static function (array $payload) use (&$capturedInlinePayload): void {
                $capturedInlinePayload = $payload;
            })
            ->execute('finish', $context);

        $this->assertIsArray($capturedInlinePayload);
        $this->assertSame('tr_finish', $capturedInlinePayload['transition_id']);
        $this->assertSame($context, $capturedInlinePayload['context']);
        $this->assertSame('audit', $capturedInlinePayload['meta']['integration']['stream']);
        $this->assertSame(['workflow', 'sync'], $capturedInlinePayload['meta']['tags']);

        $dispatched = $events->dispatchedEvents();
        $this->assertCount(1, $dispatched);
        $this->assertSame('workflow.event.finished', $dispatched[0]['name']);
        $this->assertSame($context, $dispatched[0]['payload']['context']);
        $this->assertSame('audit', $dispatched[0]['payload']['meta']['integration']['stream']);
    }
}

class RecordingDiagnosticsEmitter implements DiagnosticsEmitterInterface
{
    /** @var array<int, array{event: string, payload: array<string, mixed>}> */
    public array $events = [];

    public function emit(string $eventName, array $payload = []): void
    {
        $this->events[] = [
            'event' => $eventName,
            'payload' => $payload,
        ];
    }
}
