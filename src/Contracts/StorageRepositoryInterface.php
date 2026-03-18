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
}
