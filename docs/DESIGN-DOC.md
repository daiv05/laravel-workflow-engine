# 📄 Design Document (Version 2.1)

## Laravel Workflow Engine Package

> Update (2026-03-20): This document was reconciled against the current implementation in `src/`, `config/workflow.php`, and package migrations. Notes tagged as **Update** describe implemented behavior, clarifications, and known roadmap gaps.

## 0. Decision Log (Locked for V2.1)

The following decisions are normative for implementation:

1. `instance_id` is a UUID.
2. `can()` evaluates both authorization and state/rule validity, answering: "Is this action executable right now by this actor?"
3. Domain events are dispatched **after commit**.
4. Only one active workflow definition is allowed per `(workflow_name, tenant_id)`.
5. Versioning is immutable: instances are never migrated across definitions, and each instance is permanently linked to a specific `workflow_definition_id`.
6. Final states are terminal at runtime: `can()` returns `false`, `visibleFields()` returns empty map, and `execute()` rejects transitions.
7. Definition activation rejects duplicate `(workflow_name, version, tenant_id)` to enforce immutable version semantics.
8. Inspection APIs `availableActions()` and `history()` are part of the supported developer surface.
9. Operational diagnostics and outbox retry processing are first-class runtime support features.

> Update (2026-03-20): Decisions 1 to 9 are implemented in the package runtime and persistence layer.

### 0.1 Implementation Alignment Snapshot

- **Implemented**: immutable definition versioning, single active definition per `(workflow_name, tenant_id)`, `dsl_version` validation, mandatory `initial_state` and `final_states`, stable `transition_id`, optimistic locking on instance updates, after-commit event dispatch strategy, outbox storage and retry processor, diagnostics emitter, execution-scoped listeners, inspection APIs (`availableActions`, `history`), subject association APIs.
- **Partially Implemented**: tenant override per call is available for subject query APIs, while core runtime calls (`start`, `execute`, `can`, `visibleFields`) currently use configured default tenant resolution.
- **Roadmap/Not Exposed Yet**: fluent runtime tenant switch helper (`forTenant`) is documented as target behavior but not exposed in the current public API.

---

## 1. Overview

**Name (working):** `laravel-workflow-engine`

This package provides a **developer-first, configurable workflow engine** for Laravel applications, enabling:

* Complex multi-actor workflows
* Conditional transitions and permissions
* Dynamic field visibility/editability
* Event-driven behavior
* Extensible logic via custom functions

It is designed to be:

* **Framework-native** (leveraging Laravel features)
* **Configurable via YAML/JSON**
* **Extensible via PHP closures and services**
* **Decoupled from business models**

---

## 2. Goals

### Primary Goals

* Provide a **generic workflow engine** embeddable in any Laravel app
* Support **complex enterprise workflows**
* Allow **fine-grained permissions and conditions**
* Enable **dynamic UI behavior (fields, actions)**
* Be **developer-first**, not UI-first

---

### Secondary Goals

* Multi-tenant support
* Workflow versioning
* Debugging and introspection tools
* Future portability to microservices

---

## 3. Non-Goals

* No UI builder (initially)
* No BPMN compliance
* No external service orchestration (yet)
* No heavy visual modeling tools

---

## 4. Architecture

### High-Level Architecture

```text
Laravel App
   |
   └── Workflow Engine Package
           ├─ Engine Core
           ├─ DSL Parser
           ├─ Rule Engine
           ├─ State Machine
           ├─ Policy Layer
           ├─ Field Engine
           ├─ Event Dispatcher
           ├─ Execution Builder
           ├─ Outbox Processor
           ├─ Diagnostics Emitter
           └─ Function Registry
```

> Update (2026-03-20): Runtime components above are implemented and wired in `WorkflowServiceProvider`, including `DataMapper` and diagnostics bindings.

---

## 5. Package Structure

