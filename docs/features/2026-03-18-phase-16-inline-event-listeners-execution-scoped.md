# Phase 16 - Inline Event Listeners (Execution-Scoped Events)

## Summary

This phase introduces execution-scoped event listeners and lifecycle hooks.

Developers can now attach runtime callbacks to a single workflow execution without registering global Laravel listeners.

## Public API Additions

### Workflow engine entry point

- `WorkflowEngine::execution(?string $instanceId = null): ExecutionBuilder`

### ExecutionBuilder methods

- `forInstance(string $instanceId): self`
- `on(string $event, callable $callback): self`
- `onAny(callable $callback): self`
- `before(callable $callback): self`
- `after(callable $callback): self`
- `execute(string $action, array $context = []): array`

## Execution Semantics

- Inline listeners are execution-scoped and in-memory only.
- Events still follow transactional guarantees:
  - effect events are queued inside transaction
  - inline listeners run after successful commit
  - global Laravel dispatch still runs after commit
- Event payload now carries runtime context and optional DSL metadata:
  - `context`: execution context passed to `execute`
  - `meta`: optional `effects[].meta` value from DSL
- Listener invocation order per emitted event:
  1. named listeners (`on(event)`)
  2. catch-all listeners (`onAny`)
  3. global Laravel event dispatch

## Error Handling

Configuration:

- `workflow.events.fail_silently` (default `false`)

Behavior:

- `false`: listener exceptions bubble up (after global flush attempt).
- `true`: listener exceptions are ignored.

## Implementation Notes

- Added new class: `Engine\ExecutionBuilder`.
- Extended `WorkflowEngine` with `execution()` and `executeWithListeners()`.
- Extended `TransitionExecutor` with inline listener dispatch path and configurable listener failure mode.
- Preserved existing API behavior for `start`, `can`, `execute`, and `visibleFields`.

## Tests

Updated integration coverage in `tests/Integration/WorkflowEngineTest.php`:

- `test_execution_builder_runs_inline_listeners_and_lifecycle_hooks`
- `test_inline_listener_exception_can_be_silenced`
- `test_inline_listener_exception_bubbles_by_default`

These tests cover both happy path and error path for the new execution-scoped event behavior.
