<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\AccumulatorTestJob;
use Crustum\BatchQueue\Test\Support\TestJobs\CompensationTestJob;
use Crustum\BatchQueue\Test\Support\TestJobs\FailingTestJob;

/**
 * Test compensation flow (saga pattern) in BatchQueue
 *
 * Tests automatic rollback when chain jobs fail
 */
class CompensationFlowTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccumulatorTestJob::reset();
        CompensationTestJob::reset();
    }

    /**
     * Test simple compensation on failure
     *
     * @return void
     */
    public function testSimpleCompensationOnFailure(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'create_user'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => FailingTestJob::class,
                'args' => ['error_message' => 'Second job failed'],
            ],
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();

        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=4 --max-runtime=10');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('failed', $batchData->status, 'Chain should be failed');
        $this->assertEquals(1, $batchData->completedJobs, 'First job should be completed');
        $this->assertEquals(1, $batchData->failedJobs, 'Second job should be failed');

        $this->assertArrayHasKey('compensation_batch_id', $batchData->context, 'Context should have compensation batch ID');
        $this->assertEquals('running', $batchData->context['compensation_status'], 'Compensation should be running');

        $compensationBatchId = $batchData->context['compensation_batch_id'];

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=5 --max-runtime=5');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('compensations', $updatedBatch->context, 'Context should have compensations array');
        $this->assertCount(1, $updatedBatch->context['compensations'], 'One compensation should have executed');
        $this->assertEquals('create_user', $updatedBatch->context['compensations'][0]['action'], 'Compensation should rollback create_user');

        $compensationBatch = $storage->getBatch($compensationBatchId);
        $this->assertEquals('completed', $compensationBatch->status, 'Compensation chain should be completed');
        $this->assertEquals(1, $compensationBatch->totalJobs, 'Compensation chain should have 1 job');
        $this->assertEquals(1, $compensationBatch->completedJobs, 'Compensation job should be completed');
    }

    /**
     * Test multiple compensations in reverse order
     *
     * @return void
     */
    public function testMultipleCompensationsInReverseOrder(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'create_order'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'charge_payment'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'send_email'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => FailingTestJob::class,
                'args' => ['error_message' => 'Final step failed'],
            ],
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=20 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('failed', $batchData->status, 'Chain should be failed');
        $this->assertEquals(3, $batchData->completedJobs, 'Three jobs should be completed');
        $this->assertEquals(1, $batchData->failedJobs, 'Fourth job should be failed');

        $this->assertArrayHasKey('compensation_batch_id', $batchData->context, 'Context should have compensation batch ID');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $updatedBatch = $storage->getBatch($batchId);
        $compensationBatch = $storage->getBatch($updatedBatch->context['compensation_batch_id']);
        $this->assertEquals('completed', $compensationBatch->status, 'Compensation chain should be completed');
        $this->assertArrayHasKey('compensations', $updatedBatch->context, 'Context should have compensations array');
        $this->assertCount(3, $updatedBatch->context['compensations'], 'Three compensations should have executed');

        $actions = array_column($updatedBatch->context['compensations'], 'action');
        $this->assertEquals(['send_email', 'charge_payment', 'create_order'], $actions, 'Compensations should execute in reverse order');
    }

    /**
     * Test chain success without compensation
     *
     * @return void
     */
    public function testChainSuccessDoesNotTriggerCompensation(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'create_order'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'charge_payment'],
                'compensation' => CompensationTestJob::class,
            ],
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Chain should be completed');
        $this->assertEquals(2, $batchData->completedJobs, 'Both jobs should be completed');
        $this->assertEquals(0, $batchData->failedJobs, 'No jobs should be failed');

        $compensated = CompensationTestJob::$compensatedJobs;
        $this->assertCount(0, $compensated, 'No compensations should execute on success');
    }

    /**
     * Test that failed job with compensation is NOT compensated
     *
     * When Job 2 fails (even though it has compensation defined),
     * only Jobs 0 and 1 should be compensated (the ones that completed)
     *
     * @return void
     */
    public function testFailedJobNotCompensated(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'job1'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'job2'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => FailingTestJob::class,
                'args' => ['error_message' => 'Job 3 failed'],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'job4'],
                'compensation' => CompensationTestJob::class,
            ],
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=20 --max-runtime=10');

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('failed', $batchData->status, 'Chain should be failed');
        $this->assertEquals(2, $batchData->completedJobs, 'Only first 2 jobs should be completed');
        $this->assertEquals(1, $batchData->failedJobs, 'Third job should be failed');

        $this->assertArrayHasKey('compensation_batch_id', $batchData->context, 'Compensation batch should be created');
        $this->assertEquals(2, $batchData->context['compensation_job_count'], 'Should compensate only 2 completed jobs');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=5 --max-runtime=5');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('compensations', $updatedBatch->context, 'Compensations should have executed');
        $this->assertCount(2, $updatedBatch->context['compensations'], 'Exactly 2 compensations should execute (NOT 3 or 4)');

        $actions = array_column($updatedBatch->context['compensations'], 'action');
        $this->assertEquals(['job2', 'job1'], $actions, 'Should compensate job2 then job1 (reverse order of completion)');

        $compensationBatch = $storage->getBatch($updatedBatch->context['compensation_batch_id']);
        $compensationBatch = $storage->getBatch($updatedBatch->context['compensation_batch_id']);
        $this->assertEquals(2, $compensationBatch->totalJobs, 'Compensation chain should have exactly 2 jobs');
        $this->assertEquals('completed', $compensationBatch->status, 'Compensation chain should complete');
    }

    /**
     * Test compensation with context passing
     *
     * @return void
     */
    public function testCompensationWithContextPassing(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->chain([
            [
                'class' => CompensationTestJob::class,
                'args' => ['action' => 'create_order', 'order_id' => 12345],
                'compensation' => CompensationTestJob::class,
            ],
            [
                'class' => FailingTestJob::class,
                'args' => ['error_message' => 'Payment failed'],
            ],
        ]);

        $batchId = $batch->dispatch();

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=10 --max-runtime=10');

        $batchData = $storage->getBatch($batchId);
        $this->assertArrayHasKey('compensation_batch_id', $batchData->context, 'Context should have compensation batch ID');

        $this->refreshQM();
        $this->exec('queue worker --config=chainedjobs --queue=chainedjobs --max-jobs=5 --max-runtime=5');

        $updatedBatch = $storage->getBatch($batchId);
        $this->assertArrayHasKey('compensations', $updatedBatch->context, 'Context should have compensations array');
        $this->assertCount(1, $updatedBatch->context['compensations'], 'One compensation should execute');
        $this->assertEquals('create_order', $updatedBatch->context['compensations'][0]['action'], 'Compensation should have action from original job');
    }
}
