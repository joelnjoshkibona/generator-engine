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
            '[[validationRules]]'    => $this->generateValidationRules(true),
            '[[validationMessages]]' => $this->generateValidationMessages(true),
            '[[beforeUpdate]]'       => $beforeUpdate,
            '[[afterUpdate]]'        => $afterUpdate,
            '[[inlineItemsExtract]]' => $this->generateInlineItemsExtract(),
            '[[inlineItemsSync]]'    => $this->generateInlineItemsSync(),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'EditService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    private function generateInlineItemsSync(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key        = $item['key'];
            $parentFk   = $item['parent_fk'];
            $childNs    = $this->buildChildNamespace($item['child_group'], $item['child_module']);
            $modelClass = "\\{$childNs}\\{$item['child_module']}Model";
            $injectArr  = $this->buildInlineInjectArray($item);

            $blocks[] = implode("\n        ", [
                "// Sync {$key}",
                "\$_existingUuids = collect(\$inlineData['{$key}'] ?? [])->pluck('uuid')->filter()->values()->all();",
                "{$modelClass}::where('{$parentFk}', \$model->id)->whereNotIn('uuid', \$_existingUuids)->delete();",
                "foreach (\$inlineData['{$key}'] ?? [] as \$inlineItem) {",
                "    \$_uuid = \$inlineItem['uuid'] ?? null;",
                "    unset(\$inlineItem['uuid']);",
                "    \$_inject = {$injectArr};",
                "    if (\$_uuid) {",
                "        {$modelClass}::updateOrCreate(['uuid' => \$_uuid], array_merge(\$inlineItem, \$_inject));",
                "    } else {",
                "        {$modelClass}::create(array_merge(\$inlineItem, \$_inject));",
                "    }",
                "}",
            ]);
        }

        return implode("\n        ", $blocks);
    }
}

