<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Core;

use Blutrixx\GeneratorEngine\Core\LegacyModuleConfig;
use Blutrixx\GeneratorEngine\Core\ModuleOrder;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use PHPUnit\Framework\TestCase;

class LegacyAndOrderTest extends TestCase
{
    private static function legacy(array $over = []): array
    {
        return $over + [
            'module_name' => 'Vendors', 'module_group_name' => 'Purchasing', 'table_name' => 'vendors', 'id_type' => 'autoincrement',
            'features' => ['backend' => ['list' => ['sortableFields' => 'name']]], 'seeder' => ['data' => [['name' => 'X']]],
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '20', 'nullable' => true, 'unique' => false, 'relatedModule' => null, 'featureSelections' => []],
                ['name' => 'name', 'type' => 'string', 'length' => '160', 'nullable' => false, 'unique' => true, 'relatedModule' => null],
                ['name' => 'tier', 'type' => 'enum', 'enum_values' => ['a', 'b'], 'nullable' => false],
                ['name' => 'location_id', 'type' => 'foreignId', 'relatedModule' => 'Locations', 'nullable' => false],
            ],
            'actions' => ['approve' => ['name' => 'approve', 'label' => 'Approve', 'icon' => 'CheckIcon', 'placement' => 'main', 'hasUI' => false, 'urlParams' => ['uuid'], 'operations' => ['view' => ['enabled' => true]]]],
        ];
    }

    public function test_a_plain_legacy_module_converts_with_the_bootbox_defaults(): void
    {
        $r = LegacyModuleConfig::convert(self::legacy());
        $this->assertSame([], $r['refused']);
        $spec = $r['spec'];
        $this->assertSame(['Vendors', 'Purchasing', 'location', 'name'], [$spec['module_name'], $spec['module_group_name'], $spec['scope'], $spec['label_column']]);
        $this->assertSame(['code', 'name', 'tier'], array_column($spec['columns'], 'name')); // location_id is the scope's
        $this->assertSame(160, $spec['columns'][1]['length']);
        $this->assertSame(['a', 'b'], $spec['columns'][2]['values']);
        $this->assertArrayNotHasKey('featureSelections', $spec['columns'][0]);
        $this->assertSame(['label' => 'Approve', 'icon' => 'Check', 'button' => 'header', 'confirm' => true], $spec['actions']['approve']);
        $this->assertArrayNotHasKey('features', $spec);
        $this->assertSame(["its seed rows are not carried over: seed them in the module's seeder"], $r['notes']); // features and id_type: no note
        ModuleSpec::fromArray($spec); // it is a valid generator.json
    }

    public function test_every_reason_a_module_cannot_be_converted_is_given_at_once(): void
    {
        $r = LegacyModuleConfig::convert(self::legacy([
            'morphs' => [['name' => 'subject']], 'file_columns' => [['name' => 'logo']], 'processors' => ['before_save' => ['X']],
            'delegations' => [['relatedModule' => 'Contacts']], 'inline_items' => [['key' => 'lines']], 'unique_constraints' => [['code', 'name']],
            'columns' => [['name' => 'meta', 'type' => 'json', 'nullable' => true], ['name' => 'code', 'type' => 'string', 'nullable' => true]],
            'actions' => ['wiz' => ['wizard' => ['enabled' => true]], 'pg' => ['uiType' => 'page'], 'odd' => ['label' => 'Odd', 'fields' => [['field' => 'reason']]]],
        ]));
        $this->assertNull($r['spec']);
        foreach (['morphs', 'file columns', 'processors', 'tab_on', "'lines'", 'composite unique', 'meta (json)', 'required text column', 'action wiz: wizards', 'action pg: page actions', 'action odd: its field "reason"'] as $part) {
            $this->assertNotEmpty(array_filter($r['refused'], fn ($w) => str_contains($w, $part)), "missing: {$part}");
        }
    }

    public function test_modules_are_generated_after_the_ones_they_point_at(): void
    {
        $spec = fn (string $key, array $cols = [], array $over = []) => ModuleSpec::fromArray($over + ['module_name' => $key, 'columns' => [['name' => 'name', 'type' => 'string'], ...$cols]]);
        $specs = [
            'c' => $spec('Quotes', [], ['lines' => ['item_module' => 'Products']]),
            'a' => $spec('Notes', [['name' => 'quote_id', 'type' => 'foreignId', 'relatedModule' => 'Quotes']], ['tab_on' => ['Quotes' => ['key' => 'notes', 'via' => 'quote_id', 'tab' => 'Notes']]]),
            'b' => $spec('Products', [['name' => 'parent_id', 'type' => 'foreignId', 'relatedModule' => 'Products', 'nullable' => true], ['name' => 'user_id', 'type' => 'foreignId', 'relatedModule' => 'Users']]),
        ];
        $this->assertSame(['b', 'c', 'a'], ModuleOrder::sort($specs)); // self-reference and Users (not in the set) are fine

        $this->expectExceptionMessage('These modules point at each other: Ping → Pong → Ping');
        ModuleOrder::sort([
            'x' => $spec('Ping', [['name' => 'pong_id', 'type' => 'foreignId', 'relatedModule' => 'Pong']]),
            'y' => $spec('Pong', [['name' => 'ping_id', 'type' => 'foreignId', 'relatedModule' => 'Ping']]),
        ]);
    }

    public function test_a_module_pointing_at_itself_is_known_before_the_app_knows_it(): void
    {
        $spec = ModuleSpec::fromArray(['module_name' => 'ItemCategories', 'module_group_name' => 'Demo', 'table_name' => 'item_categories', 'columns' => [
            ['name' => 'name', 'type' => 'string'], ['name' => 'parent_category_id', 'type' => 'foreignId', 'relatedModule' => 'ItemCategories', 'nullable' => true],
        ]]);
        $app = new class implements \Blutrixx\GeneratorEngine\Core\ModuleResolver
        {
            public function table(string $m): string { throw new \RuntimeException("No module '{$m}'"); }
            public function modelClass(string $m): string { throw new \RuntimeException("No module '{$m}'"); }
            public function labelColumn(string $m): string { throw new \RuntimeException("No module '{$m}'"); }
            public function isOrganisationScoped(string $m): bool { throw new \RuntimeException("No module '{$m}'"); }
            public function exists(string $m): bool { return false; }
            public function scope(string $m): string { throw new \RuntimeException("No module '{$m}'"); }
        };
        $r = new \Blutrixx\GeneratorEngine\Core\SpecAwareResolver($spec, $app);
        $this->assertSame(['item_categories', 'App\\Modules\\Custom\\Demo\\ItemCategories\\ItemCategoriesModel', 'name', true, 'organisation'],
            [$r->table('ItemCategories'), $r->modelClass('ItemCategories'), $r->labelColumn('ItemCategories'), $r->exists('ItemCategories'), $r->scope('ItemCategories')]);
        $this->assertFalse($r->exists('Units')); // anything else is the app's to answer
        $rules = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, $r))->render()['slots']['Services/ItemCategoriesCreateService.php']['rules'];
        $this->assertStringContainsString("'parent_category_id' => ['nullable', 'integer', 'exists_in_org:item_categories,id'],", $rules);
    }
}
