<?php

/**
 * Hand-derived SchemaIntrospector::columns()-shaped fixture for the
 * "actions-suite" integration-test schema, dumped from a REAL
 * SchemaIntrospector run against the migration in this same directory
 * (not hand-typed) -- see README.md for the full scenario description
 * and usage instructions.
 *
 * Exists to exercise custom `actions` (a single state-transition
 * action, hand-filled) and `bulk_actions` (features.backend.list.bulk_actions)
 * -- see actions_config.php in this same directory for the
 * hand-authored config that layers these onto the introspected
 * PurchaseOrders config, exactly like real usage.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::columns()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::build()
 */

return [

    // ─── purchase_orders ────────────────────────────────────────────────────
    'purchase_orders' => [
        [
            'name'            => 'po_number',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 255,
            'nullable'        => false,
            'default'         => null,
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => true,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => null,
        ],
        [
            'name'            => 'supplier_name',
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
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => null,
        ],
        [
            'name'            => 'status',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 32,
            'nullable'        => false,
            'default'         => 'draft',
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => null,
        ],
        [
            'name'            => 'total_amount',
            'type'            => 'decimal',
            'normalized_type' => 'decimal',
            'length'          => null,
            'nullable'        => false,
            'default'         => null,
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => 14,
            'scale'           => 2,
            'enum_values'     => null,
        ],
    ],

];
