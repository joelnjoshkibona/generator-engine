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
        
        // Add new module to modules.json
        $subGroupPart = $this->moduleSubGroup ? '/' . strtolower($this->moduleSubGroup) : '';
        $modulePath = "/modules/" . strtolower($this->moduleGroup) . $subGroupPart . "/" . $this->moduleName;
        $existingModules[$this->moduleName] = [
            'path' => $modulePath
        ];
        
        return $this->writeFileAlways($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
     * Get the module entry for this module
     */
    public function getModuleEntry(): array
    {
        $subGroupPart = $this->moduleSubGroup ? '/' . strtolower($this->moduleSubGroup) : '';
        return [
            'path' => "/modules/" . strtolower($this->moduleGroup) . $subGroupPart . "/" . $this->moduleName
        ];
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
            return $this->writeFileAlways($modulesJsonPath, json_encode($existingModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
