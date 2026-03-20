# DSL Specification

## Input Formats

Supported input formats for workflow definitions:

- PHP array
- JSON string
- YAML string

## Mandatory Root Keys

- dsl_version (int)
- name (string)
- version (int)
- initial_state (string)
- final_states (array)
- states (non-empty array)
- transitions (non-empty array)

## Transition Keys

Each transition requires:

- from
- to
- action
- transition_id

Optional:

- allowed_if
- fields
- effects
- mappings

### mappings structure

`mappings` is an object-like map keyed by input field name.

Supported types:

- attribute
- attach
- relation
- custom

Rules:

- `type` defaults to `attribute` when omitted.
- `attach` and `relation` require `target`.
- `custom` requires `handler`.

### effects structure

Each item in `effects` must include:

- event (string)

Optional per effect:

- meta (any JSON/YAML value: object, array, scalar, boolean, or null)

`meta` is propagated to event payload so YAML can be the source of truth for downstream flows.

## Rule Operators in allowed_if

- role
- fn
- all
- any
- not

### fn args

Rules using `fn` may provide optional `args` as array values.

Example:

```yaml
allowed_if:
  fn: subject_type_matches
  args:
    - App\\Models\\Solicitud
```

## Validation Semantics

- `initial_state` must belong to `states`.
- `final_states` must be a non-empty array.
- Every `final_state` must belong to `states`.
- `from` and `to` must belong to `states`.
- Duplicate `(from, action)` transitions are rejected.
- Referenced function names in `fn` must exist in function registry.
- `args` must be an array when present.
- This `fn` validation applies to `allowed_if`, `fields.visible_if`, and `fields.editable_if`.
- `subject_type_matches` requires `args[0]` as non-empty expected subject type.
- `is_subject_owner` accepts optional `args[0]` as non-empty context key for actor id (`actor_id` by default).
- `mappings` must be an array object keyed by field name.
- Mapping type must be one of `attribute`, `attach`, `relation`, `custom`.
- Mapping-specific required keys (`target`, `handler`) are validated.

## Error Style

Validation failures include explicit message and logical node path when applicable.

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
      role: HR
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
