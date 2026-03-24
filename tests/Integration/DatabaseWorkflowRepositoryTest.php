<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Exceptions\OptimisticLockException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseWorkflowRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class DatabaseWorkflowRepositoryTest extends TestCase
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

            $table->index(['tenant_id', 'state'], 'wf_instance_tenant_state_idx');
            $table->index(['workflow_definition_id'], 'wf_instance_definition_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wf_instance_subject_lookup_idx');
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'wf_instance_definition_subject_idx');
        });

        $schema->create('workflow_instance_locator', function (Blueprint $table): void {
            $table->uuid('instance_id')->primary();
            $table->unsignedBigInteger('workflow_definition_id');
            $table->string('instances_table');
            $table->string('histories_table');
            $table->string('tenant_id')->nullable();
            $table->string('state');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->timestamps();

            $table->index(['workflow_definition_id'], 'wf_locator_definition_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wf_locator_subject_lookup_idx');
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'wf_locator_definition_subject_idx');
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
            $table->index(['created_at'], 'wf_history_created_at_idx');
        });
    }

    public function test_activate_definition_replaces_previous_active_for_same_scope(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

        $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');

        $latestId = $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 2,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ], 'tenant-a');

        $active = $repository->getActiveDefinition('termination_request', 'tenant-a');

        $this->assertSame($latestId, $active['id']);
        $this->assertSame(2, $active['version']);
    }

    public function test_optimistic_lock_throws_on_stale_version(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

        $definitionId = $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ]);

        $instance = $repository->createInstance([
            'instance_id' => '22222222-2222-4222-8222-222222222222',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => null,
            'state' => 'draft',
            'data' => [],
            'version' => 0,
        ]);

        $updated = $instance;
        $updated['state'] = 'approved';
        $updated['version'] = 1;

        $repository->updateInstanceWithVersionCheck($updated, 0);

        $this->expectException(OptimisticLockException::class);
        $repository->updateInstanceWithVersionCheck($updated, 0);
    }

    public function test_activate_definition_rejects_duplicate_version_for_same_scope(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

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

    public function test_create_instance_append_history_and_read_back(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

        $definitionId = $repository->activateDefinition('termination_request', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [],
        ]);

        $repository->createInstance([
            'instance_id' => '33333333-3333-4333-8333-333333333333',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => null,
            'state' => 'draft',
            'data' => ['request_id' => 77],
            'version' => 0,
            'subject_type' => 'App\\Models\\Request',
            'subject_id' => '77',
            'created_at' => '2026-03-24T10:00:00+00:00',
            'updated_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $repository->appendHistory([
            'instance_id' => '33333333-3333-4333-8333-333333333333',
            'transition_id' => 'tr_approve',
            'action' => 'approve',
            'from_state' => 'draft',
            'to_state' => 'approved',
            'actor' => 'tester',
            'payload' => 'scalar payload',
            'created_at' => '2026-03-24T10:05:00+00:00',
        ]);

        $history = $repository->getHistory('33333333-3333-4333-8333-333333333333');

        $this->assertCount(1, $history);
        $this->assertSame('approve', $history[0]['action']);
        $this->assertSame(['value' => 'scalar payload'], $history[0]['payload']);
    }

    public function test_get_latest_instance_for_subject_applies_workflow_and_tenant_filters(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

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
            'instance_id' => '44444444-4444-4444-8444-444444444441',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '99',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => '44444444-4444-4444-8444-444444444442',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '99',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T11:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => '44444444-4444-4444-8444-444444444443',
            'workflow_definition_id' => $otherDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '99',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T12:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => '44444444-4444-4444-8444-444444444444',
            'workflow_definition_id' => $approvalDefinitionId,
            'tenant_id' => 'tenant-b',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '99',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T13:00:00+00:00',
        ]);

        $latest = $repository->getLatestInstanceForSubject('approval_flow', [
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '99',
        ], 'tenant-a');

        $this->assertNotNull($latest);
        $this->assertSame('44444444-4444-4444-8444-444444444442', $latest['instance_id']);
    }

    public function test_get_instances_and_latest_active_for_subject_apply_filters(): void
    {
        $repository = new DatabaseWorkflowRepository($this->capsule->getConnection());

        $definitionId = $repository->activateDefinition('approval_flow', [
            'dsl_version' => 2,
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved', 'rejected'],
            'states' => ['draft', 'approved', 'rejected'],
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
            'instance_id' => '55555555-5555-4555-8555-555555555551',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '10',
            'state' => 'approved',
            'data' => [],
            'version' => 1,
            'created_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => '55555555-5555-4555-8555-555555555552',
            'workflow_definition_id' => $definitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '10',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T11:00:00+00:00',
        ]);

        $repository->createInstance([
            'instance_id' => '55555555-5555-4555-8555-555555555553',
            'workflow_definition_id' => $otherDefinitionId,
            'tenant_id' => 'tenant-a',
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '10',
            'state' => 'draft',
            'data' => [],
            'version' => 0,
            'created_at' => '2026-03-24T09:00:00+00:00',
        ]);

        $instances = $repository->getInstancesForSubject([
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => '10',
        ], 'tenant-a', 'approval_flow');

        $this->assertCount(2, $instances);
        $this->assertSame('55555555-5555-4555-8555-555555555551', $instances[0]['instance_id']);
        $this->assertSame('55555555-5555-4555-8555-555555555552', $instances[1]['instance_id']);

        $latestActive = $repository->getLatestActiveInstanceForSubject(
            'approval_flow',
            ['subject_type' => 'App\\Models\\Order', 'subject_id' => '10'],
            ['approved', 'rejected'],
            'tenant-a'
        );

        $this->assertNotNull($latestActive);
        $this->assertSame('55555555-5555-4555-8555-555555555552', $latestActive['instance_id']);
    }
}
