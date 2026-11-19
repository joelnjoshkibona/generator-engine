::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/creating-modules.md`.
:::

::: v-pre

# Creating Modules

## Overview

The `make:module` command is the primary scaffolding tool for SYSTEM_SHELL. Given a group and a module name, it introspects the target database table, resolves column types and foreign-key relationships, and then generates a complete, ready-to-run backend + frontend module: controller, model, services, migration, seeder, Vue pages, form components, locales, and route registrations — all in one pass.

Every generated file is based on templates that follow the SYSTEM_SHELL conventions, so the output is immediately wired into the authentication, permission, and activity-log systems without manual plumbing.

---

## Prerequisites

- **The database table must exist** before running the command. The generator reads the live schema to map columns to the correct input types, detect foreign keys, and determine whether the primary key is a `bigint` or `uuid`.
- If the table does not yet exist and you want stubs to get started, pass `--no-introspect` (see [Skipping introspection](#skipping-introspection) below).
- Group and module names must be **StudlyCase** (e.g. `Core`, `Books`, `ProductCategory`). Lowercase or snake_case names will be rejected.

::: tip Database connection
Make sure your `.env` points to the correct database before running `make:module`. The generator connects through the standard Laravel `DB` facade — there is no separate connection option.
:::

---

## Basic Usage

```bash
php artisan make:module [Group/][SubGroup/]ModuleName
```

### Minimal example

```bash
php artisan make:module Core/Books
```

This introspects the `books` table (snake_plural of `Books`), generates all backend and frontend files under the `Core` group, registers the module in `modules.json`, and writes its sidebar entry to `Seeders/MenuSeederData.json` (generator-engine v3.5.18+ — see Module Registration → Sidebar Navigation for what turns that into an actual visible menu item).

### Module path forms

The `path` argument accepts one, two, or three StudlyCase segments separated by `/`:

| Form | Segments | Example | Resolves to |
|---|---|---|---|
| `ModuleName` | 1 | `Books` | Group defaults to `System`; module `Books`. |
| `Group/ModuleName` | 2 | `Core/Books` | Group `Core`; module `Books`. |
| `Group/SubGroup/ModuleName` | 3 | `Core/Locations/LocationTypes` | Group `Core`, nested under sub-group `Locations`; module `LocationTypes`. Only `System` or `Core` are valid top-level groups for this 3-segment form. |

The 3-segment form is real and already in production use — `LocationTypes` (`BACKEND/app/Project/Modules/Core/Locations/LocationTypes/`) is generated this way. The sub-group is purely a directory-nesting level: the table name is still derived from the module segment alone (`Str::snake(Str::plural('LocationTypes'))` → `location_types`), never from the sub-group.

### Explicit table name

Use `--table` when the table name does not follow the default convention:

```bash
php artisan make:module Core/Books --table=library_books
```

### Overwriting an existing module

```bash
php artisan make:module Core/Books --force
```

::: warning Force re-generates all template files
`--force` overwrites every generated file **except** `module.json`. The config file is always merged, never replaced. See [Re-running and the module.json merge](#re-running-and-the-module-json-merge) for details.
:::

---

## CLI Options

| Option | Argument | Default | Description |
|---|---|---|---|
| `path` | `[Group/][SubGroup/]ModuleName` | _(required)_ | One, two, or three StudlyCase segments separated by `/`. See [Module path forms](#module-path-forms) above. |
| `--table` | `table_name` | snake_plural of module name | Override the database table the generator introspects. |
| `--force` | _(flag)_ | `false` | Overwrite existing generated files. `module.json` is always merged regardless. |
| `--no-introspect` | _(flag)_ | `false` | Skip DB introspection entirely and emit empty stubs. Use when the table does not exist yet. |
| `--emit-config` | `path/to/file.json` | _(unset)_ | Resolve and write the full config JSON to the given path, then exit without generating any files. Useful for inspection or offline editing before generation. |
| `--schema` | `path/to/file.json` | _(unset)_ | Load the resolved config from a JSON file instead of introspecting the database. Pair with `--emit-config` for an inspect-then-generate workflow. |
| `--connection` | `connection_name` | `config('generator.default_connection')` | Eloquent database connection for the generated model. Overrides the app default for this module only. |
| `--file-columns` | `col1,col2,...` | _(none)_ | Comma-separated column names to force as file/media-upload fields (`field_type=file-input`), e.g. `avatar,attachment`. |
| `--mobile` | _(flag)_ | `false` | Also scaffold a Mobile App backend counterpart (sets `features.mobile_app.enabled=true`). Only ever turns this **on** — omitting the flag on a later run, including `--force`, never turns it back off; once set, it persists via the `module.json` merge (see [Re-running and the module.json merge](#re-running-and-the-module-json-merge)). |
| `--layers` | `LIST` | — | Comma list of `backend` (required), `frontend`, `mobile`. `--layers=backend` makes the module backend-only — sets `features.frontend.enabled=false`, and no frontend is generated. Persists the same way `--mobile` does; never turns `mobile` off. Needs generator-engine v3.5.17+ (fails loudly on an older engine rather than silently generating the frontend anyway). |

::: tip Backend-only modules

Two things to know before using `--layers=backend`:

1. **Nothing is deleted when you switch an existing module to backend-only.** Frontend
   files and registry entries written before the switch stay on disk. Find them with:
   ```bash
   find FRONTEND/src/pages/modules/<group>/<Module> -type f
   grep -l <Module> FRONTEND/src/modules.json FRONTEND/src/api-contract.json BACKEND/app/Project/Modules/<group>/<Module>/Seeders/MenuSeederData.json
   ```
   then remove them by hand if you want the module fully gone from the frontend.
2. **Do not delegate to a backend-only module.** A `uiType: tab` delegation whose
   related module is backend-only will make `vite build` fail — the tab unconditionally
   imports that related module's `CreateForm.vue`/`EditForm.vue`/`ViewModal.vue`/
   `DeleteForm.vue`, none of which exist once that module stops generating a frontend.
:::

---

## What Gets Generated

Running `make:module Core/Books` produces the following file tree.

### Backend

All backend files live under:

```
BACKEND/app/Project/Modules/Core/Books/
```

```
Core/Books/
├── BooksController.php
├── BooksModel.php
├── BooksFactory.php
├── Routes/
│   └── api.php
├── Migrations/
│   └── {timestamp}_create_books_table.php
├── Seeders/
│   ├── BooksSeeder.php
│   └── BooksSeederData.json
├── Services/
│   ├── BooksListService.php
│   ├── BooksCreateService.php
│   ├── BooksCreateSplashService.php
│   ├── BooksEditService.php
│   ├── BooksEditSplashService.php
│   ├── BooksDeleteService.php
│   ├── BooksDeleteCheckService.php
│   ├── BooksViewService.php
│   └── BooksActivityListService.php
├── Tests/
│   ├── BooksTestCase.php
│   └── BooksCreateServiceTest.php
└── module.json
```

Each service handles one backend operation:

| Service | Responsibility |
|---|---|
| `ListService` | Paginated index query with filters |
| `CreateService` | Validation + insert |
| `CreateSplashService` | Data needed to render the create form (dropdowns, etc.) |
| `EditService` | Validation + update |
| `EditSplashService` | Data needed to render the edit form, pre-filled |
| `DeleteService` | Soft or hard delete |
| `DeleteCheckService` | Pre-delete dependency check (blocks delete if related records exist) |
| `ViewService` | Single-record fetch for the detail view |
| `ActivityListService` | Activity log entries for the detail history tab |

### Frontend

All frontend files live under:

```
FRONTEND/src/pages/modules/core/Books/
```

```
Books/
├── routes.ts
├── BooksListPage.vue
├── BooksCreatePage.vue
├── BooksEditPage.vue
├── BooksDeletePage.vue
├── BooksDetailsLayout.vue
├── BooksDetailsOverviewPage.vue
├── BooksDetailsHistoryPage.vue
├── Components/
│   ├── BooksCreateForm.vue
│   ├── BooksEditForm.vue
│   ├── BooksDeleteForm.vue
│   └── BooksViewModal.vue
├── e2e/
│   ├── books-crud.e2e.js
│   └── _fixtures.js
└── locales/
    ├── en.json
    └── sw.json
