<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface RuleEngineInterface
{
    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    public function evaluate(array $rule, array $context): bool;
}
