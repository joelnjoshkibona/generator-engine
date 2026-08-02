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

## Known limitation — no auto-generated inverse relationship, and no morph map

- `ModelGenerator` only ever emits `morphTo()` on the **owning** side
  (`payments`, here). It does **not** auto-generate the inverse
  `morphMany()`/`morphOne()` on the target side (`Suppliers`/`Customers`
  don't automatically get a `payments()` relationship) — hand-add that
  yourself if you want `$supplier->payments` to work.
- `targets` (`morphs[].name → targets: []` in the config) is purely a
  human-annotation field. It survives a `--force` regenerate (via
  `ModuleScaffolder::mergePersistedFields()`), but **no generator reads it**
  — it doesn't register a `Relation::morphMap()`, doesn't drive a
  dropdown/picker in generated forms, and doesn't validate anything. If you
  want short aliases (`'supplier'`/`'customer'`) instead of full class
  names stored in `payable_type`, you must register
  `Relation::morphMap([...])` yourself, typically in a service provider —
  the generator does not do this for you.
- Because of the above, creating a real `Payment` row requires setting
  `payable_type` to the fully-qualified class name (or your own registered
  morph-map alias) yourself:
  ```php
  PaymentsModel::create([
      'amount' => 150.00,
      'payment_date' => now(),
      'payable_type' => SuppliersModel::class,
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
