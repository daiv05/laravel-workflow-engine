<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface WorkflowManagerInterface
{
    /**
     * @param array<string, mixed> $definition
     */
    public function activateDefinition(string $workflowName, array|string $definition, ?string $tenantId = null): int;

    public function registerFunction(string $name, callable $function): void;
}
