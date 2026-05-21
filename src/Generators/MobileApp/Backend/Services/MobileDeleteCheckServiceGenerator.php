<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileDeleteCheckServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/delete_check_service');

        $replacements = [
            '[[moduleGroup]]'     => $this->moduleGroup,
            '[[moduleName]]'      => $this->moduleName,
            '[[dependentChecks]]' => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}DeleteCheckService.php";

        return $this->writeFile($filePath, $content);
    }
}
