<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates action modal components for MOBILE_APP.
 * Mirrors ActionComponentGenerator but uses mobile_app templates and paths.
 */
class ActionModalGenerator extends ActionComponentGenerator
{
    public function generateAction(string $actionKey, array $action): bool
    {
        if (empty($action['hasUI'])) {
            return false;
        }

        $uiType     = $action['uiType'] ?? 'modal';
        $template   = $uiType === 'page' ? 'features/action/page' : 'features/action/modal';
        $content    = $this->getTemplateContent($template, 'mobile_app');

        $actionName  = Str::studly($action['name'] ?? $actionKey);
        $actionLabel = $action['label'] ?? $actionName;
        $actionRoute = Str::kebab($action['name'] ?? $actionKey);
        $moduleRoute = Str::kebab($this->moduleName);

        $content = $this->replacePlaceholders($content, [
            '[[ModuleName]]'  => $this->moduleName,
            '[[ActionName]]'  => $actionName,
            '[[ActionLabel]]' => $actionLabel,
            '[[actionRoute]]' => "/{$moduleRoute}/{$actionRoute}",
        ]);

        $fileName = $uiType === 'page'
            ? "{$this->moduleName}{$actionName}Page.vue"
            : "{$this->moduleName}{$actionName}Modal.vue";

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$fileName}";

        return $this->writeFile($filePath, $content);
    }
}
