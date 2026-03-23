<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function test_it_validates_a_definition_with_registered_function(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isHR', static fn (array $context): bool => in_array('HR', $context['roles'] ?? [], true));

        $parser = new Parser();
        $validator = new Validator($functions);

        $definition = $parser->parse([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved', 'rejected'],
            'states' => ['draft', 'approved', 'rejected'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => ['fn' => 'isHR'],
                ],
            ],
        ]);

        $validator->validate($definition);

        $this->assertTrue(true);
    }

    public function test_it_fails_when_function_reference_is_missing(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => ['fn' => 'isHR'],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_final_states_is_empty(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => [],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_visible_if_references_unregistered_function(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'fields' => [
                        'visible' => ['comment'],
                        'visible_if' => ['fn' => 'canSeeComment'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_editable_if_references_unregistered_function(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'fields' => [
                        'editable' => ['comment'],
                        'editable_if' => ['fn' => 'canEditComment'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_mapping_type_is_invalid(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'comment' => ['type' => 'unknown'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_relation_mapping_has_no_target(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'documents' => ['type' => 'relation'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_relation_mode_is_invalid(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'documents' => ['type' => 'relation', 'target' => 'documents', 'mode' => 'sync'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_attach_uses_mode(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'documents' => ['type' => 'attach', 'target' => 'documents', 'mode' => 'reference_only'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_custom_handler_is_not_a_valid_class(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'amount' => ['type' => 'custom', 'handler' => 'processMonto'],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_accepts_relation_mapping_with_supported_mode(): void
    {
        $validator = new Validator(new FunctionRegistry());

        $validator->validate([
            'dsl_version' => 2,
            'name' => 'termination_request',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve',
                    'allowed_if' => [],
                    'mappings' => [
                        'documents' => ['type' => 'relation', 'target' => 'documents', 'mode' => 'persist'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_validates_state_update_permissions_and_field_rules(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('isOwner', static fn (array $context): bool => (($context['actor'] ?? null) === ($context['owner'] ?? null)));
        $functions->register('canEditComment', static fn (array $context): bool => in_array('EDITOR', $context['roles'] ?? [], true));

        $validator = new Validator($functions);

        $validator->validate([
            'dsl_version' => 2,
            'name' => 'draft_updates',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => [
                [
                    'name' => 'draft',
                    'permissions' => [
                        'update' => [
                            'allowed_if' => ['fn' => 'isOwner'],
                        ],
                    ],
                    'fields' => [
                        'comment' => [
                            'editable' => true,
                            'editable_if' => ['fn' => 'canEditComment'],
                        ],
                    ],
                    'mappings' => [
                        'comment' => ['type' => 'attribute'],
                    ],
                ],
                'approved',
            ],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_fails_when_state_update_allowed_if_references_unregistered_function(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'draft_updates',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => [
                [
                    'name' => 'draft',
                    'permissions' => [
                        'update' => [
                            'allowed_if' => ['fn' => 'isOwner'],
                        ],
                    ],
                ],
                'approved',
            ],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                ],
            ],
        ]);
    }

    public function test_it_accepts_transition_validation_required_with_string_fields(): void
    {
        $validator = new Validator(new FunctionRegistry());

        $validator->validate([
            'dsl_version' => 2,
            'name' => 'transition_required_validation',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                    'validation' => [
                        'required' => ['comment', 'reason'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_fails_when_transition_validation_required_is_not_array(): void
    {
        $this->expectException(DSLValidationException::class);

        $validator = new Validator(new FunctionRegistry());
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'transition_required_validation',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'submit',
                    'transition_id' => 'tr_submit',
                    'allowed_if' => [],
                    'validation' => [
                        'required' => 'comment',
                    ],
                ],
            ],
        ]);
    }

}