```text
src/
 ├── Engine/
 │    ├── WorkflowEngine.php
 │    ├── StateMachine.php
 │    ├── TransitionExecutor.php
 │    ├── ExecutionBuilder.php
 │
 ├── DSL/
 │    ├── Parser.php
 │    ├── Validator.php
 │    ├── Compiler.php
 │
 ├── Rules/
 │    ├── RuleEngine.php
 │    ├── Evaluators/
 │
 ├── Policies/
 │    ├── PolicyEngine.php
 │
 ├── Fields/
 │    ├── FieldEngine.php
 │
 ├── Functions/
 │    ├── FunctionRegistry.php
 │
 ├── Events/
 │    ├── Dispatcher.php
 │
 ├── Diagnostics/
 │    ├── DiagnosticsEmitterInterface.php
 │    ├── LaravelDiagnosticsEmitter.php
 │    ├── NullDiagnosticsEmitter.php
 │
 ├── Outbox/
 │    ├── OutboxProcessor.php
 │
 ├── Storage/
 │    ├── DatabaseWorkflowRepository.php
 │    ├── InMemoryWorkflowRepository.php
 │    ├── DatabaseOutboxStore.php
 │    ├── NullOutboxStore.php
 │
 ├── Contracts/
 │    ├── WorkflowEngineInterface.php
 │    ├── StorageRepositoryInterface.php
 │    ├── RuleEngineInterface.php
 │    ├── EventDispatcherInterface.php
 │    ├── FunctionRegistryInterface.php
 │    ├── OutboxStoreInterface.php
 │
 ├── Facades/
 │    ├── Workflow.php
 │
 ├── Providers/
 │    ├── WorkflowServiceProvider.php
```

> Update (2026-03-20): Storage is currently provided by two repository implementations (`database` and `memory`) selected via `workflow.default_driver`.

---

## 6. Configuration

### Global Config File

```php
// config/workflow.php

return [

    'default_driver' => 'memory',

    'default_tenant_id' => 'tenant-default',

    'storage' => [
        'definitions_table' => 'workflow_definitions',
        'instances_table' => 'workflow_instances',
        'histories_table' => 'workflow_histories',
    ],

    'cache' => [
        'enabled' => true,
        'ttl' => 300,
    ],

    'events' => [
        'prefix' => 'workflow.event.',
        'fail_silently' => false,
    ],

    'outbox' => [
        'enabled' => true,
        'table' => 'workflow_outbox',
        'dispatch_batch' => 50,
        'max_attempts' => 5,
    ],

    'mappings' => [
        'fail_silently' => false,
    ],

    'bindings' => [
        // optional mapping handlers
    ],

    'diagnostics' => [
        'enabled' => true,
        'prefix' => 'workflow.diagnostic.',
    ],

    'enforce_one_active_per_subject' => false,

    // Global toggle reserved for the future multi-tenant feature rollout.
    'multi_tenant' => false,
];
```

  > Update (2026-03-20): Key names and defaults above were aligned to the current `config/workflow.php` shipped by the package.

---

## 7. DSL Design (YAML/JSON)

### Example Workflow

```yaml
dsl_version: 2
name: termination_request
version: 3
initial_state: draft
final_states:
  - approved
  - rejected

states:
  - draft
  - hr_review
  - changes_requested
  - approved
  - rejected

transitions:

  - from: draft
    to: hr_review
    action: submit
    transition_id: tr_submit_for_hr

    allowed_if:
      fn: isUnitManager

    fields:
      editable:
        - reason
        - employee_id

  - from: hr_review
    to: approved
    action: approve
    transition_id: tr_approve_request

    allowed_if:
      fn: isHR

    effects:
      - event: request_approved
```

> Update (2026-03-20): Current validator enforces `name`, `version`, `initial_state`, `states`, `transitions`, non-empty `final_states`, and mandatory `transition_id` for each transition.

---

## 8. Core Concepts

### 8.1 Workflow Instance

Represents a running workflow.

```php
[
  'instance_id' => '3c7c6e2b-8fe9-4d31-80d1-4d41b3204c7f',
  'workflow_definition_id' => 42,
  'tenant_id' => 'tenant-001',
  'state' => 'hr_review',
  'data' => [...],
]
```

---

### 8.2 Transition

Defines:

* source state
* target state
* action
* conditions
* effects

---

### 8.3 Context

Passed to all evaluations:

```php
[
  'roles' => ['HR'],
  'user' => $user, // optional helper object for custom fn rules
  'model' => $model, // optional domain model for custom fn rules
  'data' => [],
]
```

Minimum context contract:

- `roles` must exist and be an array for `role`-based rules.
- Nested rules (`all`, `any`, `not`) must be recursively evaluable with provided context.
- Missing/invalid context shape must raise explicit context validation errors.

> Update (2026-03-20): Runtime context validation is operator-driven:
> - `roles` is required only when evaluating `role` rules.
> - `data` is required (and must be array) when transition mappings are executed.

---

## 9. Engine API

### Start Workflow

```php
Workflow::start('termination_request', [
    'data' => $data,
    'subject' => ['subject_type' => 'request', 'subject_id' => 'REQ-1001'],
]);
```

