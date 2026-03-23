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
use Daiv05\LaravelWorkflowEngine\Exceptions\ActiveSubjectInstanceExistsException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseWorkflowRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class SubjectAssociationIntegrationTest extends TestCase
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
            $table->index(['instance_id'], 'wf_history_instance_idx');
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

    public function test_start_with_subject_persists_subject_reference(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('solicitud_flow', [
            'dsl_version' => 2,
            'name' => 'solicitud_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $instance = $engine->start('solicitud_flow', [
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => '456',
            ],
            'data' => ['reason' => 'Termination'],
        ]);

        $this->assertSame('App\\Models\\Solicitud', $instance['subject_type']);
        $this->assertSame('456', $instance['subject_id']);
        $this->assertSame('draft', $instance['state']);

        $persisted = $storage->getInstance($instance['instance_id']);
        $this->assertSame('App\\Models\\Solicitud', $persisted['subject_type']);
        $this->assertSame('456', $persisted['subject_id']);
    }

    public function test_get_latest_instance_for_subject(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('request_workflow', [
            'dsl_version' => 2,
            'name' => 'request_workflow',
            'version' => 1,
            'initial_state' => 'pending',
            'final_states' => ['completed'],
            'states' => ['pending', 'completed'],
            'transitions' => [
                [
                    'from' => 'pending',
                    'to' => 'completed',
                    'action' => 'complete',
                    'transition_id' => 'tr_complete',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subjectRef = [
            'subject_type' => 'App\\Models\\Request',
            'subject_id' => 'uuid-789',
        ];

        $instance1 = $engine->start('request_workflow', ['subject' => $subjectRef]);
        sleep(1); // Ensure different timestamps
        $instance2 = $engine->start('request_workflow', ['subject' => $subjectRef]);

        $latest = $storage->getLatestInstanceForSubject('request_workflow', $subjectRef, 'tenant-default');

        $this->assertNotNull($latest);
        $this->assertSame($instance2['instance_id'], $latest['instance_id']);
    }

    public function test_get_instances_for_subject_returns_all(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('multi_flow', [
            'dsl_version' => 2,
            'name' => 'multi_flow',
            'version' => 1,
            'initial_state' => 'start',
            'final_states' => ['end'],
            'states' => ['start', 'end'],
            'transitions' => [
                [
                    'from' => 'start',
                    'to' => 'end',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subjectRef = [
            'subject_type' => 'App\\Models\\Document',
            'subject_id' => 'doc-123',
        ];

        $engine->start('multi_flow', ['subject' => $subjectRef]);
        $engine->start('multi_flow', ['subject' => $subjectRef]);

        $instances = $storage->getInstancesForSubject($subjectRef, 'tenant-default');

        $this->assertCount(2, $instances);
        foreach ($instances as $inst) {
            $this->assertSame('App\\Models\\Document', $inst['subject_type']);
            $this->assertSame('doc-123', $inst['subject_id']);
        }
    }

    public function test_get_instances_for_subject_filters_by_workflow(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('workflow_a', [
            'dsl_version' => 2,
            'name' => 'workflow_a',
            'version' => 1,
            'initial_state' => 'start',
            'final_states' => ['end'],
            'states' => ['start', 'end'],
            'transitions' => [
                [
                    'from' => 'start',
                    'to' => 'end',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_a',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $engine->activateDefinition('workflow_b', [
            'dsl_version' => 2,
            'name' => 'workflow_b',
            'version' => 1,
            'initial_state' => 'init',
            'final_states' => ['done'],
            'states' => ['init', 'done'],
            'transitions' => [
                [
                    'from' => 'init',
                    'to' => 'done',
                    'action' => 'proceed',
                    'transition_id' => 'tr_proceed_b',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subjectRef = [
            'subject_type' => 'App\\Models\\Entity',
            'subject_id' => 'ent-999',
        ];

        $engine->start('workflow_a', ['subject' => $subjectRef]);
        $engine->start('workflow_b', ['subject' => $subjectRef]);

        $instancesA = $storage->getInstancesForSubject($subjectRef, 'tenant-default', 'workflow_a');
        $instancesB = $storage->getInstancesForSubject($subjectRef, 'tenant-default', 'workflow_b');

        $this->assertCount(1, $instancesA);
        $this->assertCount(1, $instancesB);
    }

    public function test_engine_get_latest_instance_for_subject_normalizes_subject_id_and_returns_latest(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('engine_subject_latest_flow', [
            'dsl_version' => 2,
            'name' => 'engine_subject_latest_flow',
            'version' => 1,
            'initial_state' => 'pending',
            'final_states' => ['completed'],
            'states' => ['pending', 'completed'],
            'transitions' => [
                [
                    'from' => 'pending',
                    'to' => 'completed',
                    'action' => 'complete',
                    'transition_id' => 'tr_complete_engine_latest',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $engine->start('engine_subject_latest_flow', [
            'subject' => [
                'subject_type' => 'App\\Models\\Request',
                'subject_id' => '789',
            ],
        ]);
        sleep(1); // Ensure different timestamps for latest selection.
        $latestInstance = $engine->start('engine_subject_latest_flow', [
            'subject' => [
                'subject_type' => 'App\\Models\\Request',
                'subject_id' => '789',
            ],
        ]);

        $latest = $engine->getLatestInstanceForSubject('engine_subject_latest_flow', [
            'subject_type' => 'App\\Models\\Request',
            'subject_id' => 789,
        ]);

        $this->assertNotNull($latest);
        $this->assertSame($latestInstance['instance_id'], $latest['instance_id']);
        $this->assertSame('789', $latest['subject_id']);
    }

    public function test_engine_get_instances_for_subject_can_filter_by_workflow_name(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('engine_query_flow_a', [
            'dsl_version' => 2,
            'name' => 'engine_query_flow_a',
            'version' => 1,
            'initial_state' => 'start',
            'final_states' => ['end'],
            'states' => ['start', 'end'],
            'transitions' => [
                [
                    'from' => 'start',
                    'to' => 'end',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_engine_a',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $engine->activateDefinition('engine_query_flow_b', [
            'dsl_version' => 2,
            'name' => 'engine_query_flow_b',
            'version' => 1,
            'initial_state' => 'init',
            'final_states' => ['done'],
            'states' => ['init', 'done'],
            'transitions' => [
                [
                    'from' => 'init',
                    'to' => 'done',
                    'action' => 'proceed',
                    'transition_id' => 'tr_proceed_engine_b',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subjectRef = [
            'subject_type' => 'App\\Models\\Entity',
            'subject_id' => 'entity-100',
        ];

        $engine->start('engine_query_flow_a', ['subject' => $subjectRef]);
        $engine->start('engine_query_flow_b', ['subject' => $subjectRef]);

        $allInstances = $engine->getInstancesForSubject($subjectRef);
        $instancesA = $engine->getInstancesForSubject($subjectRef, workflowName: 'engine_query_flow_a');
        $instancesB = $engine->getInstancesForSubject($subjectRef, workflowName: 'engine_query_flow_b');

        $this->assertCount(2, $allInstances);
        $this->assertCount(1, $instancesA);
        $this->assertCount(1, $instancesB);
    }

    public function test_engine_subject_query_methods_fail_with_clear_error_on_invalid_subject_reference(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('engine_subject_error_flow', [
            'dsl_version' => 2,
            'name' => 'engine_subject_error_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_engine_error',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $engine->start('engine_subject_error_flow', [
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => 'existing',
            ],
        ]);

        try {
            $engine->getInstancesForSubject([
                'subject_type' => 'App\\Models\\Solicitud',
            ]);
            $this->fail('Expected WorkflowException was not thrown.');
        } catch (WorkflowException $exception) {
            $this->assertSame('subject_id is required', $exception->getMessage());
        }

        $instances = $engine->getInstancesForSubject([
            'subject_type' => 'App\\Models\\Solicitud',
            'subject_id' => 'existing',
        ]);
        $this->assertCount(1, $instances);
    }

    public function test_available_actions_and_can_use_subject_aware_functions(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('subject_action_gate', [
            'dsl_version' => 2,
            'name' => 'subject_action_gate',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_gate',
                    'allowed_if' => [
                        'all' => [
                            [
                                'fn' => 'matches_subject_type',
                                'args' => ['App\\Models\\Solicitud'],
                            ],
                            [
                                'fn' => 'matches_subject_owner',
                                'args' => ['actor_id'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('subject_action_gate', [
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => '456',
            ],
        ]);

        $this->assertFalse($engine->can($instance['instance_id'], 'approve', ['actor_id' => '100']));
        $this->assertSame([], $engine->availableActions($instance['instance_id'], ['actor_id' => '100']));

        $this->assertTrue($engine->can($instance['instance_id'], 'approve', ['actor_id' => '456']));
        $this->assertSame(['approve'], $engine->availableActions($instance['instance_id'], ['actor_id' => '456']));
    }

    public function test_visible_fields_includes_subject_context_for_visibility_and_editability(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('subject_fields_flow', [
            'dsl_version' => 2,
            'name' => 'subject_fields_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_fields',
                    'allowed_if' => [],
                    'fields' => [
                        'visible' => ['notes'],
                        'editable' => ['notes'],
                        'visible_if' => [
                            'fn' => 'matches_subject_type',
                            'args' => ['App\\Models\\Solicitud'],
                        ],
                        'editable_if' => [
                            'fn' => 'matches_subject_owner',
                            'args' => ['actor_id'],
                        ],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('subject_fields_flow', [
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => '777',
            ],
        ]);

        $notOwnerFields = $engine->visibleFields($instance['instance_id'], ['actor_id' => '100']);
        $ownerFields = $engine->visibleFields($instance['instance_id'], ['actor_id' => '777']);

        $this->assertSame(['notes'], $notOwnerFields['approve']['visible']);
        $this->assertSame([], $notOwnerFields['approve']['editable']);

        $this->assertSame(['notes'], $ownerFields['approve']['visible']);
        $this->assertSame(['notes'], $ownerFields['approve']['editable']);
    }

    public function test_subject_aware_rules_keep_backward_compatibility_when_instance_has_no_subject(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions);

        $engine->activateDefinition('subject_optional_flow', [
            'dsl_version' => 2,
            'name' => 'subject_optional_flow',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_optional',
                    'allowed_if' => [
                        'fn' => 'matches_subject_type',
                        'args' => ['App\\Models\\Solicitud'],
                    ],
                ],
            ],
        ]);

        $instance = $engine->start('subject_optional_flow');

        $this->assertFalse($engine->can($instance['instance_id'], 'approve'));
        $this->assertSame([], $engine->availableActions($instance['instance_id']));
        $this->assertSame([
            'approve' => [
                'visible' => [],
                'editable' => [],
            ],
        ], $engine->visibleFields($instance['instance_id']));
    }

    public function test_start_rejects_second_active_instance_for_same_subject_when_enforcement_enabled(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions, true);

        $engine->activateDefinition('single_active_subject_flow', [
            'dsl_version' => 2,
            'name' => 'single_active_subject_flow',
            'version' => 1,
            'initial_state' => 'pending',
            'final_states' => ['completed'],
            'states' => ['pending', 'completed'],
            'transitions' => [
                [
                    'from' => 'pending',
                    'to' => 'completed',
                    'action' => 'complete',
                    'transition_id' => 'tr_complete_single_active_subject_flow',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subject = [
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '1001',
        ];

        $first = $engine->start('single_active_subject_flow', ['subject' => $subject]);

        try {
            $engine->start('single_active_subject_flow', ['subject' => $subject]);
            $this->fail('Expected ActiveSubjectInstanceExistsException was not thrown.');
        } catch (ActiveSubjectInstanceExistsException $exception) {
            $this->assertSame('An active instance of single_active_subject_flow already exists for this subject', $exception->getMessage());
            $this->assertSame($first['instance_id'], $exception->context()['existing_instance_id'] ?? null);
        }
    }

    public function test_start_allows_new_instance_after_previous_reaches_final_state_when_enforcement_enabled(): void
    {
        $functions = new FunctionRegistry();
        $storage = new DatabaseWorkflowRepository($this->capsule->getConnection());
        $dispatcher = new Dispatcher('workflow.event.');
        $engine = $this->buildEngine($storage, $dispatcher, $functions, true);

        $engine->activateDefinition('single_active_subject_after_final_flow', [
            'dsl_version' => 2,
            'name' => 'single_active_subject_after_final_flow',
            'version' => 1,
            'initial_state' => 'pending',
            'final_states' => ['completed'],
            'states' => ['pending', 'completed'],
            'transitions' => [
                [
                    'from' => 'pending',
                    'to' => 'completed',
                    'action' => 'complete',
                    'transition_id' => 'tr_complete_single_active_subject_after_final_flow',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $subject = [
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '1002',
        ];

        $first = $engine->start('single_active_subject_after_final_flow', ['subject' => $subject]);
        $engine->execute($first['instance_id'], 'complete');

        $second = $engine->start('single_active_subject_after_final_flow', ['subject' => $subject]);

        $this->assertNotSame($first['instance_id'], $second['instance_id']);
        $this->assertSame('pending', $second['state']);
    }

    private function buildEngine(
        DatabaseWorkflowRepository $storage,
        EventDispatcherInterface $dispatcher,
        FunctionRegistry $functions,
        bool $enforceOneActivePerSubject = false
    ): WorkflowEngine {
        $functions->register('matches_subject_type', static function (array $context, string $expectedType): bool {
            $subject = $context['subject'] ?? null;

            if (!is_array($subject)) {
                return false;
            }

            $subjectType = $subject['subject_type'] ?? null;

            return is_string($subjectType) && $subjectType === $expectedType;
        });

        $functions->register('matches_subject_owner', static function (array $context, string $actorIdKey = 'actor_id'): bool {
            $subject = $context['subject'] ?? null;

            if (!is_array($subject)) {
                return false;
            }

            $subjectId = $subject['subject_id'] ?? null;
            $actorId = $context[$actorIdKey] ?? null;

            if (!is_scalar($subjectId) || !is_scalar($actorId)) {
                return false;
            }

            return (string) $subjectId === (string) $actorId;
        });

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
            $enforceOneActivePerSubject,
            $updateExecutor
        );
    }
}
