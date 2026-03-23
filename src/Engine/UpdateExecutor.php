<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Contracts\DataMapperInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Events\StateUpdated;
use Daiv05\LaravelWorkflowEngine\Events\TransitionFailed;
use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;
use Daiv05\LaravelWorkflowEngine\Exceptions\InvalidUpdateException;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;
use Daiv05\LaravelWorkflowEngine\Exceptions\UnauthorizedUpdateException;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;

class UpdateExecutor
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly PolicyEngine $policy,
        private readonly FieldEngine $fieldEngine,
        private readonly StorageRepositoryInterface $storage,
        private readonly EventDispatcherInterface $events,
        private readonly ?DataMapperInterface $dataMapper = null
    ) {
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     */
    public function canUpdate(array $instance, array $definition, array $context): bool
    {
        if ($this->stateMachine->isFinalState($definition, (string) $instance['state'])) {
            return false;
        }

        $stateConfig = $this->stateConfig($definition, (string) $instance['state']);
        if ($stateConfig === [] || !$this->policy->canUpdateState($stateConfig, $context)) {
            return false;
        }

        $editableFields = $this->fieldEngine->editableFieldsForState($stateConfig, $context);
        if ($editableFields === []) {
            return false;
        }

        if (!array_key_exists('data', $context)) {
            return true;
        }

        if (!is_array($context['data'])) {
            return false;
        }

        $inputFields = array_values(array_filter(array_keys($context['data']), 'is_string'));
        $disallowed = array_values(array_diff($inputFields, $editableFields));

        return $disallowed === [];
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $context
     * @param array<string, mixed> $listeners
     *
     * @return array<string, mixed>
     */
    public function executeWithListeners(array $instance, array $definition, array $context, array $listeners = []): array
    {
        $state = (string) $instance['state'];
        $emittedEvents = [];

        try {
            /** @var array<string, mixed> $updated */
            $updated = $this->storage->transaction(function () use ($instance, $definition, $context, $state, &$emittedEvents): array {
                $stateConfig = $this->stateConfig($definition, $state);

                if ($stateConfig === [] || !$this->policy->canUpdateState($stateConfig, $context)) {
                    throw UnauthorizedUpdateException::forState($state, $context);
                }

                if (!array_key_exists('data', $context)) {
                    throw ContextValidationException::missingKey('data', 'state update');
                }

                if (!is_array($context['data'])) {
                    throw ContextValidationException::invalidType('data', 'an array');
                }

                $editableFields = $this->fieldEngine->editableFieldsForState($stateConfig, $context);
                $inputData = $context['data'];
                $inputFields = array_values(array_filter(array_keys($inputData), 'is_string'));
                $disallowedFields = array_values(array_diff($inputFields, $editableFields));

                if ($editableFields === [] || $disallowedFields !== []) {
                    throw InvalidUpdateException::forDisallowedFields($state, $disallowedFields === [] ? $inputFields : $disallowedFields);
                }

                $allowedData = array_intersect_key($inputData, array_flip($editableFields));
                $mappingSummary = [];

                if (isset($stateConfig['mappings']) && is_array($stateConfig['mappings']) && $stateConfig['mappings'] !== []) {
                    if ($this->dataMapper === null) {
                        throw MappingException::mapperNotConfigured();
                    }

                    $mapping = $this->dataMapper->map(
                        $stateConfig['mappings'],
                        is_array($instance['data'] ?? null) ? $instance['data'] : [],
                        $allowedData,
                        [
                            'instance' => $instance,
                            'state' => $state,
                            'definition' => $definition,
                            'runtime_context' => $context,
                            'action' => 'update',
                        ]
                    );

                    $instance['data'] = $mapping['instance_data'];
                    $mappingSummary = $mapping['summary'];
                } else {
                    $instanceData = is_array($instance['data'] ?? null) ? $instance['data'] : [];
                    $instance['data'] = array_replace($instanceData, $allowedData);
                }

                $expectedVersion = (int) ($instance['version'] ?? 0);
                $instance['version'] = $expectedVersion + 1;
                $instance['updated_at'] = date(DATE_ATOM);

                $updated = $this->storage->updateInstanceWithVersionCheck($instance, $expectedVersion);

                $this->storage->appendHistory([
                    'instance_id' => $instance['instance_id'],
                    'transition_id' => '',
                    'action' => 'update',
                    'from_state' => $state,
                    'to_state' => $state,
                    'actor' => $context['actor'] ?? null,
                    'payload' => [
                        'action' => 'update',
                        'from_state' => $state,
                        'to_state' => $state,
                        'updated_fields' => array_values(array_filter(array_keys($allowedData), 'is_string')),
                        'mapping_summary' => $mappingSummary,
                        'context' => $this->historyContextSummary($context),
                    ],
                    'created_at' => date(DATE_ATOM),
                ]);

                $event = new StateUpdated(
                    (string) $instance['instance_id'],
                    $state,
                    array_values(array_filter(array_keys($allowedData), 'is_string')),
                    $context,
                    [
                        'mapping_summary' => $mappingSummary,
                        'version' => (int) ($updated['version'] ?? 0),
                    ],
                    $this->subjectFromInstance($instance),
                    isset($instance['tenant_id']) ? (string) $instance['tenant_id'] : null
                );

                $this->events->queue($event);
                $emittedEvents[] = [
                    'event' => 'updated',
                    'payload' => $event->toPayload(),
                ];

                return $updated;
            });

            $listenerException = $this->dispatchInlineListeners($emittedEvents, $listeners);

            $this->events->flushAfterCommit();

            if ($listenerException !== null) {
                throw $listenerException;
            }
        } catch (\Throwable $exception) {
            $this->events->clearQueue();

            $this->events->queue(new TransitionFailed(
                (string) ($instance['instance_id'] ?? ''),
                (string) ($instance['state'] ?? ''),
                'update',
                $this->normalizeException($exception),
                $this->subjectFromInstance($instance),
                isset($instance['tenant_id']) ? (string) $instance['tenant_id'] : null
            ));
            $this->events->flushAfterCommit();

            throw $exception;
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function stateConfig(array $definition, string $state): array
    {
        $stateConfigs = $definition['state_configs'] ?? [];

        if (is_array($stateConfigs) && isset($stateConfigs[$state]) && is_array($stateConfigs[$state])) {
            return $stateConfigs[$state];
        }

        return [];
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
                        $firstException ??= $exception;
                    }
                }
            }
        }

        return $firstException;
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
     * @param array<string, mixed> $instance
     *
     * @return array<string, string>|null
     */
    private function subjectFromInstance(array $instance): ?array
    {
        if (!isset($instance['subject_type']) || !isset($instance['subject_id'])) {
            return null;
        }

        return [
            'subject_type' => (string) $instance['subject_type'],
            'subject_id' => (string) $instance['subject_id'],
        ];
    }
}
