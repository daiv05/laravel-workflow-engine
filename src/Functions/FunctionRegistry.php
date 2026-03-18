<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Functions;

use Daiv05\LaravelWorkflowEngine\Contracts\FunctionRegistryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\FunctionNotFoundException;

class FunctionRegistry implements FunctionRegistryInterface
{
    /**
     * @var array<string, callable>
     */
    private array $functions = [];

    public function register(string $name, callable $function): void
    {
        $this->functions[$name] = $function;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->functions);
    }

    public function get(string $name): callable
    {
        if (!$this->has($name)) {
            throw FunctionNotFoundException::forName($name);
        }

        return $this->functions[$name];
    }
}
