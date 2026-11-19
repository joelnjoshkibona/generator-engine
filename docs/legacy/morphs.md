::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/morphs.md`.
:::

::: v-pre

# Polymorphic Relationships (`morphs`)

## Overview

A **morph pair** lets one entity belong to any one of several different
parent tables through a single pair of columns — `{prefix}_type` (which
class the parent is) and `{prefix}_id` (that parent's id) — instead of one
nullable FK column per possible parent.

The canonical example: a `Payments` ledger that records money moving both
ways — money paid **out** to a `Supplier` for inventory, and money paid
**in** by a `Customer` for an order — without two near-duplicate tables:

```
Payments
├── payable_type   ("App\...\Suppliers\SuppliersModel" or "...\Customers\CustomersModel")
├── payable_id     (the id of that Supplier or Customer row)
└── amount, payment_date, ...
```

`payable_type` + `payable_id` is the `payable` morph pair. At runtime,
Laravel's `morphTo()` resolves `$payment->payable` to the correct model by
reading the class name out of `payable_type` — it does not need to know in
advance which of several tables the row lives in. (Once the module declares
[`targets`](#targets-the-real-mechanism), `payable_type` stores a short
alias such as `"supplier"` instead, resolved through the generated
`Relation::morphMap()`.)

::: tip Reference fixture
[`morphs-suite`](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/morphs-suite)
is the permanent, real-schema fixture this page's examples are drawn from —
`Suppliers` and `Customers` are the two possible `payable` targets for
`Payments`.
:::

---

## Detection Rule — Pairs Auto-Detected, `targets` Hand-Authored

Unlike [delegations](/legacy/delegations), [`inline_items`](/legacy/inline-items),
and [actions](/legacy/actions), the morph **pairs themselves** are never
hand-authored in `module.json` — each pair is derived entirely from the
introspected schema by `IntrospectionToConfig::detectMorphPairs()`. The one
part of a `morphs[]` entry that *is* hand-authored (or blueprint-authored)
is [`targets`](#targets-the-real-mechanism), which upgrades the plain
text+number pair into a real type-aware picker and registers a morph map. A
pair is detected when both of these hold:

1. A column named `{prefix}_type` exists and is **string-like** — its
   normalized type is one of `string`, `varchar`, `char`, `text`,
   `mediumText`, `longText` (or its raw DB type is `varchar`, `char`,
   `string`, `text`, `mediumtext`, `longtext`).
2. A companion column named `{prefix}_id` exists and is **integer-like** —
   its normalized type is one of `integer`, `bigInteger`, `foreignId` (or
   its raw DB type is `int`, `integer`, `bigint`, `smallint`, `tinyint`,
   `unsigned bigint`).

Both conditions must hold on the *same* prefix. A lone `payable_type` column
with no matching `payable_id` (or vice versa) is not detected as a morph.

```php
// Either written explicitly, column by column...
$table->string('payable_type');
$table->unsignedBigInteger('payable_id');

