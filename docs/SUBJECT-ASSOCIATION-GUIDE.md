# Subject Association Integration Guide

## Overview

The workflow engine can now persist and query workflow instances by subject reference (domain entity binding).

This document shows practical patterns for using subject association in your application without coupling the engine to your models.

## 1. Basic Usage in Engine

### Persist Subject on Workflow Start

```php
use Illuminate\Support\Facades\Workflow;

$solicitud = Solicitud::find(123);

$instance = Workflow::start('termination_request', [
    'subject' => [
        'subject_type' => Solicitud::class,
        'subject_id' => (string) $solicitud->id,
    ],
    'data' => [
        'reason' => 'Redundancy',
        'department' => 'Engineering',
    ],
]);

// $instance now contains:
// - instance_id (workflow inner ID)
// - subject_type ('App\Models\Solicitud')
// - subject_id ('123')
// - state ('draft')
// - data {...}
```

### Execute Always Uses Instance ID

\$instance_id remains the primary key for workflow control:

```php
Workflow::execute($instance['instance_id'], 'submit', [
    'roles' => ['HR'],
    'model' => $solicitud,
    'data' => ['comment' => '...'],
]);
```

### Query by Subject via Facade Convenience Methods

```php
use Illuminate\Support\Facades\Workflow;

$subjectRef = [
    'subject_type' => Solicitud::class,
    'subject_id' => 123,
];

$latest = Workflow::getLatestInstanceForSubject('termination_request', $subjectRef);

$allForWorkflow = Workflow::getInstancesForSubject(
    $subjectRef,
    workflowName: 'termination_request'
);

$allAcrossWorkflows = Workflow::getInstancesForSubject($subjectRef);
```

Use facade convenience methods when you only need workflow instance discovery.
Use an application query service (Pattern B) when you need custom joins, projections, or pagination tuned for your domain reads.

## 2. Pattern A: Application-Owned Eloquent Model

Define your own model over workflow tables (recommended for Laravel projects):

```php
<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;

class WorkflowInstanceRecord extends Model
{
    protected $table = 'workflow_instances';
    protected $primaryKey = 'instance_id';
    protected $keyType = 'string';

    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinitionRecord::class, 'workflow_definition_id');
    }

    public function histories()
    {
        return $this->hasMany(WorkflowHistoryRecord::class, 'instance_id');
    }
}
```

Usage:

```php
$record = WorkflowInstanceRecord::find($instanceId);

// Eloquent now provides full relationship navigation
if ($record->subject instanceof Solicitud) {
    $record->subject->status = 'workflow_pending';
    $record->subject->save();
}
```

**Trade-off:** Simple Eloquent convenience, but your app now owns workflow table migrations.

## 3. Pattern B: Query Service (Recommended Generic Approach)

Create a dedicated read service without Eloquent coupling:

```php
<?php

namespace App\Services\Workflow;

use App\Models\Solicitud;
use Illuminate\Support\Facades\DB;

class WorkflowInstanceQueryService
{
    /**
     * Get the latest workflow instance for a domain entity.
     */
    public function getLatestForEntity(
        Solicitud $solicitud,
        string $workflowName
    ): ?array {
        return DB::table('workflow_instances')
            ->join(
                'workflow_definitions',
                'workflow_instances.workflow_definition_id',
                '=',
                'workflow_definitions.id'
            )
            ->where('workflow_definitions.workflow_name', $workflowName)
            ->where('workflow_instances.subject_type', Solicitud::class)
            ->where('workflow_instances.subject_id', (string) $solicitud->id)
            ->whereNull('workflow_instances.tenant_id')
            ->orderByDesc('workflow_instances.created_at')
            ->first()
            ->toArray();
    }

    /**
     * Get all instances for an entity across workflows.
     */
    public function getHistoryForEntity(Solicitud $solicitud): array
    {
        return DB::table('workflow_instances')
            ->where('subject_type', Solicitud::class)
            ->where('subject_id', (string) $solicitud->id)
            ->whereNull('tenant_id')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Check if entity has any active workflows.
     */
    public function hasActiveWorkflow(
        Solicitud $solicitud,
        ?array $finalStates = null
    ): bool {
        $query = DB::table('workflow_instances')
            ->where('subject_type', Solicitud::class)
            ->where('subject_id', (string) $solicitud->id)
            ->whereNull('tenant_id');

        if ($finalStates !== null) {
            $query->whereNotIn('state', $finalStates);
        }

        return $query->exists();
    }
}
```

**Trade-off:** More controllers, but fully decoupled from engine and portable.

## 4. Pattern C: Read Model / Projection (For Heavy Read Workloads)

Maintain a denormalized projection for UI dashboards:

```php
<?php

namespace Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubjectWorkflowProjection extends Migration
{
    public function up(): void
    {
        Schema::create('subject_workflow_projection', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('workflow_name');
            $table->uuid('latest_instance_id')->nullable();
            $table->string('latest_state')->nullable();
            $table->string('latest_action')->nullable();
            $table->timestamp('latest_transition_at')->nullable();
            $table->json('latest_context')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'subject_type', 'subject_id', 'workflow_name'],
                'subject_workflow_projection_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'subject_workflow_subject_idx');
            $table->index(['latest_state'], 'subject_workflow_state_idx');
        });
    }
}
```

**Update Strategy:** Listen to workflow events and update projection:

