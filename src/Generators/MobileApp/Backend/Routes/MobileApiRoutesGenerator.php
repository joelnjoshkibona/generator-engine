<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Illuminate\Support\Str;

class MobileApiRoutesGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('routes');

        $routePrefix = Str::kebab(Str::plural($this->moduleName));

        $replacements = [
            '[[moduleGroup]]'  => $this->moduleGroup,
            '[[moduleName]]'   => $this->moduleName,
            '[[routePrefix]]'  => $routePrefix,
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Routes/api.php";

        return $this->writeFile($filePath, $content);
    }
}
