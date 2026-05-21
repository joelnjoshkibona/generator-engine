<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services\Sync;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class MobileSyncComposableGenerator extends BaseMobileBackendGenerator
{
    /**
     * Override loadStub to load from the mobile UX template path instead of
     * the mobile backend template path.
     */
    protected function loadStub(string $name): string
    {
        $path = PathManager::getMobileUxTemplatePath() . "/{$name}.stub";
        if (!file_exists($path)) {
            throw new \RuntimeException("Mobile UX stub not found: {$path}");
        }
        return file_get_contents($path);
    }

    public function generate(): bool
    {
        $content = $this->loadStub('use-sync-status');

        $routePrefix = Str::kebab(Str::plural($this->moduleName));

        $replacements = [
            '[[moduleName]]'  => $this->moduleName,
            '[[routePrefix]]' => $routePrefix,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = PathManager::getMobileAppBasePath()
            . '/resources/js/src/composables/use'
            . $this->moduleName
            . 'Sync.ts';

        return $this->writeFile($filePath, $content);
    }
}
