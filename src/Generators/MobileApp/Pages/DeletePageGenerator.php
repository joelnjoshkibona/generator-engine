<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class DeletePageGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['delete'] ?? null;
        if (empty($frontendConfig)) {
            // Also generate if view is enabled (delete is typically paired with view)
            $viewConfig = $this->config['features']['frontend']['view'] ?? null;
            if (empty($viewConfig)) {
                return false;
            }
        }

        $content = $this->getTemplateContent('features/delete/page', 'mobile_app');
        $content = $this->replacePlaceholders($content);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}DeletePage.vue";

        return $this->writeFile($filePath, $content);
    }
}
