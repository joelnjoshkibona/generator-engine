<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ModelGenerator extends BaseGenerator
{
    protected array $fields;
    protected array $relationships;
    protected string $idType;
    protected string $idColumnName;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->fields = $config['columns'];
        $this->relationships = $config['relationships'] ?? [];
        
        // Extract ID configuration
        $this->idType = $config['id_type'];
        $this->idColumnName = 'id';
    }

    public function generate(): bool
    {
        // Determine template based on model type
        $modelType = 'default';
        $templateName = $this->getModelTemplate($modelType);
        $content = $this->getTemplateContent($templateName, 'backend');
        
        $replacements = [
            '[[constants]]' => $this->generateConstants(),
            '[[primaryKey]]' => $this->generatePrimaryKeyProperty(),
            '[[keyType]]' => $this->generateKeyTypeProperty(),
            '[[incrementing]]' => $this->generateIncrementingProperty(),
            '[[connection]]' => $this->generateConnection(),
            '[[timestamps]]' => $this->generateTimestamps(),
            '[[auditRelationships]]' => $this->generateAuditRelationships(),
            '[[relationships]]' => $this->generateRelationships(),
            '[[casts]]' => $this->generateCasts(),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/{$this->moduleName}Model.php";
        
        return $this->writeFile($filePath, $content);
    }
    
    protected function generateConnection(): string
    {
        $conn = $this->config['connection'] ?? config('generator.default_connection');
        return $conn ? "protected \$connection = '{$conn}';" : '';
    }

    protected function generateTimestamps(): string
    {
        $names = array_map(fn($f) => $f['name'] ?? null, $this->fields ?? []);
        if (in_array('created_date', $names, true) && in_array('modified_date', $names, true)) {
            return "const CREATED_AT = 'created_date';\n    const UPDATED_AT = 'modified_date';";
        }
        if (!in_array('created_at', $names, true) && !in_array('created_date', $names, true)) {
            return 'public $timestamps = false;';
        }
        return '';
    }

    protected function getModelTemplate(string $modelType): string
    {
        switch ($modelType) {
            case 'user':
            case 'authenticatable':
                return 'model_users';
            default:
                return 'model';
        }
    }
    

    protected function generateCasts(): string
    {
        $casts = [];
        
        // Auto-detect casts from database fields
        foreach ($this->fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'] ?? 'string';
            
            // Skip primary key and timestamps
            if ($fieldName === 'id' || in_array($fieldName, ['created_at', 'updated_at', 'deleted_at', 'created_date', 'modified_date'])) {
                continue;
            }
            
            // Determine cast type based on field type
            $castType = $this->getCastType($fieldType);
            if ($castType) {
                $casts[$fieldName] = $castType;
            }
        }
        
        // Add any manually configured casts
        $manualCasts = $this->config['backend']['model']['casts'] ?? [];
        foreach ($manualCasts as $key => $value) {
            // Handle both old format: [{ "field": "field_name", "type": "cast_type" }]
            // and new format: { "field_name": "cast_type" }
            if (is_array($value) && isset($value['field']) && isset($value['type'])) {
                // Old format
                $casts[$value['field']] = $value['type'];
            } else {
                // New format
                $casts[$key] = $value;
            }
        }
        
        if (empty($casts)) {
            return '';
        }

        $castArray = [];
        foreach ($casts as $field => $cast) {
            $castArray[] = "        '{$field}' => '{$cast}'";
        }

        return "protected \$casts = [\n" . implode(",\n", $castArray) . "\n    ];";
    }
    
    protected function getCastType(string $fieldType): ?string
    {
        switch (strtolower($fieldType)) {
            case 'json':
            case 'jsonb':
                return 'array';
            case 'boolean':
            case 'tinyint(1)':
                return 'boolean';
            case 'date':
                return 'date';
            case 'time':
                return 'string';
            case 'datetime':
            case 'timestamp':
                return 'datetime';
            case 'integer':
            case 'int':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return 'integer';
            case 'decimal':
            case 'float':
            case 'double':
                return 'float';
            default:
                return null; // No cast needed for strings and other types
        }
    }

    protected function generateRelationships(): string
    {
        $relationships = [];
        $seenMethods = [];

        // 0. morphTo — auto-generate from config['morphs'] entries
        $morphRelationships = $this->generateMorphRelationships();
        foreach ($morphRelationships as $method) {
            $methodKey = strtolower($method['name'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $method['code'];
        }

        // 1. belongsTo — auto-generate from this module's own foreignId columns
        $autoRelationships = $this->generateAutoRelationshipsFromForeignIds();
        foreach ($autoRelationships as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 2. hasMany — auto-derive inverse relationships by scanning other modules in the
        //    same project for foreignId columns pointing back at THIS module. Templates that
        //    reference the parent's child collection (e.g. $source->inventoryTransferItems)
        //    previously saw nulls because the hasMany was never emitted.
        $inverseRelationships = $this->generateInverseHasManyRelationships();
        foreach ($inverseRelationships as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 3. Manually declared hasMany / belongsToMany via `relations.hasMany[]` on module config.
        //    Escape hatch for non-standard FK naming or cross-DB relations.
        $manualInverse = $this->generateManualInverseRelationships();
        foreach ($manualInverse as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 4. Legacy manually configured relationships array ($config['relationships'])
        if (!empty($this->relationships)) {
            foreach ($this->relationships as $relationship) {
                $methodKey = strtolower($relationship['method'] ?? $relationship['name'] ?? '');
                if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
                $seenMethods[$methodKey] = true;
                $relationships[] = $this->generateRelationshipMethod($relationship);
            }
        }

        if (empty($relationships)) {
            return '';
        }

        return "\n" . implode("\n", $relationships);
    }

    /**
     * Scan other modules in the same project for foreignId columns that point back
     * at this module, and emit a hasMany relation for each. Method name is the
     * camelCase plural of the child module name (e.g. InventoryTransferItems on
     * the InventoryTransfers model becomes `inventoryTransferItems()`).
     *
     * When multiple FK columns on the child module point at this module
     * (e.g. from_location_id + to_location_id → Locations), each gets its own
     * suffixed method so method names don't collide.
     */
    protected function generateInverseHasManyRelationships(): array
    {
        $relationships = [];

        // --- Array registry path (new, decoupled) ---
        $registry = PathManager::getModuleRegistryAll();
        if (!empty($registry)) {
            foreach ($registry as $entry) {
                $childModuleName = $entry['name'] ?? null;
                if ($childModuleName === null || $childModuleName === $this->moduleName) {
                    continue;
                }
                $childConfig  = $entry['config'] ?? $entry;
                $childColumns = $childConfig['columns'] ?? [];

                $matchingCols = [];
                foreach ($childColumns as $col) {
                    if (($col['type'] ?? '') !== 'foreignId') continue;
                    if (($col['relatedModule'] ?? '') !== $this->moduleName) continue;
                    $matchingCols[] = $col;
                }
                if (empty($matchingCols)) continue;

                $multipleFks = count($matchingCols) > 1;
                foreach ($matchingCols as $col) {
                    $fkColumn = $col['name'] ?? '';
                    if ($fkColumn === '') continue;

                    $baseMethod = lcfirst(\Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($childModuleName)));
                    $method = $multipleFks
                        ? lcfirst(\Illuminate\Support\Str::camel(str_replace('_id', '', $fkColumn))) . ucfirst($baseMethod)
                        : $baseMethod;

                    $groupName = $entry['group_name'] ?? $entry['module_group'] ?? 'Core';
                    $relationships[] = [
                        'type'         => 'hasMany',
                        'module_name'  => $childModuleName,
                        'module_type'  => 'Model',
                        'module_group' => \Illuminate\Support\Str::studly($groupName),
                        'name'         => $method,
                        'method'       => $method,
                        'foreign_key'  => $fkColumn,
                        'local_key'    => null,
                    ];
                }
            }
            return $relationships;
        }

        // Registry is empty — no cross-module relationships can be derived.
        return $relationships;
    }

    /**
     * Manual escape hatch: users can declare extra inverse relations in module config
     * under `relations.hasMany[]` / `relations.belongsToMany[]` when the auto-derivation
     * can't cover the case (non-standard FK names, custom local keys, pivot tables).
     *
     * Expected shape:
     *   relations: {
     *     hasMany: [
     *       { module: "OrderItems", method: "items", foreignKey: "order_ref", localKey: "id" },
     *       ...
     *     ],
     *     belongsToMany: [
     *       { module: "Tags", method: "tags", pivotTable: "order_tag", foreignPivotKey: "order_id", relatedPivotKey: "tag_id" }
     *     ]
     *   }
     */
    protected function generateManualInverseRelationships(): array
    {
        $relations = $this->config['relations'] ?? [];
        if (!is_array($relations)) return [];

        $out = [];

        foreach (($relations['hasMany'] ?? []) as $decl) {
            if (!is_array($decl)) continue;
            $moduleName = $decl['module'] ?? '';
            $method = $decl['method'] ?? '';
            if ($moduleName === '' || $method === '') continue;
            $out[] = [
                'type' => 'hasMany',
                'module_name' => $moduleName,
                'module_type' => 'Model',
                'module_group' => $this->determineModuleGroup($moduleName),
                'name' => $method,
                'method' => $method,
                'foreign_key' => $decl['foreignKey'] ?? null,
                'local_key' => $decl['localKey'] ?? null,
            ];
        }

        foreach (($relations['belongsToMany'] ?? []) as $decl) {
            if (!is_array($decl)) continue;
            $moduleName = $decl['module'] ?? '';
            $method = $decl['method'] ?? '';
            if ($moduleName === '' || $method === '') continue;
            $out[] = [
                'type' => 'belongsToMany',
                'module_name' => $moduleName,
                'module_type' => 'Model',
                'module_group' => $this->determineModuleGroup($moduleName),
                'name' => $method,
                'method' => $method,
                'pivot_table' => $decl['pivotTable'] ?? null,
                'foreign_pivot_key' => $decl['foreignPivotKey'] ?? null,
                'related_pivot_key' => $decl['relatedPivotKey'] ?? null,
            ];
        }

        return $out;
    }
    
    /**
     * Generate morphTo() relation methods from config['morphs'] entries.
     * Returns an array of ['name' => string, 'code' => string].
     */
    protected function generateMorphRelationships(): array
    {
        $morphs = $this->config['morphs'] ?? [];
        if (empty($morphs)) {
            return [];
        }

        $out = [];
        foreach ($morphs as $morph) {
            $name = $morph['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'code' => implode("\n", [
                    "    public function {$name}(): \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo",
                    "    {",
                    "        return \$this->morphTo();",
                    "    }",
                ]),
            ];
        }

        return $out;
    }

    /**
     * Collect all column names that are part of a morph pair (to skip belongsTo for them).
     */
    protected function getMorphPairColumnNames(): array
    {
        $morphs = $this->config['morphs'] ?? [];
        $names  = [];
        foreach ($morphs as $morph) {
            if (!empty($morph['type_column'])) {
                $names[] = $morph['type_column'];
            }
            if (!empty($morph['id_column'])) {
                $names[] = $morph['id_column'];
            }
        }
        return $names;
    }

    protected function generateAutoRelationshipsFromForeignIds(): array
    {
        $relationships = [];
        $morphPairCols = $this->getMorphPairColumnNames();

        foreach ($this->fields as $field) {
            // Only process foreignId columns
            if (($field['type'] ?? '') !== 'foreignId') {
                continue;
            }

            $columnName = $field['name'];

            // Skip audit fields (created_by_id, updated_by_id) - they're handled separately
            if (in_array($columnName, ['created_by_id', 'updated_by_id'])) {
                continue;
            }

            // Skip columns that are part of a morph pair (they get morphTo(), not belongsTo())
            if (in_array($columnName, $morphPairCols, true)) {
                continue;
            }

            // Derive relationship method name from column name
            // e.g., "category_id" -> "category", "user_id" -> "user"
            $methodName = $this->deriveRelationshipMethodName($columnName);

            // Get related module information.
            // When relatedModule is set (from a DB FK constraint), use it directly.
            // When it is empty (e.g. status_id with no FK constraint), derive from column name:
            //   strip _id, pluralize, StudlyCase  → "status_id" → "status" → "statuses" → "Statuses"
            $relatedModuleName = $field['relatedModule'] ?? '';
            if ($relatedModuleName === '' && str_ends_with($columnName, '_id')) {
                $base = substr($columnName, 0, -3); // strip _id
                $relatedModuleName = \Illuminate\Support\Str::studly(
                    \Illuminate\Support\Str::plural($base)
                );
            }

            if ($relatedModuleName === '') {
                continue; // Cannot derive a target — skip
            }

            // Determine module group - try to get from config or default to 'Core'
            $relatedModuleGroup = $this->determineModuleGroup($relatedModuleName);

            $relationships[] = [
                'type' => 'belongsTo',
                'module_name' => $relatedModuleName,
                'module_type' => 'Model',
                'module_group' => $relatedModuleGroup,
                'name' => $methodName,
                'method' => $methodName,
                'foreign_key' => $columnName,
                'local_key' => null // Use default 'id'
            ];
        }

        return $relationships;
    }
    
    protected function deriveRelationshipMethodName(string $columnName): string
    {
        // Remove _id suffix and convert to camelCase (Laravel convention for relation methods)
        // e.g. "payment_method_id" -> "paymentMethod"
        //      "status_id"         -> "status"
        if (str_ends_with($columnName, '_id')) {
            $base = substr($columnName, 0, -3);
            return lcfirst(\Illuminate\Support\Str::camel($base));
        }

        // If no _id suffix, return camelCase as-is
        return lcfirst(\Illuminate\Support\Str::camel($columnName));
    }
    
    protected function determineModuleGroup(string $moduleName): string
    {
        // First, try the module registry (array-based, decoupled)
        $registryEntry = PathManager::findModuleInRegistry($moduleName);
        if ($registryEntry !== null) {
            return $registryEntry['module_type'] ?? $registryEntry['type'] ?? 'Core';
        }

        // Fallback: Try to get from the generated project's registry
        try {
            $registryPath = PathManager::getBackendRegistryPath() . '/registry_core.json';
        if (file_exists($registryPath)) {
            $registry = json_decode(file_get_contents($registryPath), true);
            if (isset($registry[$moduleName])) {
                $moduleConfig = $registry[$moduleName];
                // Extract module group from namespace path
                // e.g., "App\Project\Modules\Core\Users" -> "Core"
                if (isset($moduleConfig['namespace'])) {
                    $namespace = $moduleConfig['namespace'];
                    if (preg_match('/Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
                        return $matches[1];
                    }
                }
                // Fallback to type field
                return $moduleConfig['type'] ?? 'Core';
            }
        }
        
            // Check generated project's system registry
            $systemRegistryPath = PathManager::getBackendRegistryPath() . '/registry.json';
        if (file_exists($systemRegistryPath)) {
            $registry = json_decode(file_get_contents($systemRegistryPath), true);
            if (isset($registry[$moduleName])) {
                $moduleConfig = $registry[$moduleName];
                // Extract module group from namespace path
                if (isset($moduleConfig['namespace'])) {
                    $namespace = $moduleConfig['namespace'];
                    if (preg_match('/Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
                        return $matches[1];
                    }
                }
                // Fallback to type field
                    return $moduleConfig['type'] ?? 'System';
                }
            }
            
            // Fallback: Check actual module directory structure in generated project
            $modulesPath = PathManager::getBackendModulesPath();
            if (is_dir($modulesPath)) {
                $moduleGroups = array_filter(glob($modulesPath . '/*'), 'is_dir');
                foreach ($moduleGroups as $groupPath) {
                    $groupName = basename($groupPath);
                    $modulePath = $groupPath . '/' . $moduleName;
                    if (is_dir($modulePath)) {
                        return $groupName;
                    }
                }
            }
        } catch (\Exception) {
            // If PathManager is not set up (project root not set), fall through to generator system registry
        }

        // Last resort: Check generator system's registry (for backward compatibility, V1/Laravel only)
        if (function_exists('base_path')) {
            $generatorRegistryPath = base_path('app/Project/_Src/registry_core.json');
            if (file_exists($generatorRegistryPath)) {
                $registry = json_decode(file_get_contents($generatorRegistryPath), true);
                if (isset($registry[$moduleName])) {
                    $moduleConfig = $registry[$moduleName];
                    if (isset($moduleConfig['namespace'])) {
                        $namespace = $moduleConfig['namespace'];
                        if (preg_match('/Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
                            return $matches[1];
                        }
                    }
                    return $moduleConfig['type'] ?? 'Core';
                }
            }

            // Check generator system's system registry
            $generatorSystemRegistryPath = base_path('app/Project/_Src/registry.json');
            if (file_exists($generatorSystemRegistryPath)) {
                $registry = json_decode(file_get_contents($generatorSystemRegistryPath), true);
                if (isset($registry[$moduleName])) {
                    $moduleConfig = $registry[$moduleName];
                    if (isset($moduleConfig['namespace'])) {
                        $namespace = $moduleConfig['namespace'];
                        if (preg_match('/Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
                            return $matches[1];
                        }
                    }
                    return $moduleConfig['type'] ?? 'System';
                }
            }
        }
        
        // Default to 'Core' if not found
        return 'Core';
    }

    protected function generateRelationshipMethod(array $relationship): string
    {
        $type = $relationship['type'];
        
        // Use module information to generate the relationship
        $moduleName = $relationship['module_name'] ?? 'Unknown';
        $moduleType = $relationship['module_type'] ?? 'Model';
        $moduleGroup = $relationship['module_group'] ?? $this->config['module_group'] ?? 'Core';
        $method = $relationship['name'] ?? $relationship['method'] ?? strtolower($moduleName);
        $foreignKey = $relationship['foreign_key'] ?? null;
        $localKey = $relationship['local_key'] ?? null;

        // Generate the namespaced class using module information
        $namespacedClass = $this->generateNamespacedClass($moduleName, $moduleType, $moduleGroup);

        switch ($type) {
            case 'hasMany':
                $params = $foreignKey ? ", '{$foreignKey}'" : '';
                return "    public function {$method}()\n    {\n        return \$this->hasMany({$namespacedClass}::class{$params});\n    }";
            
            case 'belongsTo':
                $params = $foreignKey ? ", '{$foreignKey}'" : '';
                return "    public function {$method}()\n    {\n        return \$this->belongsTo({$namespacedClass}::class{$params});\n    }";
            
            case 'belongsToMany':
                $table = $relationship['pivot_table'] ?? null;
                $foreignPivotKey = $relationship['foreign_pivot_key'] ?? null;
                $relatedPivotKey = $relationship['related_pivot_key'] ?? null;
                
                $params = [];
                if ($table) $params[] = "'{$table}'";
                if ($foreignPivotKey) $params[] = "'{$foreignPivotKey}'";
                if ($relatedPivotKey) $params[] = "'{$relatedPivotKey}'";
                
                $paramString = $params ? ', ' . implode(', ', $params) : '';
                return "    public function {$method}()\n    {\n        return \$this->belongsToMany({$namespacedClass}::class{$paramString});\n    }";
            
            default:
                return '';
        }
    }

    protected function generateNamespacedClass(string $moduleName, string $moduleType, string $moduleGroup): string
    {
        $className = $moduleName . $moduleType;
        $namespace = PathManager::resolveBackendModuleNamespace($moduleName);
        return "\\{$namespace}\\{$className}";
    }


    protected function generateRules(): string
    {
        $rules = [];
        foreach ($this->fields as $field) {
            if (isset($field['rules'])) {
                $rules[] = "'{$field['name']}' => '{$field['rules']}'";
            }
        }

        if (empty($rules)) {
            return '';
        }

        return "[\n        " . implode(",\n        ", $rules) . "\n    ]";
    }

    protected function generatePrimaryKeyProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "protected \$primaryKey = 'id';";
                
            case 'uuid':
            case 'manual':
                return "protected \$primaryKey = '{$this->idColumnName}';";
                
            default:
                return "protected \$primaryKey = 'id';";
        }
    }

    protected function generateKeyTypeProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "protected \$keyType = 'int';";
                
            case 'uuid':
            case 'manual':
                return "protected \$keyType = 'string';";
                
            default:
                return "protected \$keyType = 'int';";
        }
    }

    protected function generateIncrementingProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "public \$incrementing = true;";
                
            case 'uuid':
            case 'manual':
                return "public \$incrementing = false;";
                
            default:
                return "public \$incrementing = true;";
        }
    }

    protected function generateConstants(): string
    {
        $constants = $this->config['constants'] ?? [];
        
        if (empty($constants)) {
            return '';
        }

        $constantLines = [];
        foreach ($constants as $name => $value) {
            $formatted = is_numeric($value) ? $value : "'{$value}'";
            $constantLines[] = "    public const {$name} = {$formatted};";
        }

        return "\n" . implode("\n", $constantLines);
    }

    protected function generateAuditRelationships(): string
    {
        $usersNs = '\\App\\Project\\Modules\\Core\\Users\\Users\\UsersModel';

        $auditRelationships = [
            "    public function creator(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo",
            "    {",
            "        return \$this->belongsTo(",
            "            {$usersNs}::class, 'created_by_id', 'id'",
            "        );",
            "    }",
            "",
            "    public function updater(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo",
            "    {",
            "        return \$this->belongsTo(",
            "            {$usersNs}::class, 'updated_by_id', 'id'",
            "        );",
            "    }"
        ];

        return implode("\n", $auditRelationships);
    }

    protected function extractModuleNameFromModel(string $modelClass): string
    {
        // Extract module name from full model class path
        // e.g., "App\Project\Modules\Core\Entities\EntitiesModel" -> "Entities"
        $parts = explode('\\', $modelClass);
        $modelName = end($parts);
        
        // Remove "Model" suffix if present
        if (str_ends_with($modelName, 'Model')) {
            return substr($modelName, 0, -5);
        }
        
        return $modelName;
    }
}
