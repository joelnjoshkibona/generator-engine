<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileControllerGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('controller');

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";

        return $this->writeFile($filePath, $content);
    }
}
