<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Facades;

use Daiv05\LaravelWorkflowEngine\Engine\ExecutionBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ExecutionBuilder execution(?string $instanceId = null)
 */
class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'workflow';
    }
}
