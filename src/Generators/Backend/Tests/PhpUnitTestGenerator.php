<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
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

    /**
     * Whether this module carries at least one file_columns-marked column
     * (see isFileColumn()'s docblock for where that key comes from). Computed
     * once, module-wide, rather than per fields-array: a module with a file
     * column always needs its HTTP-issuing request lines switched from
     * postJson()/putJson() to a real multipart post() (see
     * buildCreateTestMethod()/buildEditTestMethod()), regardless of which
     * particular fields[] happens to be in scope for a given test method.
     */
    protected bool $isMultipartModule;

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

        $this->isMultipartModule = !empty($config['file_columns'] ?? []);

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
    /**
     * Whether $field is one of this module's file_columns (threaded onto the
     * TOP LEVEL of the generated module.json by IntrospectionToConfig::build()
     * — see its docblock — precisely so backend generators reading $config
     * directly, like this one, can see it without walking
     * features.frontend.create.fields[]).
     */
    protected function isFileColumn(string $field): bool
    {
        return in_array($field, $this->config['file_columns'] ?? [], true);
    }

    /**
     * Build a fake-upload literal for a file_columns-marked field, for use
     * ONLY in an actual HTTP request payload (postJson/putJson body) — see
     * buildFieldValueLiteral()'s $forHttpRequest guard.
     *
     * BaseServiceGenerator::generateValidationRules() emits a generic 'file'
     * rule for every file_columns column (not 'image'), so any fake upload
     * satisfies validation regardless of the column's real content type.
     * The column-name sniff below is purely cosmetic — it picks a more
     * representative fixture (a real fake image for an "image" column, a
     * generic document otherwise) wherever that's cheap to infer, never a
     * correctness requirement.
     */
    protected function buildFileUploadLiteral(string $field): string
    {
        $lower = strtolower($field);
        if (str_contains($lower, 'image') || str_contains($lower, 'photo') || str_contains($lower, 'avatar')) {
            return "\\Illuminate\\Http\\UploadedFile::fake()->image('test.jpg')";
        }

        return "\\Illuminate\\Http\\UploadedFile::fake()->create('document.pdf', 100)";
    }

    protected function buildFieldValueLiteral(string $field, string $rules, string $prefix = 'Test', bool $forHttpRequest = false, bool $isMultipart = false): string
    {
        $isUnique = str_contains($rules, 'unique:');

        // File-upload columns (file_columns meta) must submit a fake
        // UploadedFile over HTTP — BaseServiceGenerator::generateValidationRules()
        // always emits a 'file' rule for these regardless of whatever
        // FK/integer rule this field's own 'rules' string carries (a
        // *_media_id column is still introspected as an ordinary
        // unsignedBigInteger FK — see IntrospectionToConfig::buildBackendFields()
        // — file_columns is an ADDITIONAL override applied downstream, not a
        // different 'rules' string here). A plain integer/FK literal here
        // always 422s with validation.file. This check MUST run before the
        // exists:/integer branches below, which would otherwise claim a
        // *_media_id-shaped column first — confirmed live: this accounted
        // for 2 of 40 failures in a real generation run.
        //
        // Gated on $forHttpRequest: only the actual create/edit HTTP request
        // payloads need a File object. The fixture helper
        // (buildFixtureHelper()) builds rows via direct Model::create(),
        // bypassing validation and the file-to-media-id conversion entirely
        // — it still needs a real integer/null column value, which the
        // ordinary FK/exists logic further down already provides correctly.
        if ($forHttpRequest && $this->isFileColumn($field)) {
            return $this->buildFileUploadLiteral($field);
        }

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
        // table cannot safely reuse the literal `1` either — the same
        // "no guarantee id 1 exists" problem, just without the `nullable`
        // escape hatch above. Worse, `RelatedModel::query()->value('id') ?? 1`
        // (the PREVIOUS fix attempted here) doesn't work either: generated
        // CRUD tests run under RefreshDatabase, so the referenced parent
        // table is completely EMPTY at fixture time — `value('id')` returns
        // null, the `?? 1` fallback kicks in, and row id 1 doesn't exist,
        // so the request 422s with `validation.exists`. Confirmed live: this
        // accounted for 9 of 40 failures in a real 5-module generation run.
        // Looking up an existing row was the wrong strategy — the row has to
        // be CREATED. This generator has no access to the referenced
        // module's own required columns from a single-module invocation, so
        // it cannot build a full fixture row there itself. What it CAN do is
        // ask PathManager's module registry (populated by the caller — e.g.
        // make:module wiring up every sibling module before generating any
        // one of them, the same mechanism DelegationServiceGenerator already
        // relies on to resolve a related module's FQCN) whether that foreign
        // table belongs to a known module, and if so emit an expression that
        // CREATES a real parent row at test-run time via that module's own
        // FactoryGenerator-produced factory (`RelatedModel::factory()->create()->id`)
        // instead of assuming or looking one up. Modules are generated in
        // FK-dependency order, so the related module's factory is guaranteed
        // to already exist on disk by the time this one is generated. This is
        // a no-op improvement (falls back to the same literal `1` as before)
        // whenever the registry can't place the table, so it never emits
        // broken PHP for a module the generator can't identify.
        if (preg_match('/exists:([^,]+),/', $rules, $existsMatch)) {
            $foreignTable = trim($existsMatch[1]);
            $isSelfReferential = $foreignTable === $this->getTableName();
            $isNullable = str_contains($rules, 'nullable');

            if ($isSelfReferential || $isNullable) {
                return 'null';
            }

            $resolved = $this->resolveCrossModuleFkLiteral($foreignTable);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (preg_match('/\b(integer|numeric)\b/', $rules)) {
            return $isUnique ? 'random_int(100000, 999999)' : '1';
        }

        if (str_contains($rules, 'boolean')) {
            // Mirrors BaseComponentGenerator::generateSubmitCall()'s multipart
            // boolean handling on the frontend side (v2.10.17): a real
            // multipart request — which this HTTP-issuing payload becomes
            // whenever $isMultipart is set, see buildCreateTestMethod()/
            // buildEditTestMethod() — carries every field as a string, so a
            // raw PHP `true` literal is never what actually reaches the
            // request. Only applies on the multipart HTTP-request path; the
            // fixture helper's direct Model::create() call (forHttpRequest
            // false) still needs a real boolean for the column.
            return ($forHttpRequest && $isMultipart) ? "'1'" : 'true';
        }

        if (str_contains($rules, 'date')) {
            return 'now()->toDateString()';
        }

        $studly = Str::studly($field);
        return "'{$prefix} {$studly} ' . uniqid()";
    }

    /**
     * Resolve a required cross-module FK's `exists:{table},...` target to a
     * PHP literal that CREATES a real parent row at test-run time via the
     * related module's own factory, using PathManager's module registry
     * (see PathManager::findModuleByTable() / ::resolveBackendModuleNamespace()
     * — the same lookup DelegationServiceGenerator uses to reference another
     * module's model).
     *
     * Deliberately `::factory()->create()->id`, not a `query()->value('id')`
     * lookup (the previous, broken attempt at this fix) — generated CRUD
     * tests run under RefreshDatabase, so the referenced parent table is
     * completely empty at fixture time and a lookup always comes back null.
     * FactoryGenerator emits a `{Module}Factory` for every module, and
     * modules are generated in FK-dependency order, so the related module's
     * factory class is guaranteed to already exist by the time this one is
     * generated.
     *
     * Returns null (never a partially-built expression) when the registry
     * has no entry for $foreignTable, so the caller can fall back to the
     * pre-existing literal `1` behavior rather than emitting a reference to
     * a model/factory FQCN this generator isn't actually sure exists.
     */
    protected function resolveCrossModuleFkLiteral(string $foreignTable): ?string
    {
        $moduleEntry = PathManager::findModuleByTable($foreignTable);
        if ($moduleEntry === null) {
            return null;
        }

        $relatedModuleName = $moduleEntry['name'] ?? Str::studly($foreignTable);
        $relatedNamespace = PathManager::resolveBackendModuleNamespace($relatedModuleName);
        $relatedModelFqcn = $relatedNamespace . '\\' . $relatedModuleName . 'Model';

        return "\\{$relatedModelFqcn}::factory()->create()->id";
    }

    /**
     * Render "'field' => <value-literal>," lines for embedding inside an
     * array literal.
     */
    protected function buildPayloadLines(array $fields, string $prefix = 'Test', string $indent = '            ', bool $forHttpRequest = false, bool $isMultipart = false): string
    {
        $lines = [];
        foreach ($fields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }
            $rules = $fieldDef['rules'] ?? '';
            $lines[] = "{$indent}'{$field}' => " . $this->buildFieldValueLiteral($field, $rules, $prefix, $forHttpRequest, $isMultipart) . ',';
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
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);

        $assertLines = [];
        $fileAssertLines = [];
        foreach ($fields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }

            if ($this->isFileColumn($field)) {
                // The backend converts the uploaded file into a Media row and
                // stores its integer id back onto the model (see
                // BaseServiceGenerator::generateFileColumnUploads()), so the
                // response value can never equal $payload['{$field}'] (an
                // UploadedFile instance) — asserting exact equality here would
                // always fail. Assert the conversion actually happened
                // instead: an integer id came back.
                $fileAssertLines[] = "        \$this->assertIsInt(\$response->json('data.{$field}'));";
                continue;
            }

            $assertLines[] = "            ->assertJsonPath('data.{$field}', \$payload['{$field}'])";
        }
        $assertBlock = implode("\n", $assertLines);

        $uniqueField = $this->firstUniqueField($fields);
        $uniqueFieldName = $uniqueField['field'] ?? ($fields[0]['field'] ?? 'id');

        $dbAssertLine = "        \$this->assertDatabaseHas('{$tableName}', ['{$uniqueFieldName}' => \$payload['{$uniqueFieldName}']]);";
        $postAssertions = $fileAssertLines
            ? implode("\n", $fileAssertLines) . "\n\n" . $dbAssertLine
            : $dbAssertLine;

        // A module with a file_columns field must issue a real multipart
        // request: postJson()/putJson() JSON-encode the payload, which
        // destroys the UploadedFile instance entirely — and, since it's
        // array-valued, takes every SIBLING payload field down with it (an
        // UploadedFile can't survive json_encode(), so Laravel never even
        // sees the request body). Confirmed live: a real generation run
        // produced a request with content-type: application/json and the
        // upload silently gone, failing validation on the field next to it
        // (item_id) rather than the file column itself. post() sends actual
        // multipart/form-data and is otherwise a byte-for-byte drop-in.
        $createCall = $this->isMultipartModule
            ? "\$this->post('/api/{$routeBase}/create', \$payload)"
            : "\$this->postJson('/api/{$routeBase}/create', \$payload)";

        return <<<PHP
    public function test_can_create_{$snakeSingular}(): void
    {
        \$payload = [
{$payloadLines}
        ];

        \$response = {$createCall};

        \$response->assertStatus(201)
            ->assertJson(['status' => true])
{$assertBlock};

{$postAssertions}
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
        $payloadLines = $this->buildPayloadLines($editFields, 'Updated', '            ', true, $this->isMultipartModule);

        $assertLines = [];
        $dbAssertLines = [];
        $fileAssertLines = [];
        foreach ($editFields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }

            if ($this->isFileColumn($field)) {
                // Same reasoning as buildCreateTestMethod(): the response
                // value, and the persisted DB value, are both the newly
                // created media row's integer id, never the raw
                // $payload['{$field}'] UploadedFile instance — so neither the
                // exact-match response assertion nor the exact-match DB
                // assertion below can hold for this column.
                $fileAssertLines[] = "        \$this->assertIsInt(\$response->json('data.{$field}'));";
                continue;
            }

            $assertLines[] = "            ->assertJsonPath('data.{$field}', \$payload['{$field}'])";
            $dbAssertLines[] = "            '{$field}' => \$payload['{$field}'],";
        }
        $assertBlock = implode("\n", $assertLines);
        $dbAssertBlock = implode("\n", $dbAssertLines);

        $dbAssertStatement = <<<PHP
        \$this->assertDatabaseHas('{$tableName}', [
            'uuid' => \$fixture->uuid,
{$dbAssertBlock}
        ]);
