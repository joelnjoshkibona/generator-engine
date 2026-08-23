# actions-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine`'s
custom-action and list-batch mechanisms — two `actions` (one default-shape,
one multi-param), plus the three `features.backend.list` batch mechanisms
(`bulk_actions`, `export`, `import`) — end-to-end against a real consuming
Laravel project (SYSTEM_SHELL). Sibling to
`items-suite`/`orders-suite`/`morphs-suite`/`delegations-suite`.

The hand-authored config layer described below lives in
[`actions_config.php`](./actions_config.php) as a machine-readable PHP array
(same shape as the JSON in "How to use it" below) — same sidecar-file
pattern as `orders-suite/inline_items_config.php`.

## What this fixture covers

A POS/inventory-flavored scenario: a Purchase Order sent to a supplier moves
through states (`draft` → `approved` → `received`/`cancelled`). This fixture
exercises:

- **`actions`** — two entries. `approve` (`draft` → `approved`,
  `urlParams: ['uuid']`) demonstrates the route/controller/service
  scaffolding this mechanism produces — the transition logic itself is
  **not** auto-generated, you hand-fill it. `archiveByYear`
  (`urlParams: ['uuid', 'year']`, added 2026-08-08) demonstrates the
  opposite: a route/service/frontend form still get scaffolded correctly
  for a multi-param action, but the generated PHPUnit test file for it has
  **zero test methods** — `PhpUnitTestGenerator::buildActionServiceTestMethodsForKey()`
  only emits contract-test coverage when `urlParams` is empty or exactly
  `['uuid']` (v2.44.0). See "Known limitation — `archiveByYear` gets zero
  PHPUnit coverage by design" below.
- **`bulk_actions`** (`features.backend.list.bulk_actions`, a *separate*
  config location from `actions`) — a generic `archive` bulk action (no
  `status_target`), demonstrating the bulk-action route/service/frontend
  toolbar wiring.
- **`export`** (`features.backend.list.export`, boolean) — CSV/XLSX/PDF
  download, reusing the module's own `.list` permission (no dedicated
  permission, same as the standalone-module convention).
- **`import`** (`features.backend.list.import`, boolean) — CSV/XLSX upload
  with a dry-run mode, gated behind its own `.import` permission.

| Table | Scenario exercised |
|---|---|
| `purchase_orders` | `status` is a plain string column — deliberately **not** the `status_id` + Statuses-lookup convention `bulk_actions[].status_target` expects (see "Known limitation" below) |

`actions`, `bulk_actions`, `export`, and `import` are all **entirely
hand-authored** — nothing here is introspected. Confirmed (via a full JSON
scan of every real `module.json` in this project) that none of these four
mechanisms has a single non-empty real-world usage anywhere in the project
today — this fixture is the first.

## How to use it: full end-to-end validation

1. **Copy the migration**, run it, generate the module normally first:
   ```bash
   cp tests/Fixtures/integration-schemas/actions-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   php artisan migrate
   php artisan make:module Custom/PurchaseOrders
   ```

   > **Delete the copied migration NOW — before any test run.**
   > `make:module` has just written its own copy under the module's
   > `Migrations/` folder, and a consuming project like SYSTEM_SHELL
   > auto-loads those *in addition to* the top-level folder. Both copies now
   > create the same table, so the next `migrate --fresh` — exactly what
   > `RefreshDatabase` runs at the start of any test suite — dies with
   > "table already exists", surfacing as unrelated tests failing with
   > nothing pointing at fixture migrations. The `--force` regenerate in
   > step 3 rewrites the module-scoped copy only, so removing the top-level
   > one here is safe.
   >
   > ```bash
   > rm -f /path/to/consuming-project/BACKEND/database/migrations/2026_08_02_1200*_create_purchase_orders_table.php
   > ```

