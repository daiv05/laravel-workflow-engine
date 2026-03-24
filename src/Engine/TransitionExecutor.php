<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Contracts\DataMapperInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidTransitionValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;
use Daiv05\LaravelWorkflowEngine\Exceptions\UnauthorizedTransitionException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Events\TransitionExecuted;
use Daiv05\LaravelWorkflowEngine\Events\TransitionFailed;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;

class TransitionExecutor
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly PolicyEngine $policy,
        private readonly StorageRepositoryInterface $storage,
        private readonly EventDispatcherInterface $events,
        private readonly ?DiagnosticsEmitterInterface $diagnostics = null,
        private readonly bool $listenerFailSilently = false,
        private readonly ?DataMapperInterface $dataMapper = null
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
        $outboxTable = $this->outboxTableFromDefinition($definition);
        $emittedEvents = [];

        try {
            /** @var array<string, mixed> $updated */
            $updated = $this->storage->transaction(function () use ($instance, $definition, $action, $context, $outboxTable, &$emittedEvents): array {
                $transition = $this->stateMachine->transitionFor($definition, (string) $instance['state'], $action);
                $mappingSummary = [];

                if ($transition === null) {
                    throw InvalidTransitionException::forStateAndAction((string) $instance['state'], $action);
                }

                if (!$this->policy->canExecuteTransition($transition, $context)) {
                    throw UnauthorizedTransitionException::forTransition($action, (string) $instance['state'], $context);
                }

                $this->enforceTransitionRequiredFields($transition, $instance, $context);

                $fromState = (string) $instance['state'];
                $newState = (string) $transition['to'];
                $expectedVersion = (int) ($instance['version'] ?? 0);

                if (isset($transition['mappings']) && is_array($transition['mappings']) && $transition['mappings'] !== []) {
                    $mapping = $this->applyMappings($transition, $instance, $definition, $action, $context);
                    $instance['data'] = $mapping['instance_data'];
                    $mappingSummary = $mapping['summary'];
                }

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
                    'payload' => [
                        'transition_id' => $transition['transition_id'],
                        'action' => $action,
                        'from_state' => $fromState,
                        'to_state' => $newState,
                        'mapping_summary' => $mappingSummary,
                        'context' => $this->historyContextSummary($context),
                    ],
                    'created_at' => date(DATE_ATOM),
                ]);

                if (isset($transition['effects']) && is_array($transition['effects'])) {
                    foreach ($transition['effects'] as $effect) {
                        if (!is_array($effect) || !isset($effect['event']) || !is_string($effect['event'])) {
                            continue;
                        }

                        $subject = null;
                        if (isset($instance['subject_type']) && isset($instance['subject_id'])) {
                            $subject = [
                                'subject_type' => (string) $instance['subject_type'],
                                'subject_id' => (string) $instance['subject_id'],
                            ];
                        }

                        $transitionEvent = new TransitionExecuted(
                            $effect['event'],
                            (string) $instance['instance_id'],
                            $fromState,
                            $newState,
                            $action,
                            (string) $transition['transition_id'],
                            $context,
                            array_key_exists('meta', $effect) ? $effect['meta'] : null,
                            $subject,
                            isset($instance['tenant_id']) ? (string) $instance['tenant_id'] : null,
                            $outboxTable
                        );

                        $this->events->queue($transitionEvent);
                        $emittedEvents[] = [
                            'event' => $effect['event'],
                            'payload' => $transitionEvent->toPayload(),
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

            $subject = null;
            if (isset($instance['subject_type']) && isset($instance['subject_id'])) {
                $subject = [
                    'subject_type' => (string) $instance['subject_type'],
                    'subject_id' => (string) $instance['subject_id'],
                ];
            }

            $this->events->queue(new TransitionFailed(
                (string) ($instance['instance_id'] ?? ''),
                (string) ($instance['state'] ?? ''),
                $action,
                $this->normalizeException($exception),
                $subject,
                isset($instance['tenant_id']) ? (string) $instance['tenant_id'] : null,
                $outboxTable
            ));
            $this->events->flushAfterCommit();

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

    /**
     * @param array<string, mixed> $transition
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     *
     * @return array{instance_data: array<string, mixed>, summary: array<string, mixed>}
     */
    private function applyMappings(array $transition, array $instance, array $definition, string $action, array $context): array
    {
        if (!array_key_exists('data', $context)) {
            throw ContextValidationException::missingKey('data', 'transition mappings');
        }

        if (!is_array($context['data'])) {
            throw ContextValidationException::invalidType('data', 'an array');
        }

        if ($this->dataMapper === null) {
            throw MappingException::mapperNotConfigured();
        }

        return $this->dataMapper->map(
            (array) $transition['mappings'],
            is_array($instance['data'] ?? null) ? $instance['data'] : [],
            $context['data'],
            [
                'instance' => $instance,
                'transition' => $transition,
                'definition' => $definition,
                'runtime_context' => $context,
                'action' => $action,
            ]
        );
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $context
     */
    private function enforceTransitionRequiredFields(array $transition, array $instance, array $context): void
    {
        if (!is_array($transition['validation'] ?? null)) {
            return;
        }

        if (!is_array($transition['validation']['required'] ?? null)) {
            return;
        }

        $instanceData = is_array($instance['data'] ?? null) ? $instance['data'] : [];
        $contextData = is_array($context['data'] ?? null) ? $context['data'] : [];
        $mergedData = array_replace($instanceData, $contextData);
        $missing = [];

        foreach ($transition['validation']['required'] as $fieldName) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            if (!array_key_exists($fieldName, $mergedData) || $mergedData[$fieldName] === null) {
                $missing[] = $fieldName;
            }
        }

        if ($missing !== []) {
            throw InvalidTransitionValidationException::forMissingRequiredFields(
                (string) ($instance['state'] ?? ''),
                (string) ($transition['action'] ?? ''),
                $missing
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function historyContextSummary(array $context): array
    {
        $summary = [
            'has_data' => array_key_exists('data', $context),
            'data_keys' => is_array($context['data'] ?? null) ? array_values(array_filter(array_keys($context['data']), 'is_string')) : [],
        ];

        if (isset($context['roles']) && is_array($context['roles'])) {
            $summary['roles'] = array_values($context['roles']);
        }

        if (isset($context['actor']) && is_scalar($context['actor'])) {
            $summary['actor'] = (string) $context['actor'];
        }

        if (isset($context['meta']) && is_array($context['meta'])) {
            $summary['meta_keys'] = array_values(array_filter(array_keys($context['meta']), 'is_string'));
        }

        return $summary;
    }

    private function outboxTableFromDefinition(array $definition): ?string
    {
        if (!isset($definition['storage']) || !is_array($definition['storage'])) {
            return null;
        }

        $outboxTable = $definition['storage']['outbox_table'] ?? null;

        if (!is_string($outboxTable) || $outboxTable === '') {
            return null;
        }

        return $outboxTable;
    }
}
