<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Processor;

use Cake\Event\EventManager;
use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Crustum\BatchQueue\Event\BatchFinished;
use Crustum\BatchQueue\Service\QueueConfigService;
use DateTime;
use Interop\Queue\Context;
use Interop\Queue\Message as QueueMessage;
use Interop\Queue\Processor as InteropProcessor;
use RuntimeException;
use Throwable;

/**
 * Batch Job Processor - Executes individual jobs in parallel batches
 *
 * This processor:
 * 1. Runs on the default queue as a regular job
 * 2. Executes individual jobs within parallel batches
 * 3. Tracks batch progress and handles completion
 *
 * Failure modes (parallel only):
 * - Strict (default): first job failure marks the batch failed and fires on_failure once.
 *   Sibling jobs may still run on the broker; their rows/counters are updated for observability.
 * - allowFailures: jobs keep failing/succeeding until completed + failed >= total, then settle.
 */
class BatchJobProcessor extends BaseBatchProcessor
{
    /**
     * The method processes messages
     *
     * @param \Interop\Queue\Message $message Message.
     * @param \Interop\Queue\Context $context Context.
     * @return object|string with __toString method implemented
     */
    public function process(QueueMessage $message, Context $context): string|object
    {
        $startTime = microtime(true) * 1000;
        $this->dispatchEvent('Processor.message.seen', ['queueMessage' => $message]);

        $jobMessage = new Message($message, $context, $this->container);

        try {
            $body = json_decode($message->getBody(), true);

            if (!isset($body['args'][0]['batch_id'])) {
                $this->logger->debug(__('Not a batch job, skipping'));

                return InteropProcessor::ACK;
            }

            if (isset($body['args'][0]['is_callback']) && $body['args'][0]['is_callback']) {
                $this->logger->debug(__('Executing batch callback job'));
                $this->dispatchEvent('Processor.message.seen', ['queueMessage' => $message]);
                $this->dispatchEvent('Processor.message.start', ['message' => $jobMessage]);

                $executionResult = $this->processMessageWithResult($jobMessage);
                $jobResult = $executionResult['result'];

                $duration = (int)((microtime(true) * 1000) - $startTime);
                $this->dispatchEvent('Processor.message.success', [
                    'message' => $jobMessage,
                    'duration' => $duration,
                ]);

                return InteropProcessor::ACK;
            }

            $batchId = $body['args'][0]['batch_id'];
            $jobPosition = $body['args'][0]['job_position'] ?? 0;
            $jobContext = $body['args'][0] ?? [];

            $headers = $message->getHeaders();

            $messageId = $headers['message_id'] ?? null;
            if (!$messageId) {
                $messageId = uniqid('job_', true);
            }

            $jobRecord = $this->storage->getJobByPosition($batchId, $jobPosition);
            if (!$jobRecord instanceof BatchJobDefinition) {
                $this->logger->error(__('Job not found by position for batch {0} and position {1}', $batchId, $jobPosition));

                return InteropProcessor::REJECT;
            }

            $this->storage->updateJobId($batchId, $jobPosition, $messageId);
            $jobId = $messageId;

            $this->storage->updateJobStatus($batchId, $jobId, 'running');

            $this->dispatchEvent('Processor.message.start', ['message' => $jobMessage]);

            $executionResult = $this->processMessageWithResult($jobMessage);
            $result = $executionResult['response'];
            $jobResult = $executionResult['result'];
            if ($result === null || $result === InteropProcessor::ACK) {
                $this->handleJobSuccess($batchId, $jobId, $jobPosition, $jobResult, $jobContext);
                $result = InteropProcessor::ACK;
            } elseif ($result === InteropProcessor::REJECT || $result === InteropProcessor::REQUEUE) {
                $error = new RuntimeException(__('Job was rejected or requeued'));
                $this->handleJobFailure($batchId, $jobId, $jobPosition, $error);
            }

            $duration = (int)((microtime(true) * 1000) - $startTime);

            $this->logger->debug(__('Message processed successfully'));
            $this->dispatchEvent('Processor.message.success', [
                'message' => $jobMessage,
                'duration' => $duration,
            ]);

            return $result;
        } catch (Throwable $throwable) {
            $message->setProperty('jobException', $throwable);
            $duration = (int)((microtime(true) * 1000) - $startTime);

            $this->logger->debug(__('Message encountered exception: {0}', $throwable->getMessage()));
            $this->dispatchEvent('Processor.message.exception', [
                'message' => $jobMessage,
                'exception' => $throwable,
                'duration' => $duration,
            ]);

            if (isset($body['args'][0]['batch_id']) && isset($body['args'][0]['job_position'])) {
                $this->handleJobFailure($body['args'][0]['batch_id'], $jobId ?? 'unknown', $body['args'][0]['job_position'], $throwable);
            }

            return InteropProcessor::ACK;
        }
    }

