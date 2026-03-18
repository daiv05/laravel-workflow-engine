# Phase 3 Transactional Execute and After-Commit Dispatch

## Summary

This feature upgrades transition execution to be transaction-aware and enforces after-commit event dispatch semantics.

## Scope

Implemented updates:

- Added `transaction(callable $callback): mixed` to `StorageRepositoryInterface`.
- Implemented transaction support in `InMemoryWorkflowRepository` using snapshot rollback.
- Implemented transaction support in `DatabaseWorkflowRepository` using connection transactions.
- Refactored `TransitionExecutor::execute()` to:
  - Run state update + history write + event queueing inside storage transaction.
  - Clear queued events if execution fails.
  - Flush events only after successful transaction completion.

## Behavioral Guarantees

- Transition side effects are atomic with persistence operations.
- Queued events are not flushed if transition persistence fails.
- Event dispatch now follows explicit after-commit semantics.

## Files Changed

- `src/Contracts/StorageRepositoryInterface.php`
- `src/Storage/InMemoryWorkflowRepository.php`
- `src/Storage/DatabaseWorkflowRepository.php`
- `src/Engine/TransitionExecutor.php`
- `tests/Unit/InMemoryWorkflowRepositoryTest.php`

## Test Coverage Added

- Unit test validating in-memory transaction rollback restores previous instance state/version.

## Known Gaps

- Database-level transaction integration tests are still pending.
- Outbox strategy for cross-process delivery remains future work.
