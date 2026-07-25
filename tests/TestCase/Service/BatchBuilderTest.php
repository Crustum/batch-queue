<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Service\BatchBuilder;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Crustum\BatchQueue\Test\Support\TestJob;
use Crustum\BatchQueue\Test\Support\TestQueueConfig;
use Crustum\BatchQueue\Test\Support\TestQueueName;
use InvalidArgumentException;
use ReflectionProperty;

/**
 * BatchBuilder Test Case (Wave D prepend/append/enums)
 */
class BatchBuilderTest extends TestCase
{
    private BatchStorageInterface $storage;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->createStub(BatchStorageInterface::class);
    }

    /**
     * @return \Crustum\BatchQueue\Service\BatchBuilder
     */
    private function builder(array $jobs = []): BatchBuilder
    {
        return new BatchBuilder(
            $this->storage,
            null,
            'test_queue',
            BatchDefinition::TYPE_SEQUENTIAL,
            $jobs,
        );
    }

    /**
     * @return void
     */
    public function testPrependAndAppendJobs(): void
    {
        $builder = $this->builder([TestJob::class])
            ->prepend('PrependJob')
            ->append(['class' => 'AppendJob', 'args' => ['x' => 1]]);

        $jobs = $builder->getJobs();

        $this->assertSame('PrependJob', $jobs[0]);
        $this->assertSame(TestJob::class, $jobs[1]);
        $this->assertSame(['class' => 'AppendJob', 'args' => ['x' => 1]], $jobs[2]);
    }

    /**
     * @return void
     */
    public function testPrependMultipleJobsPreservesOrder(): void
    {
        $builder = $this->builder([TestJob::class])
            ->prepend(['FirstJob', 'SecondJob']);

        $this->assertSame(['FirstJob', 'SecondJob', TestJob::class], $builder->getJobs());
    }

    /**
     * @return void
     */
    public function testAppendFiltersFalsy(): void
    {
        $builder = $this->builder([])
            ->append([null, TestJob::class, false]);

        $this->assertSame([TestJob::class], $builder->getJobs());
    }

    /**
     * @return void
     */
    public function testQueueAcceptsBackedEnum(): void
    {
        $builder = $this->builder([TestJob::class])->queue(TestQueueName::Mail);

        $ref = new ReflectionProperty($builder, 'queueName');
        $this->assertSame('mail-queue', $ref->getValue($builder));
    }

    /**
     * @return void
     */
    public function testQueueConfigAcceptsUnitEnum(): void
    {
        $builder = $this->builder([TestJob::class])->queueConfig(TestQueueConfig::BatchJob);

        $ref = new ReflectionProperty($builder, 'queueConfig');
        $this->assertSame('BatchJob', $ref->getValue($builder));
    }

    /**
     * @return void
     */
    public function testGetProgressReturnsIntPercentage(): void
    {
        $batch = new BatchDefinition(
            'progress-1',
            BatchDefinition::TYPE_PARALLEL,
            [TestJob::class, TestJob::class, TestJob::class],
        );
        $batch->completedJobs = 1;

        $storage = $this->createStub(BatchStorageInterface::class);
        $storage->method('getBatch')->willReturn($batch);

        $manager = new BatchManager($storage, null, 'test_queue');
        $progress = $manager->getProgress('progress-1');

        $this->assertIsInt($progress['progress_percentage']);
        $this->assertSame(33, $progress['progress_percentage']);
    }

    /**
     * @return void
     */
    public function testAllowFailuresSetsOption(): void
    {
        $builder = $this->parallelBuilder([TestJob::class])->allowFailures();

        $this->assertTrue($builder->getOptions()['allow_failures']);
    }

    /**
     * @return void
     */
    public function testAllowFailuresFalseClearsMode(): void
    {
        $builder = $this->parallelBuilder([TestJob::class])
            ->allowFailures(true)
            ->allowFailures(false);

        $this->assertFalse($builder->getOptions()['allow_failures']);
    }

    /**
     * @return void
     */
    public function testOnJobFailureSetsOption(): void
    {
        $builder = $this->parallelBuilder([TestJob::class])
            ->allowFailures()
            ->onJobFailure(TestJob::class);

        $this->assertSame(TestJob::class, $builder->getOptions()['on_job_failure']);
    }

    /**
     * @return void
     */
    public function testAllowFailuresRejectsSequential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential');

        $this->builder([TestJob::class])->allowFailures();
    }

    /**
     * @return void
     */
    public function testAllowFailuresRejectsCompensationPairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('compensation');

        $this->parallelBuilder([[TestJob::class, TestJob::class]])->allowFailures();
    }

    /**
     * @return void
     */
    public function testDispatchRejectsAllowFailuresAfterPrependCompensation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('compensation');

        $this->parallelBuilder([TestJob::class])
            ->allowFailures()
            ->prepend([[TestJob::class, TestJob::class]])
            ->dispatch();
    }

    /**
     * @return \Crustum\BatchQueue\Service\BatchBuilder
     */
    private function parallelBuilder(array $jobs = []): BatchBuilder
    {
        return new BatchBuilder(
            $this->storage,
            null,
            'test_queue',
            BatchDefinition::TYPE_PARALLEL,
            $jobs,
        );
    }
}
