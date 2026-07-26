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
 * Bug 4 (fixed 2026-07-26): a real running app showed a generated list with a
 * meaningless raw internal "ID" column and every FK column hidden behind
 * `defaultVisible: false` — so RelatedRecordLink, which lives inside those FK
 * columns, never rendered. Bug 3's `defaultVisible: false` rule for `isFk`
 * fields cited Users' list as its model, but Users does NOT hide its relation
 * column (`status_id` is visible; only secondary scalars `phone` and
 * `last_logged_in_at` are hidden) — the comment misread its own reference.
 *
 * Fix: generateColumnsFromListFields() no longer emits an ID column at all
 * (matching Users/LocationTypes/Locations, none of which show one), and no
 * longer appends `defaultVisible: false` for `isFk` fields — FK columns are
 * now visible by default like every other column, same as Locations'
 * location_type_id/parent_id and Users' status_id.
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
        $this->setProtectedProperty($generator, 'moduleSubGroup', null);
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

    public function test_no_id_column_is_emitted_and_actions_stays_last(): void
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
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(5, $columns);
        // No visible ID column anywhere in the output.
        $this->assertStringNotContainsString('label: "ID"', $result);
        $this->assertStringNotContainsString('key: "id"', $result);
        // Primary field must be FIRST.
        $this->assertSame("{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 }", $columns[0]);
        // Actions column must still be LAST.
        $this->assertSame("{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }", end($columns));
    }

    public function test_zero_fields_still_yields_only_actions_without_crashing(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([]);

        $expected = "{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(1, $columns, 'Zero fields should still yield exactly [actions column], with no ID column.');
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
        $this->assertCount(2, $columns, 'No extra column should be appended when "actions" is already a supplied field.');

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"actions\", label: t('test-module.col_actions'), sortable: false, width: 150 }";

        $this->assertSame($expected, $result);
    }

    public function test_uuid_id_type_emits_no_id_column_and_actions_last(): void
    {
        $generator = $this->makeGenerator(config: ['id_type' => 'uuid']);

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'title', 'sortable' => true],
        ]);

        $expected = "{ key: \"title\", label: t('test-module.col_title'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString('label: "ID"', $result);
        // "uuid" must never appear as a column entry — id_type only affects
        // the backend-filterable id-type, never the frontend column array.
        $this->assertSame(0, substr_count($result, 'key: "uuid"'));
    }

    // ─── FK/relation columns visible by default (Bug 4, 2026-07-26) ─────────

    public function test_fk_field_has_no_default_visible_key(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'role_id', 'sortable' => true, 'isFk' => true],
        ]);

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 240 },\n"
            . "\t{ key: \"role_id\", label: t('test-module.col_role_id'), sortable: true, width: 150 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        // FK columns are visible by default — no defaultVisible key at all —
        // so RelatedRecordLink (which lives inside them) actually renders.
        $this->assertStringNotContainsString('defaultVisible', $result);
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

    // ─── FK cell renderer via RelatedRecordLink (2026-07-24) ────────────────
    //
    // generateCustomCellRenderersFromListFields() gained a third branch
    // (alongside the pre-existing badge/boolean ones): any non-primary field
    // with isFk true now emits a <template #cell-{key}="{ row }"> wrapping
    // the display value in <RelatedRecordLink module="..." :uuid="...">,
    // using `{ row }` (NOT `{ item }`, unlike the badge/boolean branches)
    // and a relationAccessor derived by stripping the field key's trailing
    // "_id" suffix. RelatedRecordLink itself degrades to inert text if
    // relatedModule isn't registered, so it's always safe to emit.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateCustomCellRenderersFromListFields()

    /** @return array<string, mixed> */
    private function locationsConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationsModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationsModule.json did not decode to an array.');

        return $config;
    }

    public function test_fk_field_emits_related_record_link_cell_using_row_slot_prop(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status?.name', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'Statuses'],
        ], 'name');

        $this->assertStringContainsString('<!-- Custom cell renderer for FK column -->', $result);
        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Statuses" :uuid="row.status?.uuid">', $result);
        $this->assertStringContainsString("{{ row.status?.name || 'N/A' }}", $result);
        $this->assertStringContainsString('</RelatedRecordLink>', $result);
        $this->assertStringContainsString('</template>', $result);

        // Must use the `{ row }` slot prop, never the badge/boolean branch's `{ item }`.
        $this->assertStringNotContainsString('#cell-status_id=\'{ item }\'', $result);
    }

    /**
     * Regression test for a real, confirmed runtime crash: the badge/boolean
     * branch of generateCustomCellRenderersFromListFields() used to emit
     * `<template #cell-{key}='{ item }'>`, destructuring a slot prop that
     * the actual consuming component (<Module>ListPage.vue's <ListTable>,
     * which wraps ReportTable.vue) never provides — ReportTable.vue's
     * generic cell slot only ever passes `:row="row"` (see
     * src/components/report-table/ReportTable.vue), never `item`. Confirmed
     * live: a generated ItemsListPage.vue (is_active is a plain boolean
     * column, visible by default) threw "TypeError: Cannot read properties
     * of undefined (reading 'is_active')" and crashed the ENTIRE list
     * render the instant a real row existed — this wasn't a cosmetic
     * glitch, it made /items/list, /item-images/list, and /item-prices/list
     * completely unusable. The sibling isFk branch already used `{ row }`
     * correctly (see the FK test above) — this branch simply predated it
     * and was never updated to match. Fixed by switching both the
     * relationship-badge and direct-field-badge/boolean sub-branches from
     * `item.` to `row.` throughout.
     */
    public function test_boolean_field_cell_renderer_uses_row_slot_prop_not_item(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('<!-- Custom cell renderer for badge/boolean column -->', $result);
        $this->assertStringContainsString('<template #cell-is_active="{ row }">', $result);
        $this->assertStringContainsString("{{ row.is_active ? 'Yes' : 'No' }}", $result);
        $this->assertStringContainsString("row.is_active === 'Active' || row.is_active === 1 || row.is_active === true", $result);

        // Must never regress back to the undefined-at-runtime `{ item }` shape.
        $this->assertStringNotContainsString("{ item }", $result);
        $this->assertStringNotContainsString('item.is_active', $result);
    }

    /** Same fix, relationship-badge sub-branch (dot-notation data path, e.g. "status.name"). */
    public function test_relationship_badge_field_cell_renderer_uses_row_slot_prop_not_item(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status.name', 'type' => 'badge', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString("row.status.name === 'Active' || row.status.name === 1 || row.status.name === true", $result);
        $this->assertStringContainsString("{{ row.status.name || 'N/A' }}", $result);
        $this->assertStringNotContainsString("{ item }", $result);
    }

    public function test_fk_field_relation_accessor_strips_trailing_id_suffix_only(): void
    {
        // location_type_id -> location_type (singular strip of "_id" only,
        // never a deeper singularization of "location_types").
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'isFk' => false],
            ['key' => 'location_type_id', 'data' => 'location_type?.name', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'LocationTypes'],
        ], 'name');

        $this->assertStringContainsString('module="LocationTypes"', $result);
        $this->assertStringContainsString(':uuid="row.location_type?.uuid"', $result);
        $this->assertStringContainsString("{{ row.location_type?.name || 'N/A' }}", $result);
        // The raw FK column name itself must never leak into the row accessor.
        $this->assertStringNotContainsString('row.location_type_id', $result);
    }

    public function test_primary_fk_field_is_skipped_like_any_other_primary_field(): void
    {
        // The primary column is rendered by generatePrimaryCellContentFromListFields(),
        // not this method -- generateCustomCellRenderersFromListFields() must
        // skip it even when it happens to be a FK.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'category_id', 'sortable' => true, 'isFk' => true, 'relatedModule' => 'Categories'],
        ], 'category_id');

        $this->assertSame('', $result);
    }

    public function test_no_fk_fields_emits_no_renderer_output_at_all(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'type' => 'text', 'isFk' => false],
            ['key' => 'description', 'sortable' => true, 'type' => 'text', 'isFk' => false],
        ], 'name');

        $this->assertSame('', $result);
        $this->assertStringNotContainsString('RelatedRecordLink', $result);
    }

    public function test_real_location_types_fixture_has_no_fk_fields_and_emits_no_related_record_link(): void
    {
        // LocationTypesModule.json (see PhpUnitTestGeneratorTest's fixture) has
        // zero FK columns -- name/code/color are all plain strings -- confirmed
        // by inspection before reaching for a different fixture for the "has an
        // FK" cases above. Proves the isFk branch never fires for a module that
        // genuinely has none.
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config);

        $fields = $config['features']['frontend']['list']['fields'];
        foreach ($fields as &$field) {
            $field['isFk'] = false;
        }
        unset($field);

        $generator = $this->makeGenerator(moduleName: 'LocationTypes', config: $config);
        $result = $generator->callGenerateCustomCellRenderersFromListFields(
            $fields,
            $config['features']['frontend']['list']['primaryField']
        );

        $this->assertStringNotContainsString('RelatedRecordLink', $result);
    }

    public function test_real_locations_fixture_fk_fields_each_produce_a_related_record_link_cell(): void
    {
        // Locations/Locations/module.json is SYSTEM_SHELL's real, hand-completed
        // module config. It predates the isFk/relatedModule list-field threading
        // added to IntrospectionToConfig::buildFrontendListFields(), so its list
        // fields (FK ones hand-marked "type": "badge") are re-threaded here from
        // the fixture's own top-level columns[] (which already carries the
        // equivalent relatedModule mapping) the same way a fresh
        // `make:module --force` run would today -- proving the relation names/
        // related-module targets asserted below (location_type -> LocationTypes,
        // parent -> Locations, status -> Statuses) are exactly this real
        // module's real schema, not stand-in strings.
        //
        // Note the "type" re-derivation below is load-bearing, not cosmetic:
        // generateCustomCellRenderersFromListFields() checks
        // `type === 'badge' || type === 'boolean'` BEFORE it checks `isFk` --
        // leaving "badge" in place would make every field below hit the older
        // badge/boolean branch (`{ item }`, dot-notation) instead of the new
        // isFk branch this test targets, silently passing for the wrong reason.
        // buildFrontendListFields() itself never emits "badge" (FK fields always
        // get 'text'), so this mirrors its real, current output exactly.
        $config = $this->locationsConfig();

        $columnsByName = [];
        foreach ($config['columns'] as $col) {
            $columnsByName[$col['name']] = $col;
        }

        $fields = array_map(static function (array $field) use ($columnsByName): array {
            $col  = $columnsByName[$field['key']] ?? null;
            $isFk = ($col['type'] ?? '') === 'foreignId';
            $field['isFk'] = $isFk;
            $field['relatedModule'] = $col['relatedModule'] ?? '';
            $field['type'] = $isFk ? 'text' : (($col['type'] ?? '') === 'boolean' ? 'boolean' : 'text');
            return $field;
        }, $config['features']['frontend']['list']['fields']);

        $generator = $this->makeGenerator(moduleName: 'Locations', config: $config);
        $result = $generator->callGenerateCustomCellRenderersFromListFields(
            $fields,
            $config['features']['frontend']['list']['primaryField']
        );

        $this->assertStringContainsString('<template #cell-location_type_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="LocationTypes" :uuid="row.location_type?.uuid">', $result);

        $this->assertStringContainsString('<template #cell-parent_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.parent?.uuid">', $result);

        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Statuses" :uuid="row.status?.uuid">', $result);

        // name/code/allow_sales are not FKs -- exactly 3 RelatedRecordLink cells total.
        $this->assertSame(3, substr_count($result, 'RelatedRecordLink module='));
    }

    // ─── File-upload conditional switching (v2.10.11) ──────────────────────
    //
    // Confirmed bug: a module generated with a `file-input` field rendered a
    // correct-looking FileInputField, but the form still submitted via
    // sendPostRequest (plain JSON) instead of sendFormDataRequest (multipart),
    // so a real File object never serialized to the backend and uploads
    // silently failed.
    //
    // Fix approach (CONDITIONAL switching, not universal): a form with ANY
    // field_type: 'file-input' field switches to FormData/sendFormDataRequest,
    // a separate ref<File|null> per file field (kept out of the reactive
    // `form` object), and '1'/'0' string boolean conversion (FormData-only).
    // A form with NO file-input fields must generate byte-for-byte what it
    // did before -- that's the regression guard asserted throughout below.

    public function test_has_file_input_field_detects_raw_and_mapped_field_shapes(): void
    {
        $generator = $this->makeGenerator();

        // Raw config shape (field_type key)
        $this->assertTrue($generator->callHasFileInputField([
            ['key' => 'name', 'field_type' => 'input'],
            ['key' => 'image_path', 'field_type' => 'file-input'],
        ]));

        // Mapped-to-legacy shape (field_type folded into 'type')
        $this->assertTrue($generator->callHasFileInputField([
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'image_path', 'type' => 'file-input'],
        ]));

        $this->assertFalse($generator->callHasFileInputField([
            ['key' => 'name', 'field_type' => 'input'],
            ['key' => 'is_active', 'field_type' => 'checkbox'],
        ]));

        $this->assertFalse($generator->callHasFileInputField([]));
    }

    public function test_extract_file_input_fields_returns_only_file_fields_in_order(): void
    {
        $generator = $this->makeGenerator();

        $fields = [
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
            ['key' => 'is_active', 'type' => 'checkbox'],
            ['key' => 'ota_file', 'type' => 'file-input'],
        ];

        $result = $generator->callExtractFileInputFields($fields);

        $this->assertCount(2, $result);
        $this->assertSame('apk_file', $result[0]['key']);
        $this->assertSame('ota_file', $result[1]['key']);
    }

    public function test_file_ref_name_matches_hand_written_convention(): void
    {
        $generator = $this->makeGenerator();

        // Keys already ending in a file-ish suffix keep their camelCase as-is
        // (matches MobileReleasesCreateForm.vue: apk_file -> apkFile).
        $this->assertSame('apkFile', $generator->callFileRefName('apk_file'));
        $this->assertSame('otaFile', $generator->callFileRefName('ota_file'));

        // Keys with no file-ish suffix get "File" appended so the ref name is
        // unambiguous (image_path -> imagePathFile, not imagePath).
        $this->assertSame('imagePathFile', $generator->callFileRefName('image_path'));
        $this->assertSame('file', $generator->callFileRefName('file'));
    }

    public function test_generate_request_import_line_switches_only_when_file_fields_present(): void
    {
        $generator = $this->makeGenerator();

        // Regression guard: no file fields -> exactly the pre-existing import line.
        $this->assertSame(
            'import {sendGetRequest, sendPostRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(false, 'create')
        );
        $this->assertSame(
            'import {sendGetRequest, sendPostRequest, sendPutRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(false, 'edit')
        );

        // With file fields -> sendFormDataRequest, for both create and edit.
        $this->assertSame(
            'import {sendGetRequest, sendFormDataRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(true, 'create')
        );
        $this->assertSame(
            'import {sendGetRequest, sendFormDataRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(true, 'edit')
        );
    }

    public function test_generate_file_refs_block_is_empty_when_no_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $this->assertSame('', $generator->callGenerateFileRefsBlock([]));
    }

    public function test_generate_file_refs_block_declares_a_separate_ref_per_file_field(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFileRefsBlock([
            ['key' => 'apk_file'],
            ['key' => 'ota_file'],
        ]);

        $this->assertStringContainsString('const apkFile = ref<File | null>(null)', $result);
        $this->assertStringContainsString('const otaFile = ref<File | null>(null)', $result);
    }

    public function test_generate_form_fields_skips_file_input_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFields(['fields' => [
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'image_path', 'type' => 'file-input'],
        ]]);

        $this->assertStringContainsString('name:', $result);
        $this->assertStringNotContainsString('image_path:', $result);
    }

    public function test_generate_submit_call_is_unchanged_for_create_without_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'name', 'type' => 'input'],
        ], 'create');

        $this->assertSame(
            'const response = await sendPostRequest(submitEndpoint.value, form.value)',
            $result
        );
    }

    public function test_generate_submit_call_is_unchanged_for_edit_without_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'name', 'type' => 'input'],
        ], 'edit');

        $this->assertSame(
            'const response = await sendPutRequest(submitEndpoint.value, { ...form.value })',
            $result
        );
    }

    public function test_generate_submit_call_uses_form_data_request_with_boolean_conversion_and_file_merge(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'is_active', 'type' => 'checkbox'],
            ['key' => 'apk_file', 'type' => 'file-input'],
            ['key' => 'ota_file', 'type' => 'file-input'],
        ], 'create');

        $this->assertStringContainsString('const formData: Record<string, any> = {', $result);
        $this->assertStringContainsString('...form.value,', $result);
        // Boolean -> '1'/'0' string conversion, FormData-path only.
        $this->assertStringContainsString("is_active: form.value.is_active ? '1' : '0',", $result);
        // File-input fields never appear inside the spread/boolean block.
        $this->assertStringNotContainsString('apk_file:', $result);
        // Each file ref is conditionally merged in after the object literal.
        $this->assertStringContainsString('if (apkFile.value) formData.apk_file = apkFile.value', $result);
        $this->assertStringContainsString('if (otaFile.value) formData.ota_file = otaFile.value', $result);
        $this->assertStringContainsString('const response = await sendFormDataRequest(submitEndpoint.value, formData)', $result);
        // Never falls back to the plain JSON helpers when a file field is present.
        $this->assertStringNotContainsString('sendPostRequest', $result);
        $this->assertStringNotContainsString('sendPutRequest', $result);
    }

    public function test_generate_submit_call_for_edit_with_file_fields_also_uses_form_data_request(): void
    {
        // sendFormDataRequest always issues a POST (multipart PUT bodies aren't
        // reliably parsed by PHP), but the generated edit route is registered as
        // PUT (confirmed live via `php artisan route:list` against a real
        // generated module). A plain POST to a PUT-only route 404s, so the
        // FormData payload must carry `_method: 'PUT'` -- Laravel's built-in
        // method-override spoofing (the same technique Blade's @method('PUT')
        // directive uses for native file-upload forms) then routes it correctly.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
        ], 'edit');

        $this->assertStringContainsString('sendFormDataRequest', $result);
        $this->assertStringNotContainsString('sendPutRequest', $result);
        $this->assertStringContainsString("_method: 'PUT',", $result);
    }

    public function test_generate_submit_call_for_create_with_file_fields_has_no_method_override(): void
    {
        // _method spoofing is an edit-only concern (create's route is already POST).
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
        ], 'create');

        $this->assertStringNotContainsString('_method', $result);
    }

    // ─── $slotProp parameterization (2026-07-25) ─────────────────────────────
    //
    // generatePrimaryCellContentFromListFields() and
    // generateCustomCellRenderersFromListFields() both accept a $slotProp
    // parameter (default 'row') controlling the destructured slot-prop name
    // used in generated field accessors. Callers that splice into
    // list/component.stub / list/page.stub (which wrap <ListTable>/
    // <ReportTable>) rely on the 'row' default; CustomFeatureTabComponentGenerator,
    // which splices into features/custom/tab_action.stub (wraps
    // <ListPageBareTable>), passes 'item' explicitly to both methods.

    public function test_primary_cell_content_uses_row_slot_prop_by_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGeneratePrimaryCellContentFromListFields([
            ['key' => 'name', 'data' => 'name'],
            ['key' => 'code', 'data' => 'code', 'showOnMobileSub' => true],
        ], 'name');

        $this->assertStringContainsString('{{ row.name }}', $result);
        $this->assertStringContainsString('{{ row.code || \'N/A\' }}', $result);
        $this->assertStringNotContainsString('item.', $result);
    }

    public function test_primary_cell_content_uses_item_slot_prop_when_passed_explicitly(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGeneratePrimaryCellContentFromListFields([
            ['key' => 'name', 'data' => 'name'],
            ['key' => 'code', 'data' => 'code', 'showOnMobileSub' => true],
        ], 'name', 'item');

        $this->assertStringContainsString('{{ item.name }}', $result);
        $this->assertStringContainsString('{{ item.code || \'N/A\' }}', $result);
        $this->assertStringNotContainsString('row.', $result);
    }

    public function test_custom_cell_renderers_use_row_slot_prop_by_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('{ row }', $result);
        $this->assertStringContainsString('row.is_active', $result);
        $this->assertStringNotContainsString('item.', $result);
        $this->assertStringNotContainsString('{ item }', $result);
    }

    public function test_custom_cell_renderers_use_item_slot_prop_when_passed_explicitly(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name', 'item');

        $this->assertStringContainsString('{ item }', $result);
        $this->assertStringContainsString('item.is_active', $result);
        $this->assertStringNotContainsString('row.', $result);
        $this->assertStringNotContainsString('{ row }', $result);
    }

    public function test_generate_field_for_file_input_binds_v_model_to_separate_ref_not_form_object(): void
    {
        // This requires a real stub file on disk (getStubPath/getTemplateContent),
        // so build the generator against the real package template directory.
        $generator = $this->makeGenerator(config: [
            'id_type' => 'autoincrement',
        ]);

        $result = $generator->callGenerateField([
            'key' => 'image_path',
            'name' => 'image_path',
            'type' => 'file-input',
            'label' => 'Image',
        ]);

        $this->assertStringContainsString('v-model="imagePathFile"', $result);
        $this->assertStringNotContainsString('v-model="form.image_path"', $result);
    }

    /**
     * Bug (fixed in v2.13.1): mapNewFormFieldsToLegacy() unconditionally set
     * $mappedField['options'] = $field['splashKey'] ?? Str::plural($key)
     * BEFORE copying over the remaining original field properties. Since
     * static-options (enum) fields always carry splashKey as an empty string
     * (not null/unset), `??` doesn't fall through to Str::plural($key) -- it
     * kept the empty string. That pre-filled 'options' key then made the
     * "preserve all additional properties" copy loop skip the field's real
     * inline options array entirely (it only copies props NOT already set),
     * so the rich options array from IntrospectionToConfig was silently
     * dropped on the floor. generateField() then fell into its splash-key
     * fallback branch and rendered `:options="splash."` -- malformed Vue that
     * broke the create/edit form for every module with an enum column.
     *
     * @see BaseComponentGenerator::mapNewFormFieldsToLegacy()
     */
    public function test_map_new_form_fields_to_legacy_preserves_inline_options_array_for_enum_field(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'price_tier',
                'label' => 'Price Tier',
                'placeholder' => 'Enter Price Tier',
                'required' => true,
                'splashKey' => '',
                'field_type' => 'select',
                'type' => 'text',
                'options' => [
                    ['id' => 'standard', 'name' => 'Standard'],
                    ['id' => 'premium', 'name' => 'Premium'],
                    ['id' => 'wholesale', 'name' => 'Wholesale'],
                ],
                'option_label' => 'name',
                'option_value' => 'id',
            ],
        ]);

        $this->assertIsArray($mapped[0]['options']);
        $this->assertSame(
            ['id' => 'standard', 'name' => 'Standard'],
            $mapped[0]['options'][0]
        );
        $this->assertSame('name', $mapped[0]['option_label']);
        $this->assertSame('id', $mapped[0]['option_value']);
    }

    /**
     * When there is genuinely no inline options array, the splashKey (or its
     * Str::plural($key) fallback when splashKey is empty/absent) must still
     * be used, exactly as before the fix -- this is the regression guard for
     * the non-enum / model-backed select case.
     */
    public function test_map_new_form_fields_to_legacy_falls_back_to_plural_key_when_no_splash_key_or_options(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'status',
                'label' => 'Status',
                'field_type' => 'select',
                'type' => 'select',
            ],
        ]);

        $this->assertSame('statuses', $mapped[0]['options']);
    }

    /**
     * End-to-end regression guard for the exact malformed-Vue bug: a
     * static-select enum field pushed through generateField() must render a
     * real inline options array, never the broken `splash.` string.
     */
    public function test_generate_field_for_enum_select_renders_inline_options_not_broken_splash_string(): void
    {
        $generator = $this->makeGenerator(config: [
            'id_type' => 'autoincrement',
        ]);

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'price_tier',
                'label' => 'Price Tier',
                'required' => true,
                'splashKey' => '',
                'field_type' => 'select',
                'type' => 'text',
                'options' => [
                    ['id' => 'standard', 'name' => 'Standard'],
                ],
                'option_label' => 'name',
                'option_value' => 'id',
            ],
        ]);

        $result = $generator->callGenerateField($mapped[0]);

        $this->assertStringNotContainsString(':options="splash."', $result);
        $this->assertStringContainsString("'id': 'standard'", $result);
        $this->assertStringContainsString("'name': 'Standard'", $result);
    }

    /**
     * arrayToJsObjectString() naively swaps every `"` for `'` after
     * json_encode() to produce a JS-object-literal-looking string. JSON never
     * escapes an apostrophe inside a string value (only `"` is structurally
     * special to JSON), so a value containing one -- e.g. an enum option like
     * "o'brien" -- must have its apostrophe escaped BEFORE that swap, or the
     * generated Vue attribute breaks: the apostrophe would prematurely close
     * the surrounding single-quoted JS string.
     */
    public function test_array_to_js_object_string_escapes_apostrophe_in_value(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callArrayToJsObjectString([
            ['id' => "o'brien", 'name' => "O'Brien"],
        ]);

        // The escaped apostrophe must survive as \' inside the single-quoted
        // JS string, not as a bare ' that would terminate it early.
        $this->assertStringContainsString("'id': 'o\\'brien'", $result);
        $this->assertStringContainsString("'name': 'O\\'Brien'", $result);
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

    public function callGenerateCustomCellRenderersFromListFields(array $fields, $primaryKey, string $slotProp = 'row'): string
    {
        return $this->generateCustomCellRenderersFromListFields($fields, $primaryKey, $slotProp);
    }

    public function callGeneratePrimaryCellContentFromListFields(array $fields, ?string $primaryKey = null, string $slotProp = 'row'): string
    {
        return $this->generatePrimaryCellContentFromListFields($fields, $primaryKey, $slotProp);
    }

    public function callMapViewFieldsToInformationFields(array $fields): array
    {
        return $this->mapViewFieldsToInformationFields($fields);
    }

    public function callGenerateInformationSection(string $title, string $icon, array $fields): string
    {
        return $this->generateInformationSection($title, $icon, $fields);
    }

    public function callHasFileInputField(array $fields): bool
    {
        return $this->hasFileInputField($fields);
    }

    public function callExtractFileInputFields(array $fields): array
    {
        return $this->extractFileInputFields($fields);
    }

    public function callFileRefName(string $key): string
    {
        return $this->fileRefName($key);
    }

    public function callGenerateRequestImportLine(bool $hasFileFields, string $formType): string
    {
        return $this->generateRequestImportLine($hasFileFields, $formType);
    }

    public function callGenerateFileRefsBlock(array $fileFields): string
    {
        return $this->generateFileRefsBlock($fileFields);
    }

    public function callGenerateFormFields(array $config): string
    {
        return $this->generateFormFields($config);
    }

    public function callGenerateSubmitCall(array $fields, string $formType): string
    {
        return $this->generateSubmitCall($fields, $formType);
    }

    public function callGenerateField(array $field): string
    {
        return $this->generateField($field);
    }

    public function callMapNewFormFieldsToLegacy(array $fields): array
    {
        return $this->mapNewFormFieldsToLegacy($fields);
    }

    public function callArrayToJsObjectString(array $array): string
    {
        return $this->arrayToJsObjectString($array);
    }
}
