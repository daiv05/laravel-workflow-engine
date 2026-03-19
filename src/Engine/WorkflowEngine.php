<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Contracts\DataMapperInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\WorkflowEngineInterface;
use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class WorkflowEngine implements WorkflowEngineInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $activeDefinitionCache = [];

    /** @var array<int, array<string, mixed>> */
    private array $definitionByIdCache = [];

    public function __construct(
        private readonly StorageRepositoryInterface $storage,
        private readonly Parser $parser,
        private readonly Validator $validator,
        private readonly Compiler $compiler,
        private readonly StateMachine $stateMachine,
        private readonly TransitionExecutor $executor,
        private readonly FieldEngine $fieldEngine,
        private readonly PolicyEngine $policy,
        private readonly FunctionRegistry $functions,
        private readonly ?CacheRepository $cache = null,
        private readonly bool $cacheEnabled = true,
        private readonly int $cacheTtl = 300,
        private readonly ?DataMapperInterface $dataMapper = null,
        private readonly string $defaultTenantId = 'tenant-default'
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function start(string $workflowName, array $options = []): array
    {
        $tenantId = $this->resolveTenantId();
        $definition = $this->getActiveDefinitionCached($workflowName, $tenantId);

        $instance = [
            'instance_id' => $this->uuidV4(),
            'workflow_definition_id' => $definition['id'],
            'tenant_id' => $tenantId,
            'state' => $definition['initial_state'],
            'data' => $options['data'] ?? [],
            'version' => 0,
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
        ];

        return $this->storage->createInstance($instance);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(string $instanceId, string $action, array $context = []): array
    {
        return $this->executeWithListeners($instanceId, $action, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $listeners
     *
     * @return array<string, mixed>
     */
    public function executeWithListeners(string $instanceId, string $action, array $context = [], array $listeners = []): array
    {
        $instance = $this->storage->getInstance($instanceId);
        $definition = $this->getDefinitionByIdCached((int) $instance['workflow_definition_id']);

        return $this->executor->executeWithListeners($instance, $definition, $action, $context, $listeners);
    }

    public function execution(?string $instanceId = null): ExecutionBuilder
    {
        return new ExecutionBuilder($this, $instanceId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function can(string $instanceId, string $action, array $context = []): bool
    {
        $instance = $this->storage->getInstance($instanceId);
        $definition = $this->getDefinitionByIdCached((int) $instance['workflow_definition_id']);

        $transition = $this->stateMachine->transitionFor($definition, (string) $instance['state'], $action);
        if ($transition === null) {
            return false;
        }

        // can() answers "is this executable now by this actor" without mutating state.
        try {
            return $this->policy->canExecuteTransition($transition, $context);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, array<int, string>>
     */
    public function visibleFields(string $instanceId, array $context = []): array
    {
        $instance = $this->storage->getInstance($instanceId);
        $definition = $this->getDefinitionByIdCached((int) $instance['workflow_definition_id']);

        if ($this->stateMachine->isFinalState($definition, (string) $instance['state'])) {
            return [];
        }

        $result = [];

        foreach ($definition['transitions'] as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            if (($transition['from'] ?? null) !== $instance['state']) {
                continue;
            }

            $action = isset($transition['action']) && is_string($transition['action']) ? $transition['action'] : 'unknown';
            $result[$action] = $this->fieldEngine->fieldsForTransition($transition, $context);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    public function availableActions(string $instanceId, array $context = []): array
    {
        $instance = $this->storage->getInstance($instanceId);
        $definition = $this->getDefinitionByIdCached((int) $instance['workflow_definition_id']);

        if ($this->stateMachine->isFinalState($definition, (string) $instance['state'])) {
            return [];
        }

        $actions = [];

        foreach ($definition['transitions'] as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            if (($transition['from'] ?? null) !== $instance['state']) {
                continue;
            }

            $action = $transition['action'] ?? null;
            if (!is_string($action) || $action === '') {
                continue;
            }

            try {
                if ($this->policy->canExecuteTransition($transition, $context)) {
                    $actions[] = $action;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(string $instanceId): array
    {
        return $this->storage->getHistory($instanceId);
    }

    /**
     * Resolve related mapped data for the transition available from current state and action.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function resolveMappedData(string $instanceId, string $action, array $context = [], array $options = []): array
    {
        $instance = $this->storage->getInstance($instanceId);
        $definition = $this->getDefinitionByIdCached((int) $instance['workflow_definition_id']);
        $transition = $this->resolveTransitionForRead($instanceId, $definition, (string) $instance['state'], $action);

        if ($transition === null || !isset($transition['mappings']) || !is_array($transition['mappings'])) {
            return [];
        }

        if ($this->dataMapper === null) {
            throw MappingException::mapperNotConfigured();
        }

        return $this->dataMapper->resolve(
            $transition['mappings'],
            is_array($instance['data'] ?? null) ? $instance['data'] : [],
            [
                'instance' => $instance,
                'transition' => $transition,
                'definition' => $definition,
                'runtime_context' => $context,
                'action' => $action,
            ],
            $options
        );
    }

    /**
     * Resolve transition for read operations.
     *
     * Priority:
     * 1) transition from current state + action
     * 2) latest history entry for action -> transition_id
     * 3) unique transition by action across definition
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>|null
     */
    private function resolveTransitionForRead(string $instanceId, array $definition, string $currentState, string $action): ?array
    {
        $current = $this->stateMachine->transitionFor($definition, $currentState, $action);
        if (is_array($current)) {
            return $current;
        }

        $history = $this->storage->getHistory($instanceId);
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $entry = $history[$index] ?? null;
            if (!is_array($entry) || ($entry['action'] ?? null) !== $action) {
                continue;
            }

            $transitionId = $entry['transition_id'] ?? null;
            if (!is_string($transitionId) || $transitionId === '') {
                continue;
            }

            foreach (($definition['transitions'] ?? []) as $transition) {
                if (!is_array($transition)) {
                    continue;
                }

                if (($transition['transition_id'] ?? null) === $transitionId) {
                    return $transition;
                }
            }
        }

        $candidates = [];

        foreach (($definition['transitions'] ?? []) as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            if (($transition['action'] ?? null) === $action) {
                $candidates[] = $transition;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function activateDefinition(string $workflowName, array|string $definition, ?string $tenantId = null): int
    {
        $tenantId = $this->resolveTenantId();

        $parsed = $this->parser->parse($definition);
        $this->validator->validate($parsed);
        $compiled = $this->compiler->compile($parsed);

        $definitionId = $this->storage->activateDefinition($workflowName, $compiled, $tenantId);
        $persisted = $this->storage->getDefinitionById($definitionId);

        $scopeKey = $this->scopeKey($workflowName, $tenantId);
        $cacheKey = $this->cacheKey($workflowName, $tenantId, (int) $persisted['version']);
        $pointerKey = $this->activePointerKey($workflowName, $tenantId);

        $previousDistributedActiveKey = $this->getCacheValue($pointerKey);
        if (is_string($previousDistributedActiveKey)) {
            if ($this->cacheEnabled && $this->cache !== null) {
                $this->cache->forget($previousDistributedActiveKey);
            }
        }

        // Invalidate previous active definition cache for this scope.
        foreach (array_keys($this->activeDefinitionCache) as $existingKey) {
            if (str_starts_with($existingKey, $scopeKey . '::v')) {
                unset($this->activeDefinitionCache[$existingKey]);
            }
        }

        $this->activeDefinitionCache[$cacheKey] = $persisted;
        $this->definitionByIdCache[$definitionId] = $persisted;
        $this->putCacheValue($cacheKey, $persisted);
        $this->putCacheValue($pointerKey, $cacheKey);
        $this->putCacheValue($this->definitionCacheKey($definitionId), $persisted);

        return $definitionId;
    }

    public function registerFunction(string $name, callable $function): void
    {
        $this->functions->register($name, $function);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array<string, mixed>
     */
    private function getActiveDefinitionCached(string $workflowName, ?string $tenantId): array
    {
        $pointerKey = $this->activePointerKey($workflowName, $tenantId);
        $pointer = $this->getCacheValue($pointerKey);

        if (is_string($pointer)) {
            $cachedDefinition = $this->getCacheValue($pointer);
            if (is_array($cachedDefinition)) {
                $this->definitionByIdCache[(int) $cachedDefinition['id']] = $cachedDefinition;
                return $cachedDefinition;
            }
        }

        $scopePrefix = $this->scopeKey($workflowName, $tenantId) . '::v';

        foreach ($this->activeDefinitionCache as $key => $definition) {
            if (str_starts_with($key, $scopePrefix)) {
                $this->definitionByIdCache[(int) $definition['id']] = $definition;
                return $definition;
            }
        }

        $definition = $this->storage->getActiveDefinition($workflowName, $tenantId);
        $cacheKey = $this->cacheKey($workflowName, $tenantId, (int) $definition['version']);
        $this->activeDefinitionCache[$cacheKey] = $definition;
        $this->definitionByIdCache[(int) $definition['id']] = $definition;
        $this->putCacheValue($cacheKey, $definition);
        $this->putCacheValue($pointerKey, $cacheKey);
        $this->putCacheValue($this->definitionCacheKey((int) $definition['id']), $definition);

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefinitionByIdCached(int $definitionId): array
    {
        $cacheKey = $this->definitionCacheKey($definitionId);

        $cached = $this->getCacheValue($cacheKey);
        if (is_array($cached)) {
            $this->definitionByIdCache[$definitionId] = $cached;
            return $cached;
        }

        if (!isset($this->definitionByIdCache[$definitionId])) {
            $this->definitionByIdCache[$definitionId] = $this->storage->getDefinitionById($definitionId);
            $this->putCacheValue($cacheKey, $this->definitionByIdCache[$definitionId]);
        }

        return $this->definitionByIdCache[$definitionId];
    }

    private function scopeKey(string $workflowName, ?string $tenantId): string
    {
        return $workflowName . '::' . ($tenantId ?? '__default__');
    }

    private function cacheKey(string $workflowName, ?string $tenantId, int $version): string
    {
        return $this->scopeKey($workflowName, $tenantId) . '::v' . $version;
    }

    private function activePointerKey(string $workflowName, ?string $tenantId): string
    {
        return $this->scopeKey($workflowName, $tenantId) . '::active_pointer';
    }

    private function definitionCacheKey(int $definitionId): string
    {
        return 'workflow_definition_id::' . $definitionId;
    }

    private function resolveTenantId(): string
    {
        return $this->defaultTenantId;
    }

    private function getCacheValue(string $key): mixed
    {
        if (!$this->cacheEnabled || $this->cache === null) {
            return null;
        }

        return $this->cache->get($key);
    }

    /**
     * @param array<string, mixed>|string $value
     */
    private function putCacheValue(string $key, mixed $value): void
    {
        if (!$this->cacheEnabled || $this->cache === null) {
            return;
        }

        $this->cache->put($key, $value, $this->cacheTtl);
    }
}
