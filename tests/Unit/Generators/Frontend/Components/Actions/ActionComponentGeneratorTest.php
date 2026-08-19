<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for ActionComponentGenerator::buildEndpointExpression().
 *
 * Bug (v2.51.2 follow-up): when an action's operations left `endpoint.path`
 * unset, the frontend default independently derived
 * "/{module}/{params}/{action}" while RoutesGenerator::generateActionRoutes()
 * registers "/{module}/{action}/{params}/{op}" on the backend -- a different
 * segment order AND a missing trailing operation segment, so the generated
 * form POSTed to a route the backend never registers (a guaranteed 404) for
 * every action that doesn't set endpoint.path explicitly.
 *
 * Fix: the frontend default now derives from the exact same shape the
 * backend registers, keyed off the specific operation ('create'/'edit'/
 * 'view'/'delete'/'list', in that lookup order) that matched.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateActionRoutes()
 */
class ActionComponentGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actioncomponentgen-test-' . uniqid();
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

    private function generateAndRead(array $action, string $moduleName = 'Budgets', string $moduleGroup = 'Custom'): string
    {
        $generator = new ActionComponentGenerator($moduleName, $moduleGroup, []);
        $this->assertTrue($generator->generateAction('approve', $action), 'generateAction() should report a successful write.');

        $actionName = \Illuminate\Support\Str::studly($action['name'] ?? 'approve');
        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}{$actionName}Form.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function makeGenerator(): TestActionComponentGenerator
    {
        $ref = new ReflectionClass(TestActionComponentGenerator::class);

        /** @var TestActionComponentGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        return $generator;
    }

    public function test_default_endpoint_mirrors_backend_route_shape_for_matched_operation(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => ['edit' => ['enabled' => true]],
                'urlParams' => ['uuid'],
            ],
            'orders',
            'cancel'
        );

        $this->assertSame('/orders/cancel/${props.uuid}/edit', $result);
    }

    public function test_default_endpoint_with_no_url_params(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            ['operations' => ['create' => ['enabled' => true]]],
            'quotations',
            'activate'
        );

        $this->assertSame('/quotations/activate/create', $result);
    }

    public function test_operation_lookup_order_prefers_create_over_later_operations(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => [
                    'view' => ['enabled' => true],
                    'create' => ['enabled' => true],
                ],
            ],
            'expenses',
            'approve'
        );

        $this->assertStringEndsWith('/create', $result);
    }

    public function test_explicit_endpoint_path_is_used_verbatim_over_the_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => [
                    'edit' => [
                        'enabled' => true,
                        'endpoint' => ['path' => '/users/{uuid}/force-reset-password'],
                    ],
                ],
            ],
            'users',
            'forceResetPassword'
        );

        $this->assertSame('/users/${props.uuid}/force-reset-password', $result);
    }

    // ─── Real fields[] + optional wizard on actions ──────────────────────────
    //
    // Previously a permanently-empty form = ref({}) with a "bind them into
    // form" HTML comment -- Section05's own "Frontend Config" tab never
    // persisted anything here either (bound to a permanently-undefined
    // model-value). Reuses the SAME generateFormFields()/generateWizardSteps()
    // building blocks Create/Edit already use.

    private function baseAction(): array
    {
        return [
            'name' => 'approve',
            'label' => 'Approve',
            'hasUI' => true,
            'uiType' => 'modal',
            'operations' => ['edit' => ['enabled' => true, 'endpoint' => ['path' => '/budgets/{uuid}/approve']]],
        ];
    }

    public function test_action_with_no_fields_keeps_the_original_hand_written_placeholder(): void
    {
        $content = $this->generateAndRead($this->baseAction());

        $this->assertStringContainsString('<!-- Add your form fields here — bind them into `form`. -->', $content);
        $this->assertStringContainsString('form = ref<Record<string, any>>({', $content);
        $this->assertStringNotContainsString('Stepper', $content);
    }

    public function test_action_with_plain_fields_renders_real_field_markup(): void
    {
        $action = $this->baseAction();
        $action['fields'] = [
            ['field' => 'notes', 'label' => 'Approval Notes', 'field_type' => 'textarea', 'type' => 'text', 'required' => false],
        ];

        $content = $this->generateAndRead($action);

        $this->assertStringContainsString("v-model=\"form.notes\"", $content);
        $this->assertStringContainsString(":error=\"errors.notes\"", $content);
        $this->assertStringContainsString("isFieldDisabled('notes')", $content);
        $this->assertStringContainsString('const isFieldDisabled = (fieldName: string) => {', $content);
        $this->assertStringContainsString('const errors = ref<Record<string, string>>({})', $content);
        $this->assertStringNotContainsString('Add your form fields here', $content);
        $this->assertStringNotContainsString('Stepper', $content);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    public function test_action_with_wizard_renders_stepper_and_gates_fields_by_step(): void
    {
        $action = $this->baseAction();
        $action['fields'] = [
            ['field' => 'reason', 'label' => 'Reason', 'field_type' => 'input', 'type' => 'text', 'required' => true],
            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'textarea', 'type' => 'text', 'required' => false],
        ];
        $action['wizard'] = [
            'enabled' => true,
            'submission_mode' => 'final',
            'steps' => [
                ['id' => 'reason', 'label' => 'Reason', 'field_keys' => ['reason']],
                ['id' => 'notes', 'label' => 'Notes', 'field_keys' => ['notes']],
            ],
        ];

        $content = $this->generateAndRead($action);

        $this->assertStringContainsString("import { Stepper } from '@/components/ui/stepper';", $content);
        $this->assertStringContainsString('<Stepper :steps="wizardSteps"', $content);
        $this->assertStringContainsString('v-if="currentStep === 0"', $content);
        $this->assertStringContainsString('v-if="currentStep === 1"', $content);
        $this->assertStringContainsString('@click="goBack"', $content);
        $this->assertStringContainsString('@click="goNext"', $content);
        $this->assertStringContainsString('<Button type="button" v-else size="sm"', $content);
        // Actions have no draft mechanism -- goNext() must not reference the undefined saveDraft().
        $this->assertStringNotContainsString('saveDraft', $content);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    // ─── writeFileOnce() regression ──────────────────────────────────────────
    //
    // Bug: an action's Form.vue (and, for uiType:'page', its Page.vue) were
    // written via plain writeFile(), which force-overwrites on every
    // regenerate -- discarding any hand-added logic (a custom step's markup,
    // bespoke validation) the same way the inline-items wrapper component
    // used to before its own writeFileOnce() fix.

    public function test_regenerate_with_force_does_not_clobber_hand_edited_form(): void
    {
        $action = $this->baseAction();
        $moduleName = 'Budgets';
        $moduleGroup = 'Custom';

        $generator = new ActionComponentGenerator($moduleName, $moduleGroup, []);
        $generator->generateAction('approve', $action);

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}ApproveForm.vue";
        $handEdited = "<template><!-- hand-edited: must survive regeneration --></template>\n";
        file_put_contents($path, $handEdited);

        $regenerated = new ActionComponentGenerator($moduleName, $moduleGroup, []);
        $regenerated->setForce(true);
        $regenerated->generateAction('approve', $action);

        $this->assertSame($handEdited, file_get_contents($path), 'a forced regenerate must not overwrite a hand-edited action form');
    }

    // ─── confirm_step: default-on for wizards, opt-in for flat/fieldless actions ──

    public function test_wizard_action_defaults_to_a_confirm_step_when_confirm_step_key_is_absent(): void
    {
        $action = $this->baseAction();
        $action['fields'] = [
            ['field' => 'reason', 'label' => 'Reason', 'field_type' => 'input', 'type' => 'text', 'required' => true],
        ];
        $action['wizard'] = [
            'enabled' => true,
            'submission_mode' => 'final',
            'steps' => [
                ['id' => 'reason', 'label' => 'Reason', 'field_keys' => ['reason']],
            ],
        ];

        $content = $this->generateAndRead($action);

        $this->assertStringContainsString("{ id: 'confirm', label: 'Review & Confirm' }", $content);
        $this->assertStringContainsString('v-if="currentStep === 1"', $content);
        $this->assertStringContainsString("import CheckboxField from '@/components/form-fields/CheckboxField.vue';", $content);
        $this->assertStringContainsString('const confirmed = ref(false)', $content);
        $this->assertStringContainsString(':disabled="isSubmitting || !confirmed"', $content);
    }

    public function test_flat_action_with_fields_has_no_confirm_checkbox_by_default(): void
    {
        $action = $this->baseAction();
        $action['fields'] = [
            ['field' => 'notes', 'label' => 'Approval Notes', 'field_type' => 'textarea', 'type' => 'text', 'required' => false],
        ];

        $content = $this->generateAndRead($action);

        $this->assertStringNotContainsString('CheckboxField', $content);
        $this->assertStringNotContainsString('const confirmed', $content);
    }

    public function test_flat_action_with_fields_confirm_checkbox_when_explicitly_enabled(): void
    {
        $action = $this->baseAction();
        $action['fields'] = [
            ['field' => 'notes', 'label' => 'Approval Notes', 'field_type' => 'textarea', 'type' => 'text', 'required' => false],
        ];
        $action['confirm_step'] = ['enabled' => true, 'confirmation_text' => 'I understand this cannot be undone.'];

        $content = $this->generateAndRead($action);

        $this->assertStringContainsString("import CheckboxField from '@/components/form-fields/CheckboxField.vue';", $content);
        $this->assertStringContainsString('const confirmed = ref(false)', $content);
        $this->assertStringContainsString('<CheckboxField id="wizard-confirm" label="I understand this cannot be undone." v-model="confirmed" />', $content);
        $this->assertStringContainsString(':disabled="isSubmitting || !confirmed"', $content);
        $this->assertStringNotContainsString('Stepper', $content);
    }

    public function test_fieldless_action_can_opt_in_to_confirm_checkbox(): void
    {
        $action = $this->baseAction();
        $action['confirm_step'] = ['enabled' => true];

        $content = $this->generateAndRead($action);

        $this->assertStringContainsString('Add your form fields here', $content);
        $this->assertStringContainsString("import CheckboxField from '@/components/form-fields/CheckboxField.vue';", $content);
        $this->assertStringContainsString(':disabled="isSubmitting || !confirmed"', $content);
    }

    public function test_fieldless_action_has_no_confirm_checkbox_by_default(): void
    {
        $content = $this->generateAndRead($this->baseAction());

        $this->assertStringNotContainsString('CheckboxField', $content);
        $this->assertStringContainsString(':disabled="isSubmitting"', $content);
    }

    public function test_regenerate_with_force_does_not_clobber_hand_edited_page(): void
    {
        $action = $this->baseAction();
        $action['uiType'] = 'page';
        $moduleName = 'Budgets';
        $moduleGroup = 'Custom';

        $generator = new ActionComponentGenerator($moduleName, $moduleGroup, []);
        $generator->generateAction('approve', $action);

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}ApprovePage.vue";
        $handEdited = "<template><!-- hand-edited page: must survive regeneration --></template>\n";
        file_put_contents($path, $handEdited);

        $regenerated = new ActionComponentGenerator($moduleName, $moduleGroup, []);
        $regenerated->setForce(true);
        $regenerated->generateAction('approve', $action);

        $this->assertSame($handEdited, file_get_contents($path), 'a forced regenerate must not overwrite a hand-edited action page');
    }
}

/**
 * Minimal concrete subclass exposing the protected method under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestActionComponentGenerator extends ActionComponentGenerator
{
    public function callBuildEndpointExpression(array $action, string $moduleRoute, string $actionRoute): string
    {
        return $this->buildEndpointExpression($action, $moduleRoute, $actionRoute);
    }
}
