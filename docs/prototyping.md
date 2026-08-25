# Prototyping — `gen-frontend`

Scaffold a **runnable** Vue app from module configs alone, with no PHP runtime, no MySQL, no Laravel and no backend of any kind. A 16-module app takes about 0.1 seconds; `npm install && npm run dev` and you can click through it.

This exists because seeing a generated UI otherwise costs the whole stack: a live schema to introspect, `make:module` scaffolding both halves, migrate, seed, boot Laravel, boot Vite. That is the right workflow for building the real thing and the wrong one for answering *"what would this feel like?"*.

::: tip Requires v3.5.4
The CLI bootstraps from its own Composer autoloader and needs no host application, but use
**v3.5.4 or later**: v3.5.0–v3.5.2 declared no `bin` at all, and v3.5.3 exposed one that
fataled inside a Laravel project because the framework's helpers are autoloaded there
without the container ever being booted.
:::

---

## Quick Start

```bash
# From a directory of module.json files (a generated backend, say)
vendor/bin/gen-frontend \
  --spec=BACKEND/app/Project/Modules \
  --out=/tmp/my-prototype

cd /tmp/my-prototype/FRONTEND
npm install && npm run dev
```

That is the whole loop. The CLI writes `.env.local` with `VITE_MOCK_API=true`, so the app comes up serving its own API from SQLite running in the browser tab.

---

## Three Pieces

| Piece | What it does |
|---|---|
| `FrontendPipeline` | The frontend generator sequence, with no Laravel dependency — no container, no `config()`, no `base_path()`, no database. Also drives `make:module`, so the two cannot drift. |
| `bin/gen-frontend` | The CLI. Loads a spec, copies a chassis, runs the pipeline per module. |
| `ApiContractGenerator` | Emits `FRONTEND/src/api-contract.json` — the API description the browser-side mock stands a backend up from. |

---

## The Spec

`--spec` takes **the same module config `make:module --schema=` consumes**. That is deliberate: there is no second config language, so a prototype and the real build can never describe different apps.

Three accepted shapes:

```bash
--spec=app.json          # an app spec: {"modules": [ <module config>, ... ]}
--spec=Statuses.json     # a single module config (anything with "module_name")
--spec=path/to/modules/  # a directory, scanned recursively
```

A directory scan accepts **any `.json` declaring a `module_name`** — not just files literally called `module.json`, since exporting a generated tree flattens them to `<Module>.json` to avoid basename collisions. Files without a `module_name` are skipped and listed in the summary, so a module never goes missing silently. That check is also what keeps a `package.json` or a module *manifest* out of the results.

### Sub-groups

A nested module lives at `{Group}/{SubGroup}/{Module}/module.json`, and **the sub-group is recorded only in that path** — `module_group_name` is null even for genuinely nested modules, because `make:module` takes it from its `Core/Access/Permissions` argument rather than from the config. A directory scan recovers it from the path. When you hand-write a spec, set `module_sub_group` explicitly.

---

## The Chassis

Generated pages import a great deal they do not define — `MainLayout`, `CrudListPanel`, form fields, composables, the i18n setup. `--chassis` points at an existing `FRONTEND` app to copy first; it defaults to the nearest `SYSTEM_SHELL/FRONTEND` the package can find by walking up from its install location.

Excluded from the copy: `node_modules`, `dist`, `test-results`, `playwright-report`, `.git`, and every `e2e/` directory — the last because generated screenshot folders dwarf everything else (a chassis is ~4 MB without them and ~30 MB with).

Kernel modules come along with the chassis, which is usually what you want: the sidebar, FK pickers and the `Profiles`/`Drafts` routes the router hard-references all work without the spec having to describe them.

`--no-chassis` emits only generated module files. Useful for diffing output; the result is not a runnable app.

---

## Prototype Mode

With `VITE_MOCK_API=true`, the app serves its whole API from **SQLite compiled to WebAssembly**, seeded from `api-contract.json` and persisted as a snapshot in IndexedDB.

SQLite rather than an object store because the generated list contract — `filters[field][operator]` plus sort and paginate — is a `WHERE`/`ORDER BY`/`LIMIT` in disguise. Backing it with real SQL answers those queries the way `ListService` does instead of reimplementing them in array code that drifts, and FK dropdowns resolve against tables that genuinely exist.

Interception is an **axios adapter**, not a dev-server middleware or a service worker. Every generated page calls `sendGetRequest`/`sendPostRequest`/`sendFormDataRequest`, all of which funnel through the shared client — so swapping the adapter leaves those pages byte-unmodified. A prototype exercises the *real* generated files rather than a parallel copy that can quietly disagree with them.

Authentication is one `localStorage` write: the prototype user holds every permission named anywhere in the contract, so nothing is hidden behind a gate the prototype has no way to grant.

### Console helpers

```js
__prototype.reset()        // drop the snapshot, reseed, reload
__prototype.exportSql()    // the whole database as MySQL DDL + INSERTs
__prototype.downloadSql()  // ...as a file
```

::: warning The mock reproduces shape, not behaviour
Actions and wizards acknowledge the call; they do not run a state transition. Computed delegation aggregates and server-side report generation are not simulated. This is built for *"here's the app, click through it"* — not for proving a workflow is correct.
:::

---

## `api-contract.json`

Merged into by every module, like `modules.json` and `menus.json`. Per module:

