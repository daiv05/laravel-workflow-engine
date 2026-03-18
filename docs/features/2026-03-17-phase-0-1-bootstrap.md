# Phase 0-1 Bootstrap Implementation

## Summary

This feature introduces the initial executable foundation of the workflow engine package.

## Scope

Implemented modules:

- Contracts for engine, storage, rules, events, and function registry.
- Domain exceptions for DSL validation, transition integrity, authorization, and optimistic locking.
- Function registry with secure named-function lookup.
- DSL parser, validator, and compiler with V2 constraints.
- Rule engine with `role`, `fn`, `all`, `any`, and `not` operators.
- Policy engine and field engine.
- State machine and transition executor.
- In-memory storage repository for active definition, instances, and history.
- Workflow engine orchestration (`start`, `can`, `execute`, `visibleFields`).
- Laravel service provider, facade, and package config bootstrap.

## V2 Decisions Enforced

- `instance_id` uses UUID v4 generation.
- `can()` is side-effect free and evaluates current executability.
- Events are queued and flushed only after successful persistence path (after-commit semantics in this in-memory baseline).
- One active definition is enforced per `(workflow_name, tenant_id)` in repository activation map.
- Versioning is immutable by design because each instance stores `workflow_definition_id` and does not switch definitions.

## Constraints and Known Gaps

- Storage currently uses an in-memory repository (no database migrations yet).
- YAML parsing is not included yet; parser currently expects normalized arrays.
- Event dispatcher currently stores dispatched events internally; Laravel event bus integration is pending.
- `visibleFields()` currently returns transition field maps for transitions from current state.

## Test Coverage Added

- Unit: DSL validator happy path and missing function failure.
- Integration: `start -> can -> execute` flow with role-based approval.

## Next Steps

- Add database storage implementation and migrations.
- Add transactional repository behavior and stricter optimistic locking tests.
- Add Laravel event dispatch integration with `ShouldDispatchAfterCommit` equivalent strategy.
- Expand error path tests and add caching invalidation behavior.
