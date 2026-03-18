<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface EventDispatcherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function queue(string $eventName, array $payload = []): void;

    public function flushAfterCommit(): void;

    public function clearQueue(): void;

    /**
     * @return array<int, array{name: string, payload: array<string, mixed>}>
     */
    public function dispatchedEvents(): array;
}
