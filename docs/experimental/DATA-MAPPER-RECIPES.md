# Data Mapper V2 Recipes

This document provides practical templates for common Data Mapper V2 use cases.

## 1. Approval With Comment + Document References

### Goal

Store a comment in workflow snapshot and keep document references lightweight.

### DSL

```yaml
transitions:
  - from: draft
    to: approved
    action: approve
    transition_id: tr_approve
    allowed_if: []
    mappings:
      comment:
        type: attribute
      document_ids:
        type: attach
        target: documents
```

### Binding Config

```php
'bindings' => [
    'documents' => [
        'handler' => App\Workflow\Mappings\DocumentBindingHandler::class,
        'query_handler' => App\Workflow\Mappings\DocumentBindingHandler::class,
    ],
],
```

### Handler Template

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Mappings;

use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;

class DocumentBindingHandler implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        return ['references' => $this->normalizeReferences($value)];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        $refs = $this->normalizeReferences($context['value'] ?? []);

        // Replace with repository/service lookup.
        return array_map(
            static fn (mixed $id): array => ['id' => (string) $id, 'label' => 'doc-' . (string) $id],
            $refs
        );
    }

    private function normalizeReferences(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $normalized = [];

        foreach ($value as $item) {
            $normalized[] = is_array($item) && array_key_exists('id', $item)
                ? $item['id']
                : $item;
        }

        return $normalized;
    }
}
```

## 2. Persist Related Records On Transition (relation:persist)

### Goal

When transition executes, persist related records externally and store returned references in workflow snapshot.

### DSL

```yaml
transitions:
  - from: draft
    to: submitted
    action: submit
    transition_id: tr_submit
    allowed_if: []
    mappings:
      line_items:
        type: relation
        target: line_items
                mode: persist
```

### Binding Config

```php
'bindings' => [
    'line_items' => [
        'handler' => App\Workflow\Mappings\LineItemBindingHandler::class,
        'query_handler' => App\Workflow\Mappings\LineItemBindingHandler::class,
    ],
],
```

### Handler Template

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Mappings;

use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;

class LineItemBindingHandler implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        $rows = is_array($value) ? $value : [];
        $references = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Replace with your persistence service/repository.
            $newId = (string) ($row['external_id'] ?? uniqid('li_', true));
            $references[] = $newId;
        }

        return ['references' => $references];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        $refs = is_array($context['value'] ?? null) ? $context['value'] : [];

        // Replace with read-model lookup.
        return array_map(
            static fn (mixed $id): array => ['id' => (string) $id, 'kind' => 'line_item'],
            $refs
        );
    }
}
```

## 3. Link Existing External Records (relation:reference_only)

### Goal

Accept external IDs, do not create new records, and persist normalized references only.

### DSL

```yaml
transitions:
  - from: draft
    to: linked
    action: link_external
    transition_id: tr_link_external
    allowed_if: []
    mappings:
      external_cases:
        type: relation
        target: cases
        mode: reference_only
```

### Notes

- Runtime stores normalized references directly in `workflow_instances.data`.
- Read resolution can still use `query_handler` through `resolveMappedData()`.

## 4. Normalize/Transform Input Value (custom)

### Goal

Apply deterministic transformation (for example canonical code format) through custom handler.

### DSL

```yaml
transitions:
  - from: draft
    to: validated
    action: validate_payload
    transition_id: tr_validate_payload
    allowed_if: []
    mappings:
      request_code:
        type: custom
        handler: App\Workflow\Mappings\RequestCodeMapper
        query_handler: App\Workflow\Mappings\RequestCodeMapper
```

### Handler Template

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Mappings;

use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;

class RequestCodeMapper implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        $raw = strtoupper(trim((string) $value));
        $normalized = preg_replace('/\s+/', '-', $raw) ?? $raw;

        return ['value' => $normalized];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        return [
            'raw' => (string) ($context['value'] ?? ''),
            'display' => 'CODE:' . (string) ($context['value'] ?? ''),
        ];
    }
}
```

## 5. Critical Data With Strict Fail Fast

### Goal

Abort transition on any mapping inconsistency.

### Config

```php
'mappings' => [
    'fail_silently' => false,
],
```

### Recommended usage

- Financial records
- Legal/audit-required transitions
- External writes that must be atomic with workflow state

## 6. Best Practices Checklist

- Keep payloads small in `context.data`.
- Store references in workflow snapshot whenever possible.
- Keep domain persistence in handlers, never in engine core.
- Make handlers deterministic and idempotent where feasible.
- Prefer explicit `transition_id` and traceable mapping summaries.
- Add at least one happy-path and one error-path test for each mapping strategy.
