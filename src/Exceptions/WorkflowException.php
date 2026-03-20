<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

use Throwable;
use RuntimeException;

class WorkflowException extends RuntimeException
{
	/** @var array<string, mixed> */
	private array $context;

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		string $message = '',
		int $code = 0,
		?Throwable $previous = null,
		array $context = []
	) {
		parent::__construct($message, $code, $previous);
		$this->context = $context;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function context(): array
	{
		return $this->context;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function withContext(array $context): static
	{
		return $this->newWith(
			$this->getMessage(),
			$this->getCode(),
			$this->getPrevious(),
			array_merge($this->context, $context)
		);
	}

	/**
	 * Hook for subclasses that keep extra state outside constructor args.
	 *
	 * @param array<string, mixed> $context
	 */
	protected function newWith(string $message, int $code, ?Throwable $previous, array $context): static
	{
		return new static($message, $code, $previous, $context);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toDiagnosticContext(): array
	{
		return [
			'exception_class' => static::class,
			'exception_code' => $this->getCode(),
			'exception_message' => $this->getMessage(),
			'context' => $this->context,
		];
	}
}
