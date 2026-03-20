# Phase 5 Engine Database Transaction Integration Tests

## Summary

This feature adds engine-level integration tests using the database repository over SQLite in-memory, validating full-flow persistence and transactional rollback behavior.

## Scope

Implemented tests:

- Full DB flow test: `start -> execute submit -> execute approve`.
- Persisted history assertion for the two transitions.
- Event dispatch assertion with configured prefix after successful commit.
- Rollback test when event queueing fails during transition execution.
- No success-event dispatch assertion when rollback happens.
- Instance state/version unchanged assertion after rollback.

## Files Added

- `tests/Integration/DatabaseWorkflowEngineTest.php`

## V2 Alignment

- Confirms transaction-based execution with after-commit dispatch semantics.
- Confirms consistency guarantees: no partial state mutation on transition failure.

## Notes

- Tests instantiate workflow engine components directly to keep package-level integration explicit.
- SQLite in-memory is used to exercise real database transaction behavior quickly.

## Status Note (2026-03-20)

- As of today, tenant handling at `WorkflowEngine` level remains static by design (resolved from configured default tenant).
- Extra comment: dynamic tenant resolution at engine entry points is still planned functionality and is not enabled yet.
- Explanation: rollback guarantees in this phase remain valid; when a transition fails before commit, the success effect event is not dispatched, while failure telemetry events can still be emitted.

## Next Steps

- Completed in later phases:
	- Multi-tenant active definition isolation at engine level.
	- Cache layer and invalidation tests for compiled definitions per tenant/version.

