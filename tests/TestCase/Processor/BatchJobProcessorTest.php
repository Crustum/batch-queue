<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Processor;

use Cake\Core\ContainerInterface;
use Cake\Event\EventManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Event\BatchFinished;
use Crustum\BatchQueue\Processor\BatchJobProcessor;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Crustum\BatchQueue\Test\Support\TestJob;
use Crustum\BatchQueue\Test\Support\TestJobs\FailureCallbackJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * BatchJobProcessor settle / allowFailures unit tests
 */
#[AllowMockObjectsWithoutExpectations]
class BatchJobProcessorTest extends TestCase
{
    private BatchDefinition $batch;

    /**
     * @var list<array{class: string, args: array}>
     */
    private array $queued = [];

    private int $finishedEvents = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->queued = [];
        $this->finishedEvents = 0;

        EventManager::instance()->on(BatchFinished::NAME, function (): void {
            $this->finishedEvents++;
        });
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off(BatchFinished::NAME);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testStrictFirstFailureMarksFailedAndFiresOnFailureOnce(): void
    {
        $processor = $this->processor(
            [TestJob::class, TestJob::class, TestJob::class],
            [
                'on_failure' => ['class' => FailureCallbackJob::class],
                'on_complete' => ['class' => TestJob::class],
            ],
        );

        $this->invoke($processor, 'handleJobFailure', 'batch-1', 'job-0', 0, new RuntimeException('boom'));

        $this->assertSame(BatchDefinition::STATUS_FAILED, $this->batch->status);
        $this->assertSame(1, $this->batch->failedJobs);
        $this->assertCount(1, $this->queued);
        $this->assertSame(FailureCallbackJob::class, $this->queued[0]['class']);
        $this->assertSame(0, $this->finishedEvents);

        $this->invoke($processor, 'handleJobFailure', 'batch-1', 'job-1', 1, new RuntimeException('again'));
        $this->assertCount(1, $this->queued);
        $this->assertSame(2, $this->batch->failedJobs);
    }

    /**
     * @return void
     */
    public function testStrictSiblingSuccessAfterFailureDoesNotComplete(): void
    {
        $processor = $this->processor(
            [TestJob::class, TestJob::class],
            [
                'on_failure' => ['class' => FailureCallbackJob::class],
                'on_complete' => ['class' => TestJob::class],
            ],
        );

        $this->invoke($processor, 'handleJobFailure', 'batch-1', 'job-0', 0, new RuntimeException('boom'));
        $this->invoke($processor, 'handleJobSuccess', 'batch-1', 'job-1', 1, null, []);

        $this->assertSame(BatchDefinition::STATUS_FAILED, $this->batch->status);
        $this->assertSame(1, $this->batch->completedJobs);
        $this->assertSame(0, $this->finishedEvents);
        $this->assertCount(1, $this->queued);
        $this->assertSame(FailureCallbackJob::class, $this->queued[0]['class']);
    }

    /**
     * @return void
     */
    public function testAllowFailuresSettlesWithMixedResults(): void
    {
        $processor = $this->processor(
            [TestJob::class, TestJob::class, TestJob::class],
            [
                'allow_failures' => true,
                'on_complete' => ['class' => TestJob::class],
                'on_failure' => ['class' => FailureCallbackJob::class],
                'on_job_failure' => ['class' => FailureCallbackJob::class, 'args' => ['per_job' => true]],
            ],
        );

        $this->invoke($processor, 'handleJobSuccess', 'batch-1', 'job-0', 0, null, []);
        $this->assertSame(0, $this->finishedEvents);
        $this->assertSame([], $this->queued);

        $this->invoke($processor, 'handleJobFailure', 'batch-1', 'job-1', 1, new RuntimeException('mid'));
        $this->assertCount(1, $this->queued);
        $this->assertTrue($this->queued[0]['args']['per_job'] ?? false);
        $this->assertSame(1, $this->queued[0]['args']['failed_job_position']);

        $this->invoke($processor, 'handleJobSuccess', 'batch-1', 'job-2', 2, null, []);

        $this->assertSame(BatchDefinition::STATUS_COMPLETED, $this->batch->status);
        $this->assertSame(2, $this->batch->completedJobs);
        $this->assertSame(1, $this->batch->failedJobs);
        $this->assertSame(1, $this->finishedEvents);

        $classes = array_column($this->queued, 'class');
        $this->assertSame(
            [FailureCallbackJob::class, TestJob::class, FailureCallbackJob::class],
            $classes,
        );
    }

