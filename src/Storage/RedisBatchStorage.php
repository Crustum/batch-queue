<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Storage;

use Cake\I18n\DateTime;
use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Data\BatchJobDefinition;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Redis-based Batch Storage Implementation
 *
 * High-performance batch storage using Redis for fast operations and pub/sub capabilities.
 * Optimized for high-throughput scenarios with atomic operations.
 */
class RedisBatchStorage implements BatchStorageInterface
{
    protected Redis $redis;
    protected string $prefix;
    protected int $ttl;

    /**
     * Constructor
     *
     * @param array<string, mixed> $config Redis configuration:
     *   - 'host' => string (default: '127.0.0.1')
     *   - 'port' => int (default: 6379)
     *   - 'database' => int (default: 0)
     *   - 'password' => string|null (default: null)
     *   - 'persistent' => bool (default: false)
     *   - 'timeout' => float (default: 0.0)
     *   - 'read_timeout' => float (default: 0.0)
     *   - 'prefix' => string (default: 'batch:')
     *   - 'ttl' => int (default: 86400)
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Redis extension is not loaded');
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 6379);
        $database = (int)($config['database'] ?? 0);
        $password = $config['password'] ?? null;
        $persistent = (bool)($config['persistent'] ?? false);
        $timeout = (float)($config['timeout'] ?? 0.0);
        $readTimeout = (float)($config['read_timeout'] ?? 0.0);

        $this->prefix = $config['prefix'] ?? 'batch:';
        $this->ttl = (int)($config['ttl'] ?? 86400);

        $this->redis = new Redis();

        if ($persistent) {
            $this->redis->pconnect($host, $port, $timeout);
        } else {
            $this->redis->connect($host, $port, $timeout);
        }

        if ($readTimeout > 0) {
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
        }

        if ($password !== null) {
            $this->redis->auth($password);
        }

        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    /**
     * @inheritDoc
     */
    public function createBatch(BatchDefinition $batch): string
    {
        $batchKey = $this->getBatchKey($batch->id);
        $jobsKey = $this->getJobsKey($batch->id);

        $pipeline = $this->redis->pipeline();

        $batchData = [
            'type' => $batch->type,
            'status' => $batch->status,
            'total_jobs' => $batch->totalJobs,
            'completed_jobs' => $batch->completedJobs,
            'failed_jobs' => $batch->failedJobs,
            'context' => json_encode($batch->context),
            'options' => json_encode($batch->options),
            'created' => $batch->created?->getTimestamp() ?? time(),
            'modified' => $batch->modified?->getTimestamp() ?? time(),
            'completed_at' => $batch->completedAt?->getTimestamp() ?: 0,
        ];

        $pipeline->hMSet($batchKey, $batchData);
        $pipeline->expire($batchKey, $this->ttl);

        foreach ($batch->jobs as $index => $jobData) {
            $jobId = $jobData['id'] ?? uniqid('job_', true);
            $pipeline->hSet($jobsKey, $jobId, json_encode([
                'position' => $index,
                'payload' => $jobData,
                'status' => 'pending',
            ]));
        }

        $pipeline->expire($jobsKey, $this->ttl);
        $pipeline->exec();

        return $batch->id;
    }

    /**
     * @inheritDoc
     */
    public function updateBatch(string $batchId, array $updates): void
    {
        $batchKey = $this->getBatchKey($batchId);

        if (isset($updates['completed_at'])) {
            if ($updates['completed_at'] instanceof DateTime) {
                $updates['completed_at'] = $updates['completed_at']->getTimestamp();
            } elseif (!is_numeric($updates['completed_at'])) {
                unset($updates['completed_at']);
            }
        }

        if (isset($updates['modified']) && $updates['modified'] instanceof DateTime) {
            $updates['modified'] = $updates['modified']->getTimestamp();
        } elseif (!isset($updates['modified'])) {
            $updates['modified'] = time();
        }

        if (!empty($updates)) {
            $this->redis->hMSet($batchKey, $updates);
        }
    }

