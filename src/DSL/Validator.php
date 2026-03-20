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
            $this->validateMappings($transition, $path);

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
    private function validateMappings(array $transition, string $path): void
    {
        if (!array_key_exists('mappings', $transition)) {
            return;
        }

        if (!is_array($transition['mappings'])) {
            throw DSLValidationException::withPath('mappings must be an object-like array', $path . '.mappings');
        }

        $allowedTypes = ['attribute', 'attach', 'relation', 'custom'];
        $allowedRelationModes = ['create_many', 'reference_only'];

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
                    throw DSLValidationException::withPath('relation mode must be one of: create_many, reference_only', $fieldPath . '.mode');
                }
            }
        }
    }
}
