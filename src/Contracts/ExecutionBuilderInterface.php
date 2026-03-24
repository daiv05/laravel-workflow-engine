<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface ExecutionBuilderInterface
{
    public function forInstance(string $instanceId): self;

    public function on(string $event, callable $callback): self;

    public function onAny(callable $callback): self;

    public function before(callable $callback): self;

    public function after(callable $callback): self;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(string $action, array $context = []): array;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function update(array $context = []): array;
}
