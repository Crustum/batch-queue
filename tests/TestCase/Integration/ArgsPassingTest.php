<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulateResultsCallbackJob;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulatorTestJob;
use Exception;

/**
 * Test args passing and accumulation in BatchQueue
 *
 * Tests that job-specific args are correctly passed to jobs and can be used
 * for map-reduce style accumulation patterns
 */
class ArgsPassingTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccumulatorTestJob::reset();
    }

    /**
     * Test batch with job-specific args and accumulation
     *
     * Passes values 1, 2, 3 to three jobs and verifies they receive correct args
     * and can accumulate results (map-reduce pattern)
     *
     * @return void
     */
    public function testBatchWithJobArgsAndAccumulation(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 1]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 2]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 3]],
        ])
        ->onComplete([
            'class' => AccumulateResultsCallbackJob::class,
        ]);
        $batchId = $batch->dispatch();

        $batchJobCount = $this->countMessages('batchjob');
        $this->assertEquals(3, $batchJobCount, 'Should have 3 jobs queued to batchjob queue');

        try {
            $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=10 --max-runtime=10');
        } catch (Exception $e) {
            echo 'EXCEPTION during worker command: ' . $e->getMessage() . "\n";
            throw $e;
        }

        $storage = new SqlBatchStorage();
        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(3, $batchData->completedJobs, 'All 3 jobs should be completed');

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(3, $executedJobs, 'All 3 jobs should have executed');

        $values = [];
        foreach ($executedJobs as $job) {
            $this->assertArrayHasKey('value', $job, 'Job should have value in executed data');
            $this->assertArrayHasKey('batch_id', $job, 'Job should have batch_id');
            $this->assertArrayHasKey('job_position', $job, 'Job should have job_position');
            $values[] = $job['value'];
        }

        sort($values);
        $this->assertEquals([1, 2, 3], $values, 'Jobs should have received correct values');

        $results = $storage->getBatchResults($batchId);
        $this->assertCount(3, $results, 'Should have 3 job results');

        $this->refreshQM();

        try {
            $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=2 --max-runtime=5 --verbose');
        } catch (Exception $e) {
            echo 'EXCEPTION during callback worker: ' . $e->getMessage() . "\n";
        }

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('results', $updatedBatch->context, 'Context should have results after callback job executed');
        $this->assertArrayHasKey('accumulated_sum', $updatedBatch->context, 'Context should have accumulated_sum after callback job executed');
        $this->assertEquals(6, $updatedBatch->context['accumulated_sum'], 'Accumulated sum should be 6 (1+2+3) - calculated by callback job');
        $this->assertCount(3, $updatedBatch->context['results'], 'Context should have 3 results stored by callback job');
    }

    /**
     * Test batch with job args and shared context
     *
     * Tests that both job-specific args and batch context are available to jobs
     *
     * @return void
     */
    public function testBatchWithJobArgsAndContext(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 10]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 20]],
        ])->setContext(['user_id' => 123, 'operation' => 'test']);

        $batchId = $batch->dispatch();

        $storedBatch = $storage->getBatch($batchId);
        $this->assertIsArray($storedBatch->context, 'Context should be an array');
        $this->assertArrayHasKey('user_id', $storedBatch->context, 'Context should have user_id');
        $this->assertArrayHasKey('operation', $storedBatch->context, 'Context should have operation');
        $this->assertEquals(123, $storedBatch->context['user_id'], 'Context user_id should be 123');
        $this->assertEquals('test', $storedBatch->context['operation'], 'Context operation should be test');

        $this->refreshQM();

        $batchJobCount = $this->countMessages('batchjob');
        $this->assertEquals(2, $batchJobCount, 'Should have 2 jobs queued to batchjob queue');

        try {
            $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=4 --max-runtime=10');
        } catch (Exception $e) {
            throw $e;
        }

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
     * Test sequential chain with args and accumulation
     *
     * Tests that args are passed correctly in sequential chains
     *
     * @return void
     */
    public function testChainWithJobArgs(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        AccumulatorTestJob::reset();

        $chain = $batchManager->chain([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 5]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 10]],
        ]);
        $batchId = $chain->dispatch();
        $this->refreshQM();

        $this->refreshQM();

        $chainedCount = $this->countMessages('chainedjobs');
        $this->assertEquals(1, $chainedCount, 'Should have 1 chain job queued initially');

        try {
            $this->exec('queue worker --config=chainedjobs --max-jobs=3 --max-runtime=5 -v');
        } catch (Exception $e) {
            throw $e;
        }
        $this->refreshQM();

        $chainedCount = $this->countMessages('chainedjobs');
        $this->assertEquals(1, $chainedCount, 'Second chain job should be queued');

        try {
            $this->exec('queue worker --config=chainedjobs --max-jobs=1 --max-runtime=5 -v');
        } catch (Exception $e) {
            throw $e;
        }

        $batchData = $storage->getBatch($batchId);
        $this->assertEquals('completed', $batchData->status, 'Chain should be completed');
        $this->assertEquals(2, $batchData->completedJobs, 'Both jobs should be completed');

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(2, $executedJobs, 'Both chain jobs should have executed');

        $values = [];
        foreach ($executedJobs as $job) {
            $values[] = $job['value'];
        }

        $this->assertContains(5, $values, 'First job should have value 5');
        $this->assertContains(10, $values, 'Second job should have value 10');

        $results = $storage->getBatchResults($batchId);
        $this->assertCount(2, $results, 'Should have 2 job results');

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

        $this->assertEquals(15, $accumulatedSum, 'Accumulated total should be 15 (5+10)');
    }

    /**
     * Test mixed job args (some with args, some without)
     *
     * @return void
     */
    public function testBatchWithMixedJobArgs(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        AccumulatorTestJob::reset();

        $batch = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 100]],
            AccumulatorTestJob::class,
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 200]],
        ]);
        $batch->dispatch();

        $batchJobCount = $this->countMessages('batchjob');
        $this->assertEquals(3, $batchJobCount, 'Should have 3 jobs queued to batchjob queue');

        $this->refreshQM();

        try {
            $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=6 --max-runtime=10');
        } catch (Exception $e) {
            throw $e;
        }

        $executedJobs = AccumulatorTestJob::$executedJobs;
        $this->assertCount(3, $executedJobs, 'All 3 jobs should have executed');

        $values = [];
        foreach ($executedJobs as $job) {
            $values[] = $job['value'] ?? 0;
        }

        $this->assertContains(100, $values, 'First job should have value 100');
        $this->assertContains(0, $values, 'Second job should have default value 0 (no args)');
        $this->assertContains(200, $values, 'Third job should have value 200');
    }
}
