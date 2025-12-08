<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Model\Entity;

use Cake\ORM\Entity;

/**
 * BatchJob Entity
 *
 * @property string $id
 * @property string $batch_id
 * @property string $job_id
 * @property int $position
 * @property string $status
 * @property array $payload
 * @property mixed $result
 * @property array|null $error
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $completed_at
 *
 * @property \Crustum\BatchQueue\Model\Entity\Batch $batch
 */
class BatchJob extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'batch_id' => true,
        'job_id' => true,
        'position' => true,
        'status' => true,
        'payload' => true,
        'result' => true,
        'error' => true,
        'completed_at' => true,
        'batch' => true,
    ];

    /**
     * Fields that are excluded from JSON serialization
     *
     * @var array<string>
     */
    protected array $_hidden = [];

    /**
     * Check if job is completed
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if job has failed
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if job is pending
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
