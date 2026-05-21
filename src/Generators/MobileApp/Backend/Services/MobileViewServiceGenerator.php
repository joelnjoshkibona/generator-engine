<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileViewServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/view_service');

        $replacements = [
            '[[moduleGroup]]'            => $this->moduleGroup,
            '[[moduleName]]'             => $this->moduleName,
            '[[eagerLoadRelationships]]' => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}ViewService.php";

        return $this->writeFile($filePath, $content);
    }
}
