<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates routes.ts for MOBILE_APP modules.
 * Key differences from FRONTEND routes:
 * - Details route uses /:uuid (no /details prefix)
 * - Cleaner route structure matching mobile navigation patterns
 */
class MobileAppRoutesGenerator extends BaseGenerator
{
    protected array $features;
    protected array $customFeatures;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features from features.frontend
        $this->features = [];
        $frontendFeatures = $config['features']['frontend'] ?? [];
        foreach (['list', 'create', 'view', 'edit', 'delete'] as $feature) {
            if (isset($frontendFeatures[$feature])) {
                $this->features[] = $feature;
            }
        }
        $this->features = array_unique($this->features);

        $this->customFeatures = $config['delegations'] ?? [];
    }

    public function generate(): bool
    {
        $moduleRoute = Str::kebab($this->moduleName);
        $idParam = $this->config['features']['frontend']['view']['idParam'] ?? 'uuid';
        // Same convention as FrontendRoutesGenerator: humanize() spaces the raw
        // PascalCase moduleName, list stays plural, everything else (Create/
        // Edit/Delete/Details/Overview) uses the singular form.
        $pluralTitle   = $this->humanize($this->moduleName);
        $singularTitle = $this->humanize(Str::singular($this->moduleName));

        $content = "import type { RouteRecordRaw } from 'vue-router'

export const {$this->moduleName}Routes: RouteRecordRaw[] = [";

        // Generate list route
        if (in_array('list', $this->features)) {
            $content .= "
  {
    path: '/{$moduleRoute}/list',
    name: '{$this->moduleName}',
    component: () => import('./{$this->moduleName}ListPage.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$this->moduleName}.list',
      title: '{$pluralTitle}'
    }
  },";
        }

        // Generate create route
        if (in_array('create', $this->features)) {
            $content .= "
  {
    path: '/{$moduleRoute}/create',
    name: '{$moduleRoute}-create',
    component: () => import('./{$this->moduleName}CreatePage.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$this->moduleName}.create',
      title: 'Create {$singularTitle}'
    }
  },";
        }

        // Generate edit route
        if (in_array('edit', $this->features)) {
            $content .= "
  {
    path: '/{$moduleRoute}/:{$idParam}/edit',
    name: '{$moduleRoute}-edit',
    component: () => import('./{$this->moduleName}EditPage.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$this->moduleName}.edit',
      title: 'Edit {$singularTitle}'
    },
    props: true
  },";
        }

        // Generate delete route
        if (in_array('delete', $this->features)) {
            $content .= "
  {
    path: '/{$moduleRoute}/:{$idParam}/delete',
    name: '{$moduleRoute}-delete',
    component: () => import('./{$this->moduleName}DeletePage.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$this->moduleName}.delete',
      title: 'Delete {$singularTitle}'
    },
    props: true
  },";
        }

        // Generate view/details route with children (MOBILE_APP pattern: /:uuid not /:uuid/details)
        if (in_array('view', $this->features)) {
            $content .= "
  {
    path: '/{$moduleRoute}/:{$idParam}',
    component: () => import('./{$this->moduleName}DetailsLayout.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$this->moduleName}.view',
      title: '{$singularTitle} Details'
    },
    props: true,
    children: [
      {
        path: '',
        redirect: to => `/{$moduleRoute}/\${to.params.{$idParam}}/overview`
      },
      {
        path: 'overview',
        name: '{$moduleRoute}-overview',
        component: () => import('./{$this->moduleName}DetailsOverviewPage.vue'),
        meta: {
          requiresAuth: true,
          permission: '{$this->moduleName}.view',
          title: '{$singularTitle} Overview'
        },
        props: true
      },
      {
        path: 'history',
        name: '{$moduleRoute}-history',
        component: () => import('./{$this->moduleName}DetailsHistoryPage.vue'),
        meta: {
          requiresAuth: true,
          permission: '{$this->moduleName}.view'
        },
        props: true
      }" . $this->generateCustomFeatureRoutes($moduleRoute) . "
    ]
  },";
        }

        // A trailing comma before the closing `]` is valid JS/TS regardless
        // of whether the view block above ran, so this is safe whether or
        // not any action routes actually get appended below.
        $content .= $this->generateActionRoutes($moduleRoute);

        $content .= "
]
";

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName) . "/routes.ts";
        return $this->writeFile($filePath, $content);
    }

    /**
     * Top-level route per generated action Page — mirrors FRONTEND's
     * RoutesGenerator::generateActionRoutes(), but unconditional on `uiType`
     * (mobile's ActionModalGenerator always emits a page; see its own
     * docblock for why). Respects the same mobile_app.actions[] enable list
     * ModuleGenerationService::generateMobileAppFiles() uses to decide
     * whether the Page component itself was generated at all — a route
     * pointing at a file that was never written would crash the whole
     * mobile SPA on startup (see the sibling delegation-routes comment in
     * generateCustomFeatureRoutes() below for the exact prior incident).
     */
    protected function generateActionRoutes(string $moduleRoute): string
    {
        $content = '';
        $actions = $this->config['actions'] ?? [];
        $mobileActionsConfig = $this->config['features']['mobile_app']['actions'] ?? [];
        $mobileActionsByName = [];
        foreach ($mobileActionsConfig as $entry) {
            if (!empty($entry['name'])) {
                $mobileActionsByName[$entry['name']] = $entry;
            }
        }

        foreach ($actions as $actionKey => $action) {
            if (empty($action['hasUI'])) {
                continue;
            }

            $name = $action['name'] ?? $actionKey;
            $mobileEntry = $mobileActionsByName[$name] ?? null;
            $mobileEnabled = $mobileEntry === null ? true : !empty($mobileEntry['enabled']);
            if (!$mobileEnabled) {
                continue;
            }

            $studly = Str::studly($name);
            $kebab = Str::kebab($name);
            $label = $action['label'] ?? $this->humanize($studly);
            $permission = "{$this->moduleName}.{$name}";

            $content .= "
  {
    path: '/{$moduleRoute}/:uuid/{$kebab}',
    name: '{$moduleRoute}-{$kebab}',
    component: () => import('./{$this->moduleName}{$studly}Page.vue'),
    meta: {
      requiresAuth: true,
      permission: '{$permission}',
      title: '{$label}'
    },
    props: true
  },";
        }

        return $content;
    }

    protected function generateCustomFeatureRoutes(string $moduleRoute): string
    {
        $routes = '';

        foreach ($this->customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                $featureName = Str::kebab($customFeature['name'] ?? $featureKey);
                $FeatureName = Str::studly($customFeature['name'] ?? $featureKey);
                // Same raw-name leak as the module title above: fall back to a
                // humanized label when the blueprint doesn't supply one.
                $label = $customFeature['label'] ?? $this->humanize($FeatureName);

                $routes .= ",
      {
        path: '{$featureName}',
        name: '{$moduleRoute}-{$featureName}',
        component: () => import('./{$this->moduleName}Details{$FeatureName}Page.vue'),
        meta: {
          requiresAuth: true,
          permission: '{$this->moduleName}.{$featureName}.list',
          title: '{$label}'
        },
        props: true
      }";
            }
        }

        return $routes;
    }
}
