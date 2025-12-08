<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Service;

use Cake\Utility\Text;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use InvalidArgumentException;

/**
 * Unified Batch Builder - Single interface for batches and compensation patterns
 *
 * Supports:
 * - Simple batches: batch([Job1::class, Job2::class])
 * - Batches with compensation: batch([[Job1::class, Undo1::class], Job2::class])
 * - Sequential chains: chain([Step1::class, Step2::class])
 * - Sagas with compensation: chain([[Step1::class, Undo1::class], [Step2::class, Undo2::class]])
 */
final class BatchBuilder
{
    private BatchStorageInterface $storage;
    private ?string $queueConfig;
    private ?string $queueName;
    private string $type;
    private array $jobs;
    private string $batchId;
    private array $context = [];
    private array $options = [];

    /**
     * Constructor
     *
     * @param \Crustum\BatchQueue\Storage\BatchStorageInterface $storage Batch storage
     * @param string|null $queueName Queue name (optional, for named queue resolution)
     * @param string|null $queueConfig Queue configuration name (optional, will be resolved if null)
     * @param string $type Batch type
     * @param array $jobs Job definitions
     */
    public function __construct(
        BatchStorageInterface $storage,
        ?string $queueName,
        ?string $queueConfig,
        string $type,
        array $jobs,
    ) {
        $this->storage = $storage;
        $this->queueName = $queueName;
        $this->queueConfig = $queueConfig;
        $this->type = $type;
        $this->jobs = $jobs;
        $this->batchId = Text::uuid();
    }

    /**
     * Set batch context that will be passed to all jobs
     *
     * @param array $context Context data
     * @return static
     */
    public function setContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Set batch ID (optional - auto-generated if not set)
     *
     * @param string $batchId Batch identifier
     * @return static
     */
    public function setBatchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Set batch name for easier identification
     *
     * @param string $name Batch name
     * @return static
     */
    public function name(string $name): static
    {
        $this->options['name'] = $name;

        return $this;
    }

    /**
     * Set completion callback
     *
     * @param array|string $callback Callback definition
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function onComplete(string|array $callback)
    {
        if (is_callable($callback) && !is_string($callback)) {
            throw new InvalidArgumentException('Closures cannot be used as callbacks in queue systems. Use class names or job definitions.');
        }
        $this->options['on_complete'] = $callback;

        return $this;
    }

    /**
     * Set failure callback (triggers compensation + custom callback)
     *
     * @param array|string $callback Callback definition
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function onFailure(string|array $callback)
    {
        if (is_callable($callback) && !is_string($callback)) {
            throw new InvalidArgumentException('Closures cannot be used as callbacks in queue systems. Use class names or job definitions.');
        }
        $this->options['on_failure'] = $callback;

        return $this;
    }

    /**
     * Set retry configuration
     *
     * @param int $maxRetries Maximum number of retries
     * @param int $retryDelay Delay between retries in seconds
     * @return static
     */
    public function retry(int $maxRetries, int $retryDelay = 30): static
    {
        $this->options['max_retries'] = $maxRetries;
        $this->options['retry_delay'] = $retryDelay;

        return $this;
    }

    /**
     * Set batch timeout
     *
     * @param int $timeoutSeconds Timeout in seconds
     * @return static
     */
    public function timeout(int $timeoutSeconds): static
    {
        $this->options['timeout'] = $timeoutSeconds;

        return $this;
    }

    /**
     * Set queue name for named queue routing
     *
     * @param string $queueName Queue name identifier
     * @return static
     */
    public function queue(string $queueName): static
    {
        $this->queueName = $queueName;

        return $this;
    }

    /**
     * Set queue configuration name
     *
     * @param string $queueConfig Queue configuration name
     * @return static
     */
    public function queueConfig(?string $queueConfig): static
    {
        $this->queueConfig = $queueConfig;

        return $this;
    }

    /**
     * Dispatch the batch to the queue
     *
     * @return string Batch ID
     * @throws \RuntimeException If batch creation fails
     */
    public function dispatch(): string
    {
        if (empty($this->jobs)) {
            throw new InvalidArgumentException('Cannot dispatch empty batch');
        }

        $resolvedQueueConfig = $this->queueConfig;
        if ($resolvedQueueConfig === null && $this->queueName !== null) {
            $resolvedQueueConfig = QueueConfigService::getQueueConfigForNamedQueue($this->queueName);
        }
        if ($resolvedQueueConfig === null) {
            $resolvedQueueConfig = QueueConfigService::getQueueConfig($this->type);
        }

        $batch = new BatchDefinition(
            id: $this->batchId,
            type: $this->type,
            jobs: $this->jobs,
            context: $this->context,
            options: $this->options,
            queueName: $this->queueName,
            queueConfig: $resolvedQueueConfig,
        );

        $this->batchId = $this->storage->createBatch($batch);

        BatchDispatcher::dispatchById($this->batchId, $this->storage);

        return $this->batchId;
    }

    /**
     * Get current batch ID
     *
     * @return string Batch ID
     */
    public function getBatchId(): string
    {
        return $this->batchId;
    }

    /**
     * Get batch context
     *
     * @return array Context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get batch options
     *
     * @return array Options
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
