# Phase 17 - Observability and Troubleshooting Support

## Summary

This phase introduces a diagnostics layer to improve operational visibility and troubleshooting.

Implemented capabilities:

- structured transition diagnostics on success and failure
- structured outbox diagnostics per item and per batch
- normalized error context export from workflow domain exceptions
- operational guide for runtime troubleshooting

## Implementation

### Diagnostics abstraction

New contract:

- `DiagnosticsEmitterInterface`

Implementations:

- `LaravelDiagnosticsEmitter`
- `NullDiagnosticsEmitter`

Provider wiring selects implementation based on `workflow.diagnostics.enabled`.

### Transition diagnostics

`TransitionExecutor` now emits:

- `transition.executed`
- `transition.failed`

Failure diagnostics include normalized exception metadata from `WorkflowException::toDiagnosticContext()`.

### Outbox diagnostics

`OutboxProcessor` now emits:

- `outbox.item.dispatched`
- `outbox.item.failed`
- `outbox.batch.completed`
- `outbox.batch.skipped`

### Configuration

Added config block:

- `workflow.diagnostics.enabled`
- `workflow.diagnostics.prefix`

## Test Coverage

- `DomainExceptionsTest::test_workflow_exception_exports_normalized_diagnostic_context`
- `WorkflowEngineTest::test_it_emits_diagnostics_for_transition_success_and_failure`
- `OutboxProcessorTest::test_it_emits_diagnostics_for_outbox_processing`

## Documentation

Updated docs:

- `docs/API.md`
- `docs/ARCHITECTURE.md`
- `docs/OPERATIONS.md`

## Outcome

Support and operations teams can now consume stable, structured diagnostics from transitions and outbox processing while preserving the package's decoupled architecture.
