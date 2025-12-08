<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Processor;

use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Crustum\BatchQueue\Service\QueueConfigService;
use DateTime;
use Enqueue\Consumption\Result;
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
            if (!$jobRecord) {
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
        } catch (Throwable $e) {
            $message->setProperty('jobException', $e);
            $duration = (int)((microtime(true) * 1000) - $startTime);

            $this->logger->debug(__('Message encountered exception: {0}', $e->getMessage()));
            $this->dispatchEvent('Processor.message.exception', [
                'message' => $jobMessage,
                'exception' => $e,
                'duration' => $duration,
            ]);

            if (isset($body['args'][0]['batch_id']) && isset($body['args'][0]['job_position'])) {
                $this->handleJobFailure($body['args'][0]['batch_id'], $jobId ?? 'unknown', $body['args'][0]['job_position'], $e);
            }

            return Result::requeue('Exception occurred while processing message');
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
        if (!$batch) {
            $this->logger->error(__('Batch not found for job success for batch {0}', $batchId));

            return;
        }

        $newCompletedJobs = $this->storage->incrementCompletedJob($batchId, $jobId);

        $this->logger->info(__('Batch progress updated for batch {0}: {1} of {2} jobs completed', $batchId, $newCompletedJobs, $batch->totalJobs));
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
        $errorMessage = $error ? $error->getMessage() : '';
        $this->logger->error(__('Job failed for batch {0} and job {1} at position {2}: {3}', $batchId, $jobId, $jobPosition, $errorMessage));

        $this->storage->updateJobStatus($batchId, $jobId, 'failed', null, $errorMessage);
        $newFailedJobs = $this->storage->incrementFailedJob($batchId, $jobId);
        $this->logger->info(__('Failed job counter incremented {0} for batch {1}', $newFailedJobs, $batchId));

        $this->handleBatchFailure($batchId, $errorMessage);
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

        if (!$batch) {
            return;
        }

        $this->storage->updateBatch($batchId, [
            'status' => 'completed',
            'completed_at' => new DateTime(),
        ]);

        if (isset($batch->options['on_complete'])) {
            $this->executeCallback($batch->options['on_complete'], $batchId, 'completed');
        }
    }

    /**
     * Handle batch failure - execute failure callback and trigger compensation
     *
     * @param string $batchId Batch ID
     * @param string $error Error message
     * @return void
     */
    protected function handleBatchFailure(string $batchId, string $error): void
    {
        $batch = $this->storage->getBatch($batchId);

        if (!$batch) {
            return;
        }

        if (isset($batch->options['on_failure'])) {
            $this->executeCallback($batch->options['on_failure'], $batchId, 'failed', $error);
        }
    }

    /**
     * Execute callback (job or webhook)
     *
     * @param array|string $callback Callback definition
     * @param string $batchId Batch ID
     * @param string $status Batch status
     * @param string|null $error Error message if applicable
     * @return void
     */
    protected function executeCallback(
        string|array $callback,
        string $batchId,
        string $status,
        ?string $error = null,
    ): void {
        $job = null;
        if (is_array($callback) && isset($callback['class'])) {
            $batch = $this->storage->getBatch($batchId);
            $callbackPosition = $batch ? $batch->totalJobs : 999;

            $job = [
                'class' => $callback['class'],
                'args' => array_merge(
                    $callback['args'] ?? [],
                    [
                        'batch_id' => $batchId,
                        'status' => $status,
                        'error' => $error,
                        'job_position' => $callbackPosition,
                        'is_callback' => true,
                    ],
                ),
            ];
        }

        if ($job) {
            $batch = $this->storage->getBatch($batchId);
            $queueConfig = $batch !== null && $batch->queueConfig !== null ? $batch->queueConfig : QueueConfigService::getQueueConfig('parallel');
            $this->queueJob($job['class'], $job['args'], $queueConfig);
        }
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
