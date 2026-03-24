<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Exceptions\FunctionNotFoundException;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use PHPUnit\Framework\TestCase;

class FunctionRegistryTest extends TestCase
{
    public function test_it_registers_and_resolves_a_function(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('isApprover', static function (array $context, string $role): bool {
            return in_array($role, $context['roles'] ?? [], true);
        });

        $this->assertTrue($registry->has('isApprover'));

        $callable = $registry->get('isApprover');
        $this->assertTrue($callable(['roles' => ['APPROVER']], 'APPROVER'));
        $this->assertFalse($callable(['roles' => ['USER']], 'APPROVER'));
    }

    public function test_it_throws_explicit_error_when_function_is_not_registered(): void
    {
        $registry = new FunctionRegistry();

        $this->expectException(FunctionNotFoundException::class);
        $this->expectExceptionCode(2001);
        $this->expectExceptionMessage('Function not registered: missingFn');

        $registry->get('missingFn');
    }
}
