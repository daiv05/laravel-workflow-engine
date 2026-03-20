<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Engine\SubjectNormalizer;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use PHPUnit\Framework\TestCase;

class SubjectNormalizerTest extends TestCase
{
    public function test_normalize_valid_subject_with_string_id(): void
    {
        $result = SubjectNormalizer::normalize([
            'subject_type' => 'App\\Models\\Solicitud',
            'subject_id' => '123',
        ]);

        $this->assertSame('App\\Models\\Solicitud', $result['subject_type']);
        $this->assertSame('123', $result['subject_id']);
    }

    public function test_normalize_valid_subject_with_integer_id(): void
    {
        $result = SubjectNormalizer::normalize([
            'subject_type' => 'App\\Models\\Solicitud',
            'subject_id' => 456,
        ]);

        $this->assertSame('App\\Models\\Solicitud', $result['subject_type']);
        $this->assertSame('456', $result['subject_id']);
    }

    public function test_normalize_valid_subject_with_uuid_id(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = SubjectNormalizer::normalize([
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => $uuid,
        ]);

        $this->assertSame('App\\Models\\Order', $result['subject_type']);
        $this->assertSame($uuid, $result['subject_id']);
    }

    public function test_throws_on_missing_subject_type(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('subject_type is required');

        SubjectNormalizer::normalize([
            'subject_id' => '123',
        ]);
    }

    public function test_throws_on_empty_subject_type(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('subject_type is required');

        SubjectNormalizer::normalize([
            'subject_type' => '',
            'subject_id' => '123',
        ]);
    }

    public function test_throws_on_missing_subject_id(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('subject_id is required');

        SubjectNormalizer::normalize([
            'subject_type' => 'App\\Models\\Solicitud',
        ]);
    }

    public function test_throws_on_non_array_subject(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Subject must be an array');

        SubjectNormalizer::normalize('App\\Models\\Solicitud::123');
    }

    public function test_throws_on_non_scalar_subject_id(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('subject_id must be a scalar');

        SubjectNormalizer::normalize([
            'subject_type' => 'App\\Models\\Solicitud',
            'subject_id' => ['nested' => 'array'],
        ]);
    }
}
