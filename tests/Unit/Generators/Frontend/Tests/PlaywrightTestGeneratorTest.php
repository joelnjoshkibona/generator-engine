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
}
