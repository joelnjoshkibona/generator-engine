<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for IntrospectionToConfig::buildFrontendListFields()'s
 * `relatedModule` threading (added alongside BaseComponentGenerator's new
 * `isFk` RelatedRecordLink cell-renderer branch and
 * FrontendRoutesGenerator::generateModuleConfigExport() — all three land
 * together so a freshly-introspected FK column gets a clickable list cell
 * without any hand-editing).
 *
 * Bug this threading fixes: buildFrontendListFields() already computed
 * `isFk` per field, but never carried the FK's *target module* along with
 * it. BaseComponentGenerator::generateCustomCellRenderersFromListFields()
 * needs that target (the `module="..."` prop RelatedRecordLink requires) to
 * emit a working link — without it, the only way to make a freshly
 * generated FK list cell clickable was a manual post-generation edit (as
 * happened historically to Locations' own `location_type_id`/`parent_id`/
 * `status_id` columns — see the hand-completed Locations/Locations/module.json
 * fixture used elsewhere in this suite, whose top-level columns[] entries
 * already carry the equivalent `relatedModule` mapping this test asserts
 * buildFrontendListFields() now threads automatically).
 *
 * Fix: buildFrontendListFields() now calls the same resolveRelatedModule()
 * helper buildColumn() already used for the top-level columns[] entry, and
 * threads the result onto each list field's own 'relatedModule' key.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildFrontendListFields()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::resolveRelatedModule()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildColumn()
 */
class IntrospectionToConfigTest extends TestCase
{
    /**
     * Raw SchemaIntrospector::columns()-shaped fixture modeled on SYSTEM_SHELL's
     * real `locations` table (see
     * app/Project/Modules/Core/Locations/Locations/Migrations/..._create_locations_table.php
     * and the persisted module.json copied into tests/Fixtures/LocationsModule.json):
     * one plain string column, and one FK column (status_id -> statuses),
     * i.e. exactly the shape SchemaIntrospector::columns() emits, NOT the
     * already-built V1 config shape the *Module.json fixtures use.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columnsWithFkAndPlain(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => true,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'status_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => false,
                'default'         => '1',
                'is_fk'           => true,
                'foreign_table'   => 'statuses',
                'foreign_column'  => 'id',
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'module_name' => 'ZzzGeneratorVerifyRelated',
            'module_type' => 'Core',
            'table_name'  => 'zzz_generator_verify_related_tests',
        ];
    }

    /** @return array<string, mixed> */
    private function findListField(array $config, string $key): array
    {
        foreach ($config['features']['frontend']['list']['fields'] as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        $this->fail("No list field found for key '{$key}'.");
    }

    /** @return array<string, mixed> */
    private function findCreateField(array $config, string $key): array
    {
        foreach ($config['features']['frontend']['create']['fields'] as $field) {
            if (($field['field'] ?? null) === $key) {
                return $field;
            }
        }

        $this->fail("No create field found for field '{$key}'.");
    }

    public function test_fk_column_gets_related_module_threaded_onto_its_list_field_entry(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findListField($config, 'status_id');

        $this->assertTrue($field['isFk']);
        $this->assertSame('Statuses', $field['relatedModule']);
        $this->assertSame('status?.name', $field['data']);
    }

    /**
     * Columns shaped like SYSTEM_SHELL's real `user_locations` table: a pure
     * junction/assignment table with NO non-FK string column of its own —
     * every "identifying" field is a FK (user_id, first). Exactly the shape
     * detectPrimaryFieldFromColumns() falls back to "the first column" for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columnsWithNoNonFkStringColumn(): array
    {
        return [
            [
                'name'            => 'user_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => true,
                'foreign_table'   => 'users',
                'foreign_column'  => 'id',
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'is_primary',
                'type'            => 'tinyint',
                'normalized_type' => 'boolean',
                'length'          => null,
                'nullable'        => false,
                'default'         => '0',
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    /**
     * Bug found live while porting UserLocations: 'view.titleData' fed every
     * generated "title" of a record (details page CardTitle + document.title
     * watcher, view modal header + its 'loaded' emit, mobile details screen)
     * with the BARE primary field name, even when that field is an FK — so
     * every one of those titles rendered a raw numeric id (e.g. "3") instead
     * of the related record's name. buildFrontendListFields() already
     * resolves the correct FK-aware display path (e.g. "user?.name") for
     * this exact same column; titleData must reuse it, not the bare name.
     */
    public function test_titledata_uses_the_fk_aware_display_path_when_the_primary_field_is_itself_an_fk(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithNoNonFkStringColumn(), $this->meta());

        $this->assertSame('user_id', $config['features']['frontend']['list']['primaryField']);
        $this->assertSame('user?.name', $config['features']['frontend']['view']['titleData']);
        $this->assertNotSame('user_id', $config['features']['frontend']['view']['titleData']);
    }

