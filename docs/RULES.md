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

### Example

```yaml
allowed_if:
  fn: isHR
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
