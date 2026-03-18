<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class FunctionNotFoundException extends WorkflowException
{
	public static function forName(string $functionName): self
	{
		return new self(
			'Function not registered: ' . $functionName,
			2001,
			null,
			['function' => $functionName]
		);
	}
}
