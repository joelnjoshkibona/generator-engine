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
    public function generate(): bool
    {
        $modulesJsonPath = PathManager::getMobileAppSrcPath() . '/modules.json';
        $existingModules = $this->loadExistingModules($modulesJsonPath);

        // Add new module to modules.json
        $subGroupPart = $this->moduleSubGroup ? '/' . strtolower($this->moduleSubGroup) : '';
        $modulePath = "/modules/" . strtolower($this->moduleGroup) . $subGroupPart . "/" . $this->moduleName;
        $existingModules[$this->moduleName] = [
            'path' => $modulePath
        ];

        return $this->writeFile($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
            return $this->writeFile($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return true;
    }
}
