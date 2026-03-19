# Architecture

## Layer Boundaries

The package is organized by explicit layers with strict responsibilities:

- DSL: parsing, validation, compilation.
- Rules: condition evaluation.
- Policies: transition authorization.
- Fields: dynamic visibility/editability decisions.
- Engine: orchestration of workflow lifecycle and transition execution.
- DataMapping: mapping input payloads into instance snapshot and external handlers.
- Functions: controlled function registration and lookup.
- Events: event queueing and post-commit dispatch.
- Storage: persistence for definitions, instances, history, and outbox.

## Core Execution Flow

1. Load active definition by workflow + tenant.
2. Resolve transition from current state + action.
3. Evaluate authorization/rules.
4. Execute transactional update:
- validate mapping context contract when transition has `mappings`,
- apply `DataMapper` to `context.data`,
- state/version mutation,
- history append,
- event queueing (and outbox insert for DB mode).
5. After successful commit, flush event dispatch.

## Mapping Read Path

- Write path: transition execution applies `mappings` through `DataMapper`.
- Read path: engine resolves mapped fields through `resolveMappedData(instanceId, action)`.
- `attribute`: returned from `workflow_instances.data`.
- `attach` and `relation`: resolved through binding query handlers when configured.
- `custom`: resolved by custom handlers implementing query contract.

## Data Consistency Model

- Optimistic locking via instance `version`.
- One active definition per `(workflow_name, tenant_id)` scope.
- Immutable `workflow_definition_id` binding per instance.
- Transaction-based transition side effects.

## Caching Model

Definition cache layers:

- Process-local cache in engine.
- Optional shared Laravel cache store.

Invalidation:

- Activating a new definition invalidates active scope cache pointer and stale version entry.

## Event Delivery Model

- Events are queued during transaction.
- Execution-scoped inline listeners can be attached through `ExecutionBuilder`.
- Inline listeners are invoked per emitted effect event before global event dispatch.
- Queue is flushed after commit.
- In database mode, queued events are persisted to outbox table before commit and marked dispatched after flush.
- A dedicated outbox processor service can replay pending/failed records with bounded retries.

### Execution Builder Model

- `WorkflowEngine::execution(?string $instanceId = null)` returns an `ExecutionBuilder`.
- The builder collects listeners and hooks for a single execute call.
- Builder hooks are orchestration-level concerns:
	- `before`: runs before transition execution.
	- `after`: runs after successful transition execution.
- No global state is mutated when registering inline listeners.

## Diagnostics Model

- Technical diagnostics are emitted through a dedicated diagnostics emitter abstraction.
- Transition diagnostics include success and failure signals with structured exception metadata.
- Outbox diagnostics include per-item dispatched/failed events and per-batch summaries.
- Diagnostics emission can be toggled through package configuration.

## Multi-Tenant Isolation

- Definition activation and lookup are scoped by workflow + tenant.
- Cache keys include tenant dimension.
- Active scope uniqueness prevents cross-tenant active definition conflicts.
- Engine operations are forced into `workflow.default_tenant_id` scope.