> Update (2026-03-20): `start()` currently resolves tenant from configured default tenant. Optional start options implemented today include `data` and optional `subject` (`subject_type`, `subject_id`).

---

### Execute Transition

```php
Workflow::execute('3c7c6e2b-8fe9-4d31-80d1-4d41b3204c7f', 'approve', [
    'user' => $user,
    'model' => $request,
    'data' => $payload,
]);
```

---

### Check Permission

```php
Workflow::can('3c7c6e2b-8fe9-4d31-80d1-4d41b3204c7f', 'approve', [
  'user' => $user,
  'model' => $request,
]);
```

`can()` MUST evaluate both actor authorization and transition/state rule validity.
It answers whether the action is executable now for that actor in the current instance state.
For final states, `can()` MUST return `false`.

> Update (2026-03-20): Implemented in `StateMachine::transitionFor()` + `WorkflowEngine::can()`.

---

### Get Visible Fields

```php
Workflow::visibleFields('3c7c6e2b-8fe9-4d31-80d1-4d41b3204c7f', [
  'user' => $user,
  'model' => $request,
]);
```

For final states, `visibleFields()` MUST return an empty action map.

> Update (2026-03-20): Implemented in `WorkflowEngine::visibleFields()`.

---

### Execution Builder

```php
$result = Workflow::execution('3c7c6e2b-8fe9-4d31-80d1-4d41b3204c7f')
  ->before(function (string $action, array $context, string $instanceId): void {
      // pre execution hook
  })
  ->on('request_approved', function (array $payload): void {
      // execution-scoped listener
  })
  ->onAny(function (string $event, array $payload): void {
      // catch-all listener
  })
  ->after(function (string $action, array $context, array $updated, string $instanceId): void {
      // post execution hook
  })
  ->execute('approve', ['roles' => ['HR']]);
```

`execution()` listeners are in-memory and execution-scoped only.

---

### Inspection APIs

```php
$actions = Workflow::availableActions($instanceId, ['roles' => ['HR']]);
$history = Workflow::history($instanceId);
```

`availableActions()` returns executable actions for current state/context and empty list for final states.
`history()` returns persisted transition history in chronological order.

> Update (2026-03-20): Both APIs are implemented in `WorkflowEngine` and backed by repository adapters.

---

## 10. Function Registry

### Register Functions

```php
Workflow::registerFunction('isHR', function ($context) {
  return in_array('HR', $context['user']->roles);
});
```

> Update (2026-03-20): Rule functions are resolved from `FunctionRegistry`; custom callables can be registered through `registerFunction()`.

---

### DSL Usage

```yaml
allowed_if:
  fn: isHR
```

Function references MUST be validated in:

- `allowed_if`
- `fields.visible_if`
- `fields.editable_if`

---

## 11. Rule Engine

### Supported Conditions

```yaml
allowed_if:
  role: HR
```

```yaml
allowed_if:
  fn: customFunction
```

```yaml
allowed_if:
  all:
    - role: HR
    - fn: isActive
```

---

### Operators

* `all`
* `any`
* `not`

---

## 12. Field Engine

Supports:

* visibility
* editability

---

### Example

```yaml
fields:

  amount:
    visible_if:
      role: FINANCE

  comment:
    editable_if:
      fn: canEditComment
```

---

## 13. Event System

Uses Laravel native events.
Events MUST be dispatched **after commit** to prevent side effects when transactions roll back.

> Update (2026-03-20): Transition and start events are queued and flushed after successful transaction completion. On failure, queued events are cleared and a `TransitionFailed` event is queued and flushed.

---

### DSL

```yaml
effects:
  - event: request_approved
```

Effects may include optional `meta`, propagated to event payload.

---

### Dispatch

```php
event('workflow.event.request_approved');
```

Recommended implementation pattern: transactional state changes + after-commit event dispatch (or outbox pattern).

Execution-scoped listener order per event:

1. Named listeners (`on(event)`).
2. Catch-all listeners (`onAny`).
3. Global Laravel dispatch.

Inline listener failure behavior is configurable via `events.fail_silently`.

> Update (2026-03-20): Event prefix and listener failure mode are read from `workflow.events` config and wired by `WorkflowServiceProvider`.

---

## 14. Storage

### Default: Database

#### Tables

```text
workflow_instances
workflow_histories
workflow_definitions
workflow_outbox
```

Minimum schema requirements:

