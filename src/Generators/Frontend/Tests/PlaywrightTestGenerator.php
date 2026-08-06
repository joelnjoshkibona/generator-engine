<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

/**
 * Generates one Playwright e2e spec per testable surface of a module, all in
 * that module's own e2e/ directory, instead of one monolithic file:
 *
 *   - {module-route}-crud.e2e.js  — list -> create -> filter -> view ->
 *     related-record -> edit -> delete, gated by which features.frontend.*
 *     flags the module actually declares. Unchanged in scope from this
 *     generator's original single-file output; just renamed (see
 *     writeCrudFile()).
 *   - _fixtures.js                — shared createFixtureRecord()/
 *     cleanupRecord() pair, written only when at least one delegation/action
 *     spec below needs it (see writeFixturesFile()).
 *   - {module-route}-{delegation-key}.e2e.js — one per config['delegations']
 *     key (see writeDelegationSpecFile()).
 *   - {module-route}-{action-key}.e2e.js     — one per config['actions'] key
 *     with hasUI true (see writeActionSpecFile()).
 *
 * This mirrors the file-per-artifact convention DelegationServiceGenerator/
 * ActionServiceGenerator already use on the backend: MakeDelegation.php/
 * MakeAction.php can regenerate ONE new key's spec (regenerateOnly()) without
 * force-overwriting every other hand-edited spec in the directory, which a
 * single shared file made unavoidable.
 *
 * No bulk migration ever renames/splits an existing module's old-style
 * {module-route}.e2e.js — old- and new-style modules coexist indefinitely;
 * deleteStaleMonolithicFileIfPresent() only removes it once a --force run has
 * successfully written the new split files for that module.
 *
 * Structurally mirrors the hand-written reference pattern in
 * SYSTEM_SHELL/FRONTEND/e2e/location-types-crud.e2e.js (retry/settle
 * helpers, self-cleaning try/finally) while targeting the view-modal-first
 * DOM shape that the CURRENT frontend generator actually produces (see
 * Templates/frontend/features/list/page.stub, features/view/modal.stub,
 * features/delete/form.stub, and BaseComponentGenerator's submit-button
 * markup) rather than the older hand-written LocationTypes page, which
 * predates that pattern and has no view/submit testids of its own:
 *   - list row "view" button:            [[moduleName]]-view-{uuid}
 *   - view-modal "edit" button:          [[moduleName]]-edit-{uuid}
 *   - view-modal "More Actions" -> item: [[moduleName]]-delete-{uuid}
 *   - create/edit form submit button:    [[moduleName]]-submit
 *   - delete-form confirm button:        [[moduleName]]-confirm-delete
 *   - view-modal action trigger:         [[moduleName]]-action-{key}-{uuid}
 *     (ViewModalGenerator::renderMainButton()/renderMoreItem(), both
 *     placements — used by writeActionSpecFile())
 *   - bulk-select checkbox (per row):    [[moduleName]]-bulk-select-{uuid}
 *   - bulk-action toolbar button:        [[moduleName]]-bulk-action-{key}
 *   - bulk-action confirm dialog button: [[moduleName]]-bulk-confirm
 *   - export dropdown trigger/items:     [[moduleName]]-export-open / -csv / -xlsx / -pdf
 *   - import dialog trigger/controls:    [[moduleName]]-import-open / -file / -dry-run /
 *     -submit / -template-csv / -template-xlsx
 *     (all six computed from CrudListPanel's testIdPrefix inside
 *     ListTable.vue — see buildBulkActionBlock()/buildExportBlock()/
 *     buildImportBlock())
 *   - bulk-action/import result drawer:  batch-result-drawer (no module
 *     prefix — BatchResultDrawer.vue is shared across every module)
 */
class PlaywrightTestGenerator extends BaseGenerator
{
    /**
     * field_type values that render as a relation/option picker rather than
     * a plain fillable `<input>`/`<textarea>` — driven by fillSelectField()
     * (see selectFieldHelperBlock()), never by the default fillField().
     *
     * MUST include both 'select' AND 'api-select': IntrospectionToConfig::
     * buildFrontendFields() (the actual, only producer of features.frontend.
     * {create,edit}.fields in this codebase) emits 'api-select' for every
     * foreign-key/relation column — it never emits a bare 'select'. Before
     * this fix, every check in this class looked for 'select' only, so
     * every real FK field (item_type_id, item_category_id, item_id,
     * self-referential parent_id, ...) fell through to the plain-input
     * branch. Confirmed live: a generated Items e2e test tried
     * `fillField(page, '[role="dialog"] #item_type_id', ...)` against an
     * ApiSelect2Field control that has no element with that id at all
     * (ApiSelect2Field only applies `id` to its `<label for>`, never to a
     * root/input element — see ApiSelect2Field.vue) — every such test
     * timed out on the very first FK field it tried to fill, and
     * pickEditField()/isScalarField() could additionally pick an FK field
     * as the edit test's "one changed field", compounding the failure.
     * 'select' is kept alongside 'api-select' in case a future field_type
     * for a local (non-API) static dropdown is introduced under that exact
     * name — both shapes are already handled generically by the same
     * fillSelectField() helper.
     */
    protected const SELECT_FIELD_TYPES = ['select', 'api-select'];

    protected bool $hasCreate;
    protected bool $hasView;
    protected bool $hasEdit;
    protected bool $hasDelete;

    /** features.backend.list.{bulk_actions,export,import} — a separate config location from features.frontend, same as $filterFields above. */
    protected bool $hasBulkActions;
    protected bool $hasExport;
    protected bool $hasImport;
    /** @var array<int, array<string, mixed>> features.backend.list.bulk_actions */
    protected array $bulkActions;

    /** @var array<int, array<string, mixed>> features.frontend.create.fields */
    protected array $createFields;
    /** @var array<int, array<string, mixed>> features.frontend.edit.fields */
    protected array $editFields;

    /**
     * features.backend.list.filterFields — NOT features.frontend.list.
     * frontend.list only carries display `fields` + `primaryField`; the
     * filterFields the list's filter panel actually exposes live under
     * backend.list (confirmed against the real LocationTypes/Locations
     * module.json files, and independently against how
     * PhpUnitTestGenerator::firstFilterFieldKey() reads this same key for
     * the analogous backend test generator).
     *
     * Introspected modules routinely leave this array empty in module.json
     * (see e.g. ItemTypes) — the actual filter panel the running app renders
     * is NOT built from this raw config key, it's built from whatever the
     * generated ListService's filterFields() response carries, which falls
     * back to deriving plain-text filters from `filterableFields` when
     * `filterFields` is empty (see
     * BaseServiceGenerator::generateFilterFields()). Mirror that same
     * fallback here so pickTextFilterField() sees the filter controls that
     * genuinely exist in the UI instead of treating the module as
     * text-filter-less and falling through to the id-based Variant B.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $filterFields;

    protected ?string $primaryField;

    protected ?string $anchorField = null;
    protected bool $anchorFieldResolved = false;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        $frontend = $config['features']['frontend'] ?? [];
        $this->hasCreate = !empty($frontend['create']);
        $this->hasView   = !empty($frontend['view']);
        $this->hasEdit   = !empty($frontend['edit']);
        $this->hasDelete = !empty($frontend['delete']);

        $this->createFields = $frontend['create']['fields'] ?? [];
        $this->editFields    = $frontend['edit']['fields'] ?? [];
        $this->filterFields  = $this->resolveFilterFields($config);
        $this->primaryField  = $frontend['list']['primaryField'] ?? null;

        $backendList = $config['features']['backend']['list'] ?? [];
        $this->bulkActions    = is_array($backendList) ? ($backendList['bulk_actions'] ?? []) : [];
        $this->hasBulkActions = !empty($this->bulkActions);
        $this->hasExport      = is_array($backendList) && !empty($backendList['export']);
        $this->hasImport      = is_array($backendList) && !empty($backendList['import']);
    }

    /**
     * features.backend.list.filterFields, falling back to plain-text filters
     * derived from features.backend.list.filterableFields when the former is
     * empty — the same fallback BaseServiceGenerator::generateFilterFields()
     * applies when it renders the actual runtime filter panel. Without this,
     * introspected modules (empty filterFields, non-empty filterableFields)
     * look text-filter-less to pickTextFilterField() even though the
     * generated app genuinely renders text filter controls for them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveFilterFields(array $config): array
    {
        $filterFields = $config['features']['backend']['list']['filterFields'] ?? [];
        if (!empty($filterFields)) {
            return $filterFields;
        }

        $filterable = $config['features']['backend']['list']['filterableFields'] ?? [];
        if (is_string($filterable)) {
            $filterable = array_filter(array_map('trim', explode(',', $filterable)));
        }

        $derived = [];
        foreach ($filterable as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $derived[] = ['key' => $name, 'type' => 'text'];
        }

        return $derived;
    }

    public function generate(): bool
    {
        $allWritten = $this->writeCrudFile();

        if (!empty($this->config['delegations']) || $this->hasAnyUiAction()) {
            $allWritten = $this->writeFixturesFile() && $allWritten;

            foreach ($this->config['delegations'] ?? [] as $delegationKey => $delegation) {
                if (!is_array($delegation)) {
                    continue;
                }
                $allWritten = $this->writeDelegationSpecFile((string) $delegationKey, $delegation) && $allWritten;
            }

            foreach ($this->config['actions'] ?? [] as $actionKey => $action) {
                if (!is_array($action) || empty($action['hasUI'])) {
                    continue;
                }
                $allWritten = $this->writeActionSpecFile((string) $actionKey, $action) && $allWritten;
            }
        }

        $this->deleteStaleMonolithicFileIfPresent();

        return $allWritten;
    }

    /**
     * Scoped regeneration for MakeDelegation.php/MakeAction.php: write only
     * the ONE spec matching $key (via writeFileAlways(), bypassing the
     * skip-if-exists contract for that file only), leaving every other
     * delegation/action spec in the directory — including hand-edited ones —
     * completely untouched. $kind is 'delegation' or 'action'.
     */
    public function regenerateOnly(string $key, string $kind): bool
    {
        if (!is_file($this->fixturesFilePath())) {
            $this->writeFixturesFile();
        }

        if ($kind === 'delegation') {
            $delegation = $this->config['delegations'][$key] ?? null;
            if (!is_array($delegation)) {
                return false;
            }
            return $this->writeDelegationSpecFile($key, $delegation, force: true);
        }

        if ($kind === 'action') {
            $action = $this->config['actions'][$key] ?? null;
            if (!is_array($action) || empty($action['hasUI'])) {
                return false;
            }
            return $this->writeActionSpecFile($key, $action, force: true);
        }

        return false;
    }

    protected function e2eDir(): string
    {
        return PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . '/e2e';
    }

    protected function fixturesFilePath(): string
    {
        return $this->e2eDir() . '/_fixtures.js';
    }

    protected function hasAnyUiAction(): bool
    {
        foreach ($this->config['actions'] ?? [] as $action) {
            if (is_array($action) && !empty($action['hasUI'])) {
                return true;
            }
        }
        return false;
    }

    protected function writeCrudFile(): bool
    {
        $stub = $this->getTemplateContent('tests/crud.e2e', 'frontend');

        $content = str_replace(
            ['[[helperFunctions]]', '[[testDescription]]', '[[testBody]]'],
            [$this->buildHelperFunctions(), $this->buildTestDescription(), $this->buildTestBody()],
            $stub
        );

        $content = $this->replacePlaceholders($content);

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . '-crud.e2e.js';

        return $this->writeFile($filePath, $content);
    }

    protected function writeFixturesFile(): bool
    {
        $stub = $this->getTemplateContent('tests/fixtures.e2e', 'frontend');

        $content = str_replace(
            ['[[helperFunctions]]', '[[fixtureCreateBody]]', '[[fixtureCleanupBody]]'],
            [$this->buildFixtureHelperFunctions(), $this->buildFixtureCreateBody(), $this->buildFixtureCleanupBody()],
            $stub
        );

        $content = $this->replacePlaceholders($content);

        return $this->writeFile($this->fixturesFilePath(), $content);
    }

