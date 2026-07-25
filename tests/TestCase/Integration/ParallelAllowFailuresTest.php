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
use Crustum\BatchQueue\Test\Support\TestJobs\PerJobFailureCallbackJob;

/**
 * Parallel batch failure modes via real queue workers
 *
 * Covers strict (default) fail-once accounting and allowFailures settle-when-done.
 */
class ParallelAllowFailuresTest extends BaseIntegrationTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        AccumulatorTestJob::reset();
    }

    /**
     * Strict mode: first failure marks batch failed and fires onFailure once
     *
     * @return void
     */
    public function testStrictFirstFailureMarksBatchFailedAndFiresOnFailure(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batchId = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 1]],
            ['class' => FailingTestJob::class, 'args' => ['error_message' => 'strict boom']],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 3]],
        ])
            ->onComplete([
                'class' => AccumulateResultsCallbackJob::class,
            ])
            ->onFailure([
                'class' => FailureCallbackJob::class,
            ])
            ->dispatch();

        $this->assertEquals(3, $this->countMessages('batchjob'));

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=10 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);
        $this->assertEquals('failed', $batchData->status);
        $this->assertEquals(1, $batchData->failedJobs);
        $this->assertGreaterThanOrEqual(1, $batchData->completedJobs);

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=4 --max-runtime=10');

        $updated = $storage->getBatch($batchId);
        $this->assertTrue($updated->context['failure_handled'] ?? false);
        $this->assertArrayNotHasKey('accumulated_sum', $updated->context ?? []);
        $this->assertEquals('failed', $updated->status);
    }

    /**
     * allowFailures: mixed results settle completed with callbacks
     *
     * @return void
     */
    public function testAllowFailuresSettlesWithMixedResultsAndCallbacks(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batchId = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 1]],
            ['class' => FailingTestJob::class, 'args' => ['error_message' => 'allow boom']],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 3]],
        ])
            ->allowFailures()
            ->onJobFailure([
                'class' => PerJobFailureCallbackJob::class,
            ])
            ->onComplete([
                'class' => AccumulateResultsCallbackJob::class,
            ])
            ->onFailure([
                'class' => FailureCallbackJob::class,
            ])
            ->dispatch();

        $this->assertEquals(3, $this->countMessages('batchjob'));

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=12 --max-runtime=20');

        $batchData = $storage->getBatch($batchId);
        $this->assertEquals('completed', $batchData->status);
        $this->assertEquals(2, $batchData->completedJobs);
        $this->assertEquals(1, $batchData->failedJobs);

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=6 --max-runtime=15');

        $updated = $storage->getBatch($batchId);
        $this->assertEquals('completed', $updated->status);
        $this->assertArrayHasKey('accumulated_sum', $updated->context);
        $this->assertSame(4, $updated->context['accumulated_sum']);
        $this->assertTrue($updated->context['failure_handled'] ?? false);
        $this->assertSame(1, $updated->context['job_failure_count'] ?? 0);
        $this->assertCount(1, $updated->context['job_failures'] ?? []);
        $this->assertSame('allow boom', $updated->context['job_failures'][0]['error'] ?? null);
    }

    /**
     * allowFailures with all successes runs onComplete only
     *
     * @return void
     */
    public function testAllowFailuresAllSuccessSkipsOnFailure(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batchId = $batchManager->batch([
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 2]],
            ['class' => AccumulatorTestJob::class, 'args' => ['value' => 5]],
        ])
            ->allowFailures()
            ->onComplete([
                'class' => AccumulateResultsCallbackJob::class,
            ])
            ->onFailure([
                'class' => FailureCallbackJob::class,
            ])
            ->dispatch();

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=8 --max-runtime=15');

        $batchData = $storage->getBatch($batchId);
        $this->assertEquals('completed', $batchData->status);
        $this->assertEquals(2, $batchData->completedJobs);
        $this->assertEquals(0, $batchData->failedJobs);

        $this->refreshQM();
        $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=4 --max-runtime=10');

        $updated = $storage->getBatch($batchId);
        $this->assertSame(7, $updated->context['accumulated_sum'] ?? null);
        $this->assertArrayNotHasKey('failure_handled', $updated->context ?? []);
    }
}
