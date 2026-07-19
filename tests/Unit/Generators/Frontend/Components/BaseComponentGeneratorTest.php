<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Regression coverage for BaseComponentGenerator::generateColumnsFromListFields().
 *
 * Bug: the list/page.stub template always renders a
 * <template #cell-actions="{ row }"> slot with View/Edit/Delete buttons, but
 * generateColumnsFromListFields() never emitted a matching
 * { key: "actions", ... } entry in the generated columns array — so the
 * buttons silently never rendered in any freshly scaffolded module (they
 * only worked in modules like Users/LocationTypes/UserLocations where a
 * human had hand-added the column afterwards).
 *
 * Fix (see BaseComponentGenerator::generateColumnsFromListFields()): the
 * method now appends { key: "actions", label: "", width: 120, align: 'right' }
 * after the ID column, unless a field named "actions" was already supplied
 * by the caller (in which case the caller's own entry is left as-is and
 * nothing is double-added).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateColumnsFromListFields()
 */
class BaseComponentGeneratorTest extends TestCase
{
    /**
     * Build a bare BaseComponentGenerator instance without running its
     * constructor, since BaseGenerator::__construct() calls
     * PathManager::ensureOutputDirectories() which requires a booted
     * Laravel application (base_path(), config(), etc.) that isn't
     * available in a plain PHPUnit run.
     *
     * @param array<string, mixed> $config
     */
    private function makeGenerator(string $moduleName = 'TestModule', string $moduleGroup = 'Core', array $config = []): TestBaseComponentGenerator
    {
        $ref = new ReflectionClass(TestBaseComponentGenerator::class);
        /** @var TestBaseComponentGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $this->setProtectedProperty($generator, 'moduleName', $moduleName);
        $this->setProtectedProperty($generator, 'moduleGroup', $moduleGroup);
        $this->setProtectedProperty($generator, 'config', $config);

        return $generator;
    }

    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /** @return string[] */
    private function splitColumns(string $result): array
    {
        return explode(",\n\t", $result);
    }

    public function test_normal_fields_get_actions_column_appended_last(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'parent_id', 'sortable' => true],
            ['key' => 'description', 'sortable' => false],
            ['key' => 'status_id', 'sortable' => true],
        ]);

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"parent_id\", label: t('test-module.col_parent_id'), sortable: true, width: 150 },\n"
            . "\t{ key: \"description\", label: t('test-module.col_description'), sortable: false, width: 150 },\n"
            . "\t{ key: \"status_id\", label: t('test-module.col_status_id'), sortable: true, width: 150 },\n"
            . "\t{ key: \"id\", label: \"ID\", sortable: false, width: 100 },\n"
            . "\t{ key: \"actions\", label: \"\", width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        // The actions column must be the last of the six emitted columns.
        $columns = $this->splitColumns($result);
        $this->assertCount(6, $columns);
        $this->assertSame('{ key: "actions", label: "", width: 120, align: \'right\' }', end($columns));
    }

    public function test_zero_fields_still_appends_actions_column_without_crashing(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([]);

        $expected = "{ key: \"id\", label: \"ID\", sortable: false, width: 100 },\n"
            . "\t{ key: \"actions\", label: \"\", width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(2, $columns, 'Zero fields should still yield exactly [ID column, actions column].');
    }

    public function test_existing_actions_field_is_not_duplicated(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'actions', 'sortable' => false],
        ]);

        // Exactly one "actions" key in the output — the caller-supplied one,
        // rendered through the normal field loop (not the special
        // auto-appended entry, which carries `align: 'right'`).
        $this->assertSame(1, substr_count($result, 'key: "actions"'));
        $this->assertStringNotContainsString("align: 'right'", $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(3, $columns, 'No extra column should be appended when "actions" is already a supplied field.');

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"actions\", label: t('test-module.col_actions'), sortable: false, width: 150 },\n"
            . "\t{ key: \"id\", label: \"ID\", sortable: false, width: 100 }";

        $this->assertSame($expected, $result);
    }

    public function test_uuid_id_type_still_appends_actions_column_after_uuid_id_column(): void
    {
        $generator = $this->makeGenerator(config: ['id_type' => 'uuid']);

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'title', 'sortable' => true],
        ]);

        $expected = "{ key: \"title\", label: t('test-module.col_title'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"uuid\", label: \"ID\", sortable: false, width: 100 },\n"
            . "\t{ key: \"actions\", label: \"\", width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        $this->assertStringContainsString('{ key: "uuid", label: "ID"', $result);
    }
}

/**
 * Minimal concrete subclass exposing the protected method under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestBaseComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return true;
    }

    public function callGenerateColumnsFromListFields(array $fields, ?string $primaryKey = null): string
    {
        return $this->generateColumnsFromListFields($fields, $primaryKey);
    }
}
