<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

/**
 * Test job 1 that adds Job3 only
 */
class Job1AddsJob3 implements JobInterface
{
    public static array $executionLog = [];

    /**
     * @inheritDoc
     */
    public function execute(Message $message): ?string
    {
        $context = $message->getArgument();
        $batchId = $context['batch_id'] ?? null;
        $position = $context['job_position'] ?? -1;

        self::$executionLog[] = [
            'job' => 'Job1AddsJob3',
            'position' => $position,
            'batch_id' => $batchId,
        ];

        if ($batchId) {
            $storage = new SqlBatchStorage();
            $batchManager = new BatchManager($storage);

            $batchManager->addJobs($batchId, [Job3::class]);
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
    }
}