    /**
     * The fix above must not change output for the overwhelmingly common
     * case: a plain scalar (non-FK) primary field's titleData stays the
     * bare column name, unchanged — there is no "?.name" path to prefer.
     */
    public function test_titledata_stays_the_bare_field_name_when_the_primary_field_is_a_plain_column(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $this->assertSame('name', $config['features']['frontend']['list']['primaryField']);
        $this->assertSame('name', $config['features']['frontend']['view']['titleData']);
    }

    public function test_non_fk_column_gets_false_isfk_and_empty_string_related_module(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findListField($config, 'name');

        $this->assertFalse($field['isFk']);
        $this->assertSame('', $field['relatedModule']);
        $this->assertSame('name', $field['data']);
    }

    public function test_related_module_uses_studly_plural_form_matching_module_directory_naming(): void
    {
        // foreign_table is plural snake_case ("statuses") -- relatedModule must
        // stay plural StudlyCase ("Statuses"), matching the on-disk module
        // directory name (Core/Statuses), NOT a singularized guess ("Status")
        // which would break registry/route lookups.
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findListField($config, 'status_id');

        $this->assertSame('Statuses', $field['relatedModule']);
        $this->assertNotSame('Status', $field['relatedModule']);
    }

    public function test_related_module_on_list_field_matches_top_level_column_entrys_related_module(): void
    {
        // Same raw $col, same resolveRelatedModule() call -- buildColumn()'s
        // top-level columns[] entry and buildFrontendListFields()'s list-field
        // entry must never disagree about which module a FK points at.
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $topLevelColumn = null;
        foreach ($config['columns'] as $col) {
            if ($col['name'] === 'status_id') {
                $topLevelColumn = $col;
                break;
            }
        }
        $this->assertNotNull($topLevelColumn, 'Expected a top-level status_id column entry.');

        $listField = $this->findListField($config, 'status_id');

        $this->assertSame($topLevelColumn['relatedModule'], $listField['relatedModule']);
        $this->assertSame('Statuses', $topLevelColumn['relatedModule']);
    }

    /**
     * Companion to the list-field threading above, added alongside
     * BaseComponentGenerator::resolveInlineCreateModule() (v2.30.0):
     * buildFrontendFormFields() (CREATE/EDIT fields) previously never
     * threaded 'relatedModule' at all, only buildFrontendListFields() did —
     * so an auto-introspected FK field on a create/edit form had nothing
     * for resolveInlineCreateModule() to read, and the default "Add New"
     * quick-create affordance could never activate for it.
     */
    public function test_fk_column_gets_related_module_threaded_onto_its_create_field_entry(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findCreateField($config, 'status_id');

        $this->assertSame('api-select', $field['field_type']);
        $this->assertSame('Statuses', $field['relatedModule']);
    }

    public function test_non_fk_column_gets_no_related_module_key_on_its_create_field_entry(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findCreateField($config, 'name');

        $this->assertArrayNotHasKey('relatedModule', $field);
    }

