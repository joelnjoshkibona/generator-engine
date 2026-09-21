<?php

namespace Blutrixx\GeneratorEngine\Helpers;

/**
 * Which foreign-key dependents of a module are its own inline_items children.
 *
 * An inline_items child has no life of its own: the parent's DeleteService removes every child row
 * unconditionally (DeleteServiceGenerator::generateInlineItemsCascadeDelete()), and the parent's edit form
 * deletes any child row not in the payload. So such a child must never BLOCK a delete. The generic FK-graph
 * dependent count in {Module}DeleteCheckService did not know that and counted them like any cross-module
 * reference, so a parent with items reported can_delete: false, and the frontend refused a delete that would
 * have succeeded cleanly. The generated DeleteCheck test encoded the wrong answer as correct.
 *
 * Both generators that need this (DeleteCheckServiceGenerator and PhpUnitTestGenerator's blocking-dependent
 * test) ask here, so they cannot disagree about which dependents are children.
 */
final class InlineItemsChildren
{
    /**
     * @param array<string, mixed> $config a module config
     * @return list<array{key?: string, child_module: string, parent_fk: string}> its inline_items entries
     */
    public static function of(array $config): array
    {
        $items = [];
        foreach ((array) ($config['inline_items'] ?? []) as $item) {
            if (is_array($item) && !empty($item['child_module']) && !empty($item['parent_fk'])) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Whether $childModule's rows, pointing back through $foreignKey, are this module's inline_items children. */
    public static function isChild(array $config, string $childModule, string $foreignKey): bool
    {
        foreach (self::of($config) as $item) {
            if ($item['child_module'] === $childModule && $item['parent_fk'] === $foreignKey) {
                return true;
            }
        }

        return false;
    }
}
