<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\DSL;

use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;

class Parser
{
    /**
     * @param array<string, mixed>|string $definition
     *
     * @return array<string, mixed>
     */
    public function parse(array|string $definition): array
    {
        if (is_string($definition)) {
            $definition = $this->parseStringDefinition($definition);
        }

        if (!isset($definition['dsl_version'])) {
            throw DSLValidationException::withPath('Missing required key: dsl_version', 'dsl_version');
        }

        if (!is_int($definition['dsl_version'])) {
            throw DSLValidationException::withPath('dsl_version must be an integer', 'dsl_version');
        }

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStringDefinition(string $definition): array
    {
        $trimmed = trim($definition);
        if ($trimmed === '') {
            throw DSLValidationException::withPath('Workflow definition string cannot be empty', 'definition');
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw DSLValidationException::withPath('Parsed JSON must produce an object/array structure', 'definition');
            }

            return $decoded;
        }

        if (!class_exists('Symfony\\Component\\Yaml\\Yaml')) {
            throw DSLValidationException::withPath('YAML parsing requires symfony/yaml package', 'definition');
        }

        try {
            /** @var class-string $yamlClass */
            $yamlClass = 'Symfony\\Component\\Yaml\\Yaml';
            $decoded = $yamlClass::parse($trimmed);
        } catch (\Throwable $exception) {
            throw DSLValidationException::withPath('Invalid YAML definition: ' . $exception->getMessage(), 'definition');
        }

        if (!is_array($decoded)) {
            throw DSLValidationException::withPath('Parsed YAML must produce an object/array structure', 'definition');
        }

        return $decoded;
    }
}
