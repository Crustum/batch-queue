<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data\Job;

/**
 * Job Definition Interface
 *
 * Contract for all job definition types (simple and compensated)
 */
interface JobDefinitionInterface
{
    /**
     * Get job class name
     *
     * @return string Job class name
     */
    public function getClass(): string;

    /**
     * Get job-specific arguments
     *
     * @return array Job arguments
     */
    public function getArgs(): array;

    /**
     * Convert to normalized job structure
     *
     * @param int $position Job position in batch
     * @param string $jobId Job identifier
     * @return array Normalized job structure
     */
    public function toNormalized(int $position, string $jobId): array;
}
