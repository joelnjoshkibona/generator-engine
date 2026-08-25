<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Splash endpoint for a single action — the third instance of machinery that previously served only
 * create and edit.
 *
 * `create` and `edit` have had splash since early on: a GET the form calls on mount to fetch its
 * option lists and defaults. An action's modal needs exactly the same thing and had no way to ask
 * for it, so consuming projects hand-added a route to `Routes/api.php` — a fully regenerated file,
 * meaning the route survived until the next `--force` and then silently vanished. Config in
 * `actions.{name}.splash` survives, because `actions` is a preserved key.
 *
 * Two differences from CreateSplashServiceGenerator, both following from an action acting on a row
 * that already exists:
 *
 *   - the endpoint takes `{uuid}`, so the service can derive choices from the record's own state
 *     (which statuses this contract may move to, which installments this payment may settle);
 *   - it is NOT gated on module `constants`. Create's splash exists to serve constant-backed
 *     dropdowns; an action's splash is usually about the record, so gating it on constants would
 *     silently refuse the common case.
 *
 * Write-once, like the action's own Service: the body is hand-written business logic, and a
 * regenerate must not discard it.
 */
class ActionSplashServiceGenerator extends BaseServiceGenerator
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
        $splash = $this->action['splash'] ?? null;
        if (empty($splash)) {
            return false; // opt-in per action
        }

        $actionName  = Str::studly($this->action['name'] ?? $this->actionKey);
        $actionRoute = Str::kebab($this->action['name'] ?? $this->actionKey);

        $content = $this->getTemplateContent('Features/actionSplash/service', 'backend');

        $content = $this->replacePlaceholders($content, [
            '[[ActionName]]'  => $actionName,
            '[[actionRoute]]' => $actionRoute,
            '[[actionLabel]]' => $this->action['label'] ?? $actionName,
            '[[splashData]]'  => $this->buildSplashData(is_array($splash) ? ($splash['splashData'] ?? []) : []),
        ]);

        // Same import handling as CreateSplashServiceGenerator: a model-typed source needs its
        // module's namespace resolved from the registry, never from the source's own moduleGroup
        // (that is the sub-group, e.g. "Accounting", not the module_type, e.g. "System").
        $imports = [];
        foreach ((is_array($splash) ? ($splash['splashData'] ?? []) : []) as $source) {
            if (($source['type'] ?? 'model') === 'model' && !empty($source['module'])) {
                $module = $source['module'];
                $ns = PathManager::resolveBackendModuleNamespace($module);
                $imports[] = "use {$ns}\\{$module}Model;";
            }
        }
        if ($imports !== []) {
            $content = str_replace(
                'use App\Project\_Src\Helpers;',
                "use App\Project\_Src\Helpers;\n" . implode("\n", array_unique($imports)),
                $content
            );
        }

        $serviceName = "{$this->moduleName}{$actionName}SplashService";

        return $this->writeFileOnce("{$this->modulePath}/Services/{$serviceName}.php", $content);
    }

    /**
     * Render `splashData` entries for an action.
     *
     * BaseServiceGenerator::generateSplashData() reads from
     * `features.backend.{feature}.splashData`, which an action's config does not live under — so
     * this feeds the same shape through a temporary feature key rather than duplicating the
     * rendering logic, which handles custom/model sources, pagination and module resolution.
     */
    protected function buildSplashData(array $splashData): string
    {
        if ($splashData === []) {
            return '// Add the option lists and defaults this action\'s form needs.';
        }

        $original = $this->config;
        $this->config['features']['backend']['__actionSplash'] = ['splashData' => $splashData];

        try {
            return $this->generateSplashData('__actionSplash');
        } finally {
            $this->config = $original;
        }
    }
}
