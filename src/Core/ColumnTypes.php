<?php

namespace Blutrixx\GeneratorEngine\Core;

use Illuminate\Support\Str;

/**
 * Column types and the facts that follow from them, as data. Lifted from the legacy generators
 * (ModelGenerator::getCastType, BaseGenerator::deriveRelationshipMethodName, IntrospectionToConfig::buildBackendFields'
 * type branches); those keep their own copies (the legacy target is frozen).
 */
final class ColumnTypes
{
    public const STRING = ['string', 'varchar', 'char'];

    public const TEXT = ['text', 'longText', 'mediumText', 'tinyText', 'longtext', 'mediumtext', 'tinytext'];

    public const INTEGER = ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'unsignedInteger', 'unsignedBigInteger', 'biginteger', 'smallinteger', 'tinyinteger'];

    public const DECIMAL = ['decimal', 'float', 'double', 'numeric'];

    public const DATETIME = ['datetime', 'timestamp', 'dateTime'];

    /** One of: string, text, integer, decimal, boolean, date, datetime, time, json, enum, foreign, workflow, media (P131). */
    public static function kind(array $column): string
    {
        $type = (string) ($column['type'] ?? 'string');
        if ($type === 'workflow') {
            return 'workflow';
        }
        if ($type === 'media') {
            return 'media'; // P131: a file in the Media library, stored as <name>_media_id, sent as the media's uuid
        }
        if ($type === 'foreignId' || ! empty($column['relatedModule'])) {
            return 'foreign';
        }

        return match (true) {
            // P133: a short fixed list is a choice, never free text: a string with values is one
            in_array($type, self::STRING, true) && ! empty($column['values']) => 'enum',
            in_array($type, self::STRING, true) => 'string',
            in_array($type, self::TEXT, true) => 'text',
            in_array($type, self::INTEGER, true) => 'integer',
            in_array($type, self::DECIMAL, true) => 'decimal',
            in_array($type, self::DATETIME, true) => 'datetime',
            in_array($type, ['boolean', 'date', 'time', 'json', 'enum'], true) => $type,
            $type === 'jsonb' => 'json',
            default => 'string',
        };
    }

    /**
     * The column's `default` as a value of its kind, or null for none: a string or an enum's value, a whole or a
     * decimal number, true or false ("true"/"false" as a legacy config writes them). Other kinds keep no default.
     */
    public static function defaultValue(array $column): string|int|bool|null
    {
        if (! array_key_exists('default', $column) || $column['default'] === null || $column['default'] === '') {
            return null;
        }
        $d = $column['default'];

        return match (self::kind($column)) {
            'boolean' => $d === true || in_array(strtolower((string) $d), ['true', '1', 'yes'], true),
            'integer' => is_numeric($d) ? (int) $d : null,
            'decimal' => is_numeric($d) ? (string) (0 + $d) : null,
            'string', 'enum' => (string) $d,
            default => null,
        };
    }

    /** A default as a TypeScript literal (strings in single quotes; numbers as the form's text). */
    public static function jsDefault(string|int|bool $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
    }

    /** The Eloquent cast, or null for none (lifted from ModelGenerator::getCastType). */
    public static function cast(array $column): ?string
    {
        return match (self::kind($column)) {
            'json' => 'array',
            'boolean' => 'boolean',
            // date:Y-m-d, not date: a bare date cast serialises through UTC and can return the previous day
            'date' => 'date:Y-m-d',
            'datetime' => 'datetime',
            'decimal' => 'decimal:'.((int) ($column['scale'] ?? 2)),
            'time', 'enum' => 'string',
            default => null,
        };
    }

    /** `assignee_id` → `assignee`, `parent_location_id` → `parentLocation` (lifted from deriveRelationshipMethodName). */
    public static function relationName(string $column): string
    {
        return Str::camel(preg_replace('/_id$/', '', $column));
    }

    /**
     * The key the API sends a relation under: Laravel serialises a relation by its snake_case name, so `projectModule`
     * arrives as `project_module`. Code reading a row uses this; the camelCase name stays the list column's key and the
     * label's.
     */
    public static function relationKey(string $column): string
    {
        return \Illuminate\Support\Str::snake(self::relationName($column));
    }

    /** A string column's maximum length; 255 when none is given. */
    public static function length(array $column): int
    {
        $length = (int) ($column['length'] ?? 0);

        return $length > 0 ? $length : 255;
    }

    /** What people read for the column: its "label", else the headline of its name without `_id`. */
    public static function label(array $column): string
    {
        // P133: a yes/no reads as what it is ("Default"), not as a question ("Is Default")
        $name = (string) $column['name'];
        if (($column['type'] ?? null) === 'boolean') {
            $name = (string) preg_replace('/^(is|has)_/', '', $name);
        }

        // a percentage reads as what it is ("Rate", its value "18%"), not "Rate percent" (P133 amended 2026-11-23)
        if (self::isPercent($column)) {
            $name = (string) preg_replace('/_percent$/', '', $name);
        }

        // sentence case, as every other label ("Vendor type", not "Vendor Type")
        return (string) ($column['label'] ?? Str::ucfirst(Str::lower(Str::headline((string) preg_replace('/_id$/', '', $name)))));
    }

    /** A number that is a percentage: named `*_percent` (its value shows with %). */
    public static function isPercent(array $column): bool
    {
        return in_array(self::kind($column), ['integer', 'decimal'], true) && str_ends_with((string) $column['name'], '_percent');
    }

    /** A fixed list's value in words: the spec's `values_label` (M-Pesa), else its code as a headline (Mpesa). */
    public static function valueLabel(array $column, string $value): string
    {
        return (string) ($column['values_label'][$value] ?? Str::headline(strtolower($value)));
    }
}
