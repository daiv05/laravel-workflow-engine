<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Functions;

final class SubjectRuleFunctions
{
    /**
     * @param array<string, mixed> $context
     */
    public static function subjectTypeMatches(array $context, string $expectedType): bool
    {
        $subject = $context['subject'] ?? null;
        if (!is_array($subject)) {
            return false;
        }

        $subjectType = $subject['subject_type'] ?? null;
        return is_string($subjectType) && $subjectType === $expectedType;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function isSubjectOwner(array $context, string $actorIdKey = 'actor_id'): bool
    {
        $subject = $context['subject'] ?? null;
        if (!is_array($subject)) {
            return false;
        }

        $subjectId = $subject['subject_id'] ?? null;
        $actorId = $context[$actorIdKey] ?? null;

        if (!is_scalar($subjectId) || !is_scalar($actorId)) {
            return false;
        }

        return (string) $subjectId === (string) $actorId;
    }
}
