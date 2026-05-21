# Module Config Reference

The module config is a PHP array (or JSON object) that fully describes a single module to be generated.
It is the primary input for all generator classes.

---

## Top-Level Keys

```json
{
  "id":                "string (UUID v5 derived from module name)",
  "module_name":       "Products",
  "module_type":       "Custom",
  "table_name":        "products",
  "id_type":           "uuid | bigint",
  "module_group_name": "Core | Custom | null",
  "version":           "1.0.0",
  "columns":           [],
  "indexes":           [],
  "morphs":            [],
  "features":          {},
  "delegations":       [],
  "actions":           [],
  "processors":        [],
  "seeder":            [],
  "menu_config":       {},
  "constants":         []
}
```

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `id` | string | No | UUID v5 identifier. Auto-set by introspection. |
| `module_name` | string | **Yes** | StudlyCase singular name, e.g. `Products`. |
| `module_type` | string | No | `"Custom"` or `"Core"`. Affects namespace/path. Default `"Custom"`. |
| `table_name` | string | **Yes** | Exact DB table name, e.g. `products`. |
| `id_type` | string | No | `"uuid"` (default) or `"bigint"`. Affects model and migration. |
| `module_group_name` | string\|null | No | Sub-group label. Used in some menu groupings. |
| `version` | string | No | Semantic version. Default `"1.0.0"`. |
| `columns` | array | **Yes** | Column definitions — see [columns.md](columns.md). |
| `indexes` | array | No | Additional composite indexes beyond single-column ones. |
| `morphs` | array | No | Polymorphic relationship declarations — see below. |
| `features` | object | **Yes** | Backend + frontend + mobile feature config — see [features-config.md](features-config.md). |
| `delegations` | array | No | Related-module tab/modal panels — see [delegations.md](delegations.md). |
| `actions` | array | No | Custom action buttons and services — see [actions.md](actions.md). |
| `processors` | array | No | Pipeline hooks (before/after save/delete) — see [processors.md](processors.md). |
| `seeder` | array | No | Seed rows. Each entry is a flat object matching column names. |
| `menu_config` | object\|null | No | Navigation placement — see below. |
| `constants` | array | No | Named constant values used in splash/enum fields. |

---

## `morphs` Array

Declare polymorphic relationships on this table.

```json
"morphs": [
  {
    "name": "commentable",
    "type_column": "commentable_type",
    "id_column": "commentable_id"
  }
]
```

The generator uses this to emit `morphTo()` / `morphMany()` relationship methods and correct migration lines.

---

## `menu_config` Object

Controls where this module appears in the navigation sidebar.

```json
"menu_config": {
  "enabled":       true,
  "section":       "main",
  "section_label": "Main Menu",
  "icon":          "Package",
  "permission":    "Products.list",
  "nested":        false,
  "items": [
    {
      "title":      "All Products",
      "url":        "/products/list",
      "icon":       "List",
      "permission": "Products.list",
      "children":   []
    }
  ]
}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | boolean | `true` | Set `false` to hide from nav entirely. |
| `section` | string | `"main"` | ID of the nav section to place this module in. |
| `section_label` | string | — | Optional override for the section heading text. |
| `icon` | string | `"File"` | Lucide icon name. |
| `permission` | string | `"{Module}.list"` | Guard permission for this nav item. |
| `nested` | boolean | `false` | If `true`, renders a parent item with `List` and `Create` children. |
| `items` | array | — | Fully custom nav items. Overrides the auto-generated entry. |

---

## `constants` Array

Defines named constant sets used by `createSplash` and `editSplash` features (field-level splash dropdowns).

```json
"constants": [
  {
    "name": "STATUS",
    "values": [
      { "label": "Active",   "value": "active" },
      { "label": "Inactive", "value": "inactive" }
    ]
  }
]
```

---

## `seeder` Array

Simple array of row objects to seed the table. Keys must match column names.

```json
"seeder": [
  { "name": "Electronics", "code": "ELEC", "color": "blue" },
  { "name": "Clothing",    "code": "CLO",  "color": "green" }
]
```

---

## `indexes` Array

Additional indexes beyond those auto-created from `unique: true` on columns.

```json
"indexes": [
  { "columns": ["category_id", "status_id"], "unique": false }
]
```

---

## Complete Minimal Example

```json
{
  "module_name":    "Products",
  "module_type":    "Custom",
  "table_name":     "products",
  "id_type":        "uuid",
  "columns": [
    {
      "name": "name",
      "type": "string",
      "nullable": false,
      "unique": false,
      "featureSelections": {
        "backend":  { "create": true, "list": true, "view": true, "edit": true, "delete": false },
        "frontend": { "create": true, "list": true, "view": true, "edit": true, "delete": false }
      }
    }
  ],
  "features": {
    "backend": {
      "list":   { "endpoint": { "method": "GET",    "path": "/products",       "permission": "Products.list"   } },
      "create": { "endpoint": { "method": "POST",   "path": "/products",       "permission": "Products.create" } },
      "view":   { "endpoint": { "method": "GET",    "path": "/products/{uuid}", "permission": "Products.view"   } },
      "edit":   { "endpoint": { "method": "PUT",    "path": "/products/{uuid}", "permission": "Products.edit"   } },
      "delete": { "endpoint": { "method": "DELETE", "path": "/products/{uuid}", "permission": "Products.delete" } }
    },
    "frontend": {
      "list":   { "primaryField": "name", "fields": [] },
      "create": { "fields": [] },
      "view":   { "titleData": "name", "fields": [] },
      "edit":   { "fields": [] },
      "delete": { "fields": [] }
    },
    "mobile_app": { "enabled": false }
  },
  "menu_config": { "section": "main", "icon": "Package" }
}
```

See [examples/module-config-full.json](../examples/module-config-full.json) for a complete annotated example.
