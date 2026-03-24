<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Storage;

use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Blueprint;

class DatabaseOutboxStore implements OutboxStoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'workflow_outbox',
        private readonly string $registryTable = 'workflow_outbox_tables'
    ) {
        $this->ensureRegistryTable();
        $this->ensureOutboxTable($this->table);
        $this->registerOutboxTable($this->table);
    }

    public function store(string $eventName, array $payload): string
    {
        $table = $this->resolveOutboxTable($payload);
        if (array_key_exists('__outbox_table', $payload)) {
            unset($payload['__outbox_table']);
        }

        $this->ensureOutboxTable($table);
        $this->registerOutboxTable($table);

        $id = $this->uuidV4();
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($table)->insert([
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

        return $this->composeOutboxPointer($table, $id);
    }

    public function markDispatched(string $outboxId): void
    {
        if ($outboxId === '') {
            return;
        }

        [$table, $id] = $this->parseOutboxPointer($outboxId);

        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($table)
            ->where('id', $id)
            ->update([
                'status' => 'dispatched',
                'updated_at' => $timestamp,
                'last_error' => null,
                'dispatched_at' => $timestamp,
            ]);
    }

    public function fetchPending(int $limit, int $maxAttempts): array
    {
        $items = [];

        $tables = $this->registeredOutboxTables();

        foreach ($tables as $table) {
            $rows = $this->connection->table($table)
                ->whereIn('status', ['pending', 'failed'])
                ->where('attempts', '<', $maxAttempts)
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $payload = json_decode((string) ($row->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

                $items[] = [
                    'id' => $this->composeOutboxPointer($table, (string) $row->id),
                    'event_name' => (string) $row->event_name,
                    'payload' => is_array($payload) ? $payload : [],
                    'attempts' => (int) ($row->attempts ?? 0),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
        });

        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as &$item) {
            unset($item['created_at']);
        }
        unset($item);

        return $items;
    }

    public function markFailed(string $outboxId, string $errorMessage): void
    {
        if ($outboxId === '') {
            return;
        }

        [$table, $id] = $this->parseOutboxPointer($outboxId);

        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->table($table)
            ->where('id', $id)
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

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveOutboxTable(array $payload): string
    {
        $table = $payload['__outbox_table'] ?? null;

        if (!is_string($table) || $table === '') {
            return $this->table;
        }

        return $table;
    }

    private function composeOutboxPointer(string $table, string $id): string
    {
        return $table . '::' . $id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseOutboxPointer(string $outboxId): array
    {
        $parts = explode('::', $outboxId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [$this->table, $outboxId];
        }

        return [$parts[0], $parts[1]];
    }

    private function ensureRegistryTable(): void
    {
        $schema = $this->schemaBuilder();

        if ($schema->hasTable($this->registryTable)) {
            return;
        }

        $schema->create($this->registryTable, function (Blueprint $table): void {
            $table->string('table_name')->primary();
            $table->timestamp('registered_at')->nullable();
        });
    }

    private function ensureOutboxTable(string $tableName): void
    {
        $schema = $this->schemaBuilder();

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts', 'created_at']);
        });
    }

    private function registerOutboxTable(string $tableName): void
    {
        $exists = $this->connection->table($this->registryTable)
            ->where('table_name', $tableName)
            ->exists();

        if ($exists) {
            return;
        }

        $this->connection->table($this->registryTable)->insert([
            'table_name' => $tableName,
            'registered_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    private function schemaBuilder(): SchemaBuilder
    {
        $connection = $this->connection;

        if (!method_exists($connection, 'getSchemaBuilder')) {
            throw new \RuntimeException('Database connection does not support schema operations');
        }

        /** @var SchemaBuilder $builder */
        $builder = call_user_func([$connection, 'getSchemaBuilder']);

        return $builder;
    }

    /**
     * @return array<int, string>
     */
    private function registeredOutboxTables(): array
    {
        $rows = $this->connection->table($this->registryTable)->orderBy('table_name')->get();
        $tables = [];

        foreach ($rows as $row) {
            $name = (string) ($row->table_name ?? '');

            if ($name === '') {
                continue;
            }

            $tables[] = $name;
        }

        if (!in_array($this->table, $tables, true)) {
            $tables[] = $this->table;
        }

        return array_values(array_unique($tables));
    }
}
