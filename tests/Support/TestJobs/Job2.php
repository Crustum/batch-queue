<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

/**
 * Test job 2 - Simple job for testing
 */
class Job2 implements JobInterface
{
    public static array $executionLog = [];

    /**
     * @inheritDoc
     */
    public function execute(Message $message): ?string
    {
        $context = $message->getArgument();
        $position = $context['job_position'] ?? -1;
        $batchId = $context['batch_id'] ?? null;

        self::$executionLog[] = [
            'job' => 'Job2',
            'position' => $position,
            'batch_id' => $batchId,
        ];

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