    /**
     * @return void
     */
    public function testAllowFailuresAllOkSkipsOnFailure(): void
    {
        $processor = $this->processor(
            [TestJob::class, TestJob::class],
            [
                'allow_failures' => true,
                'on_complete' => ['class' => TestJob::class],
                'on_failure' => ['class' => FailureCallbackJob::class],
            ],
        );

        $this->invoke($processor, 'handleJobSuccess', 'batch-1', 'job-0', 0, null, []);
        $this->invoke($processor, 'handleJobSuccess', 'batch-1', 'job-1', 1, null, []);

        $this->assertSame(BatchDefinition::STATUS_COMPLETED, $this->batch->status);
        $this->assertSame(1, $this->finishedEvents);
        $this->assertCount(1, $this->queued);
        $this->assertSame(TestJob::class, $this->queued[0]['class']);
    }

    /**
     * @param array $jobs Job classes
     * @param array $options Batch options
     * @return \Crustum\BatchQueue\Processor\BatchJobProcessor
     */
    private function processor(array $jobs, array $options): BatchJobProcessor
    {
        $this->batch = new BatchDefinition(
            'batch-1',
            BatchDefinition::TYPE_PARALLEL,
            $jobs,
            options: $options,
            queueConfig: 'test_queue',
        );
        $this->batch->status = BatchDefinition::STATUS_RUNNING;

        $storage = $this->createMock(BatchStorageInterface::class);
        $storage->method('getBatch')->willReturnCallback(fn(): BatchDefinition => $this->batch);
        $storage->method('incrementCompletedJob')->willReturnCallback(function (): int {
            $this->batch->completedJobs++;

            return $this->batch->completedJobs;
        });
        $storage->method('incrementFailedJob')->willReturnCallback(function (): int {
            $this->batch->failedJobs++;

            return $this->batch->failedJobs;
        });
        $storage->method('updateBatch')->willReturnCallback(function (string $batchId, array $updates): void {
            if (isset($updates['status'])) {
                $this->batch->status = $updates['status'];
            }

            if (isset($updates['completed_at'])) {
                $this->batch->completedAt = $updates['completed_at'] instanceof DateTime
                    ? $updates['completed_at']
                    : new DateTime($updates['completed_at']);
            }
        });

        $logger = $this->createStub(LoggerInterface::class);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($storage): MockObject {
            if ($id === BatchStorageInterface::class) {
                return $storage;
            }

            throw new RuntimeException('Unexpected container get: ' . $id);
        });

        $sink = new stdClass();
        $sink->queued = &$this->queued;

        return new class ($logger, $container, $sink) extends BatchJobProcessor {
            /**
             * @param \Psr\Log\LoggerInterface $logger Logger
             * @param \Cake\Core\ContainerInterface $container Container
             * @param object{queued: list<array{class: string, args: array}>} $sink Queued jobs sink
             */
            public function __construct(
                LoggerInterface $logger,
                ContainerInterface $container,
                private object $sink,
            ) {
                parent::__construct($logger, $container);
            }

            /**
             * @inheritDoc
             */
            protected function queueJob(string $jobClass, array $args, ?string $queueConfig = null): void
            {
                $this->sink->queued[] = ['class' => $jobClass, 'args' => $args];
            }
        };
    }

    /**
     * @param object $object Object
     * @param string $method Method name
     * @param mixed ...$args Arguments
     * @return mixed
     */
    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);

        return $ref->invoke($object, ...$args);
    }
}
