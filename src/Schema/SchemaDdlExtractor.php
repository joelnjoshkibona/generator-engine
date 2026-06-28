<?php

namespace Blutrixx\GeneratorEngine\Schema;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SchemaDdlExtractor
 *
 * Extracts a faithful CREATE TABLE DDL string for an existing table.
 * Supports MySQL/MariaDB, SQLite, and PostgreSQL.
 */
class SchemaDdlExtractor
{
    public function __construct(private readonly string $table, private readonly ?string $connection = null) {}

    /**
     * Return the CREATE TABLE DDL for the table.
     *
     * @throws RuntimeException if the table does not exist or the driver is unsupported.
     */
    public function dump(): string
    {
        $db     = $this->connection ? DB::connection($this->connection) : DB::connection();
        $driver = $db->getDriverName();

        return match (true) {
            in_array($driver, ['mysql', 'mariadb'], true) => $this->dumpMysql($db),
            $driver === 'sqlite'                           => $this->dumpSqlite($db),
            $driver === 'pgsql'                            => $this->dumpPgsql($db),
            default                                        => throw new RuntimeException(
                "DDL extraction not supported for driver: {$driver}"
            ),
        };
    }

    // ─── Driver implementations ───────────────────────────────────────────────

    private function dumpMysql(\Illuminate\Database\Connection $db): string
    {
        $quoted = '`' . str_replace('`', '``', $this->table) . '`';
        $rows   = $db->select("SHOW CREATE TABLE {$quoted}");

        if (empty($rows)) {
            throw new RuntimeException("Table `{$this->table}` does not exist.");
        }

        $row = (array) $rows[0];

        // Key is 'Create Table' for MySQL, 'Create Table' or 'Create View' for MariaDB
        return $row['Create Table'] ?? $row['create table'] ?? array_values($row)[1];
    }

    private function dumpSqlite(\Illuminate\Database\Connection $db): string
    {
        $rows = $db->select(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name = ?",
            [$this->table]
        );

        if (empty($rows)) {
            throw new RuntimeException("Table `{$this->table}` does not exist.");
        }

        $ddl = ((array) $rows[0])['sql'];

        // Append index DDL
        $indexRows = $db->select(
            "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name = ? AND sql IS NOT NULL",
            [$this->table]
        );

        foreach ($indexRows as $indexRow) {
            $indexSql = ((array) $indexRow)['sql'];
            if ($indexSql) {
                $ddl .= ";\n" . $indexSql;
            }
        }

        return $ddl;
    }

    private function dumpPgsql(\Illuminate\Database\Connection $db): string
    {
        // Verify table exists
        $exists = $db->selectOne(
            "SELECT to_regclass(:table) AS oid",
            ['table' => $this->table]
        );
        if (!$exists || $exists->oid === null) {
            throw new RuntimeException("Table `{$this->table}` does not exist.");
        }

        // Build column definitions from information_schema
        $columns = $db->select(
            "SELECT column_name, data_type, character_maximum_length, numeric_precision,
                    numeric_scale, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_name = ? AND table_schema = current_schema()
             ORDER BY ordinal_position",
            [$this->table]
        );

        if (empty($columns)) {
            throw new RuntimeException("Table `{$this->table}` does not exist or has no columns.");
        }

        $colDefs = [];
        foreach ($columns as $col) {
            $col = (array) $col;
            $def = '    "' . $col['column_name'] . '" ';

            $type = strtoupper($col['data_type']);
            if (!empty($col['character_maximum_length'])) {
                $type .= '(' . $col['character_maximum_length'] . ')';
            } elseif (!empty($col['numeric_precision']) && !empty($col['numeric_scale'])) {
                $type .= '(' . $col['numeric_precision'] . ',' . $col['numeric_scale'] . ')';
            }

            $def .= $type;
            if ($col['is_nullable'] === 'NO') {
                $def .= ' NOT NULL';
            }
            if ($col['column_default'] !== null) {
                $def .= ' DEFAULT ' . $col['column_default'];
            }

            $colDefs[] = $def;
        }

        $ddl = "CREATE TABLE \"{$this->table}\" (\n" . implode(",\n", $colDefs) . "\n);";

        // Append constraint DDL (PRIMARY KEY, UNIQUE, CHECK, FOREIGN KEY)
        $constraints = $db->select(
            "SELECT conname, pg_get_constraintdef(c.oid) AS condef
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relname = ? AND t.relnamespace = current_schema()::regnamespace",
            [$this->table]
        );

        foreach ($constraints as $constraint) {
            $constraint = (array) $constraint;
            $ddl .= "\nALTER TABLE \"{$this->table}\" ADD CONSTRAINT \"{$constraint['conname']}\" {$constraint['condef']};";
        }

        // Append non-primary index DDL
        $indexes = $db->select(
            "SELECT indexdef
             FROM pg_indexes
             WHERE tablename = ? AND schemaname = current_schema()
               AND indexname NOT IN (
                   SELECT conname FROM pg_constraint
                   WHERE conrelid = ?::regclass AND contype IN ('p','u')
               )",
            [$this->table, $this->table]
        );

        foreach ($indexes as $index) {
            $ddl .= "\n" . ((array) $index)['indexdef'] . ';';
        }

        return $ddl;
    }
}
