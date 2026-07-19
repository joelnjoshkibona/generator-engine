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
                $columns[] = "{ key: \"{$key}\", label: t('{$i18nKey}'), sortable: {$sortableStr}, width: 150 }";
            }
        }

        // Add ID column (use id or uuid based on id_type)
        $idType = $this->config['id_type'] ?? 'autoincrement';
        $idField = ($idType === 'uuid') ? 'uuid' : 'id';
        $columns[] = "{ key: \"{$idField}\", label: \"ID\", sortable: false, width: 100 }";

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
            $columns[] = "{ key: \"actions\", label: \"\", width: 120, align: 'right' }";
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

    protected function generatePrimaryCellContentFromListFields(array $fields, ?string $primaryKey = null): string
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
        $content[] = "<span class=\"font-bolder\">{{ item.{$primaryDisplayPath} }}</span>";

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
\t\t\t\t{{ \$t('{$moduleRoute}.col_{$key}') }}: {{ item.{$dataPath} || 'N/A' }}
\t\t\t</div>";
        }

        return implode("\n\t\t\t", $content);
    }

    protected function generateCustomCellRenderersFromListFields(array $fields, $primaryKey): string
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

            // Generate custom cell renderer for badge and boolean types
            if ($type === 'badge' || $type === 'boolean') {
                // Check if it's a relationship field (has dot notation)
                $isRelationship = strpos($dataPath, '.') !== false;

                if ($isRelationship) {
                    // Relationship badge (e.g., status.name)
                    $parts = explode('.', $dataPath);
                    $relationship = $parts[0];
                    $fieldName = $parts[1] ?? 'name';

                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}='{ item }'>\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"item.{$relationship}.{$fieldName} === 'Active' || item.{$relationship}.{$fieldName} === 1 || item.{$relationship}.{$fieldName} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'\">\n";
                    $renderer .= "\t\t\t\t{{ item.{$dataPath} || 'N/A' }}\n";
                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                } else {
                    // Direct field badge (e.g., is_active)
                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}='{ item }'>\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"item.{$key} === 'Active' || item.{$key} === 1 || item.{$key} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'\">\n";

                    // For boolean, show Yes/No or Active/Inactive
                    if ($type === 'boolean') {
                        $renderer .= "\t\t\t\t{{ item.{$key} ? 'Yes' : 'No' }}\n";
                    } else {
                        $renderer .= "\t\t\t\t{{ item.{$key} || 'N/A' }}\n";
                    }

                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                }

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
                'label' => $label,
                'placeholder' => $placeholder,
                'required' => $required,
                'splashKey' => $field['splashKey'] ?? null,
                'options' => $field['splashKey'] ?? Str::plural($key),
            ];

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
                 . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
                 . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t\t<Button type=\"submit\" size=\"sm\" :disabled=\"isSubmitting\">\n"
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
             . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
             . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t\t<Button type=\"submit\" size=\"sm\" :disabled=\"isSubmitting\">\n"
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
        // We need to escape single quotes if we use them in the template attribute
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
            // Process inline-items fields to ensure all properties are included (especially readonly)
            $processedFields = $this->processInlineItemsFields($field['fields'] ?? []);
            $replacements['[[fields]]'] = $this->arrayToJsObjectString($processedFields);
            $replacements['[[primaryField]]'] = $field['primaryField'] ?? 'name';
            $replacements['[[colorScheme]]'] = $field['colorScheme'] ?? 'blue';
            $replacements['[[addButtonText]]'] = $field['addButtonText'] ?? 'Add Item';
            $replacements['[[emptyMessage]]'] = $field['emptyMessage'] ?? 'No items added';
        } elseif ($fieldType === 'file-input') {
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
            $key = $field['key'] ?? $field['name'];
            $defaultValue = $this->getFieldDefaultValue($field);
            $fieldDefinitions[] = "  {$key}: {$defaultValue}";
        }

        return implode(",\n", $fieldDefinitions);
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

        switch ($type) {
            case 'boolean':
                return 'false';
            case 'select':
            case 'number':
                return "'' as string | number";
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

        // Determine which select components are needed: ApiSelect2 for model sources, Select2 for custom/static
        foreach ($fields as $field) {
            $fieldType = $field['field_type'] ?? $field['type'] ?? '';
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
                    $imports[] = "import { InlineItemsComponent } from '@/components/inline-items';";
                    break;
                case 'file-input':
                    $imports[] = "import FileInputFieldWithCropper from '@/components/form-fields/FileInputFieldWithCropper.vue';";
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

        // Merge inline create imports (deduplicated)
        $allImports = array_unique(array_merge($imports, $inlineCreateImports));

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
                
                $mapped[] = [
                    'key' => $relationship, // Use relationship name as key
                    'label' => $label,
                    'type' => 'foreignKey',
                    'related_module' => $relationship,
                    'displayField' => $displayField,
                    'dataPath' => $cleanPath, // Store clean path without ? for processing
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
            $sectionContent[] = $this->generateInformationSection($title, $icon, $fields);
        }

        return implode("\n\n", $sectionContent);
    }

    protected function generateInformationSection(string $title, string $icon, array $fields): string
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

        $rowsContent = implode("\n", $rows);

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
                $id = $customFeature['name'] ?? $featureKey;
                $label = $customFeature['label'] ?? ucfirst($id);
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
     * Generate the <InlineItemsComponent> Card block for each inline item.
     * Replaces [[inlineItemsBlock]] in the form stub.
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
            $fieldsRef    = $this->inlineFieldsRefName($key);
            $modalSize    = $item['modal_size'] ?? 'md';
            $modalColumns = (int) ($item['modal_columns'] ?? 1);
            $addBtnText   = addslashes($item['add_button_text'] ?? 'Add Item');
            $addTitle     = addslashes($item['add_modal_title'] ?? 'Add Item');
            $editTitle    = addslashes($item['edit_modal_title'] ?? 'Edit Item');

            $blocks[] = <<<VUE

		<!-- {$label} -->
		<Card class="mt-2">
			<CardContent class="pt-4">
				<p class="text-sm font-semibold text-foreground mb-3">{$label}</p>
				<InlineItemsComponent
					v-model="form.{$key}"
					primary-field="{$primaryField}"
					:fields="{$fieldsRef}"
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
     * Generate the TypeScript const field-definition arrays.
     * Replaces [[inlineItemsFieldDefs]] in the form stub.
     */
    protected function generateInlineItemsFieldDefs(array $inlineItems): string
    {
        if (empty($inlineItems)) {
            return '';
        }

        $defs = [];
        foreach ($inlineItems as $item) {
            $fieldsRef  = $this->inlineFieldsRefName($item['key']);
            $fieldLines = [];

            foreach ($item['fields'] ?? [] as $field) {
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

            $defs[] = "const {$fieldsRef}: InlineItemField[] = [\n" . implode("\n", $fieldLines) . "\n]\n";
        }

        return implode("\n", $defs);
    }

    /** camelCase ref name for a field-defs array: 'line_items' → 'lineItemsFields' */
    private function inlineFieldsRefName(string $key): string
    {
        $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
        return $camel . 'Fields';
    }
}