    /**
     * If $force is true, writes via writeFileAlways() (bypassing the
     * skip-if-exists contract) regardless of $this->force — the mechanism
     * regenerateOnly() uses to touch exactly one spec.
     */
    protected function writeDelegationSpecFile(string $delegationKey, array $delegation, bool $force = false): bool
    {
        $uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? 'tab');
        $rawName = $delegation['name'] ?? $delegationKey;
        $slug = Str::kebab((string) $rawName);
        $label = (string) ($delegation['label'] ?? $rawName);

        if ($uiType === 'modal') {
            $createOp = $delegation['operations']['create'] ?? [];
            $fieldsForHelpers = !empty($createOp['enabled']) ? ($createOp['frontend']['fields'] ?? []) : [];
            $testBody = $this->buildModalDelegationSpecBody($delegationKey, $delegation, $label);
        } elseif ($uiType === 'tab' || $uiType === 'tab-action') {
            $fieldsForHelpers = [];
            $testBody = $this->buildTabDelegationSpecBody($delegationKey, $delegation, $label);
        } else {
            return false;
        }

        $content = $this->renderSplitSpec(
            $slug,
            'delegation',
            $label,
            "delegation \"{$label}\" smoke test (auto-generated)",
            $testBody,
            $fieldsForHelpers
        );

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . "-{$slug}.e2e.js";

        return $force ? $this->writeFileAlways($filePath, $content) : $this->writeFile($filePath, $content);
    }

    protected function writeActionSpecFile(string $actionKey, array $action, bool $force = false): bool
    {
        $rawName = $action['name'] ?? $actionKey;
        $slug = Str::kebab((string) $rawName);
        $label = (string) ($action['label'] ?? $rawName);

        $testBody = $this->buildActionSpecBody($actionKey, $action, $label);

        $content = $this->renderSplitSpec(
            $slug,
            'action',
            $label,
            "action \"{$label}\" smoke test (auto-generated)",
            $testBody,
            []
        );

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . "-{$slug}.e2e.js";

        return $force ? $this->writeFileAlways($filePath, $content) : $this->writeFile($filePath, $content);
    }

    /**
     * Deletes this module's pre-split {module-route}.e2e.js once a --force
     * run has written the new split files, so PlaywrightTestGenerator's
     * `testMatch` glob never double-runs the same coverage under two names.
     * Fires only under --force, only after the writes above ran (never
     * delete-then-fail, leaving a module with zero e2e coverage).
     */
    protected function deleteStaleMonolithicFileIfPresent(): void
    {
        if (!$this->force) {
            return;
        }

        $legacyPath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . '.e2e.js';
        if (!is_file($legacyPath)) {
            return;
        }

        unlink($legacyPath);
        PathManager::reportIssue(
            "Removed legacy monolithic e2e spec: " . Str::kebab($this->moduleName) . ".e2e.js (replaced by per-surface split)",
            'info'
        );
    }

    // ------------------------------------------------------------------
    // Field selection helpers
    // ------------------------------------------------------------------

    protected function hasFieldType(string $type): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (($field['field_type'] ?? 'input') === $type) {
                return true;
            }
        }
        return false;
    }

    /** True if any create/edit field uses a select-style field_type (see SELECT_FIELD_TYPES). */
    protected function hasAnySelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if any create/edit select-style field is REQUIRED — these are
     * filled via the throwing fillSelectField() helper (a required relation
     * genuinely cannot be skipped).
     */
    protected function hasRequiredSelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true) && !empty($field['required'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if any create/edit select-style field is OPTIONAL — these are
     * filled via the best-effort, never-throwing tryFillSelectField() helper
     * (see renderFieldFill()'s optional-select branch).
     */
    protected function hasOptionalSelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true) && empty($field['required'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * First create-field that is both a plain scalar (text/number/date/etc,
     * per isScalarField()) AND required — used to drive the "submit with a
     * required field empty" validation-error step. Null when the module has
     * no required scalar create field (the step is then omitted entirely).
     */
    protected function pickRequiredScalarField(): ?array
    {
        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && !empty($field['required']) && $this->isScalarField($field)) {
                return $field;
            }
        }
        return null;
    }

    /**
     * First required create-field of field_type 'file-input' — used to
     * drive the "submit without the required file" validation-error step.
     * Null when the module has no required file-input create field.
     */
    protected function pickRequiredFileField(): ?array
    {
        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && !empty($field['required']) && ($field['field_type'] ?? 'input') === 'file-input') {
                return $field;
            }
        }
        return null;
    }

    protected function isScalarField(array $field): bool
    {
        return !in_array($field['field_type'] ?? 'input', [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox'], true);
    }

    /**
     * First plain scalar (text/number) create field — preferring
     * features.frontend.list.primaryField when it points at one — used as
     * the row we can reliably re-find by visible text after creation.
     * Memoized: called from several block-builders.
     */
    protected function pickAnchorField(): ?string
    {
        if ($this->anchorFieldResolved) {
            return $this->anchorField;
        }
        $this->anchorFieldResolved = true;

        $byKey = [];
        foreach ($this->createFields as $field) {
            if (!empty($field['field'])) {
                $byKey[$field['field']] = $field;
            }
        }

        if ($this->primaryField && isset($byKey[$this->primaryField]) && $this->isScalarField($byKey[$this->primaryField])) {
            $this->anchorField = $this->primaryField;
            return $this->anchorField;
        }

        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                $this->anchorField = $field['field'];
                return $this->anchorField;
            }
        }

        return null;
    }

    /**
     * The one edit field the EDIT block changes. Prefers a plain scalar
     * field that ISN'T the anchor field used to find/filter the row (so the
     * row stays reliably re-findable by its original text throughout the
     * View step, mirroring location-types-crud.e2e.js editing "code" while
     * leaving "name" — its search key — untouched); falls back to the
     * anchor field itself, then to whatever the first edit field is, rather
     * than skip editing entirely.
     */
    protected function pickEditField(): ?array
    {
        $anchor = $this->pickAnchorField();

        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $field['field'] !== $anchor && $this->isScalarField($field)) {
                return $field;
            }
        }
        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                return $field;
            }
        }
        return $this->editFields[0] ?? null;
    }

    /**
     * Chosen filterFields entry for the FILTER block's Variant A. Variant A
     * types `createdRowText` (the anchor field's created value) into this
     * entry's filter control, so the entry is only usable when its key
     * matches the anchor field — filtering a DIFFERENT field by the anchor's
     * value would find nothing. Prefer the entry whose key equals the anchor
     * field; only fall back to the first non-id text entry (which
     * buildFilterBlock()'s equality check will then reject unless it
     * happens to be the anchor) when no anchor-matching entry exists.
     */
    protected function pickTextFilterField(): ?array
    {
        $anchor = $this->pickAnchorField();

        if ($anchor !== null) {
            foreach ($this->filterFields as $ff) {
                $key = $ff['key'] ?? '';
                if ($key === $anchor && ($ff['type'] ?? '') === 'text') {
                    return $ff;
                }
            }
        }

        foreach ($this->filterFields as $ff) {
            $key = $ff['key'] ?? '';
            if ($key !== '' && $key !== 'id' && ($ff['type'] ?? '') === 'text') {
                return $ff;
            }
        }
        return null;
    }

    /**
     * FILTER block fallback for when Variant A doesn't apply (no
     * anchor-matching text filterField — e.g. hasCreate is false, or the
     * anchor simply isn't one of the module's filterable fields): a
     * filterFields entry (excluding "id", which v2.15.0 stopped rendering as
     * a list column — see class docblock) whose key is ALSO a visible
     * features.frontend.list.fields column, paired with that column's
     * display title.
     *
     * Unlike Variant A, this doesn't need to know a value we typed in at
     * create time — it reads whatever the target row is CURRENTLY showing
     * for that column (via getRowColumnValue(page, targetRow, label)) and
     * filters by that, which is valid whether or not this test created the
     * row. This is what replaces the old "read the ID column" Variant B: the
     * id filter control still exists in the real filter panel (the backend
     * always adds one — see BaseServiceGenerator::generateFilterFields()),
     * but its value is no longer visible in the DOM anywhere (no ID column,
     * and unlike "uuid" it isn't embedded in a data-testid either), so it
     * can no longer be read back client-side. "uuid" itself was
     * investigated as a replacement identifier (its value IS always
     * available, via uuidFromTestId()) but generateFilterFields() explicitly
     * never exposes "uuid" as a user-facing filter control ("hidden but
     * filterable" — backend-only), so there is no filter-operator-uuid /
     * filter-value-uuid element for setFilterOperator()/setFilterTextValue()
     * to drive. A real, currently-displayed column is therefore the only
     * value Playwright can both read AND filter by through the UI.
     */
    protected function pickVisibleFilterField(): ?array
    {
        $titleByKey = [];
        foreach ($this->config['features']['frontend']['list']['fields'] ?? [] as $lf) {
            if (!empty($lf['key'])) {
                $titleByKey[$lf['key']] = (string) ($lf['title'] ?? $lf['key']);
            }
        }

        $anchor = $this->pickAnchorField();
        if ($anchor !== null && isset($titleByKey[$anchor])) {
            foreach ($this->filterFields as $ff) {
                if (($ff['key'] ?? '') === $anchor) {
                    return ['key' => $anchor, 'label' => $titleByKey[$anchor]];
                }
            }
        }

        foreach ($this->filterFields as $ff) {
            $key = $ff['key'] ?? '';
            if ($key !== '' && $key !== 'id' && isset($titleByKey[$key])) {
                return ['key' => $key, 'label' => $titleByKey[$key]];
            }
        }

        return null;
    }

    /** JS expression (as a PHP string) for a field's create-time unique value. References the in-scope `stamp` const. */
    protected function fieldValueExpr(array $field): string
    {
        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));

        if (in_array($type, $numericTypes, true)) {
            return '(1000000 + (stamp % 900000))';
        }

        // A real 'date' column MUST get a real yyyy-mm-dd value: it renders
        // as a native `<input type="date">` (see IntrospectionToConfig::
        // buildFrontendFields() — 'date' columns get field_type: 'date' AND
        // type: 'date'), and browsers silently reject/clear any value that
        // isn't valid date-input syntax. The generic `E2E __MODULE__
        // __LABEL__ ${stamp}` template below produced exactly that kind of
        // nonsense string for a 'date' field before this check existed —
        // confirmed live: a generated ItemPrices e2e test tried to
        // `fillField()` "E2E ItemPrices Effective Date 1784869998451" into
        // `#effective_date` (type="date"), which the input rejects outright,
        // so fillField()'s post-fill readback never matched and it threw
        // "Could not fill #effective_date: stuck at ''". Playwright's own
        // `.fill()` on a date input requires exactly this yyyy-mm-dd shape.
        if ($type === 'date') {
            return "new Date().toISOString().slice(0, 10)";
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ ${stamp}`
JS;
        $expr = str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );

        return $this->constrainToColumnLength($field, $expr);
    }

    /**
     * Clamp a generated string to its column's length.
     *
     * `E2E {Module} {Label} ${stamp}` is ~30-40 characters, so any short column
     * (a varchar(20) code, a currency, a colour) was guaranteed to fail the
     * backend's max: rule — the create POST returned 422, the dialog stayed
     * open, and the spec timed out waiting for it to close. The frontend field
     * config carries no length, so this reads columns[].length, which is the
     * same source the migration and the validation rule come from.
     *
     * `slice(-n)` keeps the stamp's low-order digits rather than the constant
     * prefix, so truncated values stay unique across runs — the point of the
     * stamp in the first place.
     */
    protected function constrainToColumnLength(array $field, string $expr): string
    {
        $key = (string) ($field['field'] ?? $field['key'] ?? '');
        if ($key === '') {
            return $expr;
        }

        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['name'] ?? null) !== $key) {
                continue;
            }

            $length = (int) ($column['length'] ?? 0);
            if ($length > 0 && $length < 40) {
                return "({$expr}).slice(-{$length})";
            }

            break;
        }

        return $expr;
    }

    /** Like fieldValueExpr() but produces a distinguishable value for the EDIT block's one changed field. */
    protected function editedFieldValueExpr(array $field): string
    {
        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));

        if (in_array($type, $numericTypes, true)) {
            return '(2000000 + (stamp % 900000))';
        }

        // See fieldValueExpr()'s matching 'date' branch for the full
        // rationale. Offset by one day so the edit test's value is
        // genuinely distinguishable from whatever the create step wrote.
        if ($type === 'date') {
            return "new Date(Date.now() + 86400000).toISOString().slice(0, 10)";
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ EDIT ${stamp}`
JS;
        $expr = str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );

        // The edit value is even longer than the create one, so it needs the
        // same clamp — otherwise edit 422s on exactly the columns create does.
        return $this->constrainToColumnLength($field, $expr);
    }

    /**
     * Renders the JS statement(s) that fill one create/edit field inside the
     * open `[role="dialog"]`, dispatching on field_type. `$valueExpr` is
     * used for the default (plain input) branch only.
     */
    protected function renderFieldFill(array $field, string $valueExpr = ''): string
    {
        $key = (string) ($field['field'] ?? '');
        if ($key === '') {
            return '';
        }
        $fieldType = $field['field_type'] ?? 'input';
        $label = (string) ($field['label'] ?? $key);

        if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
            // An optional (non-required) relation field must NOT be FORCED
            // to select something: fillSelectField() always clicks whatever
            // the first available option happens to be, which throws
            // outright the moment the referenced table has zero rows —
            // a certainty, not an edge case, for a self-referential
            // hierarchy FK on its very first record (e.g.
            // item_categories.parent_id -> item_categories.id: there is
            // categorically no row yet to pick as "parent"). Confirmed
            // live: a generated ItemCategories e2e test failed exactly
            // this way with "no selectable options found for Parent — check
            // seed data". But leaving it permanently untouched means the
            // optional-picker path is never exercised at all. The
            // best-effort tryFillSelectField() helper (see
            // tryFillSelectFieldHelperBlock()) reconciles both: it sets the
            // field when a selectable option exists, and closes the picker
            // and returns `false` — never throws — when the referenced
            // table has zero rows. A REQUIRED relation field (e.g.
            // Items.item_type_id) has no such escape: the test genuinely
            // cannot proceed without a real row in the referenced table,
            // which is a distinct, deeper gap (this generator has no
            // mechanism to seed one) — that case still emits the throwing
            // fillSelectField() call below unchanged.
            if (empty($field['required'])) {
                $tpl = <<<'JS'
		// '__LABEL__' is optional and relation-backed — best-effort: set it
		// when a selectable option exists, otherwise leave it unset (see
		// tryFillSelectField()'s docblock: forcing a selection here would
		// fail outright whenever the referenced table has no rows yet, e.g.
		// a self-referential hierarchy FK's very first record).
		const __KEY__Selected = await tryFillSelectField(page, '[role="dialog"]', '__LABEL__');
		console.log(`[${MODULE_LABEL}] optional field '__LABEL__' ${__KEY__Selected ? 'set' : 'left unset (no options available)'}`);
JS;
                return str_replace(['__KEY__', '__LABEL__'], [$key, addcslashes($label, "'\\")], $tpl);
            }

            $tpl = <<<'JS'
		await fillSelectField(page, '[role="dialog"]', '__LABEL__');
JS;
            return str_replace('__LABEL__', addcslashes($label, "'\\"), $tpl);
        }

        if ($fieldType === 'checkbox') {
            $tpl = <<<'JS'
		await page.locator('[role="dialog"] #__KEY__').click();
JS;
            return str_replace('__KEY__', $key, $tpl);
        }

        if ($fieldType === 'file-input') {
            // A bare 8-byte PNG magic-number prefix is NOT a valid image: real
            // MIME sniffing (libmagic/PHP's finfo, which is exactly what
            // Laravel's `file`/`image` validation rules use server-side) reads
            // actual file content, not just a magic number, and rejects a
            // truncated 8-byte "file" outright — so a test built that way can
            // never pass a real upload against the real backend. This is a
            // genuine, complete, valid 1x1 transparent PNG (70 bytes),
            // base64-embedded and decoded via Buffer.from(..., 'base64') so
            // Playwright's setInputFiles() receives real image bytes;
            // confirmed live with PHP's finfo_file() reporting "image/png"
            // for these exact decoded bytes.
            return <<<'JS'
		await page.locator('[role="dialog"] input[type="file"]').setInputFiles({
			name: 'e2e-fixture.png',
			mimeType: 'image/png',
			buffer: Buffer.from(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
				'base64',
			),
		});
JS;
        }

        if ($fieldType === 'number-input') {
            // A plain fillField() readback-check compares the DOM input's
            // literal displayed string against the exact value typed — but
            // NumberInputField.vue (the component every 'number-input'
            // field_type renders as) formats its value through Cleave.js
            // with thousands separators (see that component's `delimiter:
            // ','` option), so `.inputValue()` legitimately returns
            // "1,875,449" for an underlying value of 1875449. Confirmed
            // live: a generated ItemImages e2e test threw `Could not fill
            // #sort_order: stuck at "1,875,449", expected "1875449"` on
            // its very first attempt — fillField()'s exact-string
            // comparison can never pass for this component. fillNumberField()
            // is the same fill-then-verify contract, just comma-tolerant.
            $tpl = <<<'JS'
		await fillNumberField(page, '[role="dialog"] #__KEY__', __VALUE__);
JS;
            return str_replace(['__KEY__', '__VALUE__'], [$key, $valueExpr], $tpl);
        }

        $tpl = <<<'JS'
		await fillField(page, '[role="dialog"] #__KEY__', __VALUE__);
