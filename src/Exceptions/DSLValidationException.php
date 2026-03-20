<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

use Throwable;

class DSLValidationException extends WorkflowException
{
    private ?string $nodePath = null;

    public static function withPath(string $message, string $path): self
    {
        $exception = new self($message . ' at ' . $path, 1001, null, ['node_path' => $path]);
        $exception->nodePath = $path;

        return $exception;
    }

    public static function malformed(string $message): self
    {
        return new self($message, 1002);
    }

    public function nodePath(): ?string
    {
        return $this->nodePath;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function newWith(string $message, int $code, ?Throwable $previous, array $context): static
    {
        $exception = new static($message, $code, $previous, $context);
        $exception->nodePath = $this->nodePath;

        return $exception;
    }
}
