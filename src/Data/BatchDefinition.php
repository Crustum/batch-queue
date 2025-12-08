<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data;

use Cake\I18n\DateTime;
use Crustum\BatchQueue\Data\Job\JobDefinitionFactory;
use Crustum\BatchQueue\Model\Entity\BatchJob;
use InvalidArgumentException;

/**
 * Batch Definition - Unified system supporting both simple batches and compensation patterns
 *
 * Jobs can be defined as:
 * - Simple: ['SendEmailJob::class']
 * - With compensation: [['SendEmailJob::class', 'CancelEmailJob::class']]
 * - Mixed: ['SendEmailJob::class', ['ProcessOrderJob::class', 'RefundOrderJob::class']]
 */
final class BatchDefinition
{
    public const TYPE_PARALLEL = 'parallel';
    public const TYPE_SEQUENTIAL = 'sequential';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public string $id;
    public string $type;
    public array $jobs;
    public array $context;
    public array $options;
    public string $status;
    public int $totalJobs;
    public int $completedJobs;
    public int $failedJobs;
    public ?DateTime $created;
    public ?DateTime $modified;
    public ?DateTime $completedAt;
    public ?string $queueName;
    public ?string $queueConfig;

    /**
     * Constructor
     *
     * @param string $id Batch identifier
     * @param string $type Batch type (parallel or sequential)
     * @param array $jobs Job definitions
     * @param array $context Batch context data
     * @param array $options Batch options
     * @param string|null $queueName Queue name
     * @param string|null $queueConfig Queue configuration name
     * @param string $status Batch status
     * @param int $completedJobs Number of completed jobs
     * @param int $failedJobs Number of failed jobs
     * @param \Cake\I18n\DateTime|null $created Creation timestamp
     * @param \Cake\I18n\DateTime|null $modified Last modification timestamp
     * @param \Cake\I18n\DateTime|null $completedAt Completion timestamp
     */
    public function __construct(
        string $id,
        string $type,
        array $jobs,
        array $context = [],
        array $options = [],
        ?string $queueName = null,
        ?string $queueConfig = null,
        string $status = self::STATUS_PENDING,
        int $completedJobs = 0,
        int $failedJobs = 0,
        ?DateTime $created = null,
        ?DateTime $modified = null,
        ?DateTime $completedAt = null,
    ) {
        $this->completedJobs = $completedJobs;
        $this->failedJobs = $failedJobs;
        $this->created = $created;
        $this->modified = $modified;
        $this->completedAt = $completedAt;
        $this->id = $id;
        $this->type = $type;

        $jobsAreLoaded = static::areJobsLoadedFromStorage($jobs);
        if ($jobsAreLoaded) {
            $this->jobs = $jobs;
            $this->totalJobs = count($this->jobs);
        } else {
            $this->validateJobs($jobs);
            $this->jobs = $this->normalizeJobs($jobs);
            $this->totalJobs = count($this->jobs);
        }

        $this->context = $context;
        $this->options = $options;
        $this->queueName = $queueName;
        $this->queueConfig = $queueConfig;
        $this->status = $status;
    }

    /**
     * Normalize job definitions to consistent format
     *
     * @param array $jobs Job definitions
     * @return array Normalized jobs
     */
    private function normalizeJobs(array $jobs): array
    {
        $normalized = [];
        $factory = new JobDefinitionFactory();

        foreach ($jobs as $index => $jobInput) {
            try {
                $jobDefinition = $factory->create($jobInput, $this->type);

                $jobId = $jobInput instanceof BatchJob
                    ? $jobInput->id
                    : $this->generateJobId($index);

                $normalized[] = $jobDefinition->toNormalized($index, $jobId);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    "Invalid job definition at index {$index}: {$e->getMessage()}",
                );
            }
        }

