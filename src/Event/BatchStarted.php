<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Event;

use Cake\Event\Event;
use Crustum\BatchQueue\Data\BatchDefinition;

/**
 * Dispatched when a batch has been created and jobs are first queued.
 *
 * @extends \Cake\Event\Event<\Crustum\BatchQueue\Data\BatchDefinition>
 */
class BatchStarted extends Event
{
    public const NAME = 'BatchQueue.BatchStarted';

    /**
     * @param \Crustum\BatchQueue\Data\BatchDefinition $batch Batch that started
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
