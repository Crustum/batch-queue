<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Data;

use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Test\Support\Step1Job;
use Crustum\BatchQueue\Test\Support\Step2Job;
use Crustum\BatchQueue\Test\Support\Step3Job;
use Crustum\BatchQueue\Test\Support\TestJob;

/**
 * BatchDefinition Test Case
 */
class BatchDefinitionTest extends TestCase
{
    /**
     * Test creating parallel batch definition
     *
     * @return void
     */
    public function testCreateParallel(): void
    {
        $id = 'test-batch-123';
        $jobs = [
            TestJob::class,
            TestJob::class,
        ];
        $context = ['user_id' => 123];
        $options = ['timeout' => 3600];

        $batch = new BatchDefinition($id, BatchDefinition::TYPE_PARALLEL, $jobs, $context, $options);

        $this->assertEquals($id, $batch->id);
        $this->assertEquals(BatchDefinition::TYPE_PARALLEL, $batch->type);
        $this->assertEquals($context, $batch->context);
        $this->assertEquals($options, $batch->options);
        $this->assertEquals(BatchDefinition::STATUS_PENDING, $batch->status);
        $this->assertEquals(2, $batch->totalJobs);
        $this->assertEquals(BatchDefinition::TYPE_PARALLEL, $batch->type);
    }

    /**
     * Test creating sequential batch definition
     *
     * @return void
     */
    public function testCreateSequential(): void
    {
        $id = 'test-chain-456';
        $jobs = [
            Step1Job::class,
            Step2Job::class,
            Step3Job::class,
        ];

        $batch = new BatchDefinition($id, BatchDefinition::TYPE_SEQUENTIAL, $jobs);

        $this->assertEquals($id, $batch->id);
        $this->assertEquals(BatchDefinition::TYPE_SEQUENTIAL, $batch->type);
        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(BatchDefinition::TYPE_SEQUENTIAL, $batch->type);
    }

    /**
     * Test batch status methods
     *
     * @return void
     */
    public function testStatusMethods(): void
    {
        $batch = new BatchDefinition('test-id', BatchDefinition::TYPE_PARALLEL, [TestJob::class]);

        $this->assertFalse($batch->isComplete());
        $this->assertFalse($batch->hasFailed());
        $this->assertEquals(BatchDefinition::STATUS_PENDING, $batch->status);

        $batch->status = BatchDefinition::STATUS_RUNNING;
        $this->assertEquals(BatchDefinition::STATUS_RUNNING, $batch->status);

        // Mark as completed and set completed jobs
        $batch->completedJobs = $batch->totalJobs; // Mark all jobs as completed
        $batch->markCompleted();
        $this->assertTrue($batch->isComplete());
        $this->assertEquals(BatchDefinition::STATUS_COMPLETED, $batch->status);
        $this->assertInstanceOf(DateTime::class, $batch->completedAt);

        // Test failed status (create new batch since status is final)
        $failedBatch = new BatchDefinition('test-failed', BatchDefinition::TYPE_PARALLEL, [TestJob::class]);
        $failedBatch->failedJobs = 1; // Set failed jobs count
        $failedBatch->markFailed();
        $this->assertTrue($failedBatch->hasFailed());
        $this->assertEquals(BatchDefinition::STATUS_FAILED, $failedBatch->status);
    }

    /**
     * Test progress calculation
     *
     * @return void
     */
    public function testProgressCalculation(): void
    {
        $jobs = array_fill(0, 10, TestJob::class);
        $batch = new BatchDefinition('test-id', BatchDefinition::TYPE_PARALLEL, $jobs);

        // Manually set the progress state
        $batch->status = BatchDefinition::STATUS_RUNNING;
        $batch->completedJobs = 3;
        $batch->failedJobs = 1;

        // Test basic progress tracking
        $this->assertEquals(10, $batch->totalJobs);
        $this->assertEquals(3, $batch->completedJobs);
        $this->assertEquals(1, $batch->failedJobs);

        // Test manual progress updates
        $batch->completedJobs = 4;
        $this->assertEquals(4, $batch->completedJobs);

        $batch->failedJobs = 2;
        $this->assertEquals(2, $batch->failedJobs);
    }

    /**
     * Test serialization methods
     *
     * @return void
     */
    public function testSerialization(): void
    {
        $created = new DateTime('2024-01-01 12:00:00');
        $completedAt = new DateTime('2024-01-01 12:30:00');

        $batch = new BatchDefinition(
            id: 'test-serialize',
            type: BatchDefinition::TYPE_PARALLEL,
            jobs: [TestJob::class],
            context: ['test' => 'data'],
            options: ['timeout' => 3600],
        );

        // Manually set the serialization test state
        $batch->status = BatchDefinition::STATUS_COMPLETED;
        $batch->completedJobs = 1;
        $batch->failedJobs = 0;
        $batch->created = $created;
        $batch->completedAt = $completedAt;

        $array = $batch->toArray();
        $this->assertEquals('test-serialize', $array['id']);
        $this->assertEquals(BatchDefinition::TYPE_PARALLEL, $array['type']);
        // Verify jobs structure (format changed after refactoring)
        $this->assertCount(1, $array['jobs']);
        $this->assertEquals('Crustum\BatchQueue\Test\Support\TestJob', $array['jobs'][0]['class']);
        $this->assertEquals(['test' => 'data'], $array['context']);
        $this->assertEquals(['timeout' => 3600], $array['options']);
        $this->assertEquals('2024-01-01 12:00:00', $array['created']);
        $this->assertEquals('2024-01-01 12:30:00', $array['completed_at']);

        // Test array structure is complete
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('total_jobs', $array);
        $this->assertArrayHasKey('completed_jobs', $array);
        $this->assertArrayHasKey('failed_jobs', $array);
    }

    /**
     * Test JSON serialization
     *
     * @return void
     */
    public function testJsonSerialization(): void
    {
        $batch = new BatchDefinition('json-test', BatchDefinition::TYPE_PARALLEL, [TestJob::class]);

        $json = json_encode($batch);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('json-test', $decoded['id']);
        $this->assertEquals(BatchDefinition::TYPE_PARALLEL, $decoded['type']);
    }
}
