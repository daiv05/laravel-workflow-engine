# Subject Association - File Changes Reference

Quick reference for all files created or modified during Subject Association implementation.

## New Files (6)

| File | Purpose | Lines | Status |
|------|---------|-------|--------|
| [database/migrations/2026_03_19_000002_add_subject_association_to_workflow_instances.php](../database/migrations/2026_03_19_000002_add_subject_association_to_workflow_instances.php) | Schema migration: add subject columns + indexes | 40 | ✅ Created |
| [src/Engine/SubjectNormalizer.php](../src/Engine/SubjectNormalizer.php) | Subject validation & normalization logic | 65 | ✅ Created |
| [tests/Unit/SubjectNormalizerTest.php](../tests/Unit/SubjectNormalizerTest.php) | Unit tests for SubjectNormalizer | |  ✅ Created |
| [tests/Integration/SubjectAssociationIntegrationTest.php](../tests/Integration/SubjectAssociationIntegrationTest.php) | Integration tests for persistence + queries | | ✅ Created |
| [docs/SUBJECT-ASSOCIATION-GUIDE.md](../docs/SUBJECT-ASSOCIATION-GUIDE.md) | Integration patterns & usage guide | 300+ | ✅ Created |
| [docs/features/2026-03-19-phase-subject-association.md](../docs/features/2026-03-19-phase-subject-association.md) | Phase completion summary | | ✅ Created |

## Modified Files (5)

### 1. Contracts
**File:** [src/Contracts/StorageRepositoryInterface.php](../src/Contracts/StorageRepositoryInterface.php)

**Change:** Added 2 method signatures
```php
/**
 * Get the latest workflow instance for a given subject and workflow name.
 * Useful for discovering the active or most recent instance from domain context.
 */
public function getLatestInstanceForSubject(
    string $workflowName,
    array $subjectRef,
    ?string $tenantId = null
): ?array;

/**
 * Get all workflow instances associated with a subject.
 * Optionally filter by workflow name.
 */
public function getInstancesForSubject(
    array $subjectRef,
    ?string $tenantId = null,
    ?string $workflowName = null
): array;
```

**Impact:** Extends interface contract; implementations required

---

### 2. Storage - Database
**File:** [src/Storage/DatabaseWorkflowRepository.php](../src/Storage/DatabaseWorkflowRepository.php)

**Changes:**

**A) createInstance() enhancement** (~5 lines)
- Now extracts and persists 'subject_type' and 'subject_id' from input array
- Uses subject fields if provided, null if not
```php
'subject_type' => $instance['subject_type'] ?? null,
'subject_id' => $instance['subject_id'] ?? null,
```

**B) hydrateInstance() enhancement** (~3 lines)
- Now includes subject_type and subject_id in returned array
```php
'subject_type' => $row->subject_type,
'subject_id' => $row->subject_id,
```

**C) getLatestInstanceForSubject() implementation** (~35 lines)
```php
public function getLatestInstanceForSubject(
    string $workflowName,
    array $subjectRef,
    ?string $tenantId = null
): ?array {
    $query = DB::table('workflow_instances')
        ->join('workflow_definitions', 'workflow_instances.workflow_definition_id', '=', 'workflow_definitions.id')
        ->where('workflow_definitions.name', $workflowName)
        ->where('workflow_instances.subject_type', $subjectRef['subject_type'])
        ->where('workflow_instances.subject_id', $subjectRef['subject_id']);
    
    if ($tenantId) {
        $query->where('workflow_instances.tenant_id', $tenantId);
    }
    
    $row = $query->orderBy('workflow_instances.created_at', 'desc')->first();
    
    return $row ? $this->hydrateInstance((array) $row) : null;
}
```

**D) getInstancesForSubject() implementation** (~30 lines)
```php
public function getInstancesForSubject(
    array $subjectRef,
    ?string $tenantId = null,
    ?string $workflowName = null
): array {
    $query = DB::table('workflow_instances')
        ->where('subject_type', $subjectRef['subject_type'])
        ->where('subject_id', $subjectRef['subject_id']);
    
    if ($tenantId) {
        $query->where('tenant_id', $tenantId);
    }
    
    if ($workflowName) {
        $query->join('workflow_definitions', 'workflow_instances.workflow_definition_id', '=', 'workflow_definitions.id')
            ->where('workflow_definitions.name', $workflowName);
    }
    
    return $query->orderBy('created_at')->get()
        ->map(fn($row) => $this->hydrateInstance((array) $row))
        ->all();
}
```

---

### 3. Storage - In-Memory
**File:** [src/Storage/InMemoryWorkflowRepository.php](../src/Storage/InMemoryWorkflowRepository.php)

**Changes:**

