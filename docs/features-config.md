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
| `bulk_actions` | array | Bulk action keys available on the list page — a **flat array** (unlike top-level `actions`, which is keyed). Each entry: `{ key: string, status_target?: string, label?: string, icon?: string, requiresPermission?: string, confirmMessage?: string, variant?: string }`. With `status_target`, the generated service does a real `$model->update(['status_id' => {Module}Model::{STATUS_TARGET_CONST}])` — requires an integer `status_id` column and a matching key in the module's own `constants` map, not a plain string status column. Without `status_target`, it's an empty TODO stub you hand-fill, same as a no-UI action. See [the Actions Cookbook example](examples/actions) for a full worked example. |
| `import` | boolean | Generate import (CSV/Excel upload) endpoint and UI. Default `false`. |
| `export` | boolean | Generate export (CSV/Excel download) endpoint and UI. Default `false`. |
| `endpoint` | object | Route config — `{ method, path, permission }`. |

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
  "success_message": "Product deleted successfully.",
  "endpoint": {
    "method":     "DELETE",
    "path":       "/products/{uuid}",
    "permission": "Products.delete"
  }
}
```

---

### `features.backend.deleteCheck`

Controls `{Module}DeleteCheckService.php`. No configuration needed — the generator emits a stub that checks `foreign_key_graph` references.

```json
"deleteCheck": {}
```

---

### `features.backend.createSplash` / `features.backend.editSplash`

Splash variants render a secondary form section for modules that have `constants` defined. Omit these keys if no constants exist — the generator will skip them.

```json
"createSplash": {},
"editSplash": {}
```

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
  "defaults":    []
}
```

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
| `splashKey` | string | (splash fields only) Key of the constant set from `constants[]`. |
| `hiddens` | array | Hidden fields preset with values: `[{ field, value }]`. |
| `defaults` | array | Default values pre-filled in the form: `[{ field, value }]`. |

#### Field Types

| `field_type` | Description |
|---|---|
| `input` | Standard text input. |
| `textarea` | Multi-line text area. |
| `number-input` | Numeric input with optional decimal places. |
| `checkbox` | Boolean toggle/checkbox. |
| `date` | Date or datetime picker. |
| `api-select` | Async-loaded searchable dropdown (for FK fields). |

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
    { "title": "Active",   "data": "is_active",       "type": "boolean", "format": "text" }
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
