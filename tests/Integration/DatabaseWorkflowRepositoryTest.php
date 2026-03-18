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
            $table->timestamps();

            $table->index(['tenant_id', 'state'], 'wf_instance_tenant_state_idx');
            $table->index(['workflow_definition_id'], 'wf_instance_definition_idx');
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
}
