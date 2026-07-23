<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Regression coverage for BaseComponentGenerator::generateColumnsFromListFields().
 *
 * Bug 1 (fixed in v2.10.4): the list/page.stub template always renders a
 * <template #cell-actions="{ row }"> slot with View/Edit/Delete buttons, but
 * generateColumnsFromListFields() never emitted a matching
 * { key: "actions", ... } entry in the generated columns array — so the
 * buttons silently never rendered in any freshly scaffolded module (they
 * only worked in modules like Users/LocationTypes/UserLocations where a
 * human had hand-added the column afterwards).
 *
 * Fix: the method appends { key: "actions", label: "", width: 120, align: 'right' }
 * after the ID column, unless a field named "actions" was already supplied
 * by the caller (in which case the caller's own entry is left as-is and
 * nothing is double-added).
 *
 * Bug 2 (fixed in v2.10.7): the ID column was emitted LAST (right before
 * actions) and hardcoded `sortable: false`, so every generated list buried
 * its ID column at the end and it could never be sorted or filtered — unlike
 * every other column, which gets `sortable` from its field config.
 *
 * Fix: the ID column entry is now built and appended to $columns FIRST
 * (before any schema-derived field), and is always `sortable: true`. The
 * matching backend-side fix (BaseServiceGenerator::generateFilterableFields()
 * / generateSortableFields() / generateFilterFields()) makes "id" backend
 * sortable+filterable and gives it a frontend filter control, and makes
 * "uuid" backend-filterable only — it is still never added to this columns
 * array (see test_uuid_id_type_still_appends_actions_column_after_uuid_id_column
 * below: only ONE id-type column — "uuid" when id_type is 'uuid', "id"
 * otherwise — ever appears here).
 *
 * Bug 3 (fixed 2026-07-23, alongside a manual pattern revision on Users):
 * relation (FK-derived) list columns were emitted identically to any other
 * text column, with no way to keep them out of the default view — the only
 * way to get Users' now-current, decluttered list shape was to hand-edit the
 * generated file afterwards (as just happened to `role_id`). Separately, the
 * auto-appended actions column always emitted `label: ""`, untitled, while
 * Users' actions column carries a real `t('common.actions')` title.
 *
 * Fix: IntrospectionToConfig::buildFrontendListFields() now threads an
 * `isFk` flag through each field's config entry; generateColumnsFromListFields()
 * appends `, defaultVisible: false` to a non-primary field's column entry
 * when that flag is set (the column still exists — selectable via the list's
 * column picker — it just isn't shown by default), and the auto-appended
 * actions column now emits `label: t('common.actions')` instead of `""`.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateColumnsFromListFields()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildFrontendListFields()
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

    public function test_id_column_is_first_and_sortable_then_fields_then_actions_last(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'parent_id', 'sortable' => true],
            ['key' => 'description', 'sortable' => false],
            ['key' => 'status_id', 'sortable' => true],
        ]);

        $expected = "{ key: \"id\", label: \"ID\", sortable: true, width: 100 },\n"
            . "\t{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"parent_id\", label: t('test-module.col_parent_id'), sortable: true, width: 150 },\n"
            . "\t{ key: \"description\", label: t('test-module.col_description'), sortable: false, width: 150 },\n"
            . "\t{ key: \"status_id\", label: t('test-module.col_status_id'), sortable: true, width: 150 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(6, $columns);
        // ID column must be FIRST.
        $this->assertSame('{ key: "id", label: "ID", sortable: true, width: 100 }', $columns[0]);
        // Actions column must still be LAST.
        $this->assertSame("{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }", end($columns));
    }

    public function test_zero_fields_still_yields_id_then_actions_without_crashing(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([]);

        $expected = "{ key: \"id\", label: \"ID\", sortable: true, width: 100 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(2, $columns, 'Zero fields should still yield exactly [ID column, actions column].');
        $this->assertSame('{ key: "id", label: "ID", sortable: true, width: 100 }', $columns[0]);
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

        $expected = "{ key: \"id\", label: \"ID\", sortable: true, width: 100 },\n"
            . "\t{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"actions\", label: t('test-module.col_actions'), sortable: false, width: 150 }";

        $this->assertSame($expected, $result);
    }

    public function test_uuid_id_type_puts_uuid_id_column_first_and_actions_last(): void
    {
        $generator = $this->makeGenerator(config: ['id_type' => 'uuid']);

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'title', 'sortable' => true],
        ]);

        $expected = "{ key: \"uuid\", label: \"ID\", sortable: true, width: 100 },\n"
            . "\t{ key: \"title\", label: t('test-module.col_title'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        $this->assertStringContainsString('{ key: "uuid", label: "ID", sortable: true', $result);
        // "uuid" must never appear as a second/duplicate column entry elsewhere.
        $this->assertSame(1, substr_count($result, 'key: "uuid"'));
    }

    // ─── FK/relation columns default to hidden (Bug 3, 2026-07-23) ──────────

    public function test_fk_field_gets_default_visible_false(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'role_id', 'sortable' => true, 'isFk' => true],
        ]);

        $expected = "{ key: \"id\", label: \"ID\", sortable: true, width: 100 },\n"
            . "\t{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"role_id\", label: t('test-module.col_role_id'), sortable: true, width: 150, defaultVisible: false },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
    }

    public function test_non_fk_field_has_no_default_visible_key(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'description', 'sortable' => true, 'isFk' => false],
        ]);

        // Non-FK columns are unaffected — no defaultVisible key at all, so
        // existing generated modules' column shape doesn't change for the
        // common (non-relation) case.
        $this->assertStringNotContainsString('defaultVisible', $result);
    }

    public function test_fk_field_as_primary_column_stays_visible(): void
    {
        // Edge case: detectPrimaryField() (IntrospectionToConfig) explicitly
        // prefers a non-FK string column as primary and should never pick an
        // FK column unless literally nothing else exists. If a caller does
        // pass an FK field as the primary key anyway, it must not be hidden —
        // the primary column is the one thing a list can't function without.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'category_id', 'sortable' => true, 'isFk' => true],
        ], primaryKey: 'category_id');

        $this->assertStringContainsString(
            '{ key: "category_id", label: t(\'test-module.col_category_id\'), sortable: true, fixed: true, width: 240 }',
            $result
        );
        $this->assertStringNotContainsString('defaultVisible', $result);
    }

    // ─── Relation data-path snake_case normalization (v2.10.9) ──────────────
    //
    // Bug: mapViewFieldsToInformationFields() built a foreignKey field's
    // "dataPath" straight from the config's raw "data" string (e.g.
    // "itemCategory?.name" -> dataPath "item_category.name" was NEVER
    // produced; it stayed "itemCategory.name"). Eloquent's relationsToArray()
    // snake-cases relation keys in the actual JSON response regardless of the
    // camelCase relation method name ("itemCategory()" -> "item_category" in
    // JSON), so the generated Overview page referenced a key the real API
    // response never has and silently rendered "N/A".
    //
    // Fix: the relationship segment of the path is now run through
    // Str::snake() before being used to build both "key" and "dataPath".
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::mapViewFieldsToInformationFields()
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateInformationSection()

    public function test_relation_data_path_is_snake_cased_for_multiword_camel_case_relation(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'itemCategory?.name', 'title' => 'Parent'],
        ]);

        $this->assertCount(1, $mapped);
        $this->assertSame('item_category.name', $mapped[0]['dataPath']);
        $this->assertSame('item_category', $mapped[0]['key']);
    }

    public function test_overview_page_relation_field_renders_snake_cased_data_path(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'itemCategory?.name', 'title' => 'Parent'],
        ]);

        $result = $generator->callGenerateInformationSection('Overview', 'InfoIcon', $mapped);

        $this->assertStringContainsString('data?.item_category?.name', $result);
        $this->assertStringNotContainsString('itemCategory', $result);
    }

    public function test_already_snake_case_relation_data_path_is_unaffected(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'district?.name', 'title' => 'District'],
        ]);

        $this->assertSame('district.name', $mapped[0]['dataPath']);
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

    public function callMapViewFieldsToInformationFields(array $fields): array
    {
        return $this->mapViewFieldsToInformationFields($fields);
    }

    public function callGenerateInformationSection(string $title, string $icon, array $fields): string
    {
        return $this->generateInformationSection($title, $icon, $fields);
    }
}
