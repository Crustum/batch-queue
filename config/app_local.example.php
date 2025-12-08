<?php
/**
 * BatchQueue Plugin Configuration
 *
 * Copy this file to your app's config/app_local.php and configure as needed.
 */

return [
    'BatchQueue' => [
        // Storage backend: 'sql' or 'redis'
        'storage' => env('BATCH_QUEUE_STORAGE', 'sql'),

        // SQL storage configuration
        'sql' => [
            'connection' => env('BATCH_QUEUE_SQL_CONNECTION', 'default'),
        ],

        // Redis storage configuration
        'redis' => [
            'host' => env('BATCH_QUEUE_REDIS_HOST', '127.0.0.1'),
            'port' => (int)env('BATCH_QUEUE_REDIS_PORT', 6379),
            'timeout' => (float)env('BATCH_QUEUE_REDIS_TIMEOUT', 0.0),
            'read_timeout' => (float)env('BATCH_QUEUE_REDIS_READ_TIMEOUT', 0.0),
            'persistent' => env('BATCH_QUEUE_REDIS_PERSISTENT', false),
            'password' => env('BATCH_QUEUE_REDIS_PASSWORD', null),
            'database' => (int)env('BATCH_QUEUE_REDIS_DATABASE', 0),
            'prefix' => env('BATCH_QUEUE_REDIS_PREFIX', 'batch:'),
            'ttl' => (int)env('BATCH_QUEUE_REDIS_TTL', 86400), // 24 hours
        ],

        // Queue configuration
        'queue' => [
            'name' => env('BATCH_QUEUE_NAME', 'batch'),
        ],

        // Default batch options
        'defaults' => [
            'fail_on_first_error' => false,
            'max_retries' => 3,
            'timeout' => 3600, // 1 hour
        ],

        // Cleanup configuration
        'cleanup' => [
            'enabled' => true,
            'older_than_days' => 7,
            'run_interval' => 'daily', // daily, weekly, monthly
        ],
    ],
];
