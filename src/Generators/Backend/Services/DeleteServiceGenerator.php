<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class DeleteServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['delete'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/delete/service', 'backend');

        // Wire processor calls for before/after delete stages
        $beforeDeleteCode = $this->generateProcessorCalls('delete', 'before_delete');
        $afterDeleteCode = $this->generateProcessorCalls('delete', 'after_delete');

        $replacements = [
            '[[beforeDelete]]' => empty($beforeDeleteCode) ? '' : $beforeDeleteCode,
            '[[afterDelete]]' => empty($afterDeleteCode) ? '' : $afterDeleteCode,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'DeleteService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }
}

