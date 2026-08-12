<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Ux\CompositeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * `CompositeGenerator` had zero test coverage of any kind before this file —
 * confirmed via a full-tree grep for "CompositeGenerator" across tests/
 * turning up no matches. Locks in the real blueprint shape (top-level
 * `groups`, keyed by group name and valued as a list of snake_case TABLE
 * names — not module names, and not the `module_groups` shape docs/schema/
 * examples/ux-blueprint.json described before being corrected alongside this
 * test) and the real generated output: one backend
 * `{Module}CompositeCreateService.php`, plus a frontend AND mobile
 * `{Module}CreatePage.vue` that unconditionally OVERWRITE the module's plain
 * create page (the composite IS the create page for a module using it).
 */
class CompositeGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-composite-test-' . uniqid();
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
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function blueprint(): array
    {
        return [
            'groups' => [
                'Custom' => ['quotes', 'quote_items'],
            ],
            'composites' => [
                'Quotes' => [
                    'label' => 'New Quote',
                    'sections' => [
                        ['name' => 'details', 'type' => 'form', 'module' => 'Custom/Quotes', 'optional' => false],
                        ['name' => 'items', 'type' => 'repeater', 'module' => 'Custom/QuoteItems', 'optional' => false],
                    ],
                ],
            ],
        ];
    }

    public function test_generate_writes_backend_service_and_overwrites_frontend_and_mobile_create_pages(): void
    {
        $backendServicePath = PathManager::getBackendModulePath('Custom', 'Quotes') . '/Services/QuotesCompositeCreateService.php';
        $frontendPagePath = PathManager::getFrontendModulePath('Custom', 'Quotes') . '/QuotesCreatePage.vue';
        $mobilePagePath = PathManager::getMobileAppModulePath('Custom', 'Quotes') . '/QuotesCreatePage.vue';

        // Pre-seed a "plain" create page to prove the composite overwrites it
        // (overwrite: true) rather than skipping because the file exists --
        // that's the whole point of a composite: it REPLACES the module's
        // default single-record create page.
        mkdir(dirname($frontendPagePath), 0755, true);
        file_put_contents($frontendPagePath, '<!-- plain, pre-composite create page -->');

        $generator = new CompositeGenerator($this->blueprint());
        $generator->generate();

        $this->assertFileExists($backendServicePath);
        $this->assertFileExists($frontendPagePath);
        $this->assertFileExists($mobilePagePath);

        $frontendContent = (string) file_get_contents($frontendPagePath);
        $this->assertStringNotContainsString('plain, pre-composite create page', $frontendContent, 'Composite must overwrite the plain create page, not skip because it already exists.');

        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($backendServicePath) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, "Generated composite service has a PHP syntax error:\n" . implode("\n", $output));
    }

    public function test_frontend_page_imports_both_section_modules_create_forms(): void
    {
        $frontendPagePath = PathManager::getFrontendModulePath('Custom', 'Quotes') . '/QuotesCreatePage.vue';

        (new CompositeGenerator($this->blueprint()))->generate();

        $content = (string) file_get_contents($frontendPagePath);

        $this->assertStringContainsString(
            "import QuotesCreateForm from '@/pages/modules/custom/Quotes/Components/QuotesCreateForm.vue'",
            $content
        );
        $this->assertStringContainsString(
            "import QuoteItemsCreateForm from '@/pages/modules/custom/QuoteItems/Components/QuoteItemsCreateForm.vue'",
            $content
        );
    }

    public function test_backend_service_names_the_first_section_as_the_parent_section(): void
    {
        $backendServicePath = PathManager::getBackendModulePath('Custom', 'Quotes') . '/Services/QuotesCompositeCreateService.php';

        (new CompositeGenerator($this->blueprint()))->generate();

        $content = (string) file_get_contents($backendServicePath);
        $this->assertStringContainsString("data['details']", $content);
        $this->assertStringContainsString('class QuotesCompositeCreateService', $content);
    }

    /**
     * BaseUxGenerator::getGroupForModule() looks up the composite's host
     * module by its snake_case TABLE name inside `groups[group]` -- a
     * blueprint using the wrong shape (e.g. the historical, incorrect
     * `module_groups: {"Quotes": "Custom"}` map) resolves to no group at
     * all and the composite is silently skipped: 0 files, no error, no
     * warning. Confirms this is real, not just documented.
     */
    public function test_composite_is_silently_skipped_when_host_module_table_name_is_missing_from_groups(): void
    {
        $blueprint = $this->blueprint();
        unset($blueprint['groups']);

        $generator = new CompositeGenerator($blueprint);
        $generator->generate();

        $this->assertDirectoryDoesNotExist(PathManager::getBackendModulePath('Custom', 'Quotes') . '/Services');
    }
}
