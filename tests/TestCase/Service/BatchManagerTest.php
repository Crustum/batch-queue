<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use Crustum\BatchQueue\Service\BatchBuilder;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * BatchManager Test Case
 */
class BatchManagerTest extends TestCase
{
    protected BatchStorageInterface&MockObject $storage;
    protected BatchManager $manager;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->createMock(BatchStorageInterface::class);
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
            ['class' => 'TestJob1', 'args' => ['param1']],
            ['class' => 'TestJob2', 'args' => ['param2']],
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
            ['class' => 'Step1Job'],
            ['class' => 'Step2Job'],
            ['class' => 'Step3Job'],
        ];

        $builder = $this->manager->chain($jobs);

        $this->assertInstanceOf(BatchBuilder::class, $builder);
    }
}
