<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Functions\SubjectRuleFunctions;
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

    public function test_it_validates_subject_function_args_when_shape_is_correct(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('subject_type_matches', [SubjectRuleFunctions::class, 'subjectTypeMatches']);
        $functions->register('is_subject_owner', [SubjectRuleFunctions::class, 'isSubjectOwner']);

        $validator = new Validator($functions);
        $validator->validate([
            'dsl_version' => 2,
            'name' => 'subject_validation_ok',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_validation_ok',
                    'allowed_if' => [
                        'all' => [
                            [
                                'fn' => 'subject_type_matches',
                                'args' => ['App\\Models\\Solicitud'],
                            ],
                            [
                                'fn' => 'is_subject_owner',
                                'args' => ['actor_id'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_it_fails_when_subject_type_matches_has_missing_or_invalid_args(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('subject_type_matches', [SubjectRuleFunctions::class, 'subjectTypeMatches']);

        $validator = new Validator($functions);

        $this->expectException(DSLValidationException::class);
        $this->expectExceptionMessage('subject_type_matches requires args[0] as non-empty string expected subject type');

        $validator->validate([
            'dsl_version' => 2,
            'name' => 'subject_validation_error',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_validation_error',
                    'allowed_if' => [
                        'fn' => 'subject_type_matches',
                        'args' => [''],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_fails_when_is_subject_owner_arg_key_is_invalid(): void
    {
        $functions = new FunctionRegistry();
        $functions->register('is_subject_owner', [SubjectRuleFunctions::class, 'isSubjectOwner']);

        $validator = new Validator($functions);

        $this->expectException(DSLValidationException::class);
        $this->expectExceptionMessage('is_subject_owner args[0], when provided, must be a non-empty actor id context key');

        $validator->validate([
            'dsl_version' => 2,
            'name' => 'subject_owner_validation_error',
            'version' => 1,
            'initial_state' => 'draft',
            'final_states' => ['approved'],
            'states' => ['draft', 'approved'],
            'transitions' => [
                [
                    'from' => 'draft',
                    'to' => 'approved',
                    'action' => 'approve',
                    'transition_id' => 'tr_approve_subject_owner_validation_error',
                    'allowed_if' => [
                        'fn' => 'is_subject_owner',
                        'args' => [123],
                    ],
                ],
            ],
        ]);
    }
}
