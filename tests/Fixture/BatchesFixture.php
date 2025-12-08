<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * BatchesFixture
 */
class BatchesFixture extends TestFixture
{
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
