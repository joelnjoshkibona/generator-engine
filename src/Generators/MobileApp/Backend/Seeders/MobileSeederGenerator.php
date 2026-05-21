<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileSeederGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('seeders/seeder_data');

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
            '[[rows]]'        => '[]',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Seeders/{$this->moduleName}SeederData.json";

        return $this->writeFile($filePath, $content);
    }
}
