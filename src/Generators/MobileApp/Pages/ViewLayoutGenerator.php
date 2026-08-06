<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates the DetailsLayout page for MOBILE_APP.
 * Uses DetailsPageLayout with TabItem[] grid instead of FRONTEND's manual tab construction.
 */
class ViewLayoutGenerator extends BaseComponentGenerator
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

        $content = $this->getTemplateContent('features/view/details_layout', 'mobile_app');

        // Process frontend configuration for view feature
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];

        // Generate header badges. mobile's details_layout.stub also fetches
        // its record into `record` (const record = ref<any>({})), never
        // `data` — see the same fix in the frontend ViewLayoutGenerator.
        $headerBadges = $this->generateHeaderBadges($this->config, 'record');

        // Generate header actions
        $headerActions = $this->generateHeaderActions($this->config);

        // Generate tab navigation (mobile format: TabItem[] array)
        $tabs = $this->generateMobileTabNavigation($this->config);

        // Get primary display field
        $primaryField = $viewConfig['titleData'] ?? 'name';
        $secondaryField = $viewConfig['subtitleData'] ?? 'id';

        // Get ID parameter
        $idParam = $viewConfig['idParam'] ?? 'uuid';

        // Check if Badge import is needed
        $badgeImport = $this->generateBadgeImport($this->config);

        // Generate custom feature imports and modal states
        $customFeatureImports = $this->generateCustomFeatureImports($this->config);

        $content = $this->replacePlaceholders($content, [
            '[[headerBadges]]' => $headerBadges,
            '[[headerActions]]' => $headerActions,
            '[[tabs]]' => $tabs,
            '[[primaryDisplayField]]' => $primaryField,
            '[[secondaryDisplayField]]' => $secondaryField,
            '[[idParam]]' => $idParam,
            '[[badgeImport]]' => $badgeImport,
            '[[customFeatureImports]]' => $customFeatureImports,
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}DetailsLayout.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Generate mobile tab navigation as TabItem[] computed array
     * MOBILE_APP pattern: { id, label, icon, to } objects for DetailsPageLayout 2-column grid
     */
    protected function generateMobileTabNavigation(array $config): string
    {
        $viewConfig = $config['features']['frontend']['view'] ?? [];
        $idParam = $viewConfig['idParam'] ?? 'uuid';
        $moduleRoute = \Illuminate\Support\Str::kebab($this->moduleName);
        $moduleLower = strtolower($this->moduleName);

        $tabs = [];

        // Overview tab (always present)
        $tabs[] = "\t{ id: 'overview', label: 'Overview', icon: icons.BookOpenIcon, to: `/{$moduleRoute}/\${recordId.value}/overview` }";

        // Delegation tabs (custom features with uiType === 'tab' or 'tab-action')
        $customFeatures = $config['delegations'] ?? [];
        foreach ($customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                $featureName = \Illuminate\Support\Str::kebab($customFeature['name'] ?? $featureKey);
                $label = $customFeature['label'] ?? \Illuminate\Support\Str::title(str_replace('-', ' ', $featureName));
                $icon = $customFeature['icon'] ?? 'LayoutListIcon';

                $tabs[] = "\t{ id: '{$featureName}', label: '{$label}', icon: icons.{$icon}, to: `/{$moduleRoute}/\${recordId.value}/{$featureName}` }";
            }
        }

        // History tab (always present)
        $tabs[] = "\t{ id: 'history', label: 'History', icon: icons.HistoryIcon, to: `/{$moduleRoute}/\${recordId.value}/history` }";

        return implode(",\n", $tabs);
    }
}
