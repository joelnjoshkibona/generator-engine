<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class DeleteCheckServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['delete'] ?? null;
        if (empty($backendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('Features/deleteCheck/service', 'backend');
        $content = $this->replacePlaceholders($content, [
            '[[dependentCountChecks]]' => $this->generateDependentCountChecks(),
        ]);

        $serviceName = $this->moduleName . 'DeleteCheckService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Build PHP code that counts dependent records referencing this module's table.
     *
     * Uses the FK graph from PathManager to find which tables point at this table,
     * then resolves the source module namespace to emit a typed count query.
     */
    protected function generateDependentCountChecks(): string
    {
        $tableName = $this->config['table_name'] ?? '';
        if ($tableName === '') {
            return '// No table name configured — cannot derive dependents.';
        }

        $graph      = PathManager::getForeignKeyGraph();
        $dependents = $graph[$tableName] ?? [];

        if (empty($dependents)) {
            return '// No dependent tables detected — safe to delete without checks.';
        }

        $lines = [];
        $seen  = []; // dedup source_table + source_column combos

        foreach ($dependents as $dep) {
            $sourceTable  = $dep['source_table']  ?? '';
            $sourceColumn = $dep['source_column'] ?? '';

            if ($sourceTable === '' || $sourceColumn === '') {
                continue;
            }
            $key = "{$sourceTable}.{$sourceColumn}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Resolve module entry from registry by table name
            $moduleEntry = PathManager::findModuleByTable($sourceTable);
            if ($moduleEntry === null) {
                // Cannot resolve: emit a commented-out hint
                $lines[] = "// Could not resolve module for table '{$sourceTable}' — add it to the registry.";
                $lines[] = "// \$count += \\DB::table('{$sourceTable}')->where('{$sourceColumn}', \$record->id)->count();";
                continue;
            }

            $childModuleName  = $moduleEntry['name'];
            $childModuleGroup = Str::studly($moduleEntry['module_type'] ?? 'Core');
            $childGroupName   = !empty($moduleEntry['group_name'])
                ? Str::studly($moduleEntry['group_name'])
                : null;

            $ns = "App\\Project\\Modules\\{$childModuleGroup}";
            if ($childGroupName) {
                $ns .= "\\{$childGroupName}";
            }
            $ns .= "\\{$childModuleName}";
            $modelClass = "\\{$ns}\\{$childModuleName}Model";

            $lines[] = "\$count += {$modelClass}::where('{$sourceColumn}', \$record->id)->count();";
        }

        if (empty($lines)) {
            return '// No resolvable dependents — safe to delete without checks.';
        }

        return implode("\n        ", $lines);
    }
}
