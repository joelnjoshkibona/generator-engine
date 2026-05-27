<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Illuminate\Support\Str;

abstract class BaseServiceGenerator extends BaseGenerator
{
    protected function generateFilterableFields(): string
    {
        // Use filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        
        if (!empty($filterFields)) {
            // Extract keys from filterFields
            $fields = [];
            foreach ($filterFields as $field) {
                $key = $field['key'] ?? '';
                if (!empty($key)) {
                    $fields[] = "'{$key}'";
                }
            }
            return '[' . implode(', ', $fields) . ']';
        }
        
        // Fallback to filterableFields (array or comma-separated string)
        if (isset($this->config['features']['backend']['list']['filterableFields'])) {
            $filterableFields = $this->config['features']['backend']['list']['filterableFields'];
            if (is_array($filterableFields)) {
                $fields = array_map(fn($field) => "'" . trim($field) . "'", $filterableFields);
                return '[' . implode(', ', $fields) . ']';
            }
            $customFields = explode(',', $filterableFields);
            $fields = array_map(fn($field) => "'" . trim($field) . "'", $customFields);
            return '[' . implode(', ', $fields) . ']';
        }

        return '[]';
    }

    protected function generateSortableFields(): string
    {
        // Use sortableFields from list configuration
        if (isset($this->config['features']['backend']['list']['sortableFields'])) {
            $sortableFields = $this->config['features']['backend']['list']['sortableFields'];
            
            // Handle comma-separated string
            if (is_string($sortableFields)) {
                $customFields = explode(',', $sortableFields);
                $fields = array_map(fn($field) => "'" . trim($field) . "'", $customFields);
                return '[' . implode(', ', $fields) . ']';
            }
            
            // Handle array
            if (is_array($sortableFields)) {
                $fields = array_map(fn($field) => "'{$field}'", $sortableFields);
            return '[' . implode(', ', $fields) . ']';
            }
        }

        // Fallback to filterableFields if sortableFields not specified
        return $this->generateFilterableFields();
    }

    protected function generateEagerLoadRelationships($feature): string
    {
        // Check if custom configuration exists
        if (isset($this->config['features']['backend'][$feature]['eagerLoadRelationships'])) {
            $eagerLoadConfig = $this->config['features']['backend'][$feature]['eagerLoadRelationships'];
            
            // Handle comma-separated string
            if (is_string($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', explode(',', $eagerLoadConfig)),
                    fn($rel) => !empty($rel)
                );
                if (empty($customRelationships)) {
                    return '["creator", "updater"]';
                }
                $relationships = array_map(fn($rel) => "'{$rel}'", $customRelationships);
                $relationships = array_merge($relationships, ["'creator'", "'updater'"]);
                return '[' . implode(', ', $relationships) . ']';
            }
            
            // Handle array format
            if (is_array($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', $eagerLoadConfig),
                    fn($rel) => !empty($rel)
                );
                if (empty($customRelationships)) {
                    return '["creator", "updater"]';
                }
                $relationships = array_map(fn($rel) => "'{$rel}'", $customRelationships);
                $relationships = array_merge($relationships, ["'creator'", "'updater'"]);
                return '[' . implode(', ', $relationships) . ']';
            }
        }
        
        return '["creator", "updater"]';
    }

    protected function generateFilterableRelationships(): string
    {
        // Use filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        
        $filterableRelationships = [];

        // Extract relationships from filterFields that are foreign keys
        foreach ($filterFields as $field) {
            $fieldName = $field['key'] ?? '';
            if ($this->isForeignKey($fieldName, $field)) {
                $relationshipName = str_replace('_id', '', $fieldName);
                $filterableRelationships[$relationshipName] = ['id', 'name'];
            }
        }
        
        // Also check filterableRelationships if explicitly configured
        if (isset($this->config['features']['backend']['list']['filterableRelationships'])) {
            $explicitRelationships = $this->config['features']['backend']['list']['filterableRelationships'];
            if (is_array($explicitRelationships) && !empty($explicitRelationships)) {
                foreach ($explicitRelationships as $rel => $fields) {
                    $filterableRelationships[$rel] = is_array($fields) ? $fields : ['id', 'name'];
                }
            }
        }

        if (empty($filterableRelationships)) {
            return '[]';
        }

        $relationshipConfigs = [];
        foreach ($filterableRelationships as $relationship => $fields) {
            $fieldStrings = array_map(fn($field) => "'{$field}'", $fields);
            $relationshipConfigs[] = "'{$relationship}' => [" . implode(', ', $fieldStrings) . "]";
        }

        return '[' . implode(', ', $relationshipConfigs) . ']';
    }

