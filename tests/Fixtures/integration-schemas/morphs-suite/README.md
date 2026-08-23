# morphs-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine`'s
polymorphic (`morphs`) auto-detection end-to-end against a real consuming
Laravel project (SYSTEM_SHELL). Sibling to `items-suite` and `orders-suite` —
same conventions, same rigor, different mechanism under test.

## What this fixture covers

A POS-flavored scenario: a single `Payments` ledger records money moving
in both directions — money paid **out** to a **Supplier** for inventory
purchases, and money paid **in** by a **Customer** for an order — instead of
two near-duplicate tables. `payable_type`/`payable_id` is the polymorphic
pair that makes one `payments` table work for both.

| Table | Scenario exercised |
|---|---|
| `suppliers` | One of two possible `payable` targets |
| `customers` | The second possible `payable` target |
| `payments` | The polymorphic side — `payable_type` (string) + `payable_id` (bigint) triggers `morphs` auto-detection |

Unlike `inline_items`, `morphs` is entirely **schema-derived, not
hand-authored** — no config editing step is needed. A column literally named
`{prefix}_type` (string) paired with `{prefix}_id` (integer) is enough.
Generation order across this suite's three tables does not matter: `morphTo()`
resolves its target at runtime from the value stored in `payable_type`, not
at generation time (unlike a normal FK relationship, which does need its
referenced module to already be scaffolded).

## Contents

