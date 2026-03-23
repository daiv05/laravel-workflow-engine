# Phase: State Updates (In-State Mutations) MVP

Date: 2026-03-23

## Summary

This phase adds in-state mutation capabilities so workflow data can be changed without a state transition.

New runtime capabilities:

- `canUpdate(instanceId, context)`
- `update(instanceId, context)`
- `execution(...)->update(context)`

## Motivation

Transitions should model state changes, not frequent partial edits. This feature decouples data mutation from transition execution while preserving security, consistency, and auditability.

## Scope Implemented

- State-level update authorization via `permissions.update.allowed_if`.
- State-level editable field resolution for update payloads.
- Strict field enforcement: disallowed update keys fail the request.
- In-state persistence flow with optimistic locking and transaction boundaries.
- History entries with `action=update` and unchanged state.
- Event emission `workflow.event.updated` with after-commit dispatch semantics.
- DSL support for object-style state nodes (`states: [{name: ...}, ...]`) while preserving string-style compatibility.
- `UpdateExecutor` is wired through `WorkflowServiceProvider` and injected into `WorkflowEngine` explicitly (no implicit runtime fallback construction).

## DSL Additions

State object nodes can now include update metadata:

```yaml
states:
  - name: draft
    permissions:
      update:
        allowed_if:
          fn: isOwner
    fields:
      comment:
        editable: true
        editable_if:
          fn: canEditComment
    mappings:
      comment:
        type: attribute
```

Notes:

- `permissions.update` accepts either `boolean` or object with `allowed_if`.
- `fields` supports transition-style keys (`editable`, `editable_if`) and per-field keys.
- `mappings` under states follows the same validation shape used by transition mappings.

## Runtime Semantics

`update(instanceId, context)` executes this flow:

1. Resolve instance and active definition snapshot.
2. Resolve current state config from compiled `state_configs`.
3. Evaluate update authorization through policy/rule engine.
4. Require `context.data` as array.
5. Resolve editable fields and reject disallowed keys.
6. Apply state mappings when configured; otherwise merge editable keys into instance data.
7. Persist with optimistic locking (`version += 1`).
8. Append history (`action=update`, `from_state==to_state`).
9. Queue and flush `workflow.event.updated` after commit.

## New Exceptions

- `UnauthorizedUpdateException` (code `5002`): update denied by state policy.
- `InvalidUpdateException` (code `3002`): payload includes non-editable keys.

## Backward Compatibility

- Existing string-based `states` definitions remain valid.
- Existing transition execution behavior is unchanged.
- Existing events and transition history semantics remain unchanged.

## Tests Added

- Unit: validator coverage for state update permission/rule references.
- Integration (in-memory):
  - update mutates data without transitioning state
  - update writes history with `action=update`
  - update emits `workflow.event.updated`
  - disallowed field updates fail
- Integration (database): update persistence + history + event dispatch.

## Follow-Up Work

- Add richer diagnostics events for update success/failure.
- Add API-layer tests in `workflow-engine-testing` for endpoint-level update flows.
