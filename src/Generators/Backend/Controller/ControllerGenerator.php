<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class ControllerGenerator extends BaseGenerator
{
    protected array $features;
    protected array $delegations;
    protected array $actions;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features strictly from new payload: features.backend.
        // createSplash / editSplash are opt-in — only included when constants are declared.
        $this->features = [];
        $backendFeatures = $config['features']['backend'] ?? [];
        $hasSplash = !empty($config['constants']);
        $standardFeatures = ['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'];
        if ($hasSplash) {
            $standardFeatures[] = 'createSplash';
            $standardFeatures[] = 'editSplash';
        }
        foreach ($standardFeatures as $featureName) {
            $this->features[$featureName] = [true];
        }

        $this->delegations = $config['delegations'] ?? [];
        $this->actions = $config['actions'] ?? [];
    }

    public function generate(): bool
    {
        $content = $this->getTemplateContent('controller', 'backend');

        // Generate methods for standard features (remove duplicates)
        $methods = [];
        $processedFeatures = [];
        foreach ($this->features as $feature => $columns) {
            if (!in_array($feature, $processedFeatures)) {
                if (!in_array($feature, ['createSplash', 'editSplash'])) {
                    $methods[] = $this->generateControllerMethod($feature);
                }
                $processedFeatures[] = $feature;
            }
        }
        // Generate splash methods if their corresponding features are enabled
        // createSplash is generated if 'create' feature is enabled
        if (isset($this->features['create']) && isset($this->features['createSplash'])) {
            $methods[] = $this->generateControllerMethod('createSplash');
        }
        
        // editSplash is generated if 'edit' feature is enabled
        if (isset($this->features['edit']) && isset($this->features['editSplash'])) {
            $methods[] = $this->generateControllerMethod('editSplash');
        }

        // Generate export/import methods if enabled in list config
        $listConfig = $this->config['features']['backend']['list'] ?? null;
        if (!empty($listConfig)) {
            if (!empty($listConfig['export'])) {
                $methods[] = $this->generateExportMethod();
            }
            if (!empty($listConfig['import'])) {
                $methods[] = $this->generateImportTemplateMethod();
                $methods[] = $this->generateImportMethod();
            }
        }

        // Generate methods for delegations
        foreach ($this->delegations as $delegationKey => $delegation) {
            $methods[] = $this->generateDelegationMethods($delegationKey, $delegation);
        }

        // Generate methods for actions
        foreach ($this->actions as $actionKey => $action) {
            $methods[] = $this->generateActionMethods($actionKey, $action);
        }

        // Build all use statements
        $servicesNs = $this->getNamespace() . "\\Services";
        $uses = [];
        $uses[] = "use App\\Project\\_Src\\Traits\\HasActivityHistory;";

        // Standard service imports (always present)
        $standardServices = ['List', 'Create', 'View', 'Edit', 'Delete', 'DeleteCheck', 'ActivityList'];
        // Splash services only when constants are declared (splash is opt-in)
        if (!empty($this->config['constants'])) {
            $standardServices[] = 'CreateSplash';
            $standardServices[] = 'EditSplash';
        }
        foreach ($standardServices as $svc) {
            $uses[] = "use {$servicesNs}\\{$this->moduleName}{$svc}Service;";
        }

        // Delegation service imports
        foreach ($this->delegations as $delegationKey => $delegation) {
            $delegationName = \Illuminate\Support\Str::studly($delegation['name'] ?? $delegationKey);
            $uses[] = "use {$servicesNs}\\{$this->moduleName}{$delegationName}Service;";
        }

        // Action service imports
        foreach ($this->actions as $actionKey => $action) {
            $actionName = \Illuminate\Support\Str::studly($action['name'] ?? $actionKey);
            $serviceNameRaw = $action['serviceName'] ?? $actionName;
            if (str_starts_with($serviceNameRaw, $this->moduleName)) {
                $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
            }
            if (str_ends_with($serviceNameRaw, 'Service')) {
                $serviceNameRaw = substr($serviceNameRaw, 0, -7);
            }
            $uses[] = "use {$servicesNs}\\{$this->moduleName}{$serviceNameRaw}Service;";
        }

        $usesBlock = implode("\n", array_unique($uses));
        $content = str_replace(
            "use App\Http\Controllers\Controller;",
            "use App\Http\Controllers\Controller;\n{$usesBlock}",
            $content
        );

        $activityTrait = "    use HasActivityHistory;\n\n    protected string \$activityServiceClass = {$this->moduleName}ActivityListService::class;\n\n";

        $content = $this->replacePlaceholders($content, [
            '[[methods]]' => $activityTrait . implode("\n\n", $methods)
        ]);

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";

        return $this->writeFile($filePath, $content);
    }

    protected function generateControllerMethod(string $feature): string
    {
        $content = $this->getTemplateContent("Features/{$feature}/controller_method", 'backend');
        return $this->replacePlaceholders($content);
    }

    protected function generateDelegationMethods(string $delegationKey, array $delegation): string
    {
        $methods = [];
        $delegationName = \Illuminate\Support\Str::studly($delegation['name'] ?? $delegationKey);
        $parentKey = $delegation['parentKey'] ?? 'uuid';
        $operations = $delegation['operations'] ?? [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $stub = $this->getTemplateContent("Features/delegation/controller_method_{$op}", 'backend');
            $methods[] = $this->replacePlaceholders($stub, [
                '[[DelegationName]]' => $delegationName,
                '[[parentKey]]' => $parentKey,
            ]);
        }

        return implode("\n\n", $methods);
    }

    protected function generateActionMethods(string $actionKey, array $action): string
    {
        $methods = [];
        $actionName = \Illuminate\Support\Str::studly($action['name'] ?? $actionKey);
        $serviceNameRaw = $action['serviceName'] ?? $actionName;
        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }

        $urlParamsArr = $action['urlParams'] ?? [];
        $urlParamsDecl = '';
        $urlParamsArgs = '';
        if (!empty($urlParamsArr)) {
            $urlParamsDecl = ', ' . implode(', ', array_map(fn($p) => "string \${$p}", $urlParamsArr));
            $urlParamsArgs = ', ' . implode(', ', array_map(fn($p) => "\${$p}", $urlParamsArr));
        }

        $stub = $this->getTemplateContent('Features/action/controller_method', 'backend');

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($action['operations'][$op]['enabled'])) {
                continue;
            }

            $baseMethod = $action['methodName'] ?? $actionName;
            $methodName = $op === 'list' ? lcfirst($baseMethod) : $op . ucfirst($baseMethod);

            $methods[] = $this->replacePlaceholders($stub, [
                '[[methodName]]' => $methodName,
                '[[ActionName]]' => $serviceNameRaw,
                '[[urlParams]]' => $urlParamsDecl,
                '[[urlParamsArgs]]' => $urlParamsArgs,
            ]);
        }

        return implode("\n\n", $methods);
    }

    /** @deprecated Use generateDelegationMethods or generateActionMethods */
    protected function generateCustomFeatureMethods(string $featureKey, array $customFeature): string
    {
        $methods = [];
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $enabledOps = $customFeature['enabledOperations'] ?? []; // Fallback for backward compatibility

        // Bare-endpoint: Support multiple operations (list, create, view, edit, delete)
        if ($displayType === 'bare-endpoint') {
            // Check each operation's enabled flag
            $listEnabled = isset($backendFeatures['list']['enabled']) 
                ? ($backendFeatures['list']['enabled'] ?? false)
                : ($enabledOps['list'] ?? true);
            $createEnabled = isset($backendFeatures['create']['enabled'])
                ? ($backendFeatures['create']['enabled'] ?? false)
                : ($enabledOps['create'] ?? false); // Default to false for bare endpoints
            $viewEnabled = isset($backendFeatures['view']['enabled'])
                ? ($backendFeatures['view']['enabled'] ?? false)
                : ($enabledOps['view'] ?? false);
            $editEnabled = isset($backendFeatures['edit']['enabled'])
                ? ($backendFeatures['edit']['enabled'] ?? false)
                : ($enabledOps['edit'] ?? false);
            $deleteEnabled = isset($backendFeatures['delete']['enabled'])
                ? ($backendFeatures['delete']['enabled'] ?? false)
                : ($enabledOps['delete'] ?? false);

            if ($listEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'list');
            }
            if ($createEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'create');
            }
            if ($viewEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'view');
            }
            if ($editEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'edit');
            }
            if ($deleteEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'delete');
            }
            return implode("\n\n", $methods);
        }

        // For tab-action and header-action: Generate methods based on enabled flags
        // Default to enabled if neither is explicitly set
        $listEnabled = isset($backendFeatures['list']['enabled']) 
            ? ($backendFeatures['list']['enabled'] ?? false)
            : ($enabledOps['list'] ?? true);
        $createEnabled = isset($backendFeatures['create']['enabled'])
            ? ($backendFeatures['create']['enabled'] ?? false)
            : ($enabledOps['create'] ?? true);
        $viewEnabled = isset($backendFeatures['view']['enabled'])
            ? ($backendFeatures['view']['enabled'] ?? false)
            : ($enabledOps['view'] ?? true);
        $editEnabled = isset($backendFeatures['edit']['enabled'])
            ? ($backendFeatures['edit']['enabled'] ?? false)
            : ($enabledOps['edit'] ?? true);
        $deleteEnabled = isset($backendFeatures['delete']['enabled'])
            ? ($backendFeatures['delete']['enabled'] ?? false)
            : ($enabledOps['delete'] ?? true);

        if ($listEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'list');
        }
        if ($createEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'create');
            
            // Generate splash method for custom features with create enabled
            $methods[] = $this->generateCustomFeatureSplashMethod($featureKey, $customFeature);
        }
        if ($viewEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'view');
        }
        if ($editEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'edit');
        }
        if ($deleteEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'delete');
        }

        return implode("\n\n", $methods);
    }

    protected function generateCustomFeatureMethod(string $featureKey, array $customFeature, string $operation): string
    {
        $content = $this->getTemplateContent("Features/custom/controller_method_{$operation}", 'backend');

        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        
        // For bare-endpoint, method name should include operation unless it's list
        if ($displayType === 'bare-endpoint' && $operation !== 'list') {
            $baseMethodName = $customFeature['methodName'] ?? $featureName;
            $methodName = $operation . ucfirst($baseMethodName);
        } else {
            $methodName = $customFeature['methodName'] ?? $this->getCustomFeatureMethodName($featureName, $operation);
        }
        
        $serviceName = $customFeature['serviceName'] ?? $this->getCustomFeatureServiceName($featureName);
        $urlParams = '';
        $requestData = '';

        // Bare endpoint - support multiple operations with proper endpoint config per operation
        if ($displayType === 'bare-endpoint') {
            // Get endpoint config for this specific operation
            $operationConfig = $customFeature['features']['backend'][$operation] ?? [];
            $endpoint = $operationConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? ($operation === 'list' || $operation === 'view' ? 'get' : 'post'));
            
            // Build URL params from urlParams array
            $urlParamsArray = $customFeature['urlParams'] ?? [];
            if (!empty($urlParamsArray)) {
                $params = array_map(fn($p) => "\${$p}", $urlParamsArray);
                $urlParams = ', ' . implode(', ', $params);
            }
            
            // For operations that need item ID (view, edit, delete), add uuid parameter
            if (in_array($operation, ['view', 'edit', 'delete'])) {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                if (empty($urlParams)) {
                    $urlParams = ", \${$itemKey}";
                } else {
                    $urlParams .= ", \${$itemKey}";
                }
            }
            
            // Build request data based on operation type and URL params
            $requestDataParts = [];
            
            // Add URL params to request data
            if (!empty($urlParamsArray)) {
                foreach ($urlParamsArray as $param) {
                    $requestDataParts[] = "'{$param}' => \${$param}";
                }
            }
            
            // Add item UUID for view/edit/delete operations
            if (in_array($operation, ['view', 'edit', 'delete'])) {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                $requestDataParts[] = "'uuid' => \${$itemKey}";
            }
            
            // Add query params for list operation
            if ($operation === 'list') {
                $requestDataParts[] = "'params' => \$request->query()";
            }
            
            // Build final request data string
            if ($method === 'get') {
                $requestData = '[' . implode(', ', $requestDataParts) . ']';
            } else {
                $requestData = "\$request->all()" . (!empty($requestDataParts) ? " + [" . implode(', ', $requestDataParts) . "]" : '');
            }
        }
        // Tab action with parent context
        else if ($displayType === 'tab-action') {
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            // Map parentKey to parent_uuid for service
            $parentUuidKey = 'parent_uuid';
            if ($operation === 'list' || $operation === 'create') {
                $urlParams = ", \${$parentKey}";
                $requestData = $operation === 'list'
                    ? "['{$parentUuidKey}' => \${$parentKey}, 'params' => \$request->query()]"
                    : "\$request->all() + ['{$parentUuidKey}' => \${$parentKey}]";
            } else {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                $urlParams = ", \${$parentKey}, \${$itemKey}";
                $requestData = $operation === 'view'
                    ? "['{$parentUuidKey}' => \${$parentKey}, 'uuid' => \${$itemKey}]"
                    : "\$request->all() + ['{$parentUuidKey}' => \${$parentKey}, 'uuid' => \${$itemKey}]";
            }
        }
        // Header action (existing logic)
        else {
            $urlParams = ', $uuid';
            $requestData = "\$request->all() + ['parent_uuid' => \$uuid]";
        }

        // For bare-endpoint, service name should be full name (module + feature + Service)
        // The template expects just the feature name part, not the full service name
        $serviceNameForTemplate = $serviceName;
        if ($displayType === 'bare-endpoint') {
            // Template format: [[ModuleName]][[ServiceName]]Service
            // So we need just the feature name part
            $featureNameForService = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
            $serviceNameForTemplate = $featureNameForService;
        }

        return $this->replacePlaceholders($content, [
            '[[methodName]]' => $methodName,
            '[[ServiceName]]' => $serviceNameForTemplate,
            '[[FeatureName]]' => $featureName,
            '[[urlParams]]' => $urlParams,
            '[[requestData]]' => $requestData
        ]);
    }

    protected function getCustomFeatureMethodName(string $featureName, string $operation): string
    {
        $operationMap = [
            'list' => 'list' . $featureName,
            'create' => 'create' . $featureName,
            'edit' => 'edit' . $featureName,
            'view' => 'view' . $featureName,
            'delete' => 'delete' . $featureName
        ];

        return $operationMap[$operation] ?? $operation . $featureName;
    }

    protected function getCustomFeatureServiceName(string $featureName): string
    {
        return $featureName;
    }

    protected function generateCustomFeatureSplashMethod(string $featureKey, array $customFeature): string
    {
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        $methodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
        
        // Get service name
        $serviceName = $customFeature['serviceName'] ?? '';
        if (empty($serviceName)) {
            $serviceName = $this->moduleName . $featureName . 'SplashService';
        } else {
            $serviceName = $this->moduleName . $serviceName . 'SplashService';
        }
        
        // Build method signature based on display type
        $params = '';
        $requestData = '';
        
        if ($displayType === 'tab-action') {
            // Tab-action: needs parent UUID parameter
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            $params = "Request \$request, \${$parentKey}";
            $requestData = "\${$parentKey}";
        } else {
            // Header-action: needs parent UUID if endpoint path includes {uuid}
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            $createConfig = $backendFeatures['create'] ?? [];
            $endpointPath = $createConfig['endpoint']['path'] ?? '';
            
            if (strpos($endpointPath, '{uuid}') !== false) {
                $params = "Request \$request, \$uuid";
                $requestData = "\$uuid";
            } else {
                $params = "Request \$request";
                $requestData = '';
            }
        }
        
        $content = "    public function {$methodName}({$params})\n    {\n";
        if (!empty($requestData)) {
            $content .= "        \$result = {$serviceName}::execute(['parent_uuid' => {$requestData}]);\n";
        } else {
            $content .= "        \$result = {$serviceName}::execute();\n";
        }
        $content .= "        return response()->json(\$result, \$result['code']);\n    }";

        return $content;
    }

    private function generateExportMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        return <<<PHP
    /**
     * Export {$name} records to CSV, XLSX, or PDF.
     * GET /api/{$name}s/list/export?format=csv
     */
    public function export{$name}(Request \$request): mixed
    {
        \$data = \$request->all();
        \$data['params']['paginate'] = false;
        return {$svc}::execute(\$data, export: true, format: \$request->get('format', 'csv'));
    }
PHP;
    }

    private function generateImportTemplateMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        return <<<PHP
    /**
     * Download import template with correct column headers.
     * GET /api/{$name}s/import/template?format=csv
     */
    public function importTemplate{$name}(Request \$request): mixed
    {
        return {$svc}::getImportTemplate(\$request->get('format', 'csv'));
    }
PHP;
    }

    private function generateImportMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        return <<<PHP
    /**
     * Import {$name} records from uploaded CSV or XLSX.
     * POST /api/{$name}s/import
     */
    public function import{$name}(Request \$request): \Illuminate\Http\JsonResponse
    {
        \$result = {$svc}::importData(\$request->all(), \$request->file('file'));
        return response()->json(\$result, \$result['code']);
    }
PHP;
    }
}

