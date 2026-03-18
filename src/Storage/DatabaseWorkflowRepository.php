<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\OptimisticLockException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

class DatabaseWorkflowRepository implements StorageRepositoryInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return $this->connection->transaction(static fn () => $callback());
    }

    public function activateDefinition(string $workflowName, array $definition, ?string $tenantId = null): int
    {
        return (int) $this->connection->transaction(function () use ($workflowName, $definition, $tenantId): int {
            $version = (int) ($definition['version'] ?? 0);

            $existingVersionQuery = $this->connection->table('workflow_definitions')
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

            $deactivate = $this->connection->table('workflow_definitions')
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

            return (int) $this->connection->table('workflow_definitions')->insertGetId([
                'workflow_name' => $workflowName,
                'version' => $version,
                'tenant_id' => $tenantId,
                'dsl_version' => (int) $definition['dsl_version'],
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'active_scope' => $activeScope,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function getActiveDefinition(string $workflowName, ?string $tenantId = null): array
    {
        $query = $this->connection->table('workflow_definitions')
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
        $row = $this->connection->table('workflow_definitions')
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

        $this->connection->table('workflow_instances')->insert([
            'instance_id' => (string) $instance['instance_id'],
            'workflow_definition_id' => (int) $instance['workflow_definition_id'],
            'tenant_id' => $instance['tenant_id'] ?? null,
            'state' => (string) $instance['state'],
            'data' => json_encode($instance['data'] ?? [], JSON_THROW_ON_ERROR),
            'version' => (int) ($instance['version'] ?? 0),
            'created_at' => $instance['created_at'] ?? $timestamp,
            'updated_at' => $instance['updated_at'] ?? $timestamp,
        ]);

        return $this->getInstance((string) $instance['instance_id']);
    }

    public function getInstance(string $instanceId): array
    {
        $row = $this->connection->table('workflow_instances')
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

        $affected = $this->connection->table('workflow_instances')
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

        return $this->getInstance((string) $instance['instance_id']);
    }

    public function appendHistory(array $history): void
    {
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table('workflow_histories')->insert([
            'instance_id' => (string) $history['instance_id'],
            'transition_id' => (string) ($history['transition_id'] ?? ''),
            'action' => (string) $history['action'],
            'from_state' => (string) $history['from_state'],
            'to_state' => (string) $history['to_state'],
            'actor' => $history['actor'] ?? null,
            'payload' => json_encode($history, JSON_THROW_ON_ERROR),
            'created_at' => $history['created_at'] ?? $timestamp,
            'updated_at' => $history['created_at'] ?? $timestamp,
        ]);
    }

    public function getHistory(string $instanceId): array
    {
        $rows = $this->connection->table('workflow_histories')
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
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
