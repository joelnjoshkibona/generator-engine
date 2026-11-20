<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer;
use Blutrixx\GeneratorEngine\Targets\Bootbox\BootboxGenerator;
use Blutrixx\GeneratorEngine\Targets\Bootbox\Imports;
use Blutrixx\GeneratorEngine\Targets\Bootbox\ManifestMerger;
use Blutrixx\GeneratorEngine\Targets\Bootbox\Slots;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\ArrayModuleResolver;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\CoreTest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The Bootbox target (P123): slots, imports, the manifest, the rendered bodies and the contract guard. */
class BootboxTargetTest extends TestCase
{
    private const FILE = "<?php\n\nnamespace App\\X;\n\nuse A\\B;\n\nclass X\n{\n    // keep me\n    // @generated:rules begin\n    'old' => [],\n    // @generated:rules end\n    // and me\n}\n";

    public function test_a_slot_is_replaced_and_nothing_else_moves(): void
    {
        $out = Slots::fill(self::FILE, 'rules', "    'new' => ['x'],\n    'two' => [],\n");
        $this->assertStringContainsString("// @generated:rules begin\n    'new' => ['x'],\n    'two' => [],\n    // @generated:rules end", $out);
        $this->assertSame(str_replace("    'old' => [],\n", '', self::FILE), Slots::fill(self::FILE, 'rules', ''));
        $outside = fn (string $t) => Slots::strip(preg_replace('/begin\n.*?\n(\s*\/\/ @generated:rules end)/s', "begin\n$1", $t));
        $this->assertSame($outside(self::FILE), $outside($out));
        $this->assertSame(['rules'], Slots::names($out));
    }

