<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class ContextValidationException extends WorkflowException
{
	public static function missingKey(string $key, string $usage): self
	{
		return new self(
			'Context key ' . $key . ' is required for ' . $usage,
			6001,
			null,
			[
				'key' => $key,
				'usage' => $usage,
			]
		);
	}

	public static function invalidType(string $key, string $expectedType): self
	{
		return new self(
			'Context key ' . $key . ' must be provided as ' . $expectedType,
			6002,
			null,
			[
				'key' => $key,
				'expected_type' => $expectedType,
			]
		);
	}
}
