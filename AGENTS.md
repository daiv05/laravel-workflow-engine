# AGENTS - laravel-workflow-engine

This repository implements a Laravel package focused on a configurable, extensible, and secure workflow engine.
Technical decisions must align with [docs/DesignDoc.md](docs/DesignDoc.md).

## 1) Project North Star

- Developer-first: prioritize API clarity and maintainability before UI concerns.
- Config-first: workflows are defined through YAML/JSON DSL, never hardcoded.
- Framework-native: use Laravel contracts and conventions when appropriate.
- Secure-by-default: no `eval`, strict validation, and registered functions only.

## 2) Base Functional Scope (MVP)

Implement and stabilize these capabilities before expanding scope:

- State machine with valid transitions by `from`, `to`, `action`.
- Rule engine with `role`, `fn`, `all`, `any`, `not`.
- Policy layer for user/context permissions.
- Field engine for `visible_if` and `editable_if`.
- Event dispatcher with configurable prefix (`workflow.event.`).
- Function registry for secure custom functions.
- Database storage for instances, history, and definitions.

## 3) Architecture Boundaries

Respect the layer separation defined in the design doc:

- `src/DSL`: parsing, validation, and compilation.
- `src/Rules`: condition evaluation.
- `src/Policies`: transition/action authorization.
- `src/Fields`: dynamic visibility and editability.
- `src/Engine`: state orchestration and transition execution.
- `src/Functions`: controlled function registration/lookup/execution.
- `src/Events`: domain event emission.
- `src/Storage`: workflow persistence and queries.

Avoid infrastructure logic in `Engine` and avoid coupling the package to specific business models.

## 4) DSL Rules

Every workflow definition must validate at minimum:

- Required `name`, unique per version.
- Non-empty `states`.
- Non-empty `transitions`.
- Each transition includes `from`, `to`, `action`.
- `from`/`to` must exist in `states`.
- `allowed_if` must be evaluable and deterministic.
- Function references (`fn`) must exist in `FunctionRegistry`.

DSL errors must be explicit and actionable (message + node path when applicable).

## 5) Recommended DesignDoc Enhancements

When implementing each module, include these improvements to avoid early technical debt:

- Define explicit `initial_state` per workflow.
- Define `final_states` for completion rules.
- Add stable `transition_id` for auditing and idempotency.
- Version the DSL schema via `dsl_version` for controlled evolution.
- Add concurrency control (optimistic locking/version column).
- Define transaction strategy for `execute` (state + history + events).
- Define a minimal context contract (required and optional keys).
- Define cache invalidation when activating a new definition/version.

## 6) Security and Robustness

- Dynamic code execution from DSL is forbidden.
- Do not allow unregistered anonymous functions directly in DSL definitions.
- Validate input and context types before rule evaluation.
- Reject invalid transitions with clear domain exceptions.
- Prevent partial side effects by using transactions where needed.

## 7) Mandatory Testing Per Change

Every relevant feature must include new or updated tests:

- Unit: parser/validator/compiler, rule evaluators, field logic.
- Integration: full flow `start -> can -> execute -> events -> persistence`.
- Error paths: invalid transition, missing function, malformed DSL.

Do not close engine tasks without end-to-end functional tests of the main flow.

## 8) Documentation Policy (Mandatory)

- All documentation must be written and maintained in English.
- Every feature or fix delivered by any agent must include documentation updates.
- For each feature/fix, create or update dedicated docs describing behavior, constraints, and test coverage.
- Documentation updates are part of the definition of done and must be reviewed with code changes.

## 9) Contribution Acceptance Criteria

- Aligned with [docs/DesignDoc.md](docs/DesignDoc.md).
- No breaking changes to the planned public API (`start`, `execute`, `can`, `visibleFields`).
- Coverage for happy paths and error paths.
- Useful exception messages for debugging.
- No accidental coupling to a specific application.

## 10) Repository Skills Usage

For complex tasks, use skills under `.github/skills`:

- `workflow-engine-implementation`: engine implementation/refactor workflow.
- `workflow-engine-testing`: testing strategy and test matrix.

If a task includes both code and tests, load both skills.
