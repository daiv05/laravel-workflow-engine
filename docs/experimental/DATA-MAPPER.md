# 📄 Feature Design Document

## Data Mapping Layer & External Persistence

---

## 1. Overview

This feature introduces a **Data Mapping Layer** that allows workflow input data to be:

* Stored in workflow instance data when needed
* Persisted into external models/tables
* Transformed via custom handlers
* Extended with hooks and events

It enables the workflow engine to remain **agnostic of database structure**, while allowing deep integration with Laravel applications.

---

## 2. Motivation

### Problem

Workflow executions often include:

* Documents
* Comments
* Financial data
* External references

Storing everything in JSON leads to:

* performance issues
* poor queryability
* large payloads

---

### Goal

Provide a flexible system where:

* The DSL declares **what should happen**
* Laravel code defines **how it happens**

---

## 3. Goals

### Primary Goals

* Introduce a **mapping system** for workflow data
* Support persistence outside the workflow engine
* Keep the engine DB-agnostic
* Allow extensibility via handlers

---

### Secondary Goals

* Support attachments pattern
* Enable per-field processing
* Integrate with events and inline listeners

---

## 4. Non-Goals

* Direct database access from DSL
* ORM abstraction inside the engine
* Schema definition in workflows

---

## 5. High-Level Architecture

```text
WorkflowEngine
   ↓
Transition Execution
   ↓
DataMapper
   ↓
Mapping Handlers (Laravel side)
   ↓
Eloquent / Services / Storage
   ↓
Events (Global + Inline)
```

---

## 6. DSL Design

### 6.1 Mapping Definition

```yaml
transitions:

  - from: en_revision
    to: aprobado
    action: aprobar

    mappings:

      comentario:
        type: attribute

      documentos:
        type: relation
        target: documents
        mode: create_many
```

---

## 7. Mapping Types

### 7.1 Attribute

Stores data in workflow context.

```yaml
comentario:
  type: attribute
```

---

### 7.2 Relation

Creates or persists related records.

```yaml
documentos:
  type: relation
  target: documents
  mode: create_many
```

---

### 7.3 Attach

Stores references only (IDs).

```yaml
documentos:
  type: attach
  target: documents
```

---

### 7.4 Custom

Delegates to a custom handler.

```yaml
monto:
  type: custom
  handler: processMonto
```

---

## 8. Application Configuration

### 8.1 Bindings

```php
// config/workflow.php

'bindings' => [

    'documents' => [
        'model' => App\Models\Document::class,
        'handler' => App\Mappers\DocumentMapper::class,
    ],

],
```

---

## 9. DataMapper Component

### Responsibilities

* Interpret mappings
* Route data to correct handlers
* Keep engine decoupled

---

### Interface

```php
interface DataMapperInterface
{
  public function map(array $mappings, array $instanceData, array $inputData, array $context = []): array;
  public function resolve(array $mappings, array $instanceData, array $context = [], array $options = []): array;
}
```

---

### Core Implementation

```php
class DataMapper implements DataMapperInterface
{
    public function map(array $mappings, array $data, array $context): void
    {
        foreach ($mappings as $field => $config) {

            $value = $data[$field] ?? null;

            if ($value === null) {
                continue;
            }

            $this->handleField($field, $value, $config, $context);
        }
    }
}
```

---

## 10. Handler System

### Contract

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

---

### Example Handler

```php
class DocumentMapper implements MappingHandlerInterface
{
    public function handle($value, array $context): void
    {
        foreach ($value as $doc) {

            $record = Document::create([
                'path' => $doc['path'],
                'solicitud_id' => $context['model']->id,
            ]);

        }
    }
}
```

---

## 11. Context Handling

### Workflow Context

```php
[
  'roles' => [...],
  'actor' => 'user-123',
  'user' => $user,
  'model' => $model,
  'data' => [...],
  'meta' => [...],
]
```

---

### Context Contract (Agreed)

The runtime context is split by responsibility:

* `context.data` is the **mapping input payload**.
* `context.roles` is used only for role-based rule evaluation.
* `context.actor` is used for transition audit (`workflow_histories.actor`).
* `context.meta` is optional tracing/technical metadata.
* `context.user` and `context.model` are runtime helpers and must not be blindly serialized.

When a transition defines `mappings`, `context.data` must be an array.

---

### JSON Storage

```text
workflow_instances.data
workflow_histories.payload
```

Stores:

* `workflow_instances.data`: attribute values and reference IDs (current snapshot)
* `workflow_histories.payload`: transition snapshot + mapping summary (audit)

Important alignment with current package behavior:

