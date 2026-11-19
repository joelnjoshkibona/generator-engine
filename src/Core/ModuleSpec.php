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
        foreach (['morphs' => 'polymorphic relations', 'file_columns' => 'file columns', 'inline_items' => 'document lines (P115, step 4d)', 'delegations' => 'child tabs (P86, step 4b)', 'actions' => 'actions (P84, step 4c)'] as $key => $what) {
            if (! empty($spec[$key])) {
                throw new InvalidArgumentException("generator.json: '{$key}' ({$what}) is not in the Bootbox target yet");
            }
        }
        $labelColumn = $spec['label_column'] ?? 'name';
        $label = array_values(array_filter($spec['columns'], fn ($c) => ($c['name'] ?? null) === $labelColumn))[0] ?? null;
        if ($label === null || ColumnTypes::kind($label) !== 'string' || ! empty($label['nullable'])) {
            throw new InvalidArgumentException("generator.json: the label column '{$labelColumn}' must be a required string column (set label_column to the one the records are named by)");
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

    /** An override from features.backend.{feature}.{key}, or null. */
    public function backend(string $feature, string $key): mixed
    {
        return $this->spec['features']['backend'][$feature][$key] ?? null;
    }
}