// ...or via Laravel's shorthand — both are introspected identically:
$table->morphs('payable');
```

```bash
php artisan make:module Custom/Suppliers
php artisan make:module Custom/Customers
php artisan make:module Custom/Payments
```

No `--file-columns` flag, no `tmp/*-config.json` hand-edit, nothing —
detection is fully automatic on every `make:module` run against a table with
a qualifying column pair. Generation order across the three modules does not
matter: because `morphTo()` resolves its target at *runtime* from the stored
class name, `Payments` does not need `Suppliers`/`Customers` to already
exist the way a normal FK relationship would.

---

## The `module.json` Shape

Each detected pair becomes one entry in the top-level `morphs` array:

```json
{
  "morphs": [
    {
      "name": "payable",
      "type_column": "payable_type",
      "id_column": "payable_id",
      "targets": []
    }
  ]
}
```

| Key | Description |
|-----|-------------|
| `name` | camelCase name derived from the shared prefix (`lcfirst(studly('payable'))` → `"payable"`). Not currently read by any generator beyond labeling — it exists for readability. |
| `type_column` | The detected `{prefix}_type` column name, e.g. `"payable_type"`. |
| `id_column` | The detected `{prefix}_id` column name, e.g. `"payable_id"`. |
| `targets` | Emitted as `[]` by detection, then **hand-authored or blueprint-authored**. Each entry is `{"alias": …, "model": …, "module": …}` (plus an optional `"option_label"`). Populating it is what turns the pair into a real `morph-select` picker on create/edit **and** makes the owning model register a `Relation::morphMap()`. Since generator-engine **v2.51.0** this is a real, working mechanism — see [`targets` — the real mechanism](#targets-the-real-mechanism). |

`morphs` is threaded to the top level of `module.json` (not buried inside
`features.frontend`) specifically so backend generators — `ModelGenerator`
(the `morphTo()` relation), `CreateServiceGenerator`/`EditServiceGenerator`
(the validation rules) — can read it directly.

::: tip Re-detected, but `targets` is merged back
Because the morph *pairs* are schema-derived, `--force` re-introspects the
table and rebuilds the array fresh each time rather than preserving a stale
copy — the same way columns and indexes are re-derived. `targets` is the
exception: `IntrospectionToConfig::mergeMorphTargets()` merges any already
populated `targets` back onto the freshly detected pairs, matched by morph
`name`. It runs inside `build()` **before** the frontend fields array is
baked (which is why merging targets onto a returned config after the fact
does not retroactively produce a morph-select field), and SYSTEM_SHELL's own
`ModuleScaffolder` reuses the same static method for its persisted-config
round-trip. So a populated `targets` survives every regenerate — and unlike
before v2.51.0, it drives real generated output. See
[What is hand-editable vs generated](/legacy/module-structure#what-is-hand-editable-vs-generated).
:::

---

## What's Auto-Generated vs Hand-Authored

Two of these rows depend on whether `targets` is populated — see
[`targets` — the real mechanism](#targets-the-real-mechanism) below.

| | Auto-generated | Hand-authored / NOT done by the generator |
|---|---|---|
| **Model** | `payable(): MorphTo { return $this->morphTo(); }` on the owning side (`PaymentsModel`). The **inverse** (`$supplier->payments`) is also generated — but only when the target module declares it via [`relations.morphMany`](#reverse-morph-relations-relations-morphmany) (generator-engine v3.4.0+). | Nothing, once `relations.morphMany` is declared. A target module that never declares it still gets no inverse relation. |
| **Migration** | A single `$table->morphs('payable');` call — even if the source migration wrote the two columns out explicitly, a regenerated migration collapses them back to the shorthand, with no duplicate composite index. | — |
| **Backend validation** | `payable_type` → `required\|string`. `payable_id` → `required\|integer`. **Deliberately no `exists:` rule** — a polymorphic id can reference rows in several different tables, and the generator has no way to know which one to check; guessing wrong would be worse than not checking at all. This is unchanged by `targets`. | Any target-aware validation (e.g. rejecting a `payable_type` that isn't one of the configured aliases, or an `exists:` check scoped to the chosen type). |
| **Create/edit form** | With **empty `targets`**: two plain inputs — a text input for `payable_type` and a number input for `payable_id`. With **populated `targets`**: a single `morph-select` field — a type dropdown plus an API-backed record picker scoped to the chosen type (`GET /select/{module}`). | Only the choice of which targets to offer. The picker itself is generated. |
| **List / filter / view pages** | Nothing. | Any polymorphic rendering — morph columns are excluded from list columns, filter fields, and the view page entirely. There is no generic renderer for "the related record could be any of several types." |
| **Morph map** | With **populated `targets`**: a real `boot()` on the owning model calling `Relation::morphMap(['supplier' => SuppliersModel::class, …])`, built by `ModelGenerator::generateBootMethod()`, so `payable_type` stores the short alias instead of a fully-qualified class name. With **empty `targets`**: nothing — `payable_type` holds the raw class name. | Registering a map for morph columns this module does **not** own (registration lives on the owning model, by design). |

---

## `targets` — The Real Mechanism {#targets-the-real-mechanism}

Since **generator-engine v2.51.0**, `morphs[].targets` is a working config
key, not a comment field. Populate it with one entry per model the pair can
point at — by hand in `module.json`, or from the blueprint's `morphs` block
on a `make:modules-from-db` run:

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
          "module": "Suppliers"
        },
        {
          "alias": "customer",
          "model": "App\\Project\\Modules\\Custom\\Customers\\CustomersModel",
          "module": "Customers"
        }
      ]
    }
  ]
}
```

| Key | Required | Purpose |
|---|---|---|
| `alias` | yes | The short string stored in `{prefix}_type`, and the `Relation::morphMap()` key. Must be unique project-wide — `Relation::morphMap()` is a single Laravel-global registry, and `Schema\MorphAliasValidator` aborts generation if two modules claim the same alias for different models. |
| `model` | yes | Fully-qualified model class the alias maps to. |
| `module` | yes | Module whose `/select/{module}` endpoint backs the record picker for this alias. Kebab-cased into the URL (`Suppliers` → `/select/suppliers`). |
| `option_label` | no | Field on each returned option used as its label. Defaults to `name`. |

An entry is honoured only when `alias`, `model`, and `module` are all
non-empty — a partial entry is filtered out, and a morph whose entries are
all filtered out falls back to the plain input pair.

### What populating `targets` generates

1. **A `morph-select` create/edit field.**
   `IntrospectionToConfig::buildMorphFrontendFields()` emits a single field
   (`field_type: "morph-select"`, carrying `type_column`, `id_column`, and
   the `targets` list) in place of the text+number pair.
   `BaseComponentGenerator`'s morph-select branch renders it as
   `MorphSelectField.vue`: a type dropdown, then an API-backed record picker
   scoped to the chosen type, hitting `GET /select/{module}`. Submitting
   writes the alias into `{prefix}_type` and the chosen record's id into
   `{prefix}_id`.
2. **A real morph map on the owning model.**
   `ModelGenerator::generateBootMethod()` writes a `boot()` onto the owning
   model registering every `alias => Model::class` pair. Registration lives
   on the *owning* model (the one that has the morph columns), so the map is
   active as soon as that model is queried — exactly when a `{prefix}_type`
   value needs resolving.

Because the record picker goes through the generic `/select/{module}`
endpoint, every target module must satisfy this project's FK-picker
convention: a literal `name` column, or a `format{Module}()` override in
`BACKEND/app/Project/_Src/SelectTrait.php` aliasing its real identifying
column `as name`. Without that, the picker's endpoint hard-500s.

::: warning Empty `targets` keeps the legacy behavior
A morph with `"targets": []` produces exactly what it produced before
v2.51.0 — a plain text input plus a number input, no `boot()`, no morph map.
That fallback is deliberate and byte-identical, so every module generated
before targets existed regenerates unchanged.
:::

---

## Reverse Morph Relations (`relations.morphMany`) {#reverse-morph-relations-relations-morphmany}

`morphs[]` only ever generates the `morphTo()` on the **owning** model. To
give a morph *target* the inverse relation (`$vendor->payments`), declare it
in that target module's own `module.json` under the top-level `relations`
key — a real config key since generator-engine **v3.4.0**:

```json
{
  "relations": {
    "morphMany": [
      { "module": "Payments", "method": "payments", "morphName": "payable" }
    ]
  }
}
```

| Key | Purpose |
|---|---|
| `module` | The owning module that holds the morph columns. Resolved at generation time — an unresolvable name throws immediately, instead of emitting a relation pointing at a class that does not exist. |
| `method` | The relation method generated on this model (`payments()`). |
| `morphName` | The morph pair's `name` on the owning module (`payable`). |

`ModelGenerator::generateManualInverseRelationships()` reads this and emits
a real `payments(): MorphMany` on the target model. Because it is declared
in config rather than spliced into the generated file afterwards (the
`ModelRelationInjector` approach of v3.3.0, removed in v3.4.0), it is
re-emitted from config on every run and therefore survives `--force` for
free.

The same `relations` key also accepts `hasMany` and `belongsToMany` entries
for non-morph relations FK introspection cannot derive. It is a preserved,
developer-owned key, like `delegations` and `actions`.

::: tip Batch path: one `delegate` block declares the relation, the tab and the filter
In a `make:modules-from-db` blueprint, put `delegate` on the morph target and the batch command builds
**all three** — the `morphMany` relation above, a "list + view" delegation tab on the target's detail page,
and that tab's `morphFilter` (`payable_type = <alias>`):

```json
"targets": [
  { "alias": "suite_supplier", "model": "…\\SuiteSuppliersModel", "module": "SuiteSuppliers", "label": "Supplier",
    "delegate": { "enabled": true, "relation_name": "settlements", "tab_label": "Settlements" } }
]
```

`module` may be the bare name or `Group/Name`. `relation_name` defaults to the camelCased owning module and
`tab_label` to the owning module's name. Do **not** hand-write the tab under the blueprint's `delegations`
key instead: that key reads only `name`, `related`, `ui`, `filter_key` and `operations`, so a morph filter
written there (`morph_type_column`, …) is ignored — the tab then matches on `{prefix}_id` alone and lists
*other* owner types' rows. The batch command now warns about any key it drops from a delegation entry.
:::

::: tip Filtering a reverse-morph delegation
If you also surface those child rows as a tab on the target's detail page,
that delegation needs `morphFilter` alongside `filterKey` — without it the
tab matches rows belonging to *other* owner types whose `{prefix}_id`
happens to collide. See
[Delegations → `morphFilter`](/legacy/delegations#morphfilter-reverse-morph-delegations).
:::

---

## Creating a Record With a Morph Field

**With `targets` populated**, the generated create/edit form does this for
you: pick the type in the dropdown, pick the record in the picker beside it.
An API caller supplies the two values directly — the `{prefix}_type` being
the configured **alias**, because the owning model's `boot()` registered a
morph map:

```
POST /payments
{
  "amount": 150.00,
  "payment_date": "2026-08-08",
  "payable_type": "supplier",
  "payable_id": 4
}
```

**With `targets` empty**, there is no picker and no morph map, so the caller
must supply the real, fully-qualified class name as the type:

1. **`{prefix}_type`** — the fully-qualified class name of the target model,
   as a string. For a `Supplier` target that's the literal string
   `"App\\Project\\Modules\\Custom\\Suppliers\\SuppliersModel"`
   (`SuppliersModel::class` in PHP).
2. **`{prefix}_id`** — the id of a real, already-existing row in that
   target's table.

```php
PaymentsModel::create([
    'amount'        => 150.00,
    'payment_date'  => now(),
    'payable_type'  => SuppliersModel::class, // or 'supplier' with a morph map
    'payable_id'    => $supplier->id,
    'created_by_id' => auth()->id(),
]);
```

A `Payment` against a `Customer` looks identical, just with the customer
alias / `CustomersModel::class` and a customer id. Both round-trip correctly
through `payable()`'s `morphTo()`.

---

## List/Filter/View Exclusion vs Create/Edit Inclusion

Morph columns are treated asymmetrically across the generated UI, and this
is deliberate:

- **Excluded** from list columns, filter fields, and the view page — there
  is no generic component that can render "this could be a Supplier or a
  Customer" without knowing the full set of possible targets, so the
  generator does not attempt it.
- **Included** as two plain inputs on create/edit forms — as of a
  **2026-08-08 fix**. Before that fix, morph columns were excluded from
  `$userColumns` for form purposes but were *also* never validated
  anywhere, which meant creating a record through the generated API was
  flatly **impossible** for any module with a morph pair (found live via
  the `morphs-suite` fixture's `PaymentsModel`). The fix added
  `buildMorphBackendFields()`/`buildMorphFrontendFields()` specifically to
  close that gap — the form became functional, albeit with two plain inputs.
  Since **v2.51.0** that same `buildMorphFrontendFields()` emits a real
  `morph-select` picker instead whenever
  [`targets`](#targets-the-real-mechanism) is populated; the plain pair is
  now only the empty-`targets` fallback.

The list/filter/view exclusion is unaffected by `targets` — populating it
upgrades create/edit only. If you want a morph column rendered on a list or
detail page, that is still hand-written in the module's own components.

---

## Common Pitfalls

### `payable_type` set to a short alias that isn't registered

**Symptom:** `morphTo()` throws trying to resolve the model class, or the
relation returns `null`.

**Cause:** A value like `"supplier"` was stored, but no
`Relation::morphMap([...])` maps it — either the module's `targets` is empty
(so no `boot()` was generated at all), or the alias stored does not match
any configured `targets[].alias`.

**Fix:** Populate [`morphs[].targets`](#targets-the-real-mechanism) on the
owning module and regenerate — the generated `boot()` registers the map, and
the generated morph-select field stores exactly those aliases. If you
deliberately keep `targets` empty, store the fully-qualified class name
(`SuppliersModel::class`) instead of an alias.

---

### Alias collision across two modules

**Symptom:** Generation aborts with a `MorphAliasValidator` error naming an
alias registered for two different models.

**Cause:** `Relation::morphMap()` is one global registry. Two modules used
the same `targets[].alias` for different model classes (or one module's own
`targets` listed the same alias twice, which `ModelGenerator` catches
separately).

**Fix:** Rename one alias. Aliases must be unique project-wide, not just
within a module.

---

### `payable_id` pointing at a row that doesn't exist

**Symptom:** `$payment->payable` returns `null` at runtime even though
validation passed.

**Cause:** There is deliberately no `exists:` rule on `{prefix}_id` — a
polymorphic id can reference several different tables, so an id for a row
that was deleted (or never existed) passes validation silently. Populating
`targets` does not change the validation rules: it drives the form and the
morph map, not the backend rule set.

**Fix:** This is expected behavior, not a bug to fix in the generator. Add
your own hand-written, type-aware validation in the module's Create/Edit
service if you need it.

---

### A morph-select picker's type dropdown works but its record picker 500s

**Symptom:** Choosing a type in the generated morph-select field returns a
500 from `/select/{module}` and the record list stays empty.

**Cause:** The `targets[].module` has no literal `name` column and no
`format{Module}()` override in `SelectTrait.php`, so the generic
`/select/{module}` endpoint cannot build its option labels.

**Fix:** Add the `format{Module}()` override aliasing that module's real
identifying column `as name` — see
[Creating modules → Next steps](/legacy/creating-modules#next-steps).

---

## Related Pages

- [Creating modules](/legacy/creating-modules) — `make:module` CLI reference, `--force`, and the emit-config / schema workflow.
- [Module structure](/legacy/module-structure) — detailed walkthrough of every generated file and the backend service patterns.
- [Delegations](/legacy/delegations) — the other related-records mechanism, for genuinely independent, hand-authored child modules (morphs is schema-derived; delegations is not).
- [generator-engine docs: Examples — Polymorphic (morphs)](https://joelnjoshkibona.github.io/generator-engine/examples/morphs) — the canonical, package-level reference this page summarizes for SYSTEM_SHELL's own conventions.

:::
