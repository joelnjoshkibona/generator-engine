<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-08 while live-verifying
 * the morphs-suite integration fixture (tests/Fixtures/integration-schemas/morphs-suite/):
 * build() deliberately strips a morph pair's two columns (`{prefix}_type` +
 * `{prefix}_id`) out of $userColumns before buildFeatures() runs (so they
 * never clutter list/filter/table UI, which has no generic polymorphic
 * rendering), but nothing ever put them back for create/edit — despite
 * build()'s own 'file_columns' doc comment claiming
 * "CreateServiceGenerator/EditServiceGenerator -- validation-rule
 * override... logic" already consumed the top-level `morphs` key for this.
 * It never did. Every one of CreateService's validation rules, the frontend
 * CreateForm.vue, and the generated PHPUnit create-test payload derives from
 * features.backend/frontend.create.fields — with morph columns absent from
 * all three, creating a record through the generated API was impossible
 * (confirmed live: PaymentsModel::create() failed with
 * "Field 'payable_type' doesn't have a default value").
 *
 * Fix: buildMorphBackendFields()/buildMorphFrontendFields() append plain
 * (string, integer) field entries for each morph pair directly onto
 * create/edit — not list/view/filter, preserving the original UI-clutter
 * avoidance — closing the create-is-impossible gap while stopping short of
 * a full polymorphic type+FK picker (real feature work, not a bug fix).
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildMorphBackendFields()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildMorphFrontendFields()
 */
class IntrospectionToConfigMorphFieldsTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function columnsWithMorphPair(): array
    {
        return [
            [
                'name'            => 'amount',
                'type'            => 'decimal',
                'normalized_type' => 'decimal',
                'length'          => null,
                'scale'           => 2,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'payable_type',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
            [
                'name'            => 'payable_id',
                'type'            => 'bigint',
                'normalized_type' => 'foreignId',
                'length'          => null,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'module_name' => 'Payments',
            'module_type' => 'Custom',
            'table_name'  => 'payments',
        ];
    }

    /** @return array<string, mixed> */
    private function findField(array $fields, string $name): ?array
    {
        foreach ($fields as $field) {
            if (($field['field'] ?? null) === $name) {
                return $field;
            }
        }
        return null;
    }

    public function test_morph_pair_columns_get_validation_rules_on_create_and_edit(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithMorphPair(), $this->meta());

        $createFields = $config['features']['backend']['create']['fields'];
        $editFields   = $config['features']['backend']['edit']['fields'];

        $type = $this->findField($createFields, 'payable_type');
        $id   = $this->findField($createFields, 'payable_id');
        $this->assertNotNull($type, 'payable_type must have a create validation rule');
        $this->assertNotNull($id, 'payable_id must have a create validation rule');
        $this->assertSame('required|string', $type['rules']);
        $this->assertSame('required|integer', $id['rules']);

        $this->assertNotNull($this->findField($editFields, 'payable_type'));
        $this->assertNotNull($this->findField($editFields, 'payable_id'));
    }

    public function test_morph_pair_columns_get_frontend_create_form_fields(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithMorphPair(), $this->meta());

        $fields = $config['features']['frontend']['create']['fields'];

        $type = $this->findField($fields, 'payable_type');
        $id   = $this->findField($fields, 'payable_id');
        $this->assertNotNull($type, 'payable_type must have a frontend create field');
        $this->assertNotNull($id, 'payable_id must have a frontend create field');
        $this->assertSame('input', $type['field_type']);
        $this->assertSame('text', $type['type']);
        $this->assertSame('number-input', $id['field_type']);
        $this->assertSame('number', $id['type']);
    }

    /**
     * The top-level $config['columns'] array is deliberately unfiltered
     * (built straight from every raw column, morphs included — see build()'s
     * own 'file_columns' doc comment: ModelGenerator/MigrationGenerator read
     * it directly and need the morph columns present). Only the
     * feature-derived field lists (list/filter/create/edit/view, all sourced
     * from $userColumns) are meant to exclude them — this test protects
     * that narrower, correct scope from regressing back to "morphs excluded
     * everywhere" or forward to "morphs leak into list/filter too".
     */
    public function test_morph_pair_columns_stay_excluded_from_list_and_filters(): void
    {
        $config = (new IntrospectionToConfig())->build($this->columnsWithMorphPair(), $this->meta());

        $listFieldKeys = array_column($config['features']['frontend']['list']['fields'], 'key');
        $this->assertNotContains('payable_type', $listFieldKeys);
        $this->assertNotContains('payable_id', $listFieldKeys);

        $this->assertNotContains('payable_type', $config['features']['backend']['list']['filterableFields']);
        $this->assertNotContains('payable_id', $config['features']['backend']['list']['filterableFields']);
    }

    // ─── Polymorphic type-selector (v2.51.0) ─────────────────────────────────
    //
    // Critical ordering fix: build() bakes the plain-pair-vs-morph-select
    // decision into the frontend fields array immediately, via
    // buildFeatures() -- before this class even returns. A caller that only
    // merges targets onto the RETURNED config's 'morphs' key afterward (the
    // existing round-trip-from-module.json pattern) does NOT retroactively
    // fix that array. targets must be supplied via the new
    // $meta['existing_morph_targets'] key, consulted before buildFeatures()
    // runs -- these tests exercise that exact path, not a post-hoc merge.

    public function test_morph_pair_without_existing_targets_still_emits_the_plain_input_pair(): void
    {
        $config = (new IntrospectionToConfig())->build(
            $this->columnsWithMorphPair(),
            $this->meta() // no existing_morph_targets key at all
        );

        $fields = $config['features']['frontend']['create']['fields'];
        $type = $this->findField($fields, 'payable_type');

        $this->assertSame('input', $type['field_type']);
        $this->assertArrayNotHasKey('targets', $type);
    }

    public function test_morph_pair_with_existing_targets_emits_a_single_morph_select_field(): void
    {
        $targets = [
            ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
            ['alias' => 'customer', 'model' => 'App\\Models\\CustomersModel', 'module' => 'Customers', 'label' => 'Customer', 'option_label' => 'contact_person'],
        ];

        $meta = $this->meta();
        $meta['existing_morph_targets'] = [
            ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => $targets],
        ];

        $config = (new IntrospectionToConfig())->build($this->columnsWithMorphPair(), $meta);

        $fields = $config['features']['frontend']['create']['fields'];

        // A single morph-select entry replaces the plain pair -- no separate
        // payable_id field entry alongside it.
        $type = $this->findField($fields, 'payable_type');
        $id   = $this->findField($fields, 'payable_id');
        $this->assertNotNull($type);
        $this->assertNull($id, 'payable_id must not have its own separate field entry once morph-select takes over');

        $this->assertSame('morph-select', $type['field_type']);
        $this->assertSame('payable_type', $type['type_column']);
        $this->assertSame('payable_id', $type['id_column']);
        $this->assertSame($targets, $type['targets']);
    }

    public function test_morph_pair_with_targets_lacking_required_keys_falls_back_to_plain_pair(): void
    {
        $meta = $this->meta();
        // Missing 'module' on the target -- not a valid target per the
        // schema's required-keys list, must not be treated as usable.
        $meta['existing_morph_targets'] = [
            ['name' => 'payable', 'targets' => [['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'label' => 'Supplier']]],
        ];

        $config = (new IntrospectionToConfig())->build($this->columnsWithMorphPair(), $meta);

        $fields = $config['features']['frontend']['create']['fields'];
        $type = $this->findField($fields, 'payable_type');
        $id   = $this->findField($fields, 'payable_id');

        $this->assertSame('input', $type['field_type']);
        $this->assertNotNull($id, 'the plain pair must still include a separate payable_id field');
    }

    // ─── IntrospectionToConfig::mergeMorphTargets() direct coverage ──────────

    public function test_merge_morph_targets_overwrites_only_the_named_morph(): void
    {
        $fresh = [
            ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => []],
            ['name' => 'reference', 'type_column' => 'reference_type', 'id_column' => 'reference_id', 'targets' => []],
        ];
        $withTargets = [
            ['name' => 'payable', 'targets' => [['alias' => 'supplier', 'model' => 'X']]],
        ];

        $merged = IntrospectionToConfig::mergeMorphTargets($fresh, $withTargets);

        $this->assertSame([['alias' => 'supplier', 'model' => 'X']], $merged[0]['targets']);
        $this->assertSame([], $merged[1]['targets'], 'reference was not in the source -- must stay untouched');
    }

    public function test_merge_morph_targets_carries_forward_a_morph_missing_from_fresh(): void
    {
        $merged = IntrospectionToConfig::mergeMorphTargets(
            [],
            [['name' => 'payable', 'targets' => [['alias' => 'supplier', 'model' => 'X']]]]
        );

        $this->assertCount(1, $merged);
        $this->assertSame('payable', $merged[0]['name']);
    }

    public function test_merge_morph_targets_is_a_no_op_when_source_has_no_targets_for_the_morph(): void
    {
        $fresh = [['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => []]];

        $merged = IntrospectionToConfig::mergeMorphTargets($fresh, [['name' => 'payable', 'targets' => []]]);

        $this->assertSame([], $merged[0]['targets']);
    }
}
