::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/delegations.md`.
:::

::: v-pre

# Delegations and Child Modules

## Overview

A **delegation** embeds a related module's full CRUD interface — list, create, edit, and delete — directly inside the parent module's detail page. Rather than navigating away to a separate module to manage child records, the child interface appears as a **tab** or **modal panel** inside the parent's detail view.

The canonical mental model is an invoice with line items:

```
Invoices Detail Page
├── Overview tab         (standard — the invoice fields)
├── Line Items tab       (delegation — child records belonging to this invoice)
└── History tab          (standard — activity log)
```

The Line Items tab knows which invoice it is inside. It passes the parent invoice's UUID as a query parameter when it fetches child records, and it pre-fills the `invoice_id` field whenever a new line item is created from within the tab. The user never has to think about the relationship — it is wired automatically.

Delegations are declared in the parent module's `module.json` under the `"delegations"` object, keyed by a unique delegation key. The generator reads this config and scaffolds the backend delegation service and the frontend tab or modal component automatically.

::: warning Corrected 2026-08-03
This page previously showed `delegations` as a flat JSON array
(`"delegations": [{...}]`) throughout. It is a **map keyed by delegation
key** — `ModuleScaffolder`'s real scaffolding code does
`foreach ($config['delegations'] as $delegationKey => $delegation)`, which
only works with `{"saleItems": {...}}`. A JSON array decodes to integer PHP
keys (`0`, `1`, ...), breaking file/route naming. Confirmed against the
real scaffolding source and generator-engine's own fixed
[Delegations reference](https://joelnjoshkibona.github.io/generator-engine/delegations)
and [Examples](https://joelnjoshkibona.github.io/generator-engine/examples/delegations).
Every JSON example on this page has been corrected below.
:::

::: tip The related module must already exist
A delegation embeds an existing module — it does not create one. Before adding a delegation to module A that points to module B, module B must already be generated and its routes must be registered. See [Prerequisites](#prerequisites).
:::

---

## `module.json` Configuration

`delegations` is a top-level object in `module.json`, alongside `features`, `menu_config`, and `columns` — keyed by a unique delegation key (conventionally camelCase of `name`). Each entry configures one child panel.

```json
{
  "module_name": "Sales",

  "delegations": {
    "saleItems": {
      // StudlyCase name — drives all generated class and file names.
      // Can differ from the outer key, but conventionally matches it.
      "name": "SaleItems",

      // Human-readable label shown on the tab or modal trigger button
      "label": "Sale Items",

      // "tab" renders inline in the detail tab bar
      // "modal" renders as a button that opens a modal overlay
      "uiType": "tab",

      // The module that owns the child records
      // Use an object to specify the group explicitly
      "relatedModule": {
        "name": "SaleItems",
        "group": "Custom"
      },

      // Field on the parent record whose value is passed to the child as the filter value
      // Default: "uuid"
      "parentKey": "uuid",

      // FK column on the related table used to filter the child list
      // Default: "parent_id"
      "filterKey": "sale_id",

      // Field on the parent used when building default endpoint paths
      // Default: "id"
      "parentIdField": "id",

      // Fields pre-filled when a new child record is created from within the panel
      // Use {{parent.fieldName}} to reference a field from the current parent record
      "defaults": [],

      // Per-operation config — which CRUD actions are active and how they map to the API
      "operations": {
        "list": {
          "enabled": true,
          "endpoint": {
            "method": "GET",
            "path": "/sale-items",
            "permission": "SaleItems.list"
          },
          "backend": {},
          "frontend": {}
        },
        "create": {
          "enabled": true,
          "endpoint": {
            "method": "POST",
            "path": "/sale-items",
            "permission": "SaleItems.create"
          },
          "backend": {},
          "frontend": {}
        },
        "edit": {
          "enabled": true,
          "endpoint": {
            "method": "PUT",
            "path": "/sale-items/{uuid}",
            "permission": "SaleItems.edit"
          },
          "backend": {},
          "frontend": {}
        },
        "view": {
          "enabled": false,
          "endpoint": {
            "method": "GET",
            "path": "/sale-items/{uuid}",
            "permission": "SaleItems.view"
          },
          "backend": {},
          "frontend": {}
        },
        "delete": {
          "enabled": true,
          "endpoint": {
            "method": "DELETE",
            "path": "/sale-items/{uuid}",
            "permission": "SaleItems.delete"
          },
          "backend": {},
          "frontend": {}
        }
      }
    }
  }
}
```

::: tip `module.json` is always merged
Like all developer-owned keys, `delegations` is preserved on every `--force` re-run. Changing an operation's `enabled` flag and re-running with `--force` regenerates only the affected components — it will not remove delegations you have already configured.
:::

---

## Delegation Object Fields

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | required | StudlyCase name (e.g. `"SaleItems"`). Drives generated class names, component file names, and the tab label slot identifier. |
| `label` | string | same as `name` | Human-readable label shown on the tab strip or modal trigger button. |
| `uiType` | string | `"tab"` | Rendering mode. `"tab"` adds the panel to the detail tab bar. `"modal"` renders a trigger button that opens a modal overlay. |
| `relatedModule` | object \| string | required | The module that owns the child records. Preferred form: `{ "name": "SaleItems", "group": "Custom" }`. Legacy string form (e.g. `"SaleItems"`) is accepted and assumes group `"Core"`. |
| `parentKey` | string | `"uuid"` | The field on the parent record whose value is injected as the filter value when querying the child list. Usually `"uuid"`. |
| `filterKey` | string | `"parent_id"` | The FK column on the related table that is used to filter the child list (e.g. `?sale_id=<parentKey value>`). Must match an actual column on the related table. |
| `morphFilter` | object | unset | **Reverse-morph delegations only.** A second scoping clause `{ "column": "payable_type", "value": "vendor" }` appended after the `filterKey` scope on every generated query. Required when `filterKey` is the `{prefix}_id` half of a polymorphic pair — see [`morphFilter` — reverse-morph delegations](#morphfilter-reverse-morph-delegations). |
| `parentIdField` | string | `"id"` | The field on the parent record used when building default API endpoint path segments. |
| `defaults` | array | `[]` | Field values pre-filled when creating a new child record from within the panel. Each entry is <code v-pre>{ "field": "invoice_id", "value": "{{parent.uuid}}" }</code>. The <code v-pre>{{parent.fieldName}}</code> syntax resolves the named field from the current parent record at runtime. |
| `operations` | object | all disabled | Per-operation configuration. See [Operations Configuration](#operations-configuration). |

::: warning `relatedModule` must not be empty
The `DelegationConfigNormalizer` will skip any delegation whose `relatedModule` resolves to an empty string or an empty object. A skipped delegation emits a warning but does not block generation of the rest of the module. Always provide a non-empty value.
:::

---

## Operations Configuration

Each of the five CRUD operations is configured independently under the `operations` key. An operation that has `"enabled": false` is entirely omitted from the generated component — no button, no API call, no permission check.

### Operation shape

```json
"<operation>": {
  "enabled": true,
  "endpoint": {
    "method": "GET | POST | PUT | DELETE",
    "path": "/resource-path/{uuid}",
    "permission": "ModuleName.action"
  },
  "backend": { ... },
  "frontend": { ... }
}
```

### `list` operation

The `list` operation fetches child records for the current parent. The `filterKey` is appended as a query parameter automatically.

```json
"list": {
  "enabled": true,
  "endpoint": {
    "method": "GET",
    "path": "/sale-items",
    "permission": "SaleItems.list"
  },
  "backend": {
    "filterableFields": "name,quantity",
    "sortableFields": "created_at",
    "eagerLoadRelationships": "",
    "filterableRelationships": [],
    "filterFields": [],
    "bulk_actions": [
      { "key": "archive", "label": "Archive" }
    ],
    "export": true,
    "import": true
  },
  "frontend": {
    "primaryField": "name",
    "fields": [
      {
        "key": "name",
        "title": "Item Name",
        "sortable": true,
        "data": "name",
        "type": "text",
        "class": ""
      }
    ]
  }
}
```

**`backend` sub-keys for `list`**

| Key | Description |
|-----|-------------|
| `filterableFields` | Comma-separated list of column names the list service will accept as filter parameters. |
| `sortableFields` | Comma-separated list of column names that can be used as `sort_by` values. |
| `eagerLoadRelationships` | Comma-separated relation names to eager-load on every query. |
| `filterableRelationships` | Array of relationship-based filter definitions. |
| `filterFields` | Array of filter field descriptors returned in the API response so the frontend knows what filter controls to render. |
| `bulk_actions` | (Since v2.29.0) Same shape as the standalone-module key of the same name (see [Actions › Bulk actions](/legacy/actions#bulk-actions-—-a-different-nested-config-location)) — renders a real bulk-action toolbar in the delegation's own tab, gated by the **related module's own** `{RelatedModule}.bulkAction` permission. |
| `export` | (Since v2.29.0) Boolean. Renders a real export button in the delegation's tab. Reuses the related module's own `.list` permission — no dedicated export permission. |
| `import` | (Since v2.29.0) Boolean. Renders a real import dialog in the delegation's tab, gated by the related module's own `{RelatedModule}.import` permission. |

**`frontend` sub-keys for `list`**

| Key | Description |
|-----|-------------|
| `primaryField` | The column whose value is displayed as the primary identifier in mobile card views. |
| `fields[]` | Column definitions for the list table. Each entry maps to a `Column` object: `key`, `title`, `sortable`, `data` (response key), `type`, and `class`. |

### `create` operation

```json
"create": {
  "enabled": true,
  "endpoint": {
    "method": "POST",
    "path": "/sale-items",
    "permission": "SaleItems.create"
  },
  "backend": {
    "fields": [
      { "field": "name",     "rules": "required|string|max:255" },
      { "field": "quantity", "rules": "required|integer|min:1"  }
    ]
  },
  "frontend": {
    "fields": [
      { "field": "name",     "label": "Item Name", "field_type": "input",  "required": true },
      { "field": "quantity", "label": "Quantity",  "field_type": "number", "required": true }
    ],
    "button_text": "Add Item",
    "hiddens": [],
    "defaults": []
  }
}
```

**`backend` sub-keys for `create` and `edit`**

| Key | Description |
|-----|-------------|
| `fields[]` | Validation rule definitions. Each entry is `{ "field": "column_name", "rules": "laravel|validation|rules" }`. These rules are written into the delegation service's `validateData()` method. |

**`frontend` sub-keys for `create`**

| Key | Description |
|-----|-------------|
| `fields[]` | Form field descriptors. Each entry is `{ "field", "label", "field_type", "required" }`. `field_type` maps to a form input component (e.g. `input`, `number`, `select`, `textarea`, `date`). |
| `button_text` | Label on the submit button inside the inline create form. |
| `hiddens` | Hidden fields injected into the form payload. Typically used for the parent FK when `defaults` is not sufficient. |
| `defaults` | Field default values applied when the form is initialised. |

### `edit` operation

```json
"edit": {
  "enabled": true,
  "endpoint": {
    "method": "PUT",
    "path": "/sale-items/{uuid}",
    "permission": "SaleItems.edit"
  },
  "backend": {
    "fields": [
      { "field": "name",     "rules": "required|string|max:255" },
      { "field": "quantity", "rules": "required|integer|min:1"  }
    ]
  },
  "frontend": {
    "fields": [
      { "field": "name",     "label": "Item Name", "field_type": "input",  "required": true },
      { "field": "quantity", "label": "Quantity",  "field_type": "number", "required": true }
    ]
  }
}
```

### `view` operation

The `view` operation is usually disabled in delegations (`"enabled": false`). When enabled, it renders a read-only record preview inside the panel.

```json
"view": {
  "enabled": false,
  "endpoint": {
    "method": "GET",
    "path": "/sale-items/{uuid}",
    "permission": "SaleItems.view"
  }
}
```

### `delete` operation

```json
"delete": {
  "enabled": true,
  "endpoint": {
    "method": "DELETE",
    "path": "/sale-items/{uuid}",
    "permission": "SaleItems.delete"
  }
}
```

::: tip Permission checking
The generated tab/modal component calls `hasPermission()` before rendering any action button. A user who lacks `SaleItems.create` will not see the create button, regardless of whether the operation is `enabled`. Both the config flag and the runtime permission check must be satisfied.
:::

---

## Generated Files

::: warning Corrected 2026-08-03
The filenames below were wrong in a previous version of this page (they
included a redundant `Delegation`/`Component` suffix, and claimed a
`RelatedForm.vue` file that doesn't exist). Verified against real generator
output from a live scratch-module generation
(`WarehousesStockMovementsService.php`, `WarehousesStockMovementsTab.vue`).
:::

For each delegation entry the generator produces these files:

| File | Location | Purpose |
|------|----------|---------|
| `{Parent}{Delegation}Service.php` | `BACKEND/.../{Parent}/Services/` | Handles child-record queries with the FK filter applied. Wraps the related module's list and CRUD operations in a context-aware service. |
| `{Parent}{Delegation}Tab.vue` | `FRONTEND/.../{Parent}/Components/` (or module root) | (`uiType: "tab"`) Full list + inline CRUD panel rendered as a tab, wired into the parent's view modal tabs and details-page nested route. Receives the parent UUID as a prop and constructs the filtered API endpoint. |
| `{Parent}{Delegation}Modal.vue` | `FRONTEND/.../{Parent}/Components/` | (`uiType: "modal"`) Modal trigger button with the same child CRUD panel rendered inside a dialog. |
| `{RelatedModule}CreateForm.vue` / `EditForm.vue` / `ViewComponent.vue` | `FRONTEND/.../{RelatedModule}/Components/` — the **related** module's own directory, NOT the parent's | The actual create/edit/view forms — written into the related module's own canonical path, the same one its own independent scaffold uses. **No `DeleteForm.vue` is generated here** — the related module needs its own independently-scaffolded delete form. |

::: warning Generating a delegation can overwrite the related module's own standalone form
Because `{RelatedModule}CreateForm.vue`/`EditForm.vue` are written into the
related module's own canonical `Components/` path — the same file its own
independent scaffold already wrote — generating a delegation **overwrites**
that file with the delegation's own field list, which deliberately excludes
the parent FK column (the tab supplies it via props instead). If the
related module is ever accessed **standalone** (not through the parent's
tab), its create/edit form has no way to set that FK column at all,
confirmed live while verifying this. If a module needs both delegation
access and genuine standalone create/edit access, keep the FK field in both
configs and rely on `hiddens`/`defaults` (which the generated component
already supports as props) rather than physically omitting the field.
:::

### Example for Sales → SaleItems

```
BACKEND/.../Sales/Services/
└── SalesSaleItemsService.php

