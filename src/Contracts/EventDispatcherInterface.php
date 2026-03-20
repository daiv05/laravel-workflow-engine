<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

use Daiv05\LaravelWorkflowEngine\Events\WorkflowEvent;

interface EventDispatcherInterface
{
    public function queue(WorkflowEvent $event): void;

    public function flushAfterCommit(): void;

    public function clearQueue(): void;

    /** @return array<int, WorkflowEvent> */
    public function dispatchedEvents(): array;
}