JS;
        return str_replace(['__KEY__', '__VALUE__'], [$key, $valueExpr], $tpl);
    }

    /**
     * The `const {$varName} = { ...fieldValueExpr() per field };` declaration
     * block shared by buildCreateBlock() and the fixture/delegation create
     * bodies below — extracted so all three stay byte-for-byte consistent
     * with each other rather than drifting via copy-paste. Fields with no
     * plain-fillable value (select/file-input/checkbox — those are filled
     * directly, not via a declared value) are skipped, same as
     * buildFieldFillLines() below. Returns '' when nothing qualifies.
     */
    protected function buildFieldDeclarationsBlock(array $fields, string $varName = 'createValues'): string
    {
        $declLines = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? '';
            $fieldType = $field['field_type'] ?? 'input';
            if ($key === '' || in_array($fieldType, [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox'], true)) {
                continue;
            }
            $declLines[] = "\t\t\t{$key}: " . $this->fieldValueExpr($field) . ',';
        }

        if (empty($declLines)) {
            return '';
        }

        return "\n\n\t\tconst {$varName} = {\n" . implode("\n", $declLines) . "\n\t\t};";
    }

    /**
     * renderFieldFill() lines for every field, split into the non-file lines
     * (filled before submit) and file-only lines (attached last — see
     * buildCreateBlock()'s file-validation-isolation rationale). $varName
     * must match whatever buildFieldDeclarationsBlock() declared the values
     * under.
     *
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    protected function buildFieldFillLines(array $fields, string $varName = 'createValues'): array
    {
        $fillLinesNonFile = [];
        $fillLinesFileOnly = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? '';
            if ($key === '') {
                continue;
            }
            $fieldType = $field['field_type'] ?? 'input';
            $valueExpr = in_array($fieldType, [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox'], true) ? '' : ($varName . '.' . $key);
            $line = $this->renderFieldFill($field, $valueExpr);
            if ($line === '') {
                continue;
            }
            if ($fieldType === 'file-input') {
                $fillLinesFileOnly[] = $line;
            } else {
                $fillLinesNonFile[] = $line;
            }
        }

        return [$fillLinesNonFile, $fillLinesFileOnly];
    }

    /**
     * Strips one leading tab from every non-blank line of a block built by
     * the helpers above (which all emit at the CRUD file's 2-tabs-deep test()
     * body level) so it reads correctly when reused at _fixtures.js's
     * 1-tab-deep exported-function level. Purely cosmetic — JS doesn't care
     * — but keeps generated output readable.
     */
    protected function dedentBlock(string $block, int $levels = 1): string
    {
        $prefix = str_repeat("\t", $levels);
        $lines = explode("\n", $block);
        foreach ($lines as &$line) {
            if (str_starts_with($line, $prefix)) {
                $line = substr($line, strlen($prefix));
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Helper-function block builders (top of file, above test.describe)
    // ------------------------------------------------------------------

    protected function buildHelperFunctions(): string
    {
        $blocks = [];

        if ($this->hasCreate || $this->hasEdit || $this->hasDelete) {
            $blocks[] = $this->fillFieldHelpersBlock();
        }

        if ($this->hasRequiredSelectFieldType()) {
            $blocks[] = $this->selectFieldHelperBlock();
        }

        if ($this->hasOptionalSelectFieldType()) {
            $blocks[] = $this->tryFillSelectFieldHelperBlock();
        }

        if ($this->hasCreate && ($this->pickRequiredScalarField() !== null || $this->pickRequiredFileField() !== null)) {
            $blocks[] = $this->fieldErrorLocatorHelperBlock();
        }

        if ($this->hasFieldType('number-input')) {
            $blocks[] = $this->numberFieldHelperBlock();
        }

        $blocks[] = $this->rowHelpersBlock();

        if ($this->hasDelete) {
            $blocks[] = $this->cleanupHelperBlock();
        }

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    protected function fillFieldHelpersBlock(): string
    {
        return <<<'JS'
/** Replace an input's value directly and dispatch a real `input`/`change`
 * event (avoids selection/keystroke quirks with select-all + retype on
 * Vue-controlled inputs). */
async function setInputValue(page, selector, value) {
	await page.evaluate(
		({ sel, val }) => {
			const el = document.querySelector(sel);
			el.value = val;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		},
		{ sel: selector, val: String(value) },
	);
}

/**
 * Fill a field, then verify the value actually landed — falls back to a
 * direct value-set if `.fill()` dropped keystrokes or a background
 * re-render reset the field. Throws with a clear diagnostic rather than
 * letting a blank field silently reach form submit.
 */
async function fillField(page, selector, value) {
	const expected = String(value);
	await page.locator(selector).fill(expected);
	let actual = await page.locator(selector).inputValue();
	if (actual !== expected) {
		await setInputValue(page, selector, expected);
		actual = await page.locator(selector).inputValue();
		if (actual !== expected) {
			throw new Error(`Could not fill ${selector}: stuck at "${actual}", expected "${expected}"`);
		}
	}
}
JS;
    }

    protected function selectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort driver for a Select2Field/ApiSelect2Field control inside
 * `dialogSelector`, located by its <label> text (mirrors the nested-dialog
 * pattern used by helpers/filters.js's setFilterOperator and the ApiSelect2
 * helpers in locations.e2e.js). Opens the trigger, waits for the picker
 * popup stacked on top, and clicks the first selectable option — enough to
 * satisfy a required relation/select field without knowing real seed data.
 * Generic across both the local Select2Field (`.cursor-pointer` option rows)
 * and the API-backed ApiSelect2Field (`.divide-y > div` option rows) shapes;
 * may need per-module adjustment for more elaborate pickers.
 */
async function fillSelectField(page, dialogSelector, labelText) {
	const trigger = page
		.locator(dialogSelector)
		.locator('.space-y-2', { has: page.locator('label', { hasText: labelText }) })
		.locator('.select2-trigger button');
	if ((await trigger.count()) === 0) {
		throw new Error(`fillSelectField: no select2 trigger found for label "${labelText}" in "${dialogSelector}"`);
	}

	const beforeCount = await page.locator('[role="dialog"]').count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });
	await new Promise((r) => setTimeout(r, 300));

	const popup = page.locator('[role="dialog"]').last();
	const apiOptions = popup.locator('.divide-y > div');
	const option = (await apiOptions.count()) > 0 ? apiOptions.first() : popup.locator('.cursor-pointer').first();
	if ((await option.count()) === 0) {
		throw new Error(`fillSelectField: no selectable options found for "${labelText}" — check seed data`);
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
}
JS;
    }

    protected function tryFillSelectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort variant of fillSelectField() for an OPTIONAL relation field:
 * identical mechanics (open the trigger, wait for the stacked picker popup,
 * click the first selectable option), but — unlike fillSelectField(), which
 * throws when the referenced table has zero rows (a certainty, not an edge
 * case, for some self-referential hierarchies on their very first record) —
 * closes the picker and returns `false` instead of throwing when there is
 * nothing to select. This is what lets an optional relation field actually
 * get exercised when data is available, without reintroducing the
 * zero-row flakiness fillSelectField() was fixed to avoid.
 */
async function tryFillSelectField(page, dialogSelector, labelText) {
	const trigger = page
		.locator(dialogSelector)
		.locator('.space-y-2', { has: page.locator('label', { hasText: labelText }) })
		.locator('.select2-trigger button');
	if ((await trigger.count()) === 0) {
		return false;
	}

	const beforeCount = await page.locator('[role="dialog"]').count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });
	await new Promise((r) => setTimeout(r, 300));

	const popup = page.locator('[role="dialog"]').last();
	const apiOptions = popup.locator('.divide-y > div');
	const option = (await apiOptions.count()) > 0 ? apiOptions.first() : popup.locator('.cursor-pointer').first();
	if ((await option.count()) === 0) {
		await page.keyboard.press('Escape').catch(() => {});
		await page
			.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 })
			.catch(() => {});
		return false;
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
	return true;
}
JS;
    }

    protected function fieldErrorLocatorHelperBlock(): string
    {
        return <<<'JS'
/**
 * Locator for the inline validation-error message rendered directly under
 * the field labeled `labelText` inside `dialogSelector`. InputField.vue and
 * FileInputField.vue both wrap their `<Label>` + control in a `.space-y-2`
 * div with a sibling `<p class="... text-destructive ...">` that only
 * renders `v-if="error"` — mirrors the nested-`.space-y-2` lookup pattern
 * fillSelectField() already uses to find a field by its label.
 */
function fieldErrorLocator(page, dialogSelector, labelText) {
	return page
		.locator(dialogSelector)
		.locator('.space-y-2', { has: page.locator('label', { hasText: labelText }) })
		.locator('p.text-destructive');
}
JS;
    }

    protected function numberFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Like fillField(), but tolerant of NumberInputField.vue's Cleave.js
 * thousands-separator formatting (`delimiter: ','` — see that component):
 * `.inputValue()` legitimately returns "1,875,449" for an underlying value
 * of 1875449, so a bare string-equality readback check can never pass for
 * a 'number-input' field_type. Commas are stripped from both sides before
 * comparing; everything else mirrors fillField()'s fill-then-verify,
 * fall-back-to-setInputValue contract.
 */
