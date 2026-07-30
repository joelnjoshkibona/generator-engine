<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Validation\ValidationGenerator;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

abstract class BaseServiceGenerator extends BaseGenerator
{
    protected function generateFilterableFields(): string
    {
        // "id" and "uuid" are always backend-filterable, regardless of config:
        // "id" backs the standard first/sortable/filterable ID column every generated
        // list gets (see BaseComponentGenerator::generateColumnsFromListFields());
        // "uuid" is filterable-only — it stays out of generateFilterFields()'s
        // frontend-facing output and out of the visible columns array entirely,
        // so it's queryable via the API (?filters[uuid]=...) without ever showing
        // a column or a filter control for it ("hidden but filterable").
        $fields = $this->appendSystemFields($this->collectConfiguredFilterableFields(), ['id', 'uuid']);

        return $this->fieldsToArrayLiteral($fields);
    }

    protected function generateSortableFields(): string
    {
        // Use sortableFields from list configuration
        if (isset($this->config['features']['backend']['list']['sortableFields'])) {
            $sortableFields = $this->config['features']['backend']['list']['sortableFields'];

            // Handle comma-separated string
            if (is_string($sortableFields)) {
                $fields = array_map('trim', explode(',', $sortableFields));
            } elseif (is_array($sortableFields)) {
                // Handle array
                $fields = array_values($sortableFields);
            } else {
                $fields = [];
            }
        } else {
            // Fallback to filterableFields if sortableFields not specified
            $fields = $this->collectConfiguredFilterableFields();
        }

        // "id" is always sortable — it's the standard first, pinned column every
        // generated list gets. "uuid" is deliberately NOT added here: per the
        // spec it's filterable-only (see generateFilterableFields()) and never a
        // sortable/visible column.
        $fields = $this->appendSystemFields($fields, ['id']);

        return $this->fieldsToArrayLiteral($fields);
    }

    /**
     * Extract the raw, pre-system-field filterable field keys: feature-specific
     * filterFields if present, else filterableFields (array or comma-separated
     * string), else empty. Shared by generateFilterableFields() and
     * generateSortableFields()'s fallback branch — neither "id" nor "uuid" is
     * added here; callers append whichever system fields they need.
     *
     * @return string[]
     */
    private function collectConfiguredFilterableFields(): array
    {
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];

        if (!empty($filterFields)) {
            $fields = [];
            foreach ($filterFields as $field) {
                $key = $field['key'] ?? '';
                if ($key !== '') {
                    $fields[] = $key;
                }
            }
            return $fields;
        }

        if (isset($this->config['features']['backend']['list']['filterableFields'])) {
            $filterableFields = $this->config['features']['backend']['list']['filterableFields'];
            if (is_array($filterableFields)) {
                return array_map(fn($field) => trim($field), $filterableFields);
            }
            return array_map('trim', explode(',', $filterableFields));
        }

