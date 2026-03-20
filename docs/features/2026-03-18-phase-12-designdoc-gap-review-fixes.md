# Phase 12 - DesignDoc Gap Review Fixes

## Summary

This phase addresses high-priority gaps discovered during a DesignDoc V2 review focused on correctness, validation strictness, and regression coverage.

## Implemented Fixes

### 1) Exception Context Enrichment Without Cloning

- Updated `WorkflowException::withContext` to avoid cloning exceptions (PHP exceptions are not cloneable).
- `withContext` now returns a new exception instance with merged context.
- Base behavior is correct for constructor-only exception state.

Why:
- Unit test `DomainExceptionsTest::test_workflow_exception_can_merge_context_without_mutating_original` exposed a runtime error caused by `clone`.

### 2) Strict `final_states` Validation

- Validator now enforces `final_states` as a non-empty array (not only array type).

Why:
- Aligns implementation with documented DSL contract and error message.

### 3) `fn` Validation Extended to Field Rules

- Validator now checks `fn` references in:
  - `transitions.*.allowed_if`
  - `transitions.*.fields.visible_if`
  - `transitions.*.fields.editable_if`

Why:
- Prevents deferred runtime failures by failing fast during DSL validation.
- Aligns function registry guardrails across policy and field condition paths.

## Added/Updated Tests

### Unit

- `ValidatorTest::test_it_fails_when_final_states_is_empty`
- `ValidatorTest::test_it_fails_when_visible_if_references_unregistered_function`
- `ValidatorTest::test_it_fails_when_editable_if_references_unregistered_function`

### Integration

- `DatabaseWorkflowEngineTest::test_existing_instance_keeps_original_workflow_definition_id_after_new_version_activation`
- `DatabaseWorkflowEngineTest::test_database_engine_persists_and_marks_outbox_events_after_commit`

## DesignDoc V2 Alignment

This phase strengthens alignment with:

- Immutable versioning policy: instances remain permanently bound to their original `workflow_definition_id`.
- Secure-by-default function references: all rule contexts using `fn` are validated against `FunctionRegistry`.
- After-commit event reliability: outbox persistence and post-commit dispatch marking are now covered by integration tests.

## Residual Risks

- Full package test execution is pending in the user runtime environment.
- Additional edge-path tests can still be added for mixed nested operators inside field rules (`all/any/not`) with invalid node structures.

## Post-Review Follow-up Implemented (2026-03-20)

- Added a protected instantiation hook in `WorkflowException` used by `withContext`.
- Overrode the hook in `DSLValidationException` to preserve `nodePath` metadata.
- Added regression test `DomainExceptionsTest::test_dsl_validation_with_context_preserves_node_path_metadata`.

Result:

- The subclass metadata propagation issue for `withContext` is now mitigated for `DSLValidationException`.

## Follow-up Plan

1. Extend the same pattern to any future exception subclass that keeps extra state outside constructor arguments.
2. Keep focused regression tests whenever subclass-specific metadata is introduced.
