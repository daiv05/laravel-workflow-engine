<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

class StateUpdated extends WorkflowEvent
{
    /**
     * @param array<int, string> $updatedFields
     * @param array<string, mixed> $context
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly string $state,
        public readonly array $updatedFields,
        public readonly array $context,
        public readonly array $summary,
        ?array $subject = null,
        ?string $tenantId = null
    ) {
        parent::__construct('updated', $subject, $tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'instance_id' => $this->instanceId,
            'state' => $this->state,
            'updated_fields' => $this->updatedFields,
            'context' => $this->context,
            'summary' => $this->summary,
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