async function fillNumberField(page, selector, value) {
	const stripCommas = (s) => String(s).replace(/,/g, '');
	const expected = String(value);
	await page.locator(selector).fill(expected);
	let actual = await page.locator(selector).inputValue();
	if (stripCommas(actual) !== stripCommas(expected)) {
		await setInputValue(page, selector, expected);
		actual = await page.locator(selector).inputValue();
		if (stripCommas(actual) !== stripCommas(expected)) {
			throw new Error(`Could not fill ${selector}: stuck at "${actual}", expected "${expected}"`);
		}
	}
}
JS;
    }

    protected function rowHelpersBlock(): string
    {
        return <<<'JS'
/** Locator for the row(s) whose text contains `text`. */
function rowLocator(page, text) {
	return page.locator('table tbody tr', { hasText: text });
}

async function rowExists(page, name) {
	return (await rowLocator(page, name).count()) > 0;
}

/** Extract the uuid segment from a `data-testid="[[moduleName]]-{action}-{uuid}"` attribute. */
function uuidFromTestId(testId, action) {
	if (!testId) return null;
	const prefix = `[[moduleName]]-${action}-`;
	return testId.startsWith(prefix) ? testId.slice(prefix.length) : null;
}
JS;
    }

    protected function cleanupHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort delete of a record left over from a failed run. Invoked from a
 * `finally` block once the record's uuid has been captured right after
 * creation, so this runs regardless of whether the view/edit/delete steps
 * after creation succeeded or threw.
 *
 * Every failure here is swallowed and logged as a WARNING rather than
 * thrown — this executes during failure unwinding and must never replace or
 * mask the original error that triggered it.
 */
