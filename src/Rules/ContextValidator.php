<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Rules;

use Daiv05\LaravelWorkflowEngine\Exceptions\ContextValidationException;

class ContextValidator
{
    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    public function validateForRule(array $rule, array $context): void
    {
        if (isset($rule['role'])) {
            $this->assertRoles($context);
        }

        if (isset($rule['all']) && is_array($rule['all'])) {
            foreach ($rule['all'] as $nested) {
                if (is_array($nested)) {
                    $this->validateForRule($nested, $context);
                }
            }
        }

        if (isset($rule['any']) && is_array($rule['any'])) {
            foreach ($rule['any'] as $nested) {
                if (is_array($nested)) {
                    $this->validateForRule($nested, $context);
                }
            }
        }

        if (isset($rule['not']) && is_array($rule['not'])) {
            $this->validateForRule($rule['not'], $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertRoles(array $context): void
    {
        if (!array_key_exists('roles', $context)) {
            throw ContextValidationException::missingKey('roles', 'role-based rules');
        }

        if (!is_array($context['roles'])) {
            throw ContextValidationException::invalidType('roles', 'an array');
        }
    }
}
