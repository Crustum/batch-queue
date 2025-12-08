<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;

/**
 * Example Job - Regular job that can be used in batches or standalone
 *
 * This job is completely unaware of batches. When used in batch context,
 * the BatchJobProcessor handles all batch coordination.
 */
class ExampleJob implements JobInterface
{
    /**
     * Execute the job
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null Job result
     */
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();

        $compensationContext = $args['_compensation'] ?? null;

        $previousResults = $args['_previous_results'] ?? [];

        if ($compensationContext) {
            $result = $this->performCompensation($compensationContext);
        } else {
            $result = $this->performWork($args, $previousResults);
        }

        return json_encode($result);
    }

    /**
     * Perform the actual work
     *
     * @param array $args Job arguments
     * @param array $previousResults Results from previous jobs (sequential only)
     * @return array Work result
     */
    private function performWork(array $args, array $previousResults): array
    {
        $data = $args['data'] ?? 'default data';

        if (!empty($previousResults)) {
            $data = $this->combineWithPreviousResults($data, $previousResults);
        }

        sleep(1);

        return [
        'processed_data' => $data,
        'timestamp' => time(),
        'job_class' => static::class,
        ];
    }

    /**
     * Perform compensation (undo operation)
     *
     * @param array $compensationContext Compensation context
     * @return array Compensation result
     */
    private function performCompensation(array $compensationContext): array
    {
        $originalJobClass = $compensationContext['original_job_class'];
        $originalJobId = $compensationContext['original_job_id'];

        return [
            'compensated_job' => $originalJobClass,
            'compensated_job_id' => $originalJobId,
            'compensation_performed_at' => time(),
        ];
    }

    /**
     * Combine current data with previous results from chain
     *
     * @param mixed $currentData Current job data
     * @param array $previousResults Previous job results
     * @return mixed Combined data
     */
    private function combineWithPreviousResults(mixed $currentData, array $previousResults): mixed
    {
        return [
            'current' => $currentData,
            'previous' => $previousResults,
            'chain_position' => count($previousResults) + 1,
        ];
    }
}
