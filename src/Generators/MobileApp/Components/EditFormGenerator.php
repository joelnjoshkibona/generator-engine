<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator as FrontendEditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates the EditForm component for MOBILE_APP.
 * Mirrors the frontend EditFormGenerator exactly, but uses mobile_app template and path.
 */
class EditFormGenerator extends FrontendEditFormGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    /**
     * Override -- see the identical override + docblock in
     * MobileApp\Components\CreateFormGenerator for why (WEB file-existence
     * check unconditionally approving web-only-generated related modules,
     * AND unconditionally trusting an explicit `create_form_module`
     * override with no mobile existence check at all).
     */
    protected function resolveInlineCreateModule(array $field): ?string
    {
        if (($field['inline_create'] ?? true) === false) {
            return null;
        }

        $relatedModule = !empty($field['create_form_module'])
            ? $field['create_form_module']
            : ($field['relatedModule'] ?? '');

        if ($relatedModule === '' || $relatedModule === $this->moduleName) {
            return null;
        }

        $importSegment = $this->resolveCreateFormImportSegment($relatedModule);
        if ($importSegment === '') {
            return null;
        }

        $createFormPath = PathManager::getMobileAppModulesPath()
            . "/{$importSegment}/Components/{$relatedModule}CreateForm.vue";

        return file_exists($createFormPath) ? $relatedModule : null;
    }

    /**
     * Override -- see the identical override + docblock in
     * MobileApp\Components\CreateFormGenerator for why (WEB-shaped nested
     * import path even for a module that passes the mobile-aware gate above).
     */
    protected function resolveCreateFormImportSegment(string $moduleName): string
    {
        return PathManager::resolveMobileAppImportSegment($moduleName);
    }

    /**
     * Override -- see the identical override in MobileApp\Components\CreateFormGenerator
     * for why (inherited method hardcodes the web frontend's module path).
     */
    protected function writeInlineItemsWrapperComponent(string $key, string $fieldsJs): string
    {
        $componentName = $this->inlineItemsWrapperComponentName($key);

        $stub = $this->getTemplateContent('fields/inline-items-wrapper', 'frontend');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]' => $componentName,
            '[[ModuleName]]'    => $this->moduleName,
            '[[fieldKey]]'      => $key,
            '[[fields]]'        => $fieldsJs,
        ]);

        $path = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    /**
     * Override -- see the identical override + docblock in
     * MobileApp\Components\CreateFormGenerator for why (bordered/titled
     * "Main Details" box dropped in favor of a genuinely flat form on
     * mobile; web keeps the inherited boxed version, untouched).
     */
    protected function generateDefaultFormSection(array $fields, string $footerHtml = ''): string
    {
        $fieldsContent = $this->generateFieldsGrid($fields);
        $footer = !empty($footerHtml) ? "\n\t\t{$footerHtml}" : '';

        return "<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 px-4\">
{$fieldsContent}
		</div>{$footer}";
    }

    /**
     * Override -- see generateDefaultFormSection() above for why.
     */
    protected function generateFormSection(array $section, array $fields, string $footerHtml = ''): string
    {
        return $this->generateDefaultFormSection($fields, $footerHtml);
    }

    /**
     * Override -- see the identical override + docblock in
     * MobileApp\Components\CreateFormGenerator for why (native
     * camera/gallery picker instead of a plain browser file input on mobile).
     */
    protected function generateField(array $field, bool $trackFieldLabels = false): string
    {
        $fieldType = $field['field_type'] ?? $field['type'] ?? '';
        if ($fieldType !== 'file-input') {
            return parent::generateField($field, $trackFieldLabels);
        }

        $key = $field['key'] ?? $field['name'];
        $label = $field['label'] ?? $field['title'] ?? $this->generateFieldLabel($key);
        $required = isset($field['required']) && $field['required'] ? 'true' : 'false';
        $disabled = isset($field['disabled']) && $field['disabled'] ? 'true' : 'false';
        $isHidden = isset($field['hidden']) && $field['hidden'];
        $hiddenCondition = $isHidden ? 'false' : "!props.hiddens?.['{$key}']";

        $fieldTemplate = $this->getTemplateContent('fields/file-input', 'mobile_app');

        return $this->replacePlaceholders($fieldTemplate, [
            '[[fieldKey]]' => $key,
            '[[fieldLabel]]' => $label,
            '[[fieldRequired]]' => $required,
            '[[fieldDisabled]]' => $disabled,
            '[[fieldHiddenCondition]]' => $hiddenCondition,
            '[[fieldModelRef]]' => $this->fileRefName($key),
            '[[tabs]]' => "\t\t\t\t",
        ]);
    }

    /**
     * Override -- see the identical override + docblock in
     * MobileApp\Components\CreateFormGenerator for why.
     */
    protected function generateFormFieldImports(array $config): string
    {
        $imports = parent::generateFormFieldImports($config);

        $fields = $this->collectAllFieldsFromConfig($config);
        foreach ($fields as $field) {
            if ($this->resolveFieldType($field) === 'file-input') {
                return str_replace(
                    "import FileInputField from '@/components/form-fields/FileInputField.vue';",
                    "import PhotoCaptureField from '@/components/form-fields/PhotoCaptureField.vue';",
                    $imports
                );
            }
        }

        return $imports;
    }

    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['edit'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/edit/form', 'mobile_app');

        // Prefer new payload fields for edit
        $editConfig = $this->config['features']['frontend']['edit'] ?? [];
        $frontendConfig = $this->config ?? [];

        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $splashData = '';
        $hasSplash = false;
        $fieldsForSubmit = [];

        // Wizard mode: see MobileApp\Components\CreateFormGenerator's
        // identical block for the full design/inheritance-chain rationale.
        $wizardConfig = $this->config['features']['mobile_app']['edit']['wizard']
            ?? $editConfig['wizard']
            ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        $confirmStepConfig = $editConfig['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $claimedInlineItemKeys = [];
        $hasFkFieldLabels = false;
        $wizardStateBlock = '';

        if (!empty($editConfig['fields']) && is_array($editConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editConfig['fields']);
            if ($isWizard) {
                [$formSections, $claimedInlineItemKeys, $hasFkFieldLabels] = $this->generateWizardSteps(
                    $wizardConfig,
                    $mappedFields,
                    $this->config['inline_items'] ?? [],
                    $confirmStepConfig
                );
            } else {
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            }
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            if ($isWizard) {
                $formFieldImports .= "\nimport { Stepper } from '@/components/ui/stepper';";
            }
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
            $fieldsForSubmit = $mappedFields;
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'edit');
            if (!empty($fallbackFields)) {
                $mappedFields = $this->mapNewFormFieldsToLegacy($fallbackFields);
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
                $formFields = $this->generateFormFields(['fields' => $mappedFields]);
                $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
                $splashData = $this->generateSplashData(['fields' => $mappedFields]);
                $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
                $fieldsForSubmit = $mappedFields;
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
                $splashData = $this->generateSplashData($frontendConfig);
                $hasSplash = $this->hasSplashData($frontendConfig);
                $fieldsForSubmit = $this->collectAllFieldsFromConfig($frontendConfig);
            }
        }

        // See MobileApp\Components\CreateFormGenerator's identical block for why.
        $hasFileFields = $this->hasFileInputField($fieldsForSubmit);
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'edit');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'edit');

        // Drafts -- see MobileApp\Components\CreateFormGenerator's identical
        // block for the full rationale. Edit's draft shape is the single-slot
        // restore/discard banner (buildEditDraftBlocks()), not the
        // multi-draft picker create forms get -- an edit form's draft is
        // already uniquely keyed by the real record's own uuid.
        $hasDrafts = ($editConfig['drafts'] ?? true) !== false;
        $draftBlocks = $this->buildDraftBlocks('edit', $hasDrafts);

        $wizardStateBlock = $isWizard
            ? $this->generateWizardStateBlock($wizardConfig, $hasDrafts, $confirmStepConfig, $hasFkFieldLabels)
            : '';

        // Footer: see MobileApp\Components\CreateFormGenerator's identical
        // block for the full rationale.
        $hasDraftsBool = $hasDrafts ? 'true' : 'false';
        if ($isWizard) {
            $submitDisabledExpr = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'false';
            $formFooter = "<div v-if=\"currentStep === wizardSteps.length - 1\">\n"
                . "\t\t\t<FormSubmitActions\n"
                . "\t\t\t\t:is-submitting=\"isSubmitting\"\n"
                . "\t\t\t\t:submit-disabled=\"{$submitDisabledExpr}\"\n"
                . "\t\t\t\tsubmit-label=\"Save\"\n"
                . "\t\t\t\tloading-label=\"Saving...\"\n"
                . "\t\t\t\t:show-save-draft=\"{$hasDraftsBool}\"\n"
                . "\t\t\t\t:is-saving-draft=\"isSavingDraft\"\n"
                . "\t\t\t\t@cancel=\"cancel\"\n"
                . "\t\t\t\t@save-draft=\"handleSaveDraftClick\"\n"
                . "\t\t\t/>\n"
                . "\t\t</div>\n"
                . "\t\t<div v-else class=\"flex items-center justify-center gap-4 pt-2\">\n"
                . "\t\t\t<Button v-if=\"currentStep > 0\" type=\"button\" variant=\"outline\" size=\"sm\" :disabled=\"isSubmitting\" @click=\"goBack\">Back</Button>\n"
                . "\t\t\t<Button type=\"button\" size=\"sm\" :disabled=\"isSubmitting\" @click=\"goNext\">Next</Button>\n"
                . "\t\t</div>";
        } else {
            $formFooter = "<FormSubmitActions\n"
                . "\t\t\t:is-submitting=\"isSubmitting\"\n"
                . "\t\t\tsubmit-label=\"Save\"\n"
                . "\t\t\tloading-label=\"Saving...\"\n"
                . "\t\t\t:show-save-draft=\"{$hasDraftsBool}\"\n"
                . "\t\t\t:is-saving-draft=\"isSavingDraft\"\n"
                . "\t\t\t@cancel=\"cancel\"\n"
                . "\t\t\t@save-draft=\"handleSaveDraftClick\"\n"
                . "\t\t/>";
        }

        // See MobileApp\Components\CreateFormGenerator's identical block for
        // why unclaimed-only (dedup against wizard-step-claimed items).
        $inlineItems = $this->config['inline_items'] ?? [];
        $unclaimedInlineItems = $isWizard
            ? array_values(array_filter($inlineItems, fn ($item) => !in_array($item['key'], $claimedInlineItemKeys, true)))
            : $inlineItems;
        if (!empty($unclaimedInlineItems)) {
            if ($formFields !== '') {
                $formFields = rtrim($formFields) . ',';
            }
            foreach ($unclaimedInlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
                $componentName = $this->inlineItemsWrapperComponentName($item['key']);
                $formFieldImports .= "\nimport {$componentName} from './{$componentName}.vue';";
            }
        }

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]' => $formSections,
            '[[formFields]]' => $formFields,
            '[[formFieldImports]]' => $formFieldImports,
            '[[wizardStateBlock]]' => $wizardStateBlock,
            '[[formFooter]]' => $formFooter,
            '[[splashData]]' => $splashData,
            '[[hasSplash]]' => $hasSplash ? 'true' : 'false',
            '[[inlineItemsBlock]]' => $this->generateInlineItemsBlock($unclaimedInlineItems),
            '[[requestImportLine]]' => $requestImportLine,
            '[[fileRefsBlock]]' => $fileRefsBlock,
            '[[submitCall]]' => $submitCall,
            '[[draftBannerBlock]]' => $draftBlocks['draftBannerBlock'],
            '[[draftImports]]' => $draftBlocks['draftImports'],
            '[[draftWatchImport]]' => $draftBlocks['draftWatchImport'],
            '[[draftSetupBlock]]' => $draftBlocks['draftSetupBlock'],
            '[[discardDraftOnSuccess]]' => $draftBlocks['discardDraftOnSuccess'],
            '[[draftCheckBlock]]' => $draftBlocks['draftCheckBlock'],
            '[[draftWatchBlock]]' => $draftBlocks['draftWatchBlock'],
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}EditForm.vue";

        return $this->writeFile($filePath, $content);
    }
}
