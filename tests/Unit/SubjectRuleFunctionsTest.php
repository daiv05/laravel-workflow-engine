<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Functions\SubjectRuleFunctions;
use PHPUnit\Framework\TestCase;

class SubjectRuleFunctionsTest extends TestCase
{
    public function test_subject_type_matches_returns_true_when_subject_type_matches(): void
    {
        $result = SubjectRuleFunctions::subjectTypeMatches([
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => '123',
            ],
        ], 'App\\Models\\Solicitud');

        $this->assertTrue($result);
    }

    public function test_subject_type_matches_returns_false_without_subject_context(): void
    {
        $result = SubjectRuleFunctions::subjectTypeMatches([], 'App\\Models\\Solicitud');

        $this->assertFalse($result);
    }

    public function test_is_subject_owner_uses_actor_id_key_by_default(): void
    {
        $result = SubjectRuleFunctions::isSubjectOwner([
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => '321',
            ],
            'actor_id' => 321,
        ]);

        $this->assertTrue($result);
    }

    public function test_is_subject_owner_supports_custom_context_key(): void
    {
        $result = SubjectRuleFunctions::isSubjectOwner([
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => 'abc',
            ],
            'owner_id' => 'abc',
        ], 'owner_id');

        $this->assertTrue($result);
    }

    public function test_is_subject_owner_returns_false_for_mismatch(): void
    {
        $result = SubjectRuleFunctions::isSubjectOwner([
            'subject' => [
                'subject_type' => 'App\\Models\\Solicitud',
                'subject_id' => 'abc',
            ],
            'actor_id' => 'xyz',
        ]);

        $this->assertFalse($result);
    }
}
