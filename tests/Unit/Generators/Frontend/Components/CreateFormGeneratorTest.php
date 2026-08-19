<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-08 while running all 5
 * generator-engine integration-test suites simultaneously against a real
 * consuming project (SYSTEM_SHELL): generateFormFields() joins fields with
 * ",\n" and never trails the last one with a comma, but the inline_items
 * append here used to tack `{key}: [] as any[],` straight onto $formFields
 * with no comma in between -- a hard Vue SFC compile error confirmed live
 * (`[vue/compiler-sfc] Unexpected token, expected ","`) that broke
 * OrdersCreateForm.vue outright and, via Vite's global HMR error overlay,
 * cascaded into unrelated modules' e2e test failures in the same
 * dev-server session.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateFormFields()
 */
class CreateFormGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-createformgen-test-' . uniqid();
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
    private function ordersLikeConfig(): array
    {
        return [
            'table_name' => 'orders',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'notes'],
                    'create' => [
                        'fields' => [
                            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
            'inline_items' => [
                ['key' => 'order_items', 'label' => 'Order Items', 'primary_field' => 'product_name'],
            ],
        ];
    }

    public function test_inline_items_field_is_comma_separated_from_the_last_regular_field(): void
    {
        $config = $this->ordersLikeConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        // The exact broken shape must never reappear: the last regular
        // field's line immediately followed by the inline_items field with
        // no comma between them.
        $this->assertStringNotContainsString("notes: ''\n\torder_items: [] as any[],", $content);
        $this->assertMatchesRegularExpression(
            "/notes: ''.*,\\s*\\n\\torder_items: \\[\\] as any\\[\\],/",
            $content
        );
    }

    public function test_form_with_no_regular_fields_still_produces_valid_leading_inline_items_field(): void
    {
        $config = $this->ordersLikeConfig();
        unset($config['features']['frontend']['create']['fields']);
        $config['features']['frontend']['create']['fields'] = [];

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // No leading stray comma when there are zero regular fields.
        $this->assertStringNotContainsString(",\n\torder_items: [] as any[],", $content);
        $this->assertStringContainsString("\n\torder_items: [] as any[],", $content);
    }

    /**
     * Regression coverage for a bug found + fixed 2026-08-16: the Save
     * Draft/Create footer buttons used to be baked into the "Main Details"
     * card's own closing HTML (generateFormSection()'s $footerHtml param),
     * and the inline_items block was spliced in as a LATER sibling in the
     * template -- so on any module with inline_items, the Items card
     * rendered visually BELOW the submit buttons instead of above them.
     * Confirmed live on a real generated Expenses create page. Fixed by
     * emitting the footer as its own [[formFooter]] token, placed after
     * [[inlineItemsBlock]] in create/form.stub.
     */
    public function test_inline_items_block_renders_before_the_footer_buttons(): void
    {
        $config = $this->ordersLikeConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $inlineItemsPos = strpos($content, 'v-model="form.order_items"');
        $footerPos = strpos($content, 'data-testid="orders-submit"');

        $this->assertIsInt($inlineItemsPos, 'inline_items block not found in generated output');
        $this->assertIsInt($footerPos, 'footer submit button not found in generated output');
        $this->assertLessThan($footerPos, $inlineItemsPos, 'inline_items block must render BEFORE the footer buttons, not after');
    }

    /**
     * Bug (found live 2026-08-18, PurchaseOrders' modal edit form): modal
     * mode's inline_items wrapper carried `px-4 mt-2` -- horizontal padding
     * and a TOP margin, but nothing on the bottom. Non-modal mode gets
     * bottom padding for free from its inner content div's own unconditional
     * `p-4`, but in modal mode the inline_items block is typically the LAST
     * thing before the form's footer bar, so the "+ Add Item" button
     * rendered flush against it with zero breathing room.
     */
    public function test_inline_items_modal_wrapper_has_bottom_padding(): void
    {
        $config = $this->ordersLikeConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "<div :class=\"!modal ? 'rounded-md border overflow-hidden mt-2' : 'px-4 pb-4 mt-2'\">",
            $content,
            'The modal branch of the inline_items wrapper must carry bottom padding, not just horizontal padding and a top margin.'
        );
    }

    // ─── Draft autosave -- on by default, opt-out via features.frontend.create.drafts (v2.51.2) ──
    //
    // "Save as Draft" wiring (DraftRestoreBanner + Save Draft button +
    // useDraft() composable calls) was previously only hand-wired into
    // Users' own CreateForm as a reference implementation -- every other
    // generated module got none of it. Now on by default for every module,
    // real end-to-end generation verified here (not just placeholder
    // substitution logic in isolation).

    public function test_draft_wiring_is_generated_by_default(): void
    {
        $config = $this->ordersLikeConfig();
        unset($config['inline_items']);

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // Create forms get the multi-draft picker (DraftListPanel), not the
        // single-slot DraftRestoreBanner edit forms use -- see
        // BaseComponentGenerator::buildCreateDraftBlocks()'s docblock.
        $this->assertStringContainsString('<DraftListPanel', $content);
        $this->assertStringContainsString("import { useDraft, useDraftList } from '@/composables/useDraft';", $content);
        $this->assertStringContainsString("import DraftListPanel from '@/components/DraftListPanel.vue';", $content);
        $this->assertStringContainsString("useDraftList('Orders', 'Custom')", $content);
        $this->assertStringContainsString("useDraft('Orders', 'Custom', 'create', activeDraftKey)", $content);
        $this->assertStringContainsString('data-testid="orders-save-draft"', $content);
        $this->assertStringContainsString('discardDraft()', $content);
        $this->assertStringContainsString('activeDraftKey.value = newDraftKey()', $content);
        $this->assertStringContainsString('await loadDrafts()', $content);
        $this->assertStringContainsString('handleResumeDraft', $content);
        $this->assertStringContainsString('handleDeleteDraft', $content);
        $this->assertStringContainsString('scheduleDraftSave(value)', $content);
        $this->assertStringContainsString(', watch} from "vue"', $content);

        // A field left blank when the draft was saved round-trips through
        // the backend's global ConvertEmptyStringsToNull middleware as
        // null, not '' -- handleResumeDraft()'s merge must coerce it back,
        // or InputField's String|Number-only modelValue warns/breaks on
        // every blank field a resumed draft had (found live 2026-08-10).
        $this->assertStringContainsString(
            "form.value = { ...form.value, ...draftPayload.value }\n\t\t// A field left blank",
            $content
        );
    }

    public function test_draft_wiring_is_omitted_when_explicitly_disabled(): void
    {
        $config = $this->ordersLikeConfig();
        unset($config['inline_items']);
        $config['features']['frontend']['create']['drafts'] = false;

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('DraftListPanel', $content);
        $this->assertStringNotContainsString('useDraft', $content);
        $this->assertStringNotContainsString('save-draft', $content);
        $this->assertStringNotContainsString('scheduleDraftSave', $content);
        $this->assertStringNotContainsString(', watch} from "vue"', $content);
    }

    // ─── Wizard mode -- multi-step create/edit forms, additive/opt-in ───────
    //
    // features.frontend.create.wizard.{enabled,steps[]} lets a module's flat
    // create fields[] be presented as steps instead of one flat form.
    // Final-submit-only for this pass: steps only gate visible fields, the
    // underlying form/submit call are untouched. See
    // BaseComponentGenerator::generateWizardSteps()'s docblock.

    /** @return array<string, mixed> */
    private function wizardConfig(): array
    {
        $config = $this->ordersLikeConfig();
        $config['features']['frontend']['create']['fields'] = [
            ['field' => 'reference', 'label' => 'Reference', 'field_type' => 'input', 'type' => 'text', 'required' => true],
            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
        ];
        $config['features']['frontend']['create']['wizard'] = [
            'enabled' => true,
            'submission_mode' => 'final',
            'steps' => [
                ['id' => 'header', 'label' => 'Header', 'field_keys' => ['reference']],
                ['id' => 'items', 'label' => 'Items', 'field_keys' => ['order_items']],
                ['id' => 'review', 'label' => 'Review', 'field_keys' => ['notes']],
            ],
        ];

        return $config;
    }

    public function test_wizard_disabled_or_absent_produces_byte_identical_output_to_non_wizard(): void
    {
        $baseConfig = $this->ordersLikeConfig();

        $withoutWizardKey = new CreateFormGenerator('Orders', 'Custom', $baseConfig);
        $this->assertTrue($withoutWizardKey->generate());
        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $contentWithoutKey = (string) file_get_contents($path);

        unlink($path);

        $explicitlyDisabled = $baseConfig;
        $explicitlyDisabled['features']['frontend']['create']['wizard'] = ['enabled' => false, 'submission_mode' => 'final', 'steps' => []];
        $withDisabled = new CreateFormGenerator('Orders', 'Custom', $explicitlyDisabled);
        $withDisabled->setForce(true);
        $this->assertTrue($withDisabled->generate());
        $contentWithDisabled = (string) file_get_contents($path);

        $this->assertSame($contentWithoutKey, $contentWithDisabled, 'wizard:false must generate byte-identical output to omitting the key entirely');
        $this->assertStringNotContainsString('Stepper', $contentWithoutKey);
        $this->assertStringNotContainsString('wizardSteps', $contentWithoutKey);
    }

    public function test_wizard_enabled_renders_stepper_and_gates_fields_by_step(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("import { Stepper } from '@/components/ui/stepper';", $content);
        $this->assertStringContainsString('<Stepper :steps="wizardSteps" :current-step="currentStep" :completed-steps="completedSteps" @step-click="goToStep" />', $content);
        $this->assertStringContainsString("{ id: 'header', label: 'Header' }", $content);
        $this->assertStringContainsString('const currentStep = ref(0)', $content);
        $this->assertStringContainsString('const goNext = () => {', $content);
        $this->assertStringContainsString('const goBack = () => {', $content);

        // Step gating: each step's own fields must sit inside its own
        // v-if="currentStep === N" block, not all rendered together.
        $this->assertStringContainsString('v-if="currentStep === 0"', $content);
        $this->assertStringContainsString('v-if="currentStep === 1"', $content);
        $this->assertStringContainsString('v-if="currentStep === 2"', $content);

        $step0Pos = strpos($content, 'v-if="currentStep === 0"');
        $referencePos = strpos($content, 'v-model="form.reference"');
        $notesPos = strpos($content, 'v-model="form.notes"');
        $step2Pos = strpos($content, 'v-if="currentStep === 2"');
        $this->assertIsInt($referencePos);
        $this->assertIsInt($notesPos);
        $this->assertGreaterThan($step0Pos, $referencePos, 'reference field must be inside step 0');
        $this->assertLessThan($step2Pos, $referencePos, 'reference field must NOT be inside step 2 (or later)');
        $this->assertGreaterThan($step2Pos, $notesPos, 'notes field must be inside step 2, not step 0');
    }

    /**
     * Bug (found live 2026-08-18, PurchaseOrders' modal create form): the
     * wizard wrapper's `p-4` used to be bundled with the `!modal` border
     * chrome (`!modal ? 'rounded-md border overflow-hidden p-4' : ''`), and a
     * step's own fields grid had no padding of its own -- so in modal mode
     * (the class resolves to '') the Stepper and every step's fields
     * rendered flush against the dialog edge. generateFormSection() already
     * gets this right (`p-4` baked directly onto its grid div,
     * unconditionally) -- this asserts generateWizardSteps() now matches
     * that same always-padded convention.
     */
    public function test_wizard_padding_is_unconditional_not_bundled_with_the_modal_border_chrome(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : ''\">",
            $content,
            'The outer wrapper must stay chrome-only (border is genuinely modal-conditional) -- padding must not be bundled into this class.'
        );
        $this->assertStringNotContainsString(
            "rounded-md border overflow-hidden p-4",
            $content,
            'Padding must never be bundled with the modal-conditional border chrome -- that leaves modal mode with zero padding.'
        );
        $this->assertStringContainsString(
            '<div class="p-4 pb-0">',
            $content,
            'The Stepper must sit inside its own unconditional padding wrapper.'
        );
        $this->assertStringContainsString(
            'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4',
            $content,
            "Each step's own fields grid must carry unconditional padding, matching generateFormSection()'s equivalent grid."
        );
    }

    public function test_wizard_step_referencing_an_inline_items_key_renders_it_inside_that_step_not_the_catch_all(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // Rendered exactly once (inside step 1), never duplicated into the
        // old catch-all [[inlineItemsBlock]] slot as well.
        $occurrences = substr_count($content, 'v-model="form.order_items"');
        $this->assertSame(1, $occurrences, 'inline_items claimed by a wizard step must render exactly once, not duplicated');

        $step1Pos = strpos($content, 'v-if="currentStep === 1"');
        $step2Pos = strpos($content, 'v-if="currentStep === 2"');
        $inlineItemsPos = strpos($content, 'v-model="form.order_items"');
        $this->assertIsInt($inlineItemsPos);
        $this->assertGreaterThan($step1Pos, $inlineItemsPos, 'order_items must render inside step 1');
        $this->assertLessThan($step2Pos, $inlineItemsPos, 'order_items must render before step 2 starts');
    }

    public function test_wizard_unclaimed_inline_items_still_render_via_the_catch_all_block(): void
    {
        $config = $this->wizardConfig();
        // Second inline_items entry, never referenced by any step's field_keys.
        $config['inline_items'][] = ['key' => 'shipments', 'label' => 'Shipments', 'primary_field' => 'tracking_number'];

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // Claimed item: exactly once, inside its step.
        $this->assertSame(1, substr_count($content, 'v-model="form.order_items"'));
        // Unclaimed item: must NOT silently vanish -- still rendered somewhere.
        $this->assertStringContainsString('v-model="form.shipments"', $content);
    }

    public function test_wizard_footer_shows_next_on_non_final_steps_and_real_submit_on_the_last_step(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('v-if="currentStep > 0"', $content);
        $this->assertStringContainsString('@click="goBack"', $content);
        $this->assertStringContainsString('v-if="currentStep < wizardSteps.length - 1"', $content);
        $this->assertStringContainsString('@click="goNext"', $content);
        // The real submit button (identical to a non-wizard form's) still
        // exists, gated to only the final step via v-else.
        $this->assertStringContainsString('<Button type="submit" v-else size="sm" data-testid="orders-submit"', $content);
        $this->assertStringContainsString('sendPostRequest(submitEndpoint.value, form.value)', $content);
    }

    public function test_wizard_goNext_calls_save_draft_when_drafts_enabled(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $goNextPos = strpos($content, 'const goNext = () => {');
        $goBackPos = strpos($content, 'const goBack = () => {');
        $this->assertIsInt($goNextPos);
        $this->assertIsInt($goBackPos);
        $saveDraftCallPos = strpos($content, 'saveDraft(form.value)', $goNextPos);
        $this->assertIsInt($saveDraftCallPos, 'goNext() must call the existing saveDraft() when drafts are enabled');
        $this->assertLessThan($goBackPos, $saveDraftCallPos, 'the saveDraft() call must be inside goNext(), not goBack()');
    }

    public function test_wizard_goNext_omits_save_draft_call_when_drafts_disabled(): void
    {
        $config = $this->wizardConfig();
        $config['features']['frontend']['create']['drafts'] = false;

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('saveDraft(form.value)', $content);
        // Wizard navigation itself must still work with drafts off.
        $this->assertStringContainsString('const goNext = () => {', $content);
    }

    public function test_php_lints_clean_with_wizard_mode(): void
    {
        // Vue SFCs aren't PHP, but the generator method itself must never
        // throw/warn while building the string -- exercised via a real
        // generate() call plus a structural sanity check (no unresolved
        // [[placeholder]] tokens left in the output).
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    // ─── confirm_step: default-on for wizards, opt-in for flat forms ────────

    public function test_wizard_defaults_to_a_confirm_step_when_confirm_step_key_is_absent(): void
    {
        // wizardConfig() has no confirm_step key at all -- must default ON.
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // 3 configured steps (indices 0-2) -- confirm is appended as index 3.
        $this->assertStringContainsString("{ id: 'confirm', label: 'Review & Confirm' }", $content);
        $this->assertStringContainsString('v-if="currentStep === 3"', $content);
        $this->assertStringContainsString("import CheckboxField from '@/components/form-fields/CheckboxField.vue';", $content);
        $this->assertStringContainsString('const confirmed = ref(false)', $content);
        $this->assertStringContainsString('<CheckboxField id="wizard-confirm"', $content);
        $this->assertStringContainsString(':disabled="isSubmitting || !confirmed"', $content);
    }

    public function test_wizard_confirm_step_can_be_explicitly_disabled(): void
    {
        $config = $this->wizardConfig();
        $config['features']['frontend']['create']['confirm_step'] = ['enabled' => false];

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString("{ id: 'confirm'", $content);
        $this->assertStringNotContainsString('CheckboxField', $content);
        $this->assertStringNotContainsString('const confirmed', $content);
        $this->assertStringContainsString(':disabled="isSubmitting"', $content);
    }

    public function test_wizard_confirm_step_summarizes_plain_fields_and_inline_items(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // Plain field summary: label + the field's own form.key expression.
        $this->assertStringContainsString("<span class=\"text-muted-foreground\">Reference:</span> {{ form.reference || '—' }}", $content);
        $this->assertStringContainsString("<span class=\"text-muted-foreground\">Notes:</span> {{ form.notes || '—' }}", $content);
        // inline_items summary: generically knowable as a count, not a full
        // per-row render (no per-domain logic in the generator for this).
        $this->assertStringContainsString("<span class=\"text-muted-foreground\">Order Items:</span> {{ form.order_items.length }} item(s)", $content);
        // Extension point for hand-built custom step content on actions.
        $this->assertStringContainsString('<!-- Custom step summaries -- add one <div> per hand-built step here. -->', $content);
    }

    public function test_flat_form_has_no_confirm_checkbox_by_default(): void
    {
        // ordersLikeConfig() is a plain flat (non-wizard) form.
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->ordersLikeConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('CheckboxField', $content);
        $this->assertStringNotContainsString('const confirmed', $content);
        $this->assertStringContainsString(':disabled="isSubmitting"', $content);
    }

    public function test_flat_form_confirm_checkbox_when_explicitly_enabled(): void
    {
        $config = $this->ordersLikeConfig();
        $config['features']['frontend']['create']['confirm_step'] = ['enabled' => true, 'confirmation_text' => 'I have double-checked this order.'];

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("import CheckboxField from '@/components/form-fields/CheckboxField.vue';", $content);
        $this->assertStringContainsString('const confirmed = ref(false)', $content);
        $this->assertStringContainsString('<CheckboxField id="wizard-confirm" label="I have double-checked this order." v-model="confirmed" />', $content);
        $this->assertStringContainsString(':disabled="isSubmitting || !confirmed"', $content);
        // No Stepper machinery -- this form never had a wizard config.
        $this->assertStringNotContainsString('Stepper', $content);
        $this->assertStringNotContainsString('wizardSteps', $content);
    }

    // ─── FK select fields summarize by display label, not bare id ───────────

    /** @return array<string, mixed> */
    private function wizardConfigWithFkField(): array
    {
        $config = $this->ordersLikeConfig();
        $config['features']['backend']['createSplash'] = ['splashData' => [['key' => 'Vendors', 'type' => 'model']]];
        $config['features']['frontend']['create']['fields'] = [
            ['field' => 'vendor_id', 'label' => 'Vendor', 'field_type' => 'select', 'type' => 'text', 'required' => true, 'splashKey' => 'Vendors'],
            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
        ];
        $config['features']['frontend']['create']['wizard'] = [
            'enabled' => true,
            'submission_mode' => 'final',
            'steps' => [
                ['id' => 'header', 'label' => 'Header', 'field_keys' => ['vendor_id']],
                ['id' => 'review', 'label' => 'Review', 'field_keys' => ['notes']],
            ],
        ];

        return $config;
    }

    public function test_fk_select_field_summary_prefers_captured_label_over_raw_id(): void
    {
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfigWithFkField());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("@selected-object=\"(obj: any) => { fieldLabels['vendor_id'] = obj?.name ?? '' }\"", $content);
        $this->assertStringContainsString('const fieldLabels = ref<Record<string, string>>({})', $content);
        $this->assertStringContainsString("<span class=\"text-muted-foreground\">Vendor:</span> {{ fieldLabels.vendor_id || form.vendor_id || '—' }}", $content);
        // Plain (non-FK) fields are untouched -- straight to form.key.
        $this->assertStringContainsString("<span class=\"text-muted-foreground\">Notes:</span> {{ form.notes || '—' }}", $content);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    public function test_wizard_with_no_fk_fields_never_declares_field_labels(): void
    {
        // wizardConfig() has no select/FK field at all -- declaring an
        // always-unused `fieldLabels` ref would trip tsconfig's
        // noUnusedLocals on every wizard-plus-confirm form that happens not
        // to have one.
        $generator = new CreateFormGenerator('Orders', 'Custom', $this->wizardConfig());
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('fieldLabels', $content);
        $this->assertStringNotContainsString('@selected-object', $content);
    }

    /**
     * Create-then-view is the unconditional default (modal path handled
     * separately in CrudListPanel.vue, not this generator): a module with
     * `features.frontend.view` enabled navigates to the newly-created
     * record's own View on success, not back to the list.
     */
    public function test_create_success_redirects_to_view_when_the_module_has_a_view_page(): void
    {
        $config = $this->ordersLikeConfig(); // already sets features.frontend.view => true
        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('handleCreated(response.data.id, response.data.uuid)', $content);
        $this->assertStringContainsString(
            "const handleCreated = (id: any, uuid: any) => {\n\tif (props.modal) { emit('created', { id, uuid }) } else { router.push(true && uuid ? `/orders/\${uuid}/details` : props.cancelLink) }\n}",
            $content
        );
    }

    /**
     * A module with no View page at all (features.frontend.view omitted)
     * must not generate a redirect to a route that was never scaffolded --
     * falls back to the pre-existing cancelLink-based redirect.
     */
    public function test_create_success_falls_back_to_cancel_link_when_the_module_has_no_view_page(): void
    {
        $config = $this->ordersLikeConfig();
        unset($config['features']['frontend']['view']);
        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "router.push(false && uuid ? `/orders/\${uuid}/details` : props.cancelLink)",
            $content
        );
    }
}
