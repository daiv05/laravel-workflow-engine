# API Reference

## Overview

The package exposes a workflow engine with these public operations.

Core runtime operations:

- start
- can
- execute
- visibleFields

Runtime inspection operations:

- availableActions
- history

Subject-scoped query operations:

- getLatestInstanceForSubject
- getInstancesForSubject

Definition and extensibility operations:

- activateDefinition
- registerFunction

Execution-scoped composition operations:

- execution
- resolveMappedData

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
- subject: optional subject reference with `subject_type` and `subject_id`.

Runtime tenant scope is forced by `workflow.default_tenant_id`.
Incoming `tenant_id` values are ignored.

`workflow.default_tenant_id` is mandatory package configuration.

### Behavior

- Resolves active definition for workflow + tenant.
- Creates instance with UUID instance_id.
- Stores immutable workflow_definition_id on the instance.
- Initializes state with definition initial_state.
- Normalizes and validates `options.subject` when provided.
- When `workflow.enforce_one_active_per_subject` is enabled and `options.subject` is provided, rejects creating a second active instance for the same workflow + tenant + subject scope.
- Active status for this guard is evaluated as `state NOT IN definition.final_states`.
- Emits `workflow.event.instance_started` after persistence succeeds.

### start Exceptions

- `ActiveSubjectInstanceExistsException`: thrown when `workflow.enforce_one_active_per_subject` is enabled and an active instance already exists for the same scope.
- Exception message: `An active instance of {workflow} already exists for this subject`.
- Exception context includes `workflow_name`, `subject_type`, `subject_id`, `existing_instance_id`, and `tenant_id`.
- `WorkflowException`: thrown when `options.subject` is malformed.

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
- Automatically injects persisted instance subject into rule context as `subject.subject_type` and `subject.subject_id` when available.
- Returns false when the current state is in `final_states`, even if DSL defines outgoing transitions.
- Returns false on invalid transition, unauthorized transition, or non-evaluable context.

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
- Runs execution-scoped inline listeners before global Laravel listeners.
- Flushes queued event dispatch after successful commit.
- Clears queued success events on failure.
- Emits `workflow.event.transition_failed` when execution fails.
- Rejects transitions from final states with `InvalidTransitionException`.

### execute Exceptions

- `InvalidTransitionException`: no valid transition for current state/action.
- `UnauthorizedTransitionException`: `allowed_if` rule denied execution.
- `ContextValidationException`: missing/invalid context keys (for example `roles` or `data`).
- `OptimisticLockException`: concurrent write conflict on instance update.
- `MappingException`: mapping infrastructure/configuration error.
- `WorkflowException`: general domain failure (including mapping handler errors).

## resolveMappedData

Resolves read models for mapped fields (including related records via binding query handlers) for a transition from the current instance state.

### Signature

```php
resolveMappedData(string $instanceId, string $action, array $context = [], array $options = []): array
```

### Behavior

- Reads instance snapshot from storage.
- Resolves transition for read using this priority:
	- direct match by `(current_state, action)`
	- latest history entry for `action` with matching `transition_id`
	- unique transition by `action` across definition
- Returns `attribute` fields from instance data.
- Resolves `attach` and `relation` through configured binding `query_handler` when available.
- Resolves `custom` via mapping handler/query handler when it implements read contract.
- Returns an empty array when no resolvable mapped transition exists.

### resolveMappedData Exceptions

- `MappingException`: thrown when `DataMapper` is not configured.

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
- Workflow start emits `workflow.event.instance_started` with payload keys:
	- `instance_id`
	- `workflow_name`
	- `state`
	- `subject` (optional)
	- `tenant_id` (optional)
- Transition effect event payload includes transition metadata:
	- `instance_id`
	- `from_state`
	- `to_state`
	- `action`
	- `transition_id`
	- `context` (execution context passed to `execute`)
	- `meta` (optional, from transition effect definition)
	- `subject` (optional)
	- `tenant_id` (optional)

### execution Exceptions

