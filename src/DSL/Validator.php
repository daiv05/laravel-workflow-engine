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

        $stateNames = $this->extractStateNames($definition['states']);

        if (!in_array($definition['initial_state'], $stateNames, true)) {
            throw DSLValidationException::withPath('initial_state must exist in states', 'initial_state');
        }

        if (!isset($definition['final_states']) || !is_array($definition['final_states']) || $definition['final_states'] === []) {
            throw DSLValidationException::withPath('final_states must be a non-empty array', 'final_states');
        }

        foreach ($definition['final_states'] as $index => $finalState) {
            if (!in_array($finalState, $stateNames, true)) {
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
            $this->validateTransition($transition, $stateNames, $path);
            $this->validateTransitionValidation($transition, $path);
            $this->validateRuleFunctions($transition['allowed_if'] ?? [], $path . '.allowed_if');
            $this->validateFieldRules($transition, $path);
            $this->validateMappings($transition, $path);

            $uniqueKey = $transition['from'] . '::' . $transition['action'];
            if (isset($uniqueTransitionPerState[$uniqueKey])) {
                throw DSLValidationException::withPath('Duplicate transition action for state', $path . '.action');
            }

            $uniqueTransitionPerState[$uniqueKey] = true;
        }

        $this->validateStatesConfig($definition['states']);
    }

    /**
     * @param array<int, mixed> $states
     *
     * @return array<int, string>
     */
    private function extractStateNames(array $states): array
    {
        $stateNames = [];

        foreach ($states as $index => $state) {
            if (is_string($state) && $state !== '') {
                $stateNames[] = $state;
                continue;
            }

            if (is_array($state) && isset($state['name']) && is_string($state['name']) && $state['name'] !== '') {
                $stateNames[] = $state['name'];
                continue;
            }

            throw DSLValidationException::withPath('each state must be a non-empty string or an object with non-empty name', 'states.' . $index);
        }

        return $stateNames;
    }

    /**
     * @param array<int, mixed> $states
     */
    private function validateStatesConfig(array $states): void
    {
        foreach ($states as $index => $state) {
            if (!is_array($state)) {
                continue;
            }

            $path = 'states.' . $index;

            if (isset($state['permissions']) && is_array($state['permissions']) && isset($state['permissions']['update'])) {
                $update = $state['permissions']['update'];

                if (!is_bool($update) && !is_array($update)) {
                    throw DSLValidationException::withPath('permissions.update must be a boolean or object', $path . '.permissions.update');
                }

                if (is_array($update) && isset($update['allowed_if'])) {
                    $this->validateRuleFunctions($update['allowed_if'], $path . '.permissions.update.allowed_if');
                }
            }

            if (isset($state['fields']) && is_array($state['fields'])) {
                $this->validateStateFieldRules($state['fields'], $path . '.fields');
            }

            $this->validateMappings($state, $path);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function validateStateFieldRules(array $fields, string $path): void
    {
        if (isset($fields['visible_if'])) {
            $this->validateRuleFunctions($fields['visible_if'], $path . '.visible_if');
        }

        if (isset($fields['editable_if'])) {
            $this->validateRuleFunctions($fields['editable_if'], $path . '.editable_if');
        }

        foreach ($fields as $fieldName => $config) {
            if (!is_string($fieldName) || $fieldName === '' || in_array($fieldName, ['visible', 'editable', 'visible_if', 'editable_if'], true)) {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            if (isset($config['editable_if'])) {
                $this->validateRuleFunctions($config['editable_if'], $path . '.' . $fieldName . '.editable_if');
            }

            if (isset($config['visible_if'])) {
                $this->validateRuleFunctions($config['visible_if'], $path . '.' . $fieldName . '.visible_if');
            }
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

        if (isset($rule['args']) && !is_array($rule['args'])) {
            throw DSLValidationException::withPath('args must be an array', $path . '.args');
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

    /**
     * @param array<string, mixed> $transition
     */
    private function validateTransitionValidation(array $transition, string $path): void
    {
        if (!array_key_exists('validation', $transition)) {
            return;
        }

        if (!is_array($transition['validation'])) {
            throw DSLValidationException::withPath('validation must be an object-like array', $path . '.validation');
        }

        if (!array_key_exists('required', $transition['validation'])) {
            return;
        }

        if (!is_array($transition['validation']['required'])) {
            throw DSLValidationException::withPath('validation.required must be an array', $path . '.validation.required');
        }

        foreach ($transition['validation']['required'] as $index => $field) {
            if (!is_string($field) || $field === '') {
                throw DSLValidationException::withPath(
                    'validation.required entries must be non-empty strings',
                    $path . '.validation.required.' . $index
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $transition
     */
    private function validateMappings(array $transition, string $path): void
    {
        if (!array_key_exists('mappings', $transition)) {
            return;
        }

        if (!is_array($transition['mappings'])) {
            throw DSLValidationException::withPath('mappings must be an object-like array', $path . '.mappings');
        }

        $allowedTypes = ['attribute', 'attach', 'relation', 'custom'];
        $allowedRelationModes = ['persist', 'reference_only'];

        foreach ($transition['mappings'] as $field => $mapping) {
            $fieldPath = $path . '.mappings.' . (string) $field;

            if (!is_string($field) || $field === '') {
                throw DSLValidationException::withPath('mapping field key must be a non-empty string', $fieldPath);
            }

            if (!is_array($mapping)) {
                throw DSLValidationException::withPath('mapping definition must be an array', $fieldPath);
            }

            $type = $mapping['type'] ?? 'attribute';
            if (!is_string($type) || !in_array($type, $allowedTypes, true)) {
                throw DSLValidationException::withPath('mapping type must be one of: attribute, attach, relation, custom', $fieldPath . '.type');
            }

            if (($type === 'attach' || $type === 'relation') && (!isset($mapping['target']) || !is_string($mapping['target']) || $mapping['target'] === '')) {
                throw DSLValidationException::withPath('mapping target is required for attach/relation', $fieldPath . '.target');
            }

            if ($type === 'custom' && (!isset($mapping['handler']) || !is_string($mapping['handler']) || $mapping['handler'] === '')) {
                throw DSLValidationException::withPath('mapping handler is required for custom type', $fieldPath . '.handler');
            }

            if ($type === 'custom' && is_string($mapping['handler'] ?? null) && !class_exists($mapping['handler'])) {
                throw DSLValidationException::withPath('mapping handler must be a valid class name', $fieldPath . '.handler');
            }

            if (($type === 'attribute' || $type === 'custom') && array_key_exists('target', $mapping)) {
                throw DSLValidationException::withPath('mapping target is not allowed for attribute/custom', $fieldPath . '.target');
            }

            if (($type === 'attribute' || $type === 'attach' || $type === 'custom') && array_key_exists('mode', $mapping)) {
                throw DSLValidationException::withPath('mapping mode is only allowed for relation', $fieldPath . '.mode');
            }

            if ($type === 'relation' && array_key_exists('mode', $mapping)) {
                if (!is_string($mapping['mode']) || !in_array($mapping['mode'], $allowedRelationModes, true)) {
                    throw DSLValidationException::withPath('relation mode must be one of: persist, reference_only', $fieldPath . '.mode');
                }
            }
        }
    }
}
