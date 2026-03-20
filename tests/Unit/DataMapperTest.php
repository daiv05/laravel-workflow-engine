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
            'documents' => ['type' => 'relation', 'target' => 'documents', 'mode' => 'create_many'],
            'document_refs' => ['type' => 'relation', 'target' => 'documents', 'mode' => 'reference_only'],
            'document_ids' => ['type' => 'attach', 'target' => 'documents'],
            'code' => ['type' => 'custom', 'handler' => TestUppercaseMapper::class, 'query_handler' => TestUppercaseMapper::class],
        ];

        $result = $mapper->map($mappings, [], [
            'comment' => 'ready',
            'documents' => [
                ['id' => 10, 'name' => 'a'],
                ['id' => 11, 'name' => 'b'],
            ],
            'document_refs' => [10, ['id' => 11]],
            'document_ids' => [1, ['id' => 2]],
            'code' => 'ab-123',
        ]);

        $this->assertSame('ready', $result['instance_data']['comment']);
        $this->assertSame([10, 11], $result['instance_data']['documents']);
        $this->assertSame([10, 11], $result['instance_data']['document_refs']);
        $this->assertSame([1, 2], $result['instance_data']['document_ids']);
        $this->assertSame('AB-123', $result['instance_data']['code']);
        $this->assertSame('create_many', $result['summary']['documents']['mode']);
        $this->assertSame('reference_only', $result['summary']['document_refs']['mode']);
        $this->assertSame('attached', $result['summary']['document_refs']['status']);

        $resolved = $mapper->resolve($mappings, $result['instance_data']);

        $this->assertSame('ready', $resolved['comment']);
        $this->assertSame([
            ['id' => 10, 'label' => 'doc-10'],
            ['id' => 11, 'label' => 'doc-11'],
        ], $resolved['documents']);
        $this->assertSame([
            ['id' => 10, 'label' => 'doc-10'],
            ['id' => 11, 'label' => 'doc-11'],
        ], $resolved['document_refs']);
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

    public function test_it_resolves_with_fail_silently_enabled(): void
    {
        $mapper = new DataMapper([], true);

        $mappings = [
            'code' => ['type' => 'custom', 'handler' => MissingHandler::class],
            'documents' => ['type' => 'relation', 'target' => 'documents'],
        ];

        $resolved = $mapper->resolve($mappings, [
            'code' => 'AB-1',
            'documents' => [10, 11],
        ]);

        $this->assertSame('AB-1', $resolved['code']);
        $this->assertSame([10, 11], $resolved['documents']);
    }

    public function test_it_resolves_handlers_through_injected_resolver(): void
    {
        $mapper = new DataMapper([], false, static fn (string $handlerClass): object => match ($handlerClass) {
            TestResolverMapper::class => new TestResolverMapper('prefix-'),
            default => new $handlerClass(),
        });

        $result = $mapper->map([
            'token' => ['type' => 'custom', 'handler' => TestResolverMapper::class, 'query_handler' => TestResolverMapper::class],
        ], [], [
            'token' => 'abc',
        ]);

        $this->assertSame('prefix-abc', $result['instance_data']['token']);

        $resolved = $mapper->resolve([
            'token' => ['type' => 'custom', 'handler' => TestResolverMapper::class, 'query_handler' => TestResolverMapper::class],
        ], $result['instance_data']);

        $this->assertSame('resolved:prefix-abc', $resolved['token']);
    }
}

class MissingHandler
{
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

class TestResolverMapper implements MappingHandlerInterface, MappingQueryHandlerInterface
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function handle(mixed $value, array $context): ?array
    {
        return ['value' => $this->prefix . (string) $value];
    }

    public function fetch(array $context, array $options = []): mixed
    {
        return 'resolved:' . (string) ($context['value'] ?? '');
    }
}
