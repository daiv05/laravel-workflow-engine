# DSL Specification

This document describes the DSL behavior currently implemented in the engine.

## Processing Pipeline

Workflow definitions are processed in three steps:

1. Parse (`Parser`)
2. Validate (`Validator`)
3. Compile (`Compiler`)

Only validated definitions should be activated.

## Input Formats

Supported definition inputs:

- PHP array
- JSON string
- YAML string

Parser behavior:

- Empty string is rejected.
- A string starting with `{` or `[` is treated as JSON.
- Any other non-empty string is treated as YAML.
- YAML parsing requires `symfony/yaml`.
- Parsed JSON/YAML must produce an array/object structure.

## Mandatory Root Keys

The implementation enforces these root keys during parse + validation:

- `dsl_version`
- `name`
- `version`
- `initial_state`
- `final_states`
- `states`
- `transitions`

Current type checks:

- `dsl_version` must be an integer.
- `states` must be a non-empty array.
- `transitions` must be a non-empty array.
- `final_states` must be a non-empty array.
- `initial_state` must exist in `states`.
- Every item in `final_states` must exist in `states`.

## Transition Schema

Each transition requires non-empty string keys:

- `from`
- `to`
- `action`
- `transition_id`

Additional enforced semantics:

- `from` must exist in `states`.
- `to` must exist in `states`.
- Duplicate transitions by pair `(from, action)` are rejected.

Optional transition sections:

- `allowed_if`
- `fields`
- `effects`
- `mappings`

## Rule DSL (`allowed_if`, `fields.visible_if`, `fields.editable_if`)

Supported operators:

- `role`
- `fn`
- `all`
- `any`
- `not`

Validation rules:

- `fn` must reference a registered function.
- `args`, when present, must be an array.
- Function reference validation is recursive across `all`/`any`/`not` trees.
- Function reference validation is applied to:
  - `transitions.*.allowed_if`
  - `transitions.*.fields.visible_if`
  - `transitions.*.fields.editable_if`

Function argument constraints are defined by each registered function implementation.

Runtime context rules:

- Any rule tree containing `role` requires `context.roles` as array.
- Missing/invalid `context.roles` raises a context validation exception at evaluation time.

## Fields Section

`fields` is transition-local and supports:

- `visible`: array of field names
- `editable`: array of field names
- `visible_if`: rule DSL
- `editable_if`: rule DSL

Runtime behavior:

- `visible` and `editable` are filtered to string items.
- If `visible_if` evaluates to `false`, the visible list is returned empty.
- If `editable_if` evaluates to `false`, the editable list is returned empty.

## Mappings Section

`mappings` must be an object-like array keyed by input field name.

Supported mapping types:

- `attribute` (default when `type` is omitted)
- `attach`
- `relation`
- `custom`

Validation rules:

- mapping key must be a non-empty string.
- mapping value must be an array.
- `type` must be one of `attribute`, `attach`, `relation`, `custom`.
- `attach` and `relation` require non-empty string `target`.
- `custom` requires non-empty string `handler`.

Execution-time mapping behavior:

- If transition contains mappings, `context.data` is required and must be an array.
- `attribute`: stores value directly into instance data.
- `attach`: stores normalized references (array values, or item `id` when item is object-like).
- `relation`: delegates write to binding handler resolved by `target`.
- `custom`: delegates write to handler class declared in mapping.
- Mapping writes are transactional with state/history update.
- If mapping throws and fail-silent mode is disabled, transition is rolled back.

Read-time mapping behavior (`resolveMappedData`):

- `attribute`: returns stored value.
- `attach`: optionally resolves through binding query handler; otherwise returns stored value.
- `relation`: resolves through binding query handler (or returns stored value when query handler is not available).
- `custom`: resolves through mapping query handler/handler when available.

## Effects Section

`effects` is optional and consumed at transition execution time.

Current runtime behavior:

- Each effect item should be an object with string `event`.
- Invalid effect entries are ignored (no DSL validation error).
- Valid effect events are queued during transaction and flushed after commit.
- Optional `meta` is propagated to event payload as-is.

## Compiler Output

Compiler adds `transition_index`:

- key format: `from::action`
- value: transition payload

State resolution during execution uses this index.

## Final-State Guard

If current instance state belongs to `final_states`:

- no transition is resolved from that state
- `can()` returns `false`
- `availableActions()` returns empty list
- `visibleFields()` returns empty map

## Error Style

DSL validation failures throw `DSLValidationException` with:

- explicit message
- logical node path (`node_path`), included in the message (`... at <path>`)

## Current Non-Enforced Areas

The current validator does not strictly enforce all potential shape/type rules. In particular:

- `name` presence is enforced, but not non-empty string type.
- `version` presence is enforced, but not integer type.
- `effects` structure is not validated by the DSL validator.
- `fields.visible` and `fields.editable` item types are filtered at runtime rather than validated in DSL.

## Example (YAML)

```yaml
dsl_version: 2
name: termination_request
version: 1
initial_state: draft
final_states:
  - approved
  - rejected
states:
  - draft
  - hr_review
  - approved
  - rejected
transitions:
  - from: draft
    to: hr_review
    action: submit
    transition_id: tr_submit
    allowed_if: {}

  - from: hr_review
    to: approved
    action: approve
    transition_id: tr_approve
    allowed_if:
      all:
        - role: HR
        - fn: matchesSubjectType
          args:
            - App\\Models\\Solicitud
    fields:
      visible:
        - comment
        - documents
      editable:
        - comment
      visible_if:
        role: HR
      editable_if:
        fn: matchesSubjectOwner
        args:
          - actor_id
    mappings:
      comment:
        type: attribute
      documents:
        type: relation
        target: documents
      document_ids:
        type: attach
        target: documents
      checksum:
        type: custom
        handler: App\Workflow\Mappers\ChecksumMapper
    effects:
      - event: request_approved
        meta:
          bus: analytics
          topic: workflow.request.approved
          tags:
            - workflow
            - approval
```
