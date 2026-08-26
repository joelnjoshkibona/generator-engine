<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a defect found live 2026-08 on a rental-CRM domain: the generated CRUD
 * spec's edit step advances its anchor date by a day and touched nothing else. When another field
 * carries `after:{anchor}` / `after_or_equal:{anchor}` — a start/end pair, the common case — that
 * single move breaks the very rule this generator wrote into the module's own validation. The
 * update 422s ("The end date field must be a date after or equal to start date"), the dialog never
 * closes, and the spec times out on its row assertion.
 *
 * The failure reads exactly like a product bug: the backend is right, the frontend is right, and
 * the generated spec is contradicting the generated rules.
 *
 * The downstream workaround for this spliced the paired fill in with a regex post-step. Its first
 * version inserted unconditionally, double-filling a field the create path already filled — the
 * second click toggled the date back off and cost 6 previously-passing specs. That is why this
 * belongs in the generator, which knows the rules without having to guess from surrounding lines.
 *
 * 2026-08-25: the dependent fill's own mechanism changed from fillDatePickerField() (a
 * DatePickerField.vue popover click) to a plain setInputValue() — see renderFieldFill()'s
 * updated 'date' comment for why (BaseComponentGenerator never actually emits a DatePickerField
 * for field_type: 'date'; it's always a native `<input type="date">`). The dependent-fields
 * logic this file covers (which field moves, and whether it moves at all) is unchanged — only
 * the generated fill call's shape is different.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator::buildDependentDateFills()
 */
class PlaywrightTestGeneratorDateRangeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-playwright-date-range-' . uniqid();
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

    /**
     * `start_date` is the only scalar edit field here besides the anchor, so pickEditField() lands
     * on it and the edit step drives the date picker.
     */
    private function contractsConfig(string $endDateRules): array
    {
        return [
            'table_name' => 'contracts',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '64'],
                ['name' => 'start_date', 'type' => 'date'],
                ['name' => 'end_date', 'type' => 'date'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['enabled' => true],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'code', 'rules' => 'required|string'],
                        ['field' => 'start_date', 'rules' => 'required|date'],
                        ['field' => 'end_date', 'rules' => $endDateRules],
                    ]],
                    'edit' => ['enabled' => true, 'fields' => [
                        ['field' => 'code', 'rules' => 'required|string'],
                        ['field' => 'start_date', 'rules' => 'required|date'],
                        ['field' => 'end_date', 'rules' => $endDateRules],
                    ]],
                    'view' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => ['enabled' => true, 'fields' => [
                        ['field' => 'code', 'label' => 'Code'],
                        ['field' => 'start_date', 'label' => 'Start Date'],
                        ['field' => 'end_date', 'label' => 'End Date'],
                    ]],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'code', 'label' => 'Code', 'field_type' => 'input'],
                        ['field' => 'start_date', 'label' => 'Start Date', 'field_type' => 'date'],
                        ['field' => 'end_date', 'label' => 'End Date', 'field_type' => 'date'],
                    ]],
                    'edit' => ['enabled' => true, 'fields' => [
                        ['field' => 'start_date', 'label' => 'Start Date', 'field_type' => 'date'],
                        ['field' => 'end_date', 'label' => 'End Date', 'field_type' => 'date'],
                    ]],
                    'view' => ['enabled' => true],
                ],
            ],
        ];
    }

    private function generateAndRead(array $config): string
    {
        $generator = new PlaywrightTestGenerator('Contracts', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'Contracts') . '/e2e/contracts-crud.e2e.js';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extract only the edit step, so create's own two fills can't satisfy these assertions. */
    private function editBlock(string $content): string
    {
        $start = strpos($content, '── Edit (via the view modal');
        $this->assertNotFalse($start, 'Generated spec has no edit block.');
        $end = strpos($content, "shot(page, '06-after-edit')", $start);
        $this->assertNotFalse($end, 'Edit block has no closing screenshot call.');

        return substr($content, $start, $end - $start);
    }

    /** The dependent field's fill line — a plain setInputValue(), day offset 1 ("tomorrow"). */
    private function dependentFillMarker(string $key): string
    {
        return "await setInputValue(page, '[role=\"dialog\"] #{$key}', new Date(Date.now() + 86400000).toISOString().slice(0, 10));";
    }

    public function test_after_or_equal_dependent_moves_with_the_anchor(): void
    {
        $edit = $this->editBlock($this->generateAndRead(
            $this->contractsConfig('required|date|after_or_equal:start_date')
        ));

        // The anchor (start_date) is edited through the normal scalar-edit path — its own
        // fill isn't this test's concern, only that the dependent moves alongside it.
        $this->assertStringContainsString($this->dependentFillMarker('end_date'), $edit);
    }

    public function test_after_dependent_moves_with_the_anchor(): void
    {
        $edit = $this->editBlock($this->generateAndRead(
            $this->contractsConfig('required|date|after:start_date')
        ));

        $this->assertStringContainsString($this->dependentFillMarker('end_date'), $edit);
    }

    /**
     * Without a cross-field rule there is nothing to keep consistent, and filling a second picker
     * would be an unrequested extra interaction — exactly the over-application that broke six specs
     * downstream.
     */
    public function test_independent_date_field_is_left_alone(): void
    {
        $edit = $this->editBlock($this->generateAndRead(
            $this->contractsConfig('required|date')
        ));

        $this->assertStringNotContainsString($this->dependentFillMarker('end_date'), $edit);
    }

    /**
     * `before:` is satisfied, not broken, by the anchor moving forward — so it must not move.
     */
    public function test_before_dependent_is_left_alone(): void
    {
        $edit = $this->editBlock($this->generateAndRead(
            $this->contractsConfig('required|date|before:start_date')
        ));

        $this->assertStringNotContainsString($this->dependentFillMarker('end_date'), $edit);
    }

    /** The dependent must never be filled twice — one fill, one setInputValue() call. */
    public function test_dependent_is_filled_exactly_once(): void
    {
        $edit = $this->editBlock($this->generateAndRead(
            $this->contractsConfig('required|date|after_or_equal:start_date')
        ));

        $this->assertSame(1, substr_count($edit, $this->dependentFillMarker('end_date')));
    }
}
