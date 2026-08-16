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

        if (!empty($editConfig['fields']) && is_array($editConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editConfig['fields']);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
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
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
                $splashData = $this->generateSplashData($frontendConfig);
                $hasSplash = $this->hasSplashData($frontendConfig);
            }
        }

        // See MobileApp\Components\CreateFormGenerator's identical block for why.
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
            . "/Components/{$this->moduleName}EditForm.vue";

        return $this->writeFile($filePath, $content);
    }
}
