<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\FkGroupDemoter;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for FkGroupDemoter::demote() -- the de-forked home for
 * the "demote FK columns pointing at skip-group tables" logic that used to
 * be duplicated (in diverging forms) across SYSTEM_SHELL's and THC_V2's
 * MakeModulesFromDb/MakeMobileModules commands. See the class docblock for
 * the Wave-3 $tableToGroupMap fix this preserves.
 */
class FkGroupDemoterTest extends TestCase
{
    /** Build a single columns()-shaped FK row with sane defaults, override as needed. */
    private function fkCol(string $name, string $foreignTable, array $overrides = []): array
    {
        return array_merge([
            'name'            => $name,
            'type'            => 'bigint',
            'normalized_type' => 'foreignId',
            'length'          => null,
            'nullable'        => false,
            'default'         => null,
            'is_fk'           => true,
            'foreign_table'   => $foreignTable,
            'foreign_column'  => 'id',
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
        ], $overrides);
    }

    private function plainCol(string $name): array
    {
        return [
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
            'morph_role'      => null,
            'morph_name'      => null,
        ];
    }

    public function test_fk_into_skip_group_table_is_demoted_to_plain_integer(): void
    {
        $columns = [$this->fkCol('status_id', 'statuses')];
        $skipTables = ['statuses' => true];

        $result = FkGroupDemoter::demote($columns, $skipTables);

        $this->assertFalse($result[0]['is_fk']);
        $this->assertNull($result[0]['foreign_table']);
        $this->assertNull($result[0]['foreign_column']);
        $this->assertSame('bigInteger', $result[0]['normalized_type']);
    }

    public function test_fk_into_named_group_table_is_left_alone(): void
    {
        $columns = [$this->fkCol('category_id', 'categories')];
        $skipTables = ['statuses' => true];

        $result = FkGroupDemoter::demote($columns, $skipTables);

        $this->assertSame($columns, $result);
    }

    public function test_non_fk_columns_are_untouched(): void
    {
        $columns = [$this->plainCol('name')];
        $skipTables = ['statuses' => true];

        $result = FkGroupDemoter::demote($columns, $skipTables);

        $this->assertSame($columns, $result);
    }

    public function test_default_table_to_group_map_demotes_unconditionally(): void
    {
        // No $tableToGroupMap passed -- reproduces the older/simpler behaviour
        // (SYSTEM_SHELL today): every FK into a skip-group table is demoted,
        // even if that table happens to already have a module on disk.
        $columns = [$this->fkCol('item_type_id', 'item_types')];
        $skipTables = ['item_types' => true];

        $result = FkGroupDemoter::demote($columns, $skipTables);

        $this->assertFalse($result[0]['is_fk']);
        $this->assertSame('bigInteger', $result[0]['normalized_type']);
    }

    public function test_table_to_group_map_preserves_fk_for_already_scaffolded_skip_group_table(): void
    {
        // Wave-3 fix: a skip-group table that already has a module on disk
        // (per ModuleScaffolder::buildTableToGroupMapFromFs()) must NOT be
        // demoted -- it still has a real module to relate to.
        $columns = [$this->fkCol('item_type_id', 'item_types')];
        $skipTables = ['item_types' => true];
        $tableToGroupMap = ['item_types' => ['group' => 'Custom', 'name' => 'ItemTypes']];

        $result = FkGroupDemoter::demote($columns, $skipTables, $tableToGroupMap);

        $this->assertSame($columns, $result);
    }

    public function test_multiple_columns_are_each_evaluated_independently(): void
    {
        $columns = [
            $this->fkCol('status_id', 'statuses'),
            $this->fkCol('category_id', 'categories'),
            $this->plainCol('name'),
        ];
        $skipTables = ['statuses' => true];

        $result = FkGroupDemoter::demote($columns, $skipTables);

        $this->assertFalse($result[0]['is_fk']);
        $this->assertTrue($result[1]['is_fk']);
        $this->assertSame('categories', $result[1]['foreign_table']);
        $this->assertSame($columns[2], $result[2]);
    }

    public function test_empty_columns_returns_empty_array(): void
    {
        $this->assertSame([], FkGroupDemoter::demote([], ['statuses' => true]));
    }
}
