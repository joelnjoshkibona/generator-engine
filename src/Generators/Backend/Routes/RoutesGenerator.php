<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PatchesRegions;
use Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoutesGenerator extends BaseGenerator
{
    use PatchesRegions;

    /** Region name wrapping delegation/action routes — see addDelegationRoute()/addActionRoute(). */
    private const CUSTOM_ROUTES_REGION = 'custom-routes';

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

        // Normalized, not raw: the bulk make:module path (this constructor)
        // previously read $config['delegations'] straight off module.json,
        // so a delegation operation with no explicit endpoint.method fell
        // through to this file's own stale inline default in
        // generateDelegationRoutes() instead of DelegationConfigNormalizer::
        // getOperationDefaults()' per-op GET/PUT/DELETE/POST match — every
        // edit/delete route was silently registered as POST. The incremental
        // make:delegation path (addDelegationRoute()) was already unaffected
        // because MakeDelegation.php normalizes before calling it.
        $this->delegations = DelegationConfigNormalizer::normalizeAll($config['delegations'] ?? []);
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
            // No appended "s": $routePath is already the full kebab-cased
            // module name (module names in this project are already plural
            // — Warehouses, Locations, Users), matching every other route
            // this generator emits (e.g. the list route itself: "/{$routePath}/list",
            // Features/list/route.stub). A stray literal "s" here used to
            // register e.g. "/warehousess/list/export" (double s) while the
            // module's own list route was the correct "/warehouses/list" —
            // silently never noticed because nothing had ever turned
            // export/import on for a real module before this was fixed.
            $routePath = Str::kebab($this->moduleName);
            $ctrl = "{$this->moduleName}Controller";
            if (!empty($listConfig['export'])) {
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.list'])->get('/{$routePath}/list/export', [{$ctrl}::class, 'export{$this->moduleName}']);\n\n";
            }
            if (!empty($listConfig['import'])) {
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->get('/{$routePath}/import/template', [{$ctrl}::class, 'importTemplate{$this->moduleName}']);\n";
                $content .= "Route::middleware(['auth:sanctum', 'permission:{$this->moduleName}.import'])->post('/{$routePath}/import', [{$ctrl}::class, 'import{$this->moduleName}']);\n\n";
            }
        }

        // Delegation and action routes live inside a single named region,
        // wrapped unconditionally (even when empty) so every freshly
        // generated module already carries the markers make:delegation/
        // make:action need to append a route later via
        // addDelegationRoute()/addActionRoute() — see PatchesRegions. A
        // module generated by an older engine version that predates this
        // region self-heals the markers in on first incremental use instead.
        $customRoutes = '';
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customRoutes .= $this->generateDelegationRoutes($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customRoutes .= $this->generateActionRoutes($actionKey, $action);
        }
        $customRoutes = trim($customRoutes);
        $content .= '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":start]\n"
            . ($customRoutes !== '' ? $customRoutes . "\n" : '')
            . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":end]\n\n";

        // Add Activity History route
        $routePath = Str::kebab($this->moduleName);
        $content .= "Route::middleware(['auth:sanctum'])->get('/{$routePath}/{uuid}/activity', [{$this->moduleName}Controller::class, 'activityHistory']);\n";

        $filePath = "{$this->modulePath}/Routes/api.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Append one delegation's route(s) to an already-generated module's
     * Routes/api.php, inside the custom-routes region generate() wraps in
     * markers. Additive and idempotent: never touches anything outside the
     * region, never overwrites an existing route, and re-running for the
     * same delegation is a no-op rather than a duplicate.
     *
     * Self-heals a Routes/api.php generated before this region existed —
     * routes files are a flat statement list, so appending an empty region
     * pair at end-of-file is always a safe anchor.
     *
     * @throws \RuntimeException if the module was never generated (no
     *         Routes/api.php to patch).
     */
    public function addDelegationRoute(string $delegationKey, array $delegation): bool
    {
        return $this->addCustomRoute($this->generateDelegationRoutes($delegationKey, $delegation));
    }

    /** @see addDelegationRoute() — identical contract, for actions[] instead. */
    public function addActionRoute(string $actionKey, array $action): bool
    {
        return $this->addCustomRoute($this->generateActionRoutes($actionKey, $action));
    }

    private function addCustomRoute(string $snippet): bool
    {
        // Per-line, not whole-snippet: generateDelegationRoutes()/
        // generateActionRoutes() return one line per enabled operation, and
        // re-running make:delegation/make:action after a delegation/action
        // gains a newly-enabled operation must add only the new line(s) —
        // a whole-snippet match would miss that case (existing has 2 of the
        // now-3 lines, so it never matches) and duplicate the 2 already there.
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($snippet)))));
        if (empty($lines)) {
            return false; // no operations enabled — nothing to route
        }

        $filePath = "{$this->modulePath}/Routes/api.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add a route to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomRoutesRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_ROUTES_REGION) ?? '';

        $newLines = array_filter($lines, fn (string $line): bool => !str_contains($existing, $line));
        if (empty($newLines)) {
            return false; // every route in this snippet is already present
        }

        $toAppend = implode("\n", $newLines);
        $newContent = trim($existing) === '' ? $toAppend : rtrim($existing) . "\n" . $toAppend;

        return $this->patchRegion($filePath, self::CUSTOM_ROUTES_REGION, rtrim($newContent));
    }

    /**
     * Insert an empty custom-routes region at the end of a Routes/api.php
     * that predates this feature. No-op if the markers already exist.
     */
    private function ensureCustomRoutesRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_ROUTES_REGION) !== null) {
            return;
        }

        $fileContent = rtrim(file_get_contents($filePath));
        $fileContent .= "\n\n" . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":start]\n"
            . '// [generator:region:' . self::CUSTOM_ROUTES_REGION . ":end]\n";
        file_put_contents($filePath, $fileContent);
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
        // Permissions reuse the RELATED module's own permission (e.g.
        // "StockMovements.edit"), not a delegation-specific one — see
        // DelegationConfigNormalizer::resolveOperationPermission()'s
        // docblock. Falls back to $delegationStudly only if relatedModule
        // was somehow never normalized (shouldn't happen in practice).
        $relatedModuleName = $delegation['relatedModule']['name'] ?? $delegationStudly;

        // Track method+path combos to detect and warn about collisions
        $registeredRoutes = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $opConfig = $operations[$op];
            $endpoint = $opConfig['endpoint'] ?? [];
            // Matches DelegationConfigNormalizer::getOperationDefaults() exactly —
            // native EditForm/DeleteForm send PUT/DELETE unconditionally, so a
            // caller reaching this method with un-normalized config (endpoint.method
            // unset) must fall back to the same per-op default, not a blanket POST.
            $method = strtolower($endpoint['method'] ?? match ($op) {
                'list', 'view' => 'get',
                'edit' => 'put',
                'delete' => 'delete',
                default => 'post',
            });
            $permission = DelegationConfigNormalizer::resolveOperationPermission(
                $relatedModuleName,
                $op,
                $endpoint
            );

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

        // deleteCheck piggybacks on delete being enabled, and reuses delete's own
        // resolved permission — same convention native deleteCheck already uses
        // relative to native delete (see generateRouteContent() above), not a
        // separate deleteCheck permission nothing seeds.
        if (!empty($operations['delete']['enabled'])) {
            $deleteEndpoint = $operations['delete']['endpoint'] ?? [];
            $deletePermission = DelegationConfigNormalizer::resolveOperationPermission(
                $relatedModuleName,
                'delete',
                $deleteEndpoint
            );
            $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/{itemUuid}/delete/check";
            $routeKey = 'GET ' . $path;
            if (!isset($registeredRoutes[$routeKey])) {
                $registeredRoutes[$routeKey] = 'deleteCheck';
                $methodName = 'deleteCheck' . $delegationStudly;
                $routes .= "Route::middleware(['auth:sanctum', 'permission:{$deletePermission}'])->get('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
            }
        }

        // export/bulk-action/import: nested under list's own backend config
        // (operations.list.backend.{export,bulk_actions,import}), not
        // top-level operation keys — mirrors the standalone module's own
        // "Generate export/import routes if enabled" block above, just
        // delegation-scoped. Export reuses list's own resolved permission
        // (same convention the standalone module's export uses, reusing
        // .list rather than a dedicated permission); bulk-action/import each
        // get their own, matching how standalone already gives those two
        // their own .bulkAction/.import permissions distinct from .list.
        $listOp = $operations['list'] ?? [];
        if (!empty($listOp['enabled'])) {
            $listBackend = $listOp['backend'] ?? [];

            if (!empty($listBackend['export'])) {
                $exportPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'list',
                    $listOp['endpoint'] ?? []
                );
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/list/export";
                $routeKey = 'GET ' . $path;
                if (!isset($registeredRoutes[$routeKey])) {
                    $registeredRoutes[$routeKey] = 'export';
                    $methodName = 'export' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$exportPermission}'])->get('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }

            if (!empty($listBackend['bulk_actions'])) {
                $bulkPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'bulkAction',
                    []
                );
                $path = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/bulk-action";
                $routeKey = 'POST ' . $path;
                if (!isset($registeredRoutes[$routeKey])) {
                    $registeredRoutes[$routeKey] = 'bulkAction';
                    $methodName = 'bulkAction' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$bulkPermission}'])->post('{$path}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }

            if (!empty($listBackend['import'])) {
                $importPermission = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'import',
                    []
                );
                $templatePath = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/import/template";
                $templateRouteKey = 'GET ' . $templatePath;
                if (!isset($registeredRoutes[$templateRouteKey])) {
                    $registeredRoutes[$templateRouteKey] = 'importTemplate';
                    $methodName = 'importTemplate' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$importPermission}'])->get('{$templatePath}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }

                $importPath = "/{$moduleRoute}/{{$parentKey}}/{$delegationRoute}/import";
                $importRouteKey = 'POST ' . $importPath;
                if (!isset($registeredRoutes[$importRouteKey])) {
                    $registeredRoutes[$importRouteKey] = 'import';
                    $methodName = 'import' . $delegationStudly;
                    $routes .= "Route::middleware(['auth:sanctum', 'permission:{$importPermission}'])->post('{$importPath}', [{$this->moduleName}Controller::class, '{$methodName}']);\n";
                }
            }
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

        // Opt-in splash for this action: GET {module}/{uuid}/{action}/splash. Takes the uuid because
        // an action always operates on an existing row and its option lists usually depend on that
        // row's state — unlike create's splash, which has no record yet. Permission is the action's
        // own, so anyone who may run it may load its form.
        if (!empty($action['splash'])) {
            $splashPermission = "{$this->moduleName}." . lcfirst($actionStudly);
            $splashMethod = lcfirst($actionStudly);
            $routes .= "Route::middleware(['auth:sanctum', 'permission:{$splashPermission}'])"
                . "->get('/{$moduleRoute}/{uuid}/{$actionRoute}/splash', "
                . "[{$this->moduleName}Controller::class, '{$splashMethod}Splash']);\n";
        }

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
            // Users.resendInvitation the UI already checks — which means
            // lcfirst(), not the raw StudlyCase $actionName ("ForceResetPassword"):
            // every other permission in this codebase is camelCase after the
            // dot, and this comment's own claim to match Users.resendInvitation
            // was false until this fix (found + fixed 2026-08-06, alongside the
            // identical bug in SeederGenerator::generatePermissions() and
            // ViewModalGenerator::buildActionReplacements() — all three built the
            // same string independently, so they stayed self-consistent with each
            // other, but jointly violated the app's own naming convention).
            //
            // !empty(), not ?? — ActionConfigNormalizer always sets
            // permission to '' (never null), so ?? never actually falls
            // back: every action route shipped with permission:'', an empty
            // permission gate rather than the canonical form above.
            $permission = !empty($endpoint['permission']) ? $endpoint['permission'] : "{$this->moduleName}." . lcfirst($actionName);

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

            // !empty(), not ?? — see ActionServiceGenerator's identical fix
            // for why: ActionConfigNormalizer always sets serviceName/
            // methodName to '' (never null), so ?? never actually falls
            // back, and every blank-configured action's route pointed at a
            // bare "create"/"list"/etc. controller method that any second
            // such action on the module would collide with.
            $serviceNameRaw = !empty($action['serviceName']) ? $action['serviceName'] : $actionStudly;
            if (str_starts_with($serviceNameRaw, $this->moduleName)) {
                $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
            }
            if (str_ends_with($serviceNameRaw, 'Service')) {
                $serviceNameRaw = substr($serviceNameRaw, 0, -7);
            }
            $baseMethod = !empty($action['methodName']) ? $action['methodName'] : $serviceNameRaw;
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

