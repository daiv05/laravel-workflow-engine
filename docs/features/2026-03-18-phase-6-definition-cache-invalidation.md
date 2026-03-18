# Phase 6 Compiled Definition Cache and Invalidation

## Summary

This feature adds in-process caching for active and id-based workflow definitions, with invalidation when a new version is activated for the same workflow and tenant scope.

## Scope

Implemented updates:

- Added active definition cache in `WorkflowEngine` keyed by `(workflow, tenant, version)`.
- Added definition-by-id cache in `WorkflowEngine`.
- Updated `start()`, `execute()`, `can()`, and `visibleFields()` to use cached definition retrieval paths.
- Updated `activateDefinition()` to:
  - Persist and fetch activated definition,
  - Invalidate older active cache entries for the same scope,
  - Register new active cache entry for current version.

## Files Changed

- `src/Engine/WorkflowEngine.php`
- `tests/Unit/WorkflowEngineCacheTest.php`

## V2 Alignment

- Cache keys include workflow, tenant, and definition version.
- Activation invalidates stale cache entries for the same scope.
- Immutable instance-to-definition behavior remains unchanged.

## Test Coverage Added

- Unit test validating that activating a new version in same scope changes the `start()` initial state as expected, proving cache invalidation behavior.

## Notes

- Cache is process-local (in-memory) and intended as baseline optimization.
- Distributed cache invalidation remains future work for multi-node deployments.
