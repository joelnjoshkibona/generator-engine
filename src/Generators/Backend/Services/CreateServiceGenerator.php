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

        // Combine legacy field-level processing with processor-array logic.
        // File-column uploads run first: any file_columns-marked field arrives
        // here as a raw UploadedFile (see generateFileColumnUploads()'s
        // docblock) and must become a Media int id before any other
        // before-save processing (legacy processors, processor-array calls)
        // has a chance to see -- and mishandle -- an UploadedFile instance.
        $fileUploads = $this->generateFileColumnUploads(false);
        $beforeLegacy = $this->generateCustomFieldProcessing('create', 'before');
        $beforeProcessors = $this->generateProcessorCalls('create', 'before_save');
        $beforeCreate = trim("{$fileUploads}\n{$beforeLegacy}\n{$beforeProcessors}", "\n");
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
            '[[validationRules]]'      => $this->generateValidationRules(false),
            '[[validationMessages]]'   => $this->generateValidationMessages(false),
            '[[beforeCreate]]'         => $beforeCreate,
            '[[afterCreate]]'          => $afterCreate,
            '[[inlineItemsExtract]]'   => $this->generateInlineItemsExtract(),
            '[[inlineItemsSave]]'      => $this->generateInlineItemsSave(),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'CreateService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    private function generateInlineItemsSave(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key        = $item['key'];
            $childNs    = $this->buildChildNamespace($item['child_group'], $item['child_module']);
            $modelClass = "\\{$childNs}\\{$item['child_module']}Model";
            $injectArr  = $this->buildInlineInjectArray($item);

            $blocks[] = implode("\n        ", [
                "// Save {$key}",
                "foreach (\$inlineData['{$key}'] ?? [] as \$inlineItem) {",
                "    unset(\$inlineItem['uuid']);",
                "    {$modelClass}::create(array_merge(\$inlineItem, {$injectArr}));",
                "}",
            ]);
        }

        return implode("\n        ", $blocks);
    }
}

