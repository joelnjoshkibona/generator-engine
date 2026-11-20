<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer;
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
        $this->assertStringContainsString("'project_id' => ['nullable', 'integer'],", $r['rules']);
        $this->assertStringNotContainsString('exists', $r['rules']);
        $this->assertSame(['Safari', 'Meals'], [$r['locales']['sw']['values.kind.travel'], $r['locales']['sw_fallback']['values.kind.meals']]);
        $this->assertSame("const icon = 'Circle'\n", $r['slots']['module.ts']['menu']); // no icon in the spec
    }

    public function test_a_location_and_a_workflow_on_the_phone(): void
    {
        $r = (new PhoneRenderer(CoreTest::tasks(['phone' => true]), new ArrayModuleResolver))->render();
        $sheet = $r['slots']['screens/TasksSheet.vue'];
        $this->assertStringContainsString("<div v-if=\"DATA_MODE !== 'phone'\">", $sheet['fields']); // no server, no locations
        $this->assertStringContainsString("(DATA_MODE === 'phone' || location_id.value !== null)", $sheet['valid']);
        $this->assertStringContainsString("const statuses = useWorkflow('task', STATUSES)", $sheet['setup']);
        $this->assertStringContainsString("{ code: 'open', label: 'Open', label_sw: null, tone: 'muted', sort_order: 0, is_terminal: false },", $r['slots']['screens/types.ts']['types']);
        $this->assertStringContainsString('function pickTab(id: string)', $r['slots']['screens/TasksPage.vue']['setup']);
        $this->assertStringContainsString("'location_id' => ['nullable', 'integer']", $r['rules']);
    }
}
