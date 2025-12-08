<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;

/**
 * Test Job for BatchQueue testing
 */
class TestJob implements JobInterface
{
    public static array $executedJobs = [];

    public function execute(Message $message): ?string
    {
        self::$executedJobs[] = [
            'job' => static::class,
            'data' => $message->getArguments(),
            'timestamp' => time(),
        ];

        return 'test_job_completed';
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
    }
}
