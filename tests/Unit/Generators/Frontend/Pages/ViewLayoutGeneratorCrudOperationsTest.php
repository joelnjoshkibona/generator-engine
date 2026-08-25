<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a build-breaking defect found live 2026-08-24 on a rental-CRM
 * project: {Module}DetailsLayout.vue imported ./Components/{Module}EditForm.vue and
 * {Module}DeleteForm.vue unconditionally, regardless of whether those operations were
 * enabled. EditFormGenerator/DeleteFormGenerator never write those files for a module with a
 * reduced operation set -- an append-only ledger (list/view), a receipts table
 * (create/list/view) -- so the layout imported files that do not exist.
 *
 * `vite dev` tolerates it (the module is only resolved when someone opens that route), which
 * is why it went unnoticed through a full e2e suite. `vite build` resolves every import
 * statically:
 *
 *     [UNRESOLVED_IMPORT] Could not resolve './Components/PaymentsEditForm.vue'
 *
 * i.e. an application containing one such module could not be built for production at all.
 *
 * This is the frontend counterpart of the backend bug v3.4.17 fixed (a disabled operation
 * leaving its generated test file behind) and mirrors ListPageGenerator's own
 * generateCrudOperationBlocks(), which has emitted conditional imports since v3.2.x.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator::generateCrudOperationBlocks()
 */
class ViewLayoutGeneratorCrudOperationsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-layout-crud-ops-' . uniqid();
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

    private function generateAndRead(array $frontendFeatures): string
    {
        $generator = new ViewLayoutGenerator('Payments', 'Collections', [
            'features' => ['frontend' => $frontendFeatures],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Collections', 'Payments') . '/PaymentsDetailsLayout.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_create_list_view_module_imports_neither_edit_nor_delete_form(): void
    {
        $content = $this->generateAndRead([
            'view' => ['enabled' => true],
            'create' => ['enabled' => true],
        ]);

        // The build breaker: an import of a file EditFormGenerator never wrote.
        $this->assertStringNotContainsString("./Components/PaymentsEditForm.vue", $content);
        $this->assertStringNotContainsString("./Components/PaymentsDeleteForm.vue", $content);
        // ...and no markup left dangling on components that are now absent.
        $this->assertStringNotContainsString('<PaymentsEditForm', $content);
        $this->assertStringNotContainsString('<PaymentsDeleteForm', $content);
    }

    public function test_list_view_only_module_emits_no_action_toolbar_at_all(): void
    {
        $content = $this->generateAndRead(['view' => ['enabled' => true]]);

        $this->assertStringNotContainsString('Header Row 2: Action toolbar', $content);
        $this->assertStringNotContainsString('DropdownMenu v-if', $content);
        $this->assertStringNotContainsString("hasPermission('Payments.edit')", $content);
        $this->assertStringNotContainsString("hasPermission('Payments.delete')", $content);
    }

    public function test_edit_without_delete_keeps_the_edit_button_and_drops_the_delete_menu(): void
    {
        $content = $this->generateAndRead([
            'view' => ['enabled' => true],
            'edit' => ['enabled' => true],
        ]);

        $this->assertStringContainsString("hasPermission('Payments.edit')", $content);
        $this->assertStringContainsString("import PaymentsEditForm from './Components/PaymentsEditForm.vue'", $content);
        $this->assertStringNotContainsString("hasPermission('Payments.delete')", $content);
        $this->assertStringNotContainsString('PaymentsDeleteForm', $content);
        // With no delete there is no restore branch, so the v-if/v-else pair must collapse
        // rather than leave an empty <template v-if="record?.deleted_at"> nobody can satisfy.
        $this->assertStringNotContainsString('<template v-else>', $content);
        $this->assertStringNotContainsString('<template v-if="record?.deleted_at">', $content);
        // The read-only banner above the toolbar is NOT part of the restore branch and stays
        // for every module -- a row can be soft-deleted by a bulk action too.
        $this->assertStringContainsString('v-if="record?.deleted_at"', $content);
    }

    public function test_full_crud_module_is_unchanged_and_keeps_both_imports_and_modals(): void
    {
        $content = $this->generateAndRead([
            'view' => ['enabled' => true],
            'create' => ['enabled' => true],
            'edit' => ['enabled' => true],
            'delete' => ['enabled' => true],
        ]);

        $this->assertStringContainsString("import PaymentsEditForm from './Components/PaymentsEditForm.vue'", $content);
        $this->assertStringContainsString("import PaymentsDeleteForm from './Components/PaymentsDeleteForm.vue'", $content);
        $this->assertStringContainsString('<PaymentsEditForm :uuid="recordId" modal @cancel="editOpen = false" @updated="onUpdated" />', $content);
        $this->assertStringContainsString('<PaymentsDeleteForm :uuid="recordId" modal @cancel="deleteOpen = false" @deleted="onDeleted" />', $content);
        $this->assertStringContainsString('Header Row 2: Action toolbar', $content);
        $this->assertStringContainsString('<template v-if="record?.deleted_at">', $content);
        $this->assertStringContainsString('<template v-else>', $content);
        // Placeholders must all be resolved -- replacePlaceholders() is a single
        // non-recursive pass, so a token injected via another placeholder never resolves.
        $this->assertStringNotContainsString('[[', $content);
    }
}
