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

        // Override serviceName if provided, strip module prefix and 'Service' suffix.
        // !empty(), not ?? — ActionConfigNormalizer::normalize() always sets
        // serviceName to '' when the caller doesn't provide one (never null),
        // so ?? never actually falls back to $actionName: every
        // blank-serviceName action generated e.g. "StatusesService" instead
        // of "StatusesApproveService", and a second such action on the same
        // module collided with (overwrote) the first one's service file.
        $serviceNameRaw = !empty($this->action['serviceName']) ? $this->action['serviceName'] : $actionName;
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

        // Unlike Create/Edit's pure-CRUD services, an action's whole purpose
        // is custom business logic -- the stub's own "Add your custom logic
        // here" TODO is written assuming a developer fills it in by hand.
        // Plain writeFile() gets force-overwritten on every regenerate (same
        // bug class as the inline-items wrapper, see BaseGenerator::
        // writeFileOnce()'s docblock), silently wiping that hand-written
        // logic back to the empty stub the next time this module regenerates
        // for any unrelated reason (a schema tweak, another action, etc).
        return $this->writeFileOnce($filePath, $content);
    }
}
