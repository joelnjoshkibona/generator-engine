<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates custom tab pages for MOBILE_APP delegations.
 * One page per delegation with uiType = 'tab' or 'tab-action'.
 */
class CustomFeatureTabPageGenerator extends BaseGenerator
{
	protected function getModulePath(): string
	{
		return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
	}

	public function generate(): bool
	{
		$delegations = $this->config['delegations'] ?? [];
		$hasGenerated = false;

		foreach ($delegations as $featureKey => $delegation) {
			$uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? '');

			// Only generate for tab-type delegations
			if ($uiType !== 'tab' && $uiType !== 'tab-action') {
				continue;
			}

			try {
				$hasGenerated |= $this->generateCustomTabPage($featureKey, $delegation);
			} catch (\Throwable $e) {
				\Log::error("Error generating custom tab page for {$featureKey}: " . $e->getMessage());
				return false;
			}
		}

		// Return true if no delegations, or if at least one was generated
		return true;
	}

	/**
	 * Generate a single custom tab page for one delegation.
	 */
	protected function generateCustomTabPage(string $featureKey, array $delegation): bool
	{
		// Resolve all placeholder values
		$featureName = Str::studly($delegation['name'] ?? $featureKey);
		$featureNameCamel = Str::camel($featureName);
		$featureRoute = Str::kebab($featureName);
		$featureLabel = $delegation['label'] ?? $featureName;
		$featureIcon = $delegation['icon'] ?? 'LayersIcon';

		// Parent module context
		$moduleRoute = Str::kebab($this->moduleName);
		$viewConfig = $this->config['features']['frontend']['view'] ?? [];
		$idParam = $viewConfig['idParam'] ?? 'uuid';

		// Endpoints
		$listEndpoint = "/{$moduleRoute}/\${recordId.value}/{$featureRoute}/list";
		$createEndpoint = "/{$moduleRoute}/\${recordId.value}/{$featureRoute}/create";
		$deleteEndpointPattern = "/{$moduleRoute}/\${recordId.value}/{$featureRoute}/\${itemId}/delete";

		// Item display expressions (from delegation config or defaults)
		$itemTitleExpr = $delegation['titleField'] ?? 'item.name';
		$itemSubtitleExpr = $delegation['subtitleField'] ?? "''";

		// Load stub
		$content = $this->getTemplateContent('features/view/custom_tab', 'mobile_app');

		// Replace placeholders
		$content = $this->replacePlaceholders($content, [
			'[[ModuleName]]' => $this->moduleName,
			'[[moduleRoute]]' => $moduleRoute,
			'[[PermissionBaseName]]' => $this->moduleName,
			'[[FeatureName]]' => $featureName,
			'[[featureName]]' => $featureNameCamel,
			'[[featureRoute]]' => $featureRoute,
			'[[featureLabel]]' => $featureLabel,
			'[[featureIcon]]' => $featureIcon,
			'[[idParam]]' => $idParam,
			'[[listEndpoint]]' => $listEndpoint,
			'[[createEndpoint]]' => $createEndpoint,
			'[[deleteEndpointPattern]]' => $deleteEndpointPattern,
			'[[itemTitleExpr]]' => $itemTitleExpr,
			'[[itemSubtitleExpr]]' => $itemSubtitleExpr,
		]);

		// Write file
		$filePath = $this->getModulePath() . "/{$this->moduleName}Details{$featureName}Page.vue";

		return $this->writeFile($filePath, $content);
	}
}
