<?php

namespace Blutrixx\GeneratorEngine\Core;

use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * A module's generator.json, read: today's module config (module_name, module_group_name, table_name, columns with
 * type/length/nullable/unique/relatedModule, features.backend.* overrides) plus the keys a starter with its own
 * skeleton needs (scope, label, label_column, sync, keep_days, workflow, icon, search). Every reader is pure.
 */
final class ModuleSpec
{
    public const SYSTEM_COLUMNS = ['id', 'uuid', 'organization_id', 'location_id', 'version', 'created_by_id', 'updated_by_id', 'created_at', 'updated_at', 'deleted_at'];

    private function __construct(private readonly array $spec) {}

    public static function fromArray(array $spec): self
    {
        foreach (['module_name', 'columns'] as $key) {
            if (! isset($spec[$key])) {
                throw new InvalidArgumentException("generator.json: '{$key}' is required");
            }
        }
        $scope = $spec['scope'] ?? 'organisation';
        if (! in_array($scope, ['organisation', 'location', 'global'], true)) {
            throw new InvalidArgumentException("generator.json: scope '{$scope}' is not organisation, location or global");
        }
        foreach (['morphs' => 'polymorphic relations', 'file_columns' => 'file columns', 'inline_items' => 'document lines (P115, step 4d)', 'delegations' => "related-record tabs: the child declares its tab with 'tab_on' (P86)"] as $key => $what) {
            if (! empty($spec[$key])) {
                throw new InvalidArgumentException("generator.json: '{$key}' ({$what}) is not in the Bootbox target yet");
            }
        }
        $labelColumn = $spec['label_column'] ?? 'name';
        $label = array_values(array_filter($spec['columns'], fn ($c) => ($c['name'] ?? null) === $labelColumn))[0] ?? null;
        if ($label === null || ColumnTypes::kind($label) !== 'string' || ! empty($label['nullable'])) {
            throw new InvalidArgumentException("generator.json: the label column '{$labelColumn}' must be a required string column (set label_column to the one the records are named by)");
        }
        if (! empty($spec['children'])) {
            throw new InvalidArgumentException("generator.json: 'children' is declared by each child instead, with 'tab_on' (P86)");
        }
        foreach ($spec['tab_on'] ?? [] as $parent => $tab) {
            if (empty($tab['key']) || empty($tab['via']) || empty($tab['tab'])) {
                throw new InvalidArgumentException("generator.json: tab_on.{$parent} needs key, via and tab");
            }
            $via = array_values(array_filter($spec['columns'], fn ($c) => ($c['name'] ?? null) === $tab['via']))[0] ?? null;
            if ($via === null || ($via['relatedModule'] ?? null) !== $parent) {
                throw new InvalidArgumentException("generator.json: tab_on.{$parent}.via ({$tab['via']}) must be a column pointing at {$parent}");
            }
        }
        foreach ($spec['actions'] ?? [] as $key => $action) {
            self::checkAction((string) $key, (array) $action, $spec);
        }
        foreach ($spec['columns'] as $i => $column) {
            if (empty($column['name'])) {
                throw new InvalidArgumentException("generator.json: columns[{$i}] has no name");
            }
            if (ColumnTypes::kind($column) === 'foreign' && empty($column['relatedModule'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} is a foreign key without relatedModule");
            }
            if (in_array($column['type'] ?? '', ['json', 'jsonb', 'morphs', 'nullableMorphs'], true) || ! empty($column['file'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} ({$column['type']}) is not in the Bootbox target yet: it waits for a kit decision (D14)");
            }
            if (ColumnTypes::kind($column) === 'workflow' && empty($spec['workflow'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} is a workflow column but there is no workflow");
            }
        }

        return new self($spec);
    }

    /** P84: what an action entry may say, and what it may not (yet). */
    private static function checkAction(string $key, array $a, array $spec): void
    {
        $where = "generator.json: actions.{$key}";
        if (! preg_match('/^[a-z][a-zA-Z0-9]*$/', $key)) {
            throw new InvalidArgumentException("{$where}: the key is camelCase (close, sendReminder): it names the route, the permission and the service");
        }
        foreach (['wizard' => 'wizards are not in the Bootbox target yet', 'confirm_step' => 'wizards are not in the Bootbox target yet', 'splash' => 'splash data is not in the Bootbox target yet', 'operations' => 'an action has one route, POST /{module}/{uuid}/{action}', 'serviceMethod' => 'the service is {Module}{Action}Service::handle()', 'serviceArgs' => 'the service is {Module}{Action}Service::handle()'] as $legacy => $why) {
            if (isset($a[$legacy])) {
                throw new InvalidArgumentException("{$where}.{$legacy}: {$why}");
            }
        }
        if (($a['uiType'] ?? 'modal') !== 'modal') {
            throw new InvalidArgumentException("{$where}: page actions are not in the Bootbox target yet (a dialog is)");
        }
        if (isset($a['urlParams']) && $a['urlParams'] !== ['uuid']) {
            throw new InvalidArgumentException("{$where}: an action runs on one record (urlParams [\"uuid\"]); other parameters are fields");
        }
        if (empty($a['label'])) {
            throw new InvalidArgumentException("{$where} needs a label");
        }
        if (! in_array($a['button'] ?? 'more', ['header', 'more'], true)) {
            throw new InvalidArgumentException("{$where}.button is header or more");
        }
        $names = array_column($spec['columns'], null, 'name');
        foreach ((array) ($a['fields'] ?? []) as $field) {
            if (! is_string($field) || ! isset($names[$field])) {
                throw new InvalidArgumentException("{$where}.fields: list the module's own column names (".json_encode($field).' is not one)');
            }
        }
        if (! empty($a['fields']) && ! empty($a['bulk'])) {
            throw new InvalidArgumentException("{$where}: a bulk action takes no fields");
        }
        if (isset($a['status'])) {
            $codes = array_column($spec['workflow']['statuses'] ?? [], 'code');
            if (! in_array($a['status'], $codes, true)) {
                throw new InvalidArgumentException("{$where}.status '{$a['status']}' is not a status of the module's workflow");
            }
        }
    }

    public static function fromFile(string $path): self
    {
        $spec = json_decode((string) file_get_contents($path), true);
        if (! is_array($spec)) {
            throw new InvalidArgumentException("{$path} is not a JSON object");
        }

        return self::fromArray($spec);
    }

    public function raw(): array
    {
        return $this->spec;
    }

    public function key(): string
    {
        return Str::studly($this->spec['module_name']);
    }

    public function group(): string
    {
        return Str::studly($this->spec['module_group_name'] ?? $this->key());
    }

    public function table(): string
    {
        return $this->spec['table_name'] ?? Str::snake($this->key());
    }

    public function route(): string
    {
        return Str::kebab($this->key());
    }

    public function scope(): string
    {
        return $this->spec['scope'] ?? 'organisation';
    }

    public function isOrganisationScoped(): bool
    {
        return $this->scope() !== 'global';
    }

    /** What one record is called ("Task"). */
    public function recordLabel(): string
    {
        return $this->spec['label'] ?? Str::singular(Str::headline($this->key()));
    }

    /** The column a record is named by. */
    public function labelColumn(): string
    {
        return $this->spec['label_column'] ?? 'name';
    }

    public function description(): ?string
    {
        return $this->spec['description'] ?? null;
    }

    public function icon(): ?string
    {
        return $this->spec['icon'] ?? null;
    }

    public function sync(): bool
    {
        return (bool) ($this->spec['sync'] ?? false);
    }

    public function keepDays(): ?int
    {
        return isset($this->spec['keep_days']) ? (int) $this->spec['keep_days'] : null;
    }

    /** @return array{key: string, statuses: array<int, array<string, mixed>>}|null */
    public function workflow(): ?array
    {
        return $this->spec['workflow'] ?? null;
    }

    public function search(): ?array
    {
        return $this->spec['search'] ?? null;
    }

    /** The module's own columns, in order, without the skeleton's (id, scope, version, audit, timestamps). */
    public function columns(): array
    {
        return array_values(array_filter($this->spec['columns'], fn (array $c) => ! in_array($c['name'], self::SYSTEM_COLUMNS, true)));
    }

    public function column(string $name): ?array
    {
        foreach ($this->columns() as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function foreignKeys(): array
    {
        return array_values(array_filter($this->columns(), fn (array $c) => ColumnTypes::kind($c) === 'foreign'));
    }

    /**
     * P86: the tabs this module adds to its parents' pages, each its own list scoped to the parent record:
     * parent module key => ['key' => tab key, 'via' => this module's column to the parent, 'tab' => label, 'tab_sw' => ?].
     *
     * @return array<string, array{key: string, via: string, tab: string, tab_sw?: string}>
     */
    public function tabOn(): array
    {
        return $this->spec['tab_on'] ?? [];
    }

    /**
     * P84: the record actions, normalised (the legacy placement/destructive read as button/danger):
     * key => [label, label_sw, icon, button (header|more), danger, confirm, bulk, approval, effect, status (a workflow
     * code it moves to), fields (the module's column names it asks for)].
     *
     * @return array<string, array{label: string, label_sw: ?string, icon: ?string, button: string, danger: bool, confirm: bool, bulk: bool, approval: bool, effect: ?string, status: ?string, fields: list<string>}>
     */
    public function actions(): array
    {
        $out = [];
        foreach ($this->spec['actions'] ?? [] as $key => $a) {
            $out[$key] = [
                'label' => $a['label'],
                'label_sw' => $a['label_sw'] ?? null,
                'icon' => $a['icon'] ?? null,
                'button' => $a['button'] ?? (($a['placement'] ?? null) === 'main' ? 'header' : 'more'),
                'danger' => (bool) ($a['danger'] ?? $a['destructive'] ?? false),
                'confirm' => (bool) ($a['confirm'] ?? false),
                'bulk' => (bool) ($a['bulk'] ?? false),
                'approval' => (bool) ($a['approval'] ?? false),
                'effect' => $a['effect'] ?? null,
                'status' => $a['status'] ?? null,
                'fields' => array_values((array) ($a['fields'] ?? [])),
            ];
        }

        return $out;
    }

    /** An override from features.backend.{feature}.{key}, or null. */
    public function backend(string $feature, string $key): mixed
    {
        return $this->spec['features']['backend'][$feature][$key] ?? null;
    }
}
