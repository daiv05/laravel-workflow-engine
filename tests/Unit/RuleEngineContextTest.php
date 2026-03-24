<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
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

    public function test_it_passes_args_to_registered_function_rules(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('matchesActor', static function (array $context, string $expected): bool {
            return (string) ($context['actor_id'] ?? '') === $expected;
        });

        $engine = new RuleEngine($functions);

        $this->assertTrue($engine->evaluate([
            'fn' => 'matchesActor',
            'args' => ['42'],
        ], [
            'actor_id' => 42,
        ]));
    }

    public function test_it_returns_true_for_empty_rule(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->assertTrue($engine->evaluate([], []));
    }

    public function test_it_throws_for_non_evaluable_rule_without_supported_operators(): void
    {
        $engine = new RuleEngine(new FunctionRegistry());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Rule is not evaluable with the supported operators');

        $engine->evaluate(['unexpected' => 'shape'], []);
    }

    public function test_it_bubbles_exceptions_from_registered_functions(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('explode', static function (): bool {
            throw new \RuntimeException('function exploded');
        });

        $engine = new RuleEngine($functions);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('function exploded');

        $engine->evaluate(['fn' => 'explode'], []);
    }
}
