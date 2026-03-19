<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Contracts;

interface DataMapperInterface
{
    /**
     * @param array<string, mixed> $mappings
     * @param array<string, mixed> $instanceData
     * @param array<string, mixed> $inputData
     * @param array<string, mixed> $context
     *
     * @return array{instance_data: array<string, mixed>, summary: array<string, mixed>}
     */
    public function map(array $mappings, array $instanceData, array $inputData, array $context = []): array;

    /**
     * @param array<string, mixed> $mappings
     * @param array<string, mixed> $instanceData
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function resolve(array $mappings, array $instanceData, array $context = [], array $options = []): array;
}
