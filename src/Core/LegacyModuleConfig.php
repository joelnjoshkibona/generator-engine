<?php

namespace Blutrixx\GeneratorEngine\Core;

use InvalidArgumentException;

/**
 * A legacy module config (V1's stored configuration, SYSTEM_SHELL's module.json) as a Bootbox generator.json: the
 * columns and actions carried over, the defaults the legacy shape has no word for (scope, label column), and every
 * reason the Bootbox target cannot take it, all at once, so a person can fix them together.
 *
 * `features` is the legacy generator's own derived output (rules strings, field lists), not a choice: it is dropped and
 * the Bootbox target derives the same from the columns.
 */
final class LegacyModuleConfig
{
    /** Column keys the Bootbox target reads. */
    private const COLUMN_KEYS = ['name', 'type', 'length', 'nullable', 'unique', 'relatedModule', 'precision', 'scale', 'default', 'label', 'label_sw', 'form_label'];

    /**
     * A parent's delegations come back as `tabs`: each is its child's `tab_on` (P86), which convertAll() writes into the
     * child's spec; converting one module alone only notes them.
     *
     * @return array{spec: ?array, refused: list<string>, notes: list<string>, tabs: list<array{child: string, parent: string, key: string, via: string, tab: string}>} spec is null when anything is refused
     */
    public static function convert(array $legacy): array
    {
        $refused = $notes = [];
        $name = (string) ($legacy['module_name'] ?? '');
        if ($name === '') {
            return ['spec' => null, 'refused' => ['module_name is missing'], 'notes' => [], 'tabs' => []];
        }

        foreach (['morphs' => 'polymorphic relations (morphs) wait for a kit decision: link each kind with its own column', 'processors' => 'processors (before/after save hooks) are not in the Bootbox target: put the logic in the services', 'inline_items' => "inline items: a document's lines are declared with 'lines' (P115)"] as $key => $why) {
            if (! empty($legacy[$key])) {
                $refused[] = $why;
            }
        }
        // P123 (2026-11-21): a composite unique constraint is a `unique` set
        $unique = [];
        foreach ((array) ($legacy['unique_constraints'] ?? []) as $u) {
            $cols = array_values(array_filter((array) (array_is_list((array) $u) ? $u : ($u['columns'] ?? [])), fn ($c) => $c !== 'organization_id')); // [cols] or {name, columns}
            if (count($cols) >= 2) {
                $unique[] = $cols;
            }
        }
        // P131: a file column is a media column (always nullable: a phone sends a record before its file)
        $files = array_map(fn ($f) => (string) (is_array($f) ? ($f['name'] ?? '') : $f), (array) ($legacy['file_columns'] ?? [])); // names or {name}
        // P86: a delegation is the child's tab on this module
        $tabs = [];
        foreach ((array) ($legacy['delegations'] ?? []) as $dKey => $d) {
            $child = (string) (is_array($d['relatedModule'] ?? null) ? ($d['relatedModule']['name'] ?? '') : ($d['relatedModule'] ?? ''));
            $via = (string) ($d['parentContext']['filterKey'] ?? '');
            if ($child === '' || $via === '') {
                $refused[] = "delegation {$dKey}: it names no child module or no column pointing here";

                continue;
            }
            $tabs[] = ['child' => $child, 'parent' => $name, 'key' => \Illuminate\Support\Str::kebab((string) ($d['name'] ?? $dKey)), 'via' => $via, 'tab' => (string) ($d['label'] ?? \Illuminate\Support\Str::headline($child))];
        }
        if ($tabs !== []) {
            $notes[] = 'its tabs ('.implode(', ', array_column($tabs, 'child')).') are declared by those modules (tab_on): convert the set together with --dir';
        }

        $scope = 'organisation';
        $columns = [];
        foreach ($legacy['columns'] ?? [] as $c) {
            $col = array_intersect_key($c, array_flip(self::COLUMN_KEYS));
            if (isset($c['enum_values']) && ! isset($c['values'])) {
                $col['values'] = array_values((array) $c['enum_values']);
            } elseif (isset($c['values'])) {
                $col['values'] = $c['values'];
            }
            if (isset($col['length']) && is_numeric($col['length'])) {
                $col['length'] = (int) $col['length'];
            } else {
                unset($col['length']);
            }
            $col = array_filter($col, fn ($v) => $v !== null && $v !== '');
            if (($col['name'] ?? null) === 'location_id') {
                $scope = 'location'; // the record belongs to a location (P12): the skeleton adds the column
                continue;
            }
            if (in_array($col['name'] ?? '', ModuleSpec::SYSTEM_COLUMNS, true)) {
                continue; // the skeleton writes it
            }
            if (in_array($col['name'] ?? '', $files, true) || ! empty($c['file'])) {
                $col = ['name' => $col['name'], 'type' => 'media', 'nullable' => true] + array_intersect_key($col, array_flip(['label', 'label_sw', 'form_label']));
            } elseif (in_array($col['type'] ?? '', ['morphs', 'nullableMorphs'], true)) { // JSON columns: P125
                $refused[] = "column {$col['name']} ({$col['type']}) waits for a kit decision: link each kind with its own column";
            }
            $columns[] = $col;
        }

        $label = null;
        foreach ($columns as $col) {
            if (ColumnTypes::kind($col) === 'string' && empty($col['nullable']) && ! in_array($col['name'], ModuleSpec::SYSTEM_COLUMNS, true)) {
                $label = $col['name'];
                break;
            }
        }
        if ($label === null) {
            $refused[] = 'it needs a required text column to name its records by (a name, title or reference)';
        }

        $actions = [];
        foreach (self::actions($legacy['actions'] ?? []) as $key => $a) {
            [$action, $why] = self::action((string) $key, (array) $a, array_column($columns, 'name'));
            $why === null ? $actions[$key] = $action : $refused[] = $why;
        }

        // a note only for what a person set; features, id_type and the like are the legacy generator's own bookkeeping
        $set = [
            'seeder' => ! empty($legacy['seeder']['data']) ? 'its seed rows are not carried over: seed them in the module\'s seeder' : null,
            'menu_config' => ! empty($legacy['menu_config']) ? 'its menu settings are not carried over: the menu entry is the skeleton\'s' : null,
            'constants' => ! empty($legacy['constants']) ? 'its constants are not carried over: add them to the model' : null,
            'model_hand_maintained' => ! empty($legacy['model_hand_maintained']) ? 'its model was kept by hand: the Bootbox target writes the model\'s slots' : null,
            'indexes' => ! empty($legacy['indexes']) ? 'its extra indexes are not carried over: add them to the migration' : null,
        ];
        $notes = [...array_values(array_filter($set)), ...$notes];

        if ($refused !== []) {
            return ['spec' => null, 'refused' => $refused, 'notes' => $notes, 'tabs' => $tabs];
        }
        $spec = array_filter([
            'module_name' => $name,
            'module_group_name' => $legacy['module_group_name'] ?? $legacy['module_type'] ?? 'General',
            'table_name' => $legacy['table_name'] ?? null,
            'scope' => $scope,
            'label_column' => $label,
            'columns' => $columns,
            'unique' => $unique,
            'actions' => $actions,
        ], fn ($v) => $v !== null && $v !== []);
        try {
            ModuleSpec::fromArray($spec);
        } catch (InvalidArgumentException $e) {
            return ['spec' => null, 'refused' => [$e->getMessage()], 'notes' => $notes, 'tabs' => $tabs];
        }

        return ['spec' => $spec, 'refused' => [], 'notes' => $notes, 'tabs' => $tabs];
    }

