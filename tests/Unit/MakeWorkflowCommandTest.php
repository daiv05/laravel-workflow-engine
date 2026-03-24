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
        $output = $this->runCommand(['name' => 'OrderApproval']);

        $file = $this->tempDir . '/workflows/OrderApproval.yaml';

        $this->assertFileExists($file);
        $this->assertStringContainsString('dsl_version: 2',   file_get_contents($file));
        $this->assertStringContainsString('name: order_approval', file_get_contents($file));
        $this->assertStringContainsString('[OK]', $output);
    }

    public function test_yaml_stub_contains_correct_name_and_binding(): void
    {
        $this->runCommand(['name' => 'OrderApproval']);

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
        $this->runCommand(['name' => 'OrderApproval', '--format' => 'json']);

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
        $this->runCommand(['name' => 'OrderApproval', '--format' => 'php']);

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
        $this->runCommand(['name' => 'order_approval']);

        $file = $this->tempDir . '/workflows/OrderApproval.yaml';

        $this->assertFileExists($file);
        $this->assertStringContainsString('name: order_approval', file_get_contents($file));
    }

    // -------------------------------------------------------------------------
    // Migration
    // -------------------------------------------------------------------------

    public function test_no_migration_generated_without_flag(): void
    {
        $this->runCommand(['name' => 'OrderApproval']);

        $files = glob($this->tempDir . '/database/migrations/*.php');

        $this->assertCount(0, $files);
    }

    public function test_generates_migration_with_flag(): void
    {
        $this->runCommand(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files = glob($this->tempDir . '/database/migrations/*.php');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('create_wk_order_approval_tables', basename($files[0]));
    }

    public function test_migration_contains_all_three_tables(): void
    {
        $this->runCommand(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString('wk_order_approval_instances', $content);
        $this->assertStringContainsString('wk_order_approval_histories', $content);
        $this->assertStringContainsString('wk_order_approval_outbox',    $content);
    }

    public function test_migration_down_drops_all_three_tables(): void
    {
        $this->runCommand(['name' => 'OrderApproval', '--with-migrations' => true]);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $dropCount = substr_count($content, 'dropIfExists');

        $this->assertSame(3, $dropCount);
    }

    public function test_generates_migration_with_custom_prefix(): void
    {
        $this->runCommand(['name' => 'OrderApproval', '--with-migrations' => true, '--prefix' => 'my_app_']);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString('create_my_app_order_approval_tables', basename($files[0]));
        $this->assertStringContainsString('my_app_order_approval_instances', $content);
    }

    public function test_generates_migration_with_empty_prefix(): void
    {
        $this->runCommand(['name' => 'OrderApproval', '--with-migrations' => true, '--prefix' => '']);

        $files   = glob($this->tempDir . '/database/migrations/*.php');
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString('create_order_approval_tables', basename($files[0]));
        $this->assertStringContainsString('order_approval_instances', $content);
    }

    // -------------------------------------------------------------------------
    // Config hint
    // -------------------------------------------------------------------------

    public function test_always_prints_config_hint(): void
    {
        $output = $this->runCommand(['name' => 'OrderApproval']);

        $this->assertStringContainsString("'order_approval'",                          $output);
        $this->assertStringContainsString("'instances_table'",                         $output);
        $this->assertStringContainsString('wk_order_approval_instances',               $output);
        $this->assertStringContainsString('wk_order_approval_histories',               $output);
        $this->assertStringContainsString('wk_order_approval_outbox',                  $output);
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

    private function runCommand(array $args): string
    {
        $command = $this->buildCommand($args);
        $command->handle();

        return $command->getOutputBuffer();
    }

    private function runRaw(array $args): int
    {
        $command = $this->buildCommand($args);

        return $command->handle();
    }

    private function buildCommand(array $args = []): MakeWorkflowCommand
    {
        return new class ($this->tempDir, $args) extends MakeWorkflowCommand {
            private string $outputBuffer = '';

            public function __construct(private readonly string $base, private readonly array $args)
            {
                parent::__construct();
            }

            public function getOutputBuffer(): string
            {
                return $this->outputBuffer;
            }

            public function argument($key = null)
            {
                if ($key === null) {
                    return $this->args; // Simplify
                }

                return $this->args[$key] ?? null;
            }

            public function option($key = null)
            {
                if ($key === null) {
                    return [];
                }

                // If explicitly passed
                if (array_key_exists('--' . $key, $this->args)) {
                    return $this->args['--' . $key];
                }

                // Fallbacks from signature defaults
                if ($key === 'format') {
                    return 'yaml';
                }

                if ($key === 'with-migrations') {
                    return false;
                }

                if ($key === 'prefix') {
                    return 'wk_';
                }

                return null;
            }

            public function line($string, $style = null, $verbosity = null)
            {
                $this->outputBuffer .= strip_tags((string) $string) . "\n";
            }

            public function error($string, $verbosity = null)
            {
                $this->outputBuffer .= strip_tags((string) $string) . "\n";
            }

            public function newLine($count = 1)
            {
                $this->outputBuffer .= str_repeat("\n", $count);
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
