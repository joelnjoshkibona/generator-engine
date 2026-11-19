<?php

namespace Blutrixx\GeneratorEngine\Core;

use RuntimeException;

/**
 * The order to generate a set of modules in: each after the ones it points at (relatedModule, a tab_on parent, a
 * document's item module), so a module's pickers, rules and tests find them. A module pointing at itself (a parent
 * task) is fine; two pointing at each other are a cycle and stop the run.
 */
final class ModuleOrder
{
    /**
     * @param  array<string, ModuleSpec>  $specs  by path
     * @return list<string> the paths, in order
     */
    public static function sort(array $specs): array
    {
        $byKey = [];
        foreach ($specs as $path => $spec) {
            $byKey[$spec->key()] = $path;
        }
        $needs = [];
        foreach ($specs as $path => $spec) {
            $deps = array_column($spec->columns(), 'relatedModule');
            $deps = [...$deps, ...array_keys($spec->tabOn())];
            if (($l = $spec->lines()) !== null) {
                $deps[] = $l['item_module'];
            }
            // only the ones in this set: the others exist already (Users, Locations) or the run says so
            $needs[$path] = array_values(array_unique(array_filter($deps, fn ($d) => isset($byKey[$d]) && $d !== $spec->key())));
        }

        $order = [];
        $state = [];
        $visit = function (string $path, array $trail) use (&$visit, &$order, &$state, $needs, $byKey, $specs): void {
            if (($state[$path] ?? null) === 'done') {
                return;
            }
            if (($state[$path] ?? null) === 'visiting') {
                throw new RuntimeException('These modules point at each other: '.implode(' → ', [...$trail, $specs[$path]->key()]));
            }
            $state[$path] = 'visiting';
            foreach ($needs[$path] as $dep) {
                $visit($byKey[$dep], [...$trail, $specs[$path]->key()]);
            }
            $state[$path] = 'done';
            $order[] = $path;
        };
        foreach (array_keys($specs) as $path) {
            $visit($path, []);
        }

        return $order;
    }
}
