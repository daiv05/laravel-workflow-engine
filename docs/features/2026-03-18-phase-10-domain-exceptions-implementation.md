# Phase 10 Domain Exceptions Implementation

## Summary

This feature completes exception implementation across the workflow engine domain by adding structured context, stable error codes, and semantic factory methods.

## Scope

Implemented updates:

- Extended `WorkflowException` with context payload support and context merge helper.
- Implemented factory/static helpers for domain exceptions:
  - `DSLValidationException`
  - `FunctionNotFoundException`
  - `InvalidTransitionException`
  - `OptimisticLockException`
  - `UnauthorizedTransitionException`
  - `ContextValidationException`
- Updated throw sites to use semantic factories in key modules.

## Files Changed

- `src/Exceptions/WorkflowException.php`
- `src/Exceptions/DSLValidationException.php`
- `src/Exceptions/FunctionNotFoundException.php`
- `src/Exceptions/InvalidTransitionException.php`
- `src/Exceptions/OptimisticLockException.php`
- `src/Exceptions/UnauthorizedTransitionException.php`
- `src/Exceptions/ContextValidationException.php`
- `src/Functions/FunctionRegistry.php`
- `src/Rules/ContextValidator.php`
- `src/Engine/TransitionExecutor.php`
- `src/Storage/InMemoryWorkflowRepository.php`
- `src/Storage/DatabaseWorkflowRepository.php`

## Behavior Impact

- Exceptions now include richer diagnostic context for debugging and observability.
- Error creation is standardized through domain factories instead of generic message-only construction.
- Existing exception types remain unchanged to preserve compatibility for consumers.

## Current Status (2026-03-20)

- Phase 11 test coverage for exception factories is now implemented in `tests/Unit/DomainExceptionsTest.php`.
- The `withContext` subclass-metadata caveat is now mitigated:
  - `WorkflowException::withContext` delegates to a protected instantiation hook.
  - `DSLValidationException` overrides the hook to preserve `nodePath`.
  - Regression test coverage now includes node path preservation after `withContext`.

## Recommended Resolution for `withContext`

Preferred approach:

1. Introduce a protected clone/factory hook in `WorkflowException` (for example, `newWith(string $message, int $code, ?\Throwable $previous, array $context): static`).
2. Make `withContext` delegate instance creation to that hook.
3. Override the hook in `DSLValidationException` so `nodePath` is propagated to the new exception.
4. Add targeted unit tests for subclass metadata preservation after `withContext`.

Alternative approach:

- Remove subclass mutable state and derive `nodePath` directly from context (`context()['node_path'] ?? null`) to keep the base implementation generic.

## Next Steps

- Completed: Implemented the `withContext` hook strategy and added regression tests.
- Map domain error codes to user-facing/API error responses in integration layer.
