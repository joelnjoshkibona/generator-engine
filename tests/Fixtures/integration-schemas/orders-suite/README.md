# orders-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine`'s
`inline_items` parent-child feature end-to-end — the exact "Order Items"
scenario the package's own README uses as its canonical `inline_items`
example, finally exercised for real. Sibling to `items-suite` (same
conventions, same rigor), but `items-suite` has no `inline_items` coverage
at all; this suite exists specifically to fill that gap.

## What this fixture covers

| Table | Scenario exercised |
|---|---|
| `orders` | Parent entity, displayed by `order_number` (deliberately not `name` — also exercises the FK-display-field fix, v2.23.0, if anything points an FK at orders) |
| `order_items` | Child of `orders` (`order_id`, required); managed **inline** from Orders' own create/edit form via `inline_items`, not through its own list/create/edit pages, even though it's scaffolded as a full standalone module too |

Together with `inline_items_config.php` (the hand-authored `inline_items`
config layered onto Orders' introspected config — `inline_items` is never
DB-introspected, exactly like real usage), this exercises:

- The generator-engine v2.23.0 wrapper-component mechanism
  (`{Module}{Key}InlineItems.vue`, write-once, TODO-stubbed hooks) on both
  CreateForm and EditForm.
- Backend `inline_items` code generation: `[[inlineItemsExtract]]` /
  `[[inlineItemsSave]]` (create), `[[inlineItemsSync]]` (edit — update
  existing rows by uuid, delete rows no longer present, insert new ones),
  `[[inlineItemsLoad]]` (view).
- `inject_from_parent` (copying `orders.currency` onto every `order_items`
  row at save time), not just the always-present `parent_fk` pairing.
- Child-module namespace resolution for a `Custom`-grouped child
  (`child_group => 'Custom'`) — see `inline_items_config.php`'s docblock
  for the real, confirmed bug this combination found in
  `BaseServiceGenerator::buildChildNamespace()`.

### Why `total_amount` is `decimal(14,2)` and `unit_price` is `decimal(10,4)`

Same reasoning as items-suite's `price` column: `MigrationGenerator` falls
back to `(10,2)` when precision/scale are absent from config, so a
`(10,2)`-shaped fixture column can't detect a "precision never
introspected" regression — the output looks right by coincidence. Neither
column here matches that fallback, and the two decimal columns
deliberately use different scales (2 vs 4) so a copy-paste mix-up between
them in generated code would also be caught.

## Contents

- **`migrations/`** — 2 Laravel migration files, in FK-dependency order
  (`orders` → `order_items`). Same column-type/uuid/timestamps/soft-deletes/
  audit-column/index conventions as items-suite and SYSTEM_SHELL's real
  migrations. No hard DB-level foreign key constraints, matching this
  project's convention.
- **`columns.php`** — a companion PHP file returning an array keyed by
  table name, shaped exactly like `SchemaIntrospector::columns()`'s
  output, hand-derived from the migrations above.
