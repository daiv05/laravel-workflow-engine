<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\UnauthorizedTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;

class TransitionExecutor
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly PolicyEngine $policy,
        private readonly StorageRepositoryInterface $storage,
        private readonly EventDispatcherInterface $events,
        private readonly ?DiagnosticsEmitterInterface $diagnostics = null,
        private readonly bool $listenerFailSilently = false
    ) {
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(array $instance, array $definition, string $action, array $context): array
    {
        return $this->executeWithListeners($instance, $definition, $action, $context);
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @param array<string, mixed> $listeners
     *
     * @return array<string, mixed>
     */
    public function executeWithListeners(
        array $instance,
        array $definition,
        string $action,
        array $context,
        array $listeners = []
    ): array
    {
        $workflowName = $this->workflowName($definition);
        $emittedEvents = [];

        try {
            /** @var array<string, mixed> $updated */
            $updated = $this->storage->transaction(function () use ($instance, $definition, $action, $context, &$emittedEvents): array {
                $transition = $this->stateMachine->transitionFor($definition, (string) $instance['state'], $action);

                if ($transition === null) {
                    throw InvalidTransitionException::forStateAndAction((string) $instance['state'], $action);
                }

                if (!$this->policy->canExecuteTransition($transition, $context)) {
                    throw UnauthorizedTransitionException::forTransition($action, (string) $instance['state'], $context);
                }

                $fromState = (string) $instance['state'];
                $newState = (string) $transition['to'];
                $expectedVersion = (int) ($instance['version'] ?? 0);

                $instance['state'] = $newState;
                $instance['version'] = $expectedVersion + 1;
                $instance['updated_at'] = date(DATE_ATOM);

                $updated = $this->storage->updateInstanceWithVersionCheck($instance, $expectedVersion);

                $this->storage->appendHistory([
                    'instance_id' => $instance['instance_id'],
                    'transition_id' => $transition['transition_id'],
                    'action' => $action,
                    'from_state' => $fromState,
                    'to_state' => $newState,
                    'actor' => $context['actor'] ?? null,
                    'created_at' => date(DATE_ATOM),
                ]);

                if (isset($transition['effects']) && is_array($transition['effects'])) {
                    foreach ($transition['effects'] as $effect) {
                        if (!is_array($effect) || !isset($effect['event']) || !is_string($effect['event'])) {
                            continue;
                        }

                        $payload = [
                            'instance_id' => $instance['instance_id'],
                            'action' => $action,
                            'transition_id' => $transition['transition_id'],
                            'context' => $context,
                        ];

                        if (array_key_exists('meta', $effect)) {
                            $payload['meta'] = $effect['meta'];
                        }

                        $this->events->queue($effect['event'], $payload);
                        $emittedEvents[] = [
                            'event' => $effect['event'],
                            'payload' => $payload,
                        ];
                    }
                }

                return $updated;
            });

            $listenerException = $this->dispatchInlineListeners($emittedEvents, $listeners);

            // AFTER COMMIT: dispatch only when transaction was committed successfully.
            $this->events->flushAfterCommit();

            if ($listenerException !== null) {
                throw $listenerException;
            }
        } catch (\Throwable $exception) {
            $this->events->clearQueue();

            $this->diagnostics?->emit('transition.failed', [
                'workflow_name' => $workflowName,
                'instance_id' => (string) ($instance['instance_id'] ?? ''),
                'state' => (string) ($instance['state'] ?? ''),
                'action' => $action,
                'exception' => $this->normalizeException($exception),
            ]);

            throw $exception;
        }

        $this->diagnostics?->emit('transition.executed', [
            'workflow_name' => $workflowName,
            'instance_id' => (string) ($updated['instance_id'] ?? ''),
            'action' => $action,
            'state' => (string) ($updated['state'] ?? ''),
            'version' => (int) ($updated['version'] ?? 0),
        ]);

        return $updated;
    }

    /**
     * @param array<int, array{event: string, payload: array<string, mixed>}> $emittedEvents
     * @param array<string, mixed> $listeners
     */
    private function dispatchInlineListeners(array $emittedEvents, array $listeners): ?\Throwable
    {
        $firstException = null;

        foreach ($emittedEvents as $emittedEvent) {
            $eventName = $emittedEvent['event'];
            $payload = $emittedEvent['payload'];

            $named = $listeners['named'][$eventName] ?? [];
            if (is_array($named)) {
                foreach ($named as $listener) {
                    if (!is_callable($listener)) {
                        continue;
                    }

                    try {
                        $listener($payload);
                    } catch (\Throwable $exception) {
                        if ($this->listenerFailSilently) {
                            continue;
                        }

                        $firstException ??= $exception;
                    }
                }
            }

            $any = $listeners['any'] ?? [];
            if (is_array($any)) {
                foreach ($any as $listener) {
                    if (!is_callable($listener)) {
                        continue;
                    }

                    try {
                        $listener($eventName, $payload);
                    } catch (\Throwable $exception) {
                        if ($this->listenerFailSilently) {
                            continue;
                        }

                        $firstException ??= $exception;
                    }
                }
            }
        }

        return $firstException;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function workflowName(array $definition): string
    {
        if (isset($definition['name']) && is_string($definition['name'])) {
            return $definition['name'];
        }

        if (isset($definition['workflow_name']) && is_string($definition['workflow_name'])) {
            return $definition['workflow_name'];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeException(\Throwable $exception): array
    {
        if ($exception instanceof WorkflowException) {
            return $exception->toDiagnosticContext();
        }

        return [
            'exception_class' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'exception_message' => $exception->getMessage(),
            'context' => [],
        ];
    }
}
