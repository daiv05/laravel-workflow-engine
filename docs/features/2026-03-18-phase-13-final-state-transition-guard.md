# Phase 13 - Final State Transition Guard

## Summary

This phase formalizes final state semantics at execution time.

Workflow instances in final states are now terminal from the engine perspective:

- Outgoing transitions from final states are blocked.
- `can()` returns `false` for any action when current state is final.
- `visibleFields()` returns an empty action map when current state is final.

This behavior applies even if a DSL definition incorrectly includes outgoing transitions from a final state.

## Implementation Details

### Engine behavior

- `StateMachine::transitionFor` now checks `isFinalState` before transition index lookup.
- `WorkflowEngine::visibleFields` now short-circuits to `[]` for final states.

### Error behavior

- `execute()` keeps existing domain error semantics and throws `InvalidTransitionException` when no transition is available from the current state.
- Since final states now force `transitionFor` to return `null`, execute from final state is rejected consistently.

## Test Coverage

### Integration (in-memory)

- `WorkflowEngineTest::test_final_state_cannot_transition_even_if_definition_contains_outgoing_transition`

Checks:
- instance reaches final state
- `can()` is `false` for configured outgoing action
- `visibleFields()` returns empty map
- `execute()` throws `InvalidTransitionException`

### Integration (database)

- `DatabaseWorkflowEngineTest::test_database_engine_blocks_transitions_from_final_state_even_if_configured`

Checks the same behavior with database storage.

## Design Alignment

This phase reinforces completion rules around `final_states` and avoids reopening closed instances through misconfigured DSL transitions.
