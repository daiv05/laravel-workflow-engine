<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class OptimisticLockException extends WorkflowException
{
	public static function forInstance(string $instanceId, int $expectedVersion, ?int $actualVersion = null): self
	{
		$message = 'Workflow instance version mismatch';
		if ($actualVersion !== null) {
			$message .= ' (expected ' . $expectedVersion . ', actual ' . $actualVersion . ')';
		}

		return new self(
			$message,
			4001,
			null,
			[
				'instance_id' => $instanceId,
				'expected_version' => $expectedVersion,
				'actual_version' => $actualVersion,
			]
		);
	}
}
