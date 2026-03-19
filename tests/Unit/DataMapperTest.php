<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Contracts\MappingHandlerInterface;
use Daiv05\LaravelWorkflowEngine\Contracts\MappingQueryHandlerInterface;
use Daiv05\LaravelWorkflowEngine\DataMapping\DataMapper;
use Daiv05\LaravelWorkflowEngine\Exceptions\MappingException;
use PHPUnit\Framework\TestCase;

class DataMapperTest extends TestCase
{
    public function test_it_maps_and_resolves_attribute_attach_relation_and_custom_fields(): void
    {
        $mapper = new DataMapper([
            'documents' => [
                'handler' => TestDocumentMapper::class,
                'query_handler' => TestDocumentMapper::class,
            ],
        ]);

        $mappings = [
            'comment' => ['type' => 'attribute'],
            'documents' => ['type' => 'relation', 'target' => 'documents'],
            'document_ids' => ['type' => 'attach', 'target' => 'documents'],
            'code' => ['type' => 'custom', 'handler' => TestUppercaseMapper::class, 'query_handler' => TestUppercaseMapper::class],
        ];

        $result = $mapper->map($mappings, [], [
            'comment' => 'ready',
            'documents' => [
                ['id' => 10, 'name' => 'a'],
                ['id' => 11, 'name' => 'b'],
            ],
            'document_ids' => [1, ['id' => 2]],
            'code' => 'ab-123',
        ]);

        $this->assertSame('ready', $result['instance_data']['comment']);
        $this->assertSame([10, 11], $result['instance_data']['documents']);
        $this->assertSame([1, 2], $result['instance_data']['document_ids']);
        $this->assertSame('AB-123', $result['instance_data']['code']);

        $resolved = $mapper->resolve($mappings, $result['instance_data']);

        $this->assertSame('ready', $resolved['comment']);
        $this->assertSame([
            ['id' => 10, 'label' => 'doc-10'],
            ['id' => 11, 'label' => 'doc-11'],
        ], $resolved['documents']);
        $this->assertSame([
            ['id' => 1, 'label' => 'doc-1'],
            ['id' => 2, 'label' => 'doc-2'],
        ], $resolved['document_ids']);
        $this->assertSame('resolved:AB-123', $resolved['code']);
    }

    public function test_it_throws_when_relation_target_has_no_binding(): void
    {
        $this->expectException(MappingException::class);

        $mapper = new DataMapper();

        $mapper->map([
            'documents' => ['type' => 'relation', 'target' => 'documents'],
        ], [], [
            'documents' => [['id' => 1]],
        ]);
    }
}

class TestDocumentMapper implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        if (!is_array($value)) {
            return ['references' => []];
        }

        $references = [];

        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('id', $item)) {
                $references[] = $item['id'];
            }
        }

        return ['references' => $references];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        $references = $context['value'] ?? [];

        if (!is_array($references)) {
            return [];
        }

        $result = [];

        foreach ($references as $id) {
            $result[] = [
                'id' => $id,
                'label' => 'doc-' . $id,
            ];
        }

        return $result;
    }
}

class TestUppercaseMapper implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function handle(mixed $value, array $context): ?array
    {
        return ['value' => strtoupper((string) $value)];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        return 'resolved:' . (string) ($context['value'] ?? '');
    }
}
