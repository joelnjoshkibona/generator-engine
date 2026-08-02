<?php

/**
 * Hand-derived SchemaIntrospector::columns()-shaped fixture for the
 * "delegations-suite" integration-test schema, dumped from a REAL
 * SchemaIntrospector run against the migrations in this same
 * directory (not hand-typed) -- see README.md for the full scenario
 * description and usage instructions.
 *
 * Exists to exercise the `delegations` config (see README.md) --
 * `stock_movements` is rendered as a related-records sub-tab on the
 * `warehouses` view page, entirely via hand-authored config on
 * Warehouses; nothing in the schema itself marks this relationship
 * as special (warehouse_id is an ordinary FK, detected the same way
 * any other FK is).
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::columns()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::build()
 */

return [

    // ─── warehouses ─────────────────────────────────────────────────────────
    // Parent -- gets stock_movements embedded as a related-records tab via
    // its own hand-authored `delegations` config (see README.md).
    'warehouses' => [
        [
            'name'            => 'name',
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
            'name'            => 'location',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 255,
            'nullable'        => true,
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
    ],

    // ─── stock_movements ────────────────────────────────────────────────────
    // An ordinary, independently-scaffolded module -- own routes,
    // controller, CRUD services, delete form. Becomes a tab on Warehouses
    // purely via config, not via anything in this table itself.
    'stock_movements' => [
        [
            'name'            => 'warehouse_id',
            'type'            => 'bigint',
            'normalized_type' => 'foreignId',
            'length'          => null,
            'nullable'        => false,
            'default'         => null,
            'is_fk'           => true,
            'foreign_table'   => 'warehouses',
            'foreign_column'  => 'id',
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => null,
        ],
        [
            'name'            => 'item_name',
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
            'name'            => 'quantity',
            'type'            => 'int',
            'normalized_type' => 'integer',
            'length'          => null,
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
            'name'            => 'movement_type',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 16,
            'nullable'        => false,
            'default'         => 'in',
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
    ],

];
