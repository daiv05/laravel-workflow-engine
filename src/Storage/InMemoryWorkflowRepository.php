<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\OptimisticLockException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;

class InMemoryWorkflowRepository implements StorageRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $definitionsById = [];

    /** @var array<string, int> */
    private array $activeDefinitionMap = [];

    /** @var array<string, int> */
    private array $definitionVersionMap = [];

    /** @var array<string, array<string, mixed>> */
    private array $instancesById = [];

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private int $definitionAutoIncrement = 0;

    public function transaction(callable $callback): mixed
    {
        $definitionsById = $this->definitionsById;
        $activeDefinitionMap = $this->activeDefinitionMap;
        $definitionVersionMap = $this->definitionVersionMap;
        $instancesById = $this->instancesById;
        $history = $this->history;
        $definitionAutoIncrement = $this->definitionAutoIncrement;

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->definitionsById = $definitionsById;
            $this->activeDefinitionMap = $activeDefinitionMap;
            $this->definitionVersionMap = $definitionVersionMap;
            $this->instancesById = $instancesById;
            $this->history = $history;
            $this->definitionAutoIncrement = $definitionAutoIncrement;

            throw $exception;
        }
    }

    public function activateDefinition(string $workflowName, array $definition, ?string $tenantId = null): int
    {
        $version = (int) ($definition['version'] ?? 0);
        $scopeVersionKey = $this->scopeVersionKey($workflowName, $tenantId, $version);

        if (isset($this->definitionVersionMap[$scopeVersionKey])) {
            throw new WorkflowException('Workflow definition version is immutable and already exists for scope');
        }

        $activeKey = $this->activeKey($workflowName, $tenantId);
        unset($this->activeDefinitionMap[$activeKey]);

        $this->definitionAutoIncrement++;
        $definitionId = $this->definitionAutoIncrement;

        $stored = $definition;
        $stored['id'] = $definitionId;
        $stored['workflow_name'] = $workflowName;
        $stored['tenant_id'] = $tenantId;
        $stored['is_active'] = true;

        $this->definitionsById[$definitionId] = $stored;
        $this->activeDefinitionMap[$activeKey] = $definitionId;
        $this->definitionVersionMap[$scopeVersionKey] = $definitionId;

        return $definitionId;
    }

    public function getActiveDefinition(string $workflowName, ?string $tenantId = null): array
    {
        $activeKey = $this->activeKey($workflowName, $tenantId);

        if (!isset($this->activeDefinitionMap[$activeKey])) {
            throw new WorkflowException('No active workflow definition found for workflow and tenant');
        }

        $definitionId = $this->activeDefinitionMap[$activeKey];
        return $this->definitionsById[$definitionId];
    }

    public function getDefinitionById(int $definitionId): array
    {
        if (!isset($this->definitionsById[$definitionId])) {
            throw new WorkflowException('Workflow definition not found by id: ' . $definitionId);
        }

        return $this->definitionsById[$definitionId];
    }

    public function createInstance(array $instance): array
    {
        $instanceId = (string) ($instance['instance_id'] ?? '');
        if ($instanceId === '') {
            throw new WorkflowException('instance_id is required');
        }

        $this->instancesById[$instanceId] = $instance;

        return $instance;
    }

    public function getInstance(string $instanceId): array
    {
        if (!isset($this->instancesById[$instanceId])) {
            throw new WorkflowException('Workflow instance not found: ' . $instanceId);
        }

        return $this->instancesById[$instanceId];
    }

    public function updateInstanceWithVersionCheck(array $instance, int $expectedVersion): array
    {
        $instanceId = (string) ($instance['instance_id'] ?? '');
        $stored = $this->getInstance($instanceId);

        $currentVersion = (int) ($stored['version'] ?? 0);

        if ($currentVersion !== $expectedVersion) {
            throw OptimisticLockException::forInstance($instanceId, $expectedVersion, $currentVersion);
        }

        $this->instancesById[$instanceId] = $instance;

        return $instance;
    }

    public function appendHistory(array $history): void
    {
        if (!array_key_exists('payload', $history)) {
            $history['payload'] = [
                'transition_id' => $history['transition_id'] ?? '',
                'action' => $history['action'] ?? '',
                'from_state' => $history['from_state'] ?? '',
                'to_state' => $history['to_state'] ?? '',
            ];
        }

        $this->history[] = $history;
    }

    public function getHistory(string $instanceId): array
    {
        $result = [];

        foreach ($this->history as $entry) {
            if (($entry['instance_id'] ?? null) !== $instanceId) {
                continue;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function activeKey(string $workflowName, ?string $tenantId): string
    {
        return $workflowName . '::' . ($tenantId ?? '__default__');
    }

    private function scopeVersionKey(string $workflowName, ?string $tenantId, int $version): string
    {
        return $this->activeKey($workflowName, $tenantId) . '::v' . $version;
    }

    /**
     * @param array<string, string> $subjectRef
     *
     * @return array<string, mixed>|null
     */
    public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array
    {
        $latest = null;
        $latestCreatedAt = null;

        foreach ($this->instancesById as $instance) {
            if (($instance['subject_type'] ?? null) !== ($subjectRef['subject_type'] ?? null)) {
                continue;
            }

            if (($instance['subject_id'] ?? null) !== ($subjectRef['subject_id'] ?? null)) {
                continue;
            }

            $definitionId = $instance['workflow_definition_id'] ?? null;
            if ($definitionId === null || !isset($this->definitionsById[$definitionId])) {
                continue;
            }

            $definition = $this->definitionsById[$definitionId];
            if (($definition['workflow_name'] ?? null) !== $workflowName) {
                continue;
            }

            $instanceTenantId = $instance['tenant_id'] ?? null;
            if ($tenantId === null && $instanceTenantId !== null) {
                continue;
            }

            if ($tenantId !== null && $instanceTenantId !== $tenantId) {
                continue;
            }

            $createdAt = $instance['created_at'] ?? '0000-00-00T00:00:00+00:00';
            if ($latestCreatedAt === null || $createdAt > $latestCreatedAt) {
                $latestCreatedAt = $createdAt;
                $latest = $instance;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, string> $subjectRef
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array
    {
        $result = [];

        foreach ($this->instancesById as $instance) {
            if (($instance['subject_type'] ?? null) !== ($subjectRef['subject_type'] ?? null)) {
                continue;
            }

            if (($instance['subject_id'] ?? null) !== ($subjectRef['subject_id'] ?? null)) {
                continue;
            }

            $instanceTenantId = $instance['tenant_id'] ?? null;
            if ($tenantId === null && $instanceTenantId !== null) {
                continue;
            }

            if ($tenantId !== null && $instanceTenantId !== $tenantId) {
                continue;
            }

            if ($workflowName !== null) {
                $definitionId = $instance['workflow_definition_id'] ?? null;
                if ($definitionId === null || !isset($this->definitionsById[$definitionId])) {
                    continue;
                }

                $definition = $this->definitionsById[$definitionId];
                if (($definition['workflow_name'] ?? null) !== $workflowName) {
                    continue;
                }
            }

            $result[] = $instance;
        }

        usort($result, fn ($a, $b) => strcmp($a['created_at'] ?? '0000-00-00', $b['created_at'] ?? '0000-00-00'));

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
        $latest = null;
        $latestCreatedAt = null;

        foreach ($this->instancesById as $instance) {
            if (($instance['subject_type'] ?? null) !== ($subjectRef['subject_type'] ?? null)) {
                continue;
            }

            if (($instance['subject_id'] ?? null) !== ($subjectRef['subject_id'] ?? null)) {
                continue;
            }

            $instanceTenantId = $instance['tenant_id'] ?? null;
            if ($tenantId === null && $instanceTenantId !== null) {
                continue;
            }

            if ($tenantId !== null && $instanceTenantId !== $tenantId) {
                continue;
            }

            $definitionId = $instance['workflow_definition_id'] ?? null;
            if ($definitionId === null || !isset($this->definitionsById[$definitionId])) {
                continue;
            }

            $definition = $this->definitionsById[$definitionId];
            if (($definition['workflow_name'] ?? null) !== $workflowName) {
                continue;
            }

            $state = is_string($instance['state'] ?? null) ? $instance['state'] : '';
            if ($state !== '' && in_array($state, $finalStates, true)) {
                continue;
            }

            $createdAt = $instance['created_at'] ?? '0000-00-00T00:00:00+00:00';
            if ($latestCreatedAt === null || $createdAt > $latestCreatedAt) {
                $latestCreatedAt = $createdAt;
                $latest = $instance;
            }
        }

        return $latest;
    }
}
