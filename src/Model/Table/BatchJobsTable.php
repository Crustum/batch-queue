<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Crustum\BatchQueue\Model\Entity\BatchJob;
use DateTime;

/**
 * BatchJobs Model
 *
 * @property \Cake\ORM\Association\BelongsTo $Batches
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob newEmptyEntity()
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob newEntity(array $data, array $options = [])
 * @method array<\Crustum\BatchQueue\Model\Entity\BatchJob> newEntities(array $data, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\Crustum\BatchQueue\Model\Entity\BatchJob> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\BatchJob saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\BatchJob> saveMany(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\BatchJob> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\BatchJob> deleteMany(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\BatchJob> deleteManyOrFail(iterable $entities, array $options = [])
 */
class BatchJobsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('batch_jobs');
        $this->setDisplayField('job_id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Batches', [
            'foreignKey' => 'batch_id',
            'joinType' => 'INNER',
            'className' => 'Crustum/BatchQueue.Batches',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->uuid('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->uuid('batch_id')
            ->requirePresence('batch_id', 'create')
            ->notEmptyString('batch_id');

        $validator
            ->scalar('job_id')
            ->maxLength('job_id', 255)
            ->requirePresence('job_id', 'create')
            ->allowEmptyString('job_id');

        $validator
            ->integer('position')
            ->requirePresence('position', 'create')
            ->notEmptyString('position')
            ->greaterThanOrEqual('position', 0);

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', ['pending', 'running', 'completed', 'failed']);

        $validator
            ->requirePresence('payload', 'create')
            ->notEmptyArray('payload');

        $validator
            ->allowEmptyString('result');

        $validator
            ->allowEmptyArray('error');

        $validator
            ->dateTime('completed_at')
            ->allowEmptyDateTime('completed_at');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        return $rules;
    }

    /**
     * Create batch jobs from job definitions
     *
     * @param string $batchId Batch ID
     * @param array<int, array<string, mixed>> $jobs Job definitions
     * @return void
     */
    public function createBatchJobs(string $batchId, array $jobs): void
    {
        $entities = [];
        foreach ($jobs as $position => $jobData) {
            $jobId = $jobData['id'] ?? '';
            $entities[] = $this->newEntity([
                'batch_id' => $batchId,
                'job_id' => $jobId,
                'position' => $position,
                'status' => 'pending',
                'payload' => json_encode($jobData),
            ]);
        }
        $this->saveManyOrFail($entities);
    }

    /**
     * Mark job as completed
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @param mixed $result Job result
     * @return void
     */
    public function markCompleted(string $batchId, string $jobId, mixed $result): void
    {
        $this->updateAll([
            'status' => 'completed',
            'result' => $result,
            'completed_at' => new DateTime(),
        ], [
            'batch_id' => $batchId,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Mark job as completed by database ID
     *
     * @param string $batchId Batch ID
     * @param string $dbJobId Database job ID
     * @param mixed $result Job result
     * @return void
     */
    public function markCompletedById(string $batchId, string $dbJobId, mixed $result): void
    {
        $this->updateAll([
            'status' => 'completed',
            'result' => $result,
            'completed_at' => new DateTime(),
        ], [
            'batch_id' => $batchId,
            'id' => $dbJobId,
        ]);
    }

    /**
     * Mark job as failed
     *
     * @param string $batchId Batch ID
     * @param string $jobId Job ID
     * @param array<string, mixed> $error Error details
     * @return void
     */
    public function markFailed(string $batchId, string $jobId, array $error): void
    {
        $this->updateAll([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => new DateTime(),
        ], [
            'batch_id' => $batchId,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Mark job as failed by database ID
     *
     * @param string $batchId Batch ID
     * @param string $dbJobId Database job ID
     * @param array<string, mixed> $error Error details
     * @return void
     */
    public function markFailedById(string $batchId, string $dbJobId, array $error): void
    {
        $this->updateAll([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => new DateTime(),
        ], [
            'batch_id' => $batchId,
            'id' => $dbJobId,
        ]);
    }

    /**
     * Get job results for batch
     *
     * @param string $batchId Batch ID
     * @return array<string, mixed>
     */
    public function getBatchResults(string $batchId): array
    {
        $jobs = $this->find()
            ->select(['job_id', 'result'])
            ->where([
                'batch_id' => $batchId,
                'status' => 'completed',
            ])
            ->toArray();

        $results = [];
        foreach ($jobs as $job) {
            $results[$job->job_id] = $job->result;
        }

        return $results;
    }

    /**
     * Get failed jobs for batch
     *
     * @param string $batchId Batch ID
     * @return array<string, array<string, mixed>>
     */
    public function getFailedJobs(string $batchId): array
    {
        $jobs = $this->find()
            ->select(['job_id', 'payload', 'error'])
            ->where([
                'batch_id' => $batchId,
                'status' => 'failed',
            ])
            ->toArray();

        $failedJobs = [];
        foreach ($jobs as $job) {
            $failedJobs[$job->job_id] = [
                'payload' => $job->payload,
                'error' => $job->error,
            ];
        }

        return $failedJobs;
    }

    /**
     * Convert entity to BatchJobDefinition
     *
     * @param \Crustum\BatchQueue\Model\Entity\BatchJob $job Batch job entity
     * @return \Crustum\BatchQueue\Data\BatchJobDefinition Batch job definition
     */
    public function toDefinition(BatchJob $job): BatchJobDefinition
    {
        $payload = $job->payload;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $error = $job->error;
        if ($error !== null && is_string($error)) {
            $decoded = json_decode($error, true);
            $error = is_array($decoded) ? $decoded : $error;
        }

        return BatchJobDefinition::fromArray([
            'id' => $job->id,
            'batch_id' => $job->batch_id,
            'job_id' => $job->job_id,
            'position' => $job->position,
            'status' => $job->status,
            'payload' => $payload,
            'result' => $job->result,
            'error' => $error,
            'created' => $job->created !== null ? $job->created->format('Y-m-d H:i:s') : null,
            'modified' => $job->modified !== null ? $job->modified->format('Y-m-d H:i:s') : null,
            'completed_at' => $job->completed_at !== null ? $job->completed_at->format('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * Create entity from BatchJobDefinition
     *
     * @param \Crustum\BatchQueue\Data\BatchJobDefinition $jobDefinition Batch job definition
     * @return \Crustum\BatchQueue\Model\Entity\BatchJob Batch job entity
     */
    public function createFromDefinition(BatchJobDefinition $jobDefinition): BatchJob
    {
        $data = [
            'id' => $jobDefinition->id,
            'batch_id' => $jobDefinition->batchId,
            'job_id' => $jobDefinition->jobId,
            'position' => $jobDefinition->position,
            'status' => $jobDefinition->status,
            'payload' => json_encode($jobDefinition->payload),
            'result' => $jobDefinition->result,
            'error' => $jobDefinition->error,
            'completed_at' => $jobDefinition->completedAt,
        ];

        $entity = $this->newEntity($data);

        return $this->saveOrFail($entity);
    }
}
