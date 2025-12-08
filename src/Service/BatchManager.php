<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Service;

use Cake\Queue\QueueManager;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\Job\JobDefinitionFactory;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unified Batch Manager Service
 *
 * Single entry point for creating batches, chains, and sagas with optional compensation.
 * Jobs are pushed to the default queue as regular jobs for perfect Monitor plugin compatibility.
 *
 * Usage:
 * - Simple batch: BatchManager::batch([Job1::class, Job2::class])
 * - Batch with compensation: BatchManager::batch([[Job1::class, Undo1::class], Job2::class])
 * - Sequential chain: BatchManager::chain([Step1::class, Step2::class])
 * - Saga with compensation: BatchManager::chain([[Step1::class, Undo1::class], [Step2::class, Undo2::class]])
 */
class BatchManager
{
    private BatchStorageInterface $storage;
    private ?string $queueName;
    private ?string $queueConfig;

    /**
     * Constructor
     *
     * Supports both old 2-parameter signature (backward compatibility) and new 3-parameter signature
     * Old: new BatchManager($storage, 'batch')
     * New: new BatchManager($storage, null, 'batch')
     *
     * @param \Crustum\BatchQueue\Storage\BatchStorageInterface $storage Storage interface
     * @param string|null $queueNameOrConfig Queue name (new) or queue config (old signature)
     * @param string|null $queueConfig Queue config name (new signature only)
     */
    public function __construct(BatchStorageInterface $storage, ?string $queueNameOrConfig = null, ?string $queueConfig = null)
    {
        $this->storage = $storage;

        if ($queueConfig === null) {
            $this->queueConfig = $queueNameOrConfig;
            $this->queueName = null;
        } else {
            $this->queueConfig = $queueConfig;
            $this->queueName = $queueNameOrConfig;
        }
    }

    /**
     * Create a parallel batch where all jobs run simultaneously
     *
     * Jobs can be:
     * - Simple: ['SendEmailJob::class', 'ProcessOrderJob::class']
     * - With compensation: [['SendEmailJob::class', 'CancelEmailJob::class'], 'ProcessOrderJob::class']
     *
     * @param array $jobs Array of job definitions
     * @return \Crustum\BatchQueue\Service\BatchBuilder
     */
    public function batch(array $jobs): BatchBuilder
    {
        return new BatchBuilder($this->storage, $this->queueName, $this->queueConfig, BatchDefinition::TYPE_PARALLEL, $jobs);
    }

    /**
     * Create a sequential chain where jobs run one after another with context passing
     *
     * Jobs can be:
     * - Simple: ['Step1Job::class', 'Step2Job::class']
     * - With compensation: [['Step1Job::class', 'UndoStep1Job::class'], ['Step2Job::class', 'UndoStep2Job::class']]
     *
     * @param array $jobs Array of job definitions in execution order
     * @return \Crustum\BatchQueue\Service\BatchBuilder
     */
    public function chain(array $jobs): BatchBuilder
    {
        return new BatchBuilder($this->storage, $this->queueName, $this->queueConfig, BatchDefinition::TYPE_SEQUENTIAL, $jobs);
    }

    /**
     * Get batch status and details
     *
     * @param string $batchId Batch identifier
     * @return \Crustum\BatchQueue\Data\BatchDefinition|null
     */
    public function getBatch(string $batchId): ?BatchDefinition
    {
        return $this->storage->getBatch($batchId);
    }

