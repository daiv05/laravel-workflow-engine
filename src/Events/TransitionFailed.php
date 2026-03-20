<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

class TransitionFailed extends WorkflowEvent
{
    /**
     * @param array<string, mixed> $exception
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly string $state,
        public readonly string $action,
        public readonly array $exception,
        ?array $subject = null,
        ?string $tenantId = null
    ) {
        parent::__construct('transition_failed', $subject, $tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'instance_id' => $this->instanceId,
            'state' => $this->state,
            'action' => $this->action,
            'exception' => $this->exception,
        ];

        if ($this->subject !== null) {
            $payload['subject'] = $this->subject;
        }

        if ($this->tenantId !== null) {
            $payload['tenant_id'] = $this->tenantId;
        }

        return $payload;
    }
}
