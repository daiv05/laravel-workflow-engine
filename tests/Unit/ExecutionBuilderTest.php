<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\Contracts\ExecutionBuilderInterface;
use Daiv05\LaravelWorkflowEngine\Engine\ExecutionBuilder;
use Daiv05\LaravelWorkflowEngine\Engine\WorkflowEngine;
use Daiv05\LaravelWorkflowEngine\Exceptions\WorkflowException;
use PHPUnit\Framework\TestCase;

class ExecutionBuilderTest extends TestCase
{
    public function test_it_implements_public_execution_builder_contract(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $builder = new ExecutionBuilder($engine);

        $this->assertInstanceOf(ExecutionBuilderInterface::class, $builder);
    }

    public function test_execute_throws_when_instance_id_is_missing(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $builder = new ExecutionBuilder($engine);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionCode(7002);
        $this->expectExceptionMessage('ExecutionBuilder requires an instance_id. Call forInstance() before execute().');

        $builder->execute('approve', ['roles' => ['HR']]);
    }

    public function test_update_throws_when_instance_id_is_missing(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $builder = new ExecutionBuilder($engine);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionCode(7003);
        $this->expectExceptionMessage('ExecutionBuilder requires an instance_id. Call forInstance() before update().');

        $builder->update(['data' => ['comment' => 'x']]);
    }

    public function test_for_instance_update_forwards_hooks_and_listeners_and_ignores_empty_event_name(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);

        $beforeCalls = [];
        $afterCalls = [];
        $namedEvents = [];
        $anyEvents = [];

        $engine->expects($this->once())
            ->method('updateWithListeners')
            ->with(
                'iid-1',
                ['actor' => 'owner-1', 'data' => ['comment' => 'updated']],
                $this->callback(static function (array $listeners) use (&$namedEvents, &$anyEvents): bool {
                    if (isset($listeners['named'][''])) {
                        return false;
                    }

                    if (!isset($listeners['named']['updated'][0]) || !is_callable($listeners['named']['updated'][0])) {
                        return false;
                    }

                    if (!isset($listeners['any'][0]) || !is_callable($listeners['any'][0])) {
                        return false;
                    }

                    $listeners['named']['updated'][0](['state' => 'draft']);
                    $listeners['any'][0]('updated', ['state' => 'draft']);

                    return true;
                })
            )
            ->willReturn(['instance_id' => 'iid-1', 'state' => 'draft', 'version' => 1]);

        $result = (new ExecutionBuilder($engine))
            ->forInstance('iid-1')
            ->on('', static function (): void {
            })
            ->on('updated', static function (array $payload) use (&$namedEvents): void {
                $namedEvents[] = $payload['state'] ?? '';
            })
            ->onAny(static function (string $event, array $payload) use (&$anyEvents): void {
                $anyEvents[] = [$event, $payload['state'] ?? ''];
            })
            ->before(static function (string $action, array $context, string $instanceId) use (&$beforeCalls): void {
                $beforeCalls[] = [$action, $instanceId, $context['actor'] ?? null];
            })
            ->after(static function (string $action, array $context, array $updated, string $instanceId) use (&$afterCalls): void {
                $afterCalls[] = [$action, $instanceId, $updated['version'] ?? null];
            })
            ->update(['actor' => 'owner-1', 'data' => ['comment' => 'updated']]);

        $this->assertSame('iid-1', $result['instance_id']);
        $this->assertSame('draft', $result['state']);
        $this->assertSame([['update', 'iid-1', 'owner-1']], $beforeCalls);
        $this->assertSame([['update', 'iid-1', 1]], $afterCalls);
        $this->assertSame(['draft'], $namedEvents);
        $this->assertSame([['updated', 'draft']], $anyEvents);
    }

    public function test_for_instance_execute_forwards_action_context_and_result_to_hooks(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);

        $beforeCalls = [];
        $afterCalls = [];

        $engine->expects($this->once())
            ->method('executeWithListeners')
            ->with(
                'iid-2',
                'approve',
                ['roles' => ['HR']],
                $this->callback(static function (array $listeners): bool {
                    return isset($listeners['named']) && isset($listeners['any']) && is_array($listeners['named']) && is_array($listeners['any']);
                })
            )
            ->willReturn(['instance_id' => 'iid-2', 'state' => 'approved']);

        $result = (new ExecutionBuilder($engine))
            ->forInstance('iid-2')
            ->before(static function (string $action, array $context, string $instanceId) use (&$beforeCalls): void {
                $beforeCalls[] = [$action, $instanceId, $context['roles'] ?? []];
            })
            ->after(static function (string $action, array $context, array $updated, string $instanceId) use (&$afterCalls): void {
                $afterCalls[] = [$action, $instanceId, $updated['state'] ?? ''];
            })
            ->execute('approve', ['roles' => ['HR']]);

        $this->assertSame('approved', $result['state']);
        $this->assertSame([['approve', 'iid-2', ['HR']]], $beforeCalls);
        $this->assertSame([['approve', 'iid-2', 'approved']], $afterCalls);
    }
}
