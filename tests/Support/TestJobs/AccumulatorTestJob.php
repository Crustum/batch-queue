<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Log\Log;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor;
use Crustum\BatchQueue\ResultAwareInterface;

/**
 * Accumulator Test Job for testing args passing and accumulation
 *
 * Reads a value from args and stores it as result for later aggregation.
 * Implements ResultAwareInterface to return structured data.
 */
class AccumulatorTestJob implements ResultAwareInterface, JobInterface
{
    public static array $executedJobs = [];

    private mixed $result = null;

    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();

        $value = $args['value'] ?? 0;
        $batchId = $args['batch_id'] ?? null;
        $jobPosition = $args['job_position'] ?? null;

        Log::info("**AccumulatorTestJob** executed with value: {$value}, batch_id: {$batchId}, position: {$jobPosition}");

        self::$executedJobs[] = [
            'job' => static::class,
            'value' => $value,
            'batch_id' => $batchId,
            'job_position' => $jobPosition,
            'all_args' => $args,
            'timestamp' => time(),
        ];

        $this->result = ['value' => $value, 'accumulated' => true];

        return Processor::ACK;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
    }
}
