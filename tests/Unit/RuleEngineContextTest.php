<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineContextTest extends TestCase
{
    public function test_it_throws_when_roles_are_missing_for_role_rule(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->expectException(ContextValidationException::class);
        $this->expectExceptionMessage('Context key roles is required for role-based rules');

        $engine->evaluate(['role' => 'HR'], []);
    }

    public function test_it_throws_when_roles_is_not_array_for_role_rule(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->expectException(ContextValidationException::class);
        $this->expectExceptionMessage('Context key roles must be provided as an array');

        $engine->evaluate(['role' => 'HR'], ['roles' => 'HR']);
    }

    public function test_it_throws_for_nested_all_rule_when_roles_are_missing(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->expectException(ContextValidationException::class);
        $this->expectExceptionMessage('Context key roles is required for role-based rules');

        $engine->evaluate([
            'all' => [
                ['not' => ['role' => 'GUEST']],
                ['role' => 'HR'],
            ],
        ], []);
    }

    public function test_it_throws_for_nested_any_not_rule_when_roles_are_missing(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->expectException(ContextValidationException::class);
        $this->expectExceptionMessage('Context key roles is required for role-based rules');

        $engine->evaluate([
            'any' => [
                ['not' => ['role' => 'HR']],
            ],
        ], []);
    }

    public function test_it_evaluates_nested_rule_when_context_is_valid(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isActive', static fn (array $context): bool => (bool) ($context['active'] ?? false));

        $engine = new RuleEngine($functions);

        $result = $engine->evaluate([
            'all' => [
                ['role' => 'HR'],
                [
                    'any' => [
                        ['fn' => 'isActive'],
                        ['not' => ['role' => 'GUEST']],
                    ],
                ],
            ],
        ], [
            'roles' => ['HR'],
            'active' => false,
        ]);

        $this->assertTrue($result);
    }
}
