<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\TestCase\Storage;

use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Crustum\BatchQueue\Test\Support\BaseIntegrationTestCase;
use Crustum\BatchQueue\Test\Support\TestJob;

/**
 * SqlBatchStorage list/count filter tests
 *
 * Covers type / has_compensation filters used by Monitor pagination.
 * has_compensation must CAST json payload for Postgres (LIKE on json fails).
 */
class SqlBatchStorageFilterTest extends BaseIntegrationTestCase
{
    protected SqlBatchStorage $storage;

    protected BatchManager $batchManager;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new SqlBatchStorage();
        $this->batchManager = new BatchManager($this->storage);
        TestJob::reset();
    }

    /**
     * countBatches respects type and has_compensation (json CAST path).
     *
     * @return void
     */
    public function testCountBatchesRespectsTypeAndCompensationFilters(): void
    {
        $this->batchManager->batch([TestJob::class, TestJob::class])->dispatch();
        $this->batchManager->chain([TestJob::class, TestJob::class])->dispatch();
        $compensatedId = $this->batchManager->chain([
            [
                'class' => TestJob::class,
                'compensation' => TestJob::class,
            ],
            TestJob::class,
        ])->dispatch();

        $this->assertSame(3, $this->storage->countBatches());
        $this->assertSame(1, $this->storage->countBatches(['type' => 'parallel']));
        $this->assertSame(2, $this->storage->countBatches(['type' => 'sequential']));
        $this->assertSame(1, $this->storage->countBatches(['has_compensation' => true]));
        $this->assertSame(
            1,
            $this->storage->countBatches([
                'type' => 'sequential',
                'has_compensation' => true,
            ]),
        );
        $this->assertSame(
            0,
            $this->storage->countBatches([
                'type' => 'parallel',
                'has_compensation' => true,
            ]),
        );

        $withCompensation = $this->storage->getBatches(['has_compensation' => true], 25, 0);
        $this->assertCount(1, $withCompensation);
        $this->assertSame($compensatedId, $withCompensation[0]->id);
        $this->assertTrue($withCompensation[0]->hasCompensation());
    }

    /**
     * getBatches applies has_compensation before limit/offset.
     *
     * @return void
     */
    public function testGetBatchesCompensationFilterAppliesBeforePagination(): void
    {
        $this->batchManager->batch([TestJob::class])->dispatch();
        $this->batchManager->batch([TestJob::class])->dispatch();
        $firstCompensated = $this->batchManager->chain([
            [
                'class' => TestJob::class,
                'compensation' => TestJob::class,
            ],
        ])->dispatch();
        $secondCompensated = $this->batchManager->chain([
            [
                'class' => TestJob::class,
                'compensation' => TestJob::class,
            ],
        ])->dispatch();

        $this->assertSame(2, $this->storage->countBatches(['has_compensation' => true]));

        $page = $this->storage->getBatches(['has_compensation' => true], 1, 0);
        $this->assertCount(1, $page);
        $this->assertTrue($page[0]->hasCompensation());
        $this->assertContains($page[0]->id, [$firstCompensated, $secondCompensated]);

        $pageTwo = $this->storage->getBatches(['has_compensation' => true], 1, 1);
        $this->assertCount(1, $pageTwo);
        $this->assertTrue($pageTwo[0]->hasCompensation());
        $this->assertNotSame($page[0]->id, $pageTwo[0]->id);
    }

    /**
     * Compensation filter must not throw when payload is a json column (Postgres).
     *
     * @return void
     */
    public function testHasCompensationCountDoesNotThrowOnJsonPayloadColumn(): void
    {
        $this->batchManager->chain([
            [
                'class' => TestJob::class,
                'compensation' => TestJob::class,
            ],
        ])->dispatch();

        $driver = $this->getTableLocator()->get('Crustum/BatchQueue.BatchJobs')
            ->getConnection()
            ->getDriver();

        $count = $this->storage->countBatches(['has_compensation' => true]);

        $this->assertSame(1, $count);
        $this->assertNotEmpty($driver::class);
    }
}
