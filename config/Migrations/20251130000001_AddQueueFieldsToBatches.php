<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Add queue_name and queue_config fields to batches table
 */
class AddQueueFieldsToBatches extends BaseMigration
{
    /**
     * Change Method.
     *
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('batches');

        $table
            ->addColumn('queue_name', 'string', [
                'length' => 100,
                'default' => null,
                'null' => true,
                'comment' => 'Named queue identifier for routing',
            ])
            ->addColumn('queue_config', 'string', [
                'length' => 100,
                'default' => null,
                'null' => true,
                'comment' => 'Queue configuration name for inner jobs',
            ])
            ->addIndex(['queue_name'], ['name' => 'idx_batches_queue_name'])
            ->addIndex(['queue_config'], ['name' => 'idx_batches_queue_config'])
            ->update();
    }
}