FRONTEND/.../Sales/Components/
└── SalesSaleItemsTab.vue

FRONTEND/.../SaleItems/Components/        # the RELATED module's own directory
├── SaleItemsCreateForm.vue               # overwritten by the delegation, see warning above
└── SaleItemsEditForm.vue
```

::: tip File naming pattern
Backend service and frontend tab/modal file names always follow
`{ParentModule}{DelegationName}` prefix — e.g. `SalesSaleItems`, never just
`SaleItems`. This prevents collisions when two different parent modules
both delegate to the same child module. The related module's own
create/edit form files do **not** get this prefix — they keep the related
module's own plain name, since they live in that module's own directory.
:::

---

## Tab Delegations

A delegation with `"uiType": "tab"` is added to the parent module's `{Parent}DetailsLayout.vue` as a full tab alongside the standard Overview and History tabs.

```
Sales Detail Page
├── Overview tab       (always present — standard generated tab)
├── Sale Items tab     (delegation — added because uiType is "tab")
└── History tab        (always present — standard generated tab)
```

The tab component receives the parent's identifier via a prop and constructs the list endpoint dynamically:

```
GET /sale-items?sale_id=<parent.uuid>
```

Everything inside the tab — pagination, sorting, inline create, inline edit, delete confirmation — operates on child records scoped to this parent. The user never leaves the Sales detail page.

::: warning A tab delegation renders in two places, not one
Every `uiType: "tab"` delegation is wired into **both** the standalone `{Parent}DetailsLayout.vue` page **and** `{Parent}ViewModal.vue`'s own tab registry — `ViewModalGenerator::buildDelegationTabReplacements()` scans `config['delegations']` for `uiType: "tab"` entries independently of the details-page tab generation, and imports the exact same `{Parent}{Delegation}Tab.vue` component into the modal. Before this existed, the modal and the details page had entirely separate, hardcoded tab sources, so a child list was reachable only via the standalone page's URL — never from the eye/view action most users actually click. If you only ever look at the standalone details page, you will miss that the same tab also appears inside the parent's View Modal.
:::

**When to use a tab delegation:**
- The child records are primary content that the user regularly reads, adds to, or edits in context.
- There can be many child records and the user benefits from pagination and filtering.
- The child data is intrinsically part of understanding the parent record (e.g. line items on an invoice, items in a sale).

---

## Modal Delegations

A delegation with `"uiType": "modal"` renders as a button on the detail page. Clicking the button opens a modal overlay containing the child list and CRUD interface.

```
Sales Detail Page
├── Overview tab       (standard)
├── History tab        (standard)
│
└── [Attachments]      ← modal trigger button rendered in the detail body
        ↓ click
    ┌────────────────────────┐
    │  Attachments Modal     │
    │  ─────────────────     │
    │  [ list of files ]     │
    │  [ + Add Attachment ]  │
    └────────────────────────┘
