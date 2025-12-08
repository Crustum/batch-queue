<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\ContextAwareInterface;
use Interop\Queue\Processor;

/**
 * Context Aware Test Job for Chain testing
 */
class ContextAwareTestJob implements JobInterface, ContextAwareInterface
{
    public static array $executedJobs = [];
    public static array $contexts = [];

    private array $context = [];

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function execute(Message $message): ?string
    {
        $data = $message->getArguments();

        if (isset($this->context['step'])) {
            $this->context['step']++;
        }

        self::$executedJobs[] = [
            'job' => static::class,
            'data' => $data,
            'context' => $this->context,
            'timestamp' => time(),
        ];

        self::$contexts[] = $this->context;

        return Processor::ACK;
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
        self::$contexts = [];
    }
}
