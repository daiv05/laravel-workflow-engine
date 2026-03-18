<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

class StateMachine
{
    /**
     * @param array<string, mixed> $definition
     */
    public function transitionFor(array $definition, string $currentState, string $action): ?array
    {
        if ($this->isFinalState($definition, $currentState)) {
            return null;
        }

        $index = $definition['transition_index'] ?? [];
        $key = $currentState . '::' . $action;

        return is_array($index) && isset($index[$key]) && is_array($index[$key])
            ? $index[$key]
            : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function isFinalState(array $definition, string $state): bool
    {
        $finalStates = $definition['final_states'] ?? [];
        return is_array($finalStates) && in_array($state, $finalStates, true);
    }
}
