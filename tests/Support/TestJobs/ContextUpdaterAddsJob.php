<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\ContextAwareInterface;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

/**
 * Test job that updates context and adds another job
 */
class ContextUpdaterAddsJob implements JobInterface, ContextAwareInterface
{
    public static array $executionLog = [];
    public static array $contexts = [];

    private array $context = [];

    /**
     * @inheritDoc
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * @inheritDoc
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @inheritDoc
     */
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $batchId = $args['batch_id'] ?? null;
        $position = $args['job_position'] ?? -1;

        $this->context['step'] = 2;
        $this->context['data'] = 'value';

        self::$executionLog[] = [
            'job' => 'ContextUpdaterAddsJob',
            'position' => $position,
            'batch_id' => $batchId,
            'context' => $this->context,
        ];

        self::$contexts[] = $this->context;

        if ($batchId) {
            $storage = new SqlBatchStorage();
            $batchManager = new BatchManager($storage);

            $batchManager->addJobs($batchId, [ContextReceiverJob::class]);
        }

        return Processor::ACK;
    }

    /**
     * Reset execution log
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$executionLog = [];
        self::$contexts = [];
    }
}
