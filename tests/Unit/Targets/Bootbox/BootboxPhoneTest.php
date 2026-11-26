<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer;
use Blutrixx\GeneratorEngine\Targets\Bootbox\TsImports;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\ArrayModuleResolver;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\CoreTest;
use PHPUnit\Framework\TestCase;

/** D7, P124: the phone's body: every core field from the phone's own pickers, the store's rules, the labels. */
class BootboxPhoneTest extends TestCase
{
    private static function claims(): array
    {
        return (new PhoneRenderer(CoreTest::tasks([
            'module_name' => 'Claims', 'table_name' => 'claims', 'scope' => 'organisation', 'workflow' => null, 'label_column' => 'title', 'phone' => true,
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'length' => 120],
                ['name' => 'amount', 'type' => 'decimal', 'nullable' => true],
                ['name' => 'units', 'type' => 'integer'],
                ['name' => 'kind', 'type' => 'enum', 'values' => ['travel', 'meals'], 'values_sw' => ['travel' => 'Safari']],
                ['name' => 'urgent', 'type' => 'boolean'],
                ['name' => 'spent_on', 'type' => 'date'],
                ['name' => 'project_id', 'type' => 'foreignId', 'relatedModule' => 'Projects', 'nullable' => true],
            ],
        ]), new ArrayModuleResolver))->render();
    }

    public function test_every_core_field_uses_the_phones_own_pickers(): void
    {
        $r = self::claims();
        $f = $r['slots']['screens/ClaimsSheet.vue']['fields'];
        $this->assertStringContainsString('<input v-model="title" type="text" :class="INPUT"', $f);
        $this->assertStringContainsString('<input v-model="amount" type="text" inputmode="decimal"', $f);
        $this->assertStringContainsString("<OptionPick :model-value=\"kind\" :options=\"[{ value: 'travel', label: t('claims.values.kind.travel') }, { value: 'meals', label: t('claims.values.kind.meals') }]\"", $f);
        $this->assertStringContainsString('<Toggle v-model="urgent"', $f);
        $this->assertStringContainsString('<DateField v-model="spent_on" :label="t(\'claims.spentOn\')" testid="claims-spent_on" />', $f);
        $this->assertStringContainsString('<RecordPick v-model="project_id" module="Projects" :label="t(\'claims.project\')" label-column="title" :display="row?.project?.title" nullable', $f);
        $this->assertStringNotContainsString('<select', $f);
        $this->assertStringNotContainsString('type="date"', $f);

        $sheet = $r['slots']['screens/ClaimsSheet.vue'];
        $this->assertSame("  return title.value.trim().length > 0 && units.value !== '' && kind.value !== null && spent_on.value !== null\n", $sheet['valid']);
        $this->assertStringContainsString("amount: amount.value === '' ? null : Number(amount.value),", $sheet['input']);
        $this->assertStringContainsString('units: Number(units.value),', $sheet['input']);
        $this->assertSame(['default:OptionPick'], $r['imports']['screens/ClaimsSheet.vue']['@/components/ui/OptionPick.vue']);
        $this->assertArrayNotHasKey('@/app/data', $r['imports']['screens/ClaimsSheet.vue']); // no location: no DATA_MODE
        $this->assertSame(['INPUT'], $r['imports']['screens/ClaimsSheet.vue']['@/app/lib/systemInput']); // no textarea: no TEXTAREA
    }

    public function test_the_list_the_types_the_rules_and_the_labels(): void
    {
        $r = self::claims();
        $this->assertStringContainsString("[day(row.spent_on), row.project?.title].filter(Boolean).join(' · ')", $r['slots']['screens/ClaimsPage.vue']['cells']);
        $this->assertSame('', $r['slots']['screens/ClaimsPage.vue']['tabs']); // no workflow
        $this->assertStringContainsString("  kind: 'travel' | 'meals'\n", $r['slots']['screens/types.ts']['row']);
        $this->assertStringContainsString("  project?: Link | null\n", $r['slots']['screens/types.ts']['row']);
        $this->assertStringContainsString("  name: string\n  title: string\n}", $r['slots']['screens/types.ts']['types']);
        // the store's rules: the API's, with no table checks, required only on create
        $this->assertStringContainsString("'title' => [\$op === 'create' ? 'required' : 'sometimes', 'string', 'max:120'],", $r['rules']);
        $this->assertStringContainsString("'kind' => [\$op === 'create' ? 'required' : 'sometimes', 'in:travel,meals'],", $r['rules']);
        $this->assertStringContainsString("'project_id' => ['nullable'],", $r['rules']);
        $this->assertStringNotContainsString('exists', $r['rules']);
        $this->assertSame(['Safari', 'Meals'], [$r['locales']['sw']['values.kind.travel'], $r['locales']['sw_fallback']['values.kind.meals']]);
        $this->assertStringNotContainsString('Circle', $r['slots']['module.ts']['menu']); // no icon in the spec: guessed from its name, never the placeholder (P133)
        $this->assertSame("  tabs: [],\n", $r['slots']['module.ts']['tabs']); // no place in the bottom bar unless the spec gives one
    }

    public function test_a_location_and_a_workflow_on_the_phone(): void
    {
        $r = (new PhoneRenderer(CoreTest::tasks(['phone' => true]), new ArrayModuleResolver))->render();
        $sheet = $r['slots']['screens/TasksSheet.vue'];
        $this->assertStringContainsString("<div v-if=\"DATA_MODE !== 'phone'\">", $sheet['fields']); // no server, no locations
        $this->assertStringContainsString("(DATA_MODE === 'phone' || location_id.value !== null)", $sheet['valid']);
        // P133 (amended): one status, so none to choose: not in the form (as on the web), and no lookup for it
        $this->assertStringNotContainsString('task_status_id', $sheet['fields']);
        $this->assertStringNotContainsString('useWorkflow', $sheet['setup']);
        $this->assertStringContainsString("{ code: 'open', label: 'Open', label_sw: null, tone: 'muted', sort_order: 0, is_terminal: false },", $r['slots']['screens/types.ts']['types']);
        $this->assertStringContainsString('function pickTab(id: string)', $r['slots']['screens/TasksPage.vue']['setup']);
        $this->assertStringContainsString("'location_id' => ['nullable', 'integer']", $r['rules']);
    }

    public function test_the_record_page_and_its_actions_on_the_phone(): void // P129, contract 5
    {
        $r = (new PhoneRenderer(BootboxWebTest::withActions(['phone' => true]), new ArrayModuleResolver))->render();
        $detail = $r['slots']['screens/TasksDetailPage.vue'];
        $this->assertStringContainsString("const workflow = { statuses, statusColumn: 'task_status_id' }", $detail['setup']);
        $this->assertStringContainsString('data-testid="record-status"', $detail['header']);
        // P133: sections as cards; an optional field shows only when it has a value
        // P133 (amended 2026-11-23): fields in two sections or more, six or fewer: one Details card, each row keeping its own check
        $this->assertStringContainsString("<Card :title=\"t('phone.sections.details')\" icon=\"ListChecks\">\n", $detail['facts']);
        $this->assertStringContainsString("<Row v-if=\"rec?.due_date\" :k=\"t('tasks.due')\" :v=\"day(rec.due_date)\" />", $detail['facts']);
        $this->assertStringContainsString("<Row v-if=\"rec?.assignee\" :k=\"t('tasks.assignee')\" :v=\"rec.assignee.name\" />", $detail['facts']);
        $this->assertStringContainsString("<Row v-if=\"DATA_MODE !== 'phone'\" :k=\"t('tasks.location')\"", $detail['facts']);
        $this->assertStringNotContainsString("t('tasks.name')", $detail['facts']); // the record's name is the page's title
        $this->assertSame(['DATA_MODE'], $r['imports']['screens/TasksDetailPage.vue']['@/app/data']);

        $actions = $r['slots']['module.ts']['actions'];
        $this->assertStringContainsString("    close: { label: 'Close', label_sw: 'Funga', icon: 'CircleCheck', button: 'header', confirm: true, approval: true, status: 'done' },\n", $actions);
        $this->assertStringContainsString("reassign: { label: 'Reassign', button: 'more', fields: [{ key: 'assignee_id', kind: 'record', label: 'Assignee', module: 'Users' }] },", $actions);

        $none = (new PhoneRenderer(CoreTest::tasks(['phone' => true, 'workflow' => null, 'columns' => [['name' => 'name', 'type' => 'string']]]), new ArrayModuleResolver))->render();
        $this->assertSame("  actions: {},\n", $none['slots']['module.ts']['actions']);
        $this->assertSame("const workflow = {}\nconst locked = false\n", $none['slots']['screens/TasksDetailPage.vue']['setup']); // no workflow: never locked
    }

    public function test_the_record_page_imports_card_and_row_only_when_it_draws_them(): void // contract 14
    {
        $page = fn (array $columns) => (new PhoneRenderer(CoreTest::tasks(['phone' => true, 'workflow' => null, 'scope' => 'organisation', 'columns' => $columns]), new ArrayModuleResolver))->render()['imports']['screens/TasksDetailPage.vue'] ?? [];
        $short = $page([['name' => 'name', 'type' => 'string'], ['name' => 'code', 'type' => 'string', 'nullable' => true]]);
        $this->assertSame(['default:Card'], $short['@/components/ui/Card.vue']);
        $this->assertSame(['default:Row'], $short['@/components/ui/Row.vue']);
        // a name and long text: a card of its own, no row (the case that failed a product's phone typecheck)
        $long = $page([['name' => 'name', 'type' => 'string'], ['name' => 'description', 'type' => 'text', 'nullable' => true]]);
        $this->assertArrayHasKey('@/components/ui/Card.vue', $long);
        $this->assertArrayNotHasKey('@/components/ui/Row.vue', $long);
        // a name alone: neither
        $bare = $page([['name' => 'name', 'type' => 'string']]);
        $this->assertArrayNotHasKey('@/components/ui/Card.vue', $bare);
        $this->assertArrayNotHasKey('@/components/ui/Row.vue', $bare);
        // a page made before contract 14 imports both outside the slot: the slot adds neither again
        $old = "import Card from '@/components/ui/Card.vue'\nimport Row from '@/components/ui/Row.vue'\n// @generated:imports begin\n// @generated:imports end\n";
        $this->assertSame('', TsImports::slot($old, ['@/components/ui/Card.vue' => ['default:Card'], '@/components/ui/Row.vue' => ['default:Row']]));
    }

    public function test_links_a_parents_tab_and_a_documents_lines_on_the_phone(): void // P130, contract 6
    {
        // a child on its parent's tab: its link may be a uuid, and is the parent's (locked) when made from the tab
        $child = (new PhoneRenderer(CoreTest::tasks(['phone' => true, 'workflow' => null, 'scope' => 'organisation', 'label_column' => 'title', 'columns' => [
            ['name' => 'title', 'type' => 'string'], ['name' => 'project_id', 'type' => 'foreignId', 'relatedModule' => 'Projects'],
        ], 'tab_on' => ['Projects' => ['key' => 'tasks', 'via' => 'project_id', 'tab' => 'Tasks', 'tab_sw' => 'Kazi']]]), new ArrayModuleResolver))->render();
        $this->assertSame("  tabOn: {\n    Projects: { key: 'tasks', via: 'project_id', tab: 'Tasks', tab_sw: 'Kazi', labelColumn: 'title', path: '/tasks', sheet: () => import('./screens/TasksSheet.vue') },\n  },\n", $child['slots']['module.ts']['tab-on']);
        $sheet = $child['slots']['screens/TasksSheet.vue'];
        $this->assertStringContainsString('const project_id = ref<number | string | null>(null)', $sheet['state']);
        $this->assertStringContainsString('<div v-if="!parent">', $sheet['fields']);
        $this->assertStringContainsString('(!!props.parent || project_id.value !== null)', $sheet['valid']);
        $this->assertStringContainsString('  project_id: number | string', $child['slots']['screens/types.ts']['row']);
        $this->assertStringContainsString("'project_id' => [\$op === 'create' ? 'required' : 'sometimes'],", $child['rules']);
        $this->assertSame("        return \$record;\n", $child['saving']);

        // a document: its lines in the form and on its page; its totals worked out on the phone
        $doc = (new PhoneRenderer(CoreTest::tasks(['phone' => true, 'workflow' => ['key' => 'task', 'statuses' => [['code' => 'draft', 'label' => 'Draft'], ['code' => 'sent', 'label' => 'Sent']]], 'lines' => ['kind' => 'sale', 'item_module' => 'Projects', 'item_price' => 'price', 'editable_status' => 'draft', 'label_sw' => 'Mistari']]), new ArrayModuleResolver))->render();
        $form = $doc['slots']['screens/TasksSheet.vue'];
        $this->assertStringContainsString('<LinesField v-model="lines" item-module="Projects" item-price="price"', $form['fields']);
        $this->assertStringContainsString(":disabled=\"!!props.row && statuses.byId(props.row.task_status_id)?.code !== 'draft'\"", $form['fields']);
        $this->assertStringContainsString('lines: lines.value,', $form['input']);
        $this->assertStringContainsString('lines.value.length > 0', $form['valid']);
        $this->assertSame(['type SavedLine'], $doc['imports']['screens/TasksSheet.vue']['@/components/app/documents/lines']);
        $this->assertStringContainsString('<LinesCard :lines="rec?.lines ?? []" :totals="rec"', $doc['slots']['screens/TasksDetailPage.vue']['lines']);
        $this->assertSame("        return \\App\\Local\\LineTotals::apply(\$record, false);\n", $doc['saving']);
        $this->assertStringContainsString("'lines' => [\$op === 'create' ? 'required' : 'sometimes', 'array'],", $doc['rules']);
        $this->assertSame(['Lines', 'Mistari'], [$doc['locales']['en']['lines'], $doc['locales']['sw']['lines']]);
    }
}
