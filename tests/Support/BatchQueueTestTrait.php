<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support;

use Cake\Core\ContainerInterface;
use Crustum\BatchQueue\Processor\BatchJobProcessor;
use Crustum\BatchQueue\Processor\ChainedJobProcessor;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Psr\Log\NullLogger;

/**
 * BatchQueue Test Trait
 *
 * Provides helper methods for testing batch and chain processing with real queue inspection
 */
trait BatchQueueTestTrait
{
    /**
     * Get count of queued jobs in specified queue
     *
     * @param string $queueName Queue name
     * @return int Number of queued jobs
     */
    protected function getQueuedJobCount(string $queueName = 'default'): int
    {
        $queueDir = TMP . "test_{$queueName}_queue";
        $enqueueFile = $queueDir . "/enqueue.app.{$queueName}";

        if (!file_exists($enqueueFile)) {
            return 0;
        }

        $content = file_get_contents($enqueueFile);
        if (empty($content)) {
            return 0;
        }

        // Count message separators (each message ends with |)
        return substr_count($content, '|');
    }

    /**
     * Get count of queued jobs in batch queue
     *
     * @return int Number of batch jobs queued
     */
    protected function getBatchQueuedJobCount(): int
    {
        return $this->getQueuedJobCount('batch');
    }

    /**
     * Get all queued messages from specified queue
     *
     * @param string $queueName Queue name
     * @return array Array of message data
     */
    protected function getQueuedMessages(string $queueName = 'default'): array
    {
        $queueDir = TMP . "test_{$queueName}_queue";
        $enqueueFile = $queueDir . "/enqueue.app.{$queueName}";

        if (!file_exists($enqueueFile)) {
            return [];
        }

        $content = file_get_contents($enqueueFile);
        if (empty($content)) {
            return [];
        }

        $messages = [];
        $rawMessages = explode('|', trim($content, '|'));

        foreach ($rawMessages as $index => $rawMessage) {
            if (empty($rawMessage)) {
                continue;
            }

            $messageData = json_decode($rawMessage, true);
            if ($messageData && isset($messageData['body'])) {
                $bodyData = json_decode($messageData['body'], true);
                $messages[] = [
                    'file' => "message_{$index}",
                    'content' => $rawMessage,
                    'data' => $bodyData,
                    'envelope' => $messageData,
                ];
            }
        }

        return $messages;
    }

    /**
     * Clear all messages from specified queue
     *
     * @param string $queueName Queue name
     * @return void
     */
    protected function clearQueue(string $queueName = 'default'): void
    {
        $queueDir = TMP . "test_{$queueName}_queue";
        $enqueueFile = $queueDir . "/enqueue.app.{$queueName}";

        if (file_exists($enqueueFile)) {
            unlink($enqueueFile);
        }
    }

    /**
     * Clear all messages from all test queues
     *
     * @return void
     */
    protected function clearAllQueues(): void
    {
        $this->clearQueue('default');
        $this->clearQueue('batch');
    }

    /**
     * Assert that specific job class is queued
     *
     * @param string $jobClass Job class name
     * @param string $queueName Queue name
     * @return void
     */
    protected function assertJobQueued(string $jobClass, string $queueName = 'default'): void
    {
        $messages = $this->getQueuedMessages($queueName);
        $found = false;

        foreach ($messages as $message) {
            $data = $message['data'];
            if (isset($data['class']) && $data['class'][0] === $jobClass) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Job {$jobClass} not found in {$queueName} queue");
    }

    /**
     * Assert that batch jobs are queued (no longer needed for batch queue, jobs are queued immediately)
     *
     * @param string $batchId Batch ID
     * @return void
     * @deprecated Batches now queue jobs immediately, no BatchStartJob needed
     */
    protected function assertBatchJobQueued(string $batchId): void
    {
        // Batches now dispatch immediately, so this is no longer applicable
        // Kept for backward compatibility but does nothing
    }

    /**
     * Create proper Enqueue message object for processor testing
     *
     * @param array $data Message data
     * @return \Interop\Queue\Message
     */
    protected function createEnqueueMessage(array $data): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getBody')->willReturn(json_encode($data));
        $message->method('getHeaders')->willReturn([
            'message_id' => uniqid('test_msg_', true),
            'timestamp' => time(),
        ]);
        $message->method('getProperties')->willReturn([]);

        return $message;
    }

