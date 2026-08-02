<?php

/**
 * Hand-derived SchemaIntrospector::columns()-shaped fixture for the
 * "orders-suite" integration-test schema. See migrations/ in this same
 * directory for the source-of-truth migrations, and README.md for the
 * full scenario description and usage instructions.
 *
 * Each top-level key is a table name; each value is the array
 * SchemaIntrospector::columns() would return for that table, in the exact
 * shape items-suite's own columns.php uses (see that file's docblock for
 * the full field-by-field reference).
 *
 * Unlike items-suite, this suite exists specifically to exercise the
 * `inline_items` parent-child config (see inline_items_config.php) -- the
 * exact "Order Items" scenario generator-engine's own README documents as
 * the canonical inline_items example.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::columns()
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::build()
 */

return [

    // ─── orders ─────────────────────────────────────────────────────────────
    // Parent. Displayed by order_number, not "name" -- deliberately, so this
    // suite also exercises the FK-display-field fix (generator-engine
    // v2.23.0) if anything ever points an FK at orders: a naive `?.name`
    // read would be wrong here, same as it would for a real orders table.
    'orders' => [
        [
            'name'            => 'order_number',
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
            'name'            => 'customer_name',
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
            'type'            => 'enum',
            'normalized_type' => 'enum',
            'length'          => null,
            'nullable'        => false,
            'default'         => 'pending',
            'is_fk'           => false,
            'foreign_table'   => null,
            'foreign_column'  => null,
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => ['pending', 'paid', 'shipped', 'cancelled'],
        ],
        [
            'name'            => 'currency',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 3,
            'nullable'        => false,
            'default'         => 'USD',
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
            // 14,2 is deliberately off MigrationGenerator's (10,2) fallback
            // in precision (same reasoning as items-suite's price column) --
            // a real money total that happens to match the fallback's scale
            // but not its precision still catches a "never introspected"
            // regression.
            'name'            => 'total_amount',
            'type'            => 'decimal',
            'normalized_type' => 'decimal',
            'length'          => null,
            'nullable'        => false,
            'default'         => '0.00',
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
        [
            'name'            => 'notes',
            'type'            => 'text',
            'normalized_type' => 'text',
            'length'          => null,
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

    // ─── order_items ────────────────────────────────────────────────────────
    // Child of orders (order_id, required). Its own standalone columns --
    // the inline_items config that layers Orders' *inline* management of
    // these rows on top lives in inline_items_config.php, not here (it's
    // hand-authored config, not schema-derived, exactly like real usage).
    'order_items' => [
        [
            'name'            => 'order_id',
            'type'            => 'bigint',
            'normalized_type' => 'foreignId',
            'length'          => null,
            'nullable'        => false,
            'default'         => null,
            'is_fk'           => true,
            'foreign_table'   => 'orders',
            'foreign_column'  => 'id',
            'is_unique'       => false,
            'morph_role'      => null,
            'morph_name'      => null,
            'precision'       => null,
            'scale'           => null,
            'enum_values'     => null,
        ],
        [
            'name'            => 'product_name',
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
            'default'         => '1',
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
            // 10,4 deliberately differs in scale from orders.total_amount's
            // 14,2 -- catches a copy-paste mix-up between the two decimal
            // columns in either generated migration or generated validation.
            'name'            => 'unit_price',
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
            'precision'       => 10,
            'scale'           => 4,
            'enum_values'     => null,
        ],
        [
            'name'            => 'line_total',
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
            'precision'       => 12,
            'scale'           => 2,
            'enum_values'     => null,
        ],
        [
            // Populated via inject_from_parent, not user-entered when
            // managed inline -- see inline_items_config.php.
            'name'            => 'currency',
            'type'            => 'varchar',
            'normalized_type' => 'string',
            'length'          => 3,
            'nullable'        => false,
            'default'         => 'USD',
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
