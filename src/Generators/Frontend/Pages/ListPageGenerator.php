<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class ListPageGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['list'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/list/page', 'frontend');

        // Generate columns and custom cell renderers from list fields if available
        $listConfig = $this->config['features']['frontend']['list'] ?? [];
        $columns = '';
        $primaryKey = 'name';
        $customCellRenderers = '';

        if (!empty($listConfig['fields']) && is_array($listConfig['fields'])) {
            $columns = $this->generateColumnsFromListFields($listConfig['fields']);

            // Determine primary field key
            $primaryFieldKey = $listConfig['primaryField'] ?? '';
            if (empty($primaryFieldKey) && !empty($listConfig['fields'])) {
                $firstField = $listConfig['fields'][0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? 'name';
            }
            $primaryKey = $primaryFieldKey ?: 'name';

            // Generate custom cell renderers for badge/boolean/FK fields.
            // includePrimaryKey: true — page.stub (unlike the delegation tab's
            // tab_action.stub) has no separate hand-rolled primary-column cell
            // block, so the primary field must run through this same
            // type-aware renderer selection like any other column, or an
            // FK-typed primary field (e.g. UserLocations.user_id) silently
            // shows a raw id instead of a name. See this method's own
            // docblock in BaseComponentGenerator for the full explanation.
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listConfig['fields'], $primaryKey, 'row', true);
        }

        // bulk_actions/export/import live at features.backend.list, not
        // features.frontend.list — a separate config block from $listConfig
        // above.
        $backendListConfig = $this->config['features']['backend']['list'] ?? [];
        $enableExport = !empty($backendListConfig['export']) ? 'true' : 'false';
        $enableBulkActions = !empty($backendListConfig['bulk_actions']) ? 'true' : 'false';
        $enableImport = !empty($backendListConfig['import']) ? 'true' : 'false';
        $bulkActionsLiteral = $this->generateBulkActionsLiteral($backendListConfig['bulk_actions'] ?? []);

        $frontendFeatures = $this->config['features']['frontend'] ?? [];
        [$crudPanelOperationProps, $crudFormImports] = $this->generateCrudOperationBlocks($frontendFeatures);

        $content = $this->replacePlaceholders($content, [
            '[[columns]]'                  => $columns,
            '[[customCellRenderers]]'      => $customCellRenderers,
            '[[enableExport]]'             => $enableExport,
            '[[enableBulkActions]]'        => $enableBulkActions,
            '[[enableImport]]'             => $enableImport,
            '[[bulkActionsLiteral]]'       => $bulkActionsLiteral,
            '[[crudPanelOperationProps]]'  => $crudPanelOperationProps,
            '[[crudFormImports]]'          => $crudFormImports,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}ListPage.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * A module without e.g. `features.frontend.delete` (an append-only ledger
     * table, say) must not get a `{Module}DeleteForm.vue` import here —
     * DeletePageGenerator/DeleteFormGenerator never write that file for such a
     * module, so an unconditional import would break the Vite build. Builds
     * both the CrudListPanel prop lines and the component imports for
     * whichever of create/edit/delete/view are actually enabled.
     *
     * Resolves `[[ModuleName]]`/`[[PermissionBaseName]]`/`[[moduleRoute]]`
     * itself rather than embedding those tokens in the returned strings:
     * replacePlaceholders() substitutes every key in one non-recursive pass,
     * so a literal `[[ModuleName]]` inside a value injected via this method's
     * own placeholder would never get a second pass to resolve it.
     *
     * @return array{0: string, 1: string} [propsBlock, importsBlock]
     */
    private function generateCrudOperationBlocks(array $frontendFeatures): array
    {
        $moduleName = $this->moduleName;
        $permissionBaseName = $this->moduleName;
        $moduleRoute = Str::kebab($this->moduleName);

        $propLines = [];
        $importLines = [];

        if (!empty($frontendFeatures['create'])) {
            $propLines[] = ":create-component=\"{$moduleName}CreateForm\"";
            $propLines[] = ":create-permission=\"'{$permissionBaseName}.create'\"";
            $propLines[] = ":create-title=\"\$t('{$moduleRoute}.page_create')\"";
            $propLines[] = ":create-label=\"\$t('{$moduleRoute}.create_btn')\"";
            $importLines[] = "import {$moduleName}CreateForm from './Components/{$moduleName}CreateForm.vue'";
        }
        if (!empty($frontendFeatures['edit'])) {
            $propLines[] = ":edit-component=\"{$moduleName}EditForm\"";
            $propLines[] = ":edit-title=\"\$t('{$moduleRoute}.page_edit')\"";
            $importLines[] = "import {$moduleName}EditForm from './Components/{$moduleName}EditForm.vue'";
        }
        if (!empty($frontendFeatures['delete'])) {
            $propLines[] = ":delete-component=\"{$moduleName}DeleteForm\"";
            $propLines[] = ":delete-title=\"\$t('{$moduleRoute}.page_delete')\"";
            $importLines[] = "import {$moduleName}DeleteForm from './Components/{$moduleName}DeleteForm.vue'";
        }
        if (!empty($frontendFeatures['view'])) {
            $propLines[] = ":view-component=\"{$moduleName}ViewModal\"";
            $propLines[] = ":view-permission=\"'{$permissionBaseName}.view'\"";
            $propLines[] = ":view-title=\"\$t('{$moduleRoute}.page_details')\"";
            $importLines[] = "import {$moduleName}ViewModal from './Components/{$moduleName}ViewModal.vue'";
        }

        // The placeholder token in the stub already sits at the right indent
        // for the first line; every later line needs its own explicit indent
        // since it becomes a brand-new line with no other prefix.
        $propsBlock = implode("\n\t\t\t", $propLines);
        $importsBlock = implode("\n", $importLines);

        return [$propsBlock, $importsBlock];
    }
}
