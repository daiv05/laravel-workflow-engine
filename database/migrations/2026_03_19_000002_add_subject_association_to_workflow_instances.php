<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wf_instance_subject_lookup_idx');
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'wf_instance_definition_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropIndex('wf_instance_subject_lookup_idx');
            $table->dropIndex('wf_instance_definition_subject_idx');
            $table->dropColumn('subject_type', 'subject_id');
        });
    }
};
