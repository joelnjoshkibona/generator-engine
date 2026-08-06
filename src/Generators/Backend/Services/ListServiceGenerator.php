<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Helpers\BulkActionConfigNormalizer;

class ListServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['list'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }
        
        $content = $this->getTemplateContent('Features/list/service', 'backend');
        
        $replacements = [
            '[[filterableFields]]' => $this->generateFilterableFields(),
            '[[sortableFields]]' => $this->generateSortableFields(),
            '[[eagerLoadRelationships]]' => $this->generateEagerLoadRelationships('list'),
            '[[filterableRelationships]]' => $this->generateFilterableRelationships(),
            '[[filterFields]]' => $this->generateFilterFields(),
            '[[importMethods]]' => $this->generateImportMethods(),
            '[[bulkActionsArray]]' => $this->generateBulkActionsArray(),
            '[[defaultListFiltersArray]]' => $this->generateDefaultListFiltersArray(
                $this->config['features']['backend']['list']['default_list_filters'] ?? []
            ),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'ListService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }

    private function generateBulkActionsArray(): string
    {
        $bulkActions = BulkActionConfigNormalizer::normalizeAll(
            $this->config['features']['backend']['list']['bulk_actions'] ?? []
        );
        if (empty($bulkActions)) {
            return '[]';
        }
        $keys = array_map(fn($a) => "'" . addslashes($a['key']) . "'", $bulkActions);
        return '[' . implode(', ', $keys) . ']';
    }

    private function generateDefaultListFiltersArray(array $entries): string
    {
        if (empty($entries)) {
            return '[]';
        }

        $lines = [];
        foreach ($entries as $entry) {
            $column   = addslashes($entry['column'] ?? '');
            $operator = addslashes($entry['operator'] ?? 'eq');
            $raw      = $entry['value'] ?? '';
            $value    = $this->phpLiteralValue($raw);
            $lines[]  = "        '{$column}' => ['operator' => '{$operator}', 'value' => {$value}],";
        }

        return "[\n" . implode("\n", $lines) . "\n    ]";
    }

    /**
     * Convert a PHP value from config to a PHP literal string for emission.
     * Strings → quoted, numbers/booleans → bare, arrays → inline array literal.
     */
    private function phpLiteralValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(fn($v) => $this->phpLiteralValue($v), $value);
            return '[' . implode(', ', $items) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value) && !is_string($value)) {
            return (string) $value;
        }
        // Treat numeric strings as quoted (they are identifiers, not arithmetic)
        return "'" . addslashes((string) $value) . "'";
    }

    private function generateImportMethods(): string
    {
        $importEnabled = $this->config['features']['backend']['list']['import'] ?? false;
        if (!$importEnabled) {
            return '';
        }

        $name = $this->moduleName;

        return <<<PHP
    /**
     * Columns available for import (key => human-readable label).
     */
    protected static array \$importColumns = [
        // 'field_name' => 'Field Label',
    ];

    public static function getImportTemplate(?string \$format = 'csv'): mixed
    {
        return self::downloadImportTemplate(\$format);
    }

    /**
     * Public wrapper: importData() is protected, so an external caller (a
     * delegation's thin-proxy service, forcing its parent FK onto every
     * imported row) cannot call it directly. Mirrors execute_bulkAction()'s
     * existing role relative to processBulkAction().
     */
    public static function execute_import(array \$data, ?\Illuminate\Http\UploadedFile \$file, array \$forcedFields = []): array
    {
        return self::importData(\$data, \$file, \$forcedFields);
    }

    /**
     * Process a single import row. Throw an exception to mark the row as failed.
     *
     * \$forcedFields (e.g. a delegation's parent FK) must be merged AFTER
     * your own row validation — same rule as CreateService::execute()'s
     * \$params, for the same reason: validator()->validate() silently strips
     * any key your own rules don't declare.
     *   \$validRow = validator(\$row, [...])->validate();
     *   \$validRow = array_merge(\$validRow, \$forcedFields);
     */
    protected static function processImportRow(array \$row, int \$rowNumber, array \$forcedFields = []): void
    {
        // TODO: implement {$name} import logic
    }
PHP;
    }
}

