<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for how introspection keeps secret-like columns out of every
 * DERIVED field list it builds, while still writing them into `columns` and
 * the create fields (backend and frontend) — a secret has to be writable
 * somewhere, it just must never come back out through a filter/sort
 * allow-list, a list/view/delete field, an edit field, or the
 * primaryField/titleData that names "the one field that identifies this
 * record".
 *
 * `key_hash` is listed first in the fixture on purpose — a naive
 * "skip the first sensitive column, use the rest" implementation would
 * still pass a test that only ever put the sensitive column last.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::isSensitiveColumn()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::sensitiveColumns()
 */
class IntrospectionToConfigSensitiveColumnsTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function columns(): array
    {
        $base = [
            'length' => null, 'nullable' => false, 'default' => null,
            'is_fk' => false, 'foreign_table' => null, 'foreign_column' => null,
            'is_unique' => false, 'morph_role' => null, 'morph_name' => null,
        ];

        return [
            array_merge($base, ['name' => 'key_hash', 'type' => 'varchar', 'normalized_type' => 'string']),
            array_merge($base, ['name' => 'name', 'type' => 'varchar', 'normalized_type' => 'string']),
            array_merge($base, ['name' => 'secret', 'type' => 'varchar', 'normalized_type' => 'string']),
            array_merge($base, ['name' => 'password_set_at', 'type' => 'datetime', 'normalized_type' => 'datetime', 'nullable' => true]),
            array_merge($base, [
                'name' => 'api_key_id', 'type' => 'bigint', 'normalized_type' => 'foreignId',
                'is_fk' => true, 'foreign_table' => 'api_keys', 'foreign_column' => 'id',
            ]),
        ];
    }

    private function meta(array $overrides = []): array
    {
        return array_merge([
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
        ], $overrides);
    }

    private function fieldKeys(array $fields, string $keyName = 'field'): array
    {
        return array_column($fields, $keyName);
    }

    public function test_backend_filterable_and_sortable_exclude_sensitive_columns(): void
    {
        $config = IntrospectionToConfig::lenient()->build($this->columns(), $this->meta());

        $filterable = $config['features']['backend']['list']['filterableFields'];
        $sortable   = $config['features']['backend']['list']['sortableFields'];

        foreach ([$filterable, $sortable] as $list) {
            $this->assertNotContains('key_hash', $list);
            $this->assertNotContains('secret', $list);
            $this->assertContains('name', $list);
            $this->assertContains('password_set_at', $list);
            $this->assertContains('api_key_id', $list);
        }
    }

    public function test_frontend_list_view_and_delete_fields_exclude_sensitive_columns(): void
    {
        $config = IntrospectionToConfig::lenient()->build($this->columns(), $this->meta());

        $listKeys   = $this->fieldKeys($config['features']['frontend']['list']['fields'], 'key');
        $viewData   = $this->fieldKeys($config['features']['frontend']['view']['fields'], 'data');
        $deleteKeys = $this->fieldKeys($config['features']['frontend']['delete']['fields'], 'key');

        foreach ([$listKeys, $viewData, $deleteKeys] as $list) {
            $this->assertNotContains('key_hash', $list);
            $this->assertNotContains('secret', $list);
        }
    }

    public function test_create_fields_keep_sensitive_columns_masked_as_password(): void
    {
        $config = IntrospectionToConfig::lenient()->build($this->columns(), $this->meta());

        $backendCreateFields  = $this->fieldKeys($config['features']['backend']['create']['fields']);
        $frontendCreateFields = $config['features']['frontend']['create']['fields'];

        $this->assertContains('key_hash', $backendCreateFields);
        $this->assertContains('secret', $backendCreateFields);

        $frontendByField = [];
        foreach ($frontendCreateFields as $field) {
            $frontendByField[$field['field']] = $field;
        }

        $this->assertArrayHasKey('key_hash', $frontendByField);
        $this->assertArrayHasKey('secret', $frontendByField);
        $this->assertSame('password', $frontendByField['key_hash']['field_type']);
        $this->assertSame('password', $frontendByField['secret']['field_type']);
    }

    public function test_edit_fields_exclude_sensitive_columns_but_keep_the_rest(): void
    {
        $config = IntrospectionToConfig::lenient()->build($this->columns(), $this->meta());

        $backendEditFields  = $this->fieldKeys($config['features']['backend']['edit']['fields']);
        $frontendEditFields = $this->fieldKeys($config['features']['frontend']['edit']['fields']);

        foreach ([$backendEditFields, $frontendEditFields] as $list) {
            $this->assertNotContains('key_hash', $list);
            $this->assertNotContains('secret', $list);
            $this->assertContains('name', $list);
        }
    }

    public function test_primary_field_and_title_data_never_pick_a_sensitive_column(): void
    {
        $config = IntrospectionToConfig::lenient()->build($this->columns(), $this->meta());

        $this->assertSame('name', $config['features']['frontend']['list']['primaryField']);
        $this->assertSame('name', $config['features']['frontend']['view']['titleData']);
    }

    public function test_strict_build_accepts_and_echoes_sensitive_columns_override(): void
    {
        $strictMeta = $this->meta([
            'has_timestamps'      => true,
            'has_soft_deletes'    => false,
            'has_uuid'            => true,
            'has_creator_updater' => true,
            'file_columns'        => [],
            'index_groups'        => [],
        ]);

        $withOverride = IntrospectionToConfig::strict()->build(
            $this->columns(),
            $strictMeta + ['sensitive_columns' => ['include' => ['name'], 'exclude' => ['secret']]],
        );

        $this->assertSame(['include' => ['name'], 'exclude' => ['secret']], $withOverride['sensitive_columns']);
        $this->assertContains('secret', $withOverride['features']['backend']['list']['filterableFields']);
        $this->assertNotContains('name', $withOverride['features']['backend']['list']['filterableFields']);

        $withoutOverride = IntrospectionToConfig::strict()->build($this->columns(), $strictMeta);

        $this->assertArrayNotHasKey('sensitive_columns', $withoutOverride);
    }
}
