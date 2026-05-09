<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use Illuminate\Support\Str;

class ActionServiceGenerator extends BaseServiceGenerator
{
    protected array $action;
    protected string $actionKey;

    public function __construct(
        string $moduleName,
        string $moduleGroup = 'Core',
        array $config = [],
        string $actionKey = '',
        array $action = []
    ) {
        parent::__construct($moduleName, $moduleGroup, $config);

        $this->actionKey = $actionKey;
        $this->action = $action;
    }

    public function generate(): bool
    {
        $content = $this->getTemplateContent('Features/action/service', 'backend');

        $actionName = Str::studly($this->action['name'] ?? $this->actionKey);

        // Override serviceName if provided, strip module prefix and 'Service' suffix
        $serviceNameRaw = $this->action['serviceName'] ?? $actionName;
        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }

        // Build URL param declarations — TWO forms:
        //   [[urlParams]]     → typed signature form  ", string $uuid, string $year"   (valid in function signatures)
        //   [[urlParamsArgs]] → call-site args form   ", $uuid, $year"                 (valid in function calls)
        // Pasting the typed form into a call produces `process($data, string $uuid)` which is a PHP fatal.
        $urlParams = $this->action['urlParams'] ?? [];
        $urlParamsStr = '';
        $urlParamsArgs = '';
        if (!empty($urlParams)) {
            $typedParts = array_map(fn($p) => "string \${$p}", $urlParams);
            $urlParamsStr  = ', ' . implode(', ', $typedParts);
            $callParts = array_map(fn($p) => "\${$p}", $urlParams);
            $urlParamsArgs = ', ' . implode(', ', $callParts);
        }

        $content = $this->replacePlaceholders($content, [
            '[[ActionName]]'     => $serviceNameRaw,
            '[[urlParams]]'      => $urlParamsStr,
            '[[urlParamsArgs]]'  => $urlParamsArgs,
        ]);

        $fullServiceName = $this->moduleName . $serviceNameRaw . 'Service';
        $filePath = "{$this->modulePath}/Services/{$fullServiceName}.php";

        return $this->writeFile($filePath, $content);
    }
}
