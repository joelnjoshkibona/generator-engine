<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ListPageGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['list'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/list/page', 'frontend');

        // Generate columns and custom cell renderers from list fields if available
        $listConfig = $this->config['features']['frontend']['list'] ?? [];
        $columns = '';
        $primaryKey = 'name';
        $customCellRenderers = '';

        if (!empty($listConfig['fields']) && is_array($listConfig['fields'])) {
            $columns = $this->generateColumnsFromListFields($listConfig['fields']);

            // Determine primary field key
            $primaryFieldKey = $listConfig['primaryField'] ?? '';
            if (empty($primaryFieldKey) && !empty($listConfig['fields'])) {
                $firstField = $listConfig['fields'][0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? 'name';
            }
            $primaryKey = $primaryFieldKey ?: 'name';

            // Generate custom cell renderers for badge/boolean fields
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listConfig['fields'], $primaryKey);
        }

        $content = $this->replacePlaceholders($content, [
            '[[columns]]'             => $columns,
            '[[customCellRenderers]]' => $customCellRenderers,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}ListPage.vue";

        return $this->writeFile($filePath, $content);
    }
}
