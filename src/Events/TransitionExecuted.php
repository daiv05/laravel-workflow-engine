<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

class TransitionExecuted extends WorkflowEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $eventName,
        public readonly string $instanceId,
        public readonly string $fromState,
        public readonly string $toState,
        public readonly string $action,
        public readonly string $transitionId,
        public readonly array $context,
        public readonly mixed $meta = null,
        ?array $subject = null,
        ?string $tenantId = null
    ) {
        parent::__construct($eventName, $subject, $tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'instance_id' => $this->instanceId,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
            'action' => $this->action,
            'transition_id' => $this->transitionId,
            'context' => $this->context,
        ];

        if ($this->meta !== null) {
            $payload['meta'] = $this->meta;
        }

        if ($this->subject !== null) {
            $payload['subject'] = $this->subject;
        }

        if ($this->tenantId !== null) {
            $payload['tenant_id'] = $this->tenantId;
        }

        return $payload;
    }
}
