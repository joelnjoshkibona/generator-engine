::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/bulk-generation.md`.
:::

::: v-pre

# Bulk Module Generation

## Overview

SYSTEM_SHELL supports three approaches to generating multiple modules. Which one you reach for depends on where you are in the project lifecycle.

| Approach | When to use |
|---|---|
| **PROJECT_GENERATOR_SYSTEMv1 web interface** | Greenfield projects. Design all modules visually, then generate everything in one queue run. |
| **`make:module` shell loop or scripted re-run** | Ongoing development. Adding a handful of new modules to an existing project, or force-regenerating modules after a config change. |
| **`make:modules-from-db`** | Adopting an existing, already-designed database — a legacy schema, or one hand-built ahead of time — where every table should become a module in one pass, rather than being configured table-by-table. |

Both approaches converge on the same output: `module.json` files on disk plus fully generated backend and frontend code in your SYSTEM_SHELL deployment directory.

::: tip Two systems, one contract
V1 and `make:module` share the same generation engine. V1 writes `module.json` via its API; `make:module` writes it from the CLI. Either way, `module.json` is the single source of truth for what gets generated.
:::

---

## Approach 1: PROJECT_GENERATOR_SYSTEMv1 Web Interface

V1 is a standalone web application that manages **Projects** (representations of SYSTEM_SHELL deployments) and their **Modules**. It provides a visual designer for configuring every module — columns, features, relationships, delegations — and then generates them all via a background queue.

This is the recommended starting point for any greenfield build.

### Generation flow

1. **Create a Project in V1** — a Project maps to one target SYSTEM_SHELL deployment directory.
2. **Add Modules to the project** — one module per database table or feature area.
3. **Configure each module** — set columns, types, feature flags, foreign-key relationships, and delegations using the visual editor.
4. **Click "Generate Project"** — V1 dispatches a `GenerateProjectJob`, which fans out into one `GenerateModuleJob` per module.
5. **Monitor progress** — the queue monitor page shows live status for every job as they process.
6. **Inspect the output** — generated files are written directly to the target SYSTEM_SHELL directory on disk.

### GenerationQueueService

`GenerationQueueService` is the V1 service that creates queue records and dispatches jobs. The two methods you are most likely to encounter when debugging or extending V1:

```php
// Queue a single module for generation
public function queueModuleGeneration(Module $module, int $priority = 0): GenerationQueue
{
    $queueItem = GenerationQueue::create([
        'project_id'  => $module->project_id,
        'module_id'   => $module->id,
        'job_type'    => 'module',
        'status'      => 'pending',
        'priority'    => $priority,
        'payload'     => [
            'module_uuid'   => $module->uuid,
            'configuration' => $module->configuration,
        ],
    ]);
    GenerateModuleJob::dispatch($queueItem);
    return $queueItem;
}

// Queue all modules in a project (dispatches a project-level job that fans out)
public function queueProjectBuild(Project $project, int $priority = 0): GenerationQueue
{
    $queueItem = GenerationQueue::create([
        'project_id' => $project->id,
        'job_type'   => 'project',
        'status'     => 'pending',
        'priority'   => $priority,
        'payload'    => ['project_uuid' => $project->uuid],
    ]);
    GenerateProjectJob::dispatch($queueItem);
    return $queueItem;
}
```

### GenerationQueue table

Each queue record tracks one generation job from creation to completion.

| Field | Type | Description |
|---|---|---|
| `project_id` | `bigint` | The V1 project this job belongs to. |
| `module_id` | `bigint` \| `null` | Set for `module`-type jobs; `null` for `project`-type jobs. |
| `job_type` | `'module'` \| `'project'` | Whether this record tracks a single-module or full-project generation. |
| `status` | `string` | Current job state — see lifecycle below. |
| `priority` | `integer` | Higher numbers are processed first by the queue worker. |
| `payload` | `JSON` | Module UUID and configuration snapshot passed to the job. |
| `started_at` | `timestamp` \| `null` | Set when the job begins processing. |
| `completed_at` | `timestamp` \| `null` | Set when the job finishes (success or failure). |
| `error_message` | `text` \| `null` | Populated on failure. Displayed in the queue monitor. |

### Job status lifecycle

```
pending → processing → completed
                    ↘ failed
