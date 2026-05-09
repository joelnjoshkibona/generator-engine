<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Illuminate\Support\Str;

class ActionComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return false;
    }

    public function generateAction(string $actionKey, array $action): bool
    {
        if (empty($action['hasUI'])) {
            return false;
        }

        $uiType = $action['uiType'] ?? 'modal';
        $template = $uiType === 'page' ? 'features/action/page' : 'features/action/modal';

        $content = $this->getTemplateContent($template, 'frontend');

        $actionName = Str::studly($action['name'] ?? $actionKey);
        $actionLabel = $action['label'] ?? $actionName;
        $actionRoute = Str::kebab($action['name'] ?? $actionKey);
        $moduleRoute = Str::kebab($this->moduleName);

        $content = $this->replacePlaceholders($content, [
            '[[ActionName]]' => $actionName,
            '[[ActionLabel]]' => $actionLabel,
            '[[actionRoute]]' => "/{$moduleRoute}/{$actionRoute}",
        ]);

        $fileName = $uiType === 'page'
            ? "{$this->moduleName}{$actionName}Page.vue"
            : "{$this->moduleName}{$actionName}Modal.vue";

        $filePath = "{$this->modulePath}/Components/{$fileName}";

        return $this->writeFile($filePath, $content);
    }
}
