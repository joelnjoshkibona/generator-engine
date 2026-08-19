<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

/**
 * Generates PHPUnit Feature test coverage for a module's enabled backend
 * HTTP surface (list/create/view/edit/delete), the two checks that are
 * unconditional in the generation pipeline (DeleteCheck and list filtering),
 * plus one file per named bulk-action/delegation/action key.
 *
 * Emits one file per dedicated Service class this module generates
 * (`{Module}ListServiceTest.php`, `{Module}CreateServiceTest.php`, ...),
 * mirroring the exact filename of the Service class each one covers, plus a
 * shared `{Module}TestCase` base class every split file extends — see
 * generate()'s docblock for the full design and why (regeneration-safety:
 * adding one new delegation/action must never risk clobbering hand-written
 * test logic in an unrelated file).
 *
 * Modelled structurally after the hand-written reference suites
 * (LocationTypesCrudTest, PermissionsCrudTest) but never relies on an
 * Eloquent factory — most generated modules don't have one — building
 * fixtures via direct `<Module>Model::create()` instead, through a small
 * `create<Singular>Fixture()` helper on the shared base class.
 */
class PhpUnitTestGenerator extends BaseGenerator
{
    protected const USERS_MODEL_FQCN = 'App\\Project\\Modules\\Core\\Users\\Users\\UsersModel';

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
     * RoutesGenerator::generate() appends `GET /{routePath}/list/export`
     * whenever `features.backend.list` is non-empty AND its `export` key is
     * truthy. See RoutesGenerator::generate()'s "Generate export/import
     * routes if enabled" block. (Prior to a 2026-08-06 fix, that route path
     * carried a stray manually-concatenated trailing "s"; this generator's
     * own test-body emission mirrored the bug verbatim and was fixed in the
     * same pass — see buildExportTestMethod().)
     */
    protected bool $hasExport;

    /**
     * RoutesGenerator::generate() appends `GET /{routePath}/import/template`
     * plus `POST /{routePath}/import` under the identical
     * `features.backend.list.import` flag. Both halves are covered — see
     * buildImportTemplateTestMethod() and buildImportFileUploadTestMethod().
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

    /**
     * Emits one file per dedicated Service class this module generates
     * (ListService/CreateService/.../DeleteCheckService/ActivityListService),
     * one per named bulk-action key, one per delegation key, and one per
     * action key — mirroring DelegationServiceGenerator/ActionServiceGenerator's
     * own `{Module}{Key}Service.php` naming exactly, plus a shared
     * `{Module}TestCase` base class every split file extends.
     *
     * Replaces the single bundled `{Module}CrudTest.php` this generator used
     * to emit. Motivation: MakeDelegation/MakeAction force-regenerated that
     * ONE file to pick up a single new delegation/action, wholesale
     * clobbering any hand-edited test logic elsewhere in it —
     * BaseGenerator::writeFile() has no merge, only skip-or-overwrite. One
     * file per artifact means a scoped add (see regenerateOnly()) only ever
     * touches its own file.
     *
     * Every `build*TestMethod()` helper below this method is UNCHANGED from
     * the single-file design — each already returns a self-contained method
     * body with no runtime dependency on any OTHER test method, only on the
     * two helpers now living on the shared base class
     * (create{Singular}Fixture()) and the ActsWithoutPermission trait
     * (actingAsUserWithoutPermission()). This method only changes WHICH file
     * each one's output lands in.
     */
    public function generate(): bool
    {
        $fields        = $this->fieldsSource();
        $snakeSingular = Str::snake($this->moduleSingular);
        $snakePlural   = Str::snake(Str::plural($this->moduleName));
        $routeBase     = Str::kebab($this->moduleName);
        $tableName     = $this->getTableName();

        $allWritten = true;

        $allWritten = $this->writeTestCaseBase($fields) && $allWritten;

        // ── List (+ filter/export/import/bulk-action-missing-ids, list-gated) ──
        $listMethods = [];
        if ($this->hasList) {
            $listMethods[] = $this->buildListTestMethod($snakePlural, $routeBase);
        }
        // Every generated list carries filter support, so this is unconditional.
        $listMethods[] = $this->buildFilterTestMethod($snakePlural, $routeBase);
        if ($this->hasExport) {
            $listMethods[] = $this->buildExportTestMethod($routeBase);
        }
        if ($this->hasImportTemplate) {
            $listMethods[] = $this->buildImportTemplateTestMethod($routeBase);
            $listMethods[] = $this->buildImportFileUploadTestMethod($routeBase);
        }
        if ($this->hasList) {
            $listMethods[] = $this->buildBulkActionMissingIdsValidationTestMethod($routeBase);
        }
        if ($this->hasSoftDeletes && $this->hasDelete && $this->hasList) {
            $listMethods[] = $this->buildSoftDeleteExcludedFromListTestMethod($snakeSingular, $snakePlural, $routeBase);
        }
        if ($this->hasList) {
            $listMethods[] = $this->buildListForbiddenTestMethod($snakePlural, $routeBase);
            $listMethods[] = $this->buildBulkActionForbiddenTestMethod($routeBase);
        }
        $allWritten = $this->writeSplitFile('ListService', $listMethods) && $allWritten;

        // ── Create (+ every create-time validation variant, create-gated) ──
        $createMethods = [];
        if ($this->hasCreate) {
            $createMethods[] = $this->buildCreateTestMethod($fields, $snakeSingular, $routeBase, $tableName);
            $createMethods[] = $this->buildCreateValidationTestMethod($fields, $snakeSingular, $routeBase);
            $createMethods[] = $this->buildDuplicateUniqueValidationTestMethod($fields, $snakeSingular, $routeBase);
            $createMethods[] = $this->buildOverlengthValidationTestMethod($fields, $snakeSingular, $routeBase);
            $createMethods[] = $this->buildNonexistentFkValidationTestMethod($fields, $snakeSingular, $routeBase);
            $createMethods = array_merge($createMethods, $this->buildEnumValidationTestMethods($fields, $snakeSingular, $routeBase));
            $createMethods[] = $this->buildDecimalPrecisionTestMethod($fields, $snakeSingular, $routeBase);
            $createMethods[] = $this->buildCreateForbiddenTestMethod($snakeSingular, $routeBase);
        }
        // Splash coverage mirrors RoutesGenerator's own opt-in-twice gate
        // exactly (see $hasCreateSplash's docblock) — independent of
        // $hasCreate itself, so this stays OUTSIDE that gate: a module can
        // declare createSplash without create being enabled.
        if ($this->hasCreateSplash) {
            $createMethods[] = $this->buildCreateSplashTestMethod($routeBase);
        }
        $allWritten = $this->writeSplitFile('CreateService', $createMethods) && $allWritten;

        // ── Edit (+ file-edit-without-reupload, edit-splash, edit-gated) ──
        $editMethods = [];
        if ($this->hasEdit) {
            $editMethods[] = $this->buildEditTestMethod($fields, $snakeSingular, $routeBase, $tableName);
            if ($this->isMultipartModule) {
                $editMethods[] = $this->buildFileEditWithoutReuploadTestMethod($fields, $snakeSingular, $routeBase);
            }
            $editMethods[] = $this->buildEditForbiddenTestMethod($snakeSingular, $routeBase);
        }
        // Independent of $hasEdit — same reasoning as createSplash above.
        if ($this->hasEditSplash) {
            $editMethods[] = $this->buildEditSplashTestMethod($routeBase);
        }
        $allWritten = $this->writeSplitFile('EditService', $editMethods) && $allWritten;

        // ── View ──
        $viewMethods = [];
        if ($this->hasView) {
            $viewMethods[] = $this->buildViewTestMethod($fields, $snakeSingular, $routeBase);
            $viewMethods[] = $this->buildViewForbiddenTestMethod($snakeSingular, $routeBase);
        }
        $allWritten = $this->writeSplitFile('ViewService', $viewMethods) && $allWritten;

        // ── Delete ──
        $deleteMethods = [];
        if ($this->hasDelete) {
            $deleteMethods[] = $this->buildDeleteTestMethod($snakeSingular, $routeBase, $tableName);
            $deleteMethods[] = $this->buildDeleteForbiddenTestMethod($snakeSingular, $routeBase);
        }
        $allWritten = $this->writeSplitFile('DeleteService', $deleteMethods) && $allWritten;

        // ── DeleteCheck — unconditional in the pipeline, same as before ──
        $deleteCheckMethods = [$this->buildDeleteCheckTestMethod($routeBase)];
        $blockingDependent = $this->firstResolvableDependentModule();
        if ($blockingDependent !== null) {
            $deleteCheckMethods[] = $this->buildDeleteCheckBlockingTestMethod($blockingDependent, $routeBase);
        }
        $allWritten = $this->writeSplitFile('DeleteCheckService', $deleteCheckMethods) && $allWritten;

        // ── ActivityList — unconditional; note the emitted file is
        // {Module}ActivityListServiceTest.php, mirroring the actual service
        // filename {Module}ActivityListService.php (ActivityServiceGenerator
        // lives at Backend/Service/, singular, and its OWN class name omits
        // "List" even though its output filename doesn't) ──
        $allWritten = $this->writeSplitFile('ActivityListService', [$this->buildActivityTestMethod($routeBase)]) && $allWritten;

        // ── One file per named, non-status-target bulk-action key ──
        foreach ($this->allGenericBulkActionKeys() as $bulkActionKey) {
            $bulkMethods = [
                $this->buildBulkActionIdsModeTestMethod($bulkActionKey, $routeBase),
                $this->buildBulkActionFilterModeTestMethod($bulkActionKey, $routeBase),
            ];
            $allWritten = $this->writeSplitFile(Str::studly($bulkActionKey) . 'Service', $bulkMethods) && $allWritten;
        }

        // ── One file per delegation key ──
        foreach ($this->config['delegations'] ?? [] as $delegationKey => $delegation) {
            if (!is_array($delegation)) {
                continue;
            }
            $methods = $this->buildDelegationTestMethodsFor((string) $delegationKey, $delegation);
            $suffix = ($delegation['name'] ?? $delegationKey) . 'Service';
            $allWritten = $this->writeSplitFile($suffix, $methods) && $allWritten;
        }

        // ── One file per action key — every action now gets coverage,
        // not just the first enabled one (the old single-file design's
        // restriction; see buildActionServiceTestMethodsForKey()'s docblock) ──
        foreach ($this->config['actions'] ?? [] as $actionKey => $action) {
            if (!is_array($action)) {
                continue;
            }
            $methods = $this->buildActionServiceTestMethodsForKey((string) $actionKey, $action);
            $suffix = $this->resolveActionServiceNameSuffix((string) $actionKey, $action) . 'Service';
            $allWritten = $this->writeSplitFile($suffix, $methods) && $allWritten;
        }

        $this->deleteStaleMonolithicFileIfPresent();

        return $allWritten;
    }

    /**
     * Scoped regeneration for exactly one delegation, action, or bulk-action
     * key — used by MakeDelegation/MakeAction when a single new key is added
     * to an already-scaffolded module (the 'bulk_action' kind has no
     * equivalent scoped-add command yet on the consuming side as of this
     * writing, but the method supports it for parity — a scoped bulk-action
     * addition previously fell through to the unconditional `return false;`
     * below with no error, since only 'delegation'/'action' were handled).
     * Writes (unconditionally, via writeFileAlways()) ONLY the one split
     * file matching $key; every other split file this module already has is
     * left completely untouched, regardless of the object's own $force
     * flag. This is the fix for the exact bug that motivated the whole
     * split: the old single-file design had no way to add one delegation's
     * coverage without wholesale overwriting every other delegation/CRUD
     * test in the same file.
     *
     * Ensures {Module}TestCase exists first (skip-if-exists, never
     * overwritten here) — needed the FIRST time an old-style, still
     * unsplit module gets a delegation/action/bulk-action added, since the
     * new split file extends it. None of the three ever changes the
     * module's own create/edit field shape, so the base class is never
     * regenerated by this method once it already exists.
     *
     * @param string $kind 'delegation', 'action', or 'bulk_action'
     */
    public function regenerateOnly(string $key, string $kind): bool
    {
        if (!is_file($this->testCaseClassPath())) {
            $this->writeTestCaseBase($this->fieldsSource());
        }

        if ($kind === 'delegation') {
            $delegation = $this->config['delegations'][$key] ?? null;
            if (!is_array($delegation)) {
                return false;
            }
            $methods = $this->buildDelegationTestMethodsFor($key, $delegation);
            $suffix = ($delegation['name'] ?? $key) . 'Service';
            return $this->writeSplitFileAlways($suffix, $methods);
        }

        if ($kind === 'action') {
            $action = $this->config['actions'][$key] ?? null;
            if (!is_array($action)) {
                return false;
            }
            $methods = $this->buildActionServiceTestMethodsForKey($key, $action);
            $suffix = $this->resolveActionServiceNameSuffix($key, $action) . 'Service';
            return $this->writeSplitFileAlways($suffix, $methods);
        }

        if ($kind === 'bulk_action') {
            $listConfig = $this->config['features']['backend']['list'] ?? [];
            $bulkActions = is_array($listConfig) ? ($listConfig['bulk_actions'] ?? []) : [];
            $bulkAction = null;
            foreach ($bulkActions as $entry) {
                if (($entry['key'] ?? '') === $key) {
                    $bulkAction = $entry;
                    break;
                }
            }
            // Same eligibility rule as allGenericBulkActionKeys(): a
            // status_target entry references a model constant this
            // generator can't verify exists, so it gets no happy-path
            // coverage here either — matches generate()'s own fan-out.
            if ($bulkAction === null || !empty($bulkAction['status_target'])) {
                return false;
            }
            $routeBase = Str::kebab($this->moduleName);
            $methods = [
                $this->buildBulkActionIdsModeTestMethod($key, $routeBase),
                $this->buildBulkActionFilterModeTestMethod($key, $routeBase),
            ];
            return $this->writeSplitFileAlways(Str::studly($key) . 'Service', $methods);
        }

        return false;
    }

    // ─── Split-file writing ─────────────────────────────────────────────────

    protected function testCaseClassPath(): string
    {
        return $this->modulePath . '/Tests' . "/{$this->moduleName}TestCase.php";
    }

    protected function renderTestCaseClass(array $fields): string
    {
        $stub = $this->getTemplateContent('Tests/test_case', 'backend');

        return $this->replacePlaceholders($stub, [
            '[[testNamespace]]'    => $this->getNamespace() . '\Tests',
            '[[UsersModelImport]]' => $this->usersModelImportLine(),
            '[[fixtureHelper]]'    => $this->buildFixtureHelper($fields),
        ]);
    }

    /** Regenerated freely (schema-driven) — see generate()'s docblock. */
    protected function writeTestCaseBase(array $fields): bool
    {
        return $this->writeFile($this->testCaseClassPath(), $this->renderTestCaseClass($fields));
    }

    protected function splitTestFilePath(string $suffix): string
    {
        return $this->modulePath . '/Tests' . "/{$this->moduleName}{$suffix}Test.php";
    }

    /**
     * Renders one split test file's content, or null when every method for
     * this suffix was gated off (nothing to write — not a failure).
     *
     * @param array<int, string|null> $methods
     */
    protected function renderSplitTestFile(string $suffix, array $methods): ?string
    {
        $methods = array_values(array_filter($methods));
        if (empty($methods)) {
            return null;
        }

        $stub = $this->getTemplateContent('Tests/split_test', 'backend');

        return $this->replacePlaceholders($stub, [
            '[[testNamespace]]'    => $this->getNamespace() . '\Tests',
            '[[UsersModelImport]]' => $this->usersModelImportLine(),
            '[[TestClassName]]'    => "{$this->moduleName}{$suffix}Test",
            '[[testMethods]]'      => implode("\n\n", $methods),
        ]);
    }

    /** Skip-if-exists unless $this->force — same contract as every other generated file. */
    protected function writeSplitFile(string $suffix, array $methods): bool
    {
        $content = $this->renderSplitTestFile($suffix, $methods);
        if ($content === null) {
            return true;
        }
        return $this->writeFile($this->splitTestFilePath($suffix), $content);
    }

    /** Unconditional write — used only by regenerateOnly()'s single targeted key. */
    protected function writeSplitFileAlways(string $suffix, array $methods): bool
    {
        $content = $this->renderSplitTestFile($suffix, $methods);
        if ($content === null) {
            return false;
        }
        return $this->writeFileAlways($this->splitTestFilePath($suffix), $content);
    }

    protected function usersModelImportLine(): string
    {
        $moduleModelFqcn = $this->getNamespace() . '\\' . $this->moduleName . 'Model';

        return $moduleModelFqcn === self::USERS_MODEL_FQCN
            ? ''
            : 'use ' . self::USERS_MODEL_FQCN . ";\n";
    }

    /**
     * Removes the pre-split `{Module}CrudTest.php`, when present, once this
     * run has written the new split files under --force. Without this, both
     * PHPUnit's directory-based discovery and Playwright's testMatch glob
     * would pick up the stale monolithic file AND the new split files
     * simultaneously — silently doubling real DB writes and CI time for
     * this module. Only ever fires under --force (an ordinary run must never
     * delete a file it didn't just decide to replace), and only reached
     * after every split write above completed without throwing — a mid-run
     * exception propagates out of generate() immediately, so this is never
     * reached on a failed run, and a module never loses coverage from a
     * failed deletion-then-write.
     *
     * Old-style and new-style (split) modules are expected to coexist
     * indefinitely — this is not "finish the migration later" cleanup, it's
     * the one place a module actually transitions between the two layouts.
     */
    protected function deleteStaleMonolithicFileIfPresent(): void
    {
        if (!$this->force) {
            return;
        }

        $legacyPath = $this->modulePath . '/Tests' . "/{$this->moduleName}CrudTest.php";
        if (!is_file($legacyPath)) {
            return;
        }

        unlink($legacyPath);
        PathManager::reportIssue(
            "Removed legacy monolithic test file: {$this->moduleName}CrudTest.php (replaced by per-service split)",
            'info'
        );
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

        // Enum columns (enum_values meta — see findColumnConfig()'s
        // docblock for where that key lives) must get a REAL member of the
        // set, not the generic 'Test Field ' . uniqid() string below.
        // BaseServiceGenerator::generateValidationRules() emits a
        // Rule::in([...]) for every enum_values column, so an arbitrary
        // string 422s any HTTP request payload built from this literal
        // ($forHttpRequest true) — and, worse, the SAME literal also feeds
        // buildFixtureHelper()'s direct Model::create() call
        // ($forHttpRequest false), which bypasses validation entirely and
        // sends the bogus string straight to MySQL, truncating against the
        // column's real ENUM(...) definition (SQLSTATE[01000] warning
        // 1265). Confirmed live: this accounted for all 17 failures in a
        // real 5-module generation run. Deterministically the FIRST
        // configured value (no randomness) so fixtures/payloads stay
        // reproducible across runs. var_export() escaping mirrors
        // FactoryGenerator::buildValueExpression()'s 'enum' case exactly
        // (see that method's own docblock) — safe for any enum value
        // regardless of which of `'`, `"`, `\` it contains.
        $enumValues = $this->findColumnConfig($field)['enum_values'] ?? null;
        if (is_array($enumValues) && !empty($enumValues)) {
            return var_export((string) $enumValues[0], true);
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

            // Same bug this class's own resolveCrossModuleFkLiteral() docblock
            // already documents for a DIFFERENT symptom (id=1 gambling) applies
            // here too: $rules is V1's frontend-computed string, which can embed
            // a malformed table name (e.g. "itemcategories", no underscore --
            // generateColumnsValidations() in generator.ts only recomputes once,
            // the first time its wizard step mounts, so a relatedModule set or
            // changed afterward never re-syncs the baked-in `exists:` clause).
            // BaseServiceGenerator::generateValidationRules() already corrects
            // this for the real generated Service's validation rule (see its
            // own docblock) -- but that correction happens at render time and
            // is never written back into the stored config, so this class,
            // reading the SAME raw config independently, needs the identical
            // correction or it re-derives the same wrong table name and can
            // never resolve the real target module. Confirmed live against
            // ExpenseItems.category_id -> ExpenseCategories.
            $relatedModule = $this->findColumnConfig($field)['relatedModule'] ?? null;
            if ($relatedModule) {
                $foreignTable = Str::snake(Str::plural($relatedModule));
            }

            $isSelfReferential = $foreignTable === $this->getTableName();
            $isNullable = str_contains($rules, 'nullable');

            if ($isSelfReferential || $isNullable) {
                return 'null';
            }

            // Bug (found 2026-08-16 running the retail-ERP demo fixture's own
            // generated PHPUnit suite live, not caught by any prior static
            // read of generated source): a required `location_id` FK
            // resolved through the generic branch below like any other
            // cross-module FK -- `LocationsModel::factory()->create()->id`,
            // a brand-new, unrelated Location the seeded DEVELOPER test
            // actor has no `UserLocations` assignment to. SYSTEM_SHELL-style
            // consuming apps hand-maintain a location-scoping mechanism
            // (`ListServiceTrait::applyLocationFiltering()` /
            // `LocationContextService`) that silently excludes every row
            // outside the acting user's assigned locations -- confirmed live:
            // a fixture built this way is invisible to its own module's
            // list/filter tests, a guaranteed, reproducible failure for
            // every module with a required `location_id` column, not
            // something specific to this one fixture. This generator has no
            // visibility into that mechanism (it's entirely hand-maintained,
            // outside generator-engine), so it can't detect *whether* a
            // consumer applies location-scoping -- but reusing whatever
            // location DEVELOPER (UsersModel::DEVELOPER, already the
            // established creator/updater convention throughout this
            // generator) is actually assigned to, falling back to a fresh
            // factory row only if they have none, is strictly safer than
            // always creating an unrelated one: correct whether or not the
            // consumer scopes by location, since an unscoped consumer never
            // looks at `UserLocations` at all.
            if ($field === 'location_id' && $foreignTable === 'locations') {
                $userLocationsNs = PathManager::resolveBackendModuleNamespace('UserLocations');
                $usersNs = PathManager::resolveBackendModuleNamespace('Users');
                $locationsNs = PathManager::resolveBackendModuleNamespace('Locations');
                return "\\{$userLocationsNs}\\UserLocationsModel::where('user_id', \\{$usersNs}\\UsersModel::DEVELOPER)->value('location_id') ?? \\{$locationsNs}\\LocationsModel::factory()->create()->id";
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

        // BaseServiceGenerator::generateValidationRules() emits the exact
        // same bare 'date' validation rule for a 'date', 'datetime', AND
        // 'timestamp' normalized_type column alike (Laravel's `date` rule
        // accepts all three DB shapes), so $rules alone can never tell them
        // apart. A date-only literal (now()->toDateString(), e.g.
        // "2026-07-27") is wrong for a datetime/timestamp column: MySQL
        // stores it as "2026-07-27 00:00:00" local time, which — under a
        // non-UTC app timezone (e.g. Africa/Dar_es_Salaam, UTC+3) — the API
        // response then serializes as the UTC instant
        // "2026-07-26T21:00:00.000000Z", never matching the raw date-only
        // string this branch previously emitted for every 'date'-rule field
        // regardless of actual column type. Confirmed live: this was
        // responsible for the create/edit test of every datetime/timestamp
        // column across 15 modules failing identically
        // (alert_logs.triggered_at, e.g.). findColumnConfig()'s 'type' key
        // carries the real IntrospectionToConfig::buildColumn() normalized
        // type ('date' vs 'datetime' vs 'timestamp'), so branching on THAT
        // — not on $rules — is the only reliable way to keep a true `date`
        // column's fixture date-only (unaffected by timezone entirely) while
        // giving a datetime/timestamp column a full local datetime string
        // instead.
        // Bug (found live 2026-08-18, StockTransfers, Inventory cluster):
        // V1's own bulk-gen wizard-port (BackendFeatureDeriver::validations(),
        // a documented "faithful port" of generator.ts's own
        // generateColumnsValidations()) never emits a 'date' rule at all for
        // datetime/timestamp columns -- only for a column whose type is
        // EXACTLY 'date' -- so $rules alone can be entirely silent about a
        // column ($rules === "nullable") that is still genuinely date-shaped.
        // Relying purely on the rules string (as this branch used to) left
        // such a column falling through to the generic 'Test Field ' .
        // uniqid() string literal further below, which the model's real
        // datetime cast then fails to parse (Carbon InvalidFormatException).
        // Checking the column's own real declared type directly closes this
        // regardless of which upstream deriver produced -- or omitted -- the
        // rule. Confirmed live: StockTransfers.picked_up_at/delivered_at/
        // confirmed_at/issue_otp_expires_at, all `["nullable"]`-only rules.
        $columnType = $this->findColumnConfig($field)['type'] ?? '';
        if (str_contains($rules, 'date') || in_array($columnType, ['date', 'datetime', 'timestamp'], true)) {
            return $this->isDateTimeField($field)
                ? "now()->format('Y-m-d H:i:s')"
                : 'now()->toDateString()';
        }

        // JSON/array columns (IntrospectionToConfig::buildBackendFields()
        // emits a bare 'array' rule for every normalized_type === 'json'
        // column, alongside 'nullable'/'required' — see that method's `elseif
        // ($type === 'json')` branch) previously fell all the way through to
        // the generic string literal below, handing e.g.
        // `'Test AppliesTo ' . uniqid()` to a column the generated
        // service validates as `["nullable","array"]`. That 422s the
        // generated test against its OWN generated service — confirmed live
        // against terms_and_conditions.applies_to, and recurring across every
        // json column in a real project (18 of them). \b-bounded so this
        // never fires for a rule that merely contains "array" as a substring
        // of something else — in practice no other rule this generator emits
        // ever does (checked every branch above: required, nullable,
        // integer, exists:, string, max:, numeric, boolean, date, unique:),
        // but the boundary check costs nothing and matches the same
        // discipline as the integer|numeric branch above.
        //
        // A non-empty literal, not `[]`: BaseServiceGenerator::
        // generateValidationRules() never emits a nested `{field}.*` element
        // rule for a json/array column (confirmed by reading that method in
        // full — it builds one rule set per top-level field only, with no
        // per-element pass), so there is no hidden per-item constraint an
        // arbitrary element could trip over. `[]` would technically satisfy
        // `nullable|array` too, but exercises nothing (an always-empty array
        // survives create/edit/response/DB round-trips regardless of whether
        // the underlying `array` cast is even wired up correctly). `['test']`
        // is scalar, JSON-safe, and stable to compare in three different
        // shapes below (raw PHP array in the fixture-helper's
        // Model::create() call, json_encode()'d in assertDatabaseHas(), and
        // as a real decoded array in assertJsonPath()).
        //
        // Deliberately NOT $isMultipart-gated the way the boolean branch
        // above is: this test-generator's HTTP-issuing calls always go
        // through Laravel's in-process TestCase::post()/postJson(), which
        // populates the Request's ParameterBag directly from the PHP $data
        // array rather than actually serializing to multipart/form-data wire
        // format — an array parameter therefore survives as a real PHP array
        // on both the JSON and "multipart" (file-carrying) request paths
        // alike, unlike a raw boolean, which the multipart path there
        // deliberately stringifies for a DIFFERENT reason (an actual file
        // upload sibling forcing $isMultipart true does not, by itself,
        // change how Laravel's test harness treats this field).
        if (preg_match('/\barray\b/', $rules)) {
            return "['test']";
        }

        $studly = Str::studly($field);

        // A bare `max:(\d+)` rule caps how long this literal is allowed to
        // be. `uniqid()` alone is already 13 characters, so the previous
        // unconditional `'{$prefix} {$studly} ' . uniqid()` (up to ~24+
        // chars once the label is added) silently overflowed any
        // varchar(20)-or-smaller column: `Model::create()` fixtures 500'd
        // with SQLSTATE 22001 "Data too long", and HTTP-issuing payloads
        // 422'd against the service's own generated `max:N` validation rule
        // — confirmed live against trading_partners.phone (max 20),
        // orders.payment_type (max 20), financial_accounts.currency (max
        // 10), and partner_transaction_types.color (max 7), accounting for
        // 102 of 103 failures in a real generation run. buildOverlengthValidationTestMethod()
        // already parses this exact `max:(\d+)` shape a few hundred lines
        // down for the deliberately-too-long negative test — same regex
        // here, opposite goal: fit inside the bound instead of exceeding it.
        if (preg_match('/max:(\d+)/', $rules, $maxMatch)) {
            return $this->buildMaxAwareUniqueStringLiteral($prefix, $studly, (int) $maxMatch[1]);
        }

        return "'{$prefix} {$studly} ' . uniqid()";
    }

    /**
     * Build a `'{label}' . uniqid()`-shaped literal (or a pure-entropy
     * fallback) that never exceeds $max bytes, while keeping the fixture
     * unique across repeated calls in the same test — several affected
     * columns (trading_partners.phone, e.g.) also carry `unique:`, and
     * buildListTestMethod() calls the fixture helper three times in a row
     * with no overrides.
     *
     * The label is truncated from the RIGHT (kept short), never `uniqid()`
     * itself: `uniqid()` encodes the current time in seconds + microseconds,
     * most-significant digits first, so its TAIL is what actually varies
     * between two calls a few microseconds apart — the same property
     * PathManager-style entropy tokens usually rely on. Chopping off the
     * end of `uniqid()`'s output (e.g. keeping only its first N chars)
     * would keep the slow-changing "seconds" prefix and throw away exactly
     * the fast-changing part, defeating the uniqueness this literal exists
     * to provide. Truncating the LABEL instead costs nothing — it's cosmetic
     * padding, never compared against anything.
     *
     * `uniqid()` is a fixed 13-character hex string (no `more_entropy`
     * argument is ever passed here), so the budget math below is exact, not
     * a runtime guess.
     */
    protected function buildMaxAwareUniqueStringLiteral(string $prefix, string $studly, int $max): string
    {
        $uidLength = 13;
        $label = "{$prefix} {$studly} ";

        if ($max >= strlen($label) + $uidLength) {
            // Fits with room to spare — byte-for-byte the same literal this
            // method exists to special-case away from.
            return "'{$label}' . uniqid()";
        }

        if ($max <= 0) {
            // Degenerate (max:0 is not a real-world column bound); emit
            // something syntactically valid rather than a literal that
            // can't possibly satisfy any rule.
            return "''";
        }

        if ($max < $uidLength) {
            // No room for the full 13-char token even with an empty label —
            // e.g. partner_transaction_types.color's max:7. Keep only the
            // last $max characters of uniqid()'s output (still the
            // fastest-varying part) and drop the label entirely.
            return "substr(uniqid(), -{$max})";
        }

        // Room for the full uniqid() plus a shortened label.
        $labelBudget = $max - $uidLength;
        $truncatedLabel = substr($label, 0, $labelBudget);

        return "'{$truncatedLabel}' . uniqid()";
    }

    /**
     * Whether $rules carries the bare `array` validation rule — the marker
     * IntrospectionToConfig::buildBackendFields() emits for every
     * normalized_type === 'json' column (see buildFieldValueLiteral()'s array
     * branch for the full reasoning). Factored out because assertDatabaseHas()
     * cannot safely check a json/array column at all: every call site that
     * builds a `[$field => $payload['{$field}']]` DB assertion (create's
     * single-field fallback, edit's multi-field block) needs this same check
     * to EXCLUDE that field's key from the where-clause entirely, rather than
     * comparing it in any form.
     *
     * A raw PHP array value there is the obvious first problem (PDO cannot
     * bind an array). The LESS obvious one, discovered only by actually
     * running the fix against a real MySQL 8 database (json_encode()'d value
     * was the first attempt, and it also failed): MySQL's native JSON column
     * type does NOT compare equal to a bound string parameter via a plain
     * `column = ?` clause, even when the stored bytes are byte-for-byte
     * identical to json_encode()'s output — confirmed live via
     * `SELECT applies_to = '["test"]'` returning 0 against a row whose
     * `HEX(applies_to)` was exactly `5B2274657374225D` (`["test"]`), while
     * `SELECT applies_to = CAST('["test"]' AS JSON)` on the same row returned
     * 1. assertDatabaseHas()'s where() builds the former, uncast form, so a
     * json_encode()'d expected value doesn't just risk being wrong — it's
     * ALWAYS silently false for this column, regardless of whether the row
     * actually matches. Excluding the field is the only reliable option;
     * every OTHER field in the same where-clause (including the response-body
     * `assertJsonPath()` comparison a few lines above, which decodes the JSON
     * response body back into a real PHP array — no raw-string/JSON-column
     * comparison involved there at all) is completely unaffected and keeps
     * asserting exactly what it did before.
     */
    protected function isArrayField(string $rules): bool
    {
        return (bool) preg_match('/\barray\b/', $rules);
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
     * Build the response-body assertion line for a single field inside the
     * create/edit test methods.
     *
     * Defaults to comparing against `$payload['field']` — correct for every
     * ordinary column, since buildFieldValueLiteral() only ever diverges the
     * PAYLOAD's own PHP type from the JSON response's cast type in exactly
     * one case: a boolean column on the MULTIPART path, where the payload
     * literal is deliberately the STRING '1'/'0' (multipart form data
     * carries everything as a string — see buildFieldValueLiteral()'s
     * boolean branch) while the model's boolean cast means the JSON
     * response holds a real `true`/`false`. `assertJsonPath()` uses strict
     * (`===`) comparison, so `true !== '1'` always fails there — confirmed
     * live: `Failed asserting that true is identical to '1'.`, breaking
     * test_can_create_item_image/test_can_edit_item_image plus two
     * validation tests in a real multipart module. Every OTHER type
     * (integer/decimal/date) either never diverges its literal's PHP type
     * based on $isMultipart at all (integer/decimal — see
     * buildFieldValueLiteral()'s numeric branch, which ignores $isMultipart
     * entirely and always emits a bare, unquoted literal) or diverges
     * identically on both the JSON and multipart paths (date — also
     * $isMultipart-independent), so neither needs this override.
     *
     * Gated on $this->isMultipartModule so a non-multipart module's
     * assertion is completely untouched — its payload already holds a real
     * PHP boolean and the default `$payload['field']` comparison already
     * passes there.
     */
    protected function buildResponseAssertLine(string $field, string $rules): string
    {
        // Bug (found 2026-08-16 running the retail-ERP demo fixture's own
        // generated PHPUnit suite live): a field with a field-level
        // `processing_service` configured (features.backend.{create,edit}.
        // fields[].processing_service -- see BaseServiceGenerator's
        // generateFileColumnUploads()/field-processing docblocks for the
        // mechanism itself) is EXPECTED to differ from the raw submitted
        // payload once the processor actually runs -- that's the whole
        // point of the feature. A strict `=== $payload['field']` assertion
        // here is asserting the transformation *doesn't* happen, so it
        // fails the moment a hand-written processor does real work.
        // Confirmed live against VendorsCreateServiceTest (a
        // NormalizeTinProcessingService uppercasing `tin`). This generator
        // can't know what a hand-written processor actually does, so it
        // can't assert the transformed value -- but per this method's own
        // "field is never silently unverified" principle (see the
        // assertDatabaseHas()-omission docblock a few dozen lines below),
        // it still asserts the field round-tripped as *something* real
        // rather than dropping the assertion entirely.
        if ($this->hasProcessingService($field)) {
            return "            ->assertJsonPath('data.{$field}', fn (\$value) => \$value !== null && \$value !== '')";
        }

        if ($this->isMultipartModule && str_contains($rules, 'boolean')) {
            return "            ->assertJsonPath('data.{$field}', true)";
        }

        // A datetime/timestamp column's response value has round-tripped
        // through Carbon and the API's own JSON serialization, which
        // renders it in UTC (`...T...Z`) regardless of the app's configured
        // timezone — see buildFieldValueLiteral()'s date branch docblock for
        // the full mechanics. The payload literal this test itself submitted
        // is a LOCAL-timezone string with no offset info at all, so a plain
        // `=== $payload['field']` string comparison (assertJsonPath()'s
        // default, PHPUnit::assertSame() under the hood) is comparing two
        // different string shapes of the very same instant and always
        // fails — confirmed live against alert_logs.triggered_at et al.
        // under Africa/Dar_es_Salaam (UTC+3): stored "2026-07-27 00:00:00"
        // serializes as "2026-07-26T21:00:00.000000Z", never string-equal to
        // the submitted "2026-07-27 00:00:00". assertJsonPath() accepts a
        // Closure expectation (Illuminate\Testing\AssertableJsonString::
        // assertPath() special-cases it: `PHPUnit::assertTrue($expect($this->json($path)))`),
        // so comparing two Carbon::parse() INSTANTS instead — rather than
        // their string forms — is correct regardless of which timezone
        // either string is rendered in, while still failing hard if the
        // backend actually returns the wrong instant.
        if ($this->isDateTimeField($field)) {
            return "            ->assertJsonPath('data.{$field}', fn (\$value) => \\Carbon\\Carbon::parse(\$value)->equalTo(\\Carbon\\Carbon::parse(\$payload['{$field}'])))";
        }

        return "            ->assertJsonPath('data.{$field}', \$payload['{$field}'])";
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

    /**
     * Same ordering/fallback as firstUniqueField() above (first `unique:`
     * field, else $fields[0]), but never returns a json/array-typed field —
     * used ONLY by buildCreateTestMethod()'s single-field assertDatabaseHas()
     * fallback (see isArrayField()'s docblock for why that field can never be
     * safely compared there). A no-op relative to firstUniqueField() for any
     * module with no array-typed field at all, since isArrayField() is false
     * for every other rule shape this generator emits.
     *
     * Returns null only for the degenerate case where EVERY configured field
     * is array-typed — there is no other column left to build a meaningful
     * assertDatabaseHas() check from, so the caller omits that line entirely
     * rather than emit one that can never pass.
     *
     * A datetime/timestamp field is excluded here for the same reason an
     * array/json field is: assertDatabaseHas() builds a raw `column = ?`
     * where-clause comparing the RAW DB row value against $payload['field']
     * — a plain string comparison, with no Carbon parsing involved at all —
     * which is exactly the string-vs-instant mismatch buildResponseAssertLine()'s
     * docblock explains (a datetime column's stored local-time string never
     * string-equals the payload's own local-time string once the two differ
     * in precision/representation, and assertDatabaseHas() has no closure
     * escape hatch to compare instants the way assertJsonPath() does). This
     * field is never silently unverified, though — buildResponseAssertLine()'s
     * Carbon-instant assertJsonPath() closure already covers it via the
     * create/edit response body, the same "verified elsewhere, just not via
     * this raw-string DB check" reasoning already applied to json/array
     * fields here. buildEditTestMethod() additionally gives every
     * datetime/timestamp edit field its own dedicated Carbon-instant
     * assertTrue() DB check (see that method), since its combined
     * assertDatabaseHas() call covers every OTHER edited field at once and
     * would otherwise silently drop datetime coverage from that composite
     * assertion entirely.
     */
    protected function firstDbAssertableField(array $fields): ?array
    {
        // A processing_service/processors[]-mutated field is excluded for
        // the same reason as array/datetime fields above it (see this
        // method's own docblock) — the stored value is expected to differ
        // from $payload['field'], so a raw `column = ?` comparison here
        // would assert the mutation doesn't happen. Confirmed live picking
        // Vendors.tin (a NormalizeTinProcessingService field) as the sole
        // assertable column when it happened to be $fields[0].
        foreach ($fields as $f) {
            if (str_contains($f['rules'] ?? '', 'unique:') && !$this->isArrayField($f['rules'] ?? '') && !$this->isDateTimeField($f['field'] ?? '') && !$this->hasProcessingService($f['field'] ?? '')) {
                return $f;
            }
        }
        foreach ($fields as $f) {
            if (!$this->isArrayField($f['rules'] ?? '') && !$this->isDateTimeField($f['field'] ?? '') && !$this->hasProcessingService($f['field'] ?? '')) {
                return $f;
            }
        }
        return null;
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

        // Table-level composite UNIQUE constraints (unique_constraints,
        // added to module config in v2.12.0 — see IntrospectionToConfig::
        // buildIndexesAndUniqueConstraints()) were never consulted here at
        // all: every field-level literal above is independently unique (or
        // not) per-column, so a two-column unique like
        // daily_marketing_plans' UNIQUE(marketing_officer_id, plan_date)
        // still collided the moment buildListTestMethod() calls this helper
        // three times in a row with no overrides — every row got the same
        // (marketing_officer_id, plan_date) pair. See
        // buildCompositeUniqueVarianceLines()'s docblock for how the varying
        // member is chosen and why an all-FK constraint is skipped outright
        // rather than "fixed" with a fixture that can't work.
        $compositeLines = $this->buildCompositeUniqueVarianceLines();
        $sequenceDeclaration = '';
        if ($compositeLines !== '') {
            $sequenceDeclaration = "        static \$uniqueSequence = 0;\n        \$uniqueSequence++;\n\n";
            $compositeLines .= "\n";
        }

        return <<<PHP
    protected function create{$this->moduleSingular}Fixture(array \$overrides = []): {$this->moduleName}Model
    {
{$sequenceDeclaration}        return {$this->moduleName}Model::create(array_merge([
{$defaults}{$compositeLines}            'created_by_id' => UsersModel::DEVELOPER,
        ], \$overrides))->fresh();
    }
PHP;
    }

    /**
     * Build one array-literal line per composite unique constraint whose
     * varying member this generator can safely pick, so three back-to-back
     * create{Singular}Fixture() calls (buildListTestMethod()'s pattern, no
     * overrides) don't all submit an identical tuple for a constraint like
     * UNIQUE(marketing_officer_id, plan_date).
     *
     * Each emitted line re-declares that column's key in the SAME array
     * literal `array_merge()`'s first argument builds from — a duplicate PHP
     * array key is not an error, the LAST occurrence simply wins — so this
     * safely overrides whatever literal buildPayloadLines() already emitted
     * for that field above (or adds it fresh, if the field wasn't a
     * create/edit field at all). `array_merge($these, $overrides)` still
     * puts `$overrides` last of all, so a caller-supplied override for that
     * same key continues to win over both.
     *
     * Deliberately prefers a non-FK member (firstNonFkCompositeUniqueMember())
     * over varying an FK column: a fresh integer/uniqid()-suffixed value for
     * an ordinary string/date/numeric column is always safe to insert, but
     * "varying" an FK member would mean fabricating a *different* parent row
     * id per call — this generator has no basis to assume one exists (the
     * exact problem buildFieldValueLiteral()'s own exists:/self-referential
     * handling already works around for the FIRST row) and no cheap way to
     * CREATE one from here without knowing the related module's own required
     * columns. A constraint where EVERY member is FK-shaped is therefore
     * skipped outright — no test is far better than one that can't pass.
     */
    protected function buildCompositeUniqueVarianceLines(): string
    {
        $constraints = $this->config['unique_constraints'] ?? [];
        if (empty($constraints)) {
            return '';
        }

        $lines = [];
        $seenFields = [];
        foreach ($constraints as $constraint) {
            $columns = $constraint['columns'] ?? [];
            $member = $this->firstNonFkCompositeUniqueMember($columns);
            if ($member === null) {
                continue;
            }

            // Two constraints landing on the same varying column would
            // otherwise emit the same array key twice in a row — harmless
            // (still just "last wins"), but noisy generated output.
            if (isset($seenFields[$member])) {
                continue;
            }
            $seenFields[$member] = true;

            $lines[] = $this->buildCompositeUniqueVarianceLine($member);
        }

        return implode("\n", $lines);
    }

    /**
     * First member of a composite unique constraint's columns[] whose
     * normalized type is NOT `foreignId` (IntrospectionToConfig::buildColumn()
     * — see findColumnConfig()'s docblock for where that shape comes from).
     * Returns null when every member is FK-shaped, signalling the caller to
     * skip the constraint entirely rather than risk it.
     */
    protected function firstNonFkCompositeUniqueMember(array $columns): ?string
    {
        foreach ($columns as $columnName) {
            $columnConfig = $this->findColumnConfig((string) $columnName);
            $type = $columnConfig['type'] ?? '';
            if ($type !== 'foreignId') {
                return (string) $columnName;
            }
        }

        return null;
    }

    /**
     * Render the array-literal line that makes $field vary across
     * successive create{Singular}Fixture() calls, shaped by the column's own
     * normalized type so the value stays valid for that column (a date
     * column can't hold an arbitrary string, etc). `$uniqueSequence` is the
     * static call-counter declared by buildFixtureHelper() only when at
     * least one such line is emitted.
     */
    protected function buildCompositeUniqueVarianceLine(string $field): string
    {
        $columnConfig = $this->findColumnConfig($field);
        $type = $columnConfig['type'] ?? '';

        if (str_contains(strtolower($type), 'date')) {
            return "            '{$field}' => now()->addDays(\$uniqueSequence)->toDateString(),";
        }

        if (in_array($type, ['integer', 'bigInteger', 'smallInteger', 'unsignedInteger', 'unsignedBigInteger', 'decimal', 'float', 'double'], true)) {
            return "            '{$field}' => \$uniqueSequence,";
        }

        return "            '{$field}' => 'U' . \$uniqueSequence,";
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
                // stores its returned id back onto the model (see
                // BaseServiceGenerator::generateFileColumnUploads()), so the
                // response value can never equal $payload['{$field}'] (an
                // UploadedFile instance) — asserting exact equality here would
                // always fail. `assertIsInt()` on the raw response value is
                // ALSO wrong whenever the underlying column is a plain
                // varchar (e.g. expenses.receipt_path), not an actual
                // unsignedBigInteger *_media_id FK: MediaService::createFile()
                // itself always returns a real int, but MySQL/Eloquent
                // round-trip that int through a varchar column as the numeric
                // STRING "45" (no int cast on a string column), which the API
                // then serializes as JSON string "45", never a JSON int —
                // confirmed live: `assertIsInt` failed against
                // Expenses/MarketingExpenseItems/Payments' receipt_path-shaped
                // columns with exactly this shape. The one assertion that
                // holds regardless of the column's own storage type is the
                // conversion's actual, durable side effect: a real Media row
                // exists with that id. assertNotNull() there fails exactly
                // when it should — the field is null (upload never
                // converted/persisted) or holds an id nothing created (wrong
                // value) — while passing for both a varchar-stored numeric
                // string and a genuine int-typed *_media_id column alike.
                $fileAssertLines[] = "        \$this->assertNotNull(\\App\\Project\\Modules\\Core\\Media\\MediaModel::find((int) \$response->json('data.{$field}')));";
                continue;
            }

            $assertLines[] = $this->buildResponseAssertLine($field, $fieldDef['rules'] ?? '');
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

        // firstUniqueField() falls back to $fields[0] when nothing carries a
        // `unique:` rule — for a module with no OTHER unique column, that
        // fallback CAN land on a json/array field (confirmed live:
        // terms_and_conditions has no unique column, and its `applies_to`
        // json column sits before `title`/`content` in most real field
        // orderings). See isArrayField()'s docblock for why this field must
        // be skipped entirely rather than compared in any form —
        // firstDbAssertableField() applies that same exclusion on top of
        // firstUniqueField()'s exact ordering/fallback logic, and is a
        // complete no-op (identical field picked) for any module with no
        // array-typed field at all.
        $dbAssertField = $this->firstDbAssertableField($fields);
        $dbAssertLine = $dbAssertField !== null
            ? "        \$this->assertDatabaseHas('{$tableName}', ['{$dbAssertField['field']}' => \$payload['{$dbAssertField['field']}']]);"
            : null;

        $postAssertions = implode("\n\n", array_filter([
            $fileAssertLines ? implode("\n", $fileAssertLines) : null,
            $dbAssertLine,
        ]));

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
            ? "\$this->post('/api/{$routeBase}/create', \$payload, ['Accept' => 'application/json'])"
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

        // A date/datetime/timestamp column's model attribute is a Carbon
        // instance regardless of which of the three it is — ModelGenerator::
        // getCastType() casts a plain 'date' column to 'date:Y-m-d' and a
        // 'datetime'/'timestamp' column to 'datetime' (see that method), and
        // BOTH are still real Carbon objects in PHP, just formatted
        // differently once serialized to JSON. `assertJsonPath('data.field',
        // $fixture->field)` therefore always fails here, for a totally
        // different reason than buildResponseAssertLine()'s payload-based
        // case: it isn't a timezone/format mismatch between two strings, it's
        // PHPUnit::assertSame() comparing the JSON response's STRING against
        // a Carbon OBJECT — confirmed live: `daily_marketing_plans.plan_date`
        // failed with "'2026-07-27' is identical to an object of class
        // Illuminate\Support\Carbon". Comparing two Carbon::parse() INSTANTS
        // instead (Carbon::parse() accepts a DateTimeInterface directly, so
        // $fixture->{$firstField} needs no pre-conversion) sidesteps the
        // type mismatch while still failing hard if the response holds the
        // wrong day/instant. Deliberately broader than isDateTimeField()
        // (datetime/timestamp only) — that check exists to distinguish a
        // date-only STRING literal from a datetime STRING literal in
        // buildFieldValueLiteral()/buildResponseAssertLine(), a distinction
        // that doesn't matter here since a plain 'date' column's fixture
        // attribute is a Carbon object too.
        $isDateLikeField = $firstField !== null
            && in_array($this->findColumnConfig($firstField)['type'] ?? '', ['date', 'datetime', 'timestamp'], true);

        // A decimal/float/double column's model attribute goes through
        // ModelGenerator::getCastType()'s 'float' cast (see
        // buildDecimalPrecisionTestMethod()'s docblock), so a whole-number
        // fixture value is PHP float(1.0) while the same value round-tripped
        // through json_encode()/json_decode() on the HTTP response collapses
        // to int(1) — assertJsonPath()'s strict === then fails comparing
        // int(1) !== float(1.0). assertEqualsWithDelta() sidesteps that the
        // same way buildDecimalPrecisionTestMethod() already does for its
        // own decimal assertion, so it needs its own statement rather than
        // a chained ->assertJsonPath() call.
        $isDecimalLikeField = $firstField !== null
            && in_array($this->findColumnConfig($firstField)['type'] ?? '', ['decimal', 'float', 'double'], true);

        $extraAssert = match (true) {
            $firstField === null, $isDecimalLikeField => '',
            $isDateLikeField => "\n            ->assertJsonPath('data.{$firstField}', fn (\$value) => \\Carbon\\Carbon::parse(\$value)->equalTo(\\Carbon\\Carbon::parse(\$fixture->{$firstField})))",
            default => "\n            ->assertJsonPath('data.{$firstField}', \$fixture->{$firstField})",
        };

        $decimalAssertLine = $isDecimalLikeField
            ? "\n\n        \$this->assertEqualsWithDelta(\$fixture->{$firstField}, \$response->json('data.{$firstField}'), 0.0001);"
            : '';

        return <<<PHP
    public function test_can_view_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/view");

        \$response->assertStatus(200)
            ->assertJsonPath('data.uuid', \$fixture->uuid){$extraAssert};{$decimalAssertLine}
    }
PHP;
    }

    protected function buildEditTestMethod(array $fields, string $snakeSingular, string $routeBase, string $tableName): string
    {
        $editFields = $this->config['features']['backend']['edit']['fields'] ?? $fields;
        $payloadLines = $this->buildPayloadLines($editFields, 'Updated', '            ', true, $this->isMultipartModule);

        $assertLines = [];
        $dbAssertLines = [];
        $dateTimeDbAssertLines = [];
        $fileAssertLines = [];
        foreach ($editFields as $fieldDef) {
            $field = $fieldDef['field'] ?? null;
            if (!$field) {
                continue;
            }

            if ($this->isFileColumn($field)) {
                // Same reasoning as buildCreateTestMethod(): the response
                // value, and the persisted DB value, are both derived from
                // the newly created media row's id, never the raw
                // $payload['{$field}'] UploadedFile instance — so neither the
                // exact-match response assertion nor the exact-match DB
                // assertion below can hold for this column. See that
                // method's docblock for why assertNotNull() against a real
                // MediaModel row (rather than assertIsInt() on the raw
                // response value) is the assertion that survives a
                // varchar-typed file column too.
                $fileAssertLines[] = "        \$this->assertNotNull(\\App\\Project\\Modules\\Core\\Media\\MediaModel::find((int) \$response->json('data.{$field}')));";
                continue;
            }

            $assertLines[] = $this->buildResponseAssertLine($field, $fieldDef['rules'] ?? '');

            // Same assertDatabaseHas()-can't-safely-check-a-json-column
            // problem as buildCreateTestMethod()'s single-field fallback —
            // see isArrayField()'s docblock, in particular why a
            // json_encode()'d value is not a safe substitute either (MySQL's
            // native JSON type doesn't compare `=` against a bound string
            // parameter, confirmed live). This multi-field block is in fact
            // the MORE likely of the two call sites to actually carry a json
            // column, since it asserts every configured edit field at once
            // rather than just the one firstUniqueField() happens to pick —
            // simply omit this field's key from the where-clause entirely;
            // every other configured field still gets its own exact-match
            // check right alongside it.
            if ($this->isArrayField($fieldDef['rules'] ?? '')) {
                continue;
            }

            // A field with a processing_service/processors[] mutation has
            // the same problem as buildResponseAssertLine() already
            // documents — the stored value is expected to differ from the
            // raw edit payload, so a raw `column = ?` where-clause here
            // would assert the transformation *doesn't* happen. Confirmed
            // live against an after_save processor recalculating
            // total_amount from inline_items child rows on Expenses' own
            // edit test. Same omit-and-let-the-response-assertion-cover-it
            // strategy as the array-field case just above.
            if ($this->hasProcessingService($field)) {
                continue;
            }

            // A datetime/timestamp field has the identical problem —
            // assertDatabaseHas() builds a raw `column = ?` where-clause
            // comparing the stored string against $payload['{$field}']'s own
            // string, with no Carbon parsing involved (unlike
            // assertJsonPath(), which buildResponseAssertLine() already
            // routes through a Carbon-instant-comparing closure for exactly
            // this field type) — see firstDbAssertableField()'s docblock for
            // the full mechanics. Rather than drop coverage for this field
            // entirely (the way the json/array case above does, relying
            // solely on its own response-body assertion), give it its own
            // dedicated Carbon-instant assertTrue() check here: this
            // multi-field composite assertDatabaseHas() is the one place a
            // datetime column could otherwise lose ALL of its DB-level
            // verification, since firstDbAssertableField() (used by the
            // single-field create-test fallback) deliberately never picks a
            // datetime field as the sole assertable column in the first
            // place.
            if ($this->isDateTimeField($field)) {
                $dateTimeDbAssertLines[] = "        \$this->assertTrue(\\Carbon\\Carbon::parse({$this->moduleName}Model::where('uuid', \$fixture->uuid)->value('{$field}'))->equalTo(\\Carbon\\Carbon::parse(\$payload['{$field}'])));";
                continue;
            }

            $dbAssertLines[] = "            '{$field}' => \$payload['{$field}'],";
        }

        // Audit-column coverage (gap 4) — see buildCreateTestMethod()'s
        // identical guard for the created_by_id half of this pair.
        if ($this->hasCreatorUpdater) {
            $assertLines[] = "            ->assertJsonPath('data.updated_by_id', (int) UsersModel::DEVELOPER)";
        }

        $assertBlock = implode("\n", $assertLines);
        $dbAssertBlock = implode("\n", $dbAssertLines);
        $dateTimeDbAssertBlock = implode("\n", $dateTimeDbAssertLines);

        $dbAssertStatement = <<<PHP
        \$this->assertDatabaseHas('{$tableName}', [
            'uuid' => \$fixture->uuid,
{$dbAssertBlock}
        ]);
PHP;
        $postAssertions = implode("\n\n", array_filter([
            $fileAssertLines ? implode("\n", $fileAssertLines) : null,
            $dbAssertStatement,
            $dateTimeDbAssertBlock !== '' ? $dateTimeDbAssertBlock : null,
        ]));

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
            ? "\$this->post(\"/api/{$routeBase}/{\$fixture->uuid}/edit\", \$payload + ['_method' => 'PUT'], ['Accept' => 'application/json'])"
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
        // assertSoftDeleted() asserts a `deleted_at IS NOT NULL` row still
        // exists -- meaningless (and a fatal "Unknown column" QueryException)
        // for a module with no such column at all. Model::delete() performs
        // a genuine hard delete when the model has no SoftDeletes trait (see
        // ModelGenerator::hasCreatorUpdater()'s sibling hasSoftDeletes()
        // gate), so the row-is-gone assertion for that case is
        // assertDatabaseMissing() instead.
        $deletedAssertion = $this->hasSoftDeletes
            ? "\$this->assertSoftDeleted('{$tableName}', ['id' => \$fixture->id]);"
            : "\$this->assertDatabaseMissing('{$tableName}', ['id' => \$fixture->id]);";

        return <<<PHP
    public function test_can_delete_{$snakeSingular}(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->deleteJson("/api/{$routeBase}/{\$fixture->uuid}/delete");

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);

        {$deletedAssertion}
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
            ? "\$this->post('/api/{$routeBase}/create', \$payload, ['Accept' => 'application/json'])"
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
    //
    // actingAsUserWithoutPermission() used to be emitted here, into every
    // module's own file, by buildActingAsUserWithoutPermissionHelper(). It
    // carries no module-specific state, so post-split it lives once as
    // Tests\Support\ActsWithoutPermission (SYSTEM_SHELL/BACKEND/tests/Support/),
    // used by every {Module}TestCase — see writeTestCaseBase().

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
     * Whether $fieldName has a field-level `processing_service` configured
     * on either its create or edit fields[] entry (they're independently
     * configurable, but either one running is enough to make a strict
     * round-trip assertion unsafe — see buildResponseAssertLine()). This
     * key lives under features.backend.{create,edit}.fields[], keyed by
     * `field`, not in $config['columns'] — findColumnConfig() doesn't see
     * it, hence a separate lookup rather than extending that one.
     *
     * Also true when $fieldName is named in any top-level `processors[]`
     * entry's own `fields[]` array — the module-wide equivalent of the same
     * problem, confirmed live against RecalculateExpenseTotalProcessor
     * (an after_save processor recomputing `total_amount` from inline_items
     * child rows — a genuinely different value than whatever the create
     * payload submitted, same as processing_service). Deliberately not
     * scoped to a specific stage/operation here — a field named in *any*
     * processor's fields[] is unsafe to assert strict equality on in
     * general, and threading create-vs-edit context through this method's
     * two call sites isn't worth it for what's already a conservative,
     * weakened (not wrong) fallback assertion either way.
     */
    protected function hasProcessingService(string $fieldName): bool
    {
        foreach (['create', 'edit'] as $op) {
            foreach ($this->config['features']['backend'][$op]['fields'] ?? [] as $fieldDef) {
                if (($fieldDef['field'] ?? null) === $fieldName && !empty($fieldDef['processing_service'])) {
                    return true;
                }
            }
        }

        foreach ($this->config['processors'] ?? [] as $processor) {
            if (in_array($fieldName, $processor['fields'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $field is a genuine datetime/timestamp column — i.e. its
     * findColumnConfig() normalized_type (IntrospectionToConfig::
     * buildColumn()'s 'type' key) is 'datetime' or 'timestamp', NOT merely
     * whether its validation rules string contains the substring 'date'.
     * BaseServiceGenerator::generateValidationRules() emits the identical
     * bare 'date' rule for a true `date` column and for a `datetime`/
     * `timestamp` column alike, so the rules string can never distinguish
     * them — only the column's own normalized type can. See
     * buildFieldValueLiteral()'s date branch and buildResponseAssertLine()
     * for the two places this distinction actually matters.
     */
    protected function isDateTimeField(string $field): bool
    {
        $type = $this->findColumnConfig($field)['type'] ?? '';

        return in_array($type, ['datetime', 'timestamp'], true);
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
            ? "\$this->post('/api/{$routeBase}/create', \$payload, ['Accept' => 'application/json'])"
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

            // var_export(), not a raw '{$validValue}' interpolation — same
            // escaping precedent as buildFieldValueLiteral()'s own enum
            // branch (see that method's docblock) and
            // FactoryGenerator::buildValueExpression()'s 'enum' case: an
            // enum value containing a `'` would otherwise corrupt the
            // generated single-quoted literal (confirmed live: an
            // unescaped `'` produced a PHP parse error at this exact line).
            $validValueLiteral = var_export((string) $enumValues[0], true);
            $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, $this->isMultipartModule);
            $createCall = $this->buildCreateHttpCall($routeBase);

            $acceptTest = <<<PHP
    public function test_create_{$snakeSingular}_accepts_a_valid_{$fieldName}_enum_value(): void
    {
        \$payload = [
{$payloadLines}
        ];
        \$payload['{$fieldName}'] = {$validValueLiteral};

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
     * RoutesGenerator::generate() registers `GET /{routeBase}/list/export`
     * whenever `features.backend.list.export` is truthy. It dispatches to
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
        \$response = \$this->getJson('/api/{$routeBase}/list/export');

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
     * RoutesGenerator::generate() registers `GET /{routeBase}/import/template`
     * under the same `features.backend.list.import` flag (alongside `POST
     * /{routeBase}/import` — see buildImportFileUploadTestMethod() for that
     * half's coverage). The template route dispatches to
     * {Module}ListService::getImportTemplate() -> ListServiceTrait::
     * downloadImportTemplate(), which always returns a 200 streamed CSV
     * (headers-only, from either the module's own $importColumns or its
     * $filterableFields — both statically known at generation time to exist
     * as arrays, even if empty) with no fixture, no request body, and no
     * uploaded file required.
     */
    protected function buildImportTemplateTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_download_{$this->modulePluralSnake()}_import_template(): void
    {
        \$response = \$this->getJson('/api/{$routeBase}/import/template');

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
        return $this->allGenericBulkActionKeys()[0] ?? null;
    }

    /**
     * Every `bulk_actions[]` key eligible for its own
     * `{Module}{Key}ServiceTest.php` file — same "no status_target" filter
     * as firstGenericBulkActionKey()'s docblock explains, just not stopping
     * at the first match. Mirrors BulkActionServiceGenerator's own per-key
     * fan-out (`generateActionService()`, called once per `bulk_actions[]`
     * entry) so every key that gets its own `{Module}{Key}Service.php` also
     * gets its own test file.
     *
     * @return string[]
     */
    protected function allGenericBulkActionKeys(): array
    {
        $listConfig = $this->config['features']['backend']['list'] ?? [];
        $bulkActions = is_array($listConfig) ? ($listConfig['bulk_actions'] ?? []) : [];

        $keys = [];
        foreach ($bulkActions as $action) {
            $key = $action['key'] ?? '';
            if ($key === '' || !empty($action['status_target'])) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
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

    // ─── Blocking-relationship DeleteCheck coverage (family 1) ─────────────

    /**
     * Find the first dependent table (per PathManager's FK graph) that
     * resolves to a known module — mirroring
     * DeleteCheckServiceGenerator::generateDependentCountChecks()'s own
     * resolution exactly (same graph lookup, same
     * PathManager::findModuleByTable() call), so this test is emitted
     * precisely when that generator emits a REAL count check rather than a
     * commented-out "could not resolve module" hint.
     *
     * Returns the child model's fully-qualified class name and the FK
     * column that points back at this module's table, or null when no
     * dependent resolves — in which case a "blocking relationship" test
     * cannot be built at all (there is no child module to create a row in).
     */
    protected function firstResolvableDependentModule(): ?array
    {
        $tableName = $this->getTableName();
        if ($tableName === '') {
            return null;
        }

        $graph = PathManager::getForeignKeyGraph();
        $dependents = $graph[$tableName] ?? [];

        foreach ($dependents as $dep) {
            $sourceTable = $dep['source_table'] ?? '';
            $sourceColumn = $dep['source_column'] ?? '';
            if ($sourceTable === '' || $sourceColumn === '') {
                continue;
            }

            $moduleEntry = PathManager::findModuleByTable($sourceTable);
            if ($moduleEntry === null) {
                continue;
            }

            $childModuleName = $moduleEntry['name'] ?? Str::studly($sourceTable);
            $childNamespace = PathManager::resolveBackendModuleNamespace($childModuleName);

            return [
                'column' => $sourceColumn,
                'model_fqcn' => "{$childNamespace}\\{$childModuleName}Model",
            ];
        }

        return null;
    }

    /**
     * The dependent row is built via the child module's own generated
     * `{Module}Factory` (every generated module carries one — see
     * FactoryGenerator), overriding only the FK column that points back at
     * this fixture. The factory fills in every OTHER required column with
     * valid fake data on its own, so this test needs no knowledge of the
     * child module's full field shape — only the one FK column
     * firstResolvableDependentModule() already resolved. Modules are
     * generated in FK-dependency order, so the child module's factory class
     * is guaranteed to exist on disk by the time this test is generated
     * (same guarantee resolveCrossModuleFkLiteral() relies on).
     */
    protected function buildDeleteCheckBlockingTestMethod(array $dependent, string $routeBase): string
    {
        $modelFqcn = $dependent['model_fqcn'];
        $column = $dependent['column'];

        return <<<PHP
    public function test_delete_check_reports_blocking_relationship_when_a_dependent_record_exists(): void
    {
        \$fixture = \$this->create{$this->moduleSingular}Fixture();
        \\{$modelFqcn}::factory()->create(['{$column}' => \$fixture->id]);

        \$response = \$this->getJson("/api/{$routeBase}/{\$fixture->uuid}/delete/check");

        \$response->assertStatus(200)
            ->assertJsonPath('data.can_delete', false);
    }
PHP;
    }

    // ─── Filter-mode bulk-action coverage (family 6) ───────────────────────

    /**
     * Filter-mode counterpart of buildBulkActionIdsModeTestMethod(). An
     * EMPTY `filters` array under mode=filter matches every row
     * (ListServiceTrait::processBulkAction()'s filter branch only calls
     * applyFilters() `if (!empty($filters))`, otherwise leaving the base
     * query — and therefore the resulting `$ids` list — unrestricted), so
     * this needs no knowledge of the module's own filterable fields to stay
     * universally safe: it always matches the two fixtures created below,
     * regardless of their column values. Gated on the exact same
     * firstGenericBulkActionKey() condition as the ids-mode happy path (see
     * that method's docblock) — the same "no status_target" shape is
     * required here for the same reason.
     */
    protected function buildBulkActionFilterModeTestMethod(string $bulkActionKey, string $routeBase): string
    {
        return <<<PHP
    public function test_bulk_action_processes_all_records_in_filter_mode_with_an_empty_filter(): void
    {
        \$this->create{$this->moduleSingular}Fixture();
        \$this->create{$this->moduleSingular}Fixture();

        \$response = \$this->postJson('/api/{$routeBase}/bulk-action', [
            'action' => '{$bulkActionKey}',
            'mode' => 'filter',
            'filter' => ['filters' => []],
        ]);

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    // ─── Import file-upload coverage (family 4) ────────────────────────────

    /**
     * ListServiceTrait::importData() parses the uploaded file's OWN header
     * row at runtime (`fgetcsv()` reads whatever headers the file actually
     * has) and maps each data row onto them positionally — it never
     * cross-checks against the module's `$importColumns` (which
     * ListServiceGenerator always emits as an empty placeholder array for
     * the developer to fill in later; see generateImportMethods()) before
     * calling `processImportRow()`. And `processImportRow()` ITSELF is an
     * explicit "TODO: implement {$name} import logic" no-op placeholder
     * (same generator) that never throws — so importData()'s per-row
     * try/catch always lands in the success branch regardless of what the
     * row actually contains. That makes an arbitrary single-column CSV a
     * deterministic, universally-safe fixture: it needs no knowledge of the
     * module's real columns at all, and is guaranteed to report a
     * `succeeded_count: 1, failed_count: 0` BatchOutcome today. (The moment
     * a developer replaces the placeholder with real per-row logic, this
     * generated test — like every other test in this file coupled to
     * today's generated body — becomes theirs to update.)
     *
     * Asserts on `succeeded_count`/`failed_count`, not `imported`/`failed`:
     * ListServiceTrait::importData() returns the shared BatchOutcome shape
     * (App\Project\_Src\Support\BatchOutcome — see its own docblock),
     * which is what the frontend's BatchResultDrawer actually consumes.
     * A `failed`/`succeeded` key does exist on that shape too, but it's an
     * ARRAY of per-row failure/success records, not a count — a prior
     * version of this method asserted `data.failed === 0`, which happened
     * to pass only because of an unrelated, now-fixed ListServiceTrait bug
     * that let a legacy scalar `failed` count silently collide with (and,
     * for a non-empty batch, get overwritten by) BatchOutcome's own array.
     */
    protected function buildImportFileUploadTestMethod(string $routeBase): string
    {
        return <<<PHP
    public function test_can_import_{$this->modulePluralSnake()}_via_uploaded_csv(): void
    {
        \$csv = "id\\n1\\n";
        \$file = \\Illuminate\\Http\\UploadedFile::fake()->createWithContent('import.csv', \$csv);

        \$response = \$this->post('/api/{$routeBase}/import', ['file' => \$file], ['Accept' => 'application/json']);

        \$response->assertStatus(200)
            ->assertJsonPath('data.succeeded_count', 1)
            ->assertJsonPath('data.failed_count', 0);
    }
PHP;
    }

    // ─── Custom actions[] contract coverage (family 5) ─────────────────────

    /**
     * First enabled operation for a SPECIFIC action, in the same
     * ['list','create','edit','view','delete'] order
     * RoutesGenerator::generateActionRoutes() iterates in. Returns null when
     * this action has nothing enabled.
     */
    protected function resolveActionOperation(array $action): ?array
    {
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (!empty($action['operations'][$op]['enabled'] ?? null)) {
                return ['op' => $op, 'opConfig' => $action['operations'][$op]];
            }
        }

        return null;
    }

    /**
     * The service-file name suffix this action's coverage lands under —
     * MUST match ActionServiceGenerator::generate()'s own $serviceNameRaw
     * computation exactly (Str::studly(name), optional `serviceName`
     * override, module-prefix/`Service`-suffix stripping), so
     * `{Module}{suffix}ServiceTest.php` sits beside the
     * `{Module}{suffix}Service.php` it covers even when a custom
     * `serviceName` is declared.
     */
    protected function resolveActionServiceNameSuffix(string $actionKey, array $action): string
    {
        $actionName = Str::studly($action['name'] ?? $actionKey);
        $serviceNameRaw = !empty($action['serviceName']) ? $action['serviceName'] : $actionName;

        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }

        return $serviceNameRaw;
    }

    /**
     * Custom actions[] emit an explicit "Add your custom logic here" TODO
     * service body (Features/action/service.stub's process() method) that
     * unconditionally returns `Helpers::success([], 'Operation completed
     * successfully')` — status 200 — regardless of $data or urlParams,
     * until a developer replaces it. A behavioral test asserting real
     * business logic is therefore impossible, but two CONTRACT properties
     * are guaranteed by the framework/routing layer itself, independent of
     * that placeholder body, and stay true even after a developer
     * implements it (as long as they don't turn a valid request into a hard
     * 5xx):
     *   - the route is registered and requires its permission (403 without one);
     *   - a request that reaches the placeholder never hard-fails (non-5xx).
     *
     * Handles the DEFAULT route shape (no urlParams, no custom endpoint.path)
     * and the single most common real-world shape for a single-row action —
     * `urlParams: ['uuid']`, with or without a custom `endpoint.path` that
     * embeds `{uuid}` — by mirroring
     * RoutesGenerator::generateActionRoutes()'s own path-building for that
     * one param. Every other urlParams shape (multiple params, or a param
     * other than `uuid`) still returns [] rather than guess, since resolving
     * those exactly would mean duplicating the rest of that method's logic
     * for a value this generator has no way to independently verify is
     * correct. Also returns [] when this action has nothing enabled.
     *
     * Called once per `actions[]` entry (see generate()) — every action now
     * gets its own file and its own contract coverage, not just the first
     * enabled one the single-file design used to restrict this to.
     */
    protected function buildActionServiceTestMethodsForKey(string $actionKey, array $action): array
    {
        $resolved = $this->resolveActionOperation($action);
        if ($resolved === null) {
            return [];
        }

        $op = $resolved['op'];
        $opConfig = $resolved['opConfig'];
        $urlParams = $action['urlParams'] ?? [];

        if (!empty($urlParams) && $urlParams !== ['uuid']) {
            return [];
        }

        $actionName = $action['name'] ?? $actionKey;
        $actionRoute = Str::kebab($actionName);
        $moduleRoute = Str::kebab($this->moduleName);
        $method = strtolower($opConfig['endpoint']['method'] ?? (in_array($op, ['list', 'view'], true) ? 'get' : 'post'));
        $endpoint = $opConfig['endpoint'] ?? [];

        if (!empty($endpoint['path'])) {
            $path = $endpoint['path'];
            if (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
        } else {
            $urlParamsPath = !empty($urlParams) ? '/{uuid}' : '';
            $path = "/{$moduleRoute}/{$actionRoute}{$urlParamsPath}/{$op}";
        }
        $apiPath = "/api{$path}";
        $snake = Str::snake(Str::studly($actionName));

        $needsFixture = str_contains($apiPath, '{uuid}');
        if ($needsFixture) {
            [$before, $after] = explode('{uuid}', $apiPath, 2);
            $pathExpr = "'{$before}' . \$fixture->uuid . '{$after}'";
        } else {
            $pathExpr = "'{$apiPath}'";
        }
        $fixtureLine = $needsFixture ? "        \$fixture = \$this->create{$this->moduleSingular}Fixture();\n\n" : '';

        $call = $this->buildJsonHttpCall($method, $pathExpr);

        $contractTest = <<<PHP
    public function test_can_invoke_the_{$snake}_action_with_permission(): void
    {
{$fixtureLine}        \$response = {$call};

        \$this->assertLessThan(500, \$response->getStatusCode());
    }
PHP;

        $forbiddenTest = <<<PHP
    public function test_cannot_invoke_the_{$snake}_action_without_permission(): void
    {
        \$this->actingAsUserWithoutPermission();

{$fixtureLine}        \$response = {$call};

        \$response->assertStatus(403);
    }
PHP;

        return [$contractTest, $forbiddenTest];
    }

    /** Shared HTTP-verb-to-test-call mapping, used by both the actions[] and delegations[] test builders. */
    protected function buildJsonHttpCall(string $method, string $path, string $payloadExpr = '[]'): string
    {
        return match ($method) {
            'post'  => "\$this->postJson({$path}, {$payloadExpr})",
            'put'   => "\$this->putJson({$path}, {$payloadExpr})",
            'patch' => "\$this->patchJson({$path}, {$payloadExpr})",
            'delete' => "\$this->deleteJson({$path})",
            default => "\$this->getJson({$path})",
        };
    }

    // ─── Delegation route coverage (family 2) ──────────────────────────────

    /** Resolve every declared delegations[] entry into its (per-operation-gated) test methods. */
    protected function buildDelegationTestMethods(): array
    {
        $methods = [];
        foreach ($this->config['delegations'] ?? [] as $delegationKey => $delegation) {
            if (!is_array($delegation)) {
                continue;
            }
            $methods = array_merge($methods, $this->buildDelegationTestMethodsFor((string) $delegationKey, $delegation));
        }

        return $methods;
    }

    /**
     * Per-delegation test methods, gated per-operation exactly like
     * RoutesGenerator::generateDelegationRoutes() itself:
     *   - `list` needs no related-module knowledge at all — the generated
     *     list() method only validates pagination/filter params (all
     *     nullable) and scopes the related model's own query by the parent's
     *     id, so it's emitted whenever `operations.list.enabled` is true.
     *   - `view`/`delete` need a REAL related-module row to operate on, built
     *     via that module's own `{Module}Factory` (see
     *     buildDeleteCheckBlockingTestMethod()'s identical reasoning) — so
     *     these are additionally gated on the related module actually
     *     resolving to a model FQCN.
     *   - `create`/`edit` additionally need the delegation's own declared
     *     `operations.{op}.backend.fields` (the same shape
     *     DelegationServiceGenerator::buildValidationRules() itself reads) to
     *     build a valid payload — skipped when that's empty, since there is
     *     no field shape to build one from.
     */
    protected function buildDelegationTestMethodsFor(string $delegationKey, array $delegation): array
    {
        $delegationName = $delegation['name'] ?? $delegationKey;
        $delegationRoute = Str::kebab($delegationName);
        // Bug (found 2026-08-16, same root cause as
        // DelegationConfigNormalizer::normalize()'s identical fix — this
        // class reads the raw config independently rather than through
        // that normalizer): $delegation['parentContext'] is where V1's
        // GeneratorDelegationDefaultsService.php actually nests
        // parentKey/filterKey/parentIdField in its real, current stored
        // shape. Reading them as flat top-level keys here made every
        // generated delegation test fixture wrongly build its related row
        // with a hardcoded `'parent_id' => $parent->id` column that most
        // real delegated tables don't have -- confirmed live: a real
        // QueryException, "Column not found: parent_id", not a false
        // assertion.
        $parentContext = $delegation['parentContext'] ?? [];
        $parentKey = $parentContext['parentKey'] ?? $delegation['parentKey'] ?? 'uuid';
        $moduleRoute = Str::kebab($this->moduleName);
        $operations = $delegation['operations'] ?? [];
        $snake = Str::snake(Str::studly($delegationName));

        $methods = [];

        if (!empty($operations['list']['enabled'])) {
            $methods[] = $this->buildDelegationListTestMethod($snake, $delegationRoute, $parentKey, $moduleRoute, $operations['list'] ?? []);
        }

        $relatedModule = $delegation['relatedModule'] ?? null;
        $relatedModuleName = is_array($relatedModule) ? ($relatedModule['name'] ?? '') : (is_string($relatedModule) ? $relatedModule : '');
        $relatedModuleGroupHint = is_array($relatedModule) ? ($relatedModule['group'] ?? null) : null;
        $relatedModelFqcn = $this->resolveDelegationRelatedModelFqcn($relatedModuleName, $relatedModuleGroupHint);

        if ($relatedModelFqcn === null) {
            return $methods;
        }

        $filterKey = $parentContext['filterKey'] ?? $delegation['filterKey'] ?? 'parent_id';
        $parentIdField = $parentContext['parentIdField'] ?? $delegation['parentIdField'] ?? 'id';
        $morphFilter = $delegation['morphFilter'] ?? null;

        if (!empty($operations['view']['enabled'])) {
            $methods[] = $this->buildDelegationViewTestMethod($snake, $delegationRoute, $parentKey, $moduleRoute, $relatedModelFqcn, $filterKey, $parentIdField, $operations['view'] ?? [], $morphFilter);
        }

        if (!empty($operations['delete']['enabled'])) {
            $methods[] = $this->buildDelegationDeleteTestMethod($snake, $delegationRoute, $parentKey, $moduleRoute, $relatedModelFqcn, $filterKey, $parentIdField, $operations['delete'] ?? [], $morphFilter);
        }

        if (!empty($operations['create']['enabled'])) {
            $fields = $operations['create']['backend']['fields'] ?? [];
            if (!empty($fields)) {
                $methods[] = $this->buildDelegationCreateTestMethod($snake, $delegationRoute, $parentKey, $moduleRoute, $fields, $operations['create']);
            }
        }

        if (!empty($operations['edit']['enabled'])) {
            $fields = $operations['edit']['backend']['fields'] ?? [];
            if (!empty($fields)) {
                $methods[] = $this->buildDelegationEditTestMethod($snake, $delegationRoute, $parentKey, $moduleRoute, $relatedModelFqcn, $filterKey, $parentIdField, $fields, $operations['edit'], $morphFilter);
            }
        }

        return $methods;
    }

    /**
     * The PHP array-literal snippet a delegation test's `{Related}::factory()
     * ->create([...])` call scopes its fixture row with — normally just the
     * single filterKey pair. A morph-filtered delegation (generator-engine
     * v3.3.0's `morphFilter`, e.g. Vendors' reverse Payments tab) ALSO needs
     * the morph type column set to the matching alias on the fixture row,
     * or the generated test's own view/edit/delete call 404s against the
     * real runtime query's second where clause (DelegationServiceGenerator::
     * buildMorphFilterClause()) — the fixture row exists, just not scoped to
     * the type the query actually filters on.
     */
    protected function buildDelegationFixtureFields(string $filterKey, string $parentIdField, ?array $morphFilter): string
    {
        $fields = "'{$filterKey}' => \$parent->{$parentIdField}";
        if (!empty($morphFilter['column']) && isset($morphFilter['value'])) {
            $fields .= ", '{$morphFilter['column']}' => '{$morphFilter['value']}'";
        }
        return $fields;
    }

    /**
     * Resolve a delegation's related module to a fully-qualified model class
     * name, mirroring DelegationServiceGenerator::resolveRelatedModuleGroupPath()'s
     * resolution order (registry lookup, then the caller-declared group
     * hint) closely enough for TEST purposes — returns null (never a guess)
     * when neither resolves, so the caller can skip any test that would
     * need a real related-module row.
     */
    protected function resolveDelegationRelatedModelFqcn(string $relatedModuleName, ?string $relatedModuleGroupHint): ?string
    {
        if ($relatedModuleName === '') {
            return null;
        }

        if ($relatedModuleName === $this->moduleName) {
            return $this->getNamespace() . '\\' . $relatedModuleName . 'Model';
        }

        $ns = PathManager::resolveBackendModuleNamespaceOrNull($relatedModuleName);
        if ($ns !== null) {
            return $ns . '\\' . $relatedModuleName . 'Model';
        }

        if (!empty($relatedModuleGroupHint)) {
            return "App\\Project\\Modules\\{$this->moduleGroup}\\" . Str::studly($relatedModuleGroupHint) . "\\{$relatedModuleName}\\{$relatedModuleName}Model";
        }

        return null;
    }

    /**
     * Bug (found + fixed 2026-08-06, via a live Warehouses/StockMovements
     * delegation verification pass): this used to fall back to
     * `in_array($op, ['list', 'view'], true) ? 'get' : 'post'` — collapsing
     * edit and delete onto the SAME generic 'post' default. The real
     * authoritative default lives in DelegationConfigNormalizer::
     * getOperationDefaults(), the one RoutesGenerator itself reads to
     * register the actual route: 'list'/'view' => GET, 'edit' => PUT,
     * 'delete' => DELETE, default (create) => POST. A generated delete test
     * calling postJson() against a route registered as ->delete(...) always
     * 405'd — every generated delegation-delete test has been broken since
     * this method was introduced; nothing caught it because no real
     * SYSTEM_SHELL module has ever had a non-empty delegations config until
     * this session's own live-verification fixtures.
     */
    protected function delegationHttpMethod(array $opConfig, string $op): string
    {
        $default = match ($op) {
            'list', 'view' => 'get',
            'edit' => 'put',
            'delete' => 'delete',
            default => 'post', // create
        };

        return strtolower($opConfig['endpoint']['method'] ?? $default);
    }

    protected function buildDelegationListTestMethod(string $snake, string $delegationRoute, string $parentKey, string $moduleRoute, array $opConfig): string
    {
        $method = $this->delegationHttpMethod($opConfig, 'list');
        $path = "\"/api/{$moduleRoute}/{\$parent->{$parentKey}}/{$delegationRoute}/list\"";
        $call = $this->buildJsonHttpCall($method, $path);

        return <<<PHP
    public function test_can_list_{$snake}_delegation(): void
    {
        \$parent = \$this->create{$this->moduleSingular}Fixture();

        \$response = {$call};

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    protected function buildDelegationViewTestMethod(string $snake, string $delegationRoute, string $parentKey, string $moduleRoute, string $relatedModelFqcn, string $filterKey, string $parentIdField, array $opConfig, ?array $morphFilter = null): string
    {
        $method = $this->delegationHttpMethod($opConfig, 'view');
        $path = "\"/api/{$moduleRoute}/{\$parent->{$parentKey}}/{$delegationRoute}/{\$related->uuid}/view\"";
        $call = $this->buildJsonHttpCall($method, $path);
        $fixtureFields = $this->buildDelegationFixtureFields($filterKey, $parentIdField, $morphFilter);

        return <<<PHP
    public function test_can_view_{$snake}_delegation_item(): void
    {
        \$parent = \$this->create{$this->moduleSingular}Fixture();
        \$related = \\{$relatedModelFqcn}::factory()->create([{$fixtureFields}]);

        \$response = {$call};

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    protected function buildDelegationDeleteTestMethod(string $snake, string $delegationRoute, string $parentKey, string $moduleRoute, string $relatedModelFqcn, string $filterKey, string $parentIdField, array $opConfig, ?array $morphFilter = null): string
    {
        $method = $this->delegationHttpMethod($opConfig, 'delete');
        $path = "\"/api/{$moduleRoute}/{\$parent->{$parentKey}}/{$delegationRoute}/{\$related->uuid}/delete\"";
        $call = $this->buildJsonHttpCall($method, $path);
        $fixtureFields = $this->buildDelegationFixtureFields($filterKey, $parentIdField, $morphFilter);

        return <<<PHP
    public function test_can_delete_{$snake}_delegation_item(): void
    {
        \$parent = \$this->create{$this->moduleSingular}Fixture();
        \$related = \\{$relatedModelFqcn}::factory()->create([{$fixtureFields}]);

        \$response = {$call};

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    protected function buildDelegationCreateTestMethod(string $snake, string $delegationRoute, string $parentKey, string $moduleRoute, array $fields, array $opConfig): string
    {
        $method = $this->delegationHttpMethod($opConfig, 'create');
        $payloadLines = $this->buildPayloadLines($fields, 'Test', '            ', true, false);
        $path = "\"/api/{$moduleRoute}/{\$parent->{$parentKey}}/{$delegationRoute}/create\"";
        $call = $this->buildJsonHttpCall($method, $path, '$payload');

        return <<<PHP
    public function test_can_create_{$snake}_delegation_item(): void
    {
        \$parent = \$this->create{$this->moduleSingular}Fixture();

        \$payload = [
{$payloadLines}
        ];

        \$response = {$call};

        \$response->assertStatus(201)
            ->assertJson(['status' => true]);
    }
PHP;
    }

    protected function buildDelegationEditTestMethod(string $snake, string $delegationRoute, string $parentKey, string $moduleRoute, string $relatedModelFqcn, string $filterKey, string $parentIdField, array $fields, array $opConfig, ?array $morphFilter = null): string
    {
        $method = $this->delegationHttpMethod($opConfig, 'edit');
        $payloadLines = $this->buildPayloadLines($fields, 'Updated', '            ', true, false);
        $path = "\"/api/{$moduleRoute}/{\$parent->{$parentKey}}/{$delegationRoute}/{\$related->uuid}/edit\"";
        $call = $this->buildJsonHttpCall($method, $path, '$payload');
        $fixtureFields = $this->buildDelegationFixtureFields($filterKey, $parentIdField, $morphFilter);

        return <<<PHP
    public function test_can_edit_{$snake}_delegation_item(): void
    {
        \$parent = \$this->create{$this->moduleSingular}Fixture();
        \$related = \\{$relatedModelFqcn}::factory()->create([{$fixtureFields}]);

        \$payload = [
{$payloadLines}
        ];

        \$response = {$call};

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
PHP;
    }
}
