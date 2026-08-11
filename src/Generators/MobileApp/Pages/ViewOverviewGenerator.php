<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates the DetailsOverviewPage for MOBILE_APP.
 * Receives data from parent DetailsLayout via props.
 */
class ViewOverviewGenerator extends BaseComponentGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/view/overview', 'mobile_app');

        // Generate overview sections from columns
        $sections = $this->generateOverviewSections();

        $content = $this->replacePlaceholders($content, [
            '[[sections]]' => $sections,
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}DetailsOverviewPage.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Generate overview sections from module columns
     */
    protected function generateOverviewSections(): string
    {
        $columns = $this->config['columns'] ?? [];
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        // overview.stub's <script setup> declares `defineProps<{ data: any }>()` --
        // the interpolated var must be the literal prop name "data", not a
        // module-name-derived identifier (there is no such variable in scope).
        $varName = 'data';

        // Use view.sections if defined, otherwise generate from columns
        if (isset($viewConfig['sections'])) {
            return $this->generateCustomSections($viewConfig['sections'], $varName);
        }

        $sections = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            $label = $this->generateFieldLabel($name);

            // Skip system fields (handled separately in template)
            if (in_array($name, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'])) {
                continue;
            }

            $sections[] = "\t\t\t\t\t<div class=\"flex flex-col space-y-1\">\n"
                . "\t\t\t\t\t\t<span class=\"text-xs text-muted-foreground font-medium\">{$label}</span>\n"
                . "\t\t\t\t\t\t<p class=\"text-sm font-medium\">{{ {$varName}?.{$name} || 'Not specified' }}</p>\n"
                . "\t\t\t\t\t</div>";
        }

        return implode("\n", $sections);
    }

    /**
     * Generate sections from custom view config
     */
    protected function generateCustomSections(array $sections, string $varName): string
    {
        $output = [];
        foreach ($sections as $section) {
            $fields = $section['fields'] ?? [];
            foreach ($fields as $field) {
                $name = $field['field'] ?? $field['name'] ?? '';
                $label = $field['label'] ?? $this->generateFieldLabel($name);

                $output[] = "\t\t\t\t\t<div class=\"flex flex-col space-y-1\">\n"
                    . "\t\t\t\t\t\t<span class=\"text-xs text-muted-foreground font-medium\">{$label}</span>\n"
                    . "\t\t\t\t\t\t<p class=\"text-sm font-medium\">{{ {$varName}?.{$name} || 'Not specified' }}</p>\n"
                    . "\t\t\t\t\t</div>";
            }
        }

        return implode("\n", $output);
    }
}
