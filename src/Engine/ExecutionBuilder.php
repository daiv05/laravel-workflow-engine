<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;

class ExecutionBuilder
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    /** @var array<int, callable> */
    private array $anyListeners = [];

    /** @var array<int, callable> */
    private array $beforeHooks = [];

    /** @var array<int, callable> */
    private array $afterHooks = [];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private ?string $instanceId = null
    ) {
    }

    public function forInstance(string $instanceId): self
    {
        $this->instanceId = $instanceId;

        return $this;
    }

    public function on(string $event, callable $callback): self
    {
        if ($event === '') {
            return $this;
        }

        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $callback;

        return $this;
    }

    public function onAny(callable $callback): self
    {
        $this->anyListeners[] = $callback;

        return $this;
    }

    public function before(callable $callback): self
    {
        $this->beforeHooks[] = $callback;

        return $this;
    }

    public function after(callable $callback): self
    {
        $this->afterHooks[] = $callback;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(string $action, array $context = []): array
    {
        if ($this->instanceId === null || $this->instanceId === '') {
            throw new WorkflowException('ExecutionBuilder requires an instance_id. Call forInstance() before execute().', 7002);
        }

        foreach ($this->beforeHooks as $hook) {
            $hook($action, $context, $this->instanceId);
        }

        $result = $this->engine->executeWithListeners($this->instanceId, $action, $context, [
            'named' => $this->listeners,
            'any' => $this->anyListeners,
        ]);

        foreach ($this->afterHooks as $hook) {
            $hook($action, $context, $result, $this->instanceId);
        }

        return $result;
    }
}