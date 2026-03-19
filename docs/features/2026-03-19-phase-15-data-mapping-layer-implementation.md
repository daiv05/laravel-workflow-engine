# Phase 15: Data Mapping Layer Implementation

## Date

2026-03-19

## Scope

Implemented Data Mapping Layer and Binding Read Path for transition execution.

## Delivered Components

- Added mapping contracts:
  - `DataMapperInterface`
  - `MappingHandlerInterface`
  - `MappingQueryHandlerInterface`
- Added mapping service:
  - `DataMapper`
- Added mapping domain exception:
  - `MappingException`
- Added config sections:
  - `workflow.mappings.fail_silently`
  - `workflow.bindings`
- Integrated mapper into transition execution transaction.
- Added retrieval support through `WorkflowEngine::resolveMappedData()`.

## Behavior

### Execute Path

When a transition has `mappings`:

1. Engine validates `context.data` exists and is an array.
2. `DataMapper` applies mapping by type.
3. `workflow_instances.data` is updated with mapped values/references.
4. History payload stores safe context summary and mapping summary.

Supported mapping types:

- `attribute`: stores value directly in instance data.
- `attach`: stores references (ids) in instance data.
- `relation`: delegates persistence to configured binding handler.
- `custom`: delegates to configured custom handler class.

### Read Path

`resolveMappedData(instanceId, action, context, options)`:

1. Loads current instance snapshot.
2. Resolves transition mapping for current state + action.
3. Returns composed data:
   - `attribute` from instance data.
   - `attach`/`relation` through `query_handler` when configured.
   - `custom` through handler read contract when available.

## Data Safety

History payload no longer requires serializing full runtime context for mapping flows.
It stores:

- transition metadata
- mapping summary
- safe context summary (`has_data`, `data_keys`, optional role/meta keys)

## Validation

DSL validator now enforces mapping schema:

- `mappings` must be object-like array.
- mapping `type` must be one of `attribute|attach|relation|custom`.
- `target` required for `attach` and `relation`.
- `handler` required for `custom`.

## Test Coverage Added

### Unit

- `tests/Unit/DataMapperTest.php`
  - happy path for write and read behavior
  - missing binding error path
- `tests/Unit/ValidatorTest.php`
  - invalid mapping type
  - relation mapping without target

### Integration

- `tests/Integration/WorkflowEngineDataMappingTest.php`
  - full flow: execute with mappings + safe history payload
  - `context.data` required error path
  - rollback when mapping handler fails (no partial side effects)

## Notes

- Memory and database drivers remain supported.
- Mapping handlers are storage-agnostic by contract.
- Existing public API (`start`, `execute`, `can`, `visibleFields`) remains intact.
