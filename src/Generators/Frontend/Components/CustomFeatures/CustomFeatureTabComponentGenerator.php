<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class CustomFeatureTabComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        // This generator should be called via generateCustomFeature()
        return false;
    }

    public function generateCustomFeature(string $featureKey, array $customFeature): bool
    {
        $displayType = $customFeature['displayType'] ?? 'header-action';
        if ($displayType !== 'tab-action') {
            return false;
        }
        
        $content = $this->getTemplateContent('features/custom/tab_action', 'frontend');
        
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $relatedModule = $customFeature['relatedModule'] ?? null;
        
        // Handle relatedModule as object or string
        $relatedModuleName = '';
        if ($relatedModule) {
            if (is_array($relatedModule)) {
                $relatedModuleName = $relatedModule['name'] ?? '';
            } else {
                $relatedModuleName = $relatedModule;
            }
        }
        
        // Use enabled flags from features.backend (preferred) or enabledOperations (fallback)
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $enabledOps = $customFeature['enabledOperations'] ?? [];
        
        $hasCreate = isset($backendFeatures['create']['enabled']) 
            ? ($backendFeatures['create']['enabled'] ?? false)
            : ($enabledOps['create'] ?? true);
        $hasEdit = isset($backendFeatures['edit']['enabled'])
            ? ($backendFeatures['edit']['enabled'] ?? false)
            : ($enabledOps['edit'] ?? true);
        $hasView = isset($backendFeatures['view']['enabled'])
            ? ($backendFeatures['view']['enabled'] ?? false)
            : ($enabledOps['view'] ?? true);
        $hasDelete = isset($backendFeatures['delete']['enabled'])
            ? ($backendFeatures['delete']['enabled'] ?? false)
            : ($enabledOps['delete'] ?? true);
        
        // Generate columns with primary column pattern (same as ListComponentGenerator)
        $listConfig = $customFeature['features']['frontend']['list'] ?? [];
        $listFields = $listConfig['fields'] ?? [];
        $columns = '';
        $primaryKey = 'name';
        $primaryCellContent = '';
        $customCellRenderers = '';
        
        if (!empty($listFields) && is_array($listFields)) {
            // Get primary field key first (needed for filtering)
            $primaryFieldKey = $listConfig['primaryField'] ?? '';
            if (empty($primaryFieldKey) && !empty($listFields)) {
                $firstField = $listFields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? 'name';
            }
            $primaryKey = $primaryFieldKey ?: 'name';
            
            // Generate columns from list fields (pass primary key to ensure correct column structure)
            $columns = $this->generateColumnsFromListFields($listFields, $primaryKey);
            
            // Generate primary cell content for responsive display (excludes primary field).
            // Both helpers default to emitting "row.xxx" — correct for list/component.stub +
            // list/page.stub, which wrap <ListTable>/<ReportTable> (:row="row"). This generator
            // instead splices its output into features/custom/tab_action.stub, which wraps
            // <ListPageBareTable> — that component's cell slots expose :item="item", never
            // :row (see ListPageBareTable.vue). Pass 'item' explicitly to both calls so the
            // accessor prop name matches the stub's actual slot props.
            $primaryCellContent = $this->generatePrimaryCellContentFromListFields($listFields, $primaryKey, 'item');

            // Generate custom cell renderers for badge/boolean fields — pass 'item' for the
            // same reason as above; tab_action.stub's slots destructure { item }, not { row }.
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listFields, $primaryKey, 'item');
        }
        
        // Build parent-scoped endpoints from module/feature routes.
        // Tab components always live under a parent details page — the parent UUID
        // comes from route.params.uuid (reliable on hard refresh), not props.data.
        $moduleNameLower = \Illuminate\Support\Str::kebab($this->moduleName);
        $featureNameLower = \Illuminate\Support\Str::kebab($featureName);

        // /{parent-route}/${uuid.value}/{feature-route}/list
        $endpointPath = "/{$moduleNameLower}/\${uuid.value}/{$featureNameLower}/list";
        
        // Generate component imports for related module forms
        $componentImports = '';
        if (!empty($relatedModuleName)) {
            $importSegment = PathManager::resolveFrontendImportSegment($relatedModuleName);
            $imports = [];

            // Skip related-module component imports when module can't be resolved.
            // Prevents broken paths like "modules//X/..." that crash the build.
            if (empty($importSegment)) {
                \Illuminate\Support\Facades\Log::warning("CustomFeatureTabComponentGenerator: related module '{$relatedModuleName}' not resolvable for delegation on {$this->moduleName}; skipping component imports");
                $componentImports = "// Related module '{$relatedModuleName}' not found — component imports skipped";
                $relatedModuleName = ''; // neutralize downstream usage in template
            } else {

            if ($hasCreate) {
                $imports[] = "import {$relatedModuleName}CreateForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}CreateForm.vue\";";
            }
            if ($hasEdit) {
                $imports[] = "import {$relatedModuleName}EditForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}EditForm.vue\";";
            }
            if ($hasView) {
                $imports[] = "import {$relatedModuleName}ViewComponent from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}ViewComponent.vue\";";
            }
            if ($hasDelete) {
                $imports[] = "import {$relatedModuleName}DeleteForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}DeleteForm.vue\";";
            }

            $componentImports = implode("\n", $imports);
            }
        }
        
        // Get filterKey for passing parent ID in defaults
        $filterKey = $customFeature['filterKey'] ?? 'parent_id';
        
        // Get hiddens and defaults from frontend config
        $frontendCreate = $customFeature['features']['frontend']['create'] ?? [];
        $frontendEdit = $customFeature['features']['frontend']['edit'] ?? [];
        
        $createHiddens = $frontendCreate['hiddens'] ?? [];
        $createDefaults = $frontendCreate['defaults'] ?? [];
        $editHiddens = $frontendEdit['hiddens'] ?? [];
        $editDefaults = $frontendEdit['defaults'] ?? [];
        
        // Ensure filterKey is always hidden and has default value
        if (!isset($createHiddens[$filterKey])) {
            $createHiddens[$filterKey] = true;
        }
        if (!isset($createDefaults[$filterKey])) {
            $createDefaults[$filterKey] = 'uuid'; // Will be replaced with actual uuid in template
        }
        if (!isset($editHiddens[$filterKey])) {
            $editHiddens[$filterKey] = true;
        }
        if (!isset($editDefaults[$filterKey])) {
            $editDefaults[$filterKey] = 'uuid'; // Will be replaced with actual uuid in template
        }
        
        // Convert hiddens to JSON for template replacement
        $createHiddensJson = json_encode($createHiddens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $editHiddensJson = json_encode($editHiddens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Convert defaults to JavaScript object literal format
        $createDefaultsJs = $this->convertDefaultsToJs($createDefaults);
        $editDefaultsJs = $this->convertDefaultsToJs($editDefaults);
        
        $content = $this->replacePlaceholders($content, [
            '[[FeatureName]]' => $featureName,
            '[[featureName]]' => strtolower($featureName),
            '[[RelatedModule]]' => $relatedModuleName,
            '[[relatedModule]]' => strtolower($relatedModuleName),
            '[[columns]]' => $columns,
            '[[primaryKey]]' => $primaryKey,
            '[[primaryCellContent]]' => $primaryCellContent,
            '[[customCellRenderers]]' => $customCellRenderers,
            '[[hasCreate]]' => $hasCreate ? 'true' : 'false',
            '[[hasEdit]]' => $hasEdit ? 'true' : 'false',
            '[[hasView]]' => $hasView ? 'true' : 'false',
            '[[hasDelete]]' => $hasDelete ? 'true' : 'false',
            '[[apiEndpointPath]]' => $endpointPath,
            '[[componentImports]]' => $componentImports,
            '[[filterKey]]' => $filterKey,
            '[[createHiddens]]' => $createHiddensJson,
            '[[createDefaults]]' => $createDefaultsJs,
            '[[editHiddens]]' => $editHiddensJson,
            '[[editDefaults]]' => $editDefaultsJs,
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}{$featureName}Tab.vue";
        
        return $this->writeFile($filePath, $content);
    }
    
    /**
     * Convert defaults array to JavaScript object literal format
     * Handles JavaScript expressions like "props.data.uuid" as strings
     */
    protected function convertDefaultsToJs(array $defaults): string
    {
        $lines = [];
        foreach ($defaults as $key => $value) {
            if (is_string($value) && (strpos($value, 'props.') === 0 || strpos($value, 'data.') === 0 || preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*(\.[a-zA-Z_$][a-zA-Z0-9_$]*)*$/', $value))) {
                // JavaScript expression (props.data.uuid, uuid, etc.) - use as-is
                $lines[] = "\t\t\t{$key}: {$value},";
            } elseif (is_bool($value)) {
                $lines[] = "\t\t\t{$key}: " . ($value ? 'true' : 'false') . ",";
            } elseif (is_null($value)) {
                $lines[] = "\t\t\t{$key}: null,";
            } elseif (is_numeric($value)) {
                $lines[] = "\t\t\t{$key}: {$value},";
            } else {
                // String value - quote it
                $lines[] = "\t\t\t{$key}: " . json_encode($value) . ",";
            }
        }
        return implode("\n", $lines);
    }
}