```

**When to use a modal delegation:**
- The child records are secondary or supporting data (attachments, notes, tags, approvals).
- The user accesses them occasionally rather than every time they open the parent detail.
- The child list is expected to be short and does not need its own pagination controls visible at all times.
- You do not want to occupy a permanent slot in the tab bar for low-frequency data.

::: tip Choosing between tab and modal
Default to `"tab"` when in doubt. Use `"modal"` only when the child records are clearly supplementary and would clutter the tab bar if always visible.
:::

---

## Complete Example: Invoices → Line Items

This section walks through a full delegation setup from config to running code.

### 1. Confirm that LineItems already exists

The LineItems module must be generated before the delegation is added. Verify:

```bash
ls BACKEND/app/Project/Modules/Custom/LineItems/
# Should contain: LineItemsController.php, LineItemsModel.php, Routes/api.php, ...
```

### 2. Add the delegation to `Invoices/module.json`

```json
{
  "module_name": "Invoices",

  "delegations": {
    "lineItems": {
      "name":    "LineItems",
      "label":   "Line Items",
      "uiType":  "tab",
      "relatedModule": {
        "name":  "LineItems",
        "group": "Custom"
      },
      "parentKey":     "uuid",
      "filterKey":     "invoice_id",
      "parentIdField": "id",
      "defaults": [
        { "field": "invoice_id", "value": "{{parent.uuid}}" }
      ],
      "operations": {
        "list": {
          "enabled": true,
          "endpoint": {
            "method":     "GET",
            "path":       "/line-items",
            "permission": "LineItems.list"
          },
          "frontend": {
            "primaryField": "description",
            "fields": [
              { "key": "description", "title": "Description", "sortable": true,  "data": "description", "type": "text",    "class": "" },
              { "key": "quantity",    "title": "Qty",          "sortable": false, "data": "quantity",    "type": "number",  "class": "" },
              { "key": "unit_price",  "title": "Unit Price",   "sortable": false, "data": "unit_price",  "type": "decimal", "class": "" }
            ]
          }
        },
        "create": {
          "enabled": true,
          "endpoint": {
            "method":     "POST",
            "path":       "/line-items",
            "permission": "LineItems.create"
          },
          "backend": {
            "fields": [
              { "field": "description", "rules": "required|string|max:500" },
              { "field": "quantity",    "rules": "required|integer|min:1" },
              { "field": "unit_price",  "rules": "required|numeric|min:0" }
            ]
          },
          "frontend": {
            "fields": [
              { "field": "description", "label": "Description", "field_type": "input",  "required": true },
              { "field": "quantity",    "label": "Qty",          "field_type": "number", "required": true },
              { "field": "unit_price",  "label": "Unit Price",   "field_type": "number", "required": true }
            ],
            "button_text": "Add Line Item"
          }
        },
        "edit": {
          "enabled": true,
          "endpoint": {
            "method":     "PUT",
            "path":       "/line-items/{uuid}",
            "permission": "LineItems.edit"
          },
          "backend": {
            "fields": [
              { "field": "description", "rules": "required|string|max:500" },
              { "field": "quantity",    "rules": "required|integer|min:1" },
              { "field": "unit_price",  "rules": "required|numeric|min:0" }
            ]
          }
        },
        "delete": {
          "enabled": true,
          "endpoint": {
            "method":     "DELETE",
            "path":       "/line-items/{uuid}",
            "permission": "LineItems.delete"
          }
        },
        "view": {
          "enabled": false
        }
      }
    }
  }
}
```

::: tip Don't put the parent FK in `backend.fields`
`invoice_id` (the `filterKey` column) is intentionally absent from
`create`'s `backend.fields` above — it's never submitted by the frontend
form (it's hidden/defaulted from the parent instead) and the delegation
service injects it programmatically after validation
(`$validData[$filterKey] = $parent->{$parentIdField}`). Adding a
`required` rule for it would validate against a field that's never present
in the submitted payload.
:::

### 3. Regenerate the Invoices module

```bash
php artisan make:module Core/Invoices --force
```

`--force` is required to regenerate the detail layout and add the new tab. `module.json` is merged, not overwritten.

### 4. What the generator produces

- `InvoicesLineItemsService.php` — list/create/edit/view/delete service with `invoice_id` filter wired in.
- `InvoicesLineItemsTab.vue` — tab panel with paginated child list, inline create form, inline edit, and delete confirmation.
- `LineItemsCreateForm.vue` / `LineItemsEditForm.vue` — written into **LineItems' own** `Components/` directory (not Invoices'), overwriting whatever standalone create/edit forms LineItems already had — see the warning under [Generated Files](#generated-files).
- `InvoicesDetailsLayout.vue` is regenerated with the Line Items tab added alongside Overview and History.
- `InvoicesViewModal.vue` is **also** regenerated with the same Line Items tab added to its own tab registry — see the callout below.

::: warning The same tab also renders inside `InvoicesViewModal.vue`
Because this delegation uses `uiType: "tab"`, `InvoicesLineItemsTab.vue` is wired into two independent places by two independent generator code paths: the standalone `InvoicesDetailsLayout.vue` (step 4 below) **and** `InvoicesViewModal.vue`'s own tab registry, opened from the eye/view action on the Invoices list page. Both mount the exact same tab component. See [Tab Delegations](#tab-delegations) above.
:::

### 5. How the tab works at runtime

1. User opens an invoice detail page (`/invoices/<uuid>/details`) **or** opens an invoice's View Modal from the list page — both roads lead to the same tab.
2. The router mounts `InvoicesDetailsLayout.vue` (standalone page) or `InvoicesViewModal.vue` (modal), either of which renders a tab bar that now includes Line Items.
3. User clicks the Line Items tab.
4. `InvoicesLineItemsTab.vue` mounts (the same component in both places) and fires:
   ```
   GET /line-items?invoice_id=<parent.uuid>
   ```
5. The list renders. The create button (if the user has `LineItems.create`) pre-fills `invoice_id` from `defaults`.
6. All create, edit, and delete actions operate on the child records in place — the invoice URL never changes (standalone page) and the modal never closes (View Modal).

---

## `morphFilter` — Reverse-Morph Delegations {#morphfilter-reverse-morph-delegations}

`filterKey` alone is enough when the child table has a dedicated FK column
per parent. It is **not** enough when the child rows are attached through a
[polymorphic pair](/legacy/morphs) — because `{prefix}_id` is shared across
every owner type, filtering on it alone also matches rows belonging to a
*different* owner whose id happens to collide numerically.

The canonical case: `Payments` declares a `payable` morph
(`payable_type`/`payable_id`), and `Vendors` wants a Payments tab on its
detail page. `"filterKey": "payable_id"` on its own would show a Vendor
every Payment whose `payable_id` matches its id — including Payments owned
by a `PurchaseOrder` or an `Expense`. `morphFilter` adds the missing second
clause (generator-engine **v3.3.0**):

```json
{
  "delegations": {
    "Payments": {
      "name":          "Payments",
      "label":         "Payments",
      "uiType":        "tab",
      "relatedModule": { "name": "Payments", "group": "Core" },
      "parentKey":     "uuid",
      "filterKey":     "payable_id",
      "parentIdField": "id",
      "morphFilter":   { "column": "payable_type", "value": "vendor" },
      "operations":    { "list": { "enabled": true, "endpoint": { "method": "GET", "path": "/payments", "permission": "Payments.list" } } }
    }
  }
}
```

| Key | Description |
|-----|-------------|
| `column` | The morph pair's `{prefix}_type` column on the related table (e.g. `payable_type`). |
| `value` | The value stored in that column for **this** parent type — the `morphs[].targets[].alias` registered by the owning module (e.g. `vendor`), or the fully-qualified model class string when that module has no `targets` and therefore no morph map. |

`DelegationServiceGenerator::buildMorphFilterClause()` turns this into a
literal `->where('payable_type', 'vendor')` appended after the ordinary
`filterKey` scope in **every** query-building method the generated
delegation service emits — list, view, edit, delete, and the pre-flight
lookups they share. It is all-or-nothing per delegation: leave `morphFilter`
unset (or give it an empty `column`) and the generated service is
byte-identical to an ordinary delegation's.

`PhpUnitTestGenerator` reads the same key: the generated delegation tests
set the type column on their fixture rows too, so a fixture created for the
test actually matches the runtime query's second clause instead of 404ing.

::: warning `value` must match what the owning module actually stores
If the owning module (`Payments` here) declares
[`morphs[].targets`](./morphs.md#targets-the-real-mechanism), its
`payable_type` holds the short **alias**, so `value` must be that alias
(`"vendor"`). With empty `targets` there is no morph map and the column
holds a fully-qualified class name, so `value` must be that class string
instead. Mixing the two silently yields an always-empty tab — the query is
valid, it just matches nothing.
:::

::: tip Pair it with `relations.morphMany`
`morphFilter` scopes the delegation's own queries; it does not give the
parent model a relation. If you also want `$vendor->payments` in PHP,
declare [`relations.morphMany`](/legacy/morphs#reverse-morph-relations-relations-morphmany)
on the parent module. The two are independent and commonly used together.
:::

---

## Prerequisites

Before adding a delegation to module A that points to module B, verify all of the following:

1. **Module B is generated.** Its controller, model, migration, and routes must exist. Run `php artisan make:module` for module B first if it does not yet exist.

2. **Module B's table has the FK column.** The column named in `filterKey` (e.g. `invoice_id`) must exist on module B's table. If it does not, create and run a new migration to add it.

   If module B attaches its rows polymorphically instead, `filterKey` is the pair's `{prefix}_id` column and the delegation **also** needs [`morphFilter`](#morphfilter-reverse-morph-delegations) — without it the tab shows rows belonging to other owner types.

3. **Module B's list endpoint accepts the filter param.** The list endpoint path defined in `operations.list.endpoint.path` must accept `filterKey` as a query parameter. The list service's `$filterableFields` array (or the delegation service wrapper) must include it.

4. **The permissions exist.** All permission strings referenced in `operations.*.endpoint.permission` must be registered in the permissions system. Permissions for module B are created automatically when module B is generated, so this is usually satisfied automatically.

5. **`relatedModule.group` matches the actual group.** If module B is in the `Custom` group, `relatedModule.group` must be `"Custom"`, not `"Core"`. A mismatch causes the generator to look in the wrong directory and fail to find the related module files.

::: warning Create the related module first
The most common setup mistake is adding the delegation config before generating module B. The generator does not create module B for you — it only embeds it. If module B does not exist, the generator will emit a warning and skip the delegation entirely.
:::

---

## Validation Rules

The `DelegationConfigNormalizer` validates each delegation entry before the generator processes it. Any entry that fails validation is **skipped with a warning** — it does not block generation of the rest of the module.

| Rule | Detail |
|------|--------|
| `name` must not be empty | An empty or missing `name` causes the entry to be skipped. |
| `uiType` must be `"tab"` or `"modal"` | Any other value (including `"Tab"` or `"Modal"` with wrong case) is rejected. |
| `relatedModule` must not be empty | An empty string `""` or an empty object `{}` causes the entry to be skipped. String `"0"` is also treated as empty. |

::: tip Check the console output after generation
When a delegation is skipped, the generator prints a warning line with the delegation name and the reason. If your tab does not appear after a `--force` run, check the console output first — a skipped delegation warning is the most likely cause.
:::

---

## Common Pitfalls

### Related module not generated yet

**Symptom:** Delegation is skipped with a warning; the tab does not appear after generation.

**Cause:** `relatedModule` references a module that has not been generated, so the generator cannot find its files.

**Fix:** Generate module B first (`php artisan make:module {Group}/{ModuleName}`), then re-run the parent module with `--force`.

---

### `filterKey` column does not exist on the related table

**Symptom:** Tab renders but the child list returns an SQL error or empty results with an unexpected query.

**Cause:** The column named in `filterKey` (e.g. `invoice_id`) does not exist on the related table.

**Fix:** Create a new migration to add the FK column to the related table, run the migration, then regenerate.

---

### A polymorphic child tab shows other owners' rows (or none at all)

**Symptom:** A tab whose `filterKey` is a morph pair's `{prefix}_id` column lists records that belong to a different owner type — or, after adding `morphFilter`, lists nothing.

**Cause:** Either `morphFilter` is missing (so only `payable_id` is matched, and ids collide across owner types), or its `value` does not match what the owning module actually stores in `{prefix}_type` — the short alias when that module declares `morphs[].targets`, the fully-qualified class string when it does not.

**Fix:** Add [`morphFilter`](#morphfilter-reverse-morph-delegations) with the exact stored value, then regenerate the parent module with `--force`.

---

### `relatedModule.group` is wrong

**Symptom:** Generator warning saying it could not find the related module; delegation is skipped.

**Cause:** The `group` value in `relatedModule` does not match the group the related module was actually generated into.

**Fix:** Check the directory `BACKEND/app/Project/Modules/` and use the exact group name (case-sensitive, StudlyCase).

---

### Forgetting `--force` after editing `module.json`

**Symptom:** Changes to the delegation config are not reflected in the generated components.

**Cause:** `module.json` was edited but `make:module` was not re-run with `--force`, so the template files were not regenerated.

**Fix:** Always run `php artisan make:module {Group}/{ModuleName} --force` after any change to `module.json`.

---

### Tab shows rows but no column headers

**Symptom:** The tab loads (the list request returns 200 with rows) but renders a table with no headers, and the generated tab file contains `const columns: ReportColumn[] = [ ]`.

**Cause:** The tab's columns are the *related* module's list fields, read from that module's registry entry or, failing that, its `module.json`. In a `make:modules-from-db` batch the parent is generated before its child, so neither existed yet. Fixed in the batch command (its pre-seeded registry entries now carry `features`); a tab generated before that fix is still wrong on disk.

**Fix:** Re-run the parent with `php artisan make:module {Group}/{ParentModule} --force` now that the child module exists, or re-run the whole batch with `--force`. If you write your own command that pre-seeds `PathManager::setModuleRegistry()`, include `config.features` on every entry.

---

### `defaults` not pre-filling the parent FK

**Symptom:** Creating a child record from the tab results in a validation error for the FK field, or the FK is saved as `null`.

**Cause:** The `defaults` array is empty or the <code v-pre>{{parent.fieldName}}</code> placeholder references the wrong field name.

**Fix:** Add a defaults entry: <code v-pre>{ "field": "invoice_id", "value": "{{parent.uuid}}" }</code>. Ensure `fieldName` matches the exact key in the parent record's API response (usually `"uuid"` for UUID-primary modules).

---

### `uiType` casing error

**Symptom:** Delegation is skipped with a validation warning.

**Cause:** `uiType` was written as `"Tab"` or `"Modal"` instead of the required lowercase `"tab"` or `"modal"`.

**Fix:** Use exactly `"tab"` or `"modal"` (all lowercase).

---

### Permissions not granted to the current user

**Symptom:** Tab renders but the create/edit/delete buttons are invisible, or the list returns a 403.

**Cause:** The permissions referenced in `operations.*.endpoint.permission` exist in the system but have not been assigned to the user's role.

**Fix:** Go to the permissions management UI, find the relevant `LineItems.*` permissions, and assign them to the appropriate role.

::: tip Permission keys for delegations use the child module's name
Delegation permissions follow the same `{ModuleName}.{action}` convention as standalone module permissions. They are registered when the child module (e.g. LineItems) is generated — not when the delegation config is added to the parent. If you are working with a module that was generated before the permissions feature existed, you may need to seed the permissions manually.
:::

---

## Related Pages

- [Creating modules](/legacy/creating-modules) — `make:module` CLI reference, `--force`, and the emit-config / schema workflow.
- [Module structure](/legacy/module-structure) — detailed walkthrough of every generated file and the backend service patterns.
- [Parent-child inline items](/legacy/inline-items) — the *other* related-records mechanism. Different from a delegation: an inline_items child has no independent existence outside its parent (rows are synced from the parent's own form submit), while a delegation embeds a real, independently-addressable module.
- [Custom Actions](/legacy/actions) — a completely separate mechanism (`actions`/`features.backend.list.bulk_actions`) for state-transition buttons, not related-record display.
- Polymorphic relations — configure `morphs[].targets` for polymorphic select inputs, and `relations.morphMany` for the reverse relation. See [Polymorphic relationships](/legacy/morphs) and the [generator-engine docs' Examples: Polymorphic (morphs)](https://joelnjoshkibona.github.io/generator-engine/examples/morphs).

:::
