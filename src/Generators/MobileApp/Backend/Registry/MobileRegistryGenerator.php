<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Registry;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class MobileRegistryGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $registryPath = PathManager::getMobileAppBasePath() . '/app/Modules/registry.json';

        // Read existing registry or start fresh
        $data = [];
        if (file_exists($registryPath)) {
            $decoded = json_decode(file_get_contents($registryPath), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Merge / overwrite the entry for the current module
        $data[$this->moduleName] = [
            'namespace' => "App\\Modules\\{$this->moduleGroup}\\{$this->moduleName}",
            'path'      => "app/Modules/{$this->moduleGroup}/{$this->moduleName}",
            'group'     => $this->moduleGroup,
        ];

        // Ensure the directory exists before writing
        $this->ensureDirectoryExists($registryPath);

        // Always overwrite — registry is the canonical source of truth
        $written = file_put_contents(
            $registryPath,
            $this->encodeJsonPreservingIndent($registryPath, $data) . "\n"
        );

        return $written !== false;
    }
}
