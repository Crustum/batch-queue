<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor;
use Crustum\BatchQueue\ResultAwareInterface;
use Crustum\BatchQueue\Storage\SqlBatchStorage;

/**
 * Accumulate Results Callback Job
 *
 * This job is called when a batch completes. It accumulates all job results
 * into the batch context, demonstrating the real map-reduce pattern.
 */
class AccumulateResultsCallbackJob implements JobInterface, ResultAwareInterface
{
    private mixed $result = null;

    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $batchId = $args['batch_id'] ?? null;
        $status = $args['status'] ?? null;

        if (!$batchId || $status !== 'completed') {
            return Processor::ACK;
        }

        $storage = new SqlBatchStorage();
        $batch = $storage->getBatch($batchId);

        if (!$batch) {
            return Processor::ACK;
        }

        $results = $storage->getBatchResults($batchId);

        $context = $batch->context ?? [];

        $context['results'] = $results;

        $accumulatedSum = 0;

        foreach ($results as $result) {
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                if ($decoded && isset($decoded['value'])) {
                    $accumulatedSum += (int)$decoded['value'];
                }
            } elseif (is_array($result) && isset($result['value'])) {
                $accumulatedSum += (int)$result['value'];
            }
        }

        $context['accumulated_sum'] = $accumulatedSum;

        $storage->updateBatch($batchId, ['context' => $context]);

        $this->result = ['accumulated' => true, 'sum' => $accumulatedSum];

        return Processor::ACK;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}
