<?php

namespace Blutrixx\GeneratorEngine\Schema;

use Blutrixx\GeneratorEngine\Generators\PathManager;

/**
 * Explicit foreign-key targets for columns whose NAME does not say which table they point at.
 *
 * Introspection recognises a `*_id` column as a foreign key from its name alone when the database declares no
 * constraint: the `_id`-stripped base word has to pluralise or singularise to a real table
 * (SchemaIntrospector::resolveFkTargetByName()). That cannot work for a compound noun:
 *
 *   category_id         -> item_categories    (the base word is not in the table name)
 *   unit_of_measure_id  -> units_of_measure   (pluralised on the wrong word)
 *
 * Nothing in the column name can answer that, so it is declared. A project keeps a small JSON file,
 * `fk_aliases.json`, in its backend root (next to artisan):
 *
 *   {
 *     "category_id":         "item_categories",
 *     "unit_of_measure_id":  "units_of_measure",
 *     "sales_orders.source_quotation_id": "quotations"
 *   }
 *
 * A key is a column name (any table) or `table.column` (that table only, and it wins over the bare column).
 * Keys starting with an underscore (`_comment`) are ignored. The file is read on every introspection, so the
 * declaration survives every `--force` and full blueprint regenerate, unlike a hand-patch to a generated module.json.
 * An alias whose target table does not exist is reported and ignored, never trusted.
 *
 * REMEMBERED foreign keys are the second source. A developer who corrects a column by hand in module.json
 * (`type: foreignId`, `relatedModule: ...`) has stated a foreign key the database does not, and the next `--force`
 * re-introspects the table and used to demote it back to a plain integer (the module's frontend fields are built
 * from the columns, so merging the old column back in afterwards would not have fixed the form). The scaffolder
 * calls rememberFromConfig() with the persisted module.json BEFORE introspecting, so the correction is an input
 * to introspection instead of something introspection overwrites. A real database constraint still wins over
 * everything (this is only asked when there is none), an entry in fk_aliases.json wins over a remembered one,
 * and a remembered target that no longer exists is ignored.
 */
final class FkAliases
{
    public const FILE = 'fk_aliases.json';

    /** @var array<string, string>|null null = not loaded yet */
    private static ?array $aliases = null;

    /** @var array<string, string> `table.column` => table, from persisted module.json files; below the file in precedence */
    private static array $remembered = [];

    /** Replace the aliases (a command that has its own source, and tests). */
    public static function set(?array $aliases): void
    {
        self::$aliases = $aliases === null ? null : self::clean($aliases);
    }

    /** Forget everything, including a loaded file and everything remembered: the next lookup reads the file again. */
    public static function reset(): void
    {
        self::$aliases = null;
        self::$remembered = [];
    }

    /**
     * Everything the resolver should consult: remembered foreign keys first, then what the developer declared, so
     * that a declaration overrides a remembered entry for the same key.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_replace(self::$remembered, self::declared());
    }

    /** @return array<string, string> only what was declared (the file, or set()) */
    public static function declared(): array
    {
        return self::$aliases ??= self::load();
    }

    /** The declared target table for $table.$column, or null. `table.column` wins over the bare column. */
    public static function lookup(string $table, string $column): ?string
    {
        $declared = self::declared();

        return $declared["{$table}.{$column}"] ?? $declared[$column] ?? null;
    }

    /** Remember that $table.$column points at $target (see the class docblock). */
    public static function remember(string $table, string $column, string $target): void
    {
        if ($table !== '' && $column !== '' && $target !== '') {
            self::$remembered["{$table}.{$column}"] = $target;
        }
    }

    /**
     * Remember every foreign key a persisted module.json states: a column of `type: foreignId` whose
     * `relatedModule` resolves (through the module registry) to a table. Anything else is left for introspection.
     *
     * @param array<string, mixed> $persisted a module.json, decoded
     * @return int how many were remembered
     */
    public static function rememberFromConfig(array $persisted): int
    {
        $table = (string) ($persisted['table_name'] ?? '');
        $count = 0;

        foreach ((array) ($persisted['columns'] ?? []) as $column) {
            if (!is_array($column) || ($column['type'] ?? null) !== 'foreignId' || empty($column['name']) || empty($column['relatedModule'])) {
                continue;
            }

            $target = PathManager::resolveTableNameForModule((string) $column['relatedModule']);
            if ($target !== null && $target !== '') {
                self::remember($table, (string) $column['name'], $target);
                $count++;
            }
        }

        return $count;
    }

    /** Where the file is looked for: the backend root of the running application, or null with none booted. */
    public static function path(): ?string
    {
        $base = PathManager::fromLaravel(static fn () => base_path(), null);

        return is_string($base) && $base !== '' ? rtrim($base, '/') . '/' . self::FILE : null;
    }

    /** @return array<string, string> */
    private static function load(): array
    {
        $path = self::path();

        return $path !== null && is_file($path) ? self::readFile($path) : [];
    }

    /**
     * The aliases in one file. A file that is not a JSON object is reported and read as empty, never guessed at.
     *
     * @return array<string, string>
     */
    public static function readFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            PathManager::reportIssue(self::FILE . " at {$path} is not a JSON object of column => table; ignoring it.");

            return [];
        }

        return self::clean($decoded);
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, string>
     */
    private static function clean(array $raw): array
    {
        $aliases = [];
        foreach ($raw as $key => $table) {
            if (is_string($key) && $key !== '' && !str_starts_with($key, '_') && is_string($table) && $table !== '') {
                $aliases[$key] = $table;
            }
        }

        return $aliases;
    }
}
