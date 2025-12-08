# CakePHP BatchQueue Plugin

The **BatchQueue** plugin provides a unified system for managing batch job processing in CakePHP applications. It supports both parallel execution (running the same job with different arguments simultaneously) and sequential chains (jobs run one after another with context accumulation). The plugin includes built-in support for compensation patterns, allowing you to define rollback operations that execute automatically when jobs fail.

The primary use case for parallel batches is the map-reduce pattern: running the same job class with different arguments to process multiple items concurrently.

For sequential chains, the plugin automatically accumulates context between jobs, allowing each step to build upon previous results. BatchQueue integrates seamlessly with the CakePHP Queue plugin.

The plugin includes support for job-specific arguments in parallel batches, automatic context accumulation in sequential chains, compensation job execution on failures, batch progress tracking, flexible storage backends (SQL or Redis).

## Requirements

* PHP 8.2+

See [Versions.md](docs/Versions.md) for the supported CakePHP versions.

## Documentation

For documentation, as well as tutorials, see the [docs](docs/index.md) directory of this repository.

## License

Licensed under the [MIT](http://www.opensource.org/licenses/mit-license.php) License. Redistributions of the source code included in this repository must retain the copyright notice found in each file.

