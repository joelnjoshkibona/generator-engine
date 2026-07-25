<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Factories;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

/**
 * Generates a `{Module}Factory.php` co-located inside the module directory
 * (e.g. `App/Project/Modules/Core/ItemTypes/ItemTypesFactory.php`), matching
 * the hand-built convention in SYSTEM_SHELL (StatusesFactory, LocationsFactory,
 * MobileReleasesFactory, MediaFactory, UserInvitationsFactory, ...) rather
 * than Laravel's default `database/factories/` location — none of those
 * reference factories live there, and every one of them is picked up purely
 * through `{Module}Model::newFactory()` returning `{Module}Factory::new()`
 * (see BaseModel/StatusesModel), NOT Laravel's class-name-guessing default
 * resolution.
 *
 * This generator exists to close a real, confirmed bug: generated CRUD
 * tests (PhpUnitTestGenerator) run under RefreshDatabase, so a required
 * cross-module foreign key (e.g. Items.item_type_id -> item_types) has no
 * existing parent row to reference at fixture time. The fix is for the
 * dependent module's test to CREATE its own parent row via the parent
 * module's factory (`ItemTypesModel::factory()->create()->id`) instead of
 * looking one up. That only works once every module ships a factory, which
 * is what this generator produces.
 *
 * definition() values are derived straight from the module's own
 * `config['columns']` (the same shape MigrationGenerator/ModelGenerator
 * read) — never invented business data (this project's seeders
 * deliberately ship "data": [] for the same reason: a generic generator
 * cannot know what a real value SHOULD be), only structurally-valid
 * fake()/literal values that satisfy the column's own type/nullable/unique
 * constraints so a `->create()` call succeeds against a freshly migrated
 * database.
 */
class FactoryGenerator extends BaseGenerator
{
    protected array $columns;
    protected string $idType;

    /** Column names auto-managed elsewhere and never emitted individually here. */
    protected const MANAGED_COLUMNS = [
        'id', 'uuid', 'created_at', 'updated_at', 'deleted_at',
    ];

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->columns = $config['columns'] ?? [];
        $this->idType = $config['id_type'] ?? 'autoincrement';
    }

    public function generate(): bool
    {
        $lines = [];

        $lines[] = $this->buildIdLine();
        $lines[] = $this->buildUuidLine();

        foreach ($this->columns as $column) {
            $line = $this->buildColumnLine($column);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($this->hasCreatorUpdater()) {
            $lines[] = "            'created_by_id' => 1,";
        }

        $lines = array_values(array_filter($lines));
        $body = implode("\n", $lines);

        $namespace = $this->getNamespace();
        $modelFqcn = $this->moduleName . 'Model';

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\\{$namespace}\\{$modelFqcn}>
 */
class {$this->moduleName}Factory extends Factory
{
    protected \$model = {$modelFqcn}::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
{$body}
        ];
    }
}

