<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Service;

use Cake\Queue\QueueManager;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use RuntimeException;

/**
 * Batch Dispatcher Service
 *
 * Generic service class for starting batches that can be called directly or from jobs.
 * Handles queueing of batch jobs based on batch type (parallel or sequential).
 */
final class BatchDispatcher
{
    /**
     * Dispatch batch by ID
     *
     * @param string $batchId Batch identifier
     * @param \Crustum\BatchQueue\Storage\BatchStorageInterface $storage Batch storage
     * @return void
     * @throws \RuntimeException If batch not found or dispatch fails
     */
    public static function dispatchById(string $batchId, BatchStorageInterface $storage): void
    {
        $batch = $storage->getBatch($batchId);

        if (!$batch) {
            throw new RuntimeException("Batch not found: {$batchId}");
        }

        static::dispatch($batch);
    }

    /**
     * Dispatch batch from BatchDefinition
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @return void
     * @throws \RuntimeException If dispatch fails
     */
    public static function dispatch(BatchDefinition $batch): void
    {
        if ($batch->type === BatchDefinition::TYPE_PARALLEL) {
            static::queueParallelJobs($batch);
        } else {
            static::queueFirstChainJob($batch);
        }
    }

    /**
     * Queue all jobs for parallel batch
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @return void
     */
    protected static function queueParallelJobs(BatchDefinition $batch): void
    {
        foreach ($batch->jobs as $job) {
            static::queueInnerJob($batch, $job);
        }
    }

    /**
     * Queue first job for sequential chain
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @return void
     */
    protected static function queueFirstChainJob(BatchDefinition $batch): void
    {
        $firstJob = null;
        foreach ($batch->jobs as $job) {
            if ($job['position'] === 0) {
                $firstJob = $job;
                break;
            }
        }

        if ($firstJob) {
            static::queueChainJob($batch, $firstJob);
        }
    }

    /**
     * Queue individual chain job to ChainedJobProcessor
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @param array $job Job definition
     * @return void
     */
    protected static function queueChainJob(BatchDefinition $batch, array $job): void
    {
        $jobContext = $batch->context;
        $jobContext = array_merge($jobContext, $job['args'] ?? []);
        $jobContext['batch_id'] = $batch->id;
        $jobContext['job_position'] = $job['position'];
        $jobContext['compensation'] = $job['compensation'] ?? null;

        $queueConfig = $batch->queueConfig ?? QueueConfigService::getQueueConfig('sequential');
        static::queueJob($job['class'], $jobContext, $queueConfig);
    }

    /**
     * Queue individual inner job to default queue
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @param array $job Job definition
     * @param array|null $context Override context
     * @return void
     */
    public static function queueInnerJob(BatchDefinition $batch, array $job, ?array $context = null): void
    {
        $jobContext = $context ?? ($batch->context ?? []);
        $jobContext = is_array($jobContext) ? $jobContext : [];
        $jobContext = array_merge($jobContext, $job['args'] ?? []);

        $jobContext['batch_id'] = $batch->id;
        $jobContext['job_position'] = $job['position'];
        $jobContext['compensation'] = $job['compensation'] ?? null;

        $queueConfig = $batch->queueConfig ?? QueueConfigService::getQueueConfig('parallel');
        static::queueJob($job['class'], $jobContext, $queueConfig);
    }

    /**
     * Queue a job with proper configuration and interface checking
     *
     * @param string $jobClass Job class to queue
     * @param array $args Job arguments
     * @param string $queue Queue configuration name
     * @return void
     */
    protected static function queueJob(string $jobClass, array $args, string $queue = 'default'): void
    {
        $interfaces = class_implements($jobClass);
        if (is_array($interfaces) && in_array('Monitor\Job\DispatchableInterface', $interfaces, true)) {
            call_user_func([$jobClass, 'dispatch'], $args, ['config' => $queue, 'queue' => $queue]);
        } else {
            QueueManager::push($jobClass, $args, ['config' => $queue]);
        }
    }
}
