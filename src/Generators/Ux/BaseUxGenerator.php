<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class BaseUxGenerator
{
    protected array $blueprint;
    protected array $created = [];
    protected array $skipped = [];

    public function __construct(array $blueprint)
    {
        $this->blueprint = $blueprint;
    }

    protected function getGroupForModule(string $moduleName): ?string
    {
        $tableSnake = $this->studlyToSnake($moduleName);
        foreach ($this->blueprint['groups'] ?? [] as $group => $tables) {
            if ($group === '') continue;
            if (in_array($tableSnake, $tables)) return $group;
        }
        return null;
    }

    protected function studlyToSnake(string $name): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    protected function studlyToKebab(string $name): string
    {
        return str_replace('_', '-', $this->studlyToSnake($name));
    }

    protected function studlyCamel(string $name): string
    {
        return lcfirst($name);
    }

    protected function getModuleNamespace(string $moduleName, string $group): string
    {
        return "App\\Project\\Modules\\{$group}\\{$moduleName}";
    }

    protected function getModuleBackendPath(string $moduleName, string $group): string
    {
        return PathManager::getBackendModulePath($group, $moduleName);
    }

    protected function getModuleFrontendPath(string $moduleName, string $group): string
    {
        return PathManager::getFrontendModulePath($group, $moduleName);
    }

    protected function writeFile(string $path, string $content, bool $overwrite = false): bool
    {
        if (file_exists($path) && !$overwrite) {
            $this->skipped[] = $path;
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, $content);
        $this->created[] = $path;
        return true;
    }

    protected function loadStub(string $stubName): string
    {
        $path = PathManager::getUxTemplatePath() . '/' . $stubName;
        if (!file_exists($path)) {
            throw new \RuntimeException("UX stub not found: {$path}");
        }
        return file_get_contents($path);
    }

    public function getCreated(): array { return $this->created; }
    public function getSkipped(): array { return $this->skipped; }
}