    /**
     * Create proper Enqueue context object for processor testing
     *
     * @return \Interop\Queue\Context
     */
    protected function createEnqueueContext(): Context
    {
        return $this->createMock(Context::class);
    }

    /**
     * Get appropriate processor for job data
     *
     * @param array $jobData Job data
     * @return \Interop\Queue\Processor|null
     */
    protected function getProcessorForJob(array $jobData): ?Processor
    {
        $args = $jobData['args'][0] ?? [];

        if (isset($args['batch_id'])) {
            $batchId = $args['batch_id'];
            $storage = new SqlBatchStorage();
            $batch = $storage->getBatch($batchId);

             $logger = new NullLogger();
             $container = $this->createMock(ContainerInterface::class);
             $container->method('get')
                 ->with(BatchStorageInterface::class)
                 ->willReturn($storage);

            if ($batch && $batch->type === 'sequential') {
                return new ChainedJobProcessor($logger, $container);
            } else {
                return new BatchJobProcessor($logger, $container);
            }
        }

        // For non-batch jobs, return null (we can't process without container)
        return null;
    }

    /**
     * Process batch queue (no longer needed - batches dispatch immediately)
     *
     * @return array Processing results
     * @deprecated Batches now dispatch immediately, no batch queue processing needed
     */
    protected function processBatchQueue(): array
    {
        // Batches now dispatch immediately, so this is no longer needed
        // Kept for backward compatibility but returns empty array
        return [];
    }

    /**
     * Simulate individual jobs being queued after BatchStartJob processes
     *
     * @param string $batchId Batch ID
     * @return void
     */
    protected function simulateIndividualJobsQueued(string $batchId): void
    {
        $storage = new SqlBatchStorage();
        $batch = $storage->getBatch($batchId);

        if (!$batch) {
            return;
        }

        // Update batch status to running
        $storage->updateBatch($batchId, ['status' => 'running']);

        // Queue individual jobs to default queue (simulate BatchStartJob behavior)
        foreach ($batch->jobs as $job) {
            // Ensure job record exists in database (this is what BatchStartJob would create)
            $storage->updateJobId($batchId, $job['position'], uniqid('job_', true));

            $individualJobData = [
                'class' => [$job['class']],
                'args' => [array_merge($batch->context, [
                    'batch_id' => $batchId,
                    'job_position' => $job['position'],
                ])],
            ];

            // Write to default queue file (simulate QueueManager::push)
            $this->appendToQueueFile('default', $individualJobData);
        }
    }

