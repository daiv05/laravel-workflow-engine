<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Providers;

use Daiv05\LaravelWorkflowEngine\Contracts\DataMapperInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\DiagnosticsEmitterInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\EventDispatcherInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\FunctionRegistryInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\OutboxStoreInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\RuleEngineInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\StorageRepositoryInterface;
use Daiv05\LaravelWorkflowEngine\DSL\Compiler;
use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\DSL\Validator;
use Daiv05\LaravelWorkflowEngine\DataMapping\DataMapper;
use Daiv05\LaravelWorkflowEngine\Engine\StateMachine;
use Daiv05\LaravelWorkflowEngine\Engine\TransitionExecutor;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Events\Dispatcher;
use Daiv05\LaravelWorkflowEngine\Diagnostics\LaravelDiagnosticsEmitter;
use Daiv05\LaravelWorkflowEngine\Diagnostics\NullDiagnosticsEmitter;
use Daiv05\LaravelWorkflowEngine\Fields\FieldEngine;
use Daiv05\LaravelWorkflowEngine\Functions\FunctionRegistry;
use Daiv05\LaravelWorkflowEngine\Functions\SubjectRuleFunctions;
use Daiv05\LaravelWorkflowEngine\Policies\PolicyEngine;
use Daiv05\LaravelWorkflowEngine\Outbox\OutboxProcessor;
use Daiv05\LaravelWorkflowEngine\Rules\RuleEngine;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseOutboxStore;
use Daiv05\LaravelWorkflowEngine\Storage\DatabaseWorkflowRepository;
use Daiv05\LaravelWorkflowEngine\Storage\InMemoryWorkflowRepository;
use Daiv05\LaravelWorkflowEngine\Storage\NullOutboxStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher as LaravelEventDispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/workflow.php', 'workflow');

        $this->app->singleton(FunctionRegistry::class, static function () {
            $registry = new FunctionRegistry();
            $registry->register('subject_type_matches', [SubjectRuleFunctions::class, 'subjectTypeMatches']);
            $registry->register('is_subject_owner', [SubjectRuleFunctions::class, 'isSubjectOwner']);

            return $registry;
        });
        $this->app->alias(FunctionRegistry::class, FunctionRegistryInterface::class);

        $this->app->singleton(Parser::class, static fn () => new Parser());
        $this->app->singleton(Compiler::class, static fn () => new Compiler());

        $this->app->singleton(Validator::class, fn ($app) => new Validator($app->make(FunctionRegistryInterface::class)));

        $this->app->singleton(RuleEngine::class, fn ($app) => new RuleEngine($app->make(FunctionRegistryInterface::class)));
        $this->app->alias(RuleEngine::class, RuleEngineInterface::class);

        $this->app->singleton(PolicyEngine::class, fn ($app) => new PolicyEngine($app->make(RuleEngineInterface::class)));
        $this->app->singleton(FieldEngine::class, fn ($app) => new FieldEngine($app->make(RuleEngineInterface::class)));
        $this->app->singleton(StateMachine::class, static fn () => new StateMachine());

        $this->app->singleton(DataMapper::class, fn ($app) => new DataMapper(
            (array) $app['config']->get('workflow.bindings', []),
            (bool) $app['config']->get('workflow.mappings.fail_silently', false)
        ));
        $this->app->alias(DataMapper::class, DataMapperInterface::class);

        $this->app->singleton(DatabaseOutboxStore::class, fn ($app) => new DatabaseOutboxStore(
            $app->make(ConnectionInterface::class),
            (string) $app['config']->get('workflow.outbox.table', 'workflow_outbox')
        ));
        $this->app->singleton(NullOutboxStore::class, static fn () => new NullOutboxStore());

        $this->app->singleton(OutboxStoreInterface::class, function ($app) {
            $driver = (string) $app['config']->get('workflow.default_driver', 'memory');
            $outboxEnabled = (bool) $app['config']->get('workflow.outbox.enabled', true);

            if ($driver === 'database' && $outboxEnabled) {
                return $app->make(DatabaseOutboxStore::class);
            }

            return $app->make(NullOutboxStore::class);
        });

        $this->app->singleton(Dispatcher::class, fn ($app) => new Dispatcher(
            (string) $app['config']->get('workflow.events.prefix', 'workflow.event.'),
            $app->bound('events') ? $app->make(LaravelEventDispatcher::class) : null,
            $app->make(OutboxStoreInterface::class)
        ));
        $this->app->alias(Dispatcher::class, EventDispatcherInterface::class);

        $this->app->singleton(LaravelDiagnosticsEmitter::class, fn ($app) => new LaravelDiagnosticsEmitter(
            (string) $app['config']->get('workflow.diagnostics.prefix', 'workflow.diagnostic.'),
            $app->bound('events') ? $app->make(LaravelEventDispatcher::class) : null
        ));
        $this->app->singleton(NullDiagnosticsEmitter::class, static fn () => new NullDiagnosticsEmitter());

        $this->app->singleton(DiagnosticsEmitterInterface::class, function ($app) {
            $enabled = (bool) $app['config']->get('workflow.diagnostics.enabled', true);

            if (!$enabled) {
                return $app->make(NullDiagnosticsEmitter::class);
            }

            return $app->make(LaravelDiagnosticsEmitter::class);
        });

        $this->app->singleton(OutboxProcessor::class, fn ($app) => new OutboxProcessor(
            $app->make(OutboxStoreInterface::class),
            $app->bound('events') ? $app->make(LaravelEventDispatcher::class) : null,
            $app->make(DiagnosticsEmitterInterface::class)
        ));

        $this->app->singleton(InMemoryWorkflowRepository::class, static fn () => new InMemoryWorkflowRepository());
        $this->app->singleton(DatabaseWorkflowRepository::class, fn ($app) => new DatabaseWorkflowRepository(
            $app->make(ConnectionInterface::class)
        ));

        $this->app->singleton(StorageRepositoryInterface::class, fn ($app) => match ((string) $app['config']->get('workflow.default_driver', 'memory')) {
            'database' => $app->make(DatabaseWorkflowRepository::class),
            default => $app->make(InMemoryWorkflowRepository::class),
        });

        $this->app->singleton(TransitionExecutor::class, fn ($app) => new TransitionExecutor(
            $app->make(StateMachine::class),
            $app->make(PolicyEngine::class),
            $app->make(StorageRepositoryInterface::class),
            $app->make(EventDispatcherInterface::class),
            $app->make(DiagnosticsEmitterInterface::class),
            (bool) $app['config']->get('workflow.events.fail_silently', false),
            $app->make(DataMapperInterface::class)
        ));

        $this->app->singleton(WorkflowEngine::class, function ($app): WorkflowEngine {
            $defaultTenantId = $app['config']->get('workflow.default_tenant_id');

            if (!is_string($defaultTenantId) || trim($defaultTenantId) === '') {
                throw new \InvalidArgumentException('workflow.default_tenant_id is required and must be a non-empty string.');
            }

            return new WorkflowEngine(
                $app->make(StorageRepositoryInterface::class),
                $app->make(Parser::class),
                $app->make(Validator::class),
                $app->make(Compiler::class),
                $app->make(StateMachine::class),
                $app->make(TransitionExecutor::class),
                $app->make(FieldEngine::class),
                $app->make(PolicyEngine::class),
                $app->make(FunctionRegistry::class),
                $app->bound('cache.store') ? $app->make(CacheRepository::class) : null,
                (bool) $app['config']->get('workflow.cache.enabled', true),
                (int) $app['config']->get('workflow.cache.ttl', 300),
                $app->make(DataMapperInterface::class),
                $defaultTenantId,
                (bool) $app['config']->get('workflow.enforce_one_active_per_subject', false)
            );
        });

        $this->app->singleton('workflow', fn ($app) => $app->make(WorkflowEngine::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $configTarget = method_exists($this->app, 'configPath')
            ? $this->app->configPath('workflow.php')
            : $this->app->basePath('config/workflow.php');

        $this->publishes([
            __DIR__ . '/../../config/workflow.php' => $configTarget,
        ], 'workflow-config');

        $migrationTarget = method_exists($this->app, 'databasePath')
            ? $this->app->databasePath('migrations')
            : $this->app->basePath('database/migrations');

        $this->publishes([
            __DIR__ . '/../../database/migrations' => $migrationTarget,
        ], 'workflow-migrations');
    }
}
