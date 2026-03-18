# Phase 14 - Definition Version Immutability Guard

## Summary

This phase enforces immutable workflow definition versions by rejecting duplicate version activations within the same scope.

Scope key:
- `workflow_name`
- `tenant_id` (nullable)
- `version`

## Behavior

When activating a definition:
- If `(workflow_name, version, tenant_id)` already exists, activation is rejected.
- Error message: `Workflow definition version is immutable and already exists for scope`.
- To change behavior, a new version must be created and activated.

## Implementation

### InMemory storage

- Added in-memory version index keyed by `scope::v{version}`.
- Added duplicate version check before inserting a new definition.
- Included the new index in transaction rollback snapshots.

### Database storage

- Added explicit pre-insert existence check for `(workflow_name, version, tenant_id)`.
- Throws a domain error before deactivation/insert path continues.

## Tests

### Unit

- `InMemoryWorkflowRepositoryTest::test_activate_definition_rejects_duplicate_version_for_same_scope`

### Integration

- `DatabaseWorkflowRepositoryTest::test_activate_definition_rejects_duplicate_version_for_same_scope`

## Design Alignment

This phase reinforces DesignDoc V2 immutable versioning policy:

- Definitions are immutable once activated.
- Existing instances remain linked to their original `workflow_definition_id`.
- Definition updates are modeled as new versions only.
