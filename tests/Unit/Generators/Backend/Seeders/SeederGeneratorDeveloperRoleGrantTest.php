<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the DEVELOPER-role permission grant.
 *
 * Bug (hit three separate times in one session): a newly scaffolded module
 * creates its `{Module}.{feature}` permission rows via its generated seeder,
 * but attaches them to no role. The frontend router guard checks the user's
 * role permission-ID array literally, so every freshly generated module
 * renders "Page Not Found" in the UI until someone grants the permissions by
 * hand via manual SQL. The backend has a developer bypass, so generated
 * PHPUnit tests never surface this — it is a frontend-only failure.
 *
 * Fix: seeder.stub now grants the module's own permissions to the DEVELOPER
 * role (RolesModel::DEVELOPER) automatically, merging additively into
 * whatever permission IDs the role already has, idempotently, and skipping
 * gracefully (never throwing) if the DEVELOPER role row does not exist yet
 * (seeder-ordering: RolesSeeder may run before or after a module's seeder).
 *
 * These tests assert against the *generated seeder source*, since the grant
 * itself only executes at DB-seeding runtime inside the consuming app (this
 * package has no database).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator
 */
class SeederGeneratorDeveloperRoleGrantTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-seeder-grant-test-' . uniqid();
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

    private function generateAndReadSeeder(string $moduleName, array $config): string
    {
        $generator = new SeederGenerator($moduleName, 'Core', $config);
        $this->assertTrue($generator->generate());

        $filePath = PathManager::getBackendModulePath('Core', $moduleName) . "/Seeders/{$moduleName}Seeder.php";
        $this->assertFileExists($filePath);

        return file_get_contents($filePath);
    }

    public function test_generated_seeder_grants_its_permissions_to_the_developer_role(): void
    {
        $config = [
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

        $source = $this->generateAndReadSeeder('ZzzPermGrantModule', $config);

        $this->assertStringContainsString('grantPermissionsToDeveloperRole', $source);
        $this->assertStringContainsString('RolesModel::DEVELOPER', $source);
        $this->assertStringContainsString("PermissionsModel::query()->where('module', 'ZzzPermGrantModule')", $source);
        $this->assertStringContainsString('use App\Project\Modules\Core\Access\Roles\RolesModel;', $source);
        $this->assertStringContainsString('use App\Project\Modules\Core\Access\Permissions\PermissionsModel;', $source);

        // The grant step must run as part of the existing permissions() hook,
        // called from run(), so it always executes during seeding.
        $this->assertMatchesRegularExpression(
            '/protected function permissions\(\): void\s*\{.*grantPermissionsToDeveloperRole\(\);\s*\}/s',
            $source
        );
    }

    public function test_grant_is_additive_merging_into_existing_role_permissions_not_replacing_them(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => ['backend' => ['list' => true]],
        ];

        $source = $this->generateAndReadSeeder('ZzzAdditiveGrantModule', $config);

        // Must read the role's existing permissions and merge, never assign
        // a bare literal array that would wipe out pre-existing grants.
        $this->assertStringContainsString('$role->permissions ?? []', $source);
        $this->assertStringContainsString('array_merge($role->permissions ?? [], $moduleIds)', $source);
    }

    public function test_grant_is_idempotent_via_array_unique(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => ['backend' => ['list' => true]],
        ];

        $source = $this->generateAndReadSeeder('ZzzIdempotentGrantModule', $config);

        $this->assertStringContainsString('array_unique(array_merge(', $source);
    }

    public function test_grant_skips_gracefully_when_developer_role_is_missing_instead_of_throwing(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => ['backend' => ['list' => true]],
        ];

        $source = $this->generateAndReadSeeder('ZzzMissingRoleGrantModule', $config);

        // No role found -> log and return, never throw.
        $this->assertMatchesRegularExpression('/if\s*\(!\$role\)\s*\{[^}]*return;\s*\}/s', $source);
        $this->assertStringNotContainsString('throw new', $source);
    }

    public function test_grant_never_touches_roles_other_than_developer(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => ['backend' => ['list' => true]],
        ];

        $source = $this->generateAndReadSeeder('ZzzSingleRoleGrantModule', $config);

        // Only one role lookup, keyed off the DEVELOPER constant — no
        // iteration over RolesModel::all() or similar that would grant to
        // every role.
        $this->assertSame(1, substr_count($source, 'RolesModel::query()->find('));
        $this->assertStringNotContainsString('RolesModel::all()', $source);
        $this->assertStringNotContainsString('RolesModel::query()->get()', $source);
    }

    public function test_module_with_no_permissions_configured_still_generates_valid_grant_logic_and_empty_permission_data(): void
    {
        // No backend features enabled, no delegations, no actions, no
        // explicit seeder.permissions config: mergeListPermissions() must
        // still yield an empty permissions array (unaffected by the new
        // grant step), while the grant method itself is still emitted
        // (it is a no-op at runtime when PermissionsModel::where('module', ...)
        // finds nothing, guarded by the `empty($moduleIds)` check).
        $config = [
            'id_type' => 'autoincrement',
        ];

        $generator = new SeederGenerator('ZzzNoPermsGrantModule', 'Core', $config);

        $prop = new \ReflectionProperty($generator, 'permissions');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue($generator));

        $this->assertTrue($generator->generate());

        $jsonPath = PathManager::getBackendModulePath('Core', 'ZzzNoPermsGrantModule') . '/Seeders/ZzzNoPermsGrantModuleSeederData.json';
        $this->assertFileExists($jsonPath);
        $jsonData = json_decode(file_get_contents($jsonPath), true);
        $this->assertSame([], $jsonData['permissions']);

        $seederPath = PathManager::getBackendModulePath('Core', 'ZzzNoPermsGrantModule') . '/Seeders/ZzzNoPermsGrantModuleSeeder.php';
        $source = file_get_contents($seederPath);
        $this->assertStringContainsString('grantPermissionsToDeveloperRole', $source);
        $this->assertStringContainsString('if (empty($moduleIds)) {', $source);
    }
}