    protected function generateFilterFields(): string
    {
        // Use feature-specific filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        
        if (empty($filterFields)) {
            return '[]'; // Return empty if no filter fields configured
        }
        
        $fields = [];
        foreach ($filterFields as $field) {
            $fields[] = $this->formatFilterField($field);
        }

        return '[' . implode(",\n\t\t\t", $fields) . ']';
    }

    protected function formatFilterField(array $field): string
    {
        $formattedField = $field;

        // Handle custom_select type
        if ($field['type'] === 'custom_select') {
            if (isset($field['custom_keys']) && is_array($field['custom_keys'])) {
                $formattedField['custom_keys'] = $field['custom_keys'];
            }
            if (isset($field['custom_options']) && is_array($field['custom_options'])) {
                $formattedField['custom_options'] = $field['custom_options'];
            }
        }

        // Handle module_select type
        if ($field['type'] === 'module_select') {
            if (isset($field['columns']) && is_array($field['columns'])) {
                $formattedField['columns'] = $field['columns'];
            }
        }

        return $this->arrayToString($formattedField);
    }

    protected function generateFieldLabel(string $fieldName): string
    {
        // Convert snake_case to Title Case
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        $label = str_replace(' Id', '', $label);
        $label = str_replace(' At', '', $label);
        return $label;
    }

    protected function getFilterFieldType(string $fieldName, string $fieldType, array $field): string
    {
        if ($this->isForeignKey($fieldName, $field)) {
            return 'select_paginated';
        }
        if ($fieldType === 'boolean' || $fieldName === 'is_default' || $fieldName === 'is_active') {
            return 'select';
        }
        if (in_array($fieldType, ['date', 'datetime', 'timestamp'])) {
            return 'date';
        }
        if (in_array($fieldType, ['integer', 'bigint', 'decimal', 'float'])) {
            return 'number';
        }
        return 'text';
    }

    protected function isForeignKey(string $fieldName, array $field): bool
    {
        if (isset($field['foreignId']) && $field['foreignId']) {
            return true;
        }
        if (str_ends_with($fieldName, '_id')) {
            return true;
        }
        return false;
    }

    protected function getRelatedTableName(string $fieldName): string
    {
        if (str_ends_with($fieldName, '_id')) {
            $relatedTable = str_replace('_id', '', $fieldName);
            return Str::plural($relatedTable);
        }
        return Str::plural($fieldName);
    }

    protected function generateValidationRules(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        
        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }
        
        $rules = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldRules = $field['rules'] ?? '';
            
