<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Byte-identical regression coverage proving the SchemaConventions rewrite
 * of SchemaIntrospector::meta() (2026-07-26) does NOT change generated
 * migration output for a caller that already supplies all four
 * has_timestamps/has_soft_deletes/has_uuid/has_creator_updater flags
 * explicitly in $meta.
 *
 * Why this is a valid "before vs after" proof without literally diffing
 * against the pre-change code: MigrationGenerator never calls
 * SchemaIntrospector or SchemaConventions at all -- it only ever reads
 * $config['has_timestamps'] etc. via ModuleConfigContract (untouched by
 * this change). IntrospectionToConfig::build() reads $meta['has_timestamps']
 * etc. via a bare array_key_exists()/?? check (also untouched by this
 * change) -- it never calls SchemaIntrospector either. This test builds
 * $meta entirely by hand (no SchemaIntrospector involved) with all four
 * flags explicit, so the ENTIRE code path exercised here is identical
 * before and after the SchemaConventions rewrite; SchemaIntrospector::meta()
 * is simply never on the call stack for an explicit-flag caller like this
 * one.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::build()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator
 */
class MigrationGeneratorConventionRegressionTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-convention-regression-test-' . uniqid();
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

    /** @return array<int, array<string, mixed>> SchemaIntrospector::columns()-shaped rows */
    private function columns(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'indexed'         => false,
                'enum_values'     => null,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    private function fullySpecifiedMeta(bool $flagValue): array
    {
        return [
            'module_name'         => 'ZzzConventionRegression',
            'module_type'         => 'Custom',
            'table_name'          => 'zzz_convention_regression',
            'id_type'             => 'uuid',
            'has_timestamps'      => $flagValue,
            'has_soft_deletes'    => $flagValue,
            'has_uuid'            => $flagValue,
            'has_creator_updater' => $flagValue,
            'file_columns'        => [],
            'index_groups'        => [],
        ];
    }

    private function generate(array $config): string
    {
        $generator = new MigrationGenerator('ZzzConventionRegression', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $files = glob($this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/ZzzConventionRegression/Migrations/*_create_zzz_convention_regression_table.php') ?: [];
        $this->assertCount(1, $files);

        return file_get_contents($files[0]);
    }

    public function test_fully_specified_true_flags_produce_the_expected_migration_content(): void
    {
        // Strict mode is deliberately used -- proves every one of
        // IntrospectionToConfig::REQUIRED_STRICT_KEYS is present and honored
        // verbatim, exactly as a real SchemaIntrospector::meta()-fed caller
        // would supply them (this test just supplies them by hand instead).
        $config = IntrospectionToConfig::strict()->build($this->columns(), $this->fullySpecifiedMeta(true));

        $this->assertTrue($config['has_timestamps']);
        $this->assertTrue($config['has_soft_deletes']);
        $this->assertTrue($config['has_uuid']);
        $this->assertTrue($config['has_creator_updater']);

        $content = $this->generate($config);

        $this->assertStringContainsString('$table->timestamps();', $content);
        $this->assertStringContainsString('$table->softDeletes();', $content);
        $this->assertStringContainsString(
            '$table->uuid()->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();',
            $content
        );
        $this->assertStringContainsString("\$table->index(['uuid'], 'idx_zzz_convention_regression_uuid');", $content);
        $this->assertStringContainsString("\$table->index(['created_by_id'], 'idx_zzz_convention_regression_created_by_id');", $content);
        $this->assertStringContainsString("\$table->index(['updated_by_id'], 'idx_zzz_convention_regression_updated_by_id');", $content);
        $this->assertStringContainsString("\$table->index(['deleted_at'], 'idx_zzz_convention_regression_deleted_at');", $content);
        $this->assertStringContainsString("use App\\Project\\_Src\\Helpers;", $content);
        $this->assertStringContainsString("use Illuminate\\Support\\Facades\\DB;", $content);
    }

    public function test_fully_specified_false_flags_omit_every_conventional_column_and_index(): void
    {
        $config = IntrospectionToConfig::strict()->build($this->columns(), $this->fullySpecifiedMeta(false));

        $this->assertFalse($config['has_timestamps']);
        $this->assertFalse($config['has_soft_deletes']);
        $this->assertFalse($config['has_uuid']);
        $this->assertFalse($config['has_creator_updater']);

        $content = $this->generate($config);

        $this->assertStringNotContainsString('$table->timestamps();', $content);
        $this->assertStringNotContainsString('$table->softDeletes();', $content);
        $this->assertStringNotContainsString('$table->uuid()', $content);
        $this->assertStringNotContainsString('idx_zzz_convention_regression_uuid', $content);
        $this->assertStringNotContainsString('idx_zzz_convention_regression_created_by_id', $content);
        $this->assertStringNotContainsString('idx_zzz_convention_regression_updated_by_id', $content);
        $this->assertStringNotContainsString('idx_zzz_convention_regression_deleted_at', $content);
    }
}
