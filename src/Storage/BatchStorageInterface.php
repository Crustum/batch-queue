<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Storage;

use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Throwable;

/**
 * Batch Storage Interface
 *
 * Defines the contract for batch metadata storage backends.
 * Implementations should support both SQL and Redis storage.
 */
interface BatchStorageInterface
{
    /**
     * Create a new batch record
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition to store
     * @return string
     * @throws \RuntimeException If batch creation fails
     */
    public function createBatch(BatchDefinition $batch): string;

    /**
     * Update batch metadata
     *
     * @param string $batchId Batch identifier
     * @param array<string, mixed> $updates Fields to update
     * @return void
     * @throws \RuntimeException If batch update fails
     */
    public function updateBatch(string $batchId, array $updates): void;

    /**
     * Retrieve batch definition by ID
     *
     * @param string $batchId Batch identifier
     * @return \Crustum\BatchQueue\Data\BatchDefinition|null Batch definition or null if not found
     */
    public function getBatch(string $batchId): ?BatchDefinition;

    /**
     * Mark a job as completed and return whether batch is now complete
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Queue job identifier
     * @param mixed $result Job execution result
     * @return bool True if batch is now complete
     * @throws \RuntimeException If job completion tracking fails
     */
    public function markJobComplete(string $batchId, string $jobId, mixed $result): bool;

    /**
     * Mark a job as completed by database ID and return whether batch is now complete
     *
     * @param string $batchId Batch identifier
     * @param string $dbJobId Database job ID
     * @param mixed $result Job execution result
     * @return bool True if batch is now complete
     * @throws \RuntimeException If job completion tracking fails
     */
    public function markJobCompleteById(string $batchId, string $dbJobId, mixed $result): bool;

    /**
     * Mark a job as failed and return whether batch should fail
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Queue job identifier
     * @param \Throwable $error Job execution error
     * @return bool True if batch should be marked as failed
     * @throws \RuntimeException If job failure tracking fails
     */
    public function markJobFailed(string $batchId, string $jobId, Throwable $error): bool;

    /**
     * Mark a job as failed by database ID and return whether batch should fail
     *
     * @param string $batchId Batch identifier
     * @param string $dbJobId Database job ID
     * @param \Throwable $error Job execution error
     * @return bool True if batch should be marked as failed
     * @throws \RuntimeException If job failure tracking fails
     */
    public function markJobFailedById(string $batchId, string $dbJobId, Throwable $error): bool;

    /**
     * Check if batch is complete (all jobs processed)
     *
     * @param string $batchId Batch identifier
     * @return bool True if batch is complete
     */
    public function isBatchComplete(string $batchId): bool;

    /**
     * Get batch execution progress
     *
     * @param string $batchId Batch identifier
     * @return array<string, int> Progress data (total, completed, failed, pending)
     */
    public function getBatchProgress(string $batchId): array;

    /**
     * Get all job results for a batch
     *
     * @param string $batchId Batch identifier
     * @return array<string, mixed> Job results keyed by job ID
     */
    public function getBatchResults(string $batchId): array;

    /**
     * Get job result by job ID
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Job identifier
     * @return mixed Job result or null if not found
     */
    public function getJobResult(string $batchId, string $jobId): mixed;

    /**
     * Store job result
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Job identifier
     * @param mixed $result Job execution result
     * @return void
     */
    public function storeJobResult(string $batchId, string $jobId, mixed $result): void;

    /**
     * Get failed jobs for a batch
     *
     * @param string $batchId Batch identifier
     * @return array<string, \Crustum\BatchQueue\Data\BatchJobDefinition> Failed jobs keyed by job_id
     */
    public function getFailedJobs(string $batchId): array;

    /**
     * Delete batch and all associated data
     *
     * @param string $batchId Batch identifier
     * @return void
     */
    public function deleteBatch(string $batchId): void;

    /**
     * Get batches by status
     *
     * @param string $status Batch status to filter by
     * @param int $limit Maximum number of batches to return
     * @param int $offset Offset for pagination
     * @return array<\Crustum\BatchQueue\Data\BatchDefinition> Array of batch definitions
     */
    public function getBatchesByStatus(string $status, int $limit = 100, int $offset = 0): array;

