<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Regression coverage for the "forward-reference" DeleteCheckService bug:
 * a module generated BEFORE the modules that reference it could not resolve
 * them via PathManager::findModuleByTable(), because the registry was only
 * ever seeded from the filesystem (already-generated modules) plus
 * incrementally appended as generation proceeded -- so the FIRST module in
 * a dependency chain always saw an incomplete registry on a from-scratch
 * ("pass 1") run. The real fix (pre-seeding the registry from the blueprint
 * before the generation loop starts) lives in each consumer's
 * MakeModulesFromDb.php, not in this package -- see that file's "Pre-seed
 * registry with blueprint modules" block. This test covers the two things
 * that DO live in the package:
 *
 *   1. generateDependentCountChecks() must still emit byte-for-byte
 *      identical PHP for both the "no dependents" case and the "resolvable
 *      dependent" case -- the fix must not change a single character of
 *      generated output, only add a side-channel warning for the
 *      genuinely-unresolvable case.
 *   2. An unresolved dependent now reports through PathManager::reportIssue()
 *      (the existing issue-handler channel) as a 'warning' -- unless the
 *      table is a declared skip-group table (PathManager::isSkipTable()),
 *      in which case it must stay silent, since that placeholder is the
 *      intended, permanent output for a table that will never get a module.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator::generateDependentCountChecks()
 * @see \Blutrixx\GeneratorEngine\Generators\PathManager::reportIssue()
 * @see \Blutrixx\GeneratorEngine\Generators\PathManager::isSkipTable()
 */
class DeleteCheckServiceGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start every test from a clean static state -- PathManager's
        // registry/FK-graph/skip-tables/issue-handler are all process-wide
        // statics shared across tests.
        PathManager::setModuleRegistry([]);
        PathManager::setForeignKeyGraph([]);
        PathManager::setSkipTables([]);
        PathManager::setIssueHandler(null);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        PathManager::setForeignKeyGraph([]);
        PathManager::setSkipTables([]);
        PathManager::setIssueHandler(null);
        parent::tearDown();
    }

    /**
     * Build a bare DeleteCheckServiceGenerator instance without running its
     * constructor, since BaseGenerator::__construct() calls
     * PathManager::ensureOutputDirectories(), which requires a booted
     * Laravel application not available in a plain PHPUnit run.
     */
    private function makeGenerator(string $tableName): TestDeleteCheckServiceGenerator
    {
        $ref = new ReflectionClass(TestDeleteCheckServiceGenerator::class);
        /** @var TestDeleteCheckServiceGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $this->setProtectedProperty($generator, 'moduleName', 'TestModule');
        $this->setProtectedProperty($generator, 'moduleGroup', 'Core');
        $this->setProtectedProperty($generator, 'config', ['table_name' => $tableName]);

        return $generator;
    }

    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    // ─── Regression proof: output is byte-for-byte identical ───────────────

    public function test_no_dependent_tables_output_is_unchanged(): void
    {
        // A module with no incoming FK references (e.g. ItemPrices in the
        // items-suite fixture) must generate exactly this comment, whether
        // or not the registry pre-seeding fix and the new warning branch
        // exist -- neither is ever reached when $dependents is empty.
        $generator = $this->makeGenerator('item_prices');

        $result = $generator->callGenerateDependentCountChecks();

        $this->assertSame(
            '// No dependent tables detected — safe to delete without checks.',
            $result
        );
    }

    public function test_resolvable_dependent_emits_unchanged_model_count_line(): void
    {
        // A dependent whose module IS in the registry (whether seeded from
        // the filesystem, as before this fix, or pre-seeded from the
        // blueprint, as after it) must emit the exact same working count()
        // line as before -- the fix touches only the null-registry-entry
        // branch below.
        PathManager::setForeignKeyGraph([
            'item_categories' => [
                ['source_table' => 'items', 'source_column' => 'item_category_id'],
            ],
        ]);
        PathManager::setModuleRegistry([
            [
                'name'        => 'Items',
                'module_type' => 'Custom',
                'group_name'  => null,
                'table_name'  => 'items',
            ],
        ]);

        $generator = $this->makeGenerator('item_categories');

        $result = $generator->callGenerateDependentCountChecks();

        $this->assertSame(
            '$count += \App\Project\Modules\Custom\Items\ItemsModel::where(\'item_category_id\', $record->id)->count();',
            $result
        );
    }

    // ─── New behaviour: loud-but-not-fatal warning for a real unresolved case ──

    public function test_unresolved_dependent_still_emits_same_placeholder_comment(): void
    {
        PathManager::setForeignKeyGraph([
            'item_categories' => [
                ['source_table' => 'items', 'source_column' => 'item_category_id'],
            ],
        ]);
        // Registry deliberately left empty: 'items' cannot resolve.

        $generator = $this->makeGenerator('item_categories');

        $result = $generator->callGenerateDependentCountChecks();

        $this->assertSame(
            "// Could not resolve module for table 'items' — add it to the registry.\n"
            . "        // \$count += \\DB::table('items')->where('item_category_id', \$record->id)->count();",
            $result
        );
    }

    public function test_unresolved_dependent_reports_a_warning_via_path_manager(): void
    {
        PathManager::setForeignKeyGraph([
            'item_categories' => [
                ['source_table' => 'items', 'source_column' => 'item_category_id'],
            ],
        ]);

        $reported = [];
        PathManager::setIssueHandler(function (string $message, string $level = 'warning') use (&$reported) {
            $reported[] = ['message' => $message, 'level' => $level];
        });

        $generator = $this->makeGenerator('item_categories');
        $generator->callGenerateDependentCountChecks();

        $this->assertCount(1, $reported);
        $this->assertSame('warning', $reported[0]['level']);
        $this->assertStringContainsString('items', $reported[0]['message']);
        $this->assertStringContainsString('item_categories', $reported[0]['message']);
    }

    public function test_unresolved_dependent_in_skip_group_stays_silent(): void
    {
        // A skip-group table (e.g. a shared/lookup table the blueprint
        // intentionally never scaffolds a module for) is EXPECTED to never
        // resolve -- this must not produce a warning, only the same
        // commented-out placeholder every unresolved case gets.
        PathManager::setForeignKeyGraph([
            'item_categories' => [
                ['source_table' => 'audit_logs', 'source_column' => 'item_category_id'],
            ],
        ]);
        PathManager::setSkipTables(['audit_logs']);

        $reported = [];
        PathManager::setIssueHandler(function (string $message, string $level = 'warning') use (&$reported) {
            $reported[] = ['message' => $message, 'level' => $level];
        });

        $generator = $this->makeGenerator('item_categories');
        $result = $generator->callGenerateDependentCountChecks();

        $this->assertSame([], $reported);
        $this->assertStringContainsString("Could not resolve module for table 'audit_logs'", $result);
    }
}

/**
 * Minimal concrete subclass exposing the protected method under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestDeleteCheckServiceGenerator extends DeleteCheckServiceGenerator
{
    public function callGenerateDependentCountChecks(): string
    {
        return $this->generateDependentCountChecks();
    }
}
