# API Reference

## Overview

The package exposes a workflow engine centered on four core operations:

- start
- can
- execute
- visibleFields

It also exposes inspection operations for runtime observability:

- availableActions
- history

These operations are designed to support immutable definition versioning, UUID instance identity, and after-commit event semantics.

## start

Creates a new workflow instance linked to the currently active definition for a workflow and tenant.

### Signature

```php
start(string $workflowName, array $options = []): array
```

### Required Inputs

- workflowName: workflow definition name.

### Optional Inputs in options

- tenant_id: tenant scope for active definition lookup.
- data: arbitrary payload to initialize instance data.

Runtime tenant scope is forced by `workflow.default_tenant_id`.
Incoming `tenant_id` values are ignored.

`workflow.default_tenant_id` is mandatory package configuration.

### Behavior

- Resolves active definition for workflow + tenant.
- Creates instance with UUID instance_id.
- Stores immutable workflow_definition_id on the instance.
- Initializes state with definition initial_state.

## can

Answers whether an action is executable now by the provided actor/context.

### Signature

```php
can(string $instanceId, string $action, array $context = []): bool
```

### Behavior

- Side-effect free.
- Evaluates transition existence from current state.
- Evaluates policy/rule authorization.
- Returns false when the current state is in `final_states`, even if DSL defines outgoing transitions.
- Returns false on invalid transition or non-evaluable context.

## execute

Executes a transition atomically.

### Signature

```php
execute(string $instanceId, string $action, array $context = []): array
```

### Behavior

- Runs in storage transaction.
- Applies transition `mappings` when configured.
- Requires `context.data` as array when mappings exist.
- Updates state and optimistic-lock version.
- Appends history entry.
- Queues events inside transaction.
- Flushes event dispatch after successful commit.
- Clears queued events on failure.
- Rejects transitions from final states with `InvalidTransitionException`.

## resolveMappedData

Resolves read models for mapped fields (including related records via binding query handlers) for a transition from the current instance state.

### Signature

```php
resolveMappedData(string $instanceId, string $action, array $context = [], array $options = []): array
```

### Behavior

- Reads instance snapshot from storage.
- Resolves transition mappings by `(current_state, action)`.
- Returns `attribute` fields from instance data.
- Resolves `attach` and `relation` through configured binding `query_handler` when available.
- Resolves `custom` via mapping handler/query handler when it implements read contract.

## execution

Creates an execution-scoped builder that can attach inline listeners and lifecycle hooks for a single transition execution.

### Signature

```php
execution(?string $instanceId = null): ExecutionBuilder
```

### Builder Methods

- `forInstance(string $instanceId): self`
- `on(string $event, callable $callback): self`
- `onAny(callable $callback): self`
- `before(callable $callback): self`
- `after(callable $callback): self`
- `execute(string $action, array $context = []): array`

### Behavior

- Inline listeners are execution-scoped and in-memory only.
- `on(event)` listeners receive event payload.
- `onAny()` listeners receive event name and payload.
- `before()` hooks run before transition execution.
- `after()` hooks run after successful transition execution and event flush.
- Inline listeners are executed before global Laravel event listeners.
- Event payload includes transition metadata:
	- `instance_id`
	- `action`
	- `transition_id`
	- `context` (execution context passed to `execute`)
	- `meta` (optional, from transition effect definition)

### Example

```php
$result = $engine->execution($instanceId)
	->before(function (string $action, array $context, string $instanceId): void {
		// pre-execution hook
	})
	->on('request_approved', function (array $payload): void {
		// execution-scoped event listener
	})
	->onAny(function (string $event, array $payload): void {
		// catch-all listener for this execution
	})
	->after(function (string $action, array $context, array $updated, string $instanceId): void {
		// post-execution hook
	})
	->execute('approve', ['roles' => ['HR']]);
```

## visibleFields

Returns visible/editable field maps for transitions available from current state.

### Signature

```php
visibleFields(string $instanceId, array $context = []): array
```

### Behavior

- Evaluates field conditions through rule engine.
- Returns per-action visibility/editability projection.
- Returns an empty map when the instance is in a final state.

## availableActions

Returns the list of executable actions for the current instance state and context.

### Signature

```php
availableActions(string $instanceId, array $context = []): array
```

### Behavior

- Evaluates transitions available from current state.
- Applies policy/rule authorization per action.
- Returns only actions executable now for the provided context.
- Returns empty when instance is in a final state.

## history

Returns transition history entries for a workflow instance.

### Signature

```php
history(string $instanceId): array
```

### Behavior

- Returns records ordered by creation sequence.
- Includes action, transition id, from/to states, actor, and payload snapshot.

## Context Contract

For role-based rules, context must include:

- roles: array of role strings.

For transitions with mappings, context must include:

- data: array input payload for mapped fields.

If missing or invalid, evaluation throws context validation errors in strict evaluation paths.

## Exceptions

Common domain exceptions:

- DSLValidationException
- InvalidTransitionException
- UnauthorizedTransitionException
- OptimisticLockException
- ContextValidationException
- WorkflowException

### Diagnostic Context

`WorkflowException` and derived domain exceptions provide normalized diagnostic context via `toDiagnosticContext()`, including:

- `exception_class`
- `exception_code`
- `exception_message`
- `context`

## Event Listener Error Handling

Inline listener failure behavior is controlled by configuration:

- `workflow.events.fail_silently = false` (default): the first listener exception is rethrown after global event flush.
- `workflow.events.fail_silently = true`: listener exceptions are ignored and execution continues.
