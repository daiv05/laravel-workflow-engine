<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface DiagnosticsEmitterInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $eventName, array $payload = []): void;
}
