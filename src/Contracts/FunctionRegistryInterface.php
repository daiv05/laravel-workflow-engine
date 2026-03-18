<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface FunctionRegistryInterface
{
    public function register(string $name, callable $function): void;

    public function has(string $name): bool;

    public function get(string $name): callable;
}
