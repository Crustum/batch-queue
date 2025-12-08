<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Storage\BatchStorageInterface;

/**
 * Compensation Failed Callback Job
 *
 * Called when a compensation chain fails.
 * Updates the original batch context with failure status.
 */
class CompensationFailedCallbackJob implements JobInterface
{
    /**
     * Batch storage
     *
     * @var \Crustum\BatchQueue\Storage\BatchStorageInterface
     */
    private BatchStorageInterface $storage;

    /**
     * Constructor
     *
     * @param \Crustum\BatchQueue\Storage\BatchStorageInterface $storage Batch storage
     */
    public function __construct(BatchStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Execute compensation failure callback
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null Job result
     */
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $originalBatchId = $args['original_batch_id'] ?? null;
        $error = $args['error'] ?? 'Unknown error';

        if (!$originalBatchId) {
            return null;
        }

        $batch = $this->storage->getBatch($originalBatchId);

        if ($batch) {
            $context = $batch->context ?? [];
            $context['compensation_status'] = 'failed';
            $context['compensation_failed_at'] = date('Y-m-d H:i:s');
            $context['compensation_error'] = $error;

            $this->storage->updateBatch($originalBatchId, ['context' => $context]);
        }

        return json_encode(['compensation_failed' => true, 'error' => $error]);
    }
}
