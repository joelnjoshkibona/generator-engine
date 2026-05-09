<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Service;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;

class ActivityServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $content = $this->getTemplateContent('Service/service_activity', 'backend');

        $content = $this->replacePlaceholders($content, [
            '[[ModuleName]]' => $this->moduleName,
            '[[namespace]]' => "{$this->getNamespace()}\Services",
        ]);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}ActivityListService.php";

        return $this->writeFile($filePath, $content);
    }
}
