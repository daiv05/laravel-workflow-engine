<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Diagnostics;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;

class LaravelDiagnosticsEmitter implements DiagnosticsEmitterInterface
{
    public function __construct(
        private readonly string $prefix = 'workflow.diagnostic.',
        private readonly ?LaravelEventDispatcher $events = null
    ) {
    }

    public function emit(string $eventName, array $payload = []): void
    {
        $fullEventName = $this->prefix . $eventName;
        $diagnosticPayload = array_merge([
            'diagnostic_event' => $fullEventName,
            'emitted_at' => date(DATE_ATOM),
        ], $payload);

        if ($this->events !== null) {
            $this->events->dispatch($fullEventName, $diagnosticPayload);
        }
    }
}
