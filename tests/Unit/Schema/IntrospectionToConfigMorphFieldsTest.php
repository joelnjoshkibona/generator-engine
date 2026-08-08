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
}