    /**
     * Handle job success - update batch progress (parallel batches only)
     *
     * @param string $batchId Batch ID
     * @param string $jobId Unique job ID (message_id from headers)
     * @param int $jobPosition Job position in batch
     * @param mixed $jobResult Job result (from ResultAwareInterface or null)
     * @param array $context Job context
     * @return void
     */
    protected function handleJobSuccess(string $batchId, string $jobId, int $jobPosition, mixed $jobResult, array $context): void
    {
        $this->logger->info(__('Job completed successfully for batch {0} and job {1} at position {2}: {3}', $batchId, $jobId, $jobPosition, json_encode($jobResult)));

        $this->storage->updateJobStatus($batchId, $jobId, 'completed', $jobResult);

        $batch = $this->storage->getBatch($batchId);
        if (!$batch instanceof BatchDefinition) {
            $this->logger->error(__('Batch not found for job success for batch {0}', $batchId));

            return;
        }

        $newCompletedJobs = $this->storage->incrementCompletedJob($batchId, $jobId);
        $batch = $this->storage->getBatch($batchId) ?? $batch;
        $batch->completedJobs = $newCompletedJobs;

        $this->logger->info(__('Batch progress updated for batch {0}: {1} of {2} jobs completed', $batchId, $newCompletedJobs, $batch->totalJobs));

        if ($batch->allowsFailures()) {
            if ($batch->isSettled()) {
                $this->settleBatchAllowingFailures($batchId);
            }

            return;
        }

        if ($batch->isTerminal()) {
            return;
        }

        if ($newCompletedJobs >= $batch->totalJobs) {
            $this->logger->info(__('Batch completed, triggering completion handler for batch {0}', $batchId));
            $this->handleBatchCompletion($batchId);
        }
    }

    /**
     * Handle job failure - update batch state (parallel batches only)
     *
     * @param string $batchId Batch ID
     * @param string $jobId Unique job ID (message_id from headers)
     * @param int $jobPosition Job position in batch
     * @param \Throwable|null $error Error that occurred
     * @return void
     */
    protected function handleJobFailure(string $batchId, string $jobId, int $jobPosition, ?Throwable $error): void
    {
        $errorMessage = $error instanceof Throwable ? $error->getMessage() : '';
        $this->logger->error(__('Job failed for batch {0} and job {1} at position {2}: {3}', $batchId, $jobId, $jobPosition, $errorMessage));

        $this->storage->updateJobStatus($batchId, $jobId, 'failed', null, $errorMessage);
        $newFailedJobs = $this->storage->incrementFailedJob($batchId, $jobId);
        $this->logger->info(__('Failed job counter incremented {0} for batch {1}', $newFailedJobs, $batchId));

        $batch = $this->storage->getBatch($batchId);
        if (!$batch instanceof BatchDefinition) {
            return;
        }

        $batch->failedJobs = $newFailedJobs;

        if (isset($batch->options['on_job_failure'])) {
            $this->executeCallback(
                $batch->options['on_job_failure'],
                $batchId,
                'failed',
                $errorMessage,
                [
                    'job_id' => $jobId,
                    'failed_job_position' => $jobPosition,
                ],
            );
        }

        if ($batch->allowsFailures()) {
            if ($batch->isSettled()) {
                $this->settleBatchAllowingFailures($batchId);
            }

            return;
        }

        $this->handleBatchFailureOnce($batchId, $errorMessage);
    }

