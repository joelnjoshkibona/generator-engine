<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ModulesJsonGenerator extends BaseGenerator
{
    protected array $config;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->config = $config;
    }

    public function generate(): bool
    {
        $modulesJsonPath = PathManager::getFrontendSrcPath() . '/modules.json';
        $existingModules = $this->loadExistingModules($modulesJsonPath);

        $existingModules[$this->moduleName] = $this->getModuleEntry();

        return $this->writeFileAlways($modulesJsonPath, $this->encodeJsonPreservingIndent($modulesJsonPath, $existingModules));
    }

    /**
     * Load existing modules.json file
     */
    protected function loadExistingModules(string $path): array
    {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $decoded = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        return [];
    }

    /**
     * Get the module entry for this module.
     *
     * `type` mirrors RegistryGenerator's own `'type' => $this->moduleGroup`
     * (Core/System/Custom/... — whatever the caller passed as the module
     * group), so frontend module discovery (e2e test filtering by
     * type/group; see the FRONTEND-side e2e runner script) uses the same
     * taxonomy the backend registry already does instead of inventing a
     * second one. `group` is the module's sub-group (e.g. "Locations",
     * "Notifications", or a Custom module's own business-domain name like
     * "Expenses") and is only present when one was actually set — most
     * modules have none, and a missing key reads more cleanly in
     * modules.json than `"group": null` on every entry.
     *
     * NOTE: `make:module` never runs with group "Kernel" -- Auth/Logs/
     * Queue/Settings are hand-authored framework code that predates and
     * sits outside the generator entirely (same reason
     * RegistryGenerator::registryFileForGroup() never writes
     * registry_kernel.json). A Kernel-tier modules.json entry is always
     * hand-backfilled, never emitted from here.
     */
    public function getModuleEntry(): array
    {
        // Sub-group keeps its PascalCase: the frontend files are written to
        // /modules/{group}/{SubGroup}/{Module} (see PathManager::getFrontendModulePath()),
        // and router.ts looks the route file up by this exact path
        // (`/src/pages{$module.path}/routes.ts`). Lowercasing it here made that
        // lookup miss, so nested modules registered NO route and 404'd in the UI
        // while their menu entry still rendered.
        $subGroupPart = $this->moduleSubGroup ? '/' . $this->moduleSubGroup : '';

        $entry = [
            'path' => "/modules/" . strtolower($this->moduleGroup) . $subGroupPart . "/" . $this->moduleName,
            'type' => $this->moduleGroup,
        ];

        if ($this->moduleSubGroup) {
            $entry['group'] = $this->moduleSubGroup;
        }

        return $entry;
    }

    /**
     * Remove module from modules.json (for cleanup)
     */
    public function removeFromModules(): bool
    {
        $modulesJsonPath = PathManager::getFrontendSrcPath() . '/modules.json';
        $existingModules = $this->loadExistingModules($modulesJsonPath);
        
        if (isset($existingModules[$this->moduleName])) {
            unset($existingModules[$this->moduleName]);
            return $this->writeFileAlways($modulesJsonPath, $this->encodeJsonPreservingIndent($modulesJsonPath, $existingModules));
        }
        
        return true;
    }

    /**
     * Get all existing modules
     */
    public function getAllModules(): array
    {
        $modulesJsonPath = PathManager::getFrontendSrcPath() . '/modules.json';
        return $this->loadExistingModules($modulesJsonPath);
    }

    /**
     * Check if module already exists in modules.json
     */
    public function moduleExists(): bool
    {
        $existingModules = $this->getAllModules();
        return isset($existingModules[$this->moduleName]);
    }
}
