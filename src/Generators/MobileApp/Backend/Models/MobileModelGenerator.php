<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;
use Illuminate\Support\Str;

class MobileModelGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('model');

        $replacements = [
            '[[moduleGroup]]'    => $this->moduleGroup,
            '[[moduleName]]'     => $this->moduleName,
            '[[tableName]]'      => $this->resolveTableName(),
            '[[fillable]]'       => $this->buildFillable(),
            '[[casts]]'          => $this->buildCasts(),
            '[[relationships]]'  => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/{$this->moduleName}Model.php";

        return $this->writeFile($filePath, $content);
    }

    private function resolveTableName(): string
    {
        return $this->config['table_name'] ?? Str::snake(Str::plural($this->moduleName));
    }

    private function buildFillable(): string
    {
        $columns = $this->config['columns'] ?? [];
        if (empty($columns)) {
            return '';
        }

        $lines = [];
        foreach ($columns as $column) {
            $name = is_array($column) ? ($column['name'] ?? '') : $column;
            if ($name === '') {
                continue;
            }
            $lines[] = "            '{$name}',";
        }

        return implode("\n", $lines);
    }

    private function buildCasts(): string
    {
        $columns = $this->config['columns'] ?? [];
        $casts   = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = $column['name'] ?? '';
            $type = strtolower($column['type'] ?? '');

            if ($name === '' || in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $cast = match ($type) {
                'boolean', 'bool', 'tinyint(1)' => 'boolean',
                'date'                           => 'date',
                'datetime', 'timestamp'          => 'datetime',
                'integer', 'int', 'bigint'       => 'integer',
                'decimal', 'float', 'double'     => 'float',
                'json', 'jsonb'                  => 'array',
                default                          => null,
            };

            if ($cast !== null) {
                $casts[$name] = $cast;
            }
        }

        if (empty($casts)) {
            return '';
        }

        $lines = [];
        foreach ($casts as $field => $cast) {
            $lines[] = "        '{$field}' => '{$cast}'";
        }

        return "protected \$casts = [\n" . implode(",\n", $lines) . "\n    ];";
    }
}
