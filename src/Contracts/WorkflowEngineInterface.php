<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface WorkflowEngineInterface
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function start(string $workflowName, array $options = []): array;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(string $instanceId, string $action, array $context = []): array;

    /**
     * @param array<string, mixed> $context
     */
    public function can(string $instanceId, string $action, array $context = []): bool;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, array<int, string>>
     */
    public function visibleFields(string $instanceId, array $context = []): array;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    public function availableActions(string $instanceId, array $context = []): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(string $instanceId): array;
}
