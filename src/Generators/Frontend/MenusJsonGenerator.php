<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

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
        $menusJsonPath = PathManager::getFrontendSrcPath() . '/menus.json';
        $existingMenus = $this->loadExistingMenus($menusJsonPath);

        // Generated modules ALWAYS land in the "Main" top-level section; the
        // "Administration" section is hand-curated and never written to here.
        //
        // Previously each blueprint group was appended as its own TOP-LEVEL
        // group. DynamicNavMenu flattens top-level groups and reads only their
        // items — `group.label` is rendered nowhere — so a generated group's
        // name was silently dropped and its modules appeared as loose entries
        // at the bottom of the sidebar. Sections that DO render a heading are
        // items-with-children inside a top-level group, which is what this now
        // produces.
        $mainIndex = $this->findOrCreateMainGroup($existingMenus);

        // The helpers below all assume a two-level shape (sections -> items), so
        // they operate on Main's items: that list IS the section list.
        $this->addModuleToMenus($existingMenus[$mainIndex]['items']);

        // Final pass: derive parent item permissions from their children
        $this->computeParentPermissions($existingMenus);

        return $this->writeFileAlways($menusJsonPath, $this->encodeJsonPreservingIndent($menusJsonPath, $existingMenus));
    }

    /**
     * Resolve the effective menu_config for this module (blueprint-supplied or
     * the single-item default), without mutating any state. Shared by
     * addModuleToMenus() and the identity probe used for idempotent
     * find/replace so both always agree on what "this module's entry" is.
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
     * Build the menu item this module would currently produce, without
     * touching the menus array. Used purely as an identity probe (its url and
     * title) by the locate/prune helpers below.
     */
    protected function buildMenuItemForCurrentConfig(): array
    {
        return $this->createMenuItem($this->resolveMenuConfig());
    }

    /**
     * Add module menu items to appropriate menu sections
     */
    protected function addModuleToMenus(array &$menus): void
    {
        $moduleGroup = $this->moduleGroup;

        // Get menu configuration from module config
        $menuConfig = $this->resolveMenuConfig();

        // If this module is disabled in the menu, remove it and stop
        if (isset($menuConfig['enabled']) && $menuConfig['enabled'] === false) {
            $this->removeModuleFromMenus($menus);
            return;
        }

        // Find the appropriate menu section (work by index to keep reference valid)
        $sectionId    = $menuConfig['section'] ?? $this->getSectionIdForGroup($moduleGroup);
        $sectionLabel = $menuConfig['section_label'] ?? '';
        $sectionIndex = null;

        // Main's items mix generated sections (which carry an `id`) with
        // hand-authored entries like Dashboard/Reports that have none, so this
        // must tolerate a missing key rather than assume every sibling is a
        // generated section.
        foreach ($menus as $idx => $section) {
            if (($section['id'] ?? null) === $sectionId) {
                $sectionIndex = $idx;
                break;
            }
        }

        if ($sectionIndex === null) {
            // Create a new section and append it
            $menus[]      = $this->createMenuSection($moduleGroup, $sectionId, $sectionLabel);
            $sectionIndex = array_key_last($menus);
        } elseif ($sectionLabel !== '') {
            // Update the label of the existing section when the blueprint overrides it
            $menus[$sectionIndex]['label'] = $sectionLabel;
        }

        // Ensure items array exists
        if (!isset($menus[$sectionIndex]['items'])) {
            $menus[$sectionIndex]['items'] = [];
        }

        // Create the menu item this module currently produces
        $menuItem = $this->createMenuItem($menuConfig);

        // Idempotent write: a re-run (e.g. --force) must update this module's
        // existing node in place rather than append a second copy. Any stale
        // duplicates already sitting in the file (from before this fix, or
        // from a section change between runs) are cleaned up too, keeping the
        // earliest occurrence's position so we don't reshuffle the user's menu.
        $this->pruneDuplicateModuleMenuItems($menus, $menuItem);
        $existing = $this->locateModuleMenuItem($menus, $menuItem);

        if ($existing !== null) {
            [$existingSectionIndex, $existingItemIndex] = $existing;

            if ($existingSectionIndex === $sectionIndex) {
                // Same section: overwrite in place, preserving position/order.
                $menus[$sectionIndex]['items'][$existingItemIndex] = $menuItem;
                return;
            }

            // Section changed since the last run: drop the stale copy from
            // its old section before appending to the (new) target section.
            unset($menus[$existingSectionIndex]['items'][$existingItemIndex]);
            $menus[$existingSectionIndex]['items'] = array_values($menus[$existingSectionIndex]['items']);
        }

        $menus[$sectionIndex]['items'][] = $menuItem;
    }

    /**
     * Remove module from menus (internal method, doesn't write to file)
     */
    protected function removeModuleFromMenus(array &$menus): void
    {
        $this->pruneAllModuleMenuItems($menus, $this->buildMenuItemForCurrentConfig());
    }

    /**
     * Identity used to recognise "this module's" menu node across runs.
     *
     * Menu nodes are keyed primarily on their route (`url`): two distinct
     * modules can never legitimately share a list URL, but they CAN share a
     * display title (e.g. a custom label), so title alone is not a safe
     * merge key. A node's own url is checked first (covers custom titles/
     * urls that stay stable across regenerations), then the module's default
     * `/{kebab-name}/list` route is checked too (covers cleaning up legacy
     * entries written before this fix, whose title didn't match at all).
     *
     * Nodes with no route of their own (`url === '#'`, i.e. a nested/group
     * parent that is this module's own "All X / Create X" wrapper) are keyed
     * on the humanized module title instead, since that title is exactly
     * what this module's own nested-parent construction always produces.
     * This never merges a different module's group, because a group only
     * matches when this module's own probe item is itself a `'#'` node
     * (only true for its own nested wrapper) AND the section's node title
     * equals THIS module's humanized name.
     *
     * @return array<int, array{0:int,1:int}> list of [sectionIndex, itemIndex]
     */
    protected function collectModuleMenuItemLocations(array $menus, array $menuItem): array
    {
        $targetUrl      = $menuItem['url'] ?? '';
        $defaultUrl     = $this->normalizeUrl('/' . $this->toKebabCase($this->moduleName) . '/list');
        $humanizedTitle = $this->humanize($this->moduleName);

        $locations = [];
        foreach ($menus as $sIdx => $section) {
            if (empty($section['items'])) {
                continue;
            }
            foreach ($section['items'] as $iIdx => $item) {
                $url = $item['url'] ?? '';

                if ($url !== '' && $url !== '#') {
                    if ($url === $targetUrl || $url === $defaultUrl) {
                        $locations[] = [$sIdx, $iIdx];
                    }
                    continue;
                }

                if ($targetUrl === '#' && ($item['title'] ?? '') === $humanizedTitle) {
                    $locations[] = [$sIdx, $iIdx];
                }
            }
        }

        return $locations;
    }

    /**
     * First (earliest) location matching this module's current menu item, if any.
     *
     * @return array{0:int,1:int}|null
     */
    protected function locateModuleMenuItem(array $menus, array $menuItem): ?array
    {
        $matches = $this->collectModuleMenuItemLocations($menus, $menuItem);
        return $matches[0] ?? null;
    }

    /**
     * Remove every menu node belonging to this module.
     */
    protected function pruneAllModuleMenuItems(array &$menus, array $menuItem): void
    {
        $this->removeMenuItemLocations($menus, $this->collectModuleMenuItemLocations($menus, $menuItem));
    }

    /**
     * Remove every menu node belonging to this module EXCEPT the earliest
     * occurrence, so a subsequent locate/replace can update that one in place.
     */
    protected function pruneDuplicateModuleMenuItems(array &$menus, array $menuItem): void
    {
        $matches = $this->collectModuleMenuItemLocations($menus, $menuItem);
        if (count($matches) <= 1) {
            return;
        }
        array_shift($matches); // keep the earliest occurrence's position
        $this->removeMenuItemLocations($menus, $matches);
    }

    /**
     * Remove a set of [sectionIndex, itemIndex] locations and re-index the
     * affected sections' items arrays.
     *
     * @param array<int, array{0:int,1:int}> $locations
     */
    protected function removeMenuItemLocations(array &$menus, array $locations): void
    {
        if (empty($locations)) {
            return;
        }

        $bySection = [];
        foreach ($locations as [$sIdx, $iIdx]) {
            $bySection[$sIdx][] = $iIdx;
        }

        foreach ($bySection as $sIdx => $indices) {
            rsort($indices); // remove highest index first to keep lower indices valid
            foreach ($indices as $iIdx) {
                unset($menus[$sIdx]['items'][$iIdx]);
            }
            $menus[$sIdx]['items'] = array_values($menus[$sIdx]['items']);
        }
    }

    /**
     * Find or create menu section
     */
    protected function findOrCreateMenuSection(array &$menus, string $moduleGroup, array $menuConfig): array
    {
        $sectionId = $menuConfig['section'] ?? $this->getSectionIdForGroup($moduleGroup);
        
        // Same missing-`id` tolerance as addModuleToMenus(): Main's items list
        // contains hand-authored entries that carry no id.
        for($i = 0; $i < count($menus); $i++) {
            if (($menus[$i]['id'] ?? null) === $sectionId) {
                $targetSection = &$menus[$i];
                return $targetSection;
            }
        }
        
        // If not found, create a new section
        return $this->createMenuSection($moduleGroup, $sectionId);
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
            $menuItem['items'] = $this->convertChildrenToItems($firstItem['children']);
        }

        return $menuItem;
    }

    /**
     * Recursively convert children array to items format for menus.json
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
                $item['items'] = $this->convertChildrenToItems($child['children']);
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
            'items' => $subitems
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
        // heuristic below. (The old 12-entry map was stale: Entities,
        // EntityTypes, UserEntities, UserEntityRoles, UserEntityPermissions
        // and States don't exist in the consuming app at all; Users, Roles,
        // Permissions are now handled correctly by the heuristic itself.)
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

    /**
     * Create new menu section.
     *
     * When $explicitLabel is provided (e.g. from blueprint menu_config.sections)
     * it takes precedence over the group-name defaults.  Otherwise the label is
     * derived from $moduleGroup for backward-compatible groups (Core / System)
     * or falls back to a title-cased version of the sectionId.
     */
    protected function createMenuSection(string $moduleGroup, string $sectionId, string $explicitLabel = ''): array
    {
        $label = $explicitLabel !== ''
            ? $explicitLabel
            : $this->getSectionLabel($moduleGroup, $sectionId);

        // `title` is what DynamicNavMenu renders for a collapsible section;
        // `label` is retained because the section lookup and blueprint
        // section_label override both key off it.
        return [
            'id'         => $sectionId,
            'title'      => $label,
            'label'      => $label,
            'icon'       => 'Folder',
            'permission' => $this->getSectionPermission($moduleGroup),
            'items'      => [],
        ];
    }

    /**
     * Index of the top-level "Main" group, creating it if absent.
     *
     * Everything the generator writes goes inside this group. "Administration"
     * (and any other top-level group) is hand-curated: the generator never
     * touches it, so a module can never appear there by accident.
     */
    protected function findOrCreateMainGroup(array &$menus): int
    {
        foreach ($menus as $idx => $group) {
            $id    = strtolower((string) ($group['id'] ?? ''));
            $label = strtolower((string) ($group['label'] ?? ''));
            if ($id === 'main' || $label === 'main') {
                if (!isset($menus[$idx]['items']) || !is_array($menus[$idx]['items'])) {
                    $menus[$idx]['items'] = [];
                }
                return $idx;
            }
        }

        array_unshift($menus, [
            'id'         => 'main',
            'label'      => 'Main',
            'permission' => null,
            'items'      => [],
        ]);

        return 0;
    }

    /**
     * Get section label for module group.
     *
     * Falls back to a title-cased version of the sectionId for arbitrary groups
     * so that blueprint-derived sections like "custom" produce "Custom" rather
     * than the generic "Configurations".
     */
    protected function getSectionLabel(string $moduleGroup, string $sectionId = ''): string
    {
        return match($moduleGroup) {
            'Core'   => 'Configurations',
            'System' => 'Main Navigation',
            default  => $sectionId !== '' ? ucfirst($sectionId) : 'Configurations',
        };
    }

    /**
     * Get section permission for module group.
     * Sections have no permission gate — visibility is governed entirely
     * by the child item permissions computed in computeParentPermissions().
     */
    protected function getSectionPermission(string $moduleGroup): ?string
    {
        return null;
    }

    /**
     * Final pass: for every item (and section) that has child items, replace its
     * permission with a deduplicated array of all permissions collected from those
     * children recursively. This means a parent is visible if the user holds ANY
     * one of its descendants' permissions.
     *
     * Sections whose items all have null permissions keep null (always visible).
     */
    protected function computeParentPermissions(array &$menus): void
    {
        foreach ($menus as &$section) {
            if (empty($section['items'])) continue;

            // Compute item-level permissions first (depth-first)
            foreach ($section['items'] as &$item) {
                if (!empty($item['items'])) {
                    $permissions = $this->collectChildPermissions($item['items']);
                    $item['permission'] = !empty($permissions)
                        ? array_values(array_unique($permissions))
                        : null;
                }
            }
            unset($item);

            // Derive section permission from all its (now-updated) item permissions
            $sectionPermissions = $this->collectChildPermissions($section['items']);
            $section['permission'] = !empty($sectionPermissions)
                ? array_values(array_unique($sectionPermissions))
                : null;
        }
        unset($section);
    }

    /**
     * Recursively collect all permission strings from a list of menu items.
     */
    protected function collectChildPermissions(array $items): array
    {
        $permissions = [];
        foreach ($items as $item) {
            $perm = $item['permission'] ?? null;
            if (is_string($perm) && $perm !== '') {
                $permissions[] = $perm;
            } elseif (is_array($perm)) {
                foreach ($perm as $p) {
                    if (is_string($p) && $p !== '') {
                        $permissions[] = $p;
                    }
                }
            }
            // Recurse into nested children
            if (!empty($item['items'])) {
                $permissions = array_merge($permissions, $this->collectChildPermissions($item['items']));
            }
        }
        return $permissions;
    }

    /**
     * Load existing menus.json file
     */
    protected function loadExistingMenus(string $path): array
    {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $decoded = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        return [];
    }

    /**
     * Remove module from menus.json (for cleanup)
     */
    public function removeFromMenus(): bool
    {
        $menusJsonPath = PathManager::getFrontendSrcPath() . '/menus.json';
        $existingMenus = $this->loadExistingMenus($menusJsonPath);
        
        $mainIndex = $this->findOrCreateMainGroup($existingMenus);

        // Store original count to check if anything was removed
        $originalCount = $this->countModuleMenus($existingMenus[$mainIndex]['items']);
        
        // Remove all occurrences of this module from menus
        $this->removeModuleFromMenus($existingMenus[$mainIndex]['items']);
        
        // Check if anything was removed
        $newCount = $this->countModuleMenus($existingMenus[$mainIndex]['items']);
        
        if ($originalCount > $newCount) {
            $this->computeParentPermissions($existingMenus);
            return $this->writeFileAlways($menusJsonPath, $this->encodeJsonPreservingIndent($menusJsonPath, $existingMenus));
        }
        
        return true;
    }
    
    /**
     * Count how many menu entries exist for this module
     */
    protected function countModuleMenus(array $menus): int
    {
        return count($this->collectModuleMenuItemLocations($menus, $this->buildMenuItemForCurrentConfig()));
    }

    /**
     * Get all existing menus
     */
    public function getAllMenus(): array
    {
        $menusJsonPath = PathManager::getFrontendSrcPath() . '/menus.json';
        return $this->loadExistingMenus($menusJsonPath);
    }

    /**
     * Check if module already exists in menus
     */
    public function moduleExistsInMenus(): bool
    {
        $existingMenus = $this->getAllMenus();
        // Generated entries live inside the "Main" group, so probe that group's
        // items (the section list) rather than the top-level groups.
        $mainIndex = $this->findOrCreateMainGroup($existingMenus);

        return $this->locateModuleMenuItem($existingMenus[$mainIndex]['items'], $this->buildMenuItemForCurrentConfig()) !== null;
    }
}
