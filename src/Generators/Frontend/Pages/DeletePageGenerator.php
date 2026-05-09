<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class DeletePageGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        // $frontendConfig = $this->config['features']['frontend']['delete'] ?? null;
        // if (empty($frontendConfig)) {
        //     return false;
        // }

        $content = $this->getTemplateContent('features/delete/page', 'frontend');
        $content = $this->replacePlaceholders($content);

        $featureName = 'DeletePage';
        $fileName = $this->moduleName . $featureName . '.vue';
        $frontendModulePath = \Blutrixx\GeneratorEngine\Generators\PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
        $filePath = "{$frontendModulePath}/{$fileName}";

        return $this->writeFile($filePath, $content);
    }
}
