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
            file_put_contents("{$dir}/kit.json", '{"generator_contract": 1}');
            $this->assertSame(1, BootboxGenerator::contract("{$dir}/kit.json"));
            foreach (['{"generator_contract": 2}', '{}'] as $kit) {
                file_put_contents("{$dir}/kit.json", $kit);
                try {
                    BootboxGenerator::contract("{$dir}/kit.json");
                    $this->fail("accepted {$kit}");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('this generator writes for 1', $e->getMessage());
                }
            }
        } finally {
            @unlink("{$dir}/kit.json");
            @rmdir($dir);
        }
    }
}
