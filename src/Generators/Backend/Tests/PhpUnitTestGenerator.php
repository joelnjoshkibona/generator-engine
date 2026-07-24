<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Illuminate\Support\Str;

/**
 * Generates a PHPUnit Feature test stub for a module, covering its enabled
 * backend HTTP surface (list/create/view/edit/delete) plus the two checks
 * that are unconditional in the generation pipeline: DeleteCheck and list
 * filtering.
 *
 * Modelled structurally after the hand-written reference suites
 * (LocationTypesCrudTest, PermissionsCrudTest) but never relies on an
 * Eloquent factory — most generated modules don't have one — building
 * fixtures via direct `<Module>Model::create()` instead, through a small
 * `create<Singular>Fixture()` helper emitted into every generated class.
 */
class PhpUnitTestGenerator extends BaseGenerator
{
    protected const USERS_MODEL_FQCN = 'App\\Project\\Modules\\Core\\Users\\Users\\UsersModel';

    protected bool $hasList;
    protected bool $hasCreate;
    protected bool $hasView;
    protected bool $hasEdit;
    protected bool $hasDelete;

    /** Singular Studly form of the module name, e.g. "LocationType". */
    protected string $moduleSingular;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        $backendFeatures = $config['features']['backend'] ?? [];
        $this->hasList   = !empty($backendFeatures['list']);
        $this->hasCreate = !empty($backendFeatures['create']);
        $this->hasView   = !empty($backendFeatures['view']);
        $this->hasEdit   = !empty($backendFeatures['edit']);
        $this->hasDelete = !empty($backendFeatures['delete']);

