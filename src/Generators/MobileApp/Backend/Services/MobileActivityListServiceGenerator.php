<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileActivityListServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/activity_list_service');

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}ActivityListService.php";

        return $this->writeFile($filePath, $content);
    }
}
