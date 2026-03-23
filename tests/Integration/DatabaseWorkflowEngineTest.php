<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\UpdateExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Events\WorkflowEvent;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseOutboxStore;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseWorkflowRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class DatabaseWorkflowEngineTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_name');
            $table->unsignedInteger('version');
            $table->string('tenant_id')->nullable();
            $table->unsignedInteger('dsl_version')->default(2);
            $table->longText('definition');
            $table->boolean('is_active')->default(false);
            $table->string('active_scope')->nullable();
            $table->timestamps();

            $table->unique(['workflow_name', 'version', 'tenant_id'], 'wf_def_unique_name_version_tenant');
            $table->unique(['active_scope'], 'wf_def_unique_active_scope');
            $table->index(['workflow_name', 'tenant_id', 'is_active'], 'wf_def_active_lookup');
        });

        $schema->create('workflow_instances', function (Blueprint $table): void {
            $table->uuid('instance_id')->primary();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions');
            $table->string('tenant_id')->nullable();
            $table->string('state');
            $table->json('data');
            $table->unsignedInteger('version')->default(0);
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wf_instance_subject_lookup_idx');
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'wf_instance_definition_subject_idx');
        });

        $schema->create('workflow_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('instance_id');
            $table->string('transition_id');
            $table->string('action');
            $table->string('from_state');
            $table->string('to_state');
            $table->string('actor')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('instance_id')->references('instance_id')->on('workflow_instances')->cascadeOnDelete();
        });

        $schema->create('workflow_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts', 'created_at'], 'wf_outbox_status_attempts_created_idx');
        });
    }

    public function test_database_engine_full_flow_persists_history_and_dispatches_after_commit(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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
        ], 'tenant-a');

        $instance = $engine->start('termination_request', ['tenant_id' => 'tenant-a']);
        $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);
        $result = $engine->execute($instance['instance_id'], 'approve', ['roles' => ['HR']]);

        $this->assertSame('approved', $result['state']);

        $histories = $this->capsule->getConnection()->table('workflow_histories')->get();
        $this->assertCount(2, $histories);

        $events = $dispatcher->dispatchedEvents();
        $this->assertCount(2, $events);
        $eventNames = array_map(static fn ($event): string => $event->fullEventName('workflow.event.'), $events);
        $this->assertSame(['workflow.event.instance_started', 'workflow.event.request_approved'], $eventNames);
    }

    public function test_database_engine_rolls_back_and_does_not_dispatch_when_queue_fails(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new FailingQueueDispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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
        $afterSubmit = $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);

        try {
            $engine->execute($instance['instance_id'], 'approve', ['roles' => ['HR']]);
            $this->fail('Expected WorkflowException was not thrown');
        } catch (WorkflowException $exception) {
            $this->assertSame('queue failed before commit', $exception->getMessage());
        }

        $persisted = $storage->getInstance($instance['instance_id']);
        $this->assertSame($afterSubmit['state'], $persisted['state']);
        $this->assertSame($afterSubmit['version'], $persisted['version']);

        $dispatchedNames = array_map(
            static fn ($event): string => $event->fullEventName('workflow.event.'),
            $dispatcher->dispatchedEvents()
        );

        $this->assertSame(['workflow.event.instance_started', 'workflow.event.transition_failed'], $dispatchedNames);
        $this->assertNotContains('workflow.event.request_approved', $dispatchedNames);
    }

    public function test_database_engine_uses_static_tenant_scope_and_rejects_duplicate_version(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('onboarding', [
            'dsl_version' => 2,
            'name' => 'onboarding',
            'version' => 1,
            'initial_state' => 'tenant_a_start',
            'final_states' => ['done'],
            'states' => ['tenant_a_start', 'done'],
            'transitions' => [
                [
                    'from' => 'tenant_a_start',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_a',
                    'allowed_if' => [],
                ],
            ],
        ], 'tenant-a');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Workflow definition version is immutable and already exists for scope');

        // With static tenant mode, tenant input is ignored and both activations share one scope.
        $engine->activateDefinition('onboarding', [
            'dsl_version' => 2,
            'name' => 'onboarding',
            'version' => 1,
            'initial_state' => 'tenant_b_start',
            'final_states' => ['done'],
            'states' => ['tenant_b_start', 'done'],
            'transitions' => [
                [
                    'from' => 'tenant_b_start',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_b',
                    'allowed_if' => [],
                ],
            ],
        ], 'tenant-b');
    }

    public function test_existing_instance_keeps_original_workflow_definition_id_after_new_version_activation(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $version1Id = $engine->activateDefinition('onboarding', [
            'dsl_version' => 2,
            'name' => 'onboarding',
            'version' => 1,
            'initial_state' => 'draft_v1',
            'final_states' => ['done'],
            'states' => ['draft_v1', 'done'],
            'transitions' => [
                [
                    'from' => 'draft_v1',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_v1',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $instanceV1 = $engine->start('onboarding');

        $version2Id = $engine->activateDefinition('onboarding', [
            'dsl_version' => 2,
            'name' => 'onboarding',
            'version' => 2,
            'initial_state' => 'draft_v2',
            'final_states' => ['done'],
            'states' => ['draft_v2', 'done'],
            'transitions' => [
                [
                    'from' => 'draft_v2',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_v2',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $instanceV2 = $engine->start('onboarding');

        $persistedV1 = $storage->getInstance($instanceV1['instance_id']);
        $persistedV2 = $storage->getInstance($instanceV2['instance_id']);

        $this->assertSame($version1Id, $persistedV1['workflow_definition_id']);
        $this->assertSame($version2Id, $persistedV2['workflow_definition_id']);
        $this->assertNotSame($persistedV1['workflow_definition_id'], $persistedV2['workflow_definition_id']);
    }

    public function test_database_engine_persists_and_marks_outbox_events_after_commit(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $connection = $this->capsule->getConnection();
        $storage = new DatabaseWorkflowRepository($connection);
        $outbox = new DatabaseOutboxStore($connection);
        $dispatcher = new Dispatcher('workflow.event.', null, $outbox);
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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
        $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);
        $engine->execute($instance['instance_id'], 'approve', ['roles' => ['HR']]);

        $outboxRows = $connection->table('workflow_outbox')->get();

        $this->assertCount(2, $outboxRows);
        $eventNames = [];
        foreach ($outboxRows as $row) {
            $eventNames[] = (string) $row->event_name;
        }
        $this->assertSame(['workflow.event.instance_started', 'workflow.event.request_approved'], $eventNames);
        $this->assertSame('dispatched', $outboxRows[1]->status);
        $this->assertNotNull($outboxRows[1]->dispatched_at);
    }

    public function test_database_engine_includes_effect_meta_and_context_in_outbox_payload(): void
    {
        $functions = new FunctionRegistry();

        $connection = $this->capsule->getConnection();
        $storage = new DatabaseWorkflowRepository($connection);
        $outbox = new DatabaseOutboxStore($connection);
        $dispatcher = new Dispatcher('workflow.event.', null, $outbox);
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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
                                'bus' => 'analytics',
                                'topic' => 'workflow.finished',
                                'flags' => ['replayable' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('meta_flow');
        $context = ['trace_id' => 'trace-777', 'actor' => 'user-5'];
        $engine->execute($instance['instance_id'], 'finish', $context);

        $outboxRows = $connection->table('workflow_outbox')->get();
        $this->assertCount(2, $outboxRows);

        $this->assertSame('workflow.event.instance_started', (string) $outboxRows[0]->event_name);
        $this->assertSame('workflow.event.finished', (string) $outboxRows[1]->event_name);

        $payload = json_decode((string) $outboxRows[1]->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($context, $payload['context']);
        $this->assertSame('analytics', $payload['meta']['bus']);
        $this->assertSame(true, $payload['meta']['flags']['replayable']);
    }

    public function test_database_engine_blocks_transitions_from_final_state_even_if_configured(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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

    public function test_database_engine_exposes_available_actions_and_history(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

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

        $engine->execute($instance['instance_id'], 'submit', ['roles' => []]);

        $this->assertSame([], $engine->availableActions($instance['instance_id'], ['roles' => []]));
        $this->assertSame(['approve'], $engine->availableActions($instance['instance_id'], ['roles' => ['HR']]));

        $history = $engine->history($instance['instance_id']);

        $this->assertCount(1, $history);
        $this->assertSame('submit', $history[0]['action']);
        $this->assertSame('draft', $history[0]['from_state']);
        $this->assertSame('hr_review', $history[0]['to_state']);
        $this->assertArrayHasKey('payload', $history[0]);
    }

    public function test_database_engine_updates_data_in_place_and_records_update_history(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isOwner', static fn (array $context): bool => (($context['actor'] ?? null) === (($context['subject']['subject_id'] ?? null))));

        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('draft_updates', [
            'dsl_version' => 2,
            'name' => 'draft_updates',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => [
                [
                    'name' => 'draft',
                    'permissions' => [
                        'update' => [
                            'allowed_if' => ['fn' => 'isOwner'],
                        ],
                    ],
                    'fields' => [
                        'comment' => ['editable' => true],
                    ],
                ],
                'approved',
            ],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $instance = $engine->start('draft_updates', [
            'subject' => ['subject_type' => 'user', 'subject_id' => 'owner-1'],
            'data' => ['comment' => 'initial'],
        ]);

        $updated = $engine->update($instance['instance_id'], [
            'actor' => 'owner-1',
            'data' => ['comment' => 'updated'],
        ]);

        $this->assertSame('draft', $updated['state']);
        $this->assertSame('updated', $updated['data']['comment']);
        $this->assertSame(1, $updated['version']);

        $history = $engine->history($instance['instance_id']);
        $this->assertCount(1, $history);
        $this->assertSame('update', $history[0]['action']);
        $this->assertSame('draft', $history[0]['from_state']);
        $this->assertSame('draft', $history[0]['to_state']);
        $this->assertSame('', $history[0]['transition_id']);

        $eventNames = array_map(static fn ($event): string => $event->fullEventName('workflow.event.'), $dispatcher->dispatchedEvents());
        $this->assertSame(['workflow.event.instance_started', 'workflow.event.updated'], $eventNames);
    }

    public function test_database_engine_fails_transition_when_required_fields_are_missing(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('required_transition_fields', [
            'dsl_version' => 2,
            'name' => 'required_transition_fields',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                    'validation' => [
                        'required' => ['comment', 'reason'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('required_transition_fields', [
            'data' => ['comment' => 'already set'],
        ]);

        try {
            $engine->execute($instance['instance_id'], 'submit', []);
            $this->fail('Expected InvalidTransitionValidationException was not thrown');
        } catch (InvalidTransitionValidationException) {
            $this->assertTrue(true);
        }

        $persisted = $storage->getInstance($instance['instance_id']);
        $this->assertSame('draft', $persisted['state']);
        $this->assertSame(0, $persisted['version']);

        $histories = $this->capsule->getConnection()->table('workflow_histories')->get();
        $this->assertCount(0, $histories);

        $eventNames = array_map(static fn ($event): string => $event->fullEventName('workflow.event.'), $dispatcher->dispatchedEvents());
        $this->assertSame(['workflow.event.instance_started', 'workflow.event.transition_failed'], $eventNames);
    }

    public function test_database_engine_allows_transition_when_required_fields_are_present_after_merge(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('required_transition_fields', [
            'dsl_version' => 2,
            'name' => 'required_transition_fields',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                    'validation' => [
                        'required' => ['comment', 'reason'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('required_transition_fields', [
            'data' => ['comment' => 'already set'],
        ]);

        $updated = $engine->execute($instance['instance_id'], 'submit', [
            'data' => ['reason' => 'manual completion'],
        ]);

        $this->assertSame('approved', $updated['state']);
        $this->assertSame(1, $updated['version']);
    }

    private function buildEngine(
        DatabaseWorkflowRepository $storage,
        EventDispatcherInterface $dispatcher,
        FunctionRegistry $functions
    ): WorkflowEngine {
        $parser = new Parser();
        $validator = new Validator($functions);
        $compiler = new Compiler();
        $stateMachine = new StateMachine();
        $rules = new RuleEngine($functions);
        $policy = new PolicyEngine($rules);
        $fields = new FieldEngine($rules);
        $executor = new TransitionExecutor($stateMachine, $policy, $storage, $dispatcher);
        $updateExecutor = new UpdateExecutor($stateMachine, $policy, $fields, $storage, $dispatcher);

        return new WorkflowEngine(
            $storage,
            $parser,
            $validator,
            $compiler,
            $stateMachine,
            $executor,
            $fields,
            $policy,
            $functions,
            $dispatcher,
            null,
            true,
            300,
            null,
            'tenant-default',
            false,
            $updateExecutor
        );
    }
}

class FailingQueueDispatcher extends Dispatcher
{
    public function queue(WorkflowEvent $event): void
    {
        parent::queue($event);

        if ($event->fullEventName('workflow.event.') === 'workflow.event.request_approved') {
            throw new WorkflowException('queue failed before commit');
        }
    }
}
