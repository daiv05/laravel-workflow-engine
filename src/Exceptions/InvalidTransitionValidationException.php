<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class InvalidTransitionValidationException extends WorkflowException
{
    /**
     * @param array<int, string> $fields
     */
    public static function forMissingRequiredFields(string $state, string $action, array $fields): self
    {
        return new self(
            'Transition validation failed: missing required fields',
            3003,
            null,
            [
                'state' => $state,
                'action' => $action,
                'fields' => array_values($fields),
            ]
        );
    }
}