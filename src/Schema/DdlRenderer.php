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
}
