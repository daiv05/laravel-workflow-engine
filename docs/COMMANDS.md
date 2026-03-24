# Commands

## `workflow:make`

Scaffold a new workflow definition and optionally its dedicated database tables.

### Signature

```bash
php artisan workflow:make {name} [--format=yaml] [--with-migrations]
```

| Argument / Option  | Default | Description |
|--------------------|---------|-------------|
| `name`             | —       | Workflow name in PascalCase (`OrderApproval`) or snake_case (`order_approval`) |
| `--format`         | `yaml`  | Stub format: `yaml`, `json`, or `php` |
| `--with-migrations`| off     | Also generate a migration file with the 3 custom tables |

### What it generates

#### Workflow stub — `workflows/{Name}.{ext}`

A valid DSL v2 definition file with all mandatory keys pre-filled. Choose the format that best fits your loading strategy:

| Format | File | How the engine reads it |
|--------|------|-------------------------|
| `yaml` | `OrderApproval.yaml` | Passed as a YAML string to `Parser::parse()`. Requires `symfony/yaml`. |
| `json` | `OrderApproval.json` | Passed as a JSON string (starts with `{`) to `Parser::parse()`. |
| `php`  | `OrderApproval.php`  | File returns a PHP array; passed directly as `Parser::parse(array)` — no `eval`. |

#### Migration — `database/migrations/<timestamp>_create_workflow_{name}_tables.php` _(only with `--with-migrations`)_

Creates three tables that mirror the default workflow tables but are scoped to this binding:

- `workflow_{name}_instances`
- `workflow_{name}_histories`
- `workflow_{name}_outbox`

### Examples

```bash
# YAML stub only (default)
php artisan workflow:make OrderApproval

# JSON stub only
php artisan workflow:make OrderApproval --format=json

# PHP array stub only
php artisan workflow:make OrderApproval --format=php

# YAML stub + migration
php artisan workflow:make OrderApproval --with-migrations

# JSON stub + migration
php artisan workflow:make OrderApproval --format=json --with-migrations
```

### Output

```
[OK] Created: workflows/OrderApproval.yaml
[OK] Created: database/migrations/2026_03_24_131900_create_workflow_order_approval_tables.php

Add this to config/workflow.php under 'storage.bindings':

    'order_approval' => [
        'instances_table' => 'workflow_order_approval_instances',
        'histories_table' => 'workflow_order_approval_histories',
        'outbox_table'    => 'workflow_order_approval_outbox',
    ],
```

### Next steps after running the command

1. **Edit the stub** — fill in your real states, transitions, and rules.
2. **Run migrations** (if `--with-migrations` was used):
   ```bash
   php artisan migrate
   ```
3. **Register the binding** in `config/workflow.php` using the snippet printed by the command.
4. **Reference the binding** in your stub's `storage.binding` key (already pre-filled by the scaffold).
5. **Load and activate** the definition in your application code:
   ```php
   // Example for a YAML file
   $engine->activateDefinition(file_get_contents(base_path('workflows/OrderApproval.yaml')));

   // Example for a PHP file
   $engine->activateDefinition(require base_path('workflows/OrderApproval.php'));
   ```

### Validation

The command rejects:

- Empty names
- Names containing spaces, special characters, or leading digits
- Unknown `--format` values (only `yaml`, `json`, `php` are accepted)
