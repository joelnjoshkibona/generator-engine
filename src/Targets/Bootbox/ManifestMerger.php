<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Illuminate\Support\Str;

/**
 * Writes the module.json keys P123 gives a generator: description, label, references, search, workflows and the menu
 * icon. key, tier, group, scope, sync and the rest of the menu belong to the scaffolder and are never touched.
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
        if (($search = $spec->search()) !== null) {
            $route = $spec->route();
            $manifest['search'] = array_merge([
                'key' => $route,
                'label' => Str::plural($spec->recordLabel()),
                'icon' => $spec->icon() ?? 'Circle',
                'route' => $route,
                'title' => $spec->labelColumn(),
                'sub' => $route,
                'scope' => $route,
                'rank' => 50,
            ], $search);
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
