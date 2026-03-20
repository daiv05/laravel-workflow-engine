# Feature Design Document

## Subject Association (Workflow <-> Domain Binding)

## 1. Overview

This feature defines a generic mechanism to bind workflow instances to domain entities using a polymorphic reference:

- subject_type
- subject_id

The design is storage-first and keeps the workflow engine decoupled from Eloquent model relationships.

## 2. Why This Exists

Workflows often run over business entities (requests, orders, tickets). Without a stable subject reference:

- instances are hard to discover from domain context,
- cross-table queries become expensive or ad-hoc,
- integration logic is repeated in each application.

This feature introduces a normalized subject reference while preserving engine independence.

## 3. Design Principles

- Engine remains framework-native but domain-agnostic.
- No Eloquent relationship definitions in package core.
- Subject reference is persisted and queryable.
- Application adapters can expose rich model relationships.
- Source of truth for workflow state remains workflow storage.

## 4. Scope

### In Scope

- Persist subject reference in workflow instances.
- Normalize subject input at workflow start.
- Query instances by subject reference.
- Include subject metadata in events and history payload summary.
- Optional application-level projections for read performance.

### Out of Scope

- Managing subject model lifecycle.
- Enforcing business ownership/authorization rules in package core.
- Defining Eloquent traits as required package API.

## 5. Data Model

### 5.1 Current Base Tables (unchanged naming)

The package already uses:

- workflow_instances.instance_id (UUID primary key)
- workflow_instances.state
- workflow_instances.data
- workflow_histories.instance_id

This proposal extends those tables instead of replacing names.

### 5.2 Proposed Additions

Add to workflow_instances:

- subject_type string nullable
- subject_id string nullable

Why string for subject_id:

- supports integer IDs,
- supports UUID/ULID,
- avoids schema redesign later.

### 5.3 Recommended Indexes

- index (tenant_id, subject_type, subject_id)
- index (workflow_definition_id, subject_type, subject_id)
- index (tenant_id, state)

Optional uniqueness strategy (application-defined):

- unique (tenant_id, workflow_name, subject_type, subject_id, active_flag)

Note: active_flag can be implemented via projection table or business rule, depending on DB capabilities.

## 6. Canonical Subject Reference

Normalized value object shape:

```php
[
  'subject_type' => 'App\\Models\\Solicitud',
  'subject_id' => '123',
]
```

Accepted inputs:

- explicit array with subject_type and subject_id,
- object plus resolver/normalizer adapter in host application.

Rejected inputs:

- missing type,
- missing ID,
- non-scalar ID that cannot be normalized.

## 7. API Direction (Package Core)

### 7.1 Start Workflow

Start accepts optional subject in options:

```php
Workflow::start('termination_request', [
    'subject' => [
        'subject_type' => App\\Models\\Solicitud::class,
        'subject_id' => '123',
    ],
    'data' => [...],
]);
```

Engine responsibility:

- normalize subject,
- persist subject_type and subject_id,
- return created instance.

### 7.2 Execute Transition

Execution remains instance-driven:

```php
Workflow::execute($instanceId, 'approve', [
    'roles' => ['HR'],
    'model' => $solicitud,
]);
```

Reason:

- instance_id is the canonical identity of running workflow,
- avoids ambiguity when multiple instances exist for same subject.

### 7.3 Subject Lookup API

Add query-oriented operations (core or repository-level):

- latestInstanceForSubject(workflowName, subjectRef, tenantId)
- instancesForSubject(subjectRef, filters)

These APIs support application composition without forcing ORM coupling.

## 8. Architecture Fit

### 8.1 Engine Layer

- Performs normalization and orchestration.
- Does not call Eloquent relation methods.
- Passes subject metadata through runtime context when available.

### 8.2 Storage Layer

- Persists and queries subject reference.
- Keeps tenant scoping and optimistic locking behavior.

### 8.3 Events Layer

Event payload can include:

- instance_id,
- action,
- transition_id,
- subject reference (if available),
- context summary.

### 8.4 Data Mapping Layer

Mapping handlers can consume runtime_context.model or runtime_context.subject when host app provides them.

## 9. Eloquent Coupling Decision

## Decision

Do not bake morphTo/morphMany relationships into package core.

## Rationale

- preserves package portability,
- keeps clean layer boundaries,
- prevents accidental coupling to host model conventions,
- allows non-Eloquent consumers.

## 10. How Applications Can Build Relations

The best approach is adapter-first.

### Pattern A: Application-Owned Eloquent Models Over Workflow Tables

Host app defines its own model classes for workflow tables and optional relations.

Example:

```php
class WorkflowInstanceRecord extends Model
{
    protected $table = 'workflow_instances';

    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }
}
```

This keeps the package clean while giving full Laravel ergonomics to the app.

### Pattern B: Query Service (Recommended Default)

Create a dedicated read service:

- WorkflowSubjectQueryService
- accepts subject reference,
- returns latest instance plus selected history/actions.

Benefits:

- explicit query contracts,
- easy to optimize,
- easy to test,
- works with or without Eloquent.

### Pattern C: Projection Table for Complex Structures

For dashboards or heavy cross-joins, maintain a projection:

- subject_workflow_projection
- keyed by tenant_id + subject_type + subject_id + workflow_name

Update projection on transition events.

Benefits:

- fast reads,
- stable API for UI/reporting,
- avoids repeated expensive joins.

## 11. Recommended Strategy for "Relations in Flexible Structures"

When applications build custom structures, use this sequence:

1. Resolve subject reference in application boundary.
2. Query workflow instances by subject reference.
3. Compose response in a read model assembler.
4. Optionally hydrate domain models in app layer only.

Do not push model hydration into engine core.

## 12. Validation and Error Handling

Mandatory errors:

- Missing subject_type
- Missing subject_id
- Invalid subject_id format after normalization
- Subject lookup ambiguity when requesting a single latest instance

Errors must include actionable message and, when possible, node/path context.

## 13. Security Considerations

- Subject ownership and access checks are host-application responsibilities.
- Engine should never expose arbitrary model hydration by class name alone.
- If class-based subject_type is used, apply application allowlist checks.

## 14. Migration Plan

1. Add nullable subject_type and subject_id columns.
2. Add indexes for subject queries.
3. Update repository hydration/create logic.
4. Add optional subject normalization in engine start.
5. Add subject-based query methods.
6. Add tests and docs.

## 15. Testing Plan

### Unit

- subject normalization success/failure
- invalid subject payload validation
- repository hydration with nullable and non-null subject

### Integration

- start with subject persists subject columns
- query by subject returns expected instance set
- execute keeps subject unchanged and appends history
- events include subject metadata when present

### Error Paths

- malformed subject input
- missing subject in strict mode (if enabled)
- ambiguous latest instance lookup

## 16. Viability Assessment

This feature is high viability because:

- it extends existing storage patterns,
- it aligns with current engine orchestration,
- it does not require runtime dynamic code,
- it keeps package architecture boundaries intact.

Main cost areas:

- storage/repository updates,
- additional query APIs,
- test coverage expansion.

## 17. Final Recommendation

Implement Subject Association as a core storage/query capability, not as a built-in ORM relation system.

Package provides:

- normalized subject persistence,
- subject-aware querying,
- subject metadata propagation.

Host application provides:

- Eloquent relationships (optional),
- model hydration rules,
- authorization and ownership constraints,
- UI/read projections when needed.
