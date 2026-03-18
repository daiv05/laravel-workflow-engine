<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class UnauthorizedTransitionException extends WorkflowException
{
	/**
	 * @param array<string, mixed> $context
	 */
	public static function forTransition(string $action, string $fromState, array $context = []): self
	{
		return new self(
			'Transition is not authorized by allowed_if rule',
			5001,
			null,
			[
				'action' => $action,
				'from_state' => $fromState,
				'context' => $context,
			]
		);
	}
}
