<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Illuminate\Support\Str;

/**
 * Writes the module.json keys P123 gives a generator: description, label, references, search, workflows and the menu
 * icon, and a sync module's sync.reference (P126). key, tier, group, scope, the rest of sync and the rest of the menu
 * belong to the scaffolder and are never touched.
 */
final class ManifestMerger
{
    public static function merge(array $manifest, ModuleSpec $spec, ModuleResolver $modules): array
    {
        if ($spec->description() !== null) {
            $manifest['description'] = $spec->description();
        }
        $manifest['label'] = $spec->labelColumn();
        $references = [];
        foreach ($spec->foreignKeys() as $c) {
            $references[$c['name']] = ['module' => $c['relatedModule'], 'label' => $modules->labelColumn($c['relatedModule'])];
        }
        if ($spec->scope() === 'location') {
            $references['location_id'] = ['module' => 'Locations', 'label' => $modules->labelColumn('Locations')];
        }
        if ($references !== []) {
            $manifest['references'] = $references;
        }
        // P131: the files its records use: the Media library refuses to delete one still used
        if (($media = $spec->media()) !== []) {
            $manifest['media_references'] = [$spec->table() => array_map(fn ($n) => "{$n}_media_id", array_keys($media))];
        } else {
            unset($manifest['media_references']);
        }
        if ($spec->sync() && is_array($manifest['sync'] ?? null)) {
            // P126: what its records point at, kept on the phone read-only (always written, so sync stays an object)
            $reference = array_values(array_unique(array_filter(
                array_column($references, 'module'),
                fn ($module) => $module !== $spec->key()
            )));
            if (($l = $spec->lines()) !== null) {
                // P130: a document's item catalogue on the phone needs its items, with their price
                $reference = array_values(array_filter($reference, fn ($m) => $m !== $l['item_module']));
                $reference[] = $l['item_price'] ? ['module' => $l['item_module'], 'columns' => [$l['item_price']]] : $l['item_module'];
            }
            $manifest['sync']['reference'] = $reference;
        }
        if (($search = $spec->search()) !== null) {
            $route = $spec->route();
            $manifest['search'] = array_merge([
                'key' => $route,
                'label' => $spec->recordLabels(),
                'icon' => $spec->icon(),
                'route' => $route,
                'title' => $spec->labelColumn(),
                // no 'sub' or 'scope': they name methods of the platform's SearchPresenters / SearchScopes, which a
                // generated module cannot add; the search already follows the record's reach (SelectController)
                'rank' => 50,
            ], $search);
        }
        if ($spec->actions() !== []) {
            // P84: each action's route, permission, place, bulk and approval come from its entry; P127: apikey opens its route to keys
            $manifest['actions'] = array_map(fn (array $a) => array_filter([
                'label' => $a['label'], 'icon' => $a['icon'], 'button' => $a['button'], 'danger' => $a['danger'], 'confirm' => $a['confirm'],
                'fields' => $a['fields'] !== [], 'bulk' => $a['bulk'], 'approval' => $a['approval'], 'apikey' => $a['apikey'], 'effect' => $a['effect'],
                'from' => $a['from'], // P84 (2026-11-22): refused outside these statuses
                'reverses' => $a['reverses'], // P134: it undoes that action's last run
            ], fn ($v) => $v !== null && $v !== false), $spec->actions());
        }
        // P128: the calendar key is the generator's: written from the spec, removed when the spec has none
        if (($cal = $spec->calendar()) !== null) {
            $manifest['calendar'] = array_filter($cal);
        } else {
            unset($manifest['calendar']);
        }
        if ($spec->tabOn() !== []) {
            // P86: the tabs this module adds to its parents' pages; their routes (/{parent}/{uuid}/{key}/…) are its own
            $manifest['tab_on'] = array_map(fn (array $t) => ['key' => $t['key'], 'via' => $t['via'], 'tab' => $t['tab']], $spec->tabOn());
        }
        if (($wf = $spec->workflow()) !== null) {
            $manifest['workflows'] = [$wf['key'] => ['table' => "{$wf['key']}_statuses", 'statuses' => $wf['statuses']]];
        }
        // P133: the module's own menu entry is the spec's (icon, sidebar section, place); a tab-only module has none
        $menu = $spec->menu();
        if ($menu['hidden']) {
            $manifest['menu'] = [];
        } else {
            $entries = isset($manifest['menu']['title']) ? [$manifest['menu']] : ($manifest['menu'] ?? []);
            $entries[0] = ['title' => $entries[0]['title'] ?? $spec->recordLabels(), 'url' => $entries[0]['url'] ?? $spec->route(), 'icon' => $spec->icon(),
                'section' => $menu['section'] ?? ($entries[0]['section'] ?? null), 'position' => $menu['position'] ?? ($entries[0]['position'] ?? 50)];
            $manifest['menu'] = $entries;
        }
        if (($ms = $spec->menuSection()) !== null) {
            $manifest['menu_sections'] = [array_filter(['key' => $ms['key'], 'title' => $ms['title'], 'icon' => $ms['icon'], 'display_mode' => 'dropdown', 'position' => $ms['position'] ?? 20], fn ($v) => $v !== null)];
        } else {
            unset($manifest['menu_sections']);
        }

        return $manifest;
    }

    /** Laravel-style JSON: two-space indent, unescaped slashes and unicode. */
    public static function encode(array $manifest): string
    {
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return preg_replace_callback('/^( +)/m', fn ($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
    }
}
