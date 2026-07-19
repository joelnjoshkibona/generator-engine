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

        // Delegation permissions: one per enabled operation per delegation entry
        $delegations = $config['delegations'] ?? [];
        foreach ($delegations as $delegationKey => $delegation) {
            $delegationName = $delegation['name'] ?? ucfirst($delegationKey);
            $operations = $delegation['operations'] ?? [];
            foreach ($operations as $op => $opConfig) {
                if (!empty($opConfig['enabled'])) {
                    $permName = "{$moduleName}.{$delegationName}.{$op}";
                    if (!isset($existing[$permName])) {
                        $permissions[] = [
                            'name'        => $permName,
                            'module'      => $moduleName,
                            'title'       => ucfirst($op) . " {$delegationName} on {$humanModuleName}",
                            'description' => "Permission to {$op} {$delegationName} records on {$humanModuleName}",
                        ];
                        $existing[$permName] = true;
                    }
                }
            }
        }

        // Action permissions: one per action entry
        $actions = $config['actions'] ?? [];
        foreach ($actions as $actionKey => $action) {
            $actionName = $action['name'] ?? $actionKey;
            $permName   = "{$moduleName}.{$actionName}.execute";
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
            'data' => $this->processSeedData(),
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
}
