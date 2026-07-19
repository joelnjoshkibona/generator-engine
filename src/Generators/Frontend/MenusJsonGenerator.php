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
        $this->force = true;
    }

    public function generate(): bool
    {
        $menusJsonPath = PathManager::getFrontendSrcPath() . '/menus.json';
        $existingMenus = $this->loadExistingMenus($menusJsonPath);

        // Add new module to menus.json
        $this->addModuleToMenus($existingMenus);

        // Final pass: derive parent item permissions from their children
        $this->computeParentPermissions($existingMenus);

        return $this->writeFile($menusJsonPath, json_encode($existingMenus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Add module menu items to appropriate menu sections
     */
    protected function addModuleToMenus(array &$menus): void
    {
        $moduleName = $this->moduleName;
        $moduleGroup = $this->moduleGroup;

        // Remove existing menu entry for this module first to prevent duplicates
        $this->removeModuleFromMenus($menus);

        // Get menu configuration from module config
            $menuConfig = $this->config['menu_config'] ?? $this->config['features']['menu_config'] ?? [
                'enabled' => true,
                'section' => $this->getSectionIdForGroup($moduleGroup),
                'items' => [
                    [
                        'title' => $this->humanize($moduleName),
                        'url' => '/' . $this->toKebabCase($moduleName) . '/list',
                        'icon' => $this->getModuleIcon($moduleName),
                        'permission' => "{$moduleName}.list"
                    ]
                ]
            ];

        // If this module is disabled in the menu, remove it and stop
        if (isset($menuConfig['enabled']) && $menuConfig['enabled'] === false) {
            return;
        }

        // Find the appropriate menu section (work by index to keep reference valid)
        $sectionId    = $menuConfig['section'] ?? $this->getSectionIdForGroup($moduleGroup);
        $sectionLabel = $menuConfig['section_label'] ?? '';
        $sectionIndex = null;

        foreach ($menus as $idx => $section) {
            if ($section['id'] === $sectionId) {
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

        // Create menu item and append to the section
        $menuItem = $this->createMenuItem($menuConfig);
        $menus[$sectionIndex]['items'][] = $menuItem;
    }
    
    /**
     * Remove module from menus (internal method, doesn't write to file)
     */
    protected function removeModuleFromMenus(array &$menus): void
    {
        // Menu titles are now humanize()'d (spaced Title Case), not the raw
        // moduleName, so matching must compare against the same humanized form
        // that was written by createSimpleMenuItem()/createNestedMenuItem().
        $humanizedName = $this->humanize($this->moduleName);
        foreach ($menus as &$section) {
            if (isset($section['items'])) {
                $section['items'] = array_filter($section['items'], function($item) use ($humanizedName) {
                    return ($item['title'] ?? '') !== $humanizedName;
                });
                $section['items'] = array_values($section['items']); // Re-index
            }
        }
    }

    /**
     * Find or create menu section
     */
    protected function findOrCreateMenuSection(array &$menus, string $moduleGroup, array $menuConfig): array
    {
        $sectionId = $menuConfig['section'] ?? $this->getSectionIdForGroup($moduleGroup);
        
        for($i = 0; $i < count($menus); $i++) {
            if ($menus[$i]['id'] === $sectionId) {
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

        $subitems = [
            [
                'title' => "All {$pluralLabel}",
                'url' => "/{$kebabName}/list",
                'icon' => $this->getModuleIcon($moduleName),
                'permission' => "{$moduleName}.list"
            ],
            [
                'title' => "Create {$singularLabel}",
                'url' => "/{$kebabName}/create",
                'icon' => $this->getModuleIcon($moduleName),
                'permission' => "{$moduleName}.create"
            ]
        ];

        return [
            'title' => $pluralLabel,
            'url' => '#',
            'icon' => $menuConfig['icon'] ?? $this->getModuleIcon($moduleName),
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
        $iconMap = [
            'Users' => 'UserCog',
            'Roles' => 'Shield',
            'Permissions' => 'Lock',
            'Entities' => 'Building2',
            'EntityTypes' => 'Building',
            'Dashboard' => 'House',
            'Reports' => 'ChartBar',
            'UserEntities' => 'Users',
            'UserEntityRoles' => 'Shield',
            'UserEntityPermissions' => 'Lock',
            'Statuses' => 'CheckCircle',
            'States' => 'MapPin'
        ];
        
        return $iconMap[$moduleName] ?? 'File';
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

        return [
            'id'         => $sectionId,
            'label'      => $label,
            'permission' => $this->getSectionPermission($moduleGroup),
            'items'      => [],
        ];
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
        
        // Store original count to check if anything was removed
        $originalCount = $this->countModuleMenus($existingMenus);
        
        // Remove all occurrences of this module from menus
        $this->removeModuleFromMenus($existingMenus);
        
        // Check if anything was removed
        $newCount = $this->countModuleMenus($existingMenus);
        
        if ($originalCount > $newCount) {
            $this->computeParentPermissions($existingMenus);
            return $this->writeFile($menusJsonPath, json_encode($existingMenus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        
        return true;
    }
    
    /**
     * Count how many menu entries exist for this module
     */
    protected function countModuleMenus(array $menus): int
    {
        $humanizedName = $this->humanize($this->moduleName);
        $count = 0;
        foreach ($menus as $section) {
            if (isset($section['items'])) {
                foreach ($section['items'] as $item) {
                    if (($item['title'] ?? '') === $humanizedName) {
                        $count++;
                    }
                }
            }
        }
        return $count;
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
        $humanizedName = $this->humanize($this->moduleName);

        foreach ($existingMenus as $section) {
            if (isset($section['items'])) {
                foreach ($section['items'] as $item) {
                    if (($item['title'] ?? null) === $humanizedName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
