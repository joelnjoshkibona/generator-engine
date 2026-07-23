<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * IntrospectionToConfig
 *
 * Converts raw SchemaIntrospector::columns() output into a V1-shaped
 * GeneratorModule config array. All defaults are maximalist: every feature
 * enabled, every column visible everywhere.
 */
class IntrospectionToConfig
{
    /**
     * Fixed UUID v5 namespace (DNS namespace per RFC 4122).
     * Using a stable value so output is deterministic.
     */
    private const UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /** Column types excluded from filterableFields / sortableFields */
    private const NON_FILTERABLE_TYPES = ['text', 'longText', 'mediumText', 'json'];

    /**
     * Build a V1-shaped GeneratorModule config array from SchemaIntrospector output.
     *
     * @param array $columns  Output of SchemaIntrospector::columns()
     * @param array $meta     [
     *   'module_name'      => string,        // e.g. "Products"     (required, StudlyCase singular)
     *   'module_type'      => string,        // e.g. "Custom"       (required, StudlyCase group)
     *   'table_name'       => string,        // e.g. "products"     (required, snake plural)
     *   'group_name'       => string|null,   // optional sub-group within module_type
     *   'id_type'          => string,        // 'uuid' | 'bigint'  (default 'uuid')
     *   'has_timestamps'   => bool,          // does the raw table have created_at/updated_at? (default true)
     *   'has_soft_deletes' => bool,          // does the raw table have deleted_at? (default false)
     *   'has_uuid'             => bool,      // does the raw table have a separate `uuid` column? (default true)
     *   'has_creator_updater'  => bool,      // does the raw table have created_by_id/updated_by_id? (default true)
     * ]
     * @return array  GeneratorModule-shaped config
     */
    public function build(array $columns, array $meta): array
    {
        $moduleName = $meta['module_name'];
        $moduleType = $meta['module_type'];
        $tableName  = $meta['table_name'];
        $idType     = $meta['id_type'] ?? 'uuid';
        $groupName  = $meta['group_name'] ?? null;

        // created_at/updated_at/deleted_at are deliberately excluded from
        // $columns (see SchemaIntrospector::SKIP_COLUMNS), so their absence
        // there is NOT proof the table lacks them. Callers that introspected
        // the real table (e.g. via SchemaIntrospector::hasTimestamps() /
        // hasSoftDeletes()) should pass the real answer through $meta; when
        // omitted, default to the Laravel migration convention: timestamps
        // present (the vast majority of tables have $table->timestamps()),
        // soft deletes absent (opt-in feature, most tables don't have it).
        $hasTimestamps  = array_key_exists('has_timestamps', $meta) ? (bool) $meta['has_timestamps'] : true;
        $hasSoftDeletes = (bool) ($meta['has_soft_deletes'] ?? false);

        // `uuid` (a separate public-addressing column, independent of the `id`
        // column's own type — see SchemaIntrospector::hasUuid()) and the paired
        // created_by_id/updated_by_id audit columns are, like the timestamp
        // columns above, deliberately excluded from $columns (SKIP_COLUMNS), so
        // their absence there is NOT proof the table lacks them either. Default
        // to `true` for both — the Laravel/project convention is that every
        // table gets a routing uuid plus creator/updater tracking unless a
        // caller that actually introspected the real table says otherwise.
        $hasUuid            = array_key_exists('has_uuid', $meta) ? (bool) $meta['has_uuid'] : true;
        $hasCreatorUpdater  = array_key_exists('has_creator_updater', $meta) ? (bool) $meta['has_creator_updater'] : true;

        // Build slug used in endpoint paths (e.g. "ProductOrders" → "product-orders")
        $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $moduleName));

        // Detect morph pairs from raw columns before building the column entries
        $morphs = $this->detectMorphPairs($columns);

        $builtColumns = array_map(
            fn(array $col) => $this->buildColumn($col),
            $columns
        );

        // Filter to user-facing columns (exclude system columns and morph pair columns for form/validation)
        $systemColumns = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];
        $morphPairColumnNames = $this->collectMorphColumnNames($morphs);
        $userColumns   = array_filter(
            $columns,
            fn(array $col) => !in_array($col['name'], $systemColumns, true)
                && !in_array($col['name'], $morphPairColumnNames, true)
        );
        $userColumns = array_values($userColumns);

        return [
            'id'                 => $this->uuid5($moduleName),
            'module_name'        => $moduleName,
            'module_type'        => $moduleType,
            'table_name'         => $tableName,
            'id_type'            => $idType,
            'module_group_name'  => $groupName,
            'has_timestamps'     => $hasTimestamps,
            'has_soft_deletes'   => $hasSoftDeletes,
            'has_uuid'           => $hasUuid,
            'has_creator_updater' => $hasCreatorUpdater,
            'columns'            => $builtColumns,
            'indexes'            => [],
            'morphs'             => $morphs,
            'features'           => $this->buildFeatures($moduleName, $slug, $tableName, $userColumns),
            'delegations'        => [],
            'actions'            => [],
            'processors'         => [],
            'seeder'             => [],
            'menu_config'        => null,
            'constants'          => [],
        ];
    }

    /**
     * Detect polymorphic morph pairs from the raw columns array.
     * A pair exists when a <X>_type (string) column and <X>_id (integer) column share prefix X.
     *
     * @return array  Array of morph entries: [['name' => 'reference', 'type_column' => 'reference_type', 'id_column' => 'reference_id', 'targets' => []]]
     */
    private function detectMorphPairs(array $columns): array
    {
        $morphs = [];
        $byName = [];
        foreach ($columns as $col) {
            $byName[$col['name']] = $col;
        }

        $stringTypes  = ['string', 'varchar', 'char', 'text', 'mediumText', 'longText'];
        $integerTypes = ['integer', 'bigInteger', 'bigint', 'int', 'smallint', 'tinyint', 'unsigned bigint'];

        foreach ($columns as $col) {
            $name = $col['name'];
            // Only look at *_type columns
            if (!str_ends_with($name, '_type')) {
                continue;
            }

            $normalizedType = $col['normalized_type'] ?? '';
            $rawType = strtolower($col['type'] ?? '');

            // Must be string-like
            $isStringType = in_array($normalizedType, $stringTypes, true)
                || in_array($rawType, ['varchar', 'char', 'string', 'text', 'mediumtext', 'longtext'], true);
            if (!$isStringType) {
                continue;
            }

            // Companion _id column must exist and be integer
            $prefix = substr($name, 0, -5); // strip "_type"
            $idName = $prefix . '_id';
            if (!isset($byName[$idName])) {
                continue;
            }

            $idCol = $byName[$idName];
            $idNormalized = $idCol['normalized_type'] ?? '';
            $idRaw = strtolower($idCol['type'] ?? '');
            $isIntType = in_array($idNormalized, ['integer', 'bigInteger', 'foreignId'], true)
                || in_array($idRaw, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'unsigned bigint'], true);
            if (!$isIntType) {
                continue;
            }

            // Found a morph pair
            $camelName = lcfirst($this->studly($prefix));
            $morphs[] = [
                'name'        => $camelName,
                'type_column' => $name,
                'id_column'   => $idName,
                'targets'     => [], // opt-in via JSON config editing
            ];
        }

        return $morphs;
    }

    /**
     * Collect all column names that are part of a morph pair.
     */
    private function collectMorphColumnNames(array $morphs): array
    {
        $names = [];
        foreach ($morphs as $morph) {
            $names[] = $morph['type_column'];
            $names[] = $morph['id_column'];
        }
        return $names;
    }

    // ─── Column builder ───────────────────────────────────────────────────────

    private function buildColumn(array $col): array
    {
        return [
            'id'                => $this->uuid5($col['name']),
            'name'              => $col['name'],
            'type'              => $col['normalized_type'],
            'relatedModule'     => $this->resolveRelatedModule($col),
            'length'            => (string) ($col['length'] ?? ''),
            'default'           => (string) ($col['default'] ?? ''),
            'unique'            => (bool) $col['is_unique'],
            'nullable'          => (bool) $col['nullable'],
            'indexed'           => false,
            'comment'           => '',
            'featureSelections' => [
                'backend'  => ['create' => true, 'list' => true, 'view' => true, 'edit' => true, 'delete' => true],
                'frontend' => ['create' => true, 'list' => true, 'view' => true, 'edit' => true, 'delete' => true],
            ],
        ];
    }

    private function resolveRelatedModule(array $col): string
    {
        if (!$col['is_fk'] || empty($col['foreign_table'])) {
            return '';
        }

        $foreignTable = $col['foreign_table'];

        // Keep the plural form — module names on disk are plural (e.g. payment_providers → PaymentProviders).
        // Do NOT singularize: singularization would break registry lookup since module directories
        // are stored with plural names (e.g. Custom/PaymentProviders, Core/Statuses).
        if (class_exists('\Illuminate\Support\Str')) {
            return \Illuminate\Support\Str::studly($foreignTable);
        }

        // Pure-PHP fallback: StudlyCase only, no singularization
        return $this->studly($foreignTable);
    }

    // ─── Feature builder ──────────────────────────────────────────────────────

    /**
     * Build a fully-populated GeneratorModuleFeatureTypes structure.
     * Mirrors what GeneratorCreateModuleService::ensureAllFeaturesEnabled() produces,
     * but with column names pre-populated where useful.
     */
    private function buildFeatures(
        string $moduleName,
        string $slug,
        string $tableName,
        array  $userColumns
    ): array {
        return [
            'backend'    => $this->buildBackendFeatures($moduleName, $slug, $tableName, $userColumns),
            'frontend'   => $this->buildFrontendFeatures($userColumns),
            'mobile_app' => [
                'enabled' => false,
            ],
        ];
    }

    // ─── Backend features ────────────────────────────────────────────────────

    private function buildBackendFeatures(
        string $moduleName,
        string $slug,
        string $tableName,
        array  $userColumns
    ): array {
        return [
            'list' => [
                'filterableFields'        => $this->buildFilterableFieldsList($userColumns),
                'sortableFields'          => $this->buildSortableFieldsList($userColumns),
                'eagerLoadRelationships'  => $this->buildEagerLoadList($userColumns),
                'filterableRelationships' => [],
                'filterFields'            => [],
                'endpoint'                => [
                    'method'     => 'GET',
                    'path'       => '/' . $slug,
                    'permission' => $moduleName . '.list',
                ],
            ],
            'create' => [
                'fields'   => $this->buildBackendFields($userColumns, $tableName, false),
                'endpoint' => [
                    'method'     => 'POST',
                    'path'       => '/' . $slug,
                    'permission' => $moduleName . '.create',
                ],
            ],
            'view' => [
                'eagerLoadRelationships' => $this->buildEagerLoadList($userColumns),
                'endpoint'               => [
                    'method'     => 'GET',
                    'path'       => '/' . $slug . '/{uuid}',
                    'permission' => $moduleName . '.view',
                ],
            ],
            'edit' => [
                'fields'   => $this->buildBackendFields($userColumns, $tableName, true),
                'endpoint' => [
                    'method'     => 'PUT',
                    'path'       => '/' . $slug,
                    'permission' => $moduleName . '.edit',
                ],
            ],
            'delete' => [
                'success_message' => 'Record Deleted Successfully',
                'endpoint'        => [
                    'method'     => 'DELETE',
                    'path'       => '/' . $slug . '/{uuid}',
                    'permission' => $moduleName . '.delete',
                ],
            ],
            // createSplash / editSplash are intentionally omitted here.
            // Splash artefacts are opt-in: downstream generators gate on !empty($config['constants']).
            // Add 'createSplash' / 'editSplash' keys manually in the config when constants are non-empty.
        ];
    }

    /**
     * Build the backend fields array for create or edit, with validation rules.
     * Each entry: ['field' => 'col_name', 'rules' => 'required|integer|...', 'messages' => []]
     */
    private function buildBackendFields(array $userColumns, string $tableName, bool $isEdit): array
    {
        $fields = [];

        foreach ($userColumns as $col) {
            $name     = $col['name'];
            $type     = $col['normalized_type'];
            $nullable = (bool) ($col['nullable'] ?? false);
            $isUnique = (bool) ($col['is_unique'] ?? false);
            $isFk     = (bool) ($col['is_fk'] ?? false);
            $length   = $col['length'] ?? null;

            $rules = [];

            // Required / nullable
            $rules[] = $nullable ? 'nullable' : 'required';

            // Type-specific rules
            if ($isFk) {
                $rules[] = 'integer';
                $foreignTable  = $col['foreign_table'] ?? null;
                $foreignColumn = $col['foreign_column'] ?? 'id';
                if ($foreignTable) {
                    $rules[] = "exists:{$foreignTable},{$foreignColumn}";
                }
            } elseif (in_array($type, ['string', 'varchar', 'char'], true)) {
                $rules[] = 'string';
                $maxLen = ($length && (int)$length > 0) ? (int)$length : 255;
                $rules[] = "max:{$maxLen}";
            } elseif (in_array($type, ['text', 'longText', 'mediumText', 'tinyText'], true)) {
                $rules[] = 'string';
            } elseif (in_array($type, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'unsignedInteger', 'unsignedBigInteger'], true)) {
                $rules[] = 'integer';
            } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric'], true)) {
                $rules[] = 'numeric';
            } elseif ($type === 'boolean') {
                $rules[] = 'boolean';
            } elseif ($type === 'date') {
                $rules[] = 'date';
            } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                $rules[] = 'date';
            } elseif ($type === 'json') {
                $rules[] = 'array';
            } else {
                // Fallback for unknown types
                $rules[] = 'string';
            }

            // Unique constraint
            if ($isUnique) {
                if ($isEdit) {
                    // The __ID__ placeholder; generators/templates can substitute the record ID
                    $rules[] = "unique:{$tableName},{$name},__ID__";
                } else {
                    $rules[] = "unique:{$tableName},{$name}";
                }
            }

            $fields[] = [
                'field'    => $name,
                'rules'    => implode('|', $rules),
                'messages' => [],
            ];
        }

        return $fields;
    }

    /**
     * Build filterable fields list: all user columns except non-filterable types.
     */
    private function buildFilterableFieldsList(array $userColumns): array
    {
        $fields = [];
        foreach ($userColumns as $col) {
            if (!in_array($col['normalized_type'], self::NON_FILTERABLE_TYPES, true)) {
                $fields[] = $col['name'];
            }
        }
        return $fields;
    }

    /**
     * Build sortable fields list: created_at first, then same as filterable.
     */
    private function buildSortableFieldsList(array $userColumns): array
    {
        $fields = ['created_at'];
        foreach ($userColumns as $col) {
            if (!in_array($col['normalized_type'], self::NON_FILTERABLE_TYPES, true)) {
                $fields[] = $col['name'];
            }
        }
        return $fields;
    }

    /**
     * Build eager-load list: all FK relations as camelCase singular relation names.
     * Does NOT include creator/updater — BaseServiceGenerator::generateEagerLoadRelationships()
     * always appends those itself.
     */
    private function buildEagerLoadList(array $userColumns): array
    {
        $relations = [];
        foreach ($userColumns as $col) {
            if ($col['is_fk'] && !empty($col['foreign_table'])) {
                $rel = $this->foreignTableToRelationName($col['foreign_table']);
                if (!in_array($rel, ['creator', 'updater'], true)) {
                    $relations[] = $rel;
                }
            }
        }
        return array_unique($relations);
    }

    // ─── Frontend features ───────────────────────────────────────────────────

    private function buildFrontendFeatures(
        array $userColumns
    ): array {
        $primaryField = $this->detectPrimaryField($userColumns);

        $listFields   = $this->buildFrontendListFields($userColumns);
        $createFields = $this->buildFrontendFormFields($userColumns);
        $viewFields   = $this->buildFrontendViewFields($userColumns);
        $deleteFields = array_map(static fn(array $col) => [
            'title' => self::columnLabel($col['name']),
            'key'   => $col['name'],
        ], $userColumns);

        return [
            'list' => [
                'primaryField' => $primaryField,
                'fields'       => $listFields,
            ],
            'create' => [
                'fields' => $createFields,
            ],
            'view' => [
                'fields'    => $viewFields,
                'titleData' => $primaryField,
                'badges'    => [],
                'idParam'   => 'uuid',
            ],
            'edit' => [
                'fields' => $createFields,  // Same shape as create; generators duplicate as needed
            ],
            'delete' => [
                'fields' => $deleteFields,
            ],
        ];
    }

    /**
     * Detect the primary display field: first non-FK string column, else first column, else 'name'.
     */
    private function detectPrimaryField(array $userColumns): string
    {
        // First non-FK string column
        foreach ($userColumns as $col) {
            if (!$col['is_fk'] && in_array($col['normalized_type'], ['string', 'varchar', 'char'], true)) {
                return $col['name'];
            }
        }
        // Fallback: first column
        if (!empty($userColumns)) {
            return $userColumns[0]['name'];
        }
        return 'name';
    }

    /**
     * Build frontend list fields with proper data paths for FKs.
     */
    private function buildFrontendListFields(array $userColumns): array
    {
        $primaryField = $this->detectPrimaryField($userColumns);
        $firstNonFkString = null;
        $fields = [];

        foreach ($userColumns as $col) {
            $name  = $col['name'];
            $isFk  = (bool) ($col['is_fk'] ?? false);
            $type  = $col['normalized_type'];

            // Determine display data path
            if ($isFk && !empty($col['foreign_table'])) {
                $relationName = $this->foreignTableToRelationName($col['foreign_table']);
                $data         = "{$relationName}?.name";
            } else {
                $data = $name;
            }

            $label    = self::columnLabel($name);
            $sortable = !in_array($type, self::NON_FILTERABLE_TYPES, true);

            // Primary field detection for class
            $isPrimary = ($name === $primaryField);
            if ($isPrimary && $firstNonFkString === null && !$isFk) {
                $firstNonFkString = $name;
            }

            $entry = [
                'key'      => $name,
                'title'    => $label,
                'sortable' => $sortable,
                'data'     => $data,
                'type'     => $isFk ? 'text' : ($type === 'boolean' ? 'boolean' : 'text'),
                'isFk'     => $isFk,
            ];

            if ($isPrimary) {
                $entry['class'] = 'min-w-[200px]';
            } else {
                $entry['class'] = 'hidden md:table-cell';
            }

            $fields[] = $entry;
        }

        return $fields;
    }

    /**
     * Build frontend form fields with correct field_type per column type.
     */
    private function buildFrontendFormFields(array $userColumns): array
    {
        $fields = [];

        foreach ($userColumns as $col) {
            $name     = $col['name'];
            $type     = $col['normalized_type'];
            $nullable = (bool) ($col['nullable'] ?? false);
            $isFk     = (bool) ($col['is_fk'] ?? false);
            $label    = self::columnLabel($name);

            $field = [
                'field'       => $name,
                'label'       => $label,
                'placeholder' => "Enter {$label}",
                'required'    => !$nullable,
                'splashKey'   => '',
            ];

            if ($isFk) {
                $foreignTable = $col['foreign_table'] ?? null;

                // Derive API URL: kebab plural of foreign table or stripped column name
                if ($foreignTable) {
                    $apiSlug = $this->toKebabPlural($foreignTable);
                } else {
                    // Derive from column name: strip _id → snake → kebab plural
                    $base    = preg_replace('/_id$/', '', $name);
                    $apiSlug = $this->toKebabPlural($base);
                }

                $field['field_type']    = 'api-select';
                $field['type']          = 'text';
                $field['api_url']       = "/select/{$apiSlug}";
                $field['option_label']  = 'name';
                $field['option_value']  = 'id';
                $field['per_page']      = 20;
                $field['multiple']      = false;
            } elseif ($type === 'boolean') {
                $field['field_type'] = 'checkbox';
                $field['type']       = 'boolean';
            } elseif (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                $field['field_type'] = 'date';
                $field['type']       = 'date';
            } elseif (in_array($type, ['text', 'longText', 'mediumText', 'tinyText'], true)) {
                $field['field_type'] = 'textarea';
                $field['type']       = 'text';
            } elseif (in_array($type, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'unsignedInteger', 'unsignedBigInteger'], true)) {
                $field['field_type'] = 'number-input';
                $field['type']       = 'number';
                $field['decimals']   = 0;
            } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric'], true)) {
                $field['field_type'] = 'number-input';
                $field['type']       = 'number';
                $field['decimals']   = 2;
            } else {
                // Default: string / varchar / unknown
                $field['field_type'] = 'input';
                $field['type']       = 'text';
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Build frontend view fields.
     */
    private function buildFrontendViewFields(array $userColumns): array
    {
        $fields = [];

        foreach ($userColumns as $col) {
            $name  = $col['name'];
            $isFk  = (bool) ($col['is_fk'] ?? false);
            $type  = $col['normalized_type'];

            if ($isFk && !empty($col['foreign_table'])) {
                $relationName = $this->foreignTableToRelationName($col['foreign_table']);
                $data         = "{$relationName}?.name";
            } else {
                $data = $name;
            }

            $fields[] = [
                'data'   => $data,
                'type'   => ($type === 'boolean') ? 'boolean' : 'text',
                'format' => 'text',
                'title'  => self::columnLabel($name),
            ];
        }

        return $fields;
    }

    // ─── String helpers ───────────────────────────────────────────────────────

    /**
     * Convert a column name to a human-readable label.
     * Strips _id and _at suffixes, converts snake_case to Title Case.
     */
    private static function columnLabel(string $name): string
    {
        $name = preg_replace('/_id$/', '', $name);
        $name = preg_replace('/_at$/', '', $name);
        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Convert a foreign_table name to a camelCase singular relation name.
     * e.g. "payment_providers" → "paymentProvider"
     */
    private function foreignTableToRelationName(string $foreignTable): string
    {
        // Singularize then camelCase
        $singular = $this->singularize($foreignTable);
        // camelCase = lcfirst(studly)
        return lcfirst($this->studly($singular));
    }

    /**
     * Convert a snake/plural string to kebab-plural.
     * e.g. "payment_provider" → "payment-providers"
     *      "payment_providers" → "payment-providers"
     */
    private function toKebabPlural(string $value): string
    {
        // Replace underscores with hyphens
        $kebab = str_replace('_', '-', $value);
        // Ensure it's plural
        $parts = explode('-', $kebab);
        $last  = array_pop($parts);
        $last  = $this->pluralizeSimple($last);
        $parts[] = $last;
        return implode('-', $parts);
    }

    /**
     * Naive pluralizer for the last word of a kebab segment.
     */
    private function pluralizeSimple(string $word): string
    {
        // Already plural (ends in s)?
        if (substr($word, -1) === 's') {
            return $word;
        }
        // ies
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    // ─── UUID v5 helpers ──────────────────────────────────────────────────────

    /**
     * Generate a deterministic UUID v5 (SHA-1 name-based) from a string.
     * Does not require any external dependency.
     */
    private function uuid5(string $name): string
    {
        // Decode the fixed namespace UUID into its 16-byte binary form
        $ns = str_replace(['{', '}', '-'], '', self::UUID_NAMESPACE);
        $nsBin = pack('H*', $ns);

        // SHA-1 hash of namespace bytes + name
        $hash = sha1($nsBin . $name, true);

        // Apply version (5) and variant bits
        $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x50); // version 5
        $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80); // variant RFC 4122

        // Format as UUID string
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($hash), 4));
    }

    // ─── Pure-PHP string helpers (used when Illuminate\Support\Str absent) ───

    /**
     * Basic StudlyCase converter.
     */
    private function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * Naive English singularizer covering the common cases seen in DB table names.
     * For full correctness, use Illuminate\Support\Str::singular() when available.
     */
    private function singularize(string $word): string
    {
        $rules = [
            'ies$'  => 'y',
            'ses$'  => 's',
            'ves$'  => 'f',
            'xes$'  => 'x',
            'oes$'  => 'o',
            'ches$' => 'ch',
            'shes$' => 'sh',
            's$'    => '',
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match('/' . $pattern . '/i', $word)) {
                return preg_replace('/' . $pattern . '/i', $replacement, $word);
            }
        }

        return $word;
    }
}