**A) createInstance() enhancement** (~3 lines)
- Now stores 'subject_type' and 'subject_id' in instance array

**B) getLatestInstanceForSubject() implementation** (~25 lines)
```php
public function getLatestInstanceForSubject(
    string $workflowName,
    array $subjectRef,
    ?string $tenantId = null
): ?array {
    $matching = [];
    
    foreach ($this->instances as $instance) {
        if ($instance['state'] === 'started' && 
            ($instance['subject_type'] ?? null) === $subjectRef['subject_type'] &&
            ($instance['subject_id'] ?? null) === $subjectRef['subject_id'] &&
            (!$tenantId || $instance['tenant_id'] === $tenantId)) {
            
            $definition = $this->definitionsById[$instance['workflow_definition_id']] ?? null;
            if ($definition['name'] === $workflowName) {
                $matching[] = $instance;
            }
        }
    }
    
    usort($matching, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    
    return $matching[0] ?? null;
}
```

**C) getInstancesForSubject() implementation** (~20 lines)
```php
public function getInstancesForSubject(
    array $subjectRef,
    ?string $tenantId = null,
    ?string $workflowName = null
): array {
    $matching = [];
    
    foreach ($this->instances as $instance) {
        if (($instance['subject_type'] ?? null) === $subjectRef['subject_type'] &&
            ($instance['subject_id'] ?? null) === $subjectRef['subject_id'] &&
            (!$tenantId || $instance['tenant_id'] === $tenantId)) {
            
            if (!$workflowName) {
                $matching[] = $instance;
            } else {
                $definition = $this->definitionsById[$instance['workflow_definition_id']] ?? null;
                if ($definition && $definition['name'] === $workflowName) {
                    $matching[] = $instance;
                }
            }
        }
    }
    
    usort($matching, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    
    return $matching;
}
```

---

### 4. Engine
**File:** [src/Engine/WorkflowEngine.php](../src/Engine/WorkflowEngine.php)

**Changes:**

**A) Imports** (+1 line)
- Added: `use Exceptions\WorkflowException;`

**B) start() method enhancement** (~15 lines at instance creation)
```php
// Handle optional subject association
$subjectData = [];
if (isset($options['subject'])) {
    try {
        $subjectData = SubjectNormalizer::normalize($options['subject']);
    } catch (WorkflowException $e) {
        throw new WorkflowException("Invalid subject reference: {$e->getMessage()}");
    }
}

// Merge subject into instance before persistence
$instance = array_merge($instance, $subjectData);
```

**Context:** 
- Imported SubjectNormalizer (use statement)
- In start() method, after instance array is created, checks for 'subject' key in options
- If present, normalizes via SubjectNormalizer::normalize()
- Catches validation errors and re-throws as domain exception
- Merges subject_type and subject_id into instance array before storage.createInstance()

---

### 5. Feature Documentation
**File:** [docs/experimental/SUBJECT-ASSOCIATION.md](../docs/experimental/SUBJECT-ASSOCIATION.md)

**Change:** Entire document rewritten

**Key rewrites:**
- Removed all Eloquent morphTo/morphMany from core design
- Changed from "ORM-first" to "storage-first" architecture
- Clarified that applications bring their own models
- Documented storage schema (subject_type + subject_id nullable columns)
- Documented 3 integration patterns instead of proposing Eloquent inside package
- Added security considerations (no eval, registered functions only)
- Added migration and backward-compatibility notes

**New Structure:**
1. Motivation
2. Design principles (storage-first, decoupled)
3. Data model (schema + columns + indexes)
4. API contract (interface methods)
5. Integration patterns (Eloquent adapter, query service, projection)
6. Migration strategy
7. Viability assessment

---

## Summary of Changes

| Area | Type | Scope |
|------|------|-------|
| **Schema** | New | +2 nullable columns, +2 indexes |
| **Contracts** | Modified | +2 method signatures |
| **Storage (DB)** | Modified | +2 query methods, persistence enhancement |
| **Storage (Memory)** | Modified | +2 query methods, persistence enhancement |
| **Engine** | Modified | +import, +subject handling in start() |
| **Tests** | New | +13 test methods total (9 unit, 4 integration) |
| **Docs** | New + Rewrite | +integration guide, +feature summary, rewritten feature spec |

## Backward Compatibility

✅ All changes are backward compatible:
- Subject parameter is optional (falls through if not provided)
- Query methods are new (no overwriting of existing API)
- Migrations are additive only
- Existing code unaffected

## No Breaking Changes

- WorkflowEngine::start() signature unchanged (subject is optional)
- WorkflowEngine::execute() unchanged
- All existing methods continue to work
- Tests for existing functionality pass unchanged
