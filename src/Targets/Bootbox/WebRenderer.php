<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ModuleResolver;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Illuminate\Support\Str;

/**
 * The web side of a Bootbox module (P123): each slot's body and import lines, and the locale keys, for the files the
 * web scaffolder writes under src/modules/<kebab>/.
 */
final class WebRenderer
{
    private const I2 = '  ';

    private const I4 = '    ';

    private const I6 = '      ';

    private const I8 = '        ';

    private string $ns;

    private string $s;

    private string $kebab;

    public function __construct(private readonly ModuleSpec $spec, private readonly ModuleResolver $modules)
    {
        $this->kebab = $spec->route();
        $this->ns = Str::camel(str_replace('-', '_', $this->kebab));
        $this->s = Str::studly($this->kebab);
    }

    public function kebab(): string
    {
        return $this->kebab;
    }

    /** @return array{slots: array<string, array<string, string>>, imports: array<string, array<string, list<string>>>, locales: array{en: array<string, string>, sw: array<string, string>}} */
    public function render(): array
    {
        $s = $this->s;
        $slots = $imports = [];
        [$slots['api.ts'], $imports['api.ts']] = $this->api();
        [$slots["pages/{$s}Page.vue"], $imports["pages/{$s}Page.vue"]] = $this->page();
        [$slots["pages/{$s}FormDialog.vue"], $imports["pages/{$s}FormDialog.vue"]] = $this->form();
        [$slots["pages/{$s}DetailPage.vue"], $imports["pages/{$s}DetailPage.vue"]] = $this->detail();

        return ['slots' => $slots, 'imports' => $imports, 'locales' => $this->locales()];
    }

    /** `due_date` → `due`, `assignee_id` → `assignee`, the workflow's column → `status`. */
    private function short(array $c): string
    {
        if (ColumnTypes::kind($c) === 'workflow') {
            return 'status';
        }

        return Str::camel(preg_replace('/_(id|date|at)$/', '', $c['name']));
    }

    private function formLabel(array $c): string
    {
        return $c['form_label'] ?? ColumnTypes::label($c);
    }

    private function hasLinks(): bool
    {
        return $this->spec->foreignKeys() !== [] || $this->spec->scope() === 'location';
    }

