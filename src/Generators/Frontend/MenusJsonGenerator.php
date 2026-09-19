<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Writes this module's own menu entry as a small, self-contained JSON file
 * at Seeders/MenuSeederData.json (backend-side, next to this module's other
 * *SeederData.json files) instead of hand-editing a shared frontend
 * menus.json tree.
 *
 * Menu structure now lives in a real `menus` database table (self-
 * referencing parent_id + position), managed at runtime via a real admin
 * CRUD UI — a separate module, not this generator's concern. This
 * generator's ONLY job is to describe what a fresh `make:module` run thinks
 * this module's menu entry should look like; a seeder (MenusSeeder, or
 * whatever process syncs these per-module files into the `menus` table)
 * reads this file and upserts a row keyed on `module_route`.
 *
 * Deliberately stays a pure file-writer with no DB access: generator-engine
 * unit tests construct generators directly with no Laravel/DB bootstrap at
 * all, so a generator reaching for Eloquent here would break testability,
 * not just style.
 *
 * `module_route` (not title, not url) is the stable identity key downstream
 * sync keys off — this is the actual fix for a real, confirmed bug in the
 * old JSON-tree-merge design: it matched by url, so a menu entry that had
 * been hand-relocated (a different url than this generator's own default)
 * wasn't recognized on a --force regenerate and got silently duplicated.
 * Keying by module_route is correct regardless of where the row later moves
 * in the admin UI, since that UI edits the DB row directly and never touches
 * this file again.
 */
class MenusJsonGenerator extends BaseGenerator
{
    protected array $config;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->config = $config;
    }

    public function generate(): bool
    {
        $path = $this->menuSeederDataPath();
        $menuConfig = $this->resolveMenuConfig();

        if (isset($menuConfig['enabled']) && $menuConfig['enabled'] === false) {
            if (is_file($path)) {
                unlink($path);
            }
            return true;
        }

        $data = $this->buildMenuEntryData($menuConfig);

        return $this->writeFileAlways($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function menuSeederDataPath(): string
    {
        return PathManager::getBackendModulePath($this->moduleGroup, $this->moduleName) . '/Seeders/MenuSeederData.json';
    }

    /**
     * Resolve the effective menu_config for this module (blueprint-supplied
     * or the single-item default). Unchanged from the old design — a good,
     * already-tested resolver, just no longer feeding a JSON-tree merge.
     */
    protected function resolveMenuConfig(): array
    {
        $moduleName = $this->moduleName;

        return $this->config['menu_config'] ?? $this->config['features']['menu_config'] ?? [
            'enabled' => true,
            'section' => $this->getSectionIdForGroup($this->moduleGroup),
            'items' => [
                [
                    'title' => $this->humanize($moduleName),
                    'url' => '/' . $this->toKebabCase($moduleName) . '/list',
                    'icon' => $this->config['icon'] ?? $this->getModuleIcon($moduleName),
                    'permission' => "{$moduleName}.list"
                ]
            ]
        ];
    }

    /**
     * Build the flat file's full content: this module's own entry (title/
     * url/icon/permission/children) plus its identity (module_route) and
     * placement hint (section) for the downstream DB-sync step.
     */
    protected function buildMenuEntryData(array $menuConfig): array
    {
        $item = $this->createMenuItem($menuConfig);
        $section = $menuConfig['section'] ?? $this->getSectionIdForGroup($this->moduleGroup);

        return [
            'module_route' => $this->toKebabCase($this->moduleName),
            'title' => $item['title'],
            'url' => $item['url'],
            'icon' => $item['icon'],
            'permission' => $item['permission'],
            'section' => $section,
            'children' => $item['children'] ?? [],
        ];
    }

    /**
     * Create menu item for the module
     */
    protected function createMenuItem(array $menuConfig): array
    {
        // If user configured specific items via the menu builder, use those
        if (!empty($menuConfig['items'])) {
            return $this->createFromConfigItems($menuConfig);
        }

        $nested = $menuConfig['nested'] ?? false;

        if ($nested) {
            return $this->createNestedMenuItem($menuConfig);
        }

        return $this->createSimpleMenuItem($menuConfig);
    }

    /**
     * Create menu item from user-configured items (supports multi-level nesting)
     */
    protected function createFromConfigItems(array $menuConfig): array
    {
        $moduleName = $this->moduleName;
        $items = $menuConfig['items'];

        // Single item without children — create a simple link
        if (count($items) === 1 && empty($items[0]['children'])) {
            $item = $items[0];
            return [
                'title' => $item['title'] ?? $this->humanize($moduleName),
                'url' => $this->normalizeUrl($item['url'] ?? '/' . $this->toKebabCase($moduleName) . '/list'),
                'icon' => $item['icon'] ?? $this->getModuleIcon($moduleName),
                'permission' => $item['permission'] ?? "{$moduleName}.list",
            ];
        }

        // Multiple items or items with children — create a parent with subitems
        $firstItem = $items[0];
        $menuItem = [
            'title' => $firstItem['title'] ?? $this->humanize($moduleName),
            'url' => $this->normalizeUrl($firstItem['url'] ?? '#'),
            'icon' => $firstItem['icon'] ?? $this->getModuleIcon($moduleName),
            'permission' => $firstItem['permission'] ?? "{$moduleName}.list",
        ];

        if (!empty($firstItem['children'])) {
            $menuItem['children'] = $this->convertChildrenToItems($firstItem['children']);
        }

        return $menuItem;
    }

    /**
     * Recursively convert children array to the flat children shape this
     * file uses (was: nested `items` shape inside menus.json's own tree).
     */
    protected function convertChildrenToItems(array $children): array
    {
        $items = [];
        foreach ($children as $child) {
            $item = [
                'title' => $child['title'] ?? '',
                'url' => $this->normalizeUrl($child['url'] ?? '#'),
                'icon' => $child['icon'] ?? 'ArrowRight',
                'permission' => $child['permission'] ?? null,
            ];

            if (!empty($child['children'])) {
                $item['children'] = $this->convertChildrenToItems($child['children']);
            }

            $items[] = $item;
        }
        return $items;
    }

    /**
     * Create simple menu item
     */
    protected function createSimpleMenuItem(array $menuConfig): array
    {
        $moduleName = $this->moduleName;

        return [
            'title' => $this->humanize($moduleName),
            'url' => '/' . $this->toKebabCase($moduleName) . '/list',
            'icon' => $menuConfig['icon'] ?? $this->getModuleIcon($moduleName),
            'permission' => $menuConfig['permission'] ?? "{$moduleName}.list"
        ];
    }

    /**
     * Create nested menu item with subitems
     */
    protected function createNestedMenuItem(array $menuConfig): array
    {
        $moduleName = $this->moduleName;
        $kebabName = $this->toKebabCase($moduleName);
        // "All X" is list-style text (plural, like the top-level menu label);
        // "Create X" is action text (singular, matching FrontendLocaleGenerator's
        // create_btn / the "Create Role" / "Create User" convention).
        $pluralLabel   = $this->humanize($moduleName);
        $singularLabel = $this->humanize(Str::singular($moduleName));
        // An explicitly configured icon applies to the whole nested group
        // (parent and its "All X" / "Create X" subitems alike), same as the
        // other emission paths honour $item['icon']/$menuConfig['icon'] first.
        $resolvedIcon = $menuConfig['icon'] ?? $this->getModuleIcon($moduleName);

        $subitems = [
            [
                'title' => "All {$pluralLabel}",
                'url' => "/{$kebabName}/list",
                'icon' => $resolvedIcon,
                'permission' => "{$moduleName}.list"
            ],
            [
                'title' => "Create {$singularLabel}",
                'url' => "/{$kebabName}/create",
                'icon' => $resolvedIcon,
                'permission' => "{$moduleName}.create"
            ]
        ];

        return [
            'title' => $pluralLabel,
            'url' => '#',
            'icon' => $resolvedIcon,
            'permission' => ["{$moduleName}.list", "{$moduleName}.create"],
            'children' => $subitems
        ];
    }

    /**
     * Ensure a URL starts with a leading slash (absolute path for Vue Router)
     */
    protected function normalizeUrl(string $url): string
    {
        if ($url === '' || $url === '#') {
            return $url;
        }
        if (!str_starts_with($url, '/') && !str_starts_with($url, 'http')) {
            $url = '/' . $url;
        }
        // Append /list when URL is a bare module path (e.g. "/suppliers" → "/suppliers/list")
        // External URLs, query strings, and URLs already pointing at a known action are left alone.
        if (!str_starts_with($url, 'http') && preg_match('#^/[a-z0-9][a-z0-9-]*$#i', $url)) {
            $url .= '/list';
        }
        return $url;
    }

    /**
     * Convert module name to kebab-case for URLs
     */
    protected function toKebabCase(string $string): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
    }

    /**
     * Get module icon based on name
     */
    protected function getModuleIcon(string $moduleName): string
    {
        // Exact overrides for names whose sensible icon isn't obviously
        // derivable from a word-stem in the module name itself. Kept
        // deliberately small — everything else is resolved by the stem
        // heuristic below.
        $exactMap = [
            'Dashboard' => 'House',
            'Reports'   => 'ChartBar',
            'Statuses'  => 'CheckCircle',
        ];

        if (isset($exactMap[$moduleName])) {
            return $exactMap[$moduleName];
        }

        return $this->guessIconFromName($moduleName);
    }

    /**
     * Derive a sensible icon from the module name via ordered word-stem
     * matching (first match wins). Every icon name below has been verified
     * to exist in lucide-vue-next (checked against the consuming FRONTEND's
     * node_modules/lucide-vue-next dist type definitions).
     */
    protected function guessIconFromName(string $moduleName): string
    {
        $needle = strtolower($moduleName);

        $stemMap = [
            'image'        => 'Image',
            'photo'        => 'Image',
            'picture'      => 'Image',
            'gallery'      => 'Image',
            'media'        => 'Image',
            'price'        => 'Banknote',
            'payment'      => 'Banknote',
            'invoice'      => 'Banknote',
            'billing'      => 'Banknote',
            'categor'      => 'Tag',
            'type'         => 'Tag',
            'tag'          => 'Tag',
            'permission'   => 'Lock',
            'role'         => 'Shield',
            'user'         => 'User',
            'person'       => 'User',
            'people'       => 'User',
            'location'     => 'MapPin',
            'ward'         => 'MapPin',
            'countr'       => 'MapPin',
            'address'      => 'MapPin',
            'region'       => 'MapPin',
            'notification' => 'Bell',
            'broadcast'    => 'Megaphone',
            'message'      => 'MessagesSquare',
            'log'          => 'ClipboardList',
            'setting'      => 'Settings',
            'config'       => 'Settings',
            'mobile'       => 'Smartphone',
            'release'      => 'Smartphone',
            'queue'        => 'Server',
            'job'          => 'Server',
            'translation'  => 'Languages',
            'language'     => 'Languages',
            'trash'        => 'Trash2',
            'status'       => 'CheckCircle',
            'order'        => 'ShoppingCart',
            'cart'         => 'ShoppingCart',
            'product'      => 'Package',
            'item'         => 'Package',
            'stock'        => 'Package',
            'inventory'    => 'Package',
            'store'        => 'Store',
            'warehouse'    => 'Warehouse',
            'group'        => 'Layers',
            'document'     => 'FileText',
            'key'          => 'Key',
            'token'        => 'Key',
            'calendar'     => 'CalendarDays',
            'schedule'     => 'CalendarDays',
            'event'        => 'CalendarDays',
        ];

        foreach ($stemMap as $stem => $icon) {
            if (str_contains($needle, $stem)) {
                return $icon;
            }
        }

        return 'File';
    }

    /**
     * Get section ID for module group
     */
    protected function getSectionIdForGroup(string $moduleGroup): string
    {
        return match($moduleGroup) {
            'Core' => 'configurations',
            'System' => 'main',
            default => 'configurations'
        };
    }
}
