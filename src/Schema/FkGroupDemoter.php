<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * FkGroupDemoter
 *
 * Shared home for the "demote FK columns pointing at skip-group tables"
 * logic that used to live only inside THC_V2's MakeModulesFromDb command
 * (and, in a less complete form, duplicated inside SYSTEM_SHELL's
 * MakeModulesFromDb and MakeMobileModules commands).
 *
 * Bulk scaffold commands (`make:modules-from-db`, `make:mobile-modules`)
 * let the user route some DB tables into a "" (skip) group so no module is
 * generated for them (framework tables, tables intentionally left
 * unmanaged, etc.). A column that's a genuine DB foreign key into one of
 * those skip-group tables can't resolve to a `relatedModule`/`belongsTo()`
 * — there is no module on either side of that relation — so it must be
 * demoted to a plain integer column instead of `foreignId`.
 *
 * THC_V2 later found (Wave 3) that this needs to be conditional: a
 * skip-group table that was *already scaffolded in a prior generation
 * wave* (and therefore already has a module directory on disk, per
 * ModuleScaffolder::buildTableToGroupMapFromFs()) should NOT be demoted —
 * it still has a real module to relate to, the table just isn't part of
 * *this* wave's blueprint groups. Demoting it anyway silently discarded
 * `relatedModule`/`foreignId` detection for every such FK. SYSTEM_SHELL's
 * two copies of this logic never picked up that fix, so it only existed in
 * one of the two forks. See demote()'s $tableToGroupMap param.
 */
class FkGroupDemoter
{
    /**
     * Demote FK columns that point at a skip-group table to plain integer
     * columns (clear is_fk, foreign_table, foreign_column, and normalize
     * the type to 'bigInteger' instead of 'foreignId').
     *
     * @param array               $columns          Output of SchemaIntrospector::columns()
     * @param array<string, true> $skipTables       Tables in the "" (skip) group -- keyed by
     *                                                table name, e.g. array_flip($groups[''] ?? []).
     * @param array<string, array{group: string, name: string}> $tableToGroupMap
     *   Optional FS-registry map of table_name => existing module (see
     *   ModuleScaffolder::buildTableToGroupMapFromFs()). A skip-group table that
     *   already appears here was scaffolded in a prior wave and still has a
     *   real module -- its FK columns are left alone instead of being demoted.
     *   Defaults to an empty array, which reproduces the simpler/older
     *   behaviour of demoting every FK into a skip-group table unconditionally
     *   (i.e. the pre-Wave-3 behaviour still used by SYSTEM_SHELL today).
     * @return array
     */
    public static function demote(array $columns, array $skipTables, array $tableToGroupMap = []): array
    {
        return array_map(function (array $col) use ($skipTables, $tableToGroupMap): array {
            $foreignTable = $col['foreign_table'] ?? '';
            if ($col['is_fk'] && isset($skipTables[$foreignTable]) && !isset($tableToGroupMap[$foreignTable])) {
                $col['is_fk']           = false;
                $col['foreign_table']   = null;
                $col['foreign_column']  = null;
                $col['normalized_type'] = 'bigInteger'; // plain integer, not foreignId
            }
            return $col;
        }, $columns);
    }
}
