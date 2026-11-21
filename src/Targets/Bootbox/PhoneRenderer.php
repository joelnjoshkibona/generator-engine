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
        [$slots["screens/{$s}DetailPage.vue"], $imports["screens/{$s}DetailPage.vue"]] = $this->detail();
        $slots['module.ts'] = ['menu' => "const icon = '".($this->spec->icon() ?? 'Circle')."'\n", 'actions' => $this->actions(), 'tab-on' => $this->tabOn()];

        return ['slots' => $slots, 'imports' => $imports, 'rules' => $this->rules(), 'saving' => $this->saving(), 'locales' => $this->locales()];
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
            'integer', 'decimal', 'workflow' => 'number',
            'foreign' => 'number | string', // a link: an id, or the uuid of a record made on the phone (P130)
            'boolean' => 'boolean',
            'enum' => implode(' | ', array_map(fn ($v) => "'{$v}'", $c['values'] ?? [])) ?: 'string',
            'json' => 'unknown',
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
                $rel = ColumnTypes::relationKey($c['name']); // the key the API sends it under
                $row .= self::I2."{$rel}?: Link | null\n";
                $links[] = $this->modules->labelColumn($c['relatedModule']);
            }
        }
        if ($this->spec->lines() !== null) {
            $row .= self::I2."lines?: import('@/components/app/documents/lines').SavedLine[]\n";
            if ($this->spec->priced()) {
                $row .= self::I2."subtotal?: number | string | null\n".self::I2."tax?: number | string | null\n".self::I2."total?: number | string | null\n";
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
            $parts[] = 'row.'.ColumnTypes::relationKey($c['name']).'?.'.$this->modules->labelColumn($c['relatedModule']);
        }
        $second = $parts === [] ? '' : "<span class=\"block truncate text-xs text-muted-foreground\">{{ [".implode(', ', $parts)."].filter(Boolean).join(' · ') }}</span>";
        $cells = self::I12.'  '."<span class=\"min-w-0 flex-1\"><span class=\"block truncate text-sm font-medium\">{{ row.{$lc} }}</span>{$second}</span>\n";
        if ($wf !== null) {
            $n = $wf['name'];
            $cells .= self::I12.'  '."<span v-if=\"statuses.byId(row.{$n})\" :class=\"['shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-medium', TONES[statuses.byId(row.{$n})!.tone].cls]\">{{ statuses.label(statuses.byId(row.{$n})!, locale) }}</span>\n";
        }

        return [['setup' => $setup, 'sort' => "const SORT = { sort: 'updated_at', order: 'desc' as const }\n", 'tabs' => $tabs, 'cells' => $cells], $imports];
    }

    /**
     * P129: the record page: its status pill, its facts (each column the form has, the location last where there is a
     * server), and the workflow the actions need to show a status at once.
     *
     * @return array{0: array<string, string>, 1: array<string, list<string>>}
     */
    private function detail(): array
    {
        $ns = $this->ns;
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
        $header = '';
        if ($wf !== null) {
            $setup .= "const status = computed(() => statuses.byId(rec.value?.{$wf['name']}))\n"
                ."const workflow = { statuses, statusColumn: '{$wf['name']}' }\n";
            $header = self::I6."<span v-if=\"status\" :class=\"['rounded-md px-1.5 py-0.5 text-[11px] font-medium', TONES[status.tone].cls]\" data-testid=\"record-status\">{{ statuses.label(status, locale) }}</span>\n";
        } else {
            $setup .= "const workflow = {}\n";
        }
        $facts = '';
        foreach ($this->spec->columns() as $c) {
            $kind = ColumnTypes::kind($c);
            if ($c['name'] === $this->spec->labelColumn() || in_array($kind, ['workflow', 'json'], true)) {
                continue;
            }
            $n = $c['name'];
            $v = match ($kind) {
                'date', 'datetime' => "day(rec?.{$n} ?? null) || '–'",
                'foreign' => 'rec?.'.ColumnTypes::relationKey($n).'?.'.$this->modules->labelColumn($c['relatedModule'])." || '–'",
                'boolean' => "rec?.{$n} ? '✓' : '–'",
                'integer', 'decimal' => "rec?.{$n} ?? '–'",
                default => "rec?.{$n} || '–'",
            };
            $facts .= self::I6."<Row :k=\"t('{$ns}.{$this->short($c)}')\" :v=\"{$v}\" />\n";
        }
        if ($this->spec->scope() === 'location') {
            $imports['@/app/data'] = ['DATA_MODE'];
            $facts .= self::I6."<Row v-if=\"DATA_MODE !== 'phone'\" :k=\"t('{$ns}.location')\" :v=\"rec?.location?.".$this->modules->labelColumn('Locations')." || '–'\" />\n";
        }

        $lines = '';
        if (($l = $this->spec->lines()) !== null) {
            $imports['@/components/app/documents/LinesCard.vue'] = ['default:LinesCard'];
            $lcol = $this->modules->labelColumn($l['item_module']);
            $lines = self::I6.'<LinesCard :lines="rec?.lines ?? []"'.($this->spec->priced() ? ' :totals="rec"' : ' :priced="false"').($l['prices_include_vat'] ? ' prices-include-vat' : '').($lcol !== 'name' ? " item-label=\"{$lcol}\"" : '')." />\n";
        }

        return [['setup' => $setup, 'header' => $header, 'facts' => $facts, 'lines' => $lines], $imports];
    }

    /** P86, P130: this module as a tab on each parent's record page (module.ts `tabOn`). */
    private function tabOn(): string
    {
        $tabs = $this->spec->tabOn();
        if ($tabs === []) {
            return self::I2."tabOn: {},\n";
        }
        $out = self::I2."tabOn: {\n";
        foreach ($tabs as $parent => $t) {
            $parts = ["key: '{$t['key']}'", "via: '{$t['via']}'", "tab: '".str_replace("'", "\\'", $t['tab'])."'"];
            if (! empty($t['tab_sw'])) {
                $parts[] = "tab_sw: '".str_replace("'", "\\'", $t['tab_sw'])."'";
            }
            if ($this->spec->labelColumn() !== 'name') {
                $parts[] = "labelColumn: '{$this->spec->labelColumn()}'";
            }
            $parts[] = "path: '/{$this->kebab}'";
            $parts[] = "sheet: () => import('./screens/{$this->s}Sheet.vue')";
            $out .= self::I4."{$parent}: { ".implode(', ', $parts)." },\n";
        }

        return $out.self::I2."},\n";
    }

    /** P129: the module's declared actions as the phone draws them (module.ts), the same keys as module.json. */
    private function actions(): string
    {
        $actions = $this->spec->actions();
        if ($actions === []) {
            return self::I2."actions: {},\n";
        }
        $q = fn (string $v) => "'".str_replace("'", "\\'", $v)."'";
        $out = self::I2."actions: {\n";
        foreach ($actions as $key => $a) {
            $parts = ['label: '.$q($a['label'])];
            if ($a['label_sw'] !== null) {
                $parts[] = 'label_sw: '.$q($a['label_sw']);
            }
            if ($a['icon'] !== null) {
                $parts[] = 'icon: '.$q($a['icon']);
            }
            $parts[] = 'button: '.$q($a['button']);
            foreach (['confirm', 'danger', 'approval'] as $flag) {
                if ($a[$flag]) {
                    $parts[] = "{$flag}: true";
                }
            }
            if ($a['status'] !== null) {
                $parts[] = 'status: '.$q($a['status']);
            }
            if ($a['fields'] !== []) {
                $parts[] = 'fields: ['.implode(', ', array_map(fn (string $f) => $this->actionField($f, $q), $a['fields'])).']';
            }
            $out .= self::I4."{$key}: { ".implode(', ', $parts)." },\n";
        }

        return $out.self::I2."},\n";
    }

    /** One field an action asks for, by its column: its kind (P124's fields), its words, and what it picks from. */
    private function actionField(string $name, \Closure $q): string
    {
        $c = $this->spec->column($name) ?? ['name' => $name, 'type' => 'string'];
        $kind = match (ColumnTypes::kind($c)) {
            'foreign' => 'record',
            'date', 'datetime' => 'date',
            'enum', 'boolean' => 'option',
            'integer', 'decimal' => 'number',
            'text' => 'textarea',
            default => 'text',
        };
        $parts = ['key: '.$q($name), 'kind: '.$q($kind), 'label: '.$q(ColumnTypes::label($c))];
        if (isset($c['label_sw'])) {
            $parts[] = 'label_sw: '.$q((string) $c['label_sw']);
        }
        if ($kind === 'record') {
            $parts[] = 'module: '.$q((string) $c['relatedModule']);
        }
        if (ColumnTypes::kind($c) === 'enum') {
            $parts[] = 'options: ['.implode(', ', array_map(fn ($v) => '{ value: '.$q((string) $v).', label: '.$q(Str::headline(strtolower((string) $v))).' }', (array) ($c['values'] ?? []))).']';
        } elseif (ColumnTypes::kind($c) === 'boolean') {
            $parts[] = "options: [{ value: 1, label: 'Yes', label_sw: 'Ndiyo' }, { value: 0, label: 'No', label_sw: 'Hapana' }]";
        }

        return '{ '.implode(', ', $parts).' }';
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
        $vias = array_column($this->spec->tabOn(), 'via');
        foreach ($this->fields() as $c) {
            if (ColumnTypes::kind($c) === 'json') {
                continue; // P125: never a form field, on the phone either
            }
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
                    $rel = ColumnTypes::relationKey($n);
                    $lcol = $this->modules->labelColumn($module);
                    $state .= "const {$n} = ref<number | string | null>(null)\n";
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
                    if (in_array($n, $vias, true)) {
                        // P86, P130: made from its parent's tab, the link is the parent's, set and locked
                        $fields .= self::I6."<div v-if=\"!parent\">\n".self::I8."<Label>{{ {$label} }}</Label>\n".$pick.$err.self::I6."</div>\n";
                        if (! $nullable) {
                            $valid[] = "(!!props.parent || {$n}.value !== null)";
                        }
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

        if (($l = $this->spec->lines()) !== null) {
            // P115, P130: the document's lines, from the item module's catalogue; saved with it, the server's totals win
            $imports['@/components/app/documents/LinesField.vue'] = ['default:LinesField'];
            $imports['@/components/app/documents/lines'] = ['type SavedLine'];
            $state .= "const lines = ref<SavedLine[]>([])\n";
            $reset .= self::I2."lines.value = [...(props.row?.lines ?? [])]\n";
            $get .= self::I4."lines: lines.value,\n";
            $apply .= self::I4."lines.value = p.lines\n";
            $input .= self::I8."lines: lines.value.map(({ item: _item, ...line }) => line),\n";
            $valid[] = 'lines.value.length > 0';
            $item = $l['item_module'];
            $attrs = "item-module=\"{$item}\"".($l['item_price'] ? " item-price=\"{$l['item_price']}\"" : '');
            $lcol = $this->modules->labelColumn($item);
            $attrs .= $lcol !== 'name' ? " item-label=\"{$lcol}\"" : '';
            $attrs .= $this->spec->priced() ? '' : ' :priced="false"';
            $attrs .= $l['prices_include_vat'] ? ' prices-include-vat' : '';
            $locked = '';
            if ($l['editable_status'] !== null && ($wf = $this->wfColumn()) !== null) {
                $locked = " :disabled=\"!!props.row && statuses.byId(props.row.{$wf['name']})?.code !== '{$l['editable_status']}'\"";
            }
            $fields .= self::I6."<LinesField v-model=\"lines\" {$attrs} :label=\"t('{$ns}.lines')\"{$locked} />\n";
        }

        if (isset($imports['@/app/lib/systemInput'])) {
            $imports['@/app/lib/systemInput'] = array_values(array_intersect(['INPUT', 'TEXTAREA'], $imports['@/app/lib/systemInput']));
        }

        return [[
            'setup' => $setup, 'state' => $state, 'reset' => $reset, 'draft-get' => $get, 'draft-apply' => $apply,
            'valid' => self::I2.'return '.($valid === [] ? 'true' : implode(' && ', $valid))."\n", 'input' => $input, 'fields' => $fields,
        ], $imports];
    }

    /** The phone store's save hook (P104): a document's totals worked out from its lines on the phone (P130). */
    private function saving(): string
    {
        if (! $this->spec->priced()) {
            return self::I8."return \$record;\n";
        }

        return self::I8.'return \\App\\Local\\LineTotals::apply($record, '.($this->spec->lines()['prices_include_vat'] ? 'true' : 'false').");\n";
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
            $col = $this->spec->column($entry['field']);
            if ($col !== null && ColumnTypes::kind($col) === 'foreign') {
                // a link: an id, or the uuid of a record made on the phone (P130); the server checks it on sync
                $parts = array_values(array_filter($parts, fn ($p) => $p !== "'integer'"));
            }
            $out .= self::I12."'{$entry['field']}' => [".implode(', ', $parts)."],\n";
        }
        if ($this->spec->lines() !== null) {
            $out .= self::I12."'lines' => [\$op === 'create' ? 'required' : 'sometimes', 'array'],\n";
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
        if (($l = $this->spec->lines()) !== null) {
            $en['lines'] = 'Lines';
            $sw_or('lines', $l['label_sw'], 'Lines');
        }

        return ['en' => $en, 'sw' => $sw, 'sw_fallback' => $fallback];
    }
}
