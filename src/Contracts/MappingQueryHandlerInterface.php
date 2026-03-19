<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface MappingQueryHandlerInterface
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function fetch(array $context, array $options = []): mixed;
}