    public function test_a_missing_or_doubled_slot_fails_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("X.php: no slot 'casts'");
        Slots::fill(self::FILE, 'casts', '', 'X.php');
    }

    public function test_imports_go_after_the_last_import_once(): void
    {
        $out = Imports::add(self::FILE, ['C\\D', 'A\\B', 'C\\D']);
        $this->assertStringContainsString("use A\\B;\nuse C\\D;\n", $out);
        $this->assertSame(1, substr_count($out, 'use C\\D;'));
        $this->assertSame($out, Imports::add($out, ['C\\D']));
    }

    public function test_the_manifest_gets_only_the_generators_keys(): void
    {
        $base = ['key' => 'Tasks', 'tier' => 'Custom', 'group' => 'Work', 'enabled' => true, 'description' => 'Tasks', 'scope' => 'location', 'menu' => [['title' => 'Tasks', 'url' => 'tasks', 'icon' => 'Circle', 'section' => null, 'position' => 50]]];
        $spec = CoreTest::tasks(['icon' => 'ListChecks', 'description' => 'Example', 'search' => ['rank' => 7], 'scope' => 'organisation']);
        $out = ManifestMerger::merge($base, $spec, new ArrayModuleResolver);
        foreach (['key', 'tier', 'group', 'enabled', 'scope'] as $k) {
            $this->assertSame($base[$k], $out[$k]);
        }
        $this->assertSame('ListChecks', $out['menu'][0]['icon']);
        $this->assertSame('tasks', $out['menu'][0]['url']);
        $this->assertSame(['module' => 'Projects', 'label' => 'title'], $out['references']['project_id']);
        $this->assertArrayNotHasKey('location_id', $out['references']);
        $this->assertSame(7, $out['search']['rank']);
        // no presenter or scope method names: a generated module cannot add them, and global search 500'd on a missing one
        $this->assertArrayNotHasKey('sub', $out['search']);
        $this->assertArrayNotHasKey('scope', $out['search']);
        $this->assertSame('task_statuses', $out['workflows']['task']['table']);
        $this->assertStringStartsWith("{\n  \"key\": \"Tasks\"", ManifestMerger::encode($out));
    }

    public function test_bodies_spell_the_rules_in_bootbox_terms(): void
    {
        $r = (new ApiRenderer(CoreTest::tasks(), new ArrayModuleResolver))->render();
        $create = $r['slots']['Services/TasksCreateService.php']['rules'];
        $this->assertStringContainsString("'name' => ['required', 'string', 'max:200', 'unique_in_org:tasks,name'],", $create);
        $this->assertStringContainsString("'assignee_id' => ['nullable', 'integer', 'exists:users,id', new MemberOfOrganization],", $create);
        $this->assertStringContainsString("'project_id' => ['required', 'integer', 'exists_in_org:projects,id'],", $create);
        $this->assertStringContainsString('"unique_in_org:tasks,name,{$id}"', $r['slots']['Services/TasksEditService.php']['rules']);
        $this->assertContains('App\Platform\Rules\MemberOfOrganization', $r['imports']['Services/TasksCreateService.php']);
        $this->assertContains('App\Modules\Kernel\System\WorkflowStatuses\WorkflowStatuses', $r['imports']['Services/TasksListService.php']);
        $this->assertStringContainsString("public function project(): BelongsTo\n    {\n        return \$this->belongsTo(ProjectsModel::class, 'project_id');", $r['slots']['TasksModel.php']['relations']);
        $this->assertStringContainsString("'project_id' => ProjectsModel::factory(),", $r['slots']['TasksFactory.php']['definition']);
        $this->assertStringNotContainsString('assignee_id', $r['slots']['TasksFactory.php']['definition']);
        // the parent's model is imported: a child's test builds the parent through this factory
        $this->assertContains('App\\Modules\\Custom\\Work\\Projects\\ProjectsModel', $r['imports']['TasksFactory.php']);
        $this->assertStringContainsString("\$table->unique(['organization_id', 'name']);", $r['slots']['MIGRATION']['columns']);
        $this->assertArrayHasKey('Migrations/DATE_000000_create_task_statuses_table.php', $r['files']);

        $global = (new ApiRenderer(CoreTest::tasks(['scope' => 'global', 'workflow' => null, 'columns' => [['name' => 'name', 'type' => 'string', 'unique' => true]]]), new ArrayModuleResolver))->render();
        $this->assertStringContainsString("'unique:tasks,name'", $global['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringContainsString("\$table->unique('name');", $global['slots']['MIGRATION']['columns']);
        $this->assertSame([], $global['files']);
    }

    public function test_the_contract_guard_refuses_a_contract_it_does_not_know(): void
    {
        $dir = sys_get_temp_dir().'/gen-contract-'.uniqid();
        mkdir($dir);
        try {
            file_put_contents("{$dir}/kit.json", '{"generator_contract": 3}');
            $this->assertSame(3, BootboxGenerator::contract("{$dir}/kit.json"));
            foreach (['{"generator_contract": 2}', '{"generator_contract": 4}', '{}'] as $kit) {
                file_put_contents("{$dir}/kit.json", $kit);
                try {
                    BootboxGenerator::contract("{$dir}/kit.json");
                    $this->fail("accepted {$kit}");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('this generator writes for 3', $e->getMessage());
                }
            }
        } finally {
            @unlink("{$dir}/kit.json");
            @rmdir($dir);
        }
    }

    public function test_actions_reach_the_manifest_their_services_and_a_contract_test(): void
    {
        $spec = BootboxWebTest::withActions();
        $manifest = \Blutrixx\GeneratorEngine\Targets\Bootbox\ManifestMerger::merge(['key' => 'Tasks', 'menu' => []], $spec, new ArrayModuleResolver);
        $this->assertSame([
            'close' => ['label' => 'Close', 'icon' => 'CircleCheck', 'button' => 'header', 'confirm' => true, 'bulk' => true, 'approval' => true, 'effect' => 'The task is marked Done.'],
            'reassign' => ['label' => 'Reassign', 'button' => 'more', 'fields' => true],
        ], $manifest['actions']);

        $r = (new ApiRenderer($spec, new ArrayModuleResolver))->render();
        $close = $r['once']['Services/TasksCloseService.php'];
        $this->assertStringContainsString('class TasksCloseService extends ModuleAction', $close);
        $this->assertStringContainsString("throw new ApplicationException('This task is already Done.');", $close);
        $this->assertStringContainsString("\$record->update([\$record::statusColumn() => app(WorkflowStatuses::class)->id('task', 'done')]);", $close);
        $reassign = $r['once']['Services/TasksReassignService.php'];
        $this->assertStringContainsString("'assignee_id' => ['required', 'integer', 'exists:users,id', new MemberOfOrganization],", $reassign);
        $this->assertStringContainsString('use App\\Platform\\Rules\\MemberOfOrganization;', $reassign);
        $this->assertStringContainsString('$record->update($data);', $reassign);

        $test = $r['files']['Tests/TasksActionsContractTest.php'];
        $this->assertStringContainsString("foreach (['close', 'reassign'] as \$action)", $test);
        $this->assertStringContainsString("foreach (['reassign' => ['assignee_id']] as \$action => \$fields)", $test);
        $this->assertStringContainsString("foreach (['close'] as \$action)", $test);
        $this->assertArrayNotHasKey('Tests/TasksActionsContractTest.php', (new ApiRenderer(CoreTest::tasks(), new ArrayModuleResolver))->render()['files']);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function badActions(): array
    {
        return [
            'a wizard' => [['go' => ['label' => 'Go', 'wizard' => ['enabled' => true]]], 'wizards are not in the Bootbox target yet'],
            'a page' => [['go' => ['label' => 'Go', 'uiType' => 'page']], 'page actions'],
            'no label' => [['go' => ['icon' => 'X']], 'needs a label'],
            'a field not a column' => [['go' => ['label' => 'Go', 'fields' => [['field' => 'reason']]]], "list the module's own column names"],
            'bulk with fields' => [['go' => ['label' => 'Go', 'fields' => ['name'], 'bulk' => true]], 'a bulk action takes no fields'],
            'an unknown status' => [['go' => ['label' => 'Go', 'status' => 'gone']], "is not a status of the module's workflow"],
            'a snake key' => [['send_now' => ['label' => 'Go']], 'camelCase'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badActions')]
    public function test_the_spec_refuses_what_an_action_cannot_say(array $actions, string $message): void
    {
        $this->expectExceptionMessage($message);
        CoreTest::tasks(['actions' => $actions]);
    }

    public function test_a_document_with_lines_and_a_number(): void
    {
        $spec = CoreTest::tasks([
            'workflow' => ['key' => 'task', 'statuses' => [['code' => 'open', 'label' => 'Open'], ['code' => 'done', 'label' => 'Done']]],
            'number' => ['column' => 'number', 'prefix' => 'TK'],
            'lines' => ['kind' => 'sale', 'item_module' => 'Projects', 'item_price' => 'price', 'editable_status' => 'open'],
        ]);
        $r = (new ApiRenderer($spec, new ArrayModuleResolver))->render();
        $model = $r['slots']['TasksModel.php'];
        $this->assertSame("    use HasDocumentLines, HasDocumentNumber, HasWorkflowStatus;\n", $model['traits']);
        $this->assertStringContainsString("public const LINES = ['model' => TasksLineModel::class, 'key' => 'task_id', 'columns' => ['item_id', 'qty', 'price', 'disc', 'vat'], 'kind' => 'sale', 'prices_include_vat' => false, 'editable_status' => 'open'];", $model['constants']);
        $this->assertStringContainsString("changed only while it is Open. */", $model['constants']);
        $this->assertStringContainsString("public const NUMBER = ['column' => 'number', 'prefix' => 'TK'];", $model['constants']);
        $this->assertStringContainsString("'total' => 'decimal:2'", $model['casts']);
        $this->assertStringContainsString("\$table->string('number', 30)->unique();", $r['slots']['MIGRATION']['columns']);
        $this->assertStringContainsString("'item_id' => ['required', 'integer', 'exists_in_org:projects,id'],", $r['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringContainsString('], false),', $r['slots']['Services/TasksEditService.php']['rules']);
        $this->assertStringContainsString("'lines.item:id,uuid,title'," /* the item module's own label column */ , $r['slots']['Services/TasksViewService.php']['with']);
        $this->assertStringContainsString("'lines' => [['id' => 'l1', 'item_id' => ProjectsModel::factory()", $r['slots']['Tests/TasksTest.php']['fields']);
        $this->assertArrayHasKey('TasksLineModel.php', $r['files']);
        $this->assertArrayHasKey('Migrations/DATE_235959_create_task_lines_table.php', $r['files']);

        // stock: quantities only, no totals, no price rules
        $stock = (new ApiRenderer(CoreTest::tasks(['lines' => ['kind' => 'stock', 'item_module' => 'Projects']]), new ArrayModuleResolver))->render();
        $this->assertStringNotContainsString("'price'", $stock['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringNotContainsString("'total'", $stock['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringNotContainsString('subtotal', $stock['slots']['MIGRATION']['columns']);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function badLines(): array
    {
        return [
            'inline items' => [['inline_items' => [['key' => 'x']]], "declare them with 'lines'"],
            'no item module' => [['lines' => ['kind' => 'sale']], 'lines.item_module'],
            'an unknown kind' => [['lines' => ['kind' => 'rent', 'item_module' => 'Projects']], 'sale, purchase or stock'],
            'an unknown status' => [['lines' => ['item_module' => 'Projects', 'editable_status' => 'gone']], "is not a status of the module's workflow"],
            'lots' => [['lines' => ['item_module' => 'Projects', 'lots' => true]], 'lines.lots is not in the Bootbox target yet'],
            'a total of its own' => [['columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'total', 'type' => 'decimal']], 'lines' => ['item_module' => 'Projects']], 'a priced document keeps its own total'],
            'a number in the columns' => [['number' => ['column' => 'name', 'prefix' => 'X']], 'made by the server'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLines')]
    public function test_the_spec_refuses_what_lines_cannot_say(array $over, string $message): void
    {
        $this->expectExceptionMessage($message);
        CoreTest::tasks($over);
    }

    public function test_a_spec_that_disagrees_with_the_manifest_about_sync_is_refused(): void // P123: sync is the scaffolder's
    {
        $root = sys_get_temp_dir().'/gen-sync-'.uniqid();
        $dir = "{$root}/api/app/Modules/Custom/Work/Tasks";
        mkdir($dir, 0755, true);
        file_put_contents("{$root}/kit.json", '{"generator_contract": 3}');
        $spec = "{$root}/tasks.json";
        $generator = new BootboxGenerator("{$root}/api", new \Blutrixx\GeneratorEngine\Tests\Unit\Core\ArrayModuleResolver, fn () => $this->fail('the skeleton exists'), fn () => null);
        try {
            foreach ([
                [['key' => 'Tasks'], ['sync' => true], 'Tasks is not a sync module'],
                [['key' => 'Tasks', 'sync' => []], [], 'Tasks is a sync module'],
            ] as [$manifest, $extra, $says]) {
                file_put_contents("{$dir}/module.json", json_encode($manifest));
                file_put_contents($spec, json_encode(['module_name' => 'Tasks', 'module_group_name' => 'Work', 'columns' => [['name' => 'name', 'type' => 'string']], 'phone' => true] + $extra));
                try {
                    $generator->generate($spec);
                    $this->fail("generated despite: {$says}");
                } catch (\RuntimeException $e) {
                    $this->assertStringStartsWith($says, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
}
