<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services\Sync;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class MobileSyncComposableGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/sync-status-composable');

        $routePrefix = Str::kebab(Str::plural($this->moduleName));

        $replacements = [
            '[[moduleName]]'  => $this->moduleName,
            '[[routePrefix]]' => $routePrefix,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . '/composables/use'
            . $this->moduleName
            . 'Sync.ts';

        return $this->writeFile($filePath, $content);
    }
}
