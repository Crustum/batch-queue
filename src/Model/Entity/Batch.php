<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Model\Entity;

use Cake\ORM\Entity;

/**
 * Batch Entity
 *
 * @property string $id
 * @property string $type
 * @property string $status
 * @property int $total_jobs
 * @property int $completed_jobs
 * @property int $failed_jobs
 * @property array $context
 * @property array $options
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $completed_at
 *
 * @property \Crustum\BatchQueue\Model\Entity\BatchJob[] $batch_jobs
 */
class Batch extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'type' => true,
        'status' => true,
        'total_jobs' => true,
        'completed_jobs' => true,
        'failed_jobs' => true,
        'context' => true,
        'options' => true,
        'queue_name' => true,
        'queue_config' => true,
        'completed_at' => true,
        'batch_jobs' => true,
    ];

    /**
     * Fields that are excluded from JSON serialization
     *
     * @var array<string>
     */
    protected array $_hidden = [];

    /**
     * Check if batch is completed
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if batch has failed
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if batch is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Get completion percentage
     *
     * @return float
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_jobs === 0) {
            return 0.0;
        }

        return $this->completed_jobs / $this->total_jobs * 100.0;
    }

    /**
     * Get number of pending jobs
     *
     * @return int
     */
    public function getPendingJobs(): int
    {
        return $this->total_jobs - $this->completed_jobs - $this->failed_jobs;
    }
}
