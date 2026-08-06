# Scaffold Blueprint Reference

The scaffold blueprint is a JSON file that drives bulk module generation from a database schema. It is consumed by:

- `php artisan make:modules-from-db --blueprint=blueprint.json` (SYSTEM_SHELL/BACKEND — full stack)
- `php artisan make:mobile-modules --blueprint=blueprint.json` (SYSTEM_SHELL/BACKEND — mobile backend only)
- `php artisan make:mobile-scaffold --blueprint=blueprint.json` (MOBILE_APP — full mobile stack)

---

## Two-Stage Workflow

**Stage 1 — Emit blueprint** (introspects your live DB):
```bash
php artisan make:modules-from-db --emit-blueprint=blueprint.json
```

**Stage 2 — Generate from blueprint** (writes code files):
```bash
php artisan make:modules-from-db --blueprint=blueprint.json
```

You can edit the emitted JSON between stages to customize per-module config before generating.

---

## Top-Level Structure

```json
{
  "id_type":           "bigint",
  "groups":            {},
  "morphs":            [],
  "foreign_key_graph": {},
  "delegations":       {},
  "menu_config":       {},
  "seeders":           {},
  "actions":           {}
}
```

| Key | Type | Description |
|-----|------|-------------|
| `id_type` | string | Default ID type for all modules: `"uuid"` or `"bigint"`. Per-table overrides not supported in blueprints. |
| `groups` | object | Table-to-group mapping. Keys are group names; values are arrays of table names. |
| `morphs` | array | Polymorphic relationship declarations. |
| `foreign_key_graph` | object | Reverse FK map: target table → sources. Used for topological sort and `DeleteCheckService`. |
| `delegations` | object | Per-module delegation definitions. |
| `menu_config` | object | Global menu section config + per-module overrides. |
| `seeders` | object | Per-module seed rows. |
| `actions` | object | Per-module custom action definitions. |

---

## `groups` Object

Maps each group name to the list of DB table names assigned to it.

```json
"groups": {
  "Custom": ["products", "categories", "sales", "sale_items"],
  "Core":   ["users", "roles", "permissions", "statuses"],
  "":       ["migrations", "password_reset_tokens", "personal_access_tokens"]
}
```

- Tables in the empty-string group (`""`) are **skipped** — they are framework/system tables.
- Group names become the PHP namespace segment: `App\Project\Modules\Custom\Products`.
- Tables are generated in topological order (dependencies before dependants).

---

## `morphs` Array

```json
"morphs": [
  {
    "table": "notifications",
    "name":  "source",
    "targets": []
  }
]
```

| Key | Type | Description |
|-----|------|-------------|
| `table` | string | DB table that owns the morph columns. |
| `name` | string | Base name of the morph (e.g. `"source"` → `source_type`, `source_id`). |
| `targets` | array | Optional list of morph target model class names. |

---

## `foreign_key_graph` Object

Reverse map of FK relationships. Keys are **target** tables; values are arrays of source references.

```json
"foreign_key_graph": {
  "categories": [
    { "source_table": "products",   "source_column": "category_id" },
    { "source_table": "promotions", "source_column": "category_id" }
  ],
  "users": [
    { "source_table": "sales", "source_column": "user_id" }
  ]
}
```

This powers:
- **Topological sort** — generates `categories` before `products`
- **DeleteCheckService** — warns before deleting a category that has products

---

## `delegations` Object

::: warning
`delegations` is a **map keyed by delegation key** (conventionally camelCase
of `name`), not an array of configs per module name — see
[delegations.md](delegations.md#array-shape) for the confirmed reasoning (a
flat array decodes to integer PHP keys, breaking `ModuleScaffolder`'s
`foreach ($config['delegations'] as $delegationKey => $delegation)` loop).
:::

