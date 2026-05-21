<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

abstract class BaseMobileBackendGenerator extends BaseGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppBackendModulePath($this->moduleGroup, $this->moduleName);
    }

    protected function getNamespace(): string
    {
        return "App\\Modules\\{$this->moduleGroup}\\{$this->moduleName}";
    }

    protected function getServicesNamespace(): string
    {
        return $this->getNamespace() . '\\Services';
    }

    protected function loadStub(string $name): string
    {
        $path = PathManager::getMobileAppBackendTemplatePath() . "/{$name}.stub";
        if (!file_exists($path)) {
            throw new \RuntimeException("Mobile backend stub not found: {$path}");
        }
        return file_get_contents($path);
    }

    public function generate(): bool
    {
        return false;
    }
}
