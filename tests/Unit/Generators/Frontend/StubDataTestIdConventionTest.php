<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the `data-testid` naming convention introduced in
 * v2.10.11 (see CHANGELOG.md), which PlaywrightTestGenerator's generated e2e
 * specs (and any hand-written e2e test) rely on to locate list/view/delete
 * controls:
 *
 *   - list row "view" button:            [[moduleName]]-view-{uuid}
 *   - list toolbar "create" button:      [[moduleName]]-create
 *   - view-modal "edit" button:          [[moduleName]]-edit-{uuid}
 *   - view-modal "More Actions" item:    [[moduleName]]-delete-{uuid}
 *   - delete-form confirm button:        [[moduleName]]-confirm-delete
 *
 * This test reads the raw .stub files directly (the stubs, not a generated
 * module, are the source of truth to protect) and asserts each literal
 * `data-testid`/`:data-testid` attribute is still present with the exact
 * `[[moduleName]]` placeholder token and action/uuid shape documented above.
 * PlaywrightTestGenerator's generated specs (and helpers such as
 * `uuidFromTestId()`/`cleanupStrayRecord()`) hard-code these same patterns; if
 * a future edit to these stubs silently renames or restructures one of these
 * attributes, the generated e2e suite would start failing to find its
 * controls with no compile-time signal — this test exists to catch that
 * drift at the stub-source level instead.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator
 */
class StubDataTestIdConventionTest extends TestCase
{
    private function templatesDir(): string
    {
        return dirname(__DIR__, 4) . '/src/Generators/Templates/frontend/features';
    }

    private function readStub(string $relativePath): string
    {
        $path = $this->templatesDir() . '/' . $relativePath;
        $this->assertFileExists($path, "Expected stub file not found: {$path}");

        return (string) file_get_contents($path);
    }

    private function assertStubContains(string $needle, string $stubContent, string $relativePath): void
    {
        $this->assertStringContainsString(
            $needle,
            $stubContent,
            "Expected {$relativePath} to contain the data-testid fragment {$needle}, but it did not. "
            . 'This attribute is part of the data-testid convention (v2.10.11) that the generated '
            . 'PlaywrightTestGenerator e2e spec hard-codes to locate this control — removing or '
            . 'renaming it will silently break every generated e2e test.'
        );
    }

    public function test_list_page_stub_has_create_button_testid(): void
    {
        $this->assertStubContains(
            'data-testid="[[moduleName]]-create"',
            $this->readStub('list/page.stub'),
            'list/page.stub'
        );
    }

    public function test_list_page_stub_has_row_view_button_testid(): void
    {
        $this->assertStubContains(
            '`[[moduleName]]-view-${row.uuid}`',
            $this->readStub('list/page.stub'),
            'list/page.stub'
        );
    }

    public function test_view_modal_stub_has_edit_button_testid(): void
    {
        $this->assertStubContains(
            '`[[moduleName]]-edit-${uuid}`',
            $this->readStub('view/modal.stub'),
            'view/modal.stub'
        );
    }

    public function test_view_modal_stub_has_delete_menu_item_testid(): void
    {
        $this->assertStubContains(
            '`[[moduleName]]-delete-${uuid}`',
            $this->readStub('view/modal.stub'),
            'view/modal.stub'
        );
    }

    public function test_delete_form_stub_has_confirm_delete_button_testid(): void
    {
        $this->assertStubContains(
            'data-testid="[[moduleName]]-confirm-delete"',
            $this->readStub('delete/form.stub'),
            'delete/form.stub'
        );
    }

    /**
     * The edit/create form footer's submit/cancel testids are NOT baked
     * directly into edit/form.stub or create/form.stub — they're emitted by
     * BaseComponentGenerator::generateFormFooter() (PHP string concatenation,
     * not a .stub file), using `strtolower($this->moduleName)` for the same
     * `[[moduleName]]` slug the .stub-based attributes above resolve to via
     * BaseGenerator::replacePlaceholders(). This test guards that the two
     * independent code paths keep producing the identical slug convention —
     * if generateFormFooter() ever switched to e.g. Str::kebab($moduleName),
     * the submit/cancel buttons would drift out of the `[[moduleName]]-*`
     * family that every other control (and the generated e2e spec) uses.
     */
    public function test_form_footer_submit_and_cancel_testids_use_same_slug_convention_as_stub_placeholder(): void
    {
        $path = dirname(__DIR__, 4) . '/src/Generators/Frontend/Components/BaseComponentGenerator.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'data-testid=\"{$moduleSlug}-cancel\"',
            $source,
            'generateFormFooter() no longer emits a {$moduleSlug}-cancel data-testid.'
        );
        $this->assertStringContainsString(
            'data-testid=\"{$moduleSlug}-submit\"',
            $source,
            'generateFormFooter() no longer emits a {$moduleSlug}-submit data-testid.'
        );
        $this->assertStringContainsString(
            '$moduleSlug = strtolower($this->moduleName);',
            $source,
            'generateFormFooter() no longer derives $moduleSlug via strtolower($this->moduleName) — '
            . 'this must stay identical to BaseGenerator::replacePlaceholders()\'s [[moduleName]] '
            . '(also strtolower($this->moduleName)), or the submit/cancel buttons will use a different '
            . 'slug than every other [[moduleName]]-* data-testid the generated e2e spec looks for.'
        );
    }
}
