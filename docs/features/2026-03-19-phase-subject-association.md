# Subject Association - Implementation Summary

## What Was Implemented

The workflow engine now supports binding workflow instances to domain entities via a stable subject reference (subject_type + subject_id). This enables:

- Discovering workflow instances from domain context  
- Querying instances by workflow name and subject
- Keeping the engine decoupled from Eloquent while remaining framework-native

## Key Changes

### 1. Storage Layer

**New Migration**  
File: [database/migrations/2026_03_19_000002_add_subject_association_to_workflow_instances.php](../database/migrations/2026_03_19_000002_add_subject_association_to_workflow_instances.php)

Adds two nullable columns to `workflow_instances`:
- `subject_type` (string): Class name or entity type identifier
- `subject_id` (string): Entity ID (supports int, UUID, ULID)

Includes indexes for efficient querying by subject and workflow/subject combination.

**Repository Updates**  
File: [src/Storage/DatabaseWorkflowRepository.php](../src/Storage/DatabaseWorkflowRepository.php)

- `createInstance()` now persists subject_type and subject_id when provided
- `hydrateInstance()` now includes subject fields in returned array
- **New:** `getLatestInstanceForSubject(workflowName, subjectRef, tenantId)` → latest instance for subject
- **New:** `getInstancesForSubject(subjectRef, tenantId, workflowName)` → all instances for subject (optionally filtered by workflow)

File: [src/Storage/InMemoryWorkflowRepository.php](../src/Storage/InMemoryWorkflowRepository.php)

In-memory implementations of the same subject-aware query methods for testing.

### 2. Contracts

**Updated Interface**  
File: [src/Contracts/StorageRepositoryInterface.php](../src/Contracts/StorageRepositoryInterface.php)

Two new methods defined:
```php
public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array;
public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array;
```

These methods keep the engine API unchanged while enabling subject-based discovery.

**Updated Engine Contract**
File: [src/Contracts/WorkflowEngineInterface.php](../src/Contracts/WorkflowEngineInterface.php)

The engine contract now also exposes subject query convenience methods:

```php
public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array;
public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array;
```

### 3. Engine Normalization

**New Normalizer Class**  
File: [src/Engine/SubjectNormalizer.php](../src/Engine/SubjectNormalizer.php)

Handles:
- Validation: ensures subject_type and subject_id are present
- Type conversion: converts int IDs to strings
- Error handling: throws `WorkflowException` with actionable messages

Usage:
```php
$normalized = SubjectNormalizer::normalize([
    'subject_type' => 'App\\Models\\Solicitud',
    'subject_id' => 123,
]);
// Result: ['subject_type' => '...', 'subject_id' => '123']
```

**Updated Engine**  
File: [src/Engine/WorkflowEngine.php](../src/Engine/WorkflowEngine.php)

The `start()` method now:
- Accepts optional `subject` key in options
- Normalizes and validates subject via `SubjectNormalizer`
- Persists subject to instance if provided
- Falls through silently if subject is not provided (backward compatible)

The engine also now exposes convenience query methods that normalize subject input and delegate to storage:
- `getLatestInstanceForSubject(workflowName, subjectRef, tenantId)`
- `getInstancesForSubject(subjectRef, tenantId, workflowName)`

The engine now also injects persisted subject into runtime rule context for:
- `can()`
- `availableActions()`
- `visibleFields()`

Injected shape:

```php
[
  'subject' => [
    'subject_type' => 'App\\Models\\Solicitud',
    'subject_id' => '456',
  ],
]
```

This keeps API signatures unchanged while enabling subject-aware authorization and field rules.

**Rule Helpers Strategy**

Subject-aware helpers can be implemented as custom registered functions and used in `allowed_if`, `fields.visible_if`, and `fields.editable_if`.

**Updated Facade**
File: [src/Facades/Workflow.php](../src/Facades/Workflow.php)

The `Workflow` facade now exposes the same convenience methods through static calls.

### 4. Testing

**Unit Tests**  
File: [tests/Unit/SubjectNormalizerTest.php](../tests/Unit/SubjectNormalizerTest.php)

Covers:
- Valid normalization (string ID, integer ID, UUID)
- Error cases (missing type, missing ID, non-scalar, non-array)

**Integration Tests**  
File: [tests/Integration/SubjectAssociationIntegrationTest.php](../tests/Integration/SubjectAssociationIntegrationTest.php)

Covers:
- Start workflow with subject persists correctly
- Latest instance lookup returns expected result
- Query all instances by subject
- Filter by workflow name across multiple workflows

### 5. Documentation

**Feature Specification**  
File: [docs/experimental/SUBJECT-ASSOCIATION.md](../docs/experimental/SUBJECT-ASSOCIATION.md)

High-level design document covering:
- Motivation and scope
- Data model and schema decisions
- API direction and architecture fit
- Why Eloquent morph relations are not in the core
- Viability and migration plan

**Integration Guide**  
File: [docs/SUBJECT-ASSOCIATION-GUIDE.md](../docs/SUBJECT-ASSOCIATION-GUIDE.md)

Practical patterns for applications:
- **Pattern A:** Application-owned Eloquent model (convenient for Laravel projects)
- **Pattern B:** Query service (decoupled, portable)
- **Pattern C:** Projection/denormalization (for heavy read workloads)
- Complete flow examples
- Security considerations
- Testing patterns

