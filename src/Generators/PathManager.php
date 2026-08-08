<?php

namespace Blutrixx\GeneratorEngine\Generators;

use Illuminate\Support\Str;

class PathManager
{
    /** @var array|null Cached generator config array. */
    protected static ?array $config = null;

    /** @var string|null Absolute path to the project output root. */
    protected static ?string $projectRoot = null;

    /**
     * Internal project context: stored as array ['id' => ..., 'uuid' => ...].
     * The legacy $project static is kept as an alias to avoid breaking any
     * internal call sites that reference it directly via self::$project.
     *
     * @var object|array|null
     */
    protected static $project = null;

    /** @var array|null Array-based project context. */
    protected static ?array $projectContext = null;

    /** @var array Module registry: list of module config arrays keyed by name. */
    protected static array $moduleRegistry = [];

    /**
     * @var array<string, bool> Set of table names the current blueprint run
     * intentionally excludes from module generation (e.g. the "" / skip
     * group). Lets callers distinguish "this table was never meant to
     * become a module" from "this table should have a module but the
     * registry doesn't know about it yet" -- see findModuleByTable() callers
     * that need to decide whether an unresolved lookup is expected or a bug.
     */
    protected static array $skipTables = [];

    /** @var array FK graph: [target_table => [['source_table' => string, 'source_column' => string], ...]] */
    protected static array $foreignKeyGraph = [];

    /** @var callable|null Issue handler callable(string $message, string $level): void */
    protected static $issueHandler = null;

    /** @var array Template root paths keyed by 'backend', 'frontend', 'mobile'. */
    protected static array $templateRoots = [];

    protected static ?string $moduleSubGroup = null;

    /**
     * Get the generator configuration
     */
    protected static function getConfig(): array
    {
        if (self::$config === null) {
            self::$config = function_exists('config') ? config('generator', []) : [];
        }
        return self::$config;
    }

    /**
     * Set the project root path for generation
     */
    public static function setProjectRoot(string $path): void
    {
        self::$projectRoot = rtrim($path, '/');
    }

    /**
     * Get the project root path
     */
    public static function getProjectRoot(): ?string
    {
        return self::$projectRoot;
    }

    /**
     * Reset project root path
     */
    public static function resetProjectRoot(): void
    {
        self::$projectRoot = null;
        self::$project = null;
        self::$projectContext = null;
    }

    /**
     * Set the module sub-group for path injection during generation
     */
    public static function setModuleSubGroup(?string $subGroup): void
    {
        self::$moduleSubGroup = $subGroup ? Str::studly($subGroup) : null;
    }

    /**
     * Get the current module sub-group
     */
    public static function getModuleSubGroup(): ?string
    {
        return self::$moduleSubGroup;
    }

    /**
     * Reset the module sub-group
     */
    public static function resetModuleSubGroup(): void
    {
        self::$moduleSubGroup = null;
    }

    /**
     * Set the current project being generated.
     *
     * Accepts a plain array with at minimum ['id' => ..., 'uuid' => ...].
     * Use setProjectContext() for new code — this method is kept for backward
     * compatibility with call sites that already pass an array.
     *
     * @param array|null $projectArray
     */
    public static function setProject(?array $projectArray): void
    {
        if ($projectArray === null) {
            self::$project = null;
            self::$projectContext = null;
            return;
        }

        self::$projectContext = $projectArray;
        self::$project = $projectArray;
    }

    /**
     * Get the current project context array.
     *
     * Prefer getProjectContext() for new code — this method is a backward-compat alias.
     *
     * @return array|null
     */
    public static function getProject(): ?array
    {
        return self::$projectContext;
    }

    // -----------------------------------------------------------------------
    // New array-based project context API
    // -----------------------------------------------------------------------

    /**
     * Set the project context as a plain array.
     * Must hold at minimum ['id' => ..., 'uuid' => ...].
     */
    public static function setProjectContext(array $project): void
    {
        self::$projectContext = $project;
        self::$project = $project;
    }

    /**
     * Get the project context array.
     * When the context was set from an Eloquent model via setProject(), this
     * returns the extracted ['id' => ..., 'uuid' => ...] array.
     */
    public static function getProjectContext(): ?array
    {
        return self::$projectContext;
    }

    // -----------------------------------------------------------------------
    // Module registry
    // -----------------------------------------------------------------------

