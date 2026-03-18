<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\DSL;

use Daiv05\LaravelWorkflowEngine\Contracts\FunctionRegistryInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;

class Validator
{
    public function __construct(private readonly FunctionRegistryInterface $functions)
    {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function validate(array $definition): void
    {
        $required = ['name', 'version', 'initial_state', 'states', 'transitions'];

        foreach ($required as $key) {
            if (!array_key_exists($key, $definition)) {
                throw DSLValidationException::withPath('Missing required key: ' . $key, $key);
            }
        }

        if (!is_array($definition['states']) || $definition['states'] === []) {
            throw DSLValidationException::withPath('states must be a non-empty array', 'states');
        }

        if (!in_array($definition['initial_state'], $definition['states'], true)) {
            throw DSLValidationException::withPath('initial_state must exist in states', 'initial_state');
        }

        if (!isset($definition['final_states']) || !is_array($definition['final_states']) || $definition['final_states'] === []) {
            throw DSLValidationException::withPath('final_states must be a non-empty array', 'final_states');
        }

        foreach ($definition['final_states'] as $index => $finalState) {
            if (!in_array($finalState, $definition['states'], true)) {
                throw DSLValidationException::withPath('final_state must exist in states', 'final_states.' . $index);
            }
        }

        if (!is_array($definition['transitions']) || $definition['transitions'] === []) {
            throw DSLValidationException::withPath('transitions must be a non-empty array', 'transitions');
        }

        /** @var array<string, bool> $uniqueTransitionPerState */
        $uniqueTransitionPerState = [];

        foreach ($definition['transitions'] as $index => $transition) {
            $path = 'transitions.' . $index;
            $this->validateTransition($transition, $definition['states'], $path);
            $this->validateRuleFunctions($transition['allowed_if'] ?? [], $path . '.allowed_if');
            $this->validateFieldRules($transition, $path);

            $uniqueKey = $transition['from'] . '::' . $transition['action'];
            if (isset($uniqueTransitionPerState[$uniqueKey])) {
                throw DSLValidationException::withPath('Duplicate transition action for state', $path . '.action');
            }

            $uniqueTransitionPerState[$uniqueKey] = true;
        }
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<int, string> $states
     */
    private function validateTransition(array $transition, array $states, string $path): void
    {
        foreach (['from', 'to', 'action', 'transition_id'] as $requiredKey) {
            if (!isset($transition[$requiredKey]) || !is_string($transition[$requiredKey]) || $transition[$requiredKey] === '') {
                throw DSLValidationException::withPath('Transition requires non-empty string key: ' . $requiredKey, $path . '.' . $requiredKey);
            }
        }

        if (!in_array($transition['from'], $states, true)) {
            throw DSLValidationException::withPath('Transition from must exist in states', $path . '.from');
        }

        if (!in_array($transition['to'], $states, true)) {
            throw DSLValidationException::withPath('Transition to must exist in states', $path . '.to');
        }
    }

    /**
     * @param mixed $rule
     */
    private function validateRuleFunctions(mixed $rule, string $path): void
    {
        if (!is_array($rule) || $rule === []) {
            return;
        }

        if (isset($rule['fn'])) {
            if (!is_string($rule['fn']) || !$this->functions->has($rule['fn'])) {
                throw DSLValidationException::withPath('Referenced fn must be registered in FunctionRegistry', $path . '.fn');
            }
        }

        foreach (['all', 'any'] as $operator) {
            if (!isset($rule[$operator])) {
                continue;
            }

            if (!is_array($rule[$operator])) {
                throw DSLValidationException::withPath($operator . ' must be an array', $path . '.' . $operator);
            }

            foreach ($rule[$operator] as $index => $nestedRule) {
                $this->validateRuleFunctions($nestedRule, $path . '.' . $operator . '.' . $index);
            }
        }

        if (isset($rule['not'])) {
            $this->validateRuleFunctions($rule['not'], $path . '.not');
        }
    }

    /**
     * @param array<string, mixed> $transition
     */
    private function validateFieldRules(array $transition, string $path): void
    {
        if (!isset($transition['fields']) || !is_array($transition['fields'])) {
            return;
        }

        $fields = $transition['fields'];

        if (isset($fields['visible_if'])) {
            $this->validateRuleFunctions($fields['visible_if'], $path . '.fields.visible_if');
        }

        if (isset($fields['editable_if'])) {
            $this->validateRuleFunctions($fields['editable_if'], $path . '.fields.editable_if');
        }
    }
}
