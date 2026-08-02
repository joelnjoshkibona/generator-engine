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
     * $meta keys whose ABSENCE (not merely a false/empty value) is dangerous:
     * each one is a schema-derived fact that build() would otherwise silently
     * default, indistinguishable from an intentional value. In strict mode
     * every one of these must be explicitly present in $meta.
     *
     * Tightened (2026-07-26) to also require 'file_columns' and
     * 'index_groups' — the exact bug that motivated this: indexGroups()
     * already existed on SchemaIntrospector and build() already read
     * $meta['index_groups'], but every hand-assembled call site simply
     * forgot to pass it, so composite unique constraints/indexes silently
     * vanished from generated migrations. Now that
     * SchemaIntrospector::meta() is the single reliable source for every
     * schema-derived key (including these two), strict mode can — and
     * should — require them exactly like the four booleans below, instead
     * of letting them keep silently defaulting to `[]`. NEW REQUIRED KEY
     * SET AS OF THIS CHANGE: has_timestamps, has_soft_deletes, has_uuid,
     * has_creator_updater, file_columns, index_groups. Consumer call sites
     * that construct via ::strict() must thread all six through (trivially
     * satisfied by spreading SchemaIntrospector::meta()'s return value into
     * $meta) or they will now start throwing.
     */
    private const REQUIRED_STRICT_KEYS = [
        'has_timestamps',
        'has_soft_deletes',
        'has_uuid',
        'has_creator_updater',
        'file_columns',
        'index_groups',
    ];

    /**
     * Every top-level $meta key this class knows how to interpret.
     *
     * Includes SchemaConventions::SKIP_CHECK_META_KEY
     * ('skip_convention_check') even though build() itself never reads it --
     * that key is consumed entirely by SchemaIntrospector::meta() (see its
     * docblock and SchemaConventions), but callers naturally build ONE
     * $meta array and pass it to both meta() and build() in sequence.
     * Without listing it here, a caller who set the opt-out key would get an
     * "unknown $meta key" InvalidArgumentException from validateMeta() the
     * moment that same array reached build() -- the opt-out mechanism would
     * be unusable in the normal call pattern. Listed here purely so it
     * passes validation; it is otherwise inert as far as build() is
     * concerned.
     */
    private const KNOWN_META_KEYS = [
        'module_name',
        'module_type',
        'table_name',
        'group_name',
        'id_type',
        'has_timestamps',
        'has_soft_deletes',
        'has_uuid',
        'has_creator_updater',
        'file_columns',
        'index_groups',
        'skip_convention_check',
    ];

    /**
     * Single-column index groups that duplicate an index MigrationGenerator
     * ALREADY emits unconditionally via generateSystemIndexes() for the
     * framework columns (uuid / created_by_id / updated_by_id / deleted_at).
     * Those columns are excluded from $columns entirely (see
     * SchemaIntrospector::SKIP_COLUMNS), so their raw DB indexes still show up
     * in $meta['index_groups'] unfiltered -- match on exact column-name to
     * drop them here, before they'd otherwise be re-declared as a second,
     * differently-named index on the very same column.
     */
    private const CONVENTIONAL_SYSTEM_INDEX_COLUMN_SETS = [
        ['uuid'],
        ['created_by_id'],
        ['updated_by_id'],
        ['deleted_at'],
    ];

    /**
     * @param bool $strict  When true, build() raises InvalidArgumentException
     *                      for any $meta bag that omits one of
     *                      self::REQUIRED_STRICT_KEYS or that contains an
     *                      unknown/typo'd top-level key, instead of silently
     *                      defaulting. Off by default to keep existing
     *                      lenient callers working unchanged; migrate a
     *                      caller to strict mode via self::strict().
     *
     *                      Bug this guards against: a downstream command
     *                      discarded the live introspection result and never
     *                      threaded 'has_soft_deletes' into $meta at all —
     *                      build() silently defaulted it to `false`,
     *                      indistinguishable from an intentional `false`,
     *                      and every generated module quietly lost
     *                      SoftDeletes until migrations were hand-patched
     *                      three times before anyone noticed. Strict mode
     *                      makes that failure loud instead of silent.
     */
    /**
     * Map of foreign_table => that table's real display/primary field, as
     * computed by whatever introspected it (see
     * self::detectPrimaryFieldFromColumns()). Populated per-build() call via
     * its $foreignPrimaryFields parameter; empty by default, in which case
     * every FK falls back to the pre-existing hardcoded 'name' assumption --
     * fully backward compatible for callers that don't supply it.
     *
     * @var array<string, string>
     */
    private array $foreignPrimaryFields = [];

    public function __construct(private readonly bool $strict = false)
    {
    }

    /** Named constructor: strict mode. See the constructor docblock. */
    public static function strict(): self
    {
        return new self(true);
    }

    /**
     * Named constructor: explicit lenient mode (the default behaviour) —
     * useful for call sites that want to document, at the call site, that
     * they've deliberately opted out of strict validation.
     */
    public static function lenient(): self
    {
        return new self(false);
    }

    /**
     * Validate $meta before build() reads a single key out of it.
     *
     * Two independent checks, both loud-fail via InvalidArgumentException:
     *   1. Unknown/typo'd top-level keys (e.g. 'has_soft_delete' instead of
     *      'has_soft_deletes') — always rejected, in both strict and
     *      lenient mode, since a typo'd key is never intentional and silently
     *      ignoring it (the array_key_exists()/?? defaulting below would)
     *      is exactly the silent-defaulting failure mode this class exists
     *      to prevent.
     *   2. In strict mode only: any of self::REQUIRED_STRICT_KEYS entirely
     *      absent from $meta. Explicitly-present-and-false is fine and
     *      preserved verbatim; only absence is an error.
     *
     * @throws \InvalidArgumentException
     */
    private function validateMeta(array $meta): void
    {
        foreach (array_keys($meta) as $key) {
            if (!in_array($key, self::KNOWN_META_KEYS, true)) {
                $suggestion = $this->closestKnownKey($key);
                $hint = $suggestion !== null ? " Did you mean '{$suggestion}'?" : '';
                throw new \InvalidArgumentException(
                    "IntrospectionToConfig::build(): unknown \$meta key '{$key}'.{$hint}"
                );
            }
        }

        if (!$this->strict) {
            return;
        }

        $missing = array_values(array_filter(
            self::REQUIRED_STRICT_KEYS,
            fn(string $key) => !array_key_exists($key, $meta)
        ));

        if (!empty($missing)) {
            $list = implode(', ', $missing);
            throw new \InvalidArgumentException(
                "IntrospectionToConfig::build() (strict mode): \$meta is missing required key(s): {$list}. "
                . "Each one is a schema-derived fact (e.g. from SchemaIntrospector) that must be threaded "
                . "through explicitly -- an absent key is not the same as an intentional `false`. "
                . "Pass the real introspected value, or construct via ::lenient() to opt out of this check."
            );
        }
    }

    /**
     * Nearest known top-level $meta key to an unrecognized one, by Levenshtein
     * distance. Returns null when nothing is a plausible typo (distance too
     * large relative to the key's own length) to avoid nonsense suggestions.
     */
    private function closestKnownKey(string $key): ?string
    {
        $best = null;
        $bestDistance = null;

        foreach (self::KNOWN_META_KEYS as $known) {
            $distance = levenshtein($key, $known);
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $known;
            }
        }

        if ($best === null || $bestDistance === null) {
            return null;
        }

        // Only suggest when the typo is "close" -- at most half the length of
        // the longer of the two strings -- so wildly unrelated keys don't get
        // a misleading suggestion.
        $threshold = (int) ceil(max(strlen($key), strlen($best)) / 2);

        return $bestDistance <= $threshold ? $best : null;
    }

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
     *   'file_columns'         => array,     // column names to force field_type='file-input' on, overriding
     *                                         // whatever type would otherwise be inferred (default [])
     *   'index_groups'         => array,     // SchemaIntrospector::indexGroups()-shaped rows -- raw, unfiltered
     *                                         // DB index groups (single- and multi-column, unique and non-unique).
     *                                         // build() routes each group to the right place: single-column
     *                                         // uniques are already carried by the column's own `unique` flag,
     *                                         // multi-column uniques become top-level `unique_constraints`,
     *                                         // everything else becomes a top-level `indexes` entry (default [])
     * ]
     * @param array<string, string> $foreignPrimaryFields  Map of foreign_table => that
     *                      table's real display/primary field (e.g. ['orders' =>
     *                      'order_number']), as computed by running
     *                      self::detectPrimaryFieldFromColumns() against each FK
     *                      target's own introspected columns. Optional -- a table
     *                      absent from this map (or the map left empty) falls back
     *                      to the pre-existing 'name' assumption, so existing callers
     *                      that don't supply it are unaffected.
     * @return array  GeneratorModule-shaped config
     */
    public function build(array $columns, array $meta, array $foreignPrimaryFields = []): array
    {
        $this->validateMeta($meta);
        $this->foreignPrimaryFields = $foreignPrimaryFields;

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

        [$indexes, $uniqueConstraints] = $this->buildIndexesAndUniqueConstraints($meta['index_groups'] ?? []);

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
            'indexes'            => $indexes,
            'unique_constraints' => $uniqueConstraints,
            'morphs'             => $morphs,
            // Threaded to the top level (not just buried inside
            // features.frontend.create.fields[]) so backend generators that
            // read $config directly -- ModelGenerator (belongsTo(Media)
            // relationship), CreateServiceGenerator/EditServiceGenerator
            // (validation-rule override + upload-then-store-id logic) -- can
            // find it without reaching into the frontend-only path
            // buildFrontendFormFields() already uses this same $meta key for.
            'file_columns'       => $meta['file_columns'] ?? [],
            'features'           => $this->buildFeatures($moduleName, $slug, $tableName, $userColumns, $meta),
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
     * Route raw SchemaIntrospector::indexGroups() rows to the two places
     * MigrationGenerator actually consumes them: a table-level `indexes`
     * array (rendered as $table->index([...], 'name')) and a table-level
     * `unique_constraints` array (rendered as $table->unique([...], 'name')).
     *
     * Routing rules:
     *   - Groups matching a CONVENTIONAL_SYSTEM_INDEX_COLUMN_SETS entry
     *     exactly are dropped -- MigrationGenerator::generateSystemIndexes()
     *     already emits those unconditionally; keeping them here would
     *     declare the same index twice under two different names.
     *   - Single-column unique groups are dropped -- already carried by that
     *     column's own `unique` flag (see buildColumn()) and rendered inline
     *     via ->unique() on the column definition itself; adding it here too
     *     would duplicate it.
     *   - Multi-column unique groups become a `unique_constraints` entry.
     *   - Everything else (single- or multi-column, non-unique) becomes an
     *     `indexes` entry.
     *
     * @param array<int, array{name?: string|null, columns?: string[], unique?: bool}> $indexGroups
     * @return array{0: array<int, array{columns: string[], unique: bool, name: string|null}>, 1: array<int, array{columns: string[], name: string|null}>}
     */
    private function buildIndexesAndUniqueConstraints(array $indexGroups): array
    {
        $indexes = [];
        $uniqueConstraints = [];

        foreach ($indexGroups as $group) {
            $columns = array_values($group['columns'] ?? []);
            if (empty($columns)) {
                continue;
            }

            if (count($columns) === 1 && in_array($columns, self::CONVENTIONAL_SYSTEM_INDEX_COLUMN_SETS, true)) {
                continue;
            }

            $unique = (bool) ($group['unique'] ?? false);

            if ($unique) {
                if (count($columns) === 1) {
                    continue;
                }

                $uniqueConstraints[] = [
                    'columns' => $columns,
                    'name'    => $group['name'] ?? null,
                ];
                continue;
            }

            $indexes[] = [
                'columns' => $columns,
                'unique'  => false,
                'name'    => $group['name'] ?? null,
            ];
        }

        return [$indexes, $uniqueConstraints];
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
        $built = [
            'id'                => $this->uuid5($col['name']),
            'name'              => $col['name'],
            'type'              => $col['normalized_type'],
            'relatedModule'     => $this->resolveRelatedModule($col),
            'length'            => (string) ($col['length'] ?? ''),
            'default'           => (string) ($col['default'] ?? ''),
            'unique'            => (bool) $col['is_unique'],
            'nullable'          => (bool) $col['nullable'],
            'indexed'           => (bool) ($col['indexed'] ?? false),
            'comment'           => '',
            'featureSelections' => [
                'backend'  => ['create' => true, 'list' => true, 'view' => true, 'edit' => true, 'delete' => true],
                'frontend' => ['create' => true, 'list' => true, 'view' => true, 'edit' => true, 'delete' => true],
            ],
        ];

        // Additive, optional: only threaded through when the source column
        // actually carries them (SchemaIntrospector::columns() populates them
        // for real decimal/enum columns; a hand-authored config or a
        // non-decimal/non-enum column simply omits them, and MigrationGenerator
        // falls back to its existing defaults exactly as before this fields
        // existed).
        if (isset($col['precision']) && $col['precision'] !== null) {
            $built['precision'] = $col['precision'];
        }
        if (isset($col['scale']) && $col['scale'] !== null) {
            $built['scale'] = $col['scale'];
        }
        if (!empty($col['enum_values'])) {
            $built['enum_values'] = $col['enum_values'];
        }

        return $built;
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
        array  $userColumns,
        array  $meta = []
    ): array {
        return [
            'backend'    => $this->buildBackendFeatures($moduleName, $slug, $tableName, $userColumns),
            'frontend'   => $this->buildFrontendFeatures($userColumns, $meta),
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
     * appends those itself, gated on ModuleConfigContract::hasCreatorUpdater().
     */
    private function buildEagerLoadList(array $userColumns): array
    {
        $relations = [];
        foreach ($userColumns as $col) {
            if ($col['is_fk'] && !empty($col['foreign_table'])) {
                $rel = $this->columnToRelationName($col['name']);
                if (!in_array($rel, ['creator', 'updater'], true)) {
                    $relations[] = $rel;
                }
            }
        }
        return array_unique($relations);
    }

    // ─── Frontend features ───────────────────────────────────────────────────

    private function buildFrontendFeatures(
        array $userColumns,
        array $meta = []
    ): array {
        $primaryField = $this->detectPrimaryField($userColumns);

        $listFields   = $this->buildFrontendListFields($userColumns);
        $createFields = $this->buildFrontendFormFields($userColumns, $meta);
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
        return self::detectPrimaryFieldFromColumns($userColumns);
    }

    /**
     * Same rule as detectPrimaryField(), exposed as a static, columns-only
     * utility so a caller can also run it against a FOREIGN table's own
     * introspected columns -- not just the table currently being built --
     * to find out that target's real display field before it hardcodes
     * 'name' into a generated FK relation accessor. See
     * self::$foreignPrimaryFields / the $foreignPrimaryFields param on
     * build().
     *
     * @param array $columns  SchemaIntrospector::columns()-shaped rows for ANY table.
     */
    public static function detectPrimaryFieldFromColumns(array $columns): string
    {
        // First non-FK string column
        foreach ($columns as $col) {
            if (!$col['is_fk'] && in_array($col['normalized_type'], ['string', 'varchar', 'char'], true)) {
                return $col['name'];
            }
        }
        // Fallback: first column
        if (!empty($columns)) {
            return $columns[0]['name'];
        }
        return 'name';
    }

    /**
     * Real display field for a foreign table, previously hardcoded to 'name'
     * everywhere an FK's related record needed to be shown (list cells, list
     * data paths, api-select option labels). 'name' is correct for the
     * overwhelming majority of this codebase's lookup tables, but not
     * universally true (e.g. an `orders` table displayed by `order_number`,
     * not `name`) -- silently assuming it produces a generated Vue
     * expression that's either blank or, if the target's own default select
     * query also assumes a 'name' column exists, a SQL error on search.
     *
     * Falls back to 'name' when $foreignTable is unknown or wasn't present
     * in the $foreignPrimaryFields map passed to build() -- i.e. when the
     * caller didn't (or couldn't) introspect the target table, behaviour is
     * byte-identical to before this method existed.
     */
    private function resolveForeignDisplayField(?string $foreignTable): string
    {
        if ($foreignTable !== null && isset($this->foreignPrimaryFields[$foreignTable])) {
            return $this->foreignPrimaryFields[$foreignTable];
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
            $displayField = null;
            if ($isFk && !empty($col['foreign_table'])) {
                $relationName = $this->columnToRelationName($col['name']);
                $displayField = $this->resolveForeignDisplayField($col['foreign_table']);
                $data         = "{$relationName}?.{$displayField}";
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

            $hasEnumValues = !$isFk && !empty($col['enum_values']) && is_array($col['enum_values']);

            $entry = [
                'key'      => $name,
                'title'    => $label,
                'sortable' => $sortable,
                // Enum columns render as a badge, same family as 'boolean' --
                // see BaseComponentGenerator::generateCustomCellRenderersFromListFields(),
                // which already special-cases 'badge'/'boolean' for a custom
                // cell renderer. A plain string column stays 'text'.
                'data'     => $data,
                'type'     => $isFk ? 'text' : ($type === 'boolean' ? 'boolean' : ($hasEnumValues ? 'badge' : 'text')),
                'isFk'     => $isFk,
                // Threaded through so BaseComponentGenerator::generateCustomCellRenderersFromListFields()
                // knows which module a RelatedRecordLink for this FK cell should point at.
                // Sourced the same way buildColumn() populates the top-level columns[]
                // entry's own 'relatedModule' key — same raw $col, same resolver.
                'relatedModule' => $this->resolveRelatedModule($col),
                // Real display field for this FK's related record (see
                // resolveForeignDisplayField()) — null for non-FK columns.
                // Threaded through so generateCustomCellRenderersFromListFields()
                // reads the SAME resolved field this class's own 'data' path
                // above just used, instead of independently re-hardcoding 'name'.
                'displayField' => $displayField,
            ];

            if ($hasEnumValues) {
                // Precomputed value => humanised-label pairs, reusing the same
                // columnLabel() helper already used to humanise this same
                // enum's Select2Field option labels (see buildFrontendFormFields()),
                // so the list badge's text matches the form's option label
                // rather than showing the raw stored value (e.g. 'in_progress').
                // Built here rather than in BaseComponentGenerator so both
                // callers share one humanisation rule instead of duplicating it
                // across the PHP config layer and the emitted Vue/JS layer.
                $entry['enum_values'] = array_values(array_map(
                    static fn($value) => [
                        'value' => (string) $value,
                        'label' => self::columnLabel((string) $value),
                    ],
                    $col['enum_values']
                ));
            }

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
     *
     * @param array $meta  Same $meta bag passed to build(). Only 'file_columns' is
     *                      read here — mirrors the has_uuid/has_creator_updater hint
     *                      pattern: an explicit caller-supplied signal that overrides
     *                      what would otherwise be inferred from the column's own type.
     */
    private function buildFrontendFormFields(array $userColumns, array $meta = []): array
    {
        $fields = [];

        // Explicit "this column is a file/media upload" hint. Checked first so it
        // wins over every other inference below (FK, boolean, date, etc.) — a column
        // named like a foreign key or typed as text can still be forced to render as
        // a file input when the caller knows better than the raw SQL type does.
        $fileColumns = $meta['file_columns'] ?? [];

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

            if (in_array($name, $fileColumns, true)) {
                $field['field_type'] = 'file-input';
                $field['type']       = 'file';
            } elseif ($isFk) {
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
                $field['option_label']  = $this->resolveForeignDisplayField($foreignTable);
                $field['option_value']  = 'id';
                $field['per_page']      = 20;
                $field['multiple']      = false;
            } elseif (!empty($col['enum_values']) && is_array($col['enum_values'])) {
                // Static select, not api-select/splash-select: the allowed values are
                // already fully known at generation time (SchemaIntrospector::columns()
                // reads them straight off the DB enum definition — see buildColumn()
                // threading the same $col['enum_values'] onto the top-level columns[]
                // entry, and BaseServiceGenerator's Rule::in() validation, which reads
                // that top-level entry to constrain the exact same values). No network
                // round-trip is needed to populate the options, so this deliberately
                // does NOT set 'splashKey' (left at the '' default from $field's initial
                // array above) — BaseComponentGenerator::generateFieldTemplate() treats an
                // empty/missing splashKey on a 'select' field_type as "static options",
                // matching the hand-authored boolean-select precedent (Yes/No options
                // inlined the same way) and falling into the `isset($field['options'])`
                // inline-array branch there instead of the splash-data path.
                //
                // 'type' stays 'text' (not 'select') for the same reason the FK branch
                // above keeps 'type' => 'text' rather than mirroring 'field_type' — the
                // literal string 'select' in a field's 'type' key means something
                // different elsewhere in BaseComponentGenerator (hasSplashData() /
                // generateSplashData() gate splash-array generation on
                // $field['type'] === 'select'), which a static/inline-options field must
                // not trigger.
                //
                // option_value/option_label use the same 'id'/'name' keys as every other
                // select-shaped field in this file (api-select above, boolean Yes/No in
                // BaseComponentGenerator) — the raw enum value is the submitted value
                // ('id'), a humanised version is only for display ('name'), reusing
                // columnLabel()'s existing snake_case → Title Case conversion rather than
                // inventing new label logic.
                $field['field_type']    = 'select';
                $field['type']          = 'text';
                $field['options']       = array_values(array_map(
                    static fn($value) => [
                        'id'   => (string) $value,
                        'name' => self::columnLabel((string) $value),
                    ],
                    $col['enum_values']
                ));
                $field['option_label']  = 'name';
                $field['option_value']  = 'id';
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
                $relationName = $this->columnToRelationName($col['name']);
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
     * Convert an FK column name to its Eloquent relation method name.
     *
     * MUST mirror ModelGenerator::deriveRelationshipMethodName() exactly: this
     * value is embedded into generated config (eagerLoadRelationships, and the
     * list/view field `data` paths) as the name of a relation that ModelGenerator
     * independently generates on the Model itself. The two derivations used to
     * diverge for any FK column not literally named after its own target table —
     * most commonly self-referential `parent_id` (foreign_table `item_categories`,
     * but a column named "parent"), where this used to singularize the *table*
     * name into "itemCategory" while the Model's actual belongsTo() method is
     * named "parent" (derived from the *column*). That mismatch produced config
     * that eager-loads/reads a relation the Model never defines — a hard
     * "Call to undefined relationship [itemCategory]" at runtime the first time
     * anyone hit the list/view endpoint. Deriving from the column name here,
     * exactly like ModelGenerator does, keeps the two permanently in lockstep.
     *
     * e.g. "parent_id" → "parent", "item_type_id" → "itemType"
     */
    private function columnToRelationName(string $columnName): string
    {
        $base = str_ends_with($columnName, '_id') ? substr($columnName, 0, -3) : $columnName;

        if (class_exists('\Illuminate\Support\Str')) {
            return lcfirst(\Illuminate\Support\Str::camel($base));
        }

        return lcfirst($this->studly($base));
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

}
