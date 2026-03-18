<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

class DatabaseOutboxStore implements OutboxStoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'workflow_outbox'
    ) {
    }

    public function store(string $eventName, array $payload): string
    {
        $id = $this->uuidV4();
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'event_name' => $eventName,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'dispatched_at' => null,
        ]);

        return $id;
    }

    public function markDispatched(string $outboxId): void
    {
        if ($outboxId === '') {
            return;
        }

        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($this->table)
            ->where('id', $outboxId)
            ->update([
                'status' => 'dispatched',
                'updated_at' => $timestamp,
                'last_error' => null,
                'dispatched_at' => $timestamp,
            ]);
    }

    public function fetchPending(int $limit, int $maxAttempts): array
    {
        $rows = $this->connection->table($this->table)
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            $items[] = [
                'id' => (string) $row->id,
                'event_name' => (string) $row->event_name,
                'payload' => is_array($payload) ? $payload : [],
                'attempts' => (int) ($row->attempts ?? 0),
            ];
        }

        return $items;
    }

    public function markFailed(string $outboxId, string $errorMessage): void
    {
        if ($outboxId === '') {
            return;
        }

        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($this->table)
            ->where('id', $outboxId)
            ->update([
                'status' => 'failed',
                'attempts' => $this->connection->raw('attempts + 1'),
                'last_error' => substr($errorMessage, 0, 1000),
                'updated_at' => $timestamp,
            ]);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
