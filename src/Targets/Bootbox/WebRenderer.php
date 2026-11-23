<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ListSpec;
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
        $slots['module.ts'] = $this->tabsOnParents() + ['actions' => $this->actions(), 'tabs' => $this->spec->lines() !== null ? self::I2."{ key: 'lines', label: '{$this->ns}.tabs.lines' },\n" : ''];

        return ['slots' => $slots, 'imports' => $imports, 'locales' => $this->locales(), 'once' => $this->actionDialogs()];
    }

    /** `due_date` → `due`, `assignee_id` → `assignee`, the workflow's column → `status`. */
    private function short(array $c): string
    {
        if (ColumnTypes::kind($c) === 'workflow') {
            return 'status';
        }
        if ($c['name'] === $this->spec->labelColumn()) {
            return $c['name']; // the scaffolder's own key for it
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
            'media' => 'string', // P131: the media's uuid
            'enum' => implode(' | ', array_map(fn ($v) => "'{$v}'", $c['values'] ?? [])) ?: 'string',
            default => 'string',
        };

        return ! empty($c['nullable']) ? "{$base} | null" : $base;
    }

    /** P84: the record's actions in module.ts (the API module.json carries the same keys). */
    private function actions(): string
    {
        if ($this->spec->actions() === []) {
            return '';
        }
        $out = self::I2."actions: [\n";
        foreach ($this->spec->actions() as $key => $a) {
            $parts = ["key: '{$key}'", "label: '{$this->ns}.actions.{$key}.label'"];
            if ($a['icon'] !== null) {
                $parts[] = "icon: '{$a['icon']}'";
            }
            $parts[] = "button: '{$a['button']}'";
            foreach (['danger', 'confirm', 'bulk', 'approval'] as $flag) {
                if ($a[$flag]) {
                    $parts[] = "{$flag}: true";
                }
            }
            if ($a['from'] !== null) {
                // P84 (2026-11-22): offered only while the record's status is one of these
                $parts[] = "from: { workflow: '".$this->spec->workflow()['key']."', column: '".$this->spec->workflowColumn()."', codes: ['".implode("', '", $a['from'])."'] }";
            }
            if ($a['fields'] !== []) {
                $parts[] = "component: () => import('@/modules/{$this->kebab}/pages/{$this->s}".Str::studly($key)."Dialog.vue')";
            }
            $out .= self::I4.'{ '.implode(', ', $parts)." },\n";
        }

        return $out.self::I2."],\n";
    }

    /**
     * P84: the dialog of each action with fields, written once (the developer's file afterwards, P123). It asks for the
     * fields, as the record's form does, and hands them to `submit`, which sends the action (through the approval gate).
     *
     * @return array<string, string>
     */
    private function actionDialogs(): array
    {
        $out = [];
        $lc = $this->spec->labelColumn();
        $record = strtolower($this->spec->recordLabel());
        foreach ($this->spec->actions() as $key => $a) {
            if ($a['fields'] === []) {
                continue;
            }
            $state = $body = $fields = $setup = '';
            $valid = [];
            $imports = [];
            foreach ($a['fields'] as $name) {
                $f = $this->field($this->spec->column($name), false);
                $state .= $f['state'];
                $body .= preg_replace('/^/m', '  ', $f['body']);
                $fields .= $f['field'];
                $valid = [...$valid, ...$f['valid']];
                $imports = array_merge($imports, $f['imports']);
                if (ColumnTypes::kind($this->spec->column($name)) === 'workflow') {
                    $imports['@/app/lib/workflowStatuses'] = ['useWorkflow'];
                    $setup = "const { locale } = useI18n()\nconst statuses = useWorkflow('{$this->spec->workflow()['key']}')\n"
                        ."const statusOptions = computed(() => statuses.rows.value.map((s) => ({ value: s.id, label: statuses.label(s, locale.value) })))\n";
                }
            }
            $vue = $setup !== '' ? 'computed, reactive, ref' : 'reactive, ref';
            $lines = implode('', array_map(fn ($from, $names) => 'import { '.implode(', ', $names)." } from '{$from}'\n", array_keys($imports), $imports));
            $label = "t('{$this->ns}.actions.{$key}.label')";
            $icon = $a['icon'] ?? 'Check';
            $file = "pages/{$this->s}".Str::studly($key).'Dialog.vue';
            $out[$file] = <<<VUE
<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { AppDialog } from '@/components/ui/dialog'
import { Icon } from '@/components/ui/icon'
import { ApiError } from '@/app/lib/api'
import { {$vue} } from 'vue'
import { useI18n } from 'vue-i18n'
import type { {$this->s}Row } from '@/modules/{$this->kebab}/api'
{$lines}
/* P84: {$a['label']} a {$record} (module.json "actions.{$key}"). Written once by the generator: this file is yours. */
const props = defineProps<{ open?: boolean; row: {$this->s}Row; submit: (data: Record<string, unknown>) => Promise<void> }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
const { t } = useI18n()
{$setup}
const form = reactive({
{$state}})
const errors = ref<Record<string, string[]>>({})
const error = ref('')
const busy = ref(false)
const valid = () => {$this->joinValid($valid)}

async function save() {
  if (busy.value || !valid()) return
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    await props.submit({
{$body}    })
    emit('update:open', false)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && Object.keys(e.errors).length) errors.value = e.errors
    else error.value = e instanceof ApiError ? e.message : t('{$this->ns}.actions.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppDialog :open="open" :title="{$label}" :description="row.{$lc}" @update:open="emit('update:open', \$event)">
    <form class="grid gap-4" @submit.prevent="save">
{$fields}      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    </form>
    <template #footer>
      <Button variant="outline" @click="emit('update:open', false)">{{ t('common.cancel') }}</Button>
      <Button :disabled="busy || !valid()" @click="save"><Icon :name="busy ? 'LoaderCircle' : '{$icon}'" :size="14" :class="busy && 'animate-spin'" />{{ {$label} }}</Button>
    </template>
  </AppDialog>
</template>

VUE;
        }

        return $out;
    }

    /** @param list<string> $checks */
    private function joinValid(array $checks): string
    {
        return $checks === [] ? 'true' : implode(' && ', $checks);
    }

    /** P86: the tabs this module adds to its parents' pages (its own list, given the parent record), and their types. */
    private function tabsOnParents(): array
    {
        if ($this->spec->tabOn() === []) {
            return ['declarations' => '', 'extra-tabs' => ''];
        }
        $declarations = "declare module '@/app/modules' {\n  interface RecordExtraTabMap {\n";
        $tabs = self::I2."extraTabs: [\n";
        foreach ($this->spec->tabOn() as $parent => $t) {
            $declarations .= self::I4."'{$parent}.{$t['key']}': true\n";
            $column = ColumnTypes::relationName($t['via']);
            $tabs .= self::I4."{ on: '{$parent}.{$t['key']}', label: '{$this->ns}.tabOn.{$t['key']}', permission: '{$this->spec->key()}.list', child: { via: '{$t['via']}', column: '{$column}' }, component: () => import('@/modules/{$this->kebab}/pages/{$this->s}Page.vue') },\n";
        }

        return ['declarations' => $declarations.self::I2."}\n}\n", 'extra-tabs' => $tabs.self::I2."],\n"];
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
            $row .= self::I2.ColumnTypes::relationKey($c['name'])."?: Link | null\n";
        }
        if ($this->spec->scope() === 'location') {
            $row .= self::I2."location?: Link | null\n";
        }
        $types = '';
        if ($this->hasLinks()) {
            // the label column of each module it points at (most are named by `name`; a notice by `title`)
            $labels = array_map(fn ($c) => $this->modules->labelColumn($c['relatedModule']), $this->spec->foreignKeys());
            if ($this->spec->scope() === 'location') {
                $labels[] = $this->modules->labelColumn('Locations');
            }
            $labels = array_values(array_unique(['name', ...$labels]));
            // each link carries its own module's label column (the view loads rel:id,uuid,<label>)
            $fields = implode('', array_map(fn ($l) => "  {$l}: string\n", $labels));
            $types = "/** A related record as the API sends it: RelatedLink shows it (P85). */\nexport interface Link {\n  id: number\n  uuid: string\n{$fields}}\n";
        }
        $api = '';
        if (($wf = $this->wfColumn()) !== null) {
            $api = self::I2."/** Rows per status for the quick tabs (P24): the list's own scope, grouped server side. */\n"
                .self::I2."counts: async () => (await api<{ data?: { data?: { value: number | string; count: number }[] } }>('/list-counts/{$this->kebab}', { query: { field: '{$wf['name']}' } })).data?.data ?? [],\n";
        }

        $imports = [];
        if ($this->spec->lines() !== null) {
            $imports['@/components/app/doc-lines'] = ['type ApiLine'];
            $row .= self::I2."/** P115: made by the server; the totals are its figures. */\n";
            if (($n = $this->spec->number()) !== null) {
                $row .= self::I2."{$n['column']}: string\n";
            }
            if ($this->spec->priced()) {
                $row .= self::I2."subtotal: string\n".self::I2."tax: string\n".self::I2."total: string\n";
            }
            $row .= self::I2."/** With the record's view (not in the list). */\n".self::I2."lines?: ApiLine[]\n";
            $input .= self::I2."/** P115: sent with the header; `total` is the one the person saw (a cent off is a 409). */\n".self::I2."lines?: Record<string, unknown>[]\n";
            if ($this->spec->priced()) {
                $input .= self::I2."total?: number\n";
            }
        } elseif (($n = $this->spec->number()) !== null) {
            $row .= self::I2."{$n['column']}: string\n";
        }

        return [['imports' => '', 'row' => $row, 'types' => $types, 'input' => $input, 'api' => $api], $imports];
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
                ."// once per load of the list (it loads on mount); a refused count keeps the last ones\n"
                ."watch(LS.updatedAt, () => void {$ns}Api.counts().then((c) => (counts.value = Object.fromEntries(c.map((x) => [String(x.value), Number(x.count)])))).catch(() => undefined))\n"
                ."const quickFilter = (): string => '{$wf['name']}'\n"
                ."const quickTabs = (): QuickTab[] => [\n"
                ."  { value: 'all', label: t('{$ns}.tabs.all'), count: Object.values(counts.value).reduce((a, b) => a + b, 0) },\n"
                ."  ...statuses.rows.value.map((s) => ({ value: String(s.id), label: statuses.label(s, locale.value), count: counts.value[String(s.id)] ?? 0 })),\n"
                ."]\n";
        }

        $cols = $cells = '';
        // P133 (the design review): without a document total, the first money column named like an amount is the hero
        $hero = null;
        if (! $this->spec->priced()) {
            foreach ($this->spec->columns() as $c) {
                if (ColumnTypes::kind($c) === 'decimal' && preg_match('/(amount|price|total|cost)/', $c['name'])) {
                    $hero = $c['name'];
                    break;
                }
            }
        }
        foreach (ListSpec::columns($this->spec, $this->modules) as $col) {
            $sh = $col['key'];
            $n = $col['column'] ?? '';
            $head = "{ key: '{$sh}', label: t('{$ns}.columns.{$sh}'), ";
            switch ($col['kind']) {
                case 'label':
                    $cols .= self::I2.$head."identity: true, priority: 1, min: 220, sort: '{$n}', text: (r) => r.{$n} },\n";
                    break;
                case 'number':
                    $cols .= self::I2.$head."priority: 2, min: 130, fold: 'meta', sort: '{$n}', text: (r) => r.{$n} },\n";
                    break;
                case 'total':
                    // P133 (the design review): a document's total is the row's hero number
                    $cols .= self::I2.$head."hero: true, priority: 1, min: 110, sort: 'total', text: (r) => money(Number(r.total)) },\n";
                    $imports['@/components/app/doc-lines'] = ['money'];
                    break;
                case 'status':
                    $cols .= self::I2.$head."status: true, priority: 2, min: 120, text: (r) => { const s = statuses.byId(r.{$n}); return s ? statuses.label(s, locale.value) : '' } },\n";
                    $cells .= self::I8."<template #cell-status=\"{ row }\"><StatusPill workflow=\"{$this->spec->workflow()['key']}\" :code=\"statuses.byId(row.{$n})?.code\" /></template>\n";
                    $imports['@/components/app/status-pill'] = ['StatusPill'];
                    break;
                case 'date':
                    $cols .= self::I2.$head."priority: 2, min: 110, fold: 'meta', sort: '{$n}', text: (r) => day(r.{$n}) },\n";
                    break;
                case 'string':
                case 'integer':
                case 'decimal':
                case 'enum':
                case 'boolean':
                    $text = $this->plainText($col['spec'], 'r');
                    if (str_contains($text, 'formatAmount(')) {
                        $imports['@/app/lib/numbers'] = ['formatAmount'];
                    }
                    $cols .= self::I2.$head.match ($col['kind']) {
                        'string' => "priority: 3, min: 120, fold: 'meta', text: (r) => {$text} },",
                        'integer', 'decimal' => $n === $hero ? "hero: true, priority: 1, min: 100, text: (r) => {$text} }," : "priority: 3, min: 100, fold: 'meta', right: true, text: (r) => {$text} },",
                        'enum' => "priority: 3, min: 110, fold: 'badge', text: (r) => {$text} },",
                        'boolean' => "priority: 4, min: 90, fold: 'meta', text: (r) => {$text} },",
                    }."\n";
                    break;
                case 'link':
                    [$key, $lc, $module] = [$col['rowKey'], $col['labelColumn'], $col['module']];
                    $cols .= self::I2.$head."priority: {$col['priority']}, min: 150, fold: 'meta', text: (r) => r.{$key}?.{$lc} ?? '' },\n";
                    $cells .= self::I8."<template #cell-{$sh}=\"{ row }\"><RelatedLink v-if=\"row.{$key}\" module=\"{$module}\" :value=\"{ id: row.{$key}.id, uuid: row.{$key}.uuid, label: row.{$key}.{$lc} }\" /></template>\n";
                    $imports['@/components/app/related-link'] = ['RelatedLink'];
                    break;
                case 'created':
                    $cols .= self::I2.$head."priority: 5, min: 120, fold: 'meta', sort: 'created_at', text: (r) => day(r.created_at) },\n";
                    break;
            }
        }

        if (($pic = Sections::pictureColumn($this->spec)) !== null) {
            // P133: each row's picture (its first image column), or the module's icon in a muted tile
            $cells = self::I8."<template #icon=\"{ row }\"><RowPicture :uuid=\"row.{$pic}\" icon=\"{$this->spec->icon()}\" /></template>\n".$cells;
            $imports['@/components/app/media'] = ['RowPicture'];
        }

        return [['sorts' => $sorts, 'tabs' => $tabs, 'columns' => $cols, 'cells' => $cells, 'row-actions' => ''], $imports];
    }

    /** A plain column's value as text, for a list cell or an Overview line. */
    private function plainText(array $c, string $r): string
    {
        $n = $c['name'];

        return match (ColumnTypes::kind($c)) {
            'boolean' => "({$r}.{$n} ? t('listToolbar.yes') : t('listToolbar.no'))",
            // as people read a number: thousands separated, a decimal column's places kept (Bootbox's formatAmount)
            'integer' => "formatAmount({$r}.{$n})",
            'decimal' => "formatAmount({$r}.{$n}, ".(int) ($c['scale'] ?? 2).')',
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
        // P133 (the design review): a new record starts in the first status (P47), so the status is not in the create form;
        // on edit it offers only the statuses no action leads to (those are reached by their action), and it is left out
        // when that leaves one or none
        $byAction = array_values(array_unique(array_filter(array_map(fn (array $a) => $a['status'] ?? null, $this->spec->actions()))));
        $statusInForm = $wf !== null && count(array_diff(array_column($this->spec->workflow()['statuses'], 'code'), $byAction)) > 1;
        // the lookup is for the status field, or for the lines' lock past their editable status (formLines)
        if ($wf !== null && ($statusInForm || ($this->spec->lines()['editable_status'] ?? null) !== null)) {
            $key = $this->spec->workflow()['key'];
            $imports['@/app/lib/workflowStatuses'] = ['useWorkflow'];
            $setup = ($statusInForm ? "const { locale } = useI18n()\n" : '')."const statuses = useWorkflow('{$key}')\n";
            if ($statusInForm) {
                $rows = $byAction === [] ? 'statuses.rows.value' : "statuses.rows.value.filter((s) => !['".implode("', '", $byAction)."'].includes(s.code))";
                $setup .= '/* P47: the organisation\'s statuses in their order; a new '.strtolower($this->spec->recordLabel()).' starts in the first'
                    .($byAction === [] ? '' : ', and a status an action leads to is reached by that action')." (P133). */\n"
                    ."const statusOptions = computed(() => {$rows}.map((s) => ({ value: s.id, label: statuses.label(s, locale.value) })))\n";
            }
        }
        $markup = [];
        foreach ($this->spec->columns() as $c) {
            // P125: JSON is written by code or its own editor; P133: a column the system fills is not the person's to type
            if (ColumnTypes::kind($c) === 'json' || $this->spec->fill($c) !== null || (ColumnTypes::kind($c) === 'workflow' && ! $statusInForm)) {
                continue;
            }
            $f = $this->field($c, true);
            $state .= $f['state'];
            $body .= $f['body'];
            $markup[$c['name']] = $f['field'];
            $valid = [...$valid, ...$f['valid']];
            $imports = array_merge($imports, $f['imports']);
        }
        $lines = '';
        if (($l = $this->spec->lines()) !== null) {
            [$setup, $state, $body, $lines, $valid, $imports] = $this->formLines($l, $setup, $state, $body, '', $valid, $imports);
        }
        // P133: two columns, in section order (required first in each); the location with the links, the lines before the notes.
        // A long form (8 fields or more, with 2 or more sections of at least 2 fields) heads Details, Amounts, Links, Dates
        // and its own sections; a shorter one reads well in its two columns without them. Lines, files and notes carry their
        // own labels
        $parts = [];
        foreach (Sections::form($this->spec) as $section) {
            $part = '';
            foreach ($section['entries'] as $e) {
                $part .= match (true) {
                    isset($e['location']) => self::I6."<ApiSelect v-model=\"form.location_id\" module=\"locations\" required :label=\"t('{$ns}.form.location')\" :error=\"errors.location_id?.[0]\" />\n",
                    isset($e['lines']) => $lines,
                    default => $markup[$e['col']['name']] ?? '',
                };
            }
            if ($part !== '') {
                $parts[] = [$section['key'], $section['custom'] || in_array($section['key'], ['details', 'amounts', 'links', 'dates'], true), $part, substr_count($part, self::I6.'<') - substr_count($part, self::I6.'<LockedField')];
            }
        }
        // P133 (amended): a form whose fields ask for 1 or 3 quarters lays them on a quarter grid (inside the scaffold's halves);
        // its rows are the spec's own, so it has no section headings
        $quarters = false;
        foreach ($this->spec->columns() as $c) {
            $quarters = $quarters || in_array((int) ($c['span'] ?? 2), [1, 3], true);
        }
        $headed = ! $quarters && count($markup) + ($this->spec->scope() === 'location' ? 1 : 0) >= 8 && count(array_filter($parts, fn ($p) => $p[1] && $p[3] >= 2)) >= 2;
        if ($quarters) {
            foreach ($parts as &$p) {
                $p[2] = $this->spanFields($p[2]);
            }
            unset($p);
        }
        foreach ($parts as [$key, $heads, $part]) {
            if ($headed && $heads) {
                $fields .= self::I6."<h3 class=\"pt-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:col-span-2\">{{ t('{$ns}.sections.{$key}') }}</h3>\n";
            }
            $fields .= $part;
        }
        if ($quarters) {
            $fields = self::I6."<div class=\"grid grid-cols-[minmax(0,1fr)] gap-4 sm:col-span-2 sm:grid-cols-2 lg:grid-cols-4\" data-testid=\"form-quarters\">\n".$fields.self::I6."</div>\n";
        }
        $size = $l !== null && $this->spec->priced() ? '3xl' : (count($markup) + ($this->spec->scope() === 'location' ? 1 : 0) > 1 || $l !== null ? 'lg' : 'md');
        $validBody = self::I2.'return '.($valid === [] ? 'true' : implode(' && ', $valid))."\n";

        return [['setup' => $setup, 'state' => $state, 'size' => "const size = '{$size}'\n", 'valid' => $validBody, 'body' => $body, 'fields' => $fields], $imports];
    }

    /** The item module's API path (products) and the names its rows go by. */
    private function itemPath(string $module): string
    {
        return Str::kebab($module);
    }

    /**
     * P115: the lines in the form: one draft, one save. A record opened from the list comes without them (they load);
     * past the editable status they are shown, not changed; the server's line errors land on their lines.
     */
    private function formLines(array $l, string $setup, string $state, string $body, string $fields, array $valid, array $imports): array
    {
        $ns = $this->ns;
        $record = strtolower($this->spec->recordLabel());
        $path = $this->itemPath($l['item_module']);
        $priced = $this->spec->priced();
        $docNames = ['DocLines', 'docLineErrors', 'fromDocLines'];
        if ($priced) {
            $docNames[] = 'linesTotals';
        }
        $imports['@/components/app/doc-lines'] = [...$docNames, 'savedLineIds', 'toDocLines', 'useLineItems', 'type DocLine'];
        $imports['@/components/app/states'] = ['Skeleton'];
        $imports['vue'] = ['computed', 'onMounted', 'ref'];
        $setup .= "/* P115: the lines are in the same form, draft and save. A {$record} opened from the list comes without them: they load here. */\n"
            ."const items = useLineItems({ path: '{$path}'".($priced && $l['item_price'] ? ", price: '{$l['item_price']}'" : '')." })\n"
            ."items.seed(props.row?.lines)\n"
            ."const savedLines = ref(savedLineIds(props.row?.lines))\n"
            ."onMounted(async () => {\n  if (!props.row || props.row.lines) return\n  const full = await {$ns}Api.view(props.row.uuid)\n  items.seed(full.lines)\n  savedLines.value = savedLineIds(full.lines)\n  form.lines = toDocLines(full.lines)\n})\n";
        $locked = '';
        if ($l['editable_status'] !== null && ($wf = $this->wfColumn()) !== null) {
            $label = array_column($this->spec->workflow()['statuses'], 'label', 'code')[$l['editable_status']];
            $setup .= "/* Past {$label} its lines are shown, not changed (the server refuses them too). */\n"
                ."const linesLocked = computed(() => !!props.row && statuses.byId(props.row.{$wf['name']})?.code !== '{$l['editable_status']}')\n";
            $locked = ' && !linesLocked.value';
        }
        $setup .= "const lineErrors = computed(() => docLineErrors(errors.value))\n";
        $state .= self::I2."lines: (props.row && !props.row.lines ? null : toDocLines(props.row?.lines)) as DocLine[] | null,\n";
        $valid[] = 'form.lines !== null && form.lines.length > 0';
        $sent = 'lines: fromDocLines(form.lines, savedLines.value)'.($priced ? ', total: linesTotals(form.lines'.($l['prices_include_vat'] ? ', true' : '').').total' : '');
        $body .= self::I4."...(form.lines !== null{$locked} ? { {$sent} } : {}),\n";
        $props = "kind=\"{$l['kind']}\" item-module=\"{$path}\" :item-of=\"items.itemOf\"".($priced ? ' :price-of="items.priceOf"' : '').($l['prices_include_vat'] ? ' prices-include-vat' : '');
        $fields .= self::I6."<div class=\"grid min-w-0 gap-1.5 sm:col-span-2\">\n"
            .self::I8."<span class=\"text-sm font-medium\">{{ t('{$ns}.form.lines') }}</span>\n"
            .self::I8."<DocLines v-if=\"form.lines !== null\" v-model=\"form.lines\" {$props}".($locked ? ' :readonly="linesLocked"' : '')." :errors=\"lineErrors\" :error=\"errors.lines?.[0]\" />\n"
            .self::I8."<Skeleton v-else class=\"h-24 w-full\" />\n"
            .($locked ? self::I8."<p v-if=\"linesLocked\" class=\"text-xs text-muted-foreground\">{{ t('{$ns}.form.linesLocked') }}</p>\n" : '')
            .self::I6."</div>\n";

        return [$setup, $state, $body, $fields, $valid, $imports];
    }

    /**
     * One column's input: its state line, its payload line, its markup, its validity checks and imports. In the record's
     * form ($inForm) a nullable column may stay empty and a foreign key is locked on a parent's child tab (P86); in an
     * action's dialog every field is required.
     *
     * @return array{state: string, body: string, field: string, valid: list<string>, imports: array<string, list<string>>}
     */
    private function field(array $c, bool $inForm): array
    {
        $ns = $this->ns;
        $k = $this->kebab;
        $state = $body = $fields = '';
        $valid = [];
        $imports = [];
        $n = $c['name'];
        $kind = ColumnTypes::kind($c);
        $nullable = $inForm && ! empty($c['nullable']); // an action's fields are what it asks for: required
        $row = $inForm ? 'props.row?.' : 'props.row.';
        $short = $this->short($c);
        // a new record starts on the spec's default (what the migration's default says)
        $dflt = fn (string $none): string => ($d = ColumnTypes::defaultValue($c)) === null ? $none : ColumnTypes::jsDefault($d);
        $err = self::I8."<p v-if=\"errors.{$n}\" class=\"text-xs text-destructive\">{{ errors.{$n}[0] }}</p>\n";
        // P133: a required field's label carries the CSS asterisk; long fields span both columns of the record's form
        $star = $inForm && ! $nullable ? " after:ml-0.5 after:text-destructive after:content-['*']" : '';
        $wide = $inForm ? ' sm:col-span-2' : '';
        switch ($kind) {
            case 'workflow':
                $state .= self::I2."{$n}: {$row}{$n} as number | undefined,\n";
                $body .= self::I4."...(form.{$n} !== undefined ? { {$n}: Number(form.{$n}) } : {}),\n";
                $fields .= self::I6."<div ".($inForm ? 'v-if="row" ' : '')."class=\"grid gap-1.5\">\n".self::I8."<span class=\"text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}</span>\n"
                    .self::I8."<Select v-model=\"form.{$n}\" :aria-label=\"t('{$ns}.form.{$short}')\" :options=\"statusOptions\" :placeholder=\"t('{$ns}.form.{$short}First')\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/ui/select'] = ['Select'];
                break;
            case 'foreign':
                $state .= self::I2."{$n}: ({$row}{$n} ?? null) as number | null,\n";
                $body .= self::I4.($nullable ? "{$n}: form.{$n} !== null ? Number(form.{$n}) : null,\n" : "{$n}: Number(form.{$n}),\n");
                $module = Str::kebab($c['relatedModule']); // the select endpoint reads it as kebab: suite-order-types
                $imports['@/components/app/api-select'] = ['ApiSelect'];
                if (! $inForm) {
                    $fields .= self::I6."<ApiSelect v-model=\"form.{$n}\" module=\"{$module}\" required :label=\"t('{$ns}.form.{$short}')\" :error=\"errors.{$n}?.[0]\" />\n";
                    $valid[] = "form.{$n} !== null";
                    break;
                }
                $fields .= self::I6."<ApiSelect v-if=\"parent?.via !== '{$n}'\" v-model=\"form.{$n}\" module=\"{$module}\"".($nullable ? '' : ' required')." :label=\"t('{$ns}.form.{$short}')\" :error=\"errors.{$n}?.[0]\" />\n"
                    .self::I6."<LockedField v-else :label=\"t('{$ns}.form.{$short}')\" :value=\"parent?.label ?? ''\" />\n";
                $imports['@/components/app/locked-field'] = ['LockedField'];
                if (! $nullable) {
                    // on a parent's child tab (P86) the link is the parent's, set by the route
                    $valid[] = "(props.parent?.via === '{$n}' || form.{$n} !== null)";
                }
                break;
            case 'media':
                // P131: FilePicker uploads to the Media library at once and holds the file's uuid
                $state .= self::I2."{$n}: {$row}{$n} ?? '',\n";
                $body .= self::I4."{$n}: form.{$n} || null,\n";
                $accept = ($c['accept'] ?? 'image') === 'any' ? 'any' : 'image';
                $fields .= self::I6."<div class=\"grid gap-1.5{$wide}\" data-testid=\"{$k}-{$n}\">\n".self::I8."<FilePicker v-model=\"form.{$n}\" accept=\"{$accept}\" :label=\"t('{$ns}.form.{$short}')\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/app/media'] = ['FilePicker'];
                break;
            case 'date':
            case 'datetime':
                $state .= self::I2."{$n}: {$row}{$n} ?? '',\n";
                $body .= self::I4.($nullable ? "{$n}: form.{$n} || null,\n" : "{$n}: form.{$n},\n");
                $fields .= self::I6."<div class=\"grid gap-1.5\">\n".($inForm ? self::I8."<span class=\"text-sm font-medium{$star}\">{{ t('{$ns}.form.{$short}') }}</span>\n" : '')
                    .self::I8."<DatePicker v-model=\"form.{$n}\" :label=\"t('{$ns}.form.{$short}')\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/app/date-picker'] = ['DatePicker'];
                if (! $nullable) {
                    $valid[] = "form.{$n} !== ''";
                }
                break;
            case 'boolean':
                $state .= self::I2."{$n}: {$row}{$n} ?? ".$dflt('false').",\n";
                $body .= self::I4."{$n}: form.{$n},\n";
                $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label class=\"flex items-center justify-between gap-3 text-sm font-medium\">{{ t('{$ns}.form.{$short}') }}<Switch v-model=\"form.{$n}\" /></label>\n".$err.self::I6."</div>\n";
                $imports['@/components/ui/switch'] = ['Switch'];
                break;
            case 'integer':
            case 'decimal':
                $state .= self::I2."{$n}: {$row}{$n} != null ? String(props.row.{$n}) : ".$dflt("''").",\n";
                $body .= self::I4.($nullable ? "{$n}: form.{$n} === '' ? null : Number(form.{$n}),\n" : "{$n}: Number(form.{$n}),\n");
                $imports['@/components/ui/input'] = ['Input'];
                $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium{$star}\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                    .self::I8."<Input id=\"{$k}-{$n}\" v-model=\"form.{$n}\" type=\"number\" inputmode=\"decimal\" :aria-invalid=\"!!errors.{$n}\" />\n".$err.self::I6."</div>\n";
                if (! $nullable) {
                    $valid[] = "form.{$n} !== ''";
                }
                break;
            case 'enum':
                $values = array_values($c['values'] ?? []);
                // in a dialog the row is always there and its enum type has no '': the field may still start empty
                $state .= self::I2."{$n}: ".($inForm ? "{$row}{$n} ?? ".$dflt("''") : "({$row}{$n} ?? '') as string").",\n";
                $body .= self::I4."{$n}: ".($nullable ? "(form.{$n} || null) as {$this->s}Row['{$n}']" : "form.{$n} as {$this->s}Row['{$n}']").",\n";
                $options = '['.implode(', ', array_map(fn ($v) => "{ value: '{$v}', label: t('{$ns}.values.{$short}.{$v}') }", $values)).']';
                $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<span class=\"text-sm font-medium{$star}\">{{ t('{$ns}.form.{$short}') }}</span>\n"
                    .self::I8."<Select v-model=\"form.{$n}\" :aria-label=\"t('{$ns}.form.{$short}')\" :options=\"{$options}\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/ui/select'] = ['Select'];
                // with a default it starts on a value and the select has no empty choice: nothing to check (its type has no '')
                if (! $nullable && ColumnTypes::defaultValue($c) === null) {
                    $valid[] = "form.{$n} !== ''";
                }
                break;
            case 'text':
                $state .= self::I2."{$n}: {$row}{$n} ?? ".$dflt("''").",\n";
                $body .= self::I4."{$n}: form.{$n}.trim()".($nullable ? ' || null' : '').",\n";
                $fields .= self::I6."<div class=\"grid gap-1.5{$wide}\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium{$star}\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                    .self::I8."<Textarea id=\"{$k}-{$n}\" v-model=\"form.{$n}\" :rows=\"3\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/ui/textarea'] = ['Textarea'];
                if (! $nullable) {
                    $valid[] = "form.{$n}.trim().length > 0";
                }
                break;
            default:
                $state .= self::I2."{$n}: {$row}{$n} ?? ".$dflt("''").",\n";
                $body .= self::I4."{$n}: form.{$n}.trim()".($nullable ? ' || null' : '').",\n";
                $fields .= self::I6."<div class=\"grid gap-1.5\">\n".self::I8."<label for=\"{$k}-{$n}\" class=\"text-sm font-medium{$star}\">{{ t('{$ns}.form.{$short}') }}</label>\n"
                    .self::I8."<Input id=\"{$k}-{$n}\" v-model=\"form.{$n}\" :aria-invalid=\"!!errors.{$n}\" />\n".$err.self::I6."</div>\n";
                $imports['@/components/ui/input'] = ['Input'];
                if (! $nullable) {
                    $valid[] = "form.{$n}.trim().length > 0";
                }
        }

        return ['state' => $state, 'body' => $body, 'field' => $fields, 'valid' => $valid, 'imports' => $imports];
    }

    /** @return array{0: array<string, string>, 1: array<string, list<string>>} */
    private function detail(): array
    {
        $ns = $this->ns;
        $imports = [];
        $setup = '';
        $icon = $this->spec->icon();
        $header = "const headerIcon = '{$icon}'\nconst headerStatus = computed<StatusPill | null>(() => null)\n";
        if (($wf = $this->wfColumn()) !== null) {
            $key = $this->spec->workflow()['key'];
            $imports['@/app/lib/workflowStatuses'] = ['TONES', 'useWorkflow'];
            $setup = "const statuses = useWorkflow('{$key}')\n";
            $header = "const headerIcon = '{$icon}'\nconst headerStatus = computed<StatusPill | null>(() => {\n"
                ."  const s = rec.value ? statuses.byId(rec.value.{$wf['name']}) : undefined\n"
                ."  return s ? { label: statuses.label(s, locale.value), cls: TONES[s.tone].cls, dot: TONES[s.tone].dot } : null\n})\n";
        }
        if (($st = Sections::statusLink($this->spec)) !== null) {
            // P133: a link to the platform's Statuses is the header's status pill, not a link among the facts
            $key = ColumnTypes::relationKey($st['name']);
            $col = $this->modules->labelColumn('Statuses');
            $header = "const headerIcon = '{$icon}'\nconst headerStatus = computed<StatusPill | null>(() => (rec.value?.{$key} ? { label: rec.value.{$key}.{$col} } : null))\n";
        }
        // P133: the header's code (the number) and meta line (a priced document's total)
        $number = $this->spec->number()['column'] ?? null;
        $priced = $this->spec->lines() !== null && $this->spec->priced();
        $header .= "/* The header's code (its number) and meta line (a document's total), P133. */\n"
            .($number !== null ? "const headerCode = computed(() => rec.value?.{$number})\n" : "const headerCode: string | undefined = undefined\n")
            .($priced ? "const headerMeta = computed<string | null>(() => (rec.value ? `\${t('{$ns}.columns.total')} \${money(Number(rec.value.total))}` : null))\n" : "const headerMeta = computed<string | null>(() => null)\n");

        // P133: the Overview in sections, each a card; the record's picture first
        $overview = '';
        // P133 (amended): the record's picture is the first field of the first card
        $pic = Sections::pictureColumn($this->spec);
        $sections = Sections::overview($this->spec);
        if ($sections === [] && $pic !== null) {
            $sections = [['key' => 'details', 'custom' => false, 'entries' => []]]; // the picture alone still has its card
        }
        foreach ($sections as $i => $section) {
            $k = $section['key'];
            $overview .= self::I4."{ key: '{$k}', title: t('{$ns}.sections.{$k}'), facts: [\n";
            if ($i === 0 && $pic !== null) {
                $overview .= self::I6."{ icon: 'Image', label: t('{$ns}.columns.{$this->short($this->spec->column($pic))}'), media: r.{$pic}, picture: true".$this->spanOf($this->spec->column($pic))." },\n";
            }
            foreach ($section['entries'] as $e) {
                [$line, $more] = $this->fact($e);
                $overview .= self::I6.$line.",\n";
                $imports = array_merge($imports, $more);
            }
            $overview .= self::I4."] },\n";
        }

        $slots = ['setup' => $setup, 'header' => $header, 'overview' => $overview, 'own-tabs' => '', 'overview-extra' => '', 'tab-bodies' => ''];
        $card = false;
        foreach ($this->spec->columns() as $c) {
            if (ColumnTypes::kind($c) === 'json') {
                // P125: shown read-only, formatted and copyable
                // P133: an empty one is left out
                $slots['overview-extra'] .= self::I8."<Card v-if=\"rec.{$c['name']} != null && Object.keys(rec.{$c['name']}).length\" class=\"p-4\"><CodeBlock :value=\"rec.{$c['name']}\" :label=\"t('{$ns}.columns.{$this->short($c)}')\" /></Card>\n";
                $imports['@/components/app/code-block'] = ['CodeBlock'];
                $card = true;
            }
        }
        if (($l = $this->spec->lines()) !== null) {
            $record = strtolower($this->spec->recordLabel());
            $when = $l['editable_status'] !== null ? ' while it is '.array_column($this->spec->workflow()['statuses'], 'label', 'code')[$l['editable_status']] : '';
            $names = ['DocLines', 'itemsOf'];
            if ($priced) {
                $names[] = 'money';
            }
            $names[] = 'toDocLines';
            $imports['@/components/app/doc-lines'] = $names;
            $slots['own-tabs'] = self::I2."{ key: 'lines', label: t('{$ns}.tabs.lines') },\n";
            $slots['setup'] .= "/* P115: the {$record}'s lines, read-only here (they are changed in its form{$when}). */\n"
                ."const lines = computed(() => toDocLines(rec.value?.lines))\nconst itemOf = computed(() => itemsOf(rec.value?.lines))\n";
            $list = "<DocLines :model-value=\"lines\" readonly kind=\"{$l['kind']}\" item-module=\"{$this->itemPath($l['item_module'])}\" :item-of=\"itemOf\"".($l['prices_include_vat'] ? ' prices-include-vat' : '').' />';
            // P133 (the design review): the lines are the Lines tab's; the Overview has the facts and the header the total
            $slots['tab-bodies'] = self::I6."<Card v-else-if=\"current === 'lines'\" class=\"p-4\">{$list}</Card>\n";
            $card = true;
        }
        if ($card) {
            $imports['@/components/ui/card'] = ['Card'];
        }

        return [$slots, $imports];
    }

    /**
     * One Overview fact (P133): its icon by kind (the same everywhere), its label, its value or link, required when the
     * column is (an empty optional one is left out by FactSections), and how it reads (mono, num, long).
     *
     * @return array{0: string, 1: array<string, list<string>>}
     */
    /**
     * P133 (amended): a form's fields on the quarter grid: each top-level field takes its column's span (default 2, long ones
     * 4), as classes on its wrapper. Applied only when a field asks for 1 or 3 quarters, so other forms keep their halves.
     */
    private function spanFields(string $part): string
    {
        $cls = [1 => 'sm:col-span-1 lg:col-span-1', 2 => 'sm:col-span-1 lg:col-span-2', 3 => 'sm:col-span-2 lg:col-span-3', 4 => 'sm:col-span-2 lg:col-span-4'];
        $spans = [];
        foreach ($this->spec->columns() as $c) {
            $spans[$c['name']] = (int) ($c['span'] ?? (in_array(ColumnTypes::kind($c), ['text', 'media'], true) ? 4 : 2));
        }
        // each field's first line at the fields' indent; its name from v-model="form.<name>" or data-testid / for="<kebab>-<name>"
        $last = 2;

        return (string) preg_replace_callback('/^'.preg_quote(self::I6, '/').'<(div|ApiSelect|LockedField)\b([^\n]*)$(.*?)(?=^'.preg_quote(self::I6, '/').'<(?:div|ApiSelect|LockedField|h3)\b|\z)/ms', function (array $m) use ($spans, $cls, &$last) {
            if ($m[1] === 'LockedField') {
                // the v-else of the ApiSelect before it (on a parent's tab): the same place and width
                return self::I6.'<'.preg_replace('/^(\S+)/', '$1 class="'.$cls[$last].'"', $m[1].$m[2]).$m[3];
            }
            // the field's own model (a label's key, payments.form.method, is not its column)
            preg_match('/v-model="form\.([a-z0-9_]+)"/', $m[0], $f);
            $span = $last = $spans[$f[1] ?? ''] ?? (str_contains($m[0], 'sm:col-span-2') ? 4 : 2);
            $first = preg_replace('/ sm:col-span-2(?=")/', '', $m[1].$m[2]);
            $first = str_contains($first, 'class="') ? preg_replace('/class="/', 'class="'.$cls[$span].' ', $first, 1) : preg_replace('/^(\S+)/', '$1 class="'.$cls[$span].'"', $first);

            return self::I6.'<'.$first.$m[3];
        }, $part);
    }

    /** P133 (amended): a column's own span (", span: 3"), when the spec gives one. */
    private function spanOf(array $c): string
    {
        return isset($c['span']) ? ', span: '.(int) $c['span'] : '';
    }

    private function fact(array $e): array
    {
        $ns = $this->ns;
        if (isset($e['location'])) {
            return ["{ icon: 'MapPin', label: t('{$ns}.columns.location'), required: true, link: r.location ? { module: 'Locations', value: { id: r.location.id, uuid: r.location.uuid, label: r.location.".$this->modules->labelColumn('Locations')." } } : null }", []];
        }
        if (isset($e['total'])) {
            return ["{ icon: 'Banknote', label: t('{$ns}.columns.total'), value: money(Number(r.total)), required: true, num: true }", []];
        }
        $c = $e['col'];
        $n = $c['name'];
        $label = "t('{$ns}.columns.{$this->short($c)}')";
        $req = empty($c['nullable']) ? ', required: true' : '';
        $kind = ColumnTypes::kind($c);
        if ($kind === 'foreign') {
            $module = $c['relatedModule'];
            [$rel, $key] = [ColumnTypes::relationName($n), ColumnTypes::relationKey($n)];
            $col = $this->modules->labelColumn($module);
            $icon = match ($module) {
                'Users' => 'UserRound',
                'Locations' => 'MapPin',
                default => $this->modules->icon($module) ?? 'Link',
            };

            return ["{ icon: '{$icon}', label: t('{$ns}.columns.{$rel}'){$req}, link: r.{$key} ? { module: '{$module}', value: { id: r.{$key}.id, uuid: r.{$key}.uuid, label: r.{$key}.{$col} } } : null{$this->spanOf($c)} }", []];
        }
        $imports = [];
        $text = $this->plainText($c, 'r');
        if (str_contains($text, 'formatAmount(')) {
            $imports['@/app/lib/numbers'] = ['formatAmount'];
        }
        $coded = preg_match('/(^|_)(sku|code|ref|reference|no|number|barcode)$/', $n) === 1;
        // P133 (amended): a plain text field takes half a row unless the spec says
        $span = $this->spanOf($c) ?: ($kind === 'string' && ! $coded ? ', span: 2' : '');

        $line = match ($kind) {
            'date', 'datetime' => "{ icon: 'CalendarDays', label: {$label}, value: day(r.{$n}){$req} }",
            'text' => "{ icon: 'AlignLeft', label: {$label}, value: r.{$n}{$req}, long: true }",
            'integer' => "{ icon: 'Hash', label: {$label}, value: {$text}{$req}, num: true }",
            'decimal' => "{ icon: '".(preg_match('/(percent|rate)/', $n) ? 'Percent' : 'Banknote')."', label: {$label}, value: {$text}{$req}, num: true }",
            'enum' => "{ icon: 'Tag', label: {$label}, value: {$text}{$req} }",
            'boolean' => "{ icon: 'ToggleLeft', label: {$label}, value: {$text} }",
            'media' => "{ icon: 'Paperclip', label: {$label}, media: r.{$n} }",
            default => $coded ? "{ icon: 'Hash', label: {$label}, value: r.{$n}{$req}, mono: true }" : "{ icon: 'Type', label: {$label}, value: r.{$n}{$req} }",
        };

        return [$span === '' ? $line : substr($line, 0, -2).$span.' }', $imports];
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
                $en["values.{$short}.{$v}"] = Str::headline(strtolower((string) $v));
                $sw_or("values.{$short}.{$v}", $c['values_sw'][$v] ?? null, Str::headline(strtolower((string) $v)));
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
        foreach ($this->spec->sections() as $sec) {
            // P133: the spec's own sections (the default ones are the scaffold's)
            $en["sections.{$sec['key']}"] = $sec['label'];
            $sw_or("sections.{$sec['key']}", $sec['label_sw'], $sec['label']);
        }
        if ($this->wfColumn() !== null) {
            [$en['tabs.all'], $sw['tabs.all']] = ['All', 'Zote'];
        }
        if (($l = $this->spec->lines()) !== null) {
            [$en['tabs.lines'], $en['form.lines']] = ['Lines', 'Lines'];
            $sw_or('tabs.lines', $l['label_sw'], 'Lines');
            $sw_or('form.lines', $l['label_sw'], 'Lines');
            if ($l['editable_status'] !== null) {
                $st = array_column($this->spec->workflow()['statuses'], null, 'code')[$l['editable_status']];
                $en['form.linesLocked'] = "Past {$st['label']}: its lines are shown, not changed.";
                $sw_or('form.linesLocked', isset($st['label_sw']) ? "Baada ya {$st['label_sw']}: mistari yake inaonyeshwa, haibadilishwi." : null, $en['form.linesLocked']);
            }
            if ($this->spec->priced()) {
                [$en['columns.total'], $sw['columns.total']] = ['Total', 'Jumla'];
            }
        }
        if (($n = $this->spec->number()) !== null) {
            $en["columns.{$n['column']}"] = $n['label'];
            $sw_or("columns.{$n['column']}", $n['label_sw'], $n['label']);
        }
        if ($this->spec->actions() !== []) {
            [$en['actions.failed'], $sw['actions.failed']] = ['Could not do it. Try again.', 'Haikuweza kufanyika. Jaribu tena.'];
        }
        foreach ($this->spec->actions() as $key => $a) {
            $en["actions.{$key}.label"] = $a['label'];
            $sw_or("actions.{$key}.label", $a['label_sw'], $a['label']);
        }
        foreach ($this->spec->tabOn() as $t) {
            $en["tabOn.{$t['key']}"] = $t['tab'];
            $sw_or("tabOn.{$t['key']}", $t['tab_sw'] ?? null, $t['tab']);
        }
        if (isset($this->spec->raw()['labels_sw'])) {
            $plural = $this->spec->raw()['labels_sw'];
            $sw['title'] = $plural;
            $sw['subtitle'] = $this->spec->scope() === 'global' ? "{$plural} zinazoshirikiwa na mashirika yote." : "{$plural} za shirika hili.";
        }

        return ['en' => $en, 'sw' => $sw, 'sw_fallback' => $fallback];
    }
}
