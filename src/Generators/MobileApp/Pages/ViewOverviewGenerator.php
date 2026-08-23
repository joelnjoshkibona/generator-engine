<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates the DetailsOverviewPage for MOBILE_APP.
 * Receives data from parent DetailsLayout via props.
 */
class ViewOverviewGenerator extends BaseComponentGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName);
    }

    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/view/overview', 'mobile_app');

        // Generate overview sections from columns
        $sections = $this->generateOverviewSections();

        // inline_items reached mobile's Create/Edit forms already (see
        // MobileApp\Components\{Create,Edit}FormGenerator) but never the
        // View/Overview page -- these two placeholders close that gap,
        // mirroring FRONTEND\Pages\ViewOverviewGenerator's own wiring.
        $lineItemsSections = $this->generateLineItemsSections();
        $lineItemsImports  = $this->generateLineItemsViewImports();

        $content = $this->replacePlaceholders($content, [
            '[[sections]]'          => $sections,
            '[[lineItemsSections]]' => $lineItemsSections,
            '[[lineItemsImports]]'  => $lineItemsImports,
        ]);

        $filePath = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/{$this->moduleName}DetailsOverviewPage.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Mobile-specific override of BaseComponentGenerator::generateLineItemsViewImports() --
     * the inherited version is fine as-is (path-agnostic import statements),
     * but is kept as a local override anyway to stay next to
     * writeLineItemsViewComponent() below, which CANNOT reuse the inherited
     * version (that one hardcodes the web FRONTEND path/template group).
     */
    protected function generateLineItemsViewImports(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $imports = [];
        foreach ($inlineItems as $item) {
            $componentName = $this->moduleName . Str::studly($item['key']) . 'LineItemsView';
            $imports[]     = "import {$componentName} from './Components/{$componentName}.vue'";
        }

        return implode("\n", $imports);
    }

    /**
     * Matches overview.stub's icon-badge Card style (Agrovet's own details-page
     * pattern -- see DetailsPageLayout.vue's docblock for why this page
     * deliberately diverges from web's dense-row style). Kept as a local
     * override only because it must call the mobile-path-aware
     * writeLineItemsViewComponent() below instead of the inherited one.
     */
    protected function generateLineItemsSections(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key    = $item['key'];
            $label  = $item['label'] ?? ucwords(str_replace('_', ' ', $key));
            $fields = $item['fields'] ?? [];

            $componentName = $this->writeLineItemsViewComponent($key, $fields, $label);

            $blocks[] = <<<VUE

			<!-- {$label} -->
			<Card class="rounded-xl overflow-hidden gap-2 py-3">
				<CardHeader class="flex flex-row items-center gap-2 px-4 pb-0">
					<div class="p-1 rounded-lg bg-muted">
						<component :is="icons.PackageIcon" class="h-3.5 w-3.5 text-muted-foreground" />
					</div>
					<CardTitle class="text-sm">{$label}</CardTitle>
				</CardHeader>
				<CardContent class="px-4 pt-0">
					<{$componentName} :items="data?.{$key} ?? []" />
				</CardContent>
			</Card>
VUE;
        }

        return implode("\n", $blocks);
    }

    /**
     * Mobile-path/template equivalent of BaseComponentGenerator::writeLineItemsViewComponent() --
     * the field-mapping heuristics (name/quantity/unitPrice/total/discount/
     * code guessing) are identical to web's, only the output path and stub
     * template group differ, so this duplicates rather than calls the
     * inherited version (which hardcodes PathManager::getFrontendModulePath()
     * and the 'frontend' template group -- calling it from here would write
     * the wrapper component into the wrong platform's tree entirely).
     */
    protected function writeLineItemsViewComponent(string $key, array $fields, string $label): string
    {
        $componentName = $this->moduleName . Str::studly($key) . 'LineItemsView';

        $fieldNames = array_column($fields, 'key');
        $fieldList  = implode(', ', $fieldNames);

        $nameField      = $this->guessLineItemField($fieldNames, ['name', 'product_name', 'item_name', 'description', 'title']);
        $quantityField  = $this->guessLineItemField($fieldNames, ['quantity', 'qty', 'units', 'amount']);
        $unitPriceField = $this->guessLineItemField($fieldNames, ['unit_price', 'price', 'unit_cost', 'rate', 'cost']);
        $totalField     = $this->guessLineItemField($fieldNames, ['total', 'total_price', 'total_amount', 'line_total', 'subtotal']);
        $discountField  = $this->guessLineItemField($fieldNames, ['discount', 'discount_amount']);
        $codeField      = $this->guessLineItemField($fieldNames, ['code', 'sku', 'barcode', 'item_code', 'product_code']);

        $fieldsByKey = [];
        foreach ($fields as $field) {
            if (!empty($field['key'])) {
                $fieldsByKey[$field['key']] = $field;
            }
        }
        $resolvedNameField = $nameField ?? $fieldNames[0] ?? null;
        $nameFieldType     = $resolvedNameField ? ($fieldsByKey[$resolvedNameField]['type'] ?? null) : null;
        $nameFieldIsFk     = in_array($nameFieldType, ['select', 'api-select'], true);

        $lines = [];
        if ($resolvedNameField && $nameFieldIsFk) {
            $lines[] = "name: String(item.{$resolvedNameField}_object?.name ?? item.{$resolvedNameField} ?? '—'),";
        } else {
            $lines[] = "name: String(item." . ($resolvedNameField ?? 'name') . " ?? '—'),";
        }
        $lines[] = "quantity: Number(item." . ($quantityField ?? 'quantity') . " ?? 0),";

        if ($unitPriceField) {
            $lines[] = "unitPrice: item.{$unitPriceField} != null ? Number(item.{$unitPriceField}) : null,";
        } else {
            $lines[] = "// unitPrice: null, // TODO: map to the unit price field";
        }

        if ($totalField) {
            $lines[] = "total: item.{$totalField} != null ? Number(item.{$totalField}) : null,";
        } else {
            $lines[] = "// total: null, // TODO: map to the line total field";
        }

        if ($discountField) {
            $lines[] = "discount: item.{$discountField} != null ? Number(item.{$discountField}) : null,";
        }

        if ($codeField) {
            $lines[] = "code: item.{$codeField} ?? null,";
        }

        $mappings = implode("\n\t\t", $lines);

        $stub    = $this->getTemplateContent('fields/line-items-view-wrapper', 'mobile_app');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]' => $componentName,
            '[[fieldList]]'     => $fieldList,
            '[[mappings]]'      => $mappings,
        ]);

        $path = PathManager::getMobileAppModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    /**
     * First candidate present in $available, or null. Mirrors
     * BaseComponentGenerator's identically-named private helper (not
     * reusable across classes since it's declared private there).
     */
    private function guessLineItemField(array $available, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Generate overview sections from module columns
     */
    protected function generateOverviewSections(): string
    {
        $columns = $this->config['columns'] ?? [];
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        // overview.stub's <script setup> declares `defineProps<{ data: any }>()` --
        // the interpolated var must be the literal prop name "data", not a
        // module-name-derived identifier (there is no such variable in scope).
        $varName = 'data';

        // Use view.sections if defined (older custom-sections shape), else
        // view.fields (what the View-builder wizard actually writes -- this
        // branch used to be entirely missing, so mobile's Overview silently
        // ignored the configured field list/order/selection and always fell
        // through to the raw-columns loop below, for every module, since
        // this class's very first version), else generate from columns.
        if (isset($viewConfig['sections'])) {
            return $this->generateCustomSections($viewConfig['sections'], $varName);
        }

        if (!empty($viewConfig['fields']) && is_array($viewConfig['fields'])) {
            $rows = [];
            foreach ($viewConfig['fields'] as $field) {
                $name = $field['data'] ?? '';
                if ($name === '' || in_array($name, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'], true)) {
                    continue;
                }
                // 'title', matching the real key the View-builder wizard
                // writes (same key web's mapViewFieldsToInformationFields()
                // reads) -- NOT 'label', which this config shape never sets.
                $label = $field['title'] ?? $this->generateFieldLabel($name);
                $rows[] = $this->generateOverviewRow($label, $varName, $name);
            }

            return implode("\n", $rows);
        }

        $sections = [];
        foreach ($columns as $column) {
            $name = $column['name'];
            $label = $this->generateFieldLabel($name);

            // Skip system fields (handled separately in template)
            if (in_array($name, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'])) {
                continue;
            }

            $sections[] = $this->generateOverviewRow($label, $varName, $name);
        }

        return implode("\n", $sections);
    }

    /**
     * Generate sections from custom view config
     */
    protected function generateCustomSections(array $sections, string $varName): string
    {
        $output = [];
        foreach ($sections as $section) {
            $fields = $section['fields'] ?? [];
            foreach ($fields as $field) {
                $name = $field['field'] ?? $field['name'] ?? '';
                $label = $field['label'] ?? $this->generateFieldLabel($name);

                $output[] = $this->generateOverviewRow($label, $varName, $name);
            }
        }

        return implode("\n", $output);
    }

    /**
     * One field's cell -- matches Agrovet's own DetailField.vue: label
     * stacked above value inside a grid cell, not a dense side-by-side row
     * (see DetailsPageLayout.vue's docblock for why this page deliberately
     * diverges from web's dense-row style). Deliberately not the full
     * per-section-Card/type-branching structure
     * BaseComponentGenerator::generateInformationRows() has -- mobile's
     * overview.stub renders every field inside ONE flat Information card;
     * porting THAT structure is a separate, larger change from matching the
     * card style. FK/status resolution (resolveColumnRelationship()) IS
     * shared with web's generateInformationRows(), though -- same `.color`
     * convention, just this platform's own row markup.
     */
    private function generateOverviewRow(string $label, string $varName, string $name): string
    {
        $relInfo = $this->resolveColumnRelationship($name);

        if ($relInfo === null) {
            $valueHtml = "{{ {$varName}?.{$name} || 'N/A' }}";
        } else {
            $valueHtml = "{{ {$varName}?.{$relInfo['relation']}?.{$relInfo['displayField']} || 'N/A' }}";
            if ($relInfo['isStatusLike']) {
                $valueHtml = "<span class=\"h-2 w-2 rounded-full shrink-0\" :style=\"{$varName}?.{$relInfo['relation']}?.color ? `background-color: \${{$varName}?.{$relInfo['relation']}.color}` : ''\"></span>{$valueHtml}";
            }
        }

        return "\t\t\t\t\t\t<div class=\"flex flex-col leading-tight\">\n"
            . "\t\t\t\t\t\t\t<span class=\"text-xs text-muted-foreground font-medium\">{$label}</span>\n"
            . "\t\t\t\t\t\t\t<span class=\"text-sm font-medium text-foreground inline-flex items-center gap-1.5\">{$valueHtml}</span>\n"
            . "\t\t\t\t\t\t</div>";
    }
}
