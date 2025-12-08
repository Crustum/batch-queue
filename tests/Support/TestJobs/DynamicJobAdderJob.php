<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

/**
 * Test job that adds more jobs to the batch during execution
 */
class DynamicJobAdderJob implements JobInterface
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
        $jobsToAdd = $context['jobs_to_add'] ?? [];

        self::$executionLog[] = [
            'job' => 'DynamicJobAdderJob',
            'position' => $position,
            'batch_id' => $batchId,
        ];

        if ($batchId && !empty($jobsToAdd)) {
            $storage = new SqlBatchStorage();
            $batchManager = new BatchManager($storage);

            $batchManager->addJobs($batchId, $jobsToAdd);
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
