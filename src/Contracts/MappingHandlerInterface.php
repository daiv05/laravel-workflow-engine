<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface MappingHandlerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $value, array $context): ?array;
}
