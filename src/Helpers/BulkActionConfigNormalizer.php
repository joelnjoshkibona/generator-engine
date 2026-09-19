<?php

namespace Blutrixx\GeneratorEngine\Helpers;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class BulkActionConfigNormalizer
{
    /**
     * The button variants the frontend's BulkAction type accepts. Anything else used to be emitted
     * verbatim into the generated list page, where `vue-tsc` rejected it (TS2769) -- and only there:
     * generation, PHPUnit and the engine's own tests all passed. Found by the super-suite fixture with
     * `variant: "outline"`, a value the docs never listed.
     */
    public const VARIANTS = ['default', 'primary', 'danger'];

    /** @var array<string, true> Keys already reported, so a normalizer called once per generator warns once. */
    private static array $warned = [];

    /**
     * @param array|string $action A `{key, label, ...}` map, or the bare-string
     *                             shorthand where the string IS the key.
     */
    public static function normalize(array|string $action): array
    {
        // The string shorthand is not in docs/features-config.md, but it is in real
        // committed config: Core/Users/Users/module.json declares
        // `"bulk_actions": ["activate", "deactivate"]`. Every consumer
        // (ListServiceGenerator, BulkActionServiceGenerator, ListPageGenerator) funnels
        // through normalizeAll(), so before this coercion an `array`-typed parameter
        // turned that module into an uncatchable TypeError on regeneration — found by
        // running the standalone frontend CLI across all 17 SYSTEM_SHELL modules at
        // once, which is the first thing that ever regenerated Users' list page.
        // Accepting both shapes mirrors DelegationConfigNormalizer, whose
        // `relatedModule` has taken string-or-array from the start.
        if (is_string($action)) {
            $action = ['key' => $action];
        }

        $action['key'] = $action['key'] ?? '';
        $action['label'] = $action['label'] ?? $action['key'];
        $action['icon'] = $action['icon'] ?? '';
        $action['requiresPermission'] = $action['requiresPermission'] ?? '';
        $action['confirmMessage'] = $action['confirmMessage'] ?? '';
        $variant = (string) ($action['variant'] ?? '');
        if ($variant !== '' && !in_array($variant, self::VARIANTS, true)) {
            $reportKey = $action['key'] . '|' . $variant;
            if (!isset(self::$warned[$reportKey])) {
                self::$warned[$reportKey] = true;
                PathManager::reportIssue(
                    "bulk_actions '{$action['key']}': variant '{$variant}' is not one of "
                    . implode(', ', self::VARIANTS) . ' and was ignored (the generated list page would not type-check).',
                    'warning'
                );
            }
            $variant = '';
        }
        $action['variant'] = $variant;
        $action['status_target'] = $action['status_target'] ?? null;

        return $action;
    }

    /**
     * Unlike ActionConfigNormalizer::normalizeAll()/DelegationConfigNormalizer::
     * normalizeAll() (plain array_map, emptiness left to an uncalled validate()),
     * this filters out empty-key entries itself. Fixes a confirmed real
     * inconsistency: of the three call sites that used to hand-roll this
     * (BulkActionServiceGenerator, the dead ListComponentGenerator, and
     * ListServiceGenerator::generateBulkActionsArray()), only the last one
     * forgot to skip an empty key. Centralizing the skip here means a fourth
     * call site can't reintroduce the bug.
     */
    public static function normalizeAll(array $actions): array
    {
        $normalized = array_map([self::class, 'normalize'], $actions);

        return array_values(array_filter(
            $normalized,
            static fn (array $action): bool => $action['key'] !== ''
        ));
    }

    public static function validate(array $action): array
    {
        $errors = [];

        if (empty($action['key'])) {
            $errors[] = 'Bulk action key is required';
        }

        return $errors;
    }
}
