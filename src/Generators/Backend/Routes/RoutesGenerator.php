<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoutesGenerator extends BaseGenerator
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
        foreach (['list', 'create', 'view', 'edit', 'delete', 'createSplash', 'editSplash', 'deleteCheck'] as $featureName) {
            // Skip splash features unless constants are declared
            if (in_array($featureName, ['createSplash', 'editSplash']) && !$hasSplash) {
                continue;
            }
            if (isset($backendFeatures[$featureName]) || ($featureName === 'deleteCheck' && isset($backendFeatures['delete']))) {
                $this->features[$featureName] = [true];
            }
        }

        $this->delegations = $config['delegations'] ?? [];
        $this->actions = $config['actions'] ?? [];
    }

    public function generate(): bool
    {
        $content = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse {$this->getNamespace()}\\{$this->moduleName}Controller;\n\n//Add Routes here\n\n";

        // Generate routes for standard features (remove duplicates)
        $processedFeatures = [];
        foreach ($this->features as $feature => $enabled) {
            if (!in_array($feature, $processedFeatures)) {
                $content .= $this->generateFeatureRoute($feature) . "\n\n";
                $processedFeatures[] = $feature;
            }
        }

        // Generate export/import routes if enabled
        $listConfig = $this->config['features']['backend']['list'] ?? null;
        if (!empty($listConfig)) {
            $routePath = Str::kebab($this->moduleName);
            $ctrl = "{$this->moduleName}Controller";
            if (!empty($listConfig['export'])) {
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.list'])->get('/{$routePath}s/list/export', [{$ctrl}::class, 'export{$this->moduleName}']);\n\n";
            }
            if (!empty($listConfig['import'])) {
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->get('/{$routePath}s/import/template', [{$ctrl}::class, 'importTemplate{$this->moduleName}']);\n";
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->post('/{$routePath}s/import', [{$ctrl}::class, 'import{$this->moduleName}']);\n\n";
            }
        }

        // Generate routes for delegations
        foreach ($this->delegations as $delegationKey => $delegation) {
            $content .= $this->generateDelegationRoutes($delegationKey, $delegation) . "\n\n";
        }

        // Generate routes for actions
        foreach ($this->actions as $actionKey => $action) {
            $content .= $this->generateActionRoutes($actionKey, $action) . "\n\n";
        }

        // Add Activity History route
        $routePath = Str::kebab($this->moduleName);
        $content .= "Route::middleware(['auth:sanctum'])->get('/{$routePath}/{uuid}/activity', [{$this->moduleName}Controller::class, 'activityHistory']);\n";

        $filePath = "{$this->modulePath}/Routes/api.php";

        return $this->writeFile($filePath, $content);
    }

    protected function generateFeatureRoute(string $feature): string
    {
        $routeContent = $this->getTemplateContent("Features/{$feature}/route", 'backend');

        $backendConfig = $this->config['features']['backend'][$feature] ?? [];
        
        // Handle deleteCheck correctly
        $routeContent = $this->getTemplateContent("Features/{$feature}/route", 'backend');

        $endpointConfig = $backendConfig['endpoint'] ?? [];

        // Route path should be the module name in kebab case
        $routePath = Str::kebab($this->moduleName);
        $endpointPath = $endpointConfig['path'] ?? "/{$routePath}";

        // For view/edit, if endpoint path already contains {uuid}, don't append it again
        // The template will append /{uuid}/view or /{uuid}/edit
        // So we need to remove {uuid} from endpoint path if it exists
        // if (in_array($feature, ['view', 'edit']) && strpos($endpointPath, '{uuid}') !== false) {
        //     $endpointPath = str_replace('/{uuid}', '', $endpointPath);
        //     $endpointPath = str_replace('{uuid}', '', $endpointPath);
        // }

        if (in_array($feature, ['deleteCheck', 'delete']) ){
            $feature = 'delete';
            $endpointConfig['permission'] = "{$this->moduleName}.{$feature}";
        }

        $routeReplacements = [
            '[[endpointMethod]]' => strtolower($endpointConfig['method'] ?? 'get'),
            '[[endpointPath]]' => $endpointPath,
            '[[endpointPermission]]' => $endpointConfig['permission'] ?? "{$this->moduleName}.{$feature}",
            '[[ModuleName]]' => $this->moduleName,
            '[[moduleName]]' => strtolower($this->moduleName),
        ];

        return $this->replacePlaceholders($routeContent, $routeReplacements);
    }

    protected function generateDelegationRoutes(string $delegationKey, array $delegation): string
    {
        $routes = '';
        $moduleRoute = Str::kebab($this->moduleName);
        $delegationName = $delegation['name'] ?? $delegationKey;
        $delegationRoute = Str::kebab($delegationName);
        $delegationStudly = Str::studly($delegationName);
        $parentKey = $delegation['parentKey'] ?? 'uuid';
        $operations = $delegation['operations'] ?? [];

        // Track method+path combos to detect and warn about collisions
        $registeredRoutes = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $opConfig = $operations[$op];
            $endpoint = $opConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
            $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$delegationStudly}.{$op}";

            // Build path: /{module}/{parentKey}/{delegation}/{op} or /{module}/{parentKey}/{delegation}/{itemUuid}/{op}
            if (!empty($endpoint['path'])) {
                $path = $endpoint['path'];
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
            } elseif (in_array($op, ['edit', 'view', 'delete'])) {
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{itemUuid}/{$op}";
            } else {
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{$op}";
            }

            // Warn and skip if another operation already registered the same method+path
            $routeKey = strtoupper($method) . ' ' . $path;
            if (isset($registeredRoutes[$routeKey])) {
                Log::warning("Duplicate delegation route skipped: {$routeKey} — operation '{$op}' has the same method and path as '{$registeredRoutes[$routeKey]}'. Give each operation a unique endpoint path.", [
                    'module' => $this->moduleName,
                    'delegation' => $delegationKey,
                    'operation' => $op,
                ]);
                continue;
            }
            $registeredRoutes[$routeKey] = $op;

            $methodName = $op . $delegationStudly;
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
        }

        return $routes;
    }

    protected function generateActionRoutes(string $actionKey, array $action): string
    {
        $routes = '';
        $moduleRoute = Str::kebab($this->moduleName);
        $actionName = $action['name'] ?? $actionKey;
        $actionRoute = Str::kebab($actionName);
        $actionStudly = Str::studly($actionName);
        $urlParams = $action['urlParams'] ?? [];
        $operations = $action['operations'] ?? [];

        $urlParamsPath = '';
        if (!empty($urlParams)) {
            $urlParamsPath = '/' . implode('/', array_map(fn($p) => "{{$p}}", $urlParams));
        }

        // Track method+path combos to detect and warn about collisions
        $registeredRoutes = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $opConfig = $operations[$op];
            $endpoint = $opConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
            // MUST match SeederGenerator, which is what actually creates the
            // permission row, and the frontend's hasPermission() check. This
            // previously defaulted to "{Module}.{StudlyName}.{op}" while the
            // seeder created "{Module}.{name}.execute" — different case AND a
            // different final segment, so the permission the route demanded was
            // never created and every default-configured action 403'd forever.
            // The canonical form is "{Module}.{actionName}", the same shape as
            // the CRUD permissions (Users.create) and the hand-written
            // Users.resendInvitation the UI already checks.
            $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$actionName}";

            if (!empty($endpoint['path'])) {
                $path = $endpoint['path'];
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
            } else {
                $path = "/{$moduleRoute}/{$actionRoute}{$urlParamsPath}/{$op}";
            }

            // Warn and skip if another operation already registered the same method+path
            $routeKey = strtoupper($method) . ' ' . $path;
            if (isset($registeredRoutes[$routeKey])) {
                Log::warning("Duplicate action route skipped: {$routeKey} — operation '{$op}' has the same method and path as '{$registeredRoutes[$routeKey]}'. Give each operation a unique endpoint path.", [
                    'module' => $this->moduleName,
                    'action' => $actionKey,
                    'operation' => $op,
                ]);
                continue;
            }
            $registeredRoutes[$routeKey] = $op;

            $serviceNameRaw = $action['serviceName'] ?? $actionStudly;
            if (str_starts_with($serviceNameRaw, $this->moduleName)) {
                $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
            }
            if (str_ends_with($serviceNameRaw, 'Service')) {
                $serviceNameRaw = substr($serviceNameRaw, 0, -7);
            }
            $baseMethod = $action['methodName'] ?? $serviceNameRaw;
            $methodName = $op === 'list' ? lcfirst($baseMethod) : $op . ucfirst($baseMethod);

            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
        }

        return $routes;
    }

    /** @deprecated Use generateDelegationRoutes or generateActionRoutes */
    protected function generateCustomFeatureRoutes(string $featureKey, array $customFeature): string
    {
        $routes = '';
        $featureName = strtolower($customFeature['name'] ?? $featureKey);
        $moduleNameLower = strtolower($this->moduleName);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        
        // Bare endpoint: Support multiple operations (list, create, view, edit, delete)
        if ($displayType === 'bare-endpoint') {
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            $urlParams = $customFeature['urlParams'] ?? [];
            
            // Generate routes for each enabled operation
            foreach (['list', 'create', 'view', 'edit', 'delete'] as $op) {
                $featureConfig = $backendFeatures[$op] ?? null;
                if (!$featureConfig || !($featureConfig['enabled'] ?? false)) continue;
                
                $endpoint = $featureConfig['endpoint'] ?? [];
                $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.{$op}";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix from the path
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Build path with URL params
                $pathParts = [];
                if (!empty($urlParams)) {
                    foreach ($urlParams as $param) {
                        $pathParts[] = "{{$param}}";
                    }
                }
                
                // Add item UUID for view/edit/delete operations
                if (in_array($op, ['view', 'edit', 'delete'])) {
                    $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                    $pathParts[] = "{{$itemKey}}";
                }
                
                // Construct full path with operation suffix
                $path = $basePath;
                if (!empty($pathParts)) {
                    $path .= '/' . implode('/', $pathParts);
                }
                $path .= '/' . $op;
                
                // Generate method name
                $methodName = $customFeature['methodName'] ?? '';
                if (empty($methodName)) {
                    // Default method naming: operation + FeatureName (e.g., listExportData, createExportData)
                    $methodName = $op . ucfirst($customFeature['name'] ?? $featureKey);
                } elseif ($op !== 'list') {
                    // For non-list operations, append operation name if methodName is provided
                    $methodName = $op . ucfirst($methodName);
                }
                
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
            return $routes;
        }
        
        // Tab action: Nested routes with parent context
        if ($displayType === 'tab-action') {
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            
            foreach (['list', 'create', 'view', 'edit', 'delete'] as $op) {
                $featureConfig = $backendFeatures[$op] ?? null;
                if (!$featureConfig || !($featureConfig['enabled'] ?? false)) continue;
                
                $endpoint = $featureConfig['endpoint'] ?? [];
                $method = strtolower($endpoint['method'] ?? ($op === 'list' || $op === 'view' ? 'get' : 'post'));
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.{$op}";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix from the path (e.g., /list, /create, etc.)
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Build the path with operation suffix
                if (in_array($op, ['view', 'edit', 'delete'])) {
                    // For view/edit/delete, need to add the related UUID parameter before the operation
                    $relatedUuidParam = ($customFeature['name'] ?? $featureKey) . '_id';
                    // Check if path already has a parameter after the feature name
                    if (!preg_match('/\/\{[^}]+\}(\/|$)/', $basePath)) {
                        // Add the related UUID parameter
                        $basePath .= '/{' . $relatedUuidParam . '}';
                    }
                    $path = $basePath . '/' . $op;
                } else {
                    // For list and create, just append the operation
                    $path = $basePath . '/' . $op;
                }
                
                $methodName = $op . ucfirst($customFeature['name'] ?? $featureKey);
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
            
            // Generate splash route for tab-action if create is enabled
            $createConfig = $backendFeatures['create'] ?? null;
            if ($createConfig && ($createConfig['enabled'] ?? false)) {
                $endpoint = $createConfig['endpoint'] ?? [];
                $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{{$parentKey}}/{$featureName}";
                $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}.create";
                
                // Ensure path has leading slash
                if (!str_starts_with($basePath, '/')) {
                    $basePath = '/' . $basePath;
                }
                
                // Remove any existing operation suffix and ensure we have the base path
                $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
                $basePath = rtrim($basePath, '/');
                
                // Splash route: {basePath}/create/splash
                $splashMethodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->get('{$basePath}/create/splash', [{$this->moduleName}Controller::class, '{$splashMethodName}']);\n";
            }
            
            return $routes;
        }
        
        // Header action: Use endpoint path from config if available
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $createConfig = $backendFeatures['create'] ?? null;
        if ($createConfig && ($createConfig['enabled'] ?? false)) {
            $endpoint = $createConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? 'post');
            $featureNameLower = strtolower($customFeature['name'] ?? $featureKey);
            $basePath = $endpoint['path'] ?? "/{$moduleNameLower}/{uuid}/{$featureNameLower}";
            $permission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}";
            
            // Ensure path has leading slash
            if (!str_starts_with($basePath, '/')) {
                $basePath = '/' . $basePath;
            }
            
            // Remove any existing operation suffix from the path
            $basePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $basePath);
            $basePath = rtrim($basePath, '/');
            
            // Append /create to the path
            $path = $basePath . '/create';
            
            $methodName = $customFeature['methodName'] ?? 'handle' . ucfirst($customFeature['name'] ?? $featureKey);
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$permission}'])->{$method}('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            
            // Generate splash route for header-action if create is enabled
            // Splash route: {basePath}/create/splash (e.g., /test-products/{uuid}/quick-actions/create/splash)
            $splashPermission = $endpoint['permission'] ?? "{$this->moduleName}.{$featureName}";
            $splashMethodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$splashPermission}'])->get('{$basePath}/create/splash', [{$this->moduleName}Controller::class, '{$splashMethodName}']);\n";
        } else {
            // Fallback to default route with /create suffix
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.{$featureName}'])->post('/{$moduleNameLower}/{uuid}/{$featureName}/create', [{$this->moduleName}Controller::class, 'handle" . ucfirst($customFeature['name'] ?? $featureKey) . "']);\n";
        }
        
        return $routes;
    }
}

