<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Targets\Bootbox\LocaleMerger;
use Blutrixx\GeneratorEngine\Targets\Bootbox\TsImports;
use Blutrixx\GeneratorEngine\Targets\Bootbox\WebRenderer;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\ArrayModuleResolver;
use Blutrixx\GeneratorEngine\Tests\Unit\Core\CoreTest;
use PHPUnit\Framework\TestCase;

/** The Bootbox target's web side (P123): the imports slot, locale keys, and the rendered bodies. */
class BootboxWebTest extends TestCase
{
    public function test_the_imports_slot_leaves_out_names_the_file_already_imports_and_sorts_by_module(): void
    {
        $file = "<script setup lang=\"ts\">\nimport { ApiSelect } from '@/components/app/api-select'\nimport { computed } from 'vue'\nimport type { X } from './x'\n// @generated:imports begin\nimport { Old } from 'old'\n// @generated:imports end\n";
        $slot = TsImports::slot($file, ['vue' => ['computed', 'ref'], '@/components/app/api-select' => ['ApiSelect'], '@/a' => ['type Y', 'default:Z']]);
        $this->assertSame("import Z from '@/a'\nimport { type Y } from '@/a'\nimport { ref } from 'vue'\n", $slot);
        $this->assertSame(['ApiSelect', 'computed', 'X', 'Old'], TsImports::importedNames($file));
    }

    public function test_locale_keys_are_written_and_a_fallback_never_replaces_a_translation(): void
    {
        $sw = ['form' => ['name' => 'Jina'], 'title' => 'Tasks'];
        $out = LocaleMerger::merge($sw, ['form.status' => 'Hali', 'title' => 'Kazi'], ['form.name' => 'Name', 'form.due' => 'Due']);
        $this->assertSame(['form' => ['name' => 'Jina', 'status' => 'Hali', 'due' => 'Due'], 'title' => 'Kazi'], $out);
    }

    public function test_bodies_for_a_location_scoped_module_with_a_workflow(): void
    {
        $r = (new WebRenderer(CoreTest::tasks(['labels_sw' => 'Kazi']), new ArrayModuleResolver))->render();
        $page = $r['slots']['pages/TasksPage.vue'];
        $this->assertStringContainsString("const quickFilter = (): string => 'task_status_id'", $page['tabs']);
        $this->assertStringContainsString("<StatusPill workflow=\"task\" :code=\"statuses.byId(row.task_status_id)?.code\" />", $page['cells']);
        $this->assertStringContainsString("module=\"Projects\" :value=\"{ id: row.project.id, uuid: row.project.uuid, label: row.project.title }\"", $page['cells']);
        $this->assertStringContainsString("{ key: 'due', label: t('tasks.columns.due'), priority: 2", $page['columns']);
        $this->assertSame(['ref', 'watch'], $r['imports']['pages/TasksPage.vue']['vue']);

        $form = $r['slots']['pages/TasksFormDialog.vue'];
        $this->assertStringContainsString('<ApiSelect v-model="form.project_id" module="projects" required', $form['fields']);
        $this->assertStringContainsString('<ApiSelect v-model="form.assignee_id" module="users" :label', $form['fields']);
        $this->assertSame("  return form.name.trim().length > 0 && form.project_id !== null\n", $form['valid']);
        $this->assertStringContainsString("...(form.task_status_id !== undefined ? { task_status_id: Number(form.task_status_id) } : {}),", $form['body']);

        $this->assertStringContainsString("counts: async () =>", $r['slots']['api.ts']['api']);
        $this->assertStringContainsString("export interface Link", $r['slots']['api.ts']['types']);
        $this->assertStringContainsString("TONES[s.tone].cls", $r['slots']['pages/TasksDetailPage.vue']['header']);

        $this->assertSame('Kazi', $r['locales']['sw']['title']);
        $this->assertSame('Status', $r['locales']['en']['columns.status']);
        $this->assertArrayHasKey('columns.status', $r['locales']['sw_fallback']); // no label_sw given: written only where missing
    }

    public function test_a_plain_organisation_module_has_no_tabs_links_or_counts(): void
    {
        $spec = CoreTest::tasks(['scope' => 'organisation', 'workflow' => null, 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'done', 'type' => 'boolean']]]);
        $r = (new WebRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertSame("const quickFilter = (): string => ''\nconst quickTabs = (): QuickTab[] => []\n", $r['slots']['pages/TasksPage.vue']['tabs']);
        $this->assertSame('', $r['slots']['api.ts']['types']);
        $this->assertSame('', $r['slots']['api.ts']['api']);
        $this->assertSame([], $r['imports']['pages/TasksPage.vue']);
        $form = $r['slots']['pages/TasksFormDialog.vue'];
        $this->assertStringContainsString('<Switch v-model="form.done" />', $form['fields']);
        $this->assertStringContainsString("done: props.row?.done ?? false,", $form['state']);
        $this->assertStringNotContainsString('form.done.trim()', $form['body']);
        $this->assertSame(['Switch'], $r['imports']['pages/TasksFormDialog.vue']['@/components/ui/switch']);
    }
}
