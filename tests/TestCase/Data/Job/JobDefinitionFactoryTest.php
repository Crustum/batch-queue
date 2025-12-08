<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Data\Job;

use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\Job\CompensatedJobDefinition;
use Crustum\BatchQueue\Data\Job\JobDefinition;
use Crustum\BatchQueue\Data\Job\JobDefinitionFactory;
use Crustum\BatchQueue\Model\Entity\BatchJob;
use Exception;
use InvalidArgumentException;
use stdClass;

/**
 * JobDefinitionFactory Test Case
 */
class JobDefinitionFactoryTest extends TestCase
{
    /**
     * Test factory creates JobDefinition from string
     *
     * @return void
     */
    public function testFactoryCreatesJobDefinitionFromString(): void
    {
        $job = JobDefinitionFactory::create(stdClass::class, BatchDefinition::TYPE_PARALLEL);

        $this->assertInstanceOf(JobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
    }

    /**
     * Test factory creates JobDefinition from array with class key
     *
     * @return void
     */
    public function testFactoryCreatesJobDefinitionFromArrayWithClass(): void
    {
        $args = ['param1' => 'value1'];
        $job = JobDefinitionFactory::create(
            ['class' => stdClass::class, 'args' => $args],
            BatchDefinition::TYPE_PARALLEL,
        );

        $this->assertInstanceOf(JobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
        $this->assertEquals($args, $job->getArgs());
    }

    /**
     * Test factory creates CompensatedJobDefinition from compensation array
     *
     * @return void
     */
    public function testFactoryCreatesCompensatedJobDefinitionFromCompensationArray(): void
    {
        $job = JobDefinitionFactory::create(
            [stdClass::class, Exception::class],
            BatchDefinition::TYPE_SEQUENTIAL,
        );

        $this->assertInstanceOf(CompensatedJobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
        if ($job instanceof CompensatedJobDefinition) {
            $this->assertEquals(Exception::class, $job->getCompensationClass());
        }
    }

    /**
     * Test factory creates CompensatedJobDefinition from array with compensation key
     *
     * @return void
     */
    public function testFactoryCreatesCompensatedJobDefinitionFromArrayWithCompensation(): void
    {
        $args = ['param1' => 'value1'];
        $job = JobDefinitionFactory::create(
            [
                'class' => stdClass::class,
                'compensation' => Exception::class,
                'args' => $args,
            ],
            BatchDefinition::TYPE_SEQUENTIAL,
        );

        $this->assertInstanceOf(CompensatedJobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
        if ($job instanceof CompensatedJobDefinition) {
            $this->assertEquals(Exception::class, $job->getCompensationClass());
        }
        $this->assertEquals($args, $job->getArgs());
    }

    /**
     * Test factory rejects compensation in parallel batch
     *
     * @return void
     */
    public function testFactoryRejectsCompensationInParallelBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compensation is only supported for sequential chains, not parallel batches');

        JobDefinitionFactory::create(
            [stdClass::class, Exception::class],
            BatchDefinition::TYPE_PARALLEL,
        );
    }

    /**
     * Test factory rejects invalid array format
     *
     * @return void
     */
    public function testFactoryRejectsInvalidArrayFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid job definition format. Expected string, [job, compensation] array, or array with 'class' key.");

        JobDefinitionFactory::create(['args' => ['param1']], BatchDefinition::TYPE_PARALLEL);
    }

    /**
     * Test factory rejects invalid input type
     *
     * @return void
     */
    public function testFactoryRejectsInvalidInputType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid job input type. Expected string, array, or BatchJob entity.');

        JobDefinitionFactory::create(12345, BatchDefinition::TYPE_PARALLEL);
    }

    /**
     * Test factory creates JobDefinition from BatchJob entity
     *
     * @return void
     */
    public function testFactoryCreatesJobDefinitionFromBatchJobEntity(): void
    {
        $batchJob = new BatchJob();
        $batchJob->id = 'job-123';
        $batchJob->payload = json_encode([
            'class' => stdClass::class,
            'args' => ['param1' => 'value1'],
        ]);

        $job = JobDefinitionFactory::create($batchJob, BatchDefinition::TYPE_PARALLEL);

        $this->assertInstanceOf(JobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
        $this->assertEquals(['param1' => 'value1'], $job->getArgs());
    }

    /**
     * Test factory creates CompensatedJobDefinition from BatchJob entity with compensation
     *
     * @return void
     */
    public function testFactoryCreatesCompensatedJobDefinitionFromBatchJobEntity(): void
    {
        $batchJob = new BatchJob();
        $batchJob->id = 'job-456';
        $batchJob->payload = json_encode([
            'class' => stdClass::class,
            'compensation' => Exception::class,
            'args' => ['param1' => 'value1'],
        ]);

        $job = JobDefinitionFactory::create($batchJob, BatchDefinition::TYPE_SEQUENTIAL);

        $this->assertInstanceOf(CompensatedJobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
        if ($job instanceof CompensatedJobDefinition) {
            $this->assertEquals(Exception::class, $job->getCompensationClass());
        }
        $this->assertEquals(['param1' => 'value1'], $job->getArgs());
    }

    /**
     * Test factory handles BatchJob entity with array payload
     *
     * @return void
     */
    public function testFactoryHandlesBatchJobEntityWithArrayPayload(): void
    {
        $batchJob = new BatchJob();
        $batchJob->id = 'job-789';
        $batchJob->payload = [
            'class' => stdClass::class,
            'args' => ['param1' => 'value1'],
        ];

        $job = JobDefinitionFactory::create($batchJob, BatchDefinition::TYPE_PARALLEL);

        $this->assertInstanceOf(JobDefinition::class, $job);
        $this->assertEquals(stdClass::class, $job->getClass());
    }

    /**
     * Test factory rejects BatchJob entity with invalid payload
     *
     * @return void
     */
    public function testFactoryRejectsBatchJobEntityWithInvalidPayload(): void
    {
        $batchJob = new BatchJob();
        $batchJob->id = 'job-invalid';
        $batchJob->payload = 'invalid-json-string';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid payload format in BatchJob entity');

        JobDefinitionFactory::create($batchJob, BatchDefinition::TYPE_PARALLEL);
    }

    /**
     * Test factory validates class existence for string input
     *
     * @return void
     */
    public function testFactoryValidatesClassExistenceForStringInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job class not found: NonExistentClass12345');

        JobDefinitionFactory::create('NonExistentClass12345', BatchDefinition::TYPE_PARALLEL);
    }

    /**
     * Test factory validates class existence for array input
     *
     * @return void
     */
    public function testFactoryValidatesClassExistenceForArrayInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job class not found: NonExistentClass12345');

        JobDefinitionFactory::create(
            ['class' => 'NonExistentClass12345'],
            BatchDefinition::TYPE_PARALLEL,
        );
    }
}
