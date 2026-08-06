<?php

namespace Blutrixx\GeneratorEngine\Helpers;

class BulkActionConfigNormalizer
{
    public static function normalize(array $action): array
    {
        $action['key'] = $action['key'] ?? '';
        $action['label'] = $action['label'] ?? $action['key'];
        $action['icon'] = $action['icon'] ?? '';
        $action['requiresPermission'] = $action['requiresPermission'] ?? '';
        $action['confirmMessage'] = $action['confirmMessage'] ?? '';
        $action['variant'] = $action['variant'] ?? '';
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
