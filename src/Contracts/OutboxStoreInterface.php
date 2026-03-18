<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface OutboxStoreInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $eventName, array $payload): string;

    public function markDispatched(string $outboxId): void;

    /**
     * @return array<int, array{id: string, event_name: string, payload: array<string, mixed>, attempts: int}>
     */
    public function fetchPending(int $limit, int $maxAttempts): array;

    public function markFailed(string $outboxId, string $errorMessage): void;
}