        return [];
    }

    /**
     * Append any of $systemFields not already present in $fields, preserving order.
     *
     * @param string[] $fields
     * @param string[] $systemFields
     * @return string[]
     */
    private function appendSystemFields(array $fields, array $systemFields): array
    {
        foreach ($systemFields as $systemField) {
            if (!in_array($systemField, $fields, true)) {
                $fields[] = $systemField;
            }
        }
        return $fields;
    }

    /**
     * Render a plain string field-key list as a PHP array literal, e.g. ['name', 'id'].
     *
     * @param string[] $fields
     */
    private function fieldsToArrayLiteral(array $fields): string
    {
        if (empty($fields)) {
            return '[]';
        }
        return '[' . implode(', ', array_map(fn($field) => "'" . trim((string) $field) . "'", $fields)) . ']';
    }

    protected function generateEagerLoadRelationships($feature): string
    {
        // 'creator'/'updater' are only real relationships when the model
        // actually has created_by_id/updated_by_id columns -- appending them
        // unconditionally threw a RelationNotFoundException for every module
        // without has_creator_updater. ModuleConfigContract::hasCreatorUpdater()
        // is the single sanctioned place to read this fact (see its own
        // docblock) rather than re-deriving it here.
        $creatorUpdater = ModuleConfigContract::hasCreatorUpdater($this->config)
            ? ["'creator'", "'updater'"]
            : [];

        // Check if custom configuration exists
        if (isset($this->config['features']['backend'][$feature]['eagerLoadRelationships'])) {
            $eagerLoadConfig = $this->config['features']['backend'][$feature]['eagerLoadRelationships'];

            // Handle comma-separated string
            if (is_string($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', explode(',', $eagerLoadConfig)),
                    fn($rel) => !empty($rel)
                );
                $relationships = array_merge(
                    array_map(fn($rel) => "'{$rel}'", $customRelationships),
                    $creatorUpdater
                );
                return empty($relationships) ? '[]' : '[' . implode(', ', $relationships) . ']';
            }

            // Handle array format
            if (is_array($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', $eagerLoadConfig),
                    fn($rel) => !empty($rel)
                );
                $relationships = array_merge(
                    array_map(fn($rel) => "'{$rel}'", $customRelationships),
                    $creatorUpdater
                );
                return empty($relationships) ? '[]' : '[' . implode(', ', $relationships) . ']';
            }
        }

        return empty($creatorUpdater) ? '[]' : '[' . implode(', ', $creatorUpdater) . ']';
    }

    protected function generateFilterableRelationships(): string
    {
        // Use filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        
        $filterableRelationships = [];

        // Extract relationships from filterFields that are foreign keys
        foreach ($filterFields as $field) {
            $fieldName = $field['key'] ?? '';
            if ($this->isForeignKey($fieldName, $field)) {
                $relationshipName = str_replace('_id', '', $fieldName);
                $filterableRelationships[$relationshipName] = ['id', 'name'];
            }
        }
        
        // Also check filterableRelationships if explicitly configured
        if (isset($this->config['features']['backend']['list']['filterableRelationships'])) {
            $explicitRelationships = $this->config['features']['backend']['list']['filterableRelationships'];
            if (is_array($explicitRelationships) && !empty($explicitRelationships)) {
                foreach ($explicitRelationships as $rel => $fields) {
                    $filterableRelationships[$rel] = is_array($fields) ? $fields : ['id', 'name'];
                }
            }
        }

        if (empty($filterableRelationships)) {
            return '[]';
        }

        $relationshipConfigs = [];
        foreach ($filterableRelationships as $relationship => $fields) {
            $fieldStrings = array_map(fn($field) => "'{$field}'", $fields);
            $relationshipConfigs[] = "'{$relationship}' => [" . implode(', ', $fieldStrings) . "]";
        }

        return '[' . implode(', ', $relationshipConfigs) . ']';
    }

    protected function generateFilterFields(): string
    {
        // Use feature-specific filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];

        // Fallback: derive plain text filters from filterableFields so the DataTableFilter
        // has fields to render. Without this, introspected modules emit an empty array and
        // show no filter UI at all. FK/select refinements remain a manual enhancement.
        if (empty($filterFields)) {
            $filterable = $this->config['features']['backend']['list']['filterableFields'] ?? [];
            if (is_string($filterable)) {
                $filterable = array_filter(array_map('trim', explode(',', $filterable)));
            }
            foreach ($filterable as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $cleanKey = preg_replace(['/_id$/', '/_at$/'], '', $name);
                $filterFields[] = [
                    'key'   => $name,
                    'label' => ucwords(str_replace('_', ' ', $cleanKey)),
                    'type'  => 'text',
                ];
            }
        }

        // "id" always gets a frontend filter control, matching its sortable+filterable
        // status as the standard first column (see
        // BaseComponentGenerator::generateColumnsFromListFields()). "uuid" is
        // deliberately NOT added here — it's backend-filterable only (see
        // generateFilterableFields()) and, per spec, never shown as a visible column
        // or a user-facing filter control ("hidden but filterable").
        $hasIdFilter = false;
        foreach ($filterFields as $field) {
            if (($field['key'] ?? '') === 'id') {
                $hasIdFilter = true;
                break;
            }
        }
        if (!$hasIdFilter) {
            $filterFields[] = ['key' => 'id', 'label' => 'ID', 'type' => 'text'];
        }

        if (empty($filterFields)) {
            return '[]'; // Return empty if no filter fields configured
        }

        $fields = [];
        foreach ($filterFields as $field) {
            $fields[] = $this->formatFilterField($field);
        }

        return '[' . implode(",\n\t\t\t", $fields) . ']';
    }

    protected function formatFilterField(array $field): string
    {
        $formattedField = $field;

        // Handle custom_select type
        if ($field['type'] === 'custom_select') {
            if (isset($field['custom_keys']) && is_array($field['custom_keys'])) {
                $formattedField['custom_keys'] = $field['custom_keys'];
            }
            if (isset($field['custom_options']) && is_array($field['custom_options'])) {
                $formattedField['custom_options'] = $field['custom_options'];
            }
        }

        // Handle module_select type
        if ($field['type'] === 'module_select') {
            if (isset($field['columns']) && is_array($field['columns'])) {
                $formattedField['columns'] = $field['columns'];
            }
        }

        return $this->arrayToString($formattedField);
    }

    protected function generateFieldLabel(string $fieldName): string
    {
        // Convert snake_case to Title Case
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        $label = str_replace(' Id', '', $label);
        $label = str_replace(' At', '', $label);
        return $label;
    }

    protected function getFilterFieldType(string $fieldName, string $fieldType, array $field): string
    {
        if ($this->isForeignKey($fieldName, $field)) {
            return 'select_paginated';
        }
        if ($fieldType === 'boolean' || $fieldName === 'is_default' || $fieldName === 'is_active') {
            return 'select';
        }
        if (in_array($fieldType, ['date', 'datetime', 'timestamp'])) {
            return 'date';
        }
        if (in_array($fieldType, ['integer', 'bigint', 'decimal', 'float'])) {
            return 'number';
        }
        return 'text';
    }

    protected function isForeignKey(string $fieldName, array $field): bool
    {
        // The codebase's actual convention (set by SchemaIntrospector::normalizeType())
        // is 'type' => 'foreignId', never a boolean $field['foreignId'] key — no producer
        // in this codebase ever sets one. Check the real convention so a genuine FK
        // column whose name doesn't happen to end in '_id' is still detected, instead
        // of relying solely on the naming heuristic below.
        if (($field['type'] ?? '') === 'foreignId') {
            return true;
        }
        if (str_ends_with($fieldName, '_id')) {
            return true;
        }
        return false;
    }

    protected function getRelatedTableName(string $fieldName): string
    {
        if (str_ends_with($fieldName, '_id')) {
            $relatedTable = str_replace('_id', '', $fieldName);
            return Str::plural($relatedTable);
        }
        return Str::plural($fieldName);
    }

    protected function generateValidationRules(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];

        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }

        // Columns marked via IntrospectionToConfig's file_columns meta (threaded
        // to the config's top level -- see IntrospectionToConfig::build()) need a
        // 'file' validation rule instead of whatever FK/integer rule would
        // otherwise apply to their (typically unsignedBigInteger *_media_id)
        // column type. This is the single source of truth for that override --
        // checked here regardless of whatever rule string the field entry
        // itself already carries, so it applies whether that entry came from
        // IntrospectionToConfig::buildBackendFields() or a hand-authored config.
        $fileColumns = $this->config['file_columns'] ?? [];

        // Enum columns: IntrospectionToConfig::buildBackendFields()
        // (src/Schema/IntrospectionToConfig.php) builds each field's `rules`
        // string purely from its normalized DB type -- it has no enum branch,
        // so an enum column's field entry here never carries an `in:`/allowed
        // -values constraint, even though its allowed values ARE known (see
        // IntrospectionToConfig::buildColumn(), which threads `enum_values`
        // onto the top-level $config['columns'] entry for exactly this
        // column). Cross-reference that here by field name -- same pattern as
        // the $fileColumns override above -- so the constraint applies
        // whether the field's own `rules` string came from introspection or a
        // hand-authored config.
        $enumValuesByField = [];
        foreach (($this->config['columns'] ?? []) as $col) {
            $colName = $col['name'] ?? '';
            if ($colName !== '' && !empty($col['enum_values']) && is_array($col['enum_values'])) {
                $enumValuesByField[$colName] = $col['enum_values'];
            }
        }

        $rules = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';

            if ($fieldName !== '' && in_array($fieldName, $fileColumns, true)) {
                // Edit always allows an optional re-upload (see
                // BaseServiceGenerator::generateFileColumnUploads()'s edit
                // branch, which keeps the model's existing media_id untouched
                // when no new file is sent) -- matches the hand-written
                // MobileReleasesEditService reference pattern, where every
                // file field is optional on edit regardless of the column's
                // own DB nullability. Create keeps 'required' unless the
                // column's own generated rule was already 'nullable'.
                $isRequired = !$edit && !str_contains($field['rules'] ?? '', 'nullable');
                $ruleArray = [$isRequired ? 'required' : 'nullable', 'file'];
                $ruleStrings = array_map(fn($rule) => "\"{$rule}\"", $ruleArray);
                $rules[] = "'{$fieldName}' => [" . implode(', ', $ruleStrings) . ']';
                continue;
            }

            $fieldRules = $field['rules'] ?? '';

            if (!empty($fieldName) && !empty($fieldRules)) {
                // Split rules string into array
                // Laravel validation rules use pipe (|) as separator
                // Split on pipe and trim each rule
                $ruleArray = array_map('trim', explode('|', $fieldRules));
                $ruleArray = array_filter($ruleArray, fn($rule) => !empty($rule));

                // Edit-service unique rules must exclude the record being edited
                // (unique:table,column,{$model->id}) or saving an unchanged record
                // trips over its own existing value. Config authors mark this with
                // a "unique:table,column,__ID__"-style rule; rebuild it here via the
                // same logic ValidationGenerator::processUniqueRule() already
                // implements. Create services never get this — there's no existing
                // record to exclude — so this only runs when $edit is true.
                if ($edit) {
                    $ruleArray = array_map(
                        fn($rule) => str_starts_with($rule, 'unique:')
                            ? ValidationGenerator::processUniqueRule($rule, $fieldName, true)
                            : $rule,
                        $ruleArray
                    );
                }

                $ruleStrings = array_map(fn($rule) => "\"{$rule}\"", $ruleArray);

                // Append the allowed-values constraint as its own array entry,
                // NOT folded into $ruleArray/$ruleStrings above -- those two
                // are string rules ("required", "max:255", ...), each wrapped
                // in double quotes as a Laravel pipe-style rule literal. A
                // flat "in:a,b,c" string in that same style can't safely
                // represent a value containing a comma or pipe (both are
                // syntactically significant to Laravel's colon-rule parser),
                // so \Illuminate\Validation\Rule::in() is used instead --
                // fully qualified so no `use` import needs adding to the
                // Service stub templates. Composes for free with
                // required/nullable: Laravel already skips all other rules,
                // Rule::in() included, when a nullable field's value is null.
                // var_export(), not addslashes(), escapes each value for its
                // single-quoted PHP-array-literal context here -- addslashes()
                // also escapes `"`, which a single-quoted PHP string doesn't
                // recognize as an escape, so the backslash would survive
                // literally in the value (same reasoning as
                // FactoryGenerator's enum branch, which sets this precedent).
                $enumValues = $enumValuesByField[$fieldName] ?? null;
                if (is_array($enumValues) && !empty($enumValues)) {
                    $literalValues = implode(', ', array_map(
                        static fn($v) => var_export((string) $v, true),
                        $enumValues
                    ));
                    $ruleStrings[] = "\\Illuminate\\Validation\\Rule::in([{$literalValues}])";
                }

                $rulesArrayStr = '[' . implode(', ', $ruleStrings) . ']';
                $rules[] = "'{$fieldName}' => {$rulesArrayStr}";
            }
        }
        
        return '[' . implode(",\n            ", $rules) . ']';
    }

    /**
     * Emit inline PHP, injected into beforeCreate()/beforeUpdate() ahead of any
     * other before-save processing, that converts each file_columns-marked
     * field's raw UploadedFile into a Media row and stores the returned int id
     * back into $validData under the SAME column key.
     *
     * Wire-key contract: BaseComponentGenerator::generateSubmitCall() (the
     * frontend generator) sends the raw File under a FormData key equal to the
     * column's own name (e.g. 'image_media_id') -- fileRefName() only names the
     * local Vue `ref`, never the wire key (see its docblock). Laravel's
     * Request::all() -- what every generated Controller passes as $data --
     * merges $this->files->all() into that same array under that same key, so
     * by the time $data reaches validator()->validate() with the 'file' rule
     * generateValidationRules() emits for these columns, $validData[$column]
     * already holds the UploadedFile instance directly. No separate $params
     * plumbing is needed the way the hand-written MobileReleasesCreateService
     * uses (its 'apk_file'/'ota_file' $params keys predate this generator
     * feature and don't reflect its wire contract).
     *
     * Edit ($isEdit = true) mirrors the hand-written MobileReleasesEditService
     * reference pattern: only convert + overwrite when a new file was actually
     * supplied; otherwise unset the key so $model->update() never touches that
     * column, leaving its existing media_id in place. (MobileReleasesEditService
     * additionally deletes the old media row once the new one is saved --
     * deliberately NOT replicated here; see the generator-engine task notes for
     * this feature.)
     */
    protected function generateFileColumnUploads(bool $isEdit): string
    {
        $fileColumns = $this->config['file_columns'] ?? [];
        if (empty($fileColumns)) {
            return '';
        }

        $featureKey = $isEdit ? 'edit' : 'create';
        $configuredFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        $configuredNames = array_map(fn($f) => $f['field'] ?? '', $configuredFields);

        $lines = [];
        foreach ($fileColumns as $column) {
            if (!is_string($column) || $column === '' || !in_array($column, $configuredNames, true)) {
                // Not one of this feature's own fields -- e.g. a stray/typo'd
                // --file-columns entry, or one meant for a different module.
                continue;
            }

            $lines[] = "if ((\$validData['{$column}'] ?? null) instanceof \\Illuminate\\Http\\UploadedFile) {";
            $lines[] = "    \$validData['{$column}'] = \\App\\Project\\Modules\\Core\\Media\\Services\\MediaService::createFile(\$validData['{$column}'], Auth::id());";
            if ($isEdit) {
                $lines[] = "} else {";
                $lines[] = "    unset(\$validData['{$column}']);";
            }
            $lines[] = "}";
        }

        return empty($lines) ? '' : implode("\n        ", $lines);
    }

    protected function generateValidationMessages(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        
        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }
        
        $messages = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldMessages = $field['messages'] ?? [];
            
            if (!empty($fieldName) && !empty($fieldMessages)) {
                foreach ($fieldMessages as $message) {
                    $ruleKey = $message['ruleKey'] ?? '';
                    $messageText = $message['message'] ?? '';
                    
                    if (!empty($ruleKey) && !empty($messageText)) {
                        $messages[] = "'{$fieldName}.{$ruleKey}' => '{$messageText}'";
            }
        }
            }
        }
        
        return '[' . implode(",\n            ", $messages) . ']';
    }

    protected function generateSplashData(string $feature): string
    {
        // Get splash configuration from new path: features.backend
        $backendConfig = $this->config['features']['backend'][$feature] ?? [];
        $splashConfig = $backendConfig['splashData'] ?? [];

        $splashData = [];

        foreach ($splashConfig as $source) {
            $key = $source['key'];
            $type = $source['type'] ?? 'model';
            $paginate = $source['paginate'] ?? true; // Default to paginated
            $module = $source['module'] ?? '';
            $moduleGroup = $source['moduleGroup'] ?? 'Core';

            if ($type === 'custom') {
                // Custom static data - no pagination needed
                $dataArray = $this->formatCustomDataArray($source['data'] ?? []);
                $splashData[] = "'{$key}' => {$dataArray}";
            } else {
                // Model-based data
                if (empty($module)) {
                    continue;
                }
                
                // Check if this should be paginated
                if ($paginate) {
                    // Return API endpoint info instead of static data
                    // Frontend will call the endpoint directly
                    // Convert key to kebab-case for API endpoint (route pattern requires [a-z-]+)
                    // Str::kebab() only handles PascalCase, so convert snake_case manually
                    $endpointKey = str_replace('_', '-', $key);
                    $splashData[] = "'{$key}' => ['type' => 'api', 'endpoint' => '/select/{$endpointKey}']";
                } else {
                    // Static data (non-paginated)
                    $columns = $source['columns'] ?? ['id', 'name'];
                $columnsStr = is_array($columns) ? implode("', '", $columns) : $columns;
                $splashData[] = "'{$key}' => {$module}Model::select('{$columnsStr}')->get()->toArray()";
                }
            }
        }

        return implode(",\n            ", $splashData);
    }

    protected function formatCustomDataArray(array $data): string
    {
        if (empty($data)) {
            return '[]';
        }
        
        $formatted = [];
        foreach ($data as $item) {
            $itemStr = [];
            foreach ($item as $k => $v) {
                if (is_string($v)) {
                    $itemStr[] = "'{$k}' => '{$v}'";
                } elseif (is_numeric($v)) {
                    $itemStr[] = "'{$k}' => {$v}";
                } else {
                    $itemStr[] = "'{$k}' => " . json_encode($v);
                }
            }
            $formatted[] = '[' . implode(', ', $itemStr) . ']';
        }
        
        return '[' . implode(', ', $formatted) . ']';
    }

    protected function arrayToString(array $array): string
    {
        if (empty($array)) {
            return '[]';
        }

        $lines = ["["];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Numeric array
                    $valueStrings = [];
                    foreach ($value as $rule) {
                        if (is_array($rule)) {
                            $valueStrings[] = $this->arrayToString($rule);
                        } else {
                            $valueStrings[] = '"' . addslashes($rule) . '"';
                        }
                    }
                    $valueString = '[' . implode(', ', $valueStrings) . ']';
                } else {
                    // Associative array
                    $valueString = $this->arrayToString($value);
                }
            } else {
                $valueString = '"' . addslashes($value) . '"';
            }
            $lines[] = "\t\t\t\t'{$key}' => {$valueString},";
        }
        $lines[] = "\t\t\t]";

        return implode("\n", $lines);
    }

    /**
     * Emit PHP code to invoke all processors matching (operation, stage) for the current module.
     * Target processor services must expose static methods matching the camelCase of the stage:
     *   public static function beforeSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function beforeDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *
     * Generated code assigns the return value back to $validData so processors can transform data.
     *
     * @param string $op Operation: 'create', 'edit', 'delete'
     * @param string $stage Stage: 'before_save', 'after_save', 'before_delete', 'after_delete'
     * @return string Indented code block suitable for stub placeholder
     */
    protected function generateProcessorCalls(string $op, string $stage): string
    {
        $processors = $this->config['processors'] ?? [];
        $lines = [];

        foreach ($processors as $p) {
            if (!in_array($op, $p['operations'] ?? [], true)) {
                continue;
            }
            if (($p['stage'] ?? '') !== $stage) {
                continue;
            }

            $service = $p['service'] ?? null;
            $module = $p['module'] ?? null;

            if (!$service || !$module) {
                continue;
            }

            $method = Str::camel($stage); // before_save -> beforeSave
            // Resolve full namespace from DB (module_type + group) instead of trusting
            // $p['moduleGroup'] — the UI stores this inconsistently (sometimes the sub-group
            // "Accounting", sometimes the module_type "System"). DB lookup is authoritative.
            $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
            $namespace = "{$ns}\\Services\\{$service}";
            $fields = json_encode($p['fields'] ?? []);
            $config = json_encode($p['config'] ?? new \stdClass());

            // Method signature target must accept (array $data, ?Model $model, array $fields, array $config): array
            // Generated call passes data, model (null or variable), fields array, config array
            $modelArg = in_array($stage, ['after_save', 'before_delete', 'after_delete'], true) ? '$model' : 'null';

            $lines[] = "// Processor: {$module}\\{$service}::{$method}";
            $lines[] = "\$validData = \\{$namespace}::{$method}(\$validData, {$modelArg}, json_decode('{$fields}', true), json_decode('{$config}', true));";
        }

        return empty($lines) ? '' : implode("\n        ", $lines);
    }

    // ─── Inline Items helpers ─────────────────────────────────────────────────

    /**
     * Resolve the PHP namespace for a child module used in inline-items generation.
     * Domain groups (anything except Core/System) are nested under System/.
     */
    protected function buildChildNamespace(string $childGroup, string $childModule): string
    {
        $isDomainGroup = !in_array($childGroup, ['Core', 'System'], true);
        return $isDomainGroup
            ? "App\\Project\\Modules\\System\\{$childGroup}\\{$childModule}"
            : "App\\Project\\Modules\\{$childGroup}\\{$childModule}";
    }

    /**
     * Generate the extract block placed before validateData() in execute().
     * Pulls each inline-items key out of $data into $inlineData before validation
     * so the parent validator never sees the child-record arrays.
     */
    protected function generateInlineItemsExtract(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        foreach ($inlineItems as $item) {
            $key = $item['key'];
            $lines[] = "\$inlineData['{$key}'] = \$data['{$key}'] ?? [];";
            $lines[] = "unset(\$data['{$key}']);";
        }
        return implode("\n            ", $lines);
    }

    /**
     * Build the array_merge() injection block for a single inline item:
     * parent_fk + any inject_from_parent fields.
     */
    protected function buildInlineInjectArray(array $item): string
    {
        $pairs   = [];
        $pairs[] = "'{$item['parent_fk']}' => \$model->id";

        foreach ($item['inject_from_parent'] ?? [] as $mapping) {
            $childField  = $mapping['child_field'];
            $parentField = $mapping['parent_field'];
            $pairs[] = "'{$childField}' => \$model->{$parentField}";
        }

        return "[\n                " . implode(",\n                ", $pairs) . ",\n            ]";
    }

    protected function generateCustomFieldProcessing(string $featureKey, string $stage = 'before'): string
    {
        $fields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];

        $lines = [];
        foreach ($fields as $field) {
            $fieldName = $field['field'] ?? '';
            $service = $field['processing_service'] ?? null;
            $module = $field['processing_module'] ?? null;
            $fieldStage = $field['processing_stage'] ?? 'before';

            if ($service && $module && $fieldStage === $stage) {
                // Resolve full namespace from DB (module_type + group). The config
                // field 'processing_module_group' cannot be trusted as the top-level group —
                // DB lookup via PathManager is authoritative.
                $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
                $namespace = "{$ns}\\Services\\{$service}";
                $rules = $field['rules'] ?? '';
                $isArray = str_contains($rules, 'array');

                $lines[] = "// Processing {$fieldName} using {$service}";
                $lines[] = "if (isset(\$validData['{$fieldName}'])) {";
                if ($isArray) {
                    $lines[] = "    foreach (\$validData['{$fieldName}'] as \$item) {";
                    $lines[] = "        \\{$namespace}::process(\$item);";
                    $lines[] = "    }";
                } else {
                    $lines[] = "    \\{$namespace}::process(\$validData['{$fieldName}']);";
                }
                $lines[] = "}";
            }
        }

        return empty($lines) ? '// No custom field processing' : implode("\n        ", $lines);
    }
}

