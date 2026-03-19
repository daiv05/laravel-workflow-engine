<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class WorkflowEngineCacheTest extends TestCase
{
    public function test_default_tenant_id_forces_static_scope_and_ignores_input_tenant(): void
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
            $functions,
            null,
            true,
            300,
            null,
            'tenant-fixed'
        );

        $engine->activateDefinition('fixed_scope', [
            'dsl_version' => 2,
            'name' => 'fixed_scope',
            'version' => 1,
            'initial_state' => 'draft_fixed',
            'final_states' => ['done'],
            'states' => ['draft_fixed', 'done'],
            'transitions' => [
                [
                    'from' => 'draft_fixed',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_fixed',
                    'allowed_if' => [],
                ],
            ],
        ], 'tenant-a');

        $instance = $engine->start('fixed_scope', ['tenant_id' => 'tenant-b']);

        $this->assertSame('draft_fixed', $instance['state']);
        $this->assertSame('tenant-fixed', $instance['tenant_id']);
    }

    public function test_activate_definition_invalidates_scope_cache_and_uses_latest_version(): void
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

        $engine->activateDefinition('cache_test', [
            'dsl_version' => 2,
            'name' => 'cache_test',
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
        ], 'tenant-x');

        $firstInstance = $engine->start('cache_test', ['tenant_id' => 'tenant-x']);
        $this->assertSame('draft', $firstInstance['state']);

        $engine->activateDefinition('cache_test', [
            'dsl_version' => 2,
            'name' => 'cache_test',
            'version' => 2,
            'initial_state' => 'queued',
            'final_states' => ['done'],
            'states' => ['queued', 'done'],
            'transitions' => [
                [
                    'from' => 'queued',
                    'to' => 'done',
                    'action' => 'finish',
                    'transition_id' => 'tr_finish_v2',
                    'allowed_if' => [],
                ],
            ],
        ], 'tenant-x');

        $secondInstance = $engine->start('cache_test', ['tenant_id' => 'tenant-x']);
        $this->assertSame('queued', $secondInstance['state']);
    }
}
