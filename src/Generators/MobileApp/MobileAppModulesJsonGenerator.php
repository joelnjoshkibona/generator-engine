<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Updates modules.json in MOBILE_APP to register the generated module.
 * Identical pattern to FRONTEND ModulesJsonGenerator but writes to MOBILE_APP path.
 */
class MobileAppModulesJsonGenerator extends BaseGenerator
{
    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
    }

    public function generate(): bool
    {
        $modulesJsonPath = PathManager::getMobileAppSrcPath() . '/modules.json';
        $existingModules = $this->loadExistingModules($modulesJsonPath);

        // Add new module to modules.json
        //
        // Deliberately flat -- no sub-group segment. Must match
        // PathManager::getMobileAppModulePath() exactly, or routes.ts never
        // resolves (router.ts's loadModuleRoutes() matches modules.json's
        // `path` against the glob'd file tree by suffix; a mismatch here
        // means the module's real routes.ts is silently never registered,
        // and every one of its pages 404s via the router's catch-all).
        // Confirmed live: this generator's own path used to nest by
        // sub-group ("/modules/system/demo/Expenses") while
        // getMobileAppModulePath() writes files flat ("/modules/system/
        // Expenses") -- see that method's own docblock for why mobile's
        // tree is flat. Only the top-level group is lowercased, matching
        // getMobileAppModulePath()'s convention.
        $modulePath = "/modules/" . strtolower($this->moduleGroup) . "/" . $this->moduleName;
        $existingModules[$this->moduleName] = [
            'path' => $modulePath
        ];

        return $this->writeFileAlways($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

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

    public function removeFromModules(): bool
    {
        $modulesJsonPath = PathManager::getMobileAppSrcPath() . '/modules.json';
        $existingModules = $this->loadExistingModules($modulesJsonPath);

        if (isset($existingModules[$this->moduleName])) {
            unset($existingModules[$this->moduleName]);
            return $this->writeFileAlways($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return true;
    }
}