    /**
     * Get all jobs for a batch with execution state
     *
     * Supports pagination and status filtering for efficient UI display
     *
     * @param string $batchId Batch ID
     * @param array<string, mixed> $options Options:
     *   - 'status' => string|null Filter by status (pending/running/completed/failed)
     *   - 'limit' => int|null Maximum number of jobs to return (null = all)
     *   - 'offset' => int Offset for pagination (default: 0)
     *   - 'order_by' => string Sort order ('position' (default) or 'created')
     * @return array<int, \Crustum\BatchQueue\Data\BatchJobDefinition> Jobs indexed by position
     */
    public function getAllJobs(string $batchId, array $options = []): array;

    /**
     * Get batches with advanced filtering
     *
     * @param array<string, mixed> $filters Filter criteria:
     *   - 'status' => string|null
     *   - 'type' => 'parallel'|'sequential'|null
     *   - 'has_compensation' => bool|null
     *   - 'created_after' => \DateTime|null
     *   - 'created_before' => \DateTime|null
     * @param int $limit Maximum number of batches
     * @param int $offset Pagination offset
     * @return array<\Crustum\BatchQueue\Data\BatchDefinition>
     */
    public function getBatches(array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Count batches matching filter criteria
     *
     * @param array<string, mixed> $filters Filter criteria (same as getBatches):
     *   - 'status' => string|null
     *   - 'type' => 'parallel'|'sequential'|null
     *   - 'has_compensation' => bool|null
     *   - 'created_after' => \DateTime|null
     *   - 'created_before' => \DateTime|null
     * @return int Number of batches matching filters
     */
    public function countBatches(array $filters = []): int;

    /**
     * Cleanup old completed/failed batches
     *
     * @param int $olderThanDays Remove batches older than this many days
     * @return int Number of batches cleaned up
     */
    public function cleanupOldBatches(int $olderThanDays = 7): int;

    /**
     * Get storage backend type
     *
     * @return string Storage type identifier (sql|redis)
     */
    public function getStorageType(): string;

    /**
     * Health check for storage backend
     *
     * @return bool True if storage is healthy and accessible
     */
    public function healthCheck(): bool;

    /**
     * Increment completed job count
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @return int New completed job count
     */
    public function incrementCompletedJob(string $batchId, string $jobId): int;

    /**
     * Increment failed job count
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @return int New failed job count
     */
    public function incrementFailedJob(string $batchId, string $jobId): int;

    /**
     * Create or update a job record in batch_jobs table
     *
     * @param string $batchId Batch ID
     * @param string $jobId Unique job ID (message_id from headers)
     * @param array<string, mixed> $jobData Job data to store
     * @return void
     * @throws \RuntimeException If job record creation/update fails
     */
    public function createOrUpdateJob(string $batchId, string $jobId, array $jobData): void;

    /**
     * Get job record by job_id
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @return \Crustum\BatchQueue\Data\BatchJobDefinition|null Job definition or null if not found
     */
    public function getJobById(string $batchId, string $jobId): ?BatchJobDefinition;

    /**
     * Update job status and related fields
     *
     * @param string $batchId Batch ID
     * @param string $jobId Unique job ID (message_id from headers)
     * @param string $status New status
     * @param mixed|null $result Job result
     * @param mixed|null $error Job error
     * @return void
     * @throws \RuntimeException If job update fails
     */
    public function updateJobStatus(string $batchId, string $jobId, string $status, mixed $result = null, mixed $error = null): void;

    /**
     * Get job record by batch_id and position
     *
     * @param string $batchId Batch ID
     * @param int $position Job position in batch
     * @return \Crustum\BatchQueue\Data\BatchJobDefinition|null Job definition or null if not found
     */
    public function getJobByPosition(string $batchId, int $position): ?BatchJobDefinition;

    /**
     * Update job_id for tracking
     *
     * @param string $batchId Batch ID
     * @param int $position Job position in batch
     * @param string $jobId Job ID from queue headers
     * @return void
     * @throws \RuntimeException If job update fails
     */
    public function updateJobId(string $batchId, int $position, string $jobId): void;

    /**
     * Add new jobs to an existing batch
     *
     * @param string $batchId Batch identifier
     * @param array<int, array<string, mixed>> $jobs Job definitions
     * @return int Number of jobs added
     * @throws \RuntimeException If batch not found or addition fails
     */
    public function addJobsToBatch(string $batchId, array $jobs): int;
}
