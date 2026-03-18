<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Diagnostics;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;

class NullDiagnosticsEmitter implements DiagnosticsEmitterInterface
{
    public function emit(string $eventName, array $payload = []): void
    {
    }
}