- **`inline_items_config.php`** — the `inline_items` array to merge into
  Orders' built config (`$config['inline_items'] = require
  __DIR__.'/inline_items_config.php'` after building Orders' config the
  normal way — see "How to use it" below). Kept separate from
  `columns.php` because `inline_items` is never schema-derived, exactly
  like a real developer would hand-add it to a generated `module.json`
  after the initial `make:module` scaffold.

## How to use it: full end-to-end validation

1. **Copy the migrations** into the consuming project's migrations folder
   and run them:

   ```bash
   cp tests/Fixtures/integration-schemas/orders-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   php artisan migrate
   ```

2. **Scaffold OrderItems first, then Orders** (child before parent — same
   dependency-order reasoning as items-suite: `Orders.order_items` config
   references `OrderItems`' namespace, so `OrderItems` must already be
   registered):

   ```bash
   php artisan make:module Custom/OrderItems
   php artisan make:module Custom/Orders
   ```

   > **Delete the copied migrations NOW — before any test run, not at
   > step 5.** `make:module` has just written its own copy of each migration
   > under the module's `Migrations/` folder, and a consuming project like
   > SYSTEM_SHELL auto-loads those *in addition to* the top-level folder.
   > Both copies now create the same table, so the next `migrate --fresh` —
   > exactly what `RefreshDatabase` runs at the start of any test suite —
   > dies with "table already exists", surfacing as unrelated tests failing
   > with nothing pointing at fixture migrations. The `--force`
   > regenerations in steps 2–3 below rewrite the module-scoped copy only,
   > so removing the top-level one here is safe.
   >
   > ```bash
   > rm -f /path/to/consuming-project/BACKEND/database/migrations/2026_08_03_0900*_create_order*_table.php
   > ```

   **Known limitation (found live 2026-08-08, improved but not fully
   solved 2026-08-08):** `OrderItems` is scaffolded here before `Orders`
   exists anywhere — not just unregistered, genuinely not yet generated —
   so `OrderItemsModel`'s generated `order(): BelongsTo` relation cannot
   know Orders is `Custom`-grouped and silently guesses
   `\App\Project\Modules\Core\Orders\OrdersModel::class` instead of the
   real `Custom` namespace. This is a different bug from the
   `inline_items`-specific one `inline_items_config.php`'s docblock
   documents (`BaseServiceGenerator::buildChildNamespace()`, already
   fixed) — this one is in `ModelGenerator`'s ordinary FK-relation
   namespace resolution (`PathManager::resolveBackendModuleNamespace()`),
   and it affects any real-FK child-before-parent scaffold, not just
   `inline_items`. There is no way to resolve it correctly at the moment
   `OrderItems` is generated (`Orders` truly doesn't exist yet to resolve
   against). **Fix: after scaffolding `Orders`, regenerate `OrderItems`
   once more so its `Model` picks up the now-real namespace:**

   ```bash
   php artisan make:module Custom/OrderItems --force
   ```

   A misresolved namespace still prints a warning
   (`PathManager::reportIssue()`, routed through `$this->warn()`), so watch
   the `make:module Custom/OrderItems` output for a
   "not found in project or shell registry" message before proceeding to
   step 3.

3. **Layer `inline_items` onto Orders' `module.json`.** `make:module`
   never emits `inline_items` itself (it's hand-authored, not
   DB-introspected) — merge `inline_items_config.php`'s array into
   `Orders/module.json`'s top level, then regenerate Orders'
   forms/services so the wrapper component and backend save/sync/load
   code get emitted:

   ```bash
   php artisan make:module Custom/Orders --force --schema=/path/to/merged-orders-config.json
   ```

4. **Test.** Confirm:
   - `OrdersCreateForm.vue`/`OrdersEditForm.vue` render `<{Module}OrderItemsInlineItems>`
     (the wrapper component) instead of `<InlineItemsComponent>` directly,
     and the wrapper file itself (`Components/OrdersOrderItemsInlineItems.vue`)
     exists with the four configured fields and TODO-stubbed
     `dynamicDisabled`/`showField`/`render` hooks.
   - Creating an Order with 2-3 nested order items via the generated UI
     (or a direct API call) actually persists matching `order_items` rows
     with the correct `order_id` and `currency` (copied from the parent,
     not user-entered).
   - Editing an existing Order — removing one item, changing another,
     adding a new one — correctly syncs: removed rows are deleted, changed
     rows are updated in place (matched by `uuid`), new rows are inserted.
   - Viewing an Order's details loads its `order_items` alongside the
     parent record.
   - Hand-edit the wrapper component (add a real `dynamicDisabled` hook —
     e.g. disable `unit_price` when `quantity` is 0), then re-run
     `make:module Custom/Orders --force` — confirm the hand-edit survives
     (write-once) AND that `inline_items` itself survives the regenerate
     (was a separate confirmed bug — see `ModuleScaffolder::
     mergePersistedFields()`, never carried `inline_items` forward before
     this suite caught it).

5. **Clean up** when done: drop the two tables, delete the generated module
   directories, and revert the shared files `make:module` also writes to
   (`BACKEND/app/Project/_Src/registry.json`, `FRONTEND/src/modules.json`,
   `FRONTEND/src/menus.json`). The copied top-level migrations are already
   gone — step 2 removed them. This fixture's own copies are never modified
   by any of this. Confirm `git status --short` is clean in both BACKEND and
   FRONTEND — these modules are throwaway and must never be committed.

## How to use it: fast integration testing without a real DB

For PHPUnit tests that exercise the real generator classes (CreateFormGenerator,
EditFormGenerator, CreateServiceGenerator, EditServiceGenerator,
ViewServiceGenerator) against a temp filesystem — no live DB, no
`make:module` Artisan command — load `columns.php` and
`inline_items_config.php` directly and build both modules' configs by
hand, same pattern items-suite's README documents for `IntrospectionToConfig`
alone:

```php
$allTables = require __DIR__ . '/../../Fixtures/integration-schemas/orders-suite/columns.php';
$inlineItems = require __DIR__ . '/../../Fixtures/integration-schemas/orders-suite/inline_items_config.php';

$ordersConfig = (new IntrospectionToConfig())->build($allTables['orders'], [
    'module_name' => 'Orders',
    'module_type' => 'Custom',
    'table_name'  => 'orders',
    'id_type'     => 'uuid',
]);
$ordersConfig = array_merge($ordersConfig, $inlineItems);
```

See `tests/Unit/Generators/InlineItemsEndToEndTest.php` for the real,
currently-passing version of this.

## Delete behavior (resolved 2026-08-02)

Deleting an Order now cascade-deletes its `order_items` rows automatically
— `DeleteServiceGenerator` emits `OrderItemsModel::where('order_id',
$model->id)->delete()` right after `$model->delete()`, unconditionally, no
config needed. Was originally deferred as a "known limitation, needs a
design decision" note; resolved with cascade as the default rather than
block, because inline_items children have no independent lifecycle to begin
with (edit-sync already deletes any child row dropped from the parent
form's payload — see `EditServiceGenerator::generateInlineItemsSync()`).

Separately (and this was true before this fix too, and needed no change):
`OrdersDeleteCheckService` already flags `order_items` as a blocking
dependent generically, via `DeleteCheckServiceGenerator`'s FK-graph
detection — `order_id` matches the naming-convention heuristic against the
`orders` table regardless of `inline_items`. Confirmed live: the generated
`OrdersDeleteCheckService::getCreatedRecordsCount()` already contains
`OrderItemsModel::where('order_id', $record->id)->count()`. What it does
*not* do is stop a direct call to the delete endpoint from bypassing that
check — no module's `DeleteService` calls its own `DeleteCheckService`
first, which is a separate, larger architectural question than inline_items
scope covers.
