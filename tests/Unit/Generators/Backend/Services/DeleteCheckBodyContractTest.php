<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * SYSTEM_SHELL's DeleteCheckRefresher (plan 034) parses this generator's own
 * output to decide whether an existing delete check is still "generator-
 * shaped" and therefore safe to refresh: every body line generateDependentCountChecks()
 * can ever emit must be either a working count-check line or one of a fixed
 * set of comment prefixes -- never anything else. This test pins that
 * contract directly against the real generator, so a future change to its
 * emitted shapes fails here first, before silently breaking the consumer's
 * own content-based guard (RefreshableDeleteCheckServiceGenerator::isPristine()).
 *
 * COUNT_LINE / GENERATED_COMMENT_PREFIXES below are a deliberate verbatim
 * copy of SYSTEM_SHELL's own constants -- keep in sync with
 * BACKEND/app/Project/_Src/Console/RefreshableDeleteCheckServiceGenerator.php.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator::generateDependentCountChecks()
 */
class DeleteCheckBodyContractTest extends TestCase
{
    private const COUNT_LINE = '/^\$count \+= \\\\App\\\\Project\\\\Modules\\\\[A-Za-z0-9_\\\\]+Model::where\(\'[A-Za-z0-9_]+\', \$record->id\)->count\(\);$/';

    private const GENERATED_COMMENT_PREFIXES = [
        '// No dependent tables detected',
        '// No resolvable dependents',
        '// No table name configured',
        "// Could not resolve module for table '",
        '// $count += \DB::table(',
        "// FK graph declared '",
    ];

    protected function setUp(): void
    {
        parent::setUp();
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

    private function makeGenerator(string $tableName, string $class = TestContractDeleteCheckServiceGenerator::class): TestContractDeleteCheckServiceGenerator
    {
        $ref = new ReflectionClass($class);
        /** @var TestContractDeleteCheckServiceGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $prop = new ReflectionProperty($generator, 'moduleName');
        $prop->setAccessible(true);
        $prop->setValue($generator, 'TestModule');

        $prop = new ReflectionProperty($generator, 'moduleGroup');
        $prop->setAccessible(true);
        $prop->setValue($generator, 'Core');

        $prop = new ReflectionProperty($generator, 'config');
        $prop->setAccessible(true);
        $prop->setValue($generator, ['table_name' => $tableName]);

        return $generator;
    }

    private function assertEveryLineMatchesTheContract(string $body): void
    {
        foreach (preg_split('/\R/', $body) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match(self::COUNT_LINE, $trimmed)) {
                continue;
            }

            $matched = false;
            foreach (self::GENERATED_COMMENT_PREFIXES as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            $this->assertTrue($matched, "Line does not match the delete-check body contract: {$trimmed}");
        }
    }

    public function test_the_service_stub_has_exactly_one_dependent_count_checks_placeholder(): void
    {
        $generator = $this->makeGenerator('probe');
        $ref = new ReflectionClass($generator);
        $method = $ref->getMethod('getTemplateContent');
        $method->setAccessible(true);
        $stub = $method->invoke($generator, 'Features/deleteCheck/service', 'backend');

        $this->assertSame(1, substr_count($stub, '[[dependentCountChecks]]'));
    }

    /**
     * All five branches generateDependentCountChecks() can take, each
     * checked against the same content contract RefreshableDeleteCheckServiceGenerator::isPristine()
     * relies on: no dependents; a resolvable dependent; an unresolved
     * dependent; a dependent whose column is missing on the live schema;
     * and a declared skip-group table (silent, no issue reported).
     */
    public function test_every_branch_of_generated_output_matches_the_body_contract(): void
    {
        // (a) No dependents at all.
        PathManager::setForeignKeyGraph([]);
        $noDependents = $this->makeGenerator('probe_parents')->callGenerateDependentCountChecks();
        $this->assertEveryLineMatchesTheContract($noDependents);

        // (b) A resolvable dependent -- real working count-check line.
        PathManager::setModuleRegistry([
            ['name' => 'ProbeChildren', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'probe_children'],
        ]);
        PathManager::setForeignKeyGraph([
            'probe_parents' => [['source_table' => 'probe_children', 'source_column' => 'probe_parent_id']],
        ]);
        $resolvable = $this->makeGenerator('probe_parents')->callGenerateDependentCountChecks();
        $this->assertEveryLineMatchesTheContract($resolvable);
        $this->assertMatchesRegularExpression(self::COUNT_LINE, trim($resolvable));

        // (c) An unresolved dependent -- no module in the registry for it.
        PathManager::setModuleRegistry([]);
        PathManager::setForeignKeyGraph([
            'probe_parents' => [['source_table' => 'probe_ghosts', 'source_column' => 'probe_parent_id']],
        ]);
        $unresolved = $this->makeGenerator('probe_parents')->callGenerateDependentCountChecks();
        $this->assertEveryLineMatchesTheContract($unresolved);

        // (d) A dependent whose FK-graph column doesn't exist on the live schema.
        PathManager::setModuleRegistry([
            ['name' => 'ProbeChildren', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'probe_children'],
        ]);
        PathManager::setForeignKeyGraph([
            'probe_parents' => [['source_table' => 'probe_children', 'source_column' => 'ghost_column']],
        ]);
        $columnMissing = $this->makeGenerator('probe_parents', TestColumnMissingDeleteCheckServiceGenerator::class)
            ->callGenerateDependentCountChecks();
        $this->assertEveryLineMatchesTheContract($columnMissing);

        // (e) A declared skip-group table -- silent, no issue reported.
        PathManager::setModuleRegistry([]);
        PathManager::setSkipTables(['probe_skip']);
        PathManager::setForeignKeyGraph([
            'probe_parents' => [['source_table' => 'probe_skip', 'source_column' => 'probe_parent_id']],
        ]);
        $issues = [];
        PathManager::setIssueHandler(function (string $m, string $l = 'warning') use (&$issues) {
            $issues[] = $m;
        });
        $skipGroup = $this->makeGenerator('probe_parents')->callGenerateDependentCountChecks();
        $this->assertEveryLineMatchesTheContract($skipGroup);
        $this->assertSame([], $issues);
    }
}

class TestContractDeleteCheckServiceGenerator extends DeleteCheckServiceGenerator
{
    public function callGenerateDependentCountChecks(): string
    {
        return $this->generateDependentCountChecks();
    }
}

class TestColumnMissingDeleteCheckServiceGenerator extends TestContractDeleteCheckServiceGenerator
{
    protected function dependentColumnExists(string $table, string $column): bool
    {
        return false;
    }
}