    public function test_fk_column_with_no_foreign_table_gets_empty_related_module_despite_isfk_flag(): void
    {
        // Defensive edge case: is_fk true but foreign_table missing/empty must
        // still resolve to '' (resolveRelatedModule()'s own guard), never a
        // notice-triggering null or a bogus guessed name.
        $columns = [
            [
                'name'            => 'owner_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => true,
                'default'         => null,
                'is_fk'           => true,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $field  = $this->findListField($config, 'owner_id');

        $this->assertTrue($field['isFk']);
        $this->assertSame('', $field['relatedModule']);
    }

    // ─── file_columns $meta hint (buildFrontendFormFields()) ───────────────
    //
    // Mirrors the has_uuid/has_creator_updater $meta hint pattern: an explicit
    // caller-supplied signal, read in buildFrontendFormFields(), that a given
    // column is a file/media upload — overriding whatever field_type would
    // otherwise be inferred from the column's own SQL type or FK-ness.

    /**
     * Columns fixture with a plain string column, a string column meant to be
     * marked via file_columns ('avatar'), and a real FK column ('status_id')
     * used to prove the hint doesn't leak onto unrelated columns.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columnsForFileColumnsTest(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => true,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'avatar',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => true,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'status_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => false,
                'default'         => '1',
                'is_fk'           => true,
                'foreign_table'   => 'statuses',
                'foreign_column'  => 'id',
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    public function test_file_columns_meta_hint_forces_field_type_to_file_input(): void
    {
        $meta = array_merge($this->meta(), ['file_columns' => ['avatar']]);
        $config = (new IntrospectionToConfig())->build($this->columnsForFileColumnsTest(), $meta);

        $field = $this->findCreateField($config, 'avatar');

        $this->assertSame('file-input', $field['field_type']);
        $this->assertSame('file', $field['type']);
    }

    public function test_column_not_in_file_columns_keeps_its_normal_inferred_type(): void
    {
        $meta = array_merge($this->meta(), ['file_columns' => ['avatar']]);
        $config = (new IntrospectionToConfig())->build($this->columnsForFileColumnsTest(), $meta);

        // Plain string column not listed in file_columns -> untouched, normal 'input'.
        $nameField = $this->findCreateField($config, 'name');
        $this->assertSame('input', $nameField['field_type']);
        $this->assertNotSame('file-input', $nameField['field_type']);

        // A real FK column NOT listed in file_columns must still get its normal FK
        // treatment -- the file_columns branch must not accidentally sweep it up.
        $statusField = $this->findCreateField($config, 'status_id');
        $this->assertSame('api-select', $statusField['field_type']);
        $this->assertNotSame('file-input', $statusField['field_type']);
        $this->assertSame('/select/statuses', $statusField['api_url']);
    }

    public function test_file_columns_hint_takes_priority_over_fk_inference(): void
    {
        // status_id IS a real FK column, but here it's explicitly listed in
        // file_columns -- the explicit hint must win because the new branch is
        // checked FIRST, before the existing isFk branch.
        $meta = array_merge($this->meta(), ['file_columns' => ['status_id']]);
        $config = (new IntrospectionToConfig())->build($this->columnsForFileColumnsTest(), $meta);

        $field = $this->findCreateField($config, 'status_id');

        $this->assertSame('file-input', $field['field_type']);
        $this->assertSame('file', $field['type']);
        $this->assertArrayNotHasKey('api_url', $field);
    }

    public function test_omitting_file_columns_meta_key_does_not_break_normal_inference(): void
    {
        // The common case for every existing caller: 'file_columns' absent from
        // $meta entirely. Must behave exactly as before -- no crash (array
        // default kicks in), no field accidentally forced to file-input.
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $nameField = $this->findCreateField($config, 'name');
        $this->assertSame('input', $nameField['field_type']);

        $statusField = $this->findCreateField($config, 'status_id');
        $this->assertSame('api-select', $statusField['field_type']);
    }

    // ─── /select/{slug} api_url must round-trip through ModuleResolver ─────
    //
    // ModuleResolver::resolve() converts a URL slug straight back to a module
    // name via Str::studly(str_replace('-', '_', $slug)) -- no pluralization
    // awareness at all. The api_url slug must therefore be the plain kebab
    // form of the real module name, not an independently re-pluralized form
    // of the table name -- those only coincidentally match when the table
    // name's last word already looks plural (e.g. "statuses", "categories").
    // Found live: a real FK to `units_of_measure` (module UnitsOfMeasure,
    // never pluralized) produced api_url "/select/units-of-measures", which
    // studly-cases back to "UnitsOfMeasures" -- a module that doesn't exist.
    // Every Create/Edit form referencing it 403'd on every picker open,
    // silently (no prior PHPUnit coverage; this is a live-HTTP-only failure
    // mode -- the picker's own "no options" state looks identical to a
    // genuinely empty table).

    /** @return array<int, array<string, mixed>> */
    private function columnsWithSingularSoundingFk(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'unit_of_measure_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => true,
                'default'         => null,
                'is_fk'           => true,
                'foreign_table'   => 'units_of_measure',
                'foreign_column'  => 'id',
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    public function test_fk_api_url_is_the_module_names_kebab_form_not_a_repluralized_table_name(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithSingularSoundingFk(), $this->meta());

        $field = $this->findCreateField($config, 'unit_of_measure_id');

        $this->assertSame('UnitsOfMeasure', $field['relatedModule']);
        $this->assertSame('/select/units-of-measure', $field['api_url']);
        $this->assertNotSame('/select/units-of-measures', $field['api_url']);
    }

    // ─── enum list-column badge rendering ──────────────────────────────────
    //
    // buildFrontendListFields() previously special-cased only 'boolean',
    // leaving an enum column to fall through to plain 'text' -- same class of
    // bug as the cast fallthrough in ModelGenerator. An enum column (real
    // example: SYSTEM_SHELL's ItemPrices.price_tier -- standard|premium|
    // wholesale) should render as a badge, like booleans already do.

    /** @return array<int, array<string, mixed>> */
    private function columnsWithEnumBooleanAndPlain(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => true,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'is_active',
                'type'            => 'tinyint(1)',
                'normalized_type' => 'boolean',
                'length'          => null,
                'nullable'        => false,
                'default'         => '1',
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'price_tier',
                'type'            => 'enum',
                'normalized_type' => 'enum',
                'length'          => null,
                'nullable'        => false,
                'default'         => 'standard',
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
                'enum_values'     => ['standard', 'premium', 'wholesale'],
            ],
        ];
    }

