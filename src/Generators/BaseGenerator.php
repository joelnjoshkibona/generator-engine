<?php

namespace Blutrixx\GeneratorEngine\Generators;

use Illuminate\Support\Str;

abstract class BaseGenerator
{
    protected string $moduleName;
    protected string $moduleGroup;
    protected ?string $moduleSubGroup;
    protected string $modulePath;
    protected array $config;
    protected bool $force = false;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        $this->moduleName = $moduleName;
        $this->moduleGroup = PathManager::normalizeGroupName($moduleGroup);
        $this->moduleSubGroup = PathManager::getModuleSubGroup();
        $this->modulePath = $this->getModulePath();
        $this->config = $config;

        // Ensure output directories exist
        PathManager::ensureOutputDirectories();
    }

    protected function getModulePath(): string
    {
        return PathManager::getBackendModulePath($this->moduleGroup, $this->moduleName);
    }

    protected function getStubPath(string $stubName, string $type = 'backend'): string
    {
        $subdir = $type === 'backend' ? 'backend' : ($type === 'mobile_app' ? 'mobile_app' : 'frontend');
        if (function_exists('base_path')) {
            $override = base_path("stubs/generator/{$subdir}/{$stubName}.stub");
            if (is_file($override)) {
                return $override;
            }
        }

        if ($type === 'backend') {
            return PathManager::getBackendTemplatePath() . "/{$stubName}.stub";
        } elseif ($type === 'mobile_app') {
            return PathManager::getMobileAppTemplatePath() . "/{$stubName}.stub";
        } else {
            return PathManager::getFrontendTemplatePath() . "/{$stubName}.stub";
        }
    }

    protected function getTemplateContent(string $stubName, string $type = 'backend'): string
    {
        $stubPath = $this->getStubPath($stubName, $type);
        
        if (!file_exists($stubPath)) {
            throw new \Exception("Template file not found: {$stubPath}");
        }
        
        return file_get_contents($stubPath);
    }

    protected function replacePlaceholders(string $content, array $replacements = []): string
    {
        $defaultReplacements = [
            '[[ModuleName]]' => $this->moduleName,
            '[[moduleName]]' => strtolower($this->moduleName),
            // Permission key convention MUST match what the backend seeder actually creates.
            // seeder.stub calls `Helpers::saveModuleCRUDPermissions('[[ModuleName]]')` which
            // registers perms as `{ModuleName}.{action}` (PascalCase). The old behaviour
            // of falling back to $config['permission_base_name'] (typically lowercase
            // snake_case like "items") caused the frontend checks to look up
            // `items.create` while the DB has `Items.create` — users with the right role
            // saw "Access Denied" on every form. Always use PascalCase moduleName.
            '[[PermissionBaseName]]' => $this->moduleName,
            '[[moduleRoute]]' => Str::kebab($this->moduleName),
            '[[moduleNamePlural]]' => strtolower(Str::plural($this->moduleName)),
            '[[ModuleNamePlural]]' => Str::plural($this->moduleName),
            '[[moduleVarName]]' => strtolower($this->moduleName), // For variable names like 'users', 'roles', etc.
            '[[ModuleGroup]]' => $this->moduleGroup,
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[tableName]]' => $this->getTableName(),
            '[[namespace]]' => $this->getNamespace(),
            '[[ModuleNamespace]]' => $this->getNamespace(),
            '[[timestamp]]' => date('Y_m_d_His'),
        ];

        $replacements = array_merge($defaultReplacements, $replacements);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Convert a raw PascalCase/StudlyCase module (or feature) name into spaced
     * Title Case for human-facing text — menu labels, page/route titles, locale
     * strings, permission titles. E.g. "ItemCategories" -> "Item Categories",
     * "ZzzGeneratorVerifyTest" -> "Zzz Generator Verify Test".
     *
     * Grammatical number (singular/plural) is NOT touched here — callers pass
     * in whatever form (raw moduleName, or Str::singular($moduleName)) matches
     * the convention for that specific string.
     */
    protected function humanize(string $name): string
    {
        return Str::headline($name);
    }

    protected function getTableName(): string
    {
        // Use table_name from config if available
        if (isset($this->config['table_name']) && !empty($this->config['table_name'])) {
            return $this->config['table_name'];
        }
        
        // Fallback: Convert CamelCase to snake_case
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $this->moduleName));
    }

    protected function getNamespace(): string
    {
        $ns = "App\\Project\\Modules\\{$this->moduleGroup}";
        if ($this->moduleSubGroup) {
            $ns .= "\\{$this->moduleSubGroup}";
        }
        return $ns . "\\{$this->moduleName}";
    }

    protected function ensureDirectoryExists(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function setForce(bool $force): self
    {
        $this->force = $force;
        return $this;
    }

    protected function writeFile(string $path, string $content): bool
    {
        // Skip existing files unless force-overwrite is enabled
        if (!$this->force && file_exists($path)) {
            return false;
        }
        $this->ensureDirectoryExists($path);
        return file_put_contents($path, $content) !== false;
    }

    /**
     * Write a file unconditionally, bypassing writeFile()'s force-check.
     *
     * Use this for shared "registry" style outputs (module registries,
     * modules.json, menus.json, ...) that must be kept in sync on every
     * generation run, not just forced ones. Callers such as
     * ModuleScaffolder's generic runner construct the generator, then
     * unconditionally call setForce($force) using the CLI's --force
     * option — that silently clobbers any force=true a constructor set,
     * so writeFile() would skip the write whenever the shared file
     * already exists (the normal case for a plain, non --force run).
     * Mirrors the approach MobileRegistryGenerator has always used.
     */
    protected function writeFileAlways(string $path, string $content): bool
    {
        $this->ensureDirectoryExists($path);
        return file_put_contents($path, $content) !== false;
    }

    abstract public function generate(): bool;

    /**
     * Encode JSON for a shared config file, preserving the file's existing
     * indentation width.
     *
     * PHP's JSON_PRETTY_PRINT always emits 4 spaces per level. `menus.json` is
     * stored with 2, so every write reindented the entire file — one scaffolded
     * module produced a 439-line diff that was almost entirely whitespace. That
     * makes review impractical and turns any shared config file into a
     * guaranteed merge conflict as soon as two people scaffold.
     *
     * Detects the indent unit from the file's first indented line and rewrites
     * the encoded output to match. Falls back to PHP's native 4 when the file
     * does not exist or its indentation cannot be determined.
     */
    protected function encodeJsonPreservingIndent(string $path, array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $unit = $this->detectJsonIndentUnit($path);
        if ($unit === null || $unit === 4) {
            return $encoded;
        }

        // JSON_PRETTY_PRINT emits exactly 4 spaces per depth level; rescale.
        return preg_replace_callback(
            '/^( +)/m',
            static fn (array $m) => str_repeat(' ', (int) (strlen($m[1]) / 4) * $unit),
            $encoded
        );
    }

    /**
     * Number of spaces per indent level in an existing JSON file, or null.
     */
    protected function detectJsonIndentUnit(string $path): ?int
    {
        if (!file_exists($path)) {
            return null;
        }

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^( +)\S/', $line, $m)) {
                return strlen($m[1]);
            }
        }

        return null;
    }
}
