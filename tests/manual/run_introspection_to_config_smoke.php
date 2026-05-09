<?php

/**
 * Smoke test for IntrospectionToConfig.
 *
 * Run: php packages/generator-engine/tests/manual/run_introspection_to_config_smoke.php
 */

require_once __DIR__ . '/../../src/Schema/IntrospectionToConfig.php';

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;

// ─── Hand-crafted SchemaIntrospector-shaped column array ─────────────────────
// Two columns: one plain string, one FK to "payment_providers"

$columns = [
    [
        'name'            => 'title',
        'type'            => 'varchar',
        'normalized_type' => 'string',
        'length'          => 255,
        'nullable'        => false,
        'default'         => null,
        'is_fk'           => false,
        'foreign_table'   => null,
        'foreign_column'  => null,
        'is_unique'       => false,
    ],
    [
        'name'            => 'payment_provider_id',
        'type'            => 'bigint',
        'normalized_type' => 'foreignId',
        'length'          => null,
        'nullable'        => false,
        'default'         => null,
        'is_fk'           => true,
        'foreign_table'   => 'payment_providers',
        'foreign_column'  => 'id',
        'is_unique'       => false,
    ],
];

$meta = [
    'module_name' => 'Products',
    'module_type' => 'Custom',
    'table_name'  => 'products',
    'group_name'  => null,
    'id_type'     => 'uuid',
];

// ─── Run ─────────────────────────────────────────────────────────────────────

$converter = new IntrospectionToConfig();
$config    = $converter->build($columns, $meta);

echo "=== IntrospectionToConfig Smoke Test ===\n\n";
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// ─── Assertions ──────────────────────────────────────────────────────────────

$pass = true;

function check(string $label, bool $condition): void
{
    global $pass;
    if ($condition) {
        echo "[PASS] {$label}\n";
    } else {
        echo "[FAIL] {$label}\n";
        $pass = false;
    }
}

// Top-level keys
$expectedKeys = [
    'id', 'module_name', 'module_type', 'table_name', 'id_type',
    'module_group_name', 'columns', 'indexes', 'features',
    'delegations', 'actions', 'processors', 'seeder', 'menu_config', 'constants',
];
foreach ($expectedKeys as $key) {
    check("Top-level key '{$key}' present", array_key_exists($key, $config));
}

// Column count
check('columns count is 2', count($config['columns']) === 2);

// FK column
$fkCol = null;
foreach ($config['columns'] as $col) {
    if ($col['name'] === 'payment_provider_id') {
        $fkCol = $col;
        break;
    }
}

check('FK column found', $fkCol !== null);
check('FK column type === foreignId', ($fkCol['type'] ?? '') === 'foreignId');
check('FK column relatedModule === PaymentProvider', ($fkCol['relatedModule'] ?? '') === 'PaymentProvider');
check('FK column featureSelections.backend.list === true', ($fkCol['featureSelections']['backend']['list'] ?? false) === true);
check('FK column featureSelections.frontend.list === true', ($fkCol['featureSelections']['frontend']['list'] ?? false) === true);

// Feature flags
check('features.backend.list present', isset($config['features']['backend']['list']));
check('features.backend.create present', isset($config['features']['backend']['create']));
check('features.backend.view present', isset($config['features']['backend']['view']));
check('features.backend.edit present', isset($config['features']['backend']['edit']));
check('features.backend.delete present', isset($config['features']['backend']['delete']));
check('features.frontend.list present', isset($config['features']['frontend']['list']));
check('features.frontend.create present', isset($config['features']['frontend']['create']));
check('features.frontend.view present', isset($config['features']['frontend']['view']));
check('features.frontend.edit present', isset($config['features']['frontend']['edit']));
check('features.frontend.delete present', isset($config['features']['frontend']['delete']));

// List columns populated
$listFields = $config['features']['frontend']['list']['fields'] ?? [];
$listFieldNames = array_column($listFields, 'key');
check('features.frontend.list.fields includes "title"', in_array('title', $listFieldNames, true));
check('features.frontend.list.fields includes "payment_provider_id"', in_array('payment_provider_id', $listFieldNames, true));

// Module meta
check('module_name is Products', $config['module_name'] === 'Products');
check('module_type is Custom', $config['module_type'] === 'Custom');
check('table_name is products', $config['table_name'] === 'products');
check('id_type is uuid', $config['id_type'] === 'uuid');
check('id is a non-empty string', !empty($config['id']));

// Determinism — same input produces same IDs
$config2 = $converter->build($columns, $meta);
check('build is deterministic (id stable)', $config['id'] === $config2['id']);
check('build is deterministic (column id stable)', $config['columns'][0]['id'] === $config2['columns'][0]['id']);

echo "\n";
echo $pass ? "=== ALL CHECKS PASSED ===\n" : "=== SOME CHECKS FAILED ===\n";
exit($pass ? 0 : 1);