- **`migrations/`** — 3 migrations, in any order (no FK dependency between
  them). `payments`' `payable_type`/`payable_id` columns are written out
  explicitly (not via Laravel's `$table->morphs('payable')` shorthand) to
  match every other fixture's style and make the exact trigger columns
  unambiguous — `MigrationGenerator` collapses them back into a single
  `$table->morphs('payable');` call when regenerating this migration from
  the introspected config, so both forms round-trip to the same schema.
- **`columns.php`** — dumped from a **real** `SchemaIntrospector` run
  against these migrations (not hand-typed), confirming `payable_type`/
  `payable_id` are correctly tagged `morph_role`/`morph_name` by real
  introspection.

## How to use it: full end-to-end validation

1. **Copy the migrations** into the consuming project (e.g.
   `SYSTEM_SHELL/BACKEND/database/migrations/`):
   ```bash
   cp tests/Fixtures/integration-schemas/morphs-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   ```
2. **Run the migrations**:
   ```bash
   php artisan migrate
   ```
3. **Generate all three modules** — order doesn't matter:
   ```bash
   php artisan make:module Custom/Suppliers
   php artisan make:module Custom/Customers
   php artisan make:module Custom/Payments
   ```
   No `--file-columns`, no hand-edited config, nothing — `morphs` detection
   is fully automatic.

   > **Delete the copied migrations NOW — before any test run.**
   > `make:module` has just written its own copy of each migration under the
   > module's `Migrations/` folder, and a consuming project like SYSTEM_SHELL
   > auto-loads those *in addition to* the top-level folder. Both copies now
   > create the same table, so the next `migrate --fresh` — exactly what
   > `RefreshDatabase` runs at the start of any test suite — dies with
   > "table already exists", surfacing as unrelated tests failing with
   > nothing pointing at fixture migrations.
   >
   > ```bash
   > rm -f /path/to/consuming-project/BACKEND/database/migrations/2026_08_02_1000*_create_*_table.php
   > ```

4. **Confirm**:
   - `PaymentsModel.php` has a real `payable(): MorphTo { return
     $this->morphTo(); }` method.
   - `Payments/module.json` has a `morphs` entry:
     `{"name": "payable", "type_column": "payable_type", "id_column":
     "payable_id", "targets": []}`.
   - The regenerated `Payments` migration shows a single
     `$table->morphs('payable');` line, not two separate column
     declarations, and does **not** duplicate that composite index under a
     second name (a real bug found + fixed via this fixture — see
     `MigrationGeneratorMorphIndexTest.php` in the package's own test
     suite).
   - Create a Payment against a Supplier and a Payment against a Customer
     (see below), and confirm both round-trip correctly.

## Populating `targets`: a real type-selector UI (v2.51.0+)

`morphs[].targets[]` is no longer a bare, unread annotation field — populate it with typed target
objects and regenerate to get a real dropdown-plus-record-picker on the Create/Edit form, and a
`Relation::morphMap()` registration on `PaymentsModel` itself:

```json
{
  "morphs": [
    {
      "name": "payable",
      "type_column": "payable_type",
      "id_column": "payable_id",
      "targets": [
        {
          "alias": "supplier",
          "model": "App\\Project\\Modules\\Custom\\Suppliers\\SuppliersModel",
          "module": "Suppliers",
          "label": "Supplier",
          "option_label": "contact_person"
        },
        {
          "alias": "customer",
          "model": "App\\Project\\Modules\\Custom\\Customers\\CustomersModel",
          "module": "Customers",
          "label": "Customer"
        }
      ]
    }
  ]
}
```

Hand-edit this into `Payments/module.json` after the first generation (`targets` is never
auto-guessed — see `IntrospectionToConfig::detectMorphPairs()`'s own docblock), then
`php artisan make:module Custom/Payments --force`. Confirm:

- `PaymentsModel.php` gains a `boot()` method registering both aliases:
  `Relation::morphMap(['supplier' => SuppliersModel::class, 'customer' => CustomersModel::class])`
  — on `Payments` itself (the module that owns `payable_type`/`payable_id`), not on `Suppliers`/
  `Customers`. Verified live: `Relation::getMorphedModel('supplier')` resolves correctly at runtime.
- `PaymentsCreateForm.vue`/`EditForm.vue` render a single `MorphSelectField` instead of the plain
  text/number pair — a type dropdown (Supplier/Customer), then an API-backed record picker scoped
  to whichever type is chosen. `option_label` (optional, per target) controls which field of the
  target record shows in that picker's option list — `contact_person` for suppliers above, falling
  back to `name` for customers since none was set. **Only affects the create/edit picker** — list
  and view pages still don't render `payable_type`/`payable_id` at all (see below), with or without
  `option_label` set.
- **Alias uniqueness is enforced, not just documented.** `Relation::morphMap()` is a single
  Laravel-global registry, not scoped per table — registering the same alias for two different
  models is a hard-fail at generation time (`make:module` checks this run's aliases against every
  already-generated sibling module on disk; `make:modules-from-db` checks the whole blueprint before
  generating anything, wider visibility than the single-module path can offer). Confirmed live: a
  deliberately conflicting alias on a second module produced a clear, accurate error naming both
  conflicting sources, before either module was touched.
- End-to-end confirmed via the real generated Playwright suite: type dropdown → record picker
  (scoped to the chosen type's own `/select/{module}` endpoint) → submit → record appears in the
  list, full create → filter → view → edit → delete cycle passing.

**Still a real limitation**: `ModelGenerator` only ever emits `morphTo()` on the owning side
(`payments`) — it does not auto-generate the inverse `morphMany()`/`morphOne()` on the target side
(`Suppliers`/`Customers` don't automatically get a `payments()` relationship). Hand-add that
yourself if you want `$supplier->payments` to work.

Without `targets` populated, everything falls back to the original plain-input behavior — a text
field for the raw class name, a number field for the id — byte-for-byte unchanged from before this
feature existed:

```php
PaymentsModel::create([
    'amount' => 150.00,
    'payment_date' => now(),
    'payable_type' => SuppliersModel::class, // or a registered morph-map alias, once targets is set
    'payable_id'   => $supplier->id,
    'created_by_id' => auth()->id(),
]);
```

## How to use it: fast integration testing without a real DB

Same pattern as items-suite/orders-suite:

```php
$allTables = require __DIR__ . '/../../Fixtures/integration-schemas/morphs-suite/columns.php';

$config = (new \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig())->build(
    $allTables['payments'],
    ['module_name' => 'Payments', 'module_type' => 'Custom', 'table_name' => 'payments', 'id_type' => 'bigint']
);

// $config['morphs'] is now populated automatically -- no hand-editing needed.
```
