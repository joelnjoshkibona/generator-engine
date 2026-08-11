<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found alongside today's file-upload
 * validation work: the edit/form.stub's onMounted load-existing-record logic
 * did `form.value = { ...form.value, ...response.data }`, unconditionally
 * merging EVERY key of the loaded record into `form.value` -- including
 * file-input-typed fields (e.g. `image_media_id`, a plain integer column).
 *
 * File-input fields are correctly excluded from `form`'s initial `ref({...})`
 * declaration (see BaseComponentGenerator::generateFormFields()) precisely
 * because a raw column value isn't a valid `File` and a separate
 * `ref<File|null>` (see fileRefName()/generateFileRefsBlock()) is the real
 * source of truth for the upload. But the wholesale response.data merge
 * re-introduced that same excluded key into form.value on every mount, so a
 * plain edit-without-reupload submitted the record's raw `image_media_id`
 * integer back to the backend and failed its `["nullable","file"]`
 * validation rule with a 422.
 *
 * Fix: the onMounted merge now only copies response.data keys that are
 * already present on `form.value` (i.e. keys form was actually declared
 * with), so file-input keys -- never declared on form to begin with -- can
 * never be written back in, regardless of what the loaded record contains.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateFormFields()
 */
class EditFormGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-editformgen-test-' . uniqid();
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
    private function itemImagesConfig(): array
    {
        return [
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
    }

    public function test_onmounted_only_merges_keys_already_declared_on_form_not_the_whole_loaded_record(): void
    {
        $config = $this->itemImagesConfig();

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        // The old, unconditional wholesale merge must be gone.
        $this->assertStringNotContainsString('form.value = { ...form.value, ...response.data }', $content);

        // Replaced by a merge that filters response.data down to keys already on form.value.
        $this->assertStringContainsString('const loadedData: Record<string, any> = {}', $content);
        $this->assertStringContainsString('if (key in response.data) loadedData[key] = response.data[key]', $content);
        $this->assertStringContainsString('form.value = { ...form.value, ...loadedData }', $content);

        // `image_media_id` (the file-input field) must never be declared on
        // form's initial ref -- it's the reason the wholesale merge was
        // unsafe to begin with (see generateFormFields()'s file-input skip).
        $this->assertStringNotContainsString('image_media_id:', $content);

        // The separate File ref exists and is the only place image_media_id-shaped
        // data is tracked client-side (fileRefName('image_media_id') ->
        // 'imageMediaIdFile': the key doesn't end in one of the recognized
        // file-ish suffixes, so fileRefName() appends "File" to the camelCase key).
        $this->assertStringContainsString('const imageMediaIdFile = ref<File | null>(null)', $content);
    }

    public function test_form_without_any_file_input_field_still_gets_the_filtered_merge(): void
    {
        $config = $this->itemImagesConfig();
        // Strip the file-input field entirely -- the fix lives in the shared
        // stub template, so a form with zero file-input fields must get the
        // exact same filtered-merge code (harmless no-op filtering there,
        // since every response.data key it cares about is already on form).
        unset($config['features']['frontend']['create']['fields'][1]);
        unset($config['features']['frontend']['edit']['fields'][1]);

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('form.value = { ...form.value, ...loadedData }', $content);
        $this->assertStringNotContainsString('form.value = { ...form.value, ...response.data }', $content);
    }

    /**
     * Bug (fixed 2026-08-02): create/form.stub has always shipped a
     * `disabledFieldsList`/`isFieldDisabled()` block (driven by a
     * `disabledFields` prop, auto-populated from `props.defaults`) that every
     * field stub's `:disabled` binding now calls into -- but edit/form.stub
     * never had this block at all, so an EditForm passed `defaults` (e.g. a
     * delegated/related-record create-with-prefill flow) could never
     * auto-disable the pre-filled fields the way CreateForm already can.
     *
     * Fix: port the same `disabledFields` prop + `disabledFieldsList` ref +
     * `isFieldDisabled()` helper into edit/form.stub, and wire the
     * auto-populate-from-defaults loop into the point Edit already applies
     * `props.defaults` (after the existing record load, not at mount like
     * Create -- Edit only knows what to auto-disable once the loaded record
     * exists to be overridden).
     *
     * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator
     */
    public function test_edit_form_gains_disabled_fields_list_and_auto_populates_from_defaults_after_load(): void
    {
        $config = $this->itemImagesConfig();

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $content = (string) file_get_contents($path);

        // New prop, matching CreateForm's.
        $this->assertStringContainsString('disabledFields: {default: () => {return []}},', $content);

        // New ref + helper, matching CreateForm's.
        $this->assertStringContainsString('const disabledFieldsList = ref<string[]>([...props.disabledFields])', $content);
        $this->assertStringContainsString("const isFieldDisabled = (fieldName: string) => {\n\treturn disabledFieldsList.value.includes(fieldName)\n}", $content);

        // Auto-populate loop must be nested inside the EXISTING props.defaults
        // application, which itself lives inside the loaded-record branch --
        // not floating in onMounted's top level the way Create's does.
        $this->assertStringContainsString(
            "form.value = { ...form.value, ...props.defaults }\n\t\t\t// Auto-populate disabledFields if defaults are provided and no explicit disabledFields\n\t\t\tif (props.disabledFields.length === 0) {",
            $content
        );
    }

    /**
     * Bug (found + fixed 2026-08-08 while running all 5 generator-engine
     * integration-test suites simultaneously against SYSTEM_SHELL):
     * generateFormFields() joins fields with ",\n" and never trails the
     * last one with a comma, but the inline_items append here used to tack
     * `{key}: [] as any[],` straight onto $formFields with no comma in
     * between -- e.g. `notes: ''` immediately followed by
     * `order_items: [] as any[],` with nothing separating them. That is a
     * hard Vue SFC compile error (`[vue/compiler-sfc] Unexpected token,
     * expected ","`), confirmed live: it broke OrdersEditForm.vue outright
     * and, via Vite's global HMR error overlay, cascaded into unrelated
     * modules' e2e test failures in the same dev-server session.
     *
     * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator
     * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateFormFields()
     */
    public function test_inline_items_field_is_comma_separated_from_the_last_regular_field(): void
    {
        $config = $this->itemImagesConfig();
        // Strip the file-input field -- it's excluded from $formFields
        // entirely, so 'caption' ends up the sole (and therefore "last")
        // regular field, exactly reproducing the real Orders shape where
        // the last declared field is a plain scalar with no trailing comma.
        unset($config['features']['frontend']['create']['fields'][1]);
        unset($config['features']['frontend']['edit']['fields'][1]);
        $config['inline_items'] = [
            ['key' => 'order_items', 'label' => 'Order Items', 'primary_field' => 'product_name'],
        ];

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $content = (string) file_get_contents($path);

        // The exact broken shape must never reappear: the last regular
        // field's line immediately followed by the inline_items field with
        // no comma between them.
        $this->assertStringNotContainsString("caption: form.value.caption ?? ''\n\torder_items: [] as any[],", $content);
        $this->assertMatchesRegularExpression(
            "/caption:[^\\n]*,\\s*\\n\\torder_items: \\[\\] as any\\[\\],/",
            $content
        );
    }

    // ─── Draft autosave -- on by default, opt-out via features.frontend.edit.drafts (v2.51.2) ──
    // See CreateFormGeneratorTest's matching pair for the create-side coverage
    // and full rationale. Edit differs in two ways verified here: the
    // useDraft() call passes props.uuid as its record_key, and
    // checkForDraft() is called from inside the loaded-record branch, not
    // top-level onMounted.

    public function test_draft_wiring_is_generated_by_default(): void
    {
        $config = $this->itemImagesConfig();
        unset($config['features']['frontend']['create']['fields'][1]);
        unset($config['features']['frontend']['edit']['fields'][1]);

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('<DraftRestoreBanner', $content);
        $this->assertStringContainsString("useDraft('ItemImages', 'Core', 'edit', props.uuid)", $content);
        $this->assertStringContainsString('data-testid="itemimages-save-draft"', $content);
        $this->assertStringContainsString('discardDraft()', $content);
        $this->assertStringContainsString('await checkForDraft()', $content);
        // checkForDraft() must sit inside the loaded-record merge, after
        // loadedData is applied to form.value -- not at onMounted's top level.
        $this->assertStringContainsString(
            "form.value = { ...form.value, ...loadedData }\n\t\tawait checkForDraft()",
            $content
        );
        $this->assertStringContainsString('scheduleDraftSave(value)', $content);

        // A field left blank when the draft was saved round-trips through
        // the backend's global ConvertEmptyStringsToNull middleware as
        // null, not '' -- restoreDraft()'s merge must coerce it back, same
        // as the loaded-record path already does, or InputField's
        // String|Number-only modelValue warns/breaks on every blank field
        // a restored draft had (found live 2026-08-10).
        $this->assertStringContainsString(
            "form.value = { ...form.value, ...draftPayload.value }\n\t\t// A field left blank",
            $content
        );
    }

    public function test_draft_wiring_is_omitted_when_explicitly_disabled(): void
    {
        $config = $this->itemImagesConfig();
        unset($config['features']['frontend']['create']['fields'][1]);
        unset($config['features']['frontend']['edit']['fields'][1]);
        $config['features']['frontend']['edit']['drafts'] = false;

        $generator = new EditFormGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'ItemImages') . '/Components/ItemImagesEditForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('DraftRestoreBanner', $content);
        $this->assertStringNotContainsString('useDraft', $content);
        $this->assertStringNotContainsString('save-draft', $content);
        $this->assertStringNotContainsString('scheduleDraftSave', $content);
    }
}