    /**
     * Settle a parallel batch that allows failures once all jobs are accounted for
     *
     * Status is always completed; failed_jobs signals partial failure.
     * Fires BatchFinished, on_complete, and on_failure (if any failed) once.
     *
     * @param string $batchId Batch ID
     * @return void
     */
    protected function settleBatchAllowingFailures(string $batchId): void
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch instanceof BatchDefinition || $batch->isTerminal()) {
            return;
        }

        $this->storage->updateBatch($batchId, [
            'status' => BatchDefinition::STATUS_COMPLETED,
            'completed_at' => new DateTime(),
        ]);

        $finishedBatch = $this->storage->getBatch($batchId) ?? $batch;
        $finishedBatch->status = BatchDefinition::STATUS_COMPLETED;
        EventManager::instance()->dispatch(new BatchFinished($finishedBatch));

        if (isset($batch->options['on_complete'])) {
            $this->executeCallback($batch->options['on_complete'], $batchId, 'completed');
        }

        if ($finishedBatch->failedJobs > 0 && isset($batch->options['on_failure'])) {
            $this->executeCallback(
                $batch->options['on_failure'],
                $batchId,
                'failed',
                sprintf('%d of %d jobs failed', $finishedBatch->failedJobs, $finishedBatch->totalJobs),
            );
        }
    }

    /**
     * Handle batch completion - execute completion callback
     *
     * @param string $batchId Batch ID
     * @return void
     */
    protected function handleBatchCompletion(string $batchId): void
    {
        $batch = $this->storage->getBatch($batchId);

        if (!$batch instanceof BatchDefinition || $batch->isTerminal()) {
            return;
        }

        $this->storage->updateBatch($batchId, [
            'status' => BatchDefinition::STATUS_COMPLETED,
            'completed_at' => new DateTime(),
        ]);

        $finishedBatch = $this->storage->getBatch($batchId) ?? $batch;
        $finishedBatch->status = BatchDefinition::STATUS_COMPLETED;
        EventManager::instance()->dispatch(new BatchFinished($finishedBatch));

        if (isset($batch->options['on_complete'])) {
            $this->executeCallback($batch->options['on_complete'], $batchId, 'completed');
        }
    }

    /**
     * Mark batch failed and fire on_failure once (strict parallel mode)
     *
     * @param string $batchId Batch ID
     * @param string $error Error message
     * @return void
     */
    protected function handleBatchFailureOnce(string $batchId, string $error): void
    {
        $batch = $this->storage->getBatch($batchId);

        if (!$batch instanceof BatchDefinition || $batch->isTerminal()) {
            return;
        }

        $this->storage->updateBatch($batchId, [
            'status' => BatchDefinition::STATUS_FAILED,
            'completed_at' => new DateTime(),
        ]);

        if (isset($batch->options['on_failure'])) {
            $this->executeCallback($batch->options['on_failure'], $batchId, 'failed', $error);
        }
    }

    /**
     * Execute callback (job class string or job definition array)
     *
     * @param array|string $callback Callback definition
     * @param string $batchId Batch ID
     * @param string $status Batch status
     * @param string|null $error Error message if applicable
     * @param array<string, mixed> $extraArgs Extra args merged into the callback job payload
     * @return void
     */
    protected function executeCallback(
        string|array $callback,
        string $batchId,
        string $status,
        ?string $error = null,
        array $extraArgs = [],
    ): void {
        if (is_string($callback)) {
            $callback = ['class' => $callback, 'args' => []];
        }

        if (!isset($callback['class'])) {
            return;
        }

        $batch = $this->storage->getBatch($batchId);
        $callbackPosition = $batch instanceof BatchDefinition ? $batch->totalJobs : 999;

        $args = array_merge(
            $callback['args'] ?? [],
            [
                'batch_id' => $batchId,
                'status' => $status,
                'error' => $error,
                'job_position' => $callbackPosition,
                'is_callback' => true,
            ],
            $extraArgs,
        );

        $queueConfig = $batch instanceof BatchDefinition && $batch->queueConfig !== null
            ? $batch->queueConfig
            : QueueConfigService::getQueueConfig('parallel');
        $this->queueJob($callback['class'], $args, $queueConfig);
    }

    /**
     * Queue a job with proper configuration and interface checking
     *
     * @param string $jobClass Job class to queue
     * @param array $args Job arguments
     * @param string|null $queueConfig Queue configuration name
     * @return void
     */
    protected function queueJob(string $jobClass, array $args, ?string $queueConfig = null): void
    {
        if ($queueConfig === null) {
            $queueConfig = QueueConfigService::getQueueConfig('parallel');
        }

        $interfaces = class_implements($jobClass);
        if (is_array($interfaces) && in_array('Monitor\Job\DispatchableInterface', $interfaces, true)) {
            call_user_func([$jobClass, 'dispatch'], $args, ['config' => $queueConfig, 'queue' => $queueConfig]);
        } else {
            QueueManager::push($jobClass, $args, ['config' => $queueConfig]);
        }
    }
}
