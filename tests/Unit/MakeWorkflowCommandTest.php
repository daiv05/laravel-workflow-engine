<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Console\Commands\MakeWorkflowCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MakeWorkflowCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/wf_cmd_test_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows',               0755, true);
        mkdir($this->tempDir . '/database/migrations',    0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // YAML (default)
    // -------------------------------------------------------------------------

    public function test_generates_yaml_stub_by_default(): void
    {
        $output = $this->run(['name' => 'OrderApproval']);

        $file = $this->tempDir . '/workflows/OrderApproval.yaml';

        $this->assertFileExists($file);
        $this->assertStringContainsString('dsl_version: 2',   file_get_contents($file));
        $this->assertStringContainsString('name: order_approval', file_get_contents($file));
        $this->assertStringContainsString('[OK]', $output);
    }

    public function test_yaml_stub_contains_correct_name_and_binding(): void
    {
        $this->run(['name' => 'OrderApproval']);

        $content = file_get_contents($this->tempDir . '/workflows/OrderApproval.yaml');

        $this->assertStringContainsString('name: order_approval',       $content);
        $this->assertStringContainsString('binding: order_approval',    $content);
        $this->assertStringContainsString('initial_state: draft',       $content);
        $this->assertStringContainsString('transition_id: tr_approve',  $content);
        $this->assertStringContainsString('transition_id: tr_reject',   $content);
    }

    // -------------------------------------------------------------------------
    // JSON format
    // -------------------------------------------------------------------------

    public function test_generates_json_stub_with_format_option(): void
    {
        $this->run(['name' => 'OrderApproval', '--format' => 'json']);

        $file = $this->tempDir . '/workflows/OrderApproval.json';

        $this->assertFileExists($file);

        $decoded = json_decode(file_get_contents($file), true);

        $this->assertIsArray($decoded);
        $this->assertSame(2,               $decoded['dsl_version']);
        $this->assertSame('order_approval', $decoded['name']);
        $this->assertSame('order_approval', $decoded['storage']['binding']);
        $this->assertSame('draft',          $decoded['initial_state']);
    }

    // -------------------------------------------------------------------------
    // PHP format
    // -------------------------------------------------------------------------

    public function test_generates_php_stub_with_format_option(): void
    {
        $this->run(['name' => 'OrderApproval', '--format' => 'php']);

        $file = $this->tempDir . '/workflows/OrderApproval.php';

        $this->assertFileExists($file);

        $data = require $file;

        $this->assertIsArray($data);
        $this->assertSame(2,               $data['dsl_version']);
        $this->assertSame('order_approval', $data['name']);
        $this->assertSame('order_approval', $data['storage']['binding']);
        $this->assertSame('draft',          $data['initial_state']);
        $this->assertArrayHasKey('transitions', $data);
    }

    // -------------------------------------------------------------------------
    // Snake-case name input
    // -------------------------------------------------------------------------

    public function test_accepts_snake_case_name(): void
    {
        $this->run(['name' => 'order_approval']);

        $file = $this->tempDir . '/workflows/OrderApproval.yaml';

        $this->assertFileExists($file);
        $this->assertStringContainsString('name: order_approval', file_get_contents($file));
    }

    // -------------------------------------------------------------------------
    // Migration
    // -------------------------------------------------------------------------

    public function test_no_migration_generated_without_flag(): void
    {
        $this->run(['name' => 'OrderApproval']);

        $files = glob($this->tempDir . '/database/migrations/*.php');

        $this->assertCount(0, $files);
    }

    public function test_generates_migration_with_flag(): void
    {
        $this->run(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files = glob($this->tempDir . '/database/migrations/*.php');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('create_workflow_order_approval_tables', basename($files[0]));
    }

    public function test_migration_contains_all_three_tables(): void
    {
        $this->run(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString('workflow_order_approval_instances', $content);
        $this->assertStringContainsString('workflow_order_approval_histories', $content);
        $this->assertStringContainsString('workflow_order_approval_outbox',    $content);
    }

    public function test_migration_down_drops_all_three_tables(): void
    {
        $this->run(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $dropCount = substr_count($content, 'dropIfExists');

        $this->assertSame(3, $dropCount);
    }

    // -------------------------------------------------------------------------
    // Config hint
    // -------------------------------------------------------------------------

    public function test_always_prints_config_hint(): void
    {
        $output = $this->run(['name' => 'OrderApproval']);

        $this->assertStringContainsString("'order_approval'",                          $output);
        $this->assertStringContainsString("'instances_table'",                         $output);
        $this->assertStringContainsString('workflow_order_approval_instances',         $output);
        $this->assertStringContainsString('workflow_order_approval_histories',         $output);
        $this->assertStringContainsString('workflow_order_approval_outbox',            $output);
    }

    // -------------------------------------------------------------------------
    // Validation: invalid inputs
    // -------------------------------------------------------------------------

    public function test_rejects_empty_name(): void
    {
        $exitCode = $this->runRaw(['name' => '']);

        $this->assertSame(1, $exitCode);
    }

    public function test_rejects_invalid_characters(): void
    {
        $exitCode = $this->runRaw(['name' => 'Order Approval!']);

        $this->assertSame(1, $exitCode);
    }

    public function test_rejects_invalid_format(): void
    {
        $exitCode = $this->runRaw(['name' => 'OrderApproval', '--format' => 'csv']);

        $this->assertSame(1, $exitCode);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function run(array $args): string
    {
        $output = new BufferedOutput();
        $this->runRaw($args, $output);

        return $output->fetch();
    }

    private function runRaw(array $args, ?BufferedOutput $output = null): int
    {
        $output ??= new BufferedOutput();

        $command = $this->buildCommand();
        $input   = new ArrayInput(array_merge(['command' => 'workflow:make'], $args));
        $input->setInteractive(false);

        return $command->run($input, $output);
    }

    private function buildCommand(): MakeWorkflowCommand
    {
        $command = new class ($this->tempDir) extends MakeWorkflowCommand {
            public function __construct(private readonly string $base)
            {
                parent::__construct();
            }

            protected function workflowsPath(): string
            {
                return $this->base . DIRECTORY_SEPARATOR . 'workflows';
            }

            protected function migrationsPath(): string
            {
                return $this->base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
            }
        };

        $app = new Application();
        $app->add($command);
        $app->setAutoExit(false);

        return $command;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
