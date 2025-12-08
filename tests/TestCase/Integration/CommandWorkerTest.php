<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Integration;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJobs\SimpleTestJob;
use Exception;

/**
 * Test BatchQueue using actual queue worker command execution
 */
class CommandWorkerTest extends BaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SimpleTestJob::reset();
    }

    /**
     * Test real batch of 3 jobs using actual queue worker command
     */
    public function testRealBatchOf3BatchJobs(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $batch = $batchManager->batch([
            SimpleTestJob::class,
            SimpleTestJob::class,
            SimpleTestJob::class,
        ]);
        $batchId = $batch->dispatch();

        $this->refreshQM();

        $batchJobCount = $this->countMessages('batchjob');
        $this->assertEquals(3, $batchJobCount, 'Should have 3 jobs queued to batchjob queue');

        $batchJobCount = $this->countMessages('batchjob');

        $this->assertEquals(3, $batchJobCount, 'Should have 3 jobs queued to batchjob queue');

        try {
            $this->exec('queue worker --config=batchjob --queue=batchjob --max-jobs=6 --max-runtime=10');
        } catch (Exception $e) {
            echo 'EXCEPTION during worker command: ' . $e->getMessage() . "\n";
            echo 'Stack trace: ' . $e->getTraceAsString() . "\n";
            throw $e;
        }

        $storage = new SqlBatchStorage();
        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Batch should be completed');
        $this->assertEquals(3, $batchData->completedJobs, 'All 3 jobs should be completed');
    }

    /**
     * Test real chain of 2 jobs using actual queue worker command
     */
    public function testRealChainOf2Jobs(): void
    {
        $storage = new SqlBatchStorage();
        $batchManager = new BatchManager($storage);

        $chain = $batchManager->chain([
            SimpleTestJob::class,
            SimpleTestJob::class,
        ]);
        $batchId = $chain->dispatch();
        $this->refreshQM();

        $chainedCount = $this->countMessages('chainedjobs');
        $this->assertEquals(1, $chainedCount, 'Should have 1 chain job queued initially');

        try {
            $this->exec('queue worker --config=chainedjobs --max-jobs=3 --max-runtime=5 -v');
        } catch (Exception $e) {
            throw $e;
        }
        $batchData = $storage->getBatch($batchId);
        $this->refreshQM();

        $chainedCount = $this->countMessages('chainedjobs');
        $this->assertEquals(1, $chainedCount, 'Second chain job should be queued');

        try {
            $this->exec('queue worker --config=chainedjobs --max-jobs=1 --max-runtime=5 -v');
        } catch (Exception $e) {
            echo 'EXCEPTION during worker command: ' . $e->getMessage() . "\n";
            echo 'Stack trace: ' . $e->getTraceAsString() . "\n";
            throw $e;
        }

        $batchData = $storage->getBatch($batchId);

        $this->assertEquals('completed', $batchData->status, 'Chain should be completed');
        $this->assertEquals(2, $batchData->completedJobs, 'Both jobs should be completed');
    }

    private function listAllQueueFiles(): void
    {
        $queues = ['default', 'batch', 'batchjob', 'chainedjobs'];

        echo "\n=== All Queue Messages ===\n";
        foreach ($queues as $queueName) {
            $count = $this->countMessages($queueName);
            echo "  Queue '{$queueName}': {$count} messages\n";
        }
    }
}
