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

        if (!empty($createConfig['fields']) && is_array($createConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
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
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
                $splashData = $this->generateSplashData($frontendConfig);
                $hasSplash = $this->hasSplashData($frontendConfig);
            }
        }

        // Same fix as the web CreateFormGenerator (2026-08-03): inline_items
        // fields are appended here, not baked into $formSections, and
        // [[inlineItemsBlock]] is its own token placed AFTER the Main
        // Details FormCard but BEFORE FormSubmitActions in the stub -- see
        // that stub's own comment history for why (a footer-below-items bug
        // found live on the web side applies identically here).
        $inlineItems = $this->config['inline_items'] ?? [];
        if (!empty($inlineItems)) {
            if ($formFields !== '') {
                $formFields = rtrim($formFields) . ',';
            }
            foreach ($inlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
                $componentName = $this->inlineItemsWrapperComponentName($item['key']);
                $formFieldImports .= "\nimport {$componentName} from './{$componentName}.vue';";
            }
            $formFieldImports .= "\nimport { Card, CardContent } from '@/components/ui/card';";
        }

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]' => $formSections,
            '[[formFields]]' => $formFields,
            '[[formFieldImports]]' => $formFieldImports,
            '[[splashData]]' => $splashData,
            '[[hasSplash]]' => $hasSplash ? 'true' : 'false',
            '[[inlineItemsBlock]]' => $this->generateInlineItemsBlock($inlineItems),
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }
}