- `WorkflowException` code `7002` when no instance ID was provided (`execution()` without `forInstance()` and without constructor instance).

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
- Automatically injects persisted instance subject into rule context as `subject.subject_type` and `subject.subject_id` when available.
- Returns per-action visibility/editability projection for transitions originating at current state.
- Does not enforce transition authorization (`allowed_if`) filtering.
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
- Automatically injects persisted instance subject into rule context as `subject.subject_type` and `subject.subject_id` when available.
- Returns only actions executable now for the provided context.
- Returns empty when instance is in a final state.
- De-duplicates action names before returning.

## history

Returns transition history entries for a workflow instance.

### Signature

```php
history(string $instanceId): array
```

### Behavior

- Returns records ordered by creation sequence.
- Includes action, transition id, from/to states, actor, and payload snapshot.
- Payload context stores a safe summary (`has_data`, `data_keys`, optional `roles`, optional `actor`, optional `meta_keys`) instead of full arbitrary context.

## activateDefinition

Compiles and activates a workflow definition version.

### Signature

```php
activateDefinition(string $workflowName, array|string $definition, ?string $tenantId = null): int
```

### Behavior

- Parses, validates, and compiles DSL before persistence.
- Persists a new active definition version and returns definition ID.
- Invalidates in-memory/distributed cache pointer for previous active version.
- Updates active definition cache and definition-by-id cache.
- Runtime tenant scope is currently forced by `workflow.default_tenant_id`; incoming `tenantId` is ignored.

### activateDefinition Exceptions

- `DSLValidationException` for malformed/invalid DSL.

## registerFunction

Registers a custom function in the function registry for use in DSL rules (`fn`).

### Signature

```php
registerFunction(string $name, callable $function): void
```

### Behavior

- Makes the function available to rule evaluation and DSL validation paths.
- Allows extending `allowed_if`, field predicates, and other rule-based expressions.

## getLatestInstanceForSubject

Returns the most recent workflow instance for a workflow name and subject reference.

### Signature

```php
getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array
```

### Parameters

- workflowName: workflow definition name.
- subjectRef: associative array with `subject_type` and `subject_id`.
- tenantId: optional tenant override; when null, the engine default tenant is used.

### Behavior

- Normalizes and validates `subjectRef` through `SubjectNormalizer`.
- Delegates query execution to storage repository implementation.
- Returns `null` when no matching instance exists.

### getLatestInstanceForSubject Exceptions

- `WorkflowException` when `subjectRef` is malformed.

### Example

```php
$latest = Workflow::getLatestInstanceForSubject('termination_request', [
	'subject_type' => App\Models\Solicitud::class,
	'subject_id' => 123, // normalized to string internally
]);
```

## getInstancesForSubject

Returns workflow instances for a subject reference, optionally filtered by workflow name.

### Signature

```php
getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array
```

### Parameters

- subjectRef: associative array with `subject_type` and `subject_id`.
- tenantId: optional tenant override; when null, the engine default tenant is used.
- workflowName: optional workflow filter.

### Behavior

- Normalizes and validates `subjectRef` through `SubjectNormalizer`.
- Delegates query execution to storage repository implementation.
- Returns instances ordered by creation sequence.

### getInstancesForSubject Exceptions

- `WorkflowException` when `subjectRef` is malformed.

### Example

```php
$instances = Workflow::getInstancesForSubject(
	['subject_type' => App\Models\Solicitud::class, 'subject_id' => '123'],
	workflowName: 'termination_request'
);
```

## Context Contract

For role-based rules, context must include:

- roles: array of role strings.

For transitions with mappings, context must include:

- data: array input payload for mapped fields.

If missing or invalid, evaluation throws context validation errors in strict evaluation paths.

In non-strict query paths:

- `can()` returns `false` on context evaluation errors.
- `availableActions()` skips transitions that fail context evaluation.

## Exceptions

Common domain exceptions:

- DSLValidationException
- InvalidTransitionException
- UnauthorizedTransitionException
- OptimisticLockException
- ContextValidationException
- MappingException
- ActiveSubjectInstanceExistsException
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

## Event Names

With default prefix `workflow.event.`:

- `workflow.event.instance_started`
- `workflow.event.{effect_event_name}` (for each transition effect)
- `workflow.event.transition_failed`
