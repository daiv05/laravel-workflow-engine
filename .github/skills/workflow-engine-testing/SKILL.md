---
name: workflow-engine-testing
description: "Use when adding or updating tests for workflow engine behavior: transition execution, rule evaluation, policy checks, field visibility/editability, events, storage, and DSL validation errors."
---

# Workflow Engine Testing

Use this skill to define test coverage before closing workflow engine changes.

## Base Test Matrix

- Unit / DSL: parser, validator, compiler, errors including node path.
- Unit / Rules: `role`, `fn`, `all`, `any`, `not`.
- Unit / Fields: `visible_if`, `editable_if`.
- Integration / Engine: `start -> can -> execute -> events -> persistence`.
- Integration / Storage: instances, history, definitions, and active version.

## Mandatory Error Cases

- Invalid transition due to source state mismatch.
- Referenced function is not registered.
- Malformed or inconsistent DSL (`from`/`to` outside `states`).
- Non-evaluable condition due to incomplete context.

## Exit Rules

- Every relevant change adds or updates tests.
- At least one happy-path test and one error-path test per affected component.
- Ensure events are emitted with the configured prefix.
- Verify there are no partial side effects on failure (transactionality).
