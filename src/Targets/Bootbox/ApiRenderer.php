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
        [$slots["{$k}Factory.php"]['definition'], $imports["{$k}Factory.php"]] = $this->factory();
        $slots['MIGRATION'] = ['columns' => $this->migrationColumns()];
        [$slots["Services/{$k}ListService.php"], $imports["Services/{$k}ListService.php"]] = $this->list();
        foreach (['create' => 'Create', 'edit' => 'Edit'] as $op => $name) {
            [$body, $imports["Services/{$k}{$name}Service.php"]] = $this->rules($op);
            $slots["Services/{$k}{$name}Service.php"] = ['rules' => $body.$this->lineRules($op)];
        }
        $slots["Services/{$k}Csv.php"] = ['csv-columns' => $this->csvColumns()];
        $with = ListSpec::eager($this->spec, $this->modules, true);
        if (($l = $this->spec->lines()) !== null) {
            $with[] = 'lines.item:id,uuid,'.$this->modules->labelColumn($l['item_module']); // P115
        }
        $slots["Services/{$k}ViewService.php"] = ['with' => $this->lines($with, self::I12, fn ($w) => "'{$w}',")];
        [$fields, $imports["Tests/{$k}Test.php"]] = $this->testFields();
        $slots["Tests/{$k}Test.php"] = ['fields' => $fields];
        if ($this->spec->sync()) {
            // the offline record carries the same required fields (P102, P123 contract 3)
            $slots["Tests/{$k}SyncTest.php"] = ['fields' => $fields];
            $imports["Tests/{$k}SyncTest.php"] = $imports["Tests/{$k}Test.php"];
        }

        return ['slots' => $slots, 'imports' => $imports, 'files' => $this->ownFiles() + $this->actionsContractTest() + $this->lineFiles(), 'once' => $this->actionServices()];
    }

    /** The list's CSV (P123 contract 4, D188): the web list's columns, in its order, with the web's words as headers. */
    private function csvColumns(): string
    {
        $ns = Str::camel($this->spec->key());
        $head = '';
        $rows = [];
        foreach (ListSpec::columns($this->spec, $this->modules) as $col) {
            $n = $col['column'] ?? '';
            $cell = match ($col['kind']) {
                'label', 'number' => "\$r['{$n}']",
                'total' => "ExportText::fixed(\$r['total'])",
                'status' => "\$status[\$r['{$n}']] ?? ''",
                'date', 'created' => "\$t->day(\$r['{$n}'])",
                'string' => "\$r['{$n}'] ?? ''",
                'integer', 'decimal' => "ExportText::str(\$r['{$n}'])",
                'enum' => "(\$r['{$n}'] ?? '') === '' ? '' : \$t->t('{$ns}.values.{$col['key']}.'.\$r['{$n}'])",
                'boolean' => "\$r['{$n}'] ? \$t->t('listToolbar.yes') : \$t->t('listToolbar.no')",
                'link' => "\$r['{$col['rowKey']}']['{$col['labelColumn']}'] ?? ''",
            };
            if ($col['kind'] === 'status') {
                $head = self::I8."\$status = self::statuses('{$this->spec->workflow()['key']}', \$t);\n\n";
            }
            $rows[] = self::I12."[\$t->t('{$ns}.columns.{$col['key']}'), fn (array \$r) => {$cell}],";
        }

        return $head.self::I8."return [\n".implode("\n", $rows)."\n".self::I8."];\n";
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    private function model(): array
    {
        $imports = [];
        $traits = [];
        $constants = '';
        if (($wf = $this->spec->workflow()) !== null) {
            $imports[] = self::HAS_WORKFLOW_STATUS;
            $traits[] = 'HasWorkflowStatus';
            $constants = "\n".self::I4."/** P47: its status is the {$wf['key']} workflow's ({$wf['key']}_statuses); code compares the code, never the label. */\n"
                .self::I4."public const WORKFLOW = '{$wf['key']}';\n";
        }
        if (($l = $this->spec->lines()) !== null) {
            $imports[] = 'App\\Platform\\Models\\Concerns\\HasDocumentLines';
            $traits[] = 'HasDocumentLines';
            $k = $this->spec->key();
            $columns = $this->spec->priced() ? "['item_id', 'qty', 'price', 'disc', 'vat']" : "['item_id', 'qty']";
            $lock = '';
            $entry = "'model' => {$k}LineModel::class, 'key' => '{$this->spec->linesKey()}', 'columns' => {$columns}, 'kind' => '{$l['kind']}', 'prices_include_vat' => ".($l['prices_include_vat'] ? 'true' : 'false');
            if ($l['editable_status'] !== null) {
                $lock = '; changed only while it is '.$this->statusLabel($l['editable_status']);
                $entry .= ", 'editable_status' => '{$l['editable_status']}'";
            }
            $constants .= "\n".self::I4."/** P115: its lines ({$this->spec->linesTable()}), saved with it by every create and edit{$lock}. */\n"
                .self::I4."public const LINES = [{$entry}];\n";
        }
        if (($n = $this->spec->number()) !== null) {
            $imports[] = 'App\\Platform\\Models\\Concerns\\HasDocumentNumber';
            $traits[] = 'HasDocumentNumber';
            $constants .= "\n".self::I4."/** P115: its number ({$n['prefix']}-2026-0001), made when it is created. */\n"
                .self::I4."public const NUMBER = ['column' => '{$n['column']}', 'prefix' => '{$n['prefix']}'];\n";
        }
        if (($media = $this->spec->media()) !== []) {
            $imports[] = 'App\\Platform\\Models\\Concerns\\HasMediaColumns';
            $traits[] = 'HasMediaColumns';
            $entries = implode(', ', array_map(fn ($n, $a) => "'{$n}' => '{$a}'", array_keys($media), $media));
            $constants .= "\n".self::I4."/** P131: files in the Media library, stored as <name>_media_id and sent as the media's uuid. */\n"
                .self::I4."public const MEDIA = [{$entries}];\n";
        }
        if ($this->spec->labelColumn() !== 'name') {
            // the pickers, related links and global search name its records by this column, not `name`
            $constants .= "\n".self::I4."/** Its records are named by {$this->spec->labelColumn()}: what its pickers, related links and search show. */\n"
                .self::I4."public const SELECT_LABEL = '{$this->spec->labelColumn()}';\n";
        }
        sort($traits);
        $traits = $traits === [] ? '' : self::I4.'use '.implode(', ', $traits).";\n";
        $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];
        foreach ($this->spec->columns() as $c) {
            if (($cast = ColumnTypes::cast($c)) !== null) {
                $casts[$c['name']] = $cast;
            }
        }
        if ($this->spec->priced()) {
            $casts += ['subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2']; // P115: the server's figures
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

    /** A workflow status's label, by its code. */
    private function statusLabel(string $code): string
    {
        return (string) array_column($this->spec->workflow()['statuses'], 'label', 'code')[$code];
    }

    private function belongsTo(string $name, string $class, string $column): string
    {
        $short = class_basename($class);

        return self::I4."public function {$name}(): BelongsTo\n".self::I4."{\n".self::I8."return \$this->belongsTo({$short}::class, '{$column}');\n".self::I4."}\n";
    }

    /** @return array{0: string, 1: list<string>} the definition body and the models it builds */
    private function factory(): array
    {
        $out = '';
        $imports = [];
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
                'json' => "['example' => true]", // never []: `required` refuses an empty array
                // a nullable foreign key and the workflow status are left out (HasWorkflowStatus gives the first)
                'foreign' => $sample['params']['nullable'] ? null : class_basename($this->modules->modelClass($sample['params']['module'])).'::factory()',
                default => null,
            };
            if ($value !== null) {
                $out .= self::I12."'{$c['name']}' => {$value},\n";
            }
            if ($sample['kind'] === 'foreign' && ! $sample['params']['nullable'] && $sample['params']['module'] !== $this->spec->key()) {
                // the parent's model lives in its own module's namespace
                $imports[] = $this->modules->modelClass($sample['params']['module']);
            }
        }

        return [$out, $imports];
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
                'media' => "\$table->unsignedBigInteger('{$n}_media_id')",
                default => "\$table->string('{$n}')",
            };
            $kind = ColumnTypes::kind($c);
            $index = in_array($kind, ['foreign', 'workflow', 'media'], true) ? '->index()' : '';
            $comment = match ($kind) {
                'workflow' => ' // P47: the '.$this->spec->workflow()['key'].' workflow',
                'media' => ' // P131: media.id (sent and read as the media uuid)',
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
        foreach ($this->spec->uniqueTogether() as $set) {
            $cols = ($this->spec->isOrganisationScoped() ? "'organization_id', " : '')."'".implode("', '", $set)."'";
            $out .= self::I12."\$table->unique([{$cols}]); // no two records share these\n";
        }

        if (($n = $this->spec->number()) !== null) {
            $out .= self::I12."\$table->string('{$n['column']}', 30)->unique(); // P115: made on create ({$n['prefix']}-2026-0001)\n";
        }
        if ($this->spec->priced()) {
            $out .= self::I12."\$table->decimal('subtotal', 14, 2)->default(0); // P115: the server's figures, from the lines\n"
                .self::I12."\$table->decimal('tax', 14, 2)->default(0);\n"
                .self::I12."\$table->decimal('total', 14, 2)->default(0);\n";
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: list<string>} */
    private function list(): array
    {
        $imports = [];
        $array = fn (array $items) => '['.implode(', ', array_map(fn ($i) => "'{$i}'", $items)).']';
        $filterable = ListSpec::filterable($this->spec);
        $sortable = ListSpec::sortable($this->spec);
        $searchable = ListSpec::searchable($this->spec);
        if (($n = $this->spec->number()) !== null) {
            // P115: a document is found by its number
            array_splice($filterable, (int) array_search('created_at', $filterable, true), 0, [$n['column']]);
            array_splice($sortable, (int) array_search('id', $sortable, true), 0, [$n['column']]);
            $searchable[] = $n['column'];
        }
        if ($this->spec->priced()) {
            array_splice($sortable, (int) array_search('id', $sortable, true), 0, ['total']);
        }
        $slots = [
            'filterable' => self::I4.'protected static array $filterableFields = '.$array($filterable).";\n",
            'sortable' => self::I4.'protected static array $sortableFields = '.$array($sortable).";\n",
            'searchable' => self::I4.'protected static array $searchableFields = '.$array($searchable).";\n",
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
        return '['.implode(', ', array_map(fn ($v) => "['value' => '{$v}', 'label' => '".Str::headline(strtolower((string) $v))."']", $values)).']';
    }

    /** @return array{0: string, 1: list<string>} */
    private function rules(string $op): array
    {
        $imports = [];
        $out = '';
        foreach (ValidationRules::for($this->spec, $op, $this->modules) as $entry) {
            $out .= self::I12."'{$entry['field']}' => [".$this->ruleList($entry['rules'], $imports).'],'.($entry['comment'] ? " // {$entry['comment']}" : '')."\n";
        }

        return [$out, $imports];
    }

    /** P115: the rules of the document's lines and the total the person saw (an edit may leave the lines out). */
    private function lineRules(string $op): string
    {
        if (($l = $this->spec->lines()) === null) {
            return '';
        }
        $item = $l['item_module'];
        $exists = ($this->modules->isOrganisationScoped($item) ? 'exists_in_org' : 'exists').":{$this->modules->table($item)},id";
        $fields = ['item_id' => "['required', 'integer', '{$exists}']", 'qty' => "['required', 'numeric', 'gt:0']"];
        if ($this->spec->priced()) {
            $fields += ['price' => "['required', 'numeric', 'min:0']", 'disc' => "['nullable', 'numeric', 'min:0', 'max:100']", 'vat' => "['nullable', 'numeric', 'min:0', 'max:100']"];
        }
        $record = strtolower($this->spec->recordLabel());
        $out = self::I12."...\$this->lineRules([ // P115: the {$record}'s lines\n";
        foreach ($fields as $f => $rule) {
            $out .= self::I12."    '{$f}' => {$rule},\n";
        }
        $out .= self::I12.']'.($op === 'edit' ? ', false' : '')."),\n";

        return $this->spec->priced() ? $out.self::I12."'total' => ['nullable', 'numeric'], // the total the person saw: a cent off is a 409\n" : $out;
    }

    /** One field's rules as PHP, adding the classes they use to $imports. */
    private function ruleList(array $rules, array &$imports): string
    {
        $parts = [];
        foreach ($rules as $rule) {
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
                    case 'unique_together':
                        $parts[] = "new UniqueTogether('{$rule['table']}', ['".implode("', '", $rule['columns'])."'], \$data".($rule['ignoreSelf'] ? ', $id' : '').')';
                        $imports[] = 'App\\Platform\\Rules\\UniqueTogether';
                        break;
                    case 'in':
                        $parts[] = 'Rule::in('.var_export($rule['values'], true).')';
                        $imports[] = self::RULE;
                        break;
                }
        }

        return implode(', ', $parts);
    }

    /**
     * What every create in the module's tests sends beyond its name: a sample for each column, and for a required foreign
     * key a record made in the test's organisation (a member for Users). A unique column gets a value of its own each
     * time, so two creates never collide.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function testFields(): array
    {
        $out = '';
        $imports = [];
        foreach ($this->spec->columns() as $c) {
            $kind = ColumnTypes::kind($c);
            if ($c['name'] === $this->spec->labelColumn() || in_array($kind, ['workflow', 'media'], true)) {
                continue; // a media column is always nullable (P131): the test makes the record without a file
            }
            if ($kind === 'foreign') {
                if (! empty($c['nullable'])) {
                    continue;
                }
                $module = $c['relatedModule'];
                if ($module === 'Users') {
                    $value = "\$this->makeMember(\$org, 'viewer')->id";
                } else {
                    $class = $this->modules->modelClass($module);
                    $imports[] = $class;
                    $attrs = match ($this->modules->scope($module)) {
                        'location' => "['organization_id' => \$org->id, 'location_id' => \$org->id]",
                        'organisation' => "['organization_id' => \$org->id]",
                        default => '',
                    };
                    $value = class_basename($class)."::factory()->create({$attrs})->id";
                }
                $out .= self::I12."'{$c['name']}' => {$value},\n";

                continue;
            }
            if ($kind === 'string' && ! empty($c['unique'])) {
                $imports[] = 'Illuminate\Support\Str';
                $out .= self::I12."'{$c['name']}' => Str::upper(Str::random(".min(8, ColumnTypes::length($c)).')),'."\n";

                continue;
            }
            $value = match ($kind) {
                'text' => "'Hello'",
                'date' => "'2030-01-15'",
                'datetime' => "'2030-01-15 09:30:00'",
                'integer' => '1',
                'decimal' => "'10.50'",
                'boolean' => 'true',
                'enum' => var_export(($c['values'] ?? [''])[0], true),
                'json' => "['example' => true]", // never []: `required` refuses an empty array
                // within the column's length (a 3-letter code gets 'Exa')
                default => "'".substr('Example', 0, min(7, ColumnTypes::length($c)))."'",
            };
            $out .= self::I12."'{$c['name']}' => {$value},\n";
        }

        if (($l = $this->spec->lines()) !== null) {
            // P115: a document is made with its lines: one, of an item made in the test's organisation
            $class = $this->modules->modelClass($l['item_module']);
            $imports[] = $class;
            $attrs = match ($this->modules->scope($l['item_module'])) {
                'location' => "['organization_id' => \$org->id, 'location_id' => \$org->id]",
                'organisation' => "['organization_id' => \$org->id]",
                default => '',
            };
            $price = $this->spec->priced() ? ", 'price' => '10.50'" : '';
            $out .= self::I12."'lines' => [['id' => 'l1', 'item_id' => ".class_basename($class)."::factory()->create({$attrs})->id, 'qty' => 2{$price}]],\n";
        }

        return [$out, $imports];
    }

    private function ns(): string
    {
        return "App\\Modules\\Custom\\{$this->spec->group()}\\{$this->spec->key()}";
    }

    /**
     * P84: each action's service, written once (the developer's file afterwards, P123). It does what the entry says:
     * moves the record to its workflow `status` (refused when it is there already) and saves its `fields`; otherwise
     * handle() is left for the developer.
     *
     * @return array<string, string>
     */
    private function actionServices(): array
    {
        $k = $this->spec->key();
        $record = strtolower($this->spec->recordLabel());
        $out = [];
        foreach ($this->spec->actions() as $key => $a) {
            $class = $k.Str::studly($key).'Service';
            $imports = ['App\Platform\Actions\ModuleAction', 'Illuminate\Database\Eloquent\Model'];
            $rules = '';
            if ($a['fields'] !== []) {
                $lines = '';
                foreach (ValidationRules::for($this->spec, 'create', $this->modules) as $entry) {
                    if (in_array($entry['field'], $a['fields'], true)) {
                        // an action asks for what it needs: its fields are required
                        $list = array_map(fn ($r) => $r === 'nullable' ? 'required' : $r, $entry['rules']);
                        $lines .= self::I12."'{$entry['field']}' => [".$this->ruleList($list, $imports)."],\n";
                    }
                }
                $rules = self::I4."public function rules(): array\n".self::I4."{\n".self::I8."return [\n{$lines}".self::I8."];\n".self::I4."}\n\n";
            }
            if ($a['status'] !== null) {
                $wf = $this->spec->workflow();
                $statusLabel = array_column($wf['statuses'], 'label', 'code')[$a['status']];
                $imports[] = self::WORKFLOW_STATUSES;
                $imports[] = 'App\Platform\Support\ApplicationException';
                $set = "\$record::statusColumn() => app(WorkflowStatuses::class)->id('{$wf['key']}', '{$a['status']}')";
                $body = self::I8."if (\$record->statusCode() === '{$a['status']}') {\n".self::I12."throw new ApplicationException('This {$record} is already {$statusLabel}.');\n".self::I8."}\n"
                    .self::I8.'$record->update('.($a['fields'] !== [] ? "[...\$data, {$set}]" : "[{$set}]").");\n";
                $what = "the {$record} moves to {$statusLabel}".($a['fields'] !== [] ? ', with its fields saved' : '');
            } elseif ($a['fields'] !== []) {
                $body = self::I8."\$record->update(\$data);\n";
                $what = "its fields are saved on the {$record}";
            } else {
                $body = self::I8."// What {$a['label']} does to the {$record}: change \$record, or throw an ApplicationException with the reason it cannot.\n";
                $what = 'write what it does in handle()';
            }
            $uses = implode('', array_map(fn ($i) => "use {$i};\n", array_unique($imports)));
            $out["Services/{$class}.php"] = "<?php\n\nnamespace {$this->ns()}\\Services;\n\n{$uses}\n"
                ."/** P84: {$a['label']} (module.json \"actions.{$key}\"): {$what}. Written once by the generator: this file is yours. */\n"
                ."class {$class} extends ModuleAction\n{\n{$rules}".self::I4."public function handle(Model \$record, array \$data): void\n".self::I4."{\n{$body}".self::I4."}\n}\n";
        }

        return $out;
    }

    /**
     * P84: the actions' contract, rewritten every run: each needs its permission and a record in reach, its fields are
     * validated, and an approval action waits for approval while its workflow applies. What an action does is the
     * developer's to test.
     *
     * @return array<string, string>
     */
    private function actionsContractTest(): array
    {
        $actions = $this->spec->actions();
        if ($actions === []) {
            return [];
        }
        $k = $this->spec->key();
        $route = $this->spec->route();
        $lc = $this->spec->labelColumn();
        $record = strtolower($this->spec->recordLabel());
        $at = $this->spec->scope() === 'location' ? ", 'location_id' => \$org->id" : '';
        [$fields, $imports] = $this->testFields();
        $imports = [...$imports, 'Tests\Support\WorksInOrganizations', 'Tests\TestCase'];
        $keys = implode(', ', array_map(fn ($key) => "'".Str::kebab($key)."'", array_keys($actions)));
        $methods = '';

        $stranger = $this->spec->scope() === 'global' ? '' : self::I8."\$other = \$this->makeOrganization('Pwani Agro');\n".self::I8."\$stranger = \$this->as(\$this->makeMember(\$other, 'owner'), \$other);\n";
        $methods .= self::I4."public function test_every_action_needs_its_permission".($stranger ? '_and_a_record_in_reach' : '')."(): void\n".self::I4."{\n"
            .self::I8."\$org = \$this->makeOrganization('Kilima Retail');\n"
            .self::I8."\$uuid = \$this->record(\$org, \$this->as(\$this->makeMember(\$org, 'owner'), \$org));\n"
            .self::I8."\$viewer = \$this->as(\$this->makeMember(\$org, 'viewer', [\$this->makeCustomRole(\$org, 'Readers', ['{$k}.list', '{$k}.view'])]), \$org);\n"
            .$stranger
            .self::I8."foreach ([{$keys}] as \$action) {\n"
            .self::I12."\$this->postJson(\"/api/{$route}/{\$uuid}/{\$action}\", [], \$viewer)->assertForbidden();\n"
            .($stranger ? self::I12."\$this->postJson(\"/api/{$route}/{\$uuid}/{\$action}\", [], \$stranger)->assertNotFound();\n" : '')
            .self::I8."}\n".self::I4."}\n";

        $withFields = array_filter($actions, fn ($a) => $a['fields'] !== [] && ! $a['approval']);
        if ($withFields !== []) {
            $list = implode(', ', array_map(fn ($key, $a) => "'".Str::kebab($key)."' => ['".implode("', '", $a['fields'])."']", array_keys($withFields), $withFields));
            $methods .= "\n".self::I4."public function test_an_actions_fields_are_validated(): void\n".self::I4."{\n"
                .self::I8."\$org = \$this->makeOrganization('Kilima Retail');\n"
                .self::I8."\$as = \$this->as(\$this->makeMember(\$org, 'owner'), \$org);\n"
                .self::I8."\$uuid = \$this->record(\$org, \$as);\n"
                .self::I8."foreach ([{$list}] as \$action => \$fields) {\n"
                .self::I12."\$this->postJson(\"/api/{$route}/{\$uuid}/{\$action}\", [], \$as)->assertStatus(422)->assertJsonValidationErrors(\$fields);\n"
                .self::I8."}\n".self::I4."}\n";
        }

        $gated = array_filter($actions, fn ($a) => $a['approval']);
        if ($gated !== []) {
            $imports[] = 'App\Modules\Core\Approvals\RequestTypes\Services\RequestTypesCatalogue';
            $imports[] = 'App\Modules\Kernel\Tenancy\Organizations\CurrentOrganization';
            $list = implode(', ', array_map(fn ($key) => "'".Str::kebab($key)."'", array_keys($gated)));
            $methods .= "\n".self::I4."public function test_an_approval_action_waits_for_approval_while_its_workflow_applies(): void\n".self::I4."{\n"
                .self::I8."\$org = \$this->makeOrganization('Kilima Retail');\n"
                .self::I8."\$uuid = \$this->record(\$org, \$this->as(\$this->makeMember(\$org, 'owner'), \$org));\n"
                .self::I8."CurrentOrganization::instance()->run(\$org, fn () => RequestTypesCatalogue::ensure()); // P107: a workflow per approval action\n"
                .self::I8."\$admin = \$this->as(\$this->makeMember(\$org, 'admin'), \$org);\n"
                .self::I8."foreach ([{$list}] as \$action) {\n"
                .self::I12."\$this->postJson(\"/api/{$route}/{\$uuid}/{\$action}\", [], \$admin)->assertStatus(409)->assertJsonPath('data.approval', true);\n"
                .self::I8."}\n".self::I4."}\n";
        }

        $uses = implode('', array_map(fn ($i) => "use {$i};\n", array_unique($imports)));

        return ["Tests/{$k}ActionsContractTest.php" => "<?php\n\nnamespace {$this->ns()}\\Tests;\n\n{$uses}\n"
            ."/** P84: the {$record} actions' contract (permission, reach, fields, approval). Generated: rewritten every run. */\n"
            ."class {$k}ActionsContractTest extends TestCase\n{\n".self::I4."use WorksInOrganizations;\n\n"
            .self::I4."private function as(\$user, \$org): array\n".self::I4."{\n".self::I8."\$this->app['auth']->forgetGuards();\n".self::I8."\$this->app->forgetScopedInstances();\n\n".self::I8."return \$this->asMember(\$user, \$org);\n".self::I4."}\n\n"
            .self::I4."private function fields(\$org): array\n".self::I4."{\n".self::I8."return [\n{$fields}".self::I8."];\n".self::I4."}\n\n"
            .self::I4."private function record(\$org, array \$as): string\n".self::I4."{\n"
            .self::I8."return \$this->postJson('/api/{$route}/create', ['{$lc}' => 'Contract {$record}'{$at}] + \$this->fields(\$org), \$as)->assertCreated()->json('data.uuid');\n"
            .self::I4."}\n\n{$methods}}\n"];
    }

    /**
     * P115: the document's lines table (migrated after the document's) and its line model, rewritten every run. The
     * lines have no routes of their own: they are read and saved with the document.
     *
     * @return array<string, string>
     */
    private function lineFiles(): array
    {
        if (($l = $this->spec->lines()) === null) {
            return [];
        }
        $k = $this->spec->key();
        $table = $this->spec->linesTable();
        $key = $this->spec->linesKey();
        $itemClass = $this->modules->modelClass($l['item_module']);
        $itemTable = $this->modules->table($l['item_module']);
        $record = strtolower($this->spec->recordLabel());
        $records = Str::plural($record);
        $priceColumns = $this->spec->priced()
            ? self::I12."\$table->decimal('price', 14, 2)->nullable();\n".self::I12."\$table->decimal('disc', 5, 2)->default(0);\n".self::I12."\$table->decimal('vat', 5, 2)->nullable();\n"
            : '';
        $casts = $this->spec->priced() ? "['qty' => 'decimal:3', 'price' => 'decimal:2', 'disc' => 'decimal:2', 'vat' => 'decimal:2']" : "['qty' => 'decimal:3']";

        return [
            "Migrations/DATE_235959_create_{$table}_table.php" => <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P115: the {$records}' lines, after the {$records} table. Generated: rewritten every run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            \$table->uuid()->unique();
            \$table->foreignId('{$key}')->index();
            \$table->unsignedInteger('position')->default(0);
            \$table->unsignedBigInteger('item_id')->index(); // {$itemTable}
            \$table->decimal('qty', 12, 3);
{$priceColumns}            \$table->timestamps();
            \$table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP,
            "{$k}LineModel.php" => "<?php\n\nnamespace {$this->ns()};\n\nuse {$itemClass};\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;\nuse Illuminate\\Support\\Str;\n\n"
                ."/** P115: one line of a {$record}. It has no routes of its own: it is read with its {$record} and saved with it ({$k}Model::LINES). Generated: rewritten every run. */\n"
                ."class {$k}LineModel extends Model\n{\n".self::I4."use SoftDeletes;\n\n".self::I4."protected \$table = '{$table}';\n\n"
                .self::I4."/** Only the columns {$k}Model::LINES names reach it (BaseService::saveLines). */\n".self::I4."protected \$guarded = [];\n\n"
                .self::I4."protected \$casts = {$casts};\n\n"
                .self::I4."protected static function booted(): void\n".self::I4."{\n".self::I8."static::creating(fn (self \$line) => \$line->uuid ??= (string) Str::uuid());\n".self::I4."}\n\n"
                .self::I4."public function item(): BelongsTo\n".self::I4."{\n".self::I8."return \$this->belongsTo(".class_basename($itemClass)."::class, 'item_id');\n".self::I4."}\n}\n",
        ];
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
