<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Exceptions\ActiveSubjectInstanceExistsException;
use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\FunctionNotFoundException;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\OptimisticLockException;
use Daiv05\LaravelWorkflowEngine\Exceptions\UnauthorizedTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use PHPUnit\Framework\TestCase;

class DomainExceptionsTest extends TestCase
{
    public function test_workflow_exception_can_merge_context_without_mutating_original(): void
    {
        $exception = new WorkflowException('base', 42, null, ['a' => 1]);
        $enriched = $exception->withContext(['b' => 2]);

        $this->assertSame(['a' => 1], $exception->context());
        $this->assertSame(['a' => 1, 'b' => 2], $enriched->context());
        $this->assertNotSame($exception, $enriched);
    }

    public function test_dsl_validation_with_path_includes_code_path_and_node_path(): void
    {
        $exception = DSLValidationException::withPath('Missing required key: dsl_version', 'dsl_version');

        $this->assertSame(1001, $exception->getCode());
        $this->assertSame('Missing required key: dsl_version at dsl_version', $exception->getMessage());
        $this->assertSame('dsl_version', $exception->nodePath());
        $this->assertSame(['node_path' => 'dsl_version'], $exception->context());
    }

    public function test_dsl_validation_malformed_uses_specific_code(): void
    {
        $exception = DSLValidationException::malformed('Malformed DSL');

        $this->assertSame(1002, $exception->getCode());
        $this->assertSame('Malformed DSL', $exception->getMessage());
        $this->assertNull($exception->nodePath());
    }

    public function test_function_not_found_factory_sets_context_and_code(): void
    {
        $exception = FunctionNotFoundException::forName('isHR');

        $this->assertSame(2001, $exception->getCode());
        $this->assertSame('Function not registered: isHR', $exception->getMessage());
        $this->assertSame(['function' => 'isHR'], $exception->context());
    }

    public function test_invalid_transition_factory_sets_context_and_code(): void
    {
        $exception = InvalidTransitionException::forStateAndAction('draft', 'approve');

        $this->assertSame(3001, $exception->getCode());
        $this->assertSame('Invalid transition for current state and action', $exception->getMessage());
        $this->assertSame(
            ['state' => 'draft', 'action' => 'approve'],
            $exception->context()
        );
    }

    public function test_optimistic_lock_factory_supports_actual_version_context(): void
    {
        $exception = OptimisticLockException::forInstance('iid-1', 4, 7);

        $this->assertSame(4001, $exception->getCode());
        $this->assertSame('Workflow instance version mismatch (expected 4, actual 7)', $exception->getMessage());
        $this->assertSame(
            [
                'instance_id' => 'iid-1',
                'expected_version' => 4,
                'actual_version' => 7,
            ],
            $exception->context()
        );
    }

    public function test_unauthorized_transition_factory_sets_context_and_code(): void
    {
        $exception = UnauthorizedTransitionException::forTransition('approve', 'hr_review', ['roles' => ['USER']]);

        $this->assertSame(5001, $exception->getCode());
        $this->assertSame('Transition is not authorized by allowed_if rule', $exception->getMessage());
        $this->assertSame(
            [
                'action' => 'approve',
                'from_state' => 'hr_review',
                'context' => ['roles' => ['USER']],
            ],
            $exception->context()
        );
    }

    public function test_context_validation_factories_set_codes_and_messages(): void
    {
        $missing = ContextValidationException::missingKey('roles', 'role-based rules');
        $invalid = ContextValidationException::invalidType('roles', 'an array');

        $this->assertSame(6001, $missing->getCode());
        $this->assertSame('Context key roles is required for role-based rules', $missing->getMessage());
        $this->assertSame(['key' => 'roles', 'usage' => 'role-based rules'], $missing->context());

        $this->assertSame(6002, $invalid->getCode());
        $this->assertSame('Context key roles must be provided as an array', $invalid->getMessage());
        $this->assertSame(['key' => 'roles', 'expected_type' => 'an array'], $invalid->context());
    }

    public function test_workflow_exception_exports_normalized_diagnostic_context(): void
    {
        $exception = new WorkflowException('base error', 7001, null, ['instance_id' => 'iid-1']);
        $diagnostic = $exception->toDiagnosticContext();

        $this->assertSame(WorkflowException::class, $diagnostic['exception_class']);
        $this->assertSame(7001, $diagnostic['exception_code']);
        $this->assertSame('base error', $diagnostic['exception_message']);
        $this->assertSame(['instance_id' => 'iid-1'], $diagnostic['context']);
    }

    public function test_active_subject_instance_exists_factory_sets_context_and_code(): void
    {
        $exception = ActiveSubjectInstanceExistsException::forSubject(
            'approval',
            ['subject_type' => 'App\\Models\\Order', 'subject_id' => '123'],
            'iid-existing',
            'tenant-default'
        );

        $this->assertSame(7002, $exception->getCode());
        $this->assertSame('An active instance of approval already exists for this subject', $exception->getMessage());
        $this->assertSame(
            [
                'workflow_name' => 'approval',
                'subject_type' => 'App\\Models\\Order',
                'subject_id' => '123',
                'existing_instance_id' => 'iid-existing',
                'tenant_id' => 'tenant-default',
            ],
            $exception->context()
        );
    }
}
