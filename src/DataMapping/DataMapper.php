<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\DataMapping;

use Daiv05\LaravelWorkflowEngine\Contracts\DataMapperInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;

class DataMapper implements DataMapperInterface
{
    /**
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        private readonly array $bindings = [],
        private readonly bool $failSilently = false
    ) {
    }

    public function map(array $mappings, array $instanceData, array $inputData, array $context = []): array
    {
        $summary = [];

        foreach ($mappings as $field => $config) {
            if (!is_string($field) || !is_array($config)) {
                continue;
            }

            if (!array_key_exists($field, $inputData)) {
                continue;
            }

            $type = $this->mappingType($config);
            $value = $inputData[$field];

            try {
                if ($type === 'attribute') {
                    $instanceData[$field] = $value;
                    $summary[$field] = [
                        'type' => $type,
                        'status' => 'stored',
                    ];
                    continue;
                }

                if ($type === 'attach') {
                    $references = $this->normalizeReferences($value);
                    $instanceData[$field] = $references;
                    $summary[$field] = [
                        'type' => $type,
                        'status' => 'attached',
                        'references_count' => count($references),
                    ];
                    continue;
                }

                if ($type === 'relation') {
                    $target = $this->requiredTarget($config, $field);
                    $handler = $this->resolveBindingWriter($target);

                    $result = $handler->handle($value, $this->handlerContext($context, $field, $config, $value));

                    $references = $result['references'] ?? null;
                    if (is_array($references)) {
                        $instanceData[$field] = $references;
                    }

                    $summary[$field] = [
                        'type' => $type,
                        'status' => 'persisted',
                        'target' => $target,
                    ];
                    continue;
                }

                if ($type === 'custom') {
                    $handler = $this->resolveCustomWriter($config, $field);
                    $result = $handler->handle($value, $this->handlerContext($context, $field, $config, $value));

                    if (is_array($result) && array_key_exists('value', $result)) {
                        $instanceData[$field] = $result['value'];
                    }

                    $summary[$field] = [
                        'type' => $type,
                        'status' => 'handled',
                        'handler' => (string) $config['handler'],
                    ];
                    continue;
                }

                throw MappingException::invalidMappingType($type, $field);
            } catch (\Throwable $exception) {
                if ($this->failSilently) {
                    $summary[$field] = [
                        'type' => $type,
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                    ];
                    continue;
                }

                throw $exception;
            }
        }

        return [
            'instance_data' => $instanceData,
            'summary' => $summary,
        ];
    }

    public function resolve(array $mappings, array $instanceData, array $context = [], array $options = []): array
    {
        $resolved = [];

        foreach ($mappings as $field => $config) {
            if (!is_string($field) || !is_array($config)) {
                continue;
            }

            $type = $this->mappingType($config);
            $storedValue = $instanceData[$field] ?? null;

            if ($type === 'attribute') {
                $resolved[$field] = $storedValue;
                continue;
            }

            if ($type === 'attach') {
                $target = isset($config['target']) && is_string($config['target']) ? $config['target'] : null;
                if ($target === null) {
                    $resolved[$field] = $storedValue;
                    continue;
                }

                $queryHandler = $this->resolveBindingReader($target, false);
                if ($queryHandler === null) {
                    $resolved[$field] = $storedValue;
                    continue;
                }

                $resolved[$field] = $queryHandler->fetch(
                    $this->queryContext($context, $field, $config, $storedValue),
                    $options
                );
                continue;
            }

            if ($type === 'relation') {
                $target = $this->requiredTarget($config, $field);
                $queryHandler = $this->resolveBindingReader($target);

                if ($queryHandler === null) {
                    $resolved[$field] = $storedValue;
                    continue;
                }

                $resolved[$field] = $queryHandler->fetch(
                    $this->queryContext($context, $field, $config, $storedValue),
                    $options
                );
                continue;
            }

            if ($type === 'custom') {
                $queryHandler = $this->resolveCustomReader($config, $field);
                if ($queryHandler === null) {
                    $resolved[$field] = $storedValue;
                    continue;
                }

                $resolved[$field] = $queryHandler->fetch(
                    $this->queryContext($context, $field, $config, $storedValue),
                    $options
                );
                continue;
            }

            throw MappingException::invalidMappingType($type, $field);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mappingType(array $config): string
    {
        return isset($config['type']) && is_string($config['type'])
            ? $config['type']
            : 'attribute';
    }

    private function normalizeReferences(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $references = [];

        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('id', $item)) {
                $references[] = $item['id'];
                continue;
            }

            $references[] = $item;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiredTarget(array $config, string $field): string
    {
        if (isset($config['target']) && is_string($config['target']) && $config['target'] !== '') {
            return $config['target'];
        }

        throw MappingException::missingBinding($field);
    }

    private function resolveBindingWriter(string $target): MappingHandlerInterface
    {
        $binding = $this->bindings[$target] ?? null;

        if (!is_array($binding)) {
            throw MappingException::missingBinding($target);
        }

        $handlerClass = $binding['handler'] ?? null;
        if (!is_string($handlerClass) || $handlerClass === '') {
            throw MappingException::missingBinding($target);
        }

        return $this->instantiateWriter($handlerClass);
    }

    private function resolveCustomWriter(array $config, string $field): MappingHandlerInterface
    {
        $handlerClass = $config['handler'] ?? null;

        if (!is_string($handlerClass) || $handlerClass === '') {
            throw MappingException::missingHandler($field);
        }

        return $this->instantiateWriter($handlerClass);
    }

    private function resolveBindingReader(string $target, bool $required = true): ?MappingQueryHandlerInterface
    {
        $binding = $this->bindings[$target] ?? null;

        if (!is_array($binding)) {
            if ($required) {
                throw MappingException::missingBinding($target);
            }

            return null;
        }

        $queryClass = $binding['query_handler'] ?? $binding['handler'] ?? null;
        if (!is_string($queryClass) || $queryClass === '') {
            return null;
        }

        return $this->instantiateReader($queryClass);
    }

    private function resolveCustomReader(array $config, string $field): ?MappingQueryHandlerInterface
    {
        $handlerClass = $config['query_handler'] ?? $config['handler'] ?? null;

        if (!is_string($handlerClass) || $handlerClass === '') {
            throw MappingException::missingHandler($field);
        }

        return $this->instantiateReader($handlerClass);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function handlerContext(array $context, string $field, array $config, mixed $value): array
    {
        return [
            'field' => $field,
            'mapping' => $config,
            'value' => $value,
            'instance' => $context['instance'] ?? null,
            'transition' => $context['transition'] ?? null,
            'definition' => $context['definition'] ?? null,
            'runtime_context' => $context['runtime_context'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function queryContext(array $context, string $field, array $config, mixed $value): array
    {
        return [
            'field' => $field,
            'mapping' => $config,
            'value' => $value,
            'instance' => $context['instance'] ?? null,
            'transition' => $context['transition'] ?? null,
            'definition' => $context['definition'] ?? null,
            'runtime_context' => $context['runtime_context'] ?? [],
        ];
    }

    private function instantiateWriter(string $handlerClass): MappingHandlerInterface
    {
        if (!class_exists($handlerClass)) {
            throw MappingException::invalidHandler($handlerClass, MappingHandlerInterface::class);
        }

        $handler = new $handlerClass();

        if (!$handler instanceof MappingHandlerInterface) {
            throw MappingException::invalidHandler($handlerClass, MappingHandlerInterface::class);
        }

        return $handler;
    }

    private function instantiateReader(string $handlerClass): ?MappingQueryHandlerInterface
    {
        if (!class_exists($handlerClass)) {
            throw MappingException::invalidHandler($handlerClass, MappingQueryHandlerInterface::class);
        }

        $handler = new $handlerClass();

        if ($handler instanceof MappingQueryHandlerInterface) {
            return $handler;
        }

        if ($handler instanceof MappingHandlerInterface) {
            return null;
        }

        throw MappingException::invalidHandler($handlerClass, MappingQueryHandlerInterface::class);
    }
}
