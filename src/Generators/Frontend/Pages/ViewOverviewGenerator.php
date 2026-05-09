<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ViewOverviewGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/view/overview', 'frontend');
        
        // Prefer new payload fields for view
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        $frontendConfig = $this->config ?? [];
        if (!empty($viewConfig['fields']) && is_array($viewConfig['fields'])) {
            $mappedFields = $this->mapViewFieldsToInformationFields($viewConfig['fields']);
            $frontendConfig = ['sections' => [[
                'key' => 'information',
                'title' => 'Information',
                'fields' => $mappedFields,
            ]]];
        }
        
        // Generate sections
        $sections = $this->generateViewSections($frontendConfig);
        
        // Check if formatDate import is needed
        $formatDateImport = $this->generateFormatDateImport($frontendConfig);
        
        $content = $this->replacePlaceholders($content, [
            '[[sections]]' => $sections,
            '[[formatDateImport]]' => $formatDateImport,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}DetailsOverviewPage.vue";

        return $this->writeFile($filePath, $content);
    }
}

