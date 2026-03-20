<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

class WorkflowInstanceStarted extends WorkflowEvent
{
    public function __construct(
        public readonly string $instanceId,
        public readonly string $workflowName,
        public readonly string $state,
        ?array $subject = null,
        ?string $tenantId = null
    ) {
        parent::__construct('instance_started', $subject, $tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'instance_id' => $this->instanceId,
            'workflow_name' => $this->workflowName,
            'state' => $this->state,
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