* There is no `workflow_histories.context` column.
* `context.data` is not equivalent to `workflow_histories.payload`.
* History payload must store a safe summary, not the full raw context.

---

## 12. Attachments Pattern

### Concept

* Files are stored externally (disk/S3)
* DB stores metadata
* workflow stores only references

---

### Example

```json
{
  "document_ids": [1, 2]
}
```

---

## 13. Event Integration

### After Mapping

Events are dispatched:

```php
event('workflow.event.aprobado', $context);
```

---

### Inline Listeners

```php
Workflow::execution()
    ->on('aprobado', fn ($ctx) => ...)
    ->execute(...);
```

---

## 14. Execution Flow

```text
1. execute()
2. resolve transition
3. validate rules
4. validate mapping input contract (context.data)
5. DataMapper.map()
6. persist instance state/data + history in one transaction (history stores safe context + mapping summary)
7. dispatch events after commit
   - inline listeners
   - Laravel events
```

---

## 15. Error Handling

| Case                 | Behavior     |
| -------------------- | ------------ |
| Missing binding      | exception    |
| Invalid handler      | exception    |
| Invalid mapping type | exception    |
| Invalid context.data | exception    |

---

### Config

```php
'mappings' => [
    'fail_silently' => false,
],
```

---

## 16. Data Retrieval Strategy (Bindings + Mappings)

This section defines how application code retrieves related data after mapping is executed.

### 16.1 Retrieval by Mapping Type

* `attribute`:
  * Read from `workflow_instances.data`.
  * Use for lightweight fields that belong to workflow state.

* `attach`:
  * Store only references (for example, `document_ids`) in `workflow_instances.data`.
  * Resolve full records via configured binding target repository/model.

* `relation`:
  * Persist related records externally through binding handler.
  * Keep external IDs or keys in workflow data only when needed for traceability.

* `custom`:
  * Retrieval is delegated to the custom handler contract.
  * Handler is responsible for providing deterministic read behavior.

### 16.2 Binding-Level Query Contract

To keep retrieval explicit and decoupled, each binding should expose a read method in addition to write handling.

Suggested contract:

```php
interface MappingQueryHandlerInterface
{
    public function fetch(array $context, array $options = []): mixed;
}
```

If a handler supports both write and read paths, it can implement both contracts:

```php
interface MappingHandlerInterface
{
    public function handle(mixed $value, array $context): array;
}

interface MappingQueryHandlerInterface
{
    public function fetch(array $context, array $options = []): mixed;
}
```

### 16.3 Recommended Read Resolution Order

1. Read workflow instance (`state`, `data`) as source of workflow snapshot.
2. For each mapped field:
   * return `attribute` values directly,
   * resolve `attach`/`relation` through binding query handler.
3. Return a composed read model to callers (API/resource layer).

### 16.4 Memory Driver Compatibility

The memory driver remains supported.

* Do not remove memory mode.
* Mapping handlers must be storage-agnostic.
* Provide in-memory/fake query handlers for tests.

---

## 17. Performance Considerations

* Handlers execute synchronously
* Heavy logic should be queued
* Avoid large payloads in context

---

## 18. Security Considerations

* No direct DB access from DSL
* No dynamic code execution
* Handlers are trusted PHP code

---

## 19. Testing Strategy

### Unit Tests

* mapping resolution (`attribute`, `attach`, `relation`, `custom`)
* handler invocation (write + read contracts)

---

### Integration Tests

```php
Workflow::execute('aprobar', [...]);
```

Assert:

* DB records created
* workflow instance data updated correctly
* history payload contains mapping summary (not full raw context)
* rollback leaves no partial side effects when mapping fails
* related data can be reconstructed through `resolveMappedData()` + binding query handlers

---

## 20. Extensibility

### Supported Extensions

* custom mapping types
* custom handlers
* event listeners

---

## 21. Constraints

* DSL must remain declarative
* engine must remain agnostic
* mappings must use bindings
* memory and database drivers must both be supported

---

## 22. Risks

---

### Hidden Logic

Business logic spread across handlers.

**Mitigation:**

* clear naming conventions
* documentation

---

## 23. Best Practices

* Keep mappings simple
* Use handlers for complex logic
* Store only references in context
* Use events for side effects

---

## 24. Future Enhancements

* async mapping (queue)
* batch operations
* mapping validation schemas
* visual mapping tools

---

## 25. Conclusion

This feature enables the workflow engine to act as a:

> **data orchestrator rather than a data owner**

It provides:

* flexibility
* scalability
* clean separation of concerns

while maintaining:

* simplicity
* developer control
* Laravel-native integration
