<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Storage\SqlBatchStorage;

/**
 * Failure Callback Job
 *
 * Called when a batch fails to handle the failure
 */
class FailureCallbackJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $batchId = $args['batch_id'] ?? null;
        $status = $args['status'] ?? null;
        $error = $args['error'] ?? null;

        if (!$batchId || $status !== 'failed') {
            return null;
        }

        $storage = new SqlBatchStorage();
        $batch = $storage->getBatch($batchId);

        if (!$batch) {
            return null;
        }

        $context = $batch->context ?? [];
        $context['failure_handled'] = true;
        $context['error_message'] = $error;
        $context['handled_at'] = date('Y-m-d H:i:s');

        $storage->updateBatch($batchId, ['context' => $context]);

        return json_encode(['failure_handled' => true]);
    }
}
