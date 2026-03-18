<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

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
}
