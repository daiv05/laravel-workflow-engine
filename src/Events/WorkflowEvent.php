<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Events;

abstract class WorkflowEvent
{
    public function __construct(
        public readonly string $eventName,
        public readonly ?array $subject = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $outboxTable = null
    ) {
    }

    public function fullEventName(string $prefix): string
    {
        return $prefix . $this->eventName;
    }

    public function outboxTable(): ?string
    {
        return $this->outboxTable;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toPayload(): array;
}
