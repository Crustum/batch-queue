<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use RuntimeException;

/**
 * Test job that always fails
 */
class FailingTestJob implements JobInterface
{
    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();
        $errorMessage = $args['error_message'] ?? 'Job failed';

        throw new RuntimeException($errorMessage);
    }
}
