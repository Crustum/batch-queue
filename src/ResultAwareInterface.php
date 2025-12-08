<?php
declare(strict_types=1);

namespace Crustum\BatchQueue;

use Cake\Queue\Job\JobInterface;

/**
 * ResultAwareInterface for jobs that can return execution results
 *
 * Jobs implementing this interface can provide execution results
 * that will be stored in the batch job record. This allows jobs
 * to return structured data instead of just ACK/REJECT status.
 */
interface ResultAwareInterface extends JobInterface
{
    /**
     * Get the result of job execution
     *
     * This method is called after execute() completes successfully.
     * The return value will be stored as the job result in the batch.
     *
     * @return mixed The execution result (can be any serializable data)
     */
    public function getResult(): mixed;
}