async function cleanupStrayRecord(page, uuid) {
	try {
		if ((await page.locator('[role="dialog"]').count()) > 0) {
			await page.keyboard.press('Escape').catch(() => {});
			await sleep(500);
		}

		const stillPresent = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count().catch(() => 0)) > 0;
		if (!stillPresent) {
			console.log(`[${MODULE_LABEL}] cleanup: uuid ${uuid} no longer in the list — nothing to do`);
			return;
		}

		console.log(`[${MODULE_LABEL}] cleanup: attempting best-effort delete of uuid ${uuid} after failure`);

		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`cleanup view button (data-testid="[[moduleName]]-view-${uuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${uuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await sleep(500);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		const stillThere = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count()) > 0;
		if (stillThere) {
			console.log(`[${MODULE_LABEL}] WARNING: cleanup delete did not remove uuid ${uuid} — manual removal may be required`);
		} else {
			console.log(`[${MODULE_LABEL}] cleanup: deleted stray record (uuid ${uuid})`);
		}
	} catch (cleanupErr) {
		console.log(
			`[${MODULE_LABEL}] WARNING: cleanup failed for uuid ${uuid} — manual removal may be required:`,
			cleanupErr && cleanupErr.stack ? cleanupErr.stack : cleanupErr,
		);
	}
}
JS;
    }

    // ------------------------------------------------------------------
    // Test description + body
    // ------------------------------------------------------------------

    protected function buildTestDescription(): string
    {
        $steps = [];
        if ($this->hasCreate) {
            $steps[] = 'create';
        }
        $steps[] = 'filter';
        $steps[] = 'view';
        if ($this->hasEdit) {
            $steps[] = 'edit';
        }
        if ($this->hasDelete) {
            $steps[] = 'delete';
        }

        return implode(' -> ', $steps) . ' cycle (auto-generated)';
    }

    protected function buildTestBody(): string
    {
        $sections = [];

        $createBlock = $this->buildCreateBlock();
        if (trim($createBlock) !== '') {
            $sections[] = $createBlock;
        }

        $sections[] = $this->buildTargetRowBlock();
        $sections[] = $this->buildFilterBlock();
        $sections[] = $this->buildUuidCaptureBlock();

        $inner = [];
        $inner[] = $this->buildRelatedRecordBlock();
        // Delegation coverage lives in its own per-key {module}-{key}.e2e.js
        // sibling instead (see writeDelegationSpecFile()) — kept out of this
        // file so the CRUD spec's scope doesn't grow unboundedly as
        // delegations are added, and so regenerating one delegation's spec
        // never has to touch this one.
        $inner[] = $this->buildViewBlock();
        if ($this->hasEdit) {
            $inner[] = $this->buildEditBlock();
        }
        // Bulk-action/export/import operate on the LIST itself, not the one
        // record buildTargetRowBlock()/buildUuidCaptureBlock() captured
        // above — placed after view/edit (which reopen/close the view
        // modal) and before delete, so a to-be-deleted row is still present
        // for them to act against.
        if ($this->hasBulkActions) {
            $inner[] = $this->buildBulkActionBlock();
        }
        if ($this->hasExport) {
            $inner[] = $this->buildExportBlock();
        }
        if ($this->hasImport) {
            $inner[] = $this->buildImportBlock();
        }
        if ($this->hasDelete) {
            $inner[] = $this->buildDeleteBlock();
        }
        $innerBlock = implode("\n\n", array_filter($inner, fn ($s) => trim($s) !== ''));

        if ($this->hasDelete) {
            $wrapTpl = <<<'JS'
		let recordCleanedUp = false;
		try {
__INNER__

			recordCleanedUp = true;
		} finally {
			// Guaranteed to run whether the steps above succeeded or threw. If
			// the Delete block above already ran to completion, recordCleanedUp
			// is true and there is nothing to do — this only fires for failure paths.
			if (recordUuid && !recordCleanedUp) {
				await cleanupStrayRecord(page, recordUuid);
			}
		}
JS;
            // buildViewBlock()/buildEditBlock()/buildDeleteBlock() are written at the
            // test body's base 2-tab depth (the same depth as the try/finally lines
            // above) since that's also where they land verbatim when hasDelete is
            // false (the plain `$innerBlock` branch below). Wrapping them in try{}
            // here nests them one level deeper, so — only in this branch — every
            // line needs an extra tab or the generated spec reads with the whole
            // View/Edit/Delete body flush against `try {`/`} finally {` themselves.
            $sections[] = str_replace('__INNER__', $this->indentBlock($innerBlock), $wrapTpl);
        } else {
            $sections[] = $innerBlock;
        }

        return implode("\n\n", array_filter($sections, fn ($s) => trim($s) !== ''));
    }

    /**
     * Indent every non-blank line of a multi-line generated JS block by one
     * extra tab level. Blank lines are left untouched so no trailing
     * whitespace gets introduced.
     */
    protected function indentBlock(string $block, int $levels = 1): string
    {
        $prefix = str_repeat("\t", $levels);
        $lines = explode("\n", $block);
        foreach ($lines as &$line) {
            if ($line !== '') {
                $line = $prefix . $line;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Individual step blocks
    // ------------------------------------------------------------------

    protected function buildCreateBlock(): string
    {
        if (!$this->hasCreate) {
            return '';
        }

        $open = <<<'JS'
		// ── Create ────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			() => page.locator('[data-testid="[[moduleName]]-create"]').click(),
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '02-create-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing
JS;

        // ── Validation: submit the pristine, entirely empty form ────────────
        // Runs BEFORE anything below fills a single field, on the freshly
        // opened dialog — the only point in the whole flow where every field
        // is still blank. The create form has no client-side `required`
        // gate (see input.stub: `:required` is forwarded to InputField.vue
        // only to render the red "*" label, never onto the underlying
        // `<input>` — confirmed against SYSTEM_SHELL/FRONTEND/src/
        // components/form-fields/InputField.vue and Input.vue), so this
        // submit always reaches the backend and comes back as a real 422.
        // The dialog never closes/navigates on a failed submit (handleSubmit
        // only emits 'created'/navigates when `response.status` is true), so
        // every fill/submit step below runs against the exact same
        // still-open dialog and is completely unaffected by this step.
        $requiredScalarField = $this->pickRequiredScalarField();
        $validationBlock = '';
        if ($requiredScalarField !== null) {
            $validationLabel = (string) ($requiredScalarField['label'] ?? $requiredScalarField['field']);
            $validationTpl = <<<'JS'

		// ── Validation: submitting with required "__LABEL__" empty ─────────────
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
		const requiredFieldError = fieldErrorLocator(page, '[role="dialog"]', '__LABEL__');
		await requiredFieldError.first().waitFor({ timeout: 10000 });
		expect(await requiredFieldError.count(), `Expected an inline validation error for "__LABEL__" after submitting it empty`).toBeGreaterThan(0);
		expect(await page.locator('[role="dialog"]').count(), 'Dialog should remain open after a failed validation submit').toBeGreaterThan(0);
		console.log(`[${MODULE_LABEL}] validation OK — submitting with "__LABEL__" empty rendered an inline error and kept the dialog open`);
JS;
            $validationBlock = str_replace('__LABEL__', addcslashes($validationLabel, "'\\"), $validationTpl);
        }

        $declBlock = $this->buildFieldDeclarationsBlock($this->createFields);
        [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($this->createFields);
        $fillBlock = implode("\n", $fillLinesNonFile);

        // ── Validation: submit with every field filled EXCEPT the required
        // file — isolates the file requirement from the rest of the form
        // (every other required field, including required relations, is
        // already filled by $fillBlock above) so this genuinely exercises
        // the file-required path rather than conflating it with some other
        // missing field. Same "dialog never closes on failure" guarantee as
        // the empty-form validation step above, so the file itself is
        // simply attached afterwards and the happy-path submit proceeds
        // completely unaffected.
        $requiredFileField = $this->pickRequiredFileField();
        $fileValidationBlock = '';
        if ($requiredFileField !== null && !empty($fillLinesFileOnly)) {
            $fileLabel = (string) ($requiredFileField['label'] ?? $requiredFileField['field']);
            $fileValidationTpl = <<<'JS'

		// ── Validation: submitting without the required "__LABEL__" file ────────
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
		const missingFileError = fieldErrorLocator(page, '[role="dialog"]', '__LABEL__');
		await missingFileError.first().waitFor({ timeout: 10000 });
		expect(await missingFileError.count(), `Expected an inline validation error for missing required "__LABEL__" file`).toBeGreaterThan(0);
		expect(await page.locator('[role="dialog"]').count(), 'Dialog should remain open after a failed file-required submit').toBeGreaterThan(0);
		console.log(`[${MODULE_LABEL}] validation OK — submitting without required "__LABEL__" file rendered an inline error and kept the dialog open`);
JS;
            $fileValidationBlock = str_replace('__LABEL__', addcslashes($fileLabel, "'\\"), $fileValidationTpl);
        }
        $fileFillBlock = implode("\n", $fillLinesFileOnly);

        $submit = <<<'JS'
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
JS;

        $anchor = $this->pickAnchorField();
        if ($anchor !== null) {
            $confirmTpl = <<<'JS'

		const createdRowText = String(createValues.__ANCHOR__);
		await page.waitForFunction(
			(rowText) => Array.from(document.querySelectorAll('table tbody tr')).some((tr) => tr.textContent.includes(rowText)),
			createdRowText,
			{ timeout: 15000 },
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" did not appear in the list`).toBe(true);
		console.log(`[${MODULE_LABEL}] create OK — row "${createdRowText}" present`);
JS;
            $confirm = str_replace('__ANCHOR__', $anchor, $confirmTpl);
        } else {
            $confirm = <<<'JS'

		console.log(`[${MODULE_LABEL}] create submitted (no plain text/number field available — skipping row-text assertion)`);
JS;
        }

        $shotLine = "\n\t\tawait shot(page, '03-after-create');";

        $fileFillSection = $fileFillBlock !== '' ? "\n" . $fileFillBlock : '';

        return $open . $validationBlock . $declBlock . "\n\n" . $fillBlock . $fileValidationBlock . $fileFillSection . "\n\n" . $submit . $confirm . $shotLine;
    }

    protected function buildTargetRowBlock(): string
    {
        if ($this->hasCreate && $this->pickAnchorField() !== null) {
            return <<<'JS'
		const targetRow = rowLocator(page, createdRowText).first();
JS;
        }

        return <<<'JS'
		const targetRow = page.locator('table tbody tr').first();
JS;
    }

    protected function buildFilterBlock(): string
    {
        $textFilter = $this->pickTextFilterField();
        $anchor = $this->pickAnchorField();
        $useVariantA = $textFilter !== null && $this->hasCreate && $anchor !== null && $textFilter['key'] === $anchor;

        if ($useVariantA) {
            return $this->buildFilterVariantA((string) $textFilter['key']);
        }

        $visible = $this->pickVisibleFilterField();
        if ($visible === null) {
            // Ultimate fallback: no filterField is both declared filterable
            // AND confirmed to be a rendered list column (e.g.
            // features.frontend.list.fields wasn't supplied at all). Every
            // real module.json in this codebase carries list.fields with at
            // least the anchor/primary field present in both places (see
            // pickVisibleFilterField()'s docblock), so this isn't expected
            // to fire for real modules — but degrade to the module's first
            // declared filterField (whatever it is) rather than emit no
            // Filter step at all for a genuinely misconfigured one.
            $first   = $this->filterFields[0] ?? [];
            $visible = [
                'key'   => (string) ($first['key'] ?? 'id'),
                'label' => (string) ($first['label'] ?? 'ID'),
            ];
        }

        return $this->buildFilterVariantB((string) $visible['key'], (string) $visible['label']);
    }

    protected function buildFilterVariantA(string $filterKey): string
    {
        $tpl = <<<'JS'
		// ── Filter (Variant A: plain text field "__KEY__") ───────────────────
		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterTextValue(page, '__KEY__', createdRowText);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after filtering by __KEY__`).toBe(true);
		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by __KEY__="${createdRowText}" to narrow the list to 1 row, got ${filteredRowCount}`).toBe(1);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__ narrowed the list to exactly 1 row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after clearing filters`).toBe(true);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace('__KEY__', $filterKey, $tpl);
    }

    /**
     * Filter block Variant B: filters by whatever value the target row is
     * CURRENTLY showing for a real, visible list column — chosen by
     * pickVisibleFilterField() as a filterFields entry that's also a
     * features.frontend.list.fields column. Replaces the pre-v2.15.0
     * "read the ID column" approach (see class docblock and
     * pickVisibleFilterField()'s docblock for why "id"/"uuid" can't stand
     * in for it): $filterKey drives the filter panel control,
     * $columnLabel is that same field's list-column header text, used to
     * read the row's live value via getRowColumnValue().
     */
    protected function buildFilterVariantB(string $filterKey, string $columnLabel): string
    {
        $tpl = <<<'JS'
		// ── Filter (Variant B: visible column "__LABEL__", field "__KEY__") ──
		const targetValue = await getRowColumnValue(page, targetRow, '__LABEL__');
		console.log(`[${MODULE_LABEL}] filter: captured target row's __LABEL__ = "${targetValue}"`);

		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterOperator(page, '__KEY__', 'Equals');
		await setFilterTextValue(page, '__KEY__', targetValue);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by __KEY__=${targetValue} (Equals) to narrow the list to exactly 1 row, got ${filteredRowCount}`).toBe(1);
		const onlyRow = page.locator('table tbody tr').first();
		const onlyRowValue = await getRowColumnValue(page, onlyRow, '__LABEL__');
		expect(
			onlyRowValue,
			`Expected the sole remaining row after filtering by __KEY__=${targetValue} to be the target row, got __LABEL__="${onlyRowValue}"`,
		).toBe(targetValue);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__="${targetValue}" (Equals) narrowed the list to exactly the target row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace(['__KEY__', '__LABEL__'], [$filterKey, $columnLabel], $tpl);
    }

    /**
     * Click a foreign-key cell's RelatedRecordLink and assert it opens the
     * related record's view modal.
     *
     * FK columns render through <RelatedRecordLink> so a user can jump from,
     * say, an Item's "Item Category" cell straight into that category's own
     * view modal without leaving the list. Nothing tested that jump: the
     * generated spec asserted the cell's TEXT ("Beverages") and never that the
     * link works, so a link that rendered as inert text — which is exactly what
     * RelatedRecordLink degrades to when the target module is unregistered or
     * the user lacks {Module}.view — looked identical to a working one.
     */
    protected function buildRelatedRecordBlock(): string
    {
        $fk = null;
        foreach ($this->config['features']['frontend']['list']['fields'] ?? [] as $field) {
            if (($field['isFk'] ?? false) && !empty($field['relatedModule'])) {
                $fk = $field;
                break;
            }
        }

        if ($fk === null) {
            return '';
        }

        $relatedModule = $fk['relatedModule'];

        return <<<JS
		// ── Related record: FK cell -> related module's view modal ───────────
		{
			const relatedLink = page.locator('[data-testid^="related-{$relatedModule}-"]').first();

			if ((await relatedLink.count()) > 0) {
				const relatedLabel = (await relatedLink.innerText()).trim();
				await relatedLink.click();

				// openEntity() mounts the RELATED module's view modal, so the
				// dialog that appears belongs to {$relatedModule}, not this module.
				const relatedDialog = page.locator('[role="dialog"]').first();
				await relatedDialog.waitFor({ timeout: 15000 });
				await sleep(1200);

				expect(
					(await relatedDialog.innerText()).trim().length,
					'related-record modal opened but rendered nothing'
				).toBeGreaterThan(0);
				console.log(`[\${MODULE_LABEL}] related record "\${relatedLabel}" opened {$relatedModule}'s view modal`);
				await shot(page, 'related-{$relatedModule}');

				// Back to a clean list before the create/view/edit steps below.
				await page.keyboard.press('Escape');
				await sleep(600);
				await page.goto(`\${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
					waitUntil: 'networkidle',
					timeout: 30000,
				});
				await waitForListSettled(page);
			} else {
				// Not a silent skip: an inert FK cell is the bug this guards.
				console.log(`[\${MODULE_LABEL}] WARNING: no clickable {$relatedModule} related-record link found — FK cell may be rendering as plain text`);
			}
		}
JS;
    }

    /**
     * Exercise each tab-type delegation on the record's details page.
     *
     * Delegations had no e2e coverage at all: PlaywrightTestGenerator did not
     * mention them, so a generated spec drove the parent module's own CRUD and
     * never opened a child tab. That gap is how a broken tab link survived —
     * the nav emitted `/details/ScratchItems` while the route was registered as
     * `scratch-items` (see generateTabNavigation()).
     *
     * Navigates directly to the tab route rather than clicking the nav link,
     * then asserts the nav link exists and points at the same path — a click
     * alone would pass on a mismatched link by silently staying put.
     */
    protected function buildDelegationBlock(): string
    {
        $delegations = $this->config['delegations'] ?? [];
        $blocks = [];

        foreach ($delegations as $featureKey => $delegation) {
            $uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? '');
            if ($uiType !== 'tab' && $uiType !== 'tab-action') {
                continue;
            }

            $tabId = Str::kebab($delegation['name'] ?? $featureKey);
            $label = addslashes($delegation['label'] ?? $tabId);
            $filterKey = $delegation['filterKey'] ?? 'parent_id';

            $blocks[] = <<<JS
		// ── Delegation: {$label} ────────────────────────────────────────────
		if (recordUuid) {
			await page.goto(`\${BASE_URL}/[[moduleRoute]]/\${recordUuid}/details/{$tabId}`, {
				waitUntil: 'networkidle',
				timeout: 30000,
			});

			// The tab must be reachable from the nav, not just by URL: assert the
			// generated link resolves to the route that actually exists.
			const {$this->jsIdent($tabId)}Tab = page.locator('[data-testid="[[moduleName]]-tab-{$tabId}"]');
			await expect({$this->jsIdent($tabId)}Tab, 'delegation tab "{$label}" missing from the details nav').toHaveCount(1);
			expect(
				await {$this->jsIdent($tabId)}Tab.getAttribute('href'),
				'delegation tab "{$label}" links to a path the router does not register'
			).toContain('/details/{$tabId}');

			// The child list renders inside the tab's router-view. Waiting for a
			// `table` alone is not enough: a delegation tab with no columns
			// renders a table shell with no header and no rows while the fetch
			// returns 200 — it looks like missing data and is not. Assert the
			// header row, and that no label leaked as a raw i18n key.
			const childTable = page.locator('table').first();
			await childTable.waitFor({ timeout: 15000 });
			const childHeaders = (await childTable.locator('thead th').allTextContents())
				.map((h) => h.trim())
				.filter(Boolean);

			expect(
				childHeaders.length,
				'delegation "{$label}" rendered a table with no column headers — the tab generated an empty columns array'
			).toBeGreaterThan(0);
			expect(
				childHeaders.filter((h) => /^[a-z0-9-]+\.[a-z0-9_]+$/.test(h)),
				'delegation "{$label}" column headers are raw i18n keys — the label namespace does not resolve'
			).toEqual([]);

			await shot(page, 'delegation-{$tabId}');
			console.log(`[\${MODULE_LABEL}] delegation "{$label}" tab rendered — headers: \${childHeaders.join(', ')}`);

			// ── Child CREATE ────────────────────────────────────────────────
			// Only the child LIST was ever exercised, so the create path could
			// break silently — and did: the FK default sent the parent's uuid
			// into an integer column and every create 422'd with "must be an
			// integer". Opening the form and submitting is what catches it.
			const addChild = page.getByRole('button', { name: /^Add / }).first();
			if ((await addChild.count()) > 0) {
				// The create request is intercepted and answered with a synthetic
				// success rather than allowed through. Letting it complete leaves
				// a real child row, and the parent's own delete step later in
				// this spec then fails with "Cannot delete — active relationships
				// found" — a test creating data that breaks its own teardown.
				// It is FULFILLED, not aborted: aborting raises a `requestfailed`
				// event, which this spec's own diagnostics gate treats as a
				// failure. Either way the payload is captured, which is the point.
				let submitted = null;
				await page.route('**/{$tabId}/create', async (route) => {
					submitted = route.request().postData() ?? '';
					await route.fulfill({
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify({ status: true, code: 200, message: 'intercepted by e2e', data: {} }),
					});
				});

				await addChild.click();
				await page.locator('[role="dialog"]').last().waitFor({ timeout: 10000 });
				await sleep(1000);
				await shot(page, 'delegation-{$tabId}-create-form');

				const submit = page.locator('[role="dialog"]').last().getByRole('button', { name: /^(Create|Save|Submit)/ }).first();
				if ((await submit.count()) > 0) {
					await submit.click();
					await sleep(1500);
				}
				await page.unroute('**/{$tabId}/create');

				if (submitted !== null) {
					// The FK must carry the parent's integer id. Sending the uuid
					// string here is what produced "The item id field must be an
					// integer" on every delegation create.
					// Forms post multipart/form-data (they may carry an upload), so
					// the FK appears as a Content-Disposition part, not JSON. Both
					// shapes are accepted so this keeps working if that changes.
					const jsonMatch = submitted.match(/"{$filterKey}"\s*:\s*"?([^",}]*)"?/);
					const formMatch = submitted.match(/name="{$filterKey}"[\s\S]*?\\r?\\n\\r?\\n([^\\r\\n]*)/);
					const fkValue = (jsonMatch?.[1] ?? formMatch?.[1] ?? '').trim();

					expect(
						fkValue !== '',
						`delegation "{$label}" create sent no {$filterKey} at all — the parent FK default is missing`
					).toBeTruthy();
					expect(
						/^\d+$/.test(fkValue),
						`delegation "{$label}" create sent {$filterKey}="\${fkValue}" — expected the parent's integer id, not its uuid`
					).toBeTruthy();
					console.log(`[\${MODULE_LABEL}] delegation "{$label}" create sends {$filterKey}=\${fkValue} (parent id)`);
				} else {
					console.log(`[\${MODULE_LABEL}] WARNING: delegation "{$label}" create was never submitted — FK payload unverified`);
				}

				// Leave nothing open: a lingering dialog swallows the clicks of
				// every step after this block, surfacing far away as a cleanup
				// timeout rather than as a failure here.
				for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
					await page.keyboard.press('Escape');
					await sleep(700);
				}
			} else {
				console.log(`[\${MODULE_LABEL}] delegation "{$label}" has no create action enabled — skipping child create`);
			}

			// Return to the list. The edit/delete steps that follow operate on
			// the list page's row actions, and leaving the browser parked on the
			// details route made them fail with a missing-button error that
			// looked like a permissions or rendering bug rather than navigation.
			await page.goto(`\${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
				waitUntil: 'networkidle',
				timeout: 30000,
			});
			await waitForListSettled(page);
		}
JS;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * kebab-case tab id -> a safe JS identifier prefix (scratch-items -> scratchItems).
     */
    protected function jsIdent(string $kebab): string
    {
        return lcfirst(Str::studly($kebab));
    }

    protected function buildUuidCaptureBlock(): string
    {
        return <<<'JS'
		const recordTestId = await targetRow
			.locator('[data-testid^="[[moduleName]]-view-"]')
			.first()
			.getAttribute('data-testid')
			.catch(() => null);
		const recordUuid = uuidFromTestId(recordTestId, 'view');
		if (recordUuid) {
			console.log(`[${MODULE_LABEL}] captured uuid ${recordUuid} — view/edit/delete steps below are now targetable`);
		} else {
			console.log(`[${MODULE_LABEL}] WARNING: could not capture a record uuid — view/edit/delete steps below may fail`);
		}
JS;
    }

    protected function buildViewBlock(): string
    {
        $tpl = <<<'JS'
		// ── View ──────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 }).catch(() => {});
		await sleep(500);
		await shot(page, '04-view-modal');
__VIEW_ASSERT__
JS;

        if ($this->hasCreate && $this->pickAnchorField() !== null) {
            $assert = <<<'JS'

		const viewText = (await page.locator('[role="dialog"]').textContent()) || '';
		expect(viewText.includes(createdRowText), `View modal did not render expected value "${createdRowText}"`).toBe(true);
		console.log(`[${MODULE_LABEL}] view OK — modal shows the created record`);
JS;
        } else {
            $assert = <<<'JS'

		console.log(`[${MODULE_LABEL}] view OK — modal opened for the target record`);
JS;
        }

        return str_replace('__VIEW_ASSERT__', $assert, $tpl);
    }

    protected function buildEditBlock(): string
    {
        $field = $this->pickEditField();
        if ($field === null || empty($field['field'])) {
            return <<<'JS'
		// ── Edit ──────────────────────────────────────────────────────────────
		console.log(`[${MODULE_LABEL}] edit skipped — no editable fields declared in features.frontend.edit.fields`);
JS;
        }

        $key = (string) $field['field'];
        $fieldType = $field['field_type'] ?? 'input';

        $openTpl = <<<'JS'
		// ── Edit (via the view modal's Edit button) ──────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const editBtn = page.locator(`[data-testid="[[moduleName]]-edit-${recordUuid}"]`);
				if ((await editBtn.count()) === 0) throw new Error(`Edit button (data-testid="[[moduleName]]-edit-${recordUuid}") not found`);
				await editBtn.click();
			},
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '05-edit-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before editing
JS;

        if (!$this->isScalarField($field)) {
            // Non-scalar edit field (select/checkbox/file-input): reuse the
            // same fill logic as create, submit, and only assert the flow
            // completed — there's no deterministic text value to diff
            // against for these field types the way a plain input has.
            $fillLine = $this->renderFieldFill($field, '');
            $submitTpl = <<<'JS'

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
		console.log(`[${MODULE_LABEL}] edit OK — submitted an updated __KEY__ value`);
		await shot(page, '06-after-edit');
JS;
            $submit = str_replace('__KEY__', $key, $submitTpl);

            return $openTpl . "\n" . $fillLine . $submit;
        }

        $valueExpr = $this->editedFieldValueExpr($field);
        $fillEditTpl = <<<'JS'

		const editedValue = __VALUE__;
		await setInputValue(page, '[role="dialog"] #__KEY__', editedValue);
		const editedActual = await page.locator('[role="dialog"] #__KEY__').inputValue();
		// String(...) + comma-stripping, not a bare `!==`: editedValue is a raw
		// JS expression that for a numeric field is a NUMBER literal (see
		// editedFieldValueExpr()'s numeric branch), while .inputValue() always
		// returns a STRING — a bare `!==` type-mismatches and fails for every
		// numeric field regardless of its actual value. Comma-stripping
		// additionally tolerates NumberInputField.vue's Cleave.js thousands-
		// separator display (see fillNumberField()'s docblock) for the same
		// 'number-input' fields. Both were confirmed live against a generated
		// ItemPrices e2e test's "price" edit field.
		const normalizeForCompare = (v) => String(v).replace(/,/g, '');
		if (normalizeForCompare(editedActual) !== normalizeForCompare(editedValue)) {
			throw new Error(`Could not set edit #__KEY__: stuck at "${editedActual}", expected "${editedValue}"`);
		}

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		await page.waitForFunction(
			({ uuid, value }) => {
				const btn = document.querySelector(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				const row = btn ? btn.closest('tr') : null;
				return !!row && row.textContent.includes(value);
			},
			{ uuid: recordUuid, value: editedValue },
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] edit OK — record now shows the updated __KEY__ value`);
		await shot(page, '06-after-edit');
JS;
        $fillEdit = str_replace(['__VALUE__', '__KEY__'], [$valueExpr, $key], $fillEditTpl);

        return $openTpl . $fillEdit;
    }

    /**
     * Checks the first 2 rows, clicks the first configured bulk_actions[]
     * key's toolbar button, confirms the dialog, asserts a success
     * indicator (the BatchResultDrawer testid ListTable.vue always opens
     * after a bulk action completes). Skips gracefully with fewer than 2
     * rows rather than assuming seed data — matches
     * buildFilterBlock()'s own getVisibleRowCount()-gated defensive style.
     */
    protected function buildBulkActionBlock(): string
    {
        $firstKey = (string) ($this->bulkActions[0]['key'] ?? '');
        if ($firstKey === '') {
            return '';
        }
        $keyJs = addslashes($firstKey);

        return <<<JS
		// ── Bulk action ({$keyJs}) ────────────────────────────────────────────
		{
			const rowCount = await getVisibleRowCount(page);
			if (rowCount >= 2) {
				await page.locator('table tbody tr').nth(0).locator('[data-testid^="[[moduleName]]-bulk-select-"]').click();
				await page.locator('table tbody tr').nth(1).locator('[data-testid^="[[moduleName]]-bulk-select-"]').click();
				await page.locator(`[data-testid="[[moduleName]]-bulk-action-{$keyJs}"]`).click();

				// A configured confirmMessage opens the confirm dialog; without one
				// the action fires immediately (see ListTable.vue's requestBulkAction()).
				const confirmBtn = page.locator('[data-testid="[[moduleName]]-bulk-confirm"]');
				if (await confirmBtn.count() > 0 && await confirmBtn.isVisible().catch(() => false)) {
					await confirmBtn.click();
				}

				await page.locator('[data-testid="batch-result-drawer"]').waitFor({ timeout: 15000 });
				await shot(page, 'bulk-action-result');
				console.log(`[\${MODULE_LABEL}] bulk action '{$keyJs}' OK — result drawer shown`);
				await page.keyboard.press('Escape');
				await sleep(500);
				await waitForListSettled(page);
			} else {
				console.log(`[\${MODULE_LABEL}] bulk action '{$keyJs}' skipped — fewer than 2 rows available`);
			}
		}
JS;
    }

    /**
     * Intercepts (fulfills, not continues, so it doesn't count against the
     * spec's own zero-failedRequests diagnostics gate) the export request
     * rather than actually downloading a file, and asserts the requested
     * format param — proving the button/endpoint wiring works without
     * depending on Playwright's download handling.
     */
    protected function buildExportBlock(): string
    {
        return <<<'JS'
		// ── Export ────────────────────────────────────────────────────────────
		{
			let exportUrl = null;
			await page.route('**/*/export*', async (route) => {
				exportUrl = route.request().url();
				await route.fulfill({ status: 200, contentType: 'text/csv', body: '' });
			});
			await page.locator('[data-testid="[[moduleName]]-export-open"]').click();
			await page.locator('[data-testid="[[moduleName]]-export-csv"]').click();
			await sleep(500);
			await page.unroute('**/*/export*');

			expect(exportUrl, 'export button did not trigger a request').not.toBeNull();
			expect(exportUrl, `export request missing format=csv: ${exportUrl}`).toContain('format=csv');
			console.log(`[${MODULE_LABEL}] export OK — ${exportUrl}`);
		}
JS;
    }

    /**
     * Downloads the module's own real generated template (not a hand-built
     * fixture file) via Playwright's download event, feeds that exact file
     * back into the file input, submits with dry-run checked (so nothing
     * is actually committed — a real row is not required to exist), and
     * asserts the result drawer confirms the upload was processed.
     */
    protected function buildImportBlock(): string
    {
        return <<<'JS'
		// ── Import ───────────────────────────────────────────────────────────
		{
			const [download] = await Promise.all([
				page.waitForEvent('download'),
				page.locator('[data-testid="[[moduleName]]-import-open"]').click().then(() =>
					page.locator('[data-testid="[[moduleName]]-import-template-csv"]').click()
				),
			]);
			const templatePath = await download.path();
			expect(templatePath, 'import template download did not produce a file').not.toBeNull();

			await page.locator('[data-testid="[[moduleName]]-import-file"]').setInputFiles(templatePath);
			await page.locator('[data-testid="[[moduleName]]-import-dry-run"]').check();
			await page.locator('[data-testid="[[moduleName]]-import-submit"]').click();

			await page.locator('[data-testid="batch-result-drawer"]').waitFor({ timeout: 15000 });
			await shot(page, 'import-result');
			console.log(`[${MODULE_LABEL}] import OK — result drawer shown after dry-run upload`);
			await page.keyboard.press('Escape');
			await sleep(500);
		}
JS;
    }

    protected function buildDeleteBlock(): string
    {
        $reopen = '';
        if ($this->hasEdit) {
            $reopen = <<<'JS'
		// Edit closed the view modal — reopen it before reaching Delete (mirrors
		// locations.e2e.js / wards.e2e.js: view -> edit -> view again -> delete).
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found before delete`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

JS;
        }

        $body = <<<'JS'
		// ── Delete (via the view modal's "More Actions" -> Delete) ───────────
		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${recordUuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await shot(page, '07-delete-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing

		// Wrong confirmation text must NOT allow the delete to proceed: the
		// confirm button is only ever enabled when confirmText === 'YES'
		// exactly (see [[ModuleName]]DeleteForm.vue's `isConfirmValid`
		// computed, which drives `:disabled="!isConfirmValid || isDeleting"`
		// on the confirm button) — no click is even attempted here, since
		// Playwright's own actionability check would fail on a genuinely
		// disabled element; asserting the disabled state is the direct,
		// non-flaky way to prove the wrong text is rejected.
		await fillField(page, '[role="dialog"] #confirm', 'nope');
		await expect(page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]')).toBeDisabled();
		console.log(`[${MODULE_LABEL}] delete OK — wrong confirmation text keeps the confirm button disabled`);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		expect(await page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`).count(), 'Row still present after delete').toBe(0);
		console.log(`[${MODULE_LABEL}] delete OK — record gone`);
		await shot(page, '08-after-delete');
JS;

        // Soft-deleted rows use Eloquent's default global scope, which
        // excludes deleted_at-not-null rows automatically — only meaningful
        // to re-assert for modules that actually have that column (see
        // ModuleConfigContract::hasSoftDeletes(), THE single sanctioned
        // place to read this derived fact, rather than re-deriving it here).
        // Skipped when there's no createdRowText to search for (no create
        // step / no anchor field) — the testid-based check above already
        // covers those modules.
        if ($this->hasCreate && $this->pickAnchorField() !== null && ModuleConfigContract::hasSoftDeletes($this->config)) {
            $body .= <<<'JS'

		// Soft-deleted record: re-confirm by visible text too, not just by
		// testid — catches a regression where the testid disappears (e.g.
		// the row re-renders under a different uuid) but the row itself is
		// still listed.
		expect(await rowExists(page, createdRowText), `Soft-deleted row "${createdRowText}" still visible in the list`).toBe(false);
		console.log(`[${MODULE_LABEL}] soft-delete OK — row no longer appears in the list`);
JS;
        }

        return $reopen . $body;
    }

    // ------------------------------------------------------------------
    // _fixtures.js body builders
    // ------------------------------------------------------------------

    /**
     * Helper functions _fixtures.js needs, scoped to the specific field set
     * it fills (createFixtureRecord() only ever fills $this->createFields —
     * never edit fields) rather than reusing buildHelperFunctions()'s
     * whole-module gating, which would pull in edit-only helpers this file
     * never calls.
     */
    protected function buildFixtureHelperFunctions(): string
    {
        $blocks = [];

        if ($this->hasCreate) {
            $blocks[] = $this->fillFieldHelpersBlock();

            $hasRequiredSelect = false;
            $hasOptionalSelect = false;
            $hasNumberInput = false;
            foreach ($this->createFields as $field) {
                $fieldType = $field['field_type'] ?? 'input';
                if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
                    if (!empty($field['required'])) {
                        $hasRequiredSelect = true;
                    } else {
                        $hasOptionalSelect = true;
                    }
                }
                if ($fieldType === 'number-input') {
                    $hasNumberInput = true;
                }
            }

            if ($hasRequiredSelect) {
                $blocks[] = $this->selectFieldHelperBlock();
            }
            if ($hasOptionalSelect) {
                $blocks[] = $this->tryFillSelectFieldHelperBlock();
            }
            if ($hasNumberInput) {
                $blocks[] = $this->numberFieldHelperBlock();
            }
        } elseif ($this->hasDelete) {
            // cleanupRecord() still needs fillField() for the #confirm input
            // even when there's no create step to need it for.
            $blocks[] = $this->fillFieldHelpersBlock();
        }

        $blocks[] = $this->rowHelpersBlock();

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    /**
     * createFixtureRecord()'s body. Mirrors buildCreateBlock()'s happy path
     * only (see this class's docblock and fixtures.e2e.stub's own docblock
     * for why the two validation-negative sub-steps are deliberately
     * excluded here) and THROWS instead of logging+continuing when it can't
     * capture a uuid afterwards.
     *
     * When the module has no create feature at all, falls back to grabbing
     * whatever the first existing list row already is — mirrors
     * buildTargetRowBlock()'s same fallback in the CRUD file — since there's
     * no UI path to create one.
     */
    protected function buildFixtureCreateBody(): string
    {
        if (!$this->hasCreate) {
            return $this->buildFixturePickExistingRowBody();
        }

        $declBlock = $this->dedentBlock($this->buildFieldDeclarationsBlock($this->createFields));
        [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($this->createFields);
        $fillBlock = $this->dedentBlock(implode("\n", $fillLinesNonFile));
        $fileFillBlock = !empty($fillLinesFileOnly) ? "\n" . $this->dedentBlock(implode("\n", $fillLinesFileOnly)) : '';

        $open = <<<'JS'
	await clickAndWaitForSelector(
		page,
		() => page.locator('[data-testid="[[moduleName]]-create"]').click(),
		'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
	);
	await sleep(500); // let the dialog's focus-trap/animation settle before typing
JS;

        $submit = <<<'JS'

	await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
	await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
	await waitForListSettled(page);
JS;

        $anchor = $this->pickAnchorField();
        $targetRowExpr = $anchor !== null
            ? "rowLocator(page, String(createValues.{$anchor})).first()"
            : "page.locator('table tbody tr').first()";

        $captureTpl = <<<'JS'

	const recordTestId = await (__TARGET_ROW__)
		.locator('[data-testid^="[[moduleName]]-view-"]')
		.first()
		.getAttribute('data-testid')
		.catch(() => null);
	const recordUuid = uuidFromTestId(recordTestId, 'view');
	if (!recordUuid) {
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: could not capture a uuid after create`);
	}
	return { uuid: recordUuid };
JS;
        $capture = str_replace('__TARGET_ROW__', $targetRowExpr, $captureTpl);

        return $open . $declBlock . "\n\n" . $fillBlock . $fileFillBlock . $submit . $capture;
    }

    protected function buildFixturePickExistingRowBody(): string
    {
        return <<<'JS'
	const targetRow = page.locator('table tbody tr').first();
	const recordTestId = await targetRow
		.locator('[data-testid^="[[moduleName]]-view-"]')
		.first()
		.getAttribute('data-testid')
		.catch(() => null);
	const recordUuid = uuidFromTestId(recordTestId, 'view');
	if (!recordUuid) {
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: no existing record available to use as a fixture (module has no create feature and the list is empty)`);
	}
	return { uuid: recordUuid };
JS;
    }

    /**
     * cleanupRecord()'s body. Mirrors cleanupHelperBlock()'s
     * cleanupStrayRecord() logic (view -> More Actions -> Delete -> confirm
     * YES), but unconditional rather than failure-only — every
     * delegation/action spec calls this in its own `finally`, since (unlike
     * the CRUD spec) nothing else in those files ever deletes the fixture.
     *
     * A no-op, logged, when the module has no delete feature at all — the
     * same accepted "create without delete isn't self-cleaning" limitation
     * the CRUD spec already has (see this class's docblock).
     */
    protected function buildFixtureCleanupBody(): string
    {
        if (!$this->hasDelete) {
            return <<<'JS'
	// No delete feature on this module — nothing this fixture can clean up
	// via the UI. Matches [[moduleRoute]]-crud.e2e.js's own accepted
	// limitation: a create-without-delete module isn't self-cleaning either.
	console.log(`[${MODULE_LABEL}] cleanupRecord: module has no delete feature — leaving uuid ${uuid} as-is`);
JS;
        }

        return <<<'JS'
	try {
		if ((await page.locator('[role="dialog"]').count()) > 0) {
			await page.keyboard.press('Escape').catch(() => {});
			await sleep(500);
		}

		await page.goto(`${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});
		await waitForListSettled(page);

		const stillPresent = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count().catch(() => 0)) > 0;
		if (!stillPresent) {
			console.log(`[${MODULE_LABEL}] cleanupRecord: uuid ${uuid} no longer in the list — nothing to do`);
			return;
		}

		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`cleanup view button (data-testid="[[moduleName]]-view-${uuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${uuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await sleep(500);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		console.log(`[${MODULE_LABEL}] cleanupRecord: deleted fixture record (uuid ${uuid})`);
	} catch (cleanupErr) {
		console.log(
			`[${MODULE_LABEL}] WARNING: cleanupRecord failed for uuid ${uuid} — manual removal may be required:`,
			cleanupErr && cleanupErr.stack ? cleanupErr.stack : cleanupErr,
		);
	}
JS;
    }

    // ------------------------------------------------------------------
    // Split-spec (delegation/action) file rendering
    // ------------------------------------------------------------------

    /**
     * Fills tests/split.e2e.stub for one delegation or action spec.
     * $fieldsForHelpers scopes which of the fillField/select/number helpers
     * get emitted — empty for tab-delegation and action specs (neither fills
     * any field; see buildActionSpecBody()'s and buildTabDelegationSpecBody()'s
     * docblocks), the delegation's own create fields for a modal-type
     * delegation that fills them.
     */
    protected function renderSplitSpec(
        string $fileSlug,
        string $specKind,
        string $specLabel,
        string $testDescription,
        string $testBody,
        array $fieldsForHelpers
    ): string {
        $stub = $this->getTemplateContent('tests/split.e2e', 'frontend');
        $moduleRoute = Str::kebab($this->moduleName);

        $content = str_replace(
            ['[[helperFunctions]]', '[[testBody]]', '[[testDescription]]', '[[specFileName]]', '[[specKind]]', '[[specLabel]]', '[[specSlug]]'],
            [
                $this->splitSpecHelperFunctionsFor($fieldsForHelpers),
                $testBody,
                addcslashes($testDescription, "'\\"),
                "{$moduleRoute}-{$fileSlug}.e2e.js",
                $specKind,
                addcslashes($specLabel, "'\\"),
                $fileSlug,
            ],
            $stub
        );

        return $this->replacePlaceholders($content);
    }

    protected function splitSpecHelperFunctionsFor(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $blocks = [$this->fillFieldHelpersBlock()];

        $hasRequiredSelect = false;
        $hasOptionalSelect = false;
        $hasNumberInput = false;
        foreach ($fields as $field) {
            $fieldType = $field['field_type'] ?? 'input';
            if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
                if (!empty($field['required'])) {
                    $hasRequiredSelect = true;
                } else {
                    $hasOptionalSelect = true;
                }
            }
            if ($fieldType === 'number-input') {
                $hasNumberInput = true;
            }
        }

        if ($hasRequiredSelect) {
            $blocks[] = $this->selectFieldHelperBlock();
        }
        if ($hasOptionalSelect) {
            $blocks[] = $this->tryFillSelectFieldHelperBlock();
        }
        if ($hasNumberInput) {
            $blocks[] = $this->numberFieldHelperBlock();
        }

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    /**
     * A 'tab'/'tab-action' delegation's spec body — the exact per-key
     * coverage buildDelegationBlock() used to emit inline in the CRUD file
     * (nav to the tab route, assert the nav link resolves, assert the child
     * table renders real headers, probe the child create form), now
     * standalone: $recordUuid always exists (createFixtureRecord() throws
     * otherwise, so no `if (recordUuid)` guard is needed), and there's no
     * "return to list" step at the end since no further CRUD steps follow it
     * in this file — cleanupRecord() navigates wherever it needs to itself.
     *
     * Fills no fields — the child-create probe only needs to know whether
     * the create request fired and what FK value it carried, not what the
     * child form's own fields are.
     */
    protected function buildTabDelegationSpecBody(string $delegationKey, array $delegation, string $label): string
    {
        $tabId = Str::kebab($delegation['name'] ?? $delegationKey);
        $escapedLabel = addslashes($label);
        $filterKey = $delegation['filterKey'] ?? 'parent_id';

        return <<<JS
		// ── Delegation (tab): {$escapedLabel} ─────────────────────────────────
		await page.goto(`\${BASE_URL}/[[moduleRoute]]/\${recordUuid}/details/{$tabId}`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});

		// The tab must be reachable from the nav, not just by URL: assert the
		// generated link resolves to the route that actually exists.
		const {$this->jsIdent($tabId)}Tab = page.locator('[data-testid="[[moduleName]]-tab-{$tabId}"]');
		await expect({$this->jsIdent($tabId)}Tab, 'delegation tab "{$escapedLabel}" missing from the details nav').toHaveCount(1);
		expect(
			await {$this->jsIdent($tabId)}Tab.getAttribute('href'),
			'delegation tab "{$escapedLabel}" links to a path the router does not register'
		).toContain('/details/{$tabId}');

		// The child list renders inside the tab's router-view. Waiting for a
		// `table` alone is not enough: a delegation tab with no columns
		// renders a table shell with no header and no rows while the fetch
		// returns 200 — it looks like missing data and is not. Assert the
		// header row, and that no label leaked as a raw i18n key.
		const childTable = page.locator('table').first();
		await childTable.waitFor({ timeout: 15000 });
		const childHeaders = (await childTable.locator('thead th').allTextContents())
			.map((h) => h.trim())
			.filter(Boolean);

		expect(
			childHeaders.length,
			'delegation "{$escapedLabel}" rendered a table with no column headers — the tab generated an empty columns array'
		).toBeGreaterThan(0);
		expect(
			childHeaders.filter((h) => /^[a-z0-9-]+\.[a-z0-9_]+\$/.test(h)),
			'delegation "{$escapedLabel}" column headers are raw i18n keys — the label namespace does not resolve'
		).toEqual([]);

		await shot(page, 'delegation-{$tabId}');
		console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" tab rendered — headers: \${childHeaders.join(', ')}`);

		// ── Child CREATE ────────────────────────────────────────────────
		// Only the child LIST was ever exercised, so the create path could
		// break silently. Opening the form and submitting is what catches it.
		const addChild = page.getByRole('button', { name: /^Add / }).first();
		if ((await addChild.count()) > 0) {
			// The create request is intercepted and answered with a synthetic
			// success rather than allowed through — a real child row here
			// would break this module's OWN cleanupRecord() delete via
			// "Cannot delete — active relationships found". It is FULFILLED,
			// not aborted: aborting raises a `requestfailed` event, which
			// this spec's own diagnostics gate treats as a failure. Either
			// way the payload is captured, which is the point.
			let submitted = null;
			await page.route('**/{$tabId}/create', async (route) => {
				submitted = route.request().postData() ?? '';
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ status: true, code: 200, message: 'intercepted by e2e', data: {} }),
				});
			});

			await addChild.click();
			await page.locator('[role="dialog"]').last().waitFor({ timeout: 10000 });
			await sleep(1000);
			await shot(page, 'delegation-{$tabId}-create-form');

			const submit = page.locator('[role="dialog"]').last().getByRole('button', { name: /^(Create|Save|Submit)/ }).first();
			if ((await submit.count()) > 0) {
				await submit.click();
				await sleep(1500);
			}
			await page.unroute('**/{$tabId}/create');

			if (submitted !== null) {
				// The FK must carry the parent's integer id, not its uuid.
				// Forms post multipart/form-data (they may carry an upload), so
				// the FK appears as a Content-Disposition part, not JSON. Both
				// shapes are accepted so this keeps working if that changes.
				const jsonMatch = submitted.match(/"{$filterKey}"\\s*:\\s*"?([^",}]*)"?/);
				const formMatch = submitted.match(/name="{$filterKey}"[\\s\\S]*?\\r?\\n\\r?\\n([^\\r\\n]*)/);
				const fkValue = (jsonMatch?.[1] ?? formMatch?.[1] ?? '').trim();

				expect(
					fkValue !== '',
					`delegation "{$escapedLabel}" create sent no {$filterKey} at all — the parent FK default is missing`
				).toBeTruthy();
				expect(
					/^\\d+\$/.test(fkValue),
					`delegation "{$escapedLabel}" create sent {$filterKey}=\${fkValue} — expected the parent's integer id, not its uuid`
				).toBeTruthy();
				console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" create sends {$filterKey}=\${fkValue} (parent id)`);
			} else {
				console.log(`[\${MODULE_LABEL}] WARNING: delegation "{$escapedLabel}" create was never submitted — FK payload unverified`);
			}

			// Leave nothing open: a lingering dialog swallows the clicks of
			// cleanupRecord()'s own view/delete steps after this test body.
			for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
				await page.keyboard.press('Escape');
				await sleep(700);
			}
		} else {
			console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" has no create action enabled — skipping child create`);
		}
