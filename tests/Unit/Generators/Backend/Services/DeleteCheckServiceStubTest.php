<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use PHPUnit\Framework\TestCase;

/**
 * The DeleteCheckService stub generated a getUpdatedRecordsCount() that always returned 0 and that nothing in the
 * stub called: dead scaffolding in every module that has a delete check. A module that really needs to count what
 * a record last-updated (Users does) writes its own, and its own execute() calls it.
 */
class DeleteCheckServiceStubTest extends TestCase
{
    private function stub(): string
    {
        $path = dirname(__DIR__, 5) . '/src/Generators/Templates/backend/Features/deleteCheck/service.stub';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_stub_no_longer_ships_an_unwired_updated_records_count(): void
    {
        $this->assertStringNotContainsString('getUpdatedRecordsCount', $this->stub());
    }

    public function test_every_method_the_stub_defines_is_one_it_uses(): void
    {
        $stub = $this->stub();
        preg_match_all('/protected static function (\w+)\(/', $stub, $defined);

        foreach ($defined[1] as $method) {
            $this->assertGreaterThan(
                1,
                substr_count($stub, $method . '('),
                "{$method}() is defined but never called from the stub: dead scaffolding"
            );
        }
    }

    public function test_the_created_records_count_that_the_dependent_checks_fill_in_is_still_there(): void
    {
        $stub = $this->stub();

        $this->assertStringContainsString('getCreatedRecordsCount', $stub);
        $this->assertStringContainsString('[[dependentCountChecks]]', $stub);
    }
}
