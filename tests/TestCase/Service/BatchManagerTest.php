<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Service;

use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Event\BatchCanceled;
use Crustum\BatchQueue\Event\BatchFinished;
use Crustum\BatchQueue\Event\BatchStarted;
use Crustum\BatchQueue\Service\BatchBuilder;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Crustum\BatchQueue\Test\Support\TestJob;
use InvalidArgumentException;
use RuntimeException;

/**
 * BatchManager Test Case
 */
class BatchManagerTest extends TestCase
{
    protected BatchManager $manager;

    protected BatchStorageInterface $storage;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->createStub(BatchStorageInterface::class);
        $this->manager = new BatchManager($this->storage, null, 'test_queue');
    }

    /**
     * Test creating parallel batch
     *
     * @return void
     */
    public function testBatch(): void
    {
        $jobs = [
            ['class' => TestJob::class, 'args' => ['param1']],
            ['class' => TestJob::class, 'args' => ['param2']],
        ];

        $builder = $this->manager->batch($jobs);

        $this->assertInstanceOf(BatchBuilder::class, $builder);
    }

    /**
     * Test creating sequential chain
     *
     * @return void
     */
    public function testChain(): void
    {
        $jobs = [
            ['class' => TestJob::class],
            ['class' => TestJob::class],
            ['class' => TestJob::class],
        ];

        $builder = $this->manager->chain($jobs);

        $this->assertInstanceOf(BatchBuilder::class, $builder);
    }

    /**
     * Falsy null/false slots are filtered before builder construction
     *
     * @return void
     */
    public function testBatchFiltersFalsyJobs(): void
    {
        $builder = $this->manager->batch([
            null,
            TestJob::class,
            false,
            ['class' => TestJob::class],
        ]);

        $this->assertInstanceOf(BatchBuilder::class, $builder);
    }

    /**
     * Chain also filters falsy entries
     *
     * @return void
     */
    public function testChainFiltersFalsyJobs(): void
    {
        $builder = $this->manager->chain([
            false,
            TestJob::class,
            null,
            TestJob::class,
        ]);

        $this->assertInstanceOf(BatchBuilder::class, $builder);
    }

    /**
     * Empty/whitespace batch ids are rejected on lookup
     *
     * @return void
     */
    public function testGetBatchRejectsEmptyId(): void
    {
        $this->assertNull($this->manager->getBatch(''));
        $this->assertNull($this->manager->getBatch('   '));
    }

    /**
     * addJobs rejects empty batch id
     *
     * @return void
     */
    public function testAddJobsRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch id must not be empty');

        $this->manager->addJobs('  ', [TestJob::class]);
    }

    /**
     * addJobs with only falsy entries is a no-op and preserves queue routing
     *
     * @return void
     */
    public function testAddJobsNoOpWhenOnlyFalsyPreservesQueue(): void
    {
        $batch = new BatchDefinition(
            'batch-1',
            BatchDefinition::TYPE_PARALLEL,
            [TestJob::class],
            queueName: 'named-queue',
            queueConfig: 'parallel-config',
        );

        $storage = $this->createMock(BatchStorageInterface::class);
        $storage->expects($this->once())
            ->method('getBatch')
            ->with('batch-1')
            ->willReturn($batch);
        $storage->expects($this->never())->method('addJobsToBatch');

        $manager = new BatchManager($storage, null, 'test_queue');
        $result = $manager->addJobs('batch-1', [null, false]);

        $this->assertSame($batch, $result);
        $this->assertSame('parallel-config', $result->queueConfig);
        $this->assertSame('named-queue', $result->queueName);
    }

    /**
     * cancelBatch returns false for empty id
     *
     * @return void
     */
    public function testCancelBatchRejectsEmptyId(): void
    {
        $this->assertFalse($this->manager->cancelBatch(''));
    }

    /**
     * cancelBatch dispatches BatchCanceled with optional exception
     *
     * @return void
     */
    public function testCancelBatchDispatchesCanceledEvent(): void
    {
        $batch = new BatchDefinition(
            'batch-cancel',
            BatchDefinition::TYPE_PARALLEL,
            [TestJob::class],
        );

        $storage = $this->createMock(BatchStorageInterface::class);
        $storage->method('getBatch')->willReturn($batch);
        $storage->expects($this->once())->method('deleteBatch')->with('batch-cancel');

        $seen = null;
        $listener = function (BatchCanceled $event) use (&$seen): void {
            $seen = $event;
        };
        EventManager::instance()->on(BatchCanceled::NAME, $listener);

        try {
            $manager = new BatchManager($storage, null, 'test_queue');
            $exception = new RuntimeException('user cancelled');
            $this->assertTrue($manager->cancelBatch('batch-cancel', $exception));
            $this->assertInstanceOf(BatchCanceled::class, $seen);
            $this->assertSame($batch, $seen->getBatch());
            $this->assertSame($exception, $seen->getException());
        } finally {
            EventManager::instance()->off(BatchCanceled::NAME, $listener);
        }
    }

    /**
     * bulk rejects compensation pairs
     *
     * @return void
     */
    public function testBulkRejectsCompensationPairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bulk() does not support compensation pairs');

        $this->manager->bulk([
            [TestJob::class, TestJob::class],
        ]);
    }

    /**
     * bulk skips falsy entries and returns zero when nothing to enqueue
     *
     * @return void
     */
    public function testBulkSkipsFalsyAndReturnsZero(): void
    {
        $count = $this->manager->bulk([null, false]);

        $this->assertSame(0, $count);
    }

    /**
     * Lifecycle event names are stable for listeners
     *
     * @return void
     */
    public function testLifecycleEventNames(): void
    {
        $this->assertSame('BatchQueue.BatchStarted', BatchStarted::NAME);
        $this->assertSame('BatchQueue.BatchFinished', BatchFinished::NAME);
        $this->assertSame('BatchQueue.BatchCanceled', BatchCanceled::NAME);
    }

    /**
     * Lifecycle events fire via EventManager
     *
     * @return void
     */
    public function testLifecycleEventsDispatch(): void
    {
        $batch = new BatchDefinition(
            'batch-events',
            BatchDefinition::TYPE_PARALLEL,
            [TestJob::class],
        );

        $started = null;
        $finished = null;
        $onStarted = function (BatchStarted $event) use (&$started): void {
            $started = $event;
        };
        $onFinished = function (BatchFinished $event) use (&$finished): void {
            $finished = $event;
        };

        EventManager::instance()->on(BatchStarted::NAME, $onStarted);
        EventManager::instance()->on(BatchFinished::NAME, $onFinished);

        try {
            EventManager::instance()->dispatch(new BatchStarted($batch));
            EventManager::instance()->dispatch(new BatchFinished($batch));

            $this->assertInstanceOf(BatchStarted::class, $started);
            $this->assertInstanceOf(BatchFinished::class, $finished);
            $this->assertSame($batch, $started->getBatch());
            $this->assertSame($batch, $finished->getBatch());
        } finally {
            EventManager::instance()->off(BatchStarted::NAME, $onStarted);
            EventManager::instance()->off(BatchFinished::NAME, $onFinished);
        }
    }
}
