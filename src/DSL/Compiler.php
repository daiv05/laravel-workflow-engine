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

        $compiled['states'] = [];
        $compiled['state_configs'] = [];

        foreach ($definition['states'] as $state) {
            if (is_string($state) && $state !== '') {
                $compiled['states'][] = $state;
                $compiled['state_configs'][$state] = ['name' => $state];
                continue;
            }

            if (is_array($state) && isset($state['name']) && is_string($state['name']) && $state['name'] !== '') {
                $stateName = $state['name'];
                $compiled['states'][] = $stateName;
                $compiled['state_configs'][$stateName] = $state;
            }
        }

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
