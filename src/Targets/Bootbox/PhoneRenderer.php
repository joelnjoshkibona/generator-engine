<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Blutrixx\GeneratorEngine\Core\ValidationRules;
use Illuminate\Support\Str;

/**
 * The phone's body of a Bootbox module (D7, P123, P124): the slots of the phone scaffolder's skeleton
 * (mobile/resources/spa: make:module --generated). The list (label, a second line, a status pill, the status quick tabs)
 * and the form sheet with every core field from the phone's own pickers (OptionPick, DateField, RecordPick, Toggle),
 * never a native one; the phone store's rules (P104); the labels.
 */
final class PhoneRenderer
{
    private const I2 = '  ';

    private const I4 = '    ';

    private const I6 = '      ';

    private const I8 = '        ';

    private const I12 = '            ';

    private string $ns;

    private string $kebab;

    private string $s;

    public function __construct(private readonly ModuleSpec $spec, private readonly ModuleResolver $modules)
    {
        $this->kebab = $spec->route();
        $this->ns = Str::camel(str_replace('-', '_', $this->kebab));
        $this->s = Str::studly($this->kebab);
    }

    /** @return array{slots: array<string, array<string, string>>, imports: array<string, array<string, list<string>>>, rules: string, locales: array{en: array<string, string>, sw: array<string, string>, sw_fallback: array<string, string>}} */
    public function render(): array
    {
        $s = $this->s;
        $slots = $imports = [];
        $slots['screens/types.ts'] = $this->types();
        [$slots["screens/{$s}Page.vue"], $imports["screens/{$s}Page.vue"]] = $this->page();
        [$slots["screens/{$s}Sheet.vue"], $imports["screens/{$s}Sheet.vue"]] = $this->sheet();
        $slots['module.ts'] = ['menu' => "const icon = '".($this->spec->icon() ?? 'Circle')."'\n"];

        return ['slots' => $slots, 'imports' => $imports, 'rules' => $this->rules(), 'locales' => $this->locales()];
    }

    /** The phone's flat label keys: `due_date` → `due`, `assignee_id` → `assignee`, the workflow's column → `status`. */
    private function short(array $c): string
    {
        if (ColumnTypes::kind($c) === 'workflow') {
            return 'status';
        }
        if ($c['name'] === $this->spec->labelColumn()) {
            return $c['name'];
        }

        return Str::camel(preg_replace('/_(id|date|at)$/', '', $c['name']));
    }

    /** The columns of the form, the location last for a location-scoped module (P12). */
    private function fields(): array
    {
        $cols = $this->spec->columns();
        if ($this->spec->scope() === 'location') {
            $cols[] = ['name' => 'location_id', 'type' => 'foreignId', 'relatedModule' => 'Locations', 'nullable' => false, '_location' => true];
        }

        return $cols;
    }

    private function tsType(array $c): string
    {
        $base = match (ColumnTypes::kind($c)) {
            'integer', 'decimal', 'foreign', 'workflow' => 'number',
            'boolean' => 'boolean',
            'enum' => implode(' | ', array_map(fn ($v) => "'{$v}'", $c['values'] ?? [])) ?: 'string',
            default => 'string',
        };

        return ! empty($c['nullable']) ? "{$base} | null" : $base;
    }

    /** @return array<string, string> */
    private function types(): array
    {
        $row = '';
        foreach ($this->fields() as $c) {
            $row .= self::I2."{$c['name']}: ".$this->tsType($c)."\n";
        }
        $links = [];
        foreach ($this->fields() as $c) {
            if (ColumnTypes::kind($c) === 'foreign') {
                $rel = ColumnTypes::relationName($c['name']);
                $row .= self::I2."{$rel}?: Link | null\n";
                $links[] = $this->modules->labelColumn($c['relatedModule']);
            }
        }
        $types = '';
        if ($links !== []) {
            $fields = implode('', array_map(fn ($l) => "  {$l}: string\n", array_values(array_unique(['name', ...$links]))));
            $types .= "/** A related record as the API sends it: its label. */\nexport interface Link {\n  id: number\n  uuid: string\n{$fields}}\n";
        }
        if (($wf = $this->spec->workflow()) !== null) {
            $types .= ($types === '' ? '' : "\n")."/** P47: the {$wf['key']} workflow's statuses as the module declares them, for phone mode (P124). */\n"
                .'export const STATUSES: import(\'@/app/lib/workflowStatuses\').DeclaredStatus[] = ['."\n";
            foreach ($wf['statuses'] as $st) {
                $sw = isset($st['label_sw']) ? "'".addslashes($st['label_sw'])."'" : 'null';
                $types .= self::I2."{ code: '{$st['code']}', label: '".addslashes($st['label'])."', label_sw: {$sw}, tone: '".($st['tone'] ?? 'muted')."', sort_order: ".((int) ($st['sort_order'] ?? 0)).', is_terminal: '.(! empty($st['is_terminal']) ? 'true' : 'false')." },\n";
            }
            $types .= "]\n";
        }

        return ['row' => $row, 'types' => $types];
    }