```

A job enters `pending` when dispatched, transitions to `processing` when the queue worker picks it up, and ends in either `completed` or `failed`. Failed jobs retain their `error_message` for display in the queue monitor.

### Queue monitor

V1's frontend has a real-time queue monitor at `/generator/queue`. It shows:

- Aggregate counts: **Pending / Processing / Completed / Failed**
- Per-module job rows with `started_at`, `completed_at`, and duration
- `error_message` inline for failed jobs
- A **Retry Failed** button per job

::: warning Queue worker must be running
The generation queue uses Laravel's standard queue driver. Nothing will process unless the queue worker is active in the V1 backend:

```bash
# Run from V1's BACKEND directory
php artisan queue:work --queue=generation
```

If jobs sit in `pending` indefinitely, the worker is not running.
:::

---

## Approach 2: `make:module` — CLI and Shell Loops

SYSTEM_SHELL's `make:module` command generates one module per invocation. For bulk scenarios, compose it with standard shell constructs.

### Shell loop over a list

The simplest approach when you know the module names in advance:

```bash
for module in Products Categories Suppliers; do
    php artisan make:module Core/$module --force
done
```

Each invocation introspects the corresponding database table independently, so the tables must exist before the loop runs.

::: tip Group prefix
Adjust the group prefix (`Core/`) to match where your modules belong. You can mix groups in the same loop:

```bash
php artisan make:module Core/Products --force
php artisan make:module Inventory/Warehouses --force
php artisan make:module Reports/StockSummary --force
```
:::

### Regenerate all existing modules

To force-regenerate every module in a SYSTEM_SHELL deployment after a template update or engine version bump, find all `module.json` files and re-run `make:module` for each:

```bash
find BACKEND/app/Project/Modules -name "module.json" | while read f; do
    dir=$(dirname "$f")
    path=${dir#BACKEND/app/Project/Modules/}
    php artisan make:module "$path" --force
done
```

This reconstructs the full `Group[/SubGroup]/ModuleName` path by stripping the
`BACKEND/app/Project/Modules/` prefix from the module's directory, so it works
correctly for both 2-segment modules (`Core/Products`) and 3-segment
sub-grouped modules (`Core/Locations/LocationTypes`). Taking only the
immediate parent directory name (e.g. via a second `basename`) drops the
top-level group for any sub-grouped module and passes `make:module` a
2-segment path it will happily accept as a brand-new top-level group —
creating a wrongly-placed duplicate instead of regenerating the real module.

This is safe to run repeatedly — `module.json` is merged, never replaced, so your customised delegations, `inline_items`, actions, bulk actions, and menu config are always preserved.

::: warning Run from the SYSTEM_SHELL BACKEND root
`make:module` resolves paths relative to the Laravel project root. Run commands from inside the `BACKEND/` directory, or use absolute paths.
:::

### Generate from a prepared `module.json`

If you have a `module.json` already configured — either exported from V1 or hand-written — you can drive generation from it directly without database introspection.

1. Place `module.json` in the module's backend directory:
   ```
   BACKEND/app/Project/Modules/Core/Products/module.json
   ```
2. Re-run `make:module` with `--force` to generate all files from the existing config:
   ```bash
   php artisan make:module Core/Products --force
   ```
   The command detects the existing `module.json`, merges it with any schema it resolves, and generates all backend and frontend files.

For full offline generation (no DB introspection at all), use the `--schema` flag to point at the config file explicitly:

```bash
php artisan make:module Core/Products --schema=module.json --force
```

See the [Advanced: `--emit-config` and `--schema`](/legacy/creating-modules#advanced-emit-config-and-schema) section in the Creating Modules guide for the full inspect-then-generate workflow.

---

## Approach 3: `make:modules-from-db` — Whole-Database Bulk Scaffold {#approach-3}

Both approaches above still name modules one at a time — a shell loop still lists every module, V1's editor still configures one module at a time. `make:modules-from-db` instead introspects **every** non-framework table in a real database in one pass and scaffolds all of them together, in FK-topological order (dependency tables generated before the tables that reference them). Reach for it when adopting an existing, already-designed schema — a legacy database, or one hand-built ahead of time with real SQL — rather than designing modules table-by-table.

It is a two-stage command, split so you can review and adjust before anything is generated:

```bash
# Stage 1 — introspect and write an editable blueprint
php artisan make:modules-from-db --emit-blueprint=blueprint.json

# (edit blueprint.json — move tables between groups, fill in morph targets)

# Stage 2 — generate every module from the reviewed blueprint
php artisan make:modules-from-db --blueprint=blueprint.json --force
```

**Stage 1** introspects every table (skipping Laravel's own framework tables — `migrations`, `sessions`, `cache`, etc.), infers a foreign-key graph across the whole database, and writes a `blueprint.json` grouping every table under a module group. You edit this file before Stage 2 runs:

- Move a table between groups by cutting its name from one `groups.{Group}` array and pasting it into another.
- A table listed under the `""` (empty string) key is **skipped entirely** — no module is generated for it. Use this for tables that shouldn't become their own module (pivot tables already covered by a `morphs`/`delegations` relationship, for example).
- Fill in `targets` for any detected polymorphic (`morphs`) pairs. Add `delegate` to a target to get its reverse relation and a morph-filtered tab in one step ([Morphs](/legacy/morphs#reverse-morph-relations-relations-morphmany)).

The blueprint is one document describing the whole database, not a list of per-table objects. Stage 1 emits these top-level keys, and Stage 2 reads all of them:

| Blueprint key | What it carries |
|---|---|
| `connection` / `id_type` | The DB connection introspected, and the primary-key style for the batch. |
| `groups` | Map of group name → array of table names. The `""` key is the skip list. |
| `foreign_key_graph` | The inferred FK graph, used for topological ordering and reverse-FK delegation suggestions. |
| `morphs` | Detected morph pairs per table — this is where you fill in `targets`. See [Morphs](/legacy/morphs#targets-the-real-mechanism). |
| `delegations` | Suggested reverse-FK child tabs per parent module, seeded from `foreign_key_graph`. Editable before Stage 2. |
| `actions` | Custom action buttons per module. Note the blueprint→config mapping is an explicit whitelist, so a key it does not map (anything outside `name`/`label`/`description`/`icon`/`hasUI`/`uiType`/`placement`/`destructive`/`urlParams`/`methodName`/`serviceName`/`operations`) is silently dropped. |
| `inline_items` | Parent-child inline row blocks per parent module. Not emitted by Stage 1 — hand-add it, Stage 2 reads it. |
| `menu_config` | `sections` (one per named group) plus per-module label/icon/order overrides. |
| `seeders` | Seed rows per module; the blueprint owns this data outright and overwrites whatever introspection produced. |

**Stage 2** loads the reviewed blueprint, validates it, and generates every module — backend and frontend — in dependency order, so a module with a foreign key into another module in the same batch never generates before its dependency exists.

Stage 2 also merges each module's already-persisted `module.json` before injecting the blueprint's own keys, so a hand-added action or `inline_items` block the blueprint never mentions survives the regenerate. That merge is newer than the command itself — read [Preservation on re-runs](#preservation-on-re-runs) before relying on it.

Stage 2 re-derives its own FK graph from the live schema rather than trusting `foreign_key_graph` from the blueprint file verbatim — that snapshot is frozen at `--emit-blueprint` time and can drift from the live schema by the time `--blueprint=` actually runs (a column renamed or dropped in between). A blueprint-only edge whose column no longer exists is dropped, with a warning naming it, rather than emitted as a broken query against a column that isn't there — confirmed live on a `--blueprint=` run whose frozen graph still named a column the real table had since lost. Stage 2 also refreshes the delete check of every OTHER module (not part of this run) that one of this batch's own tables now references — see `{Module}DeleteCheckService` in [Module Structure](/legacy/module-structure#moduledeletecheckservice-read-only-pre-flight-for-the-delete-confirmation-ui) for what that refresh does and doesn't touch.

### Options reference

| Option | Description |
|---|---|
| `--emit-blueprint=path.json` | Run Stage 1: introspect the database and write the blueprint to this path. |
| `--blueprint=path.json` | Run Stage 2: load this blueprint and generate all modules. |
| `--force` | Overwrite existing generated files during Stage 2 (same semantics as `make:module --force`). |
| `--table=name` | Stage 2 only. Generate only this table (repeatable, or a comma list). See [Generating only part of a blueprint](#partial-blueprint). |
| `--module=Name` | Stage 2 only. Generate only this module, by name (repeatable, or a comma list). |
| `--group=Name` | Stage 2 only. Generate only the tables of this blueprint group (repeatable, or a comma list). |
| `--with-deps` | Stage 2 only, with `--table`/`--module`/`--group`. Also generate the modules the selection has foreign keys to that do not exist yet. Without it, they must already exist or the run stops. |
| `--connection=name` | Introspect/generate against a specific DB connection instead of the default. |
| `--mobile` | Also scaffold a Mobile App backend counterpart for every module in this batch. Off by default — see `features.mobile_app.enabled`. |
| `--layers` | Comma list of `backend` (required), `frontend`, `mobile`, applied to every module in this batch — see [`--layers` on `make:module`](/legacy/creating-modules#cli-options). `--layers=backend` makes every module in the batch backend-only. |

### Generating only part of a blueprint {#partial-blueprint}

Stage 1 always describes the whole database. Stage 2 can generate a subset:

```bash
php artisan make:modules-from-db --blueprint=blueprint.json --table=items                 # one table
php artisan make:modules-from-db --blueprint=blueprint.json --table=items,units           # some tables
php artisan make:modules-from-db --blueprint=blueprint.json --module=Items --module=Units # by module name
php artisan make:modules-from-db --blueprint=blueprint.json --group=Stock                 # a blueprint group
php artisan make:modules-from-db --blueprint=blueprint.json --table=items --with-deps     # plus what it needs
```

- The flags combine, take repeats or comma lists, and apply to Stage 2 only (`--emit-blueprint` with one is refused).
  The whole blueprint is still validated first, and the subset is generated in the full run's dependency order.
  An unknown table, module or group fails the request and lists the valid names.
- **A selected table's foreign keys must be satisfied**: each target is selected, already a module on disk, or a table
  the blueprint never generates (`""` group, framework, hand-written). Otherwise the run stops before writing anything
  and names what is missing; `--with-deps` generates the missing modules too. A foreign key is never demoted to fit.
- **A parent's delegation tab, inline-items child or reverse-morph tab is dropped, with a note,** when its module is neither
  selected nor on disk. Those are dependents of the parent, not requirements of it. Generate the child, then re-run the
  parent.
- **Nothing outside the selection is touched**, `--force` included, and a module's delete check never references a Model
  that was not generated (the dependent gets the usual commented placeholder and a warning).
- **An existing module is regenerated where it lives**, including one nested under `Core/Locations/` or
  `System/Masters/`. Stage 2 prints a note when the blueprint group would have put it elsewhere. Before this, regenerating
  a nested module through Stage 2 with `--force` wrote a duplicate flat copy and skipped the merge of its persisted
  `module.json`.

For one table with no blueprint at all, `make:module <Group>/<Name> --table=<t>` still works, without the blueprint's
delegations, inline items, actions, seeders, menu config or overrides.

### Foreign keys the database does not declare

Where a table has no `FOREIGN KEY` constraint, a `*_id` column is resolved by its **name** (`item_type_id` is `item_types`; a leading qualifier such as `source_` is ignored, so `source_quotation_id` is `quotations`). A compound noun cannot be guessed (`category_id` is `item_categories`): declare it in `BACKEND/fk_aliases.json`, as `{"category_id": "item_categories"}` or `{"items.category_id": "item_categories"}` for one table. A foreign key you correct by hand in a module's `module.json` (`type: foreignId` plus `relatedModule`) also survives `--force` and a full Stage 2 regenerate: the scaffolder feeds it into introspection before it runs. Precedence: a real database constraint, then `fk_aliases.json`, then the hand correction, then the naming rules. Engine v3.5.31+; the rules are in the engine's `scaffold-blueprint.md`.

### Audit columns are never introspected — they're always added

`id`, `uuid`, `created_at`, `updated_at`, `deleted_at`, `created_by_id`, `updated_by_id` are not something Stage 1 looks for or reports on, and not something you configure per table. Every module this engine generates gets all seven unconditionally — the same way the V1 wizard never offers them as a column you could add; they simply aren't a choice. A real table missing one of them (say, no `deleted_at`) does not need to be "fixed" before introspecting it, and introspection does not fail because of it — the generated module gets `has_soft_deletes: true` regardless, exactly as if the table already had the column.

This is deliberate, not a gap: an earlier revision of this engine (mid-2026) tried to *detect* these columns per table and derive the flags from whatever was actually present — which silently produced modules with SoftDeletes/timestamps/uuid/audit tracking missing, because this project's own schema-authoring convention is to leave those columns **out** of hand-written `CREATE TABLE` SQL in the first place ("the generator adds these automatically"). A later revision replaced detection with a loud, table-level convention-mismatch error instead — safer, but a hard, unrecoverable failure for any table that didn't already have all seven columns, with no config or flag anywhere to work around it short of editing the generator's own PHP source. Both were replaced (2026-08-16) with the current behavior: the columns are simply never inspected on the live table at all, so there is nothing to detect and nothing to diverge from.

::: tip A real legacy table without these columns
If a table genuinely shouldn't have one of these columns (an immutable log table with no soft-deletes, for instance), that is not expressed through introspection — hand-edit the generated module's `module.json` afterward (`has_soft_deletes: false`, etc.) and re-run `make:module {Group}/{Module} --force`, the same way any other post-generation customization is made. See "`module.json` as the Generation Contract" above.
:::

---

## `make:module` Options Reference

| Option | Description |
|---|---|
| `--force` | Overwrite all existing generated files. `module.json` is always merged, never replaced. |
| `--no-introspect` | Skip database introspection and emit empty stubs. Use when the table does not yet exist. |
| `--schema=path/to/file.json` | Load the resolved config from a JSON file instead of introspecting the database. |
| `--emit-config=path/to/file.json` | Write the resolved config to a file and exit without generating any files. Useful for reviewing the introspected schema before committing to generation. |

::: tip `--backend-only` / `--frontend-only` belong to `modules:register`
These flags do not exist on `make:module`. They belong to the separate
`modules:register` command, which auto-discovers and registers already-generated
modules into `FRONTEND/src/modules.json` / the backend module registry:

```bash
php artisan modules:register --backend-only
php artisan modules:register --frontend-only
```
:::

---

## `module.json` as the Generation Contract

Whether you use V1 or `make:module` directly, `module.json` is the contract between your intent and the generated output.

### Preservation on re-runs

`module.json` is **never overwritten wholesale** — an existing file's developer-owned keys are merged into the freshly introspected config on every run, including `--force` runs. This makes it safe to re-run generation after adding a column to the database table or upgrading the generator engine.

::: warning Two different merge paths — and the batch one is newer than this page
The single-module and batch commands reach the same result by different code, and they have **not** always behaved the same way:

- **`make:module`** builds its config through `ModuleScaffolder::buildConfig()`, which calls `mergePersistedFields()` — the long-standing path. This is where the preserved-key list below comes from.
- **`make:modules-from-db`** builds every table's config independently and never called that merge at all until **2026-08-20**, when `ModuleScaffolder::mergeExistingConfig()` (a public wrapper over the same `mergePersistedFields()`) was added and wired in. Before that fix, any hand-authored `actions`/`constants`/`processors`/`delegations`/`inline_items` that was **not also declared in the blueprint** was silently wiped on every `--force` blueprint regenerate — e.g. a wizard action added later through a `make:module --schema=` pass.

If you are on an older checkout of this project and run blueprint regenerates, verify `mergeExistingConfig()` exists in `BACKEND/app/Project/Commands/ModuleScaffolder.php` (moved from `app/Project/_Src/Console/` in September 2026) before trusting the claim below. It is shell-owned code in this repository, **not** part of the `blutrixx/generator-engine` package, so a `composer update` does not deliver it.
:::

**Precedence in the batch path is per-key, not a single blanket rule** — verified directly against
`MakeModulesFromDb.php`'s actual merge/injection code and PHP's own `array_merge()` semantics
(later argument wins on a string-key collision), not inferred from a comment. Two of the keys below
behave the *opposite* of what a quick read of the source's own inline comments suggests, so don't
trust a comment here over a real test:

| Key | On collision (declared in both persisted `module.json` AND the blueprint) | Persisted-only | Blueprint-only |
|---|---|---|---|
| `constants`, `processors`, `model_hand_maintained`, `relations` | N/A — the blueprint never declares these; persisted always wins unconditionally when non-empty | Survives | N/A |
| `features.backend.list.bulk_actions` / `.export` / `.import` | N/A — same, blueprint has no equivalent | Survives | N/A |
| `features.mobile_app.mode` / `.enabled` | N/A — same | Survives | N/A |
| `morphs[].targets` | Merged **by target name** (`applyMorphTargets()`), not overwritten wholesale | Survives | Added |
| `delegations` | **Persisted wins** — `array_merge($blueprintDelegations, $persistedDelegations)`, and the persisted value is the later argument | Survives | Added |
| `actions` | **Blueprint wins** — `array_merge($persistedActions, $blueprintActions)`, blueprint is the later argument (the source's own comment confirms this one correctly: "blueprint actions take precedence") | Survives | Added |
| `inline_items` | **Blueprint wins outright** — a flat overwrite (`$config['inline_items'] = $blueprintInlineItems[...]`), not a merge. A hand-customized `inline_items` config on a module the blueprint *also* declares `inline_items` for is silently discarded on every batch `--force` run. Only safe when the blueprint has nothing for this module. | Survives | Replaces |
| `menu_config` | **Blueprint always wins, unconditionally** — this stage runs with no `empty()` guard at all, rebuilding `menu_config` fresh from the blueprint's menu sections/module overrides on *every* run regardless of whether the blueprint says anything module-specific. A hand-edited `menu_config` (custom icon, order, visibility) is **not actually preserved** by the batch path, despite surviving the earlier `mergeExistingConfig()` restore step — it gets overwritten one step later. | **Does not survive** | Replaces |
| `features.frontend.list.fields[].defaultVisible`, `features.backend.list.filterableFields`, `features.frontend.view.fields[].group` (Step 9's display-config pass, above) | **In `mergePersistedFields()`'s preserved-key list** (added 2026-09-13) — merged per-field (list fields by `key`, view fields by `data`), so a newly-introspected column still arrives with introspection's defaults instead of being blocked by the merge. `filterableFields` keeps only persisted-order columns introspection still produces; a persisted entry naming a dropped column is not carried. Nothing downstream of `mergeExistingConfig()` in the batch path overwrites `features.frontend.list`/`.view`/`features.backend.list` afterward (unlike `menu_config`, below), so the restore actually sticks. | Survives | Added |
| `features.frontend.enabled` | N/A — the blueprint has no equivalent key | Survives | N/A |
| **`module_overrides.{Module}`** (any key at all) | **Blueprint wins, last** — deep-merged over the fully assembled config as the final step before generation, so it overrides what introspection, every key above, and the persisted `module.json` produced. Objects merge key by key; a list, scalar or `null` **replaces** what it meets (unless it is a `$merge` patch of one entry by identity); `_`-prefixed keys are comments. See [below](#module-overrides). | Survives unless the override names it | Applied |

### `module_overrides` — the blueprint's escape hatch {#module-overrides}

The blueprint has a dedicated key for groups, morphs, delegations, inline_items, actions, seeders
and menu config. Everything else in a module's config used to need a second pass
(`make:module … --force --schema=`, Step 9 above) because the batch command had no way to be told.
`module_overrides` closes that: one entry per module, deep-merged over the assembled config just
before generation.

```json
"module_overrides": {
  "SuitePings": { "location_bearing": false },
  "SuiteReceipts": {
    "features": { "backend": { "list": { "export": true, "import": true } } }
  }
}
```

- **Objects merge; everything else replaces.** A list is *replaced*, never merged index by index —
  merging one `bulk_actions[0]` into another list's `[0]` would splice two unrelated actions.
- **To change one entry of a list, address it by an identity key** rather than restating the list:
  `{"$merge": "key", "$items": [{"key": "internal_ref", "defaultVisible": false}]}`. Each item is
  deep-merged into the entry whose `key` equals its own; the other entries, and their order, are
  untouched. An item matching nothing (a typo) is a **hard failure** that lists the keys that do
  exist, and so is `$merge` against something that is not a list. To **add** an entry introspection
  cannot produce — a JSON column's item-picker field — list it under `$append`:
  `{"$merge": "field", "$append": [{"field": "items", "type": "item-picker", …}]}` (an appended identity
  that already exists is a hard failure; `$items` and `$append` may be combined). The identity key is yours to
  name — `key` for both `features.frontend.list.fields` and `features.backend.list.bulk_actions`.
  (`actions` and `delegations` are keyed objects, not lists, so they merge without it.)
- **It is the last word.** It overrides the schema's introspection, every other blueprint key, and a
  persisted `module.json`. A key you do not name is untouched, so an override can be as small as one
  flag.
- **Keys starting with `_` are comments**, at every level.
- **The module must be in a named group**, like `actions` and `inline_items` — an unknown module is a
  hard failure, not a silent no-op.

Use it for the keys with no blueprint equivalent — `location_bearing`, `json_rules`, `processors`,
`constants`, `features.backend.list.export`/`import`/`bulk_actions`, `features.frontend.create.wizard`
and `.drafts`. An action's own `confirm_step`, `splash`, `serviceMethod` and `serviceArgs` are now
forwarded by the `actions` key itself (the whitelist used to drop them silently).

To make only some modules of a batch backend-only, run `php artisan make:module System/<Group>/<Module> --layers=backend` for each (its table already exists) before Stage 2 — the batch `--force` then keeps them backend-only, since `features.frontend.enabled` survives the batch merge like any other persisted key above.

::: warning `inline_items` and `menu_config` are not safe to hand-customize on the batch path
Both get unconditionally replaced by the blueprint injection stage that runs *after*
`mergeExistingConfig()`'s restore — the restore happens, then is immediately thrown away. A
follow-up `make:module --force --schema=` pass reapplies either cleanly (the single-module path has
no equivalent unconditional-overwrite stage for them). Step 9's display config no longer needs this
workaround — see the row above.
:::

### Hand-editing to drive regeneration

The recommended workflow for any structural change to a module is:

1. Edit `module.json` — add a column entry, enable a feature flag, configure a delegation.
2. Re-run `make:module Core/ModuleName --force`.
3. The generator applies the updated config and overwrites all generated files.

Never edit generator-controlled content (routes, CRUD services, form field definitions) directly in the generated files — those edits will be lost on the next `--force` run. Drive every change through `module.json` instead.

### V1 vs CLI — same file, different writers

V1 reads and writes `module.json` via its API. `make:module` reads it from disk and writes it back after each run. Both update the same file. If you design a module in V1 and then continue iterating locally with `make:module`, the file on disk is always the shared state — no sync step required.

::: tip Committing `module.json` to version control
`module.json` should be committed to your project repository. It is the only durable record of your module's configuration. Generated files can always be regenerated from it; `module.json` cannot be regenerated from the generated files.
:::

---

## Recommended Workflow for a New Project

This is the recommended end-to-end flow when building a SYSTEM_SHELL deployment from scratch.

::: warning Step 1 is stale for whole-domain builds — use `/scaffold-domain`
This project's own `CLAUDE.md` directs greenfield, multi-module work to the
`/scaffold-domain` Claude Code command (`.claude/commands/scaffold-domain.md`)
instead: it writes or interviews `docs/specs/`, derives the SQL schema and the
blueprint from that spec, classifies each module by type, proposes
actions/delegations/inline-items/morph targets, and finishes with
`make:modules-from-db` — i.e. Approach 3 above, not V1's visual editor.
Generator-engine v3.0.0 also removed the UX Builder subsystem the V1 editor's
composite/wizard features depended on.

The V1 flow below is still accurate for a V1-driven project and is left in
place for that case, but a fresh domain build should start at
`/scaffold-domain`.
:::

1. **Design all modules in V1** — use the visual editor to configure columns, feature flags, foreign-key relationships, and delegations for every module in the project. Invest time here; a well-designed `module.json` reduces hand-editing after generation.

2. **Generate the project via V1** — click "Generate Project" and monitor the queue at `/generator/queue`. Wait for all jobs to reach `completed`. Each module's `module.json` and all generated backend/frontend files are written to the target SYSTEM_SHELL directory.

3. **Register routes and sidebar entries** — verify `FRONTEND/src/modules.json` was updated correctly, and that each module's `Seeders/MenuSeederData.json` was written (generator-engine v3.5.18+ — see Module Registration → Sidebar Navigation; syncing those into a real menu appearing in the sidebar is a separate, project-level step, not automatic).

4. **Run migrations** — from the SYSTEM_SHELL `BACKEND/` directory:
   ```bash
   php artisan migrate
   ```

5. **Seed permissions** — register all auto-generated permission keys into the permissions table:
   ```bash
   php artisan db:seed --class=PermissionsSeeder
   ```

6. **Add a `format{Module}()` override for every module whose table has no `name` column** — in `BACKEND/app/Project/_Src/SelectTrait.php`, alias that table's real identifying column(s) `as name`. `SelectController`'s generic `/select/{module}` endpoint hard-500s otherwise, and every FK picker anywhere in the app (Create/Edit `select` + `splash_key`, `actions[].fields`, `inline_items[].fields`, the morph-select record picker) goes through that one endpoint.

   This is the highest-value check in a bulk run: a blueprint batch lands nine or more modules at once, and a module is usually broken as *someone else's* FK target — nothing in its own generation output hints at it. Walk the batch's FK graph, not just the modules you were thinking about.

6. **Iterate with `make:module`** — for new modules added after the initial build, or for changes to existing modules, use `make:module` directly on the SYSTEM_SHELL. V1 is not required for ongoing iteration:
   ```bash
   php artisan make:module Core/NewFeature
   # or, to update an existing module after editing module.json:
   php artisan make:module Core/ExistingFeature --force
   ```

::: tip After adding a new module mid-project
After generating a new module with `make:module`, you still need to run `php artisan migrate` and `php artisan db:seed --class=PermissionsSeeder` to activate it fully. The generator writes files; it does not execute migrations or seed runs.
:::

---

## Related Pages

- [Creating Modules](/legacy/creating-modules) — `make:module` CLI reference, DB introspection, and the `--emit-config` / `--schema` inspect-then-generate workflow.
- [Module Anatomy](/legacy/module-structure) — detailed walkthrough of every generated file and the patterns each one follows.

:::
