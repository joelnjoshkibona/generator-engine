<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Generates Components/{ModuleName}List.vue for MOBILE_APP.
 *
 * Unlike the FRONTEND (web) ListComponentGenerator -- removed as dead output,
 * since FRONTEND's real list pages compose ListPageBareCards/ListTable
 * directly -- MOBILE_APP's real, actively-used list pages (Users, Roles,
 * LocationTypes, ...) all delegate to a Components/{Module}List.vue that
 * wraps `ListPageBareCards` + auto-generated cards from a `columns` array.
 * {ModuleName}ListPage.vue (ListPageGenerator) imports this file by
 * convention, so it must exist for every mobile module.
 */
class ListComponentGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['list'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/list/component', 'mobile_app');

        $content = $this->replacePlaceholders($content, [
            '[[columns]]' => $this->buildColumns($frontendConfig),
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}List.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Build the `Column[]` literal from features.frontend.list.fields --
     * already introspected/hand-authored in the exact {key,title,sortable,
     * data,class} shape ListPageBareCards' Column type expects.
     */
    private function buildColumns(array $frontendConfig): string
    {
        $fields = $frontendConfig['fields'] ?? [];
        if (empty($fields)) {
            return '';
        }

        $lines = [];
        $index = 0;
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['data'] ?? '';
            if ($key === '') {
                continue;
            }
            $title = addslashes($field['title'] ?? $key);
            $data = addslashes($field['data'] ?? $key);
            $sortable = ($field['sortable'] ?? true) ? 'true' : 'false';
            $class = addslashes($field['class'] ?? '');

            // Optional opt-out: a field may set 'defaultVisible' => false to
            // start hidden behind ListPageBareCards' "Customize columns"
            // sheet. Omitted entirely unless explicitly false, so existing
            // generated output is unchanged byte-for-byte -- mirrors
            // BaseComponentGenerator's identical FRONTEND-side convention.
            // Never applied to the first field: it's always the card's
            // title, never hideable (ListPageBareCards excludes columns[0]
            // from configurableColumns entirely, same as ReportTable
            // excludes fixed columns).
            $defaultVisibleStr = ($index > 0 && ($field['defaultVisible'] ?? true) === false)
                ? ', defaultVisible: false'
                : '';

            $lines[] = "\t{ key: \"{$key}\", title: \"{$title}\", sortable: {$sortable}, data: \"{$data}\", class: \"{$class}\"{$defaultVisibleStr} },";
            $index++;
        }

        return implode("\n", $lines);
    }
}
