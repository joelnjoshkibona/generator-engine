<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator as FrontendCreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates the CreateForm component for MOBILE_APP.
 * Mirrors the frontend CreateFormGenerator exactly, but uses mobile_app template and path.
 */
class CreateFormGenerator extends FrontendCreateFormGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    /**
     * Override -- the inherited BaseComponentGenerator::resolveInlineCreateModule()
     * checks file_exists() against getFrontendModulesPath() (WEB) unconditionally,
     * AND trusts an explicit `create_form_module` override with no existence
     * check at all ("a developer's explicit instruction is trusted, not
     * re-checked" -- correct for WEB, where the developer who set it was
     * presumably looking at the web app). Neither assumption holds for
     * mobile: a related module's mobile generation is independent of
     * whether it exists on web, and an explicit override authored against
     * web has no bearing on whether that module was ever mobile-generated.
     * Confirmed live: Customers' `default_price_list_id` field explicitly
     * sets `create_form_module: 'PriceLists'` (a real, working override on
     * web) -- PriceLists was never mobile-enabled, so the inherited
     * unconditional trust produced a static import to a file that doesn't
     * exist on MOBILE_APP's disk at all, breaking the whole project's
     * mobile build. Mobile checks existence in BOTH branches; only the
     * inline_create=false opt-out and the self-reference guard are trusted
     * without a filesystem check, same as web. Paired with the
     * resolveCreateFormImportSegment() override below (fixes the import
     * PATH shape once a module passes this gate) -- see
     * PathManager::resolveMobileAppImportSegment()'s own docblock too.
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
     * Override -- see resolveInlineCreateModule() above. Without this, the
     * gate correctly rejects web-only modules, but a module that DOES pass
     * (a real mobile-generated related module) would still get a WEB-shaped
     * nested import path here, since generateFormFieldImports() calls this
     * seam independently rather than reusing resolveInlineCreateModule()'s
     * own segment.
     */
    protected function resolveCreateFormImportSegment(string $moduleName): string
    {
        return PathManager::resolveMobileAppImportSegment($moduleName);
    }

    /**
     * Override -- the inherited BaseComponentGenerator::writeInlineItemsWrapperComponent()
     * hardcodes PathManager::getFrontendModulePath(), not the polymorphic
     * getModulePath() this class overrides above. Left unoverridden, an
     * inline_items-configured mobile module's wrapper component would be
     * written into the WEB frontend's module folder instead of MOBILE_APP's
     * own -- found by reading the inherited method's body before wiring it
     * in, not by a live failure.
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
     * Override -- the inherited BaseComponentGenerator::generateDefaultFormSection()
     * wraps every field group in a bordered box with a "Main Details" title
     * bar (`rounded-md border overflow-hidden` + a `px-4 py-3 border-b`
     * header row). Combined with the mobile Create/Edit form.stubs' own
     * <FormCard> wrapper (removed 2026-08-18), this doubled up as two nested
     * boxes on mobile. Removing FormCard alone left this inner
     * border+title box still rendering -- confirmed live, "Create Expenses"
     * still showed a boxed "Main Details" section after the FormCard fix.
     * Mobile now wants genuinely flat forms (fields directly on the page
     * background, no card, no border, no section title) -- keep only the
     * responsive grid layout, drop the box chrome entirely. Web is
     * untouched (still gets the bordered/titled box via the inherited
     * method) since this hasn't been asked for there.
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
     * Override -- see generateDefaultFormSection() above for why. This
     * titled variant collapses to the same flat shape -- the `$section`
     * title is intentionally unused now that mobile forms carry no section
     * chrome to put it in.
     */
    protected function generateFormSection(array $section, array $fields, string $footerHtml = ''): string
    {
        return $this->generateDefaultFormSection($fields, $footerHtml);
    }

    /**
     * Override -- mobile renders every 'file-input' field via the native
     * camera/gallery picker (PhotoCaptureField.vue, backed by our own
     * SmartCamera plugin) instead of web's plain browser file input --
     * there's no filesystem/document browser on a NativePHP WebView the way
     * there is on desktop, and the user explicitly wants the Agrovet-style
     * capture flow for mobile specifically, not a second field_type. Every
     * OTHER field type is untouched (delegates straight to the shared
     * BaseComponentGenerator implementation).
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
     * Override -- the inherited generateFormFieldImports() imports
     * FileInputField.vue (the web component) for any 'file-input' field.
     * Mobile's generateField() override above renders PhotoCaptureField.vue
     * instead, so swap the import to match -- otherwise the generated form
     * would carry an unused FileInputField import.
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
        $frontendConfig = $this->config['features']['frontend']['create'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/create/form', 'mobile_app');

        // Prefer new payload fields for create
        $createConfig = $this->config['features']['frontend']['create'] ?? [];
        $frontendConfig = $this->config;

        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $splashData = '';
        $hasSplash = false;
        $fieldsForSubmit = [];

        // Wizard mode: resolves mobile_app.create.wizard first (an explicit
        // mobile-only override), falling back to INHERIT whatever web
        // already has configured via features.frontend.create.wizard -- a
        // wizard configured for web now works on mobile automatically,
        // rather than being silently ignored as it always was before (mobile
        // never checked `wizard` config at all, unlike web's own
        // Frontend\Components\CreateFormGenerator). Same
        // generateWizardSteps()/generateWizardStateBlock() machinery mobile
        // Actions already use (see ActionModalGenerator).
        $wizardConfig = $this->config['features']['mobile_app']['create']['wizard']
            ?? $createConfig['wizard']
            ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        $confirmStepConfig = $createConfig['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $claimedInlineItemKeys = [];
        $hasFkFieldLabels = false;
        $wizardStateBlock = '';

        if (!empty($createConfig['fields']) && is_array($createConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createConfig['fields']);
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
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'create');
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

        // Conditional switching: only forms with a file-input field ever get
        // the FormData/sendFormDataRequest treatment -- everything else is
        // generated exactly as before (see generateRequestImportLine() /
        // generateFileRefsBlock() / generateSubmitCall()). Mirrors the
        // identical block in Frontend\Components\CreateFormGenerator --
        // mobile never had this wiring at all until now, which meant a
        // file-input field's picked value was silently dropped (rendered
        // in the template, but never declared as a ref or included in the
        // submitted payload). Confirmed live on Expenses' Receipt field.
        $hasFileFields = $this->hasFileInputField($fieldsForSubmit);
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'create');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'create');

        // Drafts -- server-backed autosave, same Core/Drafts substrate and
        // buildDraftBlocks()/useDraft.ts web already uses, ported onto
        // MOBILE_APP's own chassis (useDraft.ts, DraftListPanel.vue). Opt out
        // via features.frontend.create.drafts: false, same key web reads.
        // $hasDrafts (not a hardcoded false) also matters to
        // generateWizardStateBlock() below: its goNext() calls
        // scheduleDraftSave() mid-wizard when drafts are on.
        $hasDrafts = ($createConfig['drafts'] ?? true) !== false;
        $draftBlocks = $this->buildDraftBlocks('create', $hasDrafts);

        $wizardStateBlock = $isWizard
            ? $this->generateWizardStateBlock($wizardConfig, $hasDrafts, $confirmStepConfig, $hasFkFieldLabels)
            : '';

        // Footer: FormSubmitActions is mobile's own shared submit/cancel/
        // save-draft component (no web equivalent -- web hand-writes its own
        // button row via generateFormFooter(), which also emits `$t()` i18n
        // calls mobile doesn't use anywhere, so it isn't reused verbatim
        // here). In wizard mode it's only shown on the LAST step -- every
        // earlier step instead gets Back/Next, mirroring
        // generateFormFooter()'s own $backButton/$nextButton/$submitVIf
        // logic and the identical pattern already proven working in
        // MobileApp\Components\Actions\ActionModalGenerator.
        $hasDraftsBool = $hasDrafts ? 'true' : 'false';
        if ($isWizard) {
            $submitDisabledExpr = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'false';
            $formFooter = "<div v-if=\"currentStep === wizardSteps.length - 1\">\n"
                . "\t\t\t<FormSubmitActions\n"
                . "\t\t\t\t:is-submitting=\"isSubmitting\"\n"
                . "\t\t\t\t:submit-disabled=\"{$submitDisabledExpr}\"\n"
                . "\t\t\t\tsubmit-label=\"Create\"\n"
                . "\t\t\t\tloading-label=\"Creating...\"\n"
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
                . "\t\t\tsubmit-label=\"Create\"\n"
                . "\t\t\tloading-label=\"Creating...\"\n"
                . "\t\t\t:show-save-draft=\"{$hasDraftsBool}\"\n"
                . "\t\t\t:is-saving-draft=\"isSavingDraft\"\n"
                . "\t\t\t@cancel=\"cancel\"\n"
                . "\t\t\t@save-draft=\"handleSaveDraftClick\"\n"
                . "\t\t/>";
        }

        // Same fix as the web CreateFormGenerator (2026-08-03): inline_items
        // fields are appended here, not baked into $formSections, and
        // [[inlineItemsBlock]] is its own token placed AFTER the Main
        // Details section but BEFORE FormSubmitActions in the stub -- see
        // that stub's own comment history for why (a footer-below-items bug
        // found live on the web side applies identically here).
        //
        // Wizard mode already rendered any inline_items claimed by a step
        // (inside [[formSections]] itself, via generateWizardSteps()) --
        // only UNCLAIMED items still go through this catch-all block, or a
        // step-claimed item would render twice once wizard mode was wired up
        // (same fix web's own CreateFormGenerator already carries).
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
            . "/Components/{$this->moduleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }
}
