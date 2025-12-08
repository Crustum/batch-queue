<?php
declare(strict_types=1);

namespace Crustum\BatchQueue\Data\Job;

use Crustum\BatchQueue\Data\BatchDefinition;
use Crustum\BatchQueue\Model\Entity\BatchJob;
use InvalidArgumentException;

/**
 * Job Definition Factory
 *
 * Creates appropriate job definition DTOs from various input formats
 */
class JobDefinitionFactory
{
    /**
     * Create job definition from various input formats
     *
     * @param mixed $input Job input (string, array, BatchJob entity)
     * @param string $batchType Batch type ('parallel' or 'sequential')
     * @return \Crustum\BatchQueue\Data\Job\JobDefinitionInterface Job definition DTO
     * @throws \InvalidArgumentException If input format is invalid
     */
    public static function create(mixed $input, string $batchType = BatchDefinition::TYPE_PARALLEL): JobDefinitionInterface
    {
        if (is_string($input)) {
            return new JobDefinition($input);
        }

        if (is_array($input)) {
            if (count($input) === 2 && !isset($input['class'])) {
                [$jobClass, $compensationClass] = $input;

                return new CompensatedJobDefinition($jobClass, $compensationClass, [], $batchType);
            }

            if (isset($input['class'])) {
                $args = $input['args'] ?? [];

                if (isset($input['compensation'])) {
                    return new CompensatedJobDefinition(
                        $input['class'],
                        $input['compensation'],
                        $args,
                        $batchType,
                    );
                }

                return new JobDefinition($input['class'], $args);
            }

            throw new InvalidArgumentException(
                'Invalid job definition format. Expected string, [job, compensation] array, ' .
                "or array with 'class' key.",
            );
        }

        if ($input instanceof BatchJob) {
            $payload = $input->payload;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException('Invalid payload format in BatchJob entity');
                }
                $payload = $decoded;
            }

            if (!is_array($payload)) {
                throw new InvalidArgumentException('Invalid payload format in BatchJob entity');
            }

            if (isset($payload['compensation'])) {
                return new CompensatedJobDefinition(
                    $payload['class'],
                    $payload['compensation'],
                    $payload['args'] ?? [],
                    $batchType,
                );
            }

            return new JobDefinition(
                $payload['class'],
                $payload['args'] ?? [],
            );
        }

        throw new InvalidArgumentException(
            'Invalid job input type. Expected string, array, or BatchJob entity.',
        );
    }
}
