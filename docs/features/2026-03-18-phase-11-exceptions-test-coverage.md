# Phase 11 Exception Factory Test Coverage

## Summary

This feature adds dedicated unit tests to validate the implemented domain exceptions, including stable error codes, messages, and structured context payloads.

## Scope

Implemented tests:

- Base `WorkflowException` context merge behavior and immutability.
- `DSLValidationException` path-aware and malformed constructors.
- `FunctionNotFoundException` factory output.
- `InvalidTransitionException` factory output.
- `OptimisticLockException` factory output with expected/actual versions.
- `UnauthorizedTransitionException` factory output with action/state/context.
- `ContextValidationException` factories for missing key and invalid type.

## Files Added

- `tests/Unit/DomainExceptionsTest.php`

## Value Delivered

- Ensures exception implementation is verifiable and regression-safe.
- Guarantees diagnostic metadata remains stable for observability and API mapping layers.

## Next Steps

- Map domain exception codes to transport/API error envelopes in adapter layers.
- Add integration tests asserting exception translation behavior in framework boundaries.
