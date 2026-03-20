<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface StorageRepositoryInterface
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * @param array<string, mixed> $definition
     */
    public function activateDefinition(string $workflowName, array $definition, ?string $tenantId = null): int;

    /**
     * @return array<string, mixed>
     */
    public function getActiveDefinition(string $workflowName, ?string $tenantId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getDefinitionById(int $definitionId): array;

    /**
     * @param array<string, mixed> $instance
     *
     * @return array<string, mixed>
     */
    public function createInstance(array $instance): array;

    /**
     * @return array<string, mixed>
     */
    public function getInstance(string $instanceId): array;

    /**
     * @param array<string, mixed> $instance
     *
     * @return array<string, mixed>
     */
    public function updateInstanceWithVersionCheck(array $instance, int $expectedVersion): array;

    /**
     * @param array<string, mixed> $history
     */
    public function appendHistory(array $history): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(string $instanceId): array;

    /**
     * Find the latest instance for a subject by workflow name and subject reference.
     *
     * @param string $workflowName
     * @param array<string, string> $subjectRef ['subject_type' => string, 'subject_id' => string]
     * @param string|null $tenantId
     *
     * @return array<string, mixed>|null
     */
    public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array;

    /**
     * Find all instances for a subject reference.
     *
     * @param array<string, string> $subjectRef ['subject_type' => string, 'subject_id' => string]
     * @param string|null $tenantId
     * @param string|null $workflowName optional filter by workflow
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array;

    /**
     * Find the latest active instance for a subject reference in a workflow scope.
     *
     * @param array<string, string> $subjectRef ['subject_type' => string, 'subject_id' => string]
     * @param array<int, string> $finalStates states considered terminal for this workflow definition
     * @param string|null $tenantId
     *
     * @return array<string, mixed>|null
     */
    public function getLatestActiveInstanceForSubject(
        string $workflowName,
        array $subjectRef,
        array $finalStates,
        ?string $tenantId = null
    ): ?array;
}