| Key | Contents |
|---|---|
| `group`, `subGroup`, `table`, `route`, `idType` | Identity and URL prefix (`Str::kebab` of the module name) |
| `flags` | `timestamps`, `softDeletes`, `uuid`, `creatorUpdater` |
| `columns` | name, type, nullable, unique, indexed, length, default, `relatedModule` |
| `routes` | `{method, path, permission, handler}` for every registered route |
| `validation` | Laravel rule strings per field, for `create` and `edit` |
| `list` | `primaryField`, `filterFields`, `sortableFields`, `export`, `import`, `bulkActions` |
| `delegations`, `actions`, `morphs` | Declared names / definitions |
| `ddl` | Complete `CREATE TABLE` in **both** `mysql` and `sqlite` dialects |

### Routes are parsed, never re-derived

::: danger Do not build against `features.backend.*.endpoint`
Those blocks look authoritative and disagree with reality for core CRUD. A real `module.json` declares `edit` as `PUT /statuses` and `view` as `GET /statuses/{uuid}`, while `RoutesGenerator` registers `PUT /statuses/{uuid}/edit` and `GET /statuses/{uuid}/view`. Same divergence on list and delete. Anything describing the API from those blocks 404s on every request.
:::

`ApiContractGenerator` parses `RoutesGenerator::buildContent()`'s own output instead, so the contract cannot describe a route the backend does not serve. `ApiContractRouteParityTest` holds that line across five module shapes — plain CRUD, reduced operation sets, export/import, actions and delegations — re-parsing the written file with its own regex, so a bug in the generator's parser cannot agree with itself.

### DDL includes the convention columns

`ddl` comes from `DdlRenderer::fullTable()`, not `fromColumns()`. The latter renders only the business columns it is handed, because this project's authoring convention keeps `id`, `uuid`, `created_at`, `updated_at`, `deleted_at`, `created_by_id` and `updated_by_id` out of module config entirely (see `SchemaConventions`). A `CREATE TABLE` missing all of those cannot store a generated module's rows.

---

## CLI Flags

| Flag | Meaning |
|---|---|
| `--spec=PATH` | **Required.** App spec, single module config, or a directory of config `.json` files. |
| `--out=DIR` | **Required.** Prototype root; `FRONTEND/` is created beneath it. |
| `--chassis=DIR` | `FRONTEND` app to copy first. Defaults to the nearest `SYSTEM_SHELL/FRONTEND`. |
| `--no-chassis` | Emit generated module files only. Not a runnable app. |
| `--only=LABEL` | Restrict to generators whose label contains `LABEL` (case-insensitive, repeatable). |
| `--force` | Overwrite existing generated files. |
| `--quiet` | Print only the summary. |
| `--help` | Show usage. |

Exit codes: `0` success, `1` usage error, `2` a generator reported an error.

### Generator labels

`--only=` matches these, in this order:

`ListPage`, `CreatePage`, `CreateForm`, `EditPage`, `EditForm`, `ViewLayout`, `ViewOverview`, `ViewHistory`, `ViewModal`, `DeletePage`, `DeleteForm`, `FrontendRoutes`, `ModulesJson`, `MenusJson`, `ApiContract`, `FrontendLocales`, `PlaywrightTest`, then `Delegation{tab|modal}Component [key]` and `ActionComponent [key]` per delegation and action.

The order and the label text are part of the contract — renaming or reordering silently changes what an existing `--only=` invocation produces.

---

## Promoting a Prototype

The prototype is schema-first, so it feeds straight back into the real workflow:

1. `__prototype.downloadSql()` — the database as MySQL DDL plus its rows.
2. Load that into MySQL.
3. `php artisan make:modules-from-db --emit-blueprint=blueprint.json`, then generate.

The schema you clicked through is the schema you build on.

---

## Using the Pipeline Directly

`FrontendPipeline` is public API; the CLI is one caller of it.

```php
use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendPipeline;
use Blutrixx\GeneratorEngine\Generators\PathManager;

PathManager::setProjectRoot($outputRoot);
PathManager::setModuleRegistry($registry);   // ['name' => ..., 'module_type' => ..., 'config' => ...]

$result = (new FrontendPipeline($outputCallback))
    ->setForce(true)
    ->setOnly(['ListPage'])
    ->run($moduleName, $moduleGroup, $config);

// ['created' => int, 'skipped' => int, 'errors' => string[]]
```

::: tip Registry entries should carry the full config
Generators that need a **related** module's config — `CustomFeatureTabComponentGenerator`, resolving a delegation tab's columns — read `$entry['config'] ?? $entry` from the registry first, and only then fall back to reading that module's `module.json` from the backend tree. A frontend-only run has no backend tree, so a registry entry without `features` produces a delegation tab with no columns: it paginates over real rows and renders nothing.
:::

Failures are caught as `\Throwable`, not `\Exception` — scaffolding a whole app runs the pipeline over dozens of modules in one process, and a `TypeError` from one malformed config should not take the others down with it. Errors are still collected and still fail the command.

---

## Verified Against

The 13-module [super-suite fixture](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/super-suite) — inline items, morphs, delegations, actions, status machines and reduced operation sets — generates, builds, and runs: list pages, pagination, create, filtering, inline validation errors, delegation tabs, morph selects and action dialogs, all with zero console errors.
