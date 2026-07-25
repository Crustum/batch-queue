<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

/**
 * Per-job failure callback used by allowFailures integration tests
 */
class PerJobFailureCallbackJob implements JobInterface
{
    /**
     * @param \Cake\Queue\Job\Message $message Message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $batchId = $args['batch_id'] ?? null;

        if (!$batchId) {
            return Processor::ACK;
        }

        $storage = new SqlBatchStorage();
        $batch = $storage->getBatch($batchId);

        if (!$batch instanceof BatchDefinition) {
            return Processor::ACK;
        }

        $context = $batch->context ?? [];
        $failures = $context['job_failures'] ?? [];
        $failures[] = [
            'job_id' => $args['job_id'] ?? null,
            'failed_job_position' => $args['failed_job_position'] ?? null,
            'error' => $args['error'] ?? null,
        ];
        $context['job_failures'] = $failures;
        $context['job_failure_count'] = count($failures);

        $storage->updateBatch($batchId, ['context' => $context]);

        return Processor::ACK;
    }
}
