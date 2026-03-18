# Phase 4 DB Integration Tests and Active Scope Enforcement

## Summary

This feature enforces one active workflow definition per workflow and tenant at database level, and adds integration tests against a real SQLite in-memory database.

## Scope

Implemented updates:

- Added `active_scope` column and unique index in workflow definitions migration.
- Updated `DatabaseWorkflowRepository::activateDefinition()` to:
  - Build and assign active scope key.
  - Deactivate existing active rows in scope and clear their active scope.
  - Insert new active definition with active scope key.
- Added integration tests for:
  - Active definition replacement in same tenant scope.
  - Optimistic lock mismatch in stale update path.

## Files Changed

- `database/migrations/2026_03_18_000001_create_workflow_engine_tables.php`
- `src/Storage/DatabaseWorkflowRepository.php`
- `tests/Integration/DatabaseWorkflowRepositoryTest.php`

## V2 Alignment

- One active definition per `(workflow_name, tenant_id)` is now enforced in migration and repository behavior.
- Immutable instance-to-definition linkage remains unchanged.
- Optimistic locking behavior is validated in integration tests.

## Test Coverage Added

- Integration test: activate definition V1 then V2 for same scope, assert V2 is active.
- Integration test: stale version update throws `OptimisticLockException`.

## Next Steps

- Add engine-level integration tests using database driver with transition execution and persisted history assertions.
- Add integration tests for after-commit dispatch behavior under transaction failure.
