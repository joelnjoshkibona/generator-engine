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
        $fieldsForSubmit = [];

        // Draft autosave is on by default -- opt out via
        // features.frontend.create.drafts: false in module.json.
        $hasDrafts = ($createConfig['drafts'] ?? true) !== false;

        $footer = $this->generateFormFooter('create', $hasDrafts);

        // $footer is emitted as its own [[formFooter]] token (see below), placed
        // AFTER [[inlineItemsBlock]] in the stub -- NOT baked into
        // generateFormSection()/generateFormSections()'s own returned HTML.
        // Baking it in put the Save Draft/Create buttons inside the "Main
        // Details" card itself, so the Items card (inline_items) — spliced in
        // as a later sibling — rendered visually BELOW the submit buttons.
        // Confirmed live 2026-08-16 on a real Expenses create page. Passing ''
        // here (rather than $footer) is safe for the modal-embedded case too:
        // <form> itself is already `flex flex-col flex-1 min-h-0` when
        // `modal===true`, so the footer landing as a later flex-column sibling
        // (instead of nested inside the fields card) still pins correctly at
        // the bottom — and no module ever splices inline_items into a
        // modal-rendered form (CustomFeatureModalComponentGenerator never
        // references it), so this ordering only ever visibly changes anything
        // on the real page form.
        if (!empty($createConfig['fields']) && is_array($createConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $fieldsForSubmit = $mappedFields;
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'create');
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
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'create');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'create');

        // Splash plumbing is opt-in: only emitted when $config['constants'] is non-empty.
        $hasSplash = !empty($this->config['constants']);
        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $this->buildSplashBlocks('create', $hasSplash);
        $draftBlocks = $this->buildDraftBlocks('create', $hasDrafts);

        // Inline items — append form fields and imports, then generate the template block
        //
        // Bug (fixed 2026-08-03): this used to unconditionally import the
        // shared InlineItemsComponent + InlineItemField type directly into
        // the generated form -- stale since generateInlineItemsBlock()
        // (v2.23.0) started emitting a hand-edit-protected wrapper
        // component per item and rendering <{componentName}> instead of
        // <InlineItemsComponent> directly. The form no longer references
        // either import; it needs the wrapper's own sibling import
        // instead, one per item (mirrors generateFormFieldImports()'s
        // per-field inline-items case, which already got this fix).
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
            '[[draftContextProp]]'        => $draftBlocks['draftContextProp'],
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }
}

