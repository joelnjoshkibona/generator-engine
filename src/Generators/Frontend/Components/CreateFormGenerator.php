<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class CreateFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['create'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/create/form', 'frontend');

        // Prefer new payload fields for create
        $createConfig = $this->config['features']['frontend']['create'] ?? [];
        $frontendConfig = $this->config;

        $formSections = '';
        $formFields = '';
        $formFieldImports = '';

        $footer = $this->generateFormFooter('create');

        if (!empty($createConfig['fields']) && is_array($createConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields, $footer);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'create');
            if (!empty($fallbackFields)) {
                $mappedFields = $this->mapNewFormFieldsToLegacy($fallbackFields);
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields, $footer);
                $formFields = $this->generateFormFields(['fields' => $mappedFields]);
                $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig, $footer);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
            }
        }

        // Splash plumbing is opt-in: only emitted when $config['constants'] is non-empty.
        $hasSplash = !empty($this->config['constants']);
        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $this->buildSplashBlocks('create', $hasSplash);

        // Inline items — append form fields and imports, then generate the template block
        $inlineItems = $this->config['inline_items'] ?? [];
        if (!empty($inlineItems)) {
            foreach ($inlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
            }
            $formFieldImports .= "\nimport { InlineItemsComponent } from '@/components/inline-items'";
            $formFieldImports .= "\nimport type { InlineItemField } from '@/components/inline-items'";
        }

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]'         => $formSections,
            '[[formFields]]'           => $formFields,
            '[[formFieldImports]]'     => $formFieldImports,
            '[[splashPropBlock]]'      => $splashPropBlock,
            '[[splashBlock]]'          => $splashBlock,
            '[[refreshAndSetBlock]]'   => $refreshAndSetBlock,
            '[[onMountedBlock]]'       => $onMountedBlock,
            '[[inlineItemsBlock]]'     => $this->generateInlineItemsBlock($inlineItems),
            '[[inlineItemsFieldDefs]]' => $this->generateInlineItemsFieldDefs($inlineItems),
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }
}

