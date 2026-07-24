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

    private function generatedFilePath(): string
    {
        return PathManager::getFrontendModulePath('Core', 'LocationTypes') . '/e2e/location-types.e2e.js';
    }

    public function test_generate_writes_full_e2e_spec_for_a_module_with_every_feature_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = $this->generatedFilePath();
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        // Imports from the shared e2e helper files, via the fixed `#e2e-helpers/*`
        // subpath import map (SYSTEM_SHELL/FRONTEND/package.json "imports") rather
        // than a relative './helpers/...' path — the generated spec now lives
        // inside the module's own tree, at a nesting depth relative imports can't
        // reliably reach.
        $this->assertStringContainsString("from '#e2e-helpers/fixtures.js'", $content);
        $this->assertStringContainsString("from '#e2e-helpers/auth.js'", $content);
        $this->assertStringContainsString("from '#e2e-helpers/config.js'", $content);
        $this->assertStringContainsString("from '#e2e-helpers/filters.js'", $content);

        // test.describe / test( scaffold, gated by which steps are enabled.
        $this->assertStringContainsString("test.describe('location-types', () => {", $content);
        $this->assertStringContainsString(
            "test('create -> filter -> view -> edit -> delete cycle (auto-generated)'",
            $content
        );

        // ── Create block ─────────────────────────────────────────────────
        $this->assertStringContainsString('data-testid="locationtypes-create"', $content);
        $this->assertStringContainsString('[data-testid="locationtypes-submit"]', $content);
        $this->assertStringContainsString('E2E LocationTypes Name ${stamp}', $content);
        $this->assertStringContainsString('E2E LocationTypes Code ${stamp}', $content);
        $this->assertStringContainsString('E2E LocationTypes Color ${stamp}', $content);
        $this->assertStringContainsString("fillField(page, '[role=\"dialog\"] #name', createValues.name)", $content);

        // ── Filter block (Variant A: anchor field "name" doubles as the filter key) ──
        $this->assertStringContainsString('Variant A: plain text field "name"', $content);
        $this->assertStringContainsString("setFilterTextValue(page, 'name', createdRowText)", $content);

        // ── View block ────────────────────────────────────────────────────
        $this->assertStringContainsString('locationtypes-view-${recordUuid}', $content);

        // ── Edit block (first non-anchor scalar edit field: "code") ─────────
        $this->assertStringContainsString('locationtypes-edit-${recordUuid}', $content);
        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #code'", $content);
        $this->assertStringContainsString('E2E LocationTypes Code EDIT ${stamp}', $content);

        // ── Delete block ──────────────────────────────────────────────────
        $this->assertStringContainsString("name: 'More Actions'", $content);
        $this->assertStringContainsString('locationtypes-delete-${recordUuid}', $content);
        $this->assertStringContainsString('[data-testid="locationtypes-confirm-delete"]', $content);

        // Helper functions gated by feature/field-type flags.
        $this->assertStringContainsString('async function fillField(page, selector, value)', $content);
        $this->assertStringContainsString('function rowLocator(page, text)', $content);
        $this->assertStringContainsString('function uuidFromTestId(testId, action)', $content);
        $this->assertStringContainsString('async function cleanupStrayRecord(page, uuid)', $content);
        // No select-type field anywhere in LocationTypes -> select helper must be absent.
        $this->assertStringNotContainsString('async function fillSelectField(', $content);

        // Regression: the View/Edit/Delete block content that buildTestBody() wraps
        // in a try/finally (whenever hasDelete is true) must be indented one level
        // deeper than the try/finally lines themselves — not left flush against
        // them. Found live via a Tier 3 make:module smoke test: the wrapped body was
        // emitted at the same 2-tab depth as `try {`/`} finally {` instead of 3.
        $this->assertStringContainsString("\t\ttry {\n\t\t\t// ── View", $content);
        $this->assertStringNotContainsString("\t\ttry {\n\t\t// ── View", $content);
    }

    public function test_generate_omits_delete_step_when_delete_feature_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['frontend']['delete'] = false;
        $config['features']['backend']['delete'] = false; // inert for this generator; toggled for parity

        $generator = new PlaywrightTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        // Delete step, its confirm button, and its cleanup helper must be gone.
        $this->assertStringNotContainsString('locationtypes-delete-${recordUuid}', $content);
        $this->assertStringNotContainsString('locationtypes-confirm-delete', $content);
        $this->assertStringNotContainsString("name: 'More Actions'", $content);
        $this->assertStringNotContainsString('async function cleanupStrayRecord(', $content);
        $this->assertStringNotContainsString('recordCleanedUp', $content);

        $this->assertStringContainsString(
            "test('create -> filter -> view -> edit cycle (auto-generated)'",
            $content
        );

        // Create/View/Edit/Filter must all remain untouched.
        $this->assertStringContainsString('data-testid="locationtypes-create"', $content);
        $this->assertStringContainsString('locationtypes-view-${recordUuid}', $content);
        $this->assertStringContainsString('locationtypes-edit-${recordUuid}', $content);
        $this->assertStringContainsString("setFilterTextValue(page, 'name', createdRowText)", $content);
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

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'Items') . '/e2e/items.e2e.js');

        // The select helper must be emitted (an 'api-select' field is present)...
        $this->assertStringContainsString('async function fillSelectField(', $content);
        // ...and actually used to fill the FK field, by its label, in the create block.
        $this->assertStringContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Item Type')", $content);

        // The FK field must NEVER be handed to the plain-input filler.
        $this->assertStringNotContainsString('#item_type_id', $content);

        // pickEditField() must skip the FK field too (isScalarField() must
        // treat 'api-select' as non-scalar). "name" is both the anchor field
        // and the only scalar edit field declared, so with item_type_id
        // correctly excluded, pickEditField()'s second (anchor-inclusive)
        // fallback loop picks "name" — never the FK — confirming
        // isScalarField('api-select') now returns false rather than
        // silently letting the edit test try to fillField() the FK.
        $this->assertStringContainsString("setInputValue(page, '[role=\"dialog\"] #name'", $content);
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
     * Since the field is genuinely optional, the correct fix is to never
     * attempt a selection for it at all — renderFieldFill() now checks
     * `$field['required']` before emitting the fillSelectField() call.
     */
    public function test_optional_relation_field_is_left_unset_not_forced_through_the_select_helper(): void
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
            PathManager::getFrontendModulePath('Core', 'ItemCategories') . '/e2e/item-categories.e2e.js'
        );

        // Optional relation field must never be forced through the picker...
        $this->assertStringNotContainsString("fillSelectField(page, '[role=\"dialog\"]', 'Parent')", $content);
        // ...but the required plain field must still be filled normally.
        $this->assertStringContainsString('#name', $content);
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

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemPrices') . '/e2e/item-prices.e2e.js');

        // Create value: a real computed ISO date, never the generic template.
        $this->assertStringContainsString('effective_date: new Date().toISOString().slice(0, 10)', $content);
        $this->assertStringNotContainsString('effective_date: `E2E', $content);

        // Edit value: also a real computed date (offset, so it's distinguishable), never the generic template.
        $this->assertStringContainsString('new Date(Date.now() + 86400000).toISOString().slice(0, 10)', $content);
        $this->assertStringNotContainsString('EDIT ${stamp}', $content);
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

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images.e2e.js');

        // Helper emitted (gated on hasFieldType('number-input')) and used for create.
        $this->assertStringContainsString('async function fillNumberField(page, selector, value)', $content);
        $this->assertStringContainsString("fillNumberField(page, '[role=\"dialog\"] #sort_order', createValues.sort_order)", $content);
        $this->assertStringNotContainsString("fillField(page, '[role=\"dialog\"] #sort_order'", $content);

        // Edit block: comma-tolerant, type-safe comparison, not a bare `!==`.
        $this->assertStringContainsString('const normalizeForCompare = (v) => String(v).replace(/,/g, \'\');', $content);
        $this->assertStringContainsString("if (normalizeForCompare(editedActual) !== normalizeForCompare(editedValue))", $content);
        $this->assertStringNotContainsString('if (editedActual !== editedValue)', $content);
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

        $content = (string) file_get_contents(PathManager::getFrontendModulePath('Core', 'ItemImages') . '/e2e/item-images.e2e.js');

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
}