JS;
    }

    /**
     * A 'modal' (header-action) delegation's spec body — net-new coverage,
     * none existed before this refactor (buildDelegationBlock() only ever
     * handled 'tab'/'tab-action'). The trigger only renders on the parent's
     * standalone details PAGE (ViewLayoutGenerator::generateHeaderActions()),
     * never inside the list-row view modal, so this navigates straight
     * there rather than opening the view modal first.
     *
     * The trigger button has no data-testid (unlike an Action's — see
     * ViewModalGenerator::renderMainButton()/renderMoreItem()) — located by
     * its visible label instead, the same fallback this generator already
     * uses elsewhere for untestid'd buttons (e.g. the tab-delegation child
     * "Add " button and "More Actions" above).
     *
     * When the delegation's create operation is enabled and declares create
     * fields, fills and submits them (happy path only) as a real create
     * probe; otherwise this is a shallower open-and-assert-rendered smoke
     * test, matching this generator's already-established "action files are
     * shallower than delegation files" precedent for surfaces with no
     * fields to introspect.
     */
    protected function buildModalDelegationSpecBody(string $delegationKey, array $delegation, string $label): string
    {
        $kebab = Str::kebab($delegation['name'] ?? $delegationKey);
        $escapedLabel = addcslashes($label, "'\\");

        $createOp = $delegation['operations']['create'] ?? [];
        $createFields = !empty($createOp['enabled']) ? ($createOp['frontend']['fields'] ?? []) : [];

        if (!empty($createFields)) {
            $declBlock = $this->buildFieldDeclarationsBlock($createFields, 'delegationValues');
            [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($createFields, 'delegationValues');
            $fillBlock = implode("\n", $fillLinesNonFile);
            $fileFillBlock = !empty($fillLinesFileOnly) ? "\n" . implode("\n", $fillLinesFileOnly) : '';

            $probeTpl = <<<'JS'

		const delegationSubmit = delegationDialog.locator('button[type="submit"]');
		if ((await delegationSubmit.count()) > 0) {
			await delegationSubmit.click();
			await page
				.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeDialogCount, { timeout: 15000 })
				.catch(() => {});
			console.log(`[${MODULE_LABEL}] delegation "__LABEL__" create submitted`);
		} else {
			console.log(`[${MODULE_LABEL}] delegation "__LABEL__" has no visible submit control — skipping create probe`);
		}
JS;
            $fillSection = $declBlock . "\n\n" . $fillBlock . $fileFillBlock . str_replace('__LABEL__', $escapedLabel, $probeTpl);
        } else {
            $fillSection = str_replace(
                '__LABEL__',
                $escapedLabel,
                <<<'JS'
		console.log(`[${MODULE_LABEL}] delegation "__LABEL__" has no create operation enabled — smoke test only opened and rendered the modal`);
JS
            );
        }

        $tpl = <<<'JS'
		// ── Delegation (modal): __LABEL__ ─────────────────────────────────────
		await page.goto(`${BASE_URL}/[[moduleRoute]]/${recordUuid}/details`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});
		await sleep(800);

		const beforeDialogCount = await page.locator('[role="dialog"]').count();
		const delegationTrigger = page.getByRole('button', { name: '__LABEL__' });
		await expect(delegationTrigger, 'delegation trigger button "__LABEL__" not found on the details page').toHaveCount(1);
		await delegationTrigger.click();
		await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeDialogCount, { timeout: 8000 });
		await sleep(500);

		const delegationDialog = page.locator('[role="dialog"]').last();
		expect(
			(await delegationDialog.innerText()).trim().length,
			'delegation modal "__LABEL__" opened but rendered nothing',
		).toBeGreaterThan(0);
		await shot(page, 'delegation-__KEBAB__-modal');
		console.log(`[${MODULE_LABEL}] delegation "__LABEL__" modal opened`);
