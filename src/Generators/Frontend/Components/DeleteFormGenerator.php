<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class DeleteFormGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        // $frontendConfig = $this->config['features']['frontend']['delete'] ?? null;
        // if (empty($frontendConfig)) {
        //     return false;
        // }

        $content = $this->getTemplateContent('features/delete/form', 'frontend');
        $content = $this->replacePlaceholders($content);

        $componentName = $this->moduleName . 'DeleteForm';
        $frontendModulePath = \Blutrixx\GeneratorEngine\Generators\PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
        $filePath = "{$frontendModulePath}/Components/{$componentName}.vue";

        return $this->writeFile($filePath, $content);
    }
}
