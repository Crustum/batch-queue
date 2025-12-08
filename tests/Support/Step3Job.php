<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;

/**
 * Step 3 Job for Chain testing
 */
class Step3Job implements JobInterface
{
    public static array $executedJobs = [];

    public function execute(Message $message): ?string
    {
        self::$executedJobs[] = [
            'job' => static::class,
            'data' => $message->getArguments(),
            'timestamp' => time(),
        ];

        return 'step3_completed';
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
    }
}