    private function wfColumn(): ?array
    {
        foreach ($this->spec->columns() as $c) {
            if (ColumnTypes::kind($c) === 'workflow') {
                return $c;
            }
        }

        return null;
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function page(): array
    {
        $ns = $this->ns;
        $lc = $this->spec->labelColumn();
        $imports = [];
        $setup = '';
        $wf = $this->wfColumn();
        $dates = array_values(array_filter($this->spec->columns(), fn ($c) => in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)));
        if ($wf !== null || $dates !== []) {
            $setup .= "const { locale } = useI18n()\n";
        }
        if ($wf !== null) {
            $imports['@/app/lib/workflowStatuses'] = ['TONES', 'useWorkflow'];
            $imports['./types'] = ['STATUSES'];
            $setup .= "const statuses = useWorkflow('{$this->spec->workflow()['key']}', STATUSES)\n";
        }
        if ($dates !== []) {
            $setup .= "const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'sw' ? 'sw-TZ' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '')\n";
        }
        $tabs = '';
        if ($wf !== null) {
            $imports['vue'] = ['computed'];
            $imports['@/components/ui/Seg.vue'] = ['default:Seg'];
            $setup .= "/* P24: the status quick tabs, All first; a tab filters the list by its status. */\n"
                ."const tab = ref('all')\n"
                ."const tabs = computed(() => [{ id: 'all', label: t('{$ns}.tabs.all') }, ...statuses.rows.value.map((s) => ({ id: String(s.id), label: statuses.label(s, locale.value) }))])\n"
                ."function pickTab(id: string) {\n  tab.value = id\n  if (id === 'all') delete L.state.filters.{$wf['name']}\n  else L.state.filters.{$wf['name']} = { operator: 'in', value: [Number(id)] }\n}\n";
            $tabs = self::I8."<div v-if=\"tabs.length > 1\" class=\"mb-3 overflow-x-auto\"><Seg :model-value=\"tab\" :tabs=\"tabs\" @update:model-value=\"pickTab\" /></div>\n";
        }
        // the second line: its dates, then the records it points at (not its location)
        $parts = array_map(fn ($c) => "day(row.{$c['name']})", $dates);
        foreach ($this->spec->foreignKeys() as $c) {
            $parts[] = 'row.'.ColumnTypes::relationName($c['name']).'?.'.$this->modules->labelColumn($c['relatedModule']);
        }
        $second = $parts === [] ? '' : "<span class=\"block truncate text-xs text-muted-foreground\">{{ [".implode(', ', $parts)."].filter(Boolean).join(' · ') }}</span>";
        $cells = self::I12.'  '."<span class=\"min-w-0 flex-1\"><span class=\"block truncate text-sm font-medium\">{{ row.{$lc} }}</span>{$second}</span>\n";
        if ($wf !== null) {
            $n = $wf['name'];
            $cells .= self::I12.'  '."<span v-if=\"statuses.byId(row.{$n})\" :class=\"['shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-medium', TONES[statuses.byId(row.{$n})!.tone].cls]\">{{ statuses.label(statuses.byId(row.{$n})!, locale) }}</span>\n";
        }

        return [['setup' => $setup, 'sort' => "const SORT = { sort: 'updated_at', order: 'desc' as const }\n", 'tabs' => $tabs, 'cells' => $cells], $imports];
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function sheet(): array
    {
        $ns = $this->ns;
        $k = $this->kebab;
        $imports = [];
        $setup = $state = $reset = $get = $apply = $input = $fields = '';
        $valid = [];
        if (($wf = $this->spec->workflow()) !== null) {
            $imports['@/app/lib/workflowStatuses'] = ['useWorkflow'];
            $imports['./types'] = ['STATUSES'];
            $setup = "const { locale } = useI18n()\nconst statuses = useWorkflow('{$wf['key']}', STATUSES)\n"
                ."const statusOptions = computed(() => statuses.rows.value.map((s) => ({ value: s.id, label: statuses.label(s, locale.value) })))\n";
        }
        foreach ($this->fields() as $c) {
            $n = $c['name'];
            $kind = ColumnTypes::kind($c);
            $nullable = ! empty($c['nullable']);
            $label = "t('{$ns}.{$this->short($c)}')";
            $err = self::I8."<ErrorLine :show=\"!!errors.{$n}\">{{ errors.{$n} }}</ErrorLine>\n";
            $open = self::I6."<div>\n".self::I8."<Label>{{ {$label} }}</Label>\n";
            $get .= self::I4."{$n}: {$n}.value,\n";
            $apply .= self::I4."{$n}.value = p.{$n}\n";
            switch ($kind) {
                case 'workflow':
                    $state .= "const {$n} = ref<number | null>(null)\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? null\n";
                    $input .= self::I8."...({$n}.value !== null ? { {$n}: {$n}.value } : {}),\n";
                    $fields .= $open.self::I8."<OptionPick :model-value=\"{$n}\" :options=\"statusOptions\" :label=\"{$label}\" :placeholder=\"t('{$ns}.{$this->short($c)}First')\" testid=\"{$k}-{$n}\" @update:model-value=\"(v) => ({$n} = v === null ? null : Number(v))\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/OptionPick.vue'] = ['default:OptionPick'];
                    break;
                case 'enum':
                    $state .= "const {$n} = ref<string | null>(null)\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? null\n";
                    $input .= self::I8."{$n}: {$n}.value,\n";
                    $options = '['.implode(', ', array_map(fn ($v) => "{ value: '{$v}', label: t('{$ns}.values.{$this->short($c)}.{$v}') }", array_values($c['values'] ?? []))).']';
                    $fields .= $open.self::I8."<OptionPick :model-value=\"{$n}\" :options=\"{$options}\" :label=\"{$label}\"".($nullable ? ' nullable' : '')." testid=\"{$k}-{$n}\" @update:model-value=\"(v) => ({$n} = v === null ? null : String(v))\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/OptionPick.vue'] = ['default:OptionPick'];
                    if (! $nullable) {
                        $valid[] = "{$n}.value !== null";
                    }
                    break;
                case 'date':
                case 'datetime':
                    $state .= "const {$n} = ref<string | null>(null)\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? null\n";
                    $input .= self::I8."{$n}: {$n}.value,\n";
                    $fields .= $open.self::I8."<DateField v-model=\"{$n}\" :label=\"{$label}\"".($nullable ? ' nullable' : '')." testid=\"{$k}-{$n}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/DateField.vue'] = ['default:DateField'];
                    if (! $nullable) {
                        $valid[] = "{$n}.value !== null";
                    }
                    break;
                case 'foreign':
                    $module = $c['relatedModule'];
                    $rel = ColumnTypes::relationName($n);
                    $lcol = $this->modules->labelColumn($module);
                    $state .= "const {$n} = ref<number | null>(null)\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? null\n";
                    $input .= self::I8."{$n}: {$n}.value,\n";
                    $pick = self::I8."<RecordPick v-model=\"{$n}\" module=\"{$module}\" :label=\"{$label}\"".($lcol !== 'name' ? " label-column=\"{$lcol}\"" : '')." :display=\"row?.{$rel}?.{$lcol}\"".($nullable ? ' nullable' : '')." testid=\"{$k}-{$n}\" />\n";
                    $imports['@/components/ui/RecordPick.vue'] = ['default:RecordPick'];
                    if (! empty($c['_location'])) {
                        // a phone with no server has no locations (P124): the record is the phone's
                        $imports['@/app/data'] = ['DATA_MODE'];
                        $fields .= self::I6."<!-- a phone with no server has no locations (P124): the record is the phone's -->\n"
                            .self::I6."<div v-if=\"DATA_MODE !== 'phone'\">\n".self::I8."<Label>{{ {$label} }}</Label>\n".$pick.$err.self::I6."</div>\n";
                        $valid[] = "(DATA_MODE === 'phone' || {$n}.value !== null)";
                        break;
                    }
                    $fields .= $open.$pick.$err.self::I6."</div>\n";
                    if (! $nullable) {
                        $valid[] = "{$n}.value !== null";
                    }
                    break;
                case 'boolean':
                    $state .= "const {$n} = ref(false)\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? false\n";
                    $input .= self::I8."{$n}: {$n}.value,\n";
                    $fields .= self::I6."<div class=\"flex items-center justify-between gap-3\">\n".self::I8."<span class=\"text-sm font-medium\">{{ {$label} }}</span>\n"
                        .self::I8."<Toggle v-model=\"{$n}\" :label=\"{$label}\" data-testid=\"{$k}-{$n}\" />\n".self::I6."</div>\n";
                    $imports['@/components/ui/Toggle.vue'] = ['default:Toggle'];
                    break;
                case 'integer':
                case 'decimal':
                    $state .= "const {$n} = ref('')\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} != null ? String(props.row.{$n}) : ''\n";
                    $input .= self::I8."{$n}: ".($nullable ? "{$n}.value === '' ? null : Number({$n}.value)" : "Number({$n}.value)").",\n";
                    $fields .= $open.self::I8."<input v-model=\"{$n}\" type=\"text\" inputmode=\"decimal\" :class=\"INPUT\" :aria-label=\"{$label}\" data-testid=\"{$k}-{$n}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/app/lib/systemInput'][] = 'INPUT';
                    if (! $nullable) {
                        $valid[] = "{$n}.value !== ''";
                    }
                    break;
                case 'text':
                    $state .= "const {$n} = ref('')\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? ''\n";
                    $input .= self::I8."{$n}: {$n}.value.trim()".($nullable ? ' || null' : '').",\n";
                    $fields .= $open.self::I8."<textarea v-model=\"{$n}\" :class=\"TEXTAREA\" :aria-label=\"{$label}\" data-testid=\"{$k}-{$n}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/app/lib/systemInput'][] = 'TEXTAREA';
                    if (! $nullable) {
                        $valid[] = "{$n}.value.trim().length > 0";
                    }
                    break;
                default:
                    $state .= "const {$n} = ref('')\n";
                    $reset .= self::I2."{$n}.value = props.row?.{$n} ?? ''\n";
                    $input .= self::I8."{$n}: {$n}.value.trim()".($nullable ? ' || null' : '').",\n";
                    $fields .= $open.self::I8."<input v-model=\"{$n}\" type=\"text\" :class=\"INPUT\" :aria-label=\"{$label}\" data-testid=\"{$k}-{$n}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/app/lib/systemInput'][] = 'INPUT';
                    if (! $nullable) {
                        $valid[] = "{$n}.value.trim().length > 0";
                    }
            }
        }

        if (isset($imports['@/app/lib/systemInput'])) {
            $imports['@/app/lib/systemInput'] = array_values(array_intersect(['INPUT', 'TEXTAREA'], $imports['@/app/lib/systemInput']));
        }

        return [[
            'setup' => $setup, 'state' => $state, 'reset' => $reset, 'draft-get' => $get, 'draft-apply' => $apply,
            'valid' => self::I2.'return '.($valid === [] ? 'true' : implode(' && ', $valid))."\n", 'input' => $input, 'fields' => $fields,
        ], $imports];
    }

    /** The phone store's rules (P104): the API's, without the checks against tables the phone does not have. */
    private function rules(): string
    {
        $out = '';
        foreach (ValidationRules::for($this->spec, 'create', $this->modules) as $entry) {
            $parts = [];
            foreach ($entry['rules'] as $r) {
                if (is_string($r)) {
                    $parts[] = $r === 'required' ? "\$op === 'create' ? 'required' : 'sometimes'" : "'{$r}'";
                } elseif ($r['rule'] === 'in') {
                    $parts[] = "'in:".implode(',', $r['values'])."'";
                } // exists / unique: the server's to check (the phone has no such tables)
            }
            $out .= self::I12."'{$entry['field']}' => [".implode(', ', $parts)."],\n";
        }
        if ($this->spec->scope() === 'location') {
            $out .= self::I12."'location_id' => ['nullable', 'integer'], // a phone with no server has no locations; the server requires it on sync\n";
        }

        return $out;
    }

    /** @return array{en: array<string, string>, sw: array<string, string>, sw_fallback: array<string, string>} */
    private function locales(): array
    {
        $en = $sw = $fallback = [];
        $sw_or = function (string $key, ?string $given, string $english) use (&$sw, &$fallback) {
            $given !== null ? $sw[$key] = $given : $fallback[$key] = $english;
        };
        foreach ($this->fields() as $c) {
            $short = $this->short($c);
            if (! empty($c['_location'])) {
                [$en['location'], $sw['location']] = ['Location', 'Mahali'];

                continue;
            }
            $label = $c['form_label'] ?? ColumnTypes::label($c);
            $en[$short] = $label;
            $sw_or($short, $c['label_sw'] ?? null, $label);
            foreach (ColumnTypes::kind($c) === 'enum' ? ($c['values'] ?? []) : [] as $v) {
                $en["values.{$short}.{$v}"] = Str::headline(strtolower((string) $v));
                $sw_or("values.{$short}.{$v}", $c['values_sw'][$v] ?? null, Str::headline(strtolower((string) $v)));
            }
            if (ColumnTypes::kind($c) === 'workflow') {
                [$en["{$short}First"], $sw["{$short}First"]] = ['The first status', 'Hali ya kwanza'];
            }
        }
        if ($this->wfColumn() !== null) {
            [$en['tabs.all'], $sw['tabs.all']] = ['All', 'Zote'];
        }
        if (isset($this->spec->raw()['labels_sw'])) {
            $sw['title'] = $this->spec->raw()['labels_sw'];
        }

        return ['en' => $en, 'sw' => $sw, 'sw_fallback' => $fallback];
    }
}
