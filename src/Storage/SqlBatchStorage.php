<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Storage;

use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Crustum\BatchQueue\Model\Table\BatchesTable;
use Crustum\BatchQueue\Model\Table\BatchJobsTable;
use DateTime;
use RuntimeException;
use Throwable;

/**
 * SQL-based Batch Storage Implementation using CakePHP ORM
 *
 * Uses proper CakePHP 5 Table and Entity classes for database operations.
 * Provides ACID compliance and leverages ORM features.
 */
class SqlBatchStorage implements BatchStorageInterface
{
    use LocatorAwareTrait;

    protected BatchesTable $batchesTable;

    protected BatchJobsTable $batchJobsTable;

    /**
     * Constructor
     */
    public function __construct()
    {
        /** @var \Crustum\BatchQueue\Model\Table\BatchesTable $batchesTable */
        $batchesTable = $this->fetchTable('Crustum/BatchQueue.Batches');
        $this->batchesTable = $batchesTable;

        /** @var \Crustum\BatchQueue\Model\Table\BatchJobsTable $batchJobsTable */
        $batchJobsTable = $this->fetchTable('Crustum/BatchQueue.BatchJobs');
        $this->batchJobsTable = $batchJobsTable;
    }

    /**
     * @inheritDoc
     */
    public function createBatch(BatchDefinition $batch): string
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batch) {
            $batchEntity = $this->batchesTable->createFromDefinition($batch);

            $this->batchJobsTable->createBatchJobs($batchEntity->id, $batch->jobs);

            return $batchEntity->id;
        });
    }

    /**
     * @inheritDoc
     */
    public function updateBatch(string $batchId, array $updates): void
    {
        try {
            $this->batchesTable->getConnection()->transactional(function () use ($batchId, $updates): void {
                $batch = $this->batchesTable->get($batchId);

                $batch = $this->batchesTable->patchEntity($batch, $updates);

                $this->batchesTable->saveOrFail($batch);
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to update batch: {0}', $batchId), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function getBatch(string $batchId): ?BatchDefinition
    {
        try {
            $batch = $this->batchesTable->get($batchId, contain: ['BatchJobs']);

            return $this->batchesTable->toDefinition($batch);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function markJobComplete(string $batchId, string $jobId, mixed $result): bool
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId, $jobId, $result): bool {
            $this->batchJobsTable->markCompleted($batchId, $jobId, $result);

            $completedCount = $this->batchesTable->incrementCounter($batchId, 'completed_jobs');
            $batch = $this->batchesTable->get($batchId);

            if ($completedCount >= $batch->total_jobs) {
                $this->updateBatch($batchId, [
                    'status' => BatchDefinition::STATUS_COMPLETED,
                    'completed_at' => new DateTime(),
                ]);

                return true;
            }

            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function markJobCompleteById(string $batchId, string $dbJobId, mixed $result): bool
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId, $dbJobId, $result): bool {
            $this->batchJobsTable->markCompletedById($batchId, $dbJobId, $result);

            $completedCount = $this->batchesTable->incrementCounter($batchId, 'completed_jobs');
            $batch = $this->batchesTable->get($batchId);

            if ($completedCount >= $batch->total_jobs) {
                $this->updateBatch($batchId, [
                    'status' => BatchDefinition::STATUS_COMPLETED,
                    'completed_at' => new DateTime(),
                ]);

                return true;
            }

            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function markJobFailed(string $batchId, string $jobId, Throwable $error): bool
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId, $jobId, $error): bool {
            $errorData = [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ];

            $this->batchJobsTable->markFailed($batchId, $jobId, $errorData);
            $this->batchesTable->incrementCounter($batchId, 'failed_jobs');

            $batch = $this->batchesTable->get($batchId);
            if (isset($batch->options['fail_on_first_error']) && $batch->options['fail_on_first_error']) {
                $this->updateBatch($batchId, [
                    'status' => BatchDefinition::STATUS_FAILED,
                    'completed_at' => new DateTime(),
                ]);

                return true;
            }

            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function markJobFailedById(string $batchId, string $dbJobId, Throwable $error): bool
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId, $dbJobId, $error): bool {
            $errorData = [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ];

            $this->batchJobsTable->markFailedById($batchId, $dbJobId, $errorData);
            $this->batchesTable->incrementCounter($batchId, 'failed_jobs');

            $batch = $this->batchesTable->get($batchId);
            if (isset($batch->options['fail_on_first_error']) && $batch->options['fail_on_first_error']) {
                $this->updateBatch($batchId, [
                    'status' => BatchDefinition::STATUS_FAILED,
                    'completed_at' => new DateTime(),
                ]);

                return true;
            }

            return false;
        });
    }

    /**
     * @inheritDoc
     */
    public function isBatchComplete(string $batchId): bool
    {
        try {
            $batch = $this->batchesTable->get($batchId);

            return $batch->status === BatchDefinition::STATUS_COMPLETED ||
                   ($batch->completed_jobs + $batch->failed_jobs) >= $batch->total_jobs;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getBatchProgress(string $batchId): array
    {
        try {
            $batch = $this->batchesTable->get($batchId);

            return [
                'total' => $batch->total_jobs,
                'completed' => $batch->completed_jobs,
                'failed' => $batch->failed_jobs,
                'pending' => $batch->getPendingJobs(),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getBatchResults(string $batchId): array
    {
        return $this->batchJobsTable->getBatchResults($batchId);
    }

    /**
     * @inheritDoc
     */
    public function getJobResult(string $batchId, string $jobId): mixed
    {
        try {
            /** @var \Crustum\BatchQueue\Model\Entity\BatchJob|null $job */
            $job = $this->batchJobsTable->find()
                ->select(['result'])
                ->where([
                    'batch_id' => $batchId,
                    'job_id' => $jobId,
                ])
                ->first();

            return $job?->result;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function storeJobResult(string $batchId, string $jobId, mixed $result): void
    {
        $this->batchJobsTable->updateAll(
            ['result' => $result],
            [
                'batch_id' => $batchId,
                'job_id' => $jobId,
            ],
        );
    }

    /**
     * @inheritDoc
     */
    public function getFailedJobs(string $batchId): array
    {
        /** @var list<\Crustum\BatchQueue\Model\Entity\BatchJob> $jobs */
        $jobs = $this->batchJobsTable->find()
            ->where([
                'batch_id' => $batchId,
                'status' => 'failed',
            ])
            ->toArray();

        $failedJobs = [];
        foreach ($jobs as $job) {
            $definition = $this->batchJobsTable->toDefinition($job);
            $failedJobs[$definition->jobId] = $definition;
        }

        return $failedJobs;
    }

    /**
     * @inheritDoc
     */
    public function deleteBatch(string $batchId): void
    {
        $this->batchesTable->getConnection()->transactional(function () use ($batchId): void {
            $this->batchJobsTable->deleteAll(['batch_id' => $batchId]);
            $this->batchesTable->deleteAll(['id' => $batchId]);
        });
    }

    /**
     * @inheritDoc
     */
    public function getBatchesByStatus(string $status, int $limit = 100, int $offset = 0): array
    {
        /** @var list<\Crustum\BatchQueue\Model\Entity\Batch> $batches */
        $batches = $this->batchesTable->find('byStatus', status: $status)
            ->contain(['BatchJobs'])
            ->limit($limit)
            ->offset($offset)
            ->toArray();

        return array_map(
            $this->batchesTable->toDefinition(...),
            $batches,
        );
    }

    /**
     * @inheritDoc
     */
    public function getAllJobs(string $batchId, array $options = []): array
    {
        $status = $options['status'] ?? null;
        $limit = $options['limit'] ?? null;
        $offset = $options['offset'] ?? 0;
        $orderBy = $options['order_by'] ?? 'position';

        $query = $this->batchJobsTable->find()
            ->where(['batch_id' => $batchId]);

        if ($status !== null) {
            $query->where(['status' => $status]);
        }

        if ($orderBy === 'position') {
            $query->orderBy(['position' => 'ASC']);
        } elseif ($orderBy === 'created') {
            $query->orderBy(['created' => 'ASC']);
        }

        if ($limit !== null) {
            $query->limit($limit);
            $query->offset($offset);
        }

        /** @var list<\Crustum\BatchQueue\Model\Entity\BatchJob> $jobs */
        $jobs = $query->toArray();

        $result = [];
        foreach ($jobs as $job) {
            $definition = $this->batchJobsTable->toDefinition($job);
            $result[$definition->position] = $definition;
        }

        ksort($result);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getBatches(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->batchesTable->find()
            ->contain(['BatchJobs']);

        $this->applyBatchListFilters($query, $filters);

        $query->limit($limit)->offset($offset);
        $query->orderBy(['created' => 'DESC']);

        /** @var list<\Crustum\BatchQueue\Model\Entity\Batch> $batches */
        $batches = $query->toArray();

        return array_map(
            $this->batchesTable->toDefinition(...),
            $batches,
        );
    }

    /**
     * @inheritDoc
     */
    public function countBatches(array $filters = []): int
    {
        $query = $this->batchesTable->find();
        $this->applyBatchListFilters($query, $filters);

        return $query->count();
    }

    /**
     * Apply shared list/count filters to a batches query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Batches query
     * @param array<string, mixed> $filters Filter criteria
     * @return void
     */
    protected function applyBatchListFilters(SelectQuery $query, array $filters): void
    {
        if (isset($filters['status']) && is_string($filters['status'])) {
            $query->where(['status' => $filters['status']]);
        }

        if (isset($filters['type']) && is_string($filters['type'])) {
            $query->where(['type' => $filters['type']]);
        }

        if (isset($filters['created_after']) && $filters['created_after'] instanceof DateTime) {
            $query->where(['created >=' => $filters['created_after']]);
        }

        if (isset($filters['created_before']) && $filters['created_before'] instanceof DateTime) {
            $query->where(['created <=' => $filters['created_before']]);
        }

        if (isset($filters['has_compensation']) && $filters['has_compensation'] === true) {
            $this->applyHasCompensationFilter($query);
        }
    }

    /**
     * Restrict to batches that have at least one job with a compensation class in payload.
     *
     * Uses JSON path extraction. Do not CAST json AS char on MySQL (defaults to length 30).
     *
     * @param \Cake\ORM\Query\SelectQuery $query Batches query
     * @return void
     */
    protected function applyHasCompensationFilter(SelectQuery $query): void
    {
        $driver = $this->batchJobsTable->getConnection()->getDriver();

        $subquery = $this->batchJobsTable->find()
            ->select(['batch_id'])
            ->where(function (QueryExpression $exp, SelectQuery $q) use ($driver) {
                if ($driver instanceof Postgres) {
                    return $exp->and([
                        $q->expr("payload->>'compensation' IS NOT NULL"),
                        $q->expr("payload->>'compensation' <> ''"),
                    ]);
                }

                if ($driver instanceof Mysql) {
                    return $q->expr("JSON_TYPE(JSON_EXTRACT(payload, '$.compensation')) = 'STRING'");
                }

                return $exp->and([
                    $q->expr("json_extract(payload, '$.compensation') IS NOT NULL"),
                    $q->expr("json_extract(payload, '$.compensation') <> ''"),
                    $q->expr("json_extract(payload, '$.compensation') <> 'null'"),
                ]);
            })
            ->groupBy(['batch_id']);

        $alias = $this->batchesTable->getAlias();
        $query->where(
            fn(QueryExpression $exp): QueryExpression => $exp->in($alias . '.id', $subquery),
        );
    }

    /**
     * @inheritDoc
     */
    public function cleanupOldBatches(int $olderThanDays = 7): int
    {
        return $this->batchesTable->cleanupOld($olderThanDays);
    }

    /**
     * @inheritDoc
     */
    public function getStorageType(): string
    {
        return 'sql';
    }

    /**
     * @inheritDoc
     */
    public function healthCheck(): bool
    {
        try {
            $this->batchesTable->find()->limit(1)->toArray();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Increment completed job counter
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @return int Updated completed jobs count
     */
    public function incrementCompletedJob(string $batchId, string $jobId): int
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId) {
            $completedCount = $this->batchJobsTable->find()
                ->where([
                    'batch_id' => $batchId,
                    'status' => 'completed',
                ])
                ->count();

            $this->batchesTable->updateAll(
                ['completed_jobs' => $completedCount],
                ['id' => $batchId],
            );

            return $completedCount;
        });
    }

    /**
     * Increment failed job counter
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @return int Updated failed jobs count
     */
    public function incrementFailedJob(string $batchId, string $jobId): int
    {
        return $this->batchesTable->getConnection()->transactional(function () use ($batchId) {
            $failedCount = $this->batchJobsTable->find()
                ->where([
                    'batch_id' => $batchId,
                    'status' => 'failed',
                ])
                ->count();

            $this->batchesTable->updateAll(
                ['failed_jobs' => $failedCount],
                ['id' => $batchId],
            );

            return $failedCount;
        });
    }

    /**
     * @inheritDoc
     */
    public function createOrUpdateJob(string $batchId, string $jobId, array $jobData): void
    {
        try {
            $this->batchesTable->getConnection()->transactional(function () use ($batchId, $jobId, $jobData): void {
                $existingJob = $this->batchJobsTable->find()
                    ->where([
                        'batch_id' => $batchId,
                        'job_id' => $jobId,
                    ])
                    ->first();

                if ($existingJob) {
                    $existingJob = $this->batchJobsTable->patchEntity($existingJob, $jobData);
                    $this->batchJobsTable->saveOrFail($existingJob);
                } else {
                    $jobEntity = $this->batchJobsTable->newEntity(array_merge($jobData, [
                        'batch_id' => $batchId,
                        'job_id' => $jobId,
                    ]));
                    $this->batchJobsTable->saveOrFail($jobEntity);
                }
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to create/update job: {0}:{1}', $batchId, $jobId), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function getJobById(string $batchId, string $jobId): ?BatchJobDefinition
    {
        try {
            /** @var \Crustum\BatchQueue\Model\Entity\BatchJob|null $job */
            $job = $this->batchJobsTable->find()
                ->where([
                    'batch_id' => $batchId,
                    'job_id' => $jobId,
                ])
                ->first();

            if (!$job) {
                return null;
            }

            return $this->batchJobsTable->toDefinition($job);
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to get job by message ID: {0}:{1}', $batchId, $jobId), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateJobStatus(string $batchId, string $jobId, string $status, mixed $result = null, mixed $error = null): void
    {
        try {
            $this->batchesTable->getConnection()->transactional(function () use ($batchId, $jobId, $status, $result, $error): void {
                $updateData = ['status' => $status];

                if ($result !== null) {
                    if (is_array($result) || is_object($result)) {
                        $updateData['result'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $updateData['result'] = $result;
                    }
                }

                if ($error !== null) {
                    if (is_array($error) || is_object($error)) {
                        $updateData['error'] = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $updateData['error'] = $error;
                    }
                }

                if ($status === 'completed' || $status === 'failed') {
                    $updateData['completed_at'] = new DateTime();
                }

                $affected = $this->batchJobsTable->updateAll($updateData, [
                    'batch_id' => $batchId,
                    'job_id' => $jobId,
                ]);

                if ($affected === 0) {
                    throw new RuntimeException(__('No job found to update for batch {0} and job {1}', $batchId, $jobId));
                }
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to update job status: {0}:{1}', $batchId, $jobId), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function getJobByPosition(string $batchId, int $position): ?BatchJobDefinition
    {
        try {
            /** @var \Crustum\BatchQueue\Model\Entity\BatchJob|null $job */
            $job = $this->batchJobsTable->find()
                ->where([
                    'batch_id' => $batchId,
                    'position' => $position,
                ])
                ->first();

            if (!$job) {
                return null;
            }

            return $this->batchJobsTable->toDefinition($job);
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to get job by position: {0}:{1}', $batchId, $position), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateJobId(string $batchId, int $position, string $messageId): void
    {
        try {
            $this->batchesTable->getConnection()->transactional(function () use ($batchId, $position, $messageId): void {
                $this->batchJobsTable->updateAll([
                    'job_id' => $messageId,
                ], [
                    'batch_id' => $batchId,
                    'position' => $position,
                ]);
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to update job message ID: {0}:{1}', $batchId, $position), 0, $throwable);
        }
    }

    /**
     * @inheritDoc
     */
    public function addJobsToBatch(string $batchId, array $jobs): int
    {
        try {
            return $this->batchesTable->getConnection()->transactional(function () use ($batchId, $jobs): int {
                $batch = $this->batchesTable->get($batchId);

                if (!$batch) {
                    throw new RuntimeException(__('Batch not found: {0}', $batchId));
                }

                if (in_array($batch->status, ['completed', 'failed'])) {
                    throw new RuntimeException(__('Cannot add jobs to {0} batch: {1}', $batch->status, $batchId));
                }

                $currentTotalJobs = $batch->total_jobs;

                $entities = [];
                foreach ($jobs as $index => $jobData) {
                    $position = $currentTotalJobs + $index;
                    $jobId = $jobData['id'] ?? '';
                    $entities[] = $this->batchJobsTable->newEntity([
                        'batch_id' => $batchId,
                        'job_id' => $jobId,
                        'position' => $position,
                        'status' => 'pending',
                        'payload' => $jobData,
                    ]);
                }

                $this->batchJobsTable->saveManyOrFail($entities);

                $newTotalJobs = $currentTotalJobs + count($jobs);
                $this->batchesTable->updateAll(
                    ['total_jobs' => $newTotalJobs],
                    ['id' => $batchId],
                );

                return count($jobs);
            });
        } catch (Throwable $throwable) {
            throw new RuntimeException(__('Failed to add jobs to batch: {0}', $batchId), 0, $throwable);
        }
    }
}
