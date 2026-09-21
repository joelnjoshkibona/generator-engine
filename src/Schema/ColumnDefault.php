<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * A column default, as the database reports it, turned into the value a generator should emit.
 *
 * MariaDB (10.2.7+) does not report a default the way MySQL does. Through information_schema it returns:
 *
 *  - the four-character STRING `NULL` for a nullable column that has no default, where MySQL returns a real NULL;
 *  - a string default WITH its quotes: `status VARCHAR(20) DEFAULT 'UNPAID'` comes back as `'UNPAID'`.
 *
 * Both were copied verbatim into module.json and from there into every generated file:
 *
 *  - the migration wrote `->nullable()->default('NULL')`, a default of the word NULL, which MySQL's strict
 *    mode rejects on a fresh database (1067 Invalid default value). It hid in normal development because a
 *    database seeded from a dump never runs that migration; the first fresh test database or CI run did;
 *  - the factory wrote `'status' => '\'UNPAID\''`, a value with two quote characters in it, so every fixture
 *    built from it was silently wrong (no error until something read the status).
 *
 * The fix belongs at the boundary: SchemaIntrospector normalises what it reads, so module.json holds the
 * real value. BaseGenerator normalises again, because a module.json written by an older engine still holds
 * the raw form and is regenerated from as it stands.
 *
 * Only these two shapes are touched. A number, a keyword (CURRENT_TIMESTAMP) or an expression is returned
 * unchanged: what those mean is for the generator that emits them. The rule is idempotent for every real
 * default but one, a value that itself begins and ends with a quote character, which would lose one pair per
 * pass; nothing in a column default is written that way in practice.
 */
final class ColumnDefault
{
    /**
     * @param mixed $default the raw default: a string as the database reported it, a scalar from a hand-written
     *                       config, or null / '' for "no default" (both are returned as they are)
     * @return mixed null for a NULL default, the unquoted text for a quoted string, anything else unchanged
     */
    public static function normalize(mixed $default): mixed
    {
        if (!is_string($default)) {
            return $default;
        }

        $trimmed = trim($default);

        if (strcasecmp($trimmed, 'NULL') === 0) {
            return null;
        }

        if (strlen($trimmed) >= 2 && $trimmed[0] === "'" && $trimmed[strlen($trimmed) - 1] === "'") {
            // SQL escapes a quote inside a string literal by doubling it.
            return str_replace("''", "'", substr($trimmed, 1, -1));
        }

        return $default;
    }

    /**
     * A module config with every column default (and every frontend field default) normalised. Anything else,
     * and any column that carries no `default` key at all, is left exactly as it was.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalizeConfig(array $config): array
    {
        // `previous_columns` / `new_columns` are what MigrationUpdateGenerator diffs.
        foreach (['columns', 'previous_columns', 'new_columns'] as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $config[$key] = self::normalizeList($config[$key]);
            }
        }

        foreach (['list', 'create', 'view', 'edit', 'delete'] as $view) {
            $fields = $config['features']['frontend'][$view]['fields'] ?? null;
            if (is_array($fields)) {
                $config['features']['frontend'][$view]['fields'] = self::normalizeList($fields);
            }
        }

        return $config;
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int|string, mixed>
     */
    private static function normalizeList(array $items): array
    {
        foreach ($items as $i => $item) {
            if (is_array($item) && array_key_exists('default', $item)) {
                $items[$i]['default'] = self::normalize($item['default']);
            }
        }

        return $items;
    }
}
