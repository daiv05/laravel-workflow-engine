<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Engine;

use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;

class SubjectNormalizer
{
    /**
     * Normalize a subject reference to canonical form.
     *
     * @param mixed $subject
     *
     * @return array<string, string>
     *
     * @throws WorkflowException
     */
    public static function normalize(mixed $subject): array
    {
        if (!is_array($subject)) {
            throw new WorkflowException('Subject must be an array with subject_type and subject_id keys');
        }

        $subjectType = $subject['subject_type'] ?? null;
        $subjectId = $subject['subject_id'] ?? null;

        if ($subjectType === null || !is_string($subjectType) || $subjectType === '') {
            throw new WorkflowException('subject_type is required and must be a non-empty string');
        }

        if ($subjectId === null) {
            throw new WorkflowException('subject_id is required');
        }

        $normalizedId = self::normalizeId($subjectId);
        if ($normalizedId === null || $normalizedId === '') {
            throw new WorkflowException('subject_id must be a scalar value that can be normalized to a string');
        }

        return [
            'subject_type' => $subjectType,
            'subject_id' => $normalizedId,
        ];
    }

    /**
     * Normalize subject_id to string.
     *
     * @param mixed $id
     *
     * @return string|null
     */
    private static function normalizeId(mixed $id): ?string
    {
        if (is_string($id)) {
            return $id;
        }

        if (is_int($id) || is_float($id)) {
            return (string) $id;
        }

        if (is_object($id) && method_exists($id, '__toString')) {
            return (string) $id;
        }

        return null;
    }
}
