# Phase 15 - Outbox Operational Retry Support

## Summary

This phase adds operational support for database outbox dispatch with retries.

The package now includes:

- Pending outbox retrieval with bounded retries.
- Failure handling with attempt tracking and latest error capture.
- A worker service to process outbox records.

## Implementation

### Outbox contract

`OutboxStoreInterface` now supports:

- `fetchPending(int $limit, int $maxAttempts): array`
- `markFailed(string $outboxId, string $errorMessage): void`

### Database outbox store

`DatabaseOutboxStore` now:

- Persists `attempts` and `last_error` on insert.
- Fetches records with status in `pending|failed` and `attempts < maxAttempts`.
- Marks failures as `failed`, increments `attempts`, and stores a truncated error message.

### Outbox processor

New service: `OutboxProcessor`

- Loads pending/retryable records.
- Dispatches event name + payload through Laravel dispatcher.
- Marks success as dispatched.
- Marks dispatch errors as failed for retry.

### Worker integration

Application workers/schedulers can call:

- `OutboxProcessor::processPending($limit, $maxAttempts)`

### Provider wiring

- Registers `OutboxProcessor` in the service container.
- Respects `workflow.outbox.enabled`; when disabled, outbox store resolves to null implementation.

## Schema Updates

`workflow_outbox` now includes:

- `attempts` (unsigned integer, default 0)
- `last_error` (nullable text)

## Tests

Added integration suite:

- `OutboxProcessorTest::test_it_dispatches_pending_outbox_records_and_marks_them_dispatched`
- `OutboxProcessorTest::test_it_marks_failed_and_retries_until_dispatch_succeeds`
- `OutboxProcessorTest::test_it_skips_records_that_reached_max_attempts`

## Operational Guidance

Recommended worker loop from scheduler/queue runner:

- Run an application worker/scheduler step that calls `OutboxProcessor::processPending` at fixed interval.
- Keep `max-attempts` bounded to avoid infinite retry loops.
- Monitor rows that remain in `failed` status after max attempts.