    /**
     * @inheritDoc
     */
    public function getBatch(string $batchId): ?BatchDefinition
    {
        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);

        $batchData = $this->redis->hGetAll($batchKey);
        if (empty($batchData)) {
            return null;
        }

        $jobsData = $this->redis->hGetAll($jobsKey);
        $resultsData = $this->redis->hGetAll($this->getResultsKey($batchId));
        $failedData = $this->redis->hGetAll($this->getFailedKey($batchId));
        $jobs = [];

        foreach ($jobsData as $jobId => $jobJson) {
            $jobInfo = json_decode($jobJson, true);
            if (!is_array($jobInfo)) {
                continue;
            }

            $payload = $jobInfo['payload'] ?? [];
            $jobData = $payload;

            $result = null;
            $error = null;

            if ($jobInfo['status'] === 'completed' && isset($resultsData[$jobId])) {
                $resultJson = $resultsData[$jobId];
                $decoded = json_decode($resultJson, true);
                $result = $decoded !== false ? $decoded : $resultJson;
            }

            if ($jobInfo['status'] === 'failed' && isset($failedData[$jobId])) {
                $errorJson = $failedData[$jobId];
                $decoded = json_decode($errorJson, true);
                $error = is_array($decoded) ? $decoded : ['message' => $errorJson];
            }

            $jobData['result'] = $result;
            $jobData['error'] = $error;
            $jobData['status'] = $jobInfo['status'];
            $jobData['batch_id'] = $batchId;
            $jobData['job_id'] = $jobInfo['job_id'] ?? '';
            $jobData['id'] = $jobId;

            if (isset($jobInfo['completed_at'])) {
                $jobData['completed_at'] = date('Y-m-d H:i:s', (int)$jobInfo['completed_at']);
            }

            $jobs[$jobInfo['position']] = $jobData;
        }

        ksort($jobs);

        $batchArray = [
            'id' => $batchId,
            'type' => $batchData['type'],
            'jobs' => array_values($jobs),
            'context' => json_decode($batchData['context'], true) ?: [],
            'options' => json_decode($batchData['options'], true) ?: [],
            'status' => $batchData['status'],
            'total_jobs' => (int)$batchData['total_jobs'],
            'completed_jobs' => (int)$batchData['completed_jobs'],
            'failed_jobs' => (int)$batchData['failed_jobs'],
            'queue_name' => $batchData['queue_name'] ?? null,
            'queue_config' => $batchData['queue_config'] ?? null,
        ];

        if (isset($batchData['created'])) {
            $batchArray['created'] = is_numeric($batchData['created'])
                ? date('Y-m-d H:i:s', (int)$batchData['created'])
                : $batchData['created'];
        }

        if (isset($batchData['modified'])) {
            $batchArray['modified'] = is_numeric($batchData['modified'])
                ? date('Y-m-d H:i:s', (int)$batchData['modified'])
                : $batchData['modified'];
        }

        if (isset($batchData['completed_at'])) {
            $completedAt = $batchData['completed_at'];
            if (is_numeric($completedAt)) {
                $timestamp = (int)$completedAt;
                if ($timestamp > 0) {
                    $batchArray['completed_at'] = date('Y-m-d H:i:s', $timestamp);
                }
            } elseif ($completedAt instanceof DateTime) {
                $batchArray['completed_at'] = $completedAt->format('Y-m-d H:i:s');
            } elseif (is_string($completedAt) && $completedAt !== 'Object' && $completedAt !== '0') {
                $batchArray['completed_at'] = $completedAt;
            }
        }