        $this->moduleSingular = Str::singular($this->moduleName);
    }

    public function generate(): bool
    {
        $fields        = $this->fieldsSource();
        $snakeSingular = Str::snake($this->moduleSingular);
        $snakePlural   = Str::snake(Str::plural($this->moduleName));
        $routeBase     = Str::kebab($this->moduleName);
        $tableName     = $this->getTableName();

        $methods = [];
        $methods[] = $this->buildFixtureHelper($fields);

        if ($this->hasList) {
            $methods[] = $this->buildListTestMethod($snakePlural, $routeBase);
        }

        if ($this->hasCreate) {
            $methods[] = $this->buildCreateTestMethod($fields, $snakeSingular, $routeBase, $tableName);
        }

        if ($this->hasView) {
            $methods[] = $this->buildViewTestMethod($fields, $snakeSingular, $routeBase);
        }

        if ($this->hasEdit) {
            $methods[] = $this->buildEditTestMethod($fields, $snakeSingular, $routeBase, $tableName);
        }

        // DeleteCheck/Activity generators are unconditional in the pipeline,
        // so this coverage is always emitted regardless of the delete flag.
        $methods[] = $this->buildDeleteCheckTestMethod($routeBase);

        if ($this->hasDelete) {
            $methods[] = $this->buildDeleteTestMethod($snakeSingular, $routeBase, $tableName);
        }

        // Every generated list carries filter support, so this is unconditional too.
        $methods[] = $this->buildFilterTestMethod($snakePlural, $routeBase);

        if ($this->hasCreate) {
            $methods[] = $this->buildCreateValidationTestMethod($fields, $snakeSingular, $routeBase);
        }

        $stub = $this->getTemplateContent('Tests/crud_test', 'backend');

        $moduleModelFqcn = $this->getNamespace() . '\\' . $this->moduleName . 'Model';
        $usersImportLine = $moduleModelFqcn === self::USERS_MODEL_FQCN
            ? ''
            : 'use ' . self::USERS_MODEL_FQCN . ";\n";

        $content = $this->replacePlaceholders($stub, [
            '[[testNamespace]]'    => $this->getNamespace() . '\Tests',
            '[[UsersModelImport]]' => $usersImportLine,
            '[[testMethods]]'      => implode("\n\n", array_filter($methods)),
        ]);

        $filePath = $this->modulePath . '/Tests' . "/{$this->moduleName}CrudTest.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Resolve the field definitions used to build fixture/payload literals.
     *
     * Prefers features.backend.create.fields (the shape the brief targets),
     * falls back to edit.fields, and — for modules with neither enabled —
     * falls back to deriving simple string fields from the module's
     * top-level columns[] (skipping id/uuid/audit columns) so a fixture can
     * still be built for list/view/delete coverage.
     */
    protected function fieldsSource(): array
    {
        $createFields = $this->config['features']['backend']['create']['fields'] ?? [];
        if (!empty($createFields)) {
            return $createFields;
        }

        $editFields = $this->config['features']['backend']['edit']['fields'] ?? [];
        if (!empty($editFields)) {
            return $editFields;
        }

        $skip = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'created_by_id', 'updated_by_id'];
        $fields = [];
        foreach ($this->config['columns'] ?? [] as $column) {
            $name = $column['name'] ?? null;
            if (!$name || in_array($name, $skip, true)) {
                continue;
            }
            $rules = 'required|string';
            if (!empty($column['unique'])) {
                $rules .= '|unique:' . $this->getTableName() . ',' . $name;
            }
            $fields[] = ['field' => $name, 'rules' => $rules];
        }

        return $fields;
    }

    /**
     * Build a PHP literal for a single field's test value.
     *
     * Checks "email" and the other type-specific rules (integer/numeric,
     * boolean, date) BEFORE the generic "unique"/"otherwise" fallback. A
     * strict top-to-bottom reading of the brief's heuristic list would check
     * "contains unique:" first, which — for a column that is simultaneously
     * `unique` and `email`, or `unique` and `integer` — would hand back a
     * `'Test Foo ' . uniqid()` string literal to a column whose validation
     * rule requires a real email address or an integer, breaking the
     * generated create/validation tests against their own request
     * validation. Re-ordering the checks keeps every literal valid for its
     * declared rule while still appending uniqid()/random_int() wherever the
     * column is unique, so repeated runs (and pre-existing seeded rows)
     * never collide.
     */
    protected function buildFieldValueLiteral(string $field, string $rules, string $prefix = 'Test'): string
    {
        $isUnique = str_contains($rules, 'unique:');

        if (str_contains(strtolower($field), 'email')) {
            return "'test' . uniqid() . '@example.com'";
        }

        // Foreign-key columns (an `exists:table,column` rule) cannot safely
        // reuse the generic integer literal below. That branch always
        // hardcoded `1`, gambling that a row with that id already exists in
        // the referenced table on a freshly migrated + seeded test database
        // — a guarantee this generator has no basis for. The gamble fails
        // outright for a *self-referential* FK (e.g.
        // item_categories.parent_id -> item_categories.id): on the very
        // first insert into an empty table there is categorically no row
        // yet for id 1 to reference, so `exists:item_categories,id` always
        // rejects it. Falling back to `null` sidesteps the rule entirely
        // whenever the column allows it — true for every self-referential
        // hierarchy-style FK seen in practice (they're nullable so a root
        // record can omit its parent), and equally correct for any other
        // nullable reference to a table this generator can't prove is
        // seeded.
        //
        // A *required* (non-nullable) reference to a *different* module's
        // table is a distinct, deeper gap this fix does not attempt: safely
        // satisfying it means creating a fixture row in that OTHER module's
        // own table using ITS OWN required columns — information a
        // single-module generator invocation doesn't have. That case still
        // falls through to the literal `1` below (no worse than before);
        // see PhpUnitTestGeneratorTest / the engine changelog for the
        // follow-up tracking a proper cross-module fixture mechanism.
        if (preg_match('/exists:([^,]+),/', $rules, $existsMatch)) {
            $foreignTable = trim($existsMatch[1]);
            $isSelfReferential = $foreignTable === $this->getTableName();
            $isNullable = str_contains($rules, 'nullable');

            if ($isSelfReferential || $isNullable) {
                return 'null';
            }
        }

        if (preg_match('/\b(integer|numeric)\b/', $rules)) {
            return $isUnique ? 'random_int(100000, 999999)' : '1';
        }

        if (str_contains($rules, 'boolean')) {
            return 'true';
        }

        if (str_contains($rules, 'date')) {
            return 'now()->toDateString()';
        }

        $studly = Str::studly($field);
        return "'{$prefix} {$studly} ' . uniqid()";
    }

    /**
     * Render "'field' => <value-literal>," lines for embedding inside an
     * array literal.
     */
    protected function buildPayloadLines(array $fields, string $prefix = 'Test', string $indent = '            '): string
    {
        $lines = [];
        foreach ($fields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }
            $rules = $fieldDef['rules'] ?? '';
            $lines[] = "{$indent}'{$field}' => " . $this->buildFieldValueLiteral($field, $rules, $prefix) . ',';
        }

        return implode("\n", $lines);
    }

    protected function firstUniqueField(array $fields): ?array
    {
        foreach ($fields as $f) {
            if (str_contains($f['rules'] ?? '', 'unique:')) {
                return $f;
            }
        }
        return $fields[0] ?? null;
    }

    protected function firstRequiredField(array $fields): ?array
    {
        foreach ($fields as $f) {
            if (str_contains($f['rules'] ?? '', 'required')) {
                return $f;
            }
        }
        return $fields[0] ?? null;
    }

    /**
     * First filterable field's key, matching the frontend list filter UI
     * (features.backend.list.filterFields[0]). Falls back to deriving it
     * from filterableFields, then to "id" — BaseServiceGenerator always
     * back-fills an {key:'id', label:'ID', type:'text'} entry for the
     * generated ListService, but that happens downstream of this generator
     * reading the raw module config, so the fallback is reproduced here too.
     */
    protected function firstFilterFieldKey(): string
    {
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        if (!empty($filterFields[0]['key'])) {
            return $filterFields[0]['key'];
        }

        $filterable = $this->config['features']['backend']['list']['filterableFields'] ?? '';
        if (is_string($filterable) && $filterable !== '') {
            $parts = array_filter(array_map('trim', explode(',', $filterable)));
            if (!empty($parts)) {
                return (string) reset($parts);
            }
        }

        return 'id';
    }

    /**
     * A protected fixture-builder used by every other generated test method,
     * DRY-ing up the repeated "no factory exists" Model::create() boilerplate.
     *
     * Ends with `->fresh()`, mirroring exactly what every generated
     * `<Module>CreateService::process()` itself does before returning its
     * response (`$model->fresh()`) — not a test-only workaround. Without it,
     * a column with a DB-level default expression and no application-supplied
     * value (this codebase's own uuid convention: see e.g. the
     * `create_location_types_table` migration's
     * `$table->uuid()->default(DB::raw(...))`) never gets read back onto the
     * in-memory model `Model::create()` returns, since that default is
     * computed by MySQL, not PHP. Confirmed live: every uuid-path test
     * (view/edit/delete/delete-check) generated against a freshly scaffolded
     * module 404'd until this was added — `$fixture->uuid` was null, so the
     * request URL itself was malformed (`/{module}//view`). `->fresh()`
     * generalizes correctly to any other DB-generated default too, not just
     * uuid, so no `has_uuid` conditional is needed.
     */
    protected function buildFixtureHelper(array $fields): string
    {
        $defaults = $this->buildPayloadLines($fields, 'Test', '            ');
        if ($defaults !== '') {
            $defaults .= "\n";
        }

        return <<<PHP
    protected function create{$this->moduleSingular}Fixture(array \$overrides = []): {$this->moduleName}Model
    {
        return {$this->moduleName}Model::create(array_merge([
{$defaults}            'created_by_id' => UsersModel::DEVELOPER,
        ], \$overrides))->fresh();
    }
PHP;
    }

    protected function buildListTestMethod(string $snakePlural, string $routeBase): string
    {
        return <<<PHP
    public function test_can_list_{$snakePlural}(): void
    {
        \$this->create{$this->moduleSingular}Fixture();
        \$this->create{$this->moduleSingular}Fixture();
        \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson('/api/{$routeBase}/list');

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    protected function buildCreateTestMethod(array $fields, string $snakeSingular, string $routeBase, string $tableName): string
    {
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ');

        $assertLines = [];
        foreach ($fields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }
            $assertLines[] = "            ->assertJsonPath('data.{$field}', \$payload['{$field}'])";
        }
        $assertBlock = implode("\n", $assertLines);

        $uniqueField = $this->firstUniqueField($fields);
        $uniqueFieldName = $uniqueField['field'] ?? ($fields[0]['field'] ?? 'id');

        return <<<PHP
    public function test_can_create_{$snakeSingular}(): void
    {
        \$payload = [
{$payloadLines}
        ];

        \$response = \$this->postJson('/api/{$routeBase}/create', \$payload);

        \$response->assertStatus(201)
            ->assertJson(['status' => true])
{$assertBlock};

        \$this->assertDatabaseHas('{$tableName}', ['{$uniqueFieldName}' => \$payload['{$uniqueFieldName}']]);
    }
PHP;
    }

    protected function buildViewTestMethod(array $fields, string $snakeSingular, string $routeBase): string
    {
        $firstField = $fields[0]['field'] ?? null;
        $extraAssert = $firstField
            ? "\n            ->assertJsonPath('data.{$firstField}', \$fixture->{$firstField})"
            : '';

        return <<<PHP
    public function test_can_view_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/view");

        \$response->assertStatus(200)
            ->assertJsonPath('data.uuid', \$fixture->uuid){$extraAssert};
    }
PHP;
    }

    protected function buildEditTestMethod(array $fields, string $snakeSingular, string $routeBase, string $tableName): string
    {
        $editFields = $this->config['features']['backend']['edit']['fields'] ?? $fields;
        $payloadLines = $this->buildPayloadLines($editFields, 'Updated', '            ');

        $assertLines = [];
        $dbAssertLines = [];
        foreach ($editFields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }
            $assertLines[] = "            ->assertJsonPath('data.{$field}', \$payload['{$field}'])";
            $dbAssertLines[] = "            '{$field}' => \$payload['{$field}'],";
        }
        $assertBlock = implode("\n", $assertLines);
        $dbAssertBlock = implode("\n", $dbAssertLines);

        return <<<PHP
    public function test_can_edit_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$payload = [
{$payloadLines}
        ];

        \$response = \$this->putJson("/api/{$routeBase}/{\$fixture->uuid}/edit", \$payload);

        \$response->assertStatus(200)
{$assertBlock};

        \$this->assertDatabaseHas('{$tableName}', [
            'uuid' => \$fixture->uuid,
{$dbAssertBlock}
        ]);
    }
