<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Processor;

use Cake\Core\ContainerInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor;
use Closure;
use Crustum\BatchQueue\ResultAwareInterface;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Interop\Queue\Processor as InteropProcessor;
use Psr\Log\LoggerInterface;

/**
 * Base Batch Processor - Common functionality for batch job processors
 *
 * Provides container-based job execution using getCallable() pattern
 * and common storage access for batch operations.
 */
abstract class BaseBatchProcessor extends Processor
{
    use EventDispatcherTrait;

    /**
     * Batch storage
     *
     * @var \Crustum\BatchQueue\Storage\BatchStorageInterface
     */
    protected BatchStorageInterface $storage;

    /**
     * Constructor
     *
     * @param \Psr\Log\LoggerInterface $logger Logger.
     * @param \Cake\Core\ContainerInterface $container Container.
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        ContainerInterface $container,
    ) {
        parent::__construct($logger, $container);
        $this->storage = $container->get(BatchStorageInterface::class);
    }

    /**
     * Process job message using container-based execution
     *
     * Uses getCallable() to resolve job from container if available,
     * otherwise falls back to direct instantiation.
     *
     * @param \Cake\Queue\Job\Message $message Job message.
     * @return object|string with __toString method implemented
     */
    public function processMessage(Message $message): string|object
    {
        $callable = $message->getCallable();
        $response = $callable($message);

        if ($response === null) {
            $response = InteropProcessor::ACK;
        }

        return $response;
    }

    /**
     * Process job message and get both execution result and job result
     *
     * Executes job using container resolution and returns both the
     * execution response and the job result (if ResultAwareInterface).
     *
     * @param \Cake\Queue\Job\Message $message Job message.
     * @return array{response: string|object, result: mixed} Execution response and job result
     */
    protected function processMessageWithResult(Message $message): array
    {
        $target = $message->getTarget();
        $jobClass = $target[0];

        if ($this->container && $this->container->has($jobClass)) {
            $jobInstance = $this->container->get($jobClass);
        } else {
            $jobInstance = new $jobClass();
        }

        $callable = Closure::fromCallable([$jobInstance, $target[1]]);
        $response = $callable($message);

        if ($response === null) {
            $response = InteropProcessor::ACK;
        }

        $jobResult = null;
        if ($jobInstance instanceof ResultAwareInterface) {
            $jobResult = $jobInstance->getResult();
        }

        return [
            'response' => $response,
            'result' => $jobResult,
        ];
    }
}
