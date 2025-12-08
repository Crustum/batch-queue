<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data\Job;

use Crustum\BatchQueue\Data\BatchDefinition;
use InvalidArgumentException;

/**
 * Compensated Job Definition
 *
 * Represents a job with compensation (only valid for sequential chains)
 */
class CompensatedJobDefinition implements JobDefinitionInterface
{
    /**
     * Constructor
     *
     * @param string $class Job class name
     * @param string $compensationClass Compensation job class name
     * @param array $args Job-specific arguments
     * @param string $batchType Batch type ('parallel' or 'sequential')
     * @throws \InvalidArgumentException If classes don't exist or compensation used in parallel batch
     */
    public function __construct(
        private string $class,
        private string $compensationClass,
        private array $args = [],
        private string $batchType = BatchDefinition::TYPE_SEQUENTIAL,
    ) {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Job class not found: {$class}");
        }

        if (!class_exists($compensationClass)) {
            throw new InvalidArgumentException("Compensation class not found: {$compensationClass}");
        }

        if ($batchType !== BatchDefinition::TYPE_SEQUENTIAL) {
            throw new InvalidArgumentException(
                'Compensation is only supported for sequential chains, not parallel batches',
            );
        }
    }

    /**
     * Get job class name
     *
     * @return string Job class name
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Get compensation class name
     *
     * @return string Compensation class name
     */
    public function getCompensationClass(): string
    {
        return $this->compensationClass;
    }

    /**
     * Get job-specific arguments
     *
     * @return array Job arguments
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Get batch type
     *
     * @return string Batch type
     */
    public function getBatchType(): string
    {
        return $this->batchType;
    }

    /**
     * Convert to normalized job structure
     *
     * @param int $position Job position in batch
     * @param string $jobId Job identifier
     * @return array Normalized job structure
     */
    public function toNormalized(int $position, string $jobId): array
    {
        return [
            'id' => $jobId,
            'class' => $this->class,
            'compensation' => $this->compensationClass,
            'position' => $position,
            'args' => $this->args,
        ];
    }
}
