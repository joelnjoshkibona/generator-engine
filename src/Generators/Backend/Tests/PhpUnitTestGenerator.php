<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
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

    /**
     * Referenced ONLY as fully-qualified literals inline inside generated
     * method bodies (see buildActingAsUserWithoutPermissionHelper()) — never
     * as a `use` import — so the authorization-coverage helper never has to
     * worry about colliding with the module's own conditional UsersModel
     * import (or, in the Roles/UserLocations module's own generated test,
     * with itself).
     */
    protected const ROLES_MODEL_FQCN = 'App\\Project\\Modules\\Core\\Access\\Roles\\RolesModel';
    protected const USER_LOCATIONS_MODEL_FQCN = 'App\\Project\\Modules\\Core\\Users\\UserLocations\\UserLocationsModel';

    protected bool $hasList;
    protected bool $hasCreate;
    protected bool $hasView;
    protected bool $hasEdit;
    protected bool $hasDelete;

    /** ModuleConfigContract::hasSoftDeletes() — gates the soft-deleted-excluded-from-list test. */
    protected bool $hasSoftDeletes;

    /** ModuleConfigContract::hasCreatorUpdater() — gates the created_by_id/updated_by_id audit-column assertions. */
    protected bool $hasCreatorUpdater;

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

    /**
     * RoutesGenerator::generate() appends `GET /{routePath}s/list/export`
     * (note the manually-concatenated trailing "s" — mirrored verbatim, not
     * "fixed", since the test must hit whatever route actually got
     * registered) whenever `features.backend.list` is non-empty AND its
     * `export` key is truthy. See RoutesGenerator::generate()'s "Generate
     * export/import routes if enabled" block.
     */
    protected bool $hasExport;

    /**
     * RoutesGenerator::generate() appends `GET /{routePath}s/import/template`
     * (same trailing-"s" concatenation as export) plus `POST
     * /{routePath}s/import` under the identical `features.backend.list.import`
     * flag. Only the template half is covered here — see
     * buildImportTemplateTestMethod()'s docblock for why the actual
     * file-upload POST route is left untested.
     */
    protected bool $hasImportTemplate;

    /**
     * RoutesGenerator::__construct() only ever adds 'createSplash'/
     * 'editSplash' to $this->features (and generate() only ever emits their
     * routes) when BOTH `!empty($config['constants'])` AND the feature's own
     * key is `isset()` in features.backend — mirrored exactly here.
     */
    protected bool $hasCreateSplash;
    protected bool $hasEditSplash;

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

        $this->hasSoftDeletes    = ModuleConfigContract::hasSoftDeletes($config);
        $this->hasCreatorUpdater = ModuleConfigContract::hasCreatorUpdater($config);

        // $backendFeatures['list'] is frequently a bare `true` (rather than
        // an array) in hand-rolled test/fixture configs — guarded with
        // is_array() before indexing into it, exactly as RoutesGenerator's
        // own `$listConfig['export']` / `['import']` reads implicitly rely
        // on `!empty($listConfig)` never being true for a non-array value
        // that also carries a truthy 'export'/'import' key (a bare `true`
        // has neither).
        $listConfig = $backendFeatures['list'] ?? [];
        $this->hasExport         = is_array($listConfig) && !empty($listConfig['export']);
        $this->hasImportTemplate = is_array($listConfig) && !empty($listConfig['import']);

        $hasSplash = !empty($config['constants']);
        $this->hasCreateSplash = $hasSplash && isset($backendFeatures['createSplash']);
        $this->hasEditSplash   = $hasSplash && isset($backendFeatures['editSplash']);

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

        // RoutesGenerator::generate() appends the activity-history route
        // unconditionally, after every feature/delegation/action route, with
        // no config flag gating it at all (see the "Add Activity History
        // route" comment at the bottom of that method) — so this coverage is
        // equally unconditional here, alongside DeleteCheck above.
        $methods[] = $this->buildActivityTestMethod($routeBase);

        if ($this->hasDelete) {
            $methods[] = $this->buildDeleteTestMethod($snakeSingular, $routeBase, $tableName);
        }

        // Every generated list carries filter support, so this is unconditional too.
        $methods[] = $this->buildFilterTestMethod($snakePlural, $routeBase);

        // Export/import-template coverage: mirrors RoutesGenerator's own
        // `!empty($listConfig['export'])` / `['import']` gates exactly (see
        // $hasExport/$hasImportTemplate's docblocks).
        if ($this->hasExport) {
            $methods[] = $this->buildExportTestMethod($routeBase);
        }
        if ($this->hasImportTemplate) {
            $methods[] = $this->buildImportTemplateTestMethod($routeBase);
        }

        // Bulk-action coverage (route family 4): RoutesGenerator's list
        // feature unconditionally registers `POST /{routeBase}/bulk-action`
        // (route.stub's second line) whenever `features.backend.list` is
        // enabled, exactly like the list route itself — so this is gated on
        // $hasList alone, not on bulk_actions being configured. See
        // buildBulkActionMissingIdsValidationTestMethod()'s docblock for why
        // the validation test needs no bulk_actions config to be guaranteed
        // correct, and buildBulkActionIdsModeTestMethod()'s / this method's
        // own docblock for the (narrower) condition the happy-path test adds
        // on top.
        if ($this->hasList) {
            $methods[] = $this->buildBulkActionMissingIdsValidationTestMethod($routeBase);

            $bulkActionKey = $this->firstGenericBulkActionKey();
            if ($bulkActionKey !== null) {
                $methods[] = $this->buildBulkActionIdsModeTestMethod($bulkActionKey, $routeBase);
            }
        }

        if ($this->hasCreate) {
            $methods[] = $this->buildCreateValidationTestMethod($fields, $snakeSingular, $routeBase);
        }

        // Authorization coverage: one 403 test per enabled CRUD feature,
        // sharing a single "user with a permission-less role" helper. Gated
        // on at least one CRUD feature being enabled (same condition each
        // individual test below re-checks) purely so the shared helper is
        // never emitted dead into a module with no HTTP surface at all.
        if ($this->hasList || $this->hasCreate || $this->hasView || $this->hasEdit || $this->hasDelete) {
            $methods[] = $this->buildActingAsUserWithoutPermissionHelper();

            if ($this->hasList) {
                $methods[] = $this->buildListForbiddenTestMethod($snakePlural, $routeBase);
                $methods[] = $this->buildBulkActionForbiddenTestMethod($routeBase);
            }
            if ($this->hasCreate) {
                $methods[] = $this->buildCreateForbiddenTestMethod($snakeSingular, $routeBase);
            }
            if ($this->hasView) {
                $methods[] = $this->buildViewForbiddenTestMethod($snakeSingular, $routeBase);
            }
            if ($this->hasEdit) {
                $methods[] = $this->buildEditForbiddenTestMethod($snakeSingular, $routeBase);
            }
            if ($this->hasDelete) {
                $methods[] = $this->buildDeleteForbiddenTestMethod($snakeSingular, $routeBase);
            }
        }

        // Extra validation coverage beyond missing-required, each emitted
        // only when the module's own field shapes actually exercise it.
        if ($this->hasCreate) {
            $methods[] = $this->buildDuplicateUniqueValidationTestMethod($fields, $snakeSingular, $routeBase);
            $methods[] = $this->buildOverlengthValidationTestMethod($fields, $snakeSingular, $routeBase);
            $methods[] = $this->buildNonexistentFkValidationTestMethod($fields, $snakeSingular, $routeBase);
            $methods = array_merge($methods, $this->buildEnumValidationTestMethods($fields, $snakeSingular, $routeBase));
            $methods[] = $this->buildDecimalPrecisionTestMethod($fields, $snakeSingular, $routeBase);
        }

        // Soft-deleted rows must not resurface in the list endpoint.
        if ($this->hasSoftDeletes && $this->hasDelete && $this->hasList) {
            $methods[] = $this->buildSoftDeleteExcludedFromListTestMethod($snakeSingular, $snakePlural, $routeBase);
        }

        // Editing a file-carrying module without re-supplying the file must
        // leave the existing media reference untouched.
        if ($this->isMultipartModule && $this->hasEdit) {
            $methods[] = $this->buildFileEditWithoutReuploadTestMethod($fields, $snakeSingular, $routeBase);
        }

        // Splash coverage: mirrors RoutesGenerator's own opt-in-twice gate
        // exactly (see $hasCreateSplash/$hasEditSplash's docblocks).
        if ($this->hasCreateSplash) {
            $methods[] = $this->buildCreateSplashTestMethod($routeBase);
        }
        if ($this->hasEditSplash) {
            $methods[] = $this->buildEditSplashTestMethod($routeBase);
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

        // Audit-column coverage (gap 4): only for modules whose table
        // actually carries created_by_id (ModuleConfigContract::hasCreatorUpdater()
        // — the same accessor RoutesGenerator/etc. rely on rather than
        // re-deriving the flag). Folded into the existing assertion chain
        // rather than a separate test method, so a minimal module without
        // audit columns keeps byte-for-byte identical output.
        if ($this->hasCreatorUpdater) {
            $assertLines[] = "            ->assertJsonPath('data.created_by_id', (int) UsersModel::DEVELOPER)";
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

        // Audit-column coverage (gap 4) — see buildCreateTestMethod()'s
        // identical guard for the created_by_id half of this pair.
        if ($this->hasCreatorUpdater) {
            $assertLines[] = "            ->assertJsonPath('data.updated_by_id', (int) UsersModel::DEVELOPER)";
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

    // ─── Authorization coverage (gap 1) ────────────────────────────────────

    /**
     * Shared authorization-coverage helper: builds a Sanctum-ready user
     * whose role carries an EMPTY `permissions` array and is attached to
     * location 1 via a UserLocationsModel row — deliberately "zero
     * permissions at all" rather than "missing this one permission by name".
     *
     * ApplicationServiceProvider::hasPermission() resolves a permission name
     * to an id via PermissionsModel, then walks the user's userLocations
     * rows; each row's role must contain that id in its own `permissions`
     * JSON array or the check fails closed — and resolvePermissionId()
     * itself returns null (denied) for a permission name that was never
     * seeded at all. An empty `permissions` array therefore denies EVERY
     * permission unconditionally, so this single helper needs no
     * per-permission-name lookup and works identically for every CRUD
     * feature test below. Mirrors the precedent pattern already
     * hand-written in NotificationsCrudTest::actingAsUserWithPermissions()
     * / TrashCrudTest's plain-user case.
     *
     * Uses fully-qualified class references inline (never a `use` import)
     * so this helper can't collide with the module's own conditional
     * UsersModel import, or — in the real, shipped Roles/UserLocations
     * modules' own generated CrudTest — with itself.
     */
    protected function buildActingAsUserWithoutPermissionHelper(): string
    {
        $rolesModelFqcn = self::ROLES_MODEL_FQCN;
        $userLocationsModelFqcn = self::USER_LOCATIONS_MODEL_FQCN;

        return <<<PHP
    private function actingAsUserWithoutPermission(): UsersModel
    {
        \$role = \\{$rolesModelFqcn}::factory()->create(['permissions' => []]);
        \$user = UsersModel::factory()->create();

        \\{$userLocationsModelFqcn}::create([
            'user_id' => \$user->id,
            'location_id' => 1,
            'is_primary' => true,
            'role_id' => \$role->id,
            'roles' => [],
            'created_by_id' => (int) UsersModel::DEVELOPER,
        ]);

        Sanctum::actingAs(\$user->fresh());

        return \$user;
    }
PHP;
    }

    /**
     * The permission gate runs as route middleware, ahead of the
     * controller/service entirely — so, unlike the happy-path tests above,
     * none of these forbidden-tests need a realistic payload; an empty
     * array/no body reaches the same 403 before any validation would run.
     */
    protected function buildListForbiddenTestMethod(string $snakePlural, string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_list_{$snakePlural}_without_permission(): void
    {
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->getJson('/api/{$routeBase}/list');

        \$response->assertStatus(403);
    }
PHP;
    }

    protected function buildCreateForbiddenTestMethod(string $snakeSingular, string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_create_{$snakeSingular}_without_permission(): void
    {
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->postJson('/api/{$routeBase}/create', []);

        \$response->assertStatus(403);
    }
PHP;
    }

    protected function buildViewForbiddenTestMethod(string $snakeSingular, string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_view_{$snakeSingular}_without_permission(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/view");

        \$response->assertStatus(403);
    }
PHP;
    }

    protected function buildEditForbiddenTestMethod(string $snakeSingular, string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_edit_{$snakeSingular}_without_permission(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->putJson("/api/{$routeBase}/{\$fixture->uuid}/edit", []);

        \$response->assertStatus(403);
    }
PHP;
    }

    protected function buildDeleteForbiddenTestMethod(string $snakeSingular, string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_delete_{$snakeSingular}_without_permission(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->deleteJson("/api/{$routeBase}/{\$fixture->uuid}/delete");

        \$response->assertStatus(403);
    }
PHP;
    }

    // ─── Extra validation coverage (gaps 3, 9, 10) ─────────────────────────

    /**
     * First field whose `rules` string contains $needle but NOT
     * $excludeNeedle (when given). Unlike firstUniqueField()/
     * firstRequiredField() above (which fall back to $fields[0] when nothing
     * matches — correct for THEIR callers, which always need SOME field to
     * build a payload around), this returns null with no fallback: every
     * caller below needs a genuine "this gap doesn't apply to this module"
     * signal so it can skip emitting a test entirely, per the brief's
     * "everything must be CONDITIONAL" constraint.
     */
    protected function findFieldByRule(array $fields, string $needle, ?string $excludeNeedle = null): ?array
    {
        foreach ($fields as $field) {
            $rules = $field['rules'] ?? '';
            if ($rules === '' || !str_contains($rules, $needle)) {
                continue;
            }
            if ($excludeNeedle !== null && str_contains($rules, $excludeNeedle)) {
                continue;
            }
            if (empty($field['field'])) {
                continue;
            }
            return $field;
        }
        return null;
    }

    /**
     * Look up a field's own top-level column entry in $config['columns'] by
     * name — the only place `precision`/`scale`/`enum_values` are threaded
     * (see IntrospectionToConfig::buildColumn()); the create/edit fields[]
     * entries this generator otherwise works from never carry them.
     */
    protected function findColumnConfig(string $fieldName): ?array
    {
        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['name'] ?? null) === $fieldName) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Same postJson()-vs-multipart choice buildCreateTestMethod() already
     * makes inline — factored out for the gap 3/9/10 tests below, which all
     * issue a create request against an otherwise-full-and-valid payload
     * with exactly one field deliberately overridden to something invalid.
     */
    protected function buildCreateHttpCall(string $routeBase): string
    {
        return $this->isMultipartModule
            ? "\$this->post('/api/{$routeBase}/create', \$payload)"
            : "\$this->postJson('/api/{$routeBase}/create', \$payload)";
    }

    /**
     * Duplicate-value-on-a-unique-column coverage (gap 3a). Restricted to a
     * NON-FK unique field: an FK column that also happens to be unique is
     * left alone here — its `exists:`-driven literal already has its own
     * dedicated resolution path (buildFieldValueLiteral()'s
     * exists:/self-referential/nullable branch), and reusing that same
     * value here would need it to be simultaneously "a real parent row id"
     * and "guaranteed to collide with the fixture", which nothing in this
     * generator can produce safely.
     */
    protected function buildDuplicateUniqueValidationTestMethod(array $fields, string $snakeSingular, string $routeBase): ?string
    {
        $field = $this->findFieldByRule($fields, 'unique:', 'exists:');
        if ($field === null) {
            return null;
        }

        $fieldName = $field['field'];
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
        $createCall = $this->buildCreateHttpCall($routeBase);

        return <<<PHP
    public function test_create_{$snakeSingular}_validation_fails_with_duplicate_{$fieldName}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = \$fixture->{$fieldName};

        \$response = {$createCall};

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$fieldName}']);
    }
PHP;
    }

    /**
     * Over-length-value-on-a-max-constrained-column coverage (gap 3b).
     * Restricted to `string`-typed fields — IntrospectionToConfig::
     * buildBackendFields() only ever emits a numeric `max:` rule for
     * string/varchar/char columns, so this can't be mistakenly matched
     * against an unrelated numeric sanity bound.
     */
    protected function buildOverlengthValidationTestMethod(array $fields, string $snakeSingular, string $routeBase): ?string
    {
        foreach ($fields as $fieldDef) {
            $fieldName = $fieldDef['field'] ?? null;
            $rules = $fieldDef['rules'] ?? '';
            if (!$fieldName || !str_contains($rules, 'string') || !preg_match('/max:(\d+)/', $rules, $m)) {
                continue;
            }

            $overLen = ((int) $m[1]) + 1;
            $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
            $createCall = $this->buildCreateHttpCall($routeBase);

            return <<<PHP
    public function test_create_{$snakeSingular}_validation_fails_with_overlength_{$fieldName}(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = str_repeat('a', {$overLen});

        \$response = {$createCall};

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$fieldName}']);
    }
PHP;
        }

        return null;
    }

    /**
     * Non-existent-FK-id coverage (gap 3c). Restricted to a REQUIRED,
     * cross-module `exists:` rule — mirrors buildFieldValueLiteral()'s own
     * self-referential/nullable carve-out exactly (see that method's
     * docblock): a self-referential or nullable FK legitimately accepts
     * null/an empty table on first insert, so there is no id this test
     * could submit that is reliably "non-existent yet still invalid" for
     * those shapes.
     */
    protected function buildNonexistentFkValidationTestMethod(array $fields, string $snakeSingular, string $routeBase): ?string
    {
        foreach ($fields as $fieldDef) {
            $fieldName = $fieldDef['field'] ?? null;
            $rules = $fieldDef['rules'] ?? '';
            if (!$fieldName || !preg_match('/exists:([^,]+),/', $rules, $m)) {
                continue;
            }

            $foreignTable = trim($m[1]);
            if ($foreignTable === $this->getTableName() || str_contains($rules, 'nullable')) {
                continue;
            }

            $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
            $createCall = $this->buildCreateHttpCall($routeBase);

            return <<<PHP
    public function test_create_{$snakeSingular}_validation_fails_with_nonexistent_{$fieldName}(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = 999999999;

        \$response = {$createCall};

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$fieldName}']);
    }
PHP;
        }

        return null;
    }

    /**
     * Enum-value coverage (gap 10): a valid `enum_values` member is
     * accepted, an invalid one 422s under the `Rule::in([...])` constraint
     * BaseServiceGenerator::generateValidationRules() derives from the same
     * column meta. Cross-referenced against $config['columns'] by field name
     * via findColumnConfig() — create/edit fields[] entries never carry
     * `enum_values` themselves, only the top-level column entry does (see
     * IntrospectionToConfig::buildColumn()).
     *
     * @return string[] Zero entries when no create field has enum_values;
     *                   exactly two (accept + reject) for the first one that does.
     */
    protected function buildEnumValidationTestMethods(array $fields, string $snakeSingular, string $routeBase): array
    {
        foreach ($fields as $fieldDef) {
            $fieldName = $fieldDef['field'] ?? null;
            if (!$fieldName) {
                continue;
            }

            $column = $this->findColumnConfig($fieldName);
            $enumValues = $column['enum_values'] ?? null;
            if (!is_array($enumValues) || empty($enumValues)) {
                continue;
            }

            $validValue = (string) $enumValues[0];
            $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
            $createCall = $this->buildCreateHttpCall($routeBase);

            $acceptTest = <<<PHP
    public function test_create_{$snakeSingular}_accepts_a_valid_{$fieldName}_enum_value(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = '{$validValue}';

        \$response = {$createCall};

        \$response->assertStatus(201);
    }
PHP;

            $rejectTest = <<<PHP
    public function test_create_{$snakeSingular}_rejects_an_invalid_{$fieldName}_enum_value(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = 'not-a-real-value-' . uniqid();

        \$response = {$createCall};

        \$response->assertStatus(422)
            ->assertJsonValidationErrors(['{$fieldName}']);
    }
PHP;

            return [$acceptTest, $rejectTest];
        }

        return [];
    }

    /**
     * Decimal precision/scale round-trip coverage (gap 9). Compared with
     * assertEqualsWithDelta() rather than an exact string match because
     * ModelGenerator::getCastType() casts every decimal/float/double column
     * to Eloquent's plain 'float' cast, not a scale-aware 'decimal:N' one —
     * the value that survives the HTTP round trip is a PHP float, not a
     * fixed-precision string. The literal itself is capped at 4 decimal
     * digits regardless of the column's real scale, so it stays exactly
     * representable enough for a tight delta even when the column was
     * declared with a much larger one.
     */
    protected function buildDecimalPrecisionTestMethod(array $fields, string $snakeSingular, string $routeBase): ?string
    {
        foreach ($fields as $fieldDef) {
            $fieldName = $fieldDef['field'] ?? null;
            if (!$fieldName) {
                continue;
            }

            $column = $this->findColumnConfig($fieldName);
            if ($column === null || !isset($column['scale']) || (int) $column['scale'] < 1) {
                continue;
            }

            $scale = min((int) $column['scale'], 4);
            $decimalValue = '1.' . str_repeat('5', $scale);

            $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
            $createCall = $this->buildCreateHttpCall($routeBase);

            return <<<PHP
    public function test_can_create_and_view_{$snakeSingular}_with_decimal_precision_intact(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = '{$decimalValue}';

        \$response = {$createCall};
        \$response->assertStatus(201);

        \$uuid = \$response->json('data.uuid');
        \$view = \$this->getJson("/api/{$routeBase}/{\$uuid}/view");

        \$this->assertEqualsWithDelta({$decimalValue}, \$view->json('data.{$fieldName}'), 0.0001);
    }
PHP;
        }

        return null;
    }

    // ─── Soft-delete-shape coverage (gaps 5, 7) ────────────────────────────

    /**
     * Soft-deleted-row-excluded-from-list coverage (gap 5). Relies on
     * Eloquent's own default SoftDeletingScope filtering the list query —
     * the same mechanism ModelGenerator wires up via `use SoftDeletes`
     * whenever ModuleConfigContract::hasSoftDeletes() is true — so this only
     * needs to prove the module's own generated ListService hasn't opted
     * OUT of that default (e.g. via withTrashed()).
     */
    protected function buildSoftDeleteExcludedFromListTestMethod(string $snakeSingular, string $snakePlural, string $routeBase): string
    {
        return <<<PHP
    public function test_soft_deleted_{$snakeSingular}_is_excluded_from_{$snakePlural}_list(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$this->deleteJson("/api/{$routeBase}/{\$fixture->uuid}/delete")->assertStatus(200);

        \$response = \$this->getJson('/api/{$routeBase}/list');

        \$response->assertStatus(200);

        \$uuids = collect(\$response->json('data.data'))->pluck('uuid');
        \$this->assertFalse(\$uuids->contains(\$fixture->uuid));
    }
PHP;
    }

    // ─── File-column edit coverage (gap 6) ─────────────────────────────────

    /**
     * File-edit-without-re-uploading coverage (gap 6): editing a
     * file-carrying module's OTHER fields must leave the existing file
     * reference untouched. Relies on
     * BaseServiceGenerator::generateFileColumnUploads()'s edit branch, which
     * unsets the file column from $validData entirely when no new
     * UploadedFile is present in the request — $model->update() then never
     * touches that column. Returns null (skips) when every configured edit
     * field is itself a file column, since there would be no other field
     * left to actually edit in that case.
     */
    protected function buildFileEditWithoutReuploadTestMethod(array $fields, string $snakeSingular, string $routeBase): ?string
    {
        $editFields = $this->config['features']['backend']['edit']['fields'] ?? $fields;

        $fileFieldName = null;
        $nonFileFields = [];
        foreach ($editFields as $fieldDef) {
            $fieldName = $fieldDef['field'] ?? null;
            if (!$fieldName) {
                continue;
            }
            if ($this->isFileColumn($fieldName)) {
                $fileFieldName = $fileFieldName ?? $fieldName;
                continue;
            }
            $nonFileFields[] = $fieldDef;
        }

        if ($fileFieldName === null || empty($nonFileFields)) {
            return null;
        }

        // Never multipart: no file field is included in this payload at
        // all, so an ordinary JSON PUT is sufficient and correct — the same
        // reasoning buildFieldValueLiteral()'s $isMultipart parameter
        // documents for the fixture helper's non-HTTP path.
        $payloadLines = $this->buildPayloadLines($nonFileFields, 'Updated', '            ', true, false);

        return <<<PHP
    public function test_can_edit_{$snakeSingular}_without_reuploading_{$fileFieldName}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();
        \$originalFileValue = \$fixture->{$fileFieldName};

        \$payload = [
{$payloadLines}
        ];

        \$response = \$this->putJson("/api/{$routeBase}/{\$fixture->uuid}/edit", \$payload);

        \$response->assertStatus(200);

        \$this->assertEquals(\$originalFileValue, \$response->json('data.{$fileFieldName}'));
    }
