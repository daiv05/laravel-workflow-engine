<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Outbox;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;

class OutboxProcessor
{
    public function __construct(
        private readonly OutboxStoreInterface $outbox,
        private readonly ?LaravelEventDispatcher $events = null,
        private readonly ?DiagnosticsEmitterInterface $diagnostics = null
    ) {
    }

    /**
     * @return array{processed: int, dispatched: int, failed: int}
     */
    public function processPending(int $limit, int $maxAttempts): array
    {
        if ($limit <= 0 || $maxAttempts <= 0) {
            $this->diagnostics?->emit('outbox.batch.skipped', [
                'limit' => $limit,
                'max_attempts' => $maxAttempts,
            ]);

            return ['processed' => 0, 'dispatched' => 0, 'failed' => 0];
        }

        $pending = $this->outbox->fetchPending($limit, $maxAttempts);

        $dispatched = 0;
        $failed = 0;

        foreach ($pending as $item) {
            try {
                if ($this->events !== null) {
                    $this->events->dispatch($item['event_name'], $item['payload']);
                }

                $this->outbox->markDispatched($item['id']);
                $this->diagnostics?->emit('outbox.item.dispatched', [
                    'outbox_id' => $item['id'],
                    'event_name' => $item['event_name'],
                    'attempts_before' => (int) ($item['attempts'] ?? 0),
                ]);
                $dispatched++;
            } catch (\Throwable $exception) {
                $this->outbox->markFailed($item['id'], $exception->getMessage());
                $this->diagnostics?->emit('outbox.item.failed', [
                    'outbox_id' => $item['id'],
                    'event_name' => $item['event_name'],
                    'attempts_before' => (int) ($item['attempts'] ?? 0),
                    'error_message' => $exception->getMessage(),
                    'exception_class' => get_class($exception),
                ]);
                $failed++;
            }
        }

        $result = [
            'processed' => count($pending),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];

        $this->diagnostics?->emit('outbox.batch.completed', [
            'limit' => $limit,
            'max_attempts' => $maxAttempts,
            'processed' => $result['processed'],
            'dispatched' => $result['dispatched'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }
}
