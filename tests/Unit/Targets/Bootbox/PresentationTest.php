<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\Icons;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Blutrixx\GeneratorEngine\Core\ValidationRules;
use Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer;
use Blutrixx\GeneratorEngine\Targets\Bootbox\ManifestMerger;
use Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer;
use Blutrixx\GeneratorEngine\Targets\Bootbox\WebRenderer;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\ArrayModuleResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P133 (owner 2026-11-23, on the rebuilt V1 demo): a generated module's presentation — sections, fields the system fills,
 * choices, icons, the sidebar and the phone's bottom bar. The golden modules prove the common cases (generated equals
 * hand-written); these cover what they do not use.
 */
class PresentationTest extends TestCase
{
    private static function payments(array $over = []): ModuleSpec
    {
        return ModuleSpec::fromArray($over + [
            'module_name' => 'Payments', 'module_group_name' => 'Demo', 'table_name' => 'payments', 'label_column' => 'reference',
            'columns' => [
                ['name' => 'reference', 'type' => 'string'],
                ['name' => 'direction', 'type' => 'string', 'values' => ['in', 'out']],
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'paid_on', 'type' => 'date', 'nullable' => true],
                ['name' => 'recorded_by_id', 'type' => 'foreignId', 'relatedModule' => 'Users', 'fill' => 'user'],
                ['name' => 'approved_by_id', 'type' => 'foreignId', 'relatedModule' => 'Users', 'nullable' => true, 'fill' => 'workflow'],
                ['name' => 'notes', 'type' => 'text', 'nullable' => true],
            ],
        ]);
    }

    public function test_a_column_the_system_fills_is_on_the_overview_never_in_a_form_or_its_rules(): void
    {
        $spec = self::payments();
        $web = (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringNotContainsString('recorded_by_id', $web['pages/PaymentsFormDialog.vue']['fields']);
        $this->assertStringNotContainsString('approved_by_id', $web['pages/PaymentsFormDialog.vue']['state']);
        $this->assertStringContainsString("label: t('payments.columns.recordedBy'), required: true, link: r.recorded_by ?", $web['pages/PaymentsDetailPage.vue']['overview']);
        $this->assertNotContains('recorded_by_id', array_column(ValidationRules::for($spec, 'create', new ArrayModuleResolver), 'field'));
        $model = (new ApiRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString("public const FILLED_BY_USER = ['recorded_by_id'];", $model['slots']['PaymentsModel.php']['constants'] ?? implode("\n", $model['slots']['PaymentsModel.php']));
        $phone = (new PhoneRenderer(self::payments(['phone' => true]), new ArrayModuleResolver))->render()['slots'];
        $this->assertStringNotContainsString('recorded_by', $phone['screens/PaymentsSheet.vue']['fields']);
    }

    public function test_a_string_with_values_is_a_choice_in_two_columns_required_first(): void
    {
        $fields = (new WebRenderer(self::payments(), new ArrayModuleResolver))->render()['slots']['pages/PaymentsFormDialog.vue']['fields'];
        $this->assertStringContainsString("<Select v-model=\"form.direction\"", $fields);
        $this->assertStringContainsString("{ value: 'in', label: t('payments.values.direction.in') }", $fields);
        // reference, direction (Details), amount (Amounts), paid on (Dates), notes spanning both columns, last
        $order = array_map(fn ($n) => strpos($fields, "form.{$n}\""), ['reference', 'direction', 'amount', 'paid_on', 'notes']);
        $this->assertSame($order, array_values(array_filter($order, 'is_int')));
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertStringContainsString('<div class="grid gap-1.5 sm:col-span-2">', $fields);
        $this->assertStringContainsString("text-sm font-medium after:ml-0.5 after:text-destructive after:content-['*']\">{{ t('payments.form.amount') }}", $fields);
        $this->assertStringContainsString("<span class=\"text-sm font-medium\">{{ t('payments.form.paidOn') }}</span>", $fields, 'a date has a visible label');
    }

    public function test_a_spec_names_its_own_sections_first(): void
    {
        $spec = self::payments(['sections' => [['key' => 'money', 'label' => 'Money', 'label_sw' => 'Pesa', 'columns' => ['amount', 'direction']]]]);
        $web = (new WebRenderer($spec, new ArrayModuleResolver))->render();
        $overview = $web['slots']['pages/PaymentsDetailPage.vue']['overview'];
        $this->assertStringStartsWith("    { key: 'money', title: t('payments.sections.money'), facts: [\n", $overview);
        $this->assertSame('Money', $web['locales']['en']['sections.money']);
        $this->assertSame('Pesa', $web['locales']['sw']['sections.money']);
        $this->expectException(InvalidArgumentException::class);
        self::payments(['sections' => [['key' => 'a', 'label' => 'A', 'columns' => ['amount']], ['key' => 'b', 'label' => 'B', 'columns' => ['amount']]]]);
    }

    public function test_the_sidebar_section_the_place_and_a_tab_only_module_off_the_menu(): void
    {
        $base = ['key' => 'Payments', 'menu' => [['title' => 'Payments', 'url' => 'payments', 'icon' => 'Circle', 'section' => null, 'position' => 50]]];
        $m = ManifestMerger::merge($base, self::payments(['icon' => 'Banknote', 'menu' => ['section' => 'spending', 'position' => 3], 'menu_section' => ['key' => 'spending', 'title' => 'Spending', 'icon' => 'Wallet']]), new ArrayModuleResolver);
        $this->assertSame([['title' => 'Payments', 'url' => 'payments', 'icon' => 'Banknote', 'section' => 'spending', 'position' => 3]], $m['menu']);
        $this->assertSame([['key' => 'spending', 'title' => 'Spending', 'icon' => 'Wallet', 'display_mode' => 'dropdown', 'position' => 20]], $m['menu_sections']);
        $tabOnly = ManifestMerger::merge($base, self::payments(['tab_on' => ['Users' => ['key' => 'payments', 'via' => 'recorded_by_id', 'tab' => 'Payments']]]), new ArrayModuleResolver);
        $this->assertSame([], $tabOnly['menu']);
        $shown = ManifestMerger::merge($base, self::payments(['menu' => ['hidden' => false], 'tab_on' => ['Users' => ['key' => 'payments', 'via' => 'recorded_by_id', 'tab' => 'Payments']]]), new ArrayModuleResolver);
        $this->assertCount(1, $shown['menu']);
    }

    public function test_the_phone_s_bottom_bar_is_chosen_by_order(): void
    {
        $tabs = (new PhoneRenderer(self::payments(['phone' => ['tab' => true, 'order' => 2]]), new ArrayModuleResolver))->render()['slots']['module.ts']['tabs'];
        $this->assertSame("  tabs: [{ key: 'payments', title: 'payments.title', icon, path: '/payments', perm: 'Payments.list', order: 2 }],\n", $tabs);
        $this->expectException(InvalidArgumentException::class);
        self::payments(['phone' => ['tab' => true, 'order' => 'first']]);
    }

    public function test_an_icon_is_a_real_lucide_name_never_the_placeholder_and_a_missing_one_is_guessed(): void
    {
        $this->assertSame('Banknote', self::payments()->icon());
        $this->assertSame('Tags', Icons::guess('ExpenseCategories'));
        $this->assertSame('Tag', Icons::guess('ItemPrices'));
        $this->assertSame('Folder', Icons::guess('Widgets'));
        foreach (['Circle', 'NotAnIcon'] as $bad) {
            try {
                self::payments(['icon' => $bad]);
                $this->fail("{$bad} accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not a Lucide icon', $e->getMessage());
            }
        }
    }

    public function test_it_warns_about_what_a_spec_should_say(): void
    {
        $w = ModuleSpec::fromArray(['module_name' => 'Payments', 'columns' => [
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'method', 'type' => 'string'],
            ['name' => 'recorded_by_id', 'type' => 'foreignId', 'relatedModule' => 'Users'],
        ]])->warnings();
        $this->assertCount(3, $w);
        $this->assertStringContainsString('no icon', $w[0]);
        $this->assertStringContainsString('method', implode("\n", $w));
        $this->assertStringContainsString('recorded_by_id', implode("\n", $w));
    }
}