    public function test_enum_list_column_renders_as_badge_with_humanised_values(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithEnumBooleanAndPlain(), $this->meta());

        $field = $this->findListField($config, 'price_tier');

        $this->assertSame('badge', $field['type']);
        $this->assertSame(
            [
                ['value' => 'standard', 'label' => 'Standard'],
                ['value' => 'premium', 'label' => 'Premium'],
                ['value' => 'wholesale', 'label' => 'Wholesale'],
            ],
            $field['enum_values']
        );
    }

    public function test_enum_list_column_humanises_snake_case_values_matching_form_option_labels(): void
    {
        $columns = $this->columnsWithEnumBooleanAndPlain();
        $columns[2]['enum_values'] = ['in_progress', 'done'];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());

        $field = $this->findListField($config, 'price_tier');

        // Reuses columnLabel() -- same helper that humanises this enum's
        // Select2Field option labels in buildFrontendFormFields() -- so the
        // list badge shows "In Progress", not the raw 'in_progress' value.
        $this->assertSame(
            [
                ['value' => 'in_progress', 'label' => 'In Progress'],
                ['value' => 'done', 'label' => 'Done'],
            ],
            $field['enum_values']
        );
    }

    public function test_boolean_list_column_rendering_is_unchanged_by_enum_support(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithEnumBooleanAndPlain(), $this->meta());

        $field = $this->findListField($config, 'is_active');

        $this->assertSame('boolean', $field['type']);
        $this->assertArrayNotHasKey('enum_values', $field);
    }

    public function test_plain_string_list_column_with_no_enum_values_is_byte_for_byte_unchanged(): void
    {
        // Regression guard: a column with no enum_values at all (the
        // overwhelming majority of columns) must produce the exact same list
        // field entry as before enum-badge support existed (plus
        // 'displayField', added for FK display-field resolution -- always
        // null for a non-FK column like this one, see
        // test_fk_list_column_gets_the_correct_display_field_when_a_foreign_primary_field_is_supplied()).
        $config = (new IntrospectionToConfig())->build($this->columnsWithEnumBooleanAndPlain(), $this->meta());

        $field = $this->findListField($config, 'name');

        $this->assertSame(
            [
                'key'           => 'name',
                'title'         => 'Name',
                'sortable'      => true,
                'data'          => 'name',
                'type'          => 'text',
                'isFk'          => false,
                'relatedModule' => '',
                'displayField'  => null,
                'class'         => 'min-w-[200px]',
            ],
            $field
        );
    }

    /**
     * Bug (fixed 2026-08-02): every FK's list cell/data-path/api-select
     * option_label hardcoded the related record's display field to 'name' --
     * correct for the overwhelming majority of this codebase's lookup
     * tables, but silently wrong for a target whose real display column is
     * named something else (e.g. an `orders` table shown by `order_number`).
     * Confirmed as a real, structural gap (not a removed feature) while
     * investigating an identical symptom reported in a sibling project.
     *
     * Fix: build() now accepts an optional third $foreignPrimaryFields
     * parameter -- a map of foreign_table => that table's real primary/
     * display field, meant to be populated by the caller running
     * self::detectPrimaryFieldFromColumns() against each FK target's own
     * introspected columns before calling build(). When a target table is
     * present in the map, its real field wins; otherwise (map omitted, or
     * table simply absent from it) behaviour is byte-identical to before --
     * see the two backward-compat tests below.
     *
     * @see IntrospectionToConfig::resolveForeignDisplayField()
     * @see IntrospectionToConfig::detectPrimaryFieldFromColumns()
     */
    public function test_fk_list_column_gets_the_correct_display_field_when_a_foreign_primary_field_is_supplied(): void
    {
        $config = (new IntrospectionToConfig())->build(
            $this->columnsWithFkAndPlain(),
            $this->meta(),
            ['statuses' => 'code']
        );

        $field = $this->findListField($config, 'status_id');

        $this->assertSame('status?.code', $field['data']);
        $this->assertSame('code', $field['displayField']);

        $formField = $this->findFormField($config, 'status_id', 'create');
        $this->assertSame('code', $formField['option_label']);
    }

    public function test_fk_list_column_falls_back_to_name_when_foreign_primary_fields_map_is_omitted(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findListField($config, 'status_id');

        $this->assertSame('status?.name', $field['data']);
        $this->assertSame('name', $field['displayField']);

        $formField = $this->findFormField($config, 'status_id', 'create');
        $this->assertSame('name', $formField['option_label']);
    }

    public function test_fk_list_column_falls_back_to_name_when_foreign_table_absent_from_supplied_map(): void
    {
        // Map supplied, but doesn't mention 'statuses' -- must still fall
        // back to 'name', not throw or silently emit a null/blank display field.
        $config = (new IntrospectionToConfig())->build(
            $this->columnsWithFkAndPlain(),
            $this->meta(),
            ['some_other_table' => 'code']
        );

        $field = $this->findListField($config, 'status_id');

        $this->assertSame('status?.name', $field['data']);
        $this->assertSame('name', $field['displayField']);
    }

    /** @return array<string, mixed> */
    private function findFormField(array $config, string $key, string $formType): array
    {
        $fields = $config['features']['frontend'][$formType]['fields'] ?? [];
        foreach ($fields as $field) {
            if (($field['field'] ?? null) === $key) {
                return $field;
            }
        }

        $this->fail("No '{$formType}' form field found for key '{$key}'");
    }

    // ─── JSON-column columns get no create/edit form field ─────────────────
    //
    // buildFrontendFormFields() previously had no branch for a JSON/array
    // column at all -- it fell through to the default 'input'/'text' case,
    // same as a plain varchar. Found live against SYSTEM_SHELL's
    // UserLocations module: its roles/granted_permissions/denied_permissions
    // JSON columns ended up with a `field_type: 'input'` entry in
    // module.json despite having NO fillable control anywhere in the real,
    // hand-maintained UserLocationsCreateForm.vue/EditForm.vue (both carry
    // an explicit comment: managed exclusively via dedicated Manage Roles/
    // Manage Permissions endpoints, never as raw text input) --
    // PlaywrightTestGenerator's generated e2e spec then tried to fillField()
    // a '#granted_permissions' selector that does not exist.

    /** @return array<int, array<string, mixed>> */
    private function columnsWithJsonAndPlain(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => true,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'granted_permissions',
                'type'            => 'json',
                'normalized_type' => 'json',
                'length'          => null,
                'nullable'        => true,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    public function test_json_column_gets_no_create_form_field(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithJsonAndPlain(), $this->meta());

        foreach ($config['features']['frontend']['create']['fields'] as $field) {
            $this->assertNotSame(
                'granted_permissions',
                $field['field'] ?? null,
                'A JSON-typed column must not get a create form field -- it has no generic plain-input representation.'
            );
        }
    }

    public function test_json_column_gets_no_edit_form_field(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithJsonAndPlain(), $this->meta());

        foreach ($config['features']['frontend']['edit']['fields'] as $field) {
            $this->assertNotSame(
                'granted_permissions',
                $field['field'] ?? null,
                'A JSON-typed column must not get an edit form field -- it has no generic plain-input representation.'
            );
        }
    }

    public function test_plain_string_column_alongside_a_json_column_is_unaffected(): void
    {
        // Regression guard: the new 'json' branch must not swallow or alter
        // the byte-for-byte behaviour of a normal, unrelated scalar column.
        $config = (new IntrospectionToConfig())->build($this->columnsWithJsonAndPlain(), $this->meta());

        $nameField = $this->findFormField($config, 'name', 'create');
        $this->assertSame('input', $nameField['field_type']);
        $this->assertSame('text', $nameField['type']);
    }
}
