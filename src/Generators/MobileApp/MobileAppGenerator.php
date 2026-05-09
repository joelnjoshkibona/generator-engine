<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp;

use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Base class for MobileApp generators.
 * Extends FrontendGenerator since MOBILE_APP uses the same Vue 3 + TS stack
 * and reuses the frontend config section, but outputs to MOBILE_APP paths
 * with mobile-specific templates (FormPageLayout, DetailsPageLayout, itemRoute, etc.).
 */
class MobileAppGenerator extends FrontendGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    protected function getStubPath(string $stubName, string $type = 'backend'): string
    {
        // Default to mobile_app templates for this generator
        if ($type === 'backend' || $type === 'frontend') {
            return parent::getStubPath($stubName, $type);
        }
        return PathManager::getMobileAppTemplatePath() . "/{$stubName}.stub";
    }

    public function generate(): bool
    {
        // This class is the base - individual generators handle generation
        return true;
    }
}
