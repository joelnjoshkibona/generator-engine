<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\DdlRenderer;
use Illuminate\Support\Str;

/**
 * Emits FRONTEND/src/api-contract.json — a machine-readable description of the API
 * a generated frontend expects: every route, every column, every validation rule.
 *
 * Two consumers, one artifact:
 *
 *  - the browser-side mock (src/mock/), which needs the real URLs, the real column
 *    types to build a schema from, and the real rules to reproduce 422s, so a
 *    prototype can run with no backend at all; and
 *  - a human or agent standing the real backend up later, for whom this is the
 *    hand-off document.
 *
 * **Routes are read, never re-derived.** They come from parsing RoutesGenerator's
 * own emitted content (see buildContent()), because the config keys that look
 * authoritative are not: `features.backend.edit.endpoint` in a real module.json says
 * `PUT /statuses`, while the route the backend actually registers is
 * `PUT /statuses/{uuid}/edit`. Same divergence on list, view and delete. Anything
 * describing those endpoints from `endpoint` blocks 404s on contact with the server.
 * ApiContractRouteParityTest holds the line.
 *
 * Like ModulesJsonGenerator, this merges into a shared file rather than owning it,
 * so scaffolding N modules leaves one contract describing all N.
 */
class ApiContractGenerator extends BaseGenerator
{
    /** Matches a single emitted route line: method, path, controller method. */
    private const ROUTE_PATTERN = '/->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[[^,]+,\s*[\'"]([^\'"]+)[\'"]\s*\]/i';

    /** Matches the permission out of a route's middleware list, when it has one. */
    private const PERMISSION_PATTERN = '/[\'"]permission:([^\'"]+)[\'"]/';

    public function generate(): bool
    {
        $path     = PathManager::getFrontendSrcPath() . '/api-contract.json';
        $contract = $this->loadExisting($path);

        $contract['modules'][$this->moduleName] = $this->buildModuleContract();
        ksort($contract['modules']);

        return $this->writeFileAlways(
            $path,
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * @return array{modules: array<string, mixed>}
     */
    private function loadExisting(string $path): array
    {
        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['modules']) && is_array($decoded['modules'])) {
                return $decoded;
            }
        }

        return ['modules' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildModuleContract(): array
    {
        $table = $this->config['table_name'] ?? Str::snake(Str::plural($this->moduleName));

        return [
            'group'       => $this->moduleGroup,
            'subGroup'    => $this->moduleSubGroup,
            'table'       => $table,
            'route'       => Str::kebab($this->moduleName),
            'idType'      => $this->config['id_type'] ?? 'bigint',
            'flags'       => [
                'timestamps'     => (bool) ($this->config['has_timestamps'] ?? true),
                'softDeletes'    => (bool) ($this->config['has_soft_deletes'] ?? false),
                'uuid'           => (bool) ($this->config['has_uuid'] ?? true),
                'creatorUpdater' => (bool) ($this->config['has_creator_updater'] ?? true),
            ],
            'columns'     => $this->buildColumns(),
            'routes'      => $this->buildRoutes(),
            'validation'  => $this->buildValidation(),
            'list'        => $this->buildListContract(),
            'delegations' => array_keys($this->config['delegations'] ?? []),
            'actions'     => array_keys($this->config['actions'] ?? []),
            'morphs'      => $this->config['morphs'] ?? [],
            // fullTable(), not fromColumns(): the mock has to CREATE and INSERT against
            // these, and fromColumns() renders only the business columns — no id, no
            // uuid, no timestamps — because the project's authoring convention keeps
            // those out of module config entirely. Both dialects are emitted so the
            // same contract seeds a prototype and hands a real MySQL schema to whoever
            // promotes it.
            'ddl'         => [
                'mysql'  => DdlRenderer::fullTable($table, $this->config['columns'] ?? [], $this->conventionFlags(), 'mysql'),
                'sqlite' => DdlRenderer::fullTable($table, $this->config['columns'] ?? [], $this->conventionFlags(), 'sqlite'),
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function conventionFlags(): array
    {
        return [
            'has_timestamps'      => (bool) ($this->config['has_timestamps'] ?? true),
            'has_soft_deletes'    => (bool) ($this->config['has_soft_deletes'] ?? false),
            'has_uuid'            => (bool) ($this->config['has_uuid'] ?? true),
            'has_creator_updater' => (bool) ($this->config['has_creator_updater'] ?? true),
        ];
    }

    /**
     * Column metadata reduced to what a consumer actually needs to build a table and
     * fake a row: the physical type, its constraints, and where a foreign key points.
     *
     * `featureSelections` is deliberately dropped — it is authoring-time UI state for
     * the module builder, not part of the API surface, and it roughly doubles the
     * file size.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildColumns(): array
    {
        $columns = [];

        foreach ($this->config['columns'] ?? [] as $column) {
            $entry = [
                'name'     => $column['name'] ?? '',
                'type'     => $column['type'] ?? 'string',
                'nullable' => (bool) ($column['nullable'] ?? true),
                'unique'   => (bool) ($column['unique'] ?? false),
                'indexed'  => (bool) ($column['indexed'] ?? false),
            ];

            foreach (['length', 'default', 'relatedModule'] as $optional) {
                if (($column[$optional] ?? '') !== '') {
                    $entry[$optional] = $column[$optional];
                }
            }

            $columns[] = $entry;
        }

        return $columns;
    }

    /**
     * Every route the backend registers for this module, parsed out of the routes
     * file this same engine emits.
     *
     * @return array<int, array{method: string, path: string, permission: string|null, handler: string}>
     */
    private function buildRoutes(): array
    {
        $content = (new RoutesGenerator($this->moduleName, $this->moduleGroup, $this->config))->buildContent();
        $routes  = [];

        foreach (explode("\n", $content) as $line) {
            if (!preg_match(self::ROUTE_PATTERN, $line, $match)) {
                continue;
            }

            $routes[] = [
                'method'     => strtoupper($match[1]),
                'path'       => $match[2],
                'permission' => preg_match(self::PERMISSION_PATTERN, $line, $perm) ? $perm[1] : null,
                'handler'    => $match[3],
            ];
        }

        return $routes;
    }

    /**
     * Laravel rule strings per feature, keyed by field.
     *
     * Passed through verbatim rather than parsed: a mock enforcing `required`,
     * `max:100` and `unique:table,column` reproduces the real 422 payloads the
     * generated forms already know how to render, and anything it cannot interpret
     * it can safely ignore.
     *
     * @return array<string, array<string, string>>
     */
    private function buildValidation(): array
    {
        $validation = [];

        foreach (['create', 'edit'] as $feature) {
            $fields = $this->config['features']['backend'][$feature]['fields'] ?? [];
            $rules  = [];

            foreach ($fields as $field) {
                if (isset($field['field'])) {
                    $rules[$field['field']] = $field['rules'] ?? '';
                }
            }

            if ($rules !== []) {
                $validation[$feature] = $rules;
            }
        }

        return $validation;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListContract(): array
    {
        $backendList  = $this->config['features']['backend']['list'] ?? [];
        $frontendList = $this->config['features']['frontend']['list'] ?? [];

        return [
            'primaryField'    => $frontendList['primaryField'] ?? null,
            'filterFields'    => $backendList['filterFields'] ?? [],
            'filterableFields' => $backendList['filterableFields'] ?? [],
            'sortableFields'  => $backendList['sortableFields'] ?? [],
            'export'          => (bool) ($backendList['export'] ?? false),
            'import'          => (bool) ($backendList['import'] ?? false),
            'bulkActions'     => $backendList['bulk_actions'] ?? [],
        ];
    }
}