```

### Auto-registration

- **`FRONTEND/src/modules.json`** (root-level) — the module's route file is registered so Vue Router picks it up.
- **`{ModulePath}/Seeders/MenuSeederData.json`** (per-module, generator-engine v3.5.18+) — the
  module's own sidebar entry, keyed by `module_route`. See
  Module Registration → Sidebar Navigation — this
  is a declaration a project's own sync step turns into a real menu row, not the sidebar's live
  data source itself.

::: tip Locale files
`en.json` and `sw.json` are pre-populated with all label keys used by the generated components. Add translations for additional languages by creating a matching locale file alongside them.
:::

---

## DB Introspection

When `--no-introspect` is not set, the generator connects to the database and reads the target table through `SchemaIntrospector`. It extracts:

- **Column types and nullability** — used to choose the correct form input (text, number, date, select, toggle, etc.) and to set validation rules.
- **Default values** — applied to form defaults and migration column definitions.
- **Foreign keys** — detected by convention: any column ending in `_id` is assumed to be a foreign key. The generator looks for a matching table (e.g. `category_id` → `categories`) and renders a relationship select input automatically.
- **Polymorphic pairs** — columns named `{name}_type` + `{name}_id` (e.g. `typeable_type` + `typeable_id`) are detected as polymorphic relations. What the create/edit form renders depends on `morphs[].targets` in `module.json`: with `targets` **empty**, two plain inputs (a text field for the type column, where the developer types a raw fully-qualified class name, and a number field for the id); with `targets` **populated**, a real `morph-select` field — a type dropdown plus an API-backed record picker — and a generated `Relation::morphMap()` in the owning model's `boot()`, so the type column stores a short alias. `targets` is hand- or blueprint-authored and preserved across `--force` (see the table below). See [Polymorphic Relations (morphs)](/legacy/morphs) for the full reference.
- **Primary key type** — `id_type` is set to `bigint` or `uuid` based on the actual column type in the table.

::: warning Convention-based FK detection
Foreign key detection is convention-based, not constraint-based. If your schema uses explicit FK constraints but non-standard column names, the generator will miss them. Use `--emit-config` to inspect the resolved config and add any missing relationships manually before regenerating with `--schema`.
:::

### Skipping introspection

If the database table does not yet exist, pass `--no-introspect` to generate empty stubs:

```bash
php artisan make:module Core/Books --no-introspect
```

All files are created with placeholder types. Fill in the migration, then re-run the command (without `--no-introspect`) once the table exists — or edit `module.json` and re-run with `--schema`.

---

## Re-running and the `module.json` Merge

`module.json` is the persisted config for a module. It is written on first generation and **merged** (never overwritten) on every subsequent run, including `--force` runs. This makes it safe to re-run after adding new columns to the table.

The following keys in `module.json` are developer-owned and are always preserved:

| Key | Purpose |
|---|---|
| `delegations` | Child-list tabs or modals rendered inside the detail view — see [Delegations](/legacy/delegations) |
| `inline_items` | Parent-child rows edited inline in the parent's own form — see [Parent-Child Inline Items](/legacy/inline-items) |
| `actions` | Custom state-transition action buttons — see [Custom Actions](/legacy/actions) |
| `features.backend.list.bulk_actions` | List-page bulk action buttons — a separate, nested key from `actions`, not part of it — see [Custom Actions — Bulk actions](/legacy/actions#bulk-actions-—-a-different-nested-config-location) |
| `features.backend.list.export` | List-page export button |
| `features.backend.list.import` | List-page import button |
| `processors` | Before/after save/delete pipeline hooks |
| `morphs[].targets` | Concrete model targets (`{alias, model, module}`) for each polymorphic relation — populating it generates a real morph-select picker and a `Relation::morphMap()`; see [Polymorphic Relations (morphs)](/legacy/morphs) |
| `relations` | Hand-authored `morphMany`/`hasMany`/`belongsToMany` relations introspection cannot derive — most commonly the reverse `morphMany` on a morph target; see [Polymorphic Relations (morphs)](/legacy/morphs#reverse-morph-relations-relations-morphmany) |
| `constants` | Module-level constants emitted as `public const` on the generated **model** (not the services); also the gate that decides whether the create/edit splash endpoints are generated at all |
| `model_hand_maintained` | Opt a module's `{Module}Model.php` out of regeneration entirely, protecting hand-written model code from being overwritten on `--force` |
| `menu_config` | Override the auto-generated menu entry (icon, label, order, visibility) |
| `features.mobile_app.mode` | Mobile sync mode (`online` \| `offline` \| `both`) |
| `features.mobile_app.enabled` | Whether the Mobile App backend counterpart is scaffolded — set once via `--mobile` (see [CLI Options](#cli-options)) and preserved on every later run that doesn't re-pass the flag |
| `features.frontend.enabled` | Whether this module generates a frontend at all — set once via `--layers=backend` (see [CLI Options](#cli-options)) and preserved on every later run that doesn't re-pass `--layers` |

::: warning Corrected 2026-08-03
This page previously documented `delegations` and `actions` as flat JSON
arrays with field names (`key`/`module`/`foreign_key`/`display`) that don't
match either the real generator contract or how [Delegations](/legacy/delegations)
documents them. Both are **maps keyed by name**. The example below is
corrected; see [Delegations](/legacy/delegations) for the full, accurate
field reference.
:::

Example `module.json` after developer customisation:

```json
{
  "name": "Books",
  "group": "Core",
  "table": "books",
  "id_type": "bigint",
  "delegations": {
    "chapters": {
      "name": "Chapters",
      "label": "Chapters",
      "uiType": "tab",
      "relatedModule": { "name": "Chapters", "group": "Core" },
      "filterKey": "book_id",
      "operations": {
        "list": { "enabled": true }
      }
    }
  },
  "actions": {
    "publish": {
      "name": "publish",
      "label": "Publish",
      "hasUI": false,
      "urlParams": ["uuid"],
      "operations": {
        "view": { "enabled": true, "endpoint": { "method": "POST", "path": "/books/{uuid}/publish" } }
      }
    }
  },
  "morphs": [],
  "constants": {},
  "menu_config": {
    "icon": "book-open",
    "order": 2
  }
}
```

To apply config changes to regenerated files, edit `module.json` then re-run:

```bash
php artisan make:module Core/Books --force
```

---

## Advanced: `--emit-config` and `--schema`

These two options form an inspect-then-generate workflow that is useful when you want to review or tweak the resolved config before any files are written.

### Step 1 — emit the config

```bash
php artisan make:module Core/Books --emit-config=tmp/books-config.json
```

The command introspects the database, resolves the full config, writes it to `tmp/books-config.json`, and exits. No files are generated.

### Step 2 — review and edit

Open `tmp/books-config.json`, adjust field types, add relationships the introspector missed, or configure polymorphic targets.

### Step 3 — generate from the edited config

```bash
php artisan make:module Core/Books --schema=tmp/books-config.json
```

The command skips introspection entirely and generates all files from the JSON file you provide. Combine with `--force` if the module already exists:

```bash
php artisan make:module Core/Books --schema=tmp/books-config.json --force
```

::: tip When to use this workflow
This workflow is recommended for tables with complex schemas — many nullable columns, ambiguous FK names, or polymorphic relationships — where the introspector's automatic resolution may need correction.
:::

---

## Bulk Generation

When scaffolding an entire application from an existing database, use `make:modules-from-db`. It runs in two stages to give you a chance to review group assignments before any files are written.

### Stage 1 — emit a blueprint

```bash
php artisan make:modules-from-db --emit-blueprint=blueprint.json
```

The command introspects all non-framework tables (framework tables such as `migrations`, `jobs`, `cache`, `sessions`, and `telescope_*` are excluded automatically), proposes a group for each table, and writes the result to `blueprint.json`.

Open `blueprint.json` and adjust the group assignments and any other settings before proceeding.

The blueprint is one document describing the whole database, **not** a list of per-table objects. Its top-level keys are `connection`, `id_type`, `groups` (a map of group name → array of table names, where the `""` key lists tables to skip), `foreign_key_graph`, `morphs`, `delegations`, `menu_config`, `seeders`, and `actions` — plus an optional `inline_items` that Stage 2 also reads. Tables move between groups by moving their names between the `groups` arrays; module names are derived from the table names, not written per entry.

See [Bulk generation → Approach 3](/legacy/bulk-generation#approach-3) for the full walkthrough of what each key does and how blueprint values interact with an existing `module.json`.

### Stage 2 — generate from the blueprint

```bash
php artisan make:modules-from-db --blueprint=blueprint.json
```

Modules are generated in **FK-topological order**: if `Books` has a foreign key to `Categories`, then `Categories` is generated first. This ensures migration files can run in the correct order without manual reordering.

Add `--force` to overwrite any modules that already exist:

```bash
php artisan make:modules-from-db --blueprint=blueprint.json --force
```

::: warning Review the blueprint before Stage 2
Once Stage 2 runs, reordering or regrouping modules requires either a `--force` re-run or manual file moves. Invest time in the blueprint review — it is cheaper than fixing generated paths later.
:::

---

## Naming Conventions

| Concept | Convention | Example input | Resolved value |
|---|---|---|---|
| Group name | StudlyCase | `core` | Rejected — use `Core` |
| Module name | StudlyCase | `productCategory` | Rejected — use `ProductCategory` |
| Default table name | snake_plural of module name | `Books` | `books` |
| Default table name | snake_plural of module name | `ProductCategory` | `product_categories` |
| Backend directory | `Modules/{Group}/{Name}/` | `Core/Books` | `Modules/Core/Books/` |
| Frontend directory | `pages/modules/{group}/{Name}/` | `Core/Books` | `pages/modules/core/Books/` |
| Permission keys | `{Name}.{action}` | `Books` | `Books.list`, `Books.view`, `Books.create`, `Books.edit`, `Books.delete`, `Books.restore`, `Books.bulkAction` |

Permission keys are registered automatically on first generation. They appear in the permissions management UI without any manual seed steps.

---

## Next Steps

After a module has been generated, do this first:

::: warning Check the FK-picker convention before anything else
**If the new table has no literal `name` column**, add a
`format{Module}($search)` override to
`BACKEND/app/Project/_Src/SelectTrait.php` aliasing its real identifying
column(s) `as name`. `SelectController`'s generic `/select/{module}`
endpoint hard-500s otherwise — and **every** FK-picker in the app that
targets this module goes through that single endpoint: a Create/Edit
`select` + `splash_key` field, an `actions[].fields` picker, an
`inline_items[].fields` picker, and the morph-select record picker.

This applies doubly to a module scaffolded as the *target* of someone
else's FK — nothing in that other module's generation will tell you the
picker is broken; it only surfaces as a 500 the first time a user opens the
dropdown.
:::

Then:

- **[Module structure](/legacy/module-structure)** — detailed walkthrough of each generated file and how to extend it.
- **Permissions** — how auto-registered permission keys map to policy gates and how to add custom permissions.
- **[Delegations and child modules](/legacy/delegations)** — configure `module.json` delegations to embed child-list tabs inside a detail page.
- **[Polymorphic Relations (morphs)](/legacy/morphs)** — how morph columns are detected; the plain text/number inputs they render as with empty `targets` versus the real morph-select picker and generated `Relation::morphMap()` once `morphs[].targets` is populated, plus `relations.morphMany` for the reverse relation.

:::
