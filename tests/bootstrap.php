<?php
declare(strict_types=1);

$findRoot = function () {
    $root = dirname(__DIR__);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 2);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }

    $root = dirname(__DIR__, 3);
    if (is_dir($root . '/vendor/cakephp/cakephp')) {
        return $root;
    }
};

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', $findRoot());
define('APP_DIR', 'TestApp');
define('WEBROOT_DIR', 'webroot');
define('APP', ROOT . '/tests/TestApp/');
define('CONFIG', ROOT . '/tests/TestApp/config/');
define('WWW_ROOT', ROOT . DS . WEBROOT_DIR . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('TMP', ROOT . DS . 'tmp' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require ROOT . '/vendor/cakephp/cakephp/src/functions.php';
require ROOT . '/vendor/autoload.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Enqueue\CakeConnectionFactory;
use Cake\Enqueue\Client\Driver\CakephpDriver;
use Cake\Enqueue\EnqueuePlugin;
use Cake\Error\ErrorTrap;
use Cake\Queue\Plugin as QueuePlugin;
use Cake\Queue\QueueManager;
use Cake\TestSuite\Fixture\SchemaLoader;
use Crustum\BatchQueue\BatchQueuePlugin;
use Crustum\BatchQueue\Processor\BatchJobProcessor;
use Crustum\BatchQueue\Processor\ChainedJobProcessor;
use Enqueue\Client\Resources as ClientResources;
use Enqueue\Resources;

Configure::write('App', ['namespace' => 'TestApp']);
Configure::write('debug', true);

function ensureDirectoryExists(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

ensureDirectoryExists(TMP . 'cache/models');
ensureDirectoryExists(TMP . 'cache/persistent');
ensureDirectoryExists(TMP . 'cache/views');
ensureDirectoryExists(TMP . 'sessions');
ensureDirectoryExists(TMP . 'tests');
ensureDirectoryExists(LOGS);

$cache = [
    'default' => [
        'engine' => 'File',
        'path' => CACHE,
    ],
    '_cake_translations_' => [
        'className' => 'File',
        'prefix' => 'rhythm_test_cake_core_',
        'path' => CACHE . 'persistent/',
        'serialize' => true,
        'duration' => '+10 seconds',
    ],
    '_cake_model_' => [
        'className' => 'File',
        'prefix' => 'rhythm_test_cake_model_',
        'path' => CACHE . 'models/',
        'serialize' => 'File',
        'duration' => '+10 seconds',
    ],
];

Cache::setConfig($cache);
Configure::write('Session', [
    'defaults' => 'php',
]);

Configure::write('App.encoding', 'utf8');

if (!getenv('db_dsn')) {
    putenv('db_dsn=sqlite:///:memory:');
}

ConnectionManager::setConfig('test', [
    'url' => getenv('db_dsn'),
    'timezone' => 'UTC',
]);

ConnectionManager::alias('test', 'default');

// Configure Queue for testing with database transport using CakephpEnqueue
Configure::write('Queue', [
    'default' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'default',
        'receiveTimeout' => 1,
    ],
    'batchjob' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'batchjob',
        'receiveTimeout' => 1,
        'processor' => BatchJobProcessor::class,
    ],
    'chainedjobs' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'chainedjobs',
        'receiveTimeout' => 1,
        'processor' => ChainedJobProcessor::class,
    ],
    'email-chain' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'email-chain',
        'receiveTimeout' => 1,
        'processor' => ChainedJobProcessor::class,
    ],
    'payment-chain' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'payment-chain',
        'receiveTimeout' => 1,
        'processor' => ChainedJobProcessor::class,
    ],
    'custom-batch' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'custom-batch',
        'receiveTimeout' => 1,
        'processor' => BatchJobProcessor::class,
    ],
    'custom-batch-config' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'custom-batch-config',
        'receiveTimeout' => 1,
        'processor' => BatchJobProcessor::class,
    ],
    'custom-parallel' => [
        'url' => 'cakephp://test?table_name=enqueue',
        'queue' => 'custom-parallel',
        'receiveTimeout' => 1,
        'processor' => BatchJobProcessor::class,
    ],
]);

// Configure BatchQueue for testing
Configure::write('BatchQueue', [
    'storage' => 'sql',
    'cleanup' => ['enabled' => false],
]);

// Load required plugins and initialize QueueManager
foreach (Configure::read('Queue') as $key => $data) {
    if (QueueManager::getConfig($key) === null) {
        QueueManager::setConfig($key, $data);
    }
}

if (class_exists('Cake\Queue\Plugin')) {
    Plugin::getCollection()->add(new QueuePlugin());
}

if (class_exists('Cake\Enqueue\EnqueuePlugin')) {
    Plugin::getCollection()->add(new EnqueuePlugin());
}
Resources::addConnection(CakeConnectionFactory::class, [
    'cakephp',
    'cakephpenqueue',
], [], 'cakephp/cakephpenqueue');
ClientResources::addDriver(CakephpDriver::class, ['cakephp', 'cakephpenqueue'], [], ['cakephp/cakephpenqueue']);

Plugin::getCollection()->add(new BatchQueuePlugin());

$loader = new SchemaLoader();
$loader->loadInternalFile(TESTS . 'schema.php');

$error = [
    'errorLevel' => E_ALL,
    'skipLog' => [],
    'log' => true,
    'trace' => true,
    'ignoredDeprecationPaths' => [],
];
(new ErrorTrap($error))->register();