```php
<?php

namespace App\Listeners\Workflow;

class UpdateSubjectProjectionListener
{
    public function handle(object $event): void
    {
        // Extract from workflow event
        $instanceId = $event->payload['instance_id'] ?? null;
        $instance = DB::table('workflow_instances')
            ->where('instance_id', $instanceId)
            ->first();

        if (!$instance) {
            return;
        }

        DB::table('subject_workflow_projection')
            ->updateOrCreate(
                [
                    'tenant_id' => $instance->tenant_id,
                    'subject_type' => $instance->subject_type,
                    'subject_id' => $instance->subject_id,
                    'workflow_name' => $this->resolveWorkflowName($instance->workflow_definition_id),
                ],
                [
                    'latest_instance_id' => $instance->instance_id,
                    'latest_state' => $instance->state,
                    'latest_action' => $event->payload['action'] ?? null,
                    'latest_transition_at' => now(),
                    'latest_context' => json_encode($event->payload['context'] ?? []),
                ]
            );
    }

    private function resolveWorkflowName(int $definitionId): string
    {
        // Cache this lookup
        return cache()->remember(
            "workflow_name_{$definitionId}",
            3600,
            fn () => DB::table('workflow_definitions')
                ->where('id', $definitionId)
                ->value('workflow_name')
        );
    }
}
```

**Trade-off:** Extra writes and coordination, but blazing-fast reads for dashboards.

## 5. Recommended Flow in Application

```php
<?php

namespace App\Http\Controllers;

use App\Models\Solicitud;
use App\Services\Workflow\WorkflowInstanceQueryService;
use Illuminate\Support\Facades\Workflow;

class SolicitudController
{
    public function __construct(
        protected WorkflowInstanceQueryService $workflowQuery
    ) {}

    public function startTermination(Solicitud $solicitud)
    {
        // 1. Normalize subject and start workflow in engine
        $instance = Workflow::start('termination_request', [
            'subject' => [
                'subject_type' => Solicitud::class,
                'subject_id' => (string) $solicitud->id,
            ],
            'data' => [...],
        ]);

        // 2. Update domain entity if needed
        $solicitud->workflow_status = 'in_progress';
        $solicitud->latest_workflow_instance_id = $instance['instance_id'];
        $solicitud->save();

        return response()->json($instance);
    }

    public function getStatus(Solicitud $solicitud)
    {
        // 3. Query workflow state via service (decoupled)
        $latest = $this->workflowQuery->getLatestForEntity(
            $solicitud,
            'termination_request'
        );

        return response()->json([
            'current_state' => $latest['state'] ?? null,
            'available_actions' => $latest ? Workflow::availableActions(
                $latest['instance_id'],
                ['roles' => auth()->user()->roles]
            ) : [],
        ]);
    }

    public function executeAction(Solicitud $solicitud, string $action)
    {
        // 4. Execute always requires instance_id, not subject
        $latest = $this->workflowQuery->getLatestForEntity(
            $solicitud,
            'termination_request'
        );

        if (!$latest) {
            return response()->json(['error' => 'No active workflow'], 404);
        }

        $result = Workflow::execute(
            $latest['instance_id'],
            $action,
            [
                'roles' => auth()->user()->roles,
                'model' => $solicitud,
                'data' => request()->input('transition_data', []),
            ]
        );

        return response()->json($result);
    }
}
```

## 6. Optional Guard: One Active Instance Per Subject

Enable this option when your domain requires exactly one active workflow instance per subject and workflow:

```php
// config/workflow.php
'enforce_one_active_per_subject' => true,
```

Behavior:

- `Workflow::start(...)` rejects a second active instance with the same tenant + workflow + subject scope.
- Active means the current instance state is not in workflow `final_states`.
- Once an instance reaches a final state, a new instance for the same subject can be started.

Error handling example:

```php
use Daiv05\LaravelWorkflowEngine\Exceptions\ActiveSubjectInstanceExistsException;

try {
    Workflow::start('termination_request', [
        'subject' => [
            'subject_type' => Solicitud::class,
            'subject_id' => (string) $solicitud->id,
        ],
    ]);
} catch (ActiveSubjectInstanceExistsException $exception) {
    // Surface a user-friendly domain message or redirect to existing instance
    report($exception);
}
```

## 6. Security Considerations

**Do this:**

- Validate subject ownership/permissions in your app layer.
- Use allowlist for subject_type values if you support class names.
- Never trust subject_type and subject_id as input directly.

**Don't do this:**

- Pass user input directly as subject_type.
- Allow engine to hydrate arbitrary models by class name.

## 7. Testing Subject Association

### Unit: Normalization

```php
use Daiv05\LaravelWorkflowEngine\Engine\SubjectNormalizer;

public function test_normalize_subject_with_integer_id()
{
    $normalized = SubjectNormalizer::normalize([
        'subject_type' => Solicitud::class,
        'subject_id' => 123,
    ]);

    $this->assertSame('123', $normalized['subject_id']);
}
```

### Integration: Persistence and Querying

```php
$instance = Workflow::start('my_flow', [
    'subject' => [
        'subject_type' => Solicitud::class,
        'subject_id' => '456',
    ],
]);

// Repository should have persisted it
$foundBySubject = $repository->getLatestInstanceForSubject(
    'my_flow',
    [
        'subject_type' => Solicitud::class,
        'subject_id' => '456',
    ]
);

$this->assertSame($instance['instance_id'], $foundBySubject['instance_id']);
```

## 8. Common Questions

**Q: Do I have to use subject association?**

A: No. Workflows work perfectly without it. Use it if you need to query or discover instances from domain context.

**Q: Can I bind multiple workflows to the same subject?**

A: Yes. Each workflow + subject combination is independent. Query by workflow_name to filter.

**Q: What if my subject has a non-scalar ID (e.g., composite key)?**

A: Normalize it to a string in your application boundary before calling start. The engine accepts any scalar that can convert to string.

**Q: Should I denormalize state onto the subject model?**

A: It's optional. If you do, update the subject record when transitions complete (listen to workflow events).
