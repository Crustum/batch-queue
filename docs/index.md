# CakePHP BatchQueue Plugin

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Queue Setup](#queue-setup)
- [Quickstart](#quickstart)
- [Parallel Batches](#parallel-batches)
- [Sequential Chains](#sequential-chains)
- [Dynamic Job Addition](#dynamic-job-addition)
- [Compensation Patterns](#compensation-patterns)
- [Context Management](#context-management)
- [Batch Options](#batch-options)
- [Named Queues](#named-queues)
- [Progress Tracking](#progress-tracking)
- [Storage Backends](#storage-backends)
- [Job Results](#job-results)

<a name="introduction"></a>
## Introduction

The BatchQueue plugin provides a unified system for managing batch job processing in CakePHP applications. It supports both parallel execution (running the same job with different arguments simultaneously) and sequential chains (jobs run one after another with context accumulation). The plugin includes built-in support for compensation patterns, allowing you to define rollback operations that execute automatically when jobs fail.

The primary use case for parallel batches is the map-reduce pattern: running the same job class with different arguments to process multiple items concurrently. For sequential chains, the plugin automatically accumulates context between jobs, allowing each step to build upon previous results.

BatchQueue integrates seamlessly with the CakePHP Queue plugin and works perfectly with monitoring tools like the Monitor plugin. All batch jobs are pushed to the default queue as regular jobs, ensuring full compatibility with existing queue infrastructure.

Key features include job-specific arguments for parallel batches, automatic context accumulation in sequential chains, compensation job execution on failures, batch progress tracking, and flexible storage backends (SQL or Redis). The plugin handles job execution, failure tracking, and batch completion automatically.

<a name="installation"></a>
## Installation

Install the plugin via Composer:

```bash
composer require crustum/batch-queue
```

Load the plugin in your `Application.php`:

```php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/BatchQueue', ['bootstrap' => true, 'routes' => false]);
}
```

Run the database migrations:

```bash
bin/cake migrations migrate -p Crustum/BatchQueue
```

The migrations create two tables:
- `batches` - Stores batch metadata and progress
- `batch_jobs` - Tracks individual job execution within batches

<a name="configuration"></a>
## Configuration

The plugin can be configured via the `config/app.php` file or a dedicated `config/batch_queue.php` file:

```php
return [
    'BatchQueue' => [
        'storage' => env('BATCH_QUEUE_STORAGE', 'sql'),
    ],
];
```

<a name="queue-setup"></a>
## Queue Setup

BatchQueue requires proper queue configuration with dedicated processors for parallel batches and sequential chains.

### Required Queue Configurations

Add the following queue configurations to your `config/app.php`:

```php
'Queue' => [
    'default' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'default',
    ],
    'batchjob' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'batchjob',
        'processor' => \Crustum\BatchQueue\Processor\BatchJobProcessor::class,
    ],
    'chainedjobs' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'chainedjobs',
        'processor' => \Crustum\BatchQueue\Processor\ChainedJobProcessor::class,
    ],
],
```

The `BatchJobProcessor` handles parallel batch jobs, while `ChainedJobProcessor` handles sequential chain jobs with context passing.

### Running Workers

You need to run separate workers for each queue type:

```bash
bin/cake queue worker --config=batchjob --queue=batchjob
bin/cake queue worker --config=chainedjobs --queue=chainedjobs
```

Sequential chains typically need fewer workers since jobs execute one at a time, while parallel batches benefit from multiple workers for concurrent processing.

<a name="quickstart"></a>
## Quickstart

The simplest way to use BatchQueue is through the `BatchManager` service. Get an instance from the container:

```php
use Crustum\BatchQueue\Service\BatchManager;

$batchManager = \Cake\Core\FactoryLocator::get('Service', 'BatchManager');
```

### Simple Parallel Batch

Create a batch where the same job runs with different arguments (map-reduce pattern):

```php
$batchId = $batchManager->batch([
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 1]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 2]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 3]],
    ])
    ->dispatch();
```

All jobs run simultaneously, each processing a different order.

### Simple Sequential Chain

Create a chain where jobs execute one after another with automatic context accumulation:

```php
$batchId = $batchManager->chain([
        ValidateOrderJob::class,
        ChargePaymentJob::class,
        SendConfirmationJob::class,
    ])
    ->setContext(['order_id' => 123])
    ->dispatch();
```

Each job in a sequential chain automatically receives the accumulated context from previous jobs. Jobs that implement `ContextAwareInterface` can update the context, and subsequent jobs receive the updated context automatically.

<a name="parallel-batches"></a>
## Parallel Batches

Parallel batches execute all jobs simultaneously. The primary use case is running the **same job class with different arguments** (map-reduce pattern). This is useful when you need to process multiple items in parallel, such as processing multiple orders, sending emails to multiple users, or generating reports for different data sets.

### Basic Parallel Batch with Job Arguments

The main pattern for parallel batches is passing different arguments to the same job class:

```php
$batchId = $batchManager->batch([
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 1]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 2]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 3]],
    ])
    ->dispatch();
```

All three jobs will start executing immediately when the batch is dispatched, each with its own `order_id` argument. The batch completes when all jobs finish successfully.

### Accessing Job Arguments

Jobs receive their specific arguments through the message:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

class ProcessOrderJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $orderId = $message->getArgument('order_id');
        $batchId = $message->getArgument('batch_id');
        $jobPosition = $message->getArgument('job_position');

        // Process order with $orderId...
        return Processor::ACK;
    }
}
```

### Parallel Batch with Shared Context

You can optionally provide shared context data that all jobs in the batch can access:

```php
$batchId = $batchManager->batch([
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 1]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 2]],
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 3]],
    ])
    ->setContext([
        'user_id' => 123,
        'operation_type' => 'bulk_process',
    ])
    ->dispatch();
```

Jobs receive both their specific arguments and the shared context:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

class ProcessOrderJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $orderId = $message->getArgument('order_id'); // Job-specific arg
        $userId = $message->getArgument('user_id'); // From shared context
        $operationType = $message->getArgument('operation_type'); // From shared context

        // Process order...
        return Processor::ACK;
    }
}
```

### Mixed Job Types in Batch

You can also mix different job classes in a batch, though the common pattern is using the same job with different arguments:

```php
$batchId = $batchManager->batch([
        ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 1]],
        ['class' => SendEmailJob::class, 'args' => ['email' => 'user@example.com']],
        ['class' => GenerateReportJob::class, 'args' => ['report_type' => 'summary']],
    ])
    ->dispatch();
```

### Simple Job Class Syntax

For jobs that don't need arguments, you can use the simple class name syntax:

```php
$batchId = $batchManager->batch([
    ProcessOrderJob::class,
    ProcessOrderJob::class,
    ProcessOrderJob::class,
])
->dispatch();
```

This is equivalent to:

```php
$batchId = $batchManager->batch([
        ['class' => ProcessOrderJob::class],
        ['class' => ProcessOrderJob::class],
        ['class' => ProcessOrderJob::class],
    ])
    ->dispatch();
```

<a name="sequential-chains"></a>
## Sequential Chains

Sequential chains execute jobs one after another, with each job receiving the accumulated context from previous jobs. **Context passing and accumulation is the key feature of sequential chains** - each job can add data to the context, and subsequent jobs automatically receive the updated context.

### Basic Sequential Chain

```php
$batchId = $batchManager->chain([
    ValidateOrderJob::class,
    ChargePaymentJob::class,
    SendConfirmationJob::class,
])
->setContext(['order_id' => 123])
->dispatch();
```

The chain executes in order:
1. `ValidateOrderJob` runs first with the initial context
2. When it completes, `ChargePaymentJob` runs with access to validation results in the context
3. When payment completes, `SendConfirmationJob` runs with access to both previous results

### Context Accumulation

In sequential chains, context accumulates automatically. Each job can add data to the context, and subsequent jobs receive the updated context:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\ContextAwareInterface;
use Crustum\BatchQueue\ResultAwareInterface;
use Interop\Queue\Processor;

class ValidateOrderJob implements JobInterface, ContextAwareInterface, ResultAwareInterface
{
    private array $context = [];
    private mixed $result = null;

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function execute(Message $message): ?string
    {
        $orderId = $this->context['order_id'];

        // Validate order...
        $validationResult = ['validated' => true, 'total' => 99.99];

        // Update context for next job
        $this->context['validation'] = $validationResult;

        // Store result for collection
        $this->result = $validationResult;

        return Processor::ACK;
    }
}

class ChargePaymentJob implements JobInterface, ContextAwareInterface, ResultAwareInterface
{
    private array $context = [];
    private mixed $result = null;

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function execute(Message $message): ?string
    {
        // Access validation results from previous job
        $validation = $this->context['validation'] ?? null;
        $total = $validation['total'] ?? 0;

        // Charge payment...
        $chargeResult = ['charged' => true, 'transaction_id' => 'txn_123'];

        // Add to context for next job
        $this->context['payment'] = $chargeResult;

        // Store result for collection
        $this->result = $chargeResult;

        return Processor::ACK;
    }
}
```

The context is automatically persisted and passed to the next job in the chain. This allows sequential jobs to build upon previous results.

The result is automatically stored individually for each job implementing `ResultAwareInterface` when the job completes successfully.

<a name="dynamic-job-addition"></a>
## Dynamic Job Addition

Dynamic job addition allows jobs to add more jobs to an existing batch during execution. This enables adaptive workflows where later steps are determined based on runtime results.

### When to Use Dynamic Job Addition

Dynamic job addition is useful for adaptive workflows where next steps depend on job results. It enables multi-stage processing with variable steps determined at runtime. The feature supports saga patterns with dynamic compensation, allowing workflows to add compensating transactions based on execution results. Jobs can discover additional work during execution and dynamically expand the batch to handle that work.

### Adding Jobs to Sequential Batches

Jobs can add additional steps to sequential chains during execution:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

class DiscoverTasksJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $context = $message->getArgument();
        $batchId = $context['batch_id'];

        $tasksToProcess = $this->discoverTasks();

        $batchManager = new BatchManager(new SqlBatchStorage());
        $batchManager->addJobs($batchId, [
            ProcessTaskAJob::class,
            ProcessTaskBJob::class,
            ProcessTaskCJob::class,
        ]);

        return Processor::ACK;
    }
}
```

The newly added jobs will execute after the current job completes, maintaining the sequential order.

### Adding Jobs to Parallel Batches

Jobs in parallel batches can also add more jobs dynamically:

```php
$batchId = $batchManager->batch([
    ['class' => ScanDirectoryJob::class, 'args' => ['path' => '/uploads']],
    ProcessMetadataJob::class,
])->dispatch();
```

When `ScanDirectoryJob` discovers files, it can add processing jobs for each file:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Service\BatchManager;
use Crustum\BatchQueue\Storage\SqlBatchStorage;
use Interop\Queue\Processor;

class ScanDirectoryJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $files = $this->scanDirectory($message->getArgument('path'));

        $batchId = $message->getArgument('batch_id');
        $batchManager = new BatchManager(new SqlBatchStorage());

        $newJobs = [];
        foreach ($files as $file) {
            $newJobs[] = ['class' => ProcessFileJob::class, 'args' => ['file' => $file]];
        }

        $batchManager->addJobs($batchId, $newJobs);

        return Processor::ACK;
    }
}
```

New jobs in parallel batches are queued immediately for concurrent execution.

### Context Propagation

Dynamically added jobs receive the current batch context, including any updates made by previous jobs:

```php
class UpdateContextJob implements ContextAwareInterface
{
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
        $this->context['stage'] = 'processing';
        $this->context['timestamp'] = time();

        $batchId = $message->getArgument('batch_id');
        $batchManager = new BatchManager(new SqlBatchStorage());

        $batchManager->addJobs($batchId, [NextStepJob::class]);

        return Processor::ACK;
    }
}
```

The `NextStepJob` will receive the updated context with `stage` and `timestamp` values.

### Nested Job Addition

Jobs added dynamically can themselves add more jobs, creating multi-level workflows:

```php
$batchId = $batchManager->chain([InitialJob::class])->dispatch();
```

`InitialJob` adds `MiddleJob`, which then adds `FinalJob`, resulting in a three-step chain determined at runtime.

### Limitations

Jobs cannot be added to batches that have already completed or failed. New jobs are always appended to the end of the batch and cannot be inserted at specific positions. In sequential batches, dynamically added jobs execute after all originally queued jobs in the order they were added. The batch completion process waits for all dynamically added jobs to finish before marking the batch as complete, ensuring no jobs are lost or skipped.

<a name="compensation-patterns"></a>
## Compensation Patterns

Compensation patterns allow you to define rollback operations that execute automatically when jobs fail. This is essential for maintaining data consistency in distributed systems.

### Parallel Batch with Compensation

Define compensation jobs alongside regular jobs:

```php
$batchId = $batchManager->batch([
    [SendEmailJob::class, CancelEmailJob::class],
    [ProcessOrderJob::class, RefundOrderJob::class],
    GenerateReportJob::class, // No compensation for this one
])
->setContext(['user_id' => 123, 'order_id' => 456])
->dispatch();
```

If `SendEmailJob` fails, `CancelEmailJob` will be queued automatically. If `ProcessOrderJob` fails, `RefundOrderJob` will be queued. Compensation jobs receive special context about the failure.

### Sequential Chain with Compensation (Saga Pattern)

Create a full saga with compensation for each step:

```php
$batchId = $batchManager->chain([
    [CreateUserAccountJob::class, DeleteUserAccountJob::class],
    [SendWelcomeEmailJob::class, SendCancellationEmailJob::class],
    [InitializeUserSettingsJob::class, RemoveUserSettingsJob::class],
])
->setContext(['user_id' => 123])
->dispatch();
```

If any job in the chain fails, all previously completed jobs will have their compensation jobs executed in reverse order. This ensures proper rollback of all operations.

### Compensation Job Implementation

Compensation jobs receive special context about the original failure:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Interop\Queue\Processor;

class RefundOrderJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $compensation = $message->getArgument('_compensation');

        $batchId = $compensation['batch_id'];
        $originalJobId = $compensation['original_job_id'];
        $originalJobClass = $compensation['original_job_class'];
        $error = $compensation['error'];
        $context = $compensation['context'];

        // Perform refund using context from original job
        $orderId = $context['order_id'];

        // Refund logic...

        return Processor::ACK;
    }
}
```

The `_compensation` argument contains all information needed to properly rollback the original operation.

<a name="context-management"></a>
## Context Management

Context is the primary mechanism for sequential chains to pass and accumulate data between jobs. In parallel batches, context is optional and typically used for shared metadata that all jobs can access.

### ContextAwareInterface

Jobs implement the `ContextAwareInterface` to receive and update batch context. The interface requires two methods for managing context data:

```php
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\ContextAwareInterface;

class ProcessOrderJob implements JobInterface, ContextAwareInterface
{
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
        $orderId = $this->context['order_id'];
        $userId = $this->context['user_id'];

        $orderTotal = $this->processOrder($orderId);

        $this->context['order_total'] = $orderTotal;
        $this->context['processed_at'] = time();

        return Processor::ACK;
    }
}
```

The `setContext()` method receives the current batch context before job execution. The `getContext()` method returns the updated context after execution, which is automatically saved and passed to subsequent jobs in sequential chains.

### Context in Sequential Chains

Context accumulation is the key feature of sequential chains. Each job that implements `ContextAwareInterface` can update the context, and subsequent jobs automatically receive the accumulated context. This allows jobs to build upon previous results.

The context starts with initial values set via `setContext()` on the batch builder:

```php
$batchId = $batchManager->chain([
    ValidateOrderJob::class,
    ChargePaymentJob::class,
    SendConfirmationJob::class,
])
->setContext(['order_id' => 123])
->dispatch();
```

The first job receives `['order_id' => 123]`. If it updates the context to include `['order_id' => 123, 'validated' => true]`, the second job automatically receives this updated context. This continues throughout the chain, allowing each job to contribute data for subsequent steps.

See the [Sequential Chains](#sequential-chains) section for detailed examples of context accumulation.

### Context in Parallel Batches

In parallel batches, context is optional and typically used for shared metadata:

```php
$batchId = $batchManager->batch([
    ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 1]],
    ['class' => ProcessOrderJob::class, 'args' => ['order_id' => 2]],
])
->setContext([
    'user_id' => 123,
    'operation_type' => 'bulk_process',
])
->dispatch();
```

All jobs receive the same context, but each job processes different data based on its arguments. Context in parallel batches is read-only and does not accumulate between jobs, since parallel jobs execute simultaneously without a defined order.

### Accessing Context Without ContextAwareInterface

Jobs that don't implement `ContextAwareInterface` can still access context data through message arguments:

```php
class SimpleJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $context = $message->getArgument();

        $orderId = $context['order_id'] ?? null;
        $userId = $context['user_id'] ?? null;

        return Processor::ACK;
    }
}
```

However, these jobs cannot update the context for subsequent jobs in the chain. Only jobs implementing `ContextAwareInterface` can modify and accumulate context.

<a name="batch-options"></a>

### Completion Callback

Execute a callback when the batch completes successfully:

```php
$batchId = $batchManager->batch([Job1::class, Job2::class])
->onComplete([
    'class' => BatchCompletionJob::class,
    'args' => ['notification_type' => 'success'],
])
->dispatch();
```

The completion callback receives batch information:

```php
class BatchCompletionJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $batchId = $message->getArgument('batch_id');
        $status = $message->getArgument('status');

        // Handle batch completion...
        return Processor::ACK;
    }
}
```

### Failure Callback

Execute a callback when the batch fails:

```php
$batchId = $batchManager->batch([Job1::class, Job2::class])
->onFailure([
    'class' => BatchFailureJob::class,
    'args' => ['alert_team' => true],
])
->dispatch();
```

The failure callback receives batch information and error details:

```php
class BatchFailureJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $batchId = $message->getArgument('batch_id');
        $status = $message->getArgument('status');
        $error = $message->getArgument('error');

        // Handle batch failure...
        return Processor::ACK;
    }
}
```

<a name="named-queues"></a>
## Named Queues

Named queues allow you to route specific batches to dedicated queue workers, enabling workload isolation and resource allocation control.

### Configuring Named Queues

Define custom queue configurations in `config/app.php`:

```php
'Queue' => [
    'default' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'default',
    ],
    'batchjob' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'batchjob',
        'processor' => \Crustum\BatchQueue\Processor\BatchJobProcessor::class,
    ],
    'chainedjobs' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'chainedjobs',
        'processor' => \Crustum\BatchQueue\Processor\ChainedJobProcessor::class,
    ],
    'email-chain' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'email-chain',
        'processor' => \Crustum\BatchQueue\Processor\ChainedJobProcessor::class,
    ],
    'payment-chain' => [
        'url' => 'cakephp://default?table_name=enqueue',
        'queue' => 'payment-chain',
        'processor' => \Crustum\BatchQueue\Processor\ChainedJobProcessor::class,
    ],
],
```

Configure named queue mappings in BatchQueue configuration:

```php
'BatchQueue' => [
    'storage' => 'sql',
    'queues' => [
        'named' => [
            'email-processing' => [
                'queue_config' => 'email-chain',
            ],
            'payment-processing' => [
                'queue_config' => 'payment-chain',
            ],
        ],
    ],
],
```

### Using Named Queues

Route batches to specific queues using the `queue()` method:

```php
$batchId = $batchManager->chain([
    SendWelcomeEmailJob::class,
    SendConfirmationEmailJob::class,
    UpdateEmailStatsJob::class,
])
->queue('email-processing')
->dispatch();
```

This batch will be processed by workers dedicated to the `email-chain` queue.

### Running Named Queue Workers

Start dedicated workers for each named queue:

```bash
bin/cake queue worker --config=email-chain --queue=email-chain
bin/cake queue worker --config=payment-chain --queue=payment-chain
```

### Use Cases for Named Queues

**Email Processing Isolation:**
```php
$batchManager->chain([...])
    ->queue('email-processing')
    ->dispatch();
```

Isolate email sending to dedicated workers, preventing email delays from affecting other processing.

**Payment Processing Priority:**
```php
$batchManager->chain([...])
    ->queue('payment-processing')
    ->dispatch();
```

Separate workloads by tenant for isolation and fair resource allocation.

### Queue Config Override

Alternatively, specify the queue configuration directly without named queue mapping:

```php
$batchManager->chain([...])
    ->queueConfig('email-chain')
    ->dispatch();
```

This directly uses the `email-chain` queue configuration without going through named queue resolution.

<a name="progress-tracking"></a>
## Progress Tracking

Monitor batch execution progress and status in real-time.

### Getting Batch Status

Retrieve complete batch information:

```php
$batch = $batchManager->getBatch($batchId);

echo "Status: {$batch->status}\n";
echo "Total Jobs: {$batch->totalJobs}\n";
echo "Completed: {$batch->completedJobs}\n";
echo "Failed: {$batch->failedJobs}\n";
echo "Type: {$batch->type}\n";
```

### Getting Progress Information

Get formatted progress data:

```php
$progress = $batchManager->getProgress($batchId);

echo "Progress: {$progress['progress_percentage']}%\n";
echo "Completed: {$progress['completed_jobs']}/{$progress['total_jobs']}\n";
echo "Status: {$progress['status']}\n";
```


### Checking Batch Completion

Wait for batch completion synchronously (use sparingly):

```php
$batch = $batchManager->getBatch($batchId);

while (!in_array($batch->status, ['completed', 'failed'])) {
    sleep(2);
    $batch = $batchManager->getBatch($batchId);
}

if ($batch->status === 'completed') {
    // Handle success
} else {
    // Handle failure
}
```

For production use, implement this check in a background job or use batch completion callbacks instead.

<a name="storage-backends"></a>
## Storage Backends

BatchQueue supports SQL and Redis storage backends for batch metadata.

### SQL Storage (Default)

SQL storage uses CakePHP ORM with proper transactions:

```php
'BatchQueue' => [
    'storage' => 'sql',
],
```

SQL storage provides ACID compliance with full transaction support, leveraging your existing database infrastructure without requiring additional services. The storage backend is easier to query and debug using standard SQL tools, making it suitable for most applications. SQL storage works well with moderate batch volumes up to 10,000 batches per day, provides transaction guarantees for data consistency, enables simple deployment without additional dependencies, and allows querying batch history using familiar SQL queries.

### Redis Storage

Redis storage provides high-performance batch metadata storage:

```php
'BatchQueue' => [
    'storage' => 'redis',
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int)env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int)env('REDIS_DATABASE', 0),
        'prefix' => 'batch:',
        'ttl' => 86400,
    ],
],
```

Redis storage provides very fast read and write operations with significantly lower database load compared to SQL. The storage backend includes automatic TTL-based cleanup for expired batches and uses Lua scripts for atomic operations. Redis scales exceptionally well with high batch volumes exceeding 10,000 batches per day, delivers maximum performance for high-throughput scenarios, integrates with existing Redis infrastructure if available, works best when long-term batch history is not required, and can handle eventual consistency in distributed environments.

<a name="job-results"></a>
## Job Results

Jobs can return results that are collected and stored with the batch for later retrieval.

### Returning Results from Jobs

Implement `ResultAwareInterface` to return structured results:

```php
use Crustum\BatchQueue\ResultAwareInterface;

class ProcessOrderJob implements JobInterface, ResultAwareInterface
{
    private mixed $result = null;

    public function execute(Message $message): ?string
    {
        $orderId = $message->getArgument('order_id');

        $orderTotal = $this->processOrder($orderId);

        $this->result = [
            'order_id' => $orderId,
            'total' => $orderTotal,
            'processed_at' => time(),
        ];

        return Processor::ACK;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}
```

The result is automatically stored when the job completes successfully.

### Retrieving All Results

Get results for all jobs in a batch:

```php
$storage = new SqlBatchStorage();
$results = $storage->getBatchResults($batchId);

foreach ($results as $jobId => $result) {
    echo "Job {$jobId}: " . json_encode($result) . "\n";
}
```

### Retrieving Individual Results

Get result for a specific job:

```php
$result = $storage->getJobResult($batchId, $jobId);
```

### Aggregating Results

Process all job results after batch completion:

```php
class BatchCompletionJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $batchId = $message->getArgument('batch_id');
        $storage = new SqlBatchStorage();

        $results = $storage->getBatchResults($batchId);

        $totals = array_sum(array_column($results, 'total'));
        $count = count($results);

        // Store aggregated results
        $this->saveAggregatedResults($totals, $count);

        return Processor::ACK;
    }
}
```

Use batch completion callbacks to trigger result aggregation automatically:

```php
$batchId = $batchManager->batch([...])
    ->onComplete([
        'class' => BatchCompletionJob::class,
        'args' => ['aggregate' => true],
    ])
    ->dispatch();
```