    /**
     * Set the module registry.
     * Each entry should have at minimum ['name' => string, 'module_type' => string].
     * Optional: 'group_name' => ?string.
     *
     * @param array $modules List of module config arrays.
     */
    public static function setModuleRegistry(array $modules): void
    {
        self::$moduleRegistry = [];
        foreach ($modules as $module) {
            $name = $module['name'] ?? null;
            if ($name !== null) {
                self::$moduleRegistry[$name] = $module;
            }
        }
    }

    /**
     * Find a module by name in the registry.
     *
     * @return array|null Module config array or null if not found.
     */
    public static function findModuleInRegistry(string $moduleName): ?array
    {
        return self::$moduleRegistry[$moduleName] ?? null;
    }


    /**
     * Find a module in the registry by its table_name.
     * Falls back to deriving table_name from module name (snake plural).
     *
     * @return array|null Module registry entry or null if not found.
     */
    public static function findModuleByTable(string $tableName): ?array
    {
        foreach (self::$moduleRegistry as $entry) {
            // Prefer explicit table_name field
            $entryTable = $entry['table_name'] ?? null;
            if ($entryTable !== null && $entryTable === $tableName) {
                return $entry;
            }
            // Fallback: derive from module name
            $name = $entry['name'] ?? '';
            if ($name !== '' && \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($name)) === $tableName) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Set the set of table names intentionally excluded from module
     * generation this run (e.g. the blueprint's "" / skip group).
     *
     * @param string[] $tableNames
     */
    public static function setSkipTables(array $tableNames): void
    {
        self::$skipTables = array_fill_keys(array_values($tableNames), true);
    }

    /**
     * Whether a table name was declared as intentionally skipped (no module
     * generated for it) via setSkipTables().
     */
    public static function isSkipTable(string $tableName): bool
    {
        return isset(self::$skipTables[$tableName]);
    }

    // -----------------------------------------------------------------------
    // FK graph
    // -----------------------------------------------------------------------

    /**
     * Set the global FK graph (source: SchemaIntrospector::globalForeignKeys()).
     * Shape: [target_table => [['source_table' => string, 'source_column' => string], ...]]
     */
    public static function setForeignKeyGraph(array $graph): void
    {
        self::$foreignKeyGraph = $graph;
    }

    /**
     * Get the global FK graph.
     */
    public static function getForeignKeyGraph(): array
    {
        return self::$foreignKeyGraph;
    }

    /**
     * Return the full module registry (all entries).
     * Keys are module names, values are module config arrays.
     *
     * @return array<string, array>
     */
    public static function getModuleRegistryAll(): array
    {
        return self::$moduleRegistry;
    }

    // -----------------------------------------------------------------------
    // Issue handler
    // -----------------------------------------------------------------------

    /**
     * Set a callable that receives issue reports.
     * Signature: function(string $message, string $level = 'warning'): void
     *
     * Pass null to reset to default (no-op / V1 fallback) behaviour.
     */
    public static function setIssueHandler(?callable $handler): void
    {
        self::$issueHandler = $handler;
    }

    /**
     * Report an issue via the registered handler.
     *
     * Calls the user-set handler if one is registered; otherwise silently no-ops.
     *
     * Public (not protected) so generators outside this class -- e.g.
     * DeleteCheckServiceGenerator, when it cannot resolve a dependent
     * module -- can surface a genuinely-unresolvable case through the same
     * channel resolveBackendModuleNamespace()/resolveFrontendImportSegment()
     * already use below, instead of inventing a second reporting path.
     */
    public static function reportIssue(string $message, string $level = 'warning'): void
    {
        if (self::$issueHandler !== null) {
            (self::$issueHandler)($message, $level);
        }
        // No handler set: silently no-op.
    }

    // -----------------------------------------------------------------------
    // Template roots
    // -----------------------------------------------------------------------

    /**
     * Set injectable template root paths.
     * Keys: 'backend', 'frontend', 'mobile' — absolute paths.
     */
    public static function setTemplateRoots(array $roots): void
    {
        self::$templateRoots = $roots;
    }

    // -----------------------------------------------------------------------
    // Session / project root
    // -----------------------------------------------------------------------

    /**
     * Set the session base path using project UUID
     */
    public static function setSessionBasePath(string $projectUuid): void
    {
        // Set the project root path based on the UUID
        // Assuming projects are stored in storage/projects/{uuid}
        $path = storage_path("projects/{$projectUuid}");
        self::setProjectRoot($path);
    }

    public static function getCurrentSessionBasePath(): ?string
    {
        return self::$projectRoot;
    }

    // -----------------------------------------------------------------------
    // Output paths
    // -----------------------------------------------------------------------

    /**
     * Get the backend output base path
     */
    public static function getBackendBasePath(): string
    {
        if (self::$projectRoot === null) {
            throw new \RuntimeException("Project root path not set. Call PathManager::setProjectRoot() first.");
        }
        return self::$projectRoot . '/BACKEND';
    }

    /**
     * Get the frontend output base path
     */
    public static function getFrontendBasePath(): string
    {
        if (self::$projectRoot === null) {
            throw new \RuntimeException("Project root path not set. Call PathManager::setProjectRoot() first.");
        }
        return self::$projectRoot . '/FRONTEND';
    }

    /**
     * Get the backend modules path
     */
    public static function getBackendModulesPath(): string
    {
        $basePath = self::getBackendBasePath();
        $modulesPath = 'app/Project/Modules';
        return $basePath . '/' . $modulesPath;
    }

    /**
     * Get the frontend modules path
     */
    public static function getFrontendModulesPath(): string
    {
        $config = self::getConfig();
        $basePath = self::getFrontendBasePath();
        $modulesPath = $config['output']['frontend']['modules_path'] ?? 'src/pages/modules';
        return $basePath . '/' . $modulesPath;
    }

    /**
     * Get the full backend module path for a specific module
     */
    public static function getBackendModulePath(string $moduleGroup, string $moduleName): string
    {
        $base = self::getBackendModulesPath() . '/' . $moduleGroup;
        if (self::$moduleSubGroup) {
            $base .= '/' . self::$moduleSubGroup;
        }
        return $base . '/' . $moduleName;
    }

    /**
     * Get the backend Feature-tests output path for the current module context.
     *
     * Mirrors SYSTEM_SHELL's own tests/Feature convention: nested under the
     * module's sub-group directory when one is set (e.g.
     * tests/Feature/Locations/LocationTypesCrudTest.php,
     * tests/Feature/Access/PermissionsCrudTest.php), or flat directly under
     * tests/Feature when no sub-group applies (e.g.
     * tests/Feature/StatusesCrudTest.php, tests/Feature/MediaCrudTest.php).
     *
     * Deliberately does NOT nest under moduleGroup (Core/System/etc.) the
     * way getBackendModulePath() does — verified against every existing
     * hand-written Feature test in SYSTEM_SHELL, none of which have a "Core"
     * (or other group) path segment; only the sub-group ever appears.
     */
    public static function getBackendTestsPath(): string
    {
        $base = self::getBackendBasePath() . '/tests/Feature';
        if (self::$moduleSubGroup) {
            $base .= '/' . self::$moduleSubGroup;
        }
        return $base;
    }

    /**
     * Get the PHP namespace matching getBackendTestsPath()'s directory
     * structure, e.g. "Tests\Feature\Locations" or, flat, "Tests\Feature".
     */
    public static function getBackendTestsNamespace(): string
    {
        $namespace = 'Tests\\Feature';
        if (self::$moduleSubGroup) {
            $namespace .= '\\' . self::$moduleSubGroup;
        }
        return $namespace;
    }

    /**
     * Resolve the full backend PHP namespace for a related module.
     *
     * Resolution order:
     *   1. Module registry (array-based, injected via setModuleRegistry)
     *   2. Project DB (user-generated modules) — only when Eloquent model exists
     *   3. default_modules.json (SYSTEM_SHELL registry: kernel + core + business defaults)
     *   4. Fallback: App\Project\Modules\Core\{Module}  (+ warning)
     *
     * Kernel modules (type=Kernel) use the flat namespace `App\Project\{Module}`
     * as supplied by registry_kernel.json — they do NOT live under `App\Project\Modules`.
     *
     * The resolver is intentionally strict — if a reference doesn't match an existing
     * module name, it falls through to the warning branch.
     */
    public static function resolveBackendModuleNamespace(string $moduleName): string
    {
        $namespace = self::resolveBackendModuleNamespaceOrNull($moduleName);
        if ($namespace !== null) {
            return $namespace;
        }

        // Last-resort fallback
        self::reportIssue(
            "Related backend module '{$moduleName}' not found in project or shell registry — fell back to App\\Project\\Modules\\Core\\{$moduleName}. The generated PHP may fail to resolve this class if '{$moduleName}' is not actually Core-type (e.g. it hasn't been scaffolded yet, such as a child module generated before its FK-target parent for inline_items). Fix: regenerate this module with `make:module <path> --force` after '{$moduleName}' has been scaffolded — namespace resolution will pick up the real module then.",
            'warning'
        );
        return "App\\Project\\Modules\\Core\\{$moduleName}";
    }

    /**
     * Same resolution chain as resolveBackendModuleNamespace() (registry,
     * then default_modules.json, then the generated project's own persisted
     * registry files) but returns null instead of silently defaulting to
     * Core\{Module} when nothing matches. Lets callers that have their own
     * fallback data (e.g. a caller-declared sub-group that predates the
     * registry being populated) or that need to fail loudly distinguish
     * "genuinely unresolved" from "resolved to Core".
     */
    public static function resolveBackendModuleNamespaceOrNull(string $moduleName): ?string
    {
        // 1. Module registry (array-based)
        $registryEntry = self::findModuleInRegistry($moduleName);
        if ($registryEntry !== null) {
            $group    = self::normalizeGroupName($registryEntry['module_type'] ?? $registryEntry['type'] ?? 'Core');
            $subGroup = isset($registryEntry['group_name']) ? Str::studly($registryEntry['group_name']) : null;
            $ns = "App\\Project\\Modules\\{$group}";
            if ($subGroup) {
                $ns .= "\\{$subGroup}";
            }
            return $ns . "\\{$moduleName}";
        }

        // 2. default_modules.json (SYSTEM_SHELL-shipped modules)
        $defaultsPath = function_exists('storage_path')
            ? storage_path('app/templates/default_modules.json')
            : null;
        if ($defaultsPath && file_exists($defaultsPath)) {
            $defaults = json_decode(file_get_contents($defaultsPath), true);
            if (is_array($defaults) && isset($defaults[$moduleName]) && is_array($defaults[$moduleName])) {
                $entry = $defaults[$moduleName];

                if (($entry['type'] ?? '') === 'Kernel' && !empty($entry['namespace'])) {
                    return $entry['namespace'];
                }

                $group    = self::normalizeGroupName($entry['type'] ?? $entry['module_type'] ?? 'Core');
                $subGroup = null;
                if (!empty($entry['group'])) {
                    $subGroup = Str::studly($entry['group']);
                } elseif (!empty($entry['module_group'])) {
                    $subGroup = Str::studly($entry['module_group']);
                }
                $ns = "App\\Project\\Modules\\{$group}";
                if ($subGroup) {
                    $ns .= "\\{$subGroup}";
                }
                return $ns . "\\{$moduleName}";
            }
        }

        // 3. The generated project's own persisted registry files
        // (registry_core.json / registry.json) — each entry already carries
        // a fully-resolved 'namespace' string, written once the module is
        // actually scaffolded. guessedModuleExists() (ModelGenerator.php)
        // already checks these same files for a GUESSED module name, but
        // only as a boolean existence check; a real-FK-derived module name
        // (the common, higher-confidence case) never got this far at all,
        // relying solely on steps 1-2 above and falling through to a wrong
        // Core\{Module} guess whenever the array registry happened to be a
        // stale/incomplete snapshot even though the target module was
        // already scaffolded on disk. Found live via the orders-suite
        // integration fixture (OrderItems -> Orders, 2026-08-08) — this
        // step does not resolve the specific "child scaffolded before its
        // parent exists anywhere yet" ordering case (nothing can, since the
        // module genuinely doesn't exist at that point in time), but it
        // does fix every case where the target module WAS already
        // scaffolded and only the in-memory registry snapshot missed it.
        try {
            foreach (['registry_core.json', 'registry.json'] as $file) {
                $registryPath = self::getBackendRegistryPath() . '/' . $file;
                if (!file_exists($registryPath)) {
                    continue;
                }
                $registry = json_decode(file_get_contents($registryPath), true);
                if (is_array($registry) && isset($registry[$moduleName]['namespace'])) {
                    return $registry[$moduleName]['namespace'];
                }
            }
        } catch (\Exception) {
            // PathManager not set up (project root not set) — fall through.
        }

        return null;
    }

    public static function resolveFrontendImportSegment(string $moduleName): string
    {
        $group    = '';
        $subGroup = null;

        // 1. Module registry (array-based)
        $registryEntry = self::findModuleInRegistry($moduleName);
        if ($registryEntry !== null) {
            $group    = strtolower(self::normalizeGroupName($registryEntry['module_type'] ?? $registryEntry['type'] ?? ''));
            // Sub-group stays PascalCase — matches getFrontendModulePath()'s convention
            // and real generated imports (e.g. `@/pages/modules/core/Locations/Locations/...`,
            // `@/pages/modules/core/Access/Roles/...`). Lowercasing it here (as this used
            // to do) produced import paths like `core/locations/...` that don't exist on
            // disk and fail to resolve at build time.
            $subGroup = isset($registryEntry['group_name']) ? Str::studly($registryEntry['group_name']) : null;

            return $subGroup
                ? "{$group}/{$subGroup}/{$moduleName}"
                : "{$group}/{$moduleName}";
        }

        // 2. Fall back to modules.json (core template modules)
        $modulesJsonPath = self::getFrontendSrcPath() . '/modules.json';
        if (file_exists($modulesJsonPath)) {
            $modulesData = json_decode(file_get_contents($modulesJsonPath), true);
            if (is_array($modulesData) && isset($modulesData[$moduleName]['path'])) {
                return ltrim(str_replace('/modules/', '', $modulesData[$moduleName]['path']), '/');
            }
        }

        // Fallback when module not found anywhere
        if (empty($group)) {
            self::reportIssue(
                "Related frontend module '{$moduleName}' not found in project or modules.json — import path could not be built. Any form/component referencing this module will fail to resolve at build time.",
                'warning'
            );
            return '';
        }
        return $subGroup
            ? "{$group}/{$subGroup}/{$moduleName}"
            : "{$group}/{$moduleName}";
    }

    /**
     * Get the full frontend module path for a specific module
     */
    public static function getFrontendModulePath(string $moduleGroup, string $moduleName): string
    {
        // Top-level group segment is lowercase by convention (e.g. "core",
        // "system", "dev" — see SYSTEM_SHELL/FRONTEND/src/pages/modules/).
        // The sub-group segment, however, is PascalCase on disk for every
        // existing nested module (e.g. core/Locations/LocationTypes,
        // core/Access/Permissions, core/Users/Users) — it is already stored
        // PascalCase via Str::studly() in setModuleContext(). Lowercasing it
        // here (as this used to do) diverges from getBackendModulePath(),
        // which preserves casing, and causes regeneration of an existing
        // nested module to write into a fresh lowercase duplicate folder
        // instead of the real PascalCase one.
        $base = self::getFrontendModulesPath() . '/' . strtolower($moduleGroup);
        if (self::$moduleSubGroup) {
            $base .= '/' . self::$moduleSubGroup;
        }
        return $base . '/' . $moduleName;
    }

    /**
     * Get the MOBILE_APP output base path
     */
    public static function getMobileAppBasePath(): string
    {
        if (self::$projectRoot === null) {
            throw new \RuntimeException("Project root path not set. Call PathManager::setProjectRoot() first.");
        }
        return self::$projectRoot . '/MOBILE_APP';
    }

    /**
     * Get the MOBILE_APP modules path
     * Structure: MOBILE_APP/resources/js/src/pages/modules
     */
    public static function getMobileAppModulesPath(): string
    {
        $basePath = self::getMobileAppBasePath();
        return $basePath . '/resources/js/src/pages/modules';
    }

    /**
     * Get the full MOBILE_APP module path for a specific module
     * Structure: MOBILE_APP/resources/js/src/pages/modules/{group}/{ModuleName}
     */
    public static function getMobileAppModulePath(string $moduleGroup, string $moduleName): string
    {
        // The MOBILE tree is deliberately FLAT: {group}/{Module}, with no sub-group
        // segment. Verified against the real app — all 18 mobile modules sit at
        // exactly two levels, and the web tree's sub-groups are collapsed there:
        //
        //   web    core/Locations/{Countries,Locations,LocationTypes,Wards}
        //   mobile core/Countries, core/Locations, core/LocationTypes, core/Wards
        //
        // `core/Locations` on mobile is the Locations MODULE (it has its own
        // routes.ts), not a Locations sub-group. An earlier change misread those
        // flat paths as nested ones and appended self::$moduleSubGroup here, which
        // would emit system/Custom/ItemTypes where mobile expects system/ItemTypes —
        // and diverged from getMobileAppBackendModulePath(), which never nested.
        //
        // Only the top-level group is lowercased, matching what is on disk.
        return self::getMobileAppModulesPath() . '/' . strtolower($moduleGroup) . '/' . $moduleName;
    }

    /**
     * Get the MOBILE_APP src path (for modules.json, menus.json, etc.)
     */
    public static function getMobileAppSrcPath(): string
    {
        return self::getMobileAppBasePath() . '/resources/js/src';
    }

    /**
     * Get the backend template path.
     *
     * Uses injected root (setTemplateRoots) if set.
     * Falls back to the package's own bundled templates directory.
     */
    public static function getBackendTemplatePath(): string
    {
        if (!empty(self::$templateRoots['backend'])) {
            return self::$templateRoots['backend'];
        }
        return __DIR__ . '/Templates/backend';
    }

    /**
     * Get the frontend template path.
     */
    public static function getFrontendTemplatePath(): string
    {
        if (!empty(self::$templateRoots['frontend'])) {
            return self::$templateRoots['frontend'];
        }
        return __DIR__ . '/Templates/frontend';
    }

    /**
     * Get the MOBILE_APP template path.
     */
    public static function getMobileAppTemplatePath(): string
    {
        if (!empty(self::$templateRoots['mobile'])) {
            return self::$templateRoots['mobile'];
        }
        return __DIR__ . '/Templates/mobile_app';
    }

    /**
     * Get the UX template path (composites, wizards, shortcuts, dashboard stubs).
     */
    public static function getUxTemplatePath(): string
    {
        if (!empty(self::$templateRoots['ux'])) {
            return self::$templateRoots['ux'];
        }
        return __DIR__ . '/Templates/ux';
    }

    /**
     * Get the mobile UX template path (mobile composites, wizards, shortcuts, dashboard stubs).
     */
    public static function getMobileUxTemplatePath(): string
    {
        if (!empty(self::$templateRoots['mobile_ux'])) {
            return self::$templateRoots['mobile_ux'];
        }
        return __DIR__ . '/Templates/mobile_app/ux';
    }

    /**
     * Get the full MOBILE_APP backend module path for a specific module.
     * Structure: MOBILE_APP/app/Modules/{Group}/{ModuleName}
     */
    public static function getMobileAppBackendModulePath(string $group, string $moduleName): string
    {
        return static::getMobileAppBasePath() . '/app/Modules/' . $group . '/' . $moduleName;
    }

    /**
     * Get the MOBILE_APP backend template path.
     */
    public static function getMobileAppBackendTemplatePath(): string
    {
        if (!empty(self::$templateRoots['mobile_backend'])) {
            return self::$templateRoots['mobile_backend'];
        }
        return __DIR__ . '/Templates/mobile_app/backend';
    }

    /**
     * Ensure output directories exist
     */
    public static function ensureOutputDirectories(): void
    {
        $directories = [
            self::getBackendBasePath(),
            self::getFrontendBasePath(),
            self::getBackendModulesPath(),
            self::getFrontendModulesPath(),
            self::getMobileAppBasePath(),
            self::getMobileAppModulesPath(),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }

    /**
     * Normalize a module group/type name to PascalCase.
     * Ensures consistent casing regardless of what is stored in the database.
     */
    public static function normalizeGroupName(string $group): string
    {
        $normalized = strtolower(trim($group));

        return match ($normalized) {
            'system' => 'System',
            'core'   => 'Core',
            default  => Str::studly($normalized),
        };
    }

    /**
     * Get the default module group
     */
    public static function getDefaultModuleGroup(): string
    {
        $config = self::getConfig();
        return $config['defaults']['module_group'] ?? 'Core';
    }

    /**
     * Get the namespace prefix
     */
    public static function getNamespacePrefix(): string
    {
        $config = self::getConfig();
        return $config['defaults']['namespace_prefix'] ?? 'App\\Project\\Modules';
    }

    /**
     * Get the backend registry path
     */
    public static function getBackendRegistryPath(): string
    {
        return self::getBackendBasePath() . '/app/Project/_Src';
    }

    /**
     * Get the frontend src path
     */
    public static function getFrontendSrcPath(): string
    {
        return self::getFrontendBasePath() . '/src';
    }

    /**
     * Get the frontend Playwright e2e output path.
     *
     * Unlike getBackendTestsPath() (nested under the module's sub-group,
     * e.g. tests/Feature/Locations/), the e2e suite is ALWAYS flat directly
     * under FRONTEND/e2e — verified against every existing hand-written
     * *.e2e.js file in SYSTEM_SHELL/FRONTEND/e2e (login.e2e.js,
     * location-types-crud.e2e.js, wards.e2e.js, etc.): none live in a
     * per-group or per-sub-group subdirectory, they all sit side by side and
     * import shared helpers via a constant relative path ('./helpers/...').
     * Deliberately ignores moduleGroup/moduleSubGroup for that reason.
     */
    public static function getFrontendE2ePath(): string
    {
        return self::getFrontendBasePath() . '/e2e';
    }
}
