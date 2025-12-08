<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Model\Entity\Batch;

/**
 * Batches Model
 *
 * @property \Cake\ORM\Association\HasMany $BatchJobs
 * @method \Crustum\BatchQueue\Model\Entity\Batch newEmptyEntity()
 * @method \Crustum\BatchQueue\Model\Entity\Batch newEntity(array $data, array $options = [])
 * @method array<\Crustum\BatchQueue\Model\Entity\Batch> newEntities(array $data, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\Batch get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Crustum\BatchQueue\Model\Entity\Batch findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\Batch patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\Crustum\BatchQueue\Model\Entity\Batch> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\Batch|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Crustum\BatchQueue\Model\Entity\Batch saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\Batch> saveMany(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\Batch> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\Batch> deleteMany(iterable $entities, array $options = [])
 * @method iterable<\Crustum\BatchQueue\Model\Entity\Batch> deleteManyOrFail(iterable $entities, array $options = [])
 */
class BatchesTable extends Table
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

        $this->setTable('batches');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->getSchema()->setColumnType('context', 'json');
        $this->getSchema()->setColumnType('options', 'json');

        $this->hasMany('BatchJobs', [
            'foreignKey' => 'batch_id',
            'className' => 'Crustum/BatchQueue.BatchJobs',
            'dependent' => true,
            'cascadeCallbacks' => true,
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
            ->scalar('type')
            ->maxLength('type', 20)
            ->requirePresence('type', 'create')
            ->notEmptyString('type')
            ->inList('type', ['parallel', 'sequential']);

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', ['pending', 'running', 'completed', 'failed']);

        $validator
            ->integer('total_jobs')
            ->requirePresence('total_jobs', 'create')
            ->notEmptyString('total_jobs')
            ->greaterThan('total_jobs', 0);

        $validator
            ->integer('completed_jobs')
            ->notEmptyString('completed_jobs')
            ->greaterThanOrEqual('completed_jobs', 0);

        $validator
            ->integer('failed_jobs')
            ->notEmptyString('failed_jobs')
            ->greaterThanOrEqual('failed_jobs', 0);

        $validator
            ->allowEmptyArray('context');

        $validator
            ->allowEmptyArray('options');

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
     * Create batch from BatchDefinition
     *
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batchDefinition Batch definition
     * @return \Crustum\BatchQueue\Model\Entity\Batch
     */
    public function createFromDefinition(BatchDefinition $batchDefinition): Batch
    {
        $batchData = [
            'id' => $batchDefinition->id,
            'type' => $batchDefinition->type,
            'status' => $batchDefinition->status,
            'total_jobs' => $batchDefinition->totalJobs,
            'completed_jobs' => $batchDefinition->completedJobs,
            'failed_jobs' => $batchDefinition->failedJobs,
            'context' => $batchDefinition->context,
            'options' => $batchDefinition->options,
            'queue_name' => $batchDefinition->queueName,
            'queue_config' => $batchDefinition->queueConfig,
            'completed_at' => $batchDefinition->completedAt,
        ];

        $batch = $this->newEntity($batchData);

        return $this->saveOrFail($batch);
    }

    /**
     * Convert entity to BatchDefinition
     *
     * @param \Crustum\BatchQueue\Model\Entity\Batch $batch Batch entity
     * @return \Crustum\BatchQueue\Data\BatchDefinition
     */
    public function toDefinition(Batch $batch): BatchDefinition
    {
        $jobs = [];
        if (!empty($batch->batch_jobs)) {
            $jobs = array_map(function ($job) {
                $payload = $job->payload;
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    $payload = is_array($decoded) ? $decoded : [];
                }

                $jobData = $payload;

                $error = $job->error;
                if ($error !== null) {
                    if (is_string($error)) {
                        $decoded = json_decode($error, true);
                        $error = is_array($decoded) ? $decoded : ['message' => $error];
                    } elseif (!is_array($error)) {
                        $error = ['message' => (string)$error];
                    }
                }

                $jobData['result'] = $job->result;
                $jobData['error'] = $error;
                $jobData['status'] = $job->status;
                $jobData['batch_id'] = $job->batch_id;
                $jobData['job_id'] = $job->job_id;
                $jobData['id'] = $job->id;

                if ($job->completed_at !== null) {
                    $jobData['completed_at'] = $job->completed_at->format('Y-m-d H:i:s');
                }

                return $jobData;
            }, $batch->batch_jobs);
            usort($jobs, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        }
        $batchData = $batch->toArray();
        $batchData['jobs'] = $jobs;

        if (isset($batchData['modified']) && $batchData['modified'] instanceof DateTime) {
            $batchData['modified'] = $batchData['modified']->format('Y-m-d H:i:s');
        }

        return BatchDefinition::fromArray($batchData);
    }

    /**
     * Find batches by status
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query object
     * @param array<string, mixed> $options Options array
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findByStatus(SelectQuery $query, array $options): SelectQuery
    {
        $status = $options['status'] ?? null;
        if ($status) {
            $query->where(['status' => $status]);
        }

        return $query->orderByDesc('created');
    }

    /**
     * Increment job counter atomically
     *
     * @param string $batchId Batch ID
     * @param string $field Field to increment
     * @return int New counter value
     */
    public function incrementCounter(string $batchId, string $field): int
    {
        $this->updateAll(
            [$field => $this->query()->newExpr($field . ' + 1')],
            ['id' => $batchId],
        );

        $batch = $this->get($batchId);

        return $batch->get($field);
    }

    /**
     * Cleanup old batches
     *
     * @param int $olderThanDays Days threshold
     * @return int Number of deleted batches
     */
    public function cleanupOld(int $olderThanDays = 7): int
    {
        $cutoffDate = (new DateTime())->modify("-{$olderThanDays} days");

        return $this->deleteAll([
            'created <' => $cutoffDate,
            'status IN' => ['completed', 'failed'],
        ]);
    }

    /**
     * Mark batch as failed
     *
     * @param string $batchId Batch ID
     * @return void
     */
    public function markFailed(string $batchId): void
    {
        $this->updateAll(
            ['status' => 'failed', 'completed_at' => new DateTime()],
            ['id' => $batchId],
        );
    }
}
