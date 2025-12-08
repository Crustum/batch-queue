<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\ContextReceiverJob;
use Crustum\BatchQueue\Test\Support\TestJobs\ContextUpdaterAddsJob;
use Crustum\BatchQueue\Test\Support\TestJobs\DynamicJobAdderJob;
use Crustum\BatchQueue\Test\Support\TestJobs\Job1;
use Crustum\BatchQueue\Test\Support\TestJobs\Job1AddsJob2And3;
use Crustum\BatchQueue\Test\Support\TestJobs\Job1AddsJob3;
use Crustum\BatchQueue\Test\Support\TestJobs\Job2;
use Crustum\BatchQueue\Test\Support\TestJobs\Job2AddsJob4;
use Crustum\BatchQueue\Test\Support\TestJobs\Job3;
use Crustum\BatchQueue\Test\Support\TestJobs\Job4;

/**
 * Dynamic Job Addition Integration Test
 *
 * Test 1 from plan: Sequential Batch - Job Adds New Steps During Execution
 *
 * Scenario:
 * - Create sequential batch with 2 initial jobs
 * - First job executes and adds 2 more jobs to the batch
 * - Verify all 4 jobs execute in correct order
 * - Verify batch completes only after all jobs (including dynamically added ones)
 */
class DynamicJobAdditionIntegrationTest extends BaseIntegrationTestCase
{
    /**
     * Setup
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        ContextReceiverJob::reset();
        ContextUpdaterAddsJob::reset();
        DynamicJobAdderJob::reset();
        Job1::reset();
        Job1AddsJob2And3::reset();
        Job1AddsJob3::reset();
        Job2::reset();
        Job2AddsJob4::reset();
        Job3::reset();
        Job4::reset();
    }

    /**
     * Test 1: Sequential Batch - Job Adds New Steps During Execution
     *
     * Create sequential batch: [DynamicJobAdderJob, Job2]
     * DynamicJobAdderJob adds [Job3, Job4] during execution
     * Verify execution order: DynamicJobAdderJob → Job2 → Job3 → Job4
     * Verify batch completes only after Job4 finishes
     *
     * @return void
     */
    public function testSequentialBatchJobAddsNewStepsDuringExecution(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => DynamicJobAdderJob::class,
                'args' => [
                    'jobs_to_add' => [Job3::class, Job4::class],
                ],
            ],
            Job2::class,
        ]);

        $batchId = $batch->dispatch();

        $batchData = $storage->getBatch($batchId);
        $this->assertNotNull($batchData);
        $this->assertEquals(2, $batchData->totalJobs, 'Initial batch should have 2 jobs');
        $this->assertEquals('sequential', $batchData->type);

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(4, $batchData->totalJobs, 'Total jobs should be 4 after dynamic addition');
        $this->assertEquals(4, $batchData->completedJobs, 'All 4 jobs should be completed');
        $this->assertEquals(0, $batchData->failedJobs, 'No jobs should be failed');

        $jobs = $storage->getAllJobs($batchId);
        $this->assertCount(4, $jobs, 'Should have 4 jobs total');

        $positions = array_column($jobs, 'position');
        $this->assertEquals([0, 1, 2, 3], $positions, 'Jobs should have correct positions');

        $executionLog = array_merge(
            DynamicJobAdderJob::$executionLog,
            Job2::$executionLog,
            Job3::$executionLog,
            Job4::$executionLog,
        );

        usort($executionLog, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        $this->assertCount(4, $executionLog, 'All 4 jobs should have executed');
        $this->assertEquals('DynamicJobAdderJob', $executionLog[0]['job'], 'First job should be DynamicJobAdderJob');
        $this->assertEquals('Job2', $executionLog[1]['job'], 'Second job should be Job2');
        $this->assertEquals('Job3', $executionLog[2]['job'], 'Third job should be Job3');
        $this->assertEquals('Job4', $executionLog[3]['job'], 'Fourth job should be Job4');
    }

    /**
     * Test 2: Sequential Batch - Job Adds Steps That Add More Steps
     *
     * Create sequential batch: [Job1AddsJob2And3]
     * Job1 adds Job2AddsJob4 and Job3
     * Job2AddsJob4 adds Job4
     * Verify execution order: Job1 → Job2 → Job3 → Job4
     * Verify batch completes with all 4 jobs
     *
     * @return void
     */
    public function testSequentialBatchNestedJobAddition(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([Job1AddsJob2And3::class]);

        $batchId = $batch->dispatch();

        $batchData = $storage->getBatch($batchId);
        $this->assertNotNull($batchData);
        $this->assertEquals(1, $batchData->totalJobs, 'Initial batch should have 1 job');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(4, $batchData->totalJobs, 'Total jobs should be 4 after nested additions');
        $this->assertEquals(4, $batchData->completedJobs, 'All 4 jobs should be completed');
        $this->assertEquals(0, $batchData->failedJobs, 'No jobs should be failed');

        $jobs = $storage->getAllJobs($batchId);
        $this->assertCount(4, $jobs, 'Should have 4 jobs total');

        $positions = array_column($jobs, 'position');
        $this->assertEquals([0, 1, 2, 3], $positions, 'Jobs should have correct positions');

        $executionLog = array_merge(
            Job1AddsJob2And3::$executionLog,
            Job2AddsJob4::$executionLog,
            Job3::$executionLog,
            Job4::$executionLog,
        );

        usort($executionLog, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        $this->assertCount(4, $executionLog, 'All 4 jobs should have executed');
        $this->assertEquals('Job1AddsJob2And3', $executionLog[0]['job'], 'First job should be Job1AddsJob2And3');
        $this->assertEquals('Job2AddsJob4', $executionLog[1]['job'], 'Second job should be Job2AddsJob4');
        $this->assertEquals('Job3', $executionLog[2]['job'], 'Third job should be Job3');
        $this->assertEquals('Job4', $executionLog[3]['job'], 'Fourth job should be Job4');
    }

    /**
     * Test 3: Sequential Batch - Job Adds Steps Near Completion
     *
     * Create sequential batch: [Job1AddsJob3, Job2]
     * Job1 adds Job3
     * Job2 is the last original job
     * Verify Job3 executes after Job2, not skipped
     *
     * @return void
     */
    public function testSequentialBatchJobAddsStepsNearCompletion(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([Job1AddsJob3::class, Job2::class]);

        $batchId = $batch->dispatch();

        $batchData = $storage->getBatch($batchId);
        $this->assertNotNull($batchData);
        $this->assertEquals(2, $batchData->totalJobs, 'Initial batch should have 2 jobs');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(3, $batchData->totalJobs, 'Total jobs should be 3 after addition');
        $this->assertEquals(3, $batchData->completedJobs, 'All 3 jobs should be completed');
        $this->assertEquals(0, $batchData->failedJobs, 'No jobs should be failed');

        $jobs = $storage->getAllJobs($batchId);
        $this->assertCount(3, $jobs, 'Should have 3 jobs total');

        $positions = array_column($jobs, 'position');
        $this->assertEquals([0, 1, 2], $positions, 'Jobs should have correct positions');

        $executionLog = array_merge(
            Job1AddsJob3::$executionLog,
            Job2::$executionLog,
            Job3::$executionLog,
        );

        usort($executionLog, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        $this->assertCount(3, $executionLog, 'All 3 jobs should have executed');
        $this->assertEquals('Job1AddsJob3', $executionLog[0]['job'], 'First job should be Job1AddsJob3');
        $this->assertEquals('Job2', $executionLog[1]['job'], 'Second job should be Job2');
        $this->assertEquals('Job3', $executionLog[2]['job'], 'Third job should be Job3 (added dynamically)');
    }

    /**
     * Test 4: Parallel Batch - Job Adds New Steps
     *
     * Create parallel batch: [Job1, Job2]
     * Job1 adds Job3 and Job4 during execution
     * Verify all 4 jobs execute (original 2 + 2 new)
     *
     * @return void
     */
    public function testParallelBatchJobAddsNewSteps(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            [
                'class' => DynamicJobAdderJob::class,
                'args' => [
                    'jobs_to_add' => [Job3::class, Job4::class],
                ],
            ],
            Job2::class,
        ]);

        $batchId = $batch->dispatch();

        $batchData = $storage->getBatch($batchId);
        $this->assertNotNull($batchData);
        $this->assertEquals(2, $batchData->totalJobs, 'Initial batch should have 2 jobs');
        $this->assertEquals('parallel', $batchData->type);

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(4, $batchData->totalJobs, 'Total jobs should be 4 after dynamic addition');
        $this->assertEquals(4, $batchData->completedJobs, 'All 4 jobs should be completed');
        $this->assertEquals(0, $batchData->failedJobs, 'No jobs should be failed');

        $jobs = $storage->getAllJobs($batchId);
        $this->assertCount(4, $jobs, 'Should have 4 jobs total');

        $positions = array_column($jobs, 'position');
        sort($positions);
        $this->assertEquals([0, 1, 2, 3], $positions, 'Jobs should have correct positions');

        $executionLog = array_merge(
            DynamicJobAdderJob::$executionLog,
            Job2::$executionLog,
            Job3::$executionLog,
            Job4::$executionLog,
        );

        $this->assertCount(4, $executionLog, 'All 4 jobs should have executed');
    }

    /**
     * Test 5: Context Propagation to Dynamically Added Jobs
     *
     * Create sequential batch with context: ['step' => 1]
     * ContextUpdaterAddsJob updates context: ['step' => 2, 'data' => 'value']
     * ContextUpdaterAddsJob adds ContextReceiverJob
     * Verify ContextReceiverJob receives updated context
     *
     * @return void
     */
    public function testContextPropagationToDynamicallyAddedJobs(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([ContextUpdaterAddsJob::class])
            ->setContext(['step' => 1])
            ->dispatch();

        $batchId = $batch;

        $batchData = $storage->getBatch($batchId);
        $this->assertNotNull($batchData);
        $this->assertEquals(1, $batchData->totalJobs, 'Initial batch should have 1 job');
        $this->assertEquals(['step' => 1], $batchData->context, 'Initial context should be set');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(2, $batchData->totalJobs, 'Total jobs should be 2 after addition');
        $this->assertEquals(2, $batchData->completedJobs, 'All 2 jobs should be completed');

        $this->assertNotEmpty(ContextUpdaterAddsJob::$contexts, 'ContextUpdaterAddsJob should have recorded context');
        $updaterContext = ContextUpdaterAddsJob::$contexts[0];
        $this->assertEquals(2, $updaterContext['step'], 'Context step should be updated to 2');
        $this->assertEquals('value', $updaterContext['data'], 'Context data should be set to "value"');

        $this->assertNotEmpty(ContextReceiverJob::$contexts, 'ContextReceiverJob should have recorded context');
        $receiverContext = ContextReceiverJob::$contexts[0];
        $this->assertEquals(2, $receiverContext['step'], 'Dynamically added job should receive updated step = 2');
        $this->assertEquals('value', $receiverContext['data'], 'Dynamically added job should receive updated data = "value"');

        $finalBatchContext = $storage->getBatch($batchId)->context;
        $this->assertEquals(2, $finalBatchContext['step'], 'Final batch context should have step = 2');
        $this->assertEquals('value', $finalBatchContext['data'], 'Final batch context should have data = "value"');
    }
}
