<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for PlaywrightTestGenerator (v2.10.11), run against
 * a scratch PathManager project root (mirrors MenusJsonGeneratorTest's
 * harness: PathManager::setProjectRoot() in setUp, reset+recursive cleanup in
 * tearDown) using the REAL persisted module.json for SYSTEM_SHELL's
 * LocationTypes module — copied verbatim into
 * tests/Fixtures/LocationTypesModule.json (see that file) rather than a
 * hand-rolled config array.
 *
 * LocationTypes has every frontend CRUD feature enabled (create/view/edit/
 * delete) with three plain-text fields (name/code/color), primaryField
 * "name", and a matching backend.list.filterFields[0] of "name" — so
 * pickAnchorField()/pickTextFilterField() resolve to "name" and the Filter
 * block takes the "Variant A: plain text field" path, and pickEditField()
 * resolves to "code" (first non-anchor scalar edit field). The "full" test
 * below expects every one of those resolved blocks. A second test flips
 * features.frontend.delete (and its backend counterpart, inert here but
 * toggled for parity with PhpUnitTestGeneratorTest's equivalent case) off
 * and confirms the entire Delete step — and its cleanup helper — disappears,
 * while create/view/edit/filter remain.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator
 */
class PlaywrightTestGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-playwright-testgen-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function locationTypesConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        return $config;
    }

    /**
     * $suffix picks which split file to read — 'create' (default, the most
     * common case in this file's tests), 'list', 'view', 'edit', or 'delete'.
     */
    private function generatedFilePath(string $suffix = 'create'): string
    {
        return PathManager::getFrontendModulePath('Core', 'LocationTypes') . "/e2e/location-types-{$suffix}.e2e.js";
    }

    public function test_generate_writes_full_e2e_spec_for_a_module_with_every_feature_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents($this->generatedFilePath('create'));
        $list = (string) file_get_contents($this->generatedFilePath('list'));
        $view = (string) file_get_contents($this->generatedFilePath('view'));
        $edit = (string) file_get_contents($this->generatedFilePath('edit'));
        $delete = (string) file_get_contents($this->generatedFilePath('delete'));
        $fixtures = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'LocationTypes') . '/e2e/_fixtures.js'
        );

        // Imports from the shared e2e helper files, via the fixed `#e2e-helpers/*`
        // subpath import map (SYSTEM_SHELL/FRONTEND/package.json "imports") rather
        // than a relative './helpers/...' path — the generated spec now lives
        // inside the module's own tree, at a nesting depth relative imports can't
        // reliably reach. Checked on the create file (no _fixtures.js import
        // there) and the list file (has both filters.js and _fixtures.js).
        $this->assertStringContainsString("from '#e2e-helpers/fixtures.js'", $create);
        $this->assertStringContainsString("from '#e2e-helpers/auth.js'", $create);
        $this->assertStringContainsString("from '#e2e-helpers/config.js'", $create);
        $this->assertStringContainsString("from '#e2e-helpers/filters.js'", $list);
        $this->assertStringContainsString("from './_fixtures.js'", $list);

        // test.describe / test( scaffold, one per split file.
        $this->assertStringContainsString("test.describe('location-types / create', () => {", $create);
        $this->assertStringContainsString("test('create flow (auto-generated)'", $create);
        // list/view/edit/delete go through renderSplitSpec()'s $specLabel
        // (capitalized, e.g. 'List'), unlike create's own hardcoded stub text.
        $this->assertStringContainsString("test.describe('location-types / List', () => {", $list);
        $this->assertStringContainsString("test.describe('location-types / View', () => {", $view);
        $this->assertStringContainsString("test.describe('location-types / Edit', () => {", $edit);
        $this->assertStringContainsString("test.describe('location-types / Delete', () => {", $delete);

        // ── Create block (create.e2e.js) ─────────────────────────────────
        $this->assertStringContainsString('data-testid="locationtypes-create"', $create);
        $this->assertStringContainsString('[data-testid="locationtypes-submit"]', $create);
        $this->assertStringContainsString('E2E LocationTypes Name ${stamp}', $create);
        $this->assertStringContainsString('E2E LocationTypes Code ${stamp}', $create);
        $this->assertStringContainsString('E2E LocationTypes Color ${stamp}', $create);
        $this->assertStringContainsString("fillField(page, '[role=\"dialog\"] #name', createValues.name)", $create);
        // hasDelete -> create.e2e.js also cleans up what it created.
        $this->assertStringContainsString('await cleanupRecord(page, recordUuid);', $create);

        // ── Filter block (list.e2e.js — always forceVariantB, reads the
        // fixture row's own current cell value, never createdRowText) ──────
        $this->assertStringContainsString('Variant B: visible column', $list);
        $this->assertStringContainsString("getRowColumnValue(page, targetRow, 'Name')", $list);
        $this->assertStringNotContainsString('Variant A:', $list);
        $this->assertStringNotContainsString('createdRowText', $list);

        // ── View block (view.e2e.js) ─────────────────────────────────────
        $this->assertStringContainsString('locationtypes-view-${recordUuid}', $view);
        $this->assertStringContainsString('view OK — modal opened for the target record', $view);
        // Standalone view spec always closes its own dialog.
        $this->assertStringContainsString("getByRole('button', { name: 'Close' })", $view);

        // ── Edit block (edit.e2e.js — first non-anchor scalar edit field: "code") ──
        $this->assertStringContainsString('locationtypes-edit-${recordUuid}', $edit);
        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #code'", $edit);
        $this->assertStringContainsString('E2E LocationTypes Code EDIT ${stamp}', $edit);

        // ── Delete block (delete.e2e.js) ─────────────────────────────────
        $this->assertStringContainsString("name: 'More Actions'", $delete);
        $this->assertStringContainsString('locationtypes-delete-${recordUuid}', $delete);
        $this->assertStringContainsString('[data-testid="locationtypes-confirm-delete"]', $delete);

        // Helper functions gated by feature/field-type flags. rowLocator/
        // uuidFromTestId now live only in _fixtures.js's own helper set for
        // these split files (create.e2e.js still needs them itself, since it
        // derives its own targetRow/uuid to hand to cleanupRecord()).
        $this->assertStringContainsString('async function fillField(page, selector, value)', $create);
        $this->assertStringContainsString('function rowLocator(page, text)', $create);
        $this->assertStringContainsString('function uuidFromTestId(testId, action)', $fixtures);
        // cleanupStrayRecord() no longer exists anywhere -- every split file's
        // teardown goes through _fixtures.js's cleanupRecord() instead.
        $this->assertStringNotContainsString('async function cleanupStrayRecord(', $create);
        $this->assertStringNotContainsString('async function cleanupStrayRecord(', $list);
        // No select-type field anywhere in LocationTypes -> select helper must be absent.
        $this->assertStringNotContainsString('async function fillSelectField(', $create);
    }

    /**
     * pickEditField() must not pick a field that's hidden from the list
     * table via defaultVisible: false, since the edit block's own
     * row.textContent.includes(editedValue) verification can only ever
     * pass for a field actually rendered as a <td>. Baseline LocationTypes
     * (see the class docblock) picks "code" as the first non-anchor scalar
     * edit field; hiding "code" from the list here must shift the pick to
     * "color", the next available list-visible scalar field, not silently
     * keep editing "code" and produce an unverifiable row check. Found live
     * against a real 31-module display-config pass on the Retail ERP
     * project: 5 modules timed out for exactly this reason before the fix.
     */
    public function test_edit_field_picker_skips_a_field_hidden_from_the_list_via_defaultvisible(): void
    {
        $config = $this->locationTypesConfig();
        foreach ($config['features']['frontend']['list']['fields'] as &$field) {
            if ($field['key'] === 'code') {
                $field['defaultVisible'] = false;
            }
        }
        unset($field);

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('edit'));

        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #color'", $content);
        $this->assertStringContainsString('E2E LocationTypes Color EDIT ${stamp}', $content);
        $this->assertStringNotContainsString("setInputValue(page, '[role=\"dialog\"] #code'", $content);

        // "color" is list-visible, so the row-content verification must
        // still be the full waitForFunction check, not the lenient fallback.
        $this->assertStringContainsString('edit OK — record now shows the updated color value', $content);
        $this->assertStringNotContainsString('not row-verified', $content);
    }

    /**
     * When EVERY scalar edit field is list-hidden, pickEditField() still has
     * to pick something rather than skip editing entirely (falls through to
     * "code", the first non-anchor scalar field) — but the generated edit
     * block must skip the row-content waitForFunction in that case, since it
     * can never pass for a field that isn't rendered as a <td> at all, and
     * fall back to the same lenient dialog-closed-only verification the
     * non-scalar field branch already uses.
     */
    public function test_edit_field_row_check_is_skipped_when_every_scalar_edit_field_is_list_hidden(): void
    {
        $config = $this->locationTypesConfig();
        foreach ($config['features']['frontend']['list']['fields'] as &$field) {
            if (in_array($field['key'], ['code', 'color'], true)) {
                $field['defaultVisible'] = false;
            }
        }
        unset($field);

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('edit'));

        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #code'", $content);
        $this->assertStringContainsString('edit OK — submitted an updated code value (list-hidden field, not row-verified)', $content);
        // The row-content waitForFunction (keyed on the view button testid)
        // must be absent from the edit block for this module.
        $this->assertStringNotContainsString('edit OK — record now shows the updated code value', $content);
    }

    public function test_generate_omits_delete_step_when_delete_feature_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['frontend']['delete'] = false;
        $config['features']['backend']['delete'] = false; // inert for this generator; toggled for parity

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        // With hasDelete false, writeDeleteSpecFile() never runs at all --
        // no file, not "a file with the delete content stripped out".
        $this->assertFileDoesNotExist($this->generatedFilePath('delete'));

        // Create/View/Edit must all remain untouched, each in its own file.
        $create = (string) file_get_contents($this->generatedFilePath('create'));
        $view = (string) file_get_contents($this->generatedFilePath('view'));
        $edit = (string) file_get_contents($this->generatedFilePath('edit'));

        $this->assertStringContainsString('data-testid="locationtypes-create"', $create);
        // hasDelete false -> create.e2e.js has nothing to clean up afterward.
        $this->assertStringNotContainsString('cleanupRecord(page, recordUuid)', $create);
        $this->assertStringContainsString('locationtypes-view-${recordUuid}', $view);
        $this->assertStringContainsString('locationtypes-edit-${recordUuid}', $edit);
    }

    /**
     * Regression test for a real generated-and-run failure, found live running the
     * generated suite for the first time in a real project (2026-08-20): every CRUD
     * spec's create step asserted `!document.querySelector('[role="dialog"]')` after
     * clicking submit -- but CrudListPanel.vue's onCreated() (v3.2.2, "create -> view
     * by default") opens the new record's own View dialog immediately when a View
     * component is wired, so a dialog is ALWAYS still present and that assertion can
     * never pass. Confirmed against a real MySQL row: the create request succeeds
     * (the record is actually persisted, timestamped exactly when the test ran) but
     * the test times out waiting for a dialog count of zero that never occurs. Fixed
     * by asserting a dialog *remains* present (now the View one) and closing it via its
     * own Close button before continuing, gated on $this->hasView to match onCreated()'s
     * own `props.viewComponent && payload?.[props.rowKey]` guard exactly.
     *
     * v3.4.2 correction: the original fix closed the View dialog via
     * page.keyboard.press('Escape'), which passed this same unit test (it only checks the
     * generated code, not a live browser) but reproducibly hung for 15s on every module
     * when actually run -- AppDialog.vue's `persistent` prop defaults to true and the View
     * dialog usage never overrides it, so Escape is captured and preventDefault()'d, and
     * the dialog never closes. Switched to clicking the dialog's real Close button (reka-ui's
     * DialogClose, accessible name always literally "Close"), confirmed against a live
     * Playwright run this time, not just the generated text.
     */
    public function test_create_step_expects_the_view_dialog_to_open_when_view_is_enabled(): void
    {
        $config = $this->locationTypesConfig(); // has view enabled

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('create'));

        $this->assertStringContainsString(
            "Expected the View dialog to open automatically after a successful create",
            $content
        );
        $this->assertStringContainsString(
            "await page.locator('[role=\"dialog\"]').getByRole('button', { name: 'Close' }).click();",
            $content
        );
        // The stale, always-failing assertion must be gone from the create step.
        $this->assertStringNotContainsString(
            "await page.locator('[role=\"dialog\"] [data-testid=\"locationtypes-submit\"]').click();\n\n\t\tawait page.waitForFunction(() => !document.querySelector('[role=\"dialog\"]'), { timeout: 15000 });\n\t\tawait waitForListSettled(page);",
            $content
        );
    }

    public function test_create_step_expects_no_dialog_when_view_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['frontend']['view'] = false;
        $config['features']['backend']['view'] = false;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('create'));

        // onCreated() itself never opens a View when none is wired -- the original,
        // simpler assertion is the correct one for a view-disabled module.
        $this->assertStringNotContainsString('Expected the View dialog to open automatically', $content);
        $this->assertStringContainsString(
            "await page.locator('[role=\"dialog\"] [data-testid=\"locationtypes-submit\"]').click();\n\n\t\tawait page.waitForFunction(() => !document.querySelector('[role=\"dialog\"]'), { timeout: 15000 });\n\t\tawait waitForListSettled(page);",
            $content
        );
    }

    /**
     * Regression test for a real generated-and-run failure (2026-08-23): a
     * read-only module (view enabled, edit disabled) opens the View dialog,
     * logs "view OK", then falls straight into whatever runs next with the
     * dialog still open, since buildViewBlock() itself never closed it.
     *
     * Fixed: the View block closes its own dialog via the Close button (NOT
     * Escape -- AppDialog.vue defaults `persistent: true`, so Escape is
     * captured and preventDefault()'d, same class of bug buildCreateBlock()
     * already hit and fixed for this identical dialog).
     *
     * Post-split: view.e2e.js is now a standalone file with no Edit step in
     * it at all -- buildViewBlock(false) always closes its dialog
     * unconditionally, regardless of whether the MODULE has edit enabled
     * (that's edit.e2e.js's own concern, covered by the sibling test below).
     */
    public function test_view_step_closes_its_dialog_when_no_edit_step_follows(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('view'));

        $viewOkPos = strpos($content, 'view OK — modal opened for the target record');
        $this->assertNotFalse($viewOkPos, 'Expected the "view OK" log line to be present.');

        $closePos = strpos(
            $content,
            "await page.locator('[role=\"dialog\"]').getByRole('button', { name: 'Close' }).click();",
            $viewOkPos
        );
        $this->assertNotFalse($closePos, 'View step must close its dialog via the Close button.');

        $this->assertLessThan(400, $closePos - $viewOkPos, 'The Close-button click is not immediately after the View step\'s own log line.');

        $afterClose = substr($content, $closePos, 400);
        $this->assertStringContainsString(
            "await page.waitForFunction(() => !document.querySelector('[role=\"dialog\"]'), { timeout: 15000 });",
            $afterClose
        );
        $this->assertStringContainsString('await waitForListSettled(page);', $afterClose);

        // view.e2e.js never contains an Edit button click -- that lives in edit.e2e.js.
        $this->assertStringNotContainsString('locationtypes-edit-${recordUuid}', $content);
    }

    /**
     * Regression guard, post-split: edit.e2e.js opens the view modal itself
     * (buildViewBlock(true) — Edit's own button lives inside it) and must
     * leave it open going into the edit block, never closing it first.
     */
    public function test_view_step_leaves_dialog_open_when_edit_step_follows(): void
    {
        $config = $this->locationTypesConfig(); // has edit enabled

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('edit'));

        $viewOkPos = strpos($content, 'view OK — modal opened for the target record');
        $this->assertNotFalse($viewOkPos);

        // Everything between "view OK" and the Edit step's own button click
        // must NOT contain a Close-button click -- the dialog stays open.
        $editBtnPos = strpos($content, 'locationtypes-edit-${recordUuid}', $viewOkPos);
        $this->assertNotFalse($editBtnPos);

        $between = substr($content, $viewOkPos, $editBtnPos - $viewOkPos);
        $this->assertStringNotContainsString("getByRole('button', { name: 'Close' })", $between);
    }

    /**
     * Regression test for a real generated-and-run failure: a module with a
     * foreign-key/relation create field — the exact shape
     * IntrospectionToConfig::buildFrontendFields() actually emits for FK
     * columns, `field_type: 'api-select'` (confirmed against the real
     * persisted Items/module.json: item_type_id, item_category_id both come
     * back as 'api-select', never 'select'). Every field_type check in this
     * generator previously tested for the literal 'select' only, so an
     * 'api-select' field fell through to the plain-input fillField() path —
     * which fails outright, since ApiSelect2Field.vue never puts the `id`
     * prop on an actual DOM element (only on its `<label for>`). Playwright
     * confirmed this live: `fillField(page, '[role="dialog"] #item_type_id',
     * ...)` timed out because no such element exists. Fixed by adding
     * 'api-select' to PlaywrightTestGenerator::SELECT_FIELD_TYPES alongside
     * 'select', used everywhere a field_type is dispatched on.
     */
    public function test_api_select_foreign_key_field_uses_the_select_helper_not_plain_fill(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            [
                                'field' => 'item_type_id',
                                'label' => 'Item Type',
                                'field_type' => 'api-select',
                                'type' => 'text',
                                'required' => true,
                                'api_url' => '/select/item-types',
                                'option_label' => 'name',
                                'option_value' => 'id',
                            ],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            [
                                'field' => 'item_type_id',
                                'label' => 'Item Type',
                                'field_type' => 'api-select',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-create.e2e.js');
        $edit = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-edit.e2e.js');

        // The select helper must be emitted (an 'api-select' field is present)...
        $this->assertStringContainsString('async function fillSelectField(', $create);
        // ...and actually used to fill the FK field, by its label, in the create block.
        $this->assertStringContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Item Type')", $create);

        // The FK field must NEVER be handed to the plain-input filler.
        $this->assertStringNotContainsString('#item_type_id', $create);
        $this->assertStringNotContainsString('#item_type_id', $edit);

        // pickEditField() must skip the FK field too (isScalarField() must
        // treat 'api-select' as non-scalar). "name" is both the anchor field
        // and the only scalar edit field declared, so with item_type_id
        // correctly excluded, pickEditField()'s second (anchor-inclusive)
        // fallback loop picks "name" — never the FK — confirming
        // isScalarField('api-select') now returns false rather than
        // silently letting the edit test try to fillField() the FK.
        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #name'", $edit);
    }

    /**
     * Regression test for a real generated-and-run failure, found live running
     * the generated suite against a real project (2026-08-20): fillSelectField()'s
     * label-matching used `page.locator('label', { hasText: labelText })`, which
     * Playwright treats as a case-insensitive SUBSTRING match for a plain string
     * — so a module with both a "Status" field and any other field whose OWN
     * label merely CONTAINS "Status" (e.g. "Payment Status") hit a Playwright
     * strict-mode violation (locator resolved to 2 elements) every single time,
     * on every one of that module's e2e specs. Confirmed against a real project:
     * PurchaseOrders' create form has exactly this shape (`status_id` labeled
     * "Status", `payment_status` labeled "Payment Status"). Fixed by anchoring
     * the match — label.textContent() is always "labelText " or "labelText *"
     * (trailing space / required-asterisk baked into the shared Field.vue label
     * template's compiled output, confirmed by reading the real component), so
     * `^labelText\s*\*?\s*$` matches exactly the intended field and nothing
     * whose label happens to end with the same word.
     */
    public function test_select_field_label_matching_is_exact_not_a_substring(): void
    {
        $config = [
            'table_name' => 'purchase_orders',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'po_number', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'po_number'],
                    'create' => [
                        'fields' => [
                            ['field' => 'po_number', 'label' => 'Po Number', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'status_id', 'label' => 'Status', 'field_type' => 'api-select', 'type' => 'text', 'required' => true],
                            ['field' => 'payment_status', 'label' => 'Payment Status', 'field_type' => 'select', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('PurchaseOrders', 'System', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('System', 'PurchaseOrders') . '/e2e/purchase-orders-create.e2e.js');

        // Both fields are actually exercised, each under its own full label.
        $this->assertStringContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Status')", $content);
        $this->assertStringContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Payment Status')", $content);

        // The helper itself anchors the match -- the stale substring-match
        // shape must be gone from every label-locating helper in this file.
        $this->assertStringContainsString(
            "hasText: new RegExp('^' + labelText.replace(/[.*+?^\${}()|[\\]\\\\]/g, '\\\\\$&') + '\\\\s*\\\\*?\\\\s*\$')",
            $content
        );
        $this->assertStringNotContainsString("hasText: labelText }", $content);
    }

    /**
     * Regression test for a second, related real failure found immediately
     * after the fix above: a REQUIRED api-select field (Items.item_type_id)
     * correctly used fillSelectField(), but a self-referential, OPTIONAL
     * api-select field (ItemCategories.parent_id, `"required": false` in the
     * real persisted module.json — matching the backend's own `nullable`
     * validation rule) still unconditionally called fillSelectField() too.
     * Run for real: it threw "no selectable options found for Parent —
     * check seed data", because on a freshly seeded item_categories table
     * there is categorically no existing row yet to pick as a parent.
     * Since the field is genuinely optional, the fix (still true today) is
     * to never FORCE a selection for it — but leaving it permanently
     * untouched meant the optional-picker path was never exercised at all.
     * Superseded by the never-throwing tryFillSelectField() helper (added
     * for gap 5 of the e2e-coverage expansion): the optional field is now a
     * best-effort attempt — set when a selectable option exists, left
     * unset when it doesn't — never the throwing fillSelectField().
     */
    public function test_optional_relation_field_uses_the_best_effort_helper_not_the_throwing_one(): void
    {
        $config = [
            'table_name' => 'item_categories',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            [
                                'field' => 'parent_id',
                                'label' => 'Parent',
                                'field_type' => 'api-select',
                                'type' => 'text',
                                'required' => false,
                                'api_url' => '/select/item-categories',
                            ],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemCategories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'ItemCategories') . '/e2e/item-categories-create.e2e.js'
        );

        // Optional relation field must never be forced through the throwing helper...
        $this->assertStringNotContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Parent')", $content);
        $this->assertStringNotContainsString('async function fillSelectField(', $content);
        // ...but IS attempted, best-effort, through the never-throwing one.
        $this->assertStringContainsString('async function tryFillSelectField(', $content);
        $this->assertStringContainsString("tryFillSelectField(page, '[role=\"dialog\"]', 'Parent')", $content);
        // ...and the required plain field must still be filled normally.
        $this->assertStringContainsString('#name', $content);
    }

    /**
     * A required field whose OWN column the schema marks unique is a true 1:1 relation:
     * fillSelectField()'s "take option[0]" is fine for an ordinary many-to-one FK but collides
     * once anything has used that record (external report, 2026-08-23). This used to be flagged
     * with a comment and left unfixed; the super-suite fixture's full Playwright lane showed the
     * cost (every spec after the first 422'd), so the create submit now retries with the next
     * option whenever the server answers 422 on that field -- a retry rather than a lookup,
     * because a soft-deleted row holds the value yet appears in no list endpoint.
     */
    public function test_unique_required_relation_field_retries_with_the_next_option_when_the_server_says_taken(): void
    {
        $config = [
            'table_name' => 'profiles',
            'columns' => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'user_id', 'type' => 'foreignId', 'unique' => true],
                ['name' => 'role_id', 'type' => 'foreignId', 'unique' => false],
            ],
            'features' => [
                'backend' => ['list' => true, 'create' => true, 'view' => true, 'edit' => false, 'delete' => true],
                'frontend' => [
                    'list' => ['primaryField' => 'user_id'],
                    'create' => [
                        'fields' => [
                            ['field' => 'user_id', 'label' => 'User', 'field_type' => 'api-select', 'type' => 'text', 'required' => true, 'api_url' => '/select/users'],
                            ['field' => 'role_id', 'label' => 'Role', 'field_type' => 'api-select', 'type' => 'text', 'required' => true, 'api_url' => '/select/roles'],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Profiles', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Profiles') . '/e2e/profiles-create.e2e.js');

        // The unique FK (user_id) is explained where it is filled...
        $userIdPos = strpos($content, "fillSelectField(page, '[role=\"dialog\"]', 'User')");
        $this->assertNotFalse($userIdPos, "Expected a fillSelectField() call for 'User'.");
        $this->assertStringContainsString(
            "'User' is required AND UNIQUE (a true 1:1",
            substr($content, max(0, $userIdPos - 400), 400)
        );

        // ...the ordinary (non-unique) required FK (role_id) is not.
        $roleIdPos = strpos($content, "fillSelectField(page, '[role=\"dialog\"]', 'Role')");
        $this->assertNotFalse($roleIdPos, "Expected a fillSelectField() call for 'Role'.");
        $userCallEnd = $userIdPos + strlen("fillSelectField(page, '[role=\"dialog\"]', 'User')");
        $this->assertStringNotContainsString(
            'is required AND UNIQUE',
            substr($content, $userCallEnd, $roleIdPos - $userCallEnd)
        );

        // The submit retries on a 422 naming the unique field, and only that one.
        foreach (['profiles-create.e2e.js', '_fixtures.js'] as $file) {
            $body = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Profiles') . '/e2e/' . $file);
            $this->assertStringContainsString("const uniquePicks = { \"user_id\": { label: 'User', index: 0 } };", $body, $file);
            $this->assertStringNotContainsString('role_id": {', $body, $file);
            $this->assertStringContainsString('{ index: uniquePicks[takenKey].index }', $body, $file);
            $this->assertSame(1, substr_count($body, 'createResponsePromise = page.waitForResponse('), "$file arms the create response exactly once, inside the loop");
            $this->assertStringNotContainsString('const createResponsePromise', $body, $file);
        }
        $this->assertStringContainsString('async function fillSelectField(page, dialogSelector, labelText, { index = 0 } = {})', $content);
    }

    /** A module with no unique relation field keeps the plain single submit -- no retry machinery. */
    public function test_a_module_without_a_unique_relation_field_gets_no_retry_loop(): void
    {
        $config = [
            'table_name' => 'notes',
            'columns' => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'category_id', 'type' => 'foreignId', 'unique' => false],
            ],
            'features' => [
                'backend' => ['list' => true, 'create' => true, 'view' => true, 'edit' => false, 'delete' => true],
                'frontend' => [
                    'list' => ['primaryField' => 'title'],
                    'create' => ['fields' => [
                        ['field' => 'title', 'label' => 'Title', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ['field' => 'category_id', 'label' => 'Category', 'field_type' => 'api-select', 'type' => 'text', 'required' => true, 'api_url' => '/select/categories'],
                    ]],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Notes', 'Core', $config);
        $this->assertTrue($generator->generate());

        foreach (['notes-create.e2e.js', '_fixtures.js'] as $file) {
            $body = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Notes') . '/e2e/' . $file);
            $this->assertStringNotContainsString('uniquePicks', $body, $file);
        }
    }

    /**
     * Regression test for a real generated-and-run failure, found live running
     * the generated suite a second time against a real project (2026-08-20),
     * after the create-step View-dialog fixes (v3.4.1-v3.4.3) had already
     * resolved the dominant failure class: tryFillSelectField()'s own
     * zero-options fallback closed the picker via `page.keyboard.press('Escape')`
     * — but the picker is itself an AppDialog instance (same component the View
     * dialog uses), and AppDialog's `persistent` prop defaults true with no
     * override on the picker either, so Escape is captured and
     * preventDefault()'d exactly like the already-fixed View-dialog bug. Both
     * the Escape press AND the follow-up close-wait were wrapped in
     * `.catch(() => {})`, so the failure was completely silent: the function
     * returned `false` as if the field had been cleanly skipped, while the
     * picker dialog remained genuinely open (`data-state="open"`, not a
     * closing-animation remnant — confirmed live via a throwaway diagnostic
     * script dumping `document.body.children`) and its overlay blocked every
     * click on every field after it for the rest of that create flow — 15s
     * timeouts on whichever field happened to come next, hitting mostly Items
     * (Category → Unit Of Measure [no seed data] → Tax Rate, the middle field's
     * silent picker-stuck bug breaking the third).
     */
    public function test_try_fill_select_field_closes_the_picker_via_its_close_button_not_escape(): void
    {
        $config = [
            'table_name' => 'item_categories',
            'features' => [
                'backend' => ['list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]], 'create' => true, 'view' => true, 'edit' => false, 'delete' => true],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'parent_id', 'label' => 'Parent', 'field_type' => 'api-select', 'type' => 'text', 'required' => false, 'api_url' => '/select/item-categories'],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemCategories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'ItemCategories') . '/e2e/item-categories-create.e2e.js'
        );

        // tryFillSelectField()'s no-options branch closes via the picker's own
        // Close button now, never Escape.
        $this->assertStringContainsString(
            "await popup.getByRole('button', { name: 'Close' }).click();",
            $content
        );
        $this->assertStringNotContainsString("await page.keyboard.press('Escape').catch(() => {});\n\t\tawait page\n\t\t\t.waitForFunction", $content);
    }

    /**
     * Regression test for a third real failure, found immediately after the
     * two above while getting a generated ItemPrices e2e test to pass: a
     * `type: 'date'` column (`effective_date`, rendered as a native
     * `<input type="date">` — see IntrospectionToConfig::
     * buildFrontendFields()) got the generic `E2E __MODULE__ __LABEL__
     * ${stamp}` string template, same as any plain text field. Run for
     * real, this threw "Could not fill #effective_date: stuck at ''" —
     * browsers silently reject/clear a date input's value unless it is
     * exactly yyyy-mm-dd, which that template never produces.
     * fieldValueExpr()/editedFieldValueExpr() now special-case
     * `type === 'date'` to a real ISO date computed at test-run time.
     */
    public function test_date_type_field_gets_a_real_yyyy_mm_dd_value_not_the_generic_text_template(): void
    {
        $config = [
            'table_name' => 'item_prices',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'currency', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'currency'],
                    'create' => [
                        'fields' => [
                            ['field' => 'currency', 'label' => 'Currency', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'effective_date', 'label' => 'Effective Date', 'field_type' => 'date', 'type' => 'date', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'currency', 'label' => 'Currency', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'effective_date', 'label' => 'Effective Date', 'field_type' => 'date', 'type' => 'date', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemPrices', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemPrices') . '/e2e/item-prices-create.e2e.js');
        $edit = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemPrices') . '/e2e/item-prices-edit.e2e.js');

        // Create value: a real computed ISO date, never the generic template.
        $this->assertStringContainsString('effective_date: new Date().toISOString().slice(0, 10)', $create);
        $this->assertStringNotContainsString('effective_date: `E2E', $create);

        // Edit value: also a real computed date (offset, so it's distinguishable), never the generic template.
        $this->assertStringContainsString('new Date(Date.now() + 86400000).toISOString().slice(0, 10)', $edit);
        $this->assertStringNotContainsString('EDIT ${stamp}', $edit);
    }

    /**
     * Regression test for a bug found + fixed 2026-08-08, well after the
     * test above: `type: 'date'` fields stopped rendering as a native
     * `<input type="date">` once SYSTEM_SHELL migrated to the shadcn-vue
     * DatePickerField.vue popover+Calendar component (id={fieldId} on a
     * <button>, not an <input>) -- but renderFieldFill()'s default branch
     * still called fillField(), a plain `.locator(selector).fill()`, and
     * the edit block's scalar-field branch still called
     * setInputValue()+.inputValue(). Both assume a fillable/readable
     * <input>; a <button> has neither. Confirmed live: `fillField()` threw
     * "Element is not an <input>, <textarea>, <select>..." on a freshly
     * generated ItemPrices/Payments module's date field, on the very first
     * create attempt. Fixed with a dedicated fillDatePickerField() helper
     * (mirrors the hand-written one already proven in users-crud.e2e.js)
     * that drives the popover's calendar grid instead.
     */
    public function test_date_type_field_uses_the_calendar_popover_helper_not_a_plain_fill(): void
    {
        $config = [
            'table_name' => 'item_prices',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'currency', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'currency'],
                    'create' => [
                        'fields' => [
                            ['field' => 'currency', 'label' => 'Currency', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'effective_date', 'label' => 'Effective Date', 'field_type' => 'date', 'type' => 'date', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'currency', 'label' => 'Currency', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'effective_date', 'label' => 'Effective Date', 'field_type' => 'date', 'type' => 'date', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemPrices', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemPrices') . '/e2e/item-prices-create.e2e.js');
        $edit = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemPrices') . '/e2e/item-prices-edit.e2e.js');

        // Helper emitted (gated on hasFieldType('date')) in whichever file needs it.
        $this->assertStringContainsString('async function fillDatePickerField(page, dialogSelector, fieldId, dayOffset = 0)', $create);
        $this->assertStringContainsString('async function fillDatePickerField(page, dialogSelector, fieldId, dayOffset = 0)', $edit);

        // Create step: day offset 0 (today), never the plain fillField()/setInputValue() path for this field.
        $this->assertStringContainsString("fillDatePickerField(page, '[role=\"dialog\"]', 'effective_date', 0)", $create);
        $this->assertStringNotContainsString("fillField(page, '[role=\"dialog\"] #effective_date'", $create);

        // Edit step: pickEditField() resolves to 'effective_date' here (first
        // non-anchor scalar edit field, anchor being 'currency' per
        // list.primaryField) -- day offset 1 (tomorrow), no
        // setInputValue()/.inputValue() readback for this field.
        $this->assertStringContainsString("fillDatePickerField(page, '[role=\"dialog\"]', 'effective_date', 1)", $edit);
        $this->assertStringNotContainsString("setInputValue(page, '[role=\"dialog\"] #effective_date'", $edit);
    }

    /**
     * Found by the super-suite fixture's full Playwright lane (2026-09-19): every SuiteOrders spec
     * died on `locator.fill: element is not enabled`. `total` is the `sync_to` target of an
     * inline_items total, which the form writes and disables itself, so a spec must never type
     * into it.
     */
    public function test_a_field_computed_by_an_inline_items_total_is_never_typed_into(): void
    {
        $fields = [
            ['field' => 'order_no', 'label' => 'Order No', 'field_type' => 'input', 'type' => 'text', 'required' => true],
            ['field' => 'total', 'label' => 'Total', 'field_type' => 'number-input', 'type' => 'number', 'required' => true, 'decimals' => 2],
        ];
        $config = [
            'table_name' => 'suite_orders',
            'inline_items' => [[
                'key' => 'order_lines',
                'child_module' => 'SuiteOrderLines',
                'totals' => [['field' => 'line_total', 'label' => 'Total', 'sync_to' => 'total']],
            ]],
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'order_no', 'type' => 'text']]],
                    'create' => true, 'view' => true, 'edit' => true, 'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'order_no'],
                    'create' => ['fields' => $fields],
                    'view' => true,
                    'edit' => ['fields' => $fields],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('SuiteOrders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $dir = PathManager::getFrontendModulePath('Core', 'SuiteOrders') . '/e2e/';
        foreach (['create', 'edit', 'view', 'delete', 'list'] as $spec) {
            $content = (string) file_get_contents($dir . "suite-orders-{$spec}.e2e.js");
            $this->assertStringNotContainsString('#total', $content, "the {$spec} spec must not touch the computed total");
        }
        // The ordinary field alongside it is still filled.
        $this->assertStringContainsString('#order_no', (string) file_get_contents($dir . 'suite-orders-create.e2e.js'));
    }

    /**
     * Found by the super-suite fixture's full Playwright lane (2026-09-19, 50 of 71 specs failing
     * across every fixture module with a required select or date field): the picker helpers were
     * written against the old field components -- a `.select2-trigger` wrapper and a Popover-hosted
     * calendar -- and Boot Box-based frontends have neither. Their Select2/ApiSelect2 trigger is a
     * bare <button data-slot="select2-trigger">, and DatePickerField opens its Calendar in a stacked
     * AppDialog, so every spec threw "no select2 trigger found" or timed out on
     * `[data-slot="popover-content"]`. The helpers now target the Calendar and the trigger's own
     * slot, which both component generations share.
     */
    public function test_picker_helpers_do_not_assume_a_popover_or_select2_trigger_wrapper(): void
    {
        $config = [
            'table_name' => 'suite_orders',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'code', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'code'],
                    'create' => [
                        'fields' => [
                            ['field' => 'code', 'label' => 'Code', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'tier', 'label' => 'Tier', 'field_type' => 'select', 'type' => 'text', 'required' => true, 'options' => [['value' => 'a', 'label' => 'A']]],
                            ['field' => 'placed_on', 'label' => 'Placed On', 'field_type' => 'date', 'type' => 'date', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('SuiteOrders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'SuiteOrders') . '/e2e/suite-orders-create.e2e.js');

        $this->assertStringContainsString('async function fillSelectField(', $create);
        $this->assertStringContainsString('async function fillDatePickerField(', $create);

        // The trigger is found by either component generation's hook.
        $this->assertStringContainsString(".locator('.select2-trigger button, [data-slot=\"select2-trigger\"]')", $create);
        $this->assertStringNotContainsString(".locator('.select2-trigger button');", $create);

        // The calendar is located directly, never through the surface that happens to host it.
        $this->assertStringContainsString("page.locator('[data-slot=\"calendar\"]').last()", $create);
        $this->assertStringNotContainsString("page.locator('[data-slot=\"popover-content\"]')", $create);
    }

    /**
     * Regression test for a fourth real failure in the same investigation: a
     * `field_type: 'number-input'` column renders as NumberInputField.vue,
     * which formats its displayed value through Cleave.js with thousands
     * separators (`delimiter: ','`). A plain fillField() readback check
     * compares `.inputValue()` (e.g. "1,875,449") against the exact typed
     * string (e.g. "1875449") and can never match. Run for real, a
     * generated ItemImages e2e test threw `Could not fill #sort_order:
     * stuck at "1,875,449", expected "1875449"` on its very first attempt.
     * renderFieldFill() now routes 'number-input' fields to a dedicated,
     * comma-tolerant fillNumberField() helper instead of the generic
     * fillField(). A second, related bug in the SAME investigation: the
     * edit block's inline readback check compared a raw JS value (a NUMBER
     * literal for numeric fields, per editedFieldValueExpr()) against
     * `.inputValue()` (always a STRING) with a bare `!==` — a type
     * mismatch that fails for every numeric edit field regardless of
     * Cleave formatting. Both are covered here.
     */
    public function test_number_input_field_uses_the_comma_tolerant_fill_helper(): void
    {
        $config = [
            'table_name' => 'item_images',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'sort_order'],
                    'create' => [
                        'fields' => [
                            ['field' => 'sort_order', 'label' => 'Sort Order', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'sort_order', 'label' => 'Sort Order', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $create = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images-create.e2e.js');
        $edit = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images-edit.e2e.js');

        // Helper emitted (gated on hasFieldType('number-input')) and used for create.
        $this->assertStringContainsString('async function fillNumberField(page, selector, value)', $create);
        $this->assertStringContainsString("fillNumberField(page, '[role=\"dialog\"] #sort_order', createValues.sort_order)", $create);
        $this->assertStringNotContainsString("fillField(page, '[role=\"dialog\"] #sort_order'", $create);

        // Edit block: comma-tolerant, type-safe comparison, not a bare `!==`.
        $this->assertStringContainsString('const normalizeForCompare = (v) => String(v).replace(/,/g, \'\');', $edit);
        $this->assertStringContainsString("if (normalizeForCompare(editedActual) !== normalizeForCompare(editedValue))", $edit);
        $this->assertStringNotContainsString('if (editedActual !== editedValue)', $edit);
    }

    /**
     * Regression test for a bug found + fixed 2026-08-08 while running all 5
     * generator-engine integration-test suites simultaneously against
     * SYSTEM_SHELL: every numeric field's create/edit value is the fixed
     * formula `(1000000 + (stamp % 900000))` / `(2000000 + (stamp %
     * 900000))` -- always a 7-digit integer. A `decimal(10, 4)` column (6
     * integer digits, max 999999.9999) can never hold that. Confirmed live:
     * a freshly generated OrderItems create request 500'd with
     * "SQLSTATE[22003]: Numeric value out of range: 1264 Out of range
     * value for column 'unit_price'". constrainNumericExpr() now clamps
     * the value to the column's actual `precision - scale` capacity when
     * known; a plain integer/bigint column (no `precision` key at all --
     * see IntrospectionToConfig::buildColumn()) is untouched.
     */
    public function test_numeric_field_value_is_clamped_to_a_narrow_decimal_columns_capacity(): void
    {
        $config = [
            'table_name' => 'order_items',
            'columns' => [
                ['name' => 'unit_price', 'type' => 'decimal', 'precision' => 10, 'scale' => 4],
                ['name' => 'quantity', 'type' => 'integer'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'unit_price'],
                    'create' => [
                        'fields' => [
                            ['field' => 'unit_price', 'label' => 'Unit Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                            ['field' => 'quantity', 'label' => 'Quantity', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'unit_price', 'label' => 'Unit Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                            ['field' => 'quantity', 'label' => 'Quantity', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('OrderItems', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Custom', 'OrderItems') . '/e2e/order-items-create.e2e.js');

        // unit_price (decimal(10,4), 6 integer digits -- below the default
        // formula's 7-digit range): clamped, not the raw 1,000,000+/2,000,000+ formula.
        $this->assertStringContainsString('unit_price: ((stamp % 999998) + 1)', $content);
        $this->assertStringNotContainsString('unit_price: (1000000 + (stamp % 900000))', $content);

        // quantity (plain integer, no precision/scale at all): untouched, keeps the original formula.
        $this->assertStringContainsString('quantity: (1000000 + (stamp % 900000))', $content);
    }

    /**
     * Regression test: renderFieldFill()'s 'file-input' branch previously
     * built the upload fixture from a bare 8-byte PNG magic-number prefix
     * (`Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])`) —
     * not a real image. Real MIME sniffing (libmagic/PHP's finfo, exactly
     * what Laravel's `file`/`image` validation rules use server-side) reads
     * actual file content, not just a magic number, so a generated test
     * built that way could never pass a real upload against the real
     * backend. The fixture must now be a genuinely valid, minimal image
     * (a real 1x1 transparent PNG, base64-embedded and decoded via
     * `Buffer.from(..., 'base64')`), not just the old magic-number prefix.
     */
    public function test_file_input_field_uses_a_real_base64_encoded_png_not_a_magic_number_prefix(): void
    {
        $config = [
            'table_name' => 'item_images',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'caption'],
                    'create' => [
                        'fields' => [
                            ['field' => 'caption', 'label' => 'Caption', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'image_media_id', 'label' => 'Image', 'field_type' => 'file-input', 'type' => 'file', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'caption', 'label' => 'Caption', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'image_media_id', 'label' => 'Image', 'field_type' => 'file-input', 'type' => 'file', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images-create.e2e.js');

        // The old, invalid 8-byte magic-number-only buffer must be gone.
        $this->assertStringNotContainsString('Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])', $content);

        // Replaced by a real, base64-decoded PNG.
        $this->assertStringContainsString("Buffer.from(\n\t\t\t\t'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',\n\t\t\t\t'base64',\n\t\t\t)", $content);
        $this->assertStringContainsString("mimeType: 'image/png'", $content);

        // Verify the embedded base64 literal decodes to real, valid PNG bytes
        // that libmagic-backed detection (finfo) actually reports as image/png
        // -- not just a plausible-looking string.
        preg_match("/Buffer\\.from\\(\\s*'([A-Za-z0-9+\\/=]+)',\\s*'base64',/", $content, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the base64 literal in the generated file-input fill line.');

        $decoded = base64_decode($matches[1], true);
        $this->assertNotFalse($decoded, 'Embedded base64 literal did not decode.');
        $this->assertGreaterThan(60, strlen($decoded), 'Decoded fixture is suspiciously small for a real PNG.');

        $tmpFile = tempnam(sys_get_temp_dir(), 'e2e-fixture-') . '.png';
        file_put_contents($tmpFile, $decoded);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);
        unlink($tmpFile);

        $this->assertSame('image/png', $mime, 'Decoded fixture bytes are not recognized as a real PNG by finfo.');
    }

    // ------------------------------------------------------------------
    // e2e coverage expansion (gaps 1, 2, 3, 4, 5 — see the audit that drove
    // this work): validation-error display, soft-delete list disappearance,
    // required-file rejection, wrong delete-confirmation-text rejection,
    // and the optional-relation best-effort picker above.
    // ------------------------------------------------------------------

    /** @return array<string, mixed> minimal module config with everything toggleable off/absent. */
    private function minimalModuleConfig(): array
    {
        return [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            // Deliberately NOT required: the create form has no
                            // required scalar field, so the validation-error step
                            // (gap 1) must be omitted entirely.
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
            ],
        ];
    }

    /**
     * Gap 1 (validation error display): the create form's own submit
     * button is clicked with the required "Name" field still empty, and an
     * inline error must render for it, before anything else is filled.
     * Omitted entirely when no create field is required (verified by the
     * companion "omitted" test) — that's what keeps this step order-safe
     * for the rest of the sequence: it only ever runs against the
     * pristine, still-open, nothing-filled-yet dialog, and the dialog never
     * closes/navigates on a failed submit, so every fill/submit step after
     * it proceeds completely unaffected.
     */
    public function test_validation_error_step_is_emitted_when_a_required_scalar_create_field_exists(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('create'));

        $this->assertStringContainsString('function fieldErrorLocator(page, dialogSelector, labelText)', $content);
        $this->assertStringContainsString('Validation: submitting with required "Name" empty', $content);
        $this->assertStringContainsString("fieldErrorLocator(page, '[role=\"dialog\"]', 'Name')", $content);
        $this->assertStringContainsString(
            "expect(await page.locator('[role=\"dialog\"]').count(), 'Dialog should remain open after a failed validation submit').toBeGreaterThan(0);",
            $content
        );

        // Ordering: the validation step must run BEFORE any field is filled
        // (i.e. before the `const createValues = {` declaration), on the
        // still-pristine dialog.
        $validationPos = strpos($content, 'Validation: submitting with required "Name" empty');
        $createValuesPos = strpos($content, 'const createValues = {');
        $this->assertNotFalse($validationPos);
        $this->assertNotFalse($createValuesPos);
        $this->assertLessThan($createValuesPos, $validationPos, 'Validation-empty-submit step must run before any field is filled.');
    }

    public function test_validation_error_step_is_omitted_when_no_create_field_is_required(): void
    {
        $config = $this->minimalModuleConfig();

        $generator = new PlaywrightTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Widgets') . '/e2e/widgets-create.e2e.js');

        $this->assertStringNotContainsString('function fieldErrorLocator(', $content);
        $this->assertStringNotContainsString('Validation: submitting with required', $content);
    }

    /**
     * Gap 3 (required-file rejection): a required 'file-input' create field
     * gets its own, separately-isolated submit attempt — after every OTHER
     * required field is already filled, so the resulting error is
     * unambiguously about the missing file, not some other empty field.
     */
    public function test_required_file_validation_step_is_emitted_when_a_required_file_field_exists(): void
    {
        $config = [
            'table_name' => 'item_images',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'caption'],
                    'create' => [
                        'fields' => [
                            ['field' => 'caption', 'label' => 'Caption', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'image_media_id', 'label' => 'Image', 'field_type' => 'file-input', 'type' => 'file', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images-create.e2e.js');

        $this->assertStringContainsString('Validation: submitting without the required "Image" file', $content);
        $this->assertStringContainsString("fieldErrorLocator(page, '[role=\"dialog\"]', 'Image')", $content);

        // Ordering: the non-file required field ("caption") must already be
        // filled by the time this submit is attempted, and the actual file
        // attachment (setInputFiles) must happen AFTER this check — so the
        // error is isolated to the missing file, and the file is only
        // attached once the failure path has been exercised.
        $captionFillPos = strpos($content, "fillField(page, '[role=\"dialog\"] #caption'");
        $fileValidationPos = strpos($content, 'Validation: submitting without the required "Image" file');
        $setInputFilesPos = strpos($content, 'setInputFiles({');
        $this->assertNotFalse($captionFillPos);
        $this->assertNotFalse($fileValidationPos);
        $this->assertNotFalse($setInputFilesPos);
        $this->assertLessThan($fileValidationPos, $captionFillPos);
        $this->assertLessThan($setInputFilesPos, $fileValidationPos);
    }

    public function test_required_file_validation_step_is_omitted_when_no_create_field_is_a_required_file(): void
    {
        // LocationTypes has no file-input field at all.
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('create'));

        $this->assertStringNotContainsString('Validation: submitting without the required', $content);
    }

    /**
     * Gap 4 (wrong delete-confirmation text): typing anything other than
     * exactly "YES" into the delete form's #confirm field must keep the
     * confirm button disabled (see [[ModuleName]]DeleteForm.vue's
     * `isConfirmValid` computed / `:disabled="!isConfirmValid || isDeleting"`)
     * — asserted directly via toBeDisabled() rather than attempting (and
     * expecting to fail) a click, since Playwright's own actionability
     * check would refuse to click a genuinely disabled element anyway.
     * Always emitted whenever the module has a delete step at all — there
     * is no additional module-shape gate for this one, mirroring how the
     * unconditional #confirm/'YES' happy-path fill already isn't gated any
     * further than hasDelete.
     */
    public function test_wrong_delete_confirmation_text_step_is_emitted_when_delete_is_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('delete'));

        $this->assertStringContainsString("fillField(page, '[role=\"dialog\"] #confirm', 'nope');", $content);
        $this->assertStringContainsString(
            "await expect(page.locator('[role=\"dialog\"] [data-testid=\"locationtypes-confirm-delete\"]')).toBeDisabled();",
            $content
        );

        // Ordering: the wrong-text attempt must precede the correct 'YES'
        // fill WITHIN the test body's actual Delete step — search from the
        // Delete step's own marker onward, since cleanupStrayRecord() (a
        // helper function defined earlier in the file, for best-effort
        // cleanup on failure) also contains its own unrelated
        // #confirm/'YES' fill.
        $deleteStepPos = strpos($content, "// ── Delete (via the view modal's \"More Actions\" -> Delete) ");
        $this->assertNotFalse($deleteStepPos, 'Could not locate the Delete step marker.');
        $wrongPos = strpos($content, "fillField(page, '[role=\"dialog\"] #confirm', 'nope');", $deleteStepPos);
        $rightPos = strpos($content, "fillField(page, '[role=\"dialog\"] #confirm', 'YES');", $deleteStepPos);
        $this->assertNotFalse($wrongPos);
        $this->assertNotFalse($rightPos);
        $this->assertLessThan($rightPos, $wrongPos);
    }

    public function test_wrong_delete_confirmation_text_step_is_omitted_when_delete_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['frontend']['delete'] = false;
        $config['features']['backend']['delete'] = false;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        // hasDelete false -> writeDeleteSpecFile() never runs; there is no
        // file to check content against at all.
        $this->assertFileDoesNotExist($this->generatedFilePath('delete'));
    }

    /**
     * Gap 2 (soft-deleted record disappears from the list): the extra
     * text-based re-confirmation after delete is emitted ONLY when
     * ModuleConfigContract::hasSoftDeletes() says the module has a
     * `deleted_at` column — the sanctioned single source of truth for this
     * fact, deliberately used here instead of re-deriving it locally.
     */
    public function test_soft_delete_list_assertion_is_emitted_when_module_has_soft_deletes(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_soft_deletes'] = true;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('delete'));

        // The old createdRowText-based re-check is gone: delete.e2e.js gets
        // its record from _fixtures.js's createFixtureRecord(), which never
        // exposes the field values it typed in — only the testid-based
        // assertion (already covered elsewhere) is available here now.
        $this->assertStringNotContainsString('createdRowText', $content);
        $this->assertStringContainsString('soft-delete OK — record no longer appears in the list', $content);
    }

    public function test_soft_delete_list_assertion_is_omitted_when_module_has_no_soft_deletes(): void
    {
        // LocationTypesModule.json fixture has no 'has_soft_deletes' key and
        // no 'deleted_at' column -> ModuleConfigContract::hasSoftDeletes()
        // resolves to false via its documented fallback.
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath('delete'));

        $this->assertStringNotContainsString('soft-delete OK', $content);
    }

    /**
     * Regression: a module with NEITHER a required create field, NOR a
     * required file field, NOR an optional relation field, NOR soft
     * deletes, NOR a delete feature at all must produce output completely
     * free of every gap-1/2/3/4/5 addition — i.e. none of this expansion's
     * new blocks leak into a module shape that doesn't call for them.
     */
    public function test_minimal_module_omits_every_new_validation_and_soft_delete_block(): void
    {
        $config = $this->minimalModuleConfig();

        $generator = new PlaywrightTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Widgets') . '/e2e/widgets-create.e2e.js');

        // Gap 1
        $this->assertStringNotContainsString('function fieldErrorLocator(', $content);
        $this->assertStringNotContainsString('Validation: submitting with required', $content);
        // Gap 3
        $this->assertStringNotContainsString('Validation: submitting without the required', $content);
        // Gap 5
        $this->assertStringNotContainsString('tryFillSelectField', $content);
        $this->assertStringNotContainsString('fillSelectField', $content);

        // Gap 4 / delete entirely (hasDelete is false) -> no delete.e2e.js at all.
        $this->assertFileDoesNotExist(
            PathManager::getFrontendModulePath('Core', 'Widgets') . '/e2e/widgets-delete.e2e.js'
        );
    }

    // ------------------------------------------------------------------
    // Split output: delegation/action spec files, _fixtures.js,
    // regenerateOnly(), stale-file deletion (v2.21.0 — see this class's own
    // docblock). None of this had prior regression coverage: delegations had
    // zero e2e coverage of any kind before PlaywrightTestGenerator existed in
    // its current split form, and actions had none either.
    // ------------------------------------------------------------------

    private function e2eFiles(string $group, string $module): array
    {
        $dir = PathManager::getFrontendModulePath($group, $module) . '/e2e';
        if (!is_dir($dir)) {
            return [];
        }
        $files = array_map('basename', glob($dir . '/*') ?: []);
        sort($files);
        return $files;
    }

    private function itemsWithDelegationAndActionsConfig(): array
    {
        return [
            'table_name' => 'items',
            'features' => [
                'backend' => ['list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]], 'create' => true, 'view' => true, 'edit' => true, 'delete' => true],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => ['fields' => [['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true]]],
                    'view' => true,
                    'edit' => ['fields' => [['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true]]],
                    'delete' => true,
                ],
            ],
            'delegations' => [
                'itemPrices' => [
                    'name' => 'itemPrices', 'label' => 'Item Prices', 'uiType' => 'tab', 'filterKey' => 'item_id',
                ],
                'quickApprove' => [
                    'name' => 'quickApprove', 'label' => 'Quick Approve', 'uiType' => 'modal',
                    'operations' => ['create' => ['enabled' => true, 'frontend' => ['fields' => [
                        ['field' => 'note', 'label' => 'Note', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                    ]]]],
                ],
            ],
            'actions' => [
                'approve' => ['name' => 'approve', 'label' => 'Approve', 'hasUI' => true, 'uiType' => 'modal', 'placement' => 'main'],
                'export' => ['name' => 'export', 'label' => 'Export', 'hasUI' => true, 'uiType' => 'page', 'placement' => 'more'],
                'silentSync' => ['name' => 'silentSync', 'label' => 'Silent Sync', 'hasUI' => false],
            ],
        ];
    }

    public function test_generate_omits_fixtures_and_split_files_when_module_has_no_delegations_or_actions(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        // _fixtures.js is now unconditional -- every split file below needs
        // its createFixtureRecord()/cleanupRecord() pair, not just
        // delegation/action specs (renamed from the old test title's "omits
        // fixtures..." premise, which this class no longer does).
        $this->assertSame(
            ['_fixtures.js', 'location-types-create.e2e.js', 'location-types-delete.e2e.js', 'location-types-edit.e2e.js', 'location-types-list.e2e.js', 'location-types-view.e2e.js'],
            $this->e2eFiles('Core', 'LocationTypes')
        );
    }

    public function test_generate_writes_fixtures_and_one_spec_per_delegation_and_ui_action(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();

        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        // silentSync has hasUI=false -> no trigger exists in the UI at all,
        // so no spec is written for it (mirrors ActionComponentGenerator's
        // own empty($action['hasUI']) gate). items-crud.e2e.js is replaced
        // by the list/create/view/edit/delete split.
        $this->assertSame(
            ['_fixtures.js', 'items-approve.e2e.js', 'items-create.e2e.js', 'items-delete.e2e.js', 'items-edit.e2e.js', 'items-export.e2e.js', 'items-item-prices.e2e.js', 'items-list.e2e.js', 'items-quick-approve.e2e.js', 'items-view.e2e.js'],
            $this->e2eFiles('Core', 'Items')
        );
    }

    public function test_tab_delegation_spec_creates_its_own_fixture_and_navigates_directly_to_the_tab_route(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-item-prices.e2e.js'
        );

        $this->assertStringContainsString("import { createFixtureRecord, cleanupRecord } from './_fixtures.js';", $content);
        $this->assertStringContainsString('const { uuid: recordUuid } = await createFixtureRecord(page);', $content);
        $this->assertStringContainsString('await cleanupRecord(page, recordUuid);', $content);
        $this->assertStringContainsString('/items/${recordUuid}/details/item-prices', $content);
        $this->assertStringContainsString('data-testid="items-tab-item-prices"', $content);
        // No `if (recordUuid)` guard — createFixtureRecord() already throws
        // if it can't produce one, unlike the old inline crud-file version.
        $this->assertStringNotContainsString('if (recordUuid) {', $content);
    }

    public function test_modal_delegation_spec_locates_trigger_by_label_and_fills_its_own_create_fields(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-quick-approve.e2e.js'
        );

        // Details PAGE, not the list-row view modal — header-action triggers
        // only render on ViewLayoutGenerator's standalone details page.
        $this->assertStringContainsString('/items/${recordUuid}/details`', $content);
        $this->assertStringContainsString("page.getByRole('button', { name: 'Quick Approve' })", $content);
        $this->assertStringContainsString("delegationDialog.locator('button[type=\"submit\"]')", $content);
        // Its own create field gets declared/filled, not the parent's.
        $this->assertStringContainsString('E2E Items Note ${stamp}', $content);
        $this->assertStringContainsString("fillField(page, '[role=\"dialog\"] #note', delegationValues.note)", $content);
    }

    public function test_action_spec_is_written_only_for_actions_with_hasui_true(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';
        $this->assertFileDoesNotExist($dir . '/items-silent-sync.e2e.js');
    }

    public function test_main_placement_action_skips_more_actions_menu_but_more_placement_opens_it(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';

        $mainContent = (string) file_get_contents($dir . '/items-approve.e2e.js');
        $this->assertStringContainsString('data-testid="items-action-approve-${recordUuid}"', $mainContent);
        $this->assertStringNotContainsString("name: 'More Actions'", $mainContent);

        $moreContent = (string) file_get_contents($dir . '/items-export.e2e.js');
        $this->assertStringContainsString("name: 'More Actions'", $moreContent);
        $this->assertStringContainsString('data-testid="items-action-export-${recordUuid}"', $moreContent);
    }

    public function test_modal_action_treats_dialog_close_as_success_and_page_action_treats_navigation_as_success(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';

        $modalContent = (string) file_get_contents($dir . '/items-approve.e2e.js');
        $this->assertStringContainsString('document.querySelectorAll(\'[role="dialog"]\').length <= n', $modalContent);

        $pageContent = (string) file_get_contents($dir . '/items-export.e2e.js');
        $this->assertStringContainsString('!window.location.pathname.includes(`/${kebab}`)', $pageContent);
    }

    /**
     * Regression test for a real, live-caught false-positive (2026-08-21):
     * a modal action's own AppDialog renders its own chrome "Close" button
     * inside the same [role="dialog"] as the form's Cancel/Submit pair, DOM
     * -ordered after both. `formButtons.last()` silently resolved to Close,
     * not the real submit button — the generated test's own "dialog closed"
     * success signal was satisfied without the action's real endpoint ever
     * being called (confirmed live: building demo data, the generated
     * Approve smoke test would have reported success while the backend
     * never saw a POST to /purchase-orders/{uuid}/approve at all). Fixed by
     * targeting the submit button by its own accessible name, which is
     * always exactly the action's label per features/action/form.stub's
     * `{{ isSubmitting ? 'Processing…' : '[[ActionLabel]]' }}`.
     */
    public function test_modal_action_submit_button_is_matched_by_its_own_label_not_the_last_dialog_button(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $modalContent = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-approve.e2e.js');

        $this->assertStringContainsString("actionDialog.getByRole('button', { name: 'Approve', exact: true })", $modalContent);
        $this->assertStringNotContainsString('const submitBtn = formButtons.last();', $modalContent);
    }

    public function test_fixtures_file_falls_back_to_an_existing_row_when_module_has_no_create_feature(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => ['list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]], 'create' => false, 'view' => true, 'edit' => false, 'delete' => true],
                'frontend' => ['list' => ['primaryField' => 'name'], 'create' => false, 'view' => true, 'edit' => false, 'delete' => true],
            ],
            'actions' => [
                'approve' => ['name' => 'approve', 'label' => 'Approve', 'hasUI' => true, 'uiType' => 'modal'],
            ],
        ];

        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/_fixtures.js');

        $this->assertStringContainsString("const targetRow = page.locator('table tbody tr').first();", $content);
        $this->assertStringContainsString('no existing record available to use as a fixture', $content);
        $this->assertStringNotContainsString('data-testid="items-create"', $content);
    }

    /**
     * Regression test for a real generated-and-run failure (found live,
     * 2026-08-23, on UserLocations): a module with no plain text/number
     * create field falls back to `page.locator('table tbody tr').first()`
     * to find "the row just created" — but that fallback is a DOM/sort-order
     * assumption, not an identity check, and it silently grabs the wrong row
     * whenever some pre-existing row (e.g. a legacy non-UUIDv7 id) happens
     * to sort above every freshly created one under ORDER BY id DESC.
     * Confirmed live: user-locations-crud.e2e.js's View/Edit/Delete steps
     * all operated on an unrelated seeded row instead of the one the test
     * had just created.
     *
     * Fixed: when no anchor field exists, buildCreateBlock() now arms a
     * page.waitForResponse() listener before the create submit click and
     * reads the created record's uuid straight from the POST .../create
     * response body — never from DOM position — and buildTargetRowBlock()
     * filters for the <tr> containing that uuid's own view button.
     */
    private function noScalarCreateFieldConfig(): array
    {
        return [
            'table_name' => 'items',
            'features' => [
                'backend' => ['list' => ['filterFields' => []], 'create' => true, 'view' => true, 'edit' => true, 'delete' => true],
                'frontend' => [
                    'list' => ['primaryField' => 'category_id'],
                    'create' => ['fields' => [
                        ['field' => 'category_id', 'label' => 'Category', 'field_type' => 'api-select', 'type' => 'text', 'required' => true],
                        ['field' => 'is_active', 'label' => 'Is Active', 'field_type' => 'checkbox', 'type' => 'boolean', 'required' => false],
                    ]],
                    'view' => true,
                    'edit' => ['fields' => [
                        ['field' => 'category_id', 'label' => 'Category', 'field_type' => 'api-select', 'type' => 'text', 'required' => true],
                    ]],
                    'delete' => true,
                ],
            ],
        ];
    }

    public function test_create_block_targets_the_created_row_by_response_uuid_when_no_scalar_field_exists(): void
    {
        $config = $this->noScalarCreateFieldConfig();

        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items-create.e2e.js');

        $this->assertStringContainsString(
            "const createResponsePromise = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'));",
            $content
        );
        $this->assertStringContainsString(
            "const createdRecordUuid = (await createResponse.json())?.data?.uuid ?? null;",
            $content
        );
        $this->assertStringContainsString(
            "const targetRow = page.locator('table tbody tr').filter({ has: page.locator(`[data-testid=\"items-view-\${createdRecordUuid}\"]`) }).first();",
            $content
        );
        // The old, unsafe positional-only fallback must be gone.
        $this->assertStringNotContainsString("const targetRow = page.locator('table tbody tr').first();", $content);
        $this->assertStringNotContainsString('skipping row-text assertion', $content);
    }

    public function test_fixture_create_body_targets_the_created_row_by_response_uuid_when_no_scalar_field_exists(): void
    {
        $config = $this->noScalarCreateFieldConfig();
        $config['delegations'] = [
            'itemPrices' => ['name' => 'itemPrices', 'label' => 'Item Prices', 'uiType' => 'tab', 'filterKey' => 'item_id'],
        ];

        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/_fixtures.js');

        $this->assertStringContainsString(
            "const createResponsePromise = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'));",
            $content
        );
        $this->assertStringContainsString(
            "const recordUuid = (await createResponse.json())?.data?.uuid ?? null;",
            $content
        );
        $this->assertStringNotContainsString("locator('[data-testid^=\"items-view-\"]')\n\t\t.first()\n\t\t.getAttribute('data-testid')", $content);
    }

    /**
     * Regression test for a real generated-and-run failure, found live running the
     * generated suite for the first time in a real project (2026-08-20), the SAME session
     * as the buildCreateBlock() fix this class already covers (see
     * test_create_step_expects_the_view_dialog_to_open_when_view_is_enabled()'s own
     * docblock) -- but a DIFFERENT generated function. createFixtureRecord() (this file,
     * `_fixtures.js`, shared by every action/delegation smoke-test spec) has its own
     * separate post-submit assertion, never updated to match onCreated()'s "create -> view
     * by default" behavior either. Every activate/deactivate/delegation smoke test in the
     * real project hung 15s at fixture setup for exactly this reason, entirely separate
     * from the CRUD spec's own create step (already fixed). Same fix applied here: assert
     * the View dialog opens, close it via its Close button (not Escape -- see the sibling
     * fix's own v3.4.1 -> v3.4.2 correction), then capture the fixture row's uuid as before.
     */
    public function test_fixture_create_body_expects_the_view_dialog_to_open_when_view_is_enabled(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig(); // has view enabled

        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/_fixtures.js');

        $this->assertStringContainsString(
            'expected the View dialog to open automatically after a successful create',
            $content
        );
        $this->assertStringContainsString(
            "await page.locator('[role=\"dialog\"]').getByRole('button', { name: 'Close' }).click();",
            $content
        );
    }

    public function test_fixture_create_body_expects_no_dialog_when_view_is_disabled(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        $config['features']['frontend']['view'] = false;
        $config['features']['backend']['view'] = false;

        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/_fixtures.js');

        $this->assertStringNotContainsString('expected the View dialog to open automatically', $content);
        $this->assertStringContainsString(
            "await page.locator('[role=\"dialog\"] [data-testid=\"items-submit\"]').click();\n\tawait page.waitForFunction(() => !document.querySelector('[role=\"dialog\"]'), { timeout: 15000 });\n\tawait waitForListSettled(page);",
            $content
        );
    }

    public function test_fixtures_cleanup_is_a_noop_log_when_module_has_no_delete_feature(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => ['list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]], 'create' => true, 'view' => true, 'edit' => false, 'delete' => false],
                'frontend' => ['list' => ['primaryField' => 'name'], 'create' => ['fields' => [['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true]]], 'view' => true, 'edit' => false, 'delete' => false],
            ],
            'actions' => [
                'approve' => ['name' => 'approve', 'label' => 'Approve', 'hasUI' => true, 'uiType' => 'modal'],
            ],
        ];

        (new PlaywrightTestGenerator('Items', 'Core', $config))->generate();

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/_fixtures.js');

        $this->assertStringContainsString('module has no delete feature — leaving uuid', $content);
        $this->assertStringNotContainsString("name: 'More Actions'", $content);
    }

    public function test_regenerate_only_writes_exactly_the_target_delegation_file_and_nothing_else(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';
        $before = [];
        foreach ($this->e2eFiles('Core', 'Items') as $file) {
            $before[$file] = filemtime($dir . '/' . $file);
        }
        // Hand-edit an unrelated existing spec — must survive untouched.
        file_put_contents($dir . '/items-approve.e2e.js', "// HAND-EDITED\n" . file_get_contents($dir . '/items-approve.e2e.js'));
        $handEdited = file_get_contents($dir . '/items-approve.e2e.js');

        $config['delegations']['warranty'] = ['name' => 'warranty', 'label' => 'Warranty', 'uiType' => 'tab', 'filterKey' => 'item_id'];
        $regen = new PlaywrightTestGenerator('Items', 'Core', $config);
        $regen->setForce(true);
        $this->assertTrue($regen->regenerateOnly('warranty', 'delegation'));

        $after = $this->e2eFiles('Core', 'Items');
        $this->assertContains('items-warranty.e2e.js', $after);
        // Every previously-existing file, including the hand-edited one, is untouched.
        foreach ($before as $file => $mtime) {
            $this->assertFileExists($dir . '/' . $file);
        }
        $this->assertSame($handEdited, file_get_contents($dir . '/items-approve.e2e.js'), 'regenerateOnly() must not touch an unrelated spec file');
    }

    public function test_regenerate_only_writes_exactly_the_target_action_file_and_nothing_else(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';
        file_put_contents($dir . '/items-item-prices.e2e.js', "// HAND-EDITED\n" . file_get_contents($dir . '/items-item-prices.e2e.js'));
        $handEdited = file_get_contents($dir . '/items-item-prices.e2e.js');

        $config['actions']['refund'] = ['name' => 'refund', 'label' => 'Refund', 'hasUI' => true, 'uiType' => 'modal'];
        $regen = new PlaywrightTestGenerator('Items', 'Core', $config);
        $regen->setForce(true);
        $this->assertTrue($regen->regenerateOnly('refund', 'action'));

        $this->assertContains('items-refund.e2e.js', $this->e2eFiles('Core', 'Items'));
        $this->assertSame($handEdited, file_get_contents($dir . '/items-item-prices.e2e.js'), 'regenerateOnly() must not touch an unrelated spec file');
    }

    public function test_regenerate_only_returns_false_for_an_action_without_hasui(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();
        $generator = new PlaywrightTestGenerator('Items', 'Core', $config);
        $generator->generate();

        $this->assertFalse($generator->regenerateOnly('silentSync', 'action'));
    }

    public function test_deletes_stale_monolithic_file_only_under_force_after_split_files_are_written(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';
        // Simulate a module that predates this split (old bare `{module}.e2e.js`).
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/items.e2e.js', '// legacy monolithic file');

        $withoutForce = new PlaywrightTestGenerator('Items', 'Core', $config);
        $withoutForce->generate();
        $this->assertFileExists($dir . '/items.e2e.js', 'a plain (non --force) run must never delete the legacy file');

        $withForce = new PlaywrightTestGenerator('Items', 'Core', $config);
        $withForce->setForce(true);
        $withForce->generate();
        $this->assertFileDoesNotExist($dir . '/items.e2e.js');
        // The split files it was replaced by must actually exist.
        $this->assertFileExists($dir . '/items-create.e2e.js');
        $this->assertFileExists($dir . '/items-list.e2e.js');
        $this->assertFileExists($dir . '/items-view.e2e.js');
        $this->assertFileExists($dir . '/items-edit.e2e.js');
        $this->assertFileExists($dir . '/items-delete.e2e.js');
        $this->assertFileExists($dir . '/_fixtures.js');
    }

    /**
     * One generation later than the monolithic-file retirement above:
     * {module-route}-crud.e2e.js itself (the single-file list -> create ->
     * filter -> view -> edit -> delete spec) is superseded by the list/
     * create/view/edit/delete split, via deleteStaleCrudFileIfPresent() --
     * same safe "only under --force, only after the new files are written"
     * contract as deleteStaleMonolithicFileIfPresent().
     */
    public function test_deletes_stale_crud_file_only_under_force_after_split_files_are_written(): void
    {
        $config = $this->itemsWithDelegationAndActionsConfig();

        $dir = PathManager::getFrontendModulePath('Core', 'Items') . '/e2e';
        // Simulate a module that predates the list/create/view/edit/delete
        // split (the older, single combined `{module}-crud.e2e.js`).
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/items-crud.e2e.js', '// legacy combined crud file');

        $withoutForce = new PlaywrightTestGenerator('Items', 'Core', $config);
        $withoutForce->generate();
        $this->assertFileExists($dir . '/items-crud.e2e.js', 'a plain (non --force) run must never delete the legacy file');

        $withForce = new PlaywrightTestGenerator('Items', 'Core', $config);
        $withForce->setForce(true);
        $withForce->generate();
        $this->assertFileDoesNotExist($dir . '/items-crud.e2e.js');
        $this->assertFileExists($dir . '/items-create.e2e.js');
        $this->assertFileExists($dir . '/items-list.e2e.js');
        $this->assertFileExists($dir . '/items-view.e2e.js');
        $this->assertFileExists($dir . '/items-edit.e2e.js');
        $this->assertFileExists($dir . '/items-delete.e2e.js');
        $this->assertFileExists($dir . '/_fixtures.js');
    }

    // ── Bulk action / export / import steps (features.backend.list.{bulk_actions,export,import}) ──

    public function test_export_button_e2e_step_is_emitted_when_list_export_is_enabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['list']['export'] = true;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringContainsString('data-testid="locationtypes-export-open"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-export-csv"', $content);
        $this->assertStringContainsString("toContain('format=csv')", $content);
    }

    public function test_export_step_is_omitted_when_list_export_is_not_configured(): void
    {
        $config = $this->locationTypesConfig();
        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringNotContainsString('export-open', $content);
    }

    public function test_bulk_action_toolbar_e2e_step_is_emitted_for_each_configured_generic_bulk_action(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['list']['bulk_actions'] = [['key' => 'archive', 'label' => 'Archive']];

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringContainsString('data-testid^="locationtypes-bulk-select-"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-bulk-action-archive"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-bulk-confirm"', $content);
        $this->assertStringContainsString('data-testid="batch-result-drawer"', $content);
    }

    /**
     * ListPanel hides the row checkboxes until its "Select" (batch mode) toggle is
     * on, so the bulk step has to turn it on before its first checkbox click --
     * and only when it isn't already on (aria-pressed), so a list configured to
     * start in batch mode isn't toggled back off.
     */
    public function test_bulk_action_step_turns_batch_mode_on_before_clicking_a_row_checkbox(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['list']['bulk_actions'] = [['key' => 'archive', 'label' => 'Archive']];

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $toggle = strpos($content, 'data-testid="batch-mode-toggle"');
        $firstCheckbox = strpos($content, 'data-testid^="locationtypes-bulk-select-"');
        $this->assertNotFalse($toggle);
        $this->assertNotFalse($firstCheckbox);
        $this->assertLessThan($firstCheckbox, $toggle, 'the toggle must be flipped before any row checkbox is clicked');
        $this->assertStringContainsString("getAttribute('aria-pressed')) !== 'true'", $content);
    }

    /**
     * Regression test for a bug found + fixed 2026-08-08 while running all 5
     * generator-engine integration-test suites simultaneously against
     * SYSTEM_SHELL: both the bulk-action and import blocks used to close
     * the batch-result-drawer with `page.keyboard.press('Escape')` followed
     * by a blind `sleep(500)`, then immediately proceed to the next step.
     * Under load, the Sheet's close animation can still be mid fade-out
     * past 500ms -- confirmed live against a freshly generated
     * PurchaseOrders module: the very next click (view, ahead of Delete)
     * retried for the full 15s Playwright actionability timeout against
     * "<div data-slot=\"dialog-overlay\"> intercepts pointer events"
     * because the drawer had not actually finished closing. Both blocks
     * now wait for the drawer element itself to reach `state: 'hidden'`
     * instead of guessing a fixed delay.
     */
    public function test_bulk_action_and_import_blocks_wait_for_the_drawer_to_actually_close(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['list']['bulk_actions'] = [['key' => 'archive', 'label' => 'Archive']];
        $config['features']['backend']['list']['import'] = true;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringContainsString(
            "await page.locator('[data-testid=\"batch-result-drawer\"]').waitFor({ state: 'hidden', timeout: 15000 });",
            $content
        );
        $this->assertSame(
            2,
            substr_count($content, "await page.locator('[data-testid=\"batch-result-drawer\"]').waitFor({ state: 'hidden', timeout: 15000 });"),
            'expected one hidden-wait in the bulk-action block and one in the import block'
        );
    }

    public function test_bulk_action_e2e_step_is_omitted_when_bulk_actions_is_not_configured(): void
    {
        $config = $this->locationTypesConfig();
        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringNotContainsString('bulk-action-', $content);
        $this->assertStringNotContainsString('bulk-select-', $content);
    }

    public function test_import_upload_e2e_step_is_emitted_when_list_import_is_enabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['list']['import'] = true;

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringContainsString('data-testid="locationtypes-import-open"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-import-template-csv"', $content);
        $this->assertStringContainsString('setInputFiles(templatePath)', $content);
        $this->assertStringContainsString('data-testid="locationtypes-import-file"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-import-dry-run"', $content);
        $this->assertStringContainsString('data-testid="locationtypes-import-submit"', $content);

        // download.path() returns Playwright's internal temp file with no
        // extension; the shared import-modal file field (FileInputField)
        // applies a client-side accept-type check against the File object's
        // name, so the template must be re-saved under its suggested
        // filename (which does carry the real .csv extension) before being
        // fed back into the file input — otherwise the client-side check
        // silently rejects it and the import button never enables. Confirmed
        // live against a freshly generated PurchaseOrders module 2026-08-10.
        $this->assertStringContainsString('download.suggestedFilename()', $content);
        $this->assertStringContainsString('download.saveAs(', $content);
    }

    public function test_import_step_is_omitted_when_list_import_is_not_configured(): void
    {
        $config = $this->locationTypesConfig();
        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = (string) file_get_contents($this->generatedFilePath('list'));

        $this->assertStringNotContainsString('import-open', $content);
    }

    public function test_export_bulk_import_steps_do_not_appear_when_list_feature_itself_is_disabled(): void
    {
        // $backendList = $config['features']['backend']['list'] ?? [] — a
        // bare `false` (not an array) makes is_array($backendList) false,
        // so hasExport/hasBulkActions/hasImport are all correctly false
        // regardless of anything else in config, matching how the real
        // generator's own is_array() guard behaves (see the constructor).
        $config = $this->minimalModuleConfig();
        $config['features']['backend']['list'] = false;

        $generator = new PlaywrightTestGenerator('Widgets', 'Custom', $config);
        $generator->generate();

        // list.e2e.js is written unconditionally regardless of this flag
        // (every module has a list) -- it simply must contain none of the
        // bulk/export/import blocks.
        $path = PathManager::getFrontendModulePath('Custom', 'Widgets') . '/e2e/widgets-list.e2e.js';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringNotContainsString('export-open', $content);
        $this->assertStringNotContainsString('bulk-action-', $content);
        $this->assertStringNotContainsString('import-open', $content);
    }

    // ─── Select2-shaped filter fields (v2.37.0) ─────────────────────────────
    //
    // Found live against SYSTEM_SHELL's UserLocations module: a pure
    // junction table (user_id/location_id/role_id, no plain scalar create
    // field at all) with features.backend.list.filterFields left empty in
    // module.json, so resolveFilterFields() derives its filterFields from
    // filterableFields -- the exact fallback these fixtures exercise.
    // Before this fix, every derived entry was hardcoded 'type' => 'text',
    // so an FK filterable column looked exactly like a plain text filter to
    // pickTextFilterField()/buildFilterVariantB(), which called
    // setFilterTextValue() against a Select2/ApiSelect2 control that has no
    // `<input>` at all -- filters.js's setFilterTextValue() throws exactly
    // that diagnostic ("resolved to a <div>, not <input>").
    //
    // BaseServiceGenerator::generateFilterFields() (the REAL fallback the
    // running app's filter panel is built from) was fixed to be type-aware
    // on 2026-08-03; this class's OWN fallback (resolveFilterFields()) still
    // hardcoded 'text' and had silently drifted out of sync with it since.

    /** @return array<string, mixed> */
    private function fkOnlyModuleConfig(): array
    {
        return [
            'table_name' => 'assignments',
            'columns' => [
                ['name' => 'customer_id', 'type' => 'foreignId'],
                ['name' => 'status_id', 'type' => 'foreignId'],
            ],
            'features' => [
                'backend' => [
                    'list' => [
                        // Deliberately empty, exactly like UserLocations/module.json's
                        // real persisted filterFields -- forces resolveFilterFields()
                        // to derive from filterableFields below.
                        'filterFields' => [],
                        'filterableFields' => ['customer_id', 'status_id'],
                    ],
                    'create' => true,
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [
                    'list' => [
                        'primaryField' => 'customer_id',
                        'fields' => [
                            ['key' => 'customer_id', 'title' => 'Customer'],
                            ['key' => 'status_id', 'title' => 'Status'],
                        ],
                    ],
                    'create' => [
                        'fields' => [
                            // No plain scalar field at all -- both create fields are
                            // FK/api-select, matching UserLocations' own shape (a pure
                            // junction table). pickAnchorField() must resolve to null,
                            // which is exactly what routes the Filter block away from
                            // Variant A and into pickVisibleFilterField()/Variant B.
                            ['field' => 'customer_id', 'label' => 'Customer', 'field_type' => 'api-select', 'type' => 'text', 'required' => true],
                            ['field' => 'status_id', 'label' => 'Status', 'field_type' => 'api-select', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
            ],
        ];
    }

    /**
     * Bug (found 2026-08-16, live, running the retail-ERP demo fixture's own
     * generated e2e suite — a real, guaranteed "no option matching '1'"
     * failure, not a flaky one): this test used to assert that an FK-only
     * module (both filterable columns are foreign keys, no dot-path/resolved
     * -display list column for either) drives the Select2 filter helper by
     * capturing the target row's raw displayed cell text ("1", the raw id —
     * V1 never emits the dot-path list-column shape FK-column display
     * resolution needs, so the cell shows the id, not a name) and searching
     * the Select2 popup for an option literally labelled "1". That option
     * never exists — every real option label is a name, never a bare id —
     * so the filter step was a guaranteed failure for any module whose only
     * filterable columns are FKs, exactly this fixture's own shape. Fixed:
     * pickVisibleFilterField() now excludes an FK-typed candidate unless its
     * list column resolves via a dot-path (e.g. "status.name"), and
     * buildFilterBlock() skips the filter section entirely (with a clear log
     * line, not a doomed assertion) when no safe candidate survives that
     * filter — this fixture's exact case, since neither customer_id nor
     * status_id has a dot-path data shape.
     */
    public function test_fk_only_module_with_no_resolved_display_column_skips_the_filter_step(): void
    {
        $generator = new PlaywrightTestGenerator('Assignments', 'Core', $this->fkOnlyModuleConfig());
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Assignments') . '/e2e/assignments-list.e2e.js'
        );

        // Neither FK column is a safe Variant B target — no filter attempt
        // should be emitted for either, via any helper.
        $this->assertStringNotContainsString("setFilterSelect2Value(page, 'customer_id'", $content);
        $this->assertStringNotContainsString("setFilterSelect2Value(page, 'status_id'", $content);
        $this->assertStringNotContainsString("setFilterTextValue(page, 'customer_id'", $content);
        $this->assertStringNotContainsString("setFilterTextValue(page, 'status_id'", $content);

        // The generated spec says so explicitly, rather than silently
        // omitting filter coverage with no trace of why.
        $this->assertStringContainsString('filter: skipped', $content);
    }

    /**
     * Regression guard: a plain scalar filterable column, ALSO derived
     * through the same resolveFilterFields() fallback, must still resolve to
     * 'text' and drive the ORIGINAL plain-text filter helper — the type-
     * awareness fix must not change behaviour for the overwhelming majority
     * of already-correct modules (e.g. LocationTypes' own "name" filter,
     * covered by the "full spec" test above via a persisted fixture; this
     * test instead exercises the FALLBACK-DERIVATION path directly, which
     * that fixture's non-empty filterFields never touches).
     */
    public function test_plain_text_filterable_column_derived_from_filterable_fields_still_uses_plain_text_filter_value(): void
    {
        $config = $this->fkOnlyModuleConfig();
        $config['columns'][] = ['name' => 'reference', 'type' => 'string'];
        $config['features']['backend']['list']['filterableFields'] = ['reference', 'customer_id'];
        $config['features']['frontend']['list']['fields'][] = ['key' => 'reference', 'title' => 'Reference'];
        $config['features']['frontend']['create']['fields'][] = [
            'field' => 'reference', 'label' => 'Reference', 'field_type' => 'input', 'type' => 'text', 'required' => true,
        ];

        $generator = new PlaywrightTestGenerator('Assignments', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Assignments') . '/e2e/assignments-list.e2e.js'
        );

        // list.e2e.js always forces Variant B (its record comes from
        // _fixtures.js, with no createdRowText to drive Variant A) -- the
        // type-awareness fix under test here is that "reference", a plain
        // string column, resolves to buildFilterVariantB()'s PLAIN-TEXT
        // branch (setFilterOperator + setFilterTextValue against the
        // fixture row's own current cell value), not the Select2 branch.
        $this->assertStringContainsString('Variant B: visible column "Reference", field "reference"', $content);
        $this->assertStringContainsString("setFilterTextValue(page, 'reference', targetValue)", $content);
        $this->assertStringNotContainsString('createdRowText', $content);
        // setFilterSelect2Value is always present in the static import line
        // (see split.e2e.stub) — what must NOT appear is an actual call to it.
        $this->assertStringNotContainsString('setFilterSelect2Value(page,', $content);
    }

    // ─── JSON-column fields excluded from the e2e fill step (v2.37.0) ──────
    //
    // Defense-in-depth companion to IntrospectionToConfigTest's coverage of
    // the same bug: IntrospectionToConfig::buildFrontendFormFields() no
    // longer emits a create/edit field for a JSON column in a FRESHLY
    // introspected module, but an ALREADY-generated module.json predating
    // that fix (e.g. real UserLocations/module.json) is not rewritten by a
    // scoped e2e-only regenerate -- only by a full --force module
    // regenerate. This class must independently exclude a JSON-column field
    // even when the config it's handed still carries one, which is exactly
    // what UserLocations/module.json does today.

    public function test_json_column_field_is_excluded_from_the_create_fill_step(): void
    {
        $config = [
            'table_name' => 'user_locations',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'roles', 'type' => 'json'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'name', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => false,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            // Stale entry: a real module.json generated before the
                            // IntrospectionToConfig fix (e.g. UserLocations' own,
                            // persisted, unmodified by a scoped e2e-only regenerate)
                            // still carries this — field_type 'input' despite the
                            // column being JSON-typed and having no fillable control
                            // anywhere in the real form.
                            ['field' => 'roles', 'label' => 'Roles', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'roles', 'label' => 'Roles', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'delete' => false,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('UserRoleAssignments', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'UserRoleAssignments') . '/e2e/user-role-assignments-create.e2e.js'
        );

        // The JSON column must never be handed to fillField() (or any other
        // fill helper) -- it has no real DOM control to fill.
        $this->assertStringNotContainsString('#roles', $content);
        $this->assertStringNotContainsString('createValues.roles', $content);

        // The unrelated plain scalar field must still be filled normally --
        // the exclusion must be scoped to the JSON column only.
        $this->assertStringContainsString("fillField(page, '[role=\"dialog\"] #name', createValues.name)", $content);
    }

    /**
     * Regression test: constrainToColumnLength() only clamped columns shorter
     * than 40 characters, on the assumption that a generated value is
     * "~30-40 characters". That assumption breaks for a long module name plus
     * a long field label: PackSizeUnits' `abbreviation_singular` is a
     * varchar(50), but `E2E PackSizeUnits Abbreviation Singular ${stamp}` is
     * 53 characters with a 13-digit Date.now() stamp — over the backend's
     * `max:50` rule, so the create POST 422'd, the row never appeared, and the
     * spec timed out waiting for it. Confirmed live on a real 15-module scaffold.
     *
     * Any bounded column is now clamped. Clamping a varchar(255) is a harmless
     * no-op (slice(-255) of a 45-character string is the whole string), so the
     * guard bought nothing and cost a whole class of silent 422s.
     */
    public function test_string_field_value_is_clamped_to_any_bounded_column_not_only_those_under_40(): void
    {
        $config = [
            'table_name' => 'pack_size_units',
            'columns' => [
                ['name' => 'name_singular', 'type' => 'string', 'length' => '255'],
                ['name' => 'abbreviation_singular', 'type' => 'string', 'length' => '50'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'name_singular', 'type' => 'text']]],
                    'create' => true, 'view' => true, 'edit' => true, 'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name_singular'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name_singular', 'label' => 'Name Singular', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'abbreviation_singular', 'label' => 'Abbreviation Singular', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name_singular', 'label' => 'Name Singular', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                            ['field' => 'abbreviation_singular', 'label' => 'Abbreviation Singular', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('PackSizeUnits', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Custom', 'PackSizeUnits') . '/e2e/pack-size-units-create.e2e.js');

        // varchar(50): must be clamped -- this is the case the < 40 guard missed.
        $this->assertStringContainsString('.slice(-50).trimStart()', $content);

        // varchar(255): clamped too, harmlessly -- proves the guard is gone
        // rather than merely widened to some other arbitrary threshold.
        $this->assertStringContainsString('.slice(-255).trimStart()', $content);
    }

    /**
     * Regression test: the Variant B (FK / ApiSelect2) filter step asserted the
     * filtered list narrowed to exactly 1 row. A foreign key is legitimately
     * many-to-one, so that can never hold for a child table whose whole purpose
     * is many-per-parent. Confirmed live: an `item_images` table held two rows
     * both carrying item_id=1 -- one seeded as a cross-module fixture, one
     * created by the spec itself -- so filtering by that item correctly returned
     * 2 and the spec failed on a filter that was working.
     *
     * The correct invariant is that at least one row survives and every
     * surviving row carries the target value.
     */
    public function test_fk_filter_step_asserts_all_surviving_rows_match_rather_than_exactly_one_row(): void
    {
        $config = [
            'table_name' => 'item_images',
            'columns' => [
                ['name' => 'item_id', 'type' => 'foreignId', 'relatedModule' => 'Items'],
                ['name' => 'is_featured', 'type' => 'boolean'],
            ],
            'features' => [
                'backend' => [
                    'list' => [
                        'filterableFields' => ['item_id'],
                        'filterFields' => [['key' => 'item_id', 'type' => 'select']],
                    ],
                    'create' => true, 'view' => true, 'edit' => true, 'delete' => true,
                ],
                'frontend' => [
                    'list' => [
                        'primaryField' => 'item_id',
                        'fields' => [['key' => 'item_id', 'title' => 'Item', 'data' => 'item?.name', 'type' => 'text']],
                    ],
                    'create' => [
                        'fields' => [
                            ['field' => 'item_id', 'label' => 'Item', 'field_type' => 'api-select', 'type' => 'text', 'required' => true, 'api_url' => '/select/items'],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'item_id', 'label' => 'Item', 'field_type' => 'api-select', 'type' => 'text', 'required' => true, 'api_url' => '/select/items'],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('ItemImages', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Custom', 'ItemImages') . '/e2e/item-images-list.e2e.js');

        $this->assertStringContainsString('toBeGreaterThanOrEqual(1)', $content);
        $this->assertStringNotContainsString('to narrow the list to exactly 1 row', $content);
        // Every surviving row is checked, not just the first.
        $this->assertStringContainsString('must carry that value', $content);
    }

    /**
     * Regression: the three select-picker helpers waited a FIXED 300ms after
     * opening the picker (and 400ms after typing a search term) before reading
     * option rows. ApiSelect2 mounts a spinner, an empty state, or the option
     * list — mutually exclusively — while its fetch is in flight, and only the
     * list carries `.divide-y`/`.cursor-pointer` rows. Sampling after a fixed
     * delay therefore read whichever branch happened to be mounted and threw
     * "no selectable options" for data that simply had not arrived yet.
     */
    public function test_select_helpers_wait_for_the_picker_to_settle_instead_of_sleeping(): void
    {
        $rc = new \ReflectionClass(PlaywrightTestGenerator::class);
        $obj = $rc->newInstanceWithoutConstructor();

        foreach (['selectFieldHelperBlock', 'tryFillSelectFieldHelperBlock', 'morphSelectFieldHelperBlock'] as $method) {
            $rm = $rc->getMethod($method);
            $rm->setAccessible(true);
            $js = (string) $rm->invoke($obj);

            $this->assertStringNotContainsString(
                'setTimeout(r, 300)',
                $js,
                "{$method}() must not sample the option list after a fixed sleep"
            );
            $this->assertStringContainsString(
                ".locator('.animate-spin')",
                $js,
                "{$method}() must treat ApiSelect2's loading branch as 'not settled yet'"
            );
            $this->assertStringContainsString(
                'No results found|No options available',
                $js,
                "{$method}() must accept the empty state as a settled result, or it would spin for the full timeout"
            );
        }
    }

    /**
     * The settle loop must be bounded — a picker that never resolves has to
     * fall through to the helper's own "no options" error rather than hang the
     * spec until Playwright's global timeout kills it with a useless message.
     */
    public function test_select_helper_settle_loops_are_bounded(): void
    {
        $rc = new \ReflectionClass(PlaywrightTestGenerator::class);
        $obj = $rc->newInstanceWithoutConstructor();

        foreach (['selectFieldHelperBlock', 'tryFillSelectFieldHelperBlock', 'morphSelectFieldHelperBlock'] as $method) {
            $rm = $rc->getMethod($method);
            $rm->setAccessible(true);
            $js = (string) $rm->invoke($obj);

            $this->assertStringContainsString('Date.now() + 10000', $js, "{$method}() needs a deadline");
            $this->assertStringContainsString('Date.now() > deadline', $js, "{$method}() must break on that deadline");
        }
    }
}