PHP;
    }

    // ─── Activity-history coverage (route family 1) ────────────────────────

    /**
     * RoutesGenerator::generate() registers `GET /{routeBase}/{uuid}/activity`
     * unconditionally for every module, guarded by nothing but 'auth:sanctum'
     * (no permission middleware — see the "Add Activity History route"
     * block at the bottom of that method), dispatching to
     * HasActivityHistory::activityHistory() -> {Module}ActivityListService::execute()
     * -> BaseActivityListService::execute(), both stable, framework-wide
     * classes (not module-specific generated bodies) shared by every module
     * exactly like the DeleteCheckService this generator already covers
     * unconditionally above. For a freshly created fixture with a valid
     * uuid, BaseActivityListService::execute() always resolves the record
     * and returns ['status' => true, 'code' => 200, ...] regardless of
     * whether any activity log rows exist yet.
     */
    protected function buildActivityTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_view_{$this->moduleSingularSnake()}_activity_history(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/activity");

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    /** Snake-case singular module name, e.g. "location_type" — used for method-name interpolation. */
    protected function moduleSingularSnake(): string
    {
        return Str::snake($this->moduleSingular);
    }

    // ─── Export/import-template coverage (route families 2, 3) ────────────

    /**
     * RoutesGenerator::generate() registers
     * `GET /{routeBase}s/list/export` (the trailing "s" is a literal
     * string-concatenation in that method, not Str::plural() — reproduced
     * verbatim here rather than "corrected", since the test must hit
     * whatever path actually got registered) whenever
     * `features.backend.list.export` is truthy. It dispatches to
     * {Module}ListService::execute(..., export: true, format: ...) ->
     * ListServiceTrait::exportData() -> exportToCsv() by default, which
     * always returns a 200 streamed response with CSV headers regardless of
     * how many (if any) rows exist — no fixture is even required.
     */
    protected function buildExportTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_export_{$this->modulePluralSnake()}_list(): void
    {
        \$response = \$this->getJson('/api/{$routeBase}s/list/export');

        \$response->assertStatus(200);
        \$this->assertStringStartsWith('text/csv', \$response->headers->get('Content-Type'));
    }
PHP;
    }

    /** Snake-case plural module name, e.g. "location_types". */
    protected function modulePluralSnake(): string
    {
        return Str::snake(Str::plural($this->moduleName));
    }

    /**
     * RoutesGenerator::generate() registers
     * `GET /{routeBase}s/import/template` under the same
     * `features.backend.list.import` flag (alongside `POST /{routeBase}s/import`
     * — deliberately NOT covered here, see this method's own reasoning
     * below). The template route dispatches to
     * {Module}ListService::getImportTemplate() -> ListServiceTrait::
     * downloadImportTemplate(), which always returns a 200 streamed CSV
     * (headers-only, from either the module's own $importColumns or its
     * $filterableFields — both statically known at generation time to exist
     * as arrays, even if empty) with no fixture, no request body, and no
     * uploaded file required.
     *
     * The sibling `POST /{routeBase}s/import` route is intentionally left
     * untested: it needs a real uploaded file whose header row matches
     * whatever the module's own (developer-authored, not generator-derived)
     * $importColumns/processImportRow() implementation actually expects —
     * information this generator has no access to at generation time. Per
     * the brief's own guidance, a generated test that can't be guaranteed to
     * pass is worse than no coverage at all, so only the template half of
     * this route family is emitted.
     */
    protected function buildImportTemplateTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_download_{$this->modulePluralSnake()}_import_template(): void
    {
        \$response = \$this->getJson('/api/{$routeBase}s/import/template');

        \$response->assertStatus(200);
        \$this->assertStringStartsWith('text/csv', \$response->headers->get('Content-Type'));
    }