    private function tsType(array $c, bool $input): string
    {
        $base = match (ColumnTypes::kind($c)) {
            'integer', 'decimal', 'foreign', 'workflow' => 'number',
            'boolean' => 'boolean',
            'json' => 'unknown',
            'enum' => implode(' | ', array_map(fn ($v) => "'{$v}'", $c['values'] ?? [])) ?: 'string',
            default => 'string',
        };

        return ! empty($c['nullable']) ? "{$base} | null" : $base;
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function api(): array
    {
        $row = $input = '';
        foreach ($this->spec->columns() as $c) {
            $row .= self::I2."{$c['name']}: ".$this->tsType($c, false)."\n";
            $optional = ! empty($c['nullable']) || ColumnTypes::kind($c) === 'workflow';
            $input .= self::I2.$c['name'].($optional ? '?' : '').': '.(ColumnTypes::kind($c) === 'workflow' ? 'number' : $this->tsType($c, true))."\n";
        }
        foreach ($this->spec->foreignKeys() as $c) {
            $row .= self::I2.ColumnTypes::relationName($c['name'])."?: Link | null\n";
        }
        if ($this->spec->scope() === 'location') {
            $row .= self::I2."location?: Link | null\n";
        }
        $types = '';
        if ($this->hasLinks()) {
            $types = "/** A related record as the API sends it: RelatedLink shows it (P85). */\nexport interface Link {\n  id: number\n  uuid: string\n  name: string\n}\n";
        }
        $api = '';
        if (($wf = $this->wfColumn()) !== null) {
            $api = self::I2."/** Rows per status for the quick tabs (P24): the list's own scope, grouped server side. */\n"
                .self::I2."counts: async () => (await api<{ data?: { data?: { value: number | string; count: number }[] } }>('/list-counts/{$this->kebab}', { query: { field: '{$wf['name']}' } })).data?.data ?? [],\n";
        }

        return [['imports' => '', 'row' => $row, 'types' => $types, 'input' => $input, 'api' => $api], []];
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
        $imports = [];
        $sorts = '';
        foreach ($this->spec->columns() as $c) {
            if (in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)) {
                $sorts .= self::I4."{ value: '{$c['name']}', label: t('{$ns}.sorts.{$this->short($c)}') },\n";
            }
        }
        if ($this->spec->column($this->spec->labelColumn())) {
            $l = $this->spec->labelColumn();
            $sorts .= self::I4."{ value: '{$l}', label: t('{$ns}.sorts.{$l}') },\n";
        }

        $tabs = "const quickFilter = (): string => ''\nconst quickTabs = (): QuickTab[] => []\n";
        $wf = $this->wfColumn();
        if ($wf !== null) {
            $key = $this->spec->workflow()['key'];
            $imports['@/app/lib/workflowStatuses'] = ['useWorkflow'];
            $imports['vue'] = ['ref', 'watch'];
            $tabs = "const statuses = useWorkflow('{$key}')\n"
                ."const counts = ref<Record<string, number>>({})\n"
                ."watch(LS.rows, () => void {$ns}Api.counts().then((c) => (counts.value = Object.fromEntries(c.map((x) => [String(x.value), Number(x.count)])))), { immediate: true })\n"
                ."const quickFilter = (): string => '{$wf['name']}'\n"
                ."const quickTabs = (): QuickTab[] => [\n"
                ."  { value: 'all', label: t('{$ns}.tabs.all'), count: Object.values(counts.value).reduce((a, b) => a + b, 0) },\n"
                ."  ...statuses.rows.value.map((s) => ({ value: String(s.id), label: statuses.label(s, locale.value), count: counts.value[String(s.id)] ?? 0 })),\n"
                ."]\n";
        }

        $cols = $cells = '';
        $label = $this->spec->labelColumn();
        if ($this->spec->column($label)) {
            $cols .= self::I2."{ key: '{$label}', label: t('{$ns}.columns.{$label}'), identity: true, priority: 1, min: 220, sort: '{$label}', text: (r) => r.{$label} },\n";
        }
        if ($wf !== null) {
            $cols .= self::I2."{ key: 'status', label: t('{$ns}.columns.status'), status: true, priority: 2, min: 120, text: (r) => { const s = statuses.byId(r.{$wf['name']}); return s ? statuses.label(s, locale.value) : '' } },\n";
            $cells .= self::I8."<template #cell-status=\"{ row }\"><StatusPill workflow=\"{$this->spec->workflow()['key']}\" :code=\"statuses.byId(row.{$wf['name']})?.code\" /></template>\n";
            $imports['@/components/app/status-pill'] = ['StatusPill'];
        }
        foreach ($this->spec->columns() as $c) {
            if (in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)) {
                $cols .= self::I2."{ key: '{$this->short($c)}', label: t('{$ns}.columns.{$this->short($c)}'), priority: 2, min: 110, fold: 'meta', sort: '{$c['name']}', text: (r) => day(r.{$c['name']}) },\n";
            }
        }
        foreach ($this->spec->columns() as $c) {
            if ($c['name'] === $label) {
                continue;
            }
            $sh = $this->short($c);
            $text = $this->plainText($c, 'r');
            $col = match (ColumnTypes::kind($c)) {
                'string' => "{ key: '{$sh}', label: t('{$ns}.columns.{$sh}'), priority: 3, min: 120, fold: 'meta', text: (r) => {$text} },",
                'integer', 'decimal' => "{ key: '{$sh}', label: t('{$ns}.columns.{$sh}'), priority: 3, min: 100, fold: 'meta', right: true, text: (r) => {$text} },",
                'enum' => "{ key: '{$sh}', label: t('{$ns}.columns.{$sh}'), priority: 3, min: 110, fold: 'badge', text: (r) => {$text} },",
                'boolean' => "{ key: '{$sh}', label: t('{$ns}.columns.{$sh}'), priority: 4, min: 90, fold: 'meta', text: (r) => {$text} },",
                default => null,
            };
            if ($col !== null) {
                $cols .= self::I2.$col."\n";
            }
        }
        $links = [];
        foreach ($this->spec->foreignKeys() as $c) {
            $links[] = [ColumnTypes::relationName($c['name']), $c['relatedModule'], 3];
        }
        if ($this->spec->scope() === 'location') {
            $links[] = ['location', 'Locations', 4];
        }
        foreach ($links as [$rel, $module, $priority]) {
            $col = $this->modules->labelColumn($module);
            $cols .= self::I2."{ key: '{$rel}', label: t('{$ns}.columns.{$rel}'), priority: {$priority}, min: 150, fold: 'meta', text: (r) => r.{$rel}?.{$col} ?? '' },\n";
            $cells .= self::I8."<template #cell-{$rel}=\"{ row }\"><RelatedLink v-if=\"row.{$rel}\" module=\"{$module}\" :value=\"{ id: row.{$rel}.id, uuid: row.{$rel}.uuid, label: row.{$rel}.{$col} }\" /></template>\n";
            $imports['@/components/app/related-link'] = ['RelatedLink'];
        }
        $cols .= self::I2."{ key: 'created', label: t('{$ns}.columns.created'), priority: 5, min: 120, fold: 'meta', sort: 'created_at', text: (r) => day(r.created_at) },\n";

