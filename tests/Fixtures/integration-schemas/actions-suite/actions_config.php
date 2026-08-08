<?php

/**
 * The `actions` + `features.backend.list.{bulk_actions,export,import}`
 * config to layer onto PurchaseOrders' introspected config -- hand-authored,
 * not schema-derived, exactly like real usage (see README.md's "How to use
 * it" section). Part of the "actions-suite" integration-test schema fixture.
 *
 * Referenced by columns.php's own docblock since this fixture was first
 * built (2026-08-02) but never actually assembled as a file until
 * 2026-08-06, once the export/import/bulk-action frontend wiring this
 * config exercises actually existed to generate against. `export`/`import`
 * are new here relative to the fixture's original scope (which covered only
 * `actions` + generic `bulk_actions`) -- both are plain booleans, so no new
 * schema/migration work was needed to add them, just this config layer.
 *
 * `archiveByYear` (added 2026-08-08) is the fixture's second action,
 * deliberately given a non-`['uuid']` `urlParams` shape to regression-lock
 * PhpUnitTestGenerator::buildActionServiceTestMethodsForKey()'s v2.44.0
 * coverage-gating rule with a real generated module, not just generator-
 * engine's own synthetic unit test (test_generate_omits_action_contract_test_when_route_shape_is_customized,
 * which uses a hand-rolled Widgets config, never an actual scaffolded
 * module). See README.md's "Known limitation" section for what this proves.
 */

return [
    'actions' => [
        'approve' => [
            'name' => 'approve',
            'label' => 'Approve',
            'hasUI' => true,
            'uiType' => 'modal',
            'urlParams' => ['uuid'],
            'operations' => [
                'create' => [
                    'enabled' => true,
                    'endpoint' => ['method' => 'POST', 'path' => '/purchase-orders/{uuid}/approve'],
                ],
            ],
        ],
        'archiveByYear' => [
            'name' => 'archiveByYear',
            'label' => 'Archive (by fiscal year)',
            'hasUI' => true,
            'uiType' => 'modal',
            'urlParams' => ['uuid', 'year'],
            'operations' => [
                'create' => [
                    'enabled' => true,
                    'endpoint' => ['method' => 'POST', 'path' => '/purchase-orders/{uuid}/archive-by-year/{year}'],
                ],
            ],
        ],
    ],
    'features' => [
        'backend' => [
            'list' => [
                'bulk_actions' => [
                    ['key' => 'archive', 'label' => 'Archive'],
                ],
                'export' => true,
                'import' => true,
            ],
        ],
    ],
];
