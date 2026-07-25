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
     *   'nullable'        => bool,
     *   'default'         => string|null,
     *   'is_fk'           => bool,
     *   'foreign_table'   => string|null,
     *   'foreign_column'  => string|null,
     *   'is_unique'       => bool,
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

            $results[] = [
                'name'            => $name,
                'type'            => $rawType,
                'normalized_type' => $normalized,
                'length'          => $this->extractLength($col),
                'nullable'        => (bool) ($col['nullable'] ?? false),
                'default'         => $col['default'] ?? null,
                'is_fk'           => $fkInfo !== null,
                'foreign_table'   => $fkInfo['foreign_table'] ?? null,
                'foreign_column'  => $fkInfo['foreign_column'] ?? null,
                'is_unique'       => in_array($name, $uniqueCols, true),
                'morph_role'      => null,
                'morph_name'      => null,
            ];
        }

        return $this->tagMorphPairs($results);
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
     * positives):
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
            if (!empty($col['is_fk'])) {
                continue;
            }

            if (!in_array($col['normalized_type'] ?? null, $stringLikeTypes, true)) {
                continue;
            }

            $name = strtolower($col['name']);

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
