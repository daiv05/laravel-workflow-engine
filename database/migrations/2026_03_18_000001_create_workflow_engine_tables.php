<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_name');
            $table->unsignedInteger('version');
            $table->string('tenant_id')->nullable();
            $table->unsignedInteger('dsl_version')->default(2);
            $table->longText('definition');
            $table->boolean('is_active')->default(false);
            $table->string('active_scope')->nullable();
            $table->timestamps();

            $table->unique(['workflow_name', 'version', 'tenant_id'], 'wf_def_unique_name_version_tenant');
            $table->unique(['active_scope'], 'wf_def_unique_active_scope');
            $table->index(['workflow_name', 'tenant_id', 'is_active'], 'wf_def_active_lookup');
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->uuid('instance_id')->primary();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions');
            $table->string('tenant_id')->nullable();
            $table->string('state');
            $table->json('data');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'state'], 'wf_instance_tenant_state_idx');
            $table->index(['workflow_definition_id'], 'wf_instance_definition_idx');
        });

        Schema::create('workflow_instance_locator', function (Blueprint $table): void {
            $table->uuid('instance_id')->primary();
            $table->unsignedBigInteger('workflow_definition_id');
            $table->string('instances_table');
            $table->string('histories_table');
            $table->string('tenant_id')->nullable();
            $table->string('state');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->timestamps();

            $table->index(['workflow_definition_id'], 'wf_locator_definition_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wf_locator_subject_lookup_idx');
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'wf_locator_definition_subject_idx');
        });

        Schema::create('workflow_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('instance_id');
            $table->string('transition_id');
            $table->string('action');
            $table->string('from_state');
            $table->string('to_state');
            $table->string('actor')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('instance_id')->references('instance_id')->on('workflow_instances')->cascadeOnDelete();
            $table->index(['instance_id'], 'wf_history_instance_idx');
            $table->index(['created_at'], 'wf_history_created_at_idx');
        });

        Schema::create('workflow_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts', 'created_at'], 'wf_outbox_status_attempts_created_idx');
        });

        Schema::create('workflow_outbox_tables', function (Blueprint $table): void {
            $table->string('table_name')->primary();
            $table->timestamp('registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_outbox_tables');
        Schema::dropIfExists('workflow_outbox');
        Schema::dropIfExists('workflow_histories');
        Schema::dropIfExists('workflow_instance_locator');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definitions');
    }
};
