<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class MappingException extends WorkflowException
{
    public static function mapperNotConfigured(): self
    {
        return new self('Transition mappings require a configured DataMapper service', 6101);
    }

    public static function missingBinding(string $target): self
    {
        return new self(
            'Missing mapping binding for target: ' . $target,
            6102,
            null,
            ['target' => $target]
        );
    }

    public static function invalidMappingType(string $type, string $field): self
    {
        return new self(
            'Invalid mapping type "' . $type . '" for field "' . $field . '"',
            6103,
            null,
            ['type' => $type, 'field' => $field]
        );
    }

    public static function missingHandler(string $field): self
    {
        return new self(
            'Custom mapping for field "' . $field . '" requires a handler',
            6104,
            null,
            ['field' => $field]
        );
    }

    public static function invalidHandler(string $className, string $contract): self
    {
        return new self(
            'Mapping handler "' . $className . '" must implement ' . $contract,
            6105,
            null,
            ['handler' => $className, 'contract' => $contract]
        );
    }
}