        return [['sorts' => $sorts, 'tabs' => $tabs, 'columns' => $cols, 'cells' => $cells, 'row-actions' => ''], $imports];
    }

    /** A plain column's value as text, for a list cell or an Overview line. */
    private function plainText(array $c, string $r): string
    {
        $n = $c['name'];

        return match (ColumnTypes::kind($c)) {
            'boolean' => "({$r}.{$n} ? t('listToolbar.yes') : t('listToolbar.no'))",
            'integer', 'decimal' => "({$r}.{$n} == null ? '' : String({$r}.{$n}))",
            'enum' => "({$r}.{$n} ? t(`{$this->ns}.values.{$this->short($c)}.\${{$r}.{$n}}`) : '')",
            default => "({$r}.{$n} ?? '')",
        };
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function form(): array
    {
        $ns = $this->ns;
        $k = $this->kebab;
        $imports = [];
        $setup = $state = $body = $fields = '';
        $valid = [];
        $wf = $this->wfColumn();
        if ($wf !== null) {
            $key = $this->spec->workflow()['key'];
            $imports['@/app/lib/workflowStatuses'] = ['useWorkflow'];
            $setup = "const { locale } = useI18n()\nconst statuses = useWorkflow('{$key}')\n"
                .'/* P47: the options are the organisation\'s statuses in their order; a new '.strtolower($this->spec->recordLabel())." starts in the first when none is picked. */\n"
                ."const statusOptions = computed(() => statuses.rows.value.map((s) => ({ value: s.id, label: statuses.label(s, locale.value) })))\n";
        }
        foreach ($this->spec->columns() as $c) {
            $n = $c['name'];
            $kind = ColumnTypes::kind($c);
            $nullable = ! empty($c['nullable']);
            $short = $this->short($c);
            $err = self::I8."<p v-if=\"errors.{$n}\" class=\"text-xs text-destructive\">{{ errors.{$n}[0] }}</p>\n";
            switch ($kind) {
                case 'workflow':
                    $state .= self::I2."{$n}: props.row?.{$n} as number | undefined,\n";
                    $body .= self::I4."...(form.{$n} !== undefined ? { {$n}: Number(form.{$n}) } : {}),\n";
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<span class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</span>\n"
                        .self::I8."<Select v-model=\"form.{$n}\" :aria-label=\"t('{$ns}.form.{$short}')\" :options=\"statusOptions\" :placeholder=\"t('{$ns}.form.{$short}First')\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/select'] = ['Select'];
                    break;
                case 'foreign':
                    $state .= self::I2."{$n}: (props.row?.{$n} ?? null) as number | null,\n";
                    $body .= self::I4."{$n}: form.{$n} !== null ? Number(form.{$n}) : null,\n";
                    $module = strtolower($c['relatedModule']);
                    $fields .= self::I6."<ApiSelect v-model=\"form.{$n}\" module=\"{$module}\"".($nullable ? '' : ' required')." :label=\"t('{$ns}.form.{$short}')\" :error=\"errors.{$n}?.[0]\" />\n";
                    $imports['@/components/app/api-select'] = ['ApiSelect'];
                    if (! $nullable) {
                        $valid[] = "form.{$n} !== null";
                    }
                    break;
                case 'date':
                case 'datetime':
                    $state .= self::I2."{$n}: props.row?.{$n} ?? '',\n";
                    $body .= self::I4."{$n}: form.{$n} || null,\n";
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<DatePicker v-model=\"form.{$n}\" :label=\"t('{$ns}.form.{$short}')\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/app/date-picker'] = ['DatePicker'];
                    if (! $nullable) {
                        $valid[] = "form.{$n} !== ''";
                    }
                    break;
                case 'boolean':
                    $state .= self::I2."{$n}: props.row?.{$n} ?? false,\n";
                    $body .= self::I4."{$n}: form.{$n},\n";
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label class=\"flex items-center justify-between gap-3 text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}<Switch v-model=\"form.{$n}\" /></label>\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/switch'] = ['Switch'];
                    break;
                case 'integer':
                case 'decimal':
                    $state .= self::I2."{$n}: props.row?.{$n} != null ? String(props.row.{$n}) : '',\n";
                    $body .= self::I4.($nullable ? "{$n}: form.{$n} === '' ? null : Number(form.{$n}),\n" : "{$n}: Number(form.{$n}),\n");
                    $imports['@/components/ui/input'] = ['Input'];
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                        .self::I8."<Input id=\"{$k}-{$n}\" v-model=\"form.{$n}\" type=\"number\" inputmode=\"decimal\" :aria-invalid=\"!!errors.{$n}\" />\n".$err.self::I6."</div>\n";
                    if (! $nullable) {
                        $valid[] = "form.{$n} !== ''";
                    }
                    break;
                case 'enum':
                    $values = array_values($c['values'] ?? []);
                    $state .= self::I2."{$n}: props.row?.{$n} ?? '',\n";
                    $body .= self::I4."{$n}: ".($nullable ? "(form.{$n} || null) as {$this->s}Row['{$n}']" : "form.{$n} as {$this->s}Row['{$n}']").",\n";
                    $options = '['.implode(', ', array_map(fn ($v) => "{ value: '{$v}', label: t('{$ns}.values.{$short}.{$v}') }", $values)).']';
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<span class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</span>\n"
                        .self::I8."<Select v-model=\"form.{$n}\" :aria-label=\"t('{$ns}.form.{$short}')\" :options=\"{$options}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/select'] = ['Select'];
                    if (! $nullable) {
                        $valid[] = "form.{$n} !== ''";
                    }
                    break;
                case 'text':
                    $state .= self::I2."{$n}: props.row?.{$n} ?? '',\n";
                    $body .= self::I4."{$n}: form.{$n}.trim()".($nullable ? ' || null' : '').",\n";
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                        .self::I8."<Textarea id=\"{$k}-{$n}\" v-model=\"form.{$n}\" :rows=\"3\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/textarea'] = ['Textarea'];
                    if (! $nullable) {
                        $valid[] = "form.{$n}.trim().length > 0";
                    }
                    break;
                default:
                    $state .= self::I2."{$n}: props.row?.{$n} ?? '',\n";
                    $body .= self::I4."{$n}: form.{$n}.trim()".($nullable ? ' || null' : '').",\n";
                    $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                        .self::I8."<Input id=\"{$k}-{$n}\" v-model=\"form.{$n}\" :aria-invalid=\"!!errors.{$n}\" />\n".$err.self::I6."</div>\n";
                    $imports['@/components/ui/input'] = ['Input'];
                    if (! $nullable) {
                        $valid[] = "form.{$n}.trim().length > 0";
                    }
            }
        }
        $validBody = self::I2.'return '.($valid === [] ? 'true' : implode(' && ', $valid))."\n";

        return [['setup' => $setup, 'state' => $state, 'valid' => $validBody, 'body' => $body, 'fields' => $fields], $imports];
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function detail(): array
    {
        $ns = $this->ns;
        $imports = [];
        $setup = '';
        $icon = $this->spec->icon() ?? 'Circle';
        $header = "const headerIcon = '{$icon}'\nconst headerStatus = computed<StatusPill | null>(() => null)\n";
        if (($wf = $this->wfColumn()) !== null) {
            $key = $this->spec->workflow()['key'];
            $imports['@/app/lib/workflowStatuses'] = ['TONES', 'useWorkflow'];
            $setup = "const statuses = useWorkflow('{$key}')\n";
            $header = "const headerIcon = '{$icon}'\nconst headerStatus = computed<StatusPill | null>(() => {\n"
                ."  const s = rec.value ? statuses.byId(rec.value.{$wf['name']}) : undefined\n"
                ."  return s ? { label: statuses.label(s, locale.value), cls: TONES[s.tone].cls, dot: TONES[s.tone].dot } : null\n})\n";
        }
        $facts = '';
        foreach ($this->spec->columns() as $c) {
            if (in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)) {
                $facts .= self::I4."{ icon: 'CalendarClock', label: t('{$ns}.columns.{$this->short($c)}'), value: r.{$c['name']} ? day(r.{$c['name']}) : '—' },\n";
            }
        }
        $links = [];
        foreach ($this->spec->foreignKeys() as $c) {
            $links[] = [ColumnTypes::relationName($c['name']), $c['relatedModule'], $c['relatedModule'] === 'Users' ? 'UserRound' : 'Link'];
        }
        if ($this->spec->scope() === 'location') {
            $links[] = ['location', 'Locations', 'MapPin'];
        }
        foreach ($links as [$rel, $module, $ic]) {
            $col = $this->modules->labelColumn($module);
            $facts .= self::I4."{ icon: '{$ic}', label: t('{$ns}.columns.{$rel}'), value: r.{$rel}?.{$col} ?? '—', link: r.{$rel} ? { module: '{$module}', value: { id: r.{$rel}.id, uuid: r.{$rel}.uuid, label: r.{$rel}.{$col} } } : null },\n";
        }
        foreach ($this->spec->columns() as $c) {
            $icon = match (ColumnTypes::kind($c)) {
                'string' => 'Type',
                'integer', 'decimal' => 'Hash',
                'enum' => 'Tag',
                'boolean' => 'ToggleLeft',
                default => null,
            };
            if ($icon !== null && $c['name'] !== $this->spec->labelColumn()) {
                $facts .= self::I4."{ icon: '{$icon}', label: t('{$ns}.columns.{$this->short($c)}'), value: ".$this->plainText($c, 'r')." || '—' },\n";
            }
        }
        foreach ($this->spec->columns() as $c) {
            if (ColumnTypes::kind($c) === 'text') {
                $facts .= self::I4."{ icon: 'AlignLeft', label: t('{$ns}.columns.{$this->short($c)}'), value: r.{$c['name']} || '—' },\n";
            }
        }

        return [['setup' => $setup, 'header' => $header, 'overview' => $facts], $imports];
    }

    /**
     * English keys; Swahili keys from the spec (label_sw); Swahili fallbacks (the English) written only where the key is
     * missing, so a translation already there is never replaced by English.
     *
     * @return array{en: array<string, string>, sw: array<string, string>, sw_fallback: array<string, string>}
     */
    private function locales(): array
    {
        $en = $sw = $fallback = [];
        $sw_or = function (string $key, ?string $given, string $english) use (&$sw, &$fallback) {
            $given !== null ? $sw[$key] = $given : $fallback[$key] = $english;
        };
        foreach ($this->spec->columns() as $c) {
            $short = $this->short($c);
            $en["columns.{$short}"] = ColumnTypes::label($c);
            $sw_or("columns.{$short}", $c['label_sw'] ?? null, ColumnTypes::label($c));
            $en["form.{$short}"] = $this->formLabel($c);
            $sw_or("form.{$short}", $c['label_sw'] ?? null, $this->formLabel($c));
            if (in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)) {
                $en["sorts.{$short}"] = $this->formLabel($c);
                $sw_or("sorts.{$short}", $c['label_sw'] ?? null, $this->formLabel($c));
            }
            foreach (ColumnTypes::kind($c) === 'enum' ? ($c['values'] ?? []) : [] as $v) {
                $en["values.{$short}.{$v}"] = Str::headline((string) $v);
                $sw_or("values.{$short}.{$v}", $c['values_sw'][$v] ?? null, Str::headline((string) $v));
            }
            if (ColumnTypes::kind($c) === 'workflow') {
                $en["form.{$short}First"] = 'The first status';
                $sw["form.{$short}First"] = 'Hali ya kwanza';
            }
        }
        foreach ($this->spec->foreignKeys() as $c) {
            $rel = ColumnTypes::relationName($c['name']);
            $en["columns.{$rel}"] = ColumnTypes::label($c);
            $sw_or("columns.{$rel}", $c['label_sw'] ?? null, ColumnTypes::label($c));
        }
        if ($this->spec->scope() === 'location') {
            [$en['columns.location'], $sw['columns.location']] = ['Location', 'Mahali'];
        }
        if ($this->wfColumn() !== null) {
            [$en['tabs.all'], $sw['tabs.all']] = ['All', 'Zote'];
        }
        if (isset($this->spec->raw()['labels_sw'])) {
            $plural = $this->spec->raw()['labels_sw'];
            $sw['title'] = $plural;
            $sw['subtitle'] = $this->spec->scope() === 'global' ? "{$plural} zinazoshirikiwa na mashirika yote." : "{$plural} za shirika hili.";
        }

        return ['en' => $en, 'sw' => $sw, 'sw_fallback' => $fallback];
    }
}
