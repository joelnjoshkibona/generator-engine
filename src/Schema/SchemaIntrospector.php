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
