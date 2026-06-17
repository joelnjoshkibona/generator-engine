<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileDeleteServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/delete');

        $replacements = [
            '[[moduleGroup]]'  => $this->moduleGroup,
            '[[moduleName]]'   => $this->moduleName,
            '[[beforeDelete]]' => '',
            '[[afterDelete]]'  => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}DeleteService.php";

        return $this->writeFile($filePath, $content);
    }
}
