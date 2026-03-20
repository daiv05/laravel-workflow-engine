<?php

declare(strict_types=1);

return [
    'default_driver' => 'memory',

    'default_tenant_id' => 'tenant-default',

    'storage' => [
        'definitions_table' => 'workflow_definitions',
        'instances_table' => 'workflow_instances',
        'histories_table' => 'workflow_histories',
    ],

    'cache' => [
        'enabled' => true,
        'ttl' => 300,
    ],

    'outbox' => [
        'enabled' => true,
        'table' => 'workflow_outbox',
        'dispatch_batch' => 50,
        'max_attempts' => 5,
    ],

    'events' => [
        'prefix' => 'workflow.event.',
        'fail_silently' => false,
    ],

    'mappings' => [
        'fail_silently' => false,
    ],

    'bindings' => [
        // 'documents' => [
        //     'handler' => App\Workflow\Mappers\DocumentMapper::class,
        //     'query_handler' => App\Workflow\Mappers\DocumentMapper::class,
        // ],
    ],

    'diagnostics' => [
        'enabled' => true,
        'prefix' => 'workflow.diagnostic.',
    ],

    'enforce_one_active_per_subject' => false,

    // Global toggle reserved for the future multi-tenant feature rollout.
    'multi_tenant' => false,
];
