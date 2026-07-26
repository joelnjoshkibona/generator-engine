<?php

namespace Blutrixx\GeneratorEngine\Schema;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SchemaIntrospector
 *
 * Inspects a live DB table and returns structured column metadata for use
 * by scaffold commands. Works with any Laravel-supported driver (MySQL, SQLite, PostgreSQL).
 *
 * Uses Laravel 11+ Schema::getColumns() / Schema::getForeignKeys() — no Doctrine DBAL.
 */
class SchemaIntrospector
{
    /** Columns that belong to the framework / base model and should be skipped */
    protected const SKIP_COLUMNS = [
        'id', 'uuid', 'created_at', 'updated_at', 'deleted_at',
        'created_by_id', 'updated_by_id',
    ];

    /**
     * Column-name SUFFIX patterns (case-insensitive, matched against the end
     * of the column name) that suggest a file/media upload column. Kept as a
     * class constant so the heuristic is easy to extend without touching
     * fileColumns()'s logic. See fileColumns() for the full heuristic and
     * its deliberately conservative exclusions.
     */
    protected const FILE_COLUMN_SUFFIX_PATTERNS = [
        '_file', '_path', '_image', '_photo', '_avatar', '_logo',
        '_attachment', '_document',
    ];

    /**
     * Column-name EXACT (whole-name) patterns, case-insensitive, that
     * suggest a file/media upload column on their own — e.g. a bare `image`
     * or `avatar` column with no prefix/suffix. Deliberately does NOT
     * include `path` or `document`/`attachment` bare — those are common
     * non-file column names too (routing/menu `path`, generic `document`
     * type/category fields), so only their SUFFIX form
     * (FILE_COLUMN_SUFFIX_PATTERNS) is trusted.
     */
    protected const FILE_COLUMN_EXACT_PATTERNS = [
        'image', 'photo', 'avatar', 'logo', 'file',
    ];

    /**
     * Column-name SUFFIX pattern (case-insensitive) for this project's real
     * file-upload convention on INTEGER/FK-shaped columns: a
     * `*_media_id` column (e.g. `apk_media_id`, `ota_media_id`, established
     * v2.10.17, hand-built precedent: SYSTEM_SHELL's MobileReleasesModel /
     * its migration) holding the row id of a `media` table record.
     *
     * Detection is NAME-PATTERN based (this constant / MEDIA_ID_COLUMN_EXACT)
     * and is the AUTHORITATIVE signal here — not a fallback behind FK-target
     * info — because in practice FK-target info is never actually available
     * for these columns: this convention is DELIBERATELY built with no hard
     * FK constraint (see the `*_media_id` columns' migration comment, "Media
     * references (indexed, no hard FK constraints)"), and the `_id`-suffix
     * table-name-guessing convention (inferFkByConvention()) can never
     * resolve `apk_media_id`/`ota_media_id` to the real `media` table either
     * (`Str::plural('apk_media')` stays `'apk_media'`, not `'media'`) — so
     * `is_fk` is always false for these columns and columns()'s
     * `foreign_table` is always null. filterFileColumns() therefore matches
     * on name alone for this pattern, deliberately ignoring `is_fk` (unlike
     * the string-column branch, which still excludes real FKs) — see
     * filterFileColumns()'s docblock for the full reasoning and why a
     * differently-named FK that merely happens to target a `media` table
     * (e.g. a hypothetical `avatar_id -> media`) is still excluded.
     */
    protected const MEDIA_ID_COLUMN_SUFFIX = '_media_id';

    /** Bare-name counterpart of MEDIA_ID_COLUMN_SUFFIX — see its docblock. */
    protected const MEDIA_ID_COLUMN_EXACT = 'media_id';

    /**
     * Normalized column types eligible for the `*_media_id` integer/FK-shaped
     * pattern (see MEDIA_ID_COLUMN_SUFFIX's docblock). `foreignId` is
     * included because normalizeType() always returns `foreignId` — never
     * `integer`/`bigInteger` — for any column with real FK evidence
     * (columns()'s `is_fk`), so a hypothetical future schema that DOES add a
     * hard constraint to a `*_media_id` column must still match here.
     */
    protected const MEDIA_ID_COLUMN_TYPES = ['integer', 'bigInteger', 'foreignId'];