__FILL__

		// Leave nothing open before cleanupRecord() runs.
		for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > beforeDialogCount; i++) {
			await page.keyboard.press('Escape');
			await sleep(700);
		}
JS;

        return str_replace(['__LABEL__', '__KEBAB__', '__FILL__'], [$escapedLabel, $kebab, "\n" . $fillSection], $tpl);
    }

    /**
     * An action's spec body — net-new coverage, PHPUnit's contract tests
     * aside this had none before (only the first enabled action ever got
     * PHPUnit coverage, and Playwright had none at all). Deliberately
     * shallower than a delegation spec: ActionComponentGenerator's
     * features/action/form.stub has no field placeholders to introspect (see
     * that stub — "Add your form fields here" is left for a human to fill
     * in), so this can only mechanically assert trigger-exists -> click ->
     * submit -> success, locating Submit as "the form's last button" (Cancel
     * is always first in the generated stub) since the button's own label is
     * a runtime-computed loading-state string, not a stable literal.
     *
     * $action['placement'] (default 'more', mirroring ActionConfigNormalizer)
     * decides whether the trigger sits in the view modal's primary row or
     * behind "More Actions" — same placement logic ViewModalGenerator uses
     * to decide where to render it.
     */
    protected function buildActionSpecBody(string $actionKey, array $action, string $label): string
    {
        $kebab = Str::kebab($action['name'] ?? $actionKey);
        $escapedLabel = addslashes($label);
        $uiType = $action['uiType'] ?? 'modal';
        $isMain = ($action['placement'] ?? 'more') === 'main';

        $openMore = $isMain ? '' : <<<'JS'

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
JS;

        $open = <<<JS
		// ── Action: {$escapedLabel} ────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-\${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button not found ahead of action "{$escapedLabel}"`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);
{$openMore}
		const actionTrigger = page.locator(`[data-testid="[[moduleName]]-action-{$kebab}-\${recordUuid}"]`);
		await expect(actionTrigger, 'action trigger "{$escapedLabel}" not found').toHaveCount(1);
JS;

        if ($uiType === 'page') {
            $tpl = <<<'JS'

		await actionTrigger.click();
		await page.waitForFunction(() => !!document.querySelector('form button[type="button"]'), { timeout: 15000 });
		await shot(page, 'action-__KEBAB__-form');

		const formButtons = page.locator('form button');
		await expect(formButtons, 'action "__LABEL__" form rendered no buttons at all — expected at least Cancel + Submit').not.toHaveCount(0);
		const submitBtn = formButtons.last();
		await submitBtn.click();

		// A successful submit navigates away from the action's own route —
		// a failed one (toast.error, form stays put) is a real, unmasked
		// failure here, not swallowed, since actions are the newest surface
		// and least likely to already have a hand-tuned happy-path fixture.
		await page.waitForFunction(
			(kebab) => !window.location.pathname.includes(`/${kebab}`),
			'__KEBAB__',
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] action "__LABEL__" submitted and navigated away — treated as success`);
JS;
        } else {
            $tpl = <<<'JS'

		const beforeActionDialogCount = await page.locator('[role="dialog"]').count();
		await actionTrigger.click();
		await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeActionDialogCount, { timeout: 8000 });
		await sleep(500);
		await shot(page, 'action-__KEBAB__-form');

		const actionDialog = page.locator('[role="dialog"]').last();
		const formButtons = actionDialog.locator('button');
		await expect(formButtons, 'action "__LABEL__" modal rendered no buttons at all — expected at least Cancel + Submit').not.toHaveCount(0);
		const submitBtn = formButtons.last();
		await submitBtn.click();

		// handleSubmit() only closes the dialog on a successful response —
		// see features/action/form.stub — so the dialog closing IS the
		// success signal, same convention buildEditBlock()/buildCreateBlock()
		// already rely on for the CRUD spec.
		await page.waitForFunction(
			(n) => document.querySelectorAll('[role="dialog"]').length <= n,
			beforeActionDialogCount,
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] action "__LABEL__" submitted and its dialog closed — treated as success`);

		for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
			await page.keyboard.press('Escape');
			await sleep(700);
		}
JS;
        }

        return $open . str_replace(['__LABEL__', '__KEBAB__'], [$escapedLabel, $kebab], $tpl);
    }
}
