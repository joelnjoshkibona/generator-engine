# Features Config Reference

`features` is a top-level key in the module config that controls what backend services, frontend pages, and mobile pages are generated — and how they behave.

```json
"features": {
  "backend":    { "list": {}, "create": {}, "view": {}, "edit": {}, "delete": {}, "deleteCheck": {} },
  "frontend":   { "list": {}, "create": {}, "view": {}, "edit": {}, "delete": {} },
  "mobile_app": { "enabled": false, "mode": "online" }
}
```

---

## `features.backend`

### `features.backend.list`

Controls `{Module}ListService.php`.

```json
"list": {
  "filterableFields":        ["name", "code"],
  "sortableFields":          ["name", "created_at"],
  "eagerLoadRelationships":  ["category", "status"],
  "filterableRelationships": [],
  "filterFields":            [],
  "default_list_filters": [
    { "column": "status_id", "operator": "eq", "value": 1 }
  ],
  "bulk_actions": [
    { "key": "activate", "status_target": "ACTIVE", "label": "Activate" },
    { "key": "archive",  "label": "Archive" }
  ],
  "import": false,
  "export": false,
  "endpoint": {
    "method":     "GET",
    "path":       "/products",
    "permission": "Products.list"
  }
}
```

| Key | Type | Description |
|-----|------|-------------|
| `filterableFields` | string[] | Column names that can be filtered by the API client. |
| `sortableFields` | string[] | Column names the list can be sorted by. |
| `eagerLoadRelationships` | string[] | Relationship method names to eager-load (e.g. `"category"`, `"status"`). |
| `filterableRelationships` | array | Relationships exposed as filter options. |
| `filterFields` | array | UI filter field definitions for the frontend filter panel. Leave empty (the common case) to auto-derive type-aware entries from `filterableFields` — see [Filter fields: auto-derivation and default filters](#filter-fields-auto-derivation-and-default-filters) below. |
| `default_list_filters` | array | Hard-coded filters always applied to the query. Each entry: `{ column, operator, value }`. |
| `bulk_actions` | array | Bulk action keys available on the list page — a **flat array** (unlike top-level `actions`, which is keyed). Each entry: `{ key: string, status_target?: string, label?: string, icon?: string, requiresPermission?: string, confirmMessage?: string, variant?: string }`. With `status_target`, the generated service does a real `$model->update(['status_id' => {Module}Model::{STATUS_TARGET_CONST}])` — requires an integer `status_id` column and a matching key in the module's own `constants` map, not a plain string status column. Without `status_target`, it's an empty TODO stub you hand-fill, same as a no-UI action. Renders a real bulk-action toolbar in the generated frontend — see [Frontend wiring](#frontend-wiring-for-bulk-actions-export-and-import) below. See [the Actions Cookbook example](examples/actions) for a full worked example. |
| `import` | boolean | Generate an import (CSV/Excel upload) endpoint, dry-run support, and a real import dialog in the generated frontend. Default `false`. See [Frontend wiring](#frontend-wiring-for-bulk-actions-export-and-import) below. |
| `export` | boolean | Generate an export (CSV/Excel download) endpoint and a real export button in the generated frontend. Default `false`. See [Frontend wiring](#frontend-wiring-for-bulk-actions-export-and-import) below. |
| `endpoint` | object | Route config — `{ method, path, permission }`. |

#### Frontend wiring for bulk actions, export, and import

::: tip Since v2.29.0
Verified directly against `ListPageGenerator`/`CustomFeatureTabComponentGenerator`
(`src/Generators/Frontend/Pages/ListPageGenerator.php`,
`src/Generators/Frontend/Components/CustomFeatures/CustomFeatureTabComponentGenerator.php`)
and a live-generated `{Module}ListPage.vue` and delegation tab component.
:::

All three of these keys drive real UI in the generated frontend, not just a
backend endpoint — both the standalone `{Module}ListPage.vue` and a
delegation's own tab component render through the shared `CrudListPanel`
component (SYSTEM_SHELL/FRONTEND, hand-maintained), which is wired with
`:enable-export`, `:enable-bulk-actions`, `:bulk-actions`, `:enable-import`,
`:bulk-action-permission`, and `:import-permission` computed directly from
this config block:

- **`export: true`** adds an export dropdown (CSV/XLSX/PDF) to the list
  toolbar. It reuses the surface's own `.list` permission — there is no
  dedicated export permission prop at all, matching how the standalone
  export *endpoint* already reuses `{Module}.list` server-side.
- **`bulk_actions`** (non-empty) adds a bulk-action toolbar that appears
  once one or more rows are selected, plus a "select all N matching the
  filter" banner for dispatching the action against a server-resolved set
  rather than just the checked rows. Gated by `{Module}.bulkAction`.
- **`import: true`** adds an import dialog (file input, dry-run checkbox,
  CSV/XLSX template-download buttons). Gated by `{Module}.import`.

This applies to delegations too: a delegation's own
`operations.list.backend.{bulk_actions,export,import}` (nested under the
delegation's `list` operation, not the top-level `features.backend.list`)
generate the same UI inside the delegation's tab, permission-gated against
the **related module's own** `{RelatedModule}.bulkAction`/`.import` (see
[Delegations › the `list` operation](delegations#operations-object)) — not
a delegation-specific permission.

**Not retroactive** — only a module generated or regenerated against
v2.29.0 or later picks up this frontend wiring.

#### Filter fields: auto-derivation and default filters

::: tip Since v2.27.0
Verified directly against `BaseServiceGenerator::generateFilterFields()`,
`generateFilterableFields()`, `getFilterFieldType()`, and
`buildFilterFieldOptions()` (`src/Generators/Backend/Services/BaseServiceGenerator.php`).
:::

Most module configs never hand-author `filterFields` at all — leaving it empty
(or omitting it) triggers auto-derivation from `filterableFields`, with the
UI control **type inferred from each column's real type** rather than every
field defaulting to a plain text box:

| Column shape | Inferred `type` | Notes |
|---|---|---|
| Foreign key (`type: 'foreignId'` in `config['columns']`, or a `*_id` column name) | `select_paginated` | Loads its own options live via `ApiSelect2` — no `options` array needed. |
| Enum column (has `enum_values`) | `select` | `options` is a real `{name, id}[]` list built from `enum_values`, humanized via `ucwords()`. |
| Boolean column, or a column named `is_default`/`is_active` | `select` | `options` is a hardcoded `[{name: 'Yes', id: 1}, {name: 'No', id: 0}]` pair. |
| `integer`, `bigInteger`, or `decimal` | `number` | |
| `date`, `datetime`, or `timestamp` | `date` | Renders as a date-picker filter even for a full datetime column — see the note below. |
| Anything else (plain string, text, etc.) | `text` | |

A field derived this way gets `key` (the column name), a `label` humanized
from the column name (`_id`/`_at` suffixes stripped before title-casing —
`category_id` → `"Category"`), and `type`/`options` as above. A hand-authored
`filterFields` entry always wins — auto-derivation only runs when
`filterFields` itself is empty.

**`id`, `uuid`, and `created_at` are always appended, regardless of config**
(as of v2.27.0 — not something you opt into):

- `id` and `uuid` **and** `created_at` are always added to the backend
  `filterableFields` allow-list (`generateFilterableFields()`'s return
  value), even if none of the three appear in your config at all.
- `id` (`type: 'text'`), `uuid` (`type: 'text'`), and `created_at`
  (`type: 'date'`) are always added to the frontend-facing `filterFields`
  array (`generateFilterFields()`), each only if a config-supplied or
  auto-derived entry for that same `key` isn't already present — an
  existing entry for `id`/`uuid`/`created_at` is never overridden or
  duplicated.

Every generated table carries `id`, `uuid`, and `created_at` via the standard
migration columns, so this guarantees a filterable-by-date and
filterable-by-identifier experience on every list page with zero config.
`created_at` filters as `date` even though the underlying column is a full
`datetime` — the companion `ListServiceTrait::applyFilter()` fix (in the
consuming app) expands a bare `"YYYY-MM-DD"` value into a whole-day range
rather than requiring an exact-instant match.

**This is not retroactive.** An already-generated module only picks up the
new default filters the next time it is regenerated (with or without
`--force` — this only adds new array entries, it does not touch
hand-written code).

**Before/after example.** A minimal config with an FK column and an enum
column, and no `filterFields`:

```json
{
  "features": {
    "backend": {
      "list": {
        "filterableFields": ["category_id", "status"]
      }
    }
  },
  "columns": [
    { "name": "category_id", "type": "foreignId" },
    { "name": "status", "type": "string", "enum_values": ["draft", "published", "archived"] }
  ]
}
```

produces a backend `filterableFields` allow-list of
`['category_id', 'status', 'id', 'uuid', 'created_at']`, and a generated
`filterFields` array (shown here as JSON for readability — the real
generated file is a PHP array literal with the same keys and values) of:

```json
[
  { "key": "category_id", "label": "Category", "type": "select_paginated" },
  {
    "key": "status", "label": "Status", "type": "select",
    "options": [
      { "name": "Draft",     "id": "draft" },
      { "name": "Published", "id": "published" },
      { "name": "Archived",  "id": "archived" }
    ]
  },
  { "key": "id",         "label": "ID",         "type": "text" },
  { "key": "uuid",       "label": "UUID",       "type": "text" },
  { "key": "created_at", "label": "Created At", "type": "date" }
]
```

---

### `features.backend.create`

Controls `{Module}CreateService.php`.

```json
"create": {
  "fields": [
    {
      "field":    "name",
      "rules":    "required|string|max:255",
      "messages": { "required": "Name is required." }
    },
    {
      "field": "category_id",
      "rules": "required|exists:categories,id"
    }
  ],
  "endpoint": {
    "method":     "POST",
    "path":       "/products",
    "permission": "Products.create"
  }
}
```

| Key | Type | Description |
|-----|------|-------------|
| `fields` | array | Validation rule definitions. Each: `{ field, rules, messages? }`. `rules` is a Laravel validation string. |
| `endpoint` | object | Route config. |

---

### `features.backend.view`

Controls `{Module}ViewService.php`.

```json
"view": {
  "eagerLoadRelationships": ["category", "status", "createdBy"],
  "endpoint": {
    "method":     "GET",
    "path":       "/products/{uuid}",
    "permission": "Products.view"
  }
}
```

---

### `features.backend.edit`

Same structure as `create`. Controls `{Module}EditService.php`.

```json
"edit": {
  "fields": [
    { "field": "name",        "rules": "required|string|max:255" },
    { "field": "category_id", "rules": "required|exists:categories,id" }
  ],
  "endpoint": {
    "method":     "PUT",
    "path":       "/products/{uuid}",
    "permission": "Products.edit"
  }
}
```

---

### `features.backend.delete`

Controls `{Module}DeleteService.php`.

```json
"delete": {
  "endpoint": {
    "method":     "DELETE",
    "path":       "/products/{uuid}",
    "permission": "Products.delete"
  }
}
```

::: warning Corrected 2026-08-15
This block previously showed a `success_message` key. It's a dead config key — nothing in
`DeleteServiceGenerator` reads it; the generated stub always returns the hardcoded literal
`"Record deleted successfully"` (`delete/service.stub`). Removed from the example above.
:::

---

### `features.backend.deleteCheck`

Controls `{Module}DeleteCheckService.php`. Not actually gated by this key at all: `DeleteCheckServiceGenerator`
generates whenever `features.backend.delete` is configured, with or without a `deleteCheck` key present — the
key exists in the schema as a documented no-op placeholder for a future per-module override, not something
you need to set today.

```json
"deleteCheck": {}
```

---

### `features.backend.createSplash` / `features.backend.editSplash`

Generates `{Module}CreateSplashService.php` / `{Module}EditSplashService.php` and a
`GET /{module}/create/splash` / `.../edit/splash` route that **pre-loads dropdown data**
(FK option lists, etc.) before the Create/Edit form renders — it does not render any UI
section itself.

::: warning Both conditions are required — since v3.4.7
The splash route is only registered, and the frontend form only calls it on mount, when
**both** are true: top-level `constants` is non-empty **and** the matching
`createSplash`/`editSplash` key is present. Before v3.4.7, `CreateFormGenerator`/
`EditFormGenerator` fired the network call whenever `constants` alone was non-empty —
so any module with `constants` but no real splash data (the common case: any
`status_target` activate/deactivate workflow) hit a route the backend never registered,
a 404 on every form mount, silently swallowed by a generic "Failed to load form data"
toast that never threw. If your module only needs `constants` for a status/bulk-action
workflow, **do not** add `createSplash`/`editSplash` — leave them out entirely.
:::

The actual payload is declared via `splashData` — a list of sources to pre-fetch, each
keyed by a `key` matched against a field's `splashKey`/`splash_key`:

```json
"createSplash": {
  "splashData": [
    {
      "key":    "item_types",
      "type":   "model",
      "module": "ItemTypes",
      "moduleGroup": "System",
      "paginate": false,
      "columns": ["id", "name"]
    }
  ]
},
"editSplash": {}
```

| Key | Type | Description |
|-----|------|-------------|
| `splashData` | array | Sources to pre-load, each `{ key, type, module, moduleGroup, paginate, columns }` (`type: "model"` fetches from a real module; `type: "custom"` reads a static `data` array instead). |

An `editSplash: {}` with no `splashData` is valid — it means "no extra data to preload,"
distinct from omitting the key entirely (which means "no splash route at all").

---

## `features.frontend`

### `features.frontend.list`

Controls `{Module}ListPage.vue` and `{Module}ListComponent.vue`.

```json
"list": {
  "primaryField": "name",
  "fields": [
    {
      "key":      "name",
      "title":    "Product Name",
      "sortable": true,
      "data":     "name",
      "type":     "text",
      "class":    "font-medium text-gray-900"
    },
    {
      "key":      "category_id",
      "title":    "Category",
      "sortable": false,
      "data":     "category?.name",
      "type":     "text",
      "class":    ""
    },
    {
      "key":      "is_active",
      "title":    "Active",
      "sortable": false,
      "data":     "is_active",
      "type":     "boolean",
      "class":    ""
    },
    {
      "key":            "internal_notes",
      "title":          "Internal Notes",
      "sortable":       false,
      "type":           "text",
      "defaultVisible": false
    }
  ]
}
```

| Key | Type | Description |
|-----|------|-------------|
| `primaryField` | string | Column name used as the record title in breadcrumbs and delete confirmations. |
| `fields` | array | Table column definitions — see field shape below. |

**Field shape:**

| Key | Type | Description |
|-----|------|-------------|
| `key` | string | Column name. |
| `title` | string | Column header label. |
| `sortable` | boolean | Whether the column header triggers server-side sort. |
| `data` | string | JS accessor path, e.g. `"category?.name"` for FK display. |
| `type` | `"text"` \| `"boolean"` | Renders a tick/cross icon for `boolean`. |
| `class` | string | Tailwind classes appended to the cell. |
| `defaultVisible` | boolean | Optional, default `true`. Set `false` to start the column hidden behind `ReportTable.vue`'s existing "View" column-visibility toggle — the user can still show it manually; this only sets the starting state. Never emitted for the primary/pinned column (`primaryField`), since fixed columns are always shown and the flag would be a no-op there. |

> **Actions column is automatic.** `BaseComponentGenerator::generateColumnsFromListFields()` always appends a trailing `{ key: "actions", label: "", width: 120, align: 'right' }` column to line up with the View/Edit/Delete buttons rendered by `list/page.stub`'s `<template #cell-actions>` slot. Do not add an `actions` entry to `fields` yourself — if one is present, the generator detects it and skips the auto-append rather than emitting a duplicate.

---

### `features.frontend.create`

Controls `{Module}CreatePage.vue` and `{Module}CreateFormComponent.vue`.

```json
"create": {
  "fields": [
    {
      "field":       "name",
      "label":       "Product Name",
      "placeholder": "Enter product name",
      "required":    true,
      "field_type":  "input",
      "type":        "text"
    },
    {
      "field":        "category_id",
      "label":        "Category",
      "placeholder":  "Select a category",
      "required":     true,
      "field_type":   "api-select",
      "type":         "text",
      "api_url":      "/select/categories",
      "option_label": "name",
      "option_value": "id",
      "per_page":     15,
      "multiple":     false
    },
    {
      "field":      "price",
      "label":      "Price",
      "required":   true,
      "field_type": "number-input",
      "type":       "number",
      "decimals":   2
    }
  ],
  "button_text": "Create Product",
  "sections":    [],
  "hiddens":     [],
  "defaults":    [],
  "drafts":      true
}
```

`drafts` (top-level, sibling to `fields`) defaults to `true` and wires the whole "Save as Draft"
autosave feature (`DraftListPanel`, `DraftRestoreBanner`) into the generated Create/Edit form. Set
`false` to opt a form out — verified against `CreateFormGenerator.php`/`EditFormGenerator.php`:
`$hasDrafts = ($createConfig['drafts'] ?? true) !== false;`.

**Field shape:**

| Key | Type | Description |
|-----|------|-------------|
| `field` | string | Column name. |
| `label` | string | Form label. |
| `placeholder` | string | Input placeholder text. |
| `required` | boolean | Whether the field is mandatory in UI. |
| `field_type` | string | See [Field Types](#field-types) below. |
| `type` | string | Data type hint: `"text"`, `"number"`, `"boolean"`, `"date"`. |
| `api_url` | string | (api-select only) Endpoint path for async options. |
| `option_label` | string | (api-select only) Key to display from response items. |
| `option_value` | string | (api-select only) Key to use as the submitted value. |
| `per_page` | number | (api-select only) Page size for the options list. |
| `multiple` | boolean | (api-select only) Allow multi-select. |
| `decimals` | number | (number-input only) Decimal places. |
| `splashKey` / `splash_key` | string | Names a `features.backend.{createSplash,editSplash}.splashData[].key` entry to resolve this field as an `ApiSelect2Field` pre-loaded on form mount. Conventionally the snake_case plural of the related module (e.g. `item_types`). Both casings are accepted on `actions[].fields` since v3.4.6; plain Create/Edit `fields[]` has always accepted `splashKey`. Not related to `constants` at all. |
| `hiddens` | array | Hidden fields preset with values: `[{ field, value }]`. |
| `defaults` | array | Default values pre-filled in the form: `[{ field, value }]`. |
| `accept`, `maxSize`, `maxFiles`, `preview`, `enableCrop`, `aspectRatio`, `cropShape`, `multiple`, `uploadMode`, `uploadUrl` | mixed | (`file-input` only) — see the Field Types table below. |
| `type_column`, `id_column`, `targets` | mixed | (`morph-select` only) — see the Field Types table below. |
| `inline_create` | boolean | (api-select/FK fields only) Set `false` to opt an individual field OUT of the default "Add New" affordance below. Rarely needed — see [Default "Add New" for FK select fields](#default-add-new-for-fk-select-fields). |
| `create_form_module` | string | (api-select/FK fields only) Explicit override for which module's `CreateForm.vue` the "Add New" button opens — trusted as-is, unverified, same precedence as `endpoint.path`/`endpoint.permission` overrides elsewhere. Only needed when auto-detection (below) can't or shouldn't apply. |

#### Field Types

| `field_type` | Description |
|---|---|
| `input` | Standard text input. |
| `textarea` | Multi-line text area. |
| `number-input` | Numeric input with optional decimal places. |
| `checkbox` | Boolean toggle/checkbox. |
| `date` | Date or datetime picker. |
| `api-select` | Async-loaded searchable dropdown (for FK fields), paired with `api_url`. |
| `select` + `splashKey`/`splash_key` | The other FK-picker route: resolves to the same `ApiSelect2Field` as `api-select`, but derives its endpoint from a `splashData` match (or, since v3.4.6, falls back to `Str::kebab()` of the `splashKey` itself when no `splashData` entry matches — the common case for `actions[].fields`). Functionally equivalent to `api-select`; neither is a workaround for the other. |
| `file-input` | File/image upload widget. Extra field-shape keys: `multiple` (bool), `accept` (string, e.g. `"image/*"`), `maxSize` (MB, default `5`), `maxFiles` (default `10`), `preview` (bool), `enableCrop` (bool), `aspectRatio` (default `0`), `cropShape` (default `"rect"`), `uploadMode` (default `"onSubmit"`), `uploadUrl`. Also requires the column to be listed in the module config's top-level `file_columns` — see [module-config.md](module-config.md) — or the backend applies the column's normal (non-file) validation rule to the upload and 422s. |
| `morph-select` | Type dropdown + API-backed record picker for a `morphs[]` relation. One field entry represents *both* the relation's `type_column` and `id_column`; extra field-shape keys: `type_column`, `id_column`, `targets` (copied wholesale from the relation's own `targets[]` — see [module-config.md § morphs](module-config.md#morphs-array)). |
| `select`, `color`, `password`, `time`, `item-picker`, `inline-items` | Also real, each with its own stub under `src/Generators/Templates/frontend/fields/` — not detailed here; inspect the stub or an existing generated field of that type for the exact shape. |

#### Default "Add New" for FK select fields

::: tip Since v2.30.0
Verified directly against `BaseComponentGenerator::resolveInlineCreateModule()`
and `IntrospectionToConfig::buildFrontendFormFields()`
(`src/Generators/Frontend/Components/BaseComponentGenerator.php`,
`src/Schema/IntrospectionToConfig.php`).
:::

An `api-select` field for a FK column now gets a small "＋" button next to the
dropdown by default — clicking it opens the related module's own
`CreateForm.vue` in a modal, so a user picking e.g. a Location doesn't have to
abandon the form they're on just to go create one first. Gated by
`hasPermission('{RelatedModule}.create')` — a user without that permission
never sees the button at all.

This is **auto-detected, never guessed**: the field must resolve to a real
`relatedModule` (from real FK/`foreign_table` introspection — hand-authored
`api-select` fields need an explicit `create_form_module` instead, see the
field-shape table above), it must not be self-referential (a FK column
pointing back at its own module, e.g. a `parent_id` hierarchy field, never
gets this — a "Create X" modal opening from inside X's own create form is
confusing, not helpful), and the target `{RelatedModule}CreateForm.vue` must
actually exist **on disk** — checked directly with `file_exists()`, not a
`module.json` feature flag (a module's own `features.frontend.create` config
key was confirmed, against a real consuming project, to drift out of sync
with whether a working `CreateForm.vue` actually exists — trusting it here
would make this default silently inert for exactly the modules most likely to
be a real target). A field that fails any of these checks silently stays a
plain dropdown — no error, no broken build.

Set `"inline_create": false` on a field to opt it out explicitly regardless of
what auto-detection would otherwise decide.

**Not retroactive** — only a module generated or regenerated against v2.30.0
or later picks up this default. Mobile-app forms are not covered — no
equivalent mechanism exists there today.

---

### `features.frontend.edit`

Same structure as `create`. `button_text` defaults to `"Update {Module}"`.

---

### `features.frontend.view`

Controls `{Module}DetailsOverviewPage.vue`.

```json
"view": {
  "titleData": "name",
  "idParam":   "uuid",
  "fields": [
    { "title": "Name",     "data": "name",           "type": "text",    "format": "text" },
    { "title": "Category", "data": "category?.name", "type": "text",    "format": "text" },
    { "title": "Active",   "data": "is_active",       "type": "boolean", "format": "text" },
    { "title": "Phone",    "data": "phone",           "type": "text",    "group": "Contact Info" },
    { "title": "Email",    "data": "email",           "type": "text",    "group": "Contact Info" }
  ],
  "badges": []
}
```

| Key | Type | Description |
|-----|------|-------------|
| `titleData` | string | Column name used as the page/breadcrumb title. |
| `idParam` | string | URL param for the record identifier. Default `"uuid"`. |
| `fields` | array | View field definitions. |
| `badges` | array | Badge field definitions (colored status chips). |

**Grouping fields into side-by-side columns (v2.50.0).** Add a `group` key (any string label) to
one or more entries in `fields[]` — every field sharing the same `group` value renders together in
its own labeled column, and the overview card becomes an N-column grid (one column per distinct
group, in order of first appearance). If **no** field in `fields[]` sets `group`, output is
unchanged from before this existed — the normal single stacked list in one card. The moment
**any** field sets `group`, the whole section becomes a grid: fields without a `group` key don't
get a separate flat card of their own — each becomes one more, unlabeled, column in that same grid,
positioned by where it first appears among all fields. In the example above (mixed — three fields
have no `group`, two share `"Contact Info"`), the result is a 2-column card: one unlabeled column
holding `Name`/`Category`/`Active` stacked, one labeled "Contact Info" column holding `Phone`/`Email`
stacked. Put every field you want in the flat list under the *same* `group` value if you want them
kept together and labeled, or leave `group` off every field to keep today's plain single-column
layout.

---

### `features.frontend.delete`

Controls `{Module}DeleteFormComponent.vue`.

```json
"delete": {
  "fields": [
    { "title": "Name",  "key": "name" },
    { "title": "Code",  "key": "code" }
  ]
}
```

Fields shown in the delete confirmation dialog so the user sees what they're deleting.

---

## `features.mobile_app`

See [mobile-config.md](mobile-config.md) for the full reference.

```json
"mobile_app": {
  "enabled": true,
  "mode":    "online",
  "icon":    "PackageIcon",
  "list": {
    "card": {
      "titleField":     "name",
      "subtitleFields": ["code"],
      "bodyFields":     ["description"],
      "footerBadge":    "status?.name"
    }
  }
}
```
