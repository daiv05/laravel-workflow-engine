# Phase 7 Context Contract and Tenant Isolation Tests

## Summary

This feature introduces explicit context-contract validation for rule evaluation and adds engine-level tenant isolation tests.

## Scope

Implemented updates:

- Added `ContextValidationException` for explicit context contract failures.
- Added `ContextValidator` to validate minimum context requirements for rule trees.
- Integrated context validation into `RuleEngine` before rule evaluation.
- Added unit tests for missing/invalid `roles` context in role-based rules.
- Added integration test proving active-definition isolation per tenant at engine level.

## Files Changed

- `src/Exceptions/ContextValidationException.php`
- `src/Rules/ContextValidator.php`
- `src/Rules/RuleEngine.php`
- `tests/Unit/RuleEngineContextTest.php`
- `tests/Integration/DatabaseWorkflowEngineTest.php`

## V2 Alignment

- Strengthens the minimal context contract requirement.
- Reinforces one active definition per `(workflow_name, tenant_id)` behavior from an engine consumer perspective.

## Current Status Note (As of 2026-03-20)

- Runtime tenant resolution at `WorkflowEngine` entry points is currently static.
- Tenant scope is forced by configured `workflow.default_tenant_id`.
- Incoming tenant values in `start(...)` and `activateDefinition(...)` are intentionally ignored in the current implementation.

## Planned Evolution

- Introduce dynamic tenant resolution strategy at engine entry points.
- Add explicit tenant-scoped engine APIs (for example, fluent tenant context selection) while preserving backward compatibility.
- Expand integration coverage to validate true runtime per-tenant engine behavior once dynamic resolution is enabled.

## Test Coverage Added

- Unit: context validation error paths for role-based rules.
- Integration: multi-tenant isolation for workflow definition activation and instance start behavior.

## Next Steps

- Completed in later phases:
	- Context contract section in `docs/API.md` and `docs/RULES.md`.
	- Nested `all/any/not` context validation tests.

