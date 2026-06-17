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
    { "key": "activate" },
    { "key": "deactivate" }
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
| `filterFields` | array | UI filter field definitions for the frontend filter panel. |
| `default_list_filters` | array | Hard-coded filters always applied to the query. Each entry: `{ column, operator, value }`. |
| `bulk_actions` | array | Bulk action keys available on the list page. Each entry: `{ key: string }`. |
| `import` | boolean | Generate import (CSV/Excel upload) endpoint and UI. Default `false`. |
| `export` | boolean | Generate export (CSV/Excel download) endpoint and UI. Default `false`. |
| `endpoint` | object | Route config — `{ method, path, permission }`. |

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
