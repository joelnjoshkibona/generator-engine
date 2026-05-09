<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates the DeleteForm component for MOBILE_APP.
 * Delete form is identical between FRONTEND and MOBILE_APP.
 */
class DeleteFormGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        // Generate delete form if delete or view feature is enabled
        $hasDelete = !empty($this->config['features']['frontend']['delete']);
        $hasView = !empty($this->config['features']['frontend']['view']);

        if (!$hasDelete && !$hasView) {
            return false;
        }

        $content = $this->getTemplateContent('features/delete/form', 'mobile_app');
        $content = $this->replacePlaceholders($content);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}DeleteForm.vue";

        return $this->writeFile($filePath, $content);
    }
}
