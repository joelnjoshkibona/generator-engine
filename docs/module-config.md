# Module Config Reference

The module config is a PHP array (or JSON object) that fully describes a single module to be generated.
It is the primary input for all generator classes.

---

## Top-Level Keys

```json
{
  "id":                    "string (UUID v5 derived from module name)",
  "module_name":           "Products",
  "module_type":           "Custom",
  "table_name":            "products",
  "id_type":               "autoincrement | uuid | manual",
  "module_group_name":     "Core | Custom | null",
  "connection":            "mysql (optional, defaults to config('generator.default_connection'))",
  "version":               "1.0.0",
  "has_timestamps":        true,
  "has_soft_deletes":      false,
  "has_uuid":              true,
  "has_creator_updater":   true,
  "model_hand_maintained": false,
  "columns":               [],
  "indexes":               [],
  "unique_constraints":    [],
  "morphs":                [],
  "inline_items":          [],
  "file_columns":          [],
  "features":              {},
  "delegations":           {},
  "actions":               {},
  "processors":            [],
  "seeder":                { "data": [], "permissions": [] },
  "menu_config":           {},
  "constants":             {}
}
```

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `id` | string | No | UUID v5 identifier. Auto-set by introspection. |
| `module_name` | string | **Yes** | StudlyCase singular name, e.g. `Products`. |
| `module_type` | string | No | `"Custom"`, `"Core"`, or `"System"`. Affects namespace/path. |
| `table_name` | string | **Yes** | Exact DB table name, e.g. `products`. |
| `id_type` | string | Effectively yes | `"autoincrement"`, `"uuid"`, or `"manual"` — read with no `??` fallback by `MigrationGenerator`/`ModelGenerator`/`SeederGenerator`, so omitting it is a real error, not a default. Any other value falls through `MigrationGenerator`'s switch to a plain `$table->id()`. |
| `module_group_name` | string\|null | No | Sub-group label. Used in some menu groupings. |
| `connection` | string | No | DB connection name for the generated Model/migration. Defaults to `config('generator.default_connection')`. |
| `version` | string | No | Semantic version. Default `"1.0.0"`. |
| `has_timestamps` / `has_soft_deletes` / `has_uuid` / `has_creator_updater` | boolean | No | The single source of truth every generator defers to — see `ModuleConfigContract`. Defaults: `has_timestamps`/`has_uuid`/`has_creator_updater` → `true`, `has_soft_deletes` → `false` (soft deletes is opt-in, not assumed). |
| `model_hand_maintained` | boolean | No | Default `false`. When `true`, `ModelGenerator` writes the Model file once and never touches it again on regeneration (no `--force` escape hatch). |
| `columns` | array | **Yes** | Column definitions — see [columns.md](columns.md). |
| `indexes` | array | No | Plain (non-unique) and single-column-unique indexes. |
| `unique_constraints` | array | No | Composite (2+ column) unique constraints: `[{columns: string[], name?: string}]`. Renders through the same `$table->unique(...)` path as an `indexes` entry marked `unique: true` — don't declare the same column set in both, `MigrationGenerator` doesn't dedupe and will emit the DDL twice. |
| `morphs` | array | No | Polymorphic relationship declarations — see below. A genuine flat array (unlike `delegations`/`actions`) — auto-detected by introspection, rarely hand-authored. |
| `inline_items` | array | No | Parent-embedded child rows (e.g. Order → Order Items), rendered and saved inline on this module's own Create/Edit/View pages — no separate child page. See [Inline Items example](examples/inline-items). |
| `file_columns` | array of string | No | Column names that hold a file/media reference. Routes the column to a `file` validation rule instead of its DB-derived one, and to a media-upload `beforeCreate`/`beforeUpdate` hook, instead of being treated as a plain scalar. |
| `features` | object | **Yes** | Backend + frontend + mobile feature config — see [features-config.md](features-config.md). |
| `delegations` | object | No | Related-module tab/modal panels, **keyed by delegation key** — see [delegations.md](delegations.md). |
| `actions` | object | No | Custom action buttons and services, **keyed by action key** — see [actions.md](actions.md). |
| `processors` | array | No | Pipeline hooks (before/after save/delete) — see [processors.md](processors.md). |
| `seeder` | object | No | `{data: [...row objects...], permissions: [...]}` — **not** a flat array, see below. |
| `menu_config` | object\|null | No | Navigation placement — see below. |
| `constants` | object | No | Flat `{ CONST_NAME: value }` map — see below. |
| `relations` | object | No | Manual-relations escape hatch: `{ hasMany: [...], belongsToMany: [...], morphMany: [...] }`, each entry `{ module, method, ... }` — rendered onto this module's own generated Model by `ModelGenerator::generateManualInverseRelationships()`. `morphMany` (v3.4.0) is how a morph *target* module (e.g. `Vendors`, on the receiving end of a `payable` morph declared on `Payments`) gets a real `payments(): MorphMany` relation without hand-splicing the Model file — see [Polymorphic Relations](#morphs-array). Preserved across `--force` like `delegations`/`actions`/`constants`. |
| `skip_convention_check` | boolean | No | Default `false`. Opts this module out of `IntrospectionToConfig`'s audit-column naming-convention check (created_by/updated_by/etc. must match the project's documented convention, or introspection throws) — use only when a table genuinely can't follow the convention, not as a quick fix for a real mismatch. |

