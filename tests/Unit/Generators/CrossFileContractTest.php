<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationRelatedFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendLocaleGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewOverviewGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Roughly 20 delegation/action defects (fixed across v2.16.0-v2.19.0) all
 * shared one shape: each generated file was internally consistent and
 * self-consistently WRONG. Every per-generator unit test passed throughout,
 * because each one asserts its own file in isolation while the defect lived
 * in the RELATIONSHIP between two files — a tab id in StudlyCase vs a route
 * in kebab-case, a route guard demanding one permission string while the
 * seeder created a different one, a component importing a path no generator
 * emits, a `t('items.col_label')` call with no matching locale key.
 *
 * `ViewSurfaceParityTest` and `ActionGenerationTest` each caught ONE such
 * relationship. This test generates a single realistic fixture — a module
 * nested under System/Custom, an FK-select field, a delegation with every
 * CRUD operation enabled, and both a modal and a page action — and runs four
 * MECHANICAL checks over the whole generated tree at once:
 *
 *   1. Every backend endpoint literal referenced in generated Vue resolves to
 *      a `Route::` path actually registered in a generated api.php.
 *   2. Every `permission:X` (routes) / `hasPermission('X')` (Vue) reference
 *      exists in a generated seeder's permissions JSON.
 *   3. Every relative or `@/pages/modules/...` import in a generated file
 *      resolves to a file this run actually wrote.
 *   4. Every `t('module.key')` call (excluding the hand-maintained `common.*`
 *      namespace) exists in a generated locale file.
 *
 * Generated INSIDE this package's own PHPUnit run, against package source —
 * never against a vendor/ copy. See memory note
 * project-generator-cross-file-contracts / project-generator-dist-not-path-repo.
 */
class CrossFileContractTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-cross-file-contract-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    // ── Fixture ──────────────────────────────────────────────────────────

    private function itemsConfig(): array
    {
        $fkField = [
            'field' => 'category_id', 'label' => 'Category',
            'field_type' => 'api-select', 'type' => 'text',
            'api_url' => '/select/item-categories', 'option_label' => 'name', 'option_value' => 'id',
            'required' => true,
        ];
        $nameField = ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true];

        return [
            'module_name' => 'Items',
            'module_type' => 'System',
            'table_name'  => 'items',
            'id_type'     => 'bigint',
            'columns'     => [],
            'features' => [
                // SeederGenerator gates permission auto-derivation on
                // !empty($backendFeatures[$feature]) — an empty array is
                // falsy, so these must be non-empty even though
                // RoutesGenerator's own gate is a looser isset() check.
                'backend' => [
                    'list' => ['enabled' => true], 'create' => ['enabled' => true], 'view' => ['enabled' => true],
                    'edit' => ['enabled' => true], 'delete' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => [
                        'enabled' => true,
                        'primaryField' => 'name',
                        'fields' => [
                            ['key' => 'name', 'sortable' => true],
                            ['key' => 'category_id', 'data' => 'category?.name', 'sortable' => false],
                        ],
                    ],
                    'create' => ['enabled' => true, 'fields' => [$nameField, $fkField]],
                    'edit'   => ['enabled' => true, 'fields' => [$nameField, $fkField]],
                    'view'   => ['enabled' => true, 'titleData' => 'name'],
                    'delete' => ['enabled' => true],
                ],
            ],
            'delegations' => [
                'itemPrices' => [
                    'name' => 'ItemPrices', 'label' => 'Item Prices', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'ItemPrices', 'group' => 'Custom'],
                    'filterKey' => 'item_id', 'parentKey' => 'uuid', 'parentIdField' => 'id',
                    'operations' => [
                        'list' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['key' => 'price', 'label' => 'Price'],
                        ]]],
                        'create' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ]]],
                        'edit' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ]]],
                        'view'   => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
            'actions' => [
                'approve' => [
                    'name' => 'approve', 'label' => 'Approve', 'hasUI' => true, 'uiType' => 'modal', 'urlParams' => ['uuid'],
                    'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/items/{uuid}/approve']]],
                ],
                'export' => [
                    'name' => 'export', 'label' => 'Export', 'hasUI' => true, 'uiType' => 'page', 'urlParams' => ['uuid'],
                    'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/items/{uuid}/export']]],
                ],
            ],
        ];
    }

    private function itemPricesConfig(): array
    {
        return [
            'module_name' => 'ItemPrices',
            'module_type' => 'System',
            'table_name'  => 'item_prices',
            'id_type'     => 'bigint',
            'columns'     => [],
            'features' => [
                'backend'  => ['delete' => ['enabled' => true]],
                'frontend' => [
                    'list'   => ['fields' => [['key' => 'price', 'label' => 'Price']]],
                    'delete' => ['enabled' => true],
                ],
            ],
        ];
    }

    /**
     * Generates the whole fixture tree once: the Items module (nested under
     * System/Custom, an FK-select field, a full-CRUD delegation to
     * ItemPrices, one modal + one page action) plus ItemPrices' own
     * independently-scaffolded routes/seeder/locale/delete-form — the
     * realistic shape of "delegate to an already-scaffolded module".
     */
    private function generateFixture(): void
    {
        PathManager::setModuleSubGroup('Custom');
        PathManager::setModuleRegistry([
            ['name' => 'ItemPrices', 'module_type' => 'System', 'group_name' => 'Custom', 'table_name' => 'item_prices'],
        ]);

        $items = $this->itemsConfig();
        $itemPrices = $this->itemPricesConfig();

        (new RoutesGenerator('Items', 'System', $items))->generate();
        (new SeederGenerator('Items', 'System', $items))->generate();
        (new FrontendRoutesGenerator('Items', 'System', $items))->generate();
        (new FrontendLocaleGenerator('Items', 'System', $items))->generate();
        (new ListPageGenerator('Items', 'System', $items))->generate();
        (new CreateFormGenerator('Items', 'System', $items))->generate();
        (new EditFormGenerator('Items', 'System', $items))->generate();
        (new DeleteFormGenerator('Items', 'System', $items))->generate();
        (new ViewModalGenerator('Items', 'System', $items))->generate();
        (new ViewOverviewGenerator('Items', 'System', $items))->generate();

        (new ActionComponentGenerator('Items', 'System', $items))->generateAction('approve', $items['actions']['approve']);
        (new ActionComponentGenerator('Items', 'System', $items))->generateAction('export', $items['actions']['export']);

        (new DelegationTabComponentGenerator('Items', 'System', $items))->generateDelegation('itemPrices', $items['delegations']['itemPrices']);
        (new DelegationRelatedFormGenerator('Items', 'System', $items))->generateRelatedModuleForms('itemPrices', $items['delegations']['itemPrices']);

        (new RoutesGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new SeederGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new FrontendLocaleGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new DeleteFormGenerator('ItemPrices', 'System', $itemPrices))->generate();
    }

    // ── Check 1: endpoint literal (Vue) -> Route:: path (api.php) ─────────

    public function test_every_backend_endpoint_literal_in_generated_vue_resolves_to_a_registered_route(): void
    {
        $this->generateFixture();

        $routePatterns = [];
        foreach ($this->allFiles('api.php') as $content) {
            // Every generated route is a chained call —
            // Route::middleware([...])->get('/path', ...) — so the path is
            // the first string argument after the HTTP-verb method, not
            // directly after `Route::`.
            preg_match_all('/->(?:get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m);
            foreach ($m[1] as $path) {
                $routePatterns[] = $this->normalizeSegments($path);
            }
        }
        $this->assertNotEmpty($routePatterns, 'no routes were generated to check endpoints against');

        foreach ($this->allFiles('.vue') as $path => $content) {
            foreach ($this->extractEndpointLiterals($content) as $endpoint) {
                if (str_starts_with($endpoint, '/select/')) {
                    continue; // shared framework select-data endpoint, not module-generated
                }
                $segments = $this->normalizeSegments($endpoint);
                $matched = false;
                foreach ($routePatterns as $routeSegments) {
                    if ($this->segmentsMatch($segments, $routeSegments)) {
                        $matched = true;
                        break;
                    }
                }
                $this->assertTrue($matched, "endpoint '{$endpoint}' in " . basename($path) . ' has no matching registered route');
            }
        }
    }

    // ── Check 2: permission:X / hasPermission('X') -> seeded permission ───

    public function test_every_permission_referenced_in_routes_and_vue_is_seeded(): void
    {
        $this->generateFixture();

        $required = [];
        foreach ($this->allFiles('api.php') as $content) {
            preg_match_all('/permission:([\w.]+)/', $content, $m);
            $required = array_merge($required, $m[1]);
        }
        foreach ($this->allFiles('.vue') as $content) {
            preg_match_all('/hasPermission\(\s*[\'"]([\w.]+)[\'"]\s*\)/', $content, $m);
            $required = array_merge($required, $m[1]);
        }
        $required = array_values(array_unique($required));
        $this->assertNotEmpty($required, 'no permissions were referenced to check against');

        $seeded = [];
        foreach ($this->allFiles('.json') as $content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && is_array($decoded['permissions'] ?? null)) {
                foreach ($decoded['permissions'] as $perm) {
                    if (isset($perm['name'])) {
                        $seeded[] = $perm['name'];
                    }
                }
            }
        }
        $seeded = array_values(array_unique($seeded));

        foreach ($required as $permission) {
            $this->assertContains($permission, $seeded, "permission '{$permission}' is referenced but never seeded");
        }
    }

    // ── Check 3: import path -> a file this run actually wrote ────────────

    public function test_every_import_in_generated_files_resolves_to_a_file_this_run_wrote(): void
    {
        $this->generateFixture();

        $written = array_merge($this->allFiles('.vue'), $this->allFiles('.ts'));

        foreach ($this->allFiles('.vue') as $path => $content) {
            foreach ($this->extractImportPaths($content) as $importPath) {
                $resolved = $this->resolveImport($importPath, dirname($path));
                if ($resolved === null) {
                    continue; // external package or hand-maintained SYSTEM_SHELL framework file
                }
                $this->assertArrayHasKey(
                    $resolved,
                    $written,
                    "import '{$importPath}' in " . basename($path) . " does not resolve to any file this run generated (resolved to {$resolved})"
                );
            }
        }
    }

    // ── Check 4: t('module.key') -> emitted locale file ────────────────────

    public function test_every_translation_key_used_in_generated_files_exists_in_the_locale_file(): void
    {
        $this->generateFixture();

        $keysByNamespace = [];
        foreach ($this->allFiles('en.json') as $content) {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $namespace => $keys) {
                if (is_array($keys)) {
                    $keysByNamespace[$namespace] = array_merge($keysByNamespace[$namespace] ?? [], array_keys($keys));
                }
            }
        }
        $this->assertNotEmpty($keysByNamespace, 'no locale files were generated to check against');

        // Only the module-route namespaces THIS fixture generates are in
        // scope. Every other namespace (`common`, `delete`, `entity`, ...) is
        // shared, hand-maintained SYSTEM_SHELL copy — same category as
        // `common.*` — not something any generator emits, so it's out of
        // scope for a check whose job is catching a generated file that
        // references the WRONG module's own namespace (e.g. `items.col_x` on
        // an ItemPrices column), not auditing the shared locale files.
        $moduleNamespaces = ['items', 'item-prices'];

        foreach ($this->allFiles('.vue') as $path => $content) {
            preg_match_all('/(?<!\w)\$?t\(\s*[\'"]([\w-]+)\.([\w]+)[\'"]/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                [, $namespace, $key] = $match;
                if (!in_array($namespace, $moduleNamespaces, true)) {
                    continue;
                }
                $this->assertArrayHasKey($namespace, $keysByNamespace, "no locale namespace '{$namespace}' was generated (used in " . basename($path) . ')');
                $this->assertContains($key, $keysByNamespace[$namespace] ?? [], "t('{$namespace}.{$key}') used in " . basename($path) . ' has no matching locale entry');
            }
        }
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

    /** @return array<string,string> absolute path => contents, for every file whose name ends with $suffix */
    private function allFiles(string $suffix): array
    {
        $files = [];
        if (!is_dir($this->tmpRoot)) {
            return $files;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        return $files;
    }

    /**
     * @return string[] every quoted string starting with '/' found on a line
     *     that actually makes or names a backend request (`send*Request(`,
     *     an `endpoint`-named computed/prop). A blanket scan over every
     *     quoted `/...` string also catches frontend PAGE navigation
     *     (`router.push('/items/list')`, `:to="..."`, `cancelLink`) — a
     *     different routing table (Vue Router) that happens to reuse the
     *     same path strings by convention, not a backend endpoint at all.
     */
    private function extractEndpointLiterals(string $content): array
    {
        $found = [];
        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, 'Request(') && stripos($line, 'endpoint') === false) {
                continue;
            }
            preg_match_all('/["\'`](\/[a-zA-Z0-9_\-\/${}.:]+)["\'`]/', $line, $m);
            $found = array_merge($found, $m[1]);
        }
        return array_values(array_unique($found));
    }

    /** @return string[] every import specifier, from static `import ... from '...'` and lazy `() => import('...')` */
    private function extractImportPaths(string $content): array
    {
        preg_match_all('/import\s+[^;]*?\sfrom\s+[\'"]([^\'"]+)[\'"]/', $content, $m1);
        preg_match_all('/\(\)\s*=>\s*import\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m2);
        return array_values(array_unique(array_merge($m1[1], $m2[1])));
    }

    /**
     * Resolve an import specifier to an absolute path IF it points at
     * something this run is responsible for generating: a relative import,
     * or an `@/pages/modules/...` import (the alias generated component
     * cross-references use). Every other `@/...` import and every bare
     * package import (vue, vue-i18n, lucide-vue-next, @/components/ui/...,
     * @/composables/..., ...) is hand-maintained SYSTEM_SHELL framework code
     * this run never touches, so it's out of scope — return null.
     */
    private function resolveImport(string $importPath, string $importingDir): ?string
    {
        if (str_starts_with($importPath, './') || str_starts_with($importPath, '../')) {
            $target = $importingDir . '/' . $importPath;
        } elseif (str_starts_with($importPath, '@/pages/modules/')) {
            $target = $this->tmpRoot . '/FRONTEND/src/' . substr($importPath, 2);
        } else {
            return null;
        }

        $parts = [];
        foreach (explode('/', $target) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return '/' . implode('/', $parts);
    }

    /** Laravel `{param}`, JS `${expr}`, and `:param` segments all become a wildcard so only structure is compared, not parameter names. */
    private function normalizeSegments(string $path): array
    {
        $normalized = preg_replace('/\$\{[^}]+\}|\{[^}]+\}|:[a-zA-Z_][a-zA-Z0-9_]*/', '*', $path);
        return array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
    }

    private function segmentsMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $segment) {
            if ($segment === '*' || $b[$i] === '*') {
                continue;
            }
            if ($segment !== $b[$i]) {
                return false;
            }
        }
        return true;
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
