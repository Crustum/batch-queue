<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Test\Support\TestJobs;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\BatchQueue\Storage\SqlBatchStorage;

/**
 * Test job with compensation tracking
 *
 * Tracks both normal execution and compensation rollback
 */
class CompensationTestJob implements JobInterface
{
    public static array $executedJobs = [];
    public static array $compensatedJobs = [];

    public function execute(Message $message): ?string
    {
        $args = $message->getArgument();

        if (isset($args['_compensation'])) {
            $compensationData = $args['_compensation'];
            $originalBatchId = $compensationData['original_batch_id'] ?? null;
            $action = $args['action'] ?? 'unknown';
            $compensationOrder = $compensationData['compensation_order'] ?? 0;

            self::$compensatedJobs[] = [
                'original_action' => $action,
                'original_job_class' => $compensationData['original_job_class'] ?? null,
                'compensation_order' => $compensationOrder,
                'all_args' => $args,
                'timestamp' => time(),
            ];

            if ($originalBatchId) {
                $storage = new SqlBatchStorage();
                $originalBatch = $storage->getBatch($originalBatchId);
                if ($originalBatch) {
                    $context = $originalBatch->context ?? [];
                    $context['compensations'] = $context['compensations'] ?? [];
                    $context['compensations'][] = [
                        'action' => $action,
                        'original_class' => $compensationData['original_job_class'] ?? null,
                        'compensation_order' => $compensationOrder,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];
                    $storage->updateBatch($originalBatchId, ['context' => $context]);
                }
            }

            return json_encode(['compensated' => true, 'action' => $action, 'order' => $compensationOrder]);
        }

        $action = $args['action'] ?? 'unknown';
        self::$executedJobs[] = [
            'action' => $action,
            'all_args' => $args,
            'timestamp' => time(),
        ];

        return json_encode(['executed' => true, 'action' => $action]);
    }

    public static function reset(): void
    {
        self::$executedJobs = [];
        self::$compensatedJobs = [];
    }
}