PHP;

        $filePath = "{$this->modulePath}/{$this->moduleName}Factory.php";

        return $this->writeFile($filePath, $content);
    }

    protected function hasCreatorUpdater(): bool
    {
        return ModuleConfigContract::hasCreatorUpdater($this->config);
    }

    /**
     * Non-autoincrement primary keys (uuid/manual) need an explicit id —
     * mirrors UsersFactory/UserInvitationsFactory/PermissionsFactory, which
     * all set 'id' themselves because Eloquent's create() can't read back a
     * DB-generated default when $incrementing is false. Autoincrement ids
     * are omitted entirely, matching every reference-table factory
     * (StatusesFactory, LocationTypesFactory, ...).
     *
     * IMPORTANT: this must be a WHITELIST of the known non-autoincrement
     * types ('uuid'/'string'), not a blacklist of the literal string
     * 'autoincrement'. IntrospectionToConfig::build() (see its docblock)
     * documents `id_type` as only ever being `'uuid' | 'bigint'` for
     * real generated module.json configs — it never emits the literal
     * 'autoincrement'. A blacklist check against that one literal therefore
     * never matches a real 'bigint' module, so every generated factory fell
     * through to the uuid branch regardless of the column's real type —
     * this was a confirmed bug: MySQL rejected the resulting inserts with
     * "Data truncated for column 'id'" because the table's `id` is actually
     * `bigint unsigned AUTO_INCREMENT`. (The FactoryGenerator constructor's
     * own `?? 'autoincrement'` fallback only matters for hand-rolled/legacy
     * configs that omit `id_type` entirely; it still correctly omits the id
     * line via this whitelist since 'autoincrement' isn't in it.)
     */
    protected function buildIdLine(): ?string
    {
        if (!in_array($this->idType, ['uuid', 'string'], true)) {
            return null;
        }

        return "            'id' => (string) Str::uuid(),";
    }

    /**
     * Every hand-built factory sets 'uuid' explicitly even though it has a
     * DB-level default expression, because Eloquent's create() never reads
     * a DB-computed default back onto the in-memory model without an
     * explicit ->fresh()/->refresh() (see StatusesFactory's docblock).
     */
    protected function buildUuidLine(): ?string
    {
        if (!ModuleConfigContract::hasUuid($this->config)) {
            return null;
        }

        return "            'uuid' => (string) Str::uuid(),";
    }

    protected function buildColumnLine(array $column): ?string
    {
        $name = $column['name'] ?? null;
        if ($name === null || in_array($name, self::MANAGED_COLUMNS, true)) {
            return null;
        }

        // created_by_id/updated_by_id are handled separately (or, for
        // updated_by_id, deliberately omitted — no hand-built factory sets
        // it, since it's always nullable and irrelevant to a fresh fixture).
        if ($name === 'created_by_id' || $name === 'updated_by_id') {
            return null;
        }

        $type = $column['type'] ?? 'string';
        $nullable = (bool) ($column['nullable'] ?? false);
        $unique = (bool) ($column['unique'] ?? false);

        if ($type === 'foreignId' || str_ends_with($name, '_id')) {
            return "            '{$name}' => " . $this->buildForeignKeyValue($column, $nullable) . ',';
        }

        return "            '{$name}' => " . $this->buildScalarValue($name, $type, $unique, $column) . ',';
    }

    /**
     * Resolve a foreign-key column's factory value.
     *
     * Self-referential FKs (a hierarchy column pointing back at this same
     * module/table) and nullable FKs to a module this generator can't place
     * both degrade to `null` — exactly the same reasoning
     * PhpUnitTestGenerator::buildFieldValueLiteral() already documents for
     * the identical problem on the test-fixture side: on the very first
     * insert into an empty table there is categorically no parent row yet
     * for a self-reference to point at, and a related module we can't
     * identify has no factory we can safely reference.
     *
     * A REQUIRED (non-nullable) reference to a resolvable related module
     * recursively uses that module's own factory
     * (`\Ns\RelatedModel::factory()` — Eloquent resolves a Factory instance
     * used as an attribute value by creating it and substituting the new
     * row's key, exactly like MobileReleasesFactory's
     * `'apk_media_id' => MediaModel::factory()`). When the related module
     * can't be resolved, falls back to the literal `1` last resort called
     * out in the brief.
     */
    protected function buildForeignKeyValue(array $column, bool $nullable): string
    {
        $name = $column['name'];
        $relatedModuleName = $column['relatedModule'] ?? null;

        $isSelfReferential = $relatedModuleName === $this->moduleName
            || ($relatedModuleName === null && $this->looksSelfReferential($name));

        if ($isSelfReferential) {
            return 'null';
        }

        if ($relatedModuleName === null || $relatedModuleName === '') {
            return $nullable ? 'null' : '1';
        }

        if (!$this->relatedModuleResolvable($relatedModuleName)) {
            return $nullable ? 'null' : '1';
        }

        $relatedNamespace = PathManager::resolveBackendModuleNamespace($relatedModuleName);
        $relatedModelFqcn = $relatedNamespace . '\\' . $relatedModuleName . 'Model';

        if ($nullable) {
            // A nullable-but-resolvable FK: null is simpler and always
            // valid, and avoids creating an extra row nothing requires.
            return 'null';
        }

        return "\\{$relatedModelFqcn}::factory()";
    }

    /**
     * Heuristic used only when a column has no explicit relatedModule
     * metadata at all (hand-rolled configs, or introspection that couldn't
     * resolve a real FK constraint): a column named "parent_id" almost
     * always self-references its own table (item_categories.parent_id ->
     * item_categories.id), matching the exact case
     * PhpUnitTestGenerator::buildFieldValueLiteral() was fixed for.
     */
    protected function looksSelfReferential(string $columnName): bool
    {
        return $columnName === 'parent_id';
    }

    /**
     * Whether $relatedModuleName resolves to a real, known module — via the
     * array-based module registry (the same source PhpUnitTestGenerator's
     * resolveCrossModuleFkLiteral() and ModelGenerator's
     * guessedModuleExists() both already trust).
     */
    protected function relatedModuleResolvable(string $relatedModuleName): bool
    {
        return PathManager::findModuleInRegistry($relatedModuleName) !== null;
    }

    protected function buildScalarValue(string $name, string $type, bool $unique, array $column): string
    {
        if (str_contains(strtolower($name), 'email')) {
            return $unique ? 'fake()->unique()->safeEmail()' : 'fake()->safeEmail()';
        }

        switch (strtolower($type)) {
            case 'boolean':
                $default = $column['default'] ?? null;
                if ($default === null) {
                    return 'true';
                }
                if (is_string($default)) {
                    return strtolower($default) === 'true' ? 'true' : 'false';
                }
                return $default ? 'true' : 'false';

            case 'date':
                return 'now()->toDateString()';

            case 'datetime':
            case 'timestamp':
                return 'now()';

            case 'decimal':
            case 'float':
            case 'double':
                return $unique
                    ? 'fake()->unique()->randomFloat(2, 1, 100000)'
                    : 'fake()->randomFloat(2, 1, 1000)';

            case 'integer':
            case 'bigint':
            case 'bigInteger':
            case 'smallint':
            case 'smallInteger':
            case 'tinyint':
            case 'tinyInteger':
                return $unique
                    ? 'fake()->unique()->numberBetween(100000, 999999)'
                    : 'fake()->numberBetween(1, 1000)';

            case 'text':
            case 'longtext':
            case 'mediumtext':
                return 'fake()->paragraph()';

            case 'enum':
                $options = $column['options'] ?? $column['values'] ?? null;
                if (is_array($options) && !empty($options)) {
                    $literalOptions = implode(', ', array_map(
                        fn ($opt) => "'" . addslashes((string) $opt) . "'",
                        $options
                    ));
                    return "fake()->randomElement([{$literalOptions}])";
                }
                return "'" . addslashes(Str::studly($name)) . "'";

            case 'json':
            case 'jsonb':
                return '[]';

            default:
                $studly = Str::studly($name);
                return $unique
                    ? "'Test {$studly} ' . uniqid()"
                    : "fake()->words(2, true)";
        }
    }
}
