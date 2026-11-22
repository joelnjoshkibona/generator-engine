<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * What a factory or a test puts in each column, as a kind and its parameters; each target spells its own Faker call or
 * literal (lifted from FactoryGenerator::buildScalarValue/buildForeignKeyValue and PhpUnitTestGenerator's
 * sample-value helpers).
 */
final class SampleValues
{
    /** @return array{kind: string, params: array<string, mixed>} */
    public static function for(ModuleSpec $spec, array $column): array
    {
        $kind = ColumnTypes::kind($column);
        $label = $column['name'] === $spec->labelColumn();
        // a column with a default is made with it (a record as the form would start it); the label and a unique
        // column still differ record to record
        if (! $label && empty($column['unique']) && ($default = ColumnTypes::defaultValue($column)) !== null) {
            return ['kind' => 'default', 'params' => ['value' => $default]];
        }

        return match ($kind) {
            'string' => ['kind' => $label || ! empty($column['unique']) ? 'unique_words' : 'words', 'params' => ['max' => ColumnTypes::length($column)]],
            'text' => ['kind' => 'sentence', 'params' => []],
            'integer' => ['kind' => 'integer', 'params' => []],
            'decimal' => ['kind' => 'decimal', 'params' => ['scale' => (int) ($column['scale'] ?? 2)]],
            'boolean' => ['kind' => 'boolean', 'params' => []],
            'date' => ['kind' => 'future_date', 'params' => ['days' => 30]],
            'datetime' => ['kind' => 'datetime', 'params' => []],
            'enum' => ['kind' => 'enum', 'params' => ['values' => array_values($column['values'] ?? [])]],
            'json' => ['kind' => 'json', 'params' => []],
            'foreign' => ['kind' => 'foreign', 'params' => ['module' => $column['relatedModule'], 'nullable' => (bool) ($column['nullable'] ?? false)]],
            'workflow' => ['kind' => 'workflow', 'params' => []],
            'media' => ['kind' => 'none', 'params' => []], // P131: a file is uploaded, never made up: left empty (always nullable)
            default => ['kind' => 'words', 'params' => []],
        };
    }
}
