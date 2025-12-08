<?php
declare(strict_types=1);

namespace Crustum\BatchQueue;

use Cake\Queue\Job\JobInterface;

/**
 * ContextAwareInterface for jobs that can manage their own context
 */
interface ContextAwareInterface extends JobInterface
{
    /**
     * Set the context for the job
     *
     * @param array $context The context to set
     * @return void
     */
    public function setContext(array $context): void;

    /**
     * Get the context for the job
     *
     * @return array|null The context or null if not set
     */
    public function getContext(): ?array;
}
