<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ViewLayoutGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/view/details_layout', 'frontend');
        
        // Process frontend configuration for view feature (new payload path)
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        
        // Generate header badges. details_layout.stub's fetched record is
        // always named `record` (see its `const record = ref<any>(null)`),
        // never `data` — must be passed explicitly since
        // generateHeaderBadges() defaults to 'data' for other, hypothetical
        // callers.
        $headerBadges = $this->generateHeaderBadges($this->config, 'record');
        
        // Generate header actions
        $headerActions = $this->generateHeaderActions($this->config);
        
        // Generate tab navigation
        $tabs = $this->generateTabNavigation($this->config);
        
        // Get primary display field
        $primaryField = $viewConfig['titleData'] ?? 'name';
        $secondaryField = $viewConfig['subtitleData'] ?? 'id';
        
        // Get ID parameter
        $idParam = $viewConfig['idParam'] ?? 'uuid';
        
        // Check if Badge import is needed
        $badgeImport = $this->generateBadgeImport($this->config);
        
        // Generate custom feature imports and modal states
        $customFeatureImports = $this->generateCustomFeatureImports($this->config);
        $customFeatureModalStates = $this->generateCustomFeatureModalStates($this->config);
        
        $content = $this->replacePlaceholders($content, [
            '[[statusBadge]]' => $headerBadges,
            '[[headerActions]]' => $headerActions,
            '[[tabs]]' => $tabs,
            '[[metricsConfigs]]' => $this->generateMetricsConfigs($this->config),
            '[[primaryDisplayField]]' => $primaryField,
            '[[secondaryDisplayField]]' => $secondaryField,
            '[[idParam]]' => $idParam,
            '[[badgeImport]]' => $badgeImport,
            '[[customFeatureImports]]' => $customFeatureImports,
            '[[customFeatureModalStates]]' => $customFeatureModalStates,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}DetailsLayout.vue";

        return $this->writeFile($filePath, $content);
    }

    protected function generateMetricsConfigs(array $config): string
    {
        // Support both 'header_metrics' (frontend format) and 'header.metrics' (legacy nested format)
        $metrics = $config['features']['frontend']['view']['header_metrics'] 
            ?? $config['features']['frontend']['view']['header']['metrics'] 
            ?? [];
        $configs = [];
        foreach ($metrics as $metric) {
            // Support both 'title' (frontend format) and 'label' (legacy format)
            $label = $metric['title'] ?? $metric['label'] ?? 'Metric';
            // Support 'field', 'dataPath', 'data' (API format), or 'key' as the property path
            $dataPath = $metric['field'] ?? $metric['dataPath'] ?? $metric['data'] ?? $metric['key'] ?? '';
            $icon = $metric['icon'] ?? 'InfoIcon';
            // Support color from metric config, default to blue
            $metricColor = $metric['color'] ?? 'blue';
            $color = "text-{$metricColor}-400";

            $configs[] = "	{
		label: \"{$label}\",
		value: record?.{$dataPath} || 'N/A',
		icon: \"{$icon}\",
		color: \"{$color}\",
	}";
        }
        return implode(",\n", $configs);
    }
}
