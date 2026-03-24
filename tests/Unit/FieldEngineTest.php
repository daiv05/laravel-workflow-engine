<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class FieldEngineTest extends TestCase
{
    public function test_fields_for_transition_applies_visible_and_editable_guards(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('canSee', static fn (array $context): bool => (bool) ($context['see'] ?? false));
        $functions->register('canEdit', static fn (array $context): bool => (bool) ($context['edit'] ?? false));

        $engine = new FieldEngine(new RuleEngine($functions));

        $transition = [
            'fields' => [
                'visible' => ['comment', 123],
                'editable' => ['comment', false],
                'visible_if' => ['fn' => 'canSee'],
                'editable_if' => ['fn' => 'canEdit'],
            ],
        ];

        $blocked = $engine->fieldsForTransition($transition, ['see' => false, 'edit' => false]);
        $this->assertSame([], $blocked['visible']);
        $this->assertSame([], $blocked['editable']);

        $allowed = $engine->fieldsForTransition($transition, ['see' => true, 'edit' => true]);
        $this->assertSame(['comment'], $allowed['visible']);
        $this->assertSame(['comment'], $allowed['editable']);
    }

    public function test_editable_fields_for_state_combines_legacy_and_per_field_and_deduplicates(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('allowLegacy', static fn (array $context): bool => (bool) ($context['legacy'] ?? false));
        $functions->register('allowPerField', static fn (array $context): bool => (bool) ($context['per_field'] ?? false));

        $engine = new FieldEngine(new RuleEngine($functions));

        $stateConfig = [
            'fields' => [
                'editable' => ['comment', 'status', 'status'],
                'editable_if' => ['fn' => 'allowLegacy'],
                'comment' => ['editable' => true, 'editable_if' => ['fn' => 'allowPerField']],
                'status' => ['editable' => true],
                'notes' => ['editable' => true, 'editable_if' => ['fn' => 'allowPerField']],
                'visible' => ['x'],
                'visible_if' => ['fn' => 'allowLegacy'],
            ],
        ];

        $legacyOnly = $engine->editableFieldsForState($stateConfig, ['legacy' => true, 'per_field' => false]);
        $this->assertSame(['comment', 'status'], $legacyOnly);

        $perFieldOnly = $engine->editableFieldsForState($stateConfig, ['legacy' => false, 'per_field' => true]);
        $this->assertSame(['comment', 'status', 'notes'], $perFieldOnly);
    }

    public function test_editable_fields_for_state_ignores_reserved_and_invalid_entries(): void
    {
        $engine = new FieldEngine(new RuleEngine(new FunctionRegistry()));

        $stateConfig = [
            'fields' => [
                'editable' => ['comment'],
                '' => ['editable' => true],
                'visible' => ['ignored'],
                'editable_if' => [],
                'not_array' => 'ignored',
                'comment' => ['editable' => true],
                'extra' => ['editable' => false],
            ],
        ];

        $editable = $engine->editableFieldsForState($stateConfig, []);

        $this->assertSame(['comment'], $editable);
    }

    public function test_editable_fields_for_state_returns_empty_when_fields_config_is_missing(): void
    {
        $engine = new FieldEngine(new RuleEngine(new FunctionRegistry()));

        $this->assertSame([], $engine->editableFieldsForState([], []));
        $this->assertSame([], $engine->editableFieldsForState(['fields' => 'invalid'], []));
    }
}
