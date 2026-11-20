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
        if ($spec->sync() && is_array($manifest['sync'] ?? null)) {
            // P126: what its records point at, kept on the phone read-only (always written, so sync stays an object)
            $manifest['sync']['reference'] = array_values(array_unique(array_filter(
                array_column($references, 'module'),
                fn ($module) => $module !== $spec->key()
            )));
        }
        if (($search = $spec->search()) !== null) {
            $route = $spec->route();
            $manifest['search'] = array_merge([
                'key' => $route,
                'label' => Str::plural($spec->recordLabel()),
                'icon' => $spec->icon() ?? 'Circle',
                'route' => $route,
                'title' => $spec->labelColumn(),
                // no 'sub' or 'scope': they name methods of the platform's SearchPresenters / SearchScopes, which a
                // generated module cannot add; the search already follows the record's reach (SelectController)
                'rank' => 50,
            ], $search);
        }
        if ($spec->actions() !== []) {
            // P84: each action's route, permission, place, bulk and approval come from its entry
            $manifest['actions'] = array_map(fn (array $a) => array_filter([
                'label' => $a['label'], 'icon' => $a['icon'], 'button' => $a['button'], 'danger' => $a['danger'], 'confirm' => $a['confirm'],
                'fields' => $a['fields'] !== [], 'bulk' => $a['bulk'], 'approval' => $a['approval'], 'effect' => $a['effect'],
            ], fn ($v) => $v !== null && $v !== false), $spec->actions());
        }
        if ($spec->tabOn() !== []) {
            // P86: the tabs this module adds to its parents' pages; their routes (/{parent}/{uuid}/{key}/…) are its own
            $manifest['tab_on'] = array_map(fn (array $t) => ['key' => $t['key'], 'via' => $t['via'], 'tab' => $t['tab']], $spec->tabOn());
        }
        if (($wf = $spec->workflow()) !== null) {
            $manifest['workflows'] = [$wf['key'] => ['table' => "{$wf['key']}_statuses", 'statuses' => $wf['statuses']]];
        }
        if ($spec->icon() !== null && isset($manifest['menu'][0])) {
            $manifest['menu'][0]['icon'] = $spec->icon();
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
