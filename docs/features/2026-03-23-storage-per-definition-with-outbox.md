# Storage Per Definition with Outbox Routing

## Summary

This feature enables runtime storage table selection per workflow definition while keeping the global storage driver.

The workflow catalog (`workflow_definitions`) remains global. Runtime state and history tables are selected in each definition through DSL `storage.binding`.

Outbox events can also be routed per definition through the selected binding.

## DSL Contract

Optional root section:

```yaml
storage:
  binding: wf_example
```

Rules:

- `storage` must be an object-like array when provided.
- `binding` is required when `storage` exists.
- `binding` must exist in `config/workflow.php` under `storage.bindings`.
- Direct DSL table keys are rejected (`instances_table`, `histories_table`, `outbox_table`).

## Runtime Design

### Global Catalog

- Definitions are still activated and resolved from `workflow_definitions`.
- Definition JSON persists `storage` metadata and drives routing.

### Instance Locator

- New global table: `workflow_instance_locator`.
- It maps `instance_id` to runtime tables and keeps query fields used by subject lookups.
- Repository methods `getInstance`, `updateInstanceWithVersionCheck`, `appendHistory`, `getHistory`, and subject query methods resolve runtime tables through this locator.

### Dynamic Runtime Tables

- Database repository resolves `storage.binding` to table names from config and ensures runtime instance/history tables exist for each activated definition.
- If definition does not provide `storage`, `storage.default_binding` is used.

### Outbox Routing

- `DatabaseOutboxStore` now supports multiple outbox tables.
- It stores records in a table selected by event metadata and tracks known tables in `workflow_outbox_tables`.
- `fetchPending()` reads pending/failed records across registered outbox tables.

## Configuration Changes

`config/workflow.php` additions:

- `storage.instance_locator_table`
- `storage.default_binding`
- `storage.bindings`
- `outbox.tables_registry_table`

## Migrations

Updated base migration creates:

- `workflow_instance_locator`
- `workflow_outbox_tables`

## Engine Behavior Changes

- Tenant scope remains static and is resolved from `workflow.default_tenant_id`.
- Incoming `options.tenant_id` (for `start`) and `tenantId` (for `activateDefinition`) are ignored in static mode.

## Tests Added/Updated

### Unit

- `ValidatorTest`:
  - validates valid `storage.binding`
  - rejects invalid or missing bindings

### Integration

- `DatabaseWorkflowEngineTest`:
  - verifies runtime routing of instance/history/outbox to definition-specific tables
  - updates tenant-scoping behavior expectations
- `DatabaseWorkflowRepositoryTest`:
  - schema setup includes instance locator table
- `SubjectAssociationIntegrationTest`:
  - schema setup includes instance locator and outbox registry tables
- `OutboxProcessorTest`:
  - schema setup includes outbox tables registry
  - handles composite outbox pointers (`table::id`)

## Constraints

- Driver selection remains global (`memory` or `database`).
- Definition-level routing changes only binding/table destinations, not repository type.
- Existing APIs (`start`, `execute`, `can`, `visibleFields`) remain unchanged.