        return BatchDefinition::fromArray($batchArray);
    }

    /**
     * @inheritDoc
     */
    public function markJobComplete(string $batchId, string $jobId, mixed $result): bool
    {
        $script = <<<LUA
local batch_key = KEYS[1]
local jobs_key = KEYS[2]
local results_key = KEYS[3]
local job_id = ARGV[1]
local result = ARGV[2]

-- Update job status
local job_data = redis.call('HGET', jobs_key, job_id)
if job_data then
    local job_info = cjson.decode(job_data)
    job_info.status = 'completed'
    job_info.completed_at = redis.call('TIME')[1]
    redis.call('HSET', jobs_key, job_id, cjson.encode(job_info))
end

-- Store result
redis.call('HSET', results_key, job_id, result)

-- Increment completed counter and check completion
local completed = redis.call('HINCRBY', batch_key, 'completed_jobs', 1)
local total = redis.call('HGET', batch_key, 'total_jobs')

if tonumber(completed) >= tonumber(total) then
    redis.call('HSET', batch_key, 'status', 'completed')
    redis.call('HSET', batch_key, 'completed_at', redis.call('TIME')[1])
    redis.call('PUBLISH', 'batch_completed', batch_key)
    return 1
end

return 0
LUA;

        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);
        $resultsKey = $this->getResultsKey($batchId);

        $isComplete = $this->redis->eval(
            $script,
            [$batchKey, $jobsKey, $resultsKey, $jobId, json_encode($result)],
            3,
        );

        return (bool)$isComplete;
    }

    /**
     * @inheritDoc
     */
    public function markJobCompleteById(string $batchId, string $dbJobId, mixed $result): bool
    {
        return $this->markJobComplete($batchId, $dbJobId, $result);
    }

    /**
     * @inheritDoc
     */
    public function markJobFailed(string $batchId, string $jobId, Throwable $error): bool
    {
        $script = <<<LUA
local batch_key = KEYS[1]
local jobs_key = KEYS[2]
local failed_key = KEYS[3]
local job_id = ARGV[1]
local error_data = ARGV[2]
local fail_on_first = ARGV[3]

-- Update job status
local job_data = redis.call('HGET', jobs_key, job_id)
if job_data then
    local job_info = cjson.decode(job_data)
    job_info.status = 'failed'
    job_info.completed_at = redis.call('TIME')[1]
    redis.call('HSET', jobs_key, job_id, cjson.encode(job_info))
end

-- Store error
redis.call('HSET', failed_key, job_id, error_data)

-- Increment failed counter
redis.call('HINCRBY', batch_key, 'failed_jobs', 1)

-- Check if batch should fail
if fail_on_first == '1' then
    redis.call('HSET', batch_key, 'status', 'failed')
    redis.call('HSET', batch_key, 'completed_at', redis.call('TIME')[1])
    redis.call('PUBLISH', 'batch_failed', batch_key)
    return 1
end

return 0
LUA;

        $batch = $this->getBatch($batchId);
        $failOnFirst = $batch && isset($batch->options['fail_on_first_error']) && $batch->options['fail_on_first_error'] ? '1' : '0';

        $errorData = json_encode([
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
        ]);

        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);
        $failedKey = $this->getFailedKey($batchId);

        $batchFailed = $this->redis->eval(
            $script,
            [$batchKey, $jobsKey, $failedKey, $jobId, $errorData, $failOnFirst],
            3,
        );

        return (bool)$batchFailed;
    }

    /**
     * @inheritDoc
     */
    public function markJobFailedById(string $batchId, string $dbJobId, Throwable $error): bool
    {
        return $this->markJobFailed($batchId, $dbJobId, $error);
    }

    /**
     * @inheritDoc
     */
    public function isBatchComplete(string $batchId): bool
    {
        $batchKey = $this->getBatchKey($batchId);
        $batchData = $this->redis->hMGet($batchKey, ['status', 'total_jobs', 'completed_jobs', 'failed_jobs']);

        if (empty($batchData['status'])) {
            return false;
        }

        return $batchData['status'] === 'completed' ||
               ((int)$batchData['completed_jobs'] + (int)$batchData['failed_jobs']) >= (int)$batchData['total_jobs'];
    }

    /**
     * @inheritDoc
     */
    public function getBatchProgress(string $batchId): array
    {
        $batchKey = $this->getBatchKey($batchId);
        $progress = $this->redis->hMGet($batchKey, ['total_jobs', 'completed_jobs', 'failed_jobs']);

        if (empty($progress['total_jobs'])) {
            return [];
        }

        $total = (int)$progress['total_jobs'];
        $completed = (int)$progress['completed_jobs'];
        $failed = (int)$progress['failed_jobs'];

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $total - $completed - $failed,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getBatchResults(string $batchId): array
    {
        $resultsKey = $this->getResultsKey($batchId);
        $results = $this->redis->hGetAll($resultsKey);

        return array_map(fn($result) => json_decode($result, true), $results);
    }

    /**
     * @inheritDoc
     */
    public function getJobResult(string $batchId, string $jobId): mixed
    {
        $resultsKey = $this->getResultsKey($batchId);
        $result = $this->redis->hGet($resultsKey, $jobId);

        return $result ? json_decode($result, true) : null;
    }

    /**
     * @inheritDoc
     */
    public function storeJobResult(string $batchId, string $jobId, mixed $result): void
    {
        $resultsKey = $this->getResultsKey($batchId);
        $this->redis->hSet($resultsKey, $jobId, json_encode($result));
        $this->redis->expire($resultsKey, $this->ttl);
    }

    /**
     * @inheritDoc
     */
    public function getFailedJobs(string $batchId): array
    {
        $failedKey = $this->getFailedKey($batchId);

        $failedJobs = [];
        $errors = $this->redis->hGetAll($failedKey);

        foreach ($errors as $jobId => $errorJson) {
            $jobDefinition = $this->getJobById($batchId, $jobId);
            if ($jobDefinition) {
                $failedJobs[$jobDefinition->jobId] = $jobDefinition;
            }
        }

        return $failedJobs;
    }

    /**
     * @inheritDoc
     */
    public function deleteBatch(string $batchId): void
    {
        $keys = [
            $this->getBatchKey($batchId),
            $this->getJobsKey($batchId),
            $this->getResultsKey($batchId),
            $this->getFailedKey($batchId),
        ];

        $this->redis->del($keys);
    }

    /**
     * @inheritDoc
     */
    public function getBatchesByStatus(string $status, int $limit = 100, int $offset = 0): array
    {
        return $this->getBatches(['status' => $status], $limit, $offset);
    }

    /**
     * @inheritDoc
     */
    public function getAllJobs(string $batchId, array $options = []): array
    {
        $statusFilter = $options['status'] ?? null;
        $limit = $options['limit'] ?? null;
        $offset = $options['offset'] ?? 0;
        $orderBy = $options['order_by'] ?? 'position';

        $jobsKey = $this->getJobsKey($batchId);
        $resultsKey = $this->getResultsKey($batchId);
        $failedKey = $this->getFailedKey($batchId);

        $jobsData = $this->redis->hGetAll($jobsKey);
        $resultsData = $this->redis->hGetAll($resultsKey);
        $failedData = $this->redis->hGetAll($failedKey);

        $jobs = [];

        foreach ($jobsData as $jobId => $jobJson) {
            $jobInfo = json_decode($jobJson, true);
            if (!is_array($jobInfo)) {
                continue;
            }

            if ($statusFilter !== null && ($jobInfo['status'] ?? 'pending') !== $statusFilter) {
                continue;
            }

            $payload = $jobInfo['payload'] ?? [];
            $jobStatus = $jobInfo['status'] ?? 'pending';

            $result = null;
            $error = null;
            $completedAt = null;

            if ($jobStatus === 'completed' && isset($resultsData[$jobId])) {
                $resultJson = $resultsData[$jobId];
                $decoded = json_decode($resultJson, true);
                $result = $decoded !== false ? $decoded : $resultJson;
            }

            if ($jobStatus === 'failed' && isset($failedData[$jobId])) {
                $errorJson = $failedData[$jobId];
                $decoded = json_decode($errorJson, true);
                $error = is_array($decoded) ? $decoded : ['message' => $errorJson];
            }

            $completedAt = null;
            if (isset($jobInfo['completed_at'])) {
                $completedAt = DateTime::createFromTimestamp((int)$jobInfo['completed_at']);
            }

            $created = null;
            if (isset($jobInfo['created'])) {
                $created = DateTime::createFromTimestamp((int)$jobInfo['created']);
            }

            $modified = null;
            if (isset($jobInfo['modified'])) {
                $modified = DateTime::createFromTimestamp((int)$jobInfo['modified']);
            }

            $definition = new BatchJobDefinition(
                id: $jobId,
                batchId: $batchId,
                jobId: $jobInfo['job_id'] ?? $jobId,
                position: (int)($jobInfo['position'] ?? 0),
                status: $jobStatus,
                payload: $payload,
                result: $result,
                error: $error,
                created: $created ?: null,
                modified: $modified ?: null,
                completedAt: $completedAt ?: null,
            );

            $jobs[$definition->position] = $definition;
        }

        if ($orderBy === 'position') {
            ksort($jobs);
        } elseif ($orderBy === 'created') {
            uasort($jobs, function ($a, $b) {
                $aTime = $a->created?->getTimestamp() ?? 0;
                $bTime = $b->created?->getTimestamp() ?? 0;

                return $aTime <=> $bTime;
            });
        }

        if ($limit !== null) {
            $jobs = array_slice($jobs, $offset, $limit, true);
        } elseif ($offset > 0) {
            $jobs = array_slice($jobs, $offset, null, true);
        }

        return $jobs;
    }

    /**
     * @inheritDoc
     */
    public function getBatches(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $pattern = $this->prefix . '*';
        $batches = [];
        $iterator = null;

        while (($keys = $this->redis->scan($iterator, $pattern, 100)) !== false) {
            foreach ($keys as $key) {
                if (strpos($key, ':jobs:') !== false || strpos($key, ':results:') !== false || strpos($key, ':failed:') !== false) {
                    continue;
                }

                $batchId = str_replace($this->prefix, '', $key);
                $batchData = $this->redis->hMGet($key, ['status', 'type', 'context', 'created']);

                if (empty($batchData['status'])) {
                    continue;
                }

                $batchStatus = $batchData['status'] ?? '';
                if (isset($filters['status']) && is_string($filters['status']) && $batchStatus !== $filters['status']) {
                    continue;
                }

                if (isset($filters['type']) && is_string($filters['type']) && ($batchData['type'] ?? '') !== $filters['type']) {
                    continue;
                }

                if (isset($filters['created_after']) && $filters['created_after'] instanceof DateTime) {
                    $createdAt = isset($batchData['created']) ? (int)$batchData['created'] : 0;
                    if ($createdAt < $filters['created_after']->getTimestamp()) {
                        continue;
                    }
                }

                if (isset($filters['created_before']) && $filters['created_before'] instanceof DateTime) {
                    $createdAt = isset($batchData['created']) ? (int)$batchData['created'] : 0;
                    if ($createdAt > $filters['created_before']->getTimestamp()) {
                        continue;
                    }
                }

                $batch = $this->getBatch($batchId);
                if (!$batch) {
                    continue;
                }

                if (isset($filters['has_compensation']) && $filters['has_compensation'] === true) {
                    if (!$batch->hasCompensation()) {
                        continue;
                    }
                }

                $batches[] = $batch;

                if (count($batches) >= $limit + $offset) {
                    break 2;
                }
            }
        }

        usort($batches, function ($a, $b) {
            $aTime = $a->created?->getTimestamp() ?? 0;
            $bTime = $b->created?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        return array_slice($batches, $offset, $limit);
    }

    /**
     * @inheritDoc
     */
    public function countBatches(array $filters = []): int
    {
        $pattern = $this->prefix . '*';
        $count = 0;
        $iterator = null;

        while (($keys = $this->redis->scan($iterator, $pattern, 100)) !== false) {
            foreach ($keys as $key) {
                if (strpos($key, ':jobs:') !== false || strpos($key, ':results:') !== false || strpos($key, ':failed:') !== false) {
                    continue;
                }

                $batchId = str_replace($this->prefix, '', $key);
                $batchData = $this->redis->hMGet($key, ['status', 'type', 'context', 'created']);

                if (empty($batchData['status'])) {
                    continue;
                }

                $batchStatus = $batchData['status'] ?? '';
                if (isset($filters['status']) && is_string($filters['status']) && $batchStatus !== $filters['status']) {
                    continue;
                }

                if (isset($filters['type']) && is_string($filters['type']) && ($batchData['type'] ?? '') !== $filters['type']) {
                    continue;
                }

                if (isset($filters['created_after']) && $filters['created_after'] instanceof DateTime) {
                    $createdAt = isset($batchData['created']) ? (int)$batchData['created'] : 0;
                    if ($createdAt < $filters['created_after']->getTimestamp()) {
                        continue;
                    }
                }

                if (isset($filters['created_before']) && $filters['created_before'] instanceof DateTime) {
                    $createdAt = isset($batchData['created']) ? (int)$batchData['created'] : 0;
                    if ($createdAt > $filters['created_before']->getTimestamp()) {
                        continue;
                    }
                }

                if (isset($filters['has_compensation']) && $filters['has_compensation'] === true) {
                    $batch = $this->getBatch($batchId);
                    if (!$batch || !$batch->hasCompensation()) {
                        continue;
                    }
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function cleanupOldBatches(int $olderThanDays = 7): int
    {
        $cutoffTimestamp = (new DateTime())->modify("-{$olderThanDays} days")->getTimestamp();
        $pattern = $this->prefix . '*';
        $deleted = 0;
        $iterator = null;

        while (($keys = $this->redis->scan($iterator, $pattern, 100)) !== false) {
            foreach ($keys as $key) {
                if (strpos($key, ':jobs:') !== false || strpos($key, ':results:') !== false || strpos($key, ':failed:') !== false) {
                    continue;
                }

                $batchData = $this->redis->hMGet($key, ['status', 'created']);
                $status = $batchData['status'];
                $createdAt = (int)$batchData['created'];

                if (in_array($status, ['completed', 'failed']) && $createdAt < $cutoffTimestamp) {
                    $batchId = str_replace($this->prefix, '', $key);
                    $this->deleteBatch($batchId);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * @inheritDoc
     */
    public function getStorageType(): string
    {
        return 'redis';
    }

    /**
     * @inheritDoc
     */
    public function healthCheck(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get batch metadata key
     *
     * @param string $batchId Batch ID
     * @return string
     */
    protected function getBatchKey(string $batchId): string
    {
        return $this->prefix . $batchId;
    }

    /**
     * Get batch jobs key
     *
     * @param string $batchId Batch ID
     * @return string
     */
    protected function getJobsKey(string $batchId): string
    {
        return $this->prefix . $batchId . ':jobs';
    }

    /**
     * Get batch results key
     *
     * @param string $batchId Batch ID
     * @return string
     */
    protected function getResultsKey(string $batchId): string
    {
        return $this->prefix . $batchId . ':results';
    }

    /**
     * Get batch failed jobs key
     *
     * @param string $batchId Batch ID
     * @return string
     */
    protected function getFailedKey(string $batchId): string
    {
        return $this->prefix . $batchId . ':failed';
    }

    /**
     * @inheritDoc
     */
    public function incrementCompletedJob(string $batchId, string $jobId): int
    {
        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);

        $jobsData = $this->redis->hGetAll($jobsKey);
        $completedCount = 0;

        foreach ($jobsData as $jobJson) {
            $jobInfo = json_decode($jobJson, true);
            if (is_array($jobInfo) && ($jobInfo['status'] ?? '') === 'completed') {
                $completedCount++;
            }
        }

        $this->redis->hSet($batchKey, 'completed_jobs', $completedCount);
        $this->redis->expire($batchKey, $this->ttl);

        return $completedCount;
    }

    /**
     * @inheritDoc
     */
    public function incrementFailedJob(string $batchId, string $jobId): int
    {
        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);

        $jobsData = $this->redis->hGetAll($jobsKey);
        $failedCount = 0;

        foreach ($jobsData as $jobJson) {
            $jobInfo = json_decode($jobJson, true);
            if (is_array($jobInfo) && ($jobInfo['status'] ?? '') === 'failed') {
                $failedCount++;
            }
        }

        $this->redis->hSet($batchKey, 'failed_jobs', $failedCount);
        $this->redis->expire($batchKey, $this->ttl);

        return $failedCount;
    }

    /**
     * @inheritDoc
     */
    public function createOrUpdateJob(string $batchId, string $jobId, array $jobData): void
    {
        $jobsKey = $this->getJobsKey($batchId);

        $jobDataJson = json_encode([
            'position' => $jobData['position'] ?? 0,
            'payload' => $jobData['payload'] ?? [],
            'status' => $jobData['status'] ?? BatchJobDefinition::STATUS_PENDING,
            'created' => isset($jobData['created']) ? (new DateTime($jobData['created']))->getTimestamp() : time(),
            'modified' => time(),
        ]);

        $this->redis->hSet($jobsKey, $jobId, $jobDataJson);
        $this->redis->expire($jobsKey, $this->ttl);
    }

    /**
     * @inheritDoc
     */
    public function getJobById(string $batchId, string $jobId): ?BatchJobDefinition
    {
        $jobsKey = $this->getJobsKey($batchId);
        $jobDataJson = $this->redis->hGet($jobsKey, $jobId);

        if (!$jobDataJson) {
            return null;
        }

        $data = json_decode($jobDataJson, true);
        if (!is_array($data)) {
            return null;
        }

        $result = null;
        $error = null;
        $completedAt = null;

        if ($data['status'] === BatchJobDefinition::STATUS_COMPLETED) {
            $resultsKey = $this->getResultsKey($batchId);
            $resultJson = $this->redis->hGet($resultsKey, $jobId);
            if ($resultJson) {
                $decoded = json_decode($resultJson, true);
                $result = $decoded !== false ? $decoded : $resultJson;
            }
            $completedAt = null;
            if (isset($data['completed_at'])) {
                $completedAt = DateTime::createFromTimestamp((int)$data['completed_at']);
            }
        } elseif ($data['status'] === BatchJobDefinition::STATUS_FAILED) {
            $failedKey = $this->getFailedKey($batchId);
            $errorJson = $this->redis->hGet($failedKey, $jobId);
            if ($errorJson) {
                $decoded = json_decode($errorJson, true);
                $error = is_array($decoded) ? $decoded : ['message' => $errorJson];
            }
            $completedAt = null;
            if (isset($data['completed_at'])) {
                $completedAt = DateTime::createFromTimestamp((int)$data['completed_at']);
            }
        }

        $created = null;
        if (isset($data['created'])) {
            $created = DateTime::createFromTimestamp((int)$data['created']);
        }

        $modified = null;
        if (isset($data['modified'])) {
            $modified = DateTime::createFromTimestamp((int)$data['modified']);
        }

        return new BatchJobDefinition(
            id: $jobId,
            batchId: $batchId,
            jobId: $jobId,
            position: (int)$data['position'],
            status: $data['status'],
            payload: $data['payload'] ?? [],
            result: $result,
            error: $error,
            created: $created ?: null,
            modified: $modified ?: null,
            completedAt: $completedAt ?: null,
        );
    }

    /**
     * @inheritDoc
     */
    public function updateJobStatus(string $batchId, string $jobId, string $status, mixed $result = null, mixed $error = null): void
    {
        $jobsKey = $this->getJobsKey($batchId);
        $jobDataJson = $this->redis->hGet($jobsKey, $jobId);

        if (!$jobDataJson) {
            throw new RuntimeException("Job not found: {$batchId}:{$jobId}");
        }

        $jobData = json_decode($jobDataJson, true);
        if (!is_array($jobData)) {
            throw new RuntimeException("Invalid job data format: {$batchId}:{$jobId}");
        }

        $jobData['status'] = $status;
        $jobData['modified'] = time();

        if ($result !== null) {
            $jobData['result'] = $result;
        }

        if ($error !== null) {
            $jobData['error'] = is_array($error) ? $error : (is_string($error) ? ['message' => $error] : ['error' => $error]);
        }

        if ($status === BatchJobDefinition::STATUS_COMPLETED) {
            $jobData['completed_at'] = time();
            if ($result !== null) {
                $resultsKey = $this->getResultsKey($batchId);
                $this->redis->hSet($resultsKey, $jobId, json_encode($result));
                $this->redis->expire($resultsKey, $this->ttl);
            }
        } elseif ($status === BatchJobDefinition::STATUS_FAILED) {
            $jobData['completed_at'] = time();
            if ($error !== null) {
                $failedKey = $this->getFailedKey($batchId);
                $errorData = is_string($error) ? ['message' => $error] : (is_array($error) ? $error : ['error' => $error]);
                $this->redis->hSet($failedKey, $jobId, json_encode($errorData));
                $this->redis->expire($failedKey, $this->ttl);
            }
        }

        $this->redis->hSet($jobsKey, $jobId, json_encode($jobData));
    }

    /**
     * @inheritDoc
     */
    public function getJobByPosition(string $batchId, int $position): ?BatchJobDefinition
    {
        $jobsKey = $this->getJobsKey($batchId);
        $allJobs = $this->redis->hGetAll($jobsKey);

        foreach ($allJobs as $jobId => $jobDataJson) {
            $jobData = json_decode($jobDataJson, true);
            if (is_array($jobData) && ($jobData['position'] ?? -1) === $position) {
                return $this->getJobById($batchId, $jobId);
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function updateJobId(string $batchId, int $position, string $jobId): void
    {
        $jobsKey = $this->getJobsKey($batchId);
        $allJobs = $this->redis->hGetAll($jobsKey);

        foreach ($allJobs as $oldJobId => $jobDataJson) {
            $jobData = json_decode($jobDataJson, true);
            if (is_array($jobData) && ($jobData['position'] ?? -1) === $position) {
                $jobData['job_id'] = $jobId;
                $updatedJson = json_encode($jobData);

                if ($oldJobId !== $jobId) {
                    $this->redis->hDel($jobsKey, $oldJobId);
                }
                $this->redis->hSet($jobsKey, $jobId, $updatedJson);
                break;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function addJobsToBatch(string $batchId, array $jobs): int
    {
        $batchKey = $this->getBatchKey($batchId);
        $jobsKey = $this->getJobsKey($batchId);

        if (!$this->redis->exists($batchKey)) {
            throw new RuntimeException(__('Batch not found: {0}', $batchId));
        }

        $batchData = $this->redis->hGetAll($batchKey);
        $status = $batchData['status'] ?? '';

        if (in_array($status, ['completed', 'failed'])) {
            throw new RuntimeException(__('Cannot add jobs to {0} batch: {1}', $status, $batchId));
        }

        $currentTotalJobs = (int)($batchData['total_jobs'] ?? 0);

        $pipeline = $this->redis->pipeline();

        foreach ($jobs as $index => $jobData) {
            $position = $currentTotalJobs + $index;
            $jobId = $jobData['id'] ?? uniqid('job_', true);
            $pipeline->hSet($jobsKey, $jobId, json_encode([
                'position' => $position,
                'payload' => $jobData,
                'status' => 'pending',
            ]));
        }

        $newTotalJobs = $currentTotalJobs + count($jobs);
        $pipeline->hSet($batchKey, 'total_jobs', $newTotalJobs);
        $pipeline->hSet($batchKey, 'modified', time());

        $pipeline->exec();

        return count($jobs);
    }
}
