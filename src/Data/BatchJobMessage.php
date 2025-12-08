<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data;

use JsonSerializable;

/**
 * Batch Job Message Data Class
 *
 * Represents a job within a batch with all necessary metadata for processing.
 * Optimized for queue message serialization with short field names.
 */
final class BatchJobMessage implements JsonSerializable
{
    /**
     * @param string $batchId Unique batch identifier
     * @param string $jobId Unique job identifier within batch
     * @param string $type Batch execution type (parallel|sequential)
     * @param array<string, mixed> $jobData Actual job payload data
     * @param array<string, mixed> $context Shared batch context
     * @param array<int, array<string, mixed>>|null $remainingChain Remaining jobs for sequential execution
     * @param int $position Job position in sequential chain
     */
    public function __construct(
        public string $batchId,
        public string $jobId,
        public string $type,
        public array $jobData,
        public array $context = [],
        public ?array $remainingChain = null,
        public int $position = 0,
    ) {
    }

    /**
     * Create a parallel job message
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Job identifier
     * @param array<string, mixed> $jobData Job payload
     * @param array<string, mixed> $context Batch context
     * @return static
     */
    public static function createParallel(
        string $batchId,
        string $jobId,
        array $jobData,
        array $context = [],
    ): static {
        return new static(
            batchId: $batchId,
            jobId: $jobId,
            type: BatchDefinition::TYPE_PARALLEL,
            jobData: $jobData,
            context: $context,
        );
    }

    /**
     * Create a sequential job message
     *
     * @param string $batchId Batch identifier
     * @param string $jobId Job identifier
     * @param array<string, mixed> $jobData Job payload
     * @param array<string, mixed> $context Batch context
     * @param array<int, array<string, mixed>> $remainingChain Remaining jobs in chain
     * @param int $position Current position in chain
     * @return static
     */
    public static function createSequential(
        string $batchId,
        string $jobId,
        array $jobData,
        array $context = [],
        array $remainingChain = [],
        int $position = 0,
    ): static {
        return new static(
            batchId: $batchId,
            jobId: $jobId,
            type: BatchDefinition::TYPE_SEQUENTIAL,
            jobData: $jobData,
            context: $context,
            remainingChain: $remainingChain,
            position: $position,
        );
    }

    /**
     * Check if this is a parallel job
     *
     * @return bool
     */
    public function isParallel(): bool
    {
        return $this->type === BatchDefinition::TYPE_PARALLEL;
    }

    /**
     * Check if this is a sequential job
     *
     * @return bool
     */
    public function isSequential(): bool
    {
        return $this->type === BatchDefinition::TYPE_SEQUENTIAL;
    }

    /**
     * Check if there are more jobs in the chain
     *
     * @return bool
     */
    public function hasRemainingChain(): bool
    {
        return $this->isSequential() && !empty($this->remainingChain);
    }

    /**
     * Get the next job in the chain
     *
     * @return array<string, mixed>|null
     */
    public function getNextJob(): ?array
    {
        if (!$this->hasRemainingChain()) {
            return null;
        }

        return $this->remainingChain[0] ?? null;
    }

    /**
     * Get remaining jobs after current one
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRemainingJobs(): array
    {
        if (!$this->hasRemainingChain()) {
            return [];
        }

        return array_slice($this->remainingChain, 1);
    }

    /**
     * Convert to readable queue payload
     *
     * @return array<string, mixed>
     */
    public function toQueuePayload(): array
    {
        return [
            'batch_id' => $this->batchId,
            'job_id' => $this->jobId,
            'type' => $this->type,
            'job_data' => $this->jobData,
            'context' => $this->context,
            'remaining_chain' => $this->remainingChain,
            'position' => $this->position,
        ];
    }

    /**
     * Create from queue payload
     *
     * @param array<string, mixed> $payload Queue message payload
     * @return static
     */
    public static function fromQueuePayload(array $payload): static
    {
        return new static(
            batchId: $payload['batch_id'],
            jobId: $payload['job_id'],
            type: $payload['type'],
            jobData: $payload['job_data'],
            context: $payload['context'] ?? [],
            remainingChain: $payload['remaining_chain'] ?? null,
            position: $payload['position'] ?? 0,
        );
    }

    /**
     * Convert to full array format
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'job_id' => $this->jobId,
            'type' => $this->type,
            'job_data' => $this->jobData,
            'context' => $this->context,
            'remaining_chain' => $this->remainingChain,
            'position' => $this->position,
        ];
    }

    /**
     * Create from full array format
     *
     * @param array<string, mixed> $data Message data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            batchId: $data['batch_id'],
            jobId: $data['job_id'],
            type: $data['type'],
            jobData: $data['job_data'],
            context: $data['context'] ?? [],
            remainingChain: $data['remaining_chain'] ?? null,
            position: $data['position'] ?? 0,
        );
    }

    /**
     * JSON serialization
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
