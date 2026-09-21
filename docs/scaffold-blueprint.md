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
  "module_types":      {},
  "morphs":            [],
  "foreign_key_graph": {},
  "delegations":       {},
  "inline_items":      {},
  "menu_config":       {},
  "seeders":           {},
  "actions":           {}
}
```

| Key | Type | Description |
|-----|------|-------------|
| `id_type` | string | Default ID type for all modules: `"uuid"` or `"bigint"`. Per-table overrides not supported in blueprints. |
| `groups` | object | Table-to-group mapping. Keys are group names; values are arrays of table names. |
| `module_types` | object | Workflow bookkeeping — module name → array of classification slugs (simple CRUD / inline items / delegation / morph / status machine, etc). Written by the scaffolding workflow that emits this blueprint; not read by `make:modules-from-db` itself. |
| `morphs` | array | Polymorphic relationship declarations. |
| `foreign_key_graph` | object | Reverse FK map: target table → sources. Used for topological sort and `DeleteCheckService`. |
| `delegations` | object | Delegation definitions, **keyed by StudlyCase parent module name**, each value an **array** of delegation configs for that module — see below, this has an extra nesting level compared to a single module's own `module.json`. |
| `inline_items` | object | Parent-embedded child rows, **keyed by StudlyCase parent module name**, each value an array (a parent can have more than one inline-items section) — see [module-config.md § inline_items](module-config.md). |
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
    "targets": [
      { "alias": "post", "model": "App\\Project\\Modules\\Custom\\Posts\\PostsModel", "module": "Posts", "label": "Post" }
    ]
  }
]
```

| Key | Type | Description |
|-----|------|-------------|
| `table` | string | DB table that owns the morph columns. |
| `name` | string | Base name of the morph (e.g. `"source"` → `source_type`, `source_id`). |
| `targets` | array | Optional. **Objects, not bare class-name strings**: each requires `alias`/`model`/`module`, `label` optional (falls back to `alias`), `option_label` optional (defaults to `name`) — identical shape to a single module's own `module.json` `morphs[].targets` (see [module-config.md § morphs](module-config.md)). Never auto-guessed by introspection; hand-author after Stage 1 if you want the frontend `morph-select` picker. |

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

### How a `*_id` column becomes a foreign key when the database declares none

A real `FOREIGN KEY` constraint always wins. Where there is none, introspection decides from the column's **name**, in this order, and stops at the first match:

1. **`fk_aliases.json`**: what you declared (below). It beats every guess.
2. `parent_id`: a self-reference.
3. `something_by_id` (not `created_by_id` / `updated_by_id`): the `users` table.
4. The `_id`-stripped word, plural or singular, is a table: `item_type_id` is `item_types`.
5. The same after dropping a leading qualifier word: `source_quotation_id` is `quotations`. The words are `source`, `target`, `default`, `primary`, `secondary`, `original`, `previous`, `next`, `current`, `related`, `linked`, `preferred`, `billing`, `shipping`, `from`, `to`, `new`, `old`. It is a fixed list on purpose: dropping any leading word would link a column to an unrelated table without saying so. A match found this way prints a warning naming what was inferred.

A compound noun cannot be guessed. `category_id` does not spell `item_categories`, and `unit_of_measure_id` pluralises on the wrong word (`units_of_measure`), so both used to be demoted to a plain integer on every full regenerate: no FK picker, no relation, and no failing test. Declare them in **`fk_aliases.json`**, in the backend root next to `artisan`:

```json
{
  "_comment": "Keys starting with an underscore are ignored.",
  "category_id": "item_categories",
  "unit_of_measure_id": "units_of_measure",
  "sales_orders.source_quotation_id": "quotations"
}
```

A key is a column name (any table) or `table.column` (that table only, which wins over the bare name). The file is read on every introspection, so the declaration survives every `--force` and every full blueprint regenerate. An alias whose target table does not exist is reported and ignored, never trusted.

---

## `delegations` Object

::: warning Corrected 2026-08-15
This section previously showed the blueprint's `delegations` key with the same shape as a single
module's own `module.json` `delegations` key (a map keyed by delegation key). That's the shape for
`module.json`, **not** for this top-level blueprint file. The real blueprint shape, verified against
`schema/scaffold-blueprint.schema.json` and the package's own annotated `examples/scaffold-blueprint.json`,
has one extra nesting level: **keyed by StudlyCase parent module name, each value an array** of
delegation configs for that module (a parent can delegate to more than one related module). Once
`make:modules-from-db` writes each module's own `module.json`, *that* file's `delegations` key is
then the flatter, delegation-key-keyed shape — see [delegations.md](delegations.md#array-shape) for
that (different, single-module-scoped) format and the reasoning behind it.
:::

Endpoint permissions default to the **related** module's own permission
(e.g. `SaleItems.edit`, not a delegation-specific one) — omit `permission`
entirely unless a delegation genuinely needs a different gate. See
[delegations.md](delegations.md#operations-object) for the full formula.

```json
"delegations": {
  "Sales": [
    {
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
  ]
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
