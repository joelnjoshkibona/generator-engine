<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * DdlRenderer
 *
 * Renders a best-effort CREATE TABLE DDL from normalized column metadata.
 * Used as a fallback when the table does not yet exist in the database
 * and SchemaDdlExtractor cannot be used.
 */
class DdlRenderer
{
    /**
     * Render a skeleton CREATE TABLE statement from column metadata.
     *
     * Each column in $columns should have at least:
     *   - name           (string)
     *   - type|normalized_type (string)
     *   - nullable       (bool)
     *   - default        (string|null)
     *
     * @param string $table   Table name
     * @param array  $columns Normalized column metadata array
     * @param string $driver  DB driver hint (for minor syntax tweaks)
     */
    public static function fromColumns(string $table, array $columns, string $driver = 'mysql'): string
    {
        $lines   = [];
        $lines[] = '-- NOTE: skeleton DDL rendered from metadata; review before applying.';
        $lines[] = "CREATE TABLE `{$table}` (";

        $colDefs = [];
        foreach ($columns as $col) {
            $name     = $col['name'] ?? 'unknown';
            $type     = $col['normalized_type'] ?? $col['type'] ?? 'VARCHAR(255)';
            $nullable = (bool) ($col['nullable'] ?? true);
            $default  = $col['default'] ?? null;

            $sqlType = self::mapType($type, $driver);
            $def     = "    `{$name}` {$sqlType}";

            if (!$nullable) {
                $def .= ' NOT NULL';
            } else {
                $def .= ' NULL';
            }

            if ($default !== null) {
                $escaped = str_replace("'", "''", (string) $default);
                $def    .= " DEFAULT '{$escaped}'";
            }

            $colDefs[] = $def;
        }

        $lines[] = implode(",\n", $colDefs);
        $lines[] = ');';

        return implode("\n", $lines);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private static function mapType(string $type, string $driver): string
    {
        // SQLite resolves a column's affinity from substrings of the declared type
        // and has no UNSIGNED, no LONGTEXT/MEDIUMTEXT and no JSON type. Feeding it
        // the MySQL spellings mostly "works" by accident (unknown names fall back to
        // NUMERIC affinity) but silently mistypes exactly the columns a prototype
        // cares about: a JSON payload lands in a numeric column, and BIGINT UNSIGNED
        // loses the INTEGER affinity that a rowid primary key needs.
        //
        // Until this branch existed, $driver was accepted and then never read — every
        // caller got MySQL no matter what it asked for.
        if ($driver === 'sqlite') {
            return match (strtolower($type)) {
                'foreignid', 'biginteger', 'bigint',
                'integer', 'int', 'smallint',
                'tinyint', 'tinyinteger'           => 'INTEGER',
                'boolean', 'bool'                  => 'INTEGER',
                'string', 'varchar', 'uuid'        => 'TEXT',
                'text', 'longtext', 'mediumtext'   => 'TEXT',
                'json', 'jsonb'                    => 'TEXT',
                'date', 'datetime', 'timestamp',
                'time'                             => 'TEXT',
                'decimal', 'numeric',
                'float', 'double'                  => 'REAL',
                default                            => 'TEXT',
            };
        }

        return match (strtolower($type)) {
            'foreignid', 'biginteger', 'bigint'   => 'BIGINT UNSIGNED',
            'integer', 'int', 'smallint'           => 'INT',
            'tinyint', 'tinyinteger'               => 'TINYINT',
            'string', 'varchar'                    => 'VARCHAR(255)',
            'text'                                 => 'TEXT',
            'longtext'                             => 'LONGTEXT',
            'mediumtext'                           => 'MEDIUMTEXT',
            'boolean', 'bool'                      => 'TINYINT(1)',
            'date'                                 => 'DATE',
            'datetime', 'timestamp'                => 'DATETIME',
            'time'                                 => 'TIME',
            'decimal', 'numeric'                   => 'DECIMAL(10,2)',
            'float', 'double'                      => 'DOUBLE',
            'json', 'jsonb'                        => 'JSON',
            'uuid'                                 => 'CHAR(36)',
            default                                => strtoupper($type),
        };
    }

    /**
     * Render a COMPLETE physical table — convention columns included.
     *
     * fromColumns() renders only the business columns it is handed, which is right
     * for a skeleton a human will finish, but useless as something to actually
     * execute: every generated table also gets id / uuid / created_at / updated_at /
     * deleted_at / created_by_id / updated_by_id automatically, and the project's
     * authoring workflow instructs schema authors to leave those OUT of the config
     * (see SchemaConventions). A CREATE TABLE missing all of them cannot store a
     * generated module's rows.
     *
     * @param array<int, array<string, mixed>> $columns Business columns from module config.
     * @param array<string, bool>              $flags   has_timestamps / has_soft_deletes /
     *                                                  has_uuid / has_creator_updater.
     */
    public static function fullTable(
        string $table,
        array  $columns,
        array  $flags = [],
        string $driver = 'sqlite'
    ): string {
        $flags  = array_merge(SchemaConventions::DEFAULT_FLAGS, $flags);
        $quote  = $driver === 'sqlite' ? '"' : '`';
        $q      = static fn (string $name): string => $quote . $name . $quote;

        $defs = [];

        $defs[] = $driver === 'sqlite'
            ? '    ' . $q('id') . ' INTEGER PRIMARY KEY AUTOINCREMENT'
            : '    ' . $q('id') . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

        if ($flags['has_uuid']) {
            $defs[] = '    ' . $q('uuid') . ' ' . self::mapType('uuid', $driver) . ' NOT NULL';
        }

        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $type = $column['normalized_type'] ?? $column['type'] ?? 'string';
            $def  = '    ' . $q($name) . ' ' . self::mapType($type, $driver);
            $def .= ($column['nullable'] ?? true) ? ' NULL' : ' NOT NULL';

            $default = $column['default'] ?? null;
            if ($default !== null && $default !== '') {
                $def .= " DEFAULT '" . str_replace("'", "''", (string) $default) . "'";
            }

            $defs[] = $def;
        }

        if ($flags['has_creator_updater']) {
            foreach (['created_by_id', 'updated_by_id'] as $auditColumn) {
                $defs[] = '    ' . $q($auditColumn) . ' ' . self::mapType('bigint', $driver) . ' NULL';
            }
        }

        if ($flags['has_timestamps']) {
            foreach (['created_at', 'updated_at'] as $timestamp) {
                $defs[] = '    ' . $q($timestamp) . ' ' . self::mapType('datetime', $driver) . ' NULL';
            }
        }

        if ($flags['has_soft_deletes']) {
            $defs[] = '    ' . $q('deleted_at') . ' ' . self::mapType('datetime', $driver) . ' NULL';
        }

        return "CREATE TABLE IF NOT EXISTS " . $q($table) . " (\n" . implode(",\n", $defs) . "\n);";
    }
}
