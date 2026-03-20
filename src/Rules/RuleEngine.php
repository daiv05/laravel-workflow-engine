<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Rules;

use Daiv05\LaravelWorkflowEngine\Contracts\FunctionRegistryInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\RuleEngineInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;

class RuleEngine implements RuleEngineInterface
{
    private readonly ContextValidator $contextValidator;

    public function __construct(private readonly FunctionRegistryInterface $functions)
    {
        $this->contextValidator = new ContextValidator();
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    public function evaluate(array $rule, array $context): bool
    {
        if ($rule === []) {
            return true;
        }

        $this->contextValidator->validateForRule($rule, $context);

        if (isset($rule['role'])) {
            return $this->evaluateRole((string) $rule['role'], $context);
        }

        if (isset($rule['fn'])) {
            $function = $this->functions->get((string) $rule['fn']);
            $args = isset($rule['args']) && is_array($rule['args'])
                ? array_values($rule['args'])
                : [];

            return (bool) $function($context, ...$args);
        }

        if (isset($rule['all'])) {
            foreach ((array) $rule['all'] as $nested) {
                if (!$this->evaluate((array) $nested, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($rule['any'])) {
            foreach ((array) $rule['any'] as $nested) {
                if ($this->evaluate((array) $nested, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($rule['not'])) {
            return !$this->evaluate((array) $rule['not'], $context);
        }

        throw new WorkflowException('Rule is not evaluable with the supported operators');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function evaluateRole(string $role, array $context): bool
    {
        $roles = $context['roles'];

        return in_array($role, $roles, true);
    }
}
