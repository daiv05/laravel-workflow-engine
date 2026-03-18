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
}
