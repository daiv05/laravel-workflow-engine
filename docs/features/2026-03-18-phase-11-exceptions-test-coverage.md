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

## Current Status (2026-03-20)

- Documented factory coverage is in place and aligned with implementation.
- Added dedicated regression test coverage asserting subclass-specific metadata preservation (`DSLValidationException::nodePath`) when `withContext` is called.

## Next Steps

- Map domain exception codes to transport/API error envelopes in adapter layers.
- Add integration tests asserting exception translation behavior in framework boundaries.
- Completed: Added unit test coverage for `withContext` behavior on subclass exceptions.
