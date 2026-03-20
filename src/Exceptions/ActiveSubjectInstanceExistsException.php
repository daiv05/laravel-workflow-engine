<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Exceptions;

class ActiveSubjectInstanceExistsException extends WorkflowException
{
	/**
	 * @param array<string, string> $subjectRef
	 */
	public static function forSubject(
		string $workflowName,
		array $subjectRef,
		?string $existingInstanceId,
		?string $tenantId = null
	): self {
		return new self(
			'An active instance of ' . $workflowName . ' already exists for this subject',
			7002,
			null,
			[
				'workflow_name' => $workflowName,
				'subject_type' => $subjectRef['subject_type'] ?? null,
				'subject_id' => $subjectRef['subject_id'] ?? null,
				'existing_instance_id' => $existingInstanceId,
				'tenant_id' => $tenantId,
			]
		);
	}
}
