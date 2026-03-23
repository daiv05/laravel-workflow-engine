# Phase: Transition Required Validation

Date: 2026-03-23

## Summary

This phase implements transition-level required-field validation through DSL key `transitions[].validation.required`.

The runtime now validates required fields before any transition mutation or mapping write.

## Scope Implemented

- DSL validation for `transitions[].validation.required`:
  - `validation` must be an object-like array.
  - `validation.required` must be an array.
  - each required entry must be a non-empty string.
- Runtime enforcement in `TransitionExecutor` before state change/mappings:
  - required fields are validated against merged payload (`instance.data + context.data`).
  - missing or null required values fail the transition.
- New domain exception:
  - `InvalidTransitionValidationException` (code `3003`).
- Failure semantics:
  - transaction rollback (no state/history mutation).
  - `workflow.event.transition_failed` emitted through the existing failure path.

## DSL Example

```yaml
transitions:
  - from: draft
    to: approved
    action: submit
    transition_id: tr_submit
    validation:
      required:
        - comment
        - reason
```

## Runtime Semantics

Execution flow for `execute(instanceId, action, context)` now includes:

1. Resolve transition and policy authorization.
2. Validate `validation.required` against merged `instance.data + context.data`.
3. Continue with mappings/state/history/events only when validation passes.

Merged-data behavior:

- Existing persisted data can satisfy required keys.
- Incoming `context.data` can satisfy missing keys from persisted data.
- If a required key resolves to `null`, validation fails.

## Tests Added

- Unit:
  - `ValidatorTest`: valid/invalid `transitions[].validation.required` schema coverage.
  - `DomainExceptionsTest`: `InvalidTransitionValidationException` code/message/context coverage.
- Integration (in-memory):
  - transition fails when required keys are missing after merge.
  - transition succeeds when required keys are satisfied by merged data.
  - failure path preserves rollback semantics and emits `workflow.event.transition_failed`.
- Integration (database):
  - same pass/fail behavior with persisted state/history assertions.

## Backward Compatibility

- Existing transitions without `validation` are unchanged.
- Existing transition authorization and mapping behavior are unchanged.
- Public API signatures remain unchanged.