- `workflow_instances.instance_id` UUID primary key.
- `workflow_instances.workflow_definition_id` foreign key to `workflow_definitions.id`.
- `workflow_instances.version` integer for optimistic locking.
- `workflow_instances.state`, `workflow_instances.data`, timestamps.
- `workflow_instances.subject_type`, `workflow_instances.subject_id` nullable for subject association.
- `workflow_definitions.workflow_name`, `workflow_definitions.version`, `workflow_definitions.tenant_id`, `workflow_definitions.is_active`.
- `workflow_definitions.active_scope` unique constraint to enforce active definition per `(workflow_name, tenant_id)`.
- Unique immutable definition version per `(workflow_name, version, tenant_id)`.
- `workflow_histories` linked to `instance_id` with action, transition_id, actor, timestamps.
- `workflow_outbox` stores queued post-commit events with `status`, `attempts`, `last_error`, and `dispatched_at`.

Outbox lifecycle states:

- `pending`
- `failed`
- `dispatched`

> Update (2026-03-20): Current migrations and repositories implement all the above, including `active_scope` uniqueness and subject lookup indexes.

---

### Alternative

* store state in Eloquent models

---

## 15. Multi-Tenancy

Optional:

```php
Workflow::forTenant($tenantId);
```

Activation rule: only one active definition per workflow and tenant.

> Update (2026-03-20): Activation scope rule is implemented. `forTenant()` remains a target API and is not currently exposed; runtime tenant resolution uses `workflow.default_tenant_id`.

---

## 16. Caching

Cache:

* compiled workflows
* rule evaluations (optional)
* definition-by-id lookup cache

Cache keys must include tenant and definition version.
Cache must be invalidated when a new definition version is activated.
Optional shared cache integration may be used to improve multi-node behavior.

> Update (2026-03-20): Implemented cache keys include scope/version pointer keys and definition-id keys. Activation invalidates previous active pointer and active definition cache entries for that scope.

---

## 17. Service Provider

### Responsibilities

* publish config
* bind services
* register facade

---

### Publish Config

```bash
php artisan vendor:publish --tag=workflow-config
```

---

## 18. Extensibility

### Extension Points

* custom functions
* custom storage drivers
* custom rule evaluators
* event listeners
* diagnostics emitter implementations

---

## 19. Testing Strategy

### Unit Tests

* rule evaluation
* transitions
* field logic
* parser/validator DSL constraints
* optimistic lock behavior in memory repository
* subject normalization and built-in subject rule functions
* data mapping behavior

---

### Integration Tests

* full workflow execution
* `can()` checks (authorization + state/rules)
* after-commit event behavior
* optimistic locking conflict behavior
* immutable versioning behavior (`workflow_definition_id` does not change)
* final state transition blocking behavior
* execution-scoped listener behavior and failure modes
* `availableActions()` and `history()` inspection behavior
* outbox retry lifecycle (`pending` -> `failed` -> `dispatched`)
* diagnostics emission on transition and outbox paths

> Update (2026-03-20): Current test suite already covers the categories above through dedicated integration and unit test files.

---

## 20. Error Handling

* invalid transitions
* missing functions
* malformed DSL
* missing/invalid context contract
* duplicate immutable definition version activation
* optimistic locking mismatches
* listener execution failures (configurable fail-silent mode)
* active subject uniqueness collisions when `enforce_one_active_per_subject` is enabled

---

## 21. Security Considerations

* no dynamic code execution (`eval`)
* strict DSL validation
* controlled function registry

---

## 22. Performance Considerations

* compile DSL to PHP structures
* avoid repeated parsing
* cache workflows
* minimize reflection
* bound outbox retry batches (`dispatch_batch`, `max_attempts`)

---

## 23. Versioning

Support:

```text
workflow_definitions
  - version
  - active flag
```

Versioning policy (V2):

- Definitions are immutable once activated.
- Instances are never migrated between definitions.
- Each instance is permanently bound to its `workflow_definition_id`.
- New versions apply only to newly started instances.

> Update (2026-03-20): Implemented with immutable `(workflow_name, version, tenant_id)` uniqueness and `workflow_definition_id` binding at instance creation.

---

## 24. Future Enhancements

* UI builder
* REST API exposure
* external engine extraction (Go/Rust)
* optional distributed lock for outbox workers
* audit logs UI

---

## 25. Risks

### Technical Risks

* DSL complexity explosion
* performance degradation with many rules
* tight coupling with app data

---

### Mitigations

* keep DSL minimal
* push complexity to functions
* enforce boundaries

---
