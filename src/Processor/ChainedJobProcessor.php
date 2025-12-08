<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Processor;

use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Closure;
use Crustum\BatchQueue\ContextAwareInterface;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Crustum\BatchQueue\Job\CompensationCompleteCallbackJob;
use Crustum\BatchQueue\Job\CompensationFailedCallbackJob;
use Crustum\BatchQueue\ResultAwareInterface;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Service\QueueConfigService;
use DateTime;
use Interop\Queue\Context;
use Interop\Queue\Message as QueueMessage;
use Interop\Queue\Processor as InteropProcessor;
use Throwable;

/**
 * Chained Job Processor - Executes individual jobs within batch context
 *
 * This processor:
 * 1. Runs on the default queue as a regular job
 * 2. Executes the actual inner job class
 * 3. Handles compensation logic on job execution level on job failure
 * 4. Handles context passing to inner jobs
 * 5. Handles batch completion and failure
 */
class ChainedJobProcessor extends BaseBatchProcessor
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

            if (!isset($body['args'][0]['batch_id']) || !isset($body['args'][0]['job_position'])) {
                $this->logger->debug(__('Not a sequential batch job, skipping'));

                return InteropProcessor::ACK;
            }

            $batchId = $body['args'][0]['batch_id'];
            $jobPosition = $body['args'][0]['job_position'];

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

            if (isset($body['args'][0]['is_compensation']) && $body['args'][0]['is_compensation']) {
                $this->logger->debug(__('Executing compensation job'));

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

            $innerJobClass = $body['class'][0] ?? null;
            $compensation = $body['args'][0]['compensation'] ?? null;
            $jobContext = $body['args'][0];

            $jobRecord = $this->storage->getJobByPosition($batchId, $jobPosition);
            if (!$jobRecord) {
                $this->logger->error(__('Job not found by position for batch {0} and position {1}', $batchId, $jobPosition));

                return InteropProcessor::REJECT;
            }
            $jobId = $jobRecord->id;

            $messageId = $message->getHeaders()['message_id'] ?? null;
            if (!$messageId) {
                $this->logger->error(__('No message_id found in headers for batch {0} and job {1}', $batchId, $jobId));

                return InteropProcessor::REJECT;
            }

            $this->storage->updateJobId($batchId, $jobPosition, $messageId);
            $jobId = $messageId;

            $batch = $this->storage->getBatch($batchId);
            if (!$batch) {
                $this->logger->error(__('Batch not found for job execution for batch {0}', $batchId));

                return InteropProcessor::REJECT;
            }

            $this->storage->updateJobStatus($batchId, $jobId, 'running');

            $this->dispatchEvent('Processor.message.start', ['message' => $jobMessage]);

            $target = $jobMessage->getTarget();
            $innerJobClass = $target[0];

            if ($this->container && $this->container->has($innerJobClass)) {
                $innerJob = $this->container->get($innerJobClass);
            } else {
                $innerJob = new $innerJobClass();
            }

            if ($innerJob instanceof ContextAwareInterface) {
                $innerJob->setContext($batch->context);
            }

            $innerMessage = new Message($message, $context, $this->container);
            $callable = Closure::fromCallable([$innerJob, $target[1]]);
            $callable($innerMessage);

            $jobResult = null;
            if ($innerJob instanceof ResultAwareInterface) {
                $jobResult = $innerJob->getResult();
            }

            $updatedContext = null;
            if ($innerJob instanceof ContextAwareInterface) {
                $updatedContext = $innerJob->getContext();
            }

            $this->handleJobSuccess($batchId, $jobId, $jobResult, $jobContext, $updatedContext);

            $duration = (int)((microtime(true) * 1000) - $startTime);

            $this->logger->debug(__('Message processed successfully'));
            $this->dispatchEvent('Processor.message.success', [
                'message' => $jobMessage,
                'duration' => $duration,
            ]);

            return InteropProcessor::ACK;
        } catch (Throwable $e) {
            $message->setProperty('jobException', $e);
            $duration = (int)((microtime(true) * 1000) - $startTime);

            $this->logger->debug(__('Message encountered exception: {0}', $e->getMessage()));
            $this->dispatchEvent('Processor.message.exception', [
                'message' => $jobMessage,
                'exception' => $e,
                'duration' => $duration,
            ]);

            if (isset($body['args'][0]['batch_id'])) {
                $batchId = $body['args'][0]['batch_id'];
                $failureJobId = $jobId ?? 'unknown';
                $failureJobClass = $innerJobClass ?? ($body['class'][0] ?? 'unknown');
                $failureCompensation = $compensation ?? ($body['args'][0]['compensation'] ?? null);
                $failureContext = $jobContext ?? ($body['args'][0] ?? []);

                $this->handleJobFailure(
                    $batchId,
                    $failureJobId,
                    $failureJobClass,
                    $failureCompensation,
                    $failureContext,
                    $e,
                );
            }

            return InteropProcessor::ACK;
        }
    }

    /**
     * Handle job success - update batch progress and queue next job if sequential
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @param mixed $jobResult Job result (from ResultAwareInterface or null)
     * @param array $jobContext Job context
     * @param array|null $updatedContext Updated context from job
     * @return void
     */
    protected function handleJobSuccess(string $batchId, string $jobId, mixed $jobResult, array $jobContext, ?array $updatedContext = null): void
    {
        $this->logger->info(__('Job completed successfully for batch {0} and job {1}: {2}', $batchId, $jobId, json_encode($jobResult)));
        $this->storage->updateJobStatus($batchId, $jobId, 'completed', $jobResult);
        $newCompletedJobs = $this->storage->incrementCompletedJob($batchId, $jobId);
        $this->logger->info(__('Batch progress updated for batch {0}: {1} jobs completed', $batchId, $newCompletedJobs));

        if ($updatedContext !== null) {
            $this->storage->updateBatch($batchId, ['context' => $updatedContext]);
        }

        $batch = $this->storage->getBatch($batchId);
        if (!$batch) {
            $this->logger->error(__('Batch not found for job success for batch {0}', $batchId));

            return;
        }

        if ($newCompletedJobs >= $batch->totalJobs) {
            $this->logger->info(__('Chained Batch completed, triggering completion handler for batch {0}', $batchId));
            $this->handleBatchCompletion($batchId);
        } else {
            if ($batch->type === 'sequential') {
                $this->queueNextSequentialJob($batch, $jobId, $jobResult);
            }
        }
    }

    /**
     * Handle job failure - update batch state and trigger compensation
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @param string $originalJobClass Original job class
     * @param string|null $compensation Compensation job class
     * @param array $context Job context
     * @param \Throwable $error Error that occurred
     * @return void
     */
    protected function handleJobFailure(
        string $batchId,
        string $jobId,
        string $originalJobClass,
        ?string $compensation,
        array $context,
        Throwable $error,
    ): void {
        $this->logger->error(__('Job failed for batch {0} and job {1}: {2}', $batchId, $jobId, $error->getMessage()));
        $this->storage->updateJobStatus($batchId, $jobId, 'failed', null, $error->getMessage());
        $newFailedJobs = $this->storage->incrementFailedJob($batchId, $jobId);
        $this->logger->info(__('Failed job counter incremented {0} for batch {1}', $newFailedJobs, $batchId));
        $this->handleBatchFailure($batchId, $error->getMessage());

        if ($compensation) {
            $this->queueCompensation($batchId, $jobId, $originalJobClass, $compensation, $context, $error);
        }
    }

    /**
     * Queue compensation job
     *
     * @param string $batchId Batch ID
     * @param string $jobId Original job ID
     * @param string $originalJobClass Original job class
     * @param string $compensationClass Compensation job class
     * @param array $context Job context
     * @param \Throwable $error Original error
     * @return void
     */
    protected function queueCompensation(
        string $batchId,
        string $jobId,
        string $originalJobClass,
        string $compensationClass,
        array $context,
        Throwable $error,
    ): void {
        $batch = $this->storage->getBatch($batchId);
        $compensationPosition = $batch ? $batch->totalJobs + 100 : 999;

        $compensationArgs = [
            'batch_id' => $batchId,
            'job_position' => $compensationPosition,
            'is_compensation' => true,
            '_compensation' => [
                'batch_id' => $batchId,
                'original_job_id' => $jobId,
                'original_job_class' => $originalJobClass,
                'error' => $error->getMessage(),
                'context' => $context,
            ],
            ...$context,
        ];

        $this->queueJob($compensationClass, $compensationArgs);
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

        $alreadyFailed = ($batch->status === 'failed');

        if (!$alreadyFailed) {
            $this->storage->updateBatch($batchId, [
                'status' => 'failed',
                'completed_at' => new DateTime(),
            ]);

            if ($batch->hasCompensation()) {
                $this->triggerBatchCompensation($batch);
            }

            if (isset($batch->options['on_failure'])) {
                $this->executeCallback($batch->options['on_failure'], $batchId, 'failed', $error);
            }
        }
    }

    /**
     * Queue next job in sequential chain
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @param string $completedJobId Completed job ID (message_id)
     * @param mixed $result Job result
     * @return void
     */
    protected function queueNextSequentialJob(BatchDefinition $batch, string $completedJobId, mixed $result): void
    {
        $completedPosition = null;
        foreach ($batch->jobs as $job) {
            $jobRecord = $this->storage->getJobByPosition($batch->id, $job['position']);
            if ($jobRecord && $jobRecord->jobId === $completedJobId) {
                $completedPosition = $job['position'];
                break;
            }
        }

        if ($completedPosition === null) {
            $this->logger->error(__('Could not find completed job position for message_id {0} in batch {1}', $completedJobId, $batch->id));

            return;
        }

        $nextJob = null;
        foreach ($batch->jobs as $job) {
            if ($job['position'] === $completedPosition + 1) {
                $nextJob = $job;
                break;
            }
        }

        if (!$nextJob) {
            $this->logger->debug(__('No next sequential job found at position {0} for batch {1}', $completedPosition + 1, $batch->id));

            return;
        }

        $this->logger->info(__('Queueing next sequential job {0} at position {1} for batch {2}', $nextJob['class'], $nextJob['position'], $batch->id));

        $freshBatch = $this->storage->getBatch($batch->id);
        if (!$freshBatch) {
            $this->logger->error(__('Failed to get fresh batch context for next sequential job in batch {0}', $batch->id));

            return;
        }

        $nextJobArgs = array_merge($freshBatch->context, $nextJob['args'] ?? []);
        $nextJobArgs['batch_id'] = $batch->id;
        $nextJobArgs['job_position'] = $nextJob['position'];
        $nextJobArgs['compensation'] = $nextJob['compensation'] ?? null;

        $queueConfig = $freshBatch->queueConfig ?? QueueConfigService::getQueueConfig('sequential');
        $this->queueJob($nextJob['class'], $nextJobArgs, $queueConfig);
    }

    /**
     * Get completed jobs that need compensation
     *
     * @param string $batchId Batch ID
     * @return array Completed jobs with compensation in reverse order
     */
    protected function getCompletedJobsForCompensation(string $batchId): array
    {
        $batch = $this->storage->getBatch($batchId);
        if (!$batch) {
            return [];
        }

        $compensationJobs = [];

        foreach ($batch->jobs as $job) {
            if (empty($job['compensation'])) {
                continue;
            }

            $jobRecord = $this->storage->getJobByPosition($batchId, $job['position']);

            if ($jobRecord && $jobRecord->status === BatchJobDefinition::STATUS_COMPLETED) {
                $compensationJobs[] = array_merge($job, [
                    'result' => $jobRecord->result,
                ]);
            }
        }

        return array_reverse($compensationJobs);
    }

    /**
     * Build compensation chain from completed jobs
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $originalBatch Original batch
     * @param array $completedJobs Completed jobs in reverse order
     * @return array Compensation chain definition
     */
    protected function buildCompensationChain(
        BatchDefinition $originalBatch,
        array $completedJobs,
    ): array {
        $compensationChain = [];

        foreach ($completedJobs as $index => $job) {
            $compensationChain[] = [
                'class' => $job['compensation'],
                'args' => array_merge(
                    $job['args'] ?? [],
                    [
                        '_compensation' => [
                            'original_batch_id' => $originalBatch->id,
                            'original_job_class' => $job['class'],
                            'original_position' => $job['position'],
                            'original_result' => $job['result'] ?? null,
                            'compensation_order' => $index,
                        ],
                    ],
                ),
            ];
        }

        return $compensationChain;
    }

    /**
     * Trigger compensation for all completed jobs in batch
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch definition
     * @return void
     */
    protected function triggerBatchCompensation(BatchDefinition $batch): void
    {
        $completedJobs = $this->getCompletedJobsForCompensation($batch->id);

        if (empty($completedJobs)) {
            $this->logger->info(__('No completed jobs to compensate for batch {0}', $batch->id));

            return;
        }

        $this->logger->info(__(
            'Triggering compensation for {0} completed jobs in batch {1}',
            count($completedJobs),
            $batch->id,
        ));

        $compensationChain = $this->buildCompensationChain($batch, $completedJobs);
        $batchManager = $this->container->get(BatchManager::class);

        $compensationBatch = $batchManager->chain($compensationChain)
            ->queueConfig($batch->queueConfig ?? QueueConfigService::getQueueConfig('sequential'))
            ->setContext($batch->context ?? []);

        if ($batch->queueName !== null) {
            $compensationBatch->queue($batch->queueName);
        }

        $compensationBatch
            ->onComplete([
                'class' => CompensationCompleteCallbackJob::class,
                'args' => ['original_batch_id' => $batch->id],
            ])
            ->onFailure([
                'class' => CompensationFailedCallbackJob::class,
                'args' => ['original_batch_id' => $batch->id],
            ]);

        $compensationBatchId = $compensationBatch->dispatch();

        $context = $batch->context ?? [];
        $context['compensation_batch_id'] = $compensationBatchId;
        $context['compensation_started_at'] = date('Y-m-d H:i:s');
        $context['compensation_job_count'] = count($completedJobs);
        $context['compensation_status'] = 'running';

        $this->storage->updateBatch($batch->id, ['context' => $context]);

        $this->logger->info(__(
            'Compensation chain {0} dispatched for original batch {1}',
            $compensationBatchId,
            $batch->id,
        ));
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
        if (is_array($callback) && isset($callback['class'])) {
            $batch = $this->storage->getBatch($batchId);
            $callbackPosition = $batch ? $batch->totalJobs : 999;

            $callbackArgs = array_merge(
                $callback['args'] ?? [],
                [
                    'batch_id' => $batchId,
                    'status' => $status,
                    'error' => $error,
                    'job_position' => $callbackPosition,
                    'is_callback' => true,
                ],
            );

            $queueConfig = $batch !== null && $batch->queueConfig !== null ? $batch->queueConfig : QueueConfigService::getQueueConfig('sequential');
            $this->queueJob($callback['class'], $callbackArgs, $queueConfig);
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
            $queueConfig = QueueConfigService::getQueueConfig('sequential');
        }

        $interfaces = class_implements($jobClass);
        if (is_array($interfaces) && in_array('Monitor\Job\DispatchableInterface', $interfaces, true)) {
            call_user_func([$jobClass, 'dispatch'], $args, ['config' => $queueConfig, 'queue' => $queueConfig]);
        } else {
            QueueManager::push($jobClass, $args, ['config' => $queueConfig]);
        }
    }
}
