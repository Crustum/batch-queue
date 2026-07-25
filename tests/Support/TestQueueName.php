<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support;

/**
 * Backed enum used in BatchBuilder queue() tests
 */
enum TestQueueName: string
{
    case Mail = 'mail-queue';
}
