<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class FrontendRoutesGenerator extends BaseGenerator
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
        // Route `meta.title` becomes the browser tab title (see router.ts).
        // Raw PascalCase moduleName must never be interpolated verbatim —
        // humanize() spaces it, and singular/plural follows the convention
        // confirmed against hand-completed modules (Roles, Users, Locations,
        // etc.): the list route stays plural ("Roles"), everything else
        // (Delete/Details/History) uses the singular form ("Delete Role",
        // "Role Details", "Role History").
        $pluralTitle   = $this->humanize($this->moduleName);
        $singularTitle = $this->humanize(Str::singular($this->moduleName));

        $content = "import type {RouteRecordRaw} from \"vue-router\";
import type {EntityModuleConfig} from \"@/composables/useEntityNavigation\";

export const {$this->moduleName}Routes: RouteRecordRaw[] = [";

        // Generate list route
        if (in_array('list', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/list',
\t\tname: '{$this->moduleName}',
\t\tcomponent: () => import('./{$this->moduleName}ListPage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.list',
\t\t\ttitle: '{$pluralTitle}'
\t\t}
\t},";
        }

        // Generate create route
        if (in_array('create', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/create',
\t\tname: '{$moduleRoute}-create',
\t\tcomponent: () => import('./{$this->moduleName}CreatePage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.create'
\t\t}
\t},";
        }

        // Generate edit route
        if (in_array('edit', $this->features)) {
            $idParam = $this->config['features']['frontend']['view']['idParam'] ?? 'uuid';
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$idParam}/edit',
\t\tname: '{$moduleRoute}-edit',
\t\tcomponent: () => import('./{$this->moduleName}EditPage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.edit'
\t\t},
\t\tprops: true
\t},";
        }

        // Generate delete route
        if (in_array('delete', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:uuid/delete',
\t\tname: '{$moduleRoute}-delete',
\t\tcomponent: () => import('./{$this->moduleName}DeletePage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.delete',
\t\t\ttitle: 'Delete {$singularTitle}'
\t\t},
\t\tprops: true
\t},";
        }

        // Generate view/details route with children
        if (in_array('view', $this->features)) {
            $idParam = $this->config['features']['frontend']['view']['idParam'] ?? 'uuid';

            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$idParam}/details',
\t\tcomponent: () => import('./{$this->moduleName}DetailsLayout.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.view',
\t\t\ttitle: '{$singularTitle} Details'
\t\t},
\t\tprops: true,
\t\tchildren: [
\t\t\t{
\t\t\t\tpath: '',
\t\t\t\tredirect: to => `/{$moduleRoute}/\${to.params.{$idParam}}/details/overview`
\t\t\t},
\t\t\t{
\t\t\t\tpath: 'overview',
\t\t\t\tname: '{$moduleRoute}-overview',
\t\t\t\tcomponent: () => import('./{$this->moduleName}DetailsOverviewPage.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$this->moduleName}.view',
\t\t\t\t\ttitle: '{$singularTitle} Details'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t},
\t\t\t{
\t\t\t\tpath: 'history',
\t\t\t\tname: '{$moduleRoute}-history',
\t\t\t\tcomponent: () => import('./{$this->moduleName}DetailsHistoryPage.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$this->moduleName}.view',
\t\t\t\t\ttitle: '{$singularTitle} History'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t}" . $this->generateCustomFeatureRoutes($moduleRoute) . "
\t\t]
\t},";
        }

        $content .= "
]";

        $moduleConfigExport = $this->generateModuleConfigExport($moduleRoute);
        if ($moduleConfigExport !== '') {
            $content .= "\n" . $moduleConfigExport;
        }

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . "/routes.ts";
        return $this->writeFile($filePath, $content);
    }

    /**
     * Emit a `<ModuleName>ModuleConfig` export — registers this module with
     * useEntityNavigation() so RelatedRecordLink (used for FK-derived list/view
     * cells, anywhere they point at this module, including this module's own
     * self-referential FKs) can open this module's own record details.
     *
     * Mirrors the hand-added block in the reference Locations/Locations/routes.ts.
     * Only emitted when the 'view' feature is enabled: detailsView imports
     * {ModuleName}ViewModal.vue, which only exists for view-enabled modules.
     */
    protected function generateModuleConfigExport(string $moduleRoute): string
    {
        if (!in_array('view', $this->features)) {
            return '';
        }

        return "
// Registers {$this->moduleName} with useEntityNavigation() — needed for RelatedRecordLink
// to open a {$this->moduleName} record's own details view whenever a FK column
// (in this module's own list, or another module's) links back to it.
export const {$this->moduleName}ModuleConfig: EntityModuleConfig = {
\tmode: 'modal',
\troute: '/{$moduleRoute}',
\tdetailsView: () => import('./Components/{$this->moduleName}ViewModal.vue'),
\tmodalSize: 'lg'
};";
    }

    protected function generateCustomFeatureRoutes(string $moduleRoute = ''): string
    {
        if (empty($moduleRoute)) {
            $moduleRoute = Str::kebab($this->moduleName);
        }
        $routes = '';
        $idParam = $this->config['features']['frontend']['view']['idParam'] ?? 'uuid';

        foreach ($this->customFeatures as $featureKey => $customFeature) {
            // Only tab delegations generate frontend routes (they appear as tabs in details view)
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                // kebab for the URL segment, StudlyCase for the permission and
                // the component name. These are NOT interchangeable: RoutesGenerator
                // registers the backend guard as
                // `permission:{Module}.{StudlyFeature}.list`, so emitting the kebab
                // form in the route meta gave the frontend guard a permission string
                // the backend never grants — no single permission record could
                // satisfy both, and the tab 403'd for every role.
                $featureName = Str::kebab($customFeature['name'] ?? $featureKey);
                $FeatureName = Str::studly($customFeature['name'] ?? $featureKey);
                // Same raw-name leak as the module title above: fall back to a
                // humanized label when the blueprint doesn't supply one.
                $label = $customFeature['label'] ?? $this->humanize($FeatureName);

                $routes .= ",
\t\t\t{
\t\t\t\tpath: '{$featureName}',
\t\t\t\tname: '{$moduleRoute}-{$featureName}',
\t\t\t\tcomponent: () => import('./{$this->moduleName}{$FeatureName}Tab.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$this->moduleName}.{$FeatureName}.list',
\t\t\t\t\ttitle: '{$label}'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t}";
            }
        }

        return $routes;
    }
}
