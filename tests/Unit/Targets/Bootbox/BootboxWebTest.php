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
        // the tab counts follow each load once (watching the rows asked twice a visit, with the empty rows first, and a
        // quick walk through the lists passed the 60 a minute), and a refused count is no uncaught error
        $this->assertStringContainsString('watch(LS.updatedAt, () => void tasksApi.counts()', $page['tabs']);
        $this->assertStringContainsString('.catch(() => undefined))', $page['tabs']);
        $this->assertStringNotContainsString('watch(LS.rows', $page['tabs']);

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

    public function test_a_link_carries_the_label_column_of_the_module_it_points_at(): void
    {
        $spec = CoreTest::tasks(['columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'project_id', 'type' => 'foreignId', 'relatedModule' => 'Projects', 'nullable' => true]], 'scope' => 'organisation', 'workflow' => null]);
        $types = (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots']['api.ts']['types'];
        $this->assertStringContainsString("  name: string\n  title: string\n}", $types); // Projects are named by title
    }

    public function test_a_json_column_is_shown_never_typed_into(): void // P125
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'results', 'type' => 'json', 'nullable' => true]]]);
        $r = (new WebRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringNotContainsString('results', $r['slots']['pages/TasksFormDialog.vue']['fields']);
        $this->assertStringNotContainsString('results', $r['slots']['pages/TasksFormDialog.vue']['body']);
        $this->assertStringNotContainsString('results', $r['slots']['pages/TasksPage.vue']['columns']);
        $this->assertStringContainsString('<CodeBlock :value="rec.results" :label="t(\'tasks.columns.results\')" />', $r['slots']['pages/TasksDetailPage.vue']['overview-extra']);
        $this->assertStringContainsString("  results: unknown | null\n", $r['slots']['api.ts']['row']);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString("\$table->json('results')->nullable();", $api['slots']['MIGRATION']['columns']);
        $this->assertStringContainsString("'results' => ['nullable', 'array'],", $api['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringContainsString("'results' => 'array'", $api['slots']['TasksModel.php']['casts']);
        $phone = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringNotContainsString('results', $phone['slots']['screens/TasksSheet.vue']['fields']);
    }

    public function test_numbers_read_with_thousands_and_a_decimal_column_s_places(): void
    {
        // found rebuilding the V1 demo: a cost price read 2500.00 on the list, the Overview and the phone
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'phone' => true, 'columns' => [
            ['name' => 'name', 'type' => 'string'], ['name' => 'cost_price', 'type' => 'decimal', 'scale' => 2], ['name' => 'stock', 'type' => 'integer'],
        ]]);
        $web = (new WebRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString('text: (r) => formatAmount(r.cost_price, 2) },', $web['slots']['pages/TasksPage.vue']['columns']);
        $this->assertStringContainsString('text: (r) => formatAmount(r.stock) },', $web['slots']['pages/TasksPage.vue']['columns']);
        $this->assertSame(['formatAmount'], $web['imports']['pages/TasksPage.vue']['@/app/lib/numbers']);
        $this->assertStringContainsString("value: formatAmount(r.cost_price, 2) || '—' },", $web['slots']['pages/TasksDetailPage.vue']['overview']);
        $this->assertSame(['formatAmount'], $web['imports']['pages/TasksDetailPage.vue']['@/app/lib/numbers']);
        $phone = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString(":v=\"formatAmount(rec?.cost_price, 2) || '–'\"", $phone['slots']['screens/TasksDetailPage.vue']['facts']);
        $this->assertSame(['formatAmount'], $phone['imports']['screens/TasksDetailPage.vue']['@/app/lib/numbers']);
    }

    public function test_a_module_without_a_label_is_named_by_its_head_noun_in_sentence_case(): void
    {
        // found rebuilding the V1 demo: UnitsOfMeasure's menu said "Units Of Measures" (the plural made plural again)
        $spec = fn (string $key, array $more = []) => \Blutrixx\GeneratorEngine\Core\ModuleSpec::fromArray(['module_name' => $key, 'module_group_name' => 'Demo', 'columns' => [['name' => 'name', 'type' => 'string']]] + $more);
        $this->assertSame(['Unit of measure', 'Units of measure'], [$spec('UnitsOfMeasure')->recordLabel(), $spec('UnitsOfMeasure')->recordLabels()]);
        $this->assertSame(['Tax rate', 'Tax rates'], [$spec('TaxRates')->recordLabel(), $spec('TaxRates')->recordLabels()]);
        $this->assertSame(['Item category', 'Item categories'], [$spec('ItemCategories')->recordLabel(), $spec('ItemCategories')->recordLabels()]);
        $this->assertSame('Bills of lading', $spec('BillsOfLading', ['label' => 'Bill of lading'])->recordLabels(), "a label's own head noun");
    }

    public function test_a_column_s_default_reaches_the_migration_and_a_new_record_s_forms(): void
    {
        // found building the Project Generator's own settings: every boolean was default(false), other defaults were dropped
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'phone' => true, 'columns' => [
            ['name' => 'name', 'type' => 'string'],
            ['name' => 'mode', 'type' => 'enum', 'values' => ['connected', 'sync'], 'default' => 'connected'],
            ['name' => 'reports', 'type' => 'boolean', 'default' => 'true'],
            ['name' => 'archived', 'type' => 'boolean', 'default' => 'false'],
            ['name' => 'accent', 'type' => 'string', 'length' => 20, 'default' => 'blue'],
            ['name' => 'sort_order', 'type' => 'integer', 'default' => '0'],
            ['name' => 'total', 'type' => 'decimal', 'default' => '0'],
        ]]);
        $m = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render()['slots']['MIGRATION']['columns'];
        $this->assertMatchesRegularExpression("/table->enum\\('mode', .*?'sync',\\s*\\)\\)->default\\('connected'\\);/s", $m);
        $this->assertStringContainsString("\$table->boolean('reports')->default(true);", $m);
        $this->assertStringContainsString("\$table->boolean('archived')->default(false);", $m);
        $this->assertStringContainsString("\$table->string('accent', 20)->default('blue');", $m);
        $this->assertStringContainsString("\$table->integer('sort_order')->default(0);", $m);
        $this->assertStringContainsString("\$table->decimal('total', 12, 2)->default(0);", $m);

        // the factory makes a record as the form would start it (not a made-up accent or a random switch)
        $factory = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render()['slots']['TasksFactory.php']['definition'];
        $this->assertStringContainsString("'mode' => 'connected',", $factory);
        $this->assertStringContainsString("'reports' => true,", $factory);
        $this->assertStringContainsString("'accent' => 'blue',", $factory);
        $this->assertStringContainsString("'sort_order' => 0,", $factory);

        $state = (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots']['pages/TasksFormDialog.vue']['state'];
        $this->assertStringContainsString("mode: props.row?.mode ?? 'connected',", $state);
        $this->assertStringContainsString('reports: props.row?.reports ?? true,', $state);
        $this->assertStringContainsString('archived: props.row?.archived ?? false,', $state);
        $this->assertStringContainsString("accent: props.row?.accent ?? 'blue',", $state);
        $this->assertStringContainsString("sort_order: props.row?.sort_order != null ? String(props.row.sort_order) : '0',", $state);
        // an enum that starts on its default is never empty: no check against '' (TypeScript refuses one its type cannot meet)
        $this->assertStringNotContainsString("form.mode !== ''", (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots']['pages/TasksFormDialog.vue']['valid']);

        try {
            CoreTest::tasks(['workflow' => null, 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'mode', 'type' => 'enum', 'values' => ['a', 'b'], 'default' => 'c']]]);
            $this->fail('an enum default outside its values is refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("mode's default 'c' is not one of its values", $e->getMessage());
        }

        $reset = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer($spec, new ArrayModuleResolver))->render()['slots']['screens/TasksSheet.vue']['reset'];
        $this->assertStringContainsString("mode.value = props.row?.mode ?? 'connected'", $reset);
        $this->assertStringContainsString('reports.value = props.row?.reports ?? true', $reset);
        $this->assertStringContainsString("accent.value = props.row?.accent ?? 'blue'", $reset);
    }

    public function test_a_media_column_is_a_file_in_the_library_everywhere(): void // P131
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'phone' => true, 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'photo', 'type' => 'media', 'nullable' => true]]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString("\$table->unsignedBigInteger('photo_media_id')->nullable()->index(); // P131", $api['MIGRATION']['columns']);
        $this->assertStringContainsString("public const MEDIA = ['photo' => 'image'];", $api['TasksModel.php']['constants']);
        $this->assertStringContainsString("'photo' => ['nullable', 'string', 'exists_in_org:media,uuid'],", $api['Services/TasksCreateService.php']['rules']);
        $this->assertStringNotContainsString('photo', $api['Tests/TasksTest.php']['fields'] ?? '');
        $this->assertStringNotContainsString('photo', $api['TasksFactory.php']['definition'] ?? '');
        $manifest = \Blutrixx\GeneratorEngine\Targets\Bootbox\ManifestMerger::merge(['key' => 'Tasks', 'menu' => []], $spec, new ArrayModuleResolver);
        $this->assertSame(['tasks' => ['photo_media_id']], $manifest['media_references']);

        $web = (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString('<FilePicker v-model="form.photo" accept="image"', $web['pages/TasksFormDialog.vue']['fields']);
        $this->assertStringContainsString('photo: form.photo || null,', $web['pages/TasksFormDialog.vue']['body']);
        $this->assertStringContainsString('<MediaValue :uuid="rec.photo" />', $web['pages/TasksDetailPage.vue']['overview-extra']);
        $this->assertStringContainsString("  photo: string | null\n", $web['api.ts']['row']);
        $this->assertStringNotContainsString('photo', $web['pages/TasksPage.vue']['columns']);

        $phone = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString('<MediaField v-model="photo"', $phone['screens/TasksSheet.vue']['fields']);
        $this->assertStringContainsString('<MediaView v-if="rec?.photo" :value="rec.photo"', $phone['screens/TasksDetailPage.vue']['facts']);
    }

    public function test_a_media_column_must_be_nullable_and_named_for_what_it_holds(): void // P131
    {
        foreach ([['name' => 'photo', 'type' => 'media'], ['name' => 'photo_id', 'type' => 'media', 'nullable' => true], ['name' => 'photo', 'type' => 'media', 'nullable' => true, 'accept' => 'video']] as $bad) {
            try {
                CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'columns' => [['name' => 'name', 'type' => 'string'], $bad]]);
                $this->fail('refused: '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('(media)', $e->getMessage().'(media)');
            }
        }
    }

    public function test_a_set_of_columns_is_unique_together(): void // P123 (2026-11-21)
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'unique' => [['item_id', 'tier']], 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'item_id', 'type' => 'foreignId', 'relatedModule' => 'Projects'], ['name' => 'tier', 'type' => 'string', 'nullable' => true]]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString("\$table->unique(['organization_id', 'item_id', 'tier']);", $api['MIGRATION']['columns']);
        $this->assertStringContainsString("new UniqueTogether('tasks', ['item_id', 'tier'], \$data)", $api['Services/TasksCreateService.php']['rules']);
        $this->assertSame(2, substr_count($api['Services/TasksEditService.php']['rules'], "new UniqueTogether('tasks', ['item_id', 'tier'], \$data, \$id)"), 'on each column of the set');
        $this->expectException(\InvalidArgumentException::class);
        CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'unique' => [['tier']], 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'tier', 'type' => 'string']]]);
    }

    public function test_a_module_named_by_another_column_tells_its_pickers(): void
    {
        $titled = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'label_column' => 'title', 'columns' => [['name' => 'title', 'type' => 'string']]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($titled, new ArrayModuleResolver))->render();
        $this->assertStringContainsString("public const SELECT_LABEL = 'title';", $api['slots']['TasksModel.php']['constants']);
        $named = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'columns' => [['name' => 'name', 'type' => 'string']]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($named, new ArrayModuleResolver))->render();
        $this->assertStringNotContainsString('SELECT_LABEL', $api['slots']['TasksModel.php']['constants'] ?? '');
    }

    public function test_a_sync_module_s_offline_record_carries_its_required_fields(): void // P123 contract 3
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'phone' => true, 'sync' => true, 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'code', 'type' => 'string', 'length' => 10]]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertSame($api['slots']['Tests/TasksTest.php']['fields'], $api['slots']['Tests/TasksSyncTest.php']['fields']);
        $this->assertStringContainsString("'code' =>", $api['slots']['Tests/TasksSyncTest.php']['fields']);
        $plain = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'columns' => [['name' => 'name', 'type' => 'string']]]);
        $this->assertArrayNotHasKey('Tests/TasksSyncTest.php', (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($plain, new ArrayModuleResolver))->render()['slots']);
    }

    public function test_a_required_json_column_s_samples_pass_required(): void // P125
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'spec', 'type' => 'json']]]);
        $api = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\ApiRenderer($spec, new ArrayModuleResolver))->render();
        $this->assertStringContainsString("'spec' => ['required', 'array'],", $api['slots']['Services/TasksCreateService.php']['rules']);
        $this->assertStringContainsString("'spec' => ['example' => true],", $api['slots']['Tests/TasksTest.php']['fields']);
        $this->assertStringContainsString("'spec' => ['example' => true],", $api['slots']['TasksFactory.php']['definition']);
    }

    public function test_a_two_word_relation_is_read_under_the_key_the_api_sends(): void
    {
        $spec = CoreTest::tasks(['workflow' => null, 'scope' => 'organisation', 'phone' => true, 'columns' => [['name' => 'name', 'type' => 'string'], ['name' => 'project_id', 'type' => 'foreignId', 'relatedModule' => 'Projects', 'nullable' => true], ['name' => 'parent_location_id', 'type' => 'foreignId', 'relatedModule' => 'Locations', 'nullable' => true]]]);
        $web = (new WebRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString("  parent_location?: Link | null\n", $web['api.ts']['row'], 'Laravel sends parentLocation as parent_location');
        $this->assertStringContainsString("{ key: 'parentLocation', label: t('tasks.columns.parentLocation')", $web['pages/TasksPage.vue']['columns'], 'the column key and its label keep the relation name');
        $this->assertStringContainsString('text: (r) => r.parent_location?.', $web['pages/TasksPage.vue']['columns']);
        $this->assertStringContainsString('value: r.parent_location?.', $web['pages/TasksDetailPage.vue']['overview']);
        $this->assertStringNotContainsString('r.parentLocation', $web['pages/TasksDetailPage.vue']['overview']);
        $phone = (new \Blutrixx\GeneratorEngine\Targets\Bootbox\PhoneRenderer($spec, new ArrayModuleResolver))->render()['slots'];
        $this->assertStringContainsString('parent_location?: Link | null', $phone['screens/types.ts']['row']);
        $this->assertStringContainsString('row?.parent_location?.', $phone['screens/TasksSheet.vue']['fields']);
    }
}
