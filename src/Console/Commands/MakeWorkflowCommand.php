<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Console\Commands;

use Illuminate\Console\Command;

class MakeWorkflowCommand extends Command
{
    protected $signature = 'workflow:make
        {name              : Workflow name in PascalCase or snake_case, e.g. OrderApproval}
        {--format=yaml     : Stub format: yaml, json, or php (default: yaml)}
        {--with-migrations : Also generate a migration file with dedicated instances, histories and outbox tables}';

    protected $description = 'Scaffold a new workflow definition stub and optionally its database migration';

    private const VALID_FORMATS = ['yaml', 'json', 'php'];

    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $format  = strtolower((string) $this->option('format'));

        if (!$this->validateName($rawName)) {
            $this->error('Invalid workflow name. Use PascalCase (OrderApproval) or snake_case (order_approval).');

            return self::FAILURE;
        }

        if (!in_array($format, self::VALID_FORMATS, true)) {
            $this->error(sprintf(
                'Invalid format "%s". Allowed values: %s.',
                $format,
                implode(', ', self::VALID_FORMATS)
            ));

            return self::FAILURE;
        }

        $bindingKey = $this->toSnakeCase($rawName);
        $stubName   = $this->toPascalCase($rawName);

        $this->generateStub($stubName, $bindingKey, $format);

        if ($this->option('with-migrations')) {
            $this->generateMigration($bindingKey);
        }

        $this->printConfigHint($bindingKey);

