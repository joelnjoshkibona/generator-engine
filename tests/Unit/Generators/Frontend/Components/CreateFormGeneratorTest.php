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
}