            if (!empty($fieldName) && !empty($fieldRules)) {
                // Split rules string into array
                // Laravel validation rules use pipe (|) as separator
                // Split on pipe and trim each rule
                $ruleArray = array_map('trim', explode('|', $fieldRules));
                $ruleArray = array_filter($ruleArray, fn($rule) => !empty($rule));
                
                $ruleStrings = array_map(fn($rule) => "\"{$rule}\"", $ruleArray);
                $rulesArrayStr = '[' . implode(', ', $ruleStrings) . ']';
                $rules[] = "'{$fieldName}' => {$rulesArrayStr}";
            }
        }
        
        return '[' . implode(",\n            ", $rules) . ']';
    }

    protected function generateValidationMessages(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        
        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }
        
        $messages = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldMessages = $field['messages'] ?? [];
            
            if (!empty($fieldName) && !empty($fieldMessages)) {
                foreach ($fieldMessages as $message) {
                    $ruleKey = $message['ruleKey'] ?? '';
                    $messageText = $message['message'] ?? '';
                    
                    if (!empty($ruleKey) && !empty($messageText)) {
                        $messages[] = "'{$fieldName}.{$ruleKey}' => '{$messageText}'";
            }
        }
            }
        }
        
        return '[' . implode(",\n            ", $messages) . ']';
    }

    protected function generateSplashData(string $feature): string
    {
        // Get splash configuration from new path: features.backend
        $backendConfig = $this->config['features']['backend'][$feature] ?? [];
        $splashConfig = $backendConfig['splashData'] ?? [];

        $splashData = [];

        foreach ($splashConfig as $source) {
            $key = $source['key'];
            $type = $source['type'] ?? 'model';
            $paginate = $source['paginate'] ?? true; // Default to paginated
            $module = $source['module'] ?? '';
            $moduleGroup = $source['moduleGroup'] ?? 'Core';

            if ($type === 'custom') {
                // Custom static data - no pagination needed
                $dataArray = $this->formatCustomDataArray($source['data'] ?? []);
                $splashData[] = "'{$key}' => {$dataArray}";
            } else {
                // Model-based data
                if (empty($module)) {
                    continue;
                }
                
                // Check if this should be paginated
                if ($paginate) {
                    // Return API endpoint info instead of static data
                    // Frontend will call the endpoint directly
                    // Convert key to kebab-case for API endpoint (route pattern requires [a-z-]+)
                    // Str::kebab() only handles PascalCase, so convert snake_case manually
                    $endpointKey = str_replace('_', '-', $key);
                    $splashData[] = "'{$key}' => ['type' => 'api', 'endpoint' => '/select/{$endpointKey}']";
                } else {
                    // Static data (non-paginated)
                    $columns = $source['columns'] ?? ['id', 'name'];
                $columnsStr = is_array($columns) ? implode("', '", $columns) : $columns;
                $splashData[] = "'{$key}' => {$module}Model::select('{$columnsStr}')->get()->toArray()";
                }
            }
        }

        return implode(",\n            ", $splashData);
    }

    protected function formatCustomDataArray(array $data): string
    {
        if (empty($data)) {
            return '[]';
        }
        
        $formatted = [];
        foreach ($data as $item) {
            $itemStr = [];
            foreach ($item as $k => $v) {
                if (is_string($v)) {
                    $itemStr[] = "'{$k}' => '{$v}'";
                } elseif (is_numeric($v)) {
                    $itemStr[] = "'{$k}' => {$v}";
                } else {
                    $itemStr[] = "'{$k}' => " . json_encode($v);
                }
            }
            $formatted[] = '[' . implode(', ', $itemStr) . ']';
        }
        
        return '[' . implode(', ', $formatted) . ']';
    }

    protected function arrayToString(array $array): string
    {
        if (empty($array)) {
            return '[]';
        }

        $lines = ["["];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Numeric array
                    $valueStrings = [];
                    foreach ($value as $rule) {
                        if (is_array($rule)) {
                            $valueStrings[] = $this->arrayToString($rule);
                        } else {
                            $valueStrings[] = '"' . addslashes($rule) . '"';
                        }
                    }
                    $valueString = '[' . implode(', ', $valueStrings) . ']';
                } else {
                    // Associative array
                    $valueString = $this->arrayToString($value);
                }
            } else {
                $valueString = '"' . addslashes($value) . '"';
            }
            $lines[] = "\t\t\t\t'{$key}' => {$valueString},";
        }
        $lines[] = "\t\t\t]";

        return implode("\n", $lines);
    }

    /**
     * Emit PHP code to invoke all processors matching (operation, stage) for the current module.
     * Target processor services must expose static methods matching the camelCase of the stage:
     *   public static function beforeSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function beforeDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *
     * Generated code assigns the return value back to $validData so processors can transform data.
     *
     * @param string $op Operation: 'create', 'edit', 'delete'
     * @param string $stage Stage: 'before_save', 'after_save', 'before_delete', 'after_delete'
     * @return string Indented code block suitable for stub placeholder
     */
    protected function generateProcessorCalls(string $op, string $stage): string
    {
        $processors = $this->config['processors'] ?? [];
        $lines = [];

        foreach ($processors as $p) {
            if (!in_array($op, $p['operations'] ?? [], true)) {
                continue;
            }
            if (($p['stage'] ?? '') !== $stage) {
                continue;
            }

            $service = $p['service'] ?? null;
            $module = $p['module'] ?? null;

            if (!$service || !$module) {
                continue;
            }

            $method = Str::camel($stage); // before_save -> beforeSave
            // Resolve full namespace from DB (module_type + group) instead of trusting
            // $p['moduleGroup'] — the UI stores this inconsistently (sometimes the sub-group
            // "Accounting", sometimes the module_type "System"). DB lookup is authoritative.
            $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
            $namespace = "{$ns}\\Services\\{$service}";
            $fields = json_encode($p['fields'] ?? []);
            $config = json_encode($p['config'] ?? new \stdClass());

            // Method signature target must accept (array $data, ?Model $model, array $fields, array $config): array
            // Generated call passes data, model (null or variable), fields array, config array
            $modelArg = in_array($stage, ['after_save', 'before_delete', 'after_delete'], true) ? '$model' : 'null';

            $lines[] = "// Processor: {$module}\\{$service}::{$method}";
            $lines[] = "\$validData = \\{$namespace}::{$method}(\$validData, {$modelArg}, json_decode('{$fields}', true), json_decode('{$config}', true));";
        }

        return empty($lines) ? '' : implode("\n        ", $lines);
    }

    // ─── Inline Items helpers ─────────────────────────────────────────────────

    /**
     * Resolve the PHP namespace for a child module used in inline-items generation.
     * Domain groups (anything except Core/System) are nested under System/.
     */
    protected function buildChildNamespace(string $childGroup, string $childModule): string
    {
        $isDomainGroup = !in_array($childGroup, ['Core', 'System'], true);
        return $isDomainGroup
            ? "App\\Project\\Modules\\System\\{$childGroup}\\{$childModule}"
            : "App\\Project\\Modules\\{$childGroup}\\{$childModule}";
    }

    /**
     * Generate the extract block placed before validateData() in execute().
     * Pulls each inline-items key out of $data into $inlineData before validation
     * so the parent validator never sees the child-record arrays.
     */
    protected function generateInlineItemsExtract(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        foreach ($inlineItems as $item) {
            $key = $item['key'];
            $lines[] = "\$inlineData['{$key}'] = \$data['{$key}'] ?? [];";
            $lines[] = "unset(\$data['{$key}']);";
        }
        return implode("\n            ", $lines);
    }

    /**
     * Build the array_merge() injection block for a single inline item:
     * parent_fk + any inject_from_parent fields.
     */
    protected function buildInlineInjectArray(array $item): string
    {
        $pairs   = [];
        $pairs[] = "'{$item['parent_fk']}' => \$model->id";

        foreach ($item['inject_from_parent'] ?? [] as $mapping) {
            $childField  = $mapping['child_field'];
            $parentField = $mapping['parent_field'];
            $pairs[] = "'{$childField}' => \$model->{$parentField}";
        }

        return "[\n                " . implode(",\n                ", $pairs) . ",\n            ]";
    }

    protected function generateCustomFieldProcessing(string $featureKey, string $stage = 'before'): string
    {
        $fields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];

        $lines = [];
        foreach ($fields as $field) {
            $fieldName = $field['field'] ?? '';
            $service = $field['processing_service'] ?? null;
            $module = $field['processing_module'] ?? null;
            $fieldStage = $field['processing_stage'] ?? 'before';

            if ($service && $module && $fieldStage === $stage) {
                // Resolve full namespace from DB (module_type + group). The config
                // field 'processing_module_group' cannot be trusted as the top-level group —
                // DB lookup via PathManager is authoritative.
                $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
                $namespace = "{$ns}\\Services\\{$service}";
                $rules = $field['rules'] ?? '';
                $isArray = str_contains($rules, 'array');

                $lines[] = "// Processing {$fieldName} using {$service}";
                $lines[] = "if (isset(\$validData['{$fieldName}'])) {";
                if ($isArray) {
                    $lines[] = "    foreach (\$validData['{$fieldName}'] as \$item) {";
                    $lines[] = "        \\{$namespace}::process(\$item);";
                    $lines[] = "    }";
                } else {
                    $lines[] = "    \\{$namespace}::process(\$validData['{$fieldName}']);";
                }
                $lines[] = "}";
            }
        }

        return empty($lines) ? '// No custom field processing' : implode("\n        ", $lines);
    }
}

