<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class ViewModalGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $content = $this->getTemplateContent('features/view/modal', 'frontend');
        $content = $this->replacePlaceholders($content);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}ViewModal.vue";

        return $this->writeFile($filePath, $content);
    }
}