### 6. Active Instance Guard (Application-Level Only)

**What:** Optional enforcement to prevent creating a second active instance for the same `(tenant_id, workflow_name, subject_type, subject_id)` scope.

**Implemented behavior:**
- Added configuration option in `config/workflow.php`: `'enforce_one_active_per_subject' => true/false`
- Added application-level validation in `WorkflowEngine::start()` before create
- Added transaction-wrapped check+create path to reduce race windows
- Added clear domain exception message: "An active instance of {workflow} already exists for this subject"
- Added repository-level active lookup (`getLatestActiveInstanceForSubject`) in database and in-memory storage

**Decision:**
- Keep this feature enforced at application level only.
- Do not add database-specific unique constraints because support differs across engines.
- Keep current transaction-wrapped pre-check plus domain exception behavior as the package standard.

## API Changes (Non-Breaking)

### Backward Compatible

`Workflow::start()` signature unchanged:
```php
// New optional usage:
Workflow::start('workflow_name', [
    'subject' => ['subject_type' => '...', 'subject_id' => '...'],
    'data' => [...],
]);

// Still works (subject omitted):
Workflow::start('workflow_name', ['data' => [...]]);
```

All existing code continues to work. Subject association is entirely opt-in.

### New Query Methods

Available on both `StorageRepositoryInterface` implementations and the public engine/facade API:
```php
// Via storage directly
$storage->getLatestInstanceForSubject('workflow', $subjectRef);
$storage->getInstancesForSubject($subjectRef);

// Via facade convenience methods
Workflow::getLatestInstanceForSubject('workflow', $subjectRef);
Workflow::getInstancesForSubject($subjectRef, workflowName: 'workflow');
```

## Testing Summary

### Unit
- SubjectNormalizer: valid inputs, all error cases
- Backward compatibility (subject optional)
- Subject rule helpers: subject type and subject owner checks
- DSL validation for subject helper args

### Integration
- Persist subject on start
- Latest instance lookup by workflow and subject
- Query all instances for subject
- Filter by workflow name
- Engine subject query convenience methods
- Reject duplicate active instance for same subject when enforcement is enabled
- Allow new instance for same subject after previous reaches final state
- Invalid subject reference fails with clear error
- Subject-aware `can()` and `availableActions()`
- Subject-aware `visibleFields()` visibility/editability projection
- Subject included in workflow event payloads (`instance_started`, transition effects, and `transition_failed`) at implementation level
- Explicit integration assertions for `subject` inside event payloads are pending (event emission is covered; payload subject keys are not yet asserted in dedicated tests)

## Design Decisions Reflected

1. **Storage-first approach:** Subject is a query capability, not an ORM relation pattern.
2. **No Eloquent in core:** Application adapts to engine, not vice versa.
3. **Flexible subject ID:** String type supports integers, UUIDs, ULIDs, and custom formats.
4. **Tenant-aware queries:** All subject queries respect tenant isolation.
5. **Optional feature:** Existing code unaffected; use only when needed.

## Event Payload Subject Summary (Implemented)

Subject association is now propagated to workflow event payloads so observers do not need an additional instance lookup.

### Event Payload Behavior

- `workflow.event.instance_started` includes `subject` when the instance was started with subject data.
- Transition effect events (for example `workflow.event.request_approved`) include `subject` in their payload.
- `workflow.event.transition_failed` also includes `subject` when available.

Payload shape when subject exists:

```php
[
    'subject' => [
        'subject_type' => 'App\\Models\\Solicitud',
        'subject_id' => '123',
    ],
]
```

### Why It Matters

- Observers can update domain projections directly from event payloads.
- Prevents extra reads to `workflow_instances` just to recover subject context.
- Keeps event handling simpler and more deterministic.

## Update (2026-03-20)

Documentation alignment correction for event payload coverage:

- Subject propagation in event payloads is implemented in engine code.
- Existing integration tests validate event emission and names for these paths.
- Dedicated integration assertions that validate `payload.subject.subject_type` and `payload.subject.subject_id` for `instance_started`, transition effect events, and `transition_failed` remain pending.


## Migration Notes

### For Existing Databases

Run the new migration to add columns:
```bash
php artisan migrate
```

The schema is backward-compatible. No data loss.

### For New Projects

The new migration is automatically included in the package migrations.

## Quick Start

See [docs/SUBJECT-ASSOCIATION-GUIDE.md](../docs/SUBJECT-ASSOCIATION-GUIDE.md) for complete examples.

Basic usage:

```php
// 1. Start workflow with subject
$instance = Workflow::start('my_workflow', [
    'subject' => [
        'subject_type' => My\Model::class,
        'subject_id' => '123',
    ],
]);

// 2. Query by subject
$latest = $storage->getLatestInstanceForSubject(
    'my_workflow',
    ['subject_type' => My\Model::class, 'subject_id' => '123']
);

// 3. Execute by instance ID (unchanged)
Workflow::execute($instance['instance_id'], 'action', [
    'roles' => [...],
]);
```

That's it. No Eloquent coupling, no magic—just normalized persistence and queryable storage.
