<?php

/**
 * The `inline_items` config to layer onto Orders' introspected config --
 * hand-authored, not schema-derived, exactly like real usage (see
 * README.md's "How to use it" section). Part of the "orders-suite"
 * integration-test schema fixture.
 *
 * Shape note (bug found and fixed alongside this fixture, 2026-08-03):
 * generator-engine's own README previously documented `inline_items` as
 * `['line_items' => [ [...one item config...] ]]` -- a group-name key
 * wrapping a list. Every real consumer (CreateFormGenerator,
 * EditFormGenerator, CreateServiceGenerator, EditServiceGenerator,
 * ViewServiceGenerator) does `foreach ($this->config['inline_items'] ?? []
 * as $item)` expecting `$item` to be an item-config dict directly -- i.e.
 * `inline_items` is a flat LIST of item-configs, no wrapper key. The
 * README's example never round-tripped through real generation (this
 * suite is the first time it has), so the mismatch went unnoticed. Fixed
 * in the README; this fixture uses the shape the code actually expects.
 *
 * `child_group => 'Custom'` matches generator-engine's own README example
 * verbatim, and exposes the exact bug it triggers with a real
 * `make:module Custom/OrderItems` scaffold: BaseServiceGenerator::
 * buildChildNamespace() used to nest any non-Core/System group under
 * `System\{group}\...`, producing
 * `App\Project\Modules\System\Custom\OrderItems` -- a namespace that does
 * not exist. The real, correct namespace for a Custom-grouped module
 * (confirmed against every other generator's own convention, e.g.
 * RegistryGenerator, and this session's own ZzzScratchChildren scratch
 * verification) is `App\Project\Modules\Custom\OrderItems`, with no
 * forced System nesting. Fixed alongside this fixture.
 *
 * `child_has_creator_updater => true` (bug found + fixed 2026-08-02): the
 * order_items migration in this same fixture directory gives OrderItems
 * the project's standard NOT NULL created_by_id/nullable updated_by_id
 * columns (has_creator_updater is the default convention project-wide).
 * Without this flag, CreateServiceGenerator/EditServiceGenerator's
 * generated `{ChildModel}::create(...)` calls never set created_by_id,
 * which fatal-errors against a real DB ("Field 'created_by_id' doesn't
 * have a default value") -- confirmed via a live OrdersCreateService::
 * execute() call in a real SYSTEM_SHELL scratch module, the first time
 * this path was ever exercised against a real database rather than just
 * asserted as generated-PHP-source text. See BaseServiceGenerator::
 * buildInlineInjectArray()'s own docblock for the full writeup on why this
 * is opt-in rather than auto-detected.
 */

return [
    'inline_items' => [
        [
            'key'                       => 'order_items',
            'label'                     => 'Order Items',
            'child_module'              => 'OrderItems',
            'child_group'               => 'Custom',
            'child_has_creator_updater' => true,
            'parent_fk'                 => 'order_id',
            'primary_field'             => 'product_name',
            'modal_size'         => 'lg',
            'modal_columns'      => 2,
            'add_button_text'    => 'Add Item',
            'add_modal_title'    => 'Add Order Item',
            'edit_modal_title'   => 'Edit Order Item',
            'fields'             => [
                ['key' => 'product_name', 'label' => 'Product', 'type' => 'text', 'required' => true],
                ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'required' => true],
                ['key' => 'unit_price', 'label' => 'Unit Price', 'type' => 'number', 'required' => true, 'decimals' => 4],
                ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'number', 'required' => true, 'decimals' => 2],
            ],
            // Copy orders.currency onto every order_items row at save time --
            // exercises buildInlineInjectArray()'s inject_from_parent branch,
            // not just its always-present parent_fk pairing.
            'inject_from_parent' => [
                ['child_field' => 'currency', 'parent_field' => 'currency'],
            ],
        ],
    ],
];
