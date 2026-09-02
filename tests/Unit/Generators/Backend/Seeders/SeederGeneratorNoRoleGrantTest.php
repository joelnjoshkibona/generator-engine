<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage: a generated seeder must NOT grant its permissions to
 * any role.
 *
 * v2.16.0 made seeder.stub grant the module's own permission IDs to the
 * DEVELOPER role from permissions(). The consuming app's SystemSeeder already
 * assigns every permission ID to the developer role once, after all module
 * seeders have run, so every module was writing the same grant a second time
 * (one extra roles UPDATE per module per seeding run), and a module seeder run
 * on its own merged IDs of since-deleted permission rows into the role.
 * v3.5.15 removed the grant: role assignment has exactly one owner
 * (SystemSeeder) and a module seeder only creates permission rows.
 *
 * These tests assert against the generated seeder source (and the stub tree
 * itself), since this package has no database.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator
 */
class SeederGeneratorNoRoleGrantTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-seeder-no-grant-test-' . uniqid();
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

    private function generateAndReadSeeder(string $moduleName, array $config): string
    {
        $generator = new SeederGenerator($moduleName, 'Core', $config);
        $this->assertTrue($generator->generate());

        $filePath = PathManager::getBackendModulePath('Core', $moduleName) . "/Seeders/{$moduleName}Seeder.php";
        $this->assertFileExists($filePath);

        return file_get_contents($filePath);
    }

    private function fullCrudConfig(): array
    {
        return [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list' => true,
                    'view' => true,
                    'create' => true,
                    'edit' => true,
                    'delete' => true,
                ],
            ],
        ];
    }

    public function test_generated_seeder_does_not_grant_permissions_to_any_role(): void
    {
        $source = $this->generateAndReadSeeder('ZzzNoGrantModule', $this->fullCrudConfig());

        $this->assertStringNotContainsString('grantPermissionsToDeveloperRole', $source);
        $this->assertStringNotContainsString('RolesModel', $source);
        $this->assertStringNotContainsString('PermissionsModel', $source);
        $this->assertStringNotContainsString("->update(['permissions'", $source);
        $this->assertStringNotContainsString('$role->permissions', $source);
    }

    public function test_generated_seeder_does_not_import_the_role_or_permission_models_or_the_log_facade(): void
    {
        $source = $this->generateAndReadSeeder('ZzzNoImportsModule', $this->fullCrudConfig());

        $this->assertStringNotContainsString('use App\Project\Modules\Core\Access\Roles\RolesModel;', $source);
        $this->assertStringNotContainsString('use App\Project\Modules\Core\Access\Permissions\PermissionsModel;', $source);
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\Log;', $source);
    }

    public function test_permissions_hook_still_creates_the_module_permission_rows(): void
    {
        $source = $this->generateAndReadSeeder('ZzzRowsOnlyModule', $this->fullCrudConfig());

        // Permission ROWS are still this seeder's job; only the role grant went.
        $this->assertStringContainsString("Helpers::saveModuleCRUDPermissions('ZzzRowsOnlyModule');", $source);
        $this->assertStringContainsString('Helpers::savePermission($record);', $source);
        $this->assertMatchesRegularExpression(
            '/public function run\(\): void\s*\{.*\$this->permissions\(\);\s*\$this->seedData\(\);\s*\}/s',
            $source
        );
        // permissions() ends right after the savePermission loop -- no trailing call.
        $this->assertMatchesRegularExpression(
            '/protected function permissions\(\): void\s*\{.*?Helpers::savePermission\(\$record\);\s*\}\s*\}\s*(\/\/[^\n]*\n\s*)*\}/s',
            $source
        );
    }

    public function test_no_stub_in_the_template_tree_grants_permissions_to_a_role(): void
    {
        $templatesDir = realpath(__DIR__ . '/../../../../../src/Generators/Templates');
        $this->assertNotFalse($templatesDir);

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (str_contains($contents, 'grantPermissionsToDeveloperRole')
                || str_contains($contents, 'RolesModel::DEVELOPER')) {
                $offenders[] = substr($file->getPathname(), strlen($templatesDir) + 1);
            }
        }

        $this->assertSame([], $offenders, 'Stubs must not grant permissions to a role; SystemSeeder owns that.');
    }

    public function test_module_with_no_permissions_configured_still_generates_a_valid_seeder_and_empty_permission_data(): void
    {
        $config = ['id_type' => 'autoincrement'];

        $generator = new SeederGenerator('ZzzNoPermsModule', 'Core', $config);

        $prop = new \ReflectionProperty($generator, 'permissions');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue($generator));

        $this->assertTrue($generator->generate());

        $jsonPath = PathManager::getBackendModulePath('Core', 'ZzzNoPermsModule') . '/Seeders/ZzzNoPermsModuleSeederData.json';
        $this->assertFileExists($jsonPath);
        $this->assertSame([], json_decode(file_get_contents($jsonPath), true)['permissions']);

        $source = file_get_contents(PathManager::getBackendModulePath('Core', 'ZzzNoPermsModule') . '/Seeders/ZzzNoPermsModuleSeeder.php');
        $this->assertStringContainsString("Helpers::saveModuleCRUDPermissions('ZzzNoPermsModule');", $source);
        $this->assertStringNotContainsString('grantPermissionsToDeveloperRole', $source);
    }
}
