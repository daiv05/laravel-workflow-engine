<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\DSL;

class Compiler
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function compile(array $definition): array
    {
        $compiled = $definition;

        $compiled['transition_index'] = [];

        foreach ($definition['transitions'] as $transition) {
            $key = $this->indexKey($transition['from'], $transition['action']);
            $compiled['transition_index'][$key] = $transition;
        }

        return $compiled;
    }

    private function indexKey(string $from, string $action): string
    {
        return $from . '::' . $action;
    }
}
