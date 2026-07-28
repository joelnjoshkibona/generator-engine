<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

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
        
        // Needed before the column work below, which strips the parent FK column.
        $filterKey = $customFeature['filterKey'] ?? 'parent_id';

        // Generate columns with primary column pattern (same as ListComponentGenerator)
        $listConfig = $customFeature['features']['frontend']['list'] ?? [];
        $listFields = $listConfig['fields'] ?? [];

        // A delegation entry carries only its operation flags — buildDelegationEntry()
        // never populates features.frontend.list.fields — so this was ALWAYS empty
        // and every delegation tab rendered `const columns = []`. The child list
        // fetched its rows correctly (200, meta 1-1 of 1) and then displayed a
        // table with no header and no rows, because ListTable has nothing to
        // render without columns. The real source is the RELATED module's own
        // module.json.
        if (empty($listFields)) {
            $relatedConfig = $this->loadRelatedModuleConfig($customFeature);
            $listConfig = $relatedConfig['features']['frontend']['list'] ?? $listConfig;
            $listFields = $listConfig['fields'] ?? [];
        }
        $columns = '';
        $primaryKey = 'name';
        $primaryCellContent = '';
        $customCellRenderers = '';
        
        // Drop the column that points back at the parent. Inside Item Details
        // every row's "Item" is the same record, so the column carries no
        // information — and it rendered "N/A" anyway, because the child list
        // does not eager-load the parent relation. Removing it is the fix;
        // adding the join would only make a redundant column populate.
        if (!empty($listFields) && is_array($listFields) && $filterKey !== '') {
            $listFields = array_values(array_filter(
                $listFields,
                static fn (array $f): bool => ($f['key'] ?? $f['field'] ?? '') !== $filterKey
            ));
        }

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

            // Column labels are emitted as t('{module}.col_x') using THIS
            // generator's module — the parent. The columns belong to the related
            // module, whose locale file is the one that actually defines those
            // keys, so the parent namespace resolved to nothing and vue-i18n
            // printed the raw key ("items.col_label") as the header.
            $relatedName = $customFeature['relatedModule']['name']
                ?? (is_string($customFeature['relatedModule'] ?? null) ? $customFeature['relatedModule'] : '');
            if ($relatedName !== '') {
                $columns = str_replace(
                    "t('" . Str::kebab($this->moduleName) . ".col_",
                    "t('" . Str::kebab($relatedName) . ".col_",
                    $columns
                );
            }
            
            // Generate primary cell content for responsive display (excludes primary field).
            // Both helpers default to emitting "row.xxx" — correct for list/component.stub +
            // list/page.stub, which wrap <ListTable>/<ReportTable> (:row="row"). This generator
            // instead splices its output into features/custom/tab_action.stub, which wraps
            // <ListPageBareTable> — that component's cell slots expose :item="item", never
            // :row (see ListPageBareTable.vue). Pass 'item' explicitly to both calls so the
            // accessor prop name matches the stub's actual slot props.
            $primaryCellContent = $this->generatePrimaryCellContentFromListFields($listFields, $primaryKey, 'row');

            // Generate custom cell renderers for badge/boolean fields — pass 'item' for the
            // same reason as above; tab_action.stub's slots destructure { item }, not { row }.
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listFields, $primaryKey, 'row');
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
        // The FK default must be the parent's `id`, NOT its uuid. filterKey is an
        // integer foreign key (item_id), while `uuid` here is the route's uuid
        // string — sending it produced a 422 "The item id field must be an
        // integer" on every create from a delegation tab. `parentIdField`
        // (default 'id') names the parent attribute to send, and the parent
        // record arrives on the tab as props.data.
        if (!isset($createDefaults[$filterKey])) {
            $createDefaults[$filterKey] = 'parentId';
        }
        if (!isset($editHiddens[$filterKey])) {
            $editHiddens[$filterKey] = true;
        }
        if (!isset($editDefaults[$filterKey])) {
            $editDefaults[$filterKey] = 'parentId';
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
            '[[parentIdField]]' => $customFeature['parentIdField'] ?? 'id',
            '[[filterKey]]' => $filterKey,
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

    /**
     * Read the related module's persisted module.json.
     *
     * The delegation only names its related module; the columns to render live
     * in that module's own config. Resolved through the registry so a nested
     * module (System/Inventory/ItemImages) is found at its real path.
     */
    protected function loadRelatedModuleConfig(array $customFeature): array
    {
        $related = $customFeature['relatedModule'] ?? null;
        $name = is_array($related) ? ($related['name'] ?? '') : (string) $related;
        if ($name === '') {
            return [];
        }

        $entry = PathManager::findModuleInRegistry($name);
        $group = PathManager::normalizeGroupName($entry['module_type'] ?? $entry['type'] ?? 'Core');
        $subGroup = $entry['group_name'] ?? null;

        $original = PathManager::getModuleSubGroup();
        PathManager::setModuleSubGroup($subGroup);
        $path = PathManager::getBackendModulePath($group, $name) . '/module.json';
        PathManager::setModuleSubGroup($original);

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

}

