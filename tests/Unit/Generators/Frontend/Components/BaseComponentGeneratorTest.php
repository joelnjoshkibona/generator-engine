<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Regression coverage for BaseComponentGenerator::generateColumnsFromListFields().
 *
 * Bug 1 (fixed in v2.10.4): the list/page.stub template always renders a
 * <template #cell-actions="{ row }"> slot with View/Edit/Delete buttons, but
 * generateColumnsFromListFields() never emitted a matching
 * { key: "actions", ... } entry in the generated columns array — so the
 * buttons silently never rendered in any freshly scaffolded module (they
 * only worked in modules like Users/LocationTypes/UserLocations where a
 * human had hand-added the column afterwards).
 *
 * Fix: the method appends { key: "actions", label: "", width: 120, align: 'right' }
 * after the ID column, unless a field named "actions" was already supplied
 * by the caller (in which case the caller's own entry is left as-is and
 * nothing is double-added).
 *
 * Bug 2 (fixed in v2.10.7): the ID column was emitted LAST (right before
 * actions) and hardcoded `sortable: false`, so every generated list buried
 * its ID column at the end and it could never be sorted or filtered — unlike
 * every other column, which gets `sortable` from its field config.
 *
 * Fix: the ID column entry is now built and appended to $columns FIRST
 * (before any schema-derived field), and is always `sortable: true`. The
 * matching backend-side fix (BaseServiceGenerator::generateFilterableFields()
 * / generateSortableFields() / generateFilterFields()) makes "id" backend
 * sortable+filterable and gives it a frontend filter control, and makes
 * "uuid" backend-filterable only — it is still never added to this columns
 * array (see test_uuid_id_type_still_appends_actions_column_after_uuid_id_column
 * below: only ONE id-type column — "uuid" when id_type is 'uuid', "id"
 * otherwise — ever appears here).
 *
 * Bug 3 (fixed 2026-07-23, alongside a manual pattern revision on Users):
 * relation (FK-derived) list columns were emitted identically to any other
 * text column, with no way to keep them out of the default view — the only
 * way to get Users' now-current, decluttered list shape was to hand-edit the
 * generated file afterwards (as just happened to `role_id`). Separately, the
 * auto-appended actions column always emitted `label: ""`, untitled, while
 * Users' actions column carries a real `t('common.actions')` title.
 *
 * Fix: IntrospectionToConfig::buildFrontendListFields() now threads an
 * `isFk` flag through each field's config entry; generateColumnsFromListFields()
 * appends `, defaultVisible: false` to a non-primary field's column entry
 * when that flag is set (the column still exists — selectable via the list's
 * column picker — it just isn't shown by default), and the auto-appended
 * actions column now emits `label: t('common.actions')` instead of `""`.
 *
 * Bug 4 (fixed 2026-07-26): a real running app showed a generated list with a
 * meaningless raw internal "ID" column and every FK column hidden behind
 * `defaultVisible: false` — so RelatedRecordLink, which lives inside those FK
 * columns, never rendered. Bug 3's `defaultVisible: false` rule for `isFk`
 * fields cited Users' list as its model, but Users does NOT hide its relation
 * column (`status_id` is visible; only secondary scalars `phone` and
 * `last_logged_in_at` are hidden) — the comment misread its own reference.
 *
 * Fix: generateColumnsFromListFields() no longer emits an ID column at all
 * (matching Users/LocationTypes/Locations, none of which show one), and no
 * longer appends `defaultVisible: false` for `isFk` fields — FK columns are
 * now visible by default like every other column, same as Locations'
 * location_type_id/parent_id and Users' status_id.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateColumnsFromListFields()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildFrontendListFields()
 */
class BaseComponentGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Needed since writeInlineItemsWrapperComponent() does real file I/O
        // (PathManager::getFrontendModulePath() throws without a project
        // root configured) -- every other test in this class is a pure
        // string builder and never touches PathManager, so this is harmless
        // overhead for them.
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-basecomponentgen-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        PathManager::setModuleRegistry([]);
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Build a bare BaseComponentGenerator instance without running its
     * constructor, since BaseGenerator::__construct() calls
     * PathManager::ensureOutputDirectories() which requires a booted
     * Laravel application (base_path(), config(), etc.) that isn't
     * available in a plain PHPUnit run.
     *
     * @param array<string, mixed> $config
     */
    private function makeGenerator(string $moduleName = 'TestModule', string $moduleGroup = 'Core', array $config = []): TestBaseComponentGenerator
    {
        $ref = new ReflectionClass(TestBaseComponentGenerator::class);
        /** @var TestBaseComponentGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $this->setProtectedProperty($generator, 'moduleName', $moduleName);
        $this->setProtectedProperty($generator, 'moduleGroup', $moduleGroup);
        $this->setProtectedProperty($generator, 'moduleSubGroup', null);
        $this->setProtectedProperty($generator, 'config', $config);

        return $generator;
    }

    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /** @return string[] */
    private function splitColumns(string $result): array
    {
        return explode(",\n\t", $result);
    }

    public function test_no_id_column_is_emitted_and_actions_stays_last(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'parent_id', 'sortable' => true],
            ['key' => 'description', 'sortable' => false],
            ['key' => 'status_id', 'sortable' => true],
        ]);

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 150 },\n"
            . "\t{ key: \"parent_id\", label: t('test-module.col_parent_id'), sortable: true },\n"
            . "\t{ key: \"description\", label: t('test-module.col_description'), sortable: false },\n"
            . "\t{ key: \"status_id\", label: t('test-module.col_status_id'), sortable: true },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(5, $columns);
        // No visible ID column anywhere in the output.
        $this->assertStringNotContainsString('label: "ID"', $result);
        $this->assertStringNotContainsString('key: "id"', $result);
        // Primary field must be FIRST.
        $this->assertSame("{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 150 }", $columns[0]);
        // Actions column must still be LAST.
        $this->assertSame("{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }", end($columns));
    }

    public function test_zero_fields_still_yields_only_actions_without_crashing(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([]);

        $expected = "{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(1, $columns, 'Zero fields should still yield exactly [actions column], with no ID column.');
    }

    public function test_existing_actions_field_is_not_duplicated(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'actions', 'sortable' => false],
        ]);

        // Exactly one "actions" key in the output — the caller-supplied one,
        // rendered through the normal field loop (not the special
        // auto-appended entry, which carries `align: 'right'`).
        $this->assertSame(1, substr_count($result, 'key: "actions"'));
        $this->assertStringNotContainsString("align: 'right'", $result);

        $columns = $this->splitColumns($result);
        $this->assertCount(2, $columns, 'No extra column should be appended when "actions" is already a supplied field.');

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 150 },\n"
            . "\t{ key: \"actions\", label: t('test-module.col_actions'), sortable: false }";

        $this->assertSame($expected, $result);
    }

    public function test_uuid_id_type_emits_no_id_column_and_actions_last(): void
    {
        $generator = $this->makeGenerator(config: ['id_type' => 'uuid']);

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'title', 'sortable' => true],
        ]);

        $expected = "{ key: \"title\", label: t('test-module.col_title'), sortable: true, fixed: true, width: 150 },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString('label: "ID"', $result);
        // "uuid" must never appear as a column entry — id_type only affects
        // the backend-filterable id-type, never the frontend column array.
        $this->assertSame(0, substr_count($result, 'key: "uuid"'));
    }

    // ─── FK/relation columns visible by default (Bug 4, 2026-07-26) ─────────

    public function test_fk_field_has_no_default_visible_key(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'role_id', 'sortable' => true, 'isFk' => true],
        ]);

        $expected = "{ key: \"name\", label: t('test-module.col_name'), sortable: true, fixed: true, width: 150 },\n"
            . "\t{ key: \"role_id\", label: t('test-module.col_role_id'), sortable: true },\n"
            . "\t{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";

        $this->assertSame($expected, $result);
        // FK columns are visible by default — no defaultVisible key at all —
        // so RelatedRecordLink (which lives inside them) actually renders.
        $this->assertStringNotContainsString('defaultVisible', $result);
    }

    public function test_non_fk_field_has_no_default_visible_key(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'name', 'sortable' => true],
            ['key' => 'description', 'sortable' => true, 'isFk' => false],
        ]);

        // Non-FK columns are unaffected — no defaultVisible key at all, so
        // existing generated modules' column shape doesn't change for the
        // common (non-relation) case.
        $this->assertStringNotContainsString('defaultVisible', $result);
    }

    public function test_fk_field_as_primary_column_stays_visible(): void
    {
        // Edge case: detectPrimaryField() (IntrospectionToConfig) explicitly
        // prefers a non-FK string column as primary and should never pick an
        // FK column unless literally nothing else exists. If a caller does
        // pass an FK field as the primary key anyway, it must not be hidden —
        // the primary column is the one thing a list can't function without.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateColumnsFromListFields([
            ['key' => 'category_id', 'sortable' => true, 'isFk' => true],
        ], primaryKey: 'category_id');

        $this->assertStringContainsString(
            '{ key: "category_id", label: t(\'test-module.col_category_id\'), sortable: true, fixed: true, width: 150 }',
            $result
        );
        $this->assertStringNotContainsString('defaultVisible', $result);
    }

    // ─── Relation data-path snake_case normalization (v2.10.9) ──────────────
    //
    // Bug: mapViewFieldsToInformationFields() built a foreignKey field's
    // "dataPath" straight from the config's raw "data" string (e.g.
    // "itemCategory?.name" -> dataPath "item_category.name" was NEVER
    // produced; it stayed "itemCategory.name"). Eloquent's relationsToArray()
    // snake-cases relation keys in the actual JSON response regardless of the
    // camelCase relation method name ("itemCategory()" -> "item_category" in
    // JSON), so the generated Overview page referenced a key the real API
    // response never has and silently rendered "N/A".
    //
    // Fix: the relationship segment of the path is now run through
    // Str::snake() before being used to build both "key" and "dataPath".
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::mapViewFieldsToInformationFields()
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateInformationSection()

    public function test_relation_data_path_is_snake_cased_for_multiword_camel_case_relation(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'itemCategory?.name', 'title' => 'Parent'],
        ]);

        $this->assertCount(1, $mapped);
        $this->assertSame('item_category.name', $mapped[0]['dataPath']);
        $this->assertSame('item_category', $mapped[0]['key']);
    }

    public function test_overview_page_relation_field_renders_snake_cased_data_path(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'itemCategory?.name', 'title' => 'Parent'],
        ]);

        $result = $generator->callGenerateInformationSection('Overview', 'InfoIcon', $mapped);

        $this->assertStringContainsString('data?.item_category?.name', $result);
        $this->assertStringNotContainsString('itemCategory', $result);
    }

    public function test_already_snake_case_relation_data_path_is_unaffected(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'district?.name', 'title' => 'District'],
        ]);

        $this->assertSame('district.name', $mapped[0]['dataPath']);
    }

    // ─── Overview info-groups: side-by-side divided columns (2026-08-02) ────
    //
    // generateInformationSection() gained an optional 4th $groups parameter:
    // ['fields' => [...]] per column, rendering as ONE Card with N divided
    // columns instead of the default single stacked field list -- matching
    // ONGEZA_PRO_SYSTEM's BudgetExpensesDetailsOverviewPage.vue reference
    // (the screenshot that motivated this). Deliberately additive: omitting
    // $groups (the default, `[]`) must produce byte-identical output to
    // before this parameter existed -- see the two tests below.

    public function test_generate_information_section_without_groups_is_byte_for_byte_unchanged(): void
    {
        $generator = $this->makeGenerator();

        $fields = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
        ];

        $withoutGroupsArg = $generator->callGenerateInformationSection('Overview', 'InfoIcon', $fields);
        $withExplicitEmptyGroups = $generator->callGenerateInformationSection('Overview', 'InfoIcon', $fields, []);

        $this->assertSame($withoutGroupsArg, $withExplicitEmptyGroups);
        $this->assertStringContainsString('<div class="grid grid-cols-1 md:grid-cols-2">', $withoutGroupsArg);
        $this->assertStringNotContainsString('divide-x', $withoutGroupsArg);
    }

    public function test_generate_information_section_with_groups_renders_one_card_with_divided_columns(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateInformationSection('Overview', 'InfoIcon', [], [
            ['fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text']]],
            ['fields' => [['key' => 'amount', 'label' => 'Amount', 'type' => 'text']]],
            ['fields' => [['key' => 'is_paid', 'label' => 'Paid', 'type' => 'boolean']]],
        ]);

        // Exactly one Card, not one per group (avoid matching "<CardContent").
        $this->assertSame(1, substr_count($result, '<Card class='));

        // 3 groups -> 3-column grid with dividers, collapsing to 1 column on mobile.
        $this->assertStringContainsString('<div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x">', $result);

        // Each group's field renders using the exact same row markup the
        // non-grouped path uses (shared via generateInformationRows()).
        $this->assertStringContainsString('{{ data?.reference || \'N/A\' }}', $result);
        $this->assertStringContainsString('{{ data?.amount || \'N/A\' }}', $result);
        $this->assertStringContainsString("data?.is_paid ? 'Yes' : 'No'", $result);
    }

    public function test_generate_view_sections_threads_groups_key_from_config_into_information_section(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateViewSections([
            'sections' => [
                [
                    'key' => 'information',
                    'title' => 'Overview',
                    'groups' => [
                        ['fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text']]],
                        ['fields' => [['key' => 'amount', 'label' => 'Amount', 'type' => 'text']]],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('md:grid-cols-2 divide-y', $result);
        $this->assertStringContainsString('{{ data?.reference || \'N/A\' }}', $result);
        $this->assertStringContainsString('{{ data?.amount || \'N/A\' }}', $result);
    }

    // ─── Wiring view.fields[].group into the existing (previously orphaned)
    // groups mechanism ───────────────────────────────────────────────────────
    //
    // generateInformationSection()'s $groups param and its N-column rendering
    // already existed (see above) but nothing on the real, documented config
    // path (features.frontend.view.fields[]) ever populated it --
    // mapViewFieldsToInformationFields() silently dropped any 'group' key on
    // its input, and ViewOverviewGenerator::generate() always built a flat
    // 'fields' section. A module could not actually configure grouped
    // overview columns despite the renderer supporting them since v2.23.0.
    // Fixed by threading a 'group' key through mapViewFieldsToInformationFields()
    // and bucketing it via the new bucketViewFieldsIntoGroups() helper.

    public function test_map_view_fields_preserves_group_key_on_plain_and_relation_fields(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'phone', 'title' => 'Phone', 'group' => 'Contact Info'],
            ['data' => 'district?.name', 'title' => 'District', 'group' => 'Location'],
            ['data' => 'name', 'title' => 'Name'],
        ]);

        $this->assertSame('Contact Info', $mapped[0]['group']);
        $this->assertSame('Location', $mapped[1]['group']);
        $this->assertNull($mapped[2]['group']);
    }

    public function test_bucket_view_fields_into_groups_returns_flat_fields_when_none_are_grouped(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'is_active', 'title' => 'Active', 'type' => 'boolean'],
        ]);

        $bucketed = $generator->callBucketViewFieldsIntoGroups($mapped);

        $this->assertArrayHasKey('fields', $bucketed);
        $this->assertArrayNotHasKey('groups', $bucketed);
        $this->assertCount(2, $bucketed['fields']);
        // 'group' key (always null here) must not leak into the row renderer's input.
        $this->assertArrayNotHasKey('group', $bucketed['fields'][0]);
    }

    public function test_bucket_view_fields_into_groups_buckets_by_group_label_preserving_first_seen_order(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'phone', 'title' => 'Phone', 'group' => 'Contact Info'],
            ['data' => 'created_by?.name', 'title' => 'Created By', 'group' => 'Audit'],
            ['data' => 'email', 'title' => 'Email', 'group' => 'Contact Info'],
        ]);

        $bucketed = $generator->callBucketViewFieldsIntoGroups($mapped);

        $this->assertArrayHasKey('groups', $bucketed);
        $this->assertArrayNotHasKey('fields', $bucketed);
        $this->assertCount(2, $bucketed['groups']);
        $this->assertSame('Contact Info', $bucketed['groups'][0]['label']);
        $this->assertCount(2, $bucketed['groups'][0]['fields']); // phone + email, both bucketed together
        $this->assertSame('Audit', $bucketed['groups'][1]['label']);
        $this->assertCount(1, $bucketed['groups'][1]['fields']);
    }

    public function test_grouped_overview_column_renders_its_label_when_set(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateInformationSection('Overview', 'InfoIcon', [], [
            ['label' => 'Contact Info', 'fields' => [['key' => 'phone', 'label' => 'Phone', 'type' => 'text']]],
            ['fields' => [['key' => 'notes', 'label' => 'Notes', 'type' => 'text']]], // no label -- must render bare, unchanged
        ]);

        $this->assertStringContainsString('>Contact Info<', $result);
        // The unlabeled second column must NOT pick up the first column's label.
        $this->assertSame(1, substr_count($result, 'text-muted-foreground">Contact Info<'));
    }

    public function test_bucket_view_fields_into_groups_buckets_ungrouped_fields_into_their_own_unlabeled_column(): void
    {
        $generator = $this->makeGenerator();

        // Mixed input: one grouped field, one ungrouped. Since ANY field is
        // grouped, the whole section becomes a grid -- the ungrouped field
        // does NOT get a separate flat card; it becomes one more (unlabeled)
        // column alongside the labeled one, in order of first appearance.
        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'phone', 'title' => 'Phone', 'group' => 'Contact Info'],
        ]);

        $bucketed = $generator->callBucketViewFieldsIntoGroups($mapped);

        $this->assertArrayHasKey('groups', $bucketed);
        $this->assertCount(2, $bucketed['groups']);
        $this->assertArrayNotHasKey('label', $bucketed['groups'][0]); // 'name' -- first seen, ungrouped
        $this->assertSame('Contact Info', $bucketed['groups'][1]['label']);
    }

    public function test_end_to_end_view_fields_with_group_key_produce_a_grouped_overview_section(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapViewFieldsToInformationFields([
            ['data' => 'phone', 'title' => 'Phone', 'group' => 'Contact Info'],
            ['data' => 'email', 'title' => 'Email', 'group' => 'Contact Info'],
            ['data' => 'created_by?.name', 'title' => 'Created By', 'group' => 'Audit'],
        ]);
        $bucketed = $generator->callBucketViewFieldsIntoGroups($mapped);

        $result = $generator->callGenerateViewSections([
            'sections' => [array_merge(['key' => 'information', 'title' => 'Overview'], $bucketed)],
        ]);

        $this->assertStringContainsString('md:grid-cols-2 divide-y', $result);
        $this->assertStringContainsString('>Contact Info<', $result);
        $this->assertStringContainsString('>Audit<', $result);
        $this->assertStringContainsString('{{ data?.phone || \'N/A\' }}', $result);
        $this->assertStringContainsString('{{ data?.created_by?.name || \'N/A\' }}', $result);
    }

    public function test_generate_view_sections_without_groups_key_is_unaffected(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateViewSections([
            'sections' => [
                [
                    'key' => 'information',
                    'title' => 'Overview',
                    'fields' => [['key' => 'name', 'label' => 'Name', 'type' => 'text']],
                ],
            ],
        ]);

        $this->assertStringContainsString('<div class="grid grid-cols-1 md:grid-cols-2">', $result);
        $this->assertStringNotContainsString('divide-x', $result);
    }

    // ─── FK cell renderer via RelatedRecordLink (2026-07-24) ────────────────
    //
    // generateCustomCellRenderersFromListFields() gained a third branch
    // (alongside the pre-existing badge/boolean ones): any non-primary field
    // with isFk true now emits a <template #cell-{key}="{ row }"> wrapping
    // the display value in <RelatedRecordLink module="..." :uuid="...">,
    // using `{ row }` (NOT `{ item }`, unlike the badge/boolean branches)
    // and a relationAccessor derived by stripping the field key's trailing
    // "_id" suffix. RelatedRecordLink itself degrades to inert text if
    // relatedModule isn't registered, so it's always safe to emit.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateCustomCellRenderersFromListFields()

    /** @return array<string, mixed> */
    private function locationsConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationsModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationsModule.json did not decode to an array.');

        return $config;
    }

    public function test_fk_field_emits_related_record_link_cell_using_row_slot_prop(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status?.name', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'Statuses'],
        ], 'name');

        $this->assertStringContainsString('<!-- Custom cell renderer for FK column -->', $result);
        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Statuses" :uuid="row.status?.uuid">', $result);
        $this->assertStringContainsString("{{ row.status?.name || 'N/A' }}", $result);
        $this->assertStringContainsString('</RelatedRecordLink>', $result);
        $this->assertStringContainsString('</template>', $result);

        // Must use the `{ row }` slot prop, never the badge/boolean branch's `{ item }`.
        $this->assertStringNotContainsString('#cell-status_id=\'{ item }\'', $result);
    }

    /**
     * Bug (fixed 2026-08-02): this FK cell renderer hardcoded `?.name` for
     * every related record's display value, regardless of what the target
     * table's actual display column is named. IntrospectionToConfig now
     * threads the real resolved field through as 'displayField' on each list
     * field entry (see IntrospectionToConfigTest's
     * test_fk_list_column_gets_the_correct_display_field_when_a_foreign_primary_field_is_supplied) --
     * this is the consumption side of that fix: when 'displayField' is
     * present, it wins over the 'name' default.
     */
    public function test_fk_field_uses_displayfield_override_instead_of_hardcoded_name(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'order_id', 'sortable' => true, 'data' => 'order?.order_number', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'Orders', 'displayField' => 'order_number'],
        ], 'name');

        $this->assertStringContainsString('<RelatedRecordLink module="Orders" :uuid="row.order?.uuid">', $result);
        $this->assertStringContainsString("{{ row.order?.order_number || 'N/A' }}", $result);
        $this->assertStringNotContainsString("row.order?.name", $result);
    }

    /**
     * A hand-authored module.json field (or any caller predating this fix)
     * that never sets 'displayField' at all must still fall back to 'name' --
     * byte-identical to the pre-fix behaviour.
     */
    public function test_fk_field_falls_back_to_name_when_displayfield_key_absent(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status?.name', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'Statuses'],
        ], 'name');

        $this->assertStringContainsString("{{ row.status?.name || 'N/A' }}", $result);
    }

    /**
     * Regression test for a real, confirmed runtime crash: the badge/boolean
     * branch of generateCustomCellRenderersFromListFields() used to emit
     * `<template #cell-{key}='{ item }'>`, destructuring a slot prop that
     * the actual consuming component (<Module>ListPage.vue's <ListTable>,
     * which wraps ReportTable.vue) never provides — ReportTable.vue's
     * generic cell slot only ever passes `:row="row"` (see
     * src/components/report-table/ReportTable.vue), never `item`. Confirmed
     * live: a generated ItemsListPage.vue (is_active is a plain boolean
     * column, visible by default) threw "TypeError: Cannot read properties
     * of undefined (reading 'is_active')" and crashed the ENTIRE list
     * render the instant a real row existed — this wasn't a cosmetic
     * glitch, it made /items/list, /item-images/list, and /item-prices/list
     * completely unusable. The sibling isFk branch already used `{ row }`
     * correctly (see the FK test above) — this branch simply predated it
     * and was never updated to match. Fixed by switching both the
     * relationship-badge and direct-field-badge/boolean sub-branches from
     * `item.` to `row.` throughout.
     */
    public function test_boolean_field_cell_renderer_uses_row_slot_prop_not_item(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('<!-- Custom cell renderer for badge/boolean column -->', $result);
        $this->assertStringContainsString('<template #cell-is_active="{ row }">', $result);
        $this->assertStringContainsString("{{ row.is_active ? 'Yes' : 'No' }}", $result);
        $this->assertStringContainsString("row.is_active === 'Active' || row.is_active === 1 || row.is_active === true", $result);

        // Must never regress back to the undefined-at-runtime `{ item }` shape.
        $this->assertStringNotContainsString("{ item }", $result);
        $this->assertStringNotContainsString('item.is_active', $result);
    }

    /** Same fix, relationship-badge sub-branch (dot-notation data path, e.g. "status.name"). */
    public function test_relationship_badge_field_cell_renderer_uses_row_slot_prop_not_item(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status.name', 'type' => 'badge', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString("row.status.name === 'Active' || row.status.name === 1 || row.status.name === true", $result);
        $this->assertStringContainsString("{{ row.status.name || 'N/A' }}", $result);
        $this->assertStringNotContainsString("{ item }", $result);
    }

    /**
     * Enum column cell renderer -- IntrospectionToConfig::buildFrontendListFields()
     * now sets type => 'badge' + enum_values => [{value,label}, ...] for a
     * column with enum_values. Renders as a badge with the humanised label
     * (matching the form's Select2Field option labels), not the raw stored
     * value, and with a single consistent badge style rather than the
     * Active/Inactive two-tone :class ternary used elsewhere -- an enum can
     * have any number of values so that binary mapping doesn't generalise.
     *
     * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildFrontendListFields()
     */
    public function test_enum_field_cell_renderer_emits_badge_with_humanised_labels(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            [
                'key'         => 'price_tier',
                'sortable'    => true,
                'data'        => 'price_tier',
                'type'        => 'badge',
                'isFk'        => false,
                'enum_values' => [
                    ['value' => 'standard', 'label' => 'Standard'],
                    ['value' => 'premium', 'label' => 'Premium'],
                    ['value' => 'wholesale', 'label' => 'Wholesale'],
                ],
            ],
        ], 'name');

        $this->assertStringContainsString('<!-- Custom cell renderer for enum badge column -->', $result);
        $this->assertStringContainsString('<template #cell-price_tier="{ row }">', $result);
        $this->assertStringContainsString(
            "{{ ({ 'standard': 'Standard', 'premium': 'Premium', 'wholesale': 'Wholesale' })[row.price_tier] ?? row.price_tier }}",
            $result
        );
        // Single consistent style -- no Active/Inactive :class ternary for enums.
        $this->assertStringContainsString('bg-blue-100 text-blue-800', $result);
        $this->assertStringNotContainsString("=== 'Active'", $result);
        $this->assertStringNotContainsString("{ item }", $result);
    }

    /**
     * A 'badge' field with no enum_values (e.g. a relationship badge such as
     * status.name) must keep rendering exactly the way it did before enum
     * support was added -- the new branch only engages when enum_values is a
     * non-empty array.
     */
    public function test_badge_field_without_enum_values_is_byte_for_byte_unchanged(): void
    {
        $generator = $this->makeGenerator();

        $withoutEnum = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status.name', 'type' => 'badge', 'isFk' => false],
        ], 'name');

        $withEmptyEnum = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'status_id', 'sortable' => true, 'data' => 'status.name', 'type' => 'badge', 'isFk' => false, 'enum_values' => []],
        ], 'name');

        $this->assertStringNotContainsString('enum badge column', $withoutEnum);
        $this->assertSame($withoutEnum, $withEmptyEnum);
    }

    public function test_fk_field_relation_accessor_strips_trailing_id_suffix_only(): void
    {
        // location_type_id -> location_type (singular strip of "_id" only,
        // never a deeper singularization of "location_types"). Stays
        // snake_case regardless of the relation METHOD's own casing, since
        // Laravel's $snakeAttributes (default true) makes
        // HasAttributes::relationsToArray() snake_case every loaded relation's
        // array key unconditionally — see this method's inline comment for the
        // full empirical verification.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'isFk' => false],
            ['key' => 'location_type_id', 'data' => 'location_type?.name', 'type' => 'text', 'isFk' => true, 'relatedModule' => 'LocationTypes'],
        ], 'name');

        $this->assertStringContainsString('module="LocationTypes"', $result);
        $this->assertStringContainsString(':uuid="row.location_type?.uuid"', $result);
        $this->assertStringContainsString("{{ row.location_type?.name || 'N/A' }}", $result);
        // The raw FK column name itself must never leak into the row accessor.
        $this->assertStringNotContainsString('row.location_type_id', $result);
    }

    public function test_primary_fk_field_is_skipped_like_any_other_primary_field(): void
    {
        // The primary column is rendered by generatePrimaryCellContentFromListFields(),
        // not this method -- generateCustomCellRenderersFromListFields() must
        // skip it even when it happens to be a FK.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'category_id', 'sortable' => true, 'isFk' => true, 'relatedModule' => 'Categories'],
        ], 'category_id');

        $this->assertSame('', $result);
    }

    public function test_no_fk_fields_emits_no_renderer_output_at_all(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'type' => 'text', 'isFk' => false],
            ['key' => 'description', 'sortable' => true, 'type' => 'text', 'isFk' => false],
        ], 'name');

        $this->assertSame('', $result);
        $this->assertStringNotContainsString('RelatedRecordLink', $result);
    }

    public function test_real_location_types_fixture_has_no_fk_fields_and_emits_no_related_record_link(): void
    {
        // LocationTypesModule.json (see PhpUnitTestGeneratorTest's fixture) has
        // zero FK columns -- name/code/color are all plain strings -- confirmed
        // by inspection before reaching for a different fixture for the "has an
        // FK" cases above. Proves the isFk branch never fires for a module that
        // genuinely has none.
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config);

        $fields = $config['features']['frontend']['list']['fields'];
        foreach ($fields as &$field) {
            $field['isFk'] = false;
        }
        unset($field);

        $generator = $this->makeGenerator(moduleName: 'LocationTypes', config: $config);
        $result = $generator->callGenerateCustomCellRenderersFromListFields(
            $fields,
            $config['features']['frontend']['list']['primaryField']
        );

        $this->assertStringNotContainsString('RelatedRecordLink', $result);
    }

    public function test_real_locations_fixture_fk_fields_each_produce_a_related_record_link_cell(): void
    {
        // Locations/Locations/module.json is SYSTEM_SHELL's real, hand-completed
        // module config. It predates the isFk/relatedModule list-field threading
        // added to IntrospectionToConfig::buildFrontendListFields(), so its list
        // fields (FK ones hand-marked "type": "badge") are re-threaded here from
        // the fixture's own top-level columns[] (which already carries the
        // equivalent relatedModule mapping) the same way a fresh
        // `make:module --force` run would today -- proving the relation names/
        // related-module targets asserted below (location_type -> LocationTypes,
        // parent -> Locations, status -> Statuses) are exactly this real
        // module's real schema, not stand-in strings.
        //
        // Note the "type" re-derivation below is load-bearing, not cosmetic:
        // generateCustomCellRenderersFromListFields() checks
        // `type === 'badge' || type === 'boolean'` BEFORE it checks `isFk` --
        // leaving "badge" in place would make every field below hit the older
        // badge/boolean branch (`{ item }`, dot-notation) instead of the new
        // isFk branch this test targets, silently passing for the wrong reason.
        // buildFrontendListFields() itself never emits "badge" (FK fields always
        // get 'text'), so this mirrors its real, current output exactly.
        $config = $this->locationsConfig();

        $columnsByName = [];
        foreach ($config['columns'] as $col) {
            $columnsByName[$col['name']] = $col;
        }

        $fields = array_map(static function (array $field) use ($columnsByName): array {
            $col  = $columnsByName[$field['key']] ?? null;
            $isFk = ($col['type'] ?? '') === 'foreignId';
            $field['isFk'] = $isFk;
            $field['relatedModule'] = $col['relatedModule'] ?? '';
            $field['type'] = $isFk ? 'text' : (($col['type'] ?? '') === 'boolean' ? 'boolean' : 'text');
            return $field;
        }, $config['features']['frontend']['list']['fields']);

        $generator = $this->makeGenerator(moduleName: 'Locations', config: $config);
        $result = $generator->callGenerateCustomCellRenderersFromListFields(
            $fields,
            $config['features']['frontend']['list']['primaryField']
        );

        $this->assertStringContainsString('<template #cell-location_type_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="LocationTypes" :uuid="row.location_type?.uuid">', $result);

        $this->assertStringContainsString('<template #cell-parent_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.parent?.uuid">', $result);

        $this->assertStringContainsString('<template #cell-status_id="{ row }">', $result);
        $this->assertStringContainsString('<RelatedRecordLink module="Statuses" :uuid="row.status?.uuid">', $result);

        // name/code/allow_sales are not FKs -- exactly 3 RelatedRecordLink cells total.
        $this->assertSame(3, substr_count($result, 'RelatedRecordLink module='));
    }

    // ─── File-upload conditional switching (v2.10.11) ──────────────────────
    //
    // Confirmed bug: a module generated with a `file-input` field rendered a
    // correct-looking FileInputField, but the form still submitted via
    // sendPostRequest (plain JSON) instead of sendFormDataRequest (multipart),
    // so a real File object never serialized to the backend and uploads
    // silently failed.
    //
    // Fix approach (CONDITIONAL switching, not universal): a form with ANY
    // field_type: 'file-input' field switches to FormData/sendFormDataRequest,
    // a separate ref<File|null> per file field (kept out of the reactive
    // `form` object), and '1'/'0' string boolean conversion (FormData-only).
    // A form with NO file-input fields must generate byte-for-byte what it
    // did before -- that's the regression guard asserted throughout below.

    public function test_has_file_input_field_detects_raw_and_mapped_field_shapes(): void
    {
        $generator = $this->makeGenerator();

        // Raw config shape (field_type key)
        $this->assertTrue($generator->callHasFileInputField([
            ['key' => 'name', 'field_type' => 'input'],
            ['key' => 'image_path', 'field_type' => 'file-input'],
        ]));

        // Mapped-to-legacy shape (field_type folded into 'type')
        $this->assertTrue($generator->callHasFileInputField([
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'image_path', 'type' => 'file-input'],
        ]));

        $this->assertFalse($generator->callHasFileInputField([
            ['key' => 'name', 'field_type' => 'input'],
            ['key' => 'is_active', 'field_type' => 'checkbox'],
        ]));

        $this->assertFalse($generator->callHasFileInputField([]));
    }

    public function test_extract_file_input_fields_returns_only_file_fields_in_order(): void
    {
        $generator = $this->makeGenerator();

        $fields = [
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
            ['key' => 'is_active', 'type' => 'checkbox'],
            ['key' => 'ota_file', 'type' => 'file-input'],
        ];

        $result = $generator->callExtractFileInputFields($fields);

        $this->assertCount(2, $result);
        $this->assertSame('apk_file', $result[0]['key']);
        $this->assertSame('ota_file', $result[1]['key']);
    }

    public function test_file_ref_name_matches_hand_written_convention(): void
    {
        $generator = $this->makeGenerator();

        // Keys already ending in a file-ish suffix keep their camelCase as-is
        // (matches MobileReleasesCreateForm.vue: apk_file -> apkFile).
        $this->assertSame('apkFile', $generator->callFileRefName('apk_file'));
        $this->assertSame('otaFile', $generator->callFileRefName('ota_file'));

        // Keys with no file-ish suffix get "File" appended so the ref name is
        // unambiguous (image_path -> imagePathFile, not imagePath).
        $this->assertSame('imagePathFile', $generator->callFileRefName('image_path'));
        $this->assertSame('file', $generator->callFileRefName('file'));
    }

    public function test_generate_request_import_line_switches_only_when_file_fields_present(): void
    {
        $generator = $this->makeGenerator();

        // Regression guard: no file fields -> exactly the pre-existing import line.
        $this->assertSame(
            'import {sendGetRequest, sendPostRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(false, 'create')
        );
        $this->assertSame(
            'import {sendGetRequest, sendPostRequest, sendPutRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(false, 'edit')
        );

        // With file fields -> sendFormDataRequest, for both create and edit.
        $this->assertSame(
            'import {sendGetRequest, sendFormDataRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(true, 'create')
        );
        $this->assertSame(
            'import {sendGetRequest, sendFormDataRequest} from "@/helpers";',
            $generator->callGenerateRequestImportLine(true, 'edit')
        );
    }

    public function test_generate_file_refs_block_is_empty_when_no_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $this->assertSame('', $generator->callGenerateFileRefsBlock([]));
    }

    public function test_generate_file_refs_block_declares_a_separate_ref_per_file_field(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFileRefsBlock([
            ['key' => 'apk_file'],
            ['key' => 'ota_file'],
        ]);

        $this->assertStringContainsString('const apkFile = ref<File | null>(null)', $result);
        $this->assertStringContainsString('const otaFile = ref<File | null>(null)', $result);
    }

    public function test_generate_form_fields_skips_file_input_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFields(['fields' => [
            ['key' => 'name', 'type' => 'input'],
            ['key' => 'image_path', 'type' => 'file-input'],
        ]]);

        $this->assertStringContainsString('name:', $result);
        $this->assertStringNotContainsString('image_path:', $result);
    }

    public function test_generate_submit_call_is_unchanged_for_create_without_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'name', 'type' => 'input'],
        ], 'create');

        $this->assertSame(
            'const response = await sendPostRequest(submitEndpoint.value, form.value)',
            $result
        );
    }

    public function test_generate_submit_call_is_unchanged_for_edit_without_file_fields(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'name', 'type' => 'input'],
        ], 'edit');

        $this->assertSame(
            'const response = await sendPutRequest(submitEndpoint.value, { ...form.value })',
            $result
        );
    }

    public function test_generate_submit_call_uses_form_data_request_with_boolean_conversion_and_file_merge(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'is_active', 'type' => 'checkbox'],
            ['key' => 'apk_file', 'type' => 'file-input'],
            ['key' => 'ota_file', 'type' => 'file-input'],
        ], 'create');

        $this->assertStringContainsString('const formData: Record<string, any> = {', $result);
        $this->assertStringContainsString('...form.value,', $result);
        // Boolean -> '1'/'0' string conversion, FormData-path only.
        $this->assertStringContainsString("is_active: form.value.is_active ? '1' : '0',", $result);
        // File-input fields never appear inside the spread/boolean block.
        $this->assertStringNotContainsString('apk_file:', $result);
        // Each file ref is conditionally merged in after the object literal.
        $this->assertStringContainsString('if (apkFile.value) formData.apk_file = apkFile.value', $result);
        $this->assertStringContainsString('if (otaFile.value) formData.ota_file = otaFile.value', $result);
        $this->assertStringContainsString('const response = await sendFormDataRequest(submitEndpoint.value, formData)', $result);
        // Never falls back to the plain JSON helpers when a file field is present.
        $this->assertStringNotContainsString('sendPostRequest', $result);
        $this->assertStringNotContainsString('sendPutRequest', $result);
    }

    public function test_generate_submit_call_for_edit_with_file_fields_also_uses_form_data_request(): void
    {
        // sendFormDataRequest always issues a POST (multipart PUT bodies aren't
        // reliably parsed by PHP), but the generated edit route is registered as
        // PUT (confirmed live via `php artisan route:list` against a real
        // generated module). A plain POST to a PUT-only route 404s, so the
        // FormData payload must carry `_method: 'PUT'` -- Laravel's built-in
        // method-override spoofing (the same technique Blade's @method('PUT')
        // directive uses for native file-upload forms) then routes it correctly.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
        ], 'edit');

        $this->assertStringContainsString('sendFormDataRequest', $result);
        $this->assertStringNotContainsString('sendPutRequest', $result);
        $this->assertStringContainsString("_method: 'PUT',", $result);
    }

    public function test_generate_submit_call_for_create_with_file_fields_has_no_method_override(): void
    {
        // _method spoofing is an edit-only concern (create's route is already POST).
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateSubmitCall([
            ['key' => 'version', 'type' => 'input'],
            ['key' => 'apk_file', 'type' => 'file-input'],
        ], 'create');

        $this->assertStringNotContainsString('_method', $result);
    }

    // ─── $slotProp parameterization (2026-07-25) ─────────────────────────────
    //
    // generatePrimaryCellContentFromListFields() and
    // generateCustomCellRenderersFromListFields() both accept a $slotProp
    // parameter (default 'row') controlling the destructured slot-prop name
    // used in generated field accessors. Callers that splice into
    // list/component.stub / list/page.stub (which wrap <ListTable>/
    // <ReportTable>) rely on the 'row' default; CustomFeatureTabComponentGenerator,
    // which splices into features/custom/tab_action.stub (wraps
    // <ListPageBareTable>), passes 'item' explicitly to both methods.

    public function test_primary_cell_content_uses_row_slot_prop_by_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGeneratePrimaryCellContentFromListFields([
            ['key' => 'name', 'data' => 'name'],
            ['key' => 'code', 'data' => 'code', 'showOnMobileSub' => true],
        ], 'name');

        $this->assertStringContainsString('{{ row.name }}', $result);
        $this->assertStringContainsString('{{ row.code || \'N/A\' }}', $result);
        $this->assertStringNotContainsString('item.', $result);
    }

    public function test_primary_cell_content_uses_item_slot_prop_when_passed_explicitly(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGeneratePrimaryCellContentFromListFields([
            ['key' => 'name', 'data' => 'name'],
            ['key' => 'code', 'data' => 'code', 'showOnMobileSub' => true],
        ], 'name', 'item');

        $this->assertStringContainsString('{{ item.name }}', $result);
        $this->assertStringContainsString('{{ item.code || \'N/A\' }}', $result);
        $this->assertStringNotContainsString('row.', $result);
    }

    public function test_custom_cell_renderers_use_row_slot_prop_by_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name');

        $this->assertStringContainsString('{ row }', $result);
        $this->assertStringContainsString('row.is_active', $result);
        $this->assertStringNotContainsString('item.', $result);
        $this->assertStringNotContainsString('{ item }', $result);
    }

    public function test_custom_cell_renderers_use_item_slot_prop_when_passed_explicitly(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateCustomCellRenderersFromListFields([
            ['key' => 'name', 'sortable' => true, 'data' => 'name', 'type' => 'text', 'isFk' => false],
            ['key' => 'is_active', 'sortable' => true, 'data' => 'is_active', 'type' => 'boolean', 'isFk' => false],
        ], 'name', 'item');

        $this->assertStringContainsString('{ item }', $result);
        $this->assertStringContainsString('item.is_active', $result);
        $this->assertStringNotContainsString('row.', $result);
        $this->assertStringNotContainsString('{ row }', $result);
    }

    public function test_generate_field_for_file_input_binds_v_model_to_separate_ref_not_form_object(): void
    {
        // This requires a real stub file on disk (getStubPath/getTemplateContent),
        // so build the generator against the real package template directory.
        $generator = $this->makeGenerator(config: [
            'id_type' => 'autoincrement',
        ]);

        $result = $generator->callGenerateField([
            'key' => 'image_path',
            'name' => 'image_path',
            'type' => 'file-input',
            'label' => 'Image',
        ]);

        $this->assertStringContainsString('v-model="imagePathFile"', $result);
        $this->assertStringNotContainsString('v-model="form.image_path"', $result);
    }

    /**
     * Bug (fixed 2026-08-02): the generator's own `isFieldDisabled()` runtime
     * helper (defined in create/form.stub, and now also edit/form.stub) was
     * never actually wired into any field stub's `:disabled` binding -- every
     * field-type stub that had a `:disabled` attribute at all only bound the
     * static per-field `[[fieldDisabled]]` config flag, so the entire
     * `disabledFields`/`defaults`-driven auto-disable mechanism was dead code
     * from the moment a form rendered.
     *
     * Fix: every field-type stub's `:disabled` binding now combines all three
     * disable sources: `isSubmitting` (already the hand-written-reference
     * convention -- lock fields while a request is in flight), the static
     * `[[fieldDisabled]]` config flag, and the previously-dead
     * `isFieldDisabled('[[fieldKey]]')` runtime check.
     *
     * @see BaseComponentGenerator::generateField()
     */
    public function test_generate_field_combines_submitting_static_and_dynamic_disabled_for_every_scalar_field_type(): void
    {
        $generator = $this->makeGenerator();

        $fieldTypes = [
            'input', 'checkbox', 'date', 'number-input', 'textarea', 'time',
            'api-select', 'api-select-inline', 'color', 'select',
        ];

        foreach ($fieldTypes as $fieldType) {
            $result = $generator->callGenerateField([
                'key' => 'some_field',
                'name' => 'some_field',
                'type' => $fieldType,
                'label' => 'Some Field',
            ]);

            $this->assertStringContainsString(
                ":disabled=\"isSubmitting || false || isFieldDisabled('some_field')\"",
                $result,
                "field type '{$fieldType}' did not emit the combined disabled expression"
            );
        }
    }

    /**
     * Companion to the test above for file-input, which -- unlike every other
     * field type -- had NEITHER a hidden wrapper NOR a disabled binding at
     * all before this fix (confirmed via git history: never present, not
     * removed). FileInputField.vue itself already supports a real `disabled`
     * prop (verified against SYSTEM_SHELL/FRONTEND/src/components/form-fields/
     * FileInputField.vue), so both halves of the fix apply here.
     */
    public function test_generate_field_for_file_input_gains_hidden_wrapper_and_combined_disabled(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'image_path',
            'name' => 'image_path',
            'type' => 'file-input',
            'label' => 'Image',
        ]);

        $this->assertStringContainsString('<template v-if="!props.hiddens?.[\'image_path\']">', $result);
        $this->assertStringContainsString(
            ":disabled=\"isSubmitting || false || isFieldDisabled('image_path')\"",
            $result
        );
    }

    /**
     * inline-items and item-picker also had neither a hidden wrapper nor a
     * disabled binding before this fix. Unlike every scalar field type,
     * InlineItemsComponent.vue and ItemPickerComponent.vue do NOT expose a
     * component-level `disabled` prop (confirmed by reading both components'
     * Props interfaces directly -- only per-field `dynamicDisabled(item)`
     * hooks exist on InlineItemsComponent). Binding `:disabled` on either
     * component would therefore be a dead Vue attribute fallthrough, not a
     * real fix -- the same class of bug already found and fixed for
     * inline-items.stub's `color-scheme` attribute. So only the hidden
     * wrapper is added for these two field types; this is a deliberate scope
     * limit, not an oversight.
     */
    public function test_generate_field_for_inline_items_and_item_picker_gain_hidden_wrapper_only(): void
    {
        $generator = $this->makeGenerator();

        $inlineItemsResult = $generator->callGenerateField([
            'key' => 'order_items',
            'name' => 'order_items',
            'type' => 'inline-items',
            'label' => 'Order Items',
        ]);

        $this->assertStringContainsString('<template v-if="!props.hiddens?.[\'order_items\']">', $inlineItemsResult);
        $this->assertStringNotContainsString(':disabled=', $inlineItemsResult);

        $itemPickerResult = $generator->callGenerateField([
            'key' => 'products',
            'name' => 'products',
            'type' => 'item-picker',
            'label' => 'Products',
        ]);

        $this->assertStringContainsString('<template v-if="!props.hiddens?.[\'products\']">', $itemPickerResult);
        $this->assertStringNotContainsString(':disabled=', $itemPickerResult);
    }

    // ─── InlineItems wrapper component emission (2026-08-02) ─────────────────
    //
    // Bug: InlineItemsComponent's real, already-wired extension hooks
    // (dynamicDisabled/showField/render per field, @item-change/@field-change
    // events -- see its own README.md) were unreachable from generated code:
    // JSON config can't express a JS function, and both places this generator
    // ever bound <InlineItemsComponent> spliced the field list as an inline
    // :fields="[...]" literal with nowhere to hand-add a hook without editing
    // generated code regeneration would later clobber.
    //
    // Fix: both mechanisms now emit a hand-edit-protected `{Module}{Key}
    // InlineItems.vue` wrapper component per item (written once, skip-if-
    // exists -- same protection as {Module}TestCase.php) that a developer
    // hand-fills with the TODO-stubbed hooks, instead of binding
    // <InlineItemsComponent> directly.

    public function test_generate_field_for_inline_items_writes_wrapper_component_file_once(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'order_items',
            'name' => 'order_items',
            'type' => 'inline-items',
            'label' => 'Order Items',
            'fields' => [
                ['key' => 'product_name', 'label' => 'Product', 'type' => 'input', 'required' => true],
            ],
        ]);

        // The field's own markup renders the wrapper tag, not the shared component.
        $this->assertStringContainsString('<TestModuleOrderItemsInlineItems', $result);
        $this->assertStringNotContainsString('<InlineItemsComponent', $result);
        $this->assertStringNotContainsString(':fields=', $result);

        $path = PathManager::getFrontendModulePath('Core', 'TestModule') . '/Components/TestModuleOrderItemsInlineItems.vue';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString("import { InlineItemsComponent } from '@/components/inline-items'", $content);
        $this->assertStringContainsString('defineModel<any[]>', $content);
        // Built via arrayToJsObjectString() (JSON-derived, quoted keys) --
        // see the top-level inline_items mechanism's own test below, which
        // uses buildInlineItemFieldsJs() instead (bare, unquoted keys).
        $this->assertStringContainsString("'key': 'product_name'", $content);
        $this->assertStringContainsString('TODO', $content);
    }

    public function test_generate_field_for_inline_items_never_overwrites_an_already_hand_edited_wrapper(): void
    {
        $generator = $this->makeGenerator();
        $path = PathManager::getFrontendModulePath('Core', 'TestModule') . '/Components/TestModuleOrderItemsInlineItems.vue';

        // Simulate a developer having already hand-filled the wrapper's TODO hooks.
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, '<!-- HAND-EDITED: dynamicDisabled wired up -->');

        $generator->callGenerateField([
            'key' => 'order_items',
            'name' => 'order_items',
            'type' => 'inline-items',
            'label' => 'Order Items',
            'fields' => [],
        ]);

        $this->assertSame('<!-- HAND-EDITED: dynamicDisabled wired up -->', file_get_contents($path));
    }

    public function test_generate_form_field_imports_imports_the_wrapper_component_not_the_shared_package(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFieldImports([
            'fields' => [
                ['key' => 'order_items', 'field_type' => 'inline-items', 'label' => 'Order Items'],
            ],
        ]);

        $this->assertStringContainsString("import TestModuleOrderItemsInlineItems from './TestModuleOrderItemsInlineItems.vue';", $result);
        $this->assertStringNotContainsString("from '@/components/inline-items'", $result);
    }

    public function test_generate_inline_items_block_writes_wrapper_and_renders_it_instead_of_shared_component(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateInlineItemsBlock([
            [
                'key' => 'line_items',
                'label' => 'Line Items',
                'primary_field' => 'product_name',
                'fields' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text', 'required' => true],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number'],
                ],
            ],
        ]);

        $this->assertStringContainsString('<TestModuleLineItemsInlineItems', $result);
        $this->assertStringNotContainsString('<InlineItemsComponent', $result);
        $this->assertStringNotContainsString(':fields=', $result);
        // Every other prop this mechanism has always supported still flows through.
        $this->assertStringContainsString('v-model="form.line_items"', $result);
        $this->assertStringContainsString('primary-field="product_name"', $result);
        $this->assertStringContainsString(':modal-columns="1"', $result);

        $path = PathManager::getFrontendModulePath('Core', 'TestModule') . '/Components/TestModuleLineItemsInlineItems.vue';
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString("key: 'product_name'", $content);
        $this->assertStringContainsString("key: 'quantity'", $content);
    }

    /**
     * Bug (found + fixed 2026-08-09): buildInlineItemFieldsJs() emitted a
     * field's config `type` ('text'/'number'/'boolean' -- the semantic
     * value every fixture and docs example uses) straight through as the
     * `type:` prop InlineItemsFieldRenderer.vue actually reads as a WIDGET
     * selector ('input'/'number-input'/'checkbox') -- none of the semantic
     * values matched any of its cases, so the Add/Edit modal rendered zero
     * visible fields. See InlineItemsEndToEndTest for the full live-fixture
     * writeup; this covers the mapping table directly, including the
     * 'boolean' case that fixture doesn't exercise, and the explicit
     * `field_type` override escape hatch (same convention as every other
     * field config surface in this generator).
     */
    public function test_generate_inline_items_block_maps_semantic_type_to_widget_type(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateInlineItemsBlock([
            [
                'key' => 'line_items',
                'label' => 'Line Items',
                'primary_field' => 'product_name',
                'fields' => [
                    ['key' => 'product_name', 'label' => 'Product', 'type' => 'text'],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number'],
                    ['key' => 'is_gift', 'label' => 'Gift?', 'type' => 'boolean'],
                    // Explicit field_type wins over the semantic type's default mapping.
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'field_type' => 'textarea'],
                ],
            ],
        ]);

        $path = PathManager::getFrontendModulePath('Core', 'TestModule') . '/Components/TestModuleLineItemsInlineItems.vue';
        $content = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression("/key: 'product_name'.*?type: 'input'/s", $content);
        $this->assertMatchesRegularExpression("/key: 'quantity'.*?type: 'number-input'/s", $content);
        $this->assertMatchesRegularExpression("/key: 'is_gift'.*?type: 'checkbox'/s", $content);
        $this->assertMatchesRegularExpression("/key: 'notes'.*?type: 'textarea'/s", $content);
        $this->assertStringNotContainsString("type: 'text'", $content);
        $this->assertStringNotContainsString("type: 'number'", $content);
        $this->assertStringNotContainsString("type: 'boolean'", $content);
    }

    public function test_generate_inline_items_field_defs_no_longer_declares_fields_inline(): void
    {
        // Fields now live inside each item's wrapper component (written as a
        // side effect of generateInlineItemsBlock()) instead of a bare
        // `const {ref}: InlineItemField[] = [...]` spliced into the
        // generated form's own <script setup> -- there is nothing left for
        // this placeholder to emit.
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField(['key' => 'noop', 'type' => 'input']); // warm instance, no-op
        $this->assertIsString($result);

        $ref = new ReflectionClass($generator);
        $method = $ref->getMethod('generateInlineItemsFieldDefs');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke($generator, [
            ['key' => 'line_items', 'fields' => [['key' => 'product_name', 'label' => 'Product', 'type' => 'text']]],
        ]));
    }

    /**
     * Bug (fixed in v2.13.1): mapNewFormFieldsToLegacy() unconditionally set
     * $mappedField['options'] = $field['splashKey'] ?? Str::plural($key)
     * BEFORE copying over the remaining original field properties. Since
     * static-options (enum) fields always carry splashKey as an empty string
     * (not null/unset), `??` doesn't fall through to Str::plural($key) -- it
     * kept the empty string. That pre-filled 'options' key then made the
     * "preserve all additional properties" copy loop skip the field's real
     * inline options array entirely (it only copies props NOT already set),
     * so the rich options array from IntrospectionToConfig was silently
     * dropped on the floor. generateField() then fell into its splash-key
     * fallback branch and rendered `:options="splash."` -- malformed Vue that
     * broke the create/edit form for every module with an enum column.
     *
     * @see BaseComponentGenerator::mapNewFormFieldsToLegacy()
     */
    public function test_map_new_form_fields_to_legacy_preserves_inline_options_array_for_enum_field(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'price_tier',
                'label' => 'Price Tier',
                'placeholder' => 'Enter Price Tier',
                'required' => true,
                'splashKey' => '',
                'field_type' => 'select',
                'type' => 'text',
                'options' => [
                    ['id' => 'standard', 'name' => 'Standard'],
                    ['id' => 'premium', 'name' => 'Premium'],
                    ['id' => 'wholesale', 'name' => 'Wholesale'],
                ],
                'option_label' => 'name',
                'option_value' => 'id',
            ],
        ]);

        $this->assertIsArray($mapped[0]['options']);
        $this->assertSame(
            ['id' => 'standard', 'name' => 'Standard'],
            $mapped[0]['options'][0]
        );
        $this->assertSame('name', $mapped[0]['option_label']);
        $this->assertSame('id', $mapped[0]['option_value']);
    }

    /**
     * When there is genuinely no inline options array, the splashKey (or its
     * Str::plural($key) fallback when splashKey is empty/absent) must still
     * be used, exactly as before the fix -- this is the regression guard for
     * the non-enum / model-backed select case.
     */
    public function test_map_new_form_fields_to_legacy_falls_back_to_plural_key_when_no_splash_key_or_options(): void
    {
        $generator = $this->makeGenerator();

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'status',
                'label' => 'Status',
                'field_type' => 'select',
                'type' => 'select',
            ],
        ]);

        $this->assertSame('statuses', $mapped[0]['options']);
    }

    /**
     * End-to-end regression guard for the exact malformed-Vue bug: a
     * static-select enum field pushed through generateField() must render a
     * real inline options array, never the broken `splash.` string.
     */
    public function test_generate_field_for_enum_select_renders_inline_options_not_broken_splash_string(): void
    {
        $generator = $this->makeGenerator(config: [
            'id_type' => 'autoincrement',
        ]);

        $mapped = $generator->callMapNewFormFieldsToLegacy([
            [
                'field' => 'price_tier',
                'label' => 'Price Tier',
                'required' => true,
                'splashKey' => '',
                'field_type' => 'select',
                'type' => 'text',
                'options' => [
                    ['id' => 'standard', 'name' => 'Standard'],
                ],
                'option_label' => 'name',
                'option_value' => 'id',
            ],
        ]);

        $result = $generator->callGenerateField($mapped[0]);

        $this->assertStringNotContainsString(':options="splash."', $result);
        $this->assertStringContainsString("'id': 'standard'", $result);
        $this->assertStringContainsString("'name': 'Standard'", $result);
    }

    /**
     * arrayToJsObjectString() naively swaps every `"` for `'` after
     * json_encode() to produce a JS-object-literal-looking string. JSON never
     * escapes an apostrophe inside a string value (only `"` is structurally
     * special to JSON), so a value containing one -- e.g. an enum option like
     * "o'brien" -- must have its apostrophe escaped BEFORE that swap, or the
     * generated Vue attribute breaks: the apostrophe would prematurely close
     * the surrounding single-quoted JS string.
     */
    public function test_array_to_js_object_string_escapes_apostrophe_in_value(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callArrayToJsObjectString([
            ['id' => "o'brien", 'name' => "O'Brien"],
        ]);

        // The escaped apostrophe must survive as \' inside the single-quoted
        // JS string, not as a bare ' that would terminate it early.
        $this->assertStringContainsString("'id': 'o\\'brien'", $result);
        $this->assertStringContainsString("'name': 'O\\'Brien'", $result);
    }

    // ─── resolveInlineCreateModule() / default "Add New" (v2.30.0) ─────────

    /**
     * Writes a real {RelatedModule}CreateForm.vue on disk at the exact path
     * resolveInlineCreateModule() checks, and registers the module so
     * PathManager::resolveFrontendImportSegment() can resolve its group/
     * sub-group — mirrors how a real related module's own CreateFormGenerator
     * output would look, since resolveInlineCreateModule() checks the file's
     * actual existence, not a config flag (see that method's own docblock
     * for why: module.json's own `features.frontend.create` was confirmed
     * live to be unreliable for this exact purpose).
     */
    private function registerRelatedModuleWithCreateForm(string $moduleName, string $group = 'Core', ?string $subGroup = null): void
    {
        PathManager::setModuleRegistry([
            ['name' => $moduleName, 'module_type' => $group, 'group_name' => $subGroup],
        ]);
        PathManager::setModuleSubGroup($subGroup);
        $path = PathManager::getFrontendModulePath($group, $moduleName) . "/Components/{$moduleName}CreateForm.vue";
        PathManager::setModuleSubGroup(null);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "<template><div/></template>\n");
    }

    public function test_resolve_inline_create_module_returns_related_module_when_create_form_exists(): void
    {
        $this->registerRelatedModuleWithCreateForm('Locations');
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'location_id',
            'relatedModule' => 'Locations',
        ]);

        $this->assertSame('Locations', $result);
    }

    public function test_resolve_inline_create_module_returns_null_for_self_referential_fk(): void
    {
        $this->registerRelatedModuleWithCreateForm('TestModule');
        $generator = $this->makeGenerator('TestModule');

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'parent_id',
            'relatedModule' => 'TestModule',
        ]);

        $this->assertNull($result);
    }

    public function test_resolve_inline_create_module_returns_null_when_related_module_not_registered(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'location_id',
            'relatedModule' => 'Locations',
        ]);

        $this->assertNull($result);
    }

    /**
     * The core reason this checks file existence rather than a module.json
     * feature flag: a module can be registered (real name/group/table) but
     * genuinely have no CreateForm.vue — e.g. a list-only lookup module.
     * Never guess in that case.
     */
    public function test_resolve_inline_create_module_returns_null_when_registered_but_create_form_missing(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Locations', 'module_type' => 'Core', 'group_name' => null],
        ]);
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'location_id',
            'relatedModule' => 'Locations',
        ]);

        $this->assertNull($result);
    }

    public function test_resolve_inline_create_module_respects_explicit_false_opt_out(): void
    {
        $this->registerRelatedModuleWithCreateForm('Locations');
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'location_id',
            'relatedModule' => 'Locations',
            'inline_create' => false,
        ]);

        $this->assertNull($result);
    }

    /**
     * An explicit create_form_module is trusted verbatim, unverified — same
     * precedence as any other explicit config override in this codebase
     * (e.g. endpoint.path/endpoint.permission). Deliberately points at a
     * module with no registry entry and no file on disk, to prove this
     * bypasses the verification the auto-detected path requires.
     */
    public function test_resolve_inline_create_module_trusts_explicit_create_form_module_unverified(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'owner_id',
            'create_form_module' => 'SomeUnregisteredModule',
        ]);

        $this->assertSame('SomeUnregisteredModule', $result);
    }

    public function test_resolve_inline_create_module_returns_null_when_relatedmodule_is_empty(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callResolveInlineCreateModule([
            'key' => 'plain_field',
        ]);

        $this->assertNull($result);
    }

    public function test_generate_field_upgrades_direct_api_select_to_inline_variant_when_eligible(): void
    {
        $this->registerRelatedModuleWithCreateForm('Locations');
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'location_id',
            'field_type' => 'api-select',
            'label' => 'Location',
            'relatedModule' => 'Locations',
            'api_url' => '/select/locations',
        ]);

        $this->assertStringContainsString(':show-add-button="hasPermission(\'Locations.create\')"', $result);
        $this->assertStringContainsString('#add-new=', $result);
        $this->assertStringContainsString('LocationsCreateForm', $result);
        // Draft-collision prevention no longer needs a marker here at all --
        // every CreateForm mount now allocates its own fresh draft key (see
        // BaseComponentGenerator::buildCreateDraftBlocks()), so this nested
        // quick-create popup and Locations' own standalone /create page
        // naturally get independent draft slots without any explicit
        // context tag (superseded 2026-08-10's `draft-context="inline-*"`
        // attribute, which relied on a since-removed prop).
        $this->assertStringNotContainsString('draft-context', $result);
    }

    public function test_generate_field_leaves_direct_api_select_plain_when_related_module_unresolvable(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'location_id',
            'field_type' => 'api-select',
            'label' => 'Location',
            'relatedModule' => 'Locations',
            'api_url' => '/select/locations',
        ]);

        $this->assertStringNotContainsString('show-add-button', $result);
        $this->assertStringNotContainsString('#add-new', $result);
    }

    public function test_generate_field_upgrades_splash_backed_select_to_inline_variant_when_eligible(): void
    {
        $this->registerRelatedModuleWithCreateForm('Roles');
        $generator = $this->makeGenerator(config: [
            'features' => [
                'backend' => [
                    'createSplash' => ['splashData' => [
                        ['key' => 'roles', 'type' => 'model'],
                    ]],
                ],
            ],
        ]);

        $result = $generator->callGenerateField([
            'key' => 'role_id',
            'field_type' => 'select',
            'label' => 'Role',
            'splashKey' => 'roles',
            'relatedModule' => 'Roles',
        ]);

        $this->assertStringContainsString(':show-add-button="hasPermission(\'Roles.create\')"', $result);
        $this->assertStringContainsString('RolesCreateForm', $result);
    }

    public function test_generate_form_field_imports_includes_create_form_for_eligible_direct_api_select(): void
    {
        $this->registerRelatedModuleWithCreateForm('Locations');
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFieldImports([
            'fields' => [
                ['key' => 'location_id', 'field_type' => 'api-select', 'relatedModule' => 'Locations', 'api_url' => '/select/locations'],
            ],
        ]);

        $this->assertStringContainsString("import LocationsCreateForm from '@/pages/modules/core/Locations/Components/LocationsCreateForm.vue';", $result);
    }

    public function test_generate_form_field_imports_omits_create_form_when_ineligible(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFieldImports([
            'fields' => [
                ['key' => 'location_id', 'field_type' => 'api-select', 'relatedModule' => 'Locations', 'api_url' => '/select/locations'],
            ],
        ]);

        $this->assertStringNotContainsString('CreateForm', $result);
    }

    // ─── morph-select (v2.51.0 polymorphic type-selector) ────────────────────

    private function samplePayableTargets(): array
    {
        return [
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
            ['alias' => 'customer', 'model' => 'App\\Models\\CustomersModel', 'module' => 'Customers', 'label' => 'Customer', 'option_label' => 'contact_person'],
        ];
    }

    public function test_generate_field_morph_select_emits_type_options_and_target_map(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'payable_type',
            'field_type' => 'morph-select',
            'label' => 'Payable',
            'id_column' => 'payable_id',
            'targets' => $this->samplePayableTargets(),
        ]);

        $this->assertStringContainsString('MorphSelectField', $result);
        $this->assertStringContainsString('form.payable_type', $result);
        $this->assertStringContainsString('form.payable_id', $result);
        $this->assertStringContainsString("'supplier'", $result);
        $this->assertStringContainsString("'customer'", $result);
        $this->assertStringContainsString('/select/suppliers', $result);
        $this->assertStringContainsString('/select/customers', $result);
        $this->assertStringContainsString('contact_person', $result);
    }

    /**
     * Bug (found live 2026-08-09): the outer create/edit form grid is
     * `md:grid-cols-2 lg:grid-cols-3` and MorphSelectField.vue itself
     * splits into a further `sm:grid-cols-2` for its type dropdown +
     * record picker -- without claiming extra width, that squeezed both
     * pickers into a sliver of an already-narrow single grid cell. Same
     * fix inline-items.stub/file-input.stub already use for this exact
     * shape of problem: wrap in `col-span-2` so the field claims two
     * grid cells' worth of width instead of one.
     */
    public function test_generate_field_morph_select_is_wrapped_in_col_span_2(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateField([
            'key' => 'payable_type',
            'field_type' => 'morph-select',
            'label' => 'Payable',
            'id_column' => 'payable_id',
            'targets' => $this->samplePayableTargets(),
        ]);

        $this->assertStringContainsString('col-span-2', $result);
    }

    public function test_generate_morph_target_map_literal_defaults_option_label_to_name(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateMorphTargetMapLiteral([
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
        ]);

        $this->assertStringContainsString("'supplier': { apiUrl: '/select/suppliers', optionLabel: 'name' }", $result);
    }

    public function test_generate_morph_target_map_literal_uses_explicit_option_label(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateMorphTargetMapLiteral([
            ['alias' => 'customer', 'model' => 'App\\Models\\CustomersModel', 'module' => 'Customers', 'label' => 'Customer', 'option_label' => 'contact_person'],
        ]);

        $this->assertStringContainsString("optionLabel: 'contact_person'", $result);
    }

    public function test_generate_morph_target_map_literal_kebab_cases_multiword_module_names(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateMorphTargetMapLiteral([
            ['alias' => 'po', 'model' => 'App\\Models\\PurchaseOrdersModel', 'module' => 'PurchaseOrders', 'label' => 'Purchase Order'],
        ]);

        $this->assertStringContainsString("apiUrl: '/select/purchase-orders'", $result);
    }

    public function test_generate_form_fields_morph_select_emits_both_underlying_form_keys(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFields(['fields' => [
            [
                'key' => 'payable_type',
                'field_type' => 'morph-select',
                'id_column' => 'payable_id',
                'targets' => $this->samplePayableTargets(),
            ],
        ]]);

        $this->assertStringContainsString("payable_type: ''", $result);
        $this->assertStringContainsString('payable_id: null', $result);
    }

    public function test_generate_form_field_imports_includes_morph_select_field(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callGenerateFormFieldImports([
            'fields' => [
                ['key' => 'payable_type', 'field_type' => 'morph-select', 'id_column' => 'payable_id', 'targets' => $this->samplePayableTargets()],
            ],
        ]);

        $this->assertStringContainsString("import MorphSelectField from '@/components/form-fields/MorphSelectField.vue';", $result);
        $this->assertStringNotContainsString('InputField', $result);
    }

    /**
     * Bug (fixed 2026-08-06): the no-splash refreshAndSet(key, value) stub
     * dropped both arguments on the floor instead of applying them to the
     * form. Every inline-create-eligible FK field (default since v2.30.0)
     * calls refreshAndSet('{fieldKey}', data.id) from its `@created` handler
     * expecting the newly created related record to land in
     * form.{fieldKey} — unconditionally, regardless of whether the module
     * also has a constants-driven splash. Found while re-wiring
     * SYSTEM_SHELL's Roles module (status_id's inline "Add New Status"
     * flow) onto the current generator during a module port.
     */
    public function test_build_splash_blocks_without_splash_still_applies_key_value_to_form(): void
    {
        $generator = $this->makeGenerator();

        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $generator->callBuildSplashBlocks('create', false);

        $this->assertSame('', $splashPropBlock);
        $this->assertSame('', $splashBlock);
        $this->assertStringContainsString('if (key != null && value != null) (form.value as any)[key] = value', $refreshAndSetBlock);
        $this->assertStringNotContainsString('sendGetRequest', $refreshAndSetBlock);
        $this->assertSame('await refreshAndSet()', $onMountedBlock);
    }

    /**
     * Regression guard: the with-splash branch already applied key/value
     * correctly (only the no-splash branch was broken) — this must keep
     * doing both the fetch AND the assignment.
     */
    public function test_build_splash_blocks_with_splash_still_fetches_and_applies_key_value(): void
    {
        $generator = $this->makeGenerator();

        [, , $refreshAndSetBlock] = $generator->callBuildSplashBlocks('create', true);

        $this->assertStringContainsString('sendGetRequest(splashEndpoint.value)', $refreshAndSetBlock);
        $this->assertStringContainsString('if (key != null && value != null) (form.value as any)[key] = value', $refreshAndSetBlock);
    }

    /**
     * Bug 1 (fixed 2026-08-06): generateHeaderBadges() hardcoded `data?.` in
     * every emitted expression, but its only real caller (ViewLayoutGenerator,
     * details_layout.stub) fetches its record into a ref named `record`, never
     * `data` — every DetailsLayout.vue header badge for every module was
     * always falsy (v-if on an undefined variable), regardless of field.
     * Found live while re-wiring SYSTEM_SHELL's Roles module. Covered by
     * test_generate_header_badges_still_treats_multi_segment_path_as_relationship
     * and test_generate_header_badges_treats_bare_type_named_scalar_field_as_text_not_relationship
     * below, both of which assert on the `record?.` prefix.
     */

    /**
     * Bug 2 (fixed 2026-08-06, found live on Roles' `role_type` column): the
     * badge auto-detect matched any field whose data-path last segment merely
     * contained 'status' or 'type' and unconditionally treated it as a
     * relationship needing a `.name` sub-property — true for a resolved FK
     * path like "status?.name", false for a bare plain-scalar column like
     * "role_type". A plain scalar field now renders as a plain-text badge
     * instead of an always-undefined `record?.role_type?.name`.
     */
    public function test_generate_header_badges_treats_bare_type_named_scalar_field_as_text_not_relationship(): void
    {
        $config = [
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'role_type', 'title' => 'Role Type'],
                ],
            ]]],
        ];
        $generator = $this->makeGenerator(config: $config);

        $result = $generator->callGenerateHeaderBadges($config, 'record');

        $this->assertStringContainsString('v-if="record?.role_type"', $result);
        $this->assertStringContainsString('{{ record?.role_type }}', $result);
        $this->assertStringNotContainsString('role_type?.name', $result);
        $this->assertStringNotContainsString('data?.', $result);
    }

    /**
     * Regression guard: a genuinely multi-segment resolved FK display path
     * (e.g. "status?.name") must still be classified as a relationship badge
     * — only bare single-segment scalar fields changed behavior in the fix
     * above.
     */
    public function test_generate_header_badges_still_treats_multi_segment_path_as_relationship(): void
    {
        // Last segment 'employment_status' contains 'status' -- the
        // auto-detect matches on the LAST path segment, not the whole
        // string, so this is the shape that actually triggers detection
        // for a genuinely resolved FK display path.
        $config = [
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'employee?.employment_status', 'title' => 'Employment Status'],
                ],
            ]]],
        ];
        $generator = $this->makeGenerator(config: $config);

        $result = $generator->callGenerateHeaderBadges($config, 'record');

        $this->assertStringContainsString('v-if="record?.employee"', $result);
        $this->assertStringContainsString('{{ record?.employee?.employment_status }}', $result);
        $this->assertStringNotContainsString('data?.', $result);
    }

    /**
     * $stateVar defaults to 'data' when the caller omits it, preserving
     * behavior for any caller that genuinely does name its state `data`.
     */
    public function test_generate_header_badges_defaults_state_var_to_data(): void
    {
        $config = [
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'employee?.employment_status', 'title' => 'Employment Status'],
                ],
            ]]],
        ];
        $generator = $this->makeGenerator(config: $config);

        $result = $generator->callGenerateHeaderBadges($config);

        $this->assertStringContainsString('data?.employee?.employment_status', $result);
    }
}