2. **Hand-edit `module.json`**, adding an `actions` entry and the
   `bulk_actions`/`export`/`import` entries (exactly what's in
   [`actions_config.php`](./actions_config.php)):
   ```json
   {
     "actions": {
       "approve": {
         "name": "approve",
         "label": "Approve",
         "hasUI": true,
         "uiType": "modal",
         "urlParams": ["uuid"],
         "operations": {
           "create": {"enabled": true, "endpoint": {"method": "POST", "path": "/purchase-orders/{uuid}/approve"}}
         }
       },
       "archiveByYear": {
         "name": "archiveByYear",
         "label": "Archive (by fiscal year)",
         "hasUI": true,
         "uiType": "modal",
         "urlParams": ["uuid", "year"],
         "operations": {
           "create": {"enabled": true, "endpoint": {"method": "POST", "path": "/purchase-orders/{uuid}/archive-by-year/{year}"}}
         }
       }
     },
     "features": {
       "backend": {
         "list": {
           "bulk_actions": [
             {"key": "archive", "label": "Archive"}
           ],
           "export": true,
           "import": true
         }
       }
     }
   }
   ```
3. **Regenerate with `--force`**:
   ```bash
   php artisan make:module Custom/PurchaseOrders --force
   ```
4. **Hand-fill the actual transition logic.** The generator only scaffolds
   the wrapper — `Services/PurchaseOrdersApproveService.php`'s `process()`
   method is a literal `// Add your custom logic here` stub. For a real
   `approve` action you'd add:
   ```php
   protected function process(array $data, string $uuid, array $params = []): array
   {
       $model = PurchaseOrdersModel::where('uuid', $uuid)->firstOrFail();
       $model->update(['status' => 'approved']);
       return Helpers::success($model->fresh(), 'Purchase order approved');
   }
   ```
5. **Confirm**:
   - `Routes/api.php` has `POST /purchase-orders/{uuid}/approve` (permission
     `PurchaseOrders.approve`), `POST /purchase-orders/{uuid}/archive-by-year/{year}`
     (permission `PurchaseOrders.archiveByYear`), and the always-present
     `POST /purchase-orders/bulk-action` (permission
     `PurchaseOrders.bulkAction`, routes every bulk-action key through one
     shared controller method, dispatching on the `action` field in the
     request body).
   - `Tests/PurchaseOrdersApproveServiceTest.php` exists with two methods
     (permission-gated contract + non-5xx). `Tests/PurchaseOrdersArchiveByYearServiceTest.php`
     does **not** exist at all — confirming the `urlParams: ['uuid', 'year']`
     coverage-gating behavior live, not just by reading generator source.
   - `Services/PurchaseOrdersArchiveService.php` exists with a
     **static** `execute(array $data, array $params): array` — note this is
     a **different calling convention** than `ActionServiceGenerator`'s
     **instance** `execute()`. They are two unrelated mechanisms that
     happen to share the word "action".
   - `Routes/api.php` also has `GET /purchase-orders/list/export` (permission
     `PurchaseOrders.list` — export reuses the list permission, no dedicated
     one), `GET /purchase-orders/import/template`, and
     `POST /purchase-orders/import` (both permission `PurchaseOrders.import`).
   - `PurchaseOrdersController@exportPurchaseOrders`/`@importTemplatePurchaseOrders`/
     `@importPurchaseOrders` exist and delegate to
     `PurchaseOrdersListService::execute(..., export: true, ...)` /
     `::getImportTemplate()` / `::importData()`.
   - The generated `PurchaseOrdersListPage.vue` renders the export dropdown
     and import dialog from `CrudListPanel`/`ListTable` (gated by
     `:enable-export`/`:enable-import`, both `true` here), and the bulk-action
     toolbar shows the single `Archive` action once a row is selected.

## Known limitation / gotcha — `bulk_actions[].status_target` needs `status_id`, not a string column

