# Phase 16: Data Mapper V2 Hardening

## Date

2026-03-20

## Scope

Delivered a breaking-change hardening pass for Data Mapper to align DSL, runtime behavior, API contract, and documentation.

## What Was Implemented

- Added `resolveMappedData()` to `WorkflowEngineInterface`.
- Implemented Data Mapper V2 runtime behavior:
  - relation `mode` support: `persist` and `reference_only`
  - runtime defensive validation for invalid relation mode
  - fail-silent behavior for both `map()` and `resolve()`
  - handler resolution through injected resolver (container-friendly) with fallback
- Updated service provider wiring to resolve mapping handlers via Laravel container.
- Tightened DSL mapping validation:
  - `mode` allowed only for `relation`
  - allowed relation modes: `persist`, `reference_only`
  - `attach`/`relation` require `target`
  - `attribute`/`custom` reject `target`
  - `custom.handler` must be a valid class name

## Tests Updated

### Unit

- `tests/Unit/DataMapperTest.php`
  - relation `persist` and `reference_only`
  - fail-silent behavior in resolve path
  - handler resolution through injected resolver
- `tests/Unit/ValidatorTest.php`
  - invalid relation mode error
  - mode rejected for attach
  - invalid custom handler class
  - valid relation mode accepted

### Integration

- `tests/Integration/WorkflowEngineDataMappingTest.php`
  - relation mode reflected in history `mapping_summary`
  - relation `reference_only` mapping and read-path resolution

## Documentation Updated

- `docs/experimental/DATA-MAPPER.md` rewritten as executable V2 specification.
- `docs/API.md` updated with V2 mapping semantics and runtime behavior.
- `docs/ARCHITECTURE.md` updated with V2 mapping constraints.

## Breaking Changes

- `custom.handler` must be a valid class name.
- `mode` is rejected outside `relation` mappings.
- `relation.mode` values are restricted to `persist|reference_only`.
- `target` is rejected for `attribute` and `custom` mappings.
- Public engine contract now includes `resolveMappedData()`.

## Notes

- Engine core remains ORM-agnostic.
- Mapping handlers continue to host application-specific persistence logic.
- Existing V1 DSL definitions using incompatible keys/modes require migration before activation.
