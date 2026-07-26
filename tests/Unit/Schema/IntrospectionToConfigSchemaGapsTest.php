<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for IntrospectionToConfig's role in closing three of
 * the four information-loss gaps: decimal precision/scale threading, index
 * routing (per-column `indexed` flag + top-level `indexes` /
 * `unique_constraints`), and enum value threading.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildColumn()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildIndexesAndUniqueConstraints()
 */
class IntrospectionToConfigSchemaGapsTest extends TestCase
{
    /** Build a single columns()-shaped row with sane defaults, override as needed. */
    private function col(string $name, array $overrides = []): array
    {
        return array_merge([
            'name'            => $name,
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 255,
            'nullable'        => true,
            'default'         => null,
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => false,
            'indexed'         => false,
            'morph_role'      => null,
            'morph_name'      => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function meta(array $overrides = []): array
    {
        return array_merge([
            'module_name' => 'ZzzGeneratorSchemaGaps',
            'module_type' => 'Core',
            'table_name'  => 'zzz_generator_schema_gaps_tests',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function findTopLevelColumn(array $config, string $name): array
    {
        foreach ($config['columns'] as $col) {
            if ($col['name'] === $name) {
                return $col;
            }
        }
        $this->fail("No top-level column found for '{$name}'.");
    }

    // ─── decimal precision/scale threading ──────────────────────────────

    public function test_decimal_column_precision_and_scale_are_threaded_onto_built_column(): void
    {
        $columns = [
            $this->col('amount', ['type' => 'decimal', 'normalized_type' => 'decimal', 'precision' => 12, 'scale' => 4]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $built  = $this->findTopLevelColumn($config, 'amount');

        $this->assertSame(12, $built['precision']);
        $this->assertSame(4, $built['scale']);
    }

    public function test_column_without_precision_scale_keys_does_not_get_them_added(): void
    {
        // The common/legacy case: a plain string column, or a decimal column
        // introspected before this fix existed -- no 'precision'/'scale' keys
        // at all in the raw column. buildColumn() must not invent them.
        $columns = [$this->col('name')];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $built  = $this->findTopLevelColumn($config, 'name');

        $this->assertArrayNotHasKey('precision', $built);
        $this->assertArrayNotHasKey('scale', $built);
    }

    // ─── enum value threading ────────────────────────────────────────────

    public function test_enum_column_values_are_threaded_onto_built_column(): void
    {
        $columns = [
            $this->col('status', ['type' => 'enum', 'normalized_type' => 'enum', 'enum_values' => ['active', 'inactive']]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $built  = $this->findTopLevelColumn($config, 'status');

        $this->assertSame('enum', $built['type']);
        $this->assertSame(['active', 'inactive'], $built['enum_values']);
    }

    public function test_column_without_enum_values_does_not_get_the_key_added(): void
    {
        $columns = [$this->col('name')];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $built  = $this->findTopLevelColumn($config, 'name');

        $this->assertArrayNotHasKey('enum_values', $built);
    }

    // ─── enum column frontend form field (buildFrontendFormFields()) ──────

    /** @return array<string, mixed> */
    private function findCreateField(array $config, string $name): array
    {
        foreach ($config['features']['frontend']['create']['fields'] as $field) {
            if (($field['field'] ?? null) === $name) {
                return $field;
            }
        }
        $this->fail("No create field found for '{$name}'.");
    }

    public function test_required_enum_column_renders_as_static_select_with_humanised_options(): void
    {
        $columns = [
            $this->col('price_tier', [
                'type' => 'enum', 'normalized_type' => 'enum', 'nullable' => false,
                'enum_values' => ['standard', 'premium', 'wholesale'],
            ]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $field  = $this->findCreateField($config, 'price_tier');

        $this->assertSame('select', $field['field_type']);
        // 'type' deliberately stays 'text', not 'select' -- BaseComponentGenerator's
        // hasSplashData()/generateSplashData() gate splash-array generation on
        // $field['type'] === 'select', which a static/inline-options field must not
        // trigger (see the FK api-select branch just above, which keeps the same
        // 'type' => 'text' convention for the identical reason).
        $this->assertSame('text', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame('name', $field['option_label']);
        $this->assertSame('id', $field['option_value']);
        $this->assertSame([
            ['id' => 'standard', 'name' => 'Standard'],
            ['id' => 'premium', 'name' => 'Premium'],
            ['id' => 'wholesale', 'name' => 'Wholesale'],
        ], $field['options']);
        // Not splash-backed: BaseComponentGenerator treats an empty/missing splashKey
        // on a 'select' field_type as "static options" (the isset($field['options'])
        // inline-array branch), matching the hand-authored boolean Yes/No precedent.
        $this->assertSame('', $field['splashKey']);
    }

    public function test_nullable_enum_column_is_not_required_but_still_renders_as_select(): void
    {
        $columns = [
            $this->col('status', [
                'type' => 'enum', 'normalized_type' => 'enum', 'nullable' => true,
                'enum_values' => ['active', 'inactive'],
            ]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $field  = $this->findCreateField($config, 'status');

        $this->assertSame('select', $field['field_type']);
        $this->assertFalse($field['required']);
        $this->assertSame([
            ['id' => 'active', 'name' => 'Active'],
            ['id' => 'inactive', 'name' => 'Inactive'],
        ], $field['options']);
    }

    public function test_enum_option_labels_humanise_snake_case_values_reusing_columnlabel(): void
    {
        // Reuses columnLabel()'s existing snake_case -> Title Case conversion (the
        // same helper that turns a column name like "created_at" into "Created")
        // rather than inventing new label logic for enum values.
        $columns = [
            $this->col('order_status', [
                'type' => 'enum', 'normalized_type' => 'enum', 'nullable' => false,
                'enum_values' => ['in_progress', 'on_hold'],
            ]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $field  = $this->findCreateField($config, 'order_status');

        $this->assertSame([
            ['id' => 'in_progress', 'name' => 'In Progress'],
            ['id' => 'on_hold', 'name' => 'On Hold'],
        ], $field['options']);
    }

    public function test_column_without_enum_values_falls_through_to_default_input_field_unchanged(): void
    {
        // Regression: a column with no enum_values (the vast majority of columns)
        // must produce byte-for-byte the same create-field entry as before this
        // enum branch existed -- no stray 'options'/enum-only keys leak in.
        $columns = [$this->col('name', ['nullable' => false])];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());
        $field  = $this->findCreateField($config, 'name');

        $this->assertSame('input', $field['field_type']);
        $this->assertSame('text', $field['type']);
        $this->assertArrayNotHasKey('options', $field);
        $this->assertArrayNotHasKey('option_label', $field);
        $this->assertArrayNotHasKey('option_value', $field);
    }

    // ─── per-column `indexed` flag ───────────────────────────────────────

    public function test_indexed_column_flag_is_threaded_through_instead_of_hardcoded_false(): void
    {
        $columns = [
            $this->col('category_id', ['normalized_type' => 'foreignId', 'is_fk' => true, 'foreign_table' => 'categories', 'indexed' => true]),
            $this->col('name', ['indexed' => false]),
        ];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());

        $this->assertTrue($this->findTopLevelColumn($config, 'category_id')['indexed']);
        $this->assertFalse($this->findTopLevelColumn($config, 'name')['indexed']);
    }

    // ─── index_groups routing ────────────────────────────────────────────

    public function test_non_unique_index_group_becomes_a_top_level_indexes_entry(): void
    {
        $columns = [$this->col('category_id', ['normalized_type' => 'foreignId', 'is_fk' => true, 'foreign_table' => 'categories', 'indexed' => true])];
        $meta = $this->meta([
            'index_groups' => [
                ['name' => 'idx_products_category_id', 'columns' => ['category_id'], 'unique' => false],
            ],
        ]);

        $config = (new IntrospectionToConfig())->build($columns, $meta);

        $this->assertSame([
            ['columns' => ['category_id'], 'unique' => false, 'name' => 'idx_products_category_id'],
        ], $config['indexes']);
        $this->assertSame([], $config['unique_constraints']);
    }

    public function test_composite_unique_index_group_becomes_a_unique_constraint_not_an_index(): void
    {
        $columns = [$this->col('tenant_id'), $this->col('sku')];
        $meta = $this->meta([
            'index_groups' => [
                ['name' => 'products_tenant_id_sku_unique', 'columns' => ['tenant_id', 'sku'], 'unique' => true],
            ],
        ]);

        $config = (new IntrospectionToConfig())->build($columns, $meta);

        $this->assertSame([], $config['indexes']);
        $this->assertSame([
            ['columns' => ['tenant_id', 'sku'], 'name' => 'products_tenant_id_sku_unique'],
        ], $config['unique_constraints']);
    }

    public function test_single_column_unique_index_group_is_dropped_since_the_column_flag_already_covers_it(): void
    {
        $columns = [$this->col('sku', ['is_unique' => true])];
        $meta = $this->meta([
            'index_groups' => [
                ['name' => 'products_sku_unique', 'columns' => ['sku'], 'unique' => true],
            ],
        ]);

        $config = (new IntrospectionToConfig())->build($columns, $meta);

        $this->assertSame([], $config['indexes']);
        $this->assertSame([], $config['unique_constraints']);
        $this->assertTrue($this->findTopLevelColumn($config, 'sku')['unique']);
    }

    public function test_conventional_system_column_indexes_are_filtered_out_of_index_groups(): void
    {
        // uuid/created_by_id/updated_by_id/deleted_at are excluded from
        // $columns entirely (SchemaIntrospector::SKIP_COLUMNS), but their raw
        // DB indexes still show up unfiltered in $meta['index_groups'] --
        // build() must drop them, since MigrationGenerator::generateSystemIndexes()
        // already emits them unconditionally.
        $columns = [$this->col('name')];
        $meta = $this->meta([
            'index_groups' => [
                ['name' => 'idx_x_uuid', 'columns' => ['uuid'], 'unique' => false],
                ['name' => 'idx_x_created_by_id', 'columns' => ['created_by_id'], 'unique' => false],
                ['name' => 'idx_x_updated_by_id', 'columns' => ['updated_by_id'], 'unique' => false],
                ['name' => 'idx_x_deleted_at', 'columns' => ['deleted_at'], 'unique' => false],
                ['name' => 'idx_x_name', 'columns' => ['name'], 'unique' => false],
            ],
        ]);

        $config = (new IntrospectionToConfig())->build($columns, $meta);

        $this->assertSame([
            ['columns' => ['name'], 'unique' => false, 'name' => 'idx_x_name'],
        ], $config['indexes']);
    }

    public function test_omitting_index_groups_meta_key_yields_empty_indexes_and_unique_constraints(): void
    {
        // The common case for every existing caller: 'index_groups' absent
        // from $meta entirely -- must behave exactly like before this fix
        // (both empty arrays, no crash).
        $columns = [$this->col('name')];

        $config = (new IntrospectionToConfig())->build($columns, $this->meta());

        $this->assertSame([], $config['indexes']);
        $this->assertSame([], $config['unique_constraints']);
    }
}
