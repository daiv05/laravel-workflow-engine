<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Diagnostics\LaravelDiagnosticsEmitter;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;
use PHPUnit\Framework\TestCase;

class LaravelDiagnosticsEmitterTest extends TestCase
{
    public function test_emit_dispatches_prefixed_event_with_metadata_and_payload(): void
    {
        $events = $this->createMock(LaravelEventDispatcher::class);

        $events->expects($this->once())
            ->method('dispatch')
            ->with(
                'workflow.diagnostic.transition.executed',
                $this->callback(static function (array $payload): bool {
                    if (($payload['diagnostic_event'] ?? null) !== 'workflow.diagnostic.transition.executed') {
                        return false;
                    }

                    if (($payload['instance_id'] ?? null) !== 'iid-1') {
                        return false;
                    }

                    if (!isset($payload['emitted_at']) || !is_string($payload['emitted_at'])) {
                        return false;
                    }

                    return strtotime($payload['emitted_at']) !== false;
                })
            );

        $emitter = new LaravelDiagnosticsEmitter('workflow.diagnostic.', $events);
        $emitter->emit('transition.executed', ['instance_id' => 'iid-1']);
    }

    public function test_emit_is_noop_when_dispatcher_is_not_available(): void
    {
        $emitter = new LaravelDiagnosticsEmitter('workflow.diagnostic.');

        $emitter->emit('transition.failed', ['instance_id' => 'iid-2']);

        $this->assertInstanceOf(LaravelDiagnosticsEmitter::class, $emitter);
    }
}
