# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-17

### Added

- `BatchManager::bulk()` — untracked fan-out enqueue without batch storage, progress, compensation, or callbacks
- Batch lifecycle Cake events: `BatchStarted`, `BatchFinished`, `BatchCanceled` (optional `Throwable` on cancel)
- `BatchBuilder::prepend()` / `append()` to mutate the pending job list before `dispatch()`
- `BatchBuilder::getJobs()` for inspecting the pending job list
- `BatchDefinition::filterFalsyJobs()` — `null` / `false` slots ignored in `batch()` / `chain()` / `addJobs()` / normalize
- Empty / whitespace `batchId` guards on `getBatch`, `addJobs`, and `cancelBatch`
- `BatchDispatcher::queueStandaloneJob()` for Monitor-aware standalone pushes used by `bulk()`
- `BatchBuilder::allowFailures(bool $allow = true)` — parallel batches settle when `completed + failed >= total` instead of failing on the first job error
- `BatchBuilder::onJobFailure(string|array $callback)` — per-job failure callback (job class only)
- `BatchDefinition::allowsFailures()`, `isSettled()`, `isTerminal()` helpers

### Changed

- `queue()` / `queueConfig()` accept `BackedEnum|UnitEnum|string` (resolve via `->value` / `->name`)
- `BatchManager::getProgress()` `progress_percentage` is `int` 0–100 via `(int) round(...)`
- `Batch::getProgressPercentage()` return type changed from `float` to `int`
- Parallel strict mode: first job failure marks batch `failed` and fires batch `on_failure` once (idempotent); sibling jobs may still run on the broker; their rows/counters keep updating
- Parallel `allowFailures`: settle status is `completed` (use `failed_jobs` as the failure signal); fires `BatchFinished`, `on_complete`, and batch `on_failure` once if any job failed
- `executeCallback` accepts string job class names as well as `['class' => ...]` arrays

### Fixed

- Verified `addJobs` remains append-only and preserves batch `queueName` / `queueConfig`

## [1.0.0] - Initial Release

### Added

- Unified `BatchManager` API for parallel batches and sequential chains
- `BatchBuilder` fluent options: context, name, `onComplete` / `onFailure` (job class only — no Closures), retry, timeout, queue routing
- Parallel batches via `BatchJobProcessor` (map-reduce style concurrent jobs)
- Sequential chains via `ChainedJobProcessor` with automatic context accumulation (`ContextAwareInterface`)
- Compensation / saga pairs on sequential jobs with reverse-order undo on failure
- Dynamic `addJobs()` for expanding running batches and chains at runtime
- Progress tracking (`getProgress`, completed/failed counters, batch status)
- SQL and Redis storage backends (`SqlBatchStorage`, `RedisBatchStorage`)
- Queue config resolution for parallel / sequential / named queues (`QueueConfigService`)
- Container / plugin registration for BatchQueue services and processors
- Integration and unit test support fixtures for batch and chain flows
