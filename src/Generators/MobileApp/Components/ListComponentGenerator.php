<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ListComponentGenerator as FrontendListComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\MobileAppConfigResolver;

/**
 * Generates the List component for MOBILE_APP.
 * Key difference from FRONTEND: uses itemRoute prop for clickable rows
 * instead of an actions column with explicit view buttons.
 */
class ListComponentGenerator extends FrontendListComponentGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['list'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/list/component', 'mobile_app');

        // Get card configuration using resolver
        $cardConfig = MobileAppConfigResolver::resolveListCard($this->config);

        // Generate columns (no actions column for mobile - uses itemRoute instead)
        $columns = $this->generateColumnsFromListFields(
            $frontendConfig['fields'] ?? []
        );

        // Generate stats configuration
        $stats = $this->generateStatsConfigs($this->config['features']['frontend']['list']['stats'] ?? []);

        // No additional imports needed for list component
        $additionalImports = '';

        // Compute card placeholders
        $cardIcon = $cardConfig['icon'];
        $cardTitleExpr = 'item.' . $cardConfig['titleField'];

        // Compute subtitle block
        $cardSubtitleBlock = $this->generateSubtitleBlock($cardConfig['subtitleFields']);

        // Compute body block
        $cardBodyBlock = $this->generateBodyBlock($cardConfig['bodyFields']);

        // Compute footer-left block
        $cardFooterLeftBlock = $this->generateFooterLeftBlock($cardConfig['footerBadge']);

        $content = $this->replacePlaceholders($content, [
            '[[cardIcon]]' => $cardIcon,
            '[[cardTitleExpr]]' => $cardTitleExpr,
            '[[cardSubtitleBlock]]' => $cardSubtitleBlock,
            '[[cardBodyBlock]]' => $cardBodyBlock,
            '[[cardFooterLeftBlock]]' => $cardFooterLeftBlock,
            '[[columns]]' => $columns,
            '[[statsConfigs]]' => $stats,
            '[[additionalImports]]' => $additionalImports,
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}List.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Generate subtitle template block from subtitle fields.
     *
     * @param array $subtitleFields Array of field keys
     * @return string Vue template block (or empty string if no fields)
     */
    private function generateSubtitleBlock(array $subtitleFields): string
    {
        if (empty($subtitleFields)) {
            return '';
        }

        if (count($subtitleFields) === 1) {
            $field = $subtitleFields[0];
            return "\t\t\t\t<template #subtitle>\n\t\t\t\t\t<span>{{ item.{$field} || 'N/A' }}</span>\n\t\t\t\t</template>";
        }

        // Multiple subtitle fields: join with ' · ' bullets, each guarded v-if
        $subtitleParts = [];
        foreach ($subtitleFields as $field) {
            $subtitleParts[] = "\t\t\t\t\t<span v-if=\"item.{$field}\">{{ item.{$field} }}</span>";
        }

        $content = "\t\t\t\t<template #subtitle>\n";
        $content .= implode("\n\t\t\t\t\t<span> · </span>\n", $subtitleParts);
        $content .= "\n\t\t\t\t</template>";

        return $content;
    }

    /**
     * Generate body template block from body fields.
     *
     * @param array $bodyFields Array of field keys
     * @return string Vue template block (or empty string if no fields)
     */
    private function generateBodyBlock(array $bodyFields): string
    {
        if (empty($bodyFields)) {
            return '';
        }

        $bodyParts = [];
        foreach ($bodyFields as $field) {
            $bodyParts[] = "\t\t\t\t<template v-if=\"item.{$field}\" #body>\n\t\t\t\t\t<div class=\"text-xs text-muted-foreground line-clamp-2\">{{ item.{$field} }}</div>\n\t\t\t\t</template>";
        }

        return implode("\n", $bodyParts);
    }

    /**
     * Generate footer-left template block from footer badge.
     *
     * @param string|null $footerBadge Badge text (or null)
     * @return string Vue template block (or empty string if no badge)
     */
    private function generateFooterLeftBlock(?string $footerBadge): string
    {
        if (empty($footerBadge)) {
            return '';
        }

        return "\t\t\t\t<template #footer-left>\n\t\t\t\t\t<span class=\"text-[11px] font-medium bg-muted text-muted-foreground px-2 py-0.5 rounded-md\">{$footerBadge}</span>\n\t\t\t\t</template>";
    }
}
