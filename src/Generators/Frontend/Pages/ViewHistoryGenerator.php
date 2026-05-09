<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ViewHistoryGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/view/history', 'frontend');
        
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        $idParam = $viewConfig['idParam'] ?? 'uuid';
        
        $content = $this->replacePlaceholders($content, [
            '[[idParam]]' => $idParam,
            '[[moduleName]]' => $this->moduleName,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}DetailsHistoryPage.vue";

        return $this->writeFile($filePath, $content);
    }
}
