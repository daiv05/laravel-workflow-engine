# Phase 5 Engine Database Transaction Integration Tests

## Summary

This feature adds engine-level integration tests using the database repository over SQLite in-memory, validating full-flow persistence and transactional rollback behavior.

## Scope

Implemented tests:

- Full DB flow test: `start -> execute submit -> execute approve`.
- Persisted history assertion for the two transitions.
- Event dispatch assertion with configured prefix after successful commit.
- Rollback test when event queueing fails during transition execution.
- No-dispatch assertion when rollback happens.
- Instance state/version unchanged assertion after rollback.

## Files Added

- `tests/Integration/DatabaseWorkflowEngineTest.php`

## V2 Alignment

- Confirms transaction-based execution with after-commit dispatch semantics.
- Confirms consistency guarantees: no partial state mutation on transition failure.

## Notes

- Tests instantiate workflow engine components directly to keep package-level integration explicit.
- SQLite in-memory is used to exercise real database transaction behavior quickly.

## Next Steps

- Completed in later phases:
	- Multi-tenant active definition isolation at engine level.
	- Cache layer and invalidation tests for compiled definitions per tenant/version.

