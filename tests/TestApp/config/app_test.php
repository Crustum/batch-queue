<?php
/**
 * Test Configuration for BatchQueue Plugin
 * Database transport configuration using CakephpEnqueue for real queue processing in tests
 */

return [
    'Queue' => [
        'default' => [
            'url' => 'cakephp://test?table_name=enqueue',
            'receiveTimeout' => 1,
        ],
        'batch' => [
            'url' => 'cakephp://test?table_name=enqueue',
            'receiveTimeout' => 1,
        ],
        'batchjob' => [
            'url' => 'cakephp://test?table_name=enqueue',
            'receiveTimeout' => 1,
        ],
        'chainedjobs' => [
            'url' => 'cakephp://test?table_name=enqueue',
            'receiveTimeout' => 1,
        ],
    ],
    'BatchQueue' => [
        'storage' => 'sql',
        'cleanup' => [
            'enabled' => false,
        ],
    ],
];
