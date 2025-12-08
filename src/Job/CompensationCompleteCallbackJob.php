<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Job;

use Cake\Log\Log;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor;
use Crustum\BatchQueue\ResultAwareInterface;
use Crustum\BatchQueue\Storage\BatchStorageInterface;

/**
 * Compensation Complete Callback Job
 *
 * Called when a compensation chain completes successfully.
 * Updates the original batch context with completion status.
 */
class CompensationCompleteCallbackJob implements JobInterface, ResultAwareInterface
{
    /**
     * Batch storage
     *
     * @var \Crustum\BatchQueue\Storage\BatchStorageInterface
     */
    private BatchStorageInterface $storage;

    /**
     * The result of the job execution
     *
     * @var mixed
     */
    private mixed $result = null;

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
     * Execute compensation completion callback
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null Job result
     */
    public function execute(Message $message): ?string
    {
        Log::info('**CompensationCompleteCallbackJob** execute: message=' . json_encode($message));

        $args = $message->getArgument();
        $originalBatchId = $args['original_batch_id'] ?? null;

        if (!$originalBatchId) {
            return null;
        }

        $batch = $this->storage->getBatch($originalBatchId);

        if ($batch) {
            $context = $batch->context ?? [];
            $context['compensation_status'] = 'completed';
            $context['compensation_completed_at'] = date('Y-m-d H:i:s');

            $this->storage->updateBatch($originalBatchId, ['context' => $context]);
        }
        Log::info('**CompensationCompleteCallbackJob** execute: originalBatchId=' . $originalBatchId . ' batch=' . json_encode($batch));

        $this->result = ['compensation_complete' => true];

        return Processor::ACK;
    }

    /**
     * Get the result of the job execution
     *
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }
}
