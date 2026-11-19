<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ListSpec;
use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Blutrixx\GeneratorEngine\Core\SampleValues;
use Blutrixx\GeneratorEngine\Core\ValidationRules;
use Illuminate\Support\Str;

/**
 * The API side of a Bootbox module (P123): each slot's body, the imports each file needs, and the files the generator
 * owns whole. Paths are relative to the module folder; `{Key}` is the module key.
 */
final class ApiRenderer
{
    private const I4 = '    ';

    private const I8 = '        ';

    private const I12 = '            ';

    private const WORKFLOW_STATUSES = 'App\Modules\Kernel\System\WorkflowStatuses\WorkflowStatuses';

    private const HAS_WORKFLOW_STATUS = 'App\Modules\Kernel\System\WorkflowStatuses\HasWorkflowStatus';

    private const MEMBER_OF_ORGANIZATION = 'App\Platform\Rules\MemberOfOrganization';

    private const RULE = 'Illuminate\Validation\Rule';

    public function __construct(private readonly ModuleSpec $spec, private readonly ModuleResolver $modules) {}

    /** @return array{slots: array<string, array<string, string>>, imports: array<string, list<string>>, files: array<string, string>} */
    public function render(): array
    {
        $k = $this->spec->key();
        $slots = $imports = [];
        [$slots["{$k}Model.php"], $imports["{$k}Model.php"]] = $this->model();
        $slots["{$k}Factory.php"] = ['definition' => $this->factory()];
        $slots['MIGRATION'] = ['columns' => $this->migrationColumns()];
        [$slots["Services/{$k}ListService.php"], $imports["Services/{$k}ListService.php"]] = $this->list();
        foreach (['create' => 'Create', 'edit' => 'Edit'] as $op => $name) {
            [$body, $imports["Services/{$k}{$name}Service.php"]] = $this->rules($op);
            $slots["Services/{$k}{$name}Service.php"] = ['rules' => $body];
        }
        $slots["Services/{$k}ViewService.php"] = ['with' => $this->lines(ListSpec::eager($this->spec, $this->modules, true), self::I12, fn ($w) => "'{$w}',")];
        $slots["Tests/{$k}Test.php"] = ['fields' => $this->testFields()];

        return ['slots' => $slots, 'imports' => $imports, 'files' => $this->ownFiles()];
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    private function model(): array
    {
        $imports = [];
        $traits = $constants = '';
        if (($wf = $this->spec->workflow()) !== null) {
            $imports[] = self::HAS_WORKFLOW_STATUS;
            $traits = self::I4."use HasWorkflowStatus;\n";
            $constants = "\n".self::I4."/** P47: its status is the {$wf['key']} workflow's ({$wf['key']}_statuses); code compares the code, never the label. */\n"
                .self::I4."public const WORKFLOW = '{$wf['key']}';\n";
        }
        $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
        foreach ($this->spec->columns() as $c) {
            if (($cast = ColumnTypes::cast($c)) !== null) {
                $casts[$c['name']] = $cast;
            }
        }
        $castLine = self::I4.'protected $casts = ['.implode(', ', array_map(fn ($f, $c) => "'{$f}' => '{$c}'", array_keys($casts), $casts))."];\n";

        $relations = [$this->belongsTo('creator', 'App\Modules\Core\People\Users\UsersModel', 'created_by_id')];
        $imports[] = 'App\Modules\Core\People\Users\UsersModel';
        foreach ($this->spec->foreignKeys() as $c) {
            $class = $this->modules->modelClass($c['relatedModule']);
            $imports[] = $class;
            $relations[] = $this->belongsTo(ColumnTypes::relationName($c['name']), $class, $c['name']);
        }
        if ($this->spec->scope() === 'location') {
            $class = $this->modules->modelClass('Locations');
            $imports[] = $class;
            $relations[] = $this->belongsTo('location', $class, 'location_id');
        }

        return [['traits' => $traits, 'constants' => $constants, 'casts' => $castLine, 'relations' => implode("\n", $relations)], $imports];
    }

    private function belongsTo(string $name, string $class, string $column): string
    {
        $short = class_basename($class);

        return self::I4."public function {$name}(): BelongsTo\n".self::I4."{\n".self::I8."return \$this->belongsTo({$short}::class, '{$column}');\n".self::I4."}\n";
    }

    private function factory(): string
    {
        $out = '';
        foreach ($this->spec->columns() as $c) {
            $sample = SampleValues::for($this->spec, $c);
            $value = match ($sample['kind']) {
                'unique_words' => '$this->faker->unique()->words(3, true)',
                'words' => '$this->faker->words(3, true)',
                'sentence' => '$this->faker->sentence()',
                'integer' => '$this->faker->numberBetween(1, 1000)',
                'decimal' => '$this->faker->randomFloat('.$sample['params']['scale'].', 1, 1000)',
                'boolean' => '$this->faker->boolean()',
                'future_date' => "\$this->faker->dateTimeBetween('now', '+{$sample['params']['days']} days')->format('Y-m-d')",
                'datetime' => '$this->faker->dateTimeBetween(\'-30 days\', \'now\')',
                'enum' => '$this->faker->randomElement('.var_export($sample['params']['values'], true).')',
                'json' => '[]',
                // a nullable foreign key and the workflow status are left out (HasWorkflowStatus gives the first)
                'foreign' => $sample['params']['nullable'] ? null : class_basename($this->modules->modelClass($sample['params']['module'])).'::factory()',
                default => null,
            };
            if ($value !== null) {
                $out .= self::I12."'{$c['name']}' => {$value},\n";
            }
        }

        return $out;
    }

    private function migrationColumns(): string
    {
        $out = '';
        foreach ($this->spec->columns() as $c) {
            $n = $c['name'];
            $nullable = ! empty($c['nullable']) ? '->nullable()' : '';
            $line = match (ColumnTypes::kind($c)) {
                'string' => isset($c['length']) ? "\$table->string('{$n}', ".(int) $c['length'].')' : "\$table->string('{$n}')",
                'text' => "\$table->text('{$n}')",
                'integer' => "\$table->integer('{$n}')",
                'decimal' => "\$table->decimal('{$n}', ".(int) ($c['precision'] ?? 12).', '.(int) ($c['scale'] ?? 2).')',
                'boolean' => "\$table->boolean('{$n}')->default(false)",
                'date' => "\$table->date('{$n}')",
                'datetime' => "\$table->dateTime('{$n}')",
                'time' => "\$table->time('{$n}')",
                'json' => "\$table->json('{$n}')",
                'enum' => "\$table->enum('{$n}', ".var_export(array_values($c['values'] ?? []), true).')',
                'foreign' => "\$table->unsignedBigInteger('{$n}')",
                'workflow' => "\$table->unsignedBigInteger('{$n}')",
                default => "\$table->string('{$n}')",
            };
            $kind = ColumnTypes::kind($c);
            $index = in_array($kind, ['foreign', 'workflow'], true) ? '->index()' : '';
            $comment = match ($kind) {
                'workflow' => ' // P47: the '.$this->spec->workflow()['key'].' workflow',
                'foreign' => ' // '.$this->modules->table($c['relatedModule']).'.id',
                default => '',
            };
            $out .= self::I12.$line.($kind === 'boolean' ? '' : $nullable).$index.';'.$comment."\n";
        }
        foreach ($this->spec->columns() as $c) {
            if (! empty($c['unique'])) {
                $cols = $this->spec->isOrganisationScoped() ? "['organization_id', '{$c['name']}']" : "'{$c['name']}'";
                $out .= self::I12."\$table->unique({$cols});\n";
            }
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    private function list(): array
    {
        $imports = [];
        $array = fn (array $items) => '['.implode(', ', array_map(fn ($i) => "'{$i}'", $items)).']';
        $slots = [
            'filterable' => self::I4.'protected static array $filterableFields = '.$array(ListSpec::filterable($this->spec)).";\n",
            'sortable' => self::I4.'protected static array $sortableFields = '.$array(ListSpec::sortable($this->spec)).";\n",
            'searchable' => self::I4.'protected static array $searchableFields = '.$array(ListSpec::searchable($this->spec)).";\n",
            'eager' => self::I4.'protected static array $eagerLoadRelationships = '.$array(ListSpec::eager($this->spec, $this->modules)).";\n",
        ];
        $filters = '';
        foreach (ListSpec::filterFields($this->spec) as $f) {
            if ($f['key'] === 'search') {
                continue;
            }
            $head = "['key' => '{$f['key']}', 'label' => '".addslashes($f['label'])."', ";
            $filters .= self::I12.$head.match ($f['type']) {
                'workflow' => "'type' => 'select', 'options' => app(WorkflowStatuses::class)->rows('{$f['workflow']}')->map(fn (\$s) => ['value' => \$s->id, 'label' => \$s->label])->values()->all()],",
                'select_paginated' => "'type' => 'select_paginated', 'module' => '{$f['module']}'],",
                'select' => "'type' => 'select', 'options' => ".$this->options($f['values']).'],',
                default => "'type' => '{$f['type']}'],",
            }."\n";
            if ($f['type'] === 'workflow') {
                $imports[] = self::WORKFLOW_STATUSES;
            }
        }
        $slots['filter-fields'] = $filters;

        return [$slots, $imports];
    }

    private function options(array $values): string
    {
        return '['.implode(', ', array_map(fn ($v) => "['value' => '{$v}', 'label' => '".Str::headline((string) $v)."']", $values)).']';
    }

    /** @return array{0: string, 1: list<string>} */
    private function rules(string $op): array
    {
        $imports = [];
        $out = '';
        foreach (ValidationRules::for($this->spec, $op, $this->modules) as $entry) {
            $parts = [];
            foreach ($entry['rules'] as $rule) {
                if (is_string($rule)) {
                    $parts[] = "'{$rule}'";

                    continue;
                }
                switch ($rule['rule']) {
                    case 'exists':
                        $parts[] = "'".($rule['scoped'] ? 'exists_in_org' : 'exists').":{$rule['table']},{$rule['column']}'";
                        if (($rule['module'] ?? null) === 'Users') {
                            $parts[] = 'new MemberOfOrganization';
                            $imports[] = self::MEMBER_OF_ORGANIZATION;
                        }
                        break;
                    case 'unique':
                        $base = ($rule['scoped'] ? 'unique_in_org' : 'unique').":{$rule['table']},{$rule['column']}";
                        $parts[] = $rule['ignoreSelf'] ? "\"{$base},{\$id}\"" : "'{$base}'";
                        break;
                    case 'in':
                        $parts[] = 'Rule::in('.var_export($rule['values'], true).')';
                        $imports[] = self::RULE;
                        break;
                }
            }
            $out .= self::I12."'{$entry['field']}' => [".implode(', ', $parts).'],'.($entry['comment'] ? " // {$entry['comment']}" : '')."\n";
        }

        return [$out, $imports];
    }

    /** A sample for each plain column (not the label, a foreign key or the status). */
    private function testFields(): string
    {
        $out = '';
        foreach ($this->spec->columns() as $c) {
            if ($c['name'] === $this->spec->labelColumn() || in_array(ColumnTypes::kind($c), ['foreign', 'workflow'], true)) {
                continue;
            }
            $value = match (ColumnTypes::kind($c)) {
                'text' => "'Hello'",
                'date' => "'2030-01-15'",
                'datetime' => "'2030-01-15 09:30:00'",
                'integer' => '1',
                'decimal' => "'10.50'",
                'boolean' => 'true',
                'enum' => var_export(($c['values'] ?? [''])[0], true),
                'json' => '[]',
                default => "'Example'",
            };
            $out .= self::I12."'{$c['name']}' => {$value},\n";
        }

        return $out;
    }

    /** Files the generator writes whole, rewritten every run: a workflow's statuses migration. */
    private function ownFiles(): array
    {
        if (($wf = $this->spec->workflow()) === null) {
            return [];
        }
        $w = $wf['key'];

        return ["Migrations/DATE_000000_create_{$w}_statuses_table.php" => <<<PHP
<?php

use App\\Modules\\Kernel\\System\\WorkflowStatuses\\WorkflowRegistry;
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/** P47: the {$w} workflow's statuses, one copy per organisation (the defaults are in module.json). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$w}_statuses', fn (Blueprint \$table) => WorkflowRegistry::columns(\$table));
    }

    public function down(): void
    {
        Schema::dropIfExists('{$w}_statuses');
    }
};

PHP];
    }

    /** @param list<string> $items */
    private function lines(array $items, string $indent, callable $each): string
    {
        return implode('', array_map(fn ($i) => $indent.$each($i)."\n", $items));
    }
}
