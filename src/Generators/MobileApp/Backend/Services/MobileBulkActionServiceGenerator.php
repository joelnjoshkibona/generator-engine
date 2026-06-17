<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileBulkActionServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/bulk-action');

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}BulkActionService.php";

        return $this->writeFile($filePath, $content);
    }
}
