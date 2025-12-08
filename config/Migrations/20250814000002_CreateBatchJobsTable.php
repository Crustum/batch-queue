<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Create batch_jobs table for individual job tracking within batches
 */
class CreateBatchJobsTable extends BaseMigration
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
        $table = $this->table('batch_jobs', ['id' => false, 'primary_key' => 'id']);

        $table
            ->addColumn('id', 'uuid', [
                'default' => null,
                'null' => false,
                'comment' => 'Unique job record identifier',
            ])
            ->addColumn('batch_id', 'uuid', [
                'default' => null,
                'null' => false,
                'comment' => 'Reference to parent batch',
            ])
            ->addColumn('job_id', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
                'comment' => 'Job identifier within batch',
            ])
            ->addColumn('position', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => false,
                'comment' => 'Job position in batch (for sequential ordering)',
            ])
            ->addColumn('status', 'string', [
                'length' => 20,
                'default' => 'pending',
                'null' => false,
                'comment' => 'Current job status',
            ])
            ->addColumn('payload', 'json', [
                'default' => null,
                'null' => false,
                'comment' => 'Job execution payload and parameters',
            ])
            ->addColumn('result', 'json', [
                'default' => null,
                'null' => true,
                'comment' => 'Job execution result',
            ])
            ->addColumn('error', 'json', [
                'default' => null,
                'null' => true,
                'comment' => 'Job execution error details',
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
                'comment' => 'Job creation timestamp',
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => true,
                'comment' => 'Last modification timestamp',
            ])
            ->addColumn('completed_at', 'datetime', [
                'default' => null,
                'null' => true,
                'comment' => 'Job completion timestamp',
            ])
            ->addIndex(['batch_id'], ['name' => 'idx_batch_jobs_batch_id'])
            ->addIndex(['status'], ['name' => 'idx_batch_jobs_status'])
            ->addIndex(['batch_id', 'status'], ['name' => 'idx_batch_jobs_batch_status'])
            ->addIndex(['batch_id', 'position'], ['name' => 'idx_batch_jobs_batch_position'])
            ->addIndex(['batch_id', 'job_id'], ['name' => 'idx_batch_jobs_batch_job_id', 'unique' => true])
            ->addForeignKey('batch_id', 'batches', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'name' => 'fk_batch_jobs_batch_id'
            ])
            ->create();
    }
}
