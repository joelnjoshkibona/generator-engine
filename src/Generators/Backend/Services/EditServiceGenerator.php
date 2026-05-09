<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class EditServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['edit'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/edit/service', 'backend');

        // Combine legacy field-level processing with processor-array logic
        $beforeLegacy = $this->generateCustomFieldProcessing('edit', 'before');
        $beforeProcessors = $this->generateProcessorCalls('edit', 'before_save');
        $beforeUpdate = trim("{$beforeLegacy}\n{$beforeProcessors}", "\n");
        if (empty($beforeUpdate)) {
            $beforeUpdate = '// No custom field processing';
        }

        $afterLegacy = $this->generateCustomFieldProcessing('edit', 'after');
        $afterProcessors = $this->generateProcessorCalls('edit', 'after_save');
        $afterUpdate = trim("{$afterLegacy}\n{$afterProcessors}", "\n");
        if (empty($afterUpdate)) {
            $afterUpdate = '// No custom field processing';
        }

        $replacements = [
            '[[validationRules]]' => $this->generateValidationRules(true),
            '[[validationMessages]]' => $this->generateValidationMessages(true),
            '[[beforeUpdate]]' => $beforeUpdate,
            '[[afterUpdate]]' => $afterUpdate,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'EditService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }
}

