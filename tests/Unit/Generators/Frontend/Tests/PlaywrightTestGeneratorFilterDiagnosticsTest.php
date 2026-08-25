<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * pickVisibleFilterField() drops unusable filter candidates silently, so a module whose
 * filterableFields were misconfigured got a generic "no safe candidate" line naming none of them —
 * and the operator learned about it as a confusing e2e failure minutes into a suite run, if at all.
 *
 * Three distinct misconfigurations land there, all derivable from the schema the generator already
 * reads: a filterable field that is not a visible list column; an FK-typed one whose list cell
 * renders a raw id because the referenced module has no `name` column to resolve through; and a
 * nullable one, whose empty rows render "N/A" — never a selectable option.
 *
 * Follows v3.4.23's approach for the unique-FK case: a specific comment at the collision point.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator::filterCandidateDiagnostics()
 */
class PlaywrightTestGeneratorFilterDiagnosticsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-filter-diagnostics-' . uniqid();
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

    private function generateAndRead(array $columns, array $listFields, array $filterableFields): string
    {
        $config = [
            'table_name' => 'invoices',
            'id_type' => 'autoincrement',
            'columns' => $columns,
            'features' => [
                'backend' => [
                    'list' => ['enabled' => true, 'filterableFields' => $filterableFields],
                    'view' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => ['enabled' => true, 'fields' => $listFields],
                    'view' => ['enabled' => true],
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Invoices', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Core', 'Invoices') . '/e2e/invoices-crud.e2e.js';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_a_filterable_field_that_is_not_a_visible_column_says_so_by_name(): void
    {
        $content = $this->generateAndRead(
            [['name' => 'reference', 'type' => 'string', 'length' => '64']],
            [],                        // nothing rendered as a column
            ['reference']
        );

        $this->assertStringContainsString('`reference`', $content);
        $this->assertStringContainsString('not a visible list column', $content);
        $this->assertStringContainsString('defaultVisible: true', $content);
    }

    public function test_an_fk_filter_whose_cell_renders_a_raw_id_says_so_by_name(): void
    {
        $content = $this->generateAndRead(
            [['name' => 'status_id', 'type' => 'integer', 'relatedModule' => 'Statuses']],
            [['key' => 'status_id', 'title' => 'Status', 'data' => 'status_id']], // no dot-path
            ['status_id'] // inferFallbackFilterFieldType() types this as a select from relatedModule
        );

        $this->assertStringContainsString('`status_id`', $content);
        $this->assertStringContainsString('renders a raw id', $content);
        $this->assertStringContainsString('literal `name` column', $content);
    }

    /**
     * A nullable column still filters correctly for rows that DO have a value, so it stays a
     * usable target — flagged, not rejected. Rejecting it would delete working coverage.
     */
    public function test_a_nullable_filter_target_is_cautioned_not_rejected(): void
    {
        $content = $this->generateAndRead(
            [['name' => 'reference', 'type' => 'string', 'length' => '64', 'nullable' => true]],
            [['key' => 'reference', 'title' => 'Reference', 'data' => 'reference']],
            ['reference']
        );

        $this->assertStringContainsString('Caution', $content);
        $this->assertStringContainsString('nullable column', $content);
        $this->assertStringContainsString('"N/A"', $content);
        // Still generated a real filter step rather than skipping.
        $this->assertStringNotContainsString('Filter — skipped', $content);
    }

    public function test_a_clean_non_nullable_visible_filter_gets_no_diagnostics_at_all(): void
    {
        $content = $this->generateAndRead(
            [['name' => 'reference', 'type' => 'string', 'length' => '64']],
            [['key' => 'reference', 'title' => 'Reference', 'data' => 'reference']],
            ['reference']
        );

        $this->assertStringNotContainsString('Caution', $content);
        $this->assertStringNotContainsString('was rejected', $content);
        $this->assertStringNotContainsString('Filter — skipped', $content);
    }
}
