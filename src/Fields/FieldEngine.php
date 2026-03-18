<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Fields;

use Daiv05\LaravelWorkflowEngine\Contracts\RuleEngineInterface;

class FieldEngine
{
    public function __construct(private readonly RuleEngineInterface $rules)
    {
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<string, mixed> $context
     *
     * @return array{visible: array<int, string>, editable: array<int, string>}
     */
    public function fieldsForTransition(array $transition, array $context): array
    {
        $visible = [];
        $editable = [];

        if (!isset($transition['fields']) || !is_array($transition['fields'])) {
            return ['visible' => $visible, 'editable' => $editable];
        }

        /** @var array<string, mixed> $fields */
        $fields = $transition['fields'];

        if (isset($fields['visible']) && is_array($fields['visible'])) {
            $visible = array_values(array_filter($fields['visible'], 'is_string'));
        }

        if (isset($fields['editable']) && is_array($fields['editable'])) {
            $editable = array_values(array_filter($fields['editable'], 'is_string'));
        }

        if (isset($fields['visible_if']) && is_array($fields['visible_if']) && !$this->rules->evaluate($fields['visible_if'], $context)) {
            $visible = [];
        }

        if (isset($fields['editable_if']) && is_array($fields['editable_if']) && !$this->rules->evaluate($fields['editable_if'], $context)) {
            $editable = [];
        }

        return ['visible' => $visible, 'editable' => $editable];
    }
}
