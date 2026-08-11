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

        // Combine legacy field-level processing with processor-array logic.
        // File-column uploads run first -- see the matching comment in
        // CreateServiceGenerator::generate() and
        // BaseServiceGenerator::generateFileColumnUploads()'s docblock for the
        // full wire-key contract and the edit-specific optional-reupload
        // behaviour (unset when no new file was sent, leaving the model's
        // existing media_id column untouched).
        $fileUploads = $this->generateFileColumnUploads(true);
        $beforeLegacy = $this->generateCustomFieldProcessing('edit', 'before');
        $beforeProcessors = $this->generateProcessorCalls('edit', 'before_save');
        $beforeUpdate = trim("{$fileUploads}\n{$beforeLegacy}\n{$beforeProcessors}", "\n");
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
            $key         = $item['key'];
            $parentFk    = $item['parent_fk'];
            $childNs     = $this->buildChildNamespace($item['child_group'], $item['child_module'], $item['child_group_name'] ?? null);
            $modelClass  = "\\{$childNs}\\{$item['child_module']}Model";
            // Separate arrays, not one shared $_inject: a row created here
            // (no uuid yet) needs created_by_id, while an existing row
            // going through updateOrCreate() needs updated_by_id -- sharing
            // one array would silently overwrite created_by_id on every
            // edit of an already-existing child row.
            $createInject = $this->buildInlineInjectArray($item, 'created_by_id');
            $updateInject = $this->buildInlineInjectArray($item, 'updated_by_id');

            $blocks[] = implode("\n        ", [
                "// Sync {$key}",
                "\$_existingUuids = collect(\$inlineData['{$key}'] ?? [])->pluck('uuid')->filter()->values()->all();",
                "{$modelClass}::where('{$parentFk}', \$model->id)->whereNotIn('uuid', \$_existingUuids)->delete();",
                "foreach (\$inlineData['{$key}'] ?? [] as \$inlineItem) {",
                "    \$_uuid = \$inlineItem['uuid'] ?? null;",
                "    unset(\$inlineItem['uuid']);",
                "    if (\$_uuid) {",
                "        {$modelClass}::updateOrCreate(['uuid' => \$_uuid], array_merge(\$inlineItem, {$updateInject}));",
                "    } else {",
                "        {$modelClass}::create(array_merge(\$inlineItem, {$createInject}));",
                "    }",
                "}",
            ]);
        }

        return implode("\n        ", $blocks);
    }
}