        return self::SUCCESS;
    }

    private function generateStub(string $stubName, string $bindingKey, string $format): void
    {
        $workflowsDir = $this->workflowsPath();

        if (!is_dir($workflowsDir)) {
            mkdir($workflowsDir, 0755, true);
        }

        $ext      = $format;
        $filePath = $workflowsDir . DIRECTORY_SEPARATOR . $stubName . '.' . $ext;
        $content  = match ($format) {
            'yaml'  => $this->buildYamlStub($bindingKey),
            'json'  => $this->buildJsonStub($bindingKey),
            'php'   => $this->buildPhpStub($bindingKey),
            default => '',
        };

        file_put_contents($filePath, $content);

        $this->line(sprintf('<info>[OK]</info> Created: workflows/%s.%s', $stubName, $ext));
    }

    private function buildYamlStub(string $bindingKey): string
    {
        return <<<YAML
        dsl_version: 2
        name: {$bindingKey}
        version: 1
        storage:
          binding: {$bindingKey}
        initial_state: draft
        final_states:
          - approved
          - rejected
        states:
          - draft
          - approved
          - rejected
        transitions:
          - from: draft
            to: approved
            action: approve
            transition_id: tr_approve
            allowed_if: {}
          - from: draft
            to: rejected
            action: reject
            transition_id: tr_reject
            allowed_if: {}
        YAML;
    }

    private function buildJsonStub(string $bindingKey): string
    {
        $data = $this->stubData($bindingKey);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function buildPhpStub(string $bindingKey): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        return [
            'dsl_version'   => 2,
            'name'          => '{$bindingKey}',
            'version'       => 1,
            'storage'       => ['binding' => '{$bindingKey}'],
            'initial_state' => 'draft',
            'final_states'  => ['approved', 'rejected'],
            'states'        => ['draft', 'approved', 'rejected'],
            'transitions'   => [
                ['from' => 'draft', 'to' => 'approved', 'action' => 'approve', 'transition_id' => 'tr_approve', 'allowed_if' => []],
                ['from' => 'draft', 'to' => 'rejected', 'action' => 'reject',  'transition_id' => 'tr_reject',  'allowed_if' => []],
            ],
        ];
        PHP;
    }

    /**
     * @return array<string, mixed>
     */
    private function stubData(string $bindingKey): array
    {
        return [
            'dsl_version'   => 2,
            'name'          => $bindingKey,
            'version'       => 1,
            'storage'       => ['binding' => $bindingKey],
            'initial_state' => 'draft',
            'final_states'  => ['approved', 'rejected'],
            'states'        => ['draft', 'approved', 'rejected'],
            'transitions'   => [
                ['from' => 'draft', 'to' => 'approved', 'action' => 'approve', 'transition_id' => 'tr_approve', 'allowed_if' => (object) []],
                ['from' => 'draft', 'to' => 'rejected', 'action' => 'reject',  'transition_id' => 'tr_reject',  'allowed_if' => (object) []],
            ],
        ];
    }

    private function generateMigration(string $bindingKey): void
    {
        $migrationsDir = $this->migrationsPath();

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_create_workflow_{$bindingKey}_tables.php";
        $filePath  = $migrationsDir . DIRECTORY_SEPARATOR . $filename;
        $content   = $this->buildMigration($bindingKey);

        file_put_contents($filePath, $content);

        $this->line(sprintf('<info>[OK]</info> Created: database/migrations/%s', $filename));
    }

    private function buildMigration(string $bindingKey): string
    {
        $instancesTable = "workflow_{$bindingKey}_instances";
        $historiesTable = "workflow_{$bindingKey}_histories";
        $outboxTable    = "workflow_{$bindingKey}_outbox";

        // Short prefix for index names to stay within DB identifier limits (max 64 chars).
        $p = substr($bindingKey, 0, 12);

        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration {
            public function up(): void
            {
                Schema::create('{$instancesTable}', function (Blueprint \$table): void {
                    \$table->uuid('instance_id')->primary();
                    \$table->foreignId('workflow_definition_id')->constrained('workflow_definitions');
                    \$table->string('tenant_id')->nullable();
                    \$table->string('state');
                    \$table->json('data');
                    \$table->unsignedInteger('version')->default(0);
                    \$table->string('subject_type')->nullable();
                    \$table->string('subject_id')->nullable();
                    \$table->timestamps();

                    \$table->index(['tenant_id', 'state'],                          '{$p}_inst_tenant_state_idx');
                    \$table->index(['workflow_definition_id'],                       '{$p}_inst_definition_idx');
                    \$table->index(['tenant_id', 'subject_type', 'subject_id'],     '{$p}_inst_subject_idx');
                    \$table->index(['workflow_definition_id', 'subject_type', 'subject_id'], '{$p}_inst_def_subj_idx');
                });

                Schema::create('{$historiesTable}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->uuid('instance_id');
                    \$table->string('transition_id');
                    \$table->string('action');
                    \$table->string('from_state');
                    \$table->string('to_state');
                    \$table->string('actor')->nullable();
                    \$table->json('payload')->nullable();
                    \$table->timestamps();

                    \$table->foreign('instance_id')->references('instance_id')->on('{$instancesTable}')->cascadeOnDelete();
                    \$table->index(['instance_id'], '{$p}_hist_instance_idx');
                    \$table->index(['created_at'],  '{$p}_hist_created_at_idx');
                });

                Schema::create('{$outboxTable}', function (Blueprint \$table): void {
                    \$table->uuid('id')->primary();
                    \$table->string('event_name');
                    \$table->json('payload');
                    \$table->string('status')->default('pending');
                    \$table->unsignedInteger('attempts')->default(0);
                    \$table->text('last_error')->nullable();
                    \$table->timestamp('dispatched_at')->nullable();
                    \$table->timestamps();

                    \$table->index(['status', 'attempts', 'created_at'], '{$p}_outbox_status_idx');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$outboxTable}');
                Schema::dropIfExists('{$historiesTable}');
                Schema::dropIfExists('{$instancesTable}');
            }
        };
        PHP;
    }

    private function printConfigHint(string $bindingKey): void
    {
        $instancesTable = "workflow_{$bindingKey}_instances";
        $historiesTable = "workflow_{$bindingKey}_histories";
        $outboxTable    = "workflow_{$bindingKey}_outbox";

        $this->newLine();
        $this->line('<comment>Add this to config/workflow.php under \'storage.bindings\':</comment>');
        $this->newLine();
        $this->line("    <fg=cyan>'{$bindingKey}'</> => [");
        $this->line("        'instances_table' => '{$instancesTable}',");
        $this->line("        'histories_table' => '{$historiesTable}',");
        $this->line("        'outbox_table'    => '{$outboxTable}',");
        $this->line('    ],');
        $this->newLine();
    }

    private function validateName(string $name): bool
    {
        if (trim($name) === '') {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name);
    }

    private function toSnakeCase(string $name): string
    {
        $snake = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $name) ?? $name;
        $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake) ?? $snake;

        return strtolower($snake);
    }

    private function toPascalCase(string $name): string
    {
        return str_replace('_', '', ucwords($name, '_'));
    }

    protected function workflowsPath(): string
    {
        if ($this->laravel !== null && method_exists($this->laravel, 'basePath')) {
            return $this->laravel->basePath('workflows');
        }

        return getcwd() . DIRECTORY_SEPARATOR . 'workflows';
    }

    protected function migrationsPath(): string
    {
        if ($this->laravel !== null && method_exists($this->laravel, 'databasePath')) {
            return $this->laravel->databasePath('migrations');
        }

        return getcwd() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }
}
