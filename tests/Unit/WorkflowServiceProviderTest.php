<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\FunctionRegistryInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\WorkflowEngineInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\WorkflowManagerInterface;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Events\WorkflowInstanceStarted;
use Daiv05\LaravelWorkflowEngine\Providers\WorkflowServiceProvider;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseOutboxStore;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseWorkflowRepository;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use Daiv05\LaravelWorkflowEngine\Storage\NullOutboxStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

class WorkflowServiceProviderTest extends TestCase
{
    private TestApplication $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new TestApplication(dirname(__DIR__, 2));
    }

    public function test_register_binds_core_contracts_for_memory_driver(): void
    {
        $this->registerProvider([
            'default_driver' => 'memory',
            'outbox' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(FunctionRegistryInterface::class, $this->app->make(FunctionRegistryInterface::class));
        $this->assertInstanceOf(StorageRepositoryInterface::class, $this->app->make(StorageRepositoryInterface::class));
        $this->assertInstanceOf(WorkflowEngineInterface::class, $this->app->make(WorkflowEngineInterface::class));
        $this->assertInstanceOf(WorkflowManagerInterface::class, $this->app->make(WorkflowManagerInterface::class));
        $this->assertInstanceOf(InMemoryWorkflowRepository::class, $this->app->make(StorageRepositoryInterface::class));
        $this->assertInstanceOf(OutboxStoreInterface::class, $this->app->make(OutboxStoreInterface::class));
        $this->assertInstanceOf(NullOutboxStore::class, $this->app->make(OutboxStoreInterface::class));
    }

    public function test_register_selects_database_storage_and_outbox_when_driver_is_database_and_outbox_enabled(): void
    {
        $this->app->instance(ConnectionInterface::class, $this->sqliteConnection());

        $this->registerProvider([
            'default_driver' => 'database',
            'outbox' => ['enabled' => true],
        ]);

        $this->assertInstanceOf(DatabaseWorkflowRepository::class, $this->app->make(StorageRepositoryInterface::class));
        $this->assertInstanceOf(DatabaseOutboxStore::class, $this->app->make(OutboxStoreInterface::class));
    }

    public function test_register_selects_null_outbox_when_database_driver_has_outbox_disabled(): void
    {
        $this->registerProvider([
            'default_driver' => 'database',
            'outbox' => ['enabled' => false],
        ]);

        $this->assertInstanceOf(NullOutboxStore::class, $this->app->make(OutboxStoreInterface::class));
    }

    public function test_workflow_resolution_throws_when_default_tenant_id_is_blank(): void
    {
        $this->registerProvider([
            'default_tenant_id' => '   ',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workflow.default_tenant_id is required and must be a non-empty string.');

        $this->app->make(WorkflowEngine::class);
    }

    public function test_dispatcher_uses_configured_event_prefix_when_laravel_dispatcher_is_bound(): void
    {
        $laravelEvents = $this->createMock(LaravelEventDispatcher::class);
        $laravelEvents->expects($this->once())
            ->method('dispatch')
            ->with(
                'custom.workflow.instance_started',
                $this->callback(static fn (array $payload): bool => ($payload['instance_id'] ?? null) === 'iid-1')
            );

        $this->app->instance('events', $laravelEvents);
        $this->app->instance(LaravelEventDispatcher::class, $laravelEvents);

        $this->registerProvider([
            'events' => ['prefix' => 'custom.workflow.'],
        ]);

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(EventDispatcherInterface::class);
        $dispatcher->queue(new WorkflowInstanceStarted('iid-1', 'flow', 'draft'));
        $dispatcher->flushAfterCommit();
    }

    public function test_boot_registers_publish_targets_for_config_and_migrations(): void
    {
        $provider = $this->registerProvider();
        $provider->boot();

        $configPublishes = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflow-config');
        $migrationPublishes = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflow-migrations');

        $configTarget = $this->publishedTargetForSourceSuffix($configPublishes, '/config/workflow.php');
        $migrationTarget = $this->publishedTargetForSourceSuffix($migrationPublishes, '/database/migrations');

        $this->assertNotNull($configTarget);
        $this->assertSame(str_replace('\\', '/', $this->app->configPath('workflow.php')), $configTarget);

        $this->assertNotNull($migrationTarget);
        $this->assertSame(str_replace('\\', '/', $this->app->databasePath('migrations')), $migrationTarget);
    }

    /**
     * @param array<string, mixed> $workflowOverrides
     */
    private function registerProvider(array $workflowOverrides = []): WorkflowServiceProvider
    {
        $workflowConfig = require dirname(__DIR__, 2) . '/config/workflow.php';
        $workflowConfig = array_replace_recursive($workflowConfig, $workflowOverrides);

        $this->app->instance('config', new ArrayConfigRepository(['workflow' => $workflowConfig]));

        $provider = new WorkflowServiceProvider($this->app);
        $provider->register();

        return $provider;
    }

    private function sqliteConnection(): ConnectionInterface
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return $capsule->getConnection();
    }

    /**
     * @param array<string, string> $publishes
     */
    private function publishedTargetForSourceSuffix(array $publishes, string $suffix): ?string
    {
        $normalizedSuffix = str_replace('\\', '/', $suffix);

        foreach ($publishes as $source => $target) {
            $normalizedSource = str_replace('\\', '/', (string) $source);

            if (str_ends_with($normalizedSource, $normalizedSuffix)) {
                return str_replace('\\', '/', (string) $target);
            }
        }

        return null;
    }
}

class TestApplication extends Container
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    public function configPath(string $path = ''): string
    {
        $base = $this->basePath('config');

        return $path === '' ? $base : $base . '/' . $path;
    }

    public function databasePath(string $path = ''): string
    {
        $base = $this->basePath('database');

        return $path === '' ? $base : $base . '/' . $path;
    }
}

class ArrayConfigRepository
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }
}
