<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\ApiContractGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * api-contract.json must describe exactly the routes the backend registers — no
 * more, no fewer.
 *
 * This is the test that makes the contract trustworthy, and it exists because the
 * obvious way to build one is wrong. module.json carries
 * `features.backend.{list,create,view,edit,delete}.endpoint` blocks that look
 * authoritative and are not: real committed config declares
 * `edit` as `PUT /statuses` while RoutesGenerator emits `PUT /statuses/{uuid}/edit`,
 * and `view` as `GET /statuses/{uuid}` against a real `GET /statuses/{uuid}/view`.
 * A mock built from those blocks 404s on every single request, and nothing in the
 * generator would have said so.
 *
 * So ApiContractGenerator parses RoutesGenerator's own output, and this test proves
 * the parse is total: every emitted route reaches the contract, and the contract
 * invents nothing.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\ApiContractGenerator
 */
class ApiContractRouteParityTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-api-contract-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_contract_routes_match_the_generated_routes_file_exactly(): void
    {
        // Five shapes, because the route surface is not uniform: reduced operation
        // sets drop routes, export/import add unguessable ones (/list/export,
        // /import/template), and actions and delegations each build paths by their
        // own rules. A parser that only ever sees plain CRUD proves very little.
        $shapes = [
            'plain CRUD'         => self::config(),
            'list and view only' => self::reducedConfig(),
            'with export/import' => self::exportImportConfig(),
            'with an action'     => self::actionConfig(),
            'with a delegation'  => self::delegationConfig(),
        ];

        foreach ($shapes as $label => $config) {
            $this->freshProjectRoot();

            $emitted  = $this->routesFromGeneratedFile($config);
            $contract = $this->routesFromContract($config);

            $this->assertNotEmpty($emitted, "{$label}: fixture emitted no routes at all");
            $this->assertSame(
                $emitted,
                $contract,
                "{$label}: api-contract.json disagrees with the emitted Routes/api.php"
            );
        }
    }

    /** Each shape writes its own Routes/api.php, so they must not share a root. */
    private function freshProjectRoot(): void
    {
        $this->removeDirectory($this->tmpRoot);
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    public function test_contract_records_the_permission_guarding_each_route(): void
    {
        $routes = $this->routesFromContractDetailed(self::config());

        $list = $this->findRoute($routes, 'GET', '/widgets/list');
        $this->assertNotNull($list, 'no list route in the contract');
        $this->assertSame('Widgets.list', $list['permission']);

        // The activity route is deliberately auth-only, with no permission middleware.
        $activity = $this->findRoute($routes, 'GET', '/widgets/{uuid}/activity');
        $this->assertNotNull($activity, 'no activity route in the contract');
        $this->assertNull($activity['permission']);
    }

    public function test_contract_does_not_reuse_module_json_endpoint_blocks(): void
    {
        // Pins the specific trap: this config's `endpoint` blocks describe a
        // REST-ish shape the backend never registers. The contract must follow the
        // routes file, so those paths must be absent and the real ones present.
        $config = self::config();
        $config['features']['backend']['edit']['endpoint'] = ['method' => 'PUT', 'path' => '/widgets'];
        $config['features']['backend']['view']['endpoint'] = ['method' => 'GET', 'path' => '/widgets/{uuid}'];

        $routes = $this->routesFromContract($config);

        $this->assertContains('PUT /widgets/{uuid}/edit', $routes);
        $this->assertContains('GET /widgets/{uuid}/view', $routes);
        $this->assertNotContains('PUT /widgets', $routes);
        $this->assertNotContains('GET /widgets/{uuid}', $routes);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Parse "METHOD path" out of the real generated Routes/api.php on disk.
     *
     * Deliberately re-parsed from the written file rather than reusing
     * ApiContractGenerator's own regex — sharing the parser would let a bug in it
     * agree with itself and pass.
     *
     * @return array<int, string>
     */
    private function routesFromGeneratedFile(array $config): array
    {
        (new RoutesGenerator('Widgets', 'Core', $config))->setForce(true)->generate();

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Routes/api.php';
        $this->assertFileExists($path);

        preg_match_all(
            '/->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/i',
            (string) file_get_contents($path),
            $matches,
            PREG_SET_ORDER
        );

        $routes = array_map(
            static fn (array $m): string => strtoupper($m[1]) . ' ' . $m[2],
            $matches
        );

        sort($routes);

        return $routes;
    }

    /** @return array<int, string> */
    private function routesFromContract(array $config): array
    {
        $routes = array_map(
            static fn (array $r): string => $r['method'] . ' ' . $r['path'],
            $this->routesFromContractDetailed($config)
        );

        sort($routes);

        return $routes;
    }

    /** @return array<int, array{method: string, path: string, permission: string|null, handler: string}> */
    private function routesFromContractDetailed(array $config): array
    {
        return (new ApiContractGenerator('Widgets', 'Core', $config))->buildModuleContract()['routes'];
    }

    private function findRoute(array $routes, string $method, string $path): ?array
    {
        foreach ($routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return $route;
            }
        }

        return null;
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private static function config(): array
    {
        return [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
            'id_type'     => 'bigint',
            'columns'     => [
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => true],
            ],
            'features' => [
                'backend' => [
                    'list'   => ['enabled' => true],
                    'create' => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ],
        ];
    }

    private static function reducedConfig(): array
    {
        $config = self::config();
        $config['features']['backend'] = [
            'list' => ['enabled' => true],
            'view' => ['enabled' => true],
        ];

        return $config;
    }

    private static function exportImportConfig(): array
    {
        $config = self::config();
        $config['features']['backend']['list'] = [
            'enabled' => true,
            'export'  => true,
            'import'  => true,
        ];

        return $config;
    }

    private static function actionConfig(): array
    {
        $config = self::config();
        $config['actions'] = [
            'approve' => [
                'name'       => 'approve',
                'label'      => 'Approve',
                'hasUI'      => true,
                'uiType'     => 'modal',
                'urlParams'  => ['uuid'],
                'operations' => [
                    'create' => [
                        'enabled'  => true,
                        'endpoint' => ['method' => 'POST', 'path' => '/widgets/{uuid}/approve'],
                    ],
                ],
            ],
        ];

        return $config;
    }

    private static function delegationConfig(): array
    {
        $config = self::config();
        $config['delegations'] = [
            'Readings' => [
                'name'          => 'Readings',
                'label'         => 'Readings',
                'uiType'        => 'tab',
                'relatedModule' => ['name' => 'Readings', 'group' => 'Core'],
                'filterKey'     => 'widget_id',
                'operations'    => ['list' => ['enabled' => true]],
            ],
        ];

        return $config;
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
}
