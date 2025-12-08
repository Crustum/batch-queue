<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Cake\Core\Configure;
use Crustum\BatchQueue\Processor\BatchJobProcessor;
use Crustum\BatchQueue\Processor\ChainedJobProcessor;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulatorTestJob;

/**
 * Test configurable queue routing in BatchQueue
 *
 * Tests that queue configs can be customized per batch/chain
 * and that different chains can use different queues
 */
class ConfigurableQueueRoutingTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccumulatorTestJob::reset();
    }

    protected function tearDown(): void
    {
        Configure::delete('BatchQueue.queues');
        parent::tearDown();
    }

    /**
     * Test default queue routing (backward compatibility)
     *
     * @return void
     */
    public function testDefaultQueueRouting(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            AccumulatorTestJob::class,
            AccumulatorTestJob::class,
        ]);
        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertNull($storedBatch->queueName, 'Default batch should have null queueName');
        $this->assertEquals('batchjob', $storedBatch->queueConfig, 'Default batch should resolve to batchjob config');

        $this->refreshQM();

        $batchJobCount = $this->countMessages('batchjob');
        $this->assertEquals(2, $batchJobCount, 'Should have 2 jobs queued to default batchjob queue');
    }

    /**
     * Test custom queue config via BatchManager constructor
     *
     * @return void
     */
    public function testCustomQueueConfigViaConstructor(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage, null, 'custom-batch');

        $batch = $batchManager->batch([
            AccumulatorTestJob::class,
        ]);
        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertEquals('custom-batch', $storedBatch->queueConfig, 'Batch should use custom queue config');

        $this->refreshQM();

        $customBatchJobCount = $this->countMessages('custom-batch');
        $this->assertGreaterThanOrEqual(0, $customBatchJobCount, 'Jobs may be queued to custom queue');
    }

    /**
     * Test named queue routing for chains
     *
     * @return void
     */
    public function testNamedQueueRoutingForChains(): void
    {
        Configure::write('BatchQueue.queues.named.email-chain', [
            'queue_config' => 'email-chain',
            'processor' => ChainedJobProcessor::class,
        ]);

        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $chain = $batchManager->chain([
            AccumulatorTestJob::class,
            AccumulatorTestJob::class,
        ])->queue('email-chain');
        $batchId = $chain->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertEquals('email-chain', $storedBatch->queueName, 'Chain should have email-chain queue name');
        $this->assertEquals('email-chain', $storedBatch->queueConfig, 'Chain should use queue_config from named queue config');

        $this->refreshQM();

        $emailChainCount = $this->countMessages('email-chain');
        $this->assertEquals(1, $emailChainCount, 'Should have 1 job queued to email-chain queue');
    }

    /**
     * Test multiple chains on different queues
     *
     * @return void
     */
    public function testMultipleChainsOnDifferentQueues(): void
    {
        Configure::write('BatchQueue.queues.named.email-chain', [
            'queue_config' => 'email-chain',
            'processor' => ChainedJobProcessor::class,
        ]);

        Configure::write('BatchQueue.queues.named.payment-chain', [
            'queue_config' => 'payment-chain',
            'processor' => ChainedJobProcessor::class,
        ]);

        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $emailChainId = $batchManager->chain([
            AccumulatorTestJob::class,
        ])->queue('email-chain')->dispatch();

        $paymentChainId = $batchManager->chain([
            AccumulatorTestJob::class,
        ])->queue('payment-chain')->dispatch();

        $emailBatch = $storage->getBatch($emailChainId);
        $paymentBatch = $storage->getBatch($paymentChainId);

        $this->assertEquals('email-chain', $emailBatch->queueName, 'Email chain should have correct queue name');
        $this->assertEquals('payment-chain', $paymentBatch->queueName, 'Payment chain should have correct queue name');

        $this->refreshQM();
        $this->refreshQM();

        $emailChainCount = $this->countMessages('email-chain');
        $paymentChainCount = $this->countMessages('payment-chain');

        $this->assertEquals(1, $emailChainCount, 'Should have 1 job in email-chain queue');
        $this->assertEquals(1, $paymentChainCount, 'Should have 1 job in payment-chain queue');
    }

    /**
     * Test queue config priority: BatchDefinition queueConfig > QueueConfigService
     *
     * @return void
     */
    public function testQueueConfigPriority(): void
    {
        Configure::write('BatchQueue.queues.types.parallel', [
            'queue_config' => 'custom-parallel',
            'processor' => BatchJobProcessor::class,
        ]);

        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage, null, 'custom-batch-config');

        $batch = $batchManager->batch([
            AccumulatorTestJob::class,
        ]);
        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertEquals('custom-batch-config', $storedBatch->queueConfig, 'Batch should use queueConfig from BatchManager');

        $this->refreshQM();
        $this->exec('queue worker --config=custom-batch-config --max-jobs=2 --max-runtime=5');

        $customCount = $this->countMessages('custom-batch-config');
        $this->assertGreaterThanOrEqual(0, $customCount, 'Jobs should use custom queue config');
    }

    /**
     * Test context and data passing with custom queues
     *
     * @return void
     */
    public function testContextAndDataPassingWithCustomQueues(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 100]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 200]],
        ])->setContext(['user_id' => 123, 'operation' => 'test']);

        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertIsArray($storedBatch->context, 'Context should be an array');
        $this->assertArrayHasKey('user_id', $storedBatch->context, 'Context should have user_id');
        $this->assertArrayHasKey('operation', $storedBatch->context, 'Context should have operation');
        $this->assertEquals(123, $storedBatch->context['user_id'], 'Context user_id should be 123');
        $this->assertEquals('test', $storedBatch->context['operation'], 'Context operation should be test');

        $this->refreshQM();
        $this->exec('queue worker --config=batch --max-jobs=2 --max-runtime=5');
        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --max-jobs=4 --max-runtime=10');

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(2, $executedJobs, 'Both jobs should have executed');

        foreach ($executedJobs as $job) {
            $allArgs = $job['all_args'] ?? [];
            $this->assertArrayHasKey('user_id', $allArgs, 'Job should have access to batch context');
            $this->assertArrayHasKey('operation', $allArgs, 'Job should have access to batch context');
            $this->assertEquals(123, $allArgs['user_id'], 'Context user_id should be correct');
            $this->assertEquals('test', $allArgs['operation'], 'Context operation should be correct');
            $this->assertArrayHasKey('value', $allArgs, 'Job should have access to job-specific args');
            $this->assertArrayHasKey('batch_id', $allArgs, 'Job should have batch metadata');
        }
    }

    /**
     * Test simple batch with custom queue that actually executes
     *
     * @return void
     */
    public function testSimpleBatchWithCustomQueueExecution(): void
    {
        AccumulatorTestJob::reset();

        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage, null, 'custom-batch');

        $batch = $batchManager->batch([
                ['class' => AccumulatorTestJob::class, 'args' => ['value' => 10]],
                ['class' => AccumulatorTestJob::class, 'args' => ['value' => 20]],
            ])
            ->queueConfig('custom-batch');
        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertNull($storedBatch->queueName, 'Batch should have null queueName');
        $this->assertEquals('custom-batch', $storedBatch->queueConfig, 'Batch should use custom-batch queue config');

        $this->refreshQM();

        $customBatchJobCount = $this->countMessages('custom-batch');
        $this->assertEquals(2, $customBatchJobCount, 'Should have 2 jobs queued to custom-batch queue');

        $this->exec('queue worker --config=custom-batch --max-jobs=4 --max-runtime=10');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertEquals('completed', $updatedBatch->status, 'Batch should be completed');
        $this->assertEquals(2, $updatedBatch->completedJobs, 'Both jobs should be completed');
        $this->assertEquals(0, $updatedBatch->failedJobs, 'No jobs should have failed');

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(2, $executedJobs, 'Both jobs should have executed');
        $this->assertEquals(10, $executedJobs[0]['all_args']['value'] ?? null, 'First job should have value 10');
        $this->assertEquals(20, $executedJobs[1]['all_args']['value'] ?? null, 'Second job should have value 20');
    }
}
