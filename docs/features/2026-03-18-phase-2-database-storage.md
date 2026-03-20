# Phase 2 Database Storage and Driver Wiring

## Summary

This feature adds a database-backed storage implementation and package migrations, while keeping the in-memory repository available as a fallback driver.

## Scope

Implemented modules:

- `DatabaseWorkflowRepository` with support for definitions, instances, history, and optimistic locking checks.
- Package migration `2026_03_18_000001_create_workflow_engine_tables.php`.
- Service provider storage driver selection using `workflow.default_driver`.
- Migration auto-loading and migration publish tag.
- Config update for storage table keys.
- Test update to assert `can()` is side-effect free.

## V2 Decisions Enforced

- `instance_id` persisted as UUID in `workflow_instances`.
- `workflow_definition_id` persisted as immutable link from instance to definition.
- Definitions are activated by deactivating current active row for `(workflow_name, tenant_id)` and inserting a new active version.
- Optimistic locking uses `version` check in `updateInstanceWithVersionCheck()`.
- Event dispatch semantics remain after successful persistence path.

## Constraints and Notes

- Active-definition uniqueness is currently enforced at repository activation logic and indexed lookup.
- Follow-up note (added later): a strict uniqueness guard was introduced via `workflow_definitions.active_scope` unique index in the migration path, while keeping activation logic checks in the repository.
- The migration stores full compiled definition JSON in `workflow_definitions.definition`.
- SQL repository currently targets Laravel's query builder connection API.
- The `workflow.storage.*_table` config keys are present for forward compatibility; dynamic table-name resolution is intentionally deferred to a dedicated future feature.

## Changed Files

- `src/Storage/DatabaseWorkflowRepository.php`
- `database/migrations/2026_03_18_000001_create_workflow_engine_tables.php`
- `src/Providers/WorkflowServiceProvider.php`
- `config/workflow.php`
- `composer.json`
- `tests/Integration/WorkflowEngineTest.php`

## Next Steps

- Add integration tests against real database transactions.
- Add strict enforcement for one active definition per `(workflow_name, tenant_id)` according to selected DB engine capabilities.
- Add outbox or equivalent mechanism for robust after-commit event delivery in distributed environments.
