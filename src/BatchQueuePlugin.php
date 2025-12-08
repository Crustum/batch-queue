<?php
declare(strict_types=1);

namespace Crustum\BatchQueue;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Crustum\BatchQueue\Job\CompensationCompleteCallbackJob;
use Crustum\BatchQueue\Job\CompensationFailedCallbackJob;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\BatchStorageInterface;
use Crustum\BatchQueue\Storage\RedisBatchStorage;
use Crustum\BatchQueue\Storage\SqlBatchStorage;

/**
 * Plugin for BatchQueue
 */
class BatchQueuePlugin extends BasePlugin
{
    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        $config = Configure::read('BatchQueue', []);

        $container->add(BatchStorageInterface::class, function () use ($config) {
            $storageType = $config['storage'] ?? 'sql';

            if ($storageType === 'redis') {
                $redisConfig = $config['redis'] ?? [];

                return new RedisBatchStorage($redisConfig);
            } else {
                return new SqlBatchStorage();
            }
        });

        $container->add(BatchManager::class)
            ->addArgument(BatchStorageInterface::class);

        $container->add(CompensationCompleteCallbackJob::class)
            ->addArgument(BatchStorageInterface::class);

        $container->add(CompensationFailedCallbackJob::class)
            ->addArgument(BatchStorageInterface::class);
    }
}