    /**
     * Add jobs to an existing batch
     *
     * @param string $batchId Batch identifier
     * @param array $jobs Job definitions (same format as batch())
     * @return \Crustum\BatchQueue\Data\BatchDefinition Fresh batch instance
     * @throws \RuntimeException If batch not found or addition fails
     */
    public function addJobs(string $batchId, array $jobs): BatchDefinition
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch) {
            throw new RuntimeException(__('Batch not found: {0}', $batchId));
        }

        if (in_array($batch->status, ['completed', 'failed'])) {
            throw new RuntimeException(__('Cannot add jobs to {0} batch: {1}', $batch->status, $batchId));
        }

        $normalizedJobs = $this->normalizeJobsForBatch($batch, $jobs);

        $this->storage->addJobsToBatch($batchId, $normalizedJobs);

        if ($batch->type === BatchDefinition::TYPE_PARALLEL) {
            $freshBatch = $this->storage->getBatch($batchId);
            $newJobs = array_slice($freshBatch->jobs, $batch->totalJobs);
            foreach ($newJobs as $job) {
                BatchDispatcher::queueInnerJob($freshBatch, $job);
            }
        }

        return $this->storage->getBatch($batchId);
    }

    /**
     * Normalize job definitions for adding to existing batch
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Existing batch
     * @param array $jobs Job definitions to normalize
     * @return array Normalized job definitions
     */
    protected function normalizeJobsForBatch(BatchDefinition $batch, array $jobs): array
    {
        $factory = new JobDefinitionFactory();
        $normalized = [];
        $currentTotalJobs = $batch->totalJobs;

        foreach ($jobs as $index => $jobInput) {
            try {
                $jobDefinition = $factory->create($jobInput, $batch->type);
                $position = $currentTotalJobs + $index;
                $jobId = $batch->id . '-' . $position . '-' . uniqid('', true);

                $normalized[] = $jobDefinition->toNormalized($position, $jobId);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    "Invalid job definition at index {$index}: {$e->getMessage()}",
                );
            }
        }

        return $normalized;
    }

    /**
     * Get batch progress information
     *
     * @param string $batchId Batch identifier
     * @return array|null Progress data or null if batch not found
     */
    public function getProgress(string $batchId): ?array
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch) {
            return null;
        }

        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'status' => $batch->status,
            'total_jobs' => $batch->totalJobs,
            'completed_jobs' => $batch->completedJobs,
            'failed_jobs' => $batch->failedJobs,
            'progress_percentage' => $batch->totalJobs > 0
                ? round($batch->completedJobs / $batch->totalJobs * 100, 2)
                : 0,
            'has_compensation' => $batch->hasCompensation(),
            'created' => $batch->created,
            'completed_at' => $batch->completedAt,
        ];
    }

    /**
     * Cancel a pending batch (triggers compensation if applicable)
     *
     * @param string $batchId Batch identifier
     * @return bool True if batch was cancelled
     */
    public function cancelBatch(string $batchId): bool
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch) {
            return false;
        }

        if ($batch->hasCompensation() && $batch->completedJobs > 0) {
            $this->triggerCompensation($batch);
        }

        $this->storage->deleteBatch($batchId);

        return true;
    }

    /**
     * Manually trigger compensation for a batch
     *
     * @param string $batchId Batch identifier
     * @return bool True if compensation was triggered
     */
    public function compensate(string $batchId): bool
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch || !$batch->hasCompensation()) {
            return false;
        }

        return $this->triggerCompensation($batch);
    }

    /**
     * Internal method to trigger compensation jobs
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @return bool True if compensation jobs were queued
     */
    private function triggerCompensation(BatchDefinition $batch): bool
    {
        $compensationJobs = $batch->getJobsWithCompensation();
        if (empty($compensationJobs)) {
            return false;
        }

        $reversedJobs = array_reverse($compensationJobs);

        foreach ($reversedJobs as $job) {
            if ($job['compensation']) {
                $compensationPayload = [
                    'class' => $job['compensation'],
                    'args' => [
                        '_compensation' => [
                            'batch_id' => $batch->id,
                            'original_job_id' => $job['id'],
                            'original_job_class' => $job['class'],
                            'context' => $batch->context,
                        ],
                        ...$batch->context,
                    ],
                ];

                QueueManager::push($this->queueName, $compensationPayload);
            }
        }

        return true;
    }

    /**
     * Get all batches with optional filtering
     *
     * @param array<string, mixed> $filters Filter criteria:
     *   - 'status' => string|null
     *   - 'type' => 'parallel'|'sequential'|null
     *   - 'has_compensation' => bool|null
     *   - 'created_after' => \DateTime|null
     *   - 'created_before' => \DateTime|null
     *   - 'limit' => int
     *   - 'offset' => int
     * @return array<\Crustum\BatchQueue\Data\BatchDefinition> List of batches
     */
    public function getBatches(array $filters = []): array
    {
        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;

        $storageFilters = $filters;
        unset($storageFilters['limit'], $storageFilters['offset']);

        return $this->storage->getBatches($storageFilters, $limit, $offset);
    }

    /**
     * Cleanup old completed/failed batches
     *
     * @param int $olderThanDays Remove batches older than this many days
     * @return int Number of batches cleaned up
     */
    public function cleanup(int $olderThanDays = 7): int
    {
        return $this->storage->cleanupOldBatches($olderThanDays);
    }

    /**
     * Check if storage backend is healthy
     *
     * @return bool
     */
    public function healthCheck(): bool
    {
        return $this->storage->healthCheck();
    }

    /**
     * Get storage backend type
     *
     * @return string
     */
    public function getStorageType(): string
    {
        return $this->storage->getStorageType();
    }
}
