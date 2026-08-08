# Polymorphic Relationships (`morphs`)

A single table (e.g. Payments) that can belong to more than one kind of
parent (e.g. a Supplier payment or a Customer payment), instead of one
near-duplicate table per parent type.

**Reference fixture:**
[`morphs-suite`](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/morphs-suite) —
`Payments` belongs to either `Suppliers` or `Customers`.

For the full `morphs` config shape, see the
[Module Config reference](../module-config#morphs-array).

## Fully schema-derived — no config editing at all

A column literally named `{prefix}_type` (string) paired with `{prefix}_id`
(integer), same prefix, is enough:

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
must already exist" requirement like a normal FK has.

## What you get

`PaymentsModel` gets a real `payable(): MorphTo { return $this->morphTo(); }`
method. If the migration is ever regenerated, it collapses back to a single
`$table->morphs('payable');` call (with no duplicate index — a real bug
found and fixed while building this fixture, see [Gotchas](gotchas)).

## What you get from the generated API (as of v2.44.0)

Before v2.44.0, `payable_type`/`payable_id` were excluded from
`PaymentsCreateService`'s validation rules and from the generated
`CreateForm.vue` entirely — the columns were correctly stripped from
list/filter/view UI (no generic renderer exists for "the related record
could be any of several types") but the same exclusion also silently
applied to create/edit, so a `POST /payments` through the generated API
dropped both fields before they ever reached `PaymentsModel::create()`
(Laravel's `validate()` only returns declared rule keys — this project's
mass-assignment convention). Creating a `Payment` via the HTTP API was
flatly impossible; only a direct `PaymentsModel::create([...])` call like
the one above worked.

Fixed in v2.44.0: `CreateService`/`EditService` now validate both columns
(`payable_type` → `required|string`, `payable_id` → `required|integer`,
deliberately no `exists:` rule since a polymorphic id can reference more
than one table), and the create/edit form now renders two plain inputs for
them — a text input for `payable_type` (the caller still types/sends the
raw FQCN, e.g. `App\Project\Modules\Custom\Suppliers\SuppliersModel`, or
your morph-map alias) and a number input for `payable_id`. No dropdown/FK
picker — see the next section for why.

![Payments create form — payable_type and payable_id as plain text/number inputs, no FK dropdown](./screenshots/morphs-suite-01-payments-create-polymorphic-plain-inputs.png)

## What you do NOT get, and have to hand-add yourself

- **The inverse relationship.** Only the owning side (`Payments`) gets a
  relationship method. `Suppliers`/`Customers` do not automatically get a
  `payments(): MorphMany` — add that to the model by hand if you want
  `$supplier->payments` to work.
- **A morph map.** `payable_type` stores the raw fully-qualified class name
  by default (`App\Project\Modules\Custom\Suppliers\SuppliersModel`) unless
  you register `Relation::morphMap([...])` yourself, typically in a service
  provider. The `targets` key in the generated `morphs` config entry
  (`'targets' => []`) is purely a place for a human to document which
  concrete models a polymorphic column can point at — no generator reads it
  to register a morph map or drive anything in the UI.

Because of the above, creating a real `Payment` row requires setting
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

See the fixture's own
[README](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/morphs-suite)
for the full verification steps.
