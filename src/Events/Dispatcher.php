<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;

class Dispatcher implements EventDispatcherInterface
{
    /** @var array<int, array{event: WorkflowEvent, outbox_id: string}> */
    private array $queued = [];

    /** @var array<int, WorkflowEvent> */
    private array $dispatched = [];

    public function __construct(
        private readonly string $prefix = 'workflow.event.',
        private readonly ?LaravelEventDispatcher $laravelEvents = null,
        private readonly ?OutboxStoreInterface $outboxStore = null
    ) {
    }

    public function queue(WorkflowEvent $event): void
    {
        $name = $event->fullEventName($this->prefix);
        $payload = $event->toPayload();
        $outboxId = $this->outboxStore?->store($name, $payload) ?? '';

        $this->queued[] = [
            'event' => $event,
            'outbox_id' => $outboxId,
        ];
    }

    public function flushAfterCommit(): void
    {
        foreach ($this->queued as $event) {
            $name = $event['event']->fullEventName($this->prefix);
            $payload = $event['event']->toPayload();

            if ($this->laravelEvents !== null) {
                $this->laravelEvents->dispatch($name, $payload);
            }

            if ($this->outboxStore !== null && $event['outbox_id'] !== '') {
                $this->outboxStore->markDispatched($event['outbox_id']);
            }

            $this->dispatched[] = $event['event'];
        }

        $this->queued = [];
    }

    public function clearQueue(): void
    {
        $this->queued = [];
    }

    /** @return array<int, WorkflowEvent> */
    public function dispatchedEvents(): array
    {
        return $this->dispatched;
    }
}
