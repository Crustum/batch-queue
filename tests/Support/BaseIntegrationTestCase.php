<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\Queue\QueueManager;
use Cake\TestSuite\TestCase;

/**
 * Base Integration Test Case
 *
 * Provides common functionality for all BatchQueue integration tests including:
 * - Queue configuration management
 * - Queue cleanup methods
 * - Table locator cleanup for CI compatibility
 */
abstract class BaseIntegrationTestCase extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = ['plugin.Crustum/BatchQueue.Batches', 'plugin.Crustum/BatchQueue.BatchJobs'];

    /**
     * Setup
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace();

        if (!class_exists('TestApp\Application')) {
            require_once dirname(__DIR__) . DS . 'TestApp' . DS . 'Application.php';
        }

        $this->configApplication(
            'TestApp\Application',
            [CONFIG],
        );

        $this->clearAllQueues();
        $this->registerQueueConfigs();
        $this->clearAllQueues();
    }

    /**
     * Teardown
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearAllQueues();
        Configure::delete('BatchQueue.queues');

        $locator = TableRegistry::getTableLocator();
        if ($locator->exists('Cake/Enqueue.Enqueue')) {
            $locator->remove('Cake/Enqueue.Enqueue');
        }

        parent::tearDown();
    }

    /**
     * Register queue configurations
     *
     * @return void
     */
    protected function registerQueueConfigs(): void
    {
        foreach (Configure::read('Queue') as $key => $data) {
            if (QueueManager::getConfig($key) === null) {
                QueueManager::setConfig($key, $data);
            }
        }
    }

    /**
     * Count messages in a queue
     *
     * @param string $queueName Queue name
     * @return int Number of messages
     */
    protected function countMessages(string $queueName): int
    {
        $queueName = 'enqueue.app.' . $queueName;
        $locator = TableRegistry::getTableLocator();

        if (!$locator->exists('Cake/Enqueue.Enqueue')) {
            return 0;
        }

        $enqueueTable = $locator->get('Cake/Enqueue.Enqueue');

        return $enqueueTable->find()
            ->where(['queue' => $queueName])
            ->count();
    }

    /**
     * Refresh QueueManager by dropping and re-registering queue configs
     *
     * @return void
     */
    protected function refreshQM(): void
    {
        QueueManager::drop('default');
        QueueManager::drop('batch');
        QueueManager::drop('batchjob');
        QueueManager::drop('chainedjobs');
        QueueManager::drop('email-chain');
        QueueManager::drop('payment-chain');
        QueueManager::drop('custom-batch');
        QueueManager::drop('custom-batch-config');
        QueueManager::drop('custom-parallel');

        $this->registerQueueConfigs();
    }

    /**
     * Clear all queues
     *
     * @return void
     */
    protected function clearAllQueues(): void
    {
        $this->clearQueue('default');
        $this->clearQueue('batch');
        $this->clearQueue('batchjob');
        $this->clearQueue('chainedjobs');
        $this->clearQueue('email-chain');
        $this->clearQueue('payment-chain');
        $this->clearQueue('custom-batch');
        $this->clearQueue('custom-batch-config');
        $this->clearQueue('custom-parallel');
    }

    /**
     * Clear a specific queue
     *
     * @param string $queueName Queue name
     * @return void
     */
    protected function clearQueue(string $queueName): void
    {
        $locator = TableRegistry::getTableLocator();

        if (!$locator->exists('Cake/Enqueue.Enqueue')) {
            return;
        }

        $enqueueTable = $locator->get('Cake/Enqueue.Enqueue');
        $enqueueTable->deleteAll(['queue LIKE' => '%' . $queueName . '%']);
    }
}
