<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class PolicyEngineTest extends TestCase
{
    public function test_can_update_state_returns_false_when_permissions_are_missing_or_invalid(): void
    {
        $engine = new PolicyEngine(new RuleEngine(new FunctionRegistry()));

        $this->assertFalse($engine->canUpdateState([], []));
        $this->assertFalse($engine->canUpdateState(['permissions' => 'invalid'], []));
        $this->assertFalse($engine->canUpdateState(['permissions' => ['update' => 'invalid']], []));
    }

    public function test_can_update_state_supports_boolean_update_permission(): void
    {
        $engine = new PolicyEngine(new RuleEngine(new FunctionRegistry()));

        $this->assertTrue($engine->canUpdateState(['permissions' => ['update' => true]], []));
        $this->assertFalse($engine->canUpdateState(['permissions' => ['update' => false]], []));
    }

    public function test_can_update_state_evaluates_allowed_if_rule_shape(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isOwner', static fn (array $context): bool => (($context['actor'] ?? null) === ($context['owner'] ?? null)));

        $engine = new PolicyEngine(new RuleEngine($functions));

        $config = [
            'permissions' => [
                'update' => [
                    'allowed_if' => ['fn' => 'isOwner'],
                ],
            ],
        ];

        $this->assertTrue($engine->canUpdateState($config, ['actor' => 'u1', 'owner' => 'u1']));
        $this->assertFalse($engine->canUpdateState($config, ['actor' => 'u1', 'owner' => 'u2']));
    }

    public function test_can_update_state_treats_non_array_allowed_if_as_empty_rule(): void
    {
        $engine = new PolicyEngine(new RuleEngine(new FunctionRegistry()));

        $config = [
            'permissions' => [
                'update' => [
                    'allowed_if' => 'invalid-shape',
                ],
            ],
        ];

        $this->assertTrue($engine->canUpdateState($config, []));
    }

    public function test_can_execute_transition_defaults_to_allow_when_allowed_if_is_missing(): void
    {
        $engine = new PolicyEngine(new RuleEngine(new FunctionRegistry()));

        $this->assertTrue($engine->canExecuteTransition(['action' => 'approve'], []));
    }
}
