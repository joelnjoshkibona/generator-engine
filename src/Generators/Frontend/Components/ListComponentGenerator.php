<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class ListComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['list'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/list/component', 'frontend');

        // Generate columns from list fields if available
        $listConfig = $this->config['features']['frontend']['list'] ?? [];
        $columns = '';
        $primaryKey = 'name';
        $primaryCellContent = '';

        // Determine ID field based on id_type config
        $idType = $this->config['id_type'] ?? 'autoincrement';
        $idField = ($idType === 'uuid') ? 'uuid' : 'id';

        if (!empty($listConfig['fields']) && is_array($listConfig['fields'])) {
            $columns = $this->generateColumnsFromListFields($listConfig['fields']);

            // Get primary field key
            $primaryFieldKey = $listConfig['primaryField'] ?? '';
            if (empty($primaryFieldKey) && !empty($listConfig['fields'])) {
                $firstField = $listConfig['fields'][0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? 'name';
            }
            $primaryKey = $primaryFieldKey ?: 'name';

            // Generate primary cell content for responsive display (excludes primary field)
            $primaryCellContent = $this->generatePrimaryCellContentFromListFields($listConfig['fields'], $primaryKey);

            // Generate custom cell renderers for badge/boolean fields
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listConfig['fields'], $primaryKey);
        } else {
            $customCellRenderers = '';
        }

        $exportEnabled = !empty($this->config['features']['backend']['list']['export']);
        $importEnabled = !empty($this->config['features']['backend']['list']['import']);

        $content = $this->replacePlaceholders($content, [
            '[[columns]]' => $columns,
            '[[primaryKey]]' => $primaryKey,
            '[[primaryCellContent]]' => $primaryCellContent,
            '[[customCellRenderers]]' => $customCellRenderers,
            '[[idField]]' => $idField,
            '[[additionalImports]]' => '',
            '[[enableExport]]' => $exportEnabled ? 'true' : 'false',
            '[[enableImport]]' => $importEnabled ? 'true' : 'false',
            '[[bulkActionsLiteral]]' => $this->generateBulkActionsLiteral(),
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}List.vue";
        return $this->writeFile($filePath, $content);
    }

    protected function generateBulkActionsLiteral(): string
    {
        $bulkActions = $this->config['features']['backend']['list']['bulk_actions'] ?? [];
        if (empty($bulkActions)) {
            return '[]';
        }

        $entries = [];
        foreach ($bulkActions as $action) {
            $key = $action['key'] ?? '';
            if (empty($key)) {
                continue;
            }
            $parts = ["key: '" . addslashes($key) . "'"];
            $parts[] = "label: '" . addslashes($action['label'] ?? $key) . "'";
            if (!empty($action['icon'])) {
                $parts[] = "icon: '" . addslashes($action['icon']) . "'";
            }
            if (!empty($action['requiresPermission'])) {
                $parts[] = "requiresPermission: '" . addslashes($action['requiresPermission']) . "'";
            }
            if (!empty($action['confirmMessage'])) {
                $parts[] = "confirmMessage: '" . addslashes($action['confirmMessage']) . "'";
            }
            if (!empty($action['variant'])) {
                $parts[] = "variant: '" . addslashes($action['variant']) . "'";
            }
            $entries[] = "\n\t{ " . implode(', ', $parts) . " }";
        }

        return '[' . implode(',', $entries) . "\n]";
    }

}

