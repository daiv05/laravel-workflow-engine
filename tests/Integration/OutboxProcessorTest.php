<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Integration;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Outbox\OutboxProcessor;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseOutboxStore;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class OutboxProcessorTest extends TestCase
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

    public function test_it_dispatches_pending_outbox_records_and_marks_them_dispatched(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());
        $dispatcher = new RecordingLaravelDispatcher();
        $processor = new OutboxProcessor($store, $dispatcher);

        $idA = $store->store('workflow.event.a', ['x' => 1]);
        $idB = $store->store('workflow.event.b', ['y' => 2]);

        $result = $processor->processPending(50, 5);

        $this->assertSame(['processed' => 2, 'dispatched' => 2, 'failed' => 0], $result);
        $this->assertCount(2, $dispatcher->dispatched);

        $rowA = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $this->rawId($idA))->first();
        $rowB = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $this->rawId($idB))->first();

        $this->assertSame('dispatched', $rowA->status);
        $this->assertSame('dispatched', $rowB->status);
        $this->assertNotNull($rowA->dispatched_at);
        $this->assertNotNull($rowB->dispatched_at);
        $this->assertNull($rowA->last_error);
        $this->assertNull($rowB->last_error);
    }

    public function test_it_marks_failed_and_retries_until_dispatch_succeeds(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());
        $failing = new RecordingLaravelDispatcher(['workflow.event.will_fail']);
        $processorFail = new OutboxProcessor($store, $failing);

        $id = $store->store('workflow.event.will_fail', ['ticket' => 10]);

        $first = $processorFail->processPending(50, 5);
        $this->assertSame(['processed' => 1, 'dispatched' => 0, 'failed' => 1], $first);

        $afterFailure = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $this->rawId($id))->first();
        $this->assertSame('failed', $afterFailure->status);
        $this->assertSame(1, (int) $afterFailure->attempts);
        $this->assertNotNull($afterFailure->last_error);
        $this->assertNull($afterFailure->dispatched_at);

        $success = new RecordingLaravelDispatcher();
        $processorSuccess = new OutboxProcessor($store, $success);
        $second = $processorSuccess->processPending(50, 5);

        $this->assertSame(['processed' => 1, 'dispatched' => 1, 'failed' => 0], $second);

        $afterRetry = $this->capsule->getConnection()->table('workflow_outbox')->where('id', $this->rawId($id))->first();
        $this->assertSame('dispatched', $afterRetry->status);
        $this->assertNotNull($afterRetry->dispatched_at);
        $this->assertNull($afterRetry->last_error);
    }

    public function test_it_skips_records_that_reached_max_attempts(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());

        $id = $store->store('workflow.event.never_retry', ['k' => 1]);

        $this->capsule->getConnection()->table('workflow_outbox')->where('id', $this->rawId($id))->update([
            'status' => 'failed',
            'attempts' => 5,
        ]);

        $dispatcher = new RecordingLaravelDispatcher();
        $processor = new OutboxProcessor($store, $dispatcher);

        $result = $processor->processPending(50, 5);

        $this->assertSame(['processed' => 0, 'dispatched' => 0, 'failed' => 0], $result);
        $this->assertCount(0, $dispatcher->dispatched);
    }

    private function rawId(string $pointer): string
    {
        $parts = explode('::', $pointer, 2);

        if (count($parts) !== 2) {
            return $pointer;
        }

        return $parts[1];
    }

    public function test_it_emits_diagnostics_for_outbox_processing(): void
    {
        $store = new DatabaseOutboxStore($this->capsule->getConnection());
        $dispatcher = new RecordingLaravelDispatcher(['workflow.event.fail_once']);
        $diagnostics = new RecordingOutboxDiagnosticsEmitter();
        $processor = new OutboxProcessor($store, $dispatcher, $diagnostics);

        $store->store('workflow.event.ok', ['ok' => true]);
        $store->store('workflow.event.fail_once', ['ok' => false]);

        $result = $processor->processPending(50, 5);

        $this->assertSame(['processed' => 2, 'dispatched' => 1, 'failed' => 1], $result);
        $this->assertCount(3, $diagnostics->events);
        $this->assertSame('outbox.item.dispatched', $diagnostics->events[0]['event']);
        $this->assertSame('outbox.item.failed', $diagnostics->events[1]['event']);
        $this->assertSame('outbox.batch.completed', $diagnostics->events[2]['event']);
    }
}

class RecordingLaravelDispatcher implements LaravelDispatcherContract
{
    /** @var array<int, array{event: string, payload: mixed}> */
    public array $dispatched = [];

    /** @var array<int, string> */
    private array $failingEvents;

    /** @param array<int, string> $failingEvents */
    public function __construct(array $failingEvents = [])
    {
        $this->failingEvents = $failingEvents;
    }

    public function listen($events, $listener = null)
    {
    }

    public function hasListeners($eventName)
    {
        return false;
    }

    public function subscribe($subscriber)
    {
    }

    public function until($event, $payload = [])
    {
        return null;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $eventName = is_string($event) ? $event : (string) $event;

        if (in_array($eventName, $this->failingEvents, true)) {
            throw new \RuntimeException('dispatch failed for test event');
        }

        $this->dispatched[] = ['event' => $eventName, 'payload' => $payload];

        return [];
    }

    public function push($event, $payload = [])
    {
    }

    public function flush($event)
    {
    }

    public function forget($event)
    {
    }

    public function forgetPushed()
    {
    }
}

class RecordingOutboxDiagnosticsEmitter implements DiagnosticsEmitterInterface
{
    /** @var array<int, array{event: string, payload: array<string, mixed>}> */
    public array $events = [];

    public function emit(string $eventName, array $payload = []): void
    {
        $this->events[] = [
            'event' => $eventName,
            'payload' => $payload,
        ];
    }
}