PHP;
    }

    protected function buildDeleteCheckTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_delete_check_reports_no_blocking_relationships(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/delete/check");

        \$response->assertStatus(200)
            ->assertJsonPath('data.can_delete', true);
    }
PHP;
    }

    protected function buildDeleteTestMethod(string $snakeSingular, string $routeBase, string $tableName): string
    {
        return <<<PHP
    public function test_can_delete_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->deleteJson("/api/{$routeBase}/{\$fixture->uuid}/delete");

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);

        \$this->assertSoftDeleted('{$tableName}', ['id' => \$fixture->id]);
    }
PHP;
    }

    protected function buildFilterTestMethod(string $snakePlural, string $routeBase): string
    {
        $filterField = $this->firstFilterFieldKey();

        return <<<PHP
    public function test_can_filter_{$snakePlural}_list_by_{$filterField}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson('/api/{$routeBase}/list?' . http_build_query([
            'filters' => [
                '{$filterField}' => ['operator' => 'eq', 'value' => \$fixture->{$filterField}],
            ],
        ]));

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);

        \$values = collect(\$response->json('data.data'))->pluck('{$filterField}');
        \$this->assertTrue(\$values->contains(\$fixture->{$filterField}));
    }
PHP;
    }

    protected function buildCreateValidationTestMethod(array $fields, string $snakeSingular, string $routeBase): string
    {
        $required = $this->firstRequiredField($fields);
        $requiredFieldName = $required['field'] ?? ($fields[0]['field'] ?? 'name');
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ');

        return <<<PHP
    public function test_create_{$snakeSingular}_validation_fails_with_missing_required_field(): void
    {
        \$payload = [
{$payloadLines}
        ];
        unset(\$payload['{$requiredFieldName}']);

        \$response = \$this->postJson('/api/{$routeBase}/create', \$payload);

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$requiredFieldName}']);
    }
PHP;
    }
}
