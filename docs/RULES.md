# Rules and Conditions

## Supported Operators

The rule engine supports these operators:

- role
- fn
- all
- any
- not

## role

Checks that the actor context includes a role.

### Example

```yaml
allowed_if:
  role: HR
```

## fn

Calls a registered function from the function registry.

`fn` optionally supports `args`.

Registration model:

- Functions must be registered by the host application (or package integrator) before DSL validation/activation.

Invocation contract:

- Runtime call shape is `fn(context, ...args)`.
- `context` is always the first argument.
- `args` is optional; when omitted, it is treated as an empty array.
- Validator enforces `args` is an array when present.

### Example

```yaml
allowed_if:
  fn: isHR
```

### Example with args

```yaml
allowed_if:
  fn: matchesSubjectType
  args:
    - App\\Models\\Solicitud
```

## all

All nested rules must evaluate true.

### Example

```yaml
allowed_if:
  all:
    - role: HR
    - fn: isActive
```

## any

At least one nested rule must evaluate true.

### Example

```yaml
allowed_if:
  any:
    - role: ADMIN
    - role: HR
```

## not

Negates nested rule result.

### Example

```yaml
allowed_if:
  not:
    role: GUEST
```

## Context Contract

For role-based rules, context must include:

- roles: array

When an instance has subject association, rule context also includes:

- subject.subject_type: string
- subject.subject_id: string

Instance subject values are injected by the engine for `can`, `availableActions`, and `visibleFields`.

For transition execution (`execute`), context is passed as provided by caller.

If a rule uses `role`, `context.roles` must exist and be an array; otherwise evaluation raises context validation errors.

## Subject-Aware Custom Functions

Subject-aware rules should be implemented as regular custom functions registered by the host application.

Example strategy:

- `matchesSubjectType(expectedType)`
- `matchesSubjectOwner(actorIdKey = "actor_id")`

Examples:

```yaml
allowed_if:
  all:
    - fn: matchesSubjectType
      args:
        - App\\Models\\Solicitud
    - fn: matchesSubjectOwner
      args:
        - actor_id
```

Nested rule trees are validated recursively for required context keys.

## Error Paths

Typical rule failures:

- Missing roles context for role-based rule.
- Roles context with invalid type.
- Function reference not registered.
- Unsupported or malformed rule shape.

## Recommendations

- Keep rules deterministic.
- Keep function side effects out of rule evaluation.
- Prefer small composed rules over monolithic nested trees.
