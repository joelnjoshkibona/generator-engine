<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendLocaleGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for FrontendLocaleGenerator.
 *
 * Bug: the frontend stub templates (list/page.stub, view/details_layout.stub,
 * view/modal.stub) and BaseComponentGenerator::generateFormFooter('edit')
 * unconditionally emit `$t('{route}.page_create')`, `.page_edit`,
 * `.page_delete`, `.page_details`, `.save_changes`, `.saving`,
 * `.tab_overview`, and `.tab_history` into every generated module's Vue
 * files — but FrontendLocaleGenerator's key list never produced matching
 * entries in the generated locales/en.json + sw.json. Every freshly
 * scaffolded module therefore rendered raw i18n keys (e.g.
 * "item-categories.page_edit") instead of translated text in its quick-edit
 * modal title, save button, and view-modal tabs. Confirmed missing in
 * ItemCategories; present in older modules (Roles, Locations) only because
 * a human had hand-added them after generation.
 *
 * Fix (see FrontendLocaleGenerator::generate()): $enKeys/$swKeys now include
 * the full key set referenced by the stub templates.
 *
 * Separate bug covered below (test_generate_humanizes_multiword_pascalcase_module_names):
 * the raw PascalCase module name was interpolated verbatim into human-facing
 * text with no spacing — e.g. a module named "ZzzGeneratorVerifyTest"
 * produced `"page_create": "Create ZzzGeneratorVerifyTest"` instead of
 * "Create Zzz Generator Verify Test". Fixed via BaseGenerator::humanize()
 * (Str::headline()), applied to the plural 'title' key and to the singular
 * form used by every button/message key.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\FrontendLocaleGenerator::generate()
 */
class FrontendLocaleGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-locale-test-' . uniqid();
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

    /**
     * Every key referenced by `$t('[[moduleRoute]].xxx')` /
     * `t('{$moduleRoute}.xxx')` across the frontend stub templates and
     * BaseComponentGenerator's generated edit-form save button, that is NOT
     * a per-field `col_*` key (those are covered separately below).
     *
     * @return string[]
     */
    private function requiredStandardKeys(): array
    {
        return [
            'title',
            'create_btn',
            'edit_btn',
            'delete_btn',
            'details_title',
            'page_create',
            'page_edit',
            'page_delete',
            'page_details',
            'created_success',
            'updated_success',
            'deleted_success',
            'restored_success',
            'created_error',
            'updated_error',
            'restored_error',
            'failed_load',
            'saving',
            'save_changes',
            'tab_overview',
            'tab_history',
        ];
    }

    public function test_generate_emits_the_full_standard_key_set_for_en_and_sw(): void
    {
        $generator = new FrontendLocaleGenerator('TestModule', 'Core', []);
        $generator->setForce(true);

        $this->assertTrue($generator->generate());

        $enPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/TestModule/locales/en.json';
        $swPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/TestModule/locales/sw.json';

        $this->assertFileExists($enPath);
        $this->assertFileExists($swPath);

        $en = json_decode(file_get_contents($enPath), true);
        $sw = json_decode(file_get_contents($swPath), true);

        $this->assertArrayHasKey('test-module', $en);
        $this->assertArrayHasKey('test-module', $sw);

        foreach ($this->requiredStandardKeys() as $key) {
            $this->assertArrayHasKey(
                $key,
                $en['test-module'],
                "en.json is missing required key '{$key}' — a generated Vue file referencing "
                . "\$t('test-module.{$key}') would render the raw key instead of translated text."
            );
            $this->assertNotSame('', trim((string) $en['test-module'][$key]), "en.json key '{$key}' must not be blank.");

            $this->assertArrayHasKey($key, $sw['test-module'], "sw.json is missing required key '{$key}'.");
            $this->assertNotSame('', trim((string) $sw['test-module'][$key]), "sw.json key '{$key}' must not be blank.");
        }
    }

    public function test_generate_still_emits_col_keys_for_configured_list_fields(): void
    {
        $config = [
            'features' => [
                'frontend' => [
                    'list' => [
                        'fields' => [
                            ['key' => 'name', 'title' => 'Name'],
                            ['key' => 'parent_id', 'title' => 'Parent'],
                        ],
                    ],
                ],
            ],
        ];

        $generator = new FrontendLocaleGenerator('TestModule', 'Core', $config);
        $generator->setForce(true);
        $generator->generate();

        $enPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/TestModule/locales/en.json';
        $en = json_decode(file_get_contents($enPath), true);

        $this->assertSame('Name', $en['test-module']['col_name']);
        $this->assertSame('Parent', $en['test-module']['col_parent_id']);

        // The standard modal/button keys must still be present alongside the
        // dynamic col_* keys — this was the exact gap that caused the bug.
        $this->assertArrayHasKey('page_edit', $en['test-module']);
        $this->assertArrayHasKey('save_changes', $en['test-module']);
    }

    /**
     * Regression test for the raw-name-concatenation bug: a multi-word
     * PascalCase module name (as produced by `make:module
     * System/Masters/ZzzGeneratorVerifyTest`) must be spaced into Title Case
     * everywhere it appears in human-facing text, with the correct
     * singular/plural form per key — confirmed against the real convention
     * already used by hand-completed modules (Users, Roles, ItemCategories):
     * the plural 'title' key stays plural ("Zzz Generator Verify Tests"),
     * while create/edit/delete/details/message text uses the singular form
     * ("Zzz Generator Verify Test").
     */
    public function test_generate_humanizes_multiword_pascalcase_module_names(): void
    {
        $generator = new FrontendLocaleGenerator('ZzzGeneratorVerifyTest', 'System', []);
        $generator->setForce(true);
        $generator->generate();

        $enPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/system/ZzzGeneratorVerifyTest/locales/en.json';
        $this->assertFileExists($enPath);

        $en = json_decode(file_get_contents($enPath), true);
        $data = $en['zzz-generator-verify-test'];

        // "ZzzGeneratorVerifyTest" is already grammatically singular (ends in
        // "Test"), so Str::singular() is a no-op and 'title' == 'singular'
        // here — the plural/singular *distinction* is covered separately
        // below using "ItemCategories" (ends in a genuine plural word).
        $this->assertSame('Zzz Generator Verify Test', $data['title']);
        $this->assertSame('Zzz Generator Verify Test', $data['singular']);
        $this->assertSame('Create Zzz Generator Verify Test', $data['create_btn']);
        $this->assertSame('Edit Zzz Generator Verify Test', $data['edit_btn']);
        $this->assertSame('Delete Zzz Generator Verify Test', $data['delete_btn']);
        $this->assertSame('Zzz Generator Verify Test Details', $data['details_title']);
        $this->assertSame('Create Zzz Generator Verify Test', $data['page_create']);
        $this->assertSame('Edit Zzz Generator Verify Test', $data['page_edit']);
        $this->assertSame('Delete Zzz Generator Verify Test', $data['page_delete']);
        $this->assertSame('Zzz Generator Verify Test Details', $data['page_details']);
        $this->assertSame('Zzz Generator Verify Test created successfully', $data['created_success']);

        // No key may contain an unspaced run of the raw PascalCase identifier.
        foreach ($data as $key => $value) {
            $this->assertStringNotContainsString(
                'ZzzGeneratorVerifyTest',
                (string) $value,
                "en.json key '{$key}' still contains the raw unspaced module name."
            );
        }
    }

    /**
     * Confirms the singular/plural distinction using a module name that ends
     * in a genuine plural word (matching the real ItemCategories module):
     * the list-style 'title' key stays plural ("Item Categories") while
     * action/message text switches to singular ("Item Category").
     */
    public function test_generate_uses_plural_title_and_singular_action_text(): void
    {
        $generator = new FrontendLocaleGenerator('ItemCategories', 'System', []);
        $generator->setForce(true);
        $generator->generate();

        $enPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/system/ItemCategories/locales/en.json';
        $en = json_decode(file_get_contents($enPath), true);
        $data = $en['item-categories'];

        $this->assertSame('Item Categories', $data['title']);
        $this->assertSame('Item Category', $data['singular']);
        $this->assertSame('Create Item Category', $data['create_btn']);
        $this->assertSame('Item Category Details', $data['details_title']);
    }

    public function test_generate_does_not_overwrite_existing_files_without_force(): void
    {
        $generator = new FrontendLocaleGenerator('TestModule', 'Core', []);
        $generator->setForce(true);
        $generator->generate();

        $enPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/TestModule/locales/en.json';
        file_put_contents($enPath, json_encode(['test-module' => ['title' => 'Hand-edited']]));

        $regenerator = new FrontendLocaleGenerator('TestModule', 'Core', []);
        // force defaults to false
        $wrote = $regenerator->generate();

        $this->assertFalse($wrote, 'generate() should report no writes when both locale files already exist and force is false.');

        $en = json_decode(file_get_contents($enPath), true);
        $this->assertSame('Hand-edited', $en['test-module']['title'], 'Existing hand-edited locale content must be preserved without force.');
    }
}
