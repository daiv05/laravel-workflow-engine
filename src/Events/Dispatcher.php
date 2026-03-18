<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;

class Dispatcher implements EventDispatcherInterface
{
    /**
    * @var array<int, array{name: string, payload: array<string, mixed>, outbox_id: string}>
     */
    private array $queued = [];

    /**
     * @var array<int, array{name: string, payload: array<string, mixed>}>
     */
    private array $dispatched = [];

    public function __construct(
        private readonly string $prefix = 'workflow.event.',
        private readonly ?LaravelEventDispatcher $laravelEvents = null,
        private readonly ?OutboxStoreInterface $outboxStore = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function queue(string $eventName, array $payload = []): void
    {
        $name = $this->prefix . $eventName;
        $outboxId = $this->outboxStore?->store($name, $payload) ?? '';

        $this->queued[] = [
            'name' => $name,
            'payload' => $payload,
            'outbox_id' => $outboxId,
        ];
    }

    public function flushAfterCommit(): void
    {
        foreach ($this->queued as $event) {
            if ($this->laravelEvents !== null) {
                $this->laravelEvents->dispatch($event['name'], $event['payload']);
            }

            if ($this->outboxStore !== null && $event['outbox_id'] !== '') {
                $this->outboxStore->markDispatched($event['outbox_id']);
            }

            $this->dispatched[] = $event;
        }

        $this->queued = [];
    }

    public function clearQueue(): void
    {
        $this->queued = [];
    }

    /**
     * @return array<int, array{name: string, payload: array<string, mixed>}>
     */
    public function dispatchedEvents(): array
    {
        return $this->dispatched;
    }
}
