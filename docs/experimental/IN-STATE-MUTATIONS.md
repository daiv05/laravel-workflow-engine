Aquí tienes un **Feature Design Document completo** para la funcionalidad de **State Updates (mutations sin transición)**, integrado con todo lo que ya definiste (mappings, subject, events, etc.).

---

# 📄 Feature Design Document

## State Updates (In-State Mutations)

---

## 1. Overview

This feature introduces the ability to **modify workflow-related data without triggering a state transition**, enabling users to:

* Update fields
* Upload attachments
* Save progress incrementally

while remaining in the same workflow state.

---

## 2. Motivation

### Problem

In real-world workflows, users often need to:

* Upload multiple documents over time
* Edit fields before submission
* Save partial progress

Modeling these as transitions leads to:

* polluted workflow history
* unnecessary complexity
* poor UX

---

### Goal

Separate:

```text
State transitions → change workflow state  
State updates → modify data within a state
```

---

## 3. Goals

### Primary Goals

* Allow data updates without state transitions
* Validate permissions per state
* Reuse DataMapper for persistence
* Maintain optional audit trail

---

### Secondary Goals

* Enable field-level control
* Support partial updates
* Integrate with events and inline listeners

---

## 4. Non-Goals

* Replacing transitions
* Managing full CRUD of domain models
* Acting as a form builder

---

## 5. High-Level Architecture

```text
WorkflowEngine
   ├── execute()   → transitions
   └── update()    → in-state mutations

update()
   ↓
Permission check
   ↓
Field validation
   ↓
DataMapper
   ↓
Optional history
   ↓
Events
```

---

## 6. DSL Design

---

### 6.1 State-Level Configuration

```yaml
states:

  - name: borrador

    permissions:
      update:
        allowed_if:
          fn: isOwner

    fields:

      documentos:
        editable: true
        multiple: true

      comentario:
        editable: true
```

---

### 6.2 Field-Level Rules

```yaml
fields:

  comentario:
    editable_if:
      fn: canEditComment

  documentos:
    editable_if:
      role: USER
```

---

### 6.3 Validation on Transition

```yaml
transitions:

  - from: borrador
    to: en_revision
    action: enviar

    validation:
      required:
        - documentos
        - comentario
```

---

## 7. Public API

---

### 7.1 Update

```php
Workflow::update($subject, [
    'user' => $user,
    'data' => [
        'documentos' => [...],
        'comentario' => '...'
    ]
]);
```

---

### 7.2 Check Permission

```php
Workflow::canUpdate($user, $subject);
```

---

### 7.3 Execution with Inline Listeners

```php
Workflow::execution()
    ->on('updated', fn ($ctx) => ...)
    ->update($subject, [...]);
```

---

## 8. Internal Flow

```text
1. resolve workflow instance
2. get current state
3. validate update permission
4. validate editable fields
5. filter allowed fields
6. DataMapper.map()
7. persist (optional)
8. dispatch events
```

---

## 9. Field Engine Integration

### Responsibilities

* Determine editable fields
* Enforce field-level permissions
* Filter incoming data

---

### Example

```php
$allowedFields = $fieldEngine->getEditableFields($state, $context);

$data = array_intersect_key($input, array_flip($allowedFields));
```

---

## 10. DataMapper Integration

Same behavior as transitions:

```text
update() → DataMapper → handlers
```

---

## 11. History Handling

---

### Option A — No History

* simpler
* no audit trail

---

### Option B — Record Updates (Recommended)

```text
workflow_histories:

action: update
from_state: borrador
to_state: borrador
context: {...}
```

---

## 12. Event System

---

### Default Event

```php
event('workflow.event.updated', $context);
```

---

### Inline Listeners

```php
->on('updated', fn ($ctx) => ...)
```

---

## 13. Permissions Model

---

### Distinction

```text
canUpdate ≠ canExecute
```

---

### Evaluation

```php
ruleEngine->evaluate($state.permissions.update.allowed_if)
```

---

## 14. Validation Strategy

---

### Update Validation

* partial validation
* only editable fields

---

### Transition Validation

* full validation
* required fields

---

## 15. Concurrency Considerations

---

### Problem

Multiple updates at the same time.

---

### Solutions

* optimistic locking (`version` column)
* last-write-wins (simple)
* conflict detection (advanced)

---

## 16. Performance Considerations

* updates are frequent → keep lightweight
* avoid heavy processing in handlers
* queue heavy tasks

---

## 17. Security Considerations

* validate user permissions per update
* filter unauthorized fields
* never trust input blindly

---

## 18. Error Handling

| Case                | Behavior             |
| ------------------- | -------------------- |
| Unauthorized update | exception            |
| Invalid field       | ignored or error     |
| Invalid data        | validation exception |

---

## 19. Testing Strategy

---

### Unit

* field filtering
* permission checks
* partial updates

---

### Integration

```php
Workflow::update(...)
```

Assert:

* correct persistence
* correct history
* correct events

---

## 20. UX Implications

Enables:

```text
[ Upload files ]
[ Edit fields ]
[ Save progress ]

→ later →

[ Submit ]
```

---

## 21. Conclusion

This feature introduces a critical capability:

> **decoupling data mutation from state transitions**

It transforms the engine from a simple state machine into:

```text
Workflow Engine + Editable State Layer
```

---
