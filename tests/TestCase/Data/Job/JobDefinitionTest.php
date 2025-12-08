<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Data\Job;

use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\Job\CompensatedJobDefinition;
use Crustum\BatchQueue\Data\Job\JobDefinition;
use Exception;
use InvalidArgumentException;
use stdClass;

/**
 * JobDefinition Test Case
 */
class JobDefinitionTest extends TestCase
{
    /**
     * Test creating JobDefinition with valid class
     *
     * @return void
     */
    public function testCreateJobDefinitionWithValidClass(): void
    {
        $job = new JobDefinition(stdClass::class);

        $this->assertEquals(stdClass::class, $job->getClass());
        $this->assertEquals([], $job->getArgs());
    }

    /**
     * Test creating JobDefinition with class and args
     *
     * @return void
     */
    public function testCreateJobDefinitionWithArgs(): void
    {
        $args = ['param1' => 'value1', 'param2' => 'value2'];
        $job = new JobDefinition(stdClass::class, $args);

        $this->assertEquals(stdClass::class, $job->getClass());
        $this->assertEquals($args, $job->getArgs());
    }

    /**
     * Test creating JobDefinition with invalid class
     *
     * @return void
     */
    public function testCreateJobDefinitionWithInvalidClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job class not found: NonExistentClass12345');

        new JobDefinition('NonExistentClass12345');
    }

    /**
     * Test JobDefinition normalization
     *
     * @return void
     */
    public function testJobDefinitionNormalization(): void
    {
        $args = ['param1' => 'value1'];
        $job = new JobDefinition(stdClass::class, $args);
        $normalized = $job->toNormalized(0, 'job-123');

        $this->assertEquals('job-123', $normalized['id']);
        $this->assertEquals(stdClass::class, $normalized['class']);
        $this->assertNull($normalized['compensation']);
        $this->assertEquals(0, $normalized['position']);
        $this->assertEquals($args, $normalized['args']);
    }

    /**
     * Test creating CompensatedJobDefinition with valid classes
     *
     * @return void
     */
    public function testCreateCompensatedJobDefinitionWithValidClasses(): void
    {
        $job = new CompensatedJobDefinition(
            stdClass::class,
            Exception::class,
            [],
            BatchDefinition::TYPE_SEQUENTIAL,
        );

        $this->assertEquals(stdClass::class, $job->getClass());
        $this->assertEquals(Exception::class, $job->getCompensationClass());
        $this->assertEquals([], $job->getArgs());
    }

    /**
     * Test creating CompensatedJobDefinition with args
     *
     * @return void
     */
    public function testCreateCompensatedJobDefinitionWithArgs(): void
    {
        $args = ['param1' => 'value1'];
        $job = new CompensatedJobDefinition(
            stdClass::class,
            Exception::class,
            $args,
            BatchDefinition::TYPE_SEQUENTIAL,
        );

        $this->assertEquals($args, $job->getArgs());
    }

    /**
     * Test creating CompensatedJobDefinition with invalid job class
     *
     * @return void
     */
    public function testCreateCompensatedJobDefinitionWithInvalidJobClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job class not found: NonExistentClass12345');

        new CompensatedJobDefinition(
            'NonExistentClass12345',
            Exception::class,
            [],
            BatchDefinition::TYPE_SEQUENTIAL,
        );
    }

    /**
     * Test creating CompensatedJobDefinition with invalid compensation class
     *
     * @return void
     */
    public function testCreateCompensatedJobDefinitionWithInvalidCompensationClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compensation class not found: NonExistentClass12345');

        new CompensatedJobDefinition(
            stdClass::class,
            'NonExistentClass12345',
            [],
            BatchDefinition::TYPE_SEQUENTIAL,
        );
    }

    /**
     * Test CompensatedJobDefinition rejects parallel batch type
     *
     * @return void
     */
    public function testCompensatedJobDefinitionRejectsParallelBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compensation is only supported for sequential chains, not parallel batches');

        new CompensatedJobDefinition(
            stdClass::class,
            Exception::class,
            [],
            BatchDefinition::TYPE_PARALLEL,
        );
    }

    /**
     * Test CompensatedJobDefinition normalization
     *
     * @return void
     */
    public function testCompensatedJobDefinitionNormalization(): void
    {
        $args = ['param1' => 'value1'];
        $job = new CompensatedJobDefinition(
            stdClass::class,
            Exception::class,
            $args,
            BatchDefinition::TYPE_SEQUENTIAL,
        );
        $normalized = $job->toNormalized(1, 'job-456');

        $this->assertEquals('job-456', $normalized['id']);
        $this->assertEquals(stdClass::class, $normalized['class']);
        $this->assertEquals(Exception::class, $normalized['compensation']);
        $this->assertEquals(1, $normalized['position']);
        $this->assertEquals($args, $normalized['args']);
    }
}
