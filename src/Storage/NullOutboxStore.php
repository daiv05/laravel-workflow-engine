<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;

class NullOutboxStore implements OutboxStoreInterface
{
    public function store(string $eventName, array $payload): string
    {
        return '';
    }

    public function markDispatched(string $outboxId): void
    {
    }

    public function fetchPending(int $limit, int $maxAttempts): array
    {
        return [];
    }

    public function markFailed(string $outboxId, string $errorMessage): void
    {
    }
}
