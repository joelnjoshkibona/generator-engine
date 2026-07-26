<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for SchemaIntrospector::meta()'s wiring into
 * IntrospectionToConfig::build() — confirms meta()'s return shape is
 * exactly what build() (including in strict mode, see
 * IntrospectionToConfigStrictModeTest) expects, for both an existing table
 * and a not-yet-migrated one.
 *
 * meta() is exercised here via the same anonymous-subclass technique
 * SchemaIntrospectorMetaTest uses (see that file's docblock for the full
 * rationale: this package has no illuminate/database dev dependency to
 * spin up a real Schema facade against in tests) — the live-schema-hitting
 * methods meta() composes are stubbed out with canned values, so this test
 * targets meta() -> build() wiring specifically, not the live introspection
 * those individual methods perform on their own.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::meta()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::build()
 */
class IntrospectionToConfigMetaWiringTest extends TestCase
{
    /** @return array<int, array<string, mixed>> SchemaIntrospector::columns()-shaped rows */
    private function columns(): array
    {
        return [
            [
                'name'            => 'tenant_id',
                'type'            => 'bigint',
                'normalized_type' => 'bigInteger',
                'length'          => null,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'indexed'         => true,
                'enum_values'     => null,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'sku',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'indexed'         => true,
                'enum_values'     => null,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    public function test_meta_output_feeds_build_directly_in_strict_mode_including_index_groups(): void
    {
        $introspector = new class('zzz_products') extends SchemaIntrospector {
            public function exists(): bool
            {
                return true;
            }

            public function hasTimestamps(): bool
            {
                return true;
            }

            public function hasSoftDeletes(): bool
            {
                return false;
            }

            public function hasUuid(): bool
            {
                return true;
            }

            public function hasCreatorUpdater(): bool
            {
                return true;
            }

            public function fileColumns(): array
            {
                return [];
            }

            public function indexGroups(): array
            {
                return [
                    ['name' => 'zzz_products_tenant_id_sku_unique', 'columns' => ['tenant_id', 'sku'], 'unique' => true],
                ];
            }
        };

        $meta = array_merge($introspector->meta(), [
            'module_name' => 'ZzzProducts',
            'module_type' => 'Custom',
            'table_name'  => 'zzz_products',
        ]);

        $config = IntrospectionToConfig::strict()->build($this->columns(), $meta);

        $this->assertTrue($config['has_timestamps']);
        $this->assertFalse($config['has_soft_deletes']);
        $this->assertSame([], $config['file_columns']);
        $this->assertSame([
            ['columns' => ['tenant_id', 'sku'], 'name' => 'zzz_products_tenant_id_sku_unique'],
        ], $config['unique_constraints']);
    }

    public function test_meta_output_for_a_missing_table_still_satisfies_strict_mode(): void
    {
        $introspector = new class('zzz_not_yet_migrated') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }
        };

        $meta = array_merge($introspector->meta(), [
            'module_name' => 'ZzzNotYetMigrated',
            'module_type' => 'Custom',
            'table_name'  => 'zzz_not_yet_migrated',
        ]);

        $config = IntrospectionToConfig::strict()->build([], $meta);

        $this->assertFalse($config['has_timestamps']);
        $this->assertFalse($config['has_soft_deletes']);
        $this->assertSame([], $config['indexes']);
        $this->assertSame([], $config['unique_constraints']);
    }
}
