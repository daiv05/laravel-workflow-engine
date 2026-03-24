<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Facades;

use Daiv05\LaravelWorkflowEngine\Contracts\ExecutionBuilderInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ExecutionBuilderInterface execution(?string $instanceId = null)
 * @method static array<string, mixed> start(string $workflowName, array<string, mixed> $options = [])
 * @method static bool can(string $instanceId, string $action, array<string, mixed> $context = [])
 * @method static array<string, mixed> execute(string $instanceId, string $action, array<string, mixed> $context = [])
 * @method static bool canUpdate(string $instanceId, array $context = [])
 * @method static array<string, mixed> update(string $instanceId, array $context = [])
 * @method static array<string, array<int, string>> visibleFields(string $instanceId, array<string, mixed> $context = [])
 * @method static array<int, string> availableActions(string $instanceId, array<string, mixed> $context = [])
 * @method static array<int, array<string, mixed>> history(string $instanceId)
 * @method static int activateDefinition(string $workflowName, array|string $definition, ?string $tenantId = null)
 * @method static void registerFunction(string $name, callable $function)
 * @method static array<string, mixed> resolveMappedData(string $instanceId, string $action, array<string, mixed> $context = [], array<string, mixed> $options = [])
 * @method static array<string, mixed>|null getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null)
 * @method static array<int, array<string, mixed>> getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null)
 */
class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'workflow';
    }
}
