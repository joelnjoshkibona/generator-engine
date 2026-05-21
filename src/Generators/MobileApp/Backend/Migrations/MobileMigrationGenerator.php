<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Illuminate\Support\Str;

class MobileMigrationGenerator extends BaseMobileBackendGenerator
{
    /** Columns managed by the stub itself — skip them in schema generation. */
    private const STUB_MANAGED_COLUMNS = [
        'uuid', 'id', 'created_at', 'updated_at', 'deleted_at', 'last_synced_at',
    ];

    public function generate(): bool
    {
        $content = $this->loadStub('migrations/create_table');

        $tableName = $this->resolveTableName();
        $timestamp = date('Y_m_d_His');

        $replacements = [
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[moduleName]]'  => $this->moduleName,
            '[[tableName]]'   => $tableName,
            '[[schema]]'      => $this->buildSchema(),
            '[[indexes]]'     => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Migrations/{$timestamp}_create_{$tableName}_table.php";

        return $this->writeFile($filePath, $content);
    }

    private function resolveTableName(): string
    {
        return $this->config['table_name'] ?? Str::snake(Str::plural($this->moduleName));
    }

    private function buildSchema(): string
    {
        $columns = $this->config['columns'] ?? [];
        if (empty($columns)) {
            return '';
        }

        $lines = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = $column['name'] ?? '';
            if ($name === '' || in_array($name, self::STUB_MANAGED_COLUMNS, true)) {
                continue;
            }

            $type     = strtolower($column['type'] ?? 'string');
            $required = ($column['required'] ?? false) === true || ($column['nullable'] ?? true) === false;

            $definition = $this->resolveColumnDefinition($name, $type);

            if (!$required) {
                $definition .= '->nullable()';
            }

            $lines[] = "            {$definition};";
        }

        return implode("\n", $lines);
    }

    private function resolveColumnDefinition(string $name, string $type): string
    {
        return match ($type) {
            'string', 'varchar'          => "\$table->string('{$name}')",
            'text', 'longtext'           => "\$table->text('{$name}')",
            'integer', 'int'             => "\$table->integer('{$name}')",
            'bigint'                     => "\$table->bigInteger('{$name}')",
            'boolean', 'bool'            => "\$table->boolean('{$name}')->default(false)",
            'decimal', 'float', 'double' => "\$table->decimal('{$name}', 10, 2)",
            'date'                       => "\$table->date('{$name}')",
            'datetime', 'timestamp'      => "\$table->timestamp('{$name}')",
            'json'                       => "\$table->text('{$name}')",    // SQLite has no native JSON
            'uuid'                       => "\$table->uuid('{$name}')->nullable()",
            default                      => "\$table->string('{$name}')",
        };
    }
}
