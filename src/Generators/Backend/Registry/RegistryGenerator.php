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
        return $this->updateRegistryForGroup();
    }

    /**
     * Write this module's entry into the registry tier that matches its
     * module group.
     *
     * `Core` modules go to registry_core.json. Every other group -
     * `System`, `Custom`, or anything else a project invents - goes to
     * the general registry.json tier, which is the tier consumers already
     * merge for non-core modules (see the consuming app's Registry.php).
     * Previously `updateCoreRegistry()`/`updateSystemRegistry()` were
     * gated to exactly 'Core' and exactly 'System' respectively, so any
     * other group (e.g. 'Custom') matched neither guard and the module
     * was silently never written to any registry.
     */
    protected function updateRegistryForGroup(): bool
    {
        $registryPath = PathManager::getBackendRegistryPath() . '/' . $this->registryFileForGroup();
        $existingRegistry = $this->loadExistingRegistry($registryPath);

        $subGroupPath = $this->moduleSubGroup ? "/{$this->moduleSubGroup}" : '';
        $existingRegistry[$this->moduleName] = [
            'namespace' => $this->getNamespace(),
            'path' => "app/Project/Modules/{$this->moduleGroup}{$subGroupPath}/{$this->moduleName}",
            'type' => $this->moduleGroup,
            'description' => $this->config['module']['description'] ?? "{$this->moduleName} module",
        ];

        return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Determine which registry tier file this module's group belongs in.
     */
    protected function registryFileForGroup(): string
    {
        return $this->moduleGroup === 'Core' ? 'registry_core.json' : 'registry.json';
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
        return $this->removeFromRegistryForGroup();
    }

    /**
     * Remove this module's entry from the registry tier that matches its
     * module group (mirrors updateRegistryForGroup()).
     */
    protected function removeFromRegistryForGroup(): bool
    {
        $registryPath = PathManager::getBackendRegistryPath() . '/' . $this->registryFileForGroup();
        $existingRegistry = $this->loadExistingRegistry($registryPath);

        if (isset($existingRegistry[$this->moduleName])) {
            unset($existingRegistry[$this->moduleName]);
            return $this->writeFileAlways($registryPath, json_encode($existingRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return true;
    }
}
