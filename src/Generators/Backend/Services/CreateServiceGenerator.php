<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class CreateServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['create'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/create/service', 'backend');

        // Combine legacy field-level processing with processor-array logic
        $beforeLegacy = $this->generateCustomFieldProcessing('create', 'before');
        $beforeProcessors = $this->generateProcessorCalls('create', 'before_save');
        $beforeCreate = trim("{$beforeLegacy}\n{$beforeProcessors}", "\n");
        if (empty($beforeCreate)) {
            $beforeCreate = '// No custom field processing';
        }

        $afterLegacy = $this->generateCustomFieldProcessing('create', 'after');
        $afterProcessors = $this->generateProcessorCalls('create', 'after_save');
        $afterCreate = trim("{$afterLegacy}\n{$afterProcessors}", "\n");
        if (empty($afterCreate)) {
            $afterCreate = '// No custom field processing';
        }

        $replacements = [
            '[[validationRules]]' => $this->generateValidationRules(false),
            '[[validationMessages]]' => $this->generateValidationMessages(false),
            '[[beforeCreate]]' => $beforeCreate,
            '[[afterCreate]]' => $afterCreate,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'CreateService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }
}

