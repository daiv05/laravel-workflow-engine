# Data Mapper V2 Specification

## 1. Purpose

Data Mapper V2 defines a deterministic mapping contract between workflow transition input and external persistence handlers, while keeping the engine independent from ORM and business storage details.

## 2. Design Goals

- Keep mappings declarative in DSL.
- Keep persistence logic in application handlers.
- Keep runtime deterministic and auditable.
- Keep memory/database driver compatibility.

## 3. Supported Mapping Types

### `attribute`

- Stores the incoming field value directly in `workflow_instances.data`.
- No `target`.
- No `mode`.

### `attach`

- Stores normalized references only (for example IDs).
- Requires `target`.
- No `mode`.

### `relation`

- Delegates write/read to binding handlers.
- Requires `target`.
- Supports `mode`:
  - `create_many` (default)
  - `reference_only`

`mode` is a validated runtime hint. The engine does not run ORM logic itself.

### `custom`

- Delegates write/read to a custom handler class.
- Requires `handler` as valid FQCN.
- `target` is not allowed.
- `mode` is not allowed.

## 4. DSL Schema Rules (V2)

For each mapping field:

- `type` must be one of: `attribute`, `attach`, `relation`, `custom`.
- `attach` and `relation` require `target` (non-empty string).
- `relation.mode` if present must be `create_many` or `reference_only`.
- `mode` is allowed only for `relation`.
- `custom.handler` must be a valid class name.
- Unsupported keys for a mapping type fail validation.

## 5. Runtime Contracts

### `DataMapperInterface`

```php
interface DataMapperInterface
{
    public function map(array $mappings, array $instanceData, array $inputData, array $context = []): array;

    public function resolve(array $mappings, array $instanceData, array $context = [], array $options = []): array;
}
```

### Handler Contracts

```php
interface MappingHandlerInterface
{
    public function handle(mixed $value, array $context): ?array;
}

interface MappingQueryHandlerInterface
{
    public function fetch(array $context, array $options = []): mixed;
}
```

## 6. Binding Configuration

```php
'bindings' => [
    'documents' => [
        'handler' => App\Workflow\Mappings\DocumentBindingHandler::class,
        'query_handler' => App\Workflow\Mappings\DocumentBindingHandler::class,
    ],
],
```

`handler` and `query_handler` are resolved through the Laravel container when available, with fallback to direct instantiation.

## 7. Execution and Read Flow

Write path in `execute()`:

1. Transition is resolved and authorized.
2. `context.data` is required if transition has mappings.
3. `DataMapper::map()` applies field mappings.
4. Instance state/data and history are persisted in one transaction.
5. Events are flushed after commit.

Read path in `resolveMappedData()`:

1. Resolve transition by state/action.
2. Fallback to latest history transition match.
3. Fallback to unique action transition.
4. `DataMapper::resolve()` composes field outputs.

## 8. History and Audit

History payload stores:

- transition metadata
- safe context summary
- `mapping_summary` per mapped field

`mapping_summary` includes stable keys:

- `type`
- `status`
- `target` (when applicable)
- `mode` (for relation)
- `error` (when fail-silent captures an error)

## 9. Error Model

When `workflow.mappings.fail_silently = false`:

- invalid mapping type or mode fails fast
- missing binding fails fast
- invalid handler class or contract fails fast

When `workflow.mappings.fail_silently = true`:

- write/read mapping errors are captured and field processing falls back safely

## 10. Non-Goals

- No ORM orchestration in engine core.
- No direct DB access from DSL.
- No dynamic code execution from DSL.

## 11. Migration Notes (Breaking)

- `relation.mode` is validated.
- `custom.handler` must be a real class name.
- `mode` on non-`relation` mappings is rejected.
- `target` on `attribute` and `custom` mappings is rejected.

## 12. Test Requirements

Minimum coverage for V2:

- Unit: validator errors and accepted V2 mappings.
- Unit: mapper write/read for all mapping types.
- Unit: relation mode behavior (`create_many`, `reference_only`).
- Integration: `start -> can -> execute -> events -> persistence` with mappings.
- Integration: rollback without partial side effects when handler fails.
