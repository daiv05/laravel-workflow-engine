---
name: workflow-engine-implementation
description: "Use when implementing or refactoring workflow engine modules in this repo: DSL parser/validator/compiler, state machine, transitions, policy, fields, function registry, events, and storage integration."
---

# Workflow Engine Implementation

Use this skill when the task requires building or refactoring core workflow engine behavior.

## Minimum Inputs

- Functional requirement (expected action or behavior)
- Current state of impacted code
- DSL or public API constraints

## Recommended Flow

1. Confirmar contrato publico afectado (`start`, `execute`, `can`, `visibleFields`).
2. Ubicar la capa correcta (`DSL`, `Rules`, `Policies`, `Fields`, `Engine`, `Events`, `Storage`).
3. Design low-coupling changes across layers.
4. Implement validations and domain errors first.
5. Implement core logic, then secondary effects (events/persistence).
6. Add unit and integration tests for the updated path.

## Guardrails

- Do not use `eval` or unsafe dynamic execution.
- Do not hardcode business rules from a specific application.
- Do not mix DSL parsing with persistence concerns.
- Do not emit events before persisted state consistency is guaranteed.

## Quality Checklist

- Invalid DSL fails early with a clear error message.
- Invalid transitions do not mutate state.
- `allowed_if` is always evaluable, or fails with an explicit error.
- Functions (`fn`) must exist in `FunctionRegistry`.
- Changes preserve future composition (multi-tenant and versioning).