    public function __construct(private readonly string $table, private ?string $connection = null) {}

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return $this->connection
            ? \Illuminate\Support\Facades\Schema::connection($this->connection)
            : \Illuminate\Support\Facades\Schema::getFacadeRoot();
    }

    public function exists(): bool
    {
        return $this->schema()->hasTable($this->table);
    }

    /**
     * Whether the raw table has BOTH a `created_at` and `updated_at` column.
     *
     * columns() deliberately excludes these framework columns from its
     * output (see SKIP_COLUMNS), so downstream config building loses this
     * signal unless callers capture it here and pass it through explicitly
     * (e.g. as $meta['has_timestamps'] to IntrospectionToConfig::build()).
     */
    public function hasTimestamps(): bool
    {
        return $this->hasRawColumn('created_at') && $this->hasRawColumn('updated_at');
    }

    /**
     * Whether the raw table has a `deleted_at` column.
     *
     * Same rationale as hasTimestamps() — deleted_at is stripped from
     * columns() output, so this must be captured separately and passed
     * through as $meta['has_soft_deletes'].
     */
    public function hasSoftDeletes(): bool
    {
        return $this->hasRawColumn('deleted_at');
    }

    /**
     * Whether the raw table has a separate `uuid` column (distinct from the
     * `id` column's own type — a table can have a bigint autoincrement `id`
     * AND a `uuid` column used for public-facing addressing, or neither).
     *
     * Same rationale as hasTimestamps()/hasSoftDeletes() — `uuid` is stripped
     * from columns() output (see SKIP_COLUMNS), so this must be captured
     * separately and passed through as $meta['has_uuid'].
     */
    public function hasUuid(): bool
    {
        return $this->hasRawColumn('uuid');
    }

    /**
     * Whether the raw table has BOTH a `created_by_id` and `updated_by_id`
     * column (the paired audit-trail columns this project's convention
     * always adds together — see MigrationGenerator::generateAuditFields()).
     *
     * Same rationale as hasTimestamps() — both columns are stripped from
     * columns() output (see SKIP_COLUMNS), so their absence is NOT evidence
     * a table lacks them unless this is captured separately and passed
     * through as $meta['has_creator_updater'].
     */
    public function hasCreatorUpdater(): bool
    {
        return $this->hasRawColumn('created_by_id') && $this->hasRawColumn('updated_by_id');
    }

    /**
     * The complete, single-source-of-truth schema-derived $meta bag for
     * IntrospectionToConfig::build() — every key build() infers straight
     * from the live table, collected in one introspection pass instead of
     * being hand-assembled at each call site.
     *
     * Bug this closes: indexGroups() already existed and build() already
     * read $meta['index_groups'], but every consumer built $meta by hand
     * and none of them was ever updated to pass it — composite unique
     * constraints and indexes silently vanished from every generated
     * migration. A new schema-derived key added to build()'s contract now
     * only needs wiring here once, instead of at every call site.
     *
     * Deliberately excludes caller-supplied, non-schema keys
     * (module_name, module_type, table_name, group_name, id_type) — those
     * come from the caller's own scaffold context (e.g. the module name
     * being generated), not from anything the live table can tell us.
     *
     * Table-does-not-exist is NOT an error — callers scaffold modules for
     * tables that don't exist yet (pre-migration) — so this never throws;
     * it short-circuits to the same sensible "nothing here" defaults every
     * individual has*()/fileColumns()/indexGroups() method already falls
     * back to when the table is absent, just gathered in one place and
     * without hitting the schema connection once per key.
     *
     * @return array{
     *   has_timestamps: bool,
     *   has_soft_deletes: bool,
     *   has_uuid: bool,
     *   has_creator_updater: bool,
     *   file_columns: string[],
     *   index_groups: array<int, array{name: string|null, columns: string[], unique: bool}>,
     * }
     */
    public function meta(): array
    {
        if (!$this->exists()) {
            return [
                'has_timestamps'      => false,
                'has_soft_deletes'    => false,
                'has_uuid'            => false,
                'has_creator_updater' => false,
                'file_columns'        => [],
                'index_groups'        => [],
            ];
        }

        return [
            'has_timestamps'      => $this->hasTimestamps(),
            'has_soft_deletes'    => $this->hasSoftDeletes(),
            'has_uuid'            => $this->hasUuid(),
            'has_creator_updater' => $this->hasCreatorUpdater(),
            'file_columns'        => $this->fileColumns(),
            'index_groups'        => $this->indexGroups(),
        ];
    }

    private function hasRawColumn(string $name): bool
    {
        if (!$this->exists()) {
            return false;
        }

        foreach ($this->schema()->getColumns($this->table) as $col) {
            if (($col['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inspect the `id` column and return a canonical id-type token.
     * Returns 'bigint', 'uuid', or 'string'. Falls back to 'uuid'.
     */
    public function idColumnType(): string
    {
        if (!$this->exists()) {
            return 'uuid';
        }

        $rawColumns = $this->schema()->getColumns($this->table);

        foreach ($rawColumns as $col) {
            if (($col['name'] ?? '') !== 'id') {
                continue;
            }

            $typeName = strtolower($col['type_name'] ?? '');
            $fullType = strtolower($col['type'] ?? '');

            if (in_array($typeName, ['bigint', 'int', 'integer', 'smallint', 'tinyint'], true)) {
                return 'bigint';
            }

            if (in_array($typeName, ['char', 'varchar'], true)) {
                if (preg_match('/\((\d+)\)/', $fullType, $m) && (int) $m[1] === 36) {
                    return 'uuid';
                }
                return 'string';
            }

            if ($typeName === 'uuid') {
                return 'uuid';
            }

            return 'string';
        }

        return 'uuid';
    }

    /**
     * Returns column metadata for every non-framework column.
     *
     * Shape of each entry:
     * [
     *   'name'            => 'category_id',
     *   'type'            => 'bigint',         // raw DB type
     *   'normalized_type' => 'foreignId',      // mapped type
     *   'length'          => 255|null,
     *   'precision'       => int|null,         // decimal/numeric total digits, e.g. 12 for decimal(12,4)
     *   'scale'           => int|null,         // decimal/numeric digits after the point, e.g. 4 for decimal(12,4)
     *   'nullable'        => bool,
     *   'default'         => string|null,
     *   'is_fk'           => bool,
     *   'foreign_table'   => string|null,
     *   'foreign_column'  => string|null,
     *   'is_unique'       => bool,
     *   'indexed'         => bool,             // true if this column participates in ANY non-primary DB index (single- or multi-column)
     *   'enum_values'     => string[]|null,    // allowed values when normalized_type === 'enum', else null
     *   'morph_role'      => 'type'|'id'|null,
     *   'morph_name'      => string|null,
     * ]
     */
    public function columns(): array
    {
        $rawColumns  = $this->schema()->getColumns($this->table);
        $foreignKeys = $this->parseForeignKeys();
        $indexedCols = $this->parseIndexedColumns();
        $uniqueCols  = $this->parseUniqueColumns();

        $results = [];

        foreach ($rawColumns as $col) {
            $name = $col['name'];

            if (in_array($name, static::SKIP_COLUMNS, true)) {
                continue;
            }

            $rawType = strtolower($col['type_name'] ?? $col['type'] ?? 'string');

            // MySQL has no native BOOLEAN type — `$table->boolean()` migrations
            // compile to TINYINT(1), indistinguishable from a genuine small
            // integer once only the bare type name ("tinyint") is considered.
            // The display width survives in the full `type` string (e.g.
            // "tinyint(1)" vs "tinyint(4)"); recover it here so it can
            // actually reach normalizeType()'s `'tinyint(1)' => 'boolean'`
            // mapping below, which — until now — could never be hit, since
            // $rawType never carried the width in the first place.
            if ($rawType === 'tinyint' && preg_match('/^tinyint\(1\)/i', (string) ($col['type'] ?? ''))) {
                $rawType = 'tinyint(1)';
            }

            $fkInfo = $foreignKeys[$name] ?? null;

            if ($fkInfo === null && Str::endsWith($name, '_id')) {
                $fkInfo = $this->inferFkByConvention($name, $indexedCols);
            }

            $normalized = $this->normalizeType($rawType, $name, $fkInfo !== null);

            $precisionScale = self::extractPrecisionScale($col);

            $results[] = [
                'name'            => $name,
                'type'            => $rawType,
                'normalized_type' => $normalized,
                'length'          => $this->extractLength($col),
                'precision'       => $precisionScale['precision'],
                'scale'           => $precisionScale['scale'],
                'nullable'        => (bool) ($col['nullable'] ?? false),
                'default'         => $col['default'] ?? null,
                'is_fk'           => $fkInfo !== null,
                'foreign_table'   => $fkInfo['foreign_table'] ?? null,
                'foreign_column'  => $fkInfo['foreign_column'] ?? null,
                'is_unique'       => in_array($name, $uniqueCols, true),
                'indexed'         => in_array($name, $indexedCols, true),
                'enum_values'     => $normalized === 'enum' ? self::extractEnumValues($col) : null,
                'morph_role'      => null,
                'morph_name'      => null,
            ];
        }

        return $this->tagMorphPairs($results);
    }

    /**
     * Non-primary-key index groups for this table, parsed from the live DB via
     * Schema::getIndexes(). Includes BOTH single- and multi-column indexes,
     * unique and non-unique alike — callers (IntrospectionToConfig) decide how
     * to route each group (e.g. single-column unique -> the column's own
     * `is_unique` flag already covers it; multi-column unique -> a table-level
     * unique constraint; non-unique -> a table-level index).
     *
     * Each entry: ['name' => string|null, 'columns' => string[], 'unique' => bool].
     *
     * Thin live-schema wrapper around the pure, unit-testable
     * self::normalizeIndexGroups() — see that method's docblock.
     */
    public function indexGroups(): array
    {
        return self::normalizeIndexGroups($this->schema()->getIndexes($this->table));
    }

    /**
     * Pure filtering/shaping half of indexGroups() — separated out so it's
     * unit-testable against a hand-built Schema::getIndexes()-shaped array,
     * without a live DB connection (see filterFileColumns()'s docblock for the
     * same rationale — this package has no illuminate/database dev dependency).
     *
     * Drops the primary-key index (identified by the `primary` flag
     * Schema::getIndexes() already sets, not by name/column guessing) and any
     * index with no columns.
     *
     * @param array<int, array<string, mixed>> $rawIndexes Schema::getIndexes()-shaped rows.
     * @return array<int, array{name: string|null, columns: string[], unique: bool}>
     */
    public static function normalizeIndexGroups(array $rawIndexes): array
    {
        $groups = [];

        foreach ($rawIndexes as $index) {
            if (!empty($index['primary'])) {
                continue;
            }

            $columns = array_values($index['columns'] ?? []);
            if (empty($columns)) {
                continue;
            }

            $groups[] = [
                'name'    => $index['name'] ?? null,
                'columns' => $columns,
                'unique'  => (bool) ($index['unique'] ?? false),
            ];
        }

        return $groups;
    }

    /**
     * Extract (precision, scale) from a Schema::getColumns()-shaped column row's
     * full `type` string (e.g. "decimal(12,4)" or "decimal(12,4) unsigned").
     * Laravel's getColumns() does not surface precision/scale as their own
     * keys — only the full rendered type string carries them (same situation
     * extractLength() already deals with for `length`) — so they must be
     * regex-parsed out of it here.
     *
     * Deliberately generic (matches any "(int,int)" pair, not just on
     * decimal/numeric raw types): a single bare "(255)" (e.g. varchar(255) or
     * int(11)) never matches the two-number pattern, so this is safe to run
     * unconditionally and naturally returns [null, null] for every non-decimal
     * column without a type allow-list to keep in sync.
     *
     * @param array<string, mixed> $col Schema::getColumns()-shaped column row.
     * @return array{precision: int|null, scale: int|null}
     */
    public static function extractPrecisionScale(array $col): array
    {
        $fullType = (string) ($col['type'] ?? '');

        if (preg_match('/\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $fullType, $m)) {
            return ['precision' => (int) $m[1], 'scale' => (int) $m[2]];
        }

        return ['precision' => null, 'scale' => null];
    }

    /**
     * Extract allowed values from a Schema::getColumns()-shaped column row's
     * full `type` string for an ENUM column, e.g. "enum('active','inactive')"
     * -> ['active', 'inactive']. Handles escaped single quotes within a value
     * (MySQL's DDL syntax for a literal quote inside an enum value).
     *
     * Returns null (not an empty array) when the type string isn't a
     * recognizable enum literal, so callers can distinguish "not an enum" from
     * "an enum with zero values" (which MySQL doesn't actually allow, but the
     * distinction keeps the return type honest).
     *
     * @param array<string, mixed> $col Schema::getColumns()-shaped column row.
     * @return string[]|null
     */
    public static function extractEnumValues(array $col): ?array
    {
        $fullType = trim((string) ($col['type'] ?? ''));

        if (!preg_match('/^enum\((.*)\)/i', $fullType, $m)) {
            return null;
        }

        if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $vm)) {
            return [];
        }

        return array_map(
            static fn(string $v) => str_replace(["\\'", '\\\\'], ["'", '\\'], $v),
            $vm[1]
        );
    }

    /**
     * Infer which of this table's columns are most likely file/media upload
     * columns, from the live schema alone — no caller hint required.
     *
     * IntrospectionToConfig::buildFrontendFormFields() only ever renders a
     * `file-input` field when the column name appears in the caller-supplied
     * `$meta['file_columns']` array (there is no automatic detection
     * upstream of that check). Every generated module with an image/document
     * column therefore needed hand-patching after generation. This method
     * closes that gap on the schema side: a caller (e.g. the `make:module`
     * introspection flow) can fold its result into `$meta['file_columns']`
     * — `array_unique(array_merge($meta['file_columns'] ?? [],
     * $introspector->fileColumns()))` — so inference augments rather than
     * replaces an explicit hint; explicit caller-supplied names always win
     * since they are consumed first and this method's job is only to
     * populate that same array, never to bypass it.
     *
     * Deliberately conservative (false negatives preferred over false
     * positives), across TWO independent branches:
     *
     * String/text branch (a raw path/filename stored as text):
     *   - only string/text/longText columns are candidates — a column
     *     already typed json/boolean/numeric/date/foreignId is never
     *     inferred as a file column no matter what it's named;
     *   - foreign keys are always excluded, even if the name happens to
     *     match a pattern below (e.g. a hypothetical `avatar_id` FK into a
     *     media/attachments table is a relation, not a raw path column);
     *   - matching is by SUFFIX (`*_file`, `*_path`, `*_image`, `*_photo`,
     *     `*_avatar`, `*_logo`, `*_attachment`, `*_document`) or EXACT
     *     whole-name match (`image`, `photo`, `avatar`, `logo`, `file`) —
     *     never a bare substring search, so e.g. `image_url` does NOT match
     *     (no `_url` pattern; also reads as an external link, not a local
     *     upload) and a routing/menu table's bare `path` column does NOT
     *     match (only the `_path` suffix form is trusted — see
     *     FILE_COLUMN_EXACT_PATTERNS's docblock for why `path` is excluded
     *     from the exact list).
     *
     * Integer/FK-shaped `*_media_id` branch (a row id into a `media` table —
     * see MEDIA_ID_COLUMN_SUFFIX's docblock for why this is this project's
     * REAL file-upload convention, and why NAME is the authoritative signal
     * here rather than FK-target info):
     *   - only integer/bigInteger/foreignId columns are candidates
     *     (MEDIA_ID_COLUMN_TYPES);
     *   - matching is by SUFFIX (`*_media_id`) or EXACT whole-name match
     *     (`media_id`) — same non-substring discipline as the string branch;
     *   - unlike the string branch, `is_fk` does NOT exclude a match here —
     *     a `*_media_id`-named column is treated as a file column whether or
     *     not it happens to carry real FK evidence, since this convention's
     *     real-world instances never do (see MEDIA_ID_COLUMN_SUFFIX's
     *     docblock);
     *   - a differently-named FK that merely happens to target a table
     *     called `media` (e.g. a hypothetical `avatar_id -> media`) is still
     *     excluded — name is required, FK-target alone is never sufficient,
     *     so this can't false-positive on an unrelated relation.
     *
     * @return string[] Column names inferred as file/media uploads.
     */
    public function fileColumns(): array
    {
        return self::filterFileColumns($this->columns());
    }

    /**
     * Pure filtering half of fileColumns() — separated out so the heuristic
     * itself is unit-testable against a hand-built `columns()`-shaped array,
     * without requiring a live DB connection (this package has no
     * illuminate/database dev dependency to spin up a real Schema facade
     * against in tests). fileColumns() is the thin live-schema wrapper
     * around this; see its docblock for the full heuristic rationale.
     *
     * @param array<int, array<string, mixed>> $columns SchemaIntrospector::columns()-shaped rows.
     * @return string[] Column names inferred as file/media uploads.
     */
    public static function filterFileColumns(array $columns): array
    {
        $stringLikeTypes = ['string', 'text', 'longText'];
        $matches = [];

        foreach ($columns as $col) {
            $normalizedType = $col['normalized_type'] ?? null;
            $name = strtolower($col['name']);

            // Integer/FK-shaped `*_media_id` branch — see
            // MEDIA_ID_COLUMN_SUFFIX's and fileColumns()'s docblocks. Checked
            // first and independently of `is_fk`: this branch's name match
            // is authoritative on its own, so an ordinary FK is only ever
            // excluded here by NOT matching the media_id name pattern (e.g.
            // `item_type_id`, `parent_id`, `category_id`), never by its
            // `is_fk`/foreign_table value.
            if (in_array($normalizedType, self::MEDIA_ID_COLUMN_TYPES, true)) {
                if ($name === self::MEDIA_ID_COLUMN_EXACT || str_ends_with($name, self::MEDIA_ID_COLUMN_SUFFIX)) {
                    $matches[] = $col['name'];
                }
                continue;
            }

            if (!empty($col['is_fk'])) {
                continue;
            }

            if (!in_array($normalizedType, $stringLikeTypes, true)) {
                continue;
            }

            if (in_array($name, self::FILE_COLUMN_EXACT_PATTERNS, true)) {
                $matches[] = $col['name'];
                continue;
            }

            foreach (self::FILE_COLUMN_SUFFIX_PATTERNS as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    $matches[] = $col['name'];
                    continue 2;
                }
            }
        }

        return $matches;
    }

    /**
     * Detect and tag polymorphic morph pairs within a column set.
     */
    private function tagMorphPairs(array $columns): array
    {
        $byName = [];
        foreach ($columns as $i => $col) {
            $byName[$col['name']] = $i;
        }

        $stringTypes  = ['varchar', 'char', 'string', 'text', 'mediumtext', 'longtext'];
        $integerTypes = ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'unsigned bigint'];

        foreach ($columns as $i => $col) {
            $name = $col['name'];

            if (!Str::endsWith($name, '_type')) {
                continue;
            }
            if (!in_array(strtolower($col['type']), $stringTypes, true)) {
                continue;
            }

            $prefix = substr($name, 0, -5);
            $idName = $prefix . '_id';

            if (!isset($byName[$idName])) {
                continue;
            }

            $idIdx = $byName[$idName];
            $idCol = $columns[$idIdx];

            if (!in_array(strtolower($idCol['type']), $integerTypes, true)) {
                continue;
            }

            $camelPrefix = lcfirst(Str::camel($prefix));
            $columns[$i]['morph_role'] = 'type';
            $columns[$i]['morph_name'] = $camelPrefix;

            $columns[$idIdx]['morph_role']    = 'id';
            $columns[$idIdx]['morph_name']    = $camelPrefix;
            $columns[$idIdx]['is_fk']         = false;
            $columns[$idIdx]['foreign_table']  = null;
            $columns[$idIdx]['foreign_column'] = null;
        }

        return $columns;
    }

    /**
     * Build a global FK graph across all application tables.
     *
     * Returns: [target_table => [['source_table' => string, 'source_column' => string], ...]]
     */
    public static function globalForeignKeys(): array
    {
        $skipTables = [
            'migrations', 'password_reset_tokens', 'password_resets',
            'sessions', 'jobs', 'job_batches', 'failed_jobs',
            'personal_access_tokens', 'cache', 'cache_locks',
        ];

        $graph = [];

        $allTables = Schema::getTableListing();

        $bareTableSet = [];
        foreach ($allTables as $t) {
            $bare = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
            $bareTableSet[$bare] = true;
        }

        foreach ($allTables as $table) {
            $bareTable = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (in_array($bareTable, $skipTables, true)) {
                continue;
            }

            try {
                $fks = Schema::getForeignKeys($table);
            } catch (\Exception) {
                $fks = [];
            }

            $realFkColumns = [];
            foreach ($fks as $fk) {
                $localCols    = $fk['columns']       ?? [];
                $foreignTable = $fk['foreign_table'] ?? null;

                if (!$foreignTable) {
                    continue;
                }

                $bareForeign = str_contains($foreignTable, '.') ? substr($foreignTable, strrpos($foreignTable, '.') + 1) : $foreignTable;

                foreach ($localCols as $localCol) {
                    $realFkColumns[$localCol] = true;
                    $graph[$bareForeign][] = [
                        'source_table'  => $bareTable,
                        'source_column' => $localCol,
                    ];
                }
            }

            try {
                $columns = Schema::getColumns($table);
            } catch (\Exception) {
                continue;
            }

            foreach ($columns as $col) {
                $colName = $col['name'];

                if (isset($realFkColumns[$colName])) {
                    continue;
                }

                if (in_array($colName, ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by_id', 'updated_by_id'], true)) {
                    continue;
                }

                if (!Str::endsWith($colName, '_id')) {
                    continue;
                }

                $rawType = strtolower($col['type_name'] ?? $col['type'] ?? '');
                if (!in_array($rawType, ['bigint', 'int', 'integer', 'smallint', 'tinyint'], true)) {
                    continue;
                }

                $base   = preg_replace('/_id$/', '', $colName);
                $plural = Str::plural($base);
                $single = Str::singular($base);

                if (isset($bareTableSet[$plural])) {
                    $target = $plural;
                } elseif ($plural !== $single && isset($bareTableSet[$single])) {
                    $target = $single;
                } else {
                    continue;
                }

                $graph[$target][] = [
                    'source_table'  => $bareTable,
                    'source_column' => $colName,
                ];
            }
        }

        return $graph;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function inferFkByConvention(string $columnName, array $indexedCols): ?array
    {
        // `parent_id` is a universal self-referential-hierarchy convention
        // (categories, locations, org units, comment/menu trees, ...). Unlike
        // `item_type_id` -> `item_types`, it never encodes a literal target
        // table name in the column itself, so the plural/singular table-name
        // match below can never succeed for it — it would always fall through
        // to `return null`, silently classifying the column as a plain integer
        // and dropping the relation entirely. Recognize it explicitly as a
        // self-reference to the table currently being introspected.
        if ($columnName === 'parent_id') {
            if (!in_array($columnName, $indexedCols, true)) {
                $this->issueWarning("Column `{$this->table}`.`{$columnName}` looks like a FK (→ {$this->table}) but has no index.");
            }

            return [
                'foreign_table'  => $this->table,
                'foreign_column' => 'id',
            ];
        }

        $base   = preg_replace('/_id$/', '', $columnName);
        $plural = Str::plural($base);
        $single = Str::singular($base);

        if ($this->schema()->hasTable($plural)) {
            $target = $plural;
        } elseif ($plural !== $single && $this->schema()->hasTable($single)) {
            $target = $single;
        } else {
            return null;
        }

        if (!in_array($columnName, $indexedCols, true)) {
            $this->issueWarning("Column `{$this->table}`.`{$columnName}` looks like a FK (→ {$target}) but has no index.");
        }

        return [
            'foreign_table'  => $target,
            'foreign_column' => 'id',
        ];
    }

    private function issueWarning(string $message): void
    {
        if (self::$issueHandler !== null) {
            (self::$issueHandler)($message, 'warning');
        }
    }

    /** @var callable|null */
    private static $issueHandler = null;

    public static function setIssueHandler(?callable $handler): void
    {
        self::$issueHandler = $handler;
    }

    private function parseForeignKeys(): array
    {
        $fks    = $this->schema()->getForeignKeys($this->table);
        $result = [];

        foreach ($fks as $fk) {
            $localCols    = $fk['columns']         ?? [];
            $foreignCols  = $fk['foreign_columns']  ?? [];
            $foreignTable = $fk['foreign_table']    ?? null;

            foreach ($localCols as $i => $localCol) {
                $result[$localCol] = [
                    'foreign_table'  => $foreignTable,
                    'foreign_column' => $foreignCols[$i] ?? 'id',
                ];
            }
        }

        return $result;
    }

    private function parseUniqueColumns(): array
    {
        $indexes = $this->schema()->getIndexes($this->table);
        $unique  = [];

        foreach ($indexes as $index) {
            if (!empty($index['unique']) && count($index['columns'] ?? []) === 1) {
                $unique[] = $index['columns'][0];
            }
        }

        return $unique;
    }

    private function parseIndexedColumns(): array
    {
        $indexes = $this->schema()->getIndexes($this->table);
        $indexed = [];

        foreach ($indexes as $index) {
            foreach ($index['columns'] ?? [] as $colName) {
                $indexed[] = $colName;
            }
        }

        return array_unique($indexed);
    }

    private function normalizeType(string $rawType, string $colName, bool $isFk): string
    {
        // IMPORTANT: only trust real FK evidence here — $isFk is already true
        // whenever a genuine DB foreign key constraint exists OR the
        // "ends in _id" naming convention matched an ACTUALLY EXISTING
        // target table (see inferFkByConvention()). Previously this also
        // OR'd in a bare `Str::endsWith($colName, '_id')` check, which
        // classified ANY `_id`-suffixed column as 'foreignId' — including
        // plain string/business columns that merely happen to end in `_id`
        // (e.g. a `external_trans_id` idempotency key column, which is a
        // VARCHAR with no matching table). That false classification is what
        // let ModelGenerator's relation-guessing heuristic emit a belongsTo()
        // pointing at a nonexistent module (e.g. a guessed "ExternalTrans").
        // Column naming alone must never override the actual DB column type.
        if ($isFk) {
            return 'foreignId';
        }

        return match (true) {
            in_array($rawType, ['varchar', 'char', 'string'])              => 'string',
            in_array($rawType, ['text', 'mediumtext'])                     => 'text',
            in_array($rawType, ['longtext'])                               => 'longText',
            in_array($rawType, ['int', 'integer', 'smallint', 'tinyint'])  => 'integer',
            in_array($rawType, ['bigint', 'unsigned bigint'])              => 'bigInteger',
            in_array($rawType, ['decimal', 'numeric', 'float', 'double'])  => 'decimal',
            in_array($rawType, ['tinyint(1)', 'boolean', 'bool'])          => 'boolean',
            in_array($rawType, ['enum'])                                   => 'enum',
            in_array($rawType, ['date'])                                   => 'date',
            in_array($rawType, ['datetime', 'timestamp'])                  => 'datetime',
            in_array($rawType, ['json', 'jsonb'])                          => 'json',
            default                                                        => 'string',
        };
    }

    private function extractLength(array $col): ?int
    {
        if (!empty($col['length'])) {
            return (int) $col['length'];
        }

        if (preg_match('/\((\d+)\)/', $col['type'] ?? '', $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