> **List filters.** `features.backend.list.filterFields` can be left empty —
> it auto-derives type-aware filters from `filterableFields`, and `id`/`uuid`/
> `created_at` are always added as default filters regardless of config. See
> [features-config.md § Filter fields](features-config.md#filter-fields-auto-derivation-and-default-filters)
> for the full behavior.

---

## `morphs` Array

Declare polymorphic relationships on this table. Auto-detected by schema introspection (a
`{prefix}_type`/`{prefix}_id` column pair) — `name`/`type_column`/`id_column` are populated for you;
only `targets` is ever hand-authored.

```json
"morphs": [
  {
    "name": "commentable",
    "type_column": "commentable_type",
    "id_column": "commentable_id",
    "targets": [
      { "alias": "post", "model": "App\\Project\\Modules\\Custom\\Posts\\PostsModel", "module": "Posts", "label": "Post" }
    ]
  }
]
```

The generator uses this to emit a `morphTo()` relationship method — always, whether or not `targets`
is set. The migration side is **not** unconditional: `MigrationGenerator` only collapses this
morph's `type_column`/`id_column` pair into `$table->morphs('commentable')` when those two names
*also* appear as regular entries in `columns[]` — add them there first, with the exact same names.
Declare `morphs[]` without matching `columns[]` entries and no `$table->morphs()` line (and no
columns for the pair at all) gets emitted.
`morphMany()`/`morphOne()` (the inverse, on the target side) is **not** emitted — that stays a
manual add if you want e.g. `$post->comments` to work.

`targets` (optional, never auto-guessed) drives two things once populated: a `morph-select`
create/edit field (type dropdown + API-backed record picker, replacing the fallback plain
text/number input pair) and a `Relation::morphMap()` registration on this module's own generated
`boot()` method. Each entry requires `alias`/`model`/`module`; `label` falls back to `alias` if
omitted, and `option_label` is optional (which field to show in the record picker, defaults to
`name`). The same `alias` registered for two
different `model` values across the whole project is a hard-fail at generation time — see
[the morphs example page](examples/morphs#with-targets-populated-v2-51-0-a-real-type-selector-record-picker)
for the full config shape and behavior.

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
| `section` | string | `"configurations"` for `module_type: Custom/Core`, `"main"` for `System` | ID of the nav section to place this module in — derived per `module_type` when omitted, not a single fixed default. |
| `section_label` | string | — | Optional override for the section heading text. |
| `icon` | string | name-heuristic, `"File"` last resort | Lucide icon name. When omitted, guessed from the module name against a ~40-entry word-stem table; `"File"` only when nothing matches. |
| `permission` | string | `"{Module}.list"` | Guard permission for this nav item. |
| `nested` | boolean | `false` | If `true`, renders a parent item with `List` and `Create` children. |
| `items` | array | — | Fully custom nav items. Overrides the auto-generated entry. |

---

## `constants` Object

::: warning Corrected 2026-08-02
`constants` is a **flat key → value map**, not an array of named groups.
This page previously showed `[{"name": "STATUS", "values": [...]}]` —
verified against `ModelGenerator::generateConstants()`'s actual source:
`foreach ($constants as $name => $value) { ... "public const {$name} = ...;" }`.
:::

Defines PHP `public const` values emitted directly on the generated model —
a flat map, any scalar value: numeric values are emitted unquoted
(`public const ACTIVE = 1;`), anything else is quoted as a PHP string
(`public const ACCEPTED = 'ACCEPTED';`) — works identically well for a
string workflow-status token as for a numeric seeded-row id.

```json
"constants": {
  "ACTIVE":   1,
  "INACTIVE": 2,
  "RECEIVED": 3
}
```

`constants` itself does **not** populate any dropdown or splash data — that's
a separate key, `splashData`, nested inside `createSplash`/`editSplash` (see
[features-config.md](features-config.md#features-backend-createsplash-features-backend-editsplash)).
`constants` has two independent consumers:

- `features.backend.list.bulk_actions[].status_target` (see
  [features-config.md](features-config.md)) references a constant here by
  name — but note the generated bulk-action body always writes to a
  hardcoded `status_id` column, so this only works when your table's status
  column is literally named `status_id`.
- A non-empty `constants` is **half** of the gate that registers and calls
  the Create/Edit splash pre-fetch route — see the warning under
  `createSplash`/`editSplash` above. `constants` alone, with no matching
  `createSplash`/`editSplash` key, does nothing beyond emitting the `const`
  — it does **not** trigger any network call, since v3.4.7.

---

## `seeder` Object

::: warning Corrected 2026-08-15
`seeder` is an **object** with `data`/`permissions` keys, not a flat array of row objects —
verified against `SeederGenerator.php`: `$this->seedData = $config['seeder']['data'] ?? [];`
and `$this->permissions = $this->mergeListPermissions($config['seeder']['permissions'] ?? [], ...)`.
:::

```json
"seeder": {
  "data": [
    { "name": "Electronics", "code": "ELEC", "color": "blue" },
    { "name": "Clothing",    "code": "CLO",  "color": "green" }
  ],
  "permissions": []
}
```

`data` rows are flat objects keyed by column name. `permissions` is an explicit list of extra
permission strings to seed beyond the standard CRUD set — `SeederGenerator` already seeds the base
`{Module}.list/create/edit/view/delete` permissions unconditionally via `Helpers::saveModuleCRUDPermissions()`,
so `permissions` here is for anything additional (e.g. a custom action's permission).

---

## `indexes` Array

Plain indexes and single-column unique indexes. For a **composite** (2+ column) unique constraint,
use the top-level `unique_constraints` key instead — see the [Top-Level Keys](#top-level-keys) table
above; declaring the same column set in both `indexes` and `unique_constraints` emits duplicate DDL.

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

A complete, annotated config covering every top-level key —
[`examples/module-config-full.json`](https://github.com/joelnjoshkibona/generator-engine/blob/main/examples/module-config-full.json)
— lives in this repository's `examples/` directory (not `docs/examples/`).
It is a static reference file, not something actually generated and
verified end-to-end — for that, see [Examples](examples/) instead, a set of
worked, task-oriented recipes each pointing at a real config that was
actually run through the generator and checked against its output.
