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
        $this->assertStringContainsString('<ApiSelect v-if="parent?.via !== \'project_id\'" v-model="form.project_id" module="projects" required', $form['fields']);
        $this->assertStringContainsString('<LockedField v-else :label="t(\'tasks.form.project\')" :value="parent?.label ?? \'\'" />', $form['fields']);
        $this->assertStringContainsString('<ApiSelect v-if="parent?.via !== \'assignee_id\'" v-model="form.assignee_id" module="users" :label', $form['fields']);
        $this->assertSame("  return form.name.trim().length > 0 && (props.parent?.via === 'project_id' || form.project_id !== null)\n", $form['valid']);
        $this->assertStringContainsString("...(form.task_status_id !== undefined ? { task_status_id: Number(form.task_status_id) } : {}),", $form['body']);

        $this->assertStringContainsString("counts: async () =>", $r['slots']['api.ts']['api']);
        $this->assertStringContainsString("export interface Link", $r['slots']['api.ts']['types']);
        $this->assertStringContainsString("TONES[s.tone].cls", $r['slots']['pages/TasksDetailPage.vue']['header']);

        $this->assertSame('Kazi', $r['locales']['sw']['title']);
        $this->assertSame('Status', $r['locales']['en']['columns.status']);
        $this->assertArrayHasKey('columns.status', $r['locales']['sw_fallback']); // no label_sw given: written only where missing
    }

    public function test_a_child_declares_its_tab_on_the_parent_on_both_sides(): void
    {
        $spec = CoreTest::tasks(['tab_on' => ['Projects' => ['key' => 'tasks', 'via' => 'project_id', 'tab' => 'Tasks', 'tab_sw' => 'Kazi']]]);
        $r = (new WebRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString("'Projects.tasks': true", $r['slots']['module.ts']['declarations']);
        $this->assertStringContainsString("{ on: 'Projects.tasks', label: 'tasks.tabOn.tasks', permission: 'Tasks.list', child: { via: 'project_id', column: 'project' }, component: () => import('@/modules/tasks/pages/TasksPage.vue') },", $r['slots']['module.ts']['extra-tabs']);
        $this->assertSame(['Tasks', 'Kazi'], [$r['locales']['en']['tabOn.tasks'], $r['locales']['sw']['tabOn.tasks']]);
        $manifest = \Blutrixx\GeneratorEngine\Targets\Bootbox\ManifestMerger::merge(['key' => 'Tasks', 'menu' => []], $spec, new ArrayModuleResolver);
        $this->assertSame(['Projects' => ['key' => 'tasks', 'via' => 'project_id', 'tab' => 'Tasks']], $manifest['tab_on']);
        $this->assertSame(['declarations' => '', 'extra-tabs' => '', 'actions' => '', 'tabs' => ''], (new WebRenderer(CoreTest::tasks(), new ArrayModuleResolver))->render()['slots']['module.ts']);

        $this->expectExceptionMessage('must be a column pointing at Projects');
        CoreTest::tasks(['tab_on' => ['Projects' => ['key' => 'tasks', 'via' => 'assignee_id', 'tab' => 'Tasks']]]);
    }

    public function test_actions_reach_module_ts_their_dialogs_and_the_locales(): void
    {
        $r = (new WebRenderer(self::withActions(), new ArrayModuleResolver))->render();
        $this->assertSame("  actions: [\n"
            ."    { key: 'close', label: 'tasks.actions.close.label', icon: 'CircleCheck', button: 'header', confirm: true, bulk: true, approval: true },\n"
            ."    { key: 'reassign', label: 'tasks.actions.reassign.label', button: 'more', component: () => import('@/modules/tasks/pages/TasksReassignDialog.vue') },\n"
            ."  ],\n", $r['slots']['module.ts']['actions']);
        $this->assertSame(['pages/TasksReassignDialog.vue'], array_keys($r['once']));
        $dialog = $r['once']['pages/TasksReassignDialog.vue'];
        $this->assertStringContainsString('<ApiSelect v-model="form.assignee_id" module="users" required', $dialog); // required here, nullable on the record
        $this->assertStringNotContainsString('parent', $dialog);
        $this->assertStringContainsString("assignee_id: (props.row.assignee_id ?? null) as number | null,", $dialog);
        $this->assertStringContainsString('const valid = () => form.assignee_id !== null', $dialog);
        $this->assertSame(['Close', 'Funga'], [$r['locales']['en']['actions.close.label'], $r['locales']['sw']['actions.close.label']]);
        $this->assertSame('Reassign', $r['locales']['sw_fallback']['actions.reassign.label']);
    }

    public static function withActions(array $over = []): \Blutrixx\GeneratorEngine\Core\ModuleSpec
    {
        return CoreTest::tasks(['actions' => self::ACTIONS, 'workflow' => ['key' => 'task', 'statuses' => [['code' => 'open', 'label' => 'Open'], ['code' => 'done', 'label' => 'Done']]]] + $over);
    }

    public const ACTIONS = [
        'close' => ['label' => 'Close', 'label_sw' => 'Funga', 'icon' => 'CircleCheck', 'button' => 'header', 'confirm' => true, 'bulk' => true, 'approval' => true, 'effect' => 'The task is marked Done.', 'status' => 'done'],
        'reassign' => ['label' => 'Reassign', 'fields' => ['assignee_id']],
    ];

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