PHP;
    }

    // ─── Bulk-action coverage (route family 4) ─────────────────────────────

    /**
     * First `features.backend.list.bulk_actions[]` entry with a non-empty
     * `key` and NO `status_target` — i.e. the ONE shape
     * BulkActionServiceGenerator::buildActionBody() emits that never
     * references a model constant.
     *
     * A status_target entry's generated service body does
     * `{ModuleName}Model::{CONST_NAME}`, and that constant only exists on
     * the generated model if `config['constants']` happens to declare a
     * matching entry (see ModelGenerator::generateConstants()) — something
     * this generator, reading only a single bulk_actions[] entry, has no
     * way to verify. Getting it wrong would emit a test that fatals with an
     * undefined-constant error rather than failing an assertion, which is
     * strictly worse than not emitting it. The status_target-LESS branch
     * has no such dependency: it always resolves the fixture by uuid and
     * returns `Helpers::success()` unconditionally (see
     * BulkActionServiceGenerator::buildActionBody()'s else branch), so it's
     * the only shape this generator can guarantee passes.
     *
     * Returns null (never a guess) when bulk_actions is empty/absent, or
     * when every configured entry carries a status_target.
     */
    protected function firstGenericBulkActionKey(): ?string
    {
        $listConfig = $this->config['features']['backend']['list'] ?? [];
        $bulkActions = is_array($listConfig) ? ($listConfig['bulk_actions'] ?? []) : [];

        foreach ($bulkActions as $action) {
            $key = $action['key'] ?? '';
            if ($key === '' || !empty($action['status_target'])) {
                continue;
            }
            return $key;
        }

        return null;
    }

    /**
     * Bulk-action ids-mode happy-path coverage. RoutesGenerator's list
     * feature unconditionally registers `POST /{routeBase}/bulk-action`
     * (route.stub's second line) behind `permission:{ModuleName}.bulkAction`
     * — seeded unconditionally alongside it by SeederGenerator whenever list
     * is enabled — dispatching to `{ModuleName}ListService::execute_bulkAction()`.
     * That method is itself unconditionally emitted by service.stub
     * (`self::processBulkAction($data, {ModuleName}Model::query())` — not
     * gated on bulk_actions being configured), so both mode=ids and
     * mode=filter dispatch are always wired; only the CONCRETE action key
     * below depends on config, per firstGenericBulkActionKey()'s docblock.
     */
    protected function buildBulkActionIdsModeTestMethod(string $bulkActionKey, string $routeBase): string
    {
        return <<<PHP
    public function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(): void
    {
        \$first = \$this->create{$this->moduleSingular}Fixture();
        \$second = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->postJson('/api/{$routeBase}/bulk-action', [
            'action' => '{$bulkActionKey}',
            'mode' => 'ids',
            'ids' => [\$first->uuid, \$second->uuid],
        ]);

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    /**
     * Bulk-action ids-mode validation coverage: a request with no `ids`
     * array must 422. ListServiceTrait::processBulkAction() enforces this
     * unconditionally (`empty($data['ids'])`) for mode=ids (the default
     * when `mode` is omitted) — and a module with NO bulk_actions
     * configured at all 422s even earlier, on `getBulkActions()` being
     * empty ("bulk_disabled") — so either way this request 422s regardless
     * of the module's bulk_actions config. Needs no fixture and, unlike
     * buildBulkActionIdsModeTestMethod() above, no concrete action key
     * either.
     */
    protected function buildBulkActionMissingIdsValidationTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_bulk_action_validation_fails_with_missing_ids(): void
    {
        \$response = \$this->postJson('/api/{$routeBase}/bulk-action', [
            'action' => 'noop',
            'mode' => 'ids',
        ]);

        \$response->assertStatus(422);
    }
PHP;
    }

    /**
     * The permission gate runs as route middleware ahead of the controller
     * entirely, same as the other *ForbiddenTestMethod() builders above — no
     * body is needed to reach the 403.
     */
    protected function buildBulkActionForbiddenTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_cannot_bulk_action_without_permission(): void
    {
        \$this->actingAsUserWithoutPermission();

        \$response = \$this->postJson('/api/{$routeBase}/bulk-action', []);

        \$response->assertStatus(403);
    }
PHP;
    }

    // ─── Splash coverage (route family 7) ──────────────────────────────────

    /**
     * RoutesGenerator::generate() registers `GET /{routeBase}/create/splash`
     * only when both `!empty($config['constants'])` and
     * `features.backend.createSplash` is set — mirrored by $hasCreateSplash.
     * The generated {Module}CreateSplashService::execute() always returns
     * `Helpers::success($splashData, ...)` (status 200) even when
     * $splashData ends up an empty array (no splashData sources configured),
     * so this needs no fixture and no module-specific knowledge.
     */
    protected function buildCreateSplashTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_view_{$this->moduleSingularSnake()}_create_splash(): void
    {
        \$response = \$this->getJson('/api/{$routeBase}/create/splash');

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    /** Edit-side counterpart of buildCreateSplashTestMethod() — same reasoning. */
    protected function buildEditSplashTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_view_{$this->moduleSingularSnake()}_edit_splash(): void
    {
        \$response = \$this->getJson('/api/{$routeBase}/edit/splash');

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }
}
