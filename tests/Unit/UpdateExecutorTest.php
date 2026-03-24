<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\UpdateExecutor;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Events\TransitionFailed;
use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;
use Daiv05\LaravelWorkflowEngine\Exceptions\UnauthorizedUpdateException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class UpdateExecutorTest extends TestCase
{
    private StateMachine $stateMachine;
    private PolicyEngine $policy;
    private FieldEngine $fields;
    private InMemoryWorkflowRepository $storage;
    private Dispatcher $events;
    private UpdateExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $functions = new FunctionRegistry();
        $rules = new RuleEngine($functions);

        $this->stateMachine = new StateMachine();
        $this->policy = new PolicyEngine($rules);
        $this->fields = new FieldEngine($rules);
        $this->storage = new InMemoryWorkflowRepository();
        $this->events = new Dispatcher('workflow.event.');
        $this->executor = new UpdateExecutor(
            $this->stateMachine,
            $this->policy,
            $this->fields,
            $this->storage,
            $this->events
        );
    }

    public function test_can_update_returns_true_when_state_allows_update_and_data_is_absent(): void
    {
        $instance = [
            'instance_id' => 'iid-1',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->assertTrue($this->executor->canUpdate($instance, $definition, []));
    }

    public function test_can_update_returns_false_when_data_is_not_array(): void
    {
        $instance = [
            'instance_id' => 'iid-1',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->assertFalse($this->executor->canUpdate($instance, $definition, ['data' => 'invalid']));
    }

    public function test_can_update_returns_false_for_final_state_instances(): void
    {
        $instance = [
            'instance_id' => 'iid-final',
            'state' => 'approved',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'approved' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->assertFalse($this->executor->canUpdate($instance, $definition, ['data' => ['comment' => 'x']]));
    }

    public function test_can_update_returns_false_when_permissions_are_missing(): void
    {
        $instance = [
            'instance_id' => 'iid-no-permissions',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->assertFalse($this->executor->canUpdate($instance, $definition, ['data' => ['comment' => 'x']]));
    }

    public function test_can_update_returns_false_when_context_contains_disallowed_fields(): void
    {
        $instance = [
            'instance_id' => 'iid-disallowed',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->assertFalse($this->executor->canUpdate($instance, $definition, ['data' => ['forbidden' => 'x']]));
    }

    public function test_execute_with_listeners_throws_unauthorized_when_state_config_is_missing(): void
    {
        $instance = [
            'instance_id' => 'iid-unauthorized',
            'state' => 'review',
            'data' => [],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->expectException(UnauthorizedUpdateException::class);
        $this->expectExceptionCode(5002);

        $this->executor->executeWithListeners($instance, $definition, ['data' => ['comment' => 'x']]);
    }

    public function test_execute_with_listeners_throws_context_validation_when_data_is_missing(): void
    {
        $instance = [
            'instance_id' => 'iid-missing-data',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $this->expectException(ContextValidationException::class);
        $this->expectExceptionCode(6001);

        $this->executor->executeWithListeners($instance, $definition, []);
    }

    public function test_execute_with_listeners_throws_mapping_exception_when_mapper_is_not_configured(): void
    {
        $instance = [
            'instance_id' => 'iid-mapping',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
        ];

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                    'mappings' => [
                        'comment' => ['type' => 'attribute'],
                    ],
                ],
            ],
        ];

        $this->expectException(MappingException::class);
        $this->expectExceptionCode(6101);

        $this->executor->executeWithListeners($instance, $definition, ['data' => ['comment' => 'mapped']]);
    }

    public function test_execute_with_listeners_rethrows_listener_exception_and_dispatches_transition_failed(): void
    {
        $definitionId = $this->storage->activateDefinition('update_workflow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ]);

        $instance = $this->storage->createInstance([
            'instance_id' => 'iid-listener',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-default',
            'state' => 'draft',
            'data' => ['comment' => 'initial'],
            'version' => 0,
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
        ]);

        $definition = [
            'final_states' => ['approved'],
            'state_configs' => [
                'draft' => [
                    'permissions' => ['update' => true],
                    'fields' => ['editable' => ['comment']],
                ],
            ],
        ];

        $listeners = [
            'named' => [
                'updated' => [
                    static function (): void {
                        throw new \RuntimeException('listener exploded');
                    },
                ],
            ],
        ];

        try {
            $this->executor->executeWithListeners($instance, $definition, [
                'actor' => 'owner-1',
                'data' => ['comment' => 'after'],
            ], $listeners);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('listener exploded', $exception->getMessage());
        }

        $found = false;
        foreach ($this->events->dispatchedEvents() as $event) {
            if ($event instanceof TransitionFailed && $event->action === 'update') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }
}
