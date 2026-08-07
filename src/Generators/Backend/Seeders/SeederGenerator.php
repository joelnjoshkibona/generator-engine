<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class SeederGenerator extends BaseGenerator
{
    protected array $seedData;
    protected array $permissions;
    protected string $idType;
    protected string $idColumnName;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->seedData = $config['seeder']['data'] ?? [];
        $this->permissions = $this->mergeListPermissions($config['seeder']['permissions'] ?? [], $moduleName, $config);

        // Extract ID configuration
        $this->idType = $config['id_type'];
        $this->idColumnName = 'id';
    }

    private function mergeListPermissions(array $permissions, string $moduleName, array $config): array
    {
        // Index existing permissions by name for deduplication
        $existing = [];
        foreach ($permissions as $perm) {
            $existing[$perm['name'] ?? ''] = true;
        }

        // Permission `name`/`module` stay raw PascalCase — they're identifiers
        // matched elsewhere (route meta.permission, DB rows, Roles' permission
        // grouping key). Only `title`/`description` are human-facing text, so
        // only those get spaced. Plural form matches the established
        // convention (confirmed against Users/ItemCategories seeder data:
        // "Bulk Actions on Users", "List ItemCategories").
        $humanModuleName = $this->humanize($moduleName);

        $backendFeatures = $config['features']['backend'] ?? [];

        // Auto-derive CRUD permissions for each enabled backend feature
        $crudFeatures = ['list', 'view', 'create', 'edit', 'delete', 'deleteCheck'];
        foreach ($crudFeatures as $feature) {
            if (!empty($backendFeatures[$feature])) {
                $permName = "{$moduleName}.{$feature}";
                if (!isset($existing[$permName])) {
                    $humanFeature = ucfirst($feature === 'deleteCheck' ? 'Delete Check' : $feature);
                    $permissions[] = [
                        'name'        => $permName,
                        'module'      => $moduleName,
                        'title'       => "{$humanFeature} {$humanModuleName}",
                        'description' => "Permission to {$feature} {$humanModuleName}",
                    ];
                    $existing[$permName] = true;
                }
            }
        }

        // bulkAction: always emit when list is enabled
        if (!empty($backendFeatures['list'])) {
            $bulkPermName = "{$moduleName}.bulkAction";
            if (!isset($existing[$bulkPermName])) {
                $permissions[] = [
                    'name'        => $bulkPermName,
                    'module'      => $moduleName,
                    'title'       => "Bulk Actions on {$humanModuleName}",
                    'description' => "Permission to run bulk actions on {$humanModuleName}",
                ];
                $existing[$bulkPermName] = true;
            }
        }

        // import: its own opt-in flag, not just "list is enabled" (unlike
        // bulkAction above) — RoutesGenerator only emits the import routes
        // when list.import is truthy, and both guard on {Module}.import.
        // Export needs no equivalent block: it reuses {Module}.list, already
        // seeded by the $crudFeatures loop above whenever list exists.
        $listConfig = $backendFeatures['list'] ?? null;
        if (is_array($listConfig) && !empty($listConfig['import'])) {
            $importPermName = "{$moduleName}.import";
            if (!isset($existing[$importPermName])) {
                $permissions[] = [
                    'name'        => $importPermName,
                    'module'      => $moduleName,
                    'title'       => "Import {$humanModuleName}",
                    'description' => "Permission to import {$humanModuleName} records",
                ];
                $existing[$importPermName] = true;
            }
        }

        // No delegation-specific permissions are seeded here at all.
        // DelegationConfigNormalizer::resolveOperationPermission() resolves
        // every delegation operation (list/create/edit/view/delete/
        // deleteCheck/bulkAction/export/import) to the RELATED module's own
        // permission (e.g. "StockMovements.edit"), never a delegation-
        // specific one — and that permission is already, unconditionally,
        // seeded by the related module's own SeederGenerator run (this same
        // $crudFeatures/bulkAction/import logic above, applied to THAT
        // module's own config) whenever it has the corresponding feature
        // enabled. A delegation can only ever expose an operation the
        // related module's own native form/service already supports (the
        // tab imports that module's own native EditForm.vue directly), so
        // relying entirely on the related module's own seeding introduces
        // no gap — it just avoids seeding the same permission twice, once
        // under a formula nothing checks anymore.

        // Action permissions: one per action entry
        $actions = $config['actions'] ?? [];
        foreach ($actions as $actionKey => $action) {
            $actionName = $action['name'] ?? $actionKey;
            // Canonical action permission — see RoutesGenerator::generateActionRoutes().
            // The old ".execute" suffix (plus the route's StudlyCase spelling)
            // meant the seeded permission and the route guard could never match.
            //
            // lcfirst() (found + fixed 2026-08-06): action names are StudlyCase
            // (e.g. "ForceResetPassword"), but every other permission in this
            // codebase — the CRUD set (Users.create/.edit/...) and hand-written
            // ones (Users.resendInvitation) — is camelCase after the dot. This
            // three-way match (here, RoutesGenerator, ViewModalGenerator) was
            // internally self-consistent even without lcfirst() — the actual
            // permission still worked end-to-end — but "Users.ForceResetPassword"
            // silently violated the rest of the app's own naming convention.
            $permName   = "{$moduleName}." . lcfirst($actionName);
            if (!isset($existing[$permName])) {
                $label = $action['label'] ?? ucfirst($actionName);
                $permissions[] = [
                    'name'        => $permName,
                    'module'      => $moduleName,
                    'title'       => "Execute {$label} on {$humanModuleName}",
                    'description' => "Permission to execute the {$label} action on {$humanModuleName}",
                ];
                $existing[$permName] = true;
            }
        }

        return $permissions;
    }

    public function generate(): bool
    {
        // Generate Seeder Class
        $seederGenerated = $this->generateSeederClass();
        
        // Generate JSON Data
        $jsonGenerated = $this->generateJsonData();
        
        return $seederGenerated && $jsonGenerated;
    }

    protected function generateSeederClass(): bool
    {
        try {
            $content = $this->getTemplateContent('seeder', 'backend');
            
            // Prepare jsonData for the template with ID type handling
            $jsonData = [
                'data' => $this->processSeedData(),
                'permissions' => $this->permissions
            ];
            
            $content = $this->replacePlaceholders($content, [
                '[[jsonData]]' => json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ]);

            $filePath = "{$this->modulePath}/Seeders/{$this->moduleName}Seeder.php";
            
            return $this->writeFile($filePath, $content);
        } catch (\Exception $e) {
            error_log("Seeder generation error: " . $e->getMessage());
            throw $e;
        }
    }

    protected function generateJsonData(): bool
    {
        $jsonData = [
            'data' => $this->resolveSeedData(),
            'permissions' => $this->permissions
        ];

        $filePath = "{$this->modulePath}/Seeders/{$this->moduleName}SeederData.json";

        return $this->writeFile($filePath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function processSeedData(): array
    {
        $processedData = [];

        foreach ($this->seedData as $index => $item) {
            $processedItem = $item;
            $processedItem['id'] = $index + 1;
            $processedItem['created_by_id'] = $processedItem['created_by_id'] ?? 1;
            $processedItem['updated_by_id'] = $processedItem['updated_by_id'] ?? 1;
            $processedData[] = $processedItem;
        }

        return $processedData;
    }

    /**
     * Resolve the `data` rows a regenerate should actually write.
     *
     * Bug (found live 2026-08-07 while porting SYSTEM_SHELL's Countries
     * module — the identical failure mode independently bit Users'
     * bootstrap-account rows earlier in the same session): `$this->seedData`
     * comes exclusively from `$config['seeder']['data']` — module.json's
     * declared seed rows. Real-world seed data (Countries' full 252-row
     * ISO-3166 list; Users' 2 bootstrap accounts) is routinely hand-added
     * directly to the already-generated `{Module}SeederData.json` file and
     * never round-tripped back into module.json. Every prior `--force`
     * regenerate unconditionally overwrote that file with
     * `json_encode(['data' => processSeedData(), ...])` — since
     * `processSeedData()` iterates the (empty) config-only `$this->seedData`,
     * this silently wrote `"data": []`, discarding the real rows with no
     * warning. `permissions` has no equivalent risk (mergeListPermissions()
     * already merges additively against the existing config), so only
     * `data` needed this guard.
     *
     * Fix: when config declares no seed rows AND a `{Module}SeederData.json`
     * already exists on disk with a non-empty `data` array, preserve that
     * array verbatim (it already carries real ids/uuids — it must NOT be
     * re-run through processSeedData()'s index-based id reassignment, which
     * is only correct for fresh, id-less config rows). Config-declared rows
     * still always win when present, matching every other generator output
     * in this class — this only prevents an *empty* config from clobbering
     * *non-empty* existing data.
     */
    protected function resolveSeedData(): array
    {
        if (!empty($this->seedData)) {
            return $this->processSeedData();
        }

        $filePath = "{$this->modulePath}/Seeders/{$this->moduleName}SeederData.json";
        if (file_exists($filePath)) {
            $existing = json_decode(file_get_contents($filePath), true);
            if (is_array($existing) && !empty($existing['data']) && is_array($existing['data'])) {
                return $existing['data'];
            }
        }

        return [];
    }
}
