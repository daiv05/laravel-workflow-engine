<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use PHPUnit\Framework\TestCase;

class InMemoryWorkflowRepositoryTest extends TestCase
{
    public function test_transaction_rolls_back_when_exception_occurs(): void
    {
        $repository = new InMemoryWorkflowRepository();

        $definitionId = $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ]);

        $repository->createInstance([
            'instance_id' => '11111111-1111-4111-8111-111111111111',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => null,
            'state' => 'draft',
            'data' => [],
            'version' => 0,
        ]);

        try {
            $repository->transaction(function () use ($repository): void {
                $repository->updateInstanceWithVersionCheck([
                    'instance_id' => '11111111-1111-4111-8111-111111111111',
                    'workflow_definition_id' => 1,
                    'tenant_id' => null,
                    'state' => 'approved',
                    'data' => [],
                    'version' => 1,
                ], 0);

                $repository->appendHistory([
                    'instance_id' => '11111111-1111-4111-8111-111111111111',
                    'transition_id' => 'tr_approve',
                    'action' => 'approve',
                    'from_state' => 'draft',
                    'to_state' => 'approved',
                ]);

                throw new WorkflowException('force rollback');
            });

            $this->fail('Expected exception was not thrown');
        } catch (WorkflowException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $instance = $repository->getInstance('11111111-1111-4111-8111-111111111111');
        $this->assertSame('draft', $instance['state']);
        $this->assertSame(0, $instance['version']);
    }

    public function test_activate_definition_rejects_duplicate_version_for_same_scope(): void
    {
        $repository = new InMemoryWorkflowRepository();

        $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Workflow definition version is immutable and already exists for scope');

        $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');
    }

    public function test_get_latest_instance_for_subject_respects_workflow_and_tenant_filters(): void
    {
        $repository = new InMemoryWorkflowRepository();

        $approvalDefinitionId = $repository->activateDefinition('approval_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');

        $otherDefinitionId = $repository->activateDefinition('other_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [],
        ], 'tenant-a');

        $repository->createInstance([
            'instance_id' => 'iid-old',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-latest',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T11:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-other-workflow',
            'workflow_definition_id' => $otherDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T12:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-other-tenant',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-b',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T13:00:00+00:00',
        ]);

        $latest = $repository->getLatestInstanceForSubject('approval_flow', [
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
        ], 'tenant-a');

        $this->assertNotNull($latest);
        $this->assertSame('iid-latest', $latest['instance_id']);
    }

    public function test_get_instances_for_subject_returns_sorted_rows_and_applies_filters(): void
    {
        $repository = new InMemoryWorkflowRepository();

        $approvalDefinitionId = $repository->activateDefinition('approval_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');

        $otherDefinitionId = $repository->activateDefinition('other_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['done'],
            'states' => ['draft', 'done'],
            'transitions' => [],
        ], 'tenant-a');

        $repository->createInstance([
            'instance_id' => 'iid-2',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T11:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-1',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-other-workflow',
            'workflow_definition_id' => $otherDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T09:00:00+00:00',
        ]);

        $result = $repository->getInstancesForSubject([
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
        ], 'tenant-a', 'approval_flow');

        $this->assertCount(2, $result);
        $this->assertSame('iid-1', $result[0]['instance_id']);
        $this->assertSame('iid-2', $result[1]['instance_id']);
    }

    public function test_get_latest_active_instance_for_subject_ignores_final_states(): void
    {
        $repository = new InMemoryWorkflowRepository();

        $definitionId = $repository->activateDefinition('approval_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved', 'rejected'],
            'states' => ['draft', 'approved', 'rejected'],
            'transitions' => [],
        ], 'tenant-a');

        $repository->createInstance([
            'instance_id' => 'iid-final',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'approved',
            'data' => [],
            'version' => 1,
            'created_at' => '2026-03-24T12:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => 'iid-active',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '42',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T11:30:00+00:00',
        ]);

        $latestActive = $repository->getLatestActiveInstanceForSubject(
            'approval_flow',
            ['subject_type' => 'App\\Models\\Order', 'subject_id' => '42'],
            ['approved', 'rejected'],
            'tenant-a'
        );

        $this->assertNotNull($latestActive);
        $this->assertSame('iid-active', $latestActive['instance_id']);
    }
}
