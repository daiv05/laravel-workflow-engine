<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class InvalidUpdateException extends WorkflowException
{
    /**
     * @param array<int, string> $fields
     */
    public static function forDisallowedFields(string $state, array $fields): self
    {
        return new self(
            'Update contains fields that are not editable in current state',
            3002,
            null,
            [
                'state' => $state,
                'fields' => array_values($fields),
            ]
        );
    }
}
