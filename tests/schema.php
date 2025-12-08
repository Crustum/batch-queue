<?php
declare(strict_types=1);

/**
 * Test database schema for BatchQueue plugin tests.
 *
 * This format resembles the existing fixture schema
 * and is converted to SQL via the Schema generation
 * features of the Database package.
 */
return [
    'batches' => [
        'columns' => [
            'id' => ['type' => 'uuid', 'null' => false],
            'type' => ['type' => 'string', 'length' => 20, 'null' => false, 'default' => 'parallel'],
            'status' => ['type' => 'string', 'length' => 20, 'null' => false, 'default' => 'pending'],
            'total_jobs' => ['type' => 'integer', 'null' => false, 'default' => 0],
            'completed_jobs' => ['type' => 'integer', 'null' => false, 'default' => 0],
            'failed_jobs' => ['type' => 'integer', 'null' => false, 'default' => 0],
            'context' => ['type' => 'json', 'null' => true, 'default' => null],
            'options' => ['type' => 'json', 'null' => true, 'default' => null],
            'queue_name' => ['type' => 'string', 'length' => 100, 'null' => true, 'default' => null],
            'queue_config' => ['type' => 'string', 'length' => 100, 'null' => true, 'default' => null],
            'created' => ['type' => 'datetime', 'null' => false],
            'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'completed_at' => ['type' => 'datetime', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ],
    'batch_jobs' => [
        'columns' => [
            'id' => ['type' => 'uuid', 'null' => false],
            'batch_id' => ['type' => 'uuid', 'null' => false],
            'job_id' => ['type' => 'string', 'length' => 255, 'null' => false],
            'position' => ['type' => 'integer', 'null' => false, 'default' => 0],
            'status' => ['type' => 'string', 'length' => 20, 'null' => false, 'default' => 'pending'],
            'payload' => ['type' => 'json', 'null' => false],
            'result' => ['type' => 'json', 'null' => true, 'default' => null],
            'error' => ['type' => 'json', 'null' => true, 'default' => null],
            'created' => ['type' => 'datetime', 'null' => false],
            'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'completed_at' => ['type' => 'datetime', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
            'batches_batch_id_fk' => [
                'type' => 'foreign',
                'columns' => ['batch_id'],
                'references' => ['batches', 'id'],
                'update' => 'restrict',
                'delete' => 'cascade',
            ],
        ],
        'indexes' => [
            'idx_batch_jobs_batch_id' => ['type' => 'index', 'columns' => ['batch_id']],
            'idx_batch_jobs_status' => ['type' => 'index', 'columns' => ['status']],
            'idx_batch_jobs_batch_status' => ['type' => 'index', 'columns' => ['batch_id', 'status']],
            'idx_batch_jobs_batch_position' => ['type' => 'index', 'columns' => ['batch_id', 'position']],
            'idx_batch_jobs_batch_job_id' => ['type' => 'index', 'columns' => ['batch_id', 'job_id'], 'unique' => true],
        ],
    ],
    'enqueue' => [
        'columns' => [
            'id' => ['type' => 'uuid', 'null' => false],
            'published_at' => ['type' => 'biginteger', 'null' => true, 'default' => null],
            'body' => ['type' => 'text', 'null' => true, 'default' => null],
            'headers' => ['type' => 'text', 'null' => true, 'default' => null],
            'properties' => ['type' => 'text', 'null' => true, 'default' => null],
            'redelivered' => ['type' => 'boolean', 'null' => true, 'default' => false],
            'queue' => ['type' => 'string', 'length' => 255, 'null' => true, 'default' => null],
            'priority' => ['type' => 'integer', 'null' => true, 'default' => null],
            'delayed_until' => ['type' => 'integer', 'null' => true, 'default' => null],
            'time_to_live' => ['type' => 'integer', 'null' => true, 'default' => null],
            'delivery_id' => ['type' => 'string', 'length' => 36, 'null' => true, 'default' => null],
            'redeliver_after' => ['type' => 'integer', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
        'indexes' => [
            'idx_enqueue_queue' => ['type' => 'index', 'columns' => ['queue']],
        ],
    ],
];
