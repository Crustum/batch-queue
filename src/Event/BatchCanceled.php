<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Event;

use Cake\Event\Event;
use Crustum\BatchQueue\Data\BatchDefinition;
use Throwable;

/**
 * Dispatched when a batch is cancelled (and storage is about to be deleted).
 *
 * @extends \Cake\Event\Event<\Crustum\BatchQueue\Data\BatchDefinition>
 */
class BatchCanceled extends Event
{
    public const NAME = 'BatchQueue.BatchCanceled';

    /**
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch being cancelled
     * @param \Throwable|null $exception Optional reason for cancellation
     */
    public function __construct(BatchDefinition $batch, ?Throwable $exception = null)
    {
        parent::__construct(self::NAME, $batch, [
            'batch' => $batch,
            'exception' => $exception,
        ]);
    }

    /**
     * @return \Crustum\BatchQueue\Data\BatchDefinition
     */
    public function getBatch(): BatchDefinition
    {
        return $this->getSubject();
    }

    /**
     * @return \Throwable|null
     */
    public function getException(): ?Throwable
    {
        return $this->getData('exception');
    }
}
