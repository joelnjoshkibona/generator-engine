<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PatchesRegions;

class ControllerGenerator extends BaseGenerator
{
    use PatchesRegions;

    /** Region wrapping delegation/action service imports — see addDelegationMethods()/addActionMethods(). */
    private const CUSTOM_IMPORTS_REGION = 'custom-imports';
    /** Region wrapping delegation/action controller methods — see addDelegationMethods()/addActionMethods(). */
    private const CUSTOM_METHODS_REGION = 'custom-methods';

    protected array $features;
    protected array $delegations;
    protected array $actions;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features strictly from new payload: features.backend,
        // using the exact same enablement rule RoutesGenerator uses (see
        // resolveEnabledBackendFeatures() below) so a module's controller
        // methods and its routes can never silently diverge -- previously
        // this constructor hardcoded all six standard features as always
        // "enabled" and only consulted $backendFeatures for the splash
        // check below, so e.g. a module with 'create' disabled in
        // features.backend still got a dead create{Module}() method with no
        // route pointing at it.
        $this->features = [];
        $backendFeatures = $config['features']['backend'] ?? [];
        $hasSplash = !empty($config['constants']);
        foreach (self::resolveEnabledBackendFeatures($backendFeatures, $hasSplash) as $featureName => $enabled) {
            $this->features[$featureName] = [true];
        }