        return $normalized;
    }

    /**
     * Generate unique job ID within batch
     *
     * @param int $index Job index
     * @return string Job ID
     */
    private function generateJobId(int $index): string
    {
        return $this->id . '-' . $index . '-' . uniqid('', true);
    }

    /**
     * Get job by ID
     *
     * @param string $jobId Job identifier
     * @return array|null Job definition or null if not found
     */
    public function getJob(string $jobId): ?array
    {
        foreach ($this->jobs as $job) {
            if ($job['id'] === $jobId) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Get jobs that have compensation defined
     *
     * @return array Jobs with compensation
     */
    public function getJobsWithCompensation(): array
    {
        return array_filter($this->jobs, fn($job) => $job['compensation'] !== null);
    }

    /**
     * Check if batch uses compensation pattern
     *
     * @return bool True if any job has compensation
     */
    public function hasCompensation(): bool
    {
        return !empty($this->getJobsWithCompensation());
    }

    /**
     * Get next job in sequential chain
     *
     * @param int $currentPosition Current position
     * @return array|null Next job or null if at end
     */
    public function getNextSequentialJob(int $currentPosition): ?array
    {
        $nextPosition = $currentPosition + 1;

        foreach ($this->jobs as $job) {
            if ($job['position'] === $nextPosition) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Check if batch is complete
     *
     * @return bool True if all jobs are completed
     */
    public function isComplete(): bool
    {
        return $this->completedJobs >= $this->totalJobs;
    }

    /**
     * Check if batch has failed
     *
     * @return bool True if any job has failed
     */
    public function hasFailed(): bool
    {
        return $this->failedJobs > 0;
    }

    /**
     * Mark batch as completed
     *
     * @return void
     */
    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new DateTime();
    }

    /**
     * Mark batch as failed
     *
     * @return void
     */
    public function markFailed(): void
    {
        $this->status = self::STATUS_FAILED;
        $this->completedAt = new DateTime();
    }

    /**
     * Validate job definitions
     *
     * @param array $jobs Job definitions
     * @throws \InvalidArgumentException
     */
    private function validateJobs(array $jobs): void
    {
        if (empty($jobs)) {
            throw new InvalidArgumentException('Batch must contain at least one job');
        }

        $factory = new JobDefinitionFactory();

        foreach ($jobs as $index => $jobInput) {
            try {
                $factory->create($jobInput, $this->type);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    "Invalid job definition at index {$index}: {$e->getMessage()}",
                );
            }
        }
    }

    /**
     * Convert to array for storage
     *
     * @return array Batch data
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'jobs' => $this->jobs,
            'context' => $this->context,
            'options' => $this->options,
            'status' => $this->status,
            'total_jobs' => $this->totalJobs,
            'completed_jobs' => $this->completedJobs,
            'failed_jobs' => $this->failedJobs,
            'queue_name' => $this->queueName,
            'queue_config' => $this->queueConfig,
            'created' => $this->created?->format('Y-m-d H:i:s'),
            'modified' => $this->modified?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Create from array data
     *
     * @param array $data Batch data
     * @return static Batch definition
     */
    public static function fromArray(array $data): static
    {
        $batch = new static(
            id: $data['id'],
            type: $data['type'],
            jobs: $data['jobs'],
            context: $data['context'] ?? [],
            options: $data['options'] ?? [],
            queueName: $data['queue_name'] ?? null,
            queueConfig: $data['queue_config'] ?? null,
        );

        $batch->status = $data['status'] ?? self::STATUS_PENDING;
        $batch->completedJobs = $data['completed_jobs'] ?? 0;
        $batch->failedJobs = $data['failed_jobs'] ?? 0;

        if (isset($data['created'])) {
            $batch->created = is_string($data['created']) ? new DateTime($data['created']) : $data['created'];
        }

        if (isset($data['modified'])) {
            $batch->modified = is_string($data['modified']) ? new DateTime($data['modified']) : $data['modified'];
        }

        if (isset($data['completed_at'])) {
            $batch->completedAt = is_string($data['completed_at']) ? new DateTime($data['completed_at']) : $data['completed_at'];
        }

        return $batch;
    }

    /**
     * Check if jobs array contains already-loaded job data from storage
     *
     * Jobs loaded from storage will have BatchJobDefinition structure:
     * ['id', 'batch_id', 'job_id', 'position', 'status', 'payload', 'result', 'error', ...]
     *
     * User input jobs will have simpler structure:
     * ['class', 'compensation', 'args'] or just string 'MyJob::class'
     *
     * @param array $jobs Jobs array
     * @return bool True if jobs appear to be loaded from storage
     */
    private static function areJobsLoadedFromStorage(array $jobs): bool
    {
        if (empty($jobs)) {
            return false;
        }

        $firstJob = reset($jobs);
        if (!is_array($firstJob)) {
            return false;
        }

        $loadedJobKeys = ['batch_id', 'job_id', 'status', 'position'];
        $hasLoadedKeys = count(array_intersect_key($firstJob, array_flip($loadedJobKeys))) === count($loadedJobKeys);

        return $hasLoadedKeys;
    }
}
