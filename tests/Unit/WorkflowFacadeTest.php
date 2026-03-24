<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Facades\Workflow;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class WorkflowFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_facade_accessor_matches_container_binding(): void
    {
        $this->assertSame('workflow', WorkflowFacadeProbe::accessor());
    }

    public function test_facade_delegates_calls_to_workflow_service(): void
    {
        $builder = new \stdClass();
        $workflowService = new class ($builder) {
            public array $calls = [];

            public function __construct(private readonly object $builder)
            {
            }

            public function execution(?string $instanceId = null): object
            {
                $this->calls[] = ['execution', [$instanceId]];

                return $this->builder;
            }

            public function canUpdate(string $instanceId, array $context = []): bool
            {
                $this->calls[] = ['canUpdate', [$instanceId, $context]];

                return true;
            }

            public function update(string $instanceId, array $context = []): array
            {
                $this->calls[] = ['update', [$instanceId, $context]];

                return ['instance_id' => $instanceId, 'state' => 'updated'];
            }

            public function getLatestInstanceForSubject(string $workflowName, array $subjectRef, ?string $tenantId = null): ?array
            {
                $this->calls[] = ['getLatestInstanceForSubject', [$workflowName, $subjectRef, $tenantId]];

                return ['workflow_name' => $workflowName, 'instance_id' => 'iid-latest'];
            }

            public function getInstancesForSubject(array $subjectRef, ?string $tenantId = null, ?string $workflowName = null): array
            {
                $this->calls[] = ['getInstancesForSubject', [$subjectRef, $tenantId, $workflowName]];

                return [['instance_id' => 'iid-1'], ['instance_id' => 'iid-2']];
            }
        };

        $container = new Container();
        $container->instance('workflow', $workflowService);

        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->assertSame($builder, Workflow::execution('iid-1'));
        $this->assertTrue(Workflow::canUpdate('iid-1', ['roles' => ['HR']]));
        $this->assertSame(
            ['instance_id' => 'iid-1', 'state' => 'updated'],
            Workflow::update('iid-1', ['roles' => ['HR']])
        );
        $this->assertSame(
            ['workflow_name' => 'approval', 'instance_id' => 'iid-latest'],
            Workflow::getLatestInstanceForSubject('approval', ['subject_type' => 'Order', 'subject_id' => '1'], 'tenant-a')
        );
        $this->assertSame(
            [['instance_id' => 'iid-1'], ['instance_id' => 'iid-2']],
            Workflow::getInstancesForSubject(['subject_type' => 'Order', 'subject_id' => '1'], 'tenant-a', 'approval')
        );

        $this->assertSame(
            [
                ['execution', ['iid-1']],
                ['canUpdate', ['iid-1', ['roles' => ['HR']]]],
                ['update', ['iid-1', ['roles' => ['HR']]]],
                ['getLatestInstanceForSubject', ['approval', ['subject_type' => 'Order', 'subject_id' => '1'], 'tenant-a']],
                ['getInstancesForSubject', [['subject_type' => 'Order', 'subject_id' => '1'], 'tenant-a', 'approval']],
            ],
            $workflowService->calls
        );
    }
}

class WorkflowFacadeProbe extends Workflow
{
    public static function accessor(): string
    {
        return parent::getFacadeAccessor();
    }
}
