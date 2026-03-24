# Phase 1 - API Contract Stabilization

## Summary

This phase stabilizes package contracts and container bindings before deeper engine refactoring.
The implementation accepts future breaking changes as part of the CRAP-reduction roadmap.

## Changes

- Added `ExecutionBuilderInterface` to formalize the execution builder public contract.
- Added `WorkflowManagerInterface` to separate administrative operations from runtime workflow operations.
- Extended `WorkflowEngineInterface` with `execution(?string $instanceId = null)` returning `ExecutionBuilderInterface`.
- Updated `ExecutionBuilder` to implement `ExecutionBuilderInterface`.
- Updated `WorkflowEngine` to implement both `WorkflowEngineInterface` and `WorkflowManagerInterface`.
- Added container aliases in `WorkflowServiceProvider`:
  - `WorkflowEngine` -> `WorkflowEngineInterface`
  - `WorkflowEngine` -> `WorkflowManagerInterface`
- Expanded `Workflow` facade phpdoc to include the complete public surface used by applications.
- Updated `docs/API.md` with container contracts and advanced API usage notes.

## Constraints and Behavior

- Runtime behavior of transitions, updates, persistence, and event emission is unchanged in this phase.
- No DSL semantics were modified.
- This phase is contract/binding oriented and prepares low-risk refactoring steps in executors and validators.

## Tests Updated

- `tests/Unit/WorkflowServiceProviderTest.php`
  - verifies engine resolution through `WorkflowEngineInterface`
  - verifies manager resolution through `WorkflowManagerInterface`
- `tests/Unit/ExecutionBuilderTest.php`
  - verifies `ExecutionBuilder` implements `ExecutionBuilderInterface`

## Verification Commands

```bash
vendor/bin/phpunit tests/Unit/WorkflowServiceProviderTest.php
vendor/bin/phpunit tests/Unit/ExecutionBuilderTest.php
vendor/bin/phpunit tests/Unit/WorkflowFacadeTest.php
```
