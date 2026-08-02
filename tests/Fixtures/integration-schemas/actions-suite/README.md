# actions-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine`'s two
custom-action mechanisms — a single `actions` state-transition action, and a
`bulk_actions` operation on the list feature — end-to-end against a real
consuming Laravel project (SYSTEM_SHELL). Sibling to
`items-suite`/`orders-suite`/`morphs-suite`/`delegations-suite`.

## What this fixture covers

A POS/inventory-flavored scenario: a Purchase Order sent to a supplier moves
through states (`draft` → `approved` → `received`/`cancelled`). This fixture
exercises:

- **`actions`** — a single `approve` action (`draft` → `approved`),
  demonstrating the route/controller/service scaffolding this mechanism
  produces. The transition logic itself is **not** auto-generated — you
  hand-fill it.
- **`bulk_actions`** (`features.backend.list.bulk_actions`, a *separate*
  config location from `actions`) — a generic `archive` bulk action (no
  `status_target`), demonstrating the bulk-action route/service/frontend
  toolbar wiring.

| Table | Scenario exercised |
|---|---|
| `purchase_orders` | `status` is a plain string column — deliberately **not** the `status_id` + Statuses-lookup convention `bulk_actions[].status_target` expects (see "Known limitation" below) |

Both `actions` and `bulk_actions` are **entirely hand-authored** — nothing
here is introspected. Confirmed (via a full JSON scan of every real
`module.json` in this project) that neither mechanism has a single non-empty
real-world usage anywhere in the project today — this fixture is the first.

## How to use it: full end-to-end validation

1. **Copy the migration**, run it, generate the module normally first:
   ```bash
   cp tests/Fixtures/integration-schemas/actions-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   php artisan migrate
   php artisan make:module Custom/PurchaseOrders
   ```
2. **Hand-edit `module.json`**, adding both an `actions` entry and a
   `bulk_actions` entry:
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
       }
     },
     "features": {
       "backend": {
         "list": {
           "bulk_actions": [
             {"key": "archive", "label": "Archive"}
           ]
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
     `PurchaseOrders.approve`) and the always-present
     `POST /purchase-orders/bulk-action` (permission
     `PurchaseOrders.bulkAction`, routes every bulk-action key through one
     shared controller method, dispatching on the `action` field in the
     request body).
   - `Services/PurchaseOrdersArchiveService.php` exists with a
     **static** `execute(array $data, array $params): array` — note this is
     a **different calling convention** than `ActionServiceGenerator`'s
     **instance** `execute()`. They are two unrelated mechanisms that
     happen to share the word "action".

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

## How to use it: fast integration testing without a real DB

Same pattern as the other suites — `columns.php` in this directory is
dumped from a real `SchemaIntrospector` run, not hand-typed.