/**
 * Minimal concrete subclass exposing the protected method under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestBaseComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return true;
    }

    public function callGenerateColumnsFromListFields(array $fields, ?string $primaryKey = null): string
    {
        return $this->generateColumnsFromListFields($fields, $primaryKey);
    }

    public function callGenerateCustomCellRenderersFromListFields(array $fields, $primaryKey, string $slotProp = 'row'): string
    {
        return $this->generateCustomCellRenderersFromListFields($fields, $primaryKey, $slotProp);
    }

    public function callGeneratePrimaryCellContentFromListFields(array $fields, ?string $primaryKey = null, string $slotProp = 'row'): string
    {
        return $this->generatePrimaryCellContentFromListFields($fields, $primaryKey, $slotProp);
    }

    public function callMapViewFieldsToInformationFields(array $fields): array
    {
        return $this->mapViewFieldsToInformationFields($fields);
    }

    public function callGenerateInformationSection(string $title, string $icon, array $fields, array $groups = []): string
    {
        return $this->generateInformationSection($title, $icon, $fields, $groups);
    }

    public function callGenerateViewSections(array $config): string
    {
        return $this->generateViewSections($config);
    }

    public function callBucketViewFieldsIntoGroups(array $mappedFields): array
    {
        return $this->bucketViewFieldsIntoGroups($mappedFields);
    }

    public function callHasFileInputField(array $fields): bool
    {
        return $this->hasFileInputField($fields);
    }

    public function callExtractFileInputFields(array $fields): array
    {
        return $this->extractFileInputFields($fields);
    }

    public function callFileRefName(string $key): string
    {
        return $this->fileRefName($key);
    }

    public function callGenerateRequestImportLine(bool $hasFileFields, string $formType): string
    {
        return $this->generateRequestImportLine($hasFileFields, $formType);
    }

    public function callGenerateFileRefsBlock(array $fileFields): string
    {
        return $this->generateFileRefsBlock($fileFields);
    }

    public function callGenerateFormFields(array $config): string
    {
        return $this->generateFormFields($config);
    }

    public function callGenerateSubmitCall(array $fields, string $formType): string
    {
        return $this->generateSubmitCall($fields, $formType);
    }

    public function callGenerateField(array $field): string
    {
        return $this->generateField($field);
    }

    public function callMapNewFormFieldsToLegacy(array $fields): array
    {
        return $this->mapNewFormFieldsToLegacy($fields);
    }

    public function callArrayToJsObjectString(array $array): string
    {
        return $this->arrayToJsObjectString($array);
    }

    public function callGenerateMorphTargetMapLiteral(array $targets): string
    {
        return $this->generateMorphTargetMapLiteral($targets);
    }

    public function callGenerateFormFieldImports(array $config): string
    {
        return $this->generateFormFieldImports($config);
    }

    public function callGenerateInlineItemsBlock(array $inlineItems): string
    {
        return $this->generateInlineItemsBlock($inlineItems);
    }

    public function callResolveInlineCreateModule(array $field): ?string
    {
        return $this->resolveInlineCreateModule($field);
    }

    /** @return array{string, string, string, string} */
    public function callBuildSplashBlocks(string $formType, bool $hasSplash): array
    {
        return $this->buildSplashBlocks($formType, $hasSplash);
    }

    public function callGenerateHeaderBadges(array $config, string $stateVar = 'data'): string
    {
        return $this->generateHeaderBadges($config, $stateVar);
    }
}