        $this->delegations = $config['delegations'] ?? [];
        $this->actions = $config['actions'] ?? [];
    }

    /**
     * Resolve which standard backend features (list, create, view, edit,
     * delete, deleteCheck, createSplash, editSplash) are enabled from the
     * raw features.backend config block.
     *
     * MUST stay byte-for-byte identical to the equivalent inline logic in
     * RoutesGenerator::__construct()
     * (src/Generators/Backend/Routes/RoutesGenerator.php) -- these two
     * generators derive which controller methods vs. which routes to emit
     * from the very same config, and any drift between the two produces a
     * controller method with no route pointing at it (or a route pointing
     * at a controller method that doesn't exist). Kept as a local, private
     * copy rather than extracted to BaseGenerator/a shared trait because
     * this fix's file scope is restricted to ControllerGenerator.php; if
     * RoutesGenerator's copy is ever edited again, this one must be updated
     * to match -- ControllerGeneratorTest's drift-guard test asserts the
     * two generators' outputs stay in lockstep for the same config, so any
     * future divergence here fails loudly instead of shipping silently.
     *
     * Rules:
     * - A standard feature is enabled iff its own key is present in
     *   $backendFeatures (isset()).
     * - 'deleteCheck' additionally follows 'delete': enabled whenever
     *   'delete' is configured, even without its own key.
     * - 'createSplash' / 'editSplash' are opt-in twice over: $hasSplash
     *   (the caller gates this on !empty($config['constants'])) AND their
     *   own key present in $backendFeatures.
     *
     * @param array<string, mixed> $backendFeatures
     * @return array<string, true> enabled feature name => true, in the
     *         canonical order: list, create, view, edit, delete,
     *         createSplash, editSplash, deleteCheck.
     */
    private static function resolveEnabledBackendFeatures(array $backendFeatures, bool $hasSplash): array
    {
        $enabled = [];
        foreach (['list', 'create', 'view', 'edit', 'delete', 'createSplash', 'editSplash', 'deleteCheck'] as $featureName) {
            // Skip splash features unless constants are declared
            if (in_array($featureName, ['createSplash', 'editSplash'], true) && !$hasSplash) {
                continue;
            }
            if (isset($backendFeatures[$featureName]) || ($featureName === 'deleteCheck' && isset($backendFeatures['delete']))) {
                $enabled[$featureName] = true;
            }
        }
        return $enabled;
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
        // Generate splash methods if their own feature key is enabled.
        // Matches RoutesGenerator, which does not additionally require
        // 'create'/'edit' itself to be enabled -- $this->features already
        // only contains 'createSplash'/'editSplash' when $hasSplash AND
        // their own backendFeatures key are both present (see
        // resolveEnabledBackendFeatures() above).
        if (isset($this->features['createSplash'])) {
            $methods[] = $this->generateControllerMethod('createSplash');
        }

        // editSplash is generated if the 'editSplash' feature is enabled
        if (isset($this->features['editSplash'])) {
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

        // Delegation and action methods live inside a single named region,
        // wrapped unconditionally (even when empty) so every freshly
        // generated module already carries the markers make:delegation/
        // make:action need to append a method later via
        // addDelegationMethods()/addActionMethods() — see PatchesRegions. A
        // module generated by an older engine version that predates this
        // region self-heals the markers in on first incremental use instead.
        $customMethods = [];
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customMethods[] = $this->generateDelegationMethods($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customMethods[] = $this->generateActionMethods($actionKey, $action);
        }
        // trim(..., "\n") only, not a full trim() — a full trim() would eat
        // the first method's leading 4-space indent along with the outer
        // blank lines, leaving it flush against the left margin while every
        // other method in the block stays properly indented.
        $customMethodsBlock = trim(implode("\n\n", array_filter($customMethods)), "\n");
        $methods[] = '    // [generator:region:' . self::CUSTOM_METHODS_REGION . ":start]\n"
            . ($customMethodsBlock !== '' ? $customMethodsBlock . "\n" : '')
            . '    // [generator:region:' . self::CUSTOM_METHODS_REGION . ':end]';

        // Build all use statements
        $servicesNs = $this->getNamespace() . "\\Services";
        $uses = [];
        $uses[] = "use App\\Project\\_Src\\Traits\\HasActivityHistory;";

        // Standard service imports (always present) — stay unmarked/static,
        // never change incrementally, unlike the delegation/action imports.
        $standardServices = ['List', 'Create', 'View', 'Edit', 'Delete', 'DeleteCheck', 'ActivityList'];
        // Splash services only when constants are declared (splash is opt-in)
        if (!empty($this->config['constants'])) {
            $standardServices[] = 'CreateSplash';
            $standardServices[] = 'EditSplash';
        }
        foreach ($standardServices as $svc) {
            $uses[] = "use {$servicesNs}\\{$this->moduleName}{$svc}Service;";
        }
        $usesBlock = implode("\n", array_unique($uses));

        // Delegation and action service imports live inside their own named
        // region, same rationale as the methods region above — reuses the
        // exact same line-building helpers addDelegationMethods()/
        // addActionMethods() call incrementally, so the two paths can never
        // drift apart on what an import line looks like.
        $customImports = [];
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customImports[] = $this->generateDelegationImport($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customImports[] = $this->generateActionImport($actionKey, $action);
        }
        $customImportsBlock = implode("\n", array_unique(array_filter($customImports)));
        $customImportsRegion = '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":start]\n"
            . ($customImportsBlock !== '' ? $customImportsBlock . "\n" : '')
            . '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ':end]';

        $content = str_replace(
            "use App\Http\Controllers\Controller;",
            "use App\Http\Controllers\Controller;\n{$usesBlock}\n{$customImportsRegion}",
            $content
        );

        $activityTrait = "    use HasActivityHistory;\n\n    protected string \$activityServiceClass = {$this->moduleName}ActivityListService::class;\n\n";

        $content = $this->replacePlaceholders($content, [
            '[[methods]]' => $activityTrait . implode("\n\n", $methods)
        ]);

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Append one delegation's controller method(s) and its service import to
     * an already-generated module's Controller — inside the custom-imports/
     * custom-methods regions generate() wraps in markers. Additive and
     * idempotent: never touches anything outside those regions, and
     * re-running for the same delegation only adds whatever operations
     * weren't already wired (see addCustomMethod()'s per-method guard).
     *
     * Self-heals a Controller generated before these regions existed.
     *
     * @throws \RuntimeException if the module was never generated (no
     *         Controller file to patch).
     */
    public function addDelegationMethods(string $delegationKey, array $delegation): bool
    {
        $importAdded = $this->addCustomImport($this->generateDelegationImport($delegationKey, $delegation));
        $methodAdded = $this->addCustomMethod($this->generateDelegationMethods($delegationKey, $delegation));

        return $importAdded || $methodAdded;
    }

    /** @see addDelegationMethods() — identical contract, for actions[] instead. */
    public function addActionMethods(string $actionKey, array $action): bool
    {
        $importAdded = $this->addCustomImport($this->generateActionImport($actionKey, $action));
        $methodAdded = $this->addCustomMethod($this->generateActionMethods($actionKey, $action));

        return $importAdded || $methodAdded;
    }

    /** Builds one delegation's service `use` line. Shared by generate() and addDelegationMethods() so the two can never drift apart. */
    protected function generateDelegationImport(string $delegationKey, array $delegation): string
    {
        $delegationName = \Illuminate\Support\Str::studly($delegation['name'] ?? $delegationKey);
        $servicesNs = $this->getNamespace() . "\\Services";

        return "use {$servicesNs}\\{$this->moduleName}{$delegationName}Service;";
    }

    /** Builds one action's service `use` line. Shared by generate() and addActionMethods() so the two can never drift apart. */
    protected function generateActionImport(string $actionKey, array $action): string
    {
        $actionName = \Illuminate\Support\Str::studly($action['name'] ?? $actionKey);
        // !empty(), not ?? — see generateActionMethods()/ActionServiceGenerator
        // for why: ActionConfigNormalizer always sets serviceName to '' (never
        // null), so ?? never actually falls back to $actionName.
        $serviceNameRaw = !empty($action['serviceName']) ? $action['serviceName'] : $actionName;
        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }
        $servicesNs = $this->getNamespace() . "\\Services";

        $imports = ["use {$servicesNs}\\{$this->moduleName}{$serviceNameRaw}Service;"];

        // An action with opt-in splash also needs its splash service imported — the method emitted
        // by generateActionMethods() references it by short name.
        if (!empty($action['splash'])) {
            $imports[] = "use {$servicesNs}\\{$this->moduleName}{$serviceNameRaw}SplashService;";
        }

        return implode("\n", $imports);
    }

    private function addCustomImport(string $importLine): bool
    {
        $importLine = trim($importLine);
        if ($importLine === '') {
            return false;
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add an import to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomImportsRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_IMPORTS_REGION) ?? '';
        if ($existing !== '' && str_contains($existing, $importLine)) {
            return false; // already imported — idempotent no-op
        }

        $newContent = trim($existing) === '' ? $importLine : rtrim($existing) . "\n" . $importLine;

        return $this->patchRegion($filePath, self::CUSTOM_IMPORTS_REGION, rtrim($newContent));
    }

    private function addCustomMethod(string $methodBody): bool
    {
        if (trim($methodBody) === '') {
            return false; // no operations enabled — nothing to add
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add a method to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomMethodsRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_METHODS_REGION) ?? '';

        // Per-method, not whole-block: generateDelegationMethods()/
        // generateActionMethods() can return several method definitions (one
        // per enabled operation), and re-running after a delegation/action
        // gains a newly-enabled operation must add only the new method(s) —
        // a whole-block match would never match on a second call once even
        // one method already differs, duplicating everything already there.
        //
        // trim(..., "\n") only when splitting, and rtrim() (not trim()) on
        // each piece kept — a full trim() would strip every method's
        // leading 4-space indent, not just the outer edges of the block.
        $newMethods = [];
        foreach (preg_split('/\n\n(?=\s*public function)/', trim($methodBody, "\n")) as $singleMethod) {
            if (trim($singleMethod) === '') {
                continue;
            }
            preg_match('/public function (\w+)\(/', $singleMethod, $m);
            $methodName = $m[1] ?? null;
            if ($methodName !== null && str_contains($existing, "public function {$methodName}(")) {
                continue; // already added
            }
            $newMethods[] = rtrim($singleMethod);
        }

        if (empty($newMethods)) {
            return false; // every method in this block is already present
        }

        $toAppend = implode("\n\n", $newMethods);
        $newContent = trim($existing) === '' ? $toAppend : rtrim($existing) . "\n\n" . $toAppend;

        return $this->patchRegion($filePath, self::CUSTOM_METHODS_REGION, rtrim($newContent));
    }

    /**
     * Insert an empty custom-imports region right after the
     * `use App\Http\Controllers\Controller;` line of a Controller generated
     * before this region existed. Falls back to inserting after the last
     * `use` statement if that exact line isn't found, or right after the
     * opening `<?php` tag if there are no `use` statements at all.
     */
    private function ensureCustomImportsRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_IMPORTS_REGION) !== null) {
            return;
        }

        $fileContent = file_get_contents($filePath);
        $marker = '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":start]\n"
            . '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":end]\n";

        $anchor = 'use App\Http\Controllers\Controller;';
        if (str_contains($fileContent, $anchor)) {
            $fileContent = str_replace($anchor, $anchor . "\n" . rtrim($marker), $fileContent);
        } elseif (preg_match_all('/^use .+;$/m', $fileContent, $m, PREG_OFFSET_CAPTURE) && !empty($m[0])) {
            $lastUse = end($m[0]);
            $insertAt = $lastUse[1] + strlen($lastUse[0]);
            $fileContent = substr($fileContent, 0, $insertAt) . "\n" . rtrim($marker) . substr($fileContent, $insertAt);
        } else {
            $fileContent = preg_replace('/^<\?php\s*/', "<?php\n\n" . rtrim($marker) . "\n\n", $fileContent, 1);
        }

        file_put_contents($filePath, $fileContent);
    }

    /**
     * Insert an empty custom-methods region immediately before the class's
     * closing `}` of a Controller generated before this region existed.
     * Every sampled real controller in this codebase ends with the class's
     * closing brace as the file's final `}` — a safe, universal anchor.
     */
    private function ensureCustomMethodsRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_METHODS_REGION) !== null) {
            return;
        }

        $fileContent = file_get_contents($filePath);
        $lastBrace = strrpos($fileContent, '}');
        if ($lastBrace === false) {
            throw new \RuntimeException("Cannot locate the class's closing brace in {$filePath} to insert delegation/action methods.");
        }

        $marker = "\n    // [generator:region:" . self::CUSTOM_METHODS_REGION . ":start]\n"
            . "    // [generator:region:" . self::CUSTOM_METHODS_REGION . ":end]\n";

        $fileContent = substr($fileContent, 0, $lastBrace) . $marker . substr($fileContent, $lastBrace);
        file_put_contents($filePath, $fileContent);
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

        // deleteCheck piggybacks on delete being enabled — same convention
        // native deleteCheck already uses relative to native delete.
        if (!empty($operations['delete']['enabled'])) {
            $stub = $this->getTemplateContent('Features/delegation/controller_method_deleteCheck', 'backend');
            $methods[] = $this->replacePlaceholders($stub, [
                '[[DelegationName]]' => $delegationName,
                '[[parentKey]]' => $parentKey,
            ]);
        }

        // export/bulkAction/import: nested under list's own backend config,
        // not top-level operation keys — same gating RoutesGenerator::
        // generateDelegationRoutes() uses for these three.
        $listOp = $operations['list'] ?? [];
        if (!empty($listOp['enabled'])) {
            $listBackend = $listOp['backend'] ?? [];

            if (!empty($listBackend['export'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_export', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }

            if (!empty($listBackend['bulk_actions'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_bulkAction', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }

            if (!empty($listBackend['import'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_importTemplate', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);

                $stub = $this->getTemplateContent('Features/delegation/controller_method_import', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }
        }

        return implode("\n\n", $methods);
    }

    protected function generateActionMethods(string $actionKey, array $action): string
    {
        $methods = [];
        $actionName = \Illuminate\Support\Str::studly($action['name'] ?? $actionKey);
        // !empty(), not ?? — ActionConfigNormalizer always sets serviceName
        // to '' (never null), so ?? never actually falls back to $actionName;
        // every blank-configured action generated a controller method whose
        // name collided with any other blank-configured action on the module.
        $serviceNameRaw = !empty($action['serviceName']) ? $action['serviceName'] : $actionName;
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

        // Opt-in splash endpoint for this action — see ActionSplashServiceGenerator.
        if (!empty($action['splash'])) {
            $splashStub = $this->getTemplateContent('Features/actionSplash/controller_method', 'backend');
            $methods[] = str_replace(
                ['[[methodName]]', '[[ModuleName]]', '[[ActionName]]'],
                [lcfirst($serviceNameRaw), $this->moduleName, $serviceNameRaw],
                $splashStub
            );
        }

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($action['operations'][$op]['enabled'])) {
                continue;
            }

            // Falls back to $serviceNameRaw, not $actionName — MUST match
            // RoutesGenerator::generateActionRoutes()'s identical fallback.
            // Falling back to $actionName here let a customized serviceName
            // (with no explicit methodName) generate a route that pointed at
            // a controller method name this method never actually emitted.
            $baseMethod = !empty($action['methodName']) ? $action['methodName'] : $serviceNameRaw;
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
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Export {$name} records to CSV, XLSX, or PDF.
     * GET /api/{$routePath}/list/export?format=csv
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
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Download import template with correct column headers.
     * GET /api/{$routePath}/import/template?format=csv
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
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Import {$name} records from uploaded CSV or XLSX.
     * POST /api/{$routePath}/import
     */
    public function import{$name}(Request \$request): \Illuminate\Http\JsonResponse
    {
        \$result = {$svc}::execute_import(\$request->all(), \$request->file('file'));
        return response()->json(\$result, \$result['code']);
    }
PHP;
    }
}

