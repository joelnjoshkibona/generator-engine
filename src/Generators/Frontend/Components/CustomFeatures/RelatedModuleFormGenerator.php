<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class RelatedModuleFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        // This generator should be called via generateRelatedModuleForms()
        return false;
    }

    public function generateRelatedModuleForms(string $featureKey, array $customFeature): bool
    {
        $displayType = $customFeature['displayType'] ?? 'header-action';
        // Support both tab-action and header-action
        if (!in_array($displayType, ['tab-action', 'header-action'])) {
            return false;
        }

        $relatedModule = $customFeature['relatedModule'] ?? null;
        if (empty($relatedModule)) {
            return false;
        }

        // Handle relatedModule as object or string
        $relatedModuleName = '';
        if (is_array($relatedModule)) {
            $relatedModuleName = $relatedModule['name'] ?? '';
        } else {
            $relatedModuleName = $relatedModule;
        }

        if (empty($relatedModuleName)) {
            return false;
        }

        // Resolve the related module's real module_type and sub-group from the DB.
        // The `group` field in relatedModule config is the sub-group name (e.g. "Inventory"),
        // NOT the module_type (e.g. "System"). Using the group name as the top-level path
        // segment produces wrong paths like modules/inventory/inventory/InventoryTransferItem
        // instead of the correct modules/system/inventory/InventoryTransferItem.
        $relatedModuleGroup = 'Core';
        $originalSubGroup = PathManager::getModuleSubGroup();

        // 1. Self-reference: this module isn't in the registry yet while it's
        // still being generated (see DelegationServiceGenerator's identical
        // fix/rationale) -- use this generator's own known context directly.
        if ($relatedModuleName === $this->moduleName) {
            $relatedModuleGroup = $this->moduleGroup;
            PathManager::setModuleSubGroup($this->moduleSubGroup);
        } else {
            // 2. Module registry (array-based, decoupled) -- authoritative
            // whenever the related module is already known.
            $registryEntry = PathManager::findModuleInRegistry($relatedModuleName);
            if ($registryEntry !== null) {
                $relatedModuleGroup = PathManager::normalizeGroupName($registryEntry['module_type'] ?? $registryEntry['type'] ?? 'Core');
                PathManager::setModuleSubGroup($registryEntry['group_name'] ?? null);
            } elseif (!empty($relatedModule['group']) && is_array($relatedModule)) {
                // 3. Not in the registry yet -- e.g. a delegation pointing from
                // a parent module (FK target, scaffolded first) to a child
                // module (FK source, scaffolded later). The caller-declared
                // sub-group predates and doesn't depend on generation order;
                // combine it with this module's own top-level group, the same
                // assumption DelegationServiceGenerator::resolveRelatedModuleGroupPath()
                // documents and relies on.
                $relatedModuleGroup = $this->moduleGroup;
                PathManager::setModuleSubGroup($relatedModule['group']);
            }
        }

        // Get backend features to determine what to generate
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $frontendFeatures = $customFeature['features']['frontend'] ?? [];

        $results = [];

        // Generate CreateForm if create is enabled
        if (($backendFeatures['create']['enabled'] ?? false) && !empty($frontendFeatures['create']['fields'])) {
            $results[] = $this->generateCreateForm($relatedModuleName, $relatedModuleGroup, $customFeature);
        }

        // Generate EditForm if edit is enabled
        if (($backendFeatures['edit']['enabled'] ?? false) && !empty($frontendFeatures['edit']['fields'])) {
            $results[] = $this->generateEditForm($relatedModuleName, $relatedModuleGroup, $customFeature);
        }

        // Generate ViewComponent if view is enabled
        if (($backendFeatures['view']['enabled'] ?? false)) {
            $results[] = $this->generateViewComponent($relatedModuleName, $relatedModuleGroup, $customFeature);
        }

        // Restore the original sub-group context for the parent module
        PathManager::setModuleSubGroup($originalSubGroup);

        return !empty($results) && !in_array(false, $results);
    }

    protected function generateCreateForm(string $relatedModuleName, string $relatedModuleGroup, array $customFeature): bool
    {
        $content = $this->getTemplateContent('features/custom/create_form', 'frontend');
        
        $frontendCreate = $customFeature['features']['frontend']['create'] ?? [];
        $createFields = $frontendCreate['fields'] ?? [];
        
        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $splashData = '';
        $hasSplash = false;

        if (!empty($createFields) && is_array($createFields)) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createFields);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
        }

        // Get endpoint configuration from backend create config
        $backendCreate = $customFeature['features']['backend']['create'] ?? [];
        $parentKey = $customFeature['parentKey'] ?? 'uuid';
        $moduleNameLower = \Illuminate\Support\Str::kebab($this->moduleName);
        $featureNameLower = \Illuminate\Support\Str::kebab($customFeature['name'] ?? '');
        
        // Get base path from config or construct default
        $basePath = $backendCreate['endpoint']['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureNameLower}";
        // Ensure it starts with /
        $basePath = '/' . ltrim($basePath, '/');
        // Remove any existing operation suffix to get clean base path
        $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        // Build complete URLs with template literals for dynamic parts
        // Replace parent key placeholder with template literal
        $submitEndpoint = str_replace("{{$parentKey}}", '${props.parentUuid}', $basePath) . '/create';
        $splashEndpoint = str_replace("{{$parentKey}}", '${props.parentUuid}', $basePath) . '/create/splash';

        // Replace placeholders - using the custom stub template
        $content = $this->replacePlaceholders($content, [
            '[[ModuleName]]' => $relatedModuleName,
            '[[moduleName]]' => strtolower($relatedModuleName),
            '[[moduleRoute]]' => \Illuminate\Support\Str::kebab($relatedModuleName),
            '[[ModuleNamePlural]]' => \Illuminate\Support\Str::plural($relatedModuleName),
            '[[formSections]]' => $formSections,
            '[[formFields]]' => $formFields,
            '[[formFieldImports]]' => $formFieldImports,
            '[[splashData]]' => $splashData,
            '[[hasSplash]]' => $hasSplash ? 'true' : 'false',
            '[[submitEndpoint]]' => $submitEndpoint,
            '[[splashEndpoint]]' => $splashEndpoint,
        ]);

        $filePath = PathManager::getFrontendModulePath($relatedModuleGroup, $relatedModuleName)
            . "/Components/{$relatedModuleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }

    protected function generateEditForm(string $relatedModuleName, string $relatedModuleGroup, array $customFeature): bool
    {
        $content = $this->getTemplateContent('features/custom/edit_form', 'frontend');
        
        $frontendEdit = $customFeature['features']['frontend']['edit'] ?? [];
        $editFields = $frontendEdit['fields'] ?? [];
        
        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $splashData = '';
        $hasSplash = false;

        if (!empty($editFields) && is_array($editFields)) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editFields);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
        }

        // Get endpoint configuration from backend edit config
        $backendEdit = $customFeature['features']['backend']['edit'] ?? [];
        $parentKey = $customFeature['parentKey'] ?? 'uuid';
        $moduleNameLower = \Illuminate\Support\Str::kebab($this->moduleName);
        $featureNameLower = \Illuminate\Support\Str::kebab($customFeature['name'] ?? '');
        
        // Get base path from config or construct default
        $basePath = $backendEdit['endpoint']['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureNameLower}";
        // Ensure it starts with /
        $basePath = '/' . ltrim($basePath, '/');
        // Remove any existing operation suffix to get clean base path
        $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        // Determine item UUID parameter name (from config or default)
        $itemUuidParam = '';
        if (preg_match('/\{([^}]+)\}/', $basePath, $matches)) {
            // Check if there's already an item UUID param in the path
            $pathParts = explode('/', trim($basePath, '/'));
            foreach ($pathParts as $part) {
                if (preg_match('/^\{([^}]+)\}$/', $part, $paramMatch) && $paramMatch[1] !== $parentKey) {
                    $itemUuidParam = $paramMatch[1];
                    break;
                }
            }
        }
        // If no item UUID param found, use default pattern
        if (empty($itemUuidParam)) {
            $itemUuidParam = ($customFeature['name'] ?? 'item') . '_id';
        }
        
        // Get view endpoint base path (for loading existing data)
        $backendViewForEdit = $customFeature['features']['backend']['view'] ?? [];
        $viewBasePath = $backendViewForEdit['endpoint']['path'] ?? $basePath;
        // Clean view base path
        $viewBasePath = '/' . ltrim($viewBasePath, '/');
        $viewBasePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $viewBasePath);
        $viewBasePath = rtrim($viewBasePath, '/');
        
        // Build complete URLs with template literals for dynamic parts
        // Submit endpoint: includes both parent and item UUID
        $submitEndpoint = str_replace("{{$parentKey}}", '${props.parentUuid}', $basePath);
        $submitEndpoint = str_replace("{{$itemUuidParam}}", '${props.uuid}', $submitEndpoint);
        // If item UUID param not in path, append it
        if (!str_contains($submitEndpoint, '${props.uuid}')) {
            $submitEndpoint .= '/${props.uuid}';
        }
        $submitEndpoint .= '/edit';
        
        // Splash endpoint: same as create (no item UUID)
        $splashBasePath = str_replace("{{$parentKey}}", '${props.parentUuid}', $basePath);
        // Remove item UUID param if present
        $splashBasePath = preg_replace('/\/\{' . preg_quote($itemUuidParam, '/') . '\}/', '', $splashBasePath);
        $splashBasePath = str_replace('{' . $itemUuidParam . '}', '', $splashBasePath);
        $splashEndpoint = $splashBasePath . '/create/splash';
        
        // View endpoint: includes both parent and item UUID
        $viewEndpoint = str_replace("{{$parentKey}}", '${props.parentUuid}', $viewBasePath);
        $viewEndpoint = str_replace("{{$itemUuidParam}}", '${props.uuid}', $viewEndpoint);
        // If item UUID param not in path, append it
        if (!str_contains($viewEndpoint, '${props.uuid}')) {
            $viewEndpoint .= '/${props.uuid}';
        }
        $viewEndpoint .= '/view';

        // Replace placeholders - using the custom stub template
        $content = $this->replacePlaceholders($content, [
            '[[ModuleName]]' => $relatedModuleName,
            '[[moduleName]]' => strtolower($relatedModuleName),
            '[[moduleRoute]]' => \Illuminate\Support\Str::kebab($relatedModuleName),
            '[[ModuleNamePlural]]' => \Illuminate\Support\Str::plural($relatedModuleName),
            '[[formSections]]' => $formSections,
            '[[formFields]]' => $formFields,
            '[[formFieldImports]]' => $formFieldImports,
            '[[splashData]]' => $splashData,
            '[[hasSplash]]' => $hasSplash ? 'true' : 'false',
            '[[submitEndpoint]]' => $submitEndpoint,
            '[[splashEndpoint]]' => $splashEndpoint,
            '[[viewEndpoint]]' => $viewEndpoint,
        ]);

        $filePath = PathManager::getFrontendModulePath($relatedModuleGroup, $relatedModuleName)
            . "/Components/{$relatedModuleName}EditForm.vue";

        return $this->writeFile($filePath, $content);
    }

    protected function generateViewComponent(string $relatedModuleName, string $relatedModuleGroup, array $customFeature): bool
    {
        $content = $this->getTemplateContent('features/custom/view_component', 'frontend');
        
        $frontendView = $customFeature['features']['frontend']['view'] ?? [];
        $viewFields = $frontendView['fields'] ?? [];
        
        $sections = '';
        $formatDateImport = '';

        if (!empty($viewFields) && is_array($viewFields)) {
            $mappedFields = $this->mapViewFieldsToInformationFields($viewFields);
            $tempConfig = ['sections' => [[
                'key' => 'information',
                'title' => 'Overview',
                'fields' => $mappedFields,
            ]]];
            $sections = $this->generateViewSections($tempConfig);
            $formatDateImport = $this->generateFormatDateImport($tempConfig);
        } else {
            // Generate basic sections if no view fields configured
            $sections = $this->generateBasicViewSections();
        }

        // Get endpoint configuration from backend view config
        $backendView = $customFeature['features']['backend']['view'] ?? [];
        $parentKey = $customFeature['parentKey'] ?? 'uuid';
        $moduleNameLower = \Illuminate\Support\Str::kebab($this->moduleName);
        $featureNameLower = \Illuminate\Support\Str::kebab($customFeature['name'] ?? '');
        
        // Get base path from config or construct default
        $basePath = $backendView['endpoint']['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureNameLower}";
        // Ensure it starts with /
        $basePath = '/' . ltrim($basePath, '/');
        // Remove any existing operation suffix to get clean base path
        $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        // Determine item UUID parameter name (from config or default)
        $itemUuidParam = '';
        if (preg_match('/\{([^}]+)\}/', $basePath, $matches)) {
            // Check if there's already an item UUID param in the path
            $pathParts = explode('/', trim($basePath, '/'));
            foreach ($pathParts as $part) {
                if (preg_match('/^\{([^}]+)\}$/', $part, $paramMatch) && $paramMatch[1] !== $parentKey) {
                    $itemUuidParam = $paramMatch[1];
                    break;
                }
            }
        }
        // If no item UUID param found, use default pattern
        if (empty($itemUuidParam)) {
            $itemUuidParam = ($customFeature['name'] ?? 'item') . '_id';
        }

        // Build complete URL with template literals for dynamic parts
        // View endpoint: includes both parent and item UUID
        $viewEndpoint = str_replace("{{$parentKey}}", '${props.parentUuid}', $basePath);
        $viewEndpoint = str_replace("{{$itemUuidParam}}", '${props.uuid}', $viewEndpoint);
        // If item UUID param not in path, append it
        if (!str_contains($viewEndpoint, '${props.uuid}')) {
            $viewEndpoint .= '/${props.uuid}';
        }
        $viewEndpoint .= '/view';

        // Replace placeholders - using the custom stub template
        $content = $this->replacePlaceholders($content, [
            '[[ModuleName]]' => $relatedModuleName,
            '[[moduleName]]' => strtolower($relatedModuleName),
            '[[sections]]' => $sections,
            '[[formatDateImport]]' => $formatDateImport,
            '[[viewEndpoint]]' => $viewEndpoint,
        ]);

        $filePath = PathManager::getFrontendModulePath($relatedModuleGroup, $relatedModuleName)
            . "/Components/{$relatedModuleName}ViewComponent.vue";

        return $this->writeFile($filePath, $content);
    }
    
    /**
     * Generate basic view sections when no view fields are configured
     */
    protected function generateBasicViewSections(): string
    {
        return "\t\t<!-- Overview Section -->
\t\t<div>
\t\t\t<div class=\"flex items-center gap-1 mb-2\">
\t\t\t\t<component :is=\"icons['InfoIcon']\" class=\"h-4 w-4 text-gray-500\" />
\t\t\t\t<h3 class=\"text-sm font-medium\">Overview</h3>
\t\t\t</div>
\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-4 rounded-md border\">
\t\t\t\t<div class=\"flex flex-col\">
\t\t\t\t\t<span class=\"text-xs text-gray-500\">Data</span>
\t\t\t\t\t<p>{{ JSON.stringify(data, null, 2) || 'Not specified' }}</p>
\t\t\t\t</div>
\t\t\t</div>
\t\t</div>

\t\t<!-- Timeline Section -->
\t\t<div>
\t\t\t<div class=\"flex items-center gap-1 mb-2\">
\t\t\t\t<component :is=\"icons['ClockIcon']\" class=\"h-4 w-4 text-gray-500\" />
\t\t\t\t<h3 class=\"text-sm font-medium\">Timeline</h3>
\t\t\t</div>
\t\t\t<div class=\"bg-gray-50 p-4 rounded-md border\">
\t\t\t\t<div class=\"space-y-3\">
\t\t\t\t\t<div class=\"flex items-center justify-between\">
\t\t\t\t\t\t<span class=\"text-sm text-gray-600\">Created</span>
\t\t\t\t\t\t<span class=\"text-sm\">{{ data?.created_at || 'Not specified' }}</span>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"flex items-center justify-between\">
\t\t\t\t\t\t<span class=\"text-sm text-gray-600\">Last Updated</span>
\t\t\t\t\t\t<span class=\"text-sm\">{{ data?.updated_at || 'Not specified' }}</span>
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>
\t\t</div>";
    }
}

