<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data\Job;

use InvalidArgumentException;

/**
 * Job Definition
 *
 * Represents a job without compensation (used in both batches and chains)
 */
class JobDefinition implements JobDefinitionInterface
{
    /**
     * Constructor
     *
     * @param string $class Job class name
     * @param array $args Job-specific arguments
     * @throws \InvalidArgumentException If job class does not exist
     */
    public function __construct(
        private string $class,
        private array $args = [],
    ) {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Job class not found: {$class}");
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
     * Get job-specific arguments
     *
     * @return array Job arguments
     */
    public function getArgs(): array
    {
        return $this->args;
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
            'compensation' => null,
            'position' => $position,
            'args' => $this->args,
        ];
    }
}
