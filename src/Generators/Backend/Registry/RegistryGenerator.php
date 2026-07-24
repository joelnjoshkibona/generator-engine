<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Registry;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class RegistryGenerator extends BaseGenerator
{
    protected array $config;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->config = $config;
    }

    public function generate(): bool
    {
        return $this->updateCoreRegistry() && $this->updateSystemRegistry();
    }

    /**
     * Update the core registry file (only for Core modules)
     */
    protected function updateCoreRegistry(): bool
    {
        // Only add Core modules to registry_core.json
        if ($this->moduleGroup !== 'Core') {
            return true; // Skip for non-core modules
        }
        
        $registryPath = PathManager::getBackendRegistryPath() . '/registry_core.json';
        $existingRegistry = $this->loadExistingRegistry($registryPath);
        
        // Create module entry
        $subGroupPath = $this->moduleSubGroup ? "/{$this->moduleSubGroup}" : '';
        $moduleEntry = [
            'namespace' => $this->getNamespace(),
            'path' => "app/Project/Modules/{$this->moduleGroup}{$subGroupPath}/{$this->moduleName}",
            'type' => $this->moduleGroup,
            'description' => $this->config['module']['description'] ?? "{$this->moduleName} module"
        ];

        // Add or update module entry
        $existingRegistry[$this->moduleName] = $moduleEntry;

        return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Update the system registry file (only for System modules)
     */
    protected function updateSystemRegistry(): bool
    {
        if ($this->moduleGroup !== 'System') {
            return true; // Skip for non-system modules
        }

        $registryPath = PathManager::getBackendRegistryPath() . '/registry.json';
        $existingRegistry = $this->loadExistingRegistry($registryPath);

        // Create module entry
        $subGroupPath = $this->moduleSubGroup ? "/{$this->moduleSubGroup}" : '';
        $moduleEntry = [
            'namespace' => $this->getNamespace(),
            'path' => "app/Project/Modules/System{$subGroupPath}/{$this->moduleName}",
            'type' => 'System',
            'description' => $this->config['module']['description'] ?? "{$this->moduleName} module"
        ];
        
        // Add or update module entry
        $existingRegistry[$this->moduleName] = $moduleEntry;
        
        return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Load existing registry file
     */
    protected function loadExistingRegistry(string $path): array
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
     * Get the registry entry for this module
     */
    public function getRegistryEntry(): array
    {
        $subGroupPath = $this->moduleSubGroup ? "/{$this->moduleSubGroup}" : '';
        return [
            'namespace' => $this->getNamespace(),
            'path' => "app/Project/Modules/{$this->moduleGroup}{$subGroupPath}/{$this->moduleName}",
            'type' => $this->moduleGroup,
            'description' => $this->config['module']['description'] ?? "{$this->moduleName} module",
            'connection' => $this->config['connection'] ?? null,
        ];
    }

    /**
     * Remove module from registry (for cleanup)
     */
    public function removeFromRegistry(): bool
    {
        return $this->removeFromCoreRegistry() && $this->removeFromSystemRegistry();
    }

    /**
     * Remove from core registry (only for Core modules)
     */
    protected function removeFromCoreRegistry(): bool
    {
        // Only remove Core modules from registry_core.json
        if ($this->moduleGroup !== 'Core') {
            return true; // Skip for non-core modules
        }
        
        $registryPath = PathManager::getBackendRegistryPath() . '/registry_core.json';
        $existingRegistry = $this->loadExistingRegistry($registryPath);
        
        if (isset($existingRegistry[$this->moduleName])) {
            unset($existingRegistry[$this->moduleName]);
            return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        
        return true;
    }

    /**
     * Remove from system registry
     */
    protected function removeFromSystemRegistry(): bool
    {
        if ($this->moduleGroup !== 'System') {
            return true;
        }
        
        $registryPath = PathManager::getBackendRegistryPath() . '/registry.json';
        $existingRegistry = $this->loadExistingRegistry($registryPath);
        
        if (isset($existingRegistry[$this->moduleName])) {
            unset($existingRegistry[$this->moduleName]);
            return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        
        return true;
    }
}
