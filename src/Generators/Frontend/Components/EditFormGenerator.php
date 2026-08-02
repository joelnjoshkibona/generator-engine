<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class EditFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['edit'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/edit/form', 'frontend');
        
        // Prefer new payload fields for edit
        $editConfig = $this->config['features']['frontend']['edit'] ?? [];
        $frontendConfig = $this->config ?? [];
        
        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $fieldsForSubmit = [];

        $footer = $this->generateFormFooter('edit');

        if (!empty($editConfig['fields']) && is_array($editConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields, $footer);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $fieldsForSubmit = $mappedFields;
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'edit');
            if (!empty($fallbackFields)) {
                $mappedFields = $this->mapNewFormFieldsToLegacy($fallbackFields);
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields, $footer);
                $formFields = $this->generateFormFields(['fields' => $mappedFields]);
                $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
                $fieldsForSubmit = $mappedFields;
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig, $footer);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
                $fieldsForSubmit = $this->collectAllFieldsFromConfig($frontendConfig);
            }
        }

        // Conditional switching: only forms with a file-input field ever get
        // the FormData/sendFormDataRequest treatment -- everything else is
        // generated exactly as before (see generateRequestImportLine() /
        // generateFileRefsBlock() / generateSubmitCall()).
        $hasFileFields = $this->hasFileInputField($fieldsForSubmit);
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'edit');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'edit');

        // Splash plumbing is opt-in: only emitted when $config['constants'] is non-empty.
        $hasSplash = !empty($this->config['constants']);
        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $this->buildSplashBlocks('edit', $hasSplash);

        // Inline items — append form fields and imports, then generate the template block
        //
        // Bug (fixed 2026-08-03): same fix as CreateFormGenerator's
        // identical block -- see its comment for the full rationale. The
        // form no longer references InlineItemsComponent/InlineItemField
        // directly; it needs the wrapper component's own sibling import.
        $inlineItems = $this->config['inline_items'] ?? [];
        if (!empty($inlineItems)) {
            foreach ($inlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
                $componentName = $this->inlineItemsWrapperComponentName($item['key']);
                $formFieldImports .= "\nimport {$componentName} from './{$componentName}.vue';";
            }
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
            '[[requestImportLine]]'    => $requestImportLine,
            '[[fileRefsBlock]]'        => $fileRefsBlock,
            '[[submitCall]]'           => $submitCall,
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/Components/{$this->moduleName}EditForm.vue";
        
        return $this->writeFile($filePath, $content);
    }
}

