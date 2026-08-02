# generator-engine Cookbook

Task-oriented recipes for building specific kinds of modules. `README.md`
documents the package's architecture and public API; this doc answers "I
want to build a module that does X — what config do I write, what commands
do I run, and what does the result look like?" for every distinct module
shape the engine supports.

Every recipe below points at a real, permanent, tested fixture under
`tests/Fixtures/integration-schemas/` — not a hypothetical snippet. Where a
recipe says "see `items-suite`", that fixture's own `README.md` has the full
migration, the full generated-output expectations, and step-by-step
commands that have actually been run against a real SYSTEM_SHELL instance.
None of this is theoretical — every fixture referenced here has been
generated for real, against a real database, at least once.

## Contents

1. [The generate → hand-edit → regenerate loop](#1-the-generate--hand-edit--regenerate-loop)
2. [Recipe: a simple lookup module](#2-recipe-a-simple-lookup-module)
3. [Recipe: a module with FK relationships](#3-recipe-a-module-with-fk-relationships)
4. [Recipe: a self-referential module (tree/hierarchy)](#4-recipe-a-self-referential-module-treehierarchy)
5. [Recipe: a file-upload / media field](#5-recipe-a-file-upload--media-field)
6. [Recipe: a parent-child module (inline_items)](#6-recipe-a-parent-child-module-inline_items)
7. [Recipe: a polymorphic relationship (morphs)](#7-recipe-a-polymorphic-relationship-morphs)
8. [Recipe: related records as sub-tabs (delegations)](#8-recipe-related-records-as-sub-tabs-delegations)
9. [Recipe: a custom action / bulk action](#9-recipe-a-custom-action--bulk-action)
10. [Picking the right combination](#10-picking-the-right-combination)
11. [Common gotchas](#11-common-gotchas)

---

## 1. The generate → hand-edit → regenerate loop

Every module — no matter which recipe below it follows — goes through the
same three-step loop. Understanding this loop up front makes every recipe
below a variation on one theme, not a new mental model each time.

**Step 1 — introspect and generate.** Point the engine at a real table and
let it build everything schema alone can determine (columns, types,
required-ness, FKs by naming convention, `morphs` pairs by column-pair
convention):

```bash
php artisan make:module Custom/Orders
```

This writes a `module.json` next to the generated module — the *persisted,
resolved config* for that module. Everything after this point revolves
around that file.

**Step 2 — hand-edit what schema can't tell the engine.** Some config is
never introspected, because it isn't a fact about the database — it's a
decision a developer makes (which related modules to embed as sub-tabs,
which custom actions exist, how a parent-child relationship should behave).
Open the generated `module.json` and add the relevant key by hand:
`inline_items`, `delegations`, `actions`, `menu_config`, `morphs[].targets`,
etc. Every recipe below that needs this step shows the exact keys to add.

**Step 3 — regenerate with `--force`.** Re-run the same command with
`--force` so the engine picks up the hand-edited config:

```bash
php artisan make:module Custom/Orders --force
```

`ModuleScaffolder::mergePersistedFields()` carries every hand-authored key
forward from the existing `module.json` into the fresh regenerate — that's
what makes step 2's edit actually take effect, and what makes it safe to
run `--force` again later (e.g. after adding a new column) without losing
the hand-authored config. A small number of generated files are explicitly
**write-once** regardless of `--force` (see [§11](#11-common-gotchas)) —
those are meant to be hand-edited too, just never overwritten again once
they exist.

That's the whole loop. Every recipe from here on is just "which keys go in
step 2, if any."

---

## 2. Recipe: a simple lookup module

The simplest case: no FKs, no relationships, just a name/description table
(status lists, category lists, type lists).

**Reference fixture:** `items-suite`'s `ItemTypes` table (`tests/Fixtures/integration-schemas/items-suite/`).

**Migration shape:** a plain table with the standard audit columns
(`created_by_id`/`updated_by_id`), `timestamps()`, `softDeletes()`, and a
`uuid()` — no foreign keys at all.

**Config:** none needed — a lookup table needs zero hand-authored config.
Introspection alone is enough:

```bash
php artisan make:module Custom/ItemTypes
```

**What you get:** full CRUD (list/create/edit/view/delete), a simple text
form, no relationship pickers.

---

## 3. Recipe: a module with FK relationships

A module that references one or more other modules (a "main entity" with
lookups).

**Reference fixture:** `items-suite`'s `Items` table — has **two** required
FKs (`item_type_id` → `ItemTypes`, `item_category_id` → `ItemCategories`).

**Migration shape:** `$table->foreignId('item_type_id');` — a plain indexed
`unsignedBigInteger`, **no DB-level FK constraint** (this project's
convention; see [§11](#11-common-gotchas) for why a real constraint isn't
required for the engine to detect the relationship).

**Generation order matters.** FK-referenced modules must be scaffolded
*before* the module that references them, or the referencing module's
generated code can't resolve a correct namespace/import for the related
model:

```bash
php artisan make:module Custom/ItemTypes
php artisan make:module Custom/ItemCategories
php artisan make:module Custom/Items          # references both of the above
```

**Config:** none needed — FK detection is automatic (naming-convention
based: `item_type_id` matches the `item_types`/`ItemTypes` module).

**What you get:** the `Items` create/edit form offers a dropdown/picker for
each FK; the list and view pages resolve and display the related record's
label, not just its raw id.

---

## 4. Recipe: a self-referential module (tree/hierarchy)

A module whose FK points at its own table — a parent/child tree within one
module (categories with sub-categories, locations with sub-locations).

**Reference fixture:** `items-suite`'s `ItemCategories` table —
`parent_id` → `item_categories.id` (mirrors this project's real `Locations`
module, which has the identical pattern).

**Migration shape:** `$table->foreignId('parent_id')->nullable();` — nullable
so a top-level record needs no parent.

**Config:** none needed — self-referential FK detection is the same
naming-convention mechanism as any other FK, it just happens to point at
the module's own table.

```bash
php artisan make:module Custom/ItemCategories
```

**What you get:** the create/edit form offers a parent-category picker
(itself excluded from its own options, so a record can't be its own
parent); the list/view pages can render the tree depth or breadcrumb.

---

## 5. Recipe: a file-upload / media field

A column that should render as a file/image upload, not a plain text
input, and stores a reference to the project's Media module.

**Reference fixture:** `items-suite`'s `ItemImages` table —
`image_media_id` is the file-upload column.

**Migration shape:** the file column is just a plain
`$table->unsignedBigInteger('image_media_id');` — indexed, **no hard FK
constraint** to the `media` table (this project's convention: "Media
integration: Files are stored via the Media module... Models reference
media via `media_id` foreign keys (indexed, no hard FK constraints)").
Nothing in the column's type or name alone tells the engine it's a
file-upload field — that's exactly what step 2 below is for.

**Config — the one flag this recipe needs:** pass `--file-columns` at
generation time, comma-separated if there's more than one such column on
the table:

```bash
php artisan make:module Custom/ItemImages --file-columns=image_media_id
```

This sets `IntrospectionToConfig`'s `file_columns` meta option, which
routes that field to `field_type: file-input` instead of a plain text
input on the generated form.

**What you get:** the create/edit form renders `image_media_id` as a real
file upload widget (`FileInputField` + `sendFormDataRequest`, per this
project's convention — booleans get sent as `1`/`0` in the resulting
FormData), and the generated Create/Edit service handles the upload via
`MediaService::createFile()` before persisting the record.

---

## 6. Recipe: a parent-child module (inline_items)

A form where a parent record (an Order) is created/edited together with a
list of child rows (its Order Items) in the same UI, in one submit — not
two separate modules a user navigates between.

**Reference fixture:** `orders-suite`'s `Orders`/`OrderItems` pair
(`tests/Fixtures/integration-schemas/orders-suite/`) — the canonical,
fully-verified example.

**This is entirely hand-authored config — nothing here is introspected.**
Follow the full three-step loop from [§1](#1-the-generate--hand-edit--regenerate-loop):

1. Generate the child module normally (it's an ordinary module on its own —
   `OrderItems` has no special config):
   ```bash
   php artisan make:module Custom/OrderItems
   ```
2. Generate the parent module normally too:
   ```bash
   php artisan make:module Custom/Orders
   ```
3. Hand-edit the parent's `module.json`, adding an `inline_items` entry:
   ```json
   "inline_items": [{
     "key": "order_items",
     "label": "Order Items",
     "child_module": "OrderItems",
     "child_group": "Custom",
     "child_has_creator_updater": true,
     "parent_fk": "order_id",
     "primary_field": "product_name",
     "fields": [
       {"key": "product_name", "label": "Product", "type": "text", "required": true},
       {"key": "quantity", "label": "Qty", "type": "number", "required": true},
       {"key": "unit_price", "label": "Unit Price", "type": "number", "required": true, "decimals": 4}
     ],
     "inject_from_parent": [
       {"child_field": "currency", "parent_field": "currency"}
     ]
   }]
   ```
   `inline_items` is a **flat list** — not a map keyed by a group/label
   name (an earlier version of this package's docs got this wrong; see
   `README.md`'s `inline_items shape` section for the full story).
   `child_has_creator_updater: true` is required if — as is the project's
   default convention — the child table has `created_by_id`/`updated_by_id`
   columns; omitting it when the child needs it fatal-errors on the first
   real create. `inject_from_parent` is optional — it copies a value
   straight from the parent model onto every child row at save time (here,
   the currency the order was placed in).
4. Regenerate the parent with `--force` to pick up the hand-edited config:
   ```bash
   php artisan make:module Custom/Orders --force
   ```

**What you get:**
- The Orders create/edit form gets an embedded, hand-edit-protected wrapper
  component (`OrdersOrderItemsInlineItems.vue`) rendering the child rows
  inline — written once, never touched by future regeneration, so you can
  freely add per-field logic (`dynamicDisabled`, `showField`, cross-field
  totals on `@item-change`) without it being clobbered by a later `--force`.
- `OrdersCreateService` saves every child row on create.
- `OrdersEditService` syncs on edit: rows removed from the form are
  deleted, rows matched by `uuid` are updated in place, new rows (no
  `uuid` yet) are inserted.
- `OrdersViewService` loads `order_items` alongside the parent record.
- `OrdersDeleteService` cascade-deletes every `order_items` row when the
  parent Order is deleted (respecting the child's own soft/hard delete
  mode automatically).
- `OrdersDeleteCheckService`'s dependent-count check already flags
  `order_items` as a blocking dependent too — for free, via the same
  generic FK-graph naming-convention detection that covers any `*_id`
  column, entirely independent of the `inline_items` config above.

---

## 7. Recipe: a polymorphic relationship (morphs)

A single table (e.g. Payments) that can belong to more than one kind of
parent (e.g. a Supplier payment or a Customer payment), instead of one
near-duplicate table per parent type.

**Reference fixture:** `morphs-suite`'s `Payments` table (belongs to either
`Suppliers` or `Customers`), `tests/Fixtures/integration-schemas/morphs-suite/`.

**Fully schema-derived — no config editing at all.** A column literally
named `{prefix}_type` (string) paired with `{prefix}_id` (integer), same
prefix, is enough:

```php
$table->string('payable_type');
$table->unsignedBigInteger('payable_id');
// equivalently: $table->morphs('payable');
```

```bash
php artisan make:module Custom/Suppliers
php artisan make:module Custom/Customers
php artisan make:module Custom/Payments
```
Generation order doesn't matter here — unlike a normal FK, `morphTo()`
resolves its target at *runtime* (from whatever class name is stored in
`payable_type`), not at generation time, so there's no "referenced module
must already exist" requirement like [§3](#3-recipe-a-module-with-fk-relationships) has.

**What you get:** `PaymentsModel` gets a real `payable(): MorphTo { return
$this->morphTo(); }` method. The migration collapses back to a single
`$table->morphs('payable');` call (with no duplicate index — a real bug
found and fixed while building this fixture) if ever regenerated.

**What you do NOT get, and have to hand-add yourself:**
- **The inverse relationship.** Only the owning side (`Payments`) gets a
  relationship method. `Suppliers`/`Customers` do not automatically get a
  `payments(): MorphMany` — add that to the model by hand if you want
  `$supplier->payments` to work.
- **A morph map.** `payable_type` stores the raw fully-qualified class name
  by default (`App\Project\Modules\Custom\Suppliers\SuppliersModel`) unless
  you register `Relation::morphMap([...])` yourself, typically in a service
  provider. The `targets` key you'll see in the generated `morphs` config
  entry (`'targets' => []`) is purely a place for a human to document which
  concrete models a polymorphic column can point at — no generator reads it
  to register a morph map or drive anything in the UI.

---

## 8. Recipe: related records as sub-tabs (delegations)

A module's view page shows a **different, independently-addressable**
module's records, scoped to it — e.g. a Warehouse's view page has a "Stock
Movements" tab showing every movement recorded against that warehouse.
Different from [§6](#6-recipe-a-parent-child-module-inline_items): a
delegation's child rows are NOT synced from the parent's own form submit —
the child module has its own full, independent CRUD (its own routes,
controller, list page if you navigate to it directly); the parent's view
page just embeds a scoped view of it.

**Reference fixture:** `delegations-suite`'s `Warehouses`/`StockMovements`
pair, `tests/Fixtures/integration-schemas/delegations-suite/`.

**Entirely hand-authored, like `inline_items`.** Follow the three-step loop:

1. Generate both modules normally — `StockMovements` needs nothing special:
   ```bash
   php artisan make:module Custom/Warehouses
   php artisan make:module Custom/StockMovements
   ```
2. Hand-edit `Warehouses`' `module.json`, adding a `delegations` entry:
   ```json
   "delegations": {
     "stockMovements": {
       "name": "StockMovements",
       "label": "Stock Movements",
       "uiType": "tab",
       "relatedModule": {"name": "StockMovements", "group": "Custom"},
       "filterKey": "warehouse_id",
       "parentKey": "uuid",
       "parentIdField": "id",
       "operations": {
         "list":   {"enabled": true},
         "create": {
           "enabled": true,
           "backend":  {"fields": [
             {"field": "item_name", "rules": "required|string|max:255"},
             {"field": "quantity", "rules": "required|integer"}
           ]},
           "frontend": {"fields": [
             {"field": "item_name", "label": "Item", "field_type": "input", "type": "text", "required": true},
             {"field": "quantity", "label": "Quantity", "field_type": "number-input", "type": "number", "required": true}
           ]}
         },
         "view":   {"enabled": true},
         "delete": {"enabled": true}
       }
     }
   }
   ```
   `list` almost always leaves `frontend.fields` empty — the generated tab
   falls back to the **related module's own** persisted `module.json`
   `features.frontend.list.fields` for its columns. `create`/`edit` don't
   get that fallback: you must supply `frontend.fields` (the form) **and**
   `backend.fields` (the validation rules — two separate things). Skipping
   `backend.fields` doesn't error; it silently validates away every
   submitted field except the parent FK and audit columns, because Laravel's
   `validate()` only returns declared rule keys.
3. Regenerate with `--force`:
   ```bash
   php artisan make:module Custom/Warehouses --force
   ```

**What you get:** a real nested backend endpoint per enabled operation
(`GET /warehouses/{uuid}/stock-movements/list`, etc.), a
`WarehousesStockMovementsService.php` scoping every query by
`warehouse_id = $parent->id`, and a `WarehousesStockMovementsTab.vue` wired
into both the view modal's tabs and the details page's nested route.

**Known limitation — don't mix delegation and standalone create/edit access
without a deliberate plan.** The generator writes the related module's
`CreateForm.vue`/`EditForm.vue` into that module's own, single canonical
`Components/` path — the same file its own independent scaffold already
wrote. Generating the delegation **overwrites** that file with a field list
that deliberately excludes the parent FK column (the tab supplies it via
props instead). If `StockMovements` is ever accessed **standalone** (not
through the Warehouses tab), its create/edit form has no way to set
`warehouse_id` at all — confirmed live while building this fixture. If a
module needs both delegation access and real standalone create/edit, this
needs an explicit decision on your part (keep the FK field in both field
lists and rely on `hiddens`/`defaults` props — the generated component
already supports both — rather than physically omitting the field).

---

## 9. Recipe: a custom action / bulk action

A state-transition button (Approve, Reject, Mark Received) that isn't part
of ordinary create/edit/delete — either a single-record action, or a
list-level bulk action applied to many selected records at once. These are
**two separate, unrelated mechanisms** that happen to share the word
"action" — don't confuse them.

**Reference fixture:** `actions-suite`'s `PurchaseOrders` module,
`tests/Fixtures/integration-schemas/actions-suite/`.

**Single custom action — `actions` config key**, hand-authored:
```json
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
}
```
Regenerate with `--force`, then **hand-fill the actual logic** — the
generator only scaffolds the wrapper. `Services/PurchaseOrdersApproveService.php`'s
`process()` method is a literal `// Add your custom logic here` stub:
```php
protected function process(array $data, string $uuid, array $params = []): array
{
    $model = PurchaseOrdersModel::where('uuid', $uuid)->firstOrFail();
    $model->update(['status' => 'approved']);
    return Helpers::success($model->fresh(), 'Purchase order approved');
}
```
You also get a real route (`POST /purchase-orders/{uuid}/approve`,
permission `PurchaseOrders.approve`) and — since `hasUI: true` — a
`PurchaseOrdersApproveForm.vue` you fill with real fields, plus a button
wired into the view page (`placement: 'main'` for a primary footer button,
or the default `'more'` for the dropdown menu).

**Bulk action — a *different*, nested config location:**
`features.backend.list.bulk_actions`, **not** part of `actions` at all, no
shared shape:
```json
{"features": {"backend": {"list": {"bulk_actions": [
  {"key": "archive", "label": "Archive"}
]}}}}
```
This emits `Services/PurchaseOrdersArchiveService.php` with a **static**
`execute(array $data, array $params): array` — note the different calling
convention from the single-action mechanism's **instance** `execute()`.
Without a `status_target`, it's the same kind of empty TODO stub as a
single action. **With** `status_target`, it auto-generates a real
transition — but read the gotcha below before reaching for it.

**Gotcha — `status_target` assumes a `status_id` FK column, not a string.**
```php
// what status_target => 'RECEIVED' actually generates:
$model->update(['status_id' => PurchaseOrdersModel::RECEIVED]);
```
This hardcodes the column name `status_id` (an integer FK-style reference
into this project's real, shared `Statuses` lookup module) and requires a
matching PHP constant on the model holding the correct **numeric** id — from
a completely separate `constants` config key:
```json
{"constants": {"RECEIVED": 3}}
```
where `3` is the actual seeded row id, not the status's name. Using
`status_target` against a plain string `status` column (like this fixture
uses, deliberately, to stay self-contained) generates a reference to a
column that doesn't exist. If your module's status is a plain string enum,
skip `status_target` and hand-fill the transition yourself, same as a
single custom action.

**Gotcha — `bulk_actions` needs the same `--force`-survival care as
`inline_items`.** It lives at a *nested* config path
(`features.backend.list.bulk_actions`), which the SYSTEM_SHELL-side
`ModuleScaffolder::mergePersistedFields()` did not originally cover (fixed
in the same pass that built this fixture — see [§11](#11-common-gotchas)).
Confirmed live: before that fix, a `--force` regenerate silently dropped a
hand-added `bulk_actions` entry entirely while the sibling top-level
`actions` key correctly survived the same regenerate.

---

## 10. Picking the right combination

These aren't mutually exclusive — a single module can combine several:

| You want... | Use |
|---|---|
| A record with no relationships | Nothing — plain generation, [§2](#2-recipe-a-simple-lookup-module) |
| A record that references another module | FK naming convention, automatic, [§3](#3-recipe-a-module-with-fk-relationships) |
| A tree/hierarchy within one module | Self-referential FK, automatic, [§4](#4-recipe-a-self-referential-module-treehierarchy) |
| A file/image field | `--file-columns`, [§5](#5-recipe-a-file-upload--media-field) |
| Child rows edited *inline*, in the parent's own form, in one submit | `inline_items`, [§6](#6-recipe-a-parent-child-module-inline_items) |
| One table shared by several unrelated parent types | `morphs`, automatic, [§7](#7-recipe-a-polymorphic-relationship-morphs) |
| An independent module's records shown scoped, as a tab | `delegations`, [§8](#8-recipe-related-records-as-sub-tabs-delegations) |
| A state-transition button, single record or bulk | `actions` / `bulk_actions`, [§9](#9-recipe-a-custom-action--bulk-action) |

The deciding question between `inline_items` and `delegations` is usually
**"does this child data have any meaning or use outside its parent?"** — an
Order Item never exists independently of its Order (`inline_items`); a
Stock Movement is a real, independently-listable record that also happens
to relate to a Warehouse (`delegations`).

---

## 11. Common gotchas

Things that have actually gone wrong while generating and verifying real
modules from this engine — not hypothetical warnings.

- **Migration collision in the testing DB.** After a module is scaffolded,
  it gets its own copy of the migration under its own module `Migrations/`
  folder (this project's convention — every registered module's migrations
  folder is auto-loaded). If you also copied the fixture's migration into
  the top-level `database/migrations/` for introspection, both copies now
  create the same table — a fresh test-DB migration run hits "table
  already exists". Fix: delete the top-level copy once scaffolding
  succeeds; the module-scoped copy is now canonical.
- **New permissions need seeding, not just scaffolding.** `make:module` /
  `make:modules-from-db` only *write* `{Module}SeederData.json`; nothing
  runs it automatically. The Create button — and every `hasPermission()`
  gated action — stays invisible until `php artisan db:seed` actually
  runs. The seeder is idempotent (checks existence before insert), safe to
  re-run.
- **A stale "already migrated" check.** `RefreshDatabase`'s migration-state
  cache goes stale intermittently in this project's test setup. Run
  `php artisan db:migrate --fresh --seed` by hand immediately before a
  `vendor/bin/phpunit` run in `SYSTEM_SHELL/BACKEND` if tests report
  missing tables/columns that should exist.
- **Packagist propagation lag.** If you're testing against the *released*
  package version (not the temporary path-repo override below), pushing a
  new tag doesn't make it available to `composer update` instantly —
  allow ~20-30s before assuming something is broken.
- **Verifying an unreleased package change without cutting a release.** Add
  a temporary `path` repository to the consuming project's `composer.json`:
  ```json
  "repositories": [
    {"type": "path", "url": "../../packages/generator-engine", "options": {"symlink": true}}
  ]
  ```
  and loosen the require constraint to `"*"`, then
  `composer update blutrixx/generator-engine` — this symlinks
  `vendor/blutrixx/generator-engine` straight to the local working tree.
  Fully reversible: restore the original `composer.json`/`composer.lock`
  bytes and run `composer install` to remove the symlink and re-fetch the
  real dist archive. Don't manually delete the `vendor/` package directory
  mid-iteration while doing this — it can leave `composer.lock` in a
  confused state where a scoped `composer update <pkg>` silently does
  nothing; if that happens, restore from backup and retry cleanly rather
  than deleting more things by hand.
- **"Write-once" generated files.** A small number of generated files are
  explicitly meant to be hand-edited exactly once and never touched again —
  currently: the `inline_items` wrapper component
  (`{Module}{Key}InlineItems.vue`) and (as of the test-splitting refactor)
  every generated `PhpUnitTestGenerator`/`PlaywrightTestGenerator` file.
  These use `writeFileOnce()` (unconditional skip-if-exists) internally,
  **not** `writeFile()` (whose skip-if-exists is gated on `--force` and
  will happily overwrite an existing file the moment `--force` is passed —
  correct for a schema-driven file, wrong for a hand-edited one). If you're
  adding a new "generate once" output to the engine itself, use
  `writeFileOnce()`, not `writeFile()`.
- **Full teardown checklist for a throwaway scratch verification.** After
  generating example modules purely to verify something (not to keep):
  drop the tables, delete rows from the `migrations` tracking table for
  those tables, delete the generated module directories in
  BACKEND/FRONTEND/MOBILE_APP, `git checkout --` the tracked
  registry/menu/`modules.json` files, delete the copied migrations, and
  restore `composer.json`/`composer.lock` if you used the path-repo
  override above. Confirm `git status --short` is clean in every affected
  repo as the final check.
- **No hard DB-level FK constraints, by convention.** Every fixture in this
  package (and every real module in the consuming project) uses a plain
  indexed `foreignId()`/`unsignedBigInteger()` column, never
  `->constrained()`. Relationship detection does not require a real
  constraint — it works off a naming-convention heuristic
  (`{column}_id` matched against a pluralized table name) whenever a real
  constraint isn't present, falling back to real constraint introspection
  first when one is.
- **Any hand-authored config key can suffer the `inline_items`
  `--force`-drop bug — check nested paths too.** Two confirmed instances
  now: `inline_items` (a top-level key) and `bulk_actions` (nested at
  `features.backend.list.bulk_actions`) were both silently dropped by
  `ModuleScaffolder::mergePersistedFields()` on the very next `--force`
  regenerate, before being found and fixed. If you add a new hand-authored
  config key to any generator, verify it survives a live `--force`
  round-trip, not just a fresh first-time generation — a config key that
  only gets tested via first-time generation will never exercise the merge
  path where this class of bug lives.
- **A generated file that's meant to survive `--force` must actually be
  write-once, not just "usually doesn't get touched."** The `morphs`
  redundant-index bug and the delegation-vs-standalone form-overwrite issue
  (both documented in this cookbook's own recipes) are examples of the same
  underlying lesson as the `writeFileOnce()` fix above: two independent code
  paths writing to the same output can silently interact in ways neither
  path's own tests catch, because each was tested in isolation. Test the
  *interaction*, not just each generator alone.

