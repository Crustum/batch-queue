<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Service;

use Cake\Core\Configure;
use Crustum\BatchQueue\Processor\BatchJobProcessor;
use Crustum\BatchQueue\Processor\ChainedJobProcessor;

/**
 * Queue Configuration Service
 *
 * Resolves queue configuration names with priority order:
 * 1. User override via Configure
 * 2. Plugin default configuration
 */
class QueueConfigService
{
    /**
     * Default plugin queue configurations
     */
    private const DEFAULT_CONFIGS = [
        'parallel' => 'batchjob',
        'sequential' => 'chainedjobs',
    ];

    /**
     * Get queue config name for batch type
     *
     * @param string $batchType Batch type ('parallel' or 'sequential')
     * @return string Queue config name
     */
    public static function getQueueConfig(string $batchType): string
    {
        $userConfig = Configure::read("BatchQueue.queues.default.{$batchType}");
        if ($userConfig) {
            return $userConfig;
        }

        return self::DEFAULT_CONFIGS[$batchType] ?? 'default';
    }

    /**
     * Get queue config for named queue
     *
     * @param string $queueName Named queue identifier
     * @return string|null Queue config name or null if not configured
     */
    public static function getQueueConfigForNamedQueue(string $queueName): ?string
    {
        $namedConfig = Configure::read("BatchQueue.queues.named.{$queueName}");
        if ($namedConfig && isset($namedConfig['queue_config'])) {
            return $namedConfig['queue_config'];
        }

        return null;
    }

    /**
     * Get processor class for batch type
     *
     * @param string $batchType Batch type ('parallel' or 'sequential')
     * @param string|null $queueName Optional named queue identifier
     * @return string Processor class name
     */
    public static function getProcessorClass(string $batchType, ?string $queueName = null): string
    {
        if ($queueName !== null) {
            $namedConfig = Configure::read("BatchQueue.queues.named.{$queueName}");
            if ($namedConfig && isset($namedConfig['processor'])) {
                return $namedConfig['processor'];
            }
        }

        $typeConfig = Configure::read("BatchQueue.queues.types.{$batchType}");
        if ($typeConfig && isset($typeConfig['processor'])) {
            return $typeConfig['processor'];
        }

        return $batchType === 'parallel'
            ? BatchJobProcessor::class
            : ChainedJobProcessor::class;
    }
}
