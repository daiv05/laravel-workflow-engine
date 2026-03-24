<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Daiv05\LaravelWorkflowEngine\Storage\NullOutboxStore;
use PHPUnit\Framework\TestCase;

class NullOutboxStoreTest extends TestCase
{
    public function test_it_implements_outbox_store_interface(): void
    {
        $store = new NullOutboxStore();

        $this->assertInstanceOf(OutboxStoreInterface::class, $store);
    }

    public function test_store_returns_empty_string(): void
    {
        $store = new NullOutboxStore();

        $this->assertSame('', $store->store('workflow.event.updated', ['k' => 'v']));
    }

    public function test_fetch_pending_returns_empty_array(): void
    {
        $store = new NullOutboxStore();

        $this->assertSame([], $store->fetchPending(10, 5));
    }

    public function test_mark_dispatched_and_mark_failed_do_not_throw(): void
    {
        $store = new NullOutboxStore();

        $store->markDispatched('outbox-1');
        $store->markFailed('outbox-2', 'error');

        $this->assertSame([], $store->fetchPending(10, 5));
    }
}
