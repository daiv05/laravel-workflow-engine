<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Storage\DatabaseOutboxStore;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class DatabaseOutboxStoreTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('workflow_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts', 'created_at'], 'wf_outbox_status_attempts_created_idx');
        });

        $schema->create('workflow_outbox_tables', function (Blueprint $table): void {
            $table->string('table_name')->primary();
            $table->timestamp('registered_at')->nullable();
        });
    }

    public function test_store_registers_custom_outbox_table_and_sanitizes_payload(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());

        $pointer = $store->store('workflow.event.custom', [
            '__outbox_table' => 'workflow_outbox_tenant_a',
            'ticket' => 123,
        ]);

        [$table, $id] = $this->splitPointer($pointer);

        $this->assertSame('workflow_outbox_tenant_a', $table);
        $this->assertNotSame('', $id);

        $record = $this->capsule->getConnection()->table('workflow_outbox_tenant_a')->where('id', $id)->first();
        $this->assertNotNull($record);

        $payload = json_decode((string) $record->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['ticket' => 123], $payload);

        $registered = $this->capsule->getConnection()->table('workflow_outbox_tables')
            ->where('table_name', 'workflow_outbox_tenant_a')
            ->exists();

        $this->assertTrue($registered);
    }

    public function test_mark_dispatched_and_mark_failed_support_pointer_and_raw_id(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());

        $rawPointer = $store->store('workflow.event.default', ['key' => 'value']);
        [, $rawId] = $this->splitPointer($rawPointer);

        $store->markFailed($rawId, str_repeat('E', 1200));

        $failedRow = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $rawId)->first();
        $this->assertSame('failed', $failedRow->status);
        $this->assertSame(1, (int) $failedRow->attempts);
        $this->assertSame(1000, strlen((string) $failedRow->last_error));

        $store->markDispatched($rawId);

        $dispatchedRow = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $rawId)->first();
        $this->assertSame('dispatched', $dispatchedRow->status);
        $this->assertNull($dispatchedRow->last_error);
        $this->assertNotNull($dispatchedRow->dispatched_at);

        // Empty pointers are ignored and should not throw.
        $store->markDispatched('');
        $store->markFailed('', 'ignored');

        $afterEmptyPointers = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $rawId)->first();
        $this->assertSame('dispatched', $afterEmptyPointers->status);
        $this->assertSame(1, (int) $afterEmptyPointers->attempts);
    }

    public function test_fetch_pending_merges_tables_sorts_and_applies_limit_and_attempt_filter(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());

        $defaultPointer = $store->store('workflow.event.default.old', ['n' => 1]);
        $customPointerA = $store->store('workflow.event.custom.new', [
            '__outbox_table' => 'workflow_outbox_tenant_a',
            'n' => 2,
        ]);
        $customPointerB = $store->store('workflow.event.custom.skipped', [
            '__outbox_table' => 'workflow_outbox_tenant_a',
            'n' => 3,
        ]);

        [, $defaultId] = $this->splitPointer($defaultPointer);
        [, $customIdA] = $this->splitPointer($customPointerA);
        [, $customIdB] = $this->splitPointer($customPointerB);

        $this->capsule->getConnection()->table('workflow_outbox')->where('id', $defaultId)->update([
            'status' => 'failed',
            'attempts' => 1,
            'created_at' => '2026-03-24T10:00:00+00:00',
        ]);

        $this->capsule->getConnection()->table('workflow_outbox_tenant_a')->where('id', $customIdA)->update([
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => '2026-03-24T11:00:00+00:00',
        ]);

        $this->capsule->getConnection()->table('workflow_outbox_tenant_a')->where('id', $customIdB)->update([
            'status' => 'failed',
            'attempts' => 5,
            'created_at' => '2026-03-24T09:00:00+00:00',
        ]);

        $items = $store->fetchPending(2, 5);

        $this->assertCount(2, $items);
        $this->assertSame('workflow.event.default.old', $items[0]['event_name']);
        $this->assertSame('workflow.event.custom.new', $items[1]['event_name']);
        $this->assertArrayNotHasKey('created_at', $items[0]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPointer(string $pointer): array
    {
        return explode('::', $pointer, 2);
    }
}
