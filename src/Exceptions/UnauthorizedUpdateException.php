<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class UnauthorizedUpdateException extends WorkflowException
{
    /**
     * @param array<string, mixed> $context
     */
    public static function forState(string $state, array $context = []): self
    {
        return new self(
            'Update is not authorized for current state',
            5002,
            null,
            [
                'state' => $state,
                'context' => $context,
            ]
        );
    }
}
