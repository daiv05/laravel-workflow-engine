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

## Next Steps

- Add targeted unit tests for exception factories and context payload consistency.
- Map domain error codes to user-facing/API error responses in integration layer.
