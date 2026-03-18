# Phase 16 - Inspection APIs: History and Available Actions

## Summary

This phase adds runtime inspection capabilities to improve debugging, supportability, and host application UX.

New engine APIs:

- `availableActions(instanceId, context)`
- `history(instanceId)`

## API Details

### availableActions

- Evaluates transitions from current state.
- Applies policy checks (`allowed_if`) for the provided context.
- Returns only executable actions.
- Returns an empty list for final states.

### history

- Returns transition history records for an instance.
- Includes action metadata and payload snapshot from persistence.
- Preserves chronological order.

## Implementation

### Contracts

- `WorkflowEngineInterface` extended with `availableActions` and `history`.
- `StorageRepositoryInterface` extended with `getHistory`.

### Engine

- `WorkflowEngine::availableActions` implemented using definition transitions + `PolicyEngine` evaluation.
- `WorkflowEngine::history` implemented as direct storage read-through.

### Storage

- `InMemoryWorkflowRepository::getHistory` filters in-memory history by `instance_id`.
- `DatabaseWorkflowRepository::getHistory` queries `workflow_histories` ordered by `created_at`, `id`.

## Test Coverage

### In-memory integration

- `WorkflowEngineTest::test_it_exposes_available_actions_and_history_for_instance`

### Database integration

- `DatabaseWorkflowEngineTest::test_database_engine_exposes_available_actions_and_history`

## Design Alignment

This phase is aligned with DesignDoc goals for developer-first ergonomics and workflow introspection support, while preserving existing public APIs (`start`, `execute`, `can`, `visibleFields`) without breaking changes.
