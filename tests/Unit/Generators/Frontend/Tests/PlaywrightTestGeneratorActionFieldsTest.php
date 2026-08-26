<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a defect found live 2026-08-25 on a rental-CRM domain: the generated
 * action smoke test never referenced `actions.{name}.fields` at all, so an action with a REQUIRED
 * field got a spec that submits an empty form forever. `handleSubmit()` only closes the dialog on a
 * 2xx response — the exact success signal this test relies on — so a blank required field makes the
 * submit 422 and the spec times out waiting for a dialog that was never going to close.
 *
 * The bug was invisible for as long as the two real actions it was tested against were still
 * Phase-2 stubs that returned a trivial, unconditional success with no validation — the stub's own
 * leniency masked a generator gap that real validation immediately exposed.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator::buildActionSpecBody()
 */
class PlaywrightTestGeneratorActionFieldsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-action-fields-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generateAndRead(array $action, string $uiType = 'modal'): string
    {
        $config = [
            'table_name' => 'contracts',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '64'],
            ],
            'features' => [
                'backend' => ['view' => ['enabled' => true]],
                'frontend' => ['view' => ['enabled' => true]],
            ],
            'actions' => [
                'voidPayment' => array_merge([
                    'name' => 'voidPayment',
                    'label' => 'Void Payment',
                    'hasUI' => true,
                    'uiType' => $uiType,
                    'placement' => 'more',
                    'operations' => ['view' => ['enabled' => true]],
                ], $action),
            ],
        ];

        $generator = new PlaywrightTestGenerator('Contracts', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'Contracts') . '/e2e/contracts-void-payment.e2e.js';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_a_required_action_field_is_filled_before_the_modal_is_submitted(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'void_reason', 'label' => 'Reason', 'field_type' => 'textarea', 'required' => true],
            ],
        ]);

        $this->assertStringContainsString('void_reason:', $content);
        $this->assertStringContainsString(
            "await fillField(page, '[role=\"dialog\"] #void_reason', actionValues.void_reason);",
            $content
        );

        // The fill must happen BEFORE the submit button is even located, not after — filling after
        // clicking submit would still leave the first submit attempt blank. Searching for
        // "actionDialog.getByRole(" specifically, not just any getByRole('button', ...) call: the
        // "More Actions" trigger earlier in the same spec also matches the looser string.
        $fillPos = strpos($content, 'actionValues.void_reason');
        $submitPos = strpos($content, 'actionDialog.getByRole(');
        $this->assertNotFalse($fillPos);
        $this->assertNotFalse($submitPos);
        $this->assertLessThan($submitPos, $fillPos);
    }

    public function test_a_required_action_field_is_filled_before_a_page_shaped_action_is_submitted(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'void_reason', 'label' => 'Reason', 'field_type' => 'textarea', 'required' => true],
            ],
        ], uiType: 'page');

        $this->assertStringContainsString(
            "await fillField(page, '[role=\"dialog\"] #void_reason', actionValues.void_reason);",
            $content
        );
    }

    public function test_the_field_helper_functions_are_emitted_when_the_action_has_fields(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'void_reason', 'label' => 'Reason', 'field_type' => 'textarea', 'required' => true],
            ],
        ]);

        // Without this, the fill line above would call a function the spec never defines.
        $this->assertStringContainsString('async function fillField(', $content);
    }

    public function test_an_action_with_no_fields_declared_is_unaffected(): void
    {
        $content = $this->generateAndRead([]);

        $this->assertStringNotContainsString('actionValues', $content);
    }

    public function test_a_required_select_action_field_uses_the_select_fill_helper_not_plain_fill(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'reminder_profile_id', 'label' => 'Profile', 'field_type' => 'select', 'required' => true],
            ],
        ]);

        $this->assertStringContainsString("await fillSelectField(page, '[role=\"dialog\"]', 'Profile');", $content);
        $this->assertStringNotContainsString("fillField(page, '[role=\"dialog\"] #reminder_profile_id'", $content);
    }

    // ─── Wizard actions (found 2026-08-25 building Pangisha's 09.01 Activate) ──────────────────
    //
    // Until this fix, buildActionSpecBody() had NO wizard awareness at all: it filled every
    // configured field in one flat block with no "Next" clicks between them, so a wizard action's
    // generated smoke test tried to locate a later step's field while the form was still sitting
    // on step 0 — the field genuinely wasn't in the DOM yet, so the fill just timed out.

    private function generateWizardActionAndRead(array $steps, array $confirmStep = []): string
    {
        return $this->generateAndRead([
            'fields' => [
                ['field' => 'record_payment', 'label' => 'Record a payment now?', 'field_type' => 'checkbox'],
                ['field' => 'payment_amount', 'label' => 'Amount', 'field_type' => 'number'],
            ],
            'wizard' => ['enabled' => true, 'steps' => $steps],
            'confirm_step' => $confirmStep,
        ]);
    }

    public function test_wizard_action_clicks_next_between_steps_before_filling_a_later_steps_field(): void
    {
        $content = $this->generateWizardActionAndRead([
            ['id' => 'schedule', 'label' => 'Review Schedule', 'field_keys' => []],
            ['id' => 'payment', 'label' => 'Optional Payment', 'field_keys' => ['record_payment', 'payment_amount']],
        ]);

        $nextPos = strpos($content, "getByRole('button', { name: 'Next', exact: true })");
        $fillPos = strpos($content, "page.locator('[role=\"dialog\"] #record_payment').click()");

        $this->assertNotFalse($nextPos, 'expected a "Next" click to advance past the fieldless first step');
        $this->assertNotFalse($fillPos);
        $this->assertLessThan($fillPos, $nextPos, 'the field on step 2 must not be filled before the "Next" click that reaches step 2');
    }

    public function test_wizard_action_checks_the_confirm_checkbox_before_submitting_by_default(): void
    {
        // confirm_step omitted entirely -- must default to enabled for a wizard, mirroring
        // ActionComponentGenerator::generateAction()'s own ($confirmStepConfig['enabled'] ?? $isWizard).
        $content = $this->generateWizardActionAndRead([
            ['id' => 'schedule', 'label' => 'Review Schedule', 'field_keys' => []],
            ['id' => 'payment', 'label' => 'Optional Payment', 'field_keys' => ['record_payment', 'payment_amount']],
        ]);

        $confirmPos = strpos($content, "page.locator('[role=\"dialog\"] #wizard-confirm').click()");
        $submitPos = strpos($content, "actionDialog.getByRole(");

        $this->assertNotFalse($confirmPos, 'expected the auto-appended confirm-step checkbox to be checked');
        $this->assertNotFalse($submitPos);
        $this->assertLessThan($submitPos, $confirmPos, 'the confirm checkbox must be checked before the submit button is located');

        // Two configured steps + an appended confirm step = 2 "Next" clicks: one to leave the
        // fieldless first step, one more to leave "Optional Payment" and reach "Review & Confirm".
        $this->assertSame(2, substr_count($content, "getByRole('button', { name: 'Next', exact: true })"));
    }

    public function test_wizard_action_skips_the_confirm_checkbox_when_explicitly_disabled(): void
    {
        $content = $this->generateWizardActionAndRead(
            [
                ['id' => 'schedule', 'label' => 'Review Schedule', 'field_keys' => []],
                ['id' => 'payment', 'label' => 'Optional Payment', 'field_keys' => ['record_payment', 'payment_amount']],
            ],
            ['enabled' => false]
        );

        $this->assertStringNotContainsString('#wizard-confirm', $content);

        // Only one transition between the two configured steps -- no confirm step is appended, so
        // no extra "Next" click is needed after the last one.
        $this->assertSame(1, substr_count($content, "getByRole('button', { name: 'Next', exact: true })"));
    }

    public function test_non_wizard_action_never_emits_a_next_click_or_confirm_checkbox(): void
    {
        // Regression guard: every action built before this fix (Payments.voidPayment,
        // Installments.waive, Units.setMaintenance, Contracts.settleDeposit) is a flat,
        // non-wizard action and must keep generating exactly as before.
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'void_reason', 'label' => 'Reason', 'field_type' => 'textarea', 'required' => true],
            ],
        ]);

        $this->assertStringNotContainsString("name: 'Next', exact: true", $content);
        $this->assertStringNotContainsString('#wizard-confirm', $content);
    }

    // ─── Action-only fields' fieldValueExpr() (found 2026-08-25, same 09.01 Activate build) ────
    //
    // fieldValueExpr() detected a numeric/date field purely from $field['type'] — the SCHEMA
    // column type a Create/Edit field always carries. An action field (actions.{name}.fields[])
    // is hand-authored input config with no real column behind it, so it has ONLY field_type, no
    // 'type' at all — a `field_type: 'number'` action field fell through to the generic
    // `E2E __MODULE__ __LABEL__ ${stamp}` text template, which a NumberInputField cannot accept
    // (it silently strips every non-digit character as it types), and a `field_type: 'date'`
    // action field hit the exact same class of bug against a native `<input type="date">`.

    public function test_a_number_type_action_field_gets_a_numeric_test_value_not_a_text_template(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'payment_amount', 'label' => 'Amount', 'field_type' => 'number'],
            ],
        ]);

        $this->assertStringNotContainsString('E2E Contracts Amount', $content);
        $this->assertStringContainsString('payment_amount: (1000000 + (stamp % 900000))', $content);
    }

    public function test_a_date_type_action_field_gets_an_iso_date_test_value_not_a_text_template(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'paid_at', 'label' => 'Paid At', 'field_type' => 'date'],
            ],
        ]);

        $this->assertStringNotContainsString('E2E Contracts Paid At', $content);
        $this->assertStringContainsString('paid_at: new Date().toISOString().slice(0, 10)', $content);
    }

    /**
     * Pre-existing gap, not something the v3.5.9/v3.5.11 number-field saga introduced:
     * splitSpecHelperFunctionsFor() never tracked 'date' fields at all, so nothing ever emitted
     * fillDatePickerField()'s own definition into a split action spec — renderFieldFill()
     * dispatches to it correctly, but the call threw a ReferenceError the moment any action's
     * field list included a 'date' field_type. Found live building Pangisha's 09.01 Activate
     * (2026-08-26): the wizard's own `paid_at` field is exactly this case.
     */
    public function test_a_date_type_action_field_gets_its_calendar_popover_helper_emitted(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'paid_at', 'label' => 'Paid At', 'field_type' => 'date'],
            ],
        ]);

        $this->assertStringContainsString('async function fillDatePickerField(', $content);
        $this->assertStringContainsString(
            "await fillDatePickerField(page, '[role=\"dialog\"]', 'paid_at', 0);",
            $content
        );
    }

    /**
     * Sibling to the fieldValueExpr() coverage above, but for the FILL mechanism, not the test
     * value: a `field_type: 'number'` action field must be filled through fillNumberField() (the
     * comma-tolerant helper NumberInputField.vue's Cleave.js formatting needs), not the generic
     * fillField() — and that helper's own definition must actually be emitted into the spec, or
     * the call throws a ReferenceError at runtime. Both renderFieldFill()'s dispatch and
     * hasFieldType('number-input')'s action-field awareness need this, together — found live
     * building Pangisha's 09.01 Activate (2026-08-25): `stuck at "1,027,609", expected "1027609"`
     * from the plain fillField() readback comparing a Cleave.js-formatted display value against
     * the raw unformatted one.
     */
    public function test_a_number_type_action_field_is_filled_with_the_comma_tolerant_helper(): void
    {
        $content = $this->generateAndRead([
            'fields' => [
                ['field' => 'payment_amount', 'label' => 'Amount', 'field_type' => 'number'],
            ],
        ]);

        $this->assertStringContainsString('async function fillNumberField(', $content);
        $this->assertStringContainsString(
            "await fillNumberField(page, '[role=\"dialog\"] #payment_amount', actionValues.payment_amount);",
            $content
        );
        $this->assertStringNotContainsString(
            "await fillField(page, '[role=\"dialog\"] #payment_amount'",
            $content
        );
    }
}
