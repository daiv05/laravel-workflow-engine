<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\StorageBindingResolverInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;

class ConfigStorageBindingResolver implements StorageBindingResolverInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $bindings;

    public function __construct(
        array $bindings = [],
        private readonly string $defaultBinding = 'default',
        private readonly string $fallbackInstancesTable = 'workflow_instances',
        private readonly string $fallbackHistoriesTable = 'workflow_histories',
        private readonly ?string $fallbackOutboxTable = null
    ) {
        $this->bindings = $bindings;
    }

    public function resolveFromDefinition(array $definition): array
    {
        $storage = isset($definition['storage']) && is_array($definition['storage']) ? $definition['storage'] : [];

        return $this->resolveFromStorage($storage);
    }

    public function resolveFromStorage(array $storage): array
    {
        if ($this->hasResolvedTables($storage)) {
            $binding = isset($storage['binding']) && is_string($storage['binding']) && $storage['binding'] !== ''
                ? $storage['binding']
                : $this->defaultBinding;

            $outboxTable = $storage['outbox_table'] ?? null;
            if ($outboxTable !== null && (!$this->isValidTableName($outboxTable))) {
                throw new WorkflowException('Invalid storage outbox_table in definition snapshot');
            }

            return [
                'binding' => $binding,
                'instances_table' => (string) $storage['instances_table'],
                'histories_table' => (string) $storage['histories_table'],
                'outbox_table' => is_string($outboxTable) ? $outboxTable : null,
            ];
        }

        $binding = $storage['binding'] ?? $this->defaultBinding;

        if (!is_string($binding) || trim($binding) === '') {
            throw new WorkflowException('storage.binding must be a non-empty string');
        }

        return $this->resolveBinding(trim($binding));
    }

    /**
     * @return array{binding: string, instances_table: string, histories_table: string, outbox_table: string|null}
     */
    private function resolveBinding(string $binding): array
    {
        if (!isset($this->bindings[$binding])) {
            if ($binding === $this->defaultBinding && $this->bindings === []) {
                return [
                    'binding' => $binding,
                    'instances_table' => $this->fallbackInstancesTable,
                    'histories_table' => $this->fallbackHistoriesTable,
                    'outbox_table' => $this->fallbackOutboxTable,
                ];
            }

            throw new WorkflowException('Storage binding not found: ' . $binding);
        }

        $config = $this->bindings[$binding];

        if (!is_array($config)) {
            throw new WorkflowException('Storage binding must be an object-like array: ' . $binding);
        }

        $instancesTable = $config['instances_table'] ?? null;
        $historiesTable = $config['histories_table'] ?? null;
        $outboxTable = $config['outbox_table'] ?? null;

        if (!$this->isValidTableName($instancesTable)) {
            throw new WorkflowException('Invalid instances_table for storage binding: ' . $binding);
        }

        if (!$this->isValidTableName($historiesTable)) {
            throw new WorkflowException('Invalid histories_table for storage binding: ' . $binding);
        }

        if ($outboxTable !== null && !$this->isValidTableName($outboxTable)) {
            throw new WorkflowException('Invalid outbox_table for storage binding: ' . $binding);
        }

        return [
            'binding' => $binding,
            'instances_table' => $instancesTable,
            'histories_table' => $historiesTable,
            'outbox_table' => is_string($outboxTable) ? $outboxTable : null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function isValidTableName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $storage
     */
    private function hasResolvedTables(array $storage): bool
    {
        if (!isset($storage['instances_table'], $storage['histories_table'])) {
            return false;
        }

        return $this->isValidTableName($storage['instances_table'])
            && $this->isValidTableName($storage['histories_table']);
    }
}
