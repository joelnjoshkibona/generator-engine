<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * P133: a module's icon is a real Lucide name (resources/lucide-icons.txt, Bootbox's Lucide), never the placeholder
 * Circle; a spec without one gets a guess from its name, so no module is drawn with an empty circle.
 */
final class Icons
{
    /** Words in a module's name and the icon they suggest, most specific first. */
    private const GUESSES = [
        'expense' => 'Receipt', 'payment' => 'Banknote', 'invoice' => 'FileText', 'quote' => 'FileText', 'receipt' => 'Receipt',
        'vendor' => 'Truck', 'supplier' => 'Truck', 'customer' => 'Contact', 'client' => 'Contact', 'contact' => 'Contact',
        'image' => 'Image', 'photo' => 'Image', 'price' => 'Tag', 'categor' => 'Tags', 'unit' => 'Ruler', 'tax' => 'Percent',
        'item' => 'Package', 'product' => 'Package', 'stock' => 'Boxes', 'warehouse' => 'Warehouse', 'store' => 'Store',
        'order' => 'ShoppingCart', 'term' => 'CalendarClock', 'note' => 'StickyNote', 'task' => 'ListChecks',
        'project' => 'Briefcase', 'event' => 'Calendar', 'booking' => 'Calendar', 'branch' => 'Building2',
        'location' => 'MapPin', 'staff' => 'Users', 'employee' => 'Users', 'member' => 'Users', 'user' => 'UserRound',
        'report' => 'ClipboardList', 'message' => 'Mail', 'notice' => 'Bell', 'list' => 'Layers', 'account' => 'Wallet',
    ];

    /** @var array<string, true>|null */
    private static ?array $names = null;

    public static function exists(string $name): bool
    {
        if (self::$names === null) {
            $lines = file(__DIR__.'/../../resources/lucide-icons.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$names = array_fill_keys(array_filter($lines, fn (string $l) => $l[0] !== '#'), true);
        }

        return isset(self::$names[$name]);
    }

    /** The icon a module's name suggests ('ExpenseCategories' → Tags, the more specific word winning by position). */
    public static function guess(string $moduleName): string
    {
        $words = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $moduleName) ?? $moduleName);
        $best = null;
        foreach (self::GUESSES as $word => $icon) {
            $at = strrpos($words, $word);
            // the last word names the thing (ExpenseCategories are categories), so the match furthest right wins
            if ($at !== false && ($best === null || $at > $best[0])) {
                $best = [$at, $icon];
            }
        }

        return $best[1] ?? 'Folder';
    }
}
