::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/inline-items.md`.
:::

::: v-pre

# Parent-Child Modules (`inline_items`)

## Overview

`inline_items` lets a parent record (an Order) be created and edited
**together with a list of child rows** (its Order Items) inside the same
form, in one submit. This is different from a [delegation](/legacy/delegations):
an `inline_items` child has **no independent existence outside its
parent** — its rows are entirely synced from the parent's own form submit
(added, edited, and removed rows are all reconciled server-side on every
save), whereas a delegation embeds a real, independently-addressable
module with its own list page and its own create/edit/delete lifecycle.

```
Order Create/Edit Form
├── Order fields          (order_number, customer_name, status, ...)
└── Order Items           (inline_items — rows edited right here, in this form)
```

**Choosing between the two:** ask whether the child data has any meaning
or use *outside* its parent. An Order Item never exists independently of
its Order — use `inline_items`. A Stock Movement is a real,
independently-listable record that also happens to relate to a Warehouse —
use a [delegation](/legacy/delegations) instead.

`inline_items` is entirely hand-authored, like `delegations` — nothing
about it is introspected from the schema.

As of generator-engine v3.5.18, the rendering behind it is **fully concrete, generated markup** —
not a runtime, JSON-config-driven generic component. `make:module`/`--force` writes real template
code straight into `{Module}{Key}InlineItems.vue` for the rows list (card or `table` variant), the
Add/Edit modal, the View modal, and the Delete confirmation, reusing the same per-widget field
dispatch every other generated form uses (`generateField()`, via `generateInlineItemModalField()`/
`generateInlineItemViewField()`). The file is written **once** (`writeFileOnce()`) and is never
overwritten by a later `--force`, so you edit it exactly like any other Vue component afterward — no
special hook API, no shared prop/slot surface to learn. See
[What the Generator Produces](#what-the-generator-produces) below for the full breakdown.

::: warning Superseded 2026-09-19 — the shared `InlineItemsComponent` no longer exists
Before generator-engine v3.5.18, this section described a shared, generic `InlineItemsComponent`
driven end-to-end by config/props (`variant`, `totals`, `empty_message`, `can_add`/etc.) with named
slots for anything else, plus five dedicated per-field hook props (`dynamicDisabled`, `showField`,
`render`, `dynamicLabel`, `dynamicFilters`) for hand-authored logic. That component has been removed
from this project entirely (its own copy was deleted), replaced by the concrete-markup approach
above. If you're maintaining a module generated before this change, its wrapper component still
imports the old `InlineItemsComponent` and keeps working — but a fresh `--force` regenerate will
**not** convert it, since the wrapper file is write-once; only a brand-new `inline_items` entry on a
not-yet-scaffolded key gets the new markup. There is no automated migration path from the old wrapper
shape to the new one.
:::

---

## `module.json` Configuration

`inline_items` is a top-level key in `module.json`, and unlike
`delegations`/`actions` it is a **flat list**, not a map keyed by name:

```json
{
  "module_name": "Orders",

  "inline_items": [
    {
      "key": "order_items",
      "label": "Order Items",
      "child_module": "OrderItems",
      "child_group": "Custom",
      "child_has_creator_updater": true,
      "parent_fk": "order_id",
      "primary_field": "product_name",
      "modal_size": "lg",
      "modal_columns": 2,
      "fields": [
        { "key": "product_name", "label": "Product",    "type": "text",   "required": true },
        { "key": "quantity",     "label": "Qty",         "type": "number", "required": true },
        { "key": "unit_price",   "label": "Unit Price",  "type": "number", "required": true, "decimals": 4 }
      ],
      "inject_from_parent": [
        { "child_field": "currency", "parent_field": "currency" }
      ]
    }
  ]
}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `key` | string | required | The property name on the parent's form payload (`form.order_items`). Also drives the wrapper component's naming. |
| `label` | string | same as `key` | Human-readable label for the inline section header and its add-item button. |
| `child_module` | string | required | The child module's name, e.g. `"OrderItems"`. |
| `child_group` | string | required | A direct namespace/path segment for the child module — same convention as `module_type` elsewhere (`Core`, `System`, `Custom`, or a project-invented group), with **no forced nesting** under any other group. |
| `child_has_creator_updater` | boolean | auto-derived | As of generator-engine v3.4.12, this is only a manual **override** — leave it unset and the generator derives the real answer from the child module's own already-known `has_creator_updater` setting via the module registry (`MakeModulesFromDb.php`/`ModuleScaffolder::buildModuleRegistryFromFs()` both carry this onto their registry entries). Set it explicitly (`true` or `false`) only to force a value that disagrees with the registry — e.g. the child isn't registered yet. Before v3.4.12 this was purely opt-in, hand-authored knowledge with no fallback, and **getting it wrong when the child needed it fatal-errored on the first real create** (the generated code never set `created_by_id`, and the column is `NOT NULL`) — this is now self-correcting for any project whose registry populates the key. |
| `parent_fk` | string | required | The FK column on the child table pointing back at the parent. |
| `primary_field` | string | — | The child field used as the display label in the summary view. |
| `modal_size` / `modal_columns` | string / number | — | Cosmetic — controls the add/edit-item modal's width and field-grid column count. |
| `add_button_text` / `add_modal_title` / `edit_modal_title` | string | derived from `label` | Cosmetic label overrides. |
| `fields` | array | required | The child's own field definitions, rendered inside the inline add/edit modal — see the expanded field-level table below. |
| `inject_from_parent` | array | `[]` | Optional. Copies a value straight from the parent model onto every child row at save time — `{ child_field, parent_field }` pairs. Does **not** need to be declared in `fields` (it's never user-entered). |
| `variant` | `'card' \| 'table'` | `'card'` | Optional. `'table'` renders the child rows as real aligned columns (numeric columns right-aligned) instead of the default compact row — the shape for financial line items (Qty / Unit Price / Amount). |
| `totals` | array | — | Optional. One footer row per entry, summing that child field across every row: `{ field, label?, sync_to? }`. `sync_to` names a **top-level parent field** (e.g. `total_amount`) that the generated form keeps permanently equal to that sum — see below. |
| `empty_message` | string | `'No items added yet.'` | Optional. Shown in place of the rows when the list is empty. |
| `view_modal_title` | string | `'View Item'` | Optional. Title of the read-only view modal. |
| `delete_message` | string | generic confirmation copy | Optional. Confirmation text shown in the delete modal. |
| `can_add` / `can_edit` / `can_view` / `can_delete` | boolean | `true` | Optional. Turns that action off entirely for this child — e.g. a line-item type that should never be removable once added. Only emitted into the generated form when explicitly `false`. |

### Field-level options (`fields[]`)

Each entry is `{ key, label, type, required?, decimals? }` at minimum, plus
any of:

| Key | Effect |
|---|---|
| `readonly` / `disabled` | Field renders but can't be edited / is fully inert |
| `default` | Pre-filled value for a new row |
| `input_type` | A `type: "text"` field's underlying `<input type>` (`email`, `tel`, `number`, ...) |
| `table_width` | Fixed column width in `variant: "table"` |
| `show_in_table` | Set `false` to keep a field editable in the modal but hide it from the row/table display |
| `col_span` | `1` or `2` — how many columns this field spans when `modal_columns: 2` |
| `options` | A **local**, non-API fixed dropdown list — `[{id, name}, ...]`. Mutually exclusive with `splash_key`/`api_url`, which drive an API-backed picker instead |
| `splash_key` | Renders this field as a self-fetching `ApiSelect2Field` (an FK picker) hitting `GET /select/{PascalCase(splash_key)}`. See the note below — this resolution is unconditional and has nothing to do with the parent's own splash feature flags |
| `api_url` | Explicit endpoint override for the picker — skips the `/select/{splash_key}` derivation entirely. Use it when the options come from something other than the generic select endpoint |
| `option_label` / `option_value` | Which keys on each option (or related-record object, for an API-backed field) are the display label and the stored value (default `name`/`id`) |
| `option_subtitle_field` | A secondary field shown under the label in an API-backed picker's row |

::: tip `splash_key` and `api_url` resolve at generation time (engine v3.5.22+)
A `type: "select"` field with a `splash_key` and/or an `api_url`, and no literal `options`, becomes an
API-backed picker (`ApiSelect2Field`) in the generated `{Module}{Key}InlineItems.vue`. Its endpoint is
`api_url` if you set one, otherwise `/select/{StudlySplashKey}` — e.g. `"splash_key": "line_statuses"`
→ `GET /select/LineStatuses`. A select with literal `options` stays a plain select, whatever else it
carries. This does not depend on `features.backend.createSplash`/`editSplash`, unlike a Create/Edit form
field's own `splash_key` (see [Module structure → Splash endpoints](/legacy/module-structure#splash-endpoints)).

**History, if you hit this on an older engine:** before v3.5.18 the rule was applied at *runtime* by a
shared component; the v3.5.18 rewrite to concrete markup dropped it, and **v3.5.18–v3.5.21** generated
`:options="splash.<plural key>"` for such a field — a `splash` object the wrapper never receives (a
`vue-tsc` error, and a crash when the dialog opens). Found by the engine's `super-suite` fixture and
fixed in v3.5.22. On those versions, hand-edit the write-once wrapper so the field is an
`ApiSelect2Field` pointed at the endpoint you want.

Whatever the endpoint, the target must satisfy this project's FK-picker convention: a literal `name`
column, or a `format{Module}($search)` override in `BACKEND/app/Project/_Src/SelectTrait.php` aliasing
its real identifying column(s) `as name`. Without one, that endpoint hard-500s and the picker stays
empty — see [Creating modules → Next steps](/legacy/creating-modules#next-steps).
:::

::: tip Reconciled with the older `field_type: 'inline-items'` pattern
Until 2026-08-18, this list (the documented, primary mechanism) was
actually *more limited* than the older single-field `field_type:
'inline-items'` pattern's own field config, which already passed
`readonly`/`disabled`/`default`/`options`/etc straight through. Both now
support the same field-level richness — use `inline_items[]` (this page),
not the older pattern, for any new module.
:::

::: tip The financial line-items pattern (`variant` + `totals`)
For line items with money on them, the common ask is: real columns, a bold
total at the bottom, and the parent's own "Total"-style field always
matching that sum instead of drifting out of sync with hand-entered data:

```json
{
  "key": "expense_items",
  "label": "Items",
  "child_module": "ExpenseItems",
  "child_group": "Custom",
  "parent_fk": "expense_id",
  "primary_field": "description",
  "variant": "table",
  "fields": [
    { "key": "description", "label": "Description", "type": "text",   "required": true },
    { "key": "amount",      "label": "Amount",       "type": "number", "required": true, "decimals": 2 }
  ],
  "totals": [
    { "field": "amount", "label": "Total", "sync_to": "total_amount" }
  ]
}
```

With `sync_to` set, the generated Create/Edit form wires an inline
`@totals-change` handler that (1) sets `form.total_amount` from the live
sum on every add/edit/delete of a row, and (2) permanently disables that
field via the same `disabledFieldsList`/`isFieldDisabled` mechanism
`defaults` already uses — so `total_amount` is always derived from the
line items, never independently hand-entered. **Match `decimals` on the
top-level `total_amount` field to the summed child field's own
`decimals`** — the parent field's display formatting is independent of the
child field's, and a mismatch there is the one easy way to get a correctly
-summed value that *displays* rounded differently than the footer does.

The generated wrapper's totals footer row and its live-sum sync logic are emitted directly into
`{Module}{Key}InlineItems.vue` itself (via `buildInlineItemsTotalsScriptBlock()`) — see
[What the Generator Produces](#what-the-generator-produces) below for the rest of what that file
contains.
:::

::: warning `parent_fk` is injected automatically — don't put it in `fields`
Unlike a delegation's `backend.fields`, `inline_items`' `fields` list should
only contain columns the user actually fills in. `parent_fk` (e.g.
`order_id`) is injected by the generated save/sync code itself
(`$inject['order_id'] = $model->id`) — it should never appear in `fields`
or `inject_from_parent`.
:::

::: tip An inline-items table can be a step of a create wizard
Name the `inline_items` key in a step's `field_keys` exactly as you would a plain field:

```json
"wizard": { "enabled": true, "steps": [
  { "title": "Customer", "field_keys": ["proforma_no", "customer_name"] },
  { "title": "Items",    "field_keys": ["proforma_items", "total"] },
  { "title": "Notes",    "field_keys": ["notes"] }
] }
```

The table renders in that step; the Review & Confirm step summarises it as `N item(s)`; the rows are form
state, so they survive Back and Next; and each Next saves a draft that includes them. Put a `totals[].sync_to`
target (`total` above) in the **same step** — a required field named by no step is never rendered and the
create 422s. The generated Playwright spec walks a wizard's plain fields and skips the inline key, so it
reaches the review step with 0 rows; adding rows is a hand spec's job. (An **action** wizard cannot have an
inline-items step: its steps can only name the action's own `fields`.)
:::

::: tip Child rows are validated against `fields` (v3.5.27)
The generated Create and Edit services check every inline row against the `fields` you declared —
`required` becomes `required` (otherwise `nullable`), and the `type` adds a rule (`number` → `numeric`,
`checkbox` → `boolean`, `date` → `date`, `api-select` → `integer`, text → `string`; a `select` gets no
type rule, since its value may be a string or an id). A row is then **reduced to the keys it declares**
(plus `uuid`, which the Edit sync matches on) before it reaches `Child::create()`. Before v3.5.27 rows
were passed through unvalidated, and because `BaseModel` is `guarded = []` a client could set any
column of the child — its `id`, its timestamps, a status flag — and a missing required field was a
500. A column you need to write that is **not** a `fields` entry (a copied parent value, an audit
column) goes through `inject_from_parent` or the generated audit injection, which are applied after
the reduction. The Edit sync also only ever matches a `uuid` to a row **of the parent being edited**;
another parent's uuid is treated as a new row, never adopted.
:::

---

## Deleting the parent

The parent's `DeleteService` deletes its `inline_items` children with it, unconditionally, through the child model's own
query builder (so a soft-deleting child is soft-deleted). The parent's delete check does **not** count those rows as a
reason to block (engine v3.5.31+; before that, a parent with items reported `can_delete: false` for a delete that would
have worked). Any *other* module that points at the parent, or another foreign key from the child module, still blocks.

## What the Generator Produces

- A hand-edit-protected wrapper component, `{Module}{Key}InlineItems.vue` (e.g.
  `OrdersOrderItemsInlineItems.vue`) — written **once** (`writeFileOnce()`), never touched by a
  future `--force` regenerate. This is genuinely write-once — unlike most generated files, it does
  not get overwritten even when `--force` is passed, so anything you hand-edit into it afterward is
  safe. As of generator-engine v3.5.18 the file contains **real, concrete markup** generated directly
  from your `fields[]` config, not a thin wrapper around a shared generic component:
  - The rows list, rendered as compact cards (default) or aligned table columns
    (`variant: "table"`) — see [The financial line-items pattern](#the-financial-line-items-pattern-variant-totals).
  - A real Add/Edit `AppDialog`, its fields generated one-by-one via the same per-widget dispatch
    (`generateField()`, through `generateInlineItemModalField()`) every ordinary Create/Edit form
    uses — a `type: "select"`/`"api-select"`/`"date"`/etc. field renders with the same component and
    conventions it would on the parent form itself.
  - A real, read-only View `AppDialog` (`generateInlineItemViewField()`).
  - A real Delete-confirmation `AppDialog`.
  Because it's ordinary generated Vue code rather than JSON-config-driven props, hand-authoring
  anything beyond what `fields[]` already expresses — conditional visibility, cross-field
  recalculation, a custom cell renderer — means editing the wrapper's own template/script directly.
  There is no separate hook-prop API to learn, and no named slots to override.
- A read-only `{Module}{Key}LineItemsView.vue` (e.g. `OrdersOrderItemsLineItemsView.vue`, engine
  v3.5.22+) for the Details/Overview page, also write-once: a table with one column per field not
  hidden by `show_in_table: false`, headed by its label, each value resolved by widget type (a select
  shows its option label or its related record's name, a checkbox Yes/No, a number its `decimals`), a
  footer row of column sums for any `totals` entry, and a message instead of an empty table. It replaced
  a version that imported a shared `LineItemsList.vue` this frontend doesn't ship. An existing module
  keeps its old file — delete it and regenerate to adopt the new one.
- `{Module}CreateService.php` saves every child row on create.
- `{Module}EditService.php` syncs on edit: rows removed from the form are
  deleted, rows matched by `uuid` are updated in place, new rows (no
  `uuid` yet) are inserted.
- `{Module}ViewService.php` loads the child rows alongside the parent
  record.
- `{Module}DeleteService.php` cascade-deletes every child row when the
  parent is deleted, unconditionally — an `inline_items` child has no
  independent lifecycle by design, so cascading on parent delete is the
  same ownership rule the edit-sync already applies, extended to delete.
  This respects the child's own soft/hard delete mode automatically.
- `{Module}DeleteCheckService.php`'s dependent-count check already flags
  the child as a blocking dependent too — for free, via the same generic
  FK-graph naming-convention detection that covers any `*_id` column,
  entirely independent of the `inline_items` config itself.
- If any `totals[].sync_to` is set, the Create/Edit form also gets an
  inline `@totals-change` handler on the wrapper component that keeps the
  named parent field equal to that sum and permanently disables it — see
  "The financial line-items pattern" above.

---

## Complete Example: Orders → Order Items

### 1. Generate the child module first — this order is mandatory

`OrderItems` needs nothing special — it's an ordinary module on its own —
but it **must** be scaffolded before `Orders`: `Orders.order_items` config
references `OrderItems`' namespace, so `OrderItems` must already be
registered before the parent's `inline_items` config can resolve it
correctly.

```bash
php artisan make:module Custom/OrderItems
```

::: warning Known pitfall: the child's own FK relation may guess the wrong namespace
Scaffolding `OrderItems` before `Orders` exists has a side effect on the
child's own `belongsTo()` relation back to the parent — see
[Child module's FK relation guesses the wrong namespace](#child-modules-fk-relation-guesses-the-wrong-namespace)
below.
:::

### 2. Generate the parent module normally too

```bash
php artisan make:module Custom/Orders
```

### 3. Hand-edit `Orders/module.json`

Add the `inline_items` entry shown in [Configuration](#module-json-configuration) above.

### 4. Regenerate the parent with `--force`

```bash
php artisan make:module Custom/Orders --force
```

`ModuleScaffolder::mergePersistedFields()` carries `inline_items` forward
from the existing `module.json` into the fresh regenerate — this is what
makes step 3's edit actually take effect, and what makes it safe to run
`--force` again later (e.g. after adding a new column) without losing the
hand-authored config.

### 5. Confirm

- `OrdersOrderItemsInlineItems.vue` exists, containing real generated rows/Add/Edit/View/Delete
  markup for the `fields` array from step 3 — not an import of a shared component, see
  [What the Generator Produces](#what-the-generator-produces).
- `OrdersCreateForm.vue` / `EditForm.vue` import and render
  `<OrdersOrderItemsInlineItems v-model="form.order_items" />` — not the
  shared component directly.
- Create an Order with 2-3 nested order items via the generated UI (or a
  direct API call) — confirm matching `order_items` rows persist with the
  correct `order_id` and `currency` (copied from the parent, not
  user-entered, via `inject_from_parent`).
- Edit an existing Order — remove one item, change another, add a new
  one — confirm the sync is correct: removed rows are deleted, changed
  rows are updated in place (matched by `uuid`), new rows are inserted.
- Delete the Order — confirm its `order_items` rows are cascade-deleted
  too (soft-deleted if the child uses soft deletes).

---

## Common Pitfalls

### `created_by_id` fatal error on first create

**Symptom:** Creating a parent record with nested child rows throws
`Field 'created_by_id' doesn't have a default value` (or similar).

**As of generator-engine v3.4.12** this is self-correcting for most
projects: `child_has_creator_updater` auto-derives from the child module's
own real `has_creator_updater` via the module registry, so this shouldn't
happen unless the registry doesn't carry the key yet (an older project's
`MakeModulesFromDb.php`/`ModuleScaffolder.php` predating the fix) or the
child module genuinely isn't registered at generation time.

**Cause (pre-v3.4.12, or an unregistered child):** The child table has the
standard `created_by_id`/`updated_by_id` columns (this project's default
convention), but `child_has_creator_updater` was left `false`/omitted in
the `inline_items` config and nothing derived the real value.

**Fix:** Set `"child_has_creator_updater": true` explicitly and regenerate
with `--force` — or, better, upgrade to generator-engine v3.4.12+ and
confirm both registry-population call sites include `has_creator_updater`
so this never needs a manual flag again.

---

### An API-backed picker field crashes or is empty (engine v3.5.18–v3.5.21)

**Symptom:** A `fields[]` entry with `type: "select"` and a `splash_key` renders in the Add/Edit modal,
but the generated wrapper fails `vue-tsc` (`Property 'splash' does not exist`) and the dialog crashes
when opened; or its options never load.

**Cause:** those engine versions never resolved `splash_key` for `inline_items` fields, so the field fell
through to a default that names a `splash` object the wrapper doesn't have. Fixed in **v3.5.22**, which
resolves it at generation time — see [the note above](#module-json-configuration).

**Fix:** upgrade `generator-engine` to v3.5.22 or later and regenerate; or, on an older engine, hand-edit
the write-once wrapper's field to an `ApiSelect2Field` with the endpoint you want.

---

### Hand-edited wrapper component lost after `--force`

This should not happen — the wrapper component is genuinely write-once,
independent of `--force`. If you observe this, it's a regression in the
generator itself (`writeFileOnce()` in `BaseGenerator`), not a config
mistake — file it against `blutrixx/generator-engine`.

---

### `inline_items` silently disappears after an unrelated `--force`

This was a real, confirmed bug (found and fixed): `ModuleScaffolder::
mergePersistedFields()` originally didn't preserve `inline_items` across
`--force` at all, so a hand-added block vanished on the next regenerate for
an unrelated schema change.

**The fix is not a Composer update.** `ModuleScaffolder.php` is
**shell-owned** — it lives at
`BACKEND/app/Project/Commands/ModuleScaffolder.php` in this repository,
not inside the `blutrixx/generator-engine` package — so
`composer update blutrixx/generator-engine` cannot fix it. If you see this
symptom, check that this project's own `ModuleScaffolder.php` includes
`inline_items` in `mergePersistedFields()`'s preserved-key list. It is
already fixed in this repo as of **2026-08-20**, which also added
`mergeExistingConfig()` for the `make:modules-from-db` batch path — see
[Bulk generation → Preservation on re-runs](/legacy/bulk-generation#preservation-on-re-runs).

---

### Child module's FK relation guesses the wrong namespace

**Symptom:** After scaffolding the child before the parent (the mandatory
order described in step 1 of the example above), the child's own generated
`belongsTo()` relation back to the parent — e.g.
`OrderItemsModel::order(): BelongsTo` — points at the wrong namespace,
silently guessing something like `Core` when the parent is actually
`Custom`-grouped.

**Cause:** At the moment `OrderItems` is scaffolded, `Orders` doesn't
exist anywhere yet — not just unregistered, genuinely not yet generated —
so the ordinary FK-relation namespace resolver
(`PathManager::resolveBackendModuleNamespace()`, used by `ModelGenerator`)
has nothing to resolve the parent's real module group against, and
guesses wrong. This is a **different, separate bug** from the
`inline_items`-specific namespace issue in
`BaseServiceGenerator::buildChildNamespace()` (already fixed) — this one
lives in the ordinary FK-relation namespace resolver and affects any
real-FK child-before-parent scaffold, not just `inline_items`.

**Fix:** After scaffolding the parent, regenerate the child once more with
`--force` so its `Model` picks up the now-real namespace:

```bash
php artisan make:module Custom/OrderItems --force
```

A misresolved namespace also prints a warning at scaffold time
(`PathManager::reportIssue()`, surfaced through `$this->warn()` in the
console output) — watch the `make:module Custom/OrderItems` output for a
"not found in project or shell registry" message.

---

## Related Pages

- [Delegations](/legacy/delegations) — the other related-records mechanism, for genuinely independent child modules.
- [Creating modules](/legacy/creating-modules) — `make:module` CLI reference, `--force`, and the emit-config / schema workflow.
- [generator-engine docs: Examples — Parent-child (inline_items)](https://joelnjoshkibona.github.io/generator-engine/examples/inline-items) — the canonical, package-level reference this page summarizes for SYSTEM_SHELL's own conventions.

:::
