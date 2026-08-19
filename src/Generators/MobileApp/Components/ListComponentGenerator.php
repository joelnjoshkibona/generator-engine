<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\MobileAppConfigResolver;

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
     *
     * Also annotates each column with an optional `role` (title/badge/
     * footerAmount/footerDate) resolved from mobile_app.list.card via
     * MobileAppConfigResolver::resolveListCard() -- previously that config
     * had no path to the generated output at all (the resolver existed but
     * was never called from anywhere), so ListPageBareCards.vue always fell
     * back to its own runtime name-regex heuristics. The resolver already
     * falls back to those SAME heuristics when no explicit config is set,
     * so an unconfigured module's `role` annotations exactly match what the
     * runtime heuristic would have picked anyway -- this only changes
     * behavior for a module with explicit List Card config.
     */
    private function buildColumns(array $frontendConfig): string
    {
        $fields = $frontendConfig['fields'] ?? [];
        if (empty($fields)) {
            return '';
        }

        $card = MobileAppConfigResolver::resolveListCard($this->config);

        // ListPageBareCards.vue always treats columns[0] as the card title
        // and everything from index 1 onward as configurable/secondary (see
        // its own configurableColumns = columns.slice(1)) -- rather than
        // teach the client a second "find the role:title column" path, the
        // resolved titleField is reordered to the front here so the
        // existing position-based convention just works, whether or not it
        // matches $fields' own declared order.
        if (!empty($card['titleField'])) {
            $titleIndex = null;
            foreach ($fields as $i => $field) {
                if (($field['key'] ?? null) === $card['titleField']) {
                    $titleIndex = $i;
                    break;
                }
            }
            if ($titleIndex !== null && $titleIndex !== 0) {
                $titleField = $fields[$titleIndex];
                unset($fields[$titleIndex]);
                array_unshift($fields, $titleField);
            }
        }

        $roleByKey = [];
        foreach ([
            'title' => $card['titleField'] ?? null,
            'badge' => $card['badgeField'] ?? null,
            'footerAmount' => $card['footerAmountField'] ?? null,
            'footerDate' => $card['footerDateField'] ?? null,
        ] as $role => $fieldKey) {
            if ($fieldKey !== null) {
                $roleByKey[$fieldKey] = $role;
            }
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

            $role = $roleByKey[$key] ?? null;
            $roleStr = $role !== null ? ", role: \"{$role}\"" : '';

            $lines[] = "\t{ key: \"{$key}\", title: \"{$title}\", sortable: {$sortable}, data: \"{$data}\", class: \"{$class}\"{$defaultVisibleStr}{$roleStr} },";
            $index++;
        }

        return implode("\n", $lines);
    }
}
