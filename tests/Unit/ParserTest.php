<?php

declare(strict_types=1);

namespace Daiv05\LaravelWorkflowEngine\Tests\Unit;

use Daiv05\LaravelWorkflowEngine\DSL\Parser;
use Daiv05\LaravelWorkflowEngine\Exceptions\DSLValidationException;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public function test_it_parses_json_definition_string(): void
    {
        $parser = new Parser();

        $parsed = $parser->parse('{"dsl_version":2,"name":"wf"}');

        $this->assertSame(2, $parsed['dsl_version']);
        $this->assertSame('wf', $parsed['name']);
    }

    public function test_it_parses_yaml_definition_string(): void
    {
        $parser = new Parser();

        $parsed = $parser->parse("dsl_version: 2\nname: wf\nversion: 1\n");

        $this->assertSame(2, $parsed['dsl_version']);
        $this->assertSame('wf', $parsed['name']);
    }

    public function test_it_throws_for_empty_definition_string(): void
    {
        $parser = new Parser();

        $this->expectException(DSLValidationException::class);
        $parser->parse('   ');
    }

    public function test_it_throws_for_malformed_yaml_definition(): void
    {
        $parser = new Parser();

        $this->expectException(DSLValidationException::class);
        $parser->parse("dsl_version: 2\nname: [\n");
    }
}
