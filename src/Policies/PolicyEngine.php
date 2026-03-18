<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Policies;

use Daiv05\LaravelWorkflowEngine\Contracts\RuleEngineInterface;

class PolicyEngine
{
    public function __construct(private readonly RuleEngineInterface $rules)
    {
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<string, mixed> $context
     */
    public function canExecuteTransition(array $transition, array $context): bool
    {
        /** @var array<string, mixed> $allowedIf */
        $allowedIf = isset($transition['allowed_if']) && is_array($transition['allowed_if'])
            ? $transition['allowed_if']
            : [];

        return $this->rules->evaluate($allowedIf, $context);
    }
}
