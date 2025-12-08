<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Console\CommandCollection;
use Cake\Enqueue\CakeConnectionFactory;
use Cake\Enqueue\Client\Driver\CakephpDriver;
use Cake\Enqueue\Plugin as EnqueuePlugin;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Queue\Plugin;
use Cake\Routing\RouteBuilder;
use Enqueue\Client\Resources as ClientResources;
use Enqueue\Resources;

/**
 * Test Application for BatchQueue Plugin
 */
class Application extends BaseApplication
{
    public function bootstrap(): void
    {
        parent::bootstrap();

        if (class_exists('Cake\Queue\Plugin')) {
            $this->addPlugin(Plugin::class);
        }
        $this->addPlugin('Crustum/BatchQueue');
        $this->addPlugin(EnqueuePlugin::class, ['bootstrap' => true]);

        Resources::addConnection(CakeConnectionFactory::class, [
            'cakephp',
            'cakephpenqueue',
        ], [], 'cakephp/cakephpenqueue');
        ClientResources::addDriver(CakephpDriver::class, ['cakephp', 'cakephpenqueue'], [], ['cakephp/cakephpenqueue']);
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    public function routes(RouteBuilder $routes): void
    {
        parent::routes($routes);
    }

    public function console(CommandCollection $commands): CommandCollection
    {
        return parent::console($commands);
    }
}