    /**
     * Append job data to queue file (simulate queuing)
     *
     * @param string $queueName Queue name
     * @param array $jobData Job data
     * @return void
     */
    protected function appendToQueueFile(string $queueName, array $jobData): void
    {
        $queueDir = TMP . "test_{$queueName}_queue";
        $enqueueFile = $queueDir . "/enqueue.app.{$queueName}";

        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0755, true);
        }

        // Create envelope like real enqueue does
        $envelope = [
            'body' => json_encode($jobData),
            'headers' => ['message_id' => uniqid('test_', true)],
            'properties' => [],
        ];

        file_put_contents($enqueueFile, json_encode($envelope) . '|', FILE_APPEND | LOCK_EX);
    }

    /**
     * Process default queue using appropriate processors
     *
     * @return array Processing results
     */
    protected function processDefaultQueue(): array
    {
        $results = [];
        $messages = $this->getQueuedMessages('default');

        foreach ($messages as $messageData) {
            $body = $messageData['data'];

            // Determine which processor to use based on job content
            $processor = $this->getProcessorForJob($body);

            if ($processor) {
                // Create proper Enqueue QueueMessage and Context objects
                $queueMessage = $this->createEnqueueMessage($body);
                $context = $this->createEnqueueContext();

                // Debug what we're sending to the processor
                error_log('Processing job: ' . json_encode($body));

                $result = $processor->process($queueMessage, $context);
                $results[] = $result;

                error_log('Processor result: ' . $result);
            }

            // Note: File transport stores messages in enqueue.app.{queue} files
            // Individual messages don't need file cleanup
        }

        return $results;
    }

    /**
     * Process only the next job in the queue (for step-by-step chain testing)
     *
     * @param string $batchId Batch ID to process
     * @return array Processing result
     */
    protected function processNextChainStep(string $batchId): array
    {
        $messages = $this->getQueuedMessages('default');

        if (empty($messages)) {
            return ['processed' => false, 'reason' => 'no_jobs_queued'];
        }

        // Process only the first message (next chain step)
        $messageData = $messages[0];
        $body = $messageData['data'];

        // Verify it's a chain job for this batch
        $args = $body['args'][0] ?? [];
        if (($args['batch_id'] ?? '') !== $batchId) {
            return ['processed' => false, 'reason' => 'wrong_batch'];
        }

        $processor = $this->getProcessorForJob($body);
        if (!$processor) {
            return ['processed' => false, 'reason' => 'no_processor'];
        }

        // Process this single step
        $queueMessage = $this->createEnqueueMessage($body);
        $context = $this->createEnqueueContext();
        $result = $processor->process($queueMessage, $context);

        // Remove processed message file
        unlink(TMP . 'test_default_queue/' . $messageData['file']);

        return [
            'processed' => true,
            'result' => $result,
            'job_class' => $body['class'][0] ?? null,
            'job_position' => $args['job_position'] ?? null,
        ];
    }

    /**
     * Assert that chain step completed successfully
     *
     * @param string $batchId Batch ID
     * @param int $expectedPosition Job position
     * @param string $expectedJobClass Expected job class
     * @return void
     */
    protected function assertChainStepCompleted(string $batchId, int $expectedPosition, string $expectedJobClass): void
    {
        $storage = $this->getContainer()->get(BatchStorageInterface::class);

        // Check that the job at this position is completed
        $jobRecord = $storage->getJobByPosition($batchId, $expectedPosition);
        $this->assertNotNull($jobRecord, "Job at position {$expectedPosition} should exist");
        $this->assertEquals('completed', $jobRecord['status'], "Job at position {$expectedPosition} should be completed");

        // Check batch progress
        $batch = $storage->getBatch($batchId);
        $this->assertNotNull($batch, "Batch {$batchId} should exist");
        $this->assertGreaterThanOrEqual($expectedPosition + 1, $batch->completedJobs, 'Batch should have at least ' . ($expectedPosition + 1) . ' completed jobs');
    }

    /**
     * Assert that chain context contains expected data
     *
     * @param string $batchId Batch ID
     * @param array $expectedContextData Expected context data
     * @return void
     */
    protected function assertChainContextContains(string $batchId, array $expectedContextData): void
    {
        $storage = $this->getContainer()->get(BatchStorageInterface::class);
        $batch = $storage->getBatch($batchId);

        $this->assertNotNull($batch, "Batch {$batchId} should exist");

        foreach ($expectedContextData as $key => $expectedValue) {
            $this->assertArrayHasKey($key, $batch->context, "Context should contain key '{$key}'");
            $this->assertEquals($expectedValue, $batch->context[$key], "Context['{$key}'] should equal expected value");
        }
    }

    /**
     * Assert that next chain job is queued
     *
     * @param string $batchId Batch ID
     * @param int $expectedPosition Expected job position
     * @return void
     */
    protected function assertNextChainJobQueued(string $batchId, int $expectedPosition): void
    {
        $messages = $this->getQueuedMessages('default');
        $found = false;

        foreach ($messages as $message) {
            $args = $message['data']['args'][0] ?? [];
            if (
                ($args['batch_id'] ?? '') === $batchId &&
                ($args['job_position'] ?? -1) === $expectedPosition
            ) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Next chain job at position {$expectedPosition} should be queued for batch {$batchId}");
    }

    /**
     * Assert batch has specific size
     *
     * @param int $expected Expected batch size
     * @param string $message Assertion message
     * @return void
     */
    protected function assertBatchSize(int $expected, string $message = ''): void
    {
        $actual = $this->getQueuedJobCount();
        $this->assertSame($expected, $actual, $message ?: "Expected $expected messages in batch, found $actual");
    }
}
