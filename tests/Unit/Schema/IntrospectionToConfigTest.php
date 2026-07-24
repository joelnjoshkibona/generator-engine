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

    public function test_fk_column_gets_related_module_threaded_onto_its_list_field_entry(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithFkAndPlain(), $this->meta());

        $field = $this->findListField($config, 'status_id');

        $this->assertTrue($field['isFk']);
        $this->assertSame('Statuses', $field['relatedModule']);
        $this->assertSame('status?.name', $field['data']);
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
}
