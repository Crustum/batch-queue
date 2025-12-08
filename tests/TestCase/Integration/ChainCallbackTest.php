<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulateResultsCallbackJob;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulatorTestJob;
use Crustum\BatchQueue\Test\Support\TestJobs\FailingTestJob;
use Crustum\BatchQueue\Test\Support\TestJobs\FailureCallbackJob;

/**
 * Test chain (sequential) execution with callbacks
 *
 * Tests completion and failure callbacks for sequential chains
 */
class ChainCallbackTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccumulatorTestJob::reset();
    }

    /**
     * Test sequential chain with success callback
     *
     * @return void
     */
    public function testChainWithSuccessCallback(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 1]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 2]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 3]],
        ])
        ->onComplete([
            'class' => AccumulateResultsCallbackJob::class,
        ]);

        $batchId = $batch->dispatch();

        $this->assertNotEmpty($batchId, 'Batch ID should not be empty');

        $this->refreshQM();
        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $storage = new SqlBatchStorage();
        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Chain should be completed');
        $this->assertEquals(3, $batchData->completedJobs, 'All 3 jobs should be completed');

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(3, $executedJobs, 'All 3 jobs should have executed');

        $values = [];
        foreach ($executedJobs as $job) {
            $this->assertArrayHasKey('value', $job, 'Job should have value');
            $values[] = $job['value'];
        }

        $this->assertEquals([1, 2, 3], $values, 'Jobs should execute in order');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('results', $updatedBatch->context, 'Context should have results after callback');
        $this->assertArrayHasKey('accumulated_sum', $updatedBatch->context, 'Context should have accumulated_sum');
        $this->assertEquals(6, $updatedBatch->context['accumulated_sum'], 'Accumulated sum should be 6 (1+2+3)');
        $this->assertCount(3, $updatedBatch->context['results'], 'Context should have 3 results');
    }

    /**
     * Test sequential chain with failure callback
     *
     * @return void
     */
    public function testChainWithFailureCallback(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 1]],
            ['class' => FailingTestJob::class, 'args' => ['error_message' => 'Job 2 failed']],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 3]],
        ])
        ->onFailure([
            'class' => FailureCallbackJob::class,
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('failed', $batchData->status, 'Chain should be failed');
        $this->assertEquals(1, $batchData->completedJobs, 'Only first job should be completed');
        $this->assertEquals(1, $batchData->failedJobs, 'Second job should be failed');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=4 --max-runtime=5');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('failure_handled', $updatedBatch->context, 'Context should have failure_handled flag');
        $this->assertTrue($updatedBatch->context['failure_handled'], 'Failure should be handled');
    }

    /**
     * Test that callback doesn't overwrite job results
     *
     * @return void
     */
    public function testCallbackDoesNotOverwriteJobResults(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 10]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 20]],
        ])
        ->onComplete([
            'class' => AccumulateResultsCallbackJob::class,
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $storage->getBatch($batchId);
        $results = $storage->getBatchResults($batchId);

        $this->assertCount(2, $results, 'Should have 2 job results');

        $values = [];
        foreach ($results as $result) {
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                if ($decoded && isset($decoded['value'])) {
                    $values[] = $decoded['value'];
                }
            }
        }

        sort($values);
        $this->assertEquals([10, 20], $values, 'Original job results should not be overwritten');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertEquals(30, $updatedBatch->context['accumulated_sum'], 'Callback should accumulate correctly');
    }
}
