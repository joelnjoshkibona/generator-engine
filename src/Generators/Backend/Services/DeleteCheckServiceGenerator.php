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

                // A skip-group table is intentionally never generated as a
                // module, so an unresolved lookup for it is expected and must
                // stay quiet (see PathManager::isSkipTable()). Anything else
                // reaching here is a table that SHOULD have resolved -- it's
                // either already on the filesystem or declared in this run's
                // pre-seeded blueprint registry (see MakeModulesFromDb's
                // pre-loop registry seed) -- and didn't. That's exactly the
                // silent pass-1 bug this warning exists to catch: the module
                // still generates and is usable (the placeholder is only a
                // comment), so this is a warning, not a hard failure, routed
                // through the same issue-handler channel
                // resolveBackendModuleNamespace()/resolveFrontendImportSegment()
                // already use for the analogous unresolved-reference case.
                if (!PathManager::isSkipTable($sourceTable)) {
                    PathManager::reportIssue(
                        "DeleteCheckService for '{$tableName}': could not resolve module for dependent table '{$sourceTable}' — emitted a commented-out placeholder instead of a working count() check. Add '{$sourceTable}' to the module registry.",
                        'warning'
                    );
                }
                continue;
            }

            // Confirmed bug (THC_V2, GET /api/{module}/{uuid}/delete/check
            // returning 500 "Column not found: 1054 Unknown column 'item_id'
            // in 'where clause'" against `inventory_logs`): the FK graph fed
            // into this generator during a `--blueprint=` run is loaded
            // verbatim from the blueprint JSON file (see MakeModulesFromDb's
            // Stage 2, which sets `$fkGraph = $blueprint['foreign_key_graph']`
            // straight from disk, never re-scanning the live schema) rather
            // than freshly re-derived from the database at generation time.
            // That frozen snapshot had gone stale relative to the actual
            // `inventory_logs` table (which only has `inventory_id`, not
            // `item_id`) -- yet this method blindly trusted `source_column`
            // and emitted a `where('item_id', ...)` against a column that
            // does not exist, a runtime SQL error rather than a generation-
            // time one. A stale/incorrect graph entry can happen regardless
            // of *why* it went stale, so this generator must not trust
            // `source_column` blindly: verify it actually exists on the
            // dependent table's CURRENT live schema before emitting a query
            // against it, exactly the same "verify before trusting metadata"
            // principle the unresolved-module branch above already applies
            // to `source_table`.
            if (!$this->dependentColumnExists($sourceTable, $sourceColumn)) {
                $lines[] = "// FK graph declared '{$sourceTable}.{$sourceColumn}' but that column does not exist on the current schema — emitted a commented-out placeholder instead of a working count() check.";
                $lines[] = "// \$count += \\DB::table('{$sourceTable}')->where('{$sourceColumn}', \$record->id)->count();";

                PathManager::reportIssue(
                    "DeleteCheckService for '{$tableName}': FK graph column '{$sourceTable}.{$sourceColumn}' does not exist on the current schema (likely a stale FK graph snapshot) — emitted a commented-out placeholder instead of a working count() check.",
                    'warning'
                );
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

    /**
     * Whether $column actually exists on $table in the CURRENT live schema.
     *
     * Guards against a stale/incorrect FK graph snapshot (see the docblock
     * above generateDependentCountChecks()'s call site). Deliberately fails
     * OPEN (returns true, i.e. "trust the graph") rather than closed when no
     * Laravel application is booted -- this class's own unit test suite
     * (DeleteCheckServiceGeneratorTest) instantiates the generator directly
     * via reflection with no facade root available at all, exactly like
     * BaseGenerator::__construct()'s own PathManager::ensureOutputDirectories()
     * dependency documented in that test. Failing open there preserves this
     * method's pre-existing, already-tested behaviour byte-for-byte; failing
     * closed would silently turn every dependent into a placeholder comment
     * the moment Laravel isn't booted, which is worse than the bug being
     * fixed for any caller that (for whatever reason) can't resolve the
     * schema at generation time.
     */
    protected function dependentColumnExists(string $table, string $column): bool
    {
        $connection = $this->config['connection'] ?? null;

        try {
            $schema = $connection
                ? \Illuminate\Support\Facades\Schema::connection($connection)
                : \Illuminate\Support\Facades\Schema::getFacadeRoot();

            return $schema->hasColumn($table, $column);
        } catch (\Throwable) {
            return true;
        }
    }
}
