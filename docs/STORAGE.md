# Storage Model

## Overview

The package currently supports:

- In-memory repository for fast local execution and tests.
- Database repository using Laravel connection/query builder.

## Core Tables

- workflow_definitions
- workflow_instances
- workflow_histories
- workflow_outbox

Note: outbox persistence is active when storage driver is `database` and outbox support is enabled in configuration.

## workflow_definitions

Main fields:

- id
- workflow_name
- version
- tenant_id
- dsl_version
- definition
- is_active
- active_scope
- created_at
- updated_at

Constraints:

- Unique workflow_name + version + tenant_id.
- Unique active_scope for active definition enforcement per workflow + tenant scope.

## workflow_instances

Main fields:

- instance_id (UUID primary key)
- workflow_definition_id (immutable link)
- tenant_id
- state
- data (JSON)
- version (optimistic lock counter)
- created_at
- updated_at

Optional subject association fields:

- subject_type (nullable)
- subject_id (nullable)

## workflow_histories

Main fields:

- id
- instance_id
- transition_id
- action
- from_state
- to_state
- actor
- payload
- created_at
- updated_at

## Transaction Semantics

Transition execution is atomic:

- state mutation
- optimistic version update
- history append
- event queueing

Event flush occurs only after successful transaction completion.

## Outbox Operational Support

Outbox records use these lifecycle states:

- `pending`: created during transition transaction.
- `failed`: dispatch failed and record is retry-eligible.
- `dispatched`: successfully dispatched and closed.

Outbox operational fields:

- `attempts`: number of failed dispatch attempts.
- `last_error`: latest dispatch failure message (truncated).
- `dispatched_at`: timestamp set on successful dispatch.

Worker support:

- Invoke `OutboxProcessor::processPending($limit, $maxAttempts)` from an application scheduler/worker.
- If `$limit <= 0` or `$maxAttempts <= 0`, batch is skipped.

Dispatch strategy:

- Reads `pending` and `failed` rows with attempts lower than configured maximum.
- Dispatches using Laravel events dispatcher.
- Marks successful rows as `dispatched`.
- Marks failures as `failed`, increments `attempts`, and stores `last_error`.

After-commit behavior:

- Transition event queue is flushed only after successful transaction commit.
- In-process dispatcher path can mark outbox rows as `dispatched` immediately after successful dispatch.
- Pending/failed rows are intended for retry and operational recovery via outbox processor.

## Active Definition Resolution

Active definition is resolved by:

- workflow_name
- tenant_id (nullable scope)
- is_active true

Only one active definition is allowed for a given scope.

## Versioning Policy

- Definitions are immutable.
- Instances never migrate between definitions.
- Each instance keeps workflow_definition_id fixed after creation.
- Activating a definition with an already existing `(workflow_name, version, tenant_id)` is rejected with a domain error.
- Changing behavior requires creating and activating a new definition version.

## Caching

Engine-level in-memory cache stores:

- active definitions by workflow + tenant + version
- definitions by definition id

Cache is invalidated on new version activation for the same scope.

## Optional Single Active Instance Enforcement

When `workflow.enforce_one_active_per_subject` is enabled, the engine prevents creating a second active instance for the same:

- tenant_id
- workflow_name
- subject_type
- subject_id

Active is evaluated with workflow definition terminal states:

- active state = current state is not in `final_states`

Implementation notes:

- Guard is applied in engine start flow before persistence.
- Check and create execute inside repository transaction to reduce race windows.
- Database-level unique constraints are not yet uniformly available across all supported drivers.
- On drivers without filtered/partial unique index support, enforcement remains application-level.