Endpoint permissions default to the **related** module's own permission
(e.g. `SaleItems.edit`, not a delegation-specific one) — omit `permission`
entirely unless a delegation genuinely needs a different gate. See
[delegations.md](delegations.md#operations-object) for the full formula.

```json
"delegations": {
  "saleItems": {
    "name":    "SaleItems",
    "label":   "Items",
    "uiType":  "tab",
    "relatedModule": { "name": "SaleItems", "group": "Custom" },
    "parentKey":   "uuid",
    "filterKey":   "sale_id",
    "operations": {
      "list":   { "enabled": true,  "endpoint": { "method": "GET",    "path": "/sale-items" } },
      "create": { "enabled": true,  "endpoint": { "method": "POST",   "path": "/sale-items" } },
      "edit":   { "enabled": true,  "endpoint": { "method": "PUT",    "path": "/sale-items/{uuid}" } },
      "delete": { "enabled": true,  "endpoint": { "method": "DELETE", "path": "/sale-items/{uuid}" } },
      "view":   { "enabled": false }
    }
  }
}
```

---

## `menu_config` Object

### Sections

Defines the top-level navigation sections (sidebars/groups).

```json
"menu_config": {
  "sections": {
    "main": {
      "label": "Main",
      "icon":  "LayoutDashboard",
      "order": 1
    },
    "configurations": {
      "label": "Configurations",
      "icon":  "Settings",
      "order": 10
    }
  },
  "modules": {
    "Products": {
      "hidden":  false,
      "label":   "Product Catalog",
      "icon":    "Package",
      "section": "main",
      "order":   3
    },
    "Statuses": {
      "hidden":  false,
      "section": "configurations"
    }
  }
}
```

**`sections` entry:**

| Key | Type | Description |
|-----|------|-------------|
| `label` | string | Section heading text. |
| `icon` | string | Lucide icon name for the section. |
| `order` | number | Sort order among sections. |

**`modules` entry (per module override):**

| Key | Type | Description |
|-----|------|-------------|
| `hidden` | boolean | Set `true` to exclude from navigation entirely. |
| `label` | string | Override the auto-generated menu label. |
| `icon` | string | Override the auto-generated icon. |
| `section` | string | Which section to place this module in. |
| `order` | number | Sort order within the section. |

---

## `seeders` Object

Keys are StudlyCase module names; values are arrays of row objects.

```json
"seeders": {
  "Statuses": [
    { "name": "Active",   "code": "active",   "color": "green" },
    { "name": "Inactive", "code": "inactive", "color": "gray"  }
  ],
  "Roles": [
    { "name": "Admin" },
    { "name": "User"  }
  ]
}
```

Each row is a flat object whose keys match the column names of the target table.

---

## `actions` Object

Keys are StudlyCase module names; values are arrays of action configs (see [actions.md](actions.md)).

```json
"actions": {
  "Invoices": [
    {
      "name":      "markPaid",
      "label":     "Mark as Paid",
      "hasUI":     false,
      "urlParams": ["uuid"],
      "operations": {
        "view": { "enabled": true, "endpoint": { "method": "POST", "path": "/invoices/{uuid}/mark-paid", "permission": "Invoices.markPaid" } }
      }
    }
  ]
}
```

---

## CLI Flags

### `make:modules-from-db`

| Flag | Description |
|------|-------------|
| `--emit-blueprint=file.json` | Introspect the DB and write a blueprint (Stage 1). |
| `--blueprint=file.json` | Read blueprint and generate code (Stage 2). |
| `--force` | Overwrite existing files. |
| `--only=TableName` | Generate only the specified table. |
| `--group=GroupName` | Generate only tables in the specified group. |

### `make:mobile-modules`

| Flag | Description |
|------|-------------|
| `--blueprint=file.json` | Blueprint JSON. Generated mobile backend only (no SHELL backend, no frontend). |
| `--force` | Overwrite existing files. |
| `--only=TableName` | Generate only the specified table. |

### `make:mobile-scaffold`

Run from inside `MOBILE_APP`. Introspects the local SQLite directly.

| Flag | Description |
|------|-------------|
| `--blueprint=file.json` | Optional — provide a blueprint instead of live introspection. |
| `--force` | Overwrite existing files. |
| `--only=TableName` | Generate only the specified table. |

---

## Complete Minimal Blueprint

```json
{
  "id_type": "uuid",
  "groups": {
    "Custom": ["products", "categories"],
    "":       ["migrations", "password_reset_tokens", "personal_access_tokens"]
  },
  "morphs": [],
  "foreign_key_graph": {
    "categories": [
      { "source_table": "products", "source_column": "category_id" }
    ]
  },
  "delegations":  {},
  "menu_config":  {},
  "seeders":      {},
  "actions":      {}
}
```

See [examples/scaffold-blueprint.json](../examples/scaffold-blueprint.json) for a full annotated example.
