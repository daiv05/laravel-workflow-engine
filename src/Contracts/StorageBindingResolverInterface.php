<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface StorageBindingResolverInterface
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return array{binding: string, instances_table: string, histories_table: string, outbox_table: string|null}
     */
    public function resolveFromDefinition(array $definition): array;

    /**
     * @param array<string, mixed> $storage
     *
     * @return array{binding: string, instances_table: string, histories_table: string, outbox_table: string|null}
     */
    public function resolveFromStorage(array $storage): array;
}
