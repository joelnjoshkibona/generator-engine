<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the 2026-08-05 addition of `submitUrl`/`checkUrl`/
 * `permissionOverride` props to the native DeleteForm — mirrors the
 * `submitUrl`/`viewUrl` override pattern Create/EditForm already had.
 * `CrudListPanel` needs these to route a delegation-tab delete through the
 * delegation's own scoped, separately-permissioned endpoint instead of the
 * module's own top-level `/{module}/{uuid}/delete` route.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator
 */
class DeleteFormGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-deleteformgen-test-' . uniqid();
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

    private function generate(): string
    {
        $config = ['module_name' => 'Statuses', 'module_type' => 'Core', 'columns' => []];
        $generator = new DeleteFormGenerator('Statuses', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/Statuses/Components/StatusesDeleteForm.vue';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_declares_submit_url_check_url_and_permission_override_props_defaulting_to_null(): void
    {
        $content = $this->generate();

        $this->assertStringContainsString('submitUrl: { default: null },', $content);
        $this->assertStringContainsString('checkUrl: { default: null },', $content);
        $this->assertStringContainsString('permissionOverride: { default: null },', $content);
    }

    public function test_check_and_submit_endpoints_fall_back_to_the_module_own_default_when_overrides_are_unset(): void
    {
        $content = $this->generate();

        $this->assertStringContainsString(
            "const checkEndpoint = computed(() => props.checkUrl || `/statuses/\${props.uuid}/delete/check`)",
            $content
        );
        $this->assertStringContainsString(
            "const submitEndpoint = computed(() => props.submitUrl || `/statuses/\${props.uuid}/delete`)",
            $content
        );
    }

    public function test_requests_use_the_computed_endpoints_not_hardcoded_literals(): void
    {
        $content = $this->generate();

        $this->assertStringContainsString('sendGetRequest(checkEndpoint.value)', $content);
        $this->assertStringContainsString('sendDeleteRequest(submitEndpoint.value)', $content);
        // The old hardcoded literals must be gone, not just duplicated alongside the computed refs.
        $this->assertStringNotContainsString('sendGetRequest(`/statuses/${props.uuid}/delete/check`)', $content);
        $this->assertStringNotContainsString('sendDeleteRequest(`/statuses/${props.uuid}/delete`)', $content);
    }

    public function test_permission_gate_honors_the_override(): void
    {
        $content = $this->generate();

        $this->assertStringContainsString(
            "hasPermission(props.permissionOverride ?? 'Statuses.delete')",
            $content
        );
        $this->assertStringNotContainsString("hasPermission('Statuses.delete')", $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }
}
