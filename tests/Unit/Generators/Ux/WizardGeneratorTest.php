<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Ux\WizardGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression coverage for WizardGenerator::resolveStepImportSegments()
 * (v2.51.2 follow-up).
 *
 * Bug 1: a wizard step's frontend import was hand-built as
 * "@/pages/modules/{lowercased-first-segment}/{module}/Components/...",
 * which both lowercased a PascalCase sub-group and omitted the mandatory
 * top-level "system/" group segment entirely -- confirmed against the
 * `system/{PascalGroup}/{Module}` convention this project's own root
 * CLAUDE.md documents. Real generated imports never resolved at build time.
 *
 * Bug 2: a wizard step referencing an `inline_items` child (which never
 * gets its own scaffolded module) generated an import for a CreateForm.vue
 * that will never exist, since nothing cross-checked a step's `module`
 * against the blueprint's `inline_items` keys.
 *
 * Fix: both steps' import segment is now resolved via the same
 * registry-backed PathManager::resolveFrontendImportSegment() every other
 * cross-module frontend reference in this codebase already uses -- which
 * both produces the correct group/subGroup/module segment for a real,
 * registered module, and naturally returns '' for anything not in the
 * registry (an inline_items child has no registry entry of its own).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\PathManager::resolveFrontendImportSegment()
 */
class WizardGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No modules.json at this path -- forces resolveFrontendImportSegment()'s
        // registry-miss branch to fall all the way through to '' rather than
        // throwing, for the "unresolved / inline_items child" test cases.
        PathManager::setProjectRoot(sys_get_temp_dir() . '/generator-engine-wizard-test-' . uniqid());
        PathManager::setModuleRegistry([]);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        parent::tearDown();
    }

    private function resolveSegments(array $steps, array $formTypes): array
    {
        $ref = new ReflectionClass(WizardGenerator::class);
        $generator = $ref->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(WizardGenerator::class, 'resolveStepImportSegments');
        $method->setAccessible(true);

        return $method->invoke($generator, $steps, $formTypes);
    }

    public function test_resolves_real_module_to_group_subgroup_module_segment(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Quotations', 'module_type' => 'System', 'group_name' => 'Documents'],
        ]);

        $segments = $this->resolveSegments(
            [['step' => 1, 'name' => 's1', 'label' => 'L', 'module' => 'System/Quotations', 'type' => 'form']],
            ['form', 'repeater', 'select_or_create']
        );

        $this->assertSame('system/Documents/Quotations', $segments['Quotations']);
    }

    public function test_resolves_top_level_group_module_with_no_sub_group(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Payments', 'module_type' => 'Custom'],
        ]);

        $segments = $this->resolveSegments(
            [['step' => 1, 'name' => 's1', 'label' => 'L', 'module' => 'Custom/Payments', 'type' => 'form']],
            ['form', 'repeater', 'select_or_create']
        );

        $this->assertSame('custom/Payments', $segments['Payments']);
    }

    public function test_unregistered_module_resolves_to_empty_string_not_a_guessed_path(): void
    {
        // Simulates an inline_items child (e.g. OrderItems) that never gets
        // its own scaffolded module/registry entry.
        $segments = $this->resolveSegments(
            [['step' => 1, 'name' => 's1', 'label' => 'L', 'module' => 'Custom/OrderItems', 'type' => 'repeater']],
            ['form', 'repeater', 'select_or_create']
        );

        $this->assertSame('', $segments['OrderItems']);
    }

    public function test_ignores_non_form_type_steps(): void
    {
        $segments = $this->resolveSegments(
            [['step' => 1, 'name' => 's1', 'label' => 'L', 'module' => 'Custom/Anything', 'type' => 'select_or_create_but_unknown']],
            ['form', 'repeater', 'select_or_create']
        );

        $this->assertArrayNotHasKey('Anything', $segments);
    }
}
