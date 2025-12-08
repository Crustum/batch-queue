<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data;

use Cake\I18n\DateTime;
use InvalidArgumentException;

/**
 * Batch Job Definition - Storage-independent DTO for batch job data
 *
 * Represents a single job within a batch with all necessary metadata.
 * Used for storage abstraction between SQL and Redis implementations.
 */
final class BatchJobDefinition
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Database job ID (UUID)
     *
     * @var string
     */
    public string $id;

    /**
     * Batch identifier
     *
     * @var string
     */
    public string $batchId;

    /**
     * Unique job identifier (message_id from queue)
     *
     * @var string
     */
    public string $jobId;

    /**
     * Job position in batch
     *
     * @var int
     */
    public int $position;

    /**
     * Job status
     *
     * @var string
     */
    public string $status;

    /**
     * Job payload data
     *
     * @var array
     */
    public array $payload;

    /**
     * Job execution result
     *
     * @var mixed
     */
    public mixed $result;

    /**
     * Job error details
     *
     * @var array|null
     */
    public ?array $error;

    /**
     * Creation timestamp
     *
     * @var \Cake\I18n\DateTime|null
     */
    public ?DateTime $created;

    /**
     * Last modification timestamp
     *
     * @var \Cake\I18n\DateTime|null
     */
    public ?DateTime $modified;

    /**
     * Completion timestamp
     *
     * @var \Cake\I18n\DateTime|null
     */
    public ?DateTime $completedAt;

    /**
     * Constructor
     *
     * @param string $id Database job ID (UUID)
     * @param string $batchId Batch identifier
     * @param string $jobId Unique job identifier (message_id from queue)
     * @param int $position Job position in batch
     * @param string $status Job status
     * @param array $payload Job payload data
     * @param mixed $result Job execution result
     * @param array|null $error Job error details
     * @param \Cake\I18n\DateTime|null $created Creation timestamp
     * @param \Cake\I18n\DateTime|null $modified Last modification timestamp
     * @param \Cake\I18n\DateTime|null $completedAt Completion timestamp
     */
    public function __construct(
        string $id,
        string $batchId,
        string $jobId,
        int $position,
        string $status,
        array $payload,
        mixed $result = null,
        ?array $error = null,
        ?DateTime $created = null,
        ?DateTime $modified = null,
        ?DateTime $completedAt = null,
    ) {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
            throw new InvalidArgumentException("Invalid job status: {$status}");
        }

        if ($position < 0) {
            throw new InvalidArgumentException("Job position must be >= 0, got: {$position}");
        }

        $this->id = $id;
        $this->batchId = $batchId;
        $this->jobId = $jobId;
        $this->position = $position;
        $this->status = $status;
        $this->payload = $payload;
        $this->result = $result;
        $this->error = $error;
        $this->created = $created;
        $this->modified = $modified;
        $this->completedAt = $completedAt;
    }

    /**
     * Check if job is completed
     *
     * @return bool True if job status is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if job has failed
     *
     * @return bool True if job status is failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if job is pending
     *
     * @return bool True if job status is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if job is running
     *
     * @return bool True if job status is running
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Convert to array for storage
     *
     * @return array<string, mixed> Job data
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batchId,
            'job_id' => $this->jobId,
            'position' => $this->position,
            'status' => $this->status,
            'payload' => $this->payload,
            'result' => $this->result,
            'error' => $this->error,
            'created' => $this->created?->format('Y-m-d H:i:s'),
            'modified' => $this->modified?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Create from array data
     *
     * @param array<string, mixed> $data Job data
     * @return static Batch job definition
     */
    public static function fromArray(array $data): static
    {
        $created = null;
        if (isset($data['created'])) {
            $created = is_string($data['created']) ? new DateTime($data['created']) : $data['created'];
        }

        $modified = null;
        if (isset($data['modified'])) {
            $modified = is_string($data['modified']) ? new DateTime($data['modified']) : $data['modified'];
        }

        $completedAt = null;
        if (isset($data['completed_at'])) {
            $completedAt = is_string($data['completed_at']) ? new DateTime($data['completed_at']) : $data['completed_at'];
        }

        $payload = $data['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $error = $data['error'] ?? null;
        if ($error !== null) {
            if (is_string($error)) {
                $decoded = json_decode($error, true);
                $error = is_array($decoded) ? $decoded : ['message' => $error];
            } elseif (!is_array($error)) {
                $error = ['message' => (string)$error];
            }
        }

        return new static(
            id: $data['id'],
            batchId: $data['batch_id'],
            jobId: $data['job_id'],
            position: (int)$data['position'],
            status: $data['status'],
            payload: $payload,
            result: $data['result'] ?? null,
            error: $error,
            created: $created,
            modified: $modified,
            completedAt: $completedAt,
        );
    }
}
