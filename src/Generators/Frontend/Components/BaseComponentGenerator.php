<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

abstract class BaseComponentGenerator extends BaseGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
    }

    /**
     * Generate a human-readable label from a field name
     * Converts snake_case to Title Case, removes " Id" and " At" suffixes
     * 
     * @param string $fieldName The field name (e.g., "first_name", "country_id", "created_at")
     * @return string The formatted label (e.g., "First Name", "Country", "Created")
     */
    protected function generateFieldLabel(string $fieldName): string
    {
        // Remove common suffixes first
        $fieldName = preg_replace('/_id$/', '', $fieldName); // Remove "_id" suffix
        $fieldName = preg_replace('/_at$/', '', $fieldName); // Remove "_at" suffix
        
        // Convert snake_case to Title Case
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        
        // Remove any remaining " Id" or " At" (in case they weren't at the end)
        $label = str_replace(' Id', '', $label);
        $label = str_replace(' At', '', $label);
        
        // Trim any extra spaces
        $label = trim($label);
        
        return $label;
    }

    /**
     * Format a placeholder string, removing underscores and formatting field names
     * If placeholder contains underscores or field-like patterns, format them
     * 
     * @param string $placeholder The placeholder text (e.g., "Enter first_name", "Enter country_id")
     * @param string|null $fieldKey Optional field key to use for formatting if placeholder is generic
     * @return string The formatted placeholder (e.g., "Enter First Name", "Enter Country")
     */
    protected function formatPlaceholder(string $placeholder, ?string $fieldKey = null): string
    {
        // If placeholder contains underscores, find and replace all field-like patterns
        if (strpos($placeholder, '_') !== false) {
            // Find all words with underscores (field-like patterns)
            // Match patterns like: first_name, country_id, created_at, etc.
            $placeholder = preg_replace_callback('/\b([a-z][a-z0-9_]*[a-z0-9])\b/i', function($matches) {
                $word = $matches[1];
                // Only format if it contains underscore (looks like a field name)
                if (strpos($word, '_') !== false) {
                    return $this->generateFieldLabel($word);
                }
                return $word;
            }, $placeholder);
        } elseif ($fieldKey && strpos($placeholder, $fieldKey) !== false) {
            // If placeholder contains the field key but no underscores, check if field key has underscores
            if (strpos($fieldKey, '_') !== false) {
                $formattedKey = $this->generateFieldLabel($fieldKey);
                $placeholder = str_replace($fieldKey, $formattedKey, $placeholder);
            }
        }
        
        return $placeholder;
    }

    protected function generateColumnsFromListFields(array $fields, ?string $primaryKey = null): string
    {
        $columns = [];
        $moduleRoute = Str::kebab($this->moduleName);

        // Get primary field from parameter, config, or use first field
        if ($primaryKey === null) {
            $listConfig = $this->config['features']['frontend']['list'] ?? [];
            $primaryFieldKey = $listConfig['primaryField'] ?? '';

            // If no explicit primaryField, use first field
            if (empty($primaryFieldKey) && !empty($fields)) {
                $firstField = $fields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? '';
            }
            $primaryKey = $primaryFieldKey;
        }

        // No dedicated ID column is emitted (fixed 2026-07-26): a raw internal
        // "id"/"uuid" integer/string is meaningless to end users and every
        // hand-built reference list (Users, LocationTypes, Locations) omits
        // one. "id" (and, for uuid-keyed modules, "uuid") remains backend
        // sortable/filterable via BaseServiceGenerator::generateFilterableFields()
        // / generateSortableFields() / generateFilterFields() and via the
        // crud.e2e.stub's `?sort=id&order=desc` navigation — none of that
        // depends on a visible frontend column, so removing this entry is safe.

        // Emit ReportColumn-shaped objects (see @/components/report-table): the
        // header text is `label` (NOT `title`) and width is in pixels. The report
        // table handles horizontal scroll + column visibility itself, so no
        // responsive `hidden lg:table-cell` classes are needed.
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';
            $sortable = $field['sortable'] ?? true;
            $i18nKey = "{$moduleRoute}.col_{$key}";

            if ($key === $primaryKey) {
                // Primary column pinned left so it stays visible while scrolling
                $columns[] = "{ key: \"{$key}\", label: t('{$i18nKey}'), sortable: true, fixed: true, width: 240 }";
            } else {
                $sortableStr = $sortable ? 'true' : 'false';
                // Relation (FK) columns stay visible by default, same as every
                // other column — Locations' list shows location_type_id and
                // parent_id, and Users' list shows status_id, both un-hidden.
                // Hiding them here previously stopped RelatedRecordLink (which
                // lives inside these columns) from ever rendering.
                $columns[] = "{ key: \"{$key}\", label: t('{$i18nKey}'), sortable: {$sortableStr}, width: 150 }";
            }
        }

        // Add the actions column (View/Edit/Delete buttons) so it lines up with the
        // <template #cell-actions="{ row }"> slot emitted in list/page.stub. Skip it
        // if a field named "actions" was already supplied to avoid a duplicate key.
        $hasActionsColumn = false;
        foreach ($fields as $field) {
            $fieldKey = $field['key'] ?? $field['field'] ?? '';
            if ($fieldKey === 'actions') {
                $hasActionsColumn = true;
                break;
            }
        }
        if (!$hasActionsColumn) {
            $columns[] = "{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";
        }

        return implode(",\n\t", $columns);
    }

    public function getPrimaryListField(array $fields, ?string $primaryKey = null) {
        // Get primary field from parameter, config, or use first field
        if ($primaryKey === null) {
            $listConfig = $this->config['features']['frontend']['list'] ?? [];
            $primaryFieldKey = $listConfig['primaryField'] ?? '';

            // If no explicit primaryField, use first field
            if (empty($primaryFieldKey) && !empty($fields)) {
                $firstField = $fields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? '';
            }
            $primaryKey = $primaryFieldKey;
        }

        return $primaryKey;
    }

    // $slotProp controls the destructured slot-prop name emitted for the
    // primary-field accessor and the mobile-sub field accessors. Defaults to
    // (and, as of 2026-08-05, every caller passes) 'row' — ListPageGenerator
    // (list/page.stub) and CustomFeatureTabComponentGenerator
    // (custom/tab_action.stub) both wrap <CrudListPanel> -> <ListTable> ->
    // <ReportTable>, which only ever exposes `:row="row"`. ListComponentGenerator
    // (list/component.stub) also wraps <ListTable> directly, same reason,
    // though nothing currently calls that generator — SYSTEM_SHELL's own
    // ModuleScaffolder stopped invoking it, since its output was never
    // imported by anything.
    protected function generatePrimaryCellContentFromListFields(array $fields, ?string $primaryKey = null, string $slotProp = 'row'): string
    {
        $content = [];
        $primaryKey = $this->getPrimaryListField($fields, $primaryKey);
        $moduleRoute = Str::kebab($this->moduleName);

        // First item is the primary field itself — use data path if set (e.g. FK: customer?.name)
        $primaryField = null;
        foreach ($fields as $f) {
            if (($f['key'] ?? $f['field'] ?? '') === $primaryKey) {
                $primaryField = $f;
                break;
            }
        }
        $primaryDisplayPath = ($primaryField['data'] ?? null) ?: $primaryKey;
        $content[] = "<span class=\"font-bolder\">{{ {$slotProp}.{$primaryDisplayPath} }}</span>";

        // Generate responsive content for other fields marked with showOnMobileSub
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';

            // Skip primary field itself
            if ($key === $primaryKey) {
                continue;
            }

            // Only include if showOnMobileSub is true
            if (!($field['showOnMobileSub'] ?? false)) {
                continue;
            }

            $data = $field['data'] ?? null;

            // Use data path if available, otherwise use key
            $dataPath = $data ?? $key;

            // Handle different field types
            $content[] = "<div class=\"text-xs text-muted-foreground lg:hidden\">
\t\t\t\t{{ \$t('{$moduleRoute}.col_{$key}') }}: {{ {$slotProp}.{$dataPath} || 'N/A' }}
\t\t\t</div>";
        }

        return implode("\n\t\t\t", $content);
    }

    // $slotProp controls the destructured slot-prop name emitted in each
    // generated `<template #cell-XXX="{ ... }">` block. Defaults to (and,
    // as of 2026-08-05, every caller passes) 'row' — see
    // generatePrimaryCellContentFromListFields()'s docblock above for why.
    protected function generateCustomCellRenderersFromListFields(array $fields, $primaryKey, string $slotProp = 'row'): string
    {
        $renderers = [];

        $primaryKey = $this->getPrimaryListField($fields, $primaryKey);

        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';

            // Skip primary field itself
            if ($key === $primaryKey) {
                continue;
            }

            $type = $field['type'] ?? 'text';
            $data = $field['data'] ?? null;
            $dataPath = $data ?? $key;
            $enumValues = $field['enum_values'] ?? null;

            // Generate custom cell renderer for badge and boolean types
            if ($type === 'badge' || $type === 'boolean') {
                // Check if it's a relationship field (has dot notation)
                $isRelationship = strpos($dataPath, '.') !== false;

                // Enum column (IntrospectionToConfig::buildFrontendListFields()
                // sets type => 'badge' + enum_values => [{value,label}, ...] for
                // these). Never a relationship -- enum columns aren't FKs -- so
                // this is checked before the isRelationship branch below, which
                // exists only for the (currently introspection-unused) generic
                // relationship-badge case such as status.name.
                if (!$isRelationship && is_array($enumValues) && !empty($enumValues)) {
                    // Consistent single badge style rather than the
                    // Active/Inactive-style two-tone :class ternary used
                    // below -- an enum can have any number of values (e.g.
                    // ItemPrices.price_tier: standard|premium|wholesale), so
                    // that binary green/gray mapping doesn't generalise.
                    // Per-value colour mapping would be a nice bonus but
                    // needs a colour-assignment concept that doesn't exist
                    // anywhere in this generator yet -- deliberately not
                    // half-implemented here.
                    $mapEntries = [];
                    foreach ($enumValues as $enumValue) {
                        $rawValue = addslashes((string) ($enumValue['value'] ?? ''));
                        $label    = addslashes((string) ($enumValue['label'] ?? ''));
                        $mapEntries[] = "'{$rawValue}': '{$label}'";
                    }
                    $mapLiteral = '{ ' . implode(', ', $mapEntries) . ' }';

                    $renderer = "\t\t<!-- Custom cell renderer for enum badge column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800\">\n";
                    $renderer .= "\t\t\t\t{{ ({$mapLiteral})[{$slotProp}.{$key}] ?? {$slotProp}.{$key} }}\n";
                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";

                    $renderers[] = $renderer;
                    continue;
                }

                if ($isRelationship) {
                    // Relationship badge (e.g., status.name)
                    $parts = explode('.', $dataPath);
                    $relationship = $parts[0];
                    $fieldName = $parts[1] ?? 'name';

                    // 'row', not 'item': this renderer is spliced into
                    // <Module>ListPage.vue, whose <ListTable> wraps
                    // ReportTable.vue — confirmed via
                    // `<slot :name="`cell-${col.key}`" :row="row" ...>` in
                    // ReportTable.vue, which never provides an `item` prop
                    // at all. The sibling isFk branch below already used
                    // 'row' correctly; this badge/boolean branch predates
                    // it and was never updated to match, so 'item' was
                    // always undefined here. Confirmed live: a generated
                    // ItemsListPage.vue crashed the entire list — "Cannot
                    // read properties of undefined (reading 'is_active')"
                    // — the instant a real row existed to render, since
                    // is_active (a boolean column) is visible by default.
                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"{$slotProp}.{$relationship}.{$fieldName} === 'Active' || {$slotProp}.{$relationship}.{$fieldName} === 1 || {$slotProp}.{$relationship}.{$fieldName} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'\">\n";
                    $renderer .= "\t\t\t\t{{ {$slotProp}.{$dataPath} || 'N/A' }}\n";
                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                } else {
                    // Direct field badge (e.g., is_active) — see the
                    // 'row' vs 'item' rationale in the isRelationship
                    // branch's comment above; identical fix, same root
                    // cause.
                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"{$slotProp}.{$key} === 'Active' || {$slotProp}.{$key} === 1 || {$slotProp}.{$key} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'\">\n";

                    // For boolean, show Yes/No or Active/Inactive
                    if ($type === 'boolean') {
                        $renderer .= "\t\t\t\t{{ {$slotProp}.{$key} ? 'Yes' : 'No' }}\n";
                    } else {
                        $renderer .= "\t\t\t\t{{ {$slotProp}.{$key} || 'N/A' }}\n";
                    }

                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                }

                $renderers[] = $renderer;
            } elseif ($field['isFk'] ?? false) {
                // Custom cell renderer for FK columns: wrap the display value in
                // RelatedRecordLink so it becomes a clickable link to the related
                // record's own view. RelatedRecordLink itself degrades to plain
                // inert text if relatedModule isn't registered or the user lacks
                // permission, so it's always safe to emit here even for
                // not-yet-registered targets.
                //
                // relationAccessor is the FK column key with its trailing "_id"
                // stripped (e.g. "location_type_id" -> "location_type"), matching
                // the belongsTo() relation-method naming convention this codebase's
                // generated Models use (see
                // ModelGenerator::deriveRelationshipMethodName()) — and, regardless
                // of whether that method name itself is camelCase or snake_case,
                // Eloquent's relationsToArray() snake-cases the JSON key anyway, so
                // this is also what the real API response actually keys the loaded
                // relation under (confirmed against the hand-completed
                // LocationsListPage.vue reference: row.location_type, row.status).
                //
                // Display field defaults to 'name' but is overridden by
                // 'displayField' when the caller (IntrospectionToConfig::
                // buildFrontendListFields(), via its $foreignPrimaryFields
                // map) already resolved the FK target's real display column
                // -- e.g. an `orders` table displayed by `order_number`, not
                // `name`. A hand-authored module.json field that never sets
                // 'displayField' still falls back to 'name', unchanged from
                // before this existed.
                $relationAccessor = preg_replace('/_id$/', '', $key);
                $relatedModule = $field['relatedModule'] ?? '';
                $displayField = $field['displayField'] ?? 'name';

                $renderer = "\t\t<!-- Custom cell renderer for FK column -->\n";
                $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                $renderer .= "\t\t\t<RelatedRecordLink module=\"{$relatedModule}\" :uuid=\"{$slotProp}.{$relationAccessor}?.uuid\">\n";
                $renderer .= "\t\t\t\t{{ {$slotProp}.{$relationAccessor}?.{$displayField} || 'N/A' }}\n";
                $renderer .= "\t\t\t</RelatedRecordLink>\n";
                $renderer .= "\t\t</template>";

                $renderers[] = $renderer;
            }
        }

        return !empty($renderers) ? implode("\n\n", $renderers) . "\n" : '';
    }

    // Form generation helper methods (shared across form generators)
    protected function mapNewFormFieldsToLegacy(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? $field['key'] ?? 'name';
            $label = $field['label'] ?? $this->generateFieldLabel($key);
            $placeholder = $field['placeholder'] ?? ("Enter {$label}");
            // Format placeholder to remove underscores if present
            $placeholder = $this->formatPlaceholder($placeholder, $key);
            $required = $field['required'] ?? false;
            $fieldType = $field['field_type'] ?? 'input';

            // Build mapped field with all original properties preserved
            $mappedField = [
                'key' => $key,
                'name' => $key,
                'type' => $fieldType,  // Use field_type directly instead of mapping
                // The raw semantic type ('number', 'boolean', ...), kept under
                // its own key: 'type' above is a component/stub selector
                // (generateField()/resolveFieldType() key off it, e.g.
                // 'select', 'checkbox') and must not be overloaded to also
                // carry the default-value type — 'number-input' !== 'number'.
                'dataType' => $field['type'] ?? $fieldType,
                'label' => $label,
                'placeholder' => $placeholder,
                'required' => $required,
                'splashKey' => $field['splashKey'] ?? null,
            ];

            // Determine 'options': preserve a real inline options array from config
            // as-is (e.g. enum columns carry their choices here); otherwise fall
            // back to the splash key naming convention. Note splashKey can be an
            // empty string (not null) for static/inline-options fields, so `?:`
            // (falsy check) is used here rather than `??` (null-only check) --
            // using `??` would keep the empty string and short-circuit the
            // Str::plural($key) fallback.
            if (isset($field['options']) && is_array($field['options'])) {
                $mappedField['options'] = $field['options'];
            } else {
                $mappedField['options'] = !empty($field['splashKey']) ? $field['splashKey'] : Str::plural($key);
            }

            // Preserve all additional properties from the original field
            // This includes: api_url, option_label, option_value, decimals, fields, primaryField, etc.
            foreach ($field as $prop => $value) {
                if (!isset($mappedField[$prop]) && $prop !== 'field' && $prop !== 'field_type') {
                    $mappedField[$prop] = $value;
                }
            }

            $mapped[] = $mappedField;
        }
        return $mapped;
    }

    protected function generateFormSections(array $config, string $footerHtml = ''): string
    {
        $sections = $config['sections'] ?? [];

        if (empty($sections)) {
            // Fallback to old structure for backward compatibility
            $fields = $config['fields'] ?? [];
            return $this->generateDefaultFormSection($fields, $footerHtml);
        }

        $sectionContent = [];
        $lastIndex = count($sections) - 1;
        foreach ($sections as $index => $section) {
            $footer = ($index === $lastIndex) ? $footerHtml : '';
            $sectionContent[] = $this->generateFormSection($section, $section['fields'] ?? [], $footer);
        }

        return implode("\n\n\t\t", $sectionContent);
    }

    protected function generateFormFooter(string $formType = 'create'): string
    {
        $moduleRoute = Str::kebab($this->moduleName);
        $moduleSlug = strtolower($this->moduleName);

        if ($formType === 'edit') {
            return "<div class=\"flex items-center justify-between px-4 py-3 border-t shrink-0\">\n"
                 . "\t\t\t<router-link v-if=\"modal\" :to=\"`/{$moduleRoute}/\${uuid}/edit`\">\n"
                 . "\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"sm\" class=\"text-muted-foreground\">\n"
                 . "\t\t\t\t\t<component :is=\"icons['ExternalLinkIcon']\" class=\"h-3.5 w-3.5 mr-1.5\" />\n"
                 . "\t\t\t\t\t{{ \$t('entity.open_full') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t</router-link>\n"
                 . "\t\t\t<div v-else />\n"
                 . "\t\t\t<div class=\"flex gap-3\">\n"
                 . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-cancel\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
                 . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t\t<Button type=\"submit\" size=\"sm\" data-testid=\"{$moduleSlug}-submit\" :disabled=\"isSubmitting\">\n"
                 . "\t\t\t\t\t<component :is=\"icons['Loader2Icon']\" v-if=\"isSubmitting\" class=\"h-3.5 w-3.5 mr-1.5 animate-spin\" />\n"
                 . "\t\t\t\t\t{{ isSubmitting ? \$t('{$moduleRoute}.saving') : \$t('{$moduleRoute}.save_changes') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t</div>\n"
                 . "\t\t</div>";
        }

        // create footer
        return "<div class=\"flex items-center justify-between px-4 py-3 border-t shrink-0\">\n"
             . "\t\t\t<router-link v-if=\"modal\" to=\"/{$moduleRoute}/create\">\n"
             . "\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"sm\" class=\"text-muted-foreground\">\n"
             . "\t\t\t\t\t<component :is=\"icons['ExternalLinkIcon']\" class=\"h-3.5 w-3.5 mr-1.5\" />\n"
             . "\t\t\t\t\t{{ \$t('entity.open_full') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t</router-link>\n"
             . "\t\t\t<div v-else />\n"
             . "\t\t\t<div class=\"flex gap-3\">\n"
             . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-cancel\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
             . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t\t<Button type=\"submit\" size=\"sm\" data-testid=\"{$moduleSlug}-submit\" :disabled=\"isSubmitting\">\n"
             . "\t\t\t\t\t<component :is=\"icons['Loader2Icon']\" v-if=\"isSubmitting\" class=\"h-3.5 w-3.5 mr-1.5 animate-spin\" />\n"
             . "\t\t\t\t\t{{ isSubmitting ? \$t('common.creating') : \$t('common.create') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t</div>\n"
             . "\t\t</div>";
    }

    protected function generateDefaultFormSection(array $fields, string $footerHtml = ''): string
    {
        $fieldsContent = $this->generateFieldsGrid($fields);
        $footer = !empty($footerHtml) ? "\n\t\t{$footerHtml}" : '';

        return "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : 'flex flex-col flex-1 min-h-0'\">
			<div v-if=\"!modal\" class=\"px-4 py-3 border-b shrink-0\">
				<span class=\"text-sm font-semibold\">Main Details</span>
			</div>
			<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4 flex-1 min-h-0 overflow-y-auto\">
            {$fieldsContent}
			</div>{$footer}
		</div>";
    }

    protected function generateFormSection(array $section, array $fields, string $footerHtml = ''): string
    {
        $fieldsContent = $this->generateFieldsGrid($fields);
        $title = $section['title'] ?? 'Main Details';
        $footer = !empty($footerHtml) ? "\n\t\t{$footerHtml}" : '';

        return "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : 'flex flex-col flex-1 min-h-0'\">
			<div v-if=\"!modal\" class=\"px-4 py-3 border-b shrink-0\">
				<span class=\"text-sm font-semibold\">{$title}</span>
			</div>
			<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4 flex-1 min-h-0 overflow-y-auto\">
{$fieldsContent}
			</div>{$footer}
		</div>";
    }

    protected function generateFieldsGrid(array $fields): string
    {
        $fieldContent = [];

        foreach ($fields as $field) {
            $fieldContent[] = $this->generateField($field);
        }

        return implode("\n", $fieldContent);
    }


    protected function arrayToJsObjectString(array $array): string
    {
        // Recursively convert array to JS object string
        $json = json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Remove quotes around keys to make it look more like JS object (optional but cleaner)
        // For simplicity, we'll just use the JSON string which is valid JS
        // But we need to handle function strings if any (e.g. render functions)
        // For now, assuming simple data structures.
        // We need to escape single quotes if we use them in the template attribute.
        // json_encode never escapes an apostrophe inside a string value (only
        // double quotes are special in JSON), so any data value containing one
        // (e.g. an enum option like "o'brien") must have it escaped BEFORE the
        // structural double-quotes are swapped for single-quotes below --
        // otherwise the apostrophe would prematurely terminate the resulting
        // JS single-quoted string and break the generated Vue attribute.
        $json = str_replace("'", "\\'", $json);
        return str_replace('"', "'", $json);
    }

    /**
     * Process inline-items fields to ensure all properties are properly included
     * This ensures readonly and other properties are preserved
     */
    protected function processInlineItemsFields(array $fields): array
    {
        $processedFields = [];
        
        foreach ($fields as $field) {
            $processedField = [
                'key' => $field['key'] ?? '',
                'label' => $field['label'] ?? '',
                'type' => $field['type'] ?? 'input',
            ];
            
            // Include optional properties if they exist
            if (isset($field['placeholder'])) {
                $processedField['placeholder'] = $field['placeholder'];
            }
            if (isset($field['required'])) {
                $processedField['required'] = (bool)$field['required'];
            }
            if (isset($field['disabled'])) {
                $processedField['disabled'] = (bool)$field['disabled'];
            }
            if (isset($field['readonly'])) {
                $processedField['readonly'] = (bool)$field['readonly'];
            }
            if (isset($field['decimals'])) {
                $processedField['decimals'] = (int)$field['decimals'];
            }
            if (isset($field['default'])) {
                $processedField['default'] = $field['default'];
            }
            if (isset($field['inputType'])) {
                $processedField['inputType'] = $field['inputType'];
            }
            if (isset($field['options'])) {
                $processedField['options'] = $field['options'];
            }
            if (isset($field['optionLabel'])) {
                $processedField['optionLabel'] = $field['optionLabel'];
            }
            if (isset($field['optionValue'])) {
                $processedField['optionValue'] = $field['optionValue'];
            }
            if (isset($field['splashKey'])) {
                $processedField['splashKey'] = $field['splashKey'];
            }
            if (isset($field['apiUrl'])) {
                $processedField['apiUrl'] = $field['apiUrl'];
            }
            if (isset($field['showInTable'])) {
                $processedField['showInTable'] = (bool)$field['showInTable'];
            }
            if (isset($field['tableWidth'])) {
                $processedField['tableWidth'] = $field['tableWidth'];
            }
            
            $processedFields[] = $processedField;
        }

        return $processedFields;
    }

    /**
     * Component name for a module's inline-items wrapper -- shared by both
     * inline-items mechanisms this generator has (see
     * writeInlineItemsWrapperComponent()'s docblock), so a field-type field
     * and a top-level `inline_items` block never derive the name differently
     * for the same key.
     */
    protected function inlineItemsWrapperComponentName(string $key): string
    {
        return "{$this->moduleName}" . Str::studly($key) . 'InlineItems';
    }

    /**
     * Emit `{Module}{Key}InlineItems.vue` -- a hand-edit-protected wrapper
     * around the shared InlineItemsComponent, written once via
     * writeFileOnce()'s truly unconditional skip-if-exists (never
     * writeFile() or writeFileAlways()). It declares the field list locally
     * (with TODO-stubbed dynamicDisabled/showField/render hooks) and forwards
     * v-model/attrs straight through to <InlineItemsComponent>, so a module
     * with dependent inline-item fields (e.g. Order Items: disable a field
     * based on another, recompute totals on @item-change) hand-fills exactly
     * one file that regeneration never touches again.
     *
     * Bug (found + fixed 2026-08-02): this used to call writeFile(), whose
     * skip-if-exists is gated on `!$this->force` -- correct for every
     * regularly-regenerated output, but backwards for a file whose entire
     * purpose is to survive regeneration. Any `--force` run (the normal case
     * for picking up an unrelated schema change) silently overwrote a
     * developer's hand-added hooks back to the template. Confirmed via a
     * live `make:module --force` run against a real scratch module. See
     * writeFileOnce()'s own docblock on BaseGenerator for the full writeup.
     *
     * Shared by BOTH of this generator's inline-items mechanisms: a single
     * `field_type: 'inline-items'` entry inside a normal fields[] list (see
     * generateField()), and a full `Card`-wrapped block declared via the
     * top-level `inline_items` config key (see generateInlineItemsBlock()) --
     * the latter is this package's documented parent-child pattern (e.g.
     * Order Items, with its own backend save/sync/load generation), and
     * exactly the scenario this wrapper was built for. Each caller builds
     * its own $fieldsJs (their field shapes differ: camelCase vs this
     * mechanism's snake_case config keys), this method only owns the
     * template render + write-once file I/O both share.
     *
     * @param string $fieldsJs  Pre-built `[{ key: '...', ... }, ...]` JS array literal.
     * @return string  The wrapper component's name (e.g. "OrdersOrderItemsInlineItems"),
     *                 for the caller to splice into its own markup as the tag to render.
     */
    protected function writeInlineItemsWrapperComponent(string $key, string $fieldsJs): string
    {
        $componentName = $this->inlineItemsWrapperComponentName($key);

        $stub = $this->getTemplateContent('fields/inline-items-wrapper', 'frontend');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]' => $componentName,
            '[[ModuleName]]'    => $this->moduleName,
            '[[fieldKey]]'      => $key,
            '[[fields]]'        => $fieldsJs,
        ]);

        $path = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    protected function generateField(array $field): string
    {
        $key = $field['key'] ?? $field['name'];
        $type = $field['type'] ?? 'text';
        $fieldType = $field['field_type'] ?? $type;  // Use field_type if available
        $label = $field['label'] ?? $field['title'] ?? $this->generateFieldLabel($key);
        $required = isset($field['required']) && $field['required'] ? 'true' : 'false';
        $disabled = isset($field['disabled']) && $field['disabled'] ? 'true' : 'false';
        $placeholder = $field['placeholder'] ?? "Enter {$label}";
        // Format placeholder to remove underscores if present
        $placeholder = $this->formatPlaceholder($placeholder, $key);

        // Check if field should be hidden
        $isHidden = isset($field['hidden']) && $field['hidden'];
        // Generate condition: show if not explicitly hidden AND not in hiddens prop
        $hiddenCondition = $isHidden
            ? 'false' // Always hide if explicitly hidden
            : "!props.hiddens?.['{$key}']"; // Show if not in hiddens prop

        // Determine template based on field_type
        $templateType = $fieldType;

        // Normalize field_type aliases that don't have dedicated stub files
        $templateAliases = [
            'date-input'       => 'date',
            'datetime'         => 'date',
            'time-input'       => 'time',
            'boolean'          => 'checkbox',
            'toggle'           => 'checkbox',
            'switch'           => 'checkbox',
            'number'           => 'number-input',
            'email'            => 'input',
            'password'         => 'input',
            'text'             => 'input',
            'select_paginated' => 'api-select',
            'select-paginated' => 'api-select',
        ];
        if (isset($templateAliases[$templateType])) {
            $templateType = $templateAliases[$templateType];
        }

        // Handle boolean fields - can use checkbox or select with YES/NO
        if ($type === 'boolean' && $fieldType === 'select') {
            $templateType = 'select';
            // Boolean select will use static YES/NO options
        } elseif ($type === 'boolean' && $fieldType === 'checkbox') {
            $templateType = 'checkbox';
        } elseif ($fieldType === 'select') {
            $splashKey = $field['splashKey'] ?? null;
            $useApiSelect = false;

            // Use ApiSelect2 for model-backed sources; Select2 for custom/static data (e.g. YES/NO)
            if ($splashKey) {
                $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                $allSplash = array_merge($createSplash, $editSplash);

                foreach ($allSplash as $splashItem) {
                    if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                        $useApiSelect = true;
                        $templateType = 'api-select';
                        break;
                    }
                }
            }

            // Upgrade to inline variant if inline_create is enabled
            if ($templateType === 'api-select' && ($field['inline_create'] ?? false)) {
                $templateType = 'api-select-inline';
            }
        }

        // Defensive fallback: if no stub exists for this template type, fall back to 'input'
        // and log a warning so the issue is visible without breaking generation.
        $stubPath = $this->getStubPath("fields/{$templateType}", 'frontend');
        if (!file_exists($stubPath)) {
            \Illuminate\Support\Facades\Log::warning("No stub for field_type '{$fieldType}' (template '{$templateType}'). Falling back to 'input'.", [
                'module' => $this->moduleName,
                'field_key' => $key,
            ]);
            $templateType = 'input';
        }

        $fieldTemplate = $this->getTemplateContent("fields/{$templateType}", 'frontend');

        $replacements = [
            '[[fieldKey]]' => $key,
            '[[fieldLabel]]' => $label,
            '[[fieldRequired]]' => $required,
            '[[fieldDisabled]]' => $disabled,
            '[[fieldPlaceholder]]' => $placeholder,
            '[[fieldHiddenCondition]]' => $hiddenCondition,
            '[[tabs]]' => "\t\t\t\t", // 4 tabs for proper indentation in grid
        ];

        // Add type-specific replacements
        if ($type === 'boolean' && $fieldType === 'select') {
            // Boolean field using Select2Field with YES/NO options
            // Format: [{ id: true, name: 'Yes' }, { id: false, name: 'No' }]
            $replacements['[[fieldOptions]]'] = "[\n\t\t\t\t\t{ id: true, name: 'Yes' },\n\t\t\t\t\t{ id: false, name: 'No' }\n\t\t\t\t]";
            $replacements['[[fieldOptionLabel]]'] = 'name';
            $replacements['[[fieldOptionValue]]'] = 'id';
            $replacements['[[fieldClearable]]'] = '';
        } elseif ($fieldType === 'select') {
            $splashKey = $field['splashKey'] ?? null;
            $useApiSelect = false;

            // Use ApiSelect2 for model-backed sources; Select2 for custom/static data (e.g. YES/NO)
            if ($splashKey) {
                $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                $allSplash = array_merge($createSplash, $editSplash);

                foreach ($allSplash as $splashItem) {
                    if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                        $useApiSelect = true;
                        // Convert splashKey to kebab-case for API endpoint (route pattern requires [a-z-]+)
                        // Str::kebab() only handles PascalCase, so convert snake_case manually
                        $endpointKey = str_replace('_', '-', $splashKey);
                        $apiEndpoint = "/select/{$endpointKey}";
                        $replacements['[[fieldApiEndpoint]]'] = $apiEndpoint;
                        $replacements['[[fieldPerPage]]'] = $field['per_page'] ?? 20;
                        $replacements['[[fieldMultiple]]'] = isset($field['multiple']) && $field['multiple'] ? 'true' : 'false';
                        if ($field['inline_create'] ?? false) {
                            $createFormModule = $field['create_form_module'] ?? Str::studly(str_replace('_id', '', $key));
                            $replacements['[[createFormModule]]'] = $createFormModule;
                        }
                        break;
                    }
                }
            }

            if (!$useApiSelect) {
                // Static select - use splash options or inline options
                if (isset($field['options']) && is_array($field['options'])) {
                    // Inline options array provided
                    $replacements['[[fieldOptions]]'] = $this->arrayToJsObjectString($field['options']);
                } else {
                    // Use splash options
                    $replacements['[[fieldOptions]]'] = "splash." . ($field['options'] ?? Str::plural($key));
                }
            }

            $replacements['[[fieldOptionLabel]]'] = $field['option_label'] ?? 'name';
            $replacements['[[fieldOptionValue]]'] = $field['option_value'] ?? 'id';
            $replacements['[[fieldClearable]]'] = isset($field['clearable']) && $field['clearable'] ? ':clearable="true"' : '';
        } elseif ($fieldType === 'api-select') {
            // Direct api-select field (not splash-backed; api_url provided directly)
            $replacements['[[fieldApiEndpoint]]'] = $field['api_url'] ?? $field['apiUrl'] ?? '';
            $replacements['[[fieldOptionLabel]]']  = $field['option_label'] ?? $field['optionLabel'] ?? 'name';
            $replacements['[[fieldOptionValue]]']  = $field['option_value'] ?? $field['optionValue'] ?? 'id';
            $replacements['[[fieldPerPage]]']       = $field['per_page'] ?? $field['perPage'] ?? 20;
            $replacements['[[fieldMultiple]]']      = (isset($field['multiple']) && $field['multiple']) ? 'true' : 'false';
        } elseif ($fieldType === 'number-input') {
            // Handle number-input specific replacements
            $decimals = $field['decimals'] ?? 0;
            $replacements['[[fieldDecimals]]'] = $decimals;
            $replacements['[[fieldType]]'] = ''; // No type attribute needed for NumberInputField
        } elseif ($fieldType === 'item-picker') {
            $replacements['[[availableItems]]'] = $field['availableItems'] ?? '[]';
            $replacements['[[availableItemsFields]]'] = $this->arrayToJsObjectString($field['availableItemsFields'] ?? []);
            $replacements['[[configFields]]'] = $this->arrayToJsObjectString($field['configFields'] ?? []);
            $replacements['[[summaryFields]]'] = $this->arrayToJsObjectString($field['summaryFields'] ?? []);
            $replacements['[[primaryField]]'] = $field['primaryField'] ?? 'name';
            $replacements['[[colorScheme]]'] = $field['colorScheme'] ?? 'blue';
            $replacements['[[addButtonText]]'] = $field['addButtonText'] ?? 'Add Item';
            $replacements['[[emptyMessage]]'] = $field['emptyMessage'] ?? 'No items selected';
        } elseif ($fieldType === 'inline-items') {
            // Emit a hand-edit-protected wrapper component (write-once, see
            // writeInlineItemsWrapperComponent()) instead of binding
            // <InlineItemsComponent> directly with an inline :fields array.
            // The wrapper is what a module hand-fills with dynamicDisabled/
            // showField/render hooks and @item-change/@field-change
            // listeners for dependent-field scenarios (e.g. Order Items) --
            // regeneration never touches it again once it exists.
            $processedFields = $this->processInlineItemsFields($field['fields'] ?? []);
            $replacements['[[inlineItemsWrapperComponent]]'] = $this->writeInlineItemsWrapperComponent(
                $key,
                $this->arrayToJsObjectString($processedFields)
            );
            $replacements['[[primaryField]]'] = $field['primaryField'] ?? 'name';
            // No [[colorScheme]] here: InlineItemsComponent never declared a
            // colorScheme prop (confirmed by reading its Props interface --
            // unlike ItemPickerComponent, which genuinely has one), so
            // inline-items.stub previously passed a dead attribute that fell
            // through as inert raw HTML. Dropped rather than wired in, since
            // this same workstream restyles InlineItemsComponent's default
            // row rendering to a fixed, neutral bordered-row-list look with
            // no per-scheme theming (see InlineItemsComponent.vue).
            $replacements['[[addButtonText]]'] = $field['addButtonText'] ?? 'Add Item';
            $replacements['[[emptyMessage]]'] = $field['emptyMessage'] ?? 'No items added';
        } elseif ($fieldType === 'file-input') {
            // All of these map to real props on FileInputField.vue (verified against
            // SYSTEM_SHELL/FRONTEND/src/components/form-fields/FileInputField.vue) —
            // including enableCrop/aspectRatio/cropShape/uploadMode, which that
            // component handles itself via its own built-in ImageCropperModal.
            //
            // v-model does NOT point at form.[[fieldKey]] like every other field --
            // a File object can't live inside the reactive `form` object the same
            // way plain values do (see generateFormFields()'s file-input skip and
            // generateFileRefsBlock()). It binds to a separate ref<File|null>
            // instead, matching the hand-written MobileReleasesCreateForm.vue /
            // MediaCreateForm.vue reference pattern.
            $replacements['[[fieldModelRef]]'] = $this->fileRefName($key);
            $replacements['[[fieldMultiple]]'] = isset($field['multiple']) && $field['multiple'] ? 'true' : 'false';
            $replacements['[[fieldAccept]]'] = $field['accept'] ?? '';
            $replacements['[[fieldMaxSize]]'] = $field['maxSize'] ?? 5;
            $replacements['[[fieldMaxFiles]]'] = $field['maxFiles'] ?? 10;
            $replacements['[[fieldPreview]]'] = isset($field['preview']) && $field['preview'] ? 'true' : 'false';
            $replacements['[[fieldEnableCrop]]'] = isset($field['enableCrop']) && $field['enableCrop'] ? 'true' : 'false';
            $replacements['[[fieldAspectRatio]]'] = $field['aspectRatio'] ?? 0;
            $replacements['[[fieldCropShape]]'] = $field['cropShape'] ?? 'rect';
            $replacements['[[fieldUploadMode]]'] = $field['uploadMode'] ?? 'onSubmit';
            $replacements['[[fieldUploadUrl]]'] = $field['uploadUrl'] ?? '';
        } elseif ($fieldType === 'textarea') {
            $replacements['[[fieldClass]]'] = "class=\"col-span-full\"";
        } elseif (in_array($fieldType, ['input', 'email', 'password', 'date', 'time', 'number'])) {
            $replacements['[[fieldType]]'] = $fieldType !== 'input' ? "type=\"{$fieldType}\"" : '';
        }

        return $this->replacePlaceholders($fieldTemplate, $replacements);
    }

    protected function generateFormFields(array $config): string
    {
        $fieldDefinitions = [];

        // Fallback to old structure
        $fields = $config['fields'] ?? [];
        foreach ($fields as $field) {
            // File fields never live in the reactive `form` object -- a File
            // instance can't round-trip through form.value the way a plain
            // ref/JSON value does, and (per the hand-written
            // MobileReleasesCreateForm.vue / MediaCreateForm.vue reference
            // pattern) they're kept in their own separate `ref<File|null>`
            // instead, merged into the FormData payload at submit time.
            // See generateFileRefsBlock() / generateSubmitCall().
            if ($this->resolveFieldType($field) === 'file-input') {
                continue;
            }

            $key = $field['key'] ?? $field['name'];
            $defaultValue = $this->getFieldDefaultValue($field);
            $fieldDefinitions[] = "  {$key}: {$defaultValue}";
        }

        return implode(",\n", $fieldDefinitions);
    }

    /**
     * Resolve a field's effective "type" for file/boolean detection, regardless
     * of whether it's a raw config field (which carries 'field_type') or one
     * that's already passed through mapNewFormFieldsToLegacy() (which folds
     * field_type into 'type' and drops the 'field_type' key entirely).
     */
    protected function resolveFieldType(array $field): string
    {
        return $field['field_type'] ?? $field['type'] ?? '';
    }

    protected function isBooleanFieldType(string $fieldType): bool
    {
        return in_array($fieldType, ['checkbox', 'boolean', 'toggle', 'switch'], true);
    }

    /**
     * True if any field in the given list (raw or mapped-to-legacy shape) is a
     * file-input field -- the trigger for switching a generated form's submit
     * handler from sendPostRequest/sendPutRequest (plain JSON) to
     * sendFormDataRequest (multipart), per the conditional-switching approach:
     * forms with zero file-input fields are generated exactly as before.
     */
    protected function hasFileInputField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->resolveFieldType($field) === 'file-input') {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> Only the file-input fields, in original order. */
    protected function extractFileInputFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field): bool => $this->resolveFieldType($field) === 'file-input'
        ));
    }

    /**
     * Collect a flat field list from either the new sections-based config
     * shape or the old flat 'fields' shape -- mirrors the same fallback logic
     * already used by generateFormFieldImports().
     */
    protected function collectAllFieldsFromConfig(array $config): array
    {
        $sections = $config['sections'] ?? [];
        if (empty($sections)) {
            return $config['fields'] ?? [];
        }

        $all = [];
        foreach ($sections as $section) {
            $all = array_merge($all, $section['fields'] ?? []);
        }
        return $all;
    }

    /**
     * camelCase ref name for a file-input field's separate `ref<File|null>`.
     * Matches the hand-written convention seen in MobileReleasesCreateForm.vue
     * (apk_file -> apkFile, ota_file -> otaFile) for keys that already end in
     * a file-ish suffix, and appends "File" otherwise (e.g. image_path ->
     * imagePathFile) so the ref name never collides with an unrelated
     * camelCased key and always reads unambiguously as a file ref.
     */
    protected function fileRefName(string $key): string
    {
        $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
        $lower = strtolower($key);

        if ($lower === 'file') {
            return 'file';
        }

        foreach (['_file', '_image', '_document', '_photo', '_attachment'] as $suffix) {
            if (Str::endsWith($lower, $suffix)) {
                return $camel;
            }
        }

        return $camel . 'File';
    }

    /**
     * The `import {...} from "@/helpers"` line for a create/edit form.
     * File-input forms only ever need sendGetRequest (splash/view) +
     * sendFormDataRequest; non-file forms keep the exact import they had
     * before this feature existed.
     */
    protected function generateRequestImportLine(bool $hasFileFields, string $formType): string
    {
        if ($hasFileFields) {
            return 'import {sendGetRequest, sendFormDataRequest} from "@/helpers";';
        }

        return $formType === 'edit'
            ? 'import {sendGetRequest, sendPostRequest, sendPutRequest} from "@/helpers";'
            : 'import {sendGetRequest, sendPostRequest} from "@/helpers";';
    }

    /**
     * Declare a separate `ref<File|null>` per file-input field, placed outside
     * the reactive `form` object -- exactly the pattern used by hand in
     * MobileReleasesCreateForm.vue / MediaCreateForm.vue. Empty string (no-op)
     * when there are no file-input fields, so the common case gets zero diff.
     */
    protected function generateFileRefsBlock(array $fileFields): string
    {
        if (empty($fileFields)) {
            return '';
        }

        $lines = ['', '// File refs'];
        foreach ($fileFields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $ref = $this->fileRefName($key);
            $lines[] = "const {$ref} = ref<File | null>(null)";
        }

        return implode("\n", $lines);
    }

    /**
     * Build the handleSubmit() request call. When no file-input fields are
     * present this is byte-for-byte what the generator emitted before this
     * feature existed (regression guard). When file-input fields ARE present,
     * emits the FormData path: spread the non-file `form.value` fields,
     * converting booleans to '1'/'0' strings (a FormData-only requirement --
     * the JSON path never needed this), then conditionally merge each file
     * ref's value in, then call sendFormDataRequest.
     */
    protected function generateSubmitCall(array $fields, string $formType): string
    {
        $fileFields = $this->extractFileInputFields($fields);

        if (empty($fileFields)) {
            return $formType === 'edit'
                ? 'const response = await sendPutRequest(submitEndpoint.value, { ...form.value })'
                : 'const response = await sendPostRequest(submitEndpoint.value, form.value)';
        }

        $booleanLines = [];
        foreach ($fields as $field) {
            $fieldType = $this->resolveFieldType($field);
            if ($fieldType === 'file-input' || !$this->isBooleanFieldType($fieldType)) {
                continue;
            }
            $key = $field['key'] ?? $field['name'] ?? '';
            $booleanLines[] = "\t\t\t\t{$key}: form.value.{$key} ? '1' : '0',";
        }

        $fileAssignmentLines = [];
        foreach ($fileFields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $ref = $this->fileRefName($key);
            $fileAssignmentLines[] = "\t\t\tif ({$ref}.value) formData.{$key} = {$ref}.value";
        }

        $booleanBlock = !empty($booleanLines) ? "\n" . implode("\n", $booleanLines) : '';
        $fileAssignmentBlock = !empty($fileAssignmentLines) ? "\n" . implode("\n", $fileAssignmentLines) : '';

        // sendFormDataRequest always issues a POST (multipart PUT bodies aren't
        // reliably parsed by PHP), but the generated edit route is registered as
        // PUT (see RouteGenerator). Laravel's Request::enableHttpMethodParameterOverride()
        // -- enabled unconditionally for every request via Request::capture() --
        // resolves a POST to the PUT route when a `_method` field is present, the
        // same spoofing technique Blade's @method('PUT') directive uses for
        // native HTML file-upload forms. Without this, an edit form with a file
        // field would POST to a route only registered for PUT and 404.
        $methodOverrideLine = $formType === 'edit' ? "\n\t\t\t\t_method: 'PUT'," : '';

        return "const formData: Record<string, any> = {\n\t\t\t\t...form.value,{$methodOverrideLine}{$booleanBlock}\n\t\t\t}{$fileAssignmentBlock}\n\t\t\tconst response = await sendFormDataRequest(submitEndpoint.value, formData)";
    }

    protected function getFieldDefaultValue(array $field): string
    {
        $type = $field['type'] ?? 'text';
        $default = $field['default'] ?? '';

        if ($default !== '') {
            // Handle boolean defaults
            if ($type === 'boolean') {
                return $default === 'true' || $default === true || $default === 1 ? 'false' : 'false';
            }
            return "'{$default}'";
        }

        // A numeric input declares modelValue as Number | Null, so seeding it
        // with '' produced "Invalid prop: type check failed for prop
        // modelValue — Expected Number | Null, got String with value ''" on
        // every create form with a numeric column. $type here is the
        // field_type-derived component selector ('number-input'/'checkbox'),
        // not the semantic type, so this checks 'dataType' (preserved
        // separately by mapNewFormFieldsToLegacy()) directly rather than the
        // switch below — the same fix applies to boolean fields, whose
        // CheckboxField declares modelValue: Boolean and produced the
        // identical warning for the same reason ('checkbox' !== 'boolean').
        $dataType = $field['dataType'] ?? null;
        if ($dataType === 'number') {
            return 'null as number | null';
        }
        if ($dataType === 'boolean') {
            return 'false';
        }

        switch ($type) {
            case 'boolean':
                return 'false';
            case 'select':
                return "'' as string | number";
            case 'number':
                return 'null as number | null';
            case 'textarea':
            case 'text':
            case 'email':
            case 'password':
            default:
                return "''";
        }
    }

    protected function generateFormFieldImports(array $config): string
    {
        $sections = $config['sections'] ?? [];
        $imports = [];

        // Collect all fields to check for ApiSelect2 usage
        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
        } else {
            // New section-based structure - collect all fields from all sections
            $allFields = [];
            foreach ($sections as $section) {
                $sectionFields = $section['fields'] ?? [];
                $allFields = array_merge($allFields, $sectionFields);
            }
            $fields = $allFields;
        }

        $fieldTypes = array_unique(array_column($fields, 'field_type'));
        if (empty($fieldTypes)) {
            // Fallback to 'type' column if 'field_type' not available
            $fieldTypes = array_unique(array_column($fields, 'type'));
        }

        $hasApiSelect = false;
        $hasSelect2 = false;
        $inlineCreateImports = [];
        $inlineItemsWrapperImports = [];

        // Determine which select components are needed: ApiSelect2 for model sources, Select2 for custom/static
        foreach ($fields as $field) {
            $fieldType = $field['field_type'] ?? $field['type'] ?? '';

            // Each inline-items field gets its OWN wrapper component (see
            // writeInlineItemsWrapperComponent()), so this is a per-field
            // import, not a per-type one like every other case in the switch
            // below -- a form with two inline-items fields needs two
            // different sibling imports, not one shared package import.
            if ($fieldType === 'inline-items') {
                $key = $field['key'] ?? $field['name'] ?? '';
                $componentName = $this->inlineItemsWrapperComponentName($key);
                $inlineItemsWrapperImports[] = "import {$componentName} from './{$componentName}.vue';";
            }

            if ($fieldType === 'select') {
                $hasSelect2 = true;
                $splashKey = $field['splashKey'] ?? null;
                if ($splashKey) {
                    $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                    $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                    $allSplash = array_merge($createSplash, $editSplash);

                    foreach ($allSplash as $splashItem) {
                        if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                            $hasApiSelect = true;
                            // Collect inline_create imports
                            if ($field['inline_create'] ?? false) {
                                $key = $field['key'] ?? $field['name'] ?? '';
                                $createModule = $field['create_form_module'] ?? Str::studly(str_replace('_id', '', $key));
                                $importSegment = PathManager::resolveFrontendImportSegment($createModule);
                                $inlineCreateImports[] = "import {$createModule}CreateForm from '@/pages/modules/{$importSegment}/Components/{$createModule}CreateForm.vue';";
                            }
                            break;
                        }
                    }
                }
            }
        }

        foreach ($fieldTypes as $type) {
            switch ($type) {
                case 'api-select':
                case 'api-select-inline':
                    // Direct api-select fields (field_type set to 'api-select' by IntrospectionToConfig)
                    $imports[] = "import ApiSelect2Field from '@/components/form-fields/ApiSelect2Field.vue';";
                    break;
                case 'select':
                    if ($hasApiSelect) {
                        $imports[] = "import ApiSelect2Field from '@/components/form-fields/ApiSelect2Field.vue';";
                    }
                    if ($hasSelect2) {
                        $imports[] = "import Select2Field from '@/components/form-fields/Select2Field.vue';";
                    }
                    break;
                case 'checkbox':
                case 'boolean':
                case 'toggle':
                case 'switch':
                    $imports[] = "import CheckboxField from '@/components/form-fields/CheckboxField.vue';";
                    break;
                case 'number-input':
                    $imports[] = "import NumberInputField from '@/components/form-fields/NumberInputField.vue';";
                    break;
                case 'item-picker':
                    $imports[] = "import { ItemPickerComponent } from '@/components/item-picker';";
                    break;
                case 'inline-items':
                    // No shared-package import here -- see $inlineItemsWrapperImports
                    // above, merged into $allImports below. Each field imports its
                    // own generated wrapper component instead of InlineItemsComponent
                    // directly.
                    break;
                case 'file-input':
                    // FileInputField.vue is the real component in SYSTEM_SHELL/FRONTEND
                    // (FileInputFieldWithCropper.vue does not exist there — it only ever
                    // existed in an unrelated legacy project). FileInputField.vue already
                    // has a built-in cropper (enableCrop/aspectRatio/cropShape props +
                    // its own ImageCropperModal), so no functionality is lost by importing it.
                    $imports[] = "import FileInputField from '@/components/form-fields/FileInputField.vue';";
                    break;
                case 'textarea':
                    $imports[] = "import TextAreaField from '@/components/form-fields/TextAreaField.vue';";
                    break;
                case 'date':
                case 'input':
                case 'email':
                case 'password':
                case 'number':
                default:
                    $imports[] = "import InputField from '@/components/form-fields/InputField.vue';";
                    break;
            }
        }

        // Merge inline create imports and inline-items wrapper imports (deduplicated)
        $allImports = array_unique(array_merge($imports, $inlineCreateImports, $inlineItemsWrapperImports));

        return implode("\n", $allImports);
    }

    protected function generateSplashData(array $config): string
    {
        $sections = $config['sections'] ?? [];
        $splashFields = [];

        $processFields = function($fields) use (&$splashFields, &$processFields) {
            foreach ($fields as $field) {
                if ($field['type'] === 'select') {
                    $options = $field['options'] ?? ($field['key'] ?? $field['name']) . 's';
                    $splashFields[] = "\t{$options}: []";
                } elseif (($field['field_type'] ?? '') === 'item-picker' && isset($field['availableItems'])) {
                    $splashFields[] = "\t{$field['availableItems']}: []";
                } elseif (($field['field_type'] ?? '') === 'inline-items' && isset($field['fields'])) {
                    // Recurse for inline-items fields
                    $processFields($field['fields']);
                }
            }
        };

        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
            $processFields($fields);
        } else {
            // New section-based structure
            foreach ($sections as $section) {
                $fields = $section['fields'] ?? [];
                $processFields($fields);
            }
        }

        return implode(",\n", array_unique($splashFields));
    }

    protected function hasSplashData(array $config): bool
    {
        $sections = $config['sections'] ?? [];

        $checkFields = function($fields) use (&$checkFields) {
            foreach ($fields as $field) {
                if ($field['type'] === 'select') {
                    return true;
                }
                if (($field['field_type'] ?? '') === 'item-picker' && isset($field['availableItems'])) {
                    return true;
                }
                if (($field['field_type'] ?? '') === 'inline-items' && isset($field['fields'])) {
                    if ($checkFields($field['fields'])) {
                        return true;
                    }
                }
            }
            return false;
        };

        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
            if ($checkFields($fields)) return true;
        } else {
            // New section-based structure
            foreach ($sections as $section) {
                $fields = $section['fields'] ?? [];
                if ($checkFields($fields)) return true;
            }
        }

        return false;
    }

    // View generation helper methods (shared across view generators)
    protected function mapViewFieldsToInformationFields(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $dataPath = $field['data'] ?? '';
            $label = $field['title'] ?? '';
            
            // Check if this is a relationship field (has dot notation like "district.name" or "item_category?.name")
            if (!empty($dataPath) && strpos($dataPath, '.') !== false) {
                // Clean the path - remove optional chaining operators for processing
                // "item_category?.name" -> "item_category.name"
                $cleanPath = str_replace('?.', '.', $dataPath);
                $cleanPath = str_replace('?', '', $cleanPath);
                
                // Split the clean path: "item_category.name" -> relationship: "item_category", displayField: "name"
                $parts = explode('.', $cleanPath);
                $relationship = $parts[0];
                $displayField = $parts[1] ?? 'name';
                
                // Generate label if not provided
                if (empty($label)) {
                    // Generate relationship label (remove _id suffix if present, convert to Title Case)
                    $relationshipLabel = $this->generateFieldLabel($relationship);
                    // For display field, just capitalize first letter (usually "name", "title", etc.)
                    $displayFieldLabel = ucfirst($displayField);
                    $label = $relationshipLabel . ' ' . $displayFieldLabel;
                }
                
                // Eloquent's relationsToArray() snake-cases relation keys in the
                // actual JSON response regardless of the camelCase relation method
                // name (e.g. an itemCategory() relation method surfaces as
                // "item_category" in the response) — normalize here so the
                // generated data path matches the real API response shape instead
                // of silently referencing a key that never exists.
                $relationshipKey = Str::snake($relationship);

                $mapped[] = [
                    'key' => $relationshipKey, // Use snake_cased relationship name as key
                    'label' => $label,
                    'type' => 'foreignKey',
                    'related_module' => $relationship,
                    'displayField' => $displayField,
                    'dataPath' => $relationshipKey . '.' . $displayField, // snake_cased to match Eloquent's relationsToArray() JSON keys
                ];
            } else {
                // Regular field (no dot notation)
                $key = $dataPath ?: 'name';
                if (empty($label)) {
                    $label = $this->generateFieldLabel($key);
                }
                
                // Check if it's a boolean field
                $type = $field['type'] ?? 'text';
                if ($type === 'boolean' || strpos(strtolower($key), 'is_') === 0) {
                    $type = 'boolean';
                }
                
                $mapped[] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => $type,
                ];
            }
        }
        return $mapped;
    }

    protected function generateViewSections(array $config): string
    {
        $sections = $config['sections'] ?? [
            [
                'key' => 'information',
                'title' => 'Overview',
                'fields' => $config['columns'] ?? []
            ]
        ];
        $sectionContent = [];

        foreach ($sections as $section) {
            $key = $section['key'] ?? '';
            $title = $section['title'] ?? '';
            $icon = $section['icon'] ?? 'InfoIcon';
            $fields = $section['fields'] ?? [];
            $groups = $section['groups'] ?? [];
            $sectionContent[] = $this->generateInformationSection($title, $icon, $fields, $groups);
        }

        return implode("\n\n", $sectionContent);
    }

    /**
     * Render one field's info row -- shared between the flat single-list
     * layout and each column of a grouped layout (see generateInformationSection())
     * so both paths produce byte-identical row markup for the same field.
     *
     * @return string[]
     */
    private function generateInformationRows(array $fields): array
    {
        $rows = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $label = $field['label'] ?? '';
            $type = $field['type'] ?? 'text';

            if ($type === 'foreignKey') {
                $relationship = $field['related_module'] ?? $field['relationship'] ?? '';
                $displayField = $field['displayField'] ?? 'name';

                if (!empty($field['dataPath'])) {
                    $dataPath = $field['dataPath'];
                    $vueExpression = 'data?.' . str_replace('.', '?.', $dataPath);
                    $valueHtml = "{{ {$vueExpression} || 'N/A' }}";
                } elseif (!empty($relationship)) {
                    $relationshipSnake = Str::snake($relationship);
                    $valueHtml = "{{ data?.{$relationshipSnake}?.{$displayField} || 'N/A' }}";
                } else {
                    $valueHtml = "{{ data?.{$key} || 'N/A' }}";
                }

                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right\">{$valueHtml}</span>\n\t\t\t\t</div>";
            } elseif ($type === 'boolean') {
                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right\" :class=\"{'text-green-500': data?.{$key}, 'text-red-500': !data?.{$key}}\">{{ data?.{$key} ? 'Yes' : 'No' }}</span>\n\t\t\t\t</div>";
            } else {
                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right\">{{ data?.{$key} || 'N/A' }}</span>\n\t\t\t\t</div>";
            }
        }

        return $rows;
    }

    /**
     * @param array $groups  Optional. When non-empty, each entry is
     *                       ['fields' => [...]] and renders as its own
     *                       divided column inside ONE Card instead of the
     *                       default single stacked field list -- e.g. a
     *                       3-column grouped info panel like ONGEZA_PRO_SYSTEM's
     *                       BudgetExpensesDetailsOverviewPage.vue. $fields is
     *                       ignored when $groups is supplied. Omitted/empty
     *                       (the default) produces byte-identical output to
     *                       before this parameter existed.
     */
    protected function generateInformationSection(string $title, string $icon, array $fields, array $groups = []): string
    {
        if (!empty($groups)) {
            $columns = [];
            foreach ($groups as $group) {
                $rowsContent = implode("\n", $this->generateInformationRows($group['fields'] ?? []));
                $columns[] = "\t\t\t\t<div>\n{$rowsContent}\n\t\t\t\t</div>";
            }
            $columnCount = count($groups);
            $columnsContent = implode("\n", $columns);

            return "<Card class=\"gap-0 overflow-hidden p-0\">\n\t\t\t<div class=\"px-4 py-3 border-b\">\n\t\t\t\t<span class=\"text-sm font-semibold\">{$title}</span>\n\t\t\t</div>\n\t\t\t<CardContent class=\"p-0\">\n\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-{$columnCount} divide-y md:divide-y-0 md:divide-x\">\n{$columnsContent}\n\t\t\t\t</div>\n\t\t\t</CardContent>\n\t\t</Card>";
        }

        $rowsContent = implode("\n", $this->generateInformationRows($fields));

        return "<Card class=\"gap-0 overflow-hidden p-0\">\n\t\t\t<div class=\"px-4 py-3 border-b\">\n\t\t\t\t<span class=\"text-sm font-semibold\">{$title}</span>\n\t\t\t</div>\n\t\t\t<CardContent class=\"p-0\">\n\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-2\">\n{$rowsContent}\n\t\t\t\t</div>\n\t\t\t</CardContent>\n\t\t</Card>";
    }

    protected function generateFormatDateImport(array $config): string
    {
        return "import { formatDate } from '@/helpers'";
    }

    protected function generateHeaderBadges(array $config): string
    {
        $headerConfig = $config['features']['frontend']['view']['header'] ?? [];
        $badges = $headerConfig['badges'] ?? [];

        // Auto-detect common status/type fields if no badges configured
        if (empty($badges)) {
            $viewConfig = $config['features']['frontend']['view'] ?? [];
            $fields = $viewConfig['fields'] ?? [];
            
            foreach ($fields as $field) {
                $key = $field['data'] ?? '';
                $lastSegment = $key ? preg_replace('/^.*\./', '', $key) : '';
                
                // Check for status or type fields
                if (str_contains($lastSegment, 'status') || str_contains($lastSegment, 'type')) {
                    $badges[] = [
                        'data' => $key,
                        'type' => 'relationship', // Assume relationship/object for complex fields
                        'icon' => str_contains($lastSegment, 'type') ? 'TagIcon' : 'InfoIcon',
                        'showColor' => false
                    ];
                }
            }
        }

        if (empty($badges)) {
            return '';
        }

        $badgeContent = [];
        foreach ($badges as $badge) {
            $type = $badge['type'] ?? 'text';
            $icon = $badge['icon'] ?? null;
            $showColor = $badge['showColor'] ?? false;

            if ($type === 'relationship') {
                // New structure: relationship and displayPath
                $relationship = $badge['relationship'] ?? '';
                $displayPath = $badge['displayPath'] ?? 'name';
                
                // Fallback to data field if relationship not set (backward compatibility)
                if (empty($relationship) && !empty($badge['data'])) {
                    // Parse old format: "country?.name" -> relationship: "country", displayPath: "name"
                    $parts = preg_replace('/\\?/', '', $badge['data']);
                    $segments = explode('.', $parts);
                    $relationship = $segments[0] ?? '';
                    $displayPath = $segments[1] ?? 'name';
                }
                
                $badgeContent[] = $this->generateRelationshipBadge($relationship, $displayPath, $icon, $showColor);
            } else {
                $field = $badge['data'] ?? '';
                $badgeContent[] = $this->generateTextBadge($field, $icon);
            }
        }

        return implode("\n\t\t\t\t", $badgeContent);
    }

    protected function generateBadgeImport(array $config): string
    {
        $view = $config['features']['frontend']['view'] ?? [];
        $headerConfig = $view['header'] ?? [];
        $badges = $headerConfig['badges'] ?? [];

        if (empty($badges)) {
            return '';
        }

        return "import { Badge } from '@/components/ui/badge'";
    }

    protected function generateRelationshipBadge(string $relationship, string $displayPath, ?string $icon, bool $showColor): string
    {
        $iconComponent = $icon ? "<component :is=\"icons.{$icon}\" class=\"h-3 w-3\" />" : '';
        
        // Color uses relationship.color for styling
        $colorStyle = $showColor ? ":style=\"data?.{$relationship}?.color ? `border-color: \${data?.{$relationship}.color}; background-color: \${data?.{$relationship}.color}20;` : ''\"" : '';
        $colorIndicator = $showColor ? "<span class=\"h-2 w-2 rounded-full\" :style=\"data?.{$relationship}?.color ? `background-color: \${data?.{$relationship}.color}` : ''\"></span>" : '';

        // Display uses relationship.displayPath
        $displayValue = "{{ data?.{$relationship}?.{$displayPath} }}";

        return "<Badge v-if=\"data?.{$relationship}\"
\t\t\t\tvariant=\"outline\"
\t\t\t\tclass=\"px-3 py-1 flex items-center gap-1\"
\t\t\t\t{$colorStyle}
\t\t\t>
\t\t\t\t{$iconComponent}
\t\t\t\t{$colorIndicator}
\t\t\t\t{$displayValue}
\t\t\t</Badge>";
    }

    protected function generateTextBadge(string $field, ?string $icon): string
    {
        $iconComponent = $icon ? "<component :is=\"icons.{$icon}\" class=\"h-3 w-3\" />" : '';

        return "<Badge v-if=\"data?.{$field}\"
\t\t\t\tvariant=\"outline\"
\t\t\t\tclass=\"px-3 py-1 flex items-center gap-1\"
\t\t\t>
\t\t\t\t{$iconComponent}
\t\t\t\t{{ data?.{$field} }}
\t\t\t</Badge>";
    }

    protected function generateHeaderActions(array $config): string
    {
        $headerConfig = $config['features']['frontend']['view']['header'] ?? [];
        $customActions = $headerConfig['customActions'] ?? [];

        $actionContent = [];

        // Add configured custom actions
        foreach ($customActions as $action) {
            $type = $action['type'] ?? 'button';
            $component = $action['component'] ?? '';
            $label = $action['label'] ?? 'Action';
            $props = $action['props'] ?? [];

            if ($type === 'modal') {
                $propsString = implode(' ', array_map(fn($prop) => ":{$prop}=\"{$prop}\"", $props));
                $actionContent[] = "<{$component} {$propsString} />";
            } else {
                $actionContent[] = "<Button size=\"sm\" variant=\"outline\" class=\"h-6 px-2 flex items-center gap-1\">
\t\t\t\t\t{$label}
\t\t\t\t</Button>";
            }
        }

        // Add delegations as header actions (only modal type)
        $customFeatures = $config['delegations'] ?? [];
        foreach ($customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'modal' || $uiType === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $label = $customFeature['label'] ?? $featureName;
                $icon = $customFeature['icon'] ?? 'ListIcon';
                $componentName = $this->moduleName . $featureName . 'Modal';
                $modalStateVar = 'show' . $featureName . 'Modal';

                $actionContent[] = "<Button size=\"sm\" variant=\"outline\" class=\"h-6 px-2 flex items-center gap-1\" @click=\"{$modalStateVar} = true\">
\t\t\t\t\t<component :is=\"icons['{$icon}']\" class=\"h-3.5 w-3.5\" />
\t\t\t\t\t<span>{$label}</span>
\t\t\t\t</Button>
\t\t\t\t<{$componentName} :is-open=\"{$modalStateVar}\" @update:open=\"{$modalStateVar} = \$event\" :parent-uuid=\"recordId\" />";
            }
        }

        if (empty($actionContent)) {
            return '';
        }

        return implode("\n\t\t\t\t", $actionContent);
    }

    protected function generateTabNavigation(array $config): string
    {
        $viewConfig = $config['features']['frontend']['view'] ?? [];
        $tabs = $viewConfig['tabs'] ?? [];

        $tabContent = [];

        // Always add the default overview tab first
        $tabContent[] = "\t{ id: 'overview', label: 'Overview', icon: icons.BookOpenIcon }";
        $tabContent[] = "\t{ id: 'history', label: 'History', icon: icons.HistoryIcon }";

        // Add configured tabs
        foreach ($tabs as $tab) {
            $id = $tab['id'] ?? '';
            $label = $tab['label'] ?? '';
            $icon = $tab['icon'] ?? 'BookOpenIcon';

            $tabContent[] = "\t{ id: '{$id}', label: '{$label}', icon: icons.{$icon} }";
        }

        // Add delegations as tabs (only tab type)
        $customFeatures = $config['delegations'] ?? [];
        foreach ($customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                $rawId = $customFeature['name'] ?? $featureKey;

                // MUST match FrontendRoutesGenerator, which registers the child
                // route as Str::kebab($customFeature['name']). details_layout.stub
                // links each tab to `.../details/${tab.id}`, so a StudlyCase id
                // here produced `/details/ScratchItems` against a route declared
                // as `scratch-items`. Vue Router paths are case-sensitive, so the
                // link matched nothing: every delegation tab rendered in the nav
                // and went nowhere when clicked. The built-in overview/history
                // tabs were unaffected because their ids already equal their paths.
                $id = Str::kebab($rawId);
                $label = $customFeature['label'] ?? ucfirst($rawId);
                $icon = $customFeature['icon'] ?? 'ListIcon';

                $tabContent[] = "\t{ id: '{$id}', label: '{$label}', icon: icons.{$icon} }";
            }
        }

        return implode(",\n", $tabContent);
    }

    protected function generateCustomFeatureImports(array $config): string
    {
        $imports = [];
        $customFeatures = $config['delegations'] ?? [];

        foreach ($customFeatures as $featureKey => $customFeature) {
            if (($customFeature['displayType'] ?? '') === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $componentName = $this->moduleName . $featureName . 'Modal';
                $imports[] = "import {$componentName} from './{$componentName}.vue'";
            }
        }

        return !empty($imports) ? implode("\n", $imports) : '';
    }

    protected function generateCustomFeatureModalStates(array $config): string
    {
        $states = [];
        $customFeatures = $config['delegations'] ?? [];

        foreach ($customFeatures as $featureKey => $customFeature) {
            if (($customFeature['displayType'] ?? '') === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $modalStateVar = 'show' . $featureName . 'Modal';
                $states[] = "const {$modalStateVar} = ref(false)";
            }
        }

        return !empty($states) ? implode("\n", $states) : '';
    }

    /**
     * Generate fields from columns when fields array is empty
     * This is a fallback to ensure forms have fields even if they weren't explicitly configured
     */
    protected function generateFieldsFromColumns(array $config, string $featureType = 'create'): array
    {
        $columns = $config['columns'] ?? [];
        $fields = [];

        foreach ($columns as $column) {
            // Skip system columns
            if (in_array($column['name'] ?? '', ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $columnName = $column['name'] ?? '';
            if (empty($columnName)) {
                continue;
            }

            $columnType = $column['type'] ?? 'string';
            $nullable = $column['nullable'] ?? false;

            // Determine field type based on column type
            $type = 'text';
            $fieldType = 'input';
            $splashKey = '';
            $decimals = null;

            if ($columnType === 'foreignId') {
                $type = 'text';
                $fieldType = 'select';
                // splashData[].key is conventionally snake_case plural (e.g. "accounts", "order_items")
                // not the PascalCase module name. Normalize here so the field's splashKey
                // actually matches the splashData entry the splash-service generator emits.
                $related = $column['relatedModule'] ?? '';
                $splashKey = $related ? Str::snake(Str::plural($related)) : '';
            } elseif ($columnType === 'date' || $columnType === 'datetime' || $columnType === 'timestamp') {
                $type = 'date';
                $fieldType = 'date';
            } elseif (in_array($columnType, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger'])) {
                $type = 'number';
                $fieldType = 'number-input';
                $decimals = 0;
            } elseif (in_array($columnType, ['float', 'double', 'decimal'])) {
                $type = 'number';
                $fieldType = 'number-input';
                $decimals = 2;
            } elseif ($columnType === 'boolean') {
                $type = 'boolean';
                $fieldType = 'checkbox';
            } elseif (in_array($columnType, ['text', 'longText', 'mediumText'])) {
                $type = 'text';
                $fieldType = 'textarea';
            }

            $fieldLabel = $this->generateFieldLabel($columnName);
            $field = [
                'field' => $columnName,
                'label' => $fieldLabel,
                'placeholder' => "Enter {$fieldLabel}",
                'type' => $type,
                'field_type' => $fieldType,
                'splashKey' => $splashKey,
                'required' => !$nullable,
            ];

            if ($decimals !== null) {
                $field['decimals'] = $decimals;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Build the four splash code blocks for a form stub.
     *
     * Returns an array of [splashPropBlock, splashBlock, refreshAndSetBlock, onMountedBlock].
     *
     * When $hasSplash is false every block is an empty string (or a no-op onMounted line),
     * so the generated Vue file has zero splash artefacts.
     *
     * When $hasSplash is true the full splash plumbing is returned, keyed to the given
     * $formType ('create' or 'edit').
     *
     * @param string $formType  'create' or 'edit'
     * @param bool   $hasSplash Whether constants are declared in the module config
     * @return array{string, string, string, string}
     */
    protected function buildSplashBlocks(string $formType, bool $hasSplash): array
    {
        if (!$hasSplash) {
            // No splash: prop omitted, no computed endpoint, no ref, no refreshAndSet,
            // onMounted just sets isLoading = false directly.
            $splashPropBlock    = '';
            $splashBlock        = '';
            $refreshAndSetBlock = <<<'TS'
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    isLoading.value = false
}
TS;
            $onMountedBlock     = 'await refreshAndSet()';

            return [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock];
        }

        // With splash
        $splashPropBlock = "splashUrl: {default: null},\n\t";

        // Resolve moduleRoute eagerly here — this string is used as a replacement value
        // inside replacePlaceholders() so [[moduleRoute]] inside it won't be processed again.
        $moduleRoute = Str::kebab($this->moduleName);
        $endpoint = $formType === 'edit'
            ? "props.splashUrl || '/{$moduleRoute}/edit/splash'"
            : "props.splashUrl || '/{$moduleRoute}/create/splash'";

        $splashBlock = <<<TS
const splashEndpoint = computed(() => {
\treturn {$endpoint}
})

// Options loaded from splash endpoint
const splash = ref<Record<string, any>>({})
const hasSplash = true

TS;

        $refreshAndSetBlock = <<<'TS'
// Use this to build external data for selection while you are still on this page.
// Special use case in select fields.
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    isLoading.value = true
    const response = await sendGetRequest(splashEndpoint.value)
    if (response.status) {
        splash.value = response.data
        if (key != null && value != null) (form.value as any)[key] = value
    } else {
        toast.error('Failed to load form data. Please refresh the page.')
    }
    isLoading.value = false
}
TS;

        $onMountedBlock = 'await refreshAndSet()';

        return [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock];
    }

    // ─── Inline Items helpers ─────────────────────────────────────────────────

    /**
     * Generate the wrapper-component Card block for each top-level
     * `inline_items` entry (this package's documented parent-child pattern,
     * e.g. Order Items -- see README.md's `inline_items` shape). Replaces
     * [[inlineItemsBlock]] in the form stub.
     *
     * Bug (fixed 2026-08-02): this used to bind <InlineItemsComponent>
     * directly with an inline `:fields="{ref}"`, where {ref} was a const
     * array declared elsewhere in the SAME generated form file (see the old
     * generateInlineItemsFieldDefs()) -- there was nowhere for a module to
     * add dynamicDisabled/showField/render hooks or listen to
     * @item-change/@field-change without hand-editing generated code that
     * regeneration would later clobber. Fix: each item now gets its own
     * hand-edit-protected wrapper component (see
     * writeInlineItemsWrapperComponent()), same as the field_type:
     * 'inline-items' case in generateField().
     */
    protected function generateInlineItemsBlock(array $inlineItems): string
    {
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key          = $item['key'];
            $label        = $item['label'] ?? ucwords(str_replace('_', ' ', $key));
            $primaryField = $item['primary_field'];
            $modalSize    = $item['modal_size'] ?? 'md';
            $modalColumns = (int) ($item['modal_columns'] ?? 1);
            $addBtnText   = addslashes($item['add_button_text'] ?? 'Add Item');
            $addTitle     = addslashes($item['add_modal_title'] ?? 'Add Item');
            $editTitle    = addslashes($item['edit_modal_title'] ?? 'Edit Item');

            $componentName = $this->writeInlineItemsWrapperComponent(
                $key,
                $this->buildInlineItemFieldsJs($item['fields'] ?? [])
            );

            $blocks[] = <<<VUE

		<!-- {$label} -->
		<Card class="mt-2">
			<CardContent class="pt-4">
				<p class="text-sm font-semibold text-foreground mb-3">{$label}</p>
				<{$componentName}
					v-model="form.{$key}"
					primary-field="{$primaryField}"
					add-button-text="{$addBtnText}"
					add-modal-title="{$addTitle}"
					edit-modal-title="{$editTitle}"
					modal-size="{$modalSize}"
					:modal-columns="{$modalColumns}"
				/>
			</CardContent>
		</Card>
VUE;
        }

        return implode('', $blocks);
    }

    /**
     * Build the `[{ key: '...', ... }, ...]` JS array literal body shared by
     * generateInlineItemsBlock() (each item's wrapper component) -- extracted
     * from the old generateInlineItemsFieldDefs(), which used to declare this
     * same array as a bare `const {ref}: InlineItemField[] = [...]` directly
     * inside the generated form file. `inline_items` config fields use
     * snake_case keys (splash_key, api_url, table_width, show_in_table,
     * col_span) -- a different convention than the camelCase
     * processInlineItemsFields() uses for the field_type: 'inline-items'
     * case, so this is deliberately a separate builder, not a shared one.
     */
    private function buildInlineItemFieldsJs(array $fields): string
    {
        $fieldLines = [];

        foreach ($fields as $field) {
            $parts   = [];
            $parts[] = "key: '{$field['key']}'";
            $parts[] = "label: '{$field['label']}'";
            $parts[] = "type: '{$field['type']}'";

            if (!empty($field['required']))      $parts[] = 'required: true';
            if (!empty($field['splash_key']))     $parts[] = "splashKey: '{$field['splash_key']}'";
            if (!empty($field['api_url']))        $parts[] = "apiUrl: '{$field['api_url']}'";
            if (isset($field['decimals']))        $parts[] = "decimals: {$field['decimals']}";
            if (!empty($field['table_width']))    $parts[] = "tableWidth: '{$field['table_width']}'";
            if (isset($field['show_in_table']) && !$field['show_in_table']) $parts[] = 'showInTable: false';
            if (!empty($field['col_span']))       $parts[] = "colSpan: {$field['col_span']}";
            if (!empty($field['placeholder']))    $parts[] = "placeholder: '{$field['placeholder']}'";

            $fieldLines[] = "\t{ " . implode(', ', $parts) . " },";
        }

        return "[\n" . implode("\n", $fieldLines) . "\n]";
    }

    /**
     * Replaces [[inlineItemsFieldDefs]] in the form stub. Always empty now:
     * each item's field list lives inside its own wrapper component (written
     * by generateInlineItemsBlock() as a side effect) instead of being
     * declared inline in the generated form file -- see this method's
     * docblock history in generateInlineItemsBlock() for why. Kept (rather
     * than removing the placeholder from create/form.stub and edit/form.stub
     * outright) so neither stub needs touching for this change.
     */
    protected function generateInlineItemsFieldDefs(array $inlineItems): string
    {
        return '';
    }
}

