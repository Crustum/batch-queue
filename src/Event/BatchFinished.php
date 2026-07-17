<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Event;

use Cake\Event\Event;
use Crustum\BatchQueue\Data\BatchDefinition;

/**
 * Dispatched when a batch has completed successfully.
 *
 * @extends \Cake\Event\Event<\Crustum\BatchQueue\Data\BatchDefinition>
 */
class BatchFinished extends Event
{
    public const NAME = 'BatchQueue.BatchFinished';

    /**
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Completed batch
     */
    public function __construct(BatchDefinition $batch)
    {
        parent::__construct(self::NAME, $batch, [
            'batch' => $batch,
        ]);
    }

    /**
     * @return \Crustum\BatchQueue\Data\BatchDefinition
     */
    public function getBatch(): BatchDefinition
    {
        return $this->getSubject();
    }
}
