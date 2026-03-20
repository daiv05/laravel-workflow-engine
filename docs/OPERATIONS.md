# Operations and Troubleshooting

## Overview

This guide describes practical operational checks and troubleshooting workflows for workflow runtime, outbox delivery, and diagnostics.

## Diagnostics Configuration

Configuration keys:

- `workflow.diagnostics.enabled`
- `workflow.diagnostics.prefix`

When enabled, the package emits technical diagnostics for:

- transition execution success/failure
- outbox item dispatch success/failure
- outbox batch completion and skip conditions

Transition diagnostics are emitted by transition executor, and outbox diagnostics are emitted by outbox processor.

## Common Diagnostic Event Names

With default prefix (`workflow.diagnostic.`):

- `workflow.diagnostic.transition.executed`
- `workflow.diagnostic.transition.failed`
- `workflow.diagnostic.outbox.item.dispatched`
- `workflow.diagnostic.outbox.item.failed`
- `workflow.diagnostic.outbox.batch.completed`
- `workflow.diagnostic.outbox.batch.skipped`

## Structured Error Context

Workflow domain exceptions can be exported as normalized diagnostic payloads:

- `exception_class`
- `exception_code`
- `exception_message`
- `context`

This shape is used by transition failure diagnostics.

Outbox failure diagnostics also include:

- `outbox_id`
- `event_name`
- `attempts_before`
- `error_message`
- `exception_class`

Outbox success diagnostics include:

- `outbox_id`
- `event_name`
- `attempts_before`

Outbox batch diagnostics include:

- `limit`
- `max_attempts`
- `processed`
- `dispatched`
- `failed`

## Outbox Troubleshooting

### Dispatch Flow Clarification

Outbox rows are created when workflow events are queued.

After commit, dispatcher attempts immediate Laravel event dispatch and marks outbox rows as dispatched when successful.

`OutboxProcessor::processPending(limit, maxAttempts)` handles pending/failed rows for retry/recovery flows.

When `limit <= 0` or `maxAttempts <= 0`, processor emits `outbox.batch.skipped` and does not process rows.

### Symptom: records remain pending

Checklist:

- verify application worker invokes `OutboxProcessor::processPending(limit, maxAttempts)`
- verify events dispatcher is correctly bound in host application
- inspect `workflow_outbox.status`, `attempts`, and `last_error`

### Symptom: records move to failed

Checklist:

- inspect `last_error` for dispatch exception details
- validate listener dependencies and runtime connectivity
- run bounded retries and monitor if `status` transitions to `dispatched`

### Symptom: no diagnostics observed

Checklist:

- ensure `workflow.diagnostics.enabled = true`
- verify diagnostics prefix expected by listeners
- verify host app has event/log handlers configured

## Recommended Monitoring

Track these counters over time:

- transition failures by `exception_code`
- outbox `failed` count
- outbox retry attempts distribution
- outbox dispatch success ratio

Use alerts for sustained outbox failure growth or repeated transition failures for the same workflow/action.