`BulkActionServiceGenerator` hardcodes the transition column name:
```php
$model->update(['status_id' => {Module}Model::{CONST}]);
```
This assumes the table has an **integer** `status_id` column (an FK-style
reference into this project's real, shared `Statuses` lookup module — the
convention used elsewhere in the project), and that `{Module}Model` has a
matching PHP constant holding the correct **numeric** status id (from the
`constants` config key — a completely separate hand-authored config,
unrelated to `bulk_actions` itself; see `ModelGenerator::generateConstants()`).
It does **not** work against a plain string `status` column like this
fixture's `purchase_orders.status` — using `status_target` here would
generate a reference to a `status_id` column that doesn't exist.

This fixture deliberately stays with the generic (no `status_target`) bulk
action shape to remain self-contained, rather than depending on a seeded
`Statuses` table with specific, known numeric ids. If you do want the
`status_target` shape for a real module, you need, together:
```json
{
  "constants": {"RECEIVED": 3},
  "features": {"backend": {"list": {"bulk_actions": [
    {"key": "markReceived", "status_target": "RECEIVED", "label": "Mark Received"}
  ]}}}
}
```
where `3` is the real, seeded row id of the matching `Statuses` record —
not a string, not the status's own name.

## Known limitation — `archiveByYear` gets zero PHPUnit coverage by design

`PhpUnitTestGenerator::buildActionServiceTestMethodsForKey()` only emits a
contract test (route registered + requires its permission, never hard-fails)
when an action's `urlParams` is empty (the default route shape) or exactly
`['uuid']`. `archiveByYear`'s `urlParams: ['uuid', 'year']` is neither, so
`Tests/PurchaseOrdersArchiveByYearServiceTest.php` is never written at
all — confirmed by regenerating this exact fixture (2026-08-08): the route,
`Services/PurchaseOrdersArchiveByYearService.php`, and the frontend form all
scaffold correctly, but there's no PHPUnit backend test for any of it.

This is deliberate, not a bug: resolving a multi-param path exactly would
mean duplicating `RoutesGenerator::generateActionRoutes()`'s full
path-building logic inside the test generator for a value it has no way to
independently verify is correct — the generator would rather emit nothing
than guess wrong. If backend coverage matters for a multi-param action,
write the contract test by hand, mirroring `PurchaseOrdersApproveServiceTest.php`'s
shape but substituting the real path (`'/api/purchase-orders/' . $fixture->uuid . '/archive-by-year/2026'`,
i.e. a literal year value — there's no fixture helper for the extra param).

## Another gotcha found while building this fixture — bulk_actions dropped by `--force`

`ModuleScaffolder::mergePersistedFields()` (SYSTEM_SHELL-side) never carried
`features.backend.list.bulk_actions` forward across a `--force` regenerate —
the exact same bug class as `inline_items`' own `mergePersistedFields` gap
(fixed earlier, generator-engine v2.25.0 era), just at a nested config path
instead of a top-level key. Confirmed live: after a `--force` regenerate,
`PurchaseOrdersArchiveService.php` was silently never written, and
`module.json`'s `bulk_actions` entry had vanished, while the sibling
top-level `actions` key (already covered by the existing preserved-fields
list) correctly survived the same regenerate. Fixed in the same
`ModuleScaffolder.php` this session.

`features.backend.list.export`/`.import` — added to this fixture 2026-08-06
— are exactly the same shape of gap (plain booleans, hand-added, living
under `features`, never derived from introspection) and were added to
`mergePersistedFields()`'s preserved-fields list proactively, alongside
`bulk_actions`, before this fixture's own live-verification pass could
rediscover the identical bug a third time.

## How to use it: fast integration testing without a real DB

Same pattern as the other suites — `columns.php` in this directory is
dumped from a real `SchemaIntrospector` run, not hand-typed, and
`actions_config.php` is the machine-readable form of the hand-authored
`actions`/`bulk_actions`/`export`/`import` config layer from step 2 above,
ready to `require` directly in a test and merge onto the introspected
config — see `orders-suite/inline_items_config.php` and
`InlineItemsEndToEndTest.php` for the pattern this mirrors.