PHP;
        $postAssertions = $fileAssertLines
            ? implode("\n", $fileAssertLines) . "\n\n" . $dbAssertStatement
            : $dbAssertStatement;

        // Same postJson()-destroys-the-upload problem as buildCreateTestMethod(),
        // plus one more wrinkle: the edit route is registered as PUT (see the
        // generated Routes/api.php), and a real multipart body can only be sent
        // via POST — PHP never populates $_FILES for a PUT request regardless of
        // framework. This mirrors BaseComponentGenerator::generateSubmitCall()'s
        // frontend fix (v2.10.17) exactly: POST the multipart body with a
        // `_method: 'PUT'` override field, which Laravel's
        // Request::enableHttpMethodParameterOverride() — active for every
        // request via Request::capture(), including in-process test requests —
        // resolves back to the PUT route, the identical spoofing mechanism
        // Blade's @method('PUT') directive relies on for native HTML file
        // upload forms.
        $editCall = $this->isMultipartModule
            ? "\$this->post(\"/api/{$routeBase}/{\$fixture->uuid}/edit\", \$payload + ['_method' => 'PUT'])"
            : "\$this->putJson(\"/api/{$routeBase}/{\$fixture->uuid}/edit\", \$payload)";

        return <<<PHP
    public function test_can_edit_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$payload = [
{$payloadLines}
        ];

        \$response = {$editCall};

        \$response->assertStatus(200)
{$assertBlock};

{$postAssertions}
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
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);

        // Same postJson()-vs-multipart reasoning as buildCreateTestMethod():
        // this payload still carries a real UploadedFile for any file_columns
        // field, so it needs the same real multipart request.
        $validationCall = $this->isMultipartModule
            ? "\$this->post('/api/{$routeBase}/create', \$payload)"
            : "\$this->postJson('/api/{$routeBase}/create', \$payload)";

        return <<<PHP
    public function test_create_{$snakeSingular}_validation_fails_with_missing_required_field(): void
    {
        \$payload = [
{$payloadLines}
        ];
        unset(\$payload['{$requiredFieldName}']);

        \$response = {$validationCall};

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$requiredFieldName}']);
    }
PHP;
    }
}
