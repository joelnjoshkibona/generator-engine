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

        // Draft autosave is on by default -- opt out via
        // features.frontend.edit.drafts: false in module.json.
        $hasDrafts = ($editConfig['drafts'] ?? true) !== false;

        $footer = $this->generateFormFooter('edit', $hasDrafts);

        // $footer is emitted as its own [[formFooter]] token (see below) --
        // see CreateFormGenerator's identical comment for the full rationale
        // (inline_items rendering below the Save/Create buttons).
        if (!empty($editConfig['fields']) && is_array($editConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $fieldsForSubmit = $mappedFields;
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'edit');
            if (!empty($fallbackFields)) {
                $mappedFields = $this->mapNewFormFieldsToLegacy($fallbackFields);
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
                $formFields = $this->generateFormFields(['fields' => $mappedFields]);
                $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
                $fieldsForSubmit = $mappedFields;
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig);
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
        $draftBlocks = $this->buildDraftBlocks('edit', $hasDrafts);

        // Inline items — append form fields and imports, then generate the template block
        //
        // Bug (fixed 2026-08-03): same fix as CreateFormGenerator's
        // identical block -- see its comment for the full rationale. The
        // form no longer references InlineItemsComponent/InlineItemField
        // directly; it needs the wrapper component's own sibling import.
        $inlineItems = $this->config['inline_items'] ?? [];
        if (!empty($inlineItems)) {
            // generateFormFields() joins fields with ",\n" and never trails
            // the last one with a comma -- appending directly here produced
            // a hard Vue SFC compile error (missing "," between the last
            // regular field and the first inline_items field), confirmed
            // live 2026-08-08 for every module carrying `inline_items`
            // (broke Orders*Form.vue outright, and cascaded via Vite's
            // global HMR error overlay into unrelated modules' e2e runs).
            if ($formFields !== '') {
                $formFields = rtrim($formFields) . ',';
            }
            foreach ($inlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
                $componentName = $this->inlineItemsWrapperComponentName($item['key']);
                $formFieldImports .= "\nimport {$componentName} from './{$componentName}.vue';";
            }
            // generateInlineItemsBlock() emits <Card>/<CardContent> elements;
            // add the ui/card import once for the whole block.
            $formFieldImports .= "\nimport { Card, CardContent } from '@/components/ui/card';";
        }

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]'         => $formSections,
            '[[formFooter]]'           => $footer,
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
            '[[draftBannerBlock]]'        => $draftBlocks['draftBannerBlock'],
            '[[draftImports]]'            => $draftBlocks['draftImports'],
            '[[draftWatchImport]]'        => $draftBlocks['draftWatchImport'],
            '[[draftSetupBlock]]'         => $draftBlocks['draftSetupBlock'],
            '[[discardDraftOnSuccess]]'   => $draftBlocks['discardDraftOnSuccess'],
            '[[draftCheckBlock]]'         => $draftBlocks['draftCheckBlock'],
            '[[draftWatchBlock]]'         => $draftBlocks['draftWatchBlock'],
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/Components/{$this->moduleName}EditForm.vue";
        
        return $this->writeFile($filePath, $content);
    }
}