    /**
     * A whole set of legacy configs: each converted, then every parent's delegations written into its child's spec as
     * `tab_on` (P86) and the child checked again. Keyed by module name, in the order given.
     *
     * @param  list<array>  $legacies
     * @return array<string, array{spec: ?array, refused: list<string>, notes: list<string>, tabs: list<array>}>
     */
    public static function convertAll(array $legacies): array
    {
        $results = [];
        foreach ($legacies as $legacy) {
            $results[(string) ($legacy['module_name'] ?? '?')] = self::convert($legacy);
        }
        foreach ($results as $parent => $r) {
            foreach ($r['tabs'] as $t) {
                $child = $results[$t['child']] ?? null;
                if ($child === null) {
                    $results[$parent]['notes'][] = "its tab {$t['child']} is not in this set";

                    continue;
                }
                $results[$parent]['notes'] = array_values(array_filter($results[$parent]['notes'], fn ($n) => ! str_starts_with($n, 'its tabs (')));
                if ($child['spec'] === null) {
                    continue; // the child's own reasons are listed under it
                }
                $results[$t['child']]['spec']['tab_on'][$parent] = ['key' => $t['key'], 'via' => $t['via'], 'tab' => $t['tab']];
            }
        }
        foreach ($results as $module => $r) {
            if ($r['spec'] === null || empty($r['spec']['tab_on'])) {
                continue;
            }
            try {
                ModuleSpec::fromArray($r['spec']);
            } catch (InvalidArgumentException $e) {
                $results[$module] = ['spec' => null, 'refused' => [$e->getMessage()], 'notes' => $r['notes'], 'tabs' => $r['tabs']];
            }
        }

        return $results;
    }

    /** The legacy actions as a map by key (they were stored as a list or a map). */
    private static function actions(array $actions): array
    {
        $out = [];
        foreach ($actions as $k => $a) {
            $key = is_string($k) ? $k : (string) ($a['name'] ?? $k);
            $out[lcfirst($key)] = $a;
        }

        return $out;
    }

    /** @return array{0: array, 1: ?string} the action in the Bootbox shape, or why it cannot be */
    private static function action(string $key, array $a, array $columnNames): array
    {
        if (! empty($a['wizard']['enabled']) || ! empty($a['confirm_step']['enabled'])) {
            return [[], "action {$key}: wizards wait for a kit decision"];
        }
        if (($a['uiType'] ?? 'modal') === 'page') {
            return [[], "action {$key}: page actions are not in the Bootbox target yet"];
        }
        if (isset($a['urlParams']) && array_values((array) $a['urlParams']) !== ['uuid'] && $a['urlParams'] !== []) {
            return [[], "action {$key}: it takes parameters beyond the record (".implode(', ', (array) $a['urlParams']).')'];
        }
        $fields = [];
        foreach ((array) ($a['fields'] ?? []) as $f) {
            $field = is_array($f) ? ($f['field'] ?? $f['name'] ?? null) : $f;
            if (! in_array($field, $columnNames, true)) {
                return [[], "action {$key}: its field ".json_encode($field).' is not a column of the module (an action asks for its own columns)'];
            }
            $fields[] = $field;
        }

        return [array_filter([
            'label' => $a['label'] ?? $key,
            'icon' => isset($a['icon']) ? preg_replace('/Icon$/', '', (string) $a['icon']) : null,
            'button' => ($a['placement'] ?? null) === 'main' ? 'header' : 'more',
            'danger' => ! empty($a['destructive']) ?: null,
            'confirm' => empty($a['hasUI']) && $fields === [] ? true : null,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== []), null];
    }
}
