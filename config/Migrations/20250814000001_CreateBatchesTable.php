<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Create batches table for batch processing metadata
 */
class CreateBatchesTable extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('batches', ['id' => false, 'primary_key' => 'id']);

        $table
            ->addColumn('id', 'uuid', [
                'default' => null,
                'null' => false,
                'comment' => 'Unique batch identifier',
            ])
            ->addColumn('type', 'string', [
                'length' => 20,
                'default' => 'parallel',
                'null' => false,
                'comment' => 'Batch execution type',
            ])
            ->addColumn('status', 'string', [
                'length' => 20,
                'default' => 'pending',
                'null' => false,
                'comment' => 'Current batch status',
            ])
            ->addColumn('total_jobs', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => false,
                'comment' => 'Total number of jobs in batch',
            ])
            ->addColumn('completed_jobs', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => false,
                'comment' => 'Number of completed jobs',
            ])
            ->addColumn('failed_jobs', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => false,
                'comment' => 'Number of failed jobs',
            ])
            ->addColumn('context', 'json', [
                'default' => null,
                'null' => true,
                'comment' => 'Shared context data for batch jobs',
            ])
            ->addColumn('options', 'json', [
                'default' => null,
                'null' => true,
                'comment' => 'Batch configuration options',
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
                'comment' => 'Batch creation timestamp',
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => true,
                'comment' => 'Last modification timestamp',
            ])
            ->addColumn('completed_at', 'datetime', [
                'default' => null,
                'null' => true,
                'comment' => 'Batch completion timestamp',
            ])
            ->addIndex(['status'], ['name' => 'idx_batches_status'])
            ->addIndex(['type'], ['name' => 'idx_batches_type'])
            ->addIndex(['created'], ['name' => 'idx_batches_created'])
            ->addIndex(['status', 'created'], ['name' => 'idx_batches_status_created'])
            ->create();
    }
}
