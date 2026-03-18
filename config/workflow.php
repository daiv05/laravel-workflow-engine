<?php

declare(strict_types=1);

return [
    'default_driver' => 'memory',

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

    'diagnostics' => [
        'enabled' => true,
        'prefix' => 'workflow.diagnostic.',
    ],

    'multi_tenant' => false,
];
