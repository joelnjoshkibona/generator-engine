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
      title: '{$this->moduleName}'
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
      title: 'Create {$this->moduleName}'
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
      title: 'Edit {$this->moduleName}'
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
      title: 'Delete {$this->moduleName}'
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
      title: '{$this->moduleName} Details'
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
          title: '{$this->moduleName} Overview'
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
  }";
        }

        $content .= "
]
";

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName) . "/routes.ts";
        return $this->writeFile($filePath, $content);
    }

    protected function generateCustomFeatureRoutes(string $moduleRoute): string
    {
        $routes = '';

        foreach ($this->customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                $featureName = Str::kebab($customFeature['name'] ?? $featureKey);
                $FeatureName = Str::studly($customFeature['name'] ?? $featureKey);
                $label = $customFeature['label'] ?? $FeatureName;

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
