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
        $this->assertContains('seeder is not used by the Bootbox target (dropped)', $r['notes']);
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
}
