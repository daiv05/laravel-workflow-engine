<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\StorageBindingResolverInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\OptimisticLockException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Blueprint;

class DatabaseWorkflowRepository implements StorageRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $definitionsTable = 'workflow_definitions',
        private readonly string $instanceLocatorTable = 'workflow_instance_locator',
        private readonly string $defaultInstancesTable = 'workflow_instances',
        private readonly string $defaultHistoriesTable = 'workflow_histories',
        ?StorageBindingResolverInterface $storageBindingResolver = null
    ) {
        $this->storageBindingResolver = $storageBindingResolver ?? new ConfigStorageBindingResolver(
            [],
            'default',
            $this->defaultInstancesTable,
            $this->defaultHistoriesTable,
            null
        );
    }

    private readonly StorageBindingResolverInterface $storageBindingResolver;

    public function transaction(callable $callback): mixed
    {
        return $this->connection->transaction(static fn () => $callback());
    }

    public function activateDefinition(string $workflowName, array $definition, ?string $tenantId = null): int
    {
        return (int) $this->connection->transaction(function () use ($workflowName, $definition, $tenantId): int {
            $version = (int) ($definition['version'] ?? 0);
            $storage = $this->definitionStorage($definition);
            $definitionSnapshot = $definition;
            $definitionSnapshot['storage'] = $storage;

            $this->ensureLocatorTable();
            $this->ensureRuntimeTables($storage['instances_table'], $storage['histories_table']);

            $existingVersionQuery = $this->connection->table($this->definitionsTable)
                ->where('workflow_name', $workflowName)
                ->where('version', $version);

            if ($tenantId === null) {
                $existingVersionQuery->whereNull('tenant_id');
            } else {
                $existingVersionQuery->where('tenant_id', $tenantId);
            }

            if ($existingVersionQuery->exists()) {
                throw new WorkflowException('Workflow definition version is immutable and already exists for scope');
            }

            $activeScope = $this->activeScope($workflowName, $tenantId);

            $deactivate = $this->connection->table($this->definitionsTable)
                ->where('workflow_name', $workflowName)
                ->where('is_active', true);

            if ($tenantId === null) {
                $deactivate->whereNull('tenant_id');
            } else {
                $deactivate->where('tenant_id', $tenantId);
            }

            $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

            $deactivate->update([
                'is_active' => false,
                'active_scope' => null,
                'updated_at' => $timestamp,
            ]);

            return (int) $this->connection->table($this->definitionsTable)->insertGetId([
                'workflow_name' => $workflowName,
                'version' => $version,
                'tenant_id' => $tenantId,
                'dsl_version' => (int) $definitionSnapshot['dsl_version'],
                'definition' => json_encode($definitionSnapshot, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'active_scope' => $activeScope,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function getActiveDefinition(string $workflowName, ?string $tenantId = null): array
    {
        $query = $this->connection->table($this->definitionsTable)
            ->where('workflow_name', $workflowName)
            ->where('is_active', true);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        $row = $query->first();

        if ($row === null) {
            throw new WorkflowException('No active workflow definition found for workflow and tenant');
        }

        return $this->hydrateDefinition((array) $row);
    }

    public function getDefinitionById(int $definitionId): array
    {
        $row = $this->connection->table($this->definitionsTable)
            ->where('id', $definitionId)
            ->first();

        if ($row === null) {
            throw new WorkflowException('Workflow definition not found by id: ' . $definitionId);
        }

        return $this->hydrateDefinition((array) $row);
    }

    public function createInstance(array $instance): array
    {
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);
        $definitionId = (int) $instance['workflow_definition_id'];
        $storage = $this->definitionStorage($this->getDefinitionById($definitionId));

        $this->ensureLocatorTable();
        $this->ensureRuntimeTables($storage['instances_table'], $storage['histories_table']);

        $this->connection->table($storage['instances_table'])->insert([
            'instance_id' => (string) $instance['instance_id'],
            'workflow_definition_id' => $definitionId,
            'tenant_id' => $instance['tenant_id'] ?? null,
            'state' => (string) $instance['state'],
            'data' => json_encode($instance['data'] ?? [], JSON_THROW_ON_ERROR),
            'version' => (int) ($instance['version'] ?? 0),
            'subject_type' => $instance['subject_type'] ?? null,
            'subject_id' => $instance['subject_id'] ?? null,
            'created_at' => $instance['created_at'] ?? $timestamp,
            'updated_at' => $instance['updated_at'] ?? $timestamp,
        ]);

        $this->connection->table($this->instanceLocatorTable)->insert([
            'instance_id' => (string) $instance['instance_id'],
            'workflow_definition_id' => $definitionId,
            'instances_table' => $storage['instances_table'],
            'histories_table' => $storage['histories_table'],
            'tenant_id' => $instance['tenant_id'] ?? null,
            'state' => (string) $instance['state'],
            'subject_type' => $instance['subject_type'] ?? null,
            'subject_id' => $instance['subject_id'] ?? null,
            'created_at' => $instance['created_at'] ?? $timestamp,
            'updated_at' => $instance['updated_at'] ?? $timestamp,
        ]);

        return $this->getInstance((string) $instance['instance_id']);
    }

    public function getInstance(string $instanceId): array
    {
        $locator = $this->instanceLocator($instanceId);

        $row = $this->connection->table((string) $locator['instances_table'])
            ->where('instance_id', $instanceId)
            ->first();

        if ($row === null) {
            throw new WorkflowException('Workflow instance not found: ' . $instanceId);
        }

        return $this->hydrateInstance((array) $row);
    }

    public function updateInstanceWithVersionCheck(array $instance, int $expectedVersion): array
    {
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);
        $locator = $this->instanceLocator((string) $instance['instance_id']);
        $instancesTable = (string) $locator['instances_table'];

        $affected = $this->connection->table($instancesTable)
            ->where('instance_id', (string) $instance['instance_id'])
            ->where('version', $expectedVersion)
            ->update([
                'state' => (string) $instance['state'],
                'data' => json_encode($instance['data'] ?? [], JSON_THROW_ON_ERROR),
                'version' => (int) $instance['version'],
                'updated_at' => $instance['updated_at'] ?? $timestamp,
            ]);

        if ($affected !== 1) {
            throw OptimisticLockException::forInstance((string) $instance['instance_id'], $expectedVersion);
        }

        $this->connection->table($this->instanceLocatorTable)
            ->where('instance_id', (string) $instance['instance_id'])
            ->update([
                'state' => (string) $instance['state'],
                'updated_at' => $instance['updated_at'] ?? $timestamp,
            ]);

        return $this->getInstance((string) $instance['instance_id']);
    }

    public function appendHistory(array $history): void
    {
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);
        $locator = $this->instanceLocator((string) $history['instance_id']);
        $historiesTable = (string) $locator['histories_table'];
        $payload = $history['payload'] ?? $history;

        if (!is_array($payload)) {
            $payload = ['value' => $payload];
        }

        $this->connection->table($historiesTable)->insert([
            'instance_id' => (string) $history['instance_id'],
            'transition_id' => (string) ($history['transition_id'] ?? ''),
            'action' => (string) $history['action'],
            'from_state' => (string) $history['from_state'],
            'to_state' => (string) $history['to_state'],
            'actor' => $history['actor'] ?? null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $history['created_at'] ?? $timestamp,
            'updated_at' => $history['created_at'] ?? $timestamp,
        ]);
    }

    public function getHistory(string $instanceId): array
    {
        $locator = $this->instanceLocator($instanceId);
        $rows = $this->connection->table((string) $locator['histories_table'])
            ->where('instance_id', $instanceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            $result[] = [
                'id' => (int) $row->id,
                'instance_id' => (string) $row->instance_id,
                'transition_id' => (string) $row->transition_id,
                'action' => (string) $row->action,
                'from_state' => (string) $row->from_state,
                'to_state' => (string) $row->to_state,
                'actor' => $row->actor,
                'payload' => is_array($payload) ? $payload : [],
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateDefinition(array $row): array
    {
        $definition = json_decode((string) ($row['definition'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($definition)) {
            throw new WorkflowException('Persisted workflow definition is invalid');
        }

        $definition['id'] = (int) $row['id'];
        $definition['workflow_name'] = (string) $row['workflow_name'];
        $definition['tenant_id'] = $row['tenant_id'] ?? null;
        $definition['is_active'] = (bool) $row['is_active'];

        return $definition;
    }

    private function activeScope(string $workflowName, ?string $tenantId): string
    {
        return $workflowName . '::' . ($tenantId ?? '__default__');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateInstance(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

        return [
            'instance_id' => (string) $row['instance_id'],
            'workflow_definition_id' => (int) $row['workflow_definition_id'],
            'tenant_id' => $row['tenant_id'] ?? null,
            'state' => (string) $row['state'],
            'data' => is_array($data) ? $data : [],
            'version' => (int) $row['version'],
            'subject_type' => $row['subject_type'] ?? null,
            'subject_id' => $row['subject_id'] ?? null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, string> $subjectRef
     *
     * @return array<string, mixed>|null
     */
    public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array
    {
        $query = $this->connection->table($this->instanceLocatorTable)
            ->join($this->definitionsTable, $this->instanceLocatorTable . '.workflow_definition_id', '=', $this->definitionsTable . '.id')
            ->where($this->definitionsTable . '.workflow_name', $workflowName)
            ->where($this->instanceLocatorTable . '.subject_type', $subjectRef['subject_type'] ?? null)
            ->where($this->instanceLocatorTable . '.subject_id', $subjectRef['subject_id'] ?? null);

        if ($tenantId === null) {
            $query->whereNull($this->instanceLocatorTable . '.tenant_id');
        } else {
            $query->where($this->instanceLocatorTable . '.tenant_id', $tenantId);
        }

        $row = $query->orderByDesc($this->instanceLocatorTable . '.created_at')
            ->orderByDesc($this->instanceLocatorTable . '.instance_id')
            ->first([$this->instanceLocatorTable . '.instance_id']);

        if ($row === null) {
            return null;
        }

        return $this->getInstance((string) $row->instance_id);
    }

    /**
     * @param array<string, string> $subjectRef
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array
    {
        $query = $this->connection->table($this->instanceLocatorTable)
            ->where($this->instanceLocatorTable . '.subject_type', $subjectRef['subject_type'] ?? null)
            ->where($this->instanceLocatorTable . '.subject_id', $subjectRef['subject_id'] ?? null);

        if ($tenantId === null) {
            $query->whereNull($this->instanceLocatorTable . '.tenant_id');
        } else {
            $query->where($this->instanceLocatorTable . '.tenant_id', $tenantId);
        }

        if ($workflowName !== null) {
            $query->join($this->definitionsTable, $this->instanceLocatorTable . '.workflow_definition_id', '=', $this->definitionsTable . '.id')
                ->where($this->definitionsTable . '.workflow_name', $workflowName);
        }

        $rows = $query->orderBy($this->instanceLocatorTable . '.created_at')
            ->get([$this->instanceLocatorTable . '.instance_id']);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->getInstance((string) $row->instance_id);
        }

        return $result;
    }

    /**
     * @param array<string, string> $subjectRef
     * @param array<int, string> $finalStates
     *
     * @return array<string, mixed>|null
     */
    public function getLatestActiveInstanceForSubject(
        string $workflowName,
        array $subjectRef,
        array $finalStates,
        ?string $tenantId = null
    ): ?array {
        $query = $this->connection->table($this->instanceLocatorTable)
            ->join($this->definitionsTable, $this->instanceLocatorTable . '.workflow_definition_id', '=', $this->definitionsTable . '.id')
            ->where($this->definitionsTable . '.workflow_name', $workflowName)
            ->where($this->instanceLocatorTable . '.subject_type', $subjectRef['subject_type'] ?? null)
            ->where($this->instanceLocatorTable . '.subject_id', $subjectRef['subject_id'] ?? null);

        if ($tenantId === null) {
            $query->whereNull($this->instanceLocatorTable . '.tenant_id');
        } else {
            $query->where($this->instanceLocatorTable . '.tenant_id', $tenantId);
        }

        if ($finalStates !== []) {
            $query->whereNotIn($this->instanceLocatorTable . '.state', $finalStates);
        }

        $row = $query->orderByDesc($this->instanceLocatorTable . '.created_at')
            ->orderByDesc($this->instanceLocatorTable . '.instance_id')
            ->first([$this->instanceLocatorTable . '.instance_id']);

        if ($row === null) {
            return null;
        }

        return $this->getInstance((string) $row->instance_id);
    }

    /**
     * @return array{instances_table: string, histories_table: string, outbox_table: string|null}
     */
    private function definitionStorage(array $definition): array
    {
        return $this->storageBindingResolver->resolveFromDefinition($definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function instanceLocator(string $instanceId): array
    {
        $this->ensureLocatorTable();

        $row = $this->connection->table($this->instanceLocatorTable)
            ->where('instance_id', $instanceId)
            ->first();

        if ($row === null) {
            throw new WorkflowException('Workflow instance not found: ' . $instanceId);
        }

        return (array) $row;
    }

    private function ensureLocatorTable(): void
    {
        $schema = $this->schemaBuilder();

        if ($schema->hasTable($this->instanceLocatorTable)) {
            return;
        }

        $schema->create($this->instanceLocatorTable, function (Blueprint $table): void {
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
    }

    private function ensureRuntimeTables(string $instancesTable, string $historiesTable): void
    {
        $schema = $this->schemaBuilder();

        if (!$schema->hasTable($instancesTable)) {
            $schema->create($instancesTable, function (Blueprint $table): void {
                $table->uuid('instance_id')->primary();
                $table->unsignedBigInteger('workflow_definition_id');
                $table->string('tenant_id')->nullable();
                $table->string('state');
                $table->json('data');
                $table->unsignedInteger('version')->default(0);
                $table->string('subject_type')->nullable();
                $table->string('subject_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'state']);
                $table->index(['workflow_definition_id']);
                $table->index(['tenant_id', 'subject_type', 'subject_id']);
                $table->index(['workflow_definition_id', 'subject_type', 'subject_id']);
            });
        }

        if (!$schema->hasTable($historiesTable)) {
            $schema->create($historiesTable, function (Blueprint $table): void {
                $table->id();
                $table->uuid('instance_id');
                $table->string('transition_id');
                $table->string('action');
                $table->string('from_state');
                $table->string('to_state');
                $table->string('actor')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['instance_id']);
                $table->index(['created_at']);
            });
        }
    }

    private function schemaBuilder(): SchemaBuilder
    {
        $connection = $this->connection;

        if (!method_exists($connection, 'getSchemaBuilder')) {
            throw new WorkflowException('Database connection does not support schema operations');
        }

        /** @var SchemaBuilder $builder */
        $builder = call_user_func([$connection, 'getSchemaBuilder']);

        return $builder;
    }
}
