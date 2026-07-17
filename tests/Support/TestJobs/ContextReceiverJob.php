<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

/**
 * Test job that receives and records context
 */
class ContextReceiverJob implements JobInterface
{
    /**
     * Execution log
     *
     * @var array<string, mixed>
     */
    public static array $executionLog = [];

    /**
     * Stored contexts
     *
     * @var array<array<string, mixed>>
     */
    public static array $contexts = [];

    /**
     * @inheritDoc
     */
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $batchId = $args['batch_id'] ?? null;
        $position = $args['job_position'] ?? -1;

        $contextReceived = array_filter($args, fn($key): bool => !in_array($key, ['batch_id', 'job_position', 'compensation']), ARRAY_FILTER_USE_KEY);

        self::$executionLog[] = [
            'job' => 'ContextReceiverJob',
            'position' => $position,
            'batch_id' => $batchId,
            'context' => $contextReceived,
        ];

        self::$contexts[] = $contextReceived;

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
