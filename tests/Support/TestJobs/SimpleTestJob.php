<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Log\Log;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

/**
 * Simple Test Job for BatchQueue testing
 */
class SimpleTestJob implements JobInterface
{
    public static array $executedJobs = [];

    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        Log::info('**SimpleTestJob** executed with data: ' . json_encode($args));

        self::$executedJobs[] = [
            'job' => static::class,
            'data' => $message->getArgument(),
            'timestamp' => time(),
        ];

        return Processor::ACK;
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
    }
}
