<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class InvalidTransitionException extends WorkflowException
{
	public static function forStateAndAction(string $state, string $action): self
	{
		return new self(
			'Invalid transition for current state and action',
			3001,
			null,
			[
				'state' => $state,
				'action' => $action,
			]
		);
	}
}
