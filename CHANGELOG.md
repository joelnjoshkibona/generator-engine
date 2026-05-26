# Changelog

## v2.4.8 — 2026-05-27

### Changed — Tab modal dialogs match ApiSelect2Field design

All four tab modals (Create, Edit, View, Delete) now use the same dialog
styling as the `ApiSelect2Field` "Add new" pattern:

- `Dialog class="p-0"`
- `DialogContent class="min-w-4xl p-0 gap-0 overflow-hidden"`
- `DialogHeader class="px-4 py-3 border-b"`
- `DialogTitle class="text-sm font-semibold"`

Form content renders flush inside `DialogContent` with no extra wrapper,
letting each form control its own internal padding.

---

## v2.4.7 — 2026-05-26

### Changed — Tab delete uses DeleteForm modal instead of inline confirmation

The inline "Are you sure?" delete dialog in delegation/custom-feature tab components has been
replaced with the full `<RelatedModuleDeleteForm :modal="true">` component, consistent with
how Create/Edit/View already work in tabs.

**Benefits:**
- Relationship blocking checks before deletion (cannot delete if children exist)
- "Type YES to confirm" safety gate
- Consistent UX across all tab operations

**`delete/form.stub` updated:**
- Added `const emit = defineEmits(['cancel', 'deleted'])`
- `handleDelete` success path: emits `deleted` when `modal=true`, navigates when standalone
- `cancel()`: emits `cancel` when `modal=true`, navigates when standalone

**Tab stubs (`delegation/tab.stub`, `custom/tab_action.stub`) updated:**
- Delete dialog now renders `<[[RelatedModule]]DeleteForm :modal="true">` instead of inline dialog
- Removed `confirmDelete`, `sendPostRequest`, `toast`, `DialogFooter` — no longer needed
- Added `handleDeleteSuccess` which closes modal and refreshes list

**`CustomFeatureTabComponentGenerator.php` updated:**
- Adds `DeleteForm` to `[[componentImports]]` when `hasDelete` is true
- Removed `[[deleteEndpointPath]]` substitution (DeleteForm handles the endpoint internally)

---

## v2.4.6 — 2026-05-26

### Fixed — Tab component API endpoints always produced `/list`

`CustomFeatureTabComponentGenerator` used the backend list config's `endpoint.path` as the
base path for the tab's `apiEndpoint`. When that value was a bare operation name (e.g. `list`),
the cleanup regex stripped it, leaving an empty base — so every generated tab emitted
`` `/list` `` instead of the correct parent-scoped URL.

Additionally, all endpoint template literals used `${props.data.uuid}` for the parent UUID,
which is undefined on hard refresh before `props.data` is populated.

**Fix:** endpoints are now always built directly from the parent module route and feature route,
using `${uuid.value}` (read from `route.params.uuid` — always reliable):

- List endpoint: `` `/{parent-route}/${uuid.value}/{feature-route}/list` ``
- Delete endpoint: `` `/{parent-route}/${uuid.value}/{feature-route}/${deletingItem.value.uuid}/delete` ``

This removes ~30 lines of fragile path-cleanup logic and makes the output deterministic.

---

## v2.4.5 — 2026-05-26

### Fixed — Unsubstituted `[[idParam]]` in delegation and custom-feature tab stubs

`delegation/tab.stub` and `custom/tab_action.stub` both read the parent record UUID from route
params using `route.params.[[idParam]]`. The placeholder was never included in
`CustomFeatureTabComponentGenerator`'s substitution array, so generated tab components contained
the literal string `[[idParam]]` instead of the real param name.

Since these tab components always operate as children of a details layout whose parent ID is
always `uuid`, the placeholder is now hardcoded to `uuid` directly in both stubs, removing the
dependency on substitution entirely.

**Stubs fixed:**
- `features/delegation/tab.stub` — `route.params.[[idParam]]` → `route.params.uuid`
- `features/custom/tab_action.stub` — `route.params.[[idParam]]` → `route.params.uuid`

**Also fixed in this release:**
- `scripts/migrate_back_arrow.py` — two regex capture-group bugs in `patch_main_layout` that
  caused `title: string` to be duplicated and `<nav` to be doubled in the output when patching
  a generated system's `MainLayout.vue`. Both patterns now capture only the whitespace in group 2.

---

## v2.4.4 — 2026-05-24

### Added — Back-arrow navigation + conditional Cancel button

Generated pages now include a back-arrow in the `MainLayout` header, and form Cancel buttons are
only rendered in modal context (`v-if="modal"`). In page context the back arrow handles navigation,
eliminating the redundant Cancel button on standalone pages.

**Stubs updated:**

- `features/create/page.stub` — passes `:back-link="backLink"` to `MainLayout` (defaults to list route)
- `features/edit/page.stub` — passes `:back-link="backLink"` to `MainLayout` (defaults to details route)
- `features/delete/page.stub` — passes `:back-link="backLink"` to `MainLayout` (defaults to details/overview route)
- `features/view/details_layout.stub` — passes `:back-link="'/[[moduleRoute]]/list'"` to `MainLayout`
- `features/create/form.stub` and `features/edit/form.stub` — Cancel button already rendered conditionally via `BaseComponentGenerator`
- `features/delete/form.stub` — added `modal: { default: false }` prop; Cancel button now has `v-if="modal"`

**Generator updated:**

- `BaseComponentGenerator::generateFormFooter()` — Cancel button now includes `v-if="modal"` so it
  only appears when the form is used inside a modal/delegation, not on a standalone page.

**Migration script:** `scripts/migrate_back_arrow.py` — idempotent script to apply this pattern to
any already-generated system. Run: `python3 scripts/migrate_back_arrow.py <path-to-FRONTEND/src>`

---

## v2.4.3 — 2026-05-22

### Added — Injectable region system for generator patching

- `PatchesRegions` trait (`src/Generators/PatchesRegions.php`) — idempotent file patching via named
  comment markers. Supports HTML-comment style (`<!-- [generator:region:name:start] -->`) for Vue
  templates and JS-comment style (`// [generator:region:name:start]`) for script blocks.
- `details_layout.stub` — `shortcut-import` and `shortcuts` region markers added so shortcut
  injection is stable on regeneration.
- `ShortcutGenerator` — uses `patchRegion()` for both desktop and mobile detail-layout patching;
  falls back to string-anchor approach for files generated before region markers were introduced.
- `DashboardGenerator` — now calls `patchDashboardPage()` after generating `DashboardQuickActions.vue`,
  automatically wiring the component into the Analytics `DashboardPage.vue` via region markers.

## v2.4.2 — 2026-05-22

### Added — Comprehensive JSON schema documentation

Added `docs/`, `examples/`, and `schema/` directories covering every JSON structure
accepted by the engine.

**Docs (10 files):**

| File | Covers |
|------|--------|
| `docs/module-config.md` | Top-level module config, `menu_config`, `seeder`, `constants`, `morphs` |
| `docs/columns.md` | Column types, FK columns, morph pairs, `featureSelections`, frontend field mapping |
| `docs/features-config.md` | `features.backend` and `features.frontend` per-operation shapes |
| `docs/mobile-config.md` | `features.mobile_app`, card layout resolution, offline sync API, generated file list |
| `docs/delegations.md` | Delegation tab/modal config, `operations`, generated files |
| `docs/actions.md` | Action buttons, `urlParams`, `serviceName`, generated files |
| `docs/processors.md` | Lifecycle pipeline stages, processor class contract |
| `docs/scaffold-blueprint.md` | Blueprint JSON for `make:modules-from-db` / `make:mobile-modules` / `make:mobile-scaffold` |
| `docs/ux-blueprint.md` | UX blueprint with `module_groups` (critical), composites, wizards, shortcuts, dashboard |
| `docs/README.md` | Index, quick-reference error table for common agent mistakes |

**JSON Schema files (3 files):**
- `schema/module-config.schema.json`
- `schema/scaffold-blueprint.schema.json`
- `schema/ux-blueprint.schema.json`

**Annotated examples (3 files):**
- `examples/module-config-full.json` — complete `Products` module config
- `examples/scaffold-blueprint.json` — full scaffold blueprint with groups, delegations, seeders, actions
- `examples/ux-blueprint.json` — full UX blueprint with composite, wizard, shortcuts, dashboard

**Key pitfalls documented:**
- `module_groups` missing from UX blueprint → all four UX generators produce 0 files
- `dashboard.quick_actions: []` → DashboardGenerator returns early with 0 files
- `relatedModule` not set on FK column → plain input instead of `api-select`

Updated `composer.json` description to mention NativePHP Mobile support.

---

## v2.4.1 — 2026-05-21

### Changed — Laravel 13 support

Widened `illuminate/support` and `illuminate/filesystem` constraints from
`^11.0|^12.0` to `^11.0|^12.0|^13.0` so the package can be installed as a
`require-dev` dependency inside `SYSTEM_SHELL/MOBILE_APP` (which uses Laravel 13
via `nativephp/mobile ^3.3.5`).

---

## v2.4.0 — 2026-05-21

### Added — `SchemaIntrospector` promoted to engine package

`Blutrixx\GeneratorEngine\Schema\SchemaIntrospector` is now shipped with the engine.
Previously this class lived in `SYSTEM_SHELL/BACKEND` as `App\Project\_Src\Console\SchemaIntrospector`.

**New public class:** `Blutrixx\GeneratorEngine\Schema\SchemaIntrospector`

Supports MySQL, SQLite, and PostgreSQL via Laravel's `Schema` facade (no Doctrine DBAL).
Uses Laravel 11+ `Schema::getColumns()` / `Schema::getForeignKeys()` / `Schema::getIndexes()`.

Key methods:

| Method | Description |
|--------|-------------|
| `__construct(string $table)` | Bind to a specific table. |
| `exists(): bool` | Whether the table exists in the current DB. |
| `idColumnType(): string` | Returns `'bigint'`, `'uuid'`, or `'string'` for the `id` column. |
| `columns(): array` | Structured column metadata for every non-framework column. |
| `static globalForeignKeys(): array` | Builds the full FK graph across all application tables. |
| `static setIssueHandler(?callable $handler): void` | Register a warning callback. |

`SKIP_COLUMNS` constant: `id, uuid, created_at, updated_at, deleted_at, created_by_id, updated_by_id`.

**Column metadata shape returned by `columns()`:**
```
name, type, normalized_type, length, nullable, default,
is_fk, foreign_table, foreign_column, is_unique, morph_role, morph_name
```

**Migration note:** `SYSTEM_SHELL/BACKEND/app/Project/_Src/Console/SchemaIntrospector.php`
now contains a thin backward-compat alias extending the engine class:
```php
class SchemaIntrospector extends \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector {}
```

---

## v2.3.0 — 2026-05-21

### Added — MOBILE_APP modular backend scaffold + offline sync

The engine now generates a full PHP/Laravel backend for NativePHP Mobile apps.
NativePHP Mobile embeds a complete Laravel app on the device; the Vue SPA calls
`http://localhost/api/...` locally. Previously, no backend code was generated —
every endpoint had to be hand-written.

#### New generators (16 classes under `Generators\MobileApp\Backend\`)

| Generator | Emits |
|-----------|-------|
| `MobileModelGenerator` | `{Module}Model.php` — extends plain `Eloquent\Model`, no BaseModel deps |
| `MobileControllerGenerator` | `{Module}Controller.php` — returns `{status, data, message}` JSON |
| `MobileApiRoutesGenerator` | `Routes/api.php` — 5 CRUD + 2 sync endpoints |
| `MobileMigrationGenerator` | `Migrations/{date}_create_{table}_table.php` — SQLite-safe |
| `MobileSeederGenerator` | `Seeders/{Module}SeederData.json` |
| `MobileListServiceGenerator` | `Services/{Module}ListService.php` |
| `MobileCreateServiceGenerator` | `Services/{Module}CreateService.php` |
| `MobileViewServiceGenerator` | `Services/{Module}ViewService.php` |
| `MobileEditServiceGenerator` | `Services/{Module}EditService.php` |
| `MobileDeleteServiceGenerator` | `Services/{Module}DeleteService.php` |
| `MobileDeleteCheckServiceGenerator` | `Services/{Module}DeleteCheckService.php` |
| `MobileActivityListServiceGenerator` | `Services/{Module}ActivityListService.php` |
| `MobileBulkActionServiceGenerator` | `Services/{Module}BulkActionService.php` |
| `MobileSyncServiceGenerator` | `Services/{Module}SyncService.php` |
| `MobileSyncComposableGenerator` | `resources/js/src/composables/use{Module}Sync.ts` |
| `MobileRegistryGenerator` | Updates `MOBILE_APP/app/Modules/registry.json` (runs last) |

#### Offline sync

Every generated mobile module includes push/pull sync:

```
POST /api/{prefix}/sync/push   — upsert records by uuid, sets last_synced_at
GET  /api/{prefix}/sync/pull   — return records where updated_at > ?since
```

`use{Module}Sync.ts` — Pinia composable exposing `{ isSyncing, isOnline, lastSyncedAt, push, pull }`,
persists `lastSyncedAt` in `localStorage`.

#### SQLite-safety

All mobile backend stubs are SQLite-safe:
- `TEXT` instead of `JSON` columns
- `LIKE` for text search (no MySQL `FIND_IN_SET` or JSON operators)
- Direct `$table->uuid('uuid')->primary()` (no `DB::raw(Helpers::...)`)
- `last_synced_at TIMESTAMP NULL` column on every table for sync tracking

#### Registry-based autoloader

`MOBILE_APP/app/Providers/ModuleServiceProvider` reads `app/Modules/registry.json` on boot
and for each module calls `loadMigrationsFrom()` + `Route::prefix('api')->group()`.
`MobileRegistryGenerator` updates `registry.json` after every scaffold run.

#### New PathManager methods

```php
PathManager::getMobileAppBackendModulePath(string $group, string $moduleName): string
PathManager::getMobileAppBackendTemplatePath(): string
```

#### New Artisan commands

- `make:mobile-modules --blueprint=file.json` — generates mobile backend only from blueprint JSON
  (runs from `SYSTEM_SHELL/BACKEND` where the dev DB is accessible)
- `make:mobile-scaffold` — full-stack mobile scaffold from inside `MOBILE_APP`, introspecting
  local SQLite directly (generates PHP backend + Vue frontend in one pass)

---

## v2.2.0 — 2026-05-21

### Added — Full mobile generation: actions, composites, wizards, shortcuts, dashboard

The generator engine is now fully full-stack. Both `make:modules-from-db` and
`make:ux-from-blueprint` now produce output for all three targets: **BACKEND**,
**FRONTEND**, and **MOBILE_APP**.

#### `make:modules-from-db` — mobile action modals

When an action has `hasUI: true`, a `{Module}{Action}Modal.vue` is now generated
inside `MOBILE_APP/resources/js/src/pages/modules/{group}/{Module}/Components/`
alongside the existing frontend modal/page component.

New class: `Generators\MobileApp\Components\Actions\ActionModalGenerator`

New stub: `Templates/mobile_app/features/action/modal.stub`

#### `make:ux-from-blueprint` — mobile UX pages and components

All four UX generators now produce a mobile output in addition to the existing
frontend output:

| Generator | Frontend output | Mobile output |
|---|---|---|
| `CompositeGenerator` | `FRONTEND/.../ModuleCreatePage.vue` | `MOBILE_APP/.../ModuleCreatePage.vue` |
| `WizardGenerator` | `FRONTEND/.../wizards/WizardPage.vue` + `routes.ts` | `MOBILE_APP/.../wizards/WizardPage.vue` + `routes.ts` |
| `ShortcutGenerator` | `FRONTEND/.../ModuleShortcuts.vue` + patches `DetailsLayout` | `MOBILE_APP/.../ModuleShortcuts.vue` + patches `DetailsLayout` |
| `DashboardGenerator` | `FRONTEND/.../DashboardQuickActions.vue` | `MOBILE_APP/.../DashboardQuickActions.vue` |

New stubs: `Templates/mobile_app/ux/composite-page.stub`, `wizard-page.stub`,
`shortcuts.stub`, `dashboard-quick-actions.stub`

`MakeUxFromBlueprintCommand` output now groups created/skipped files into
`[Frontend]` and `[Mobile]` sections.

#### Infrastructure

- `PathManager::getMobileUxTemplatePath()` — resolves the mobile UX stub directory;
  overridable via `PathManager::setTemplateRoots(['mobile_ux' => '...'])`
- `BaseUxGenerator::getModuleMobileAppPath()` — resolves mobile module output path
- `BaseUxGenerator::loadMobileStub()` — loads a stub from the mobile UX template directory

---

## v2.1.5 — 2026-05-16

### Fixed — `delegation/tab.stub` and `custom/tab_action.stub`: hard-refresh UUID bug

Both stubs derived the parent record UUID from `props.data?.uuid || props.data?.id || ''`.
Because the parent layout fetches its data asynchronously, `props.data` is an empty object
on the initial render before the first API response arrives. Any hard page refresh would
fire the child component's API endpoint with `undefined` as the UUID
(e.g. `/users/undefined/locations`), returning a 404 or empty result.

Both stubs now import `useRoute` and read the UUID directly from the router params, which
are populated synchronously from the URL regardless of async data:

```typescript
const route = useRoute()
const uuid = computed(() => String(route.params.[[idParam]]))
```

`[[idParam]]` is resolved by the generator to the correct route parameter key for the
module (e.g. `uuid`). The `useRoute` import from `vue-router` is added automatically.

### Changed — `details_layout.stub`: loading state replaced with `CardSkeleton`

The inline loading spinner:
```html
<div v-if="isLoading" class="flex items-center justify-center py-12">
    <component :is="icons.Loader2Icon" class="h-8 w-8 animate-spin text-gray-500" />
    <span class="ml-2 text-gray-600">Loading … details...</span>
</div>
```
is replaced by:
```html
<CardSkeleton v-if="isLoading" :cards="3" :hasHeader="false" />
```
The `CardSkeleton` import is added to the script block. Generated detail layouts now show
the same pulsing skeleton used across all existing hand-authored `*DetailsLayout.vue` files.

### Changed — `details_layout.stub`: tabs navigation strip receives `bg-card`

The tabs wrapper class updated from `tabs-header border-y` to `tabs-header border-y bg-card`.
This matches the treatment applied to all existing hand-authored `*DetailsLayout.vue` files
and ensures newly generated layouts render a visually elevated tab strip in both light and
dark mode.

---

## v2.1.4 — 2026-05-12

### Fixed — list primary cell renders FK relationship name instead of raw ID

`generatePrimaryCellContentFromListFields()` always rendered the raw column key
(e.g. `{{ item.customer_id }}`), ignoring the `data` path already resolved by
`IntrospectionToConfig` (e.g. `customer?.name`).

The primary `<span>` now reads the field's `data` property first and falls back
to the column key only when no `data` path is set. For any FK column used as the
primary list field, the generated template will now emit
`{{ item.customer?.name }}` instead of `{{ item.customer_id }}`.

---

## v2.1.3 — 2026-05-12

### Removed — `registry_business.json` tier

All non-Core modules live under `System/{SubGroup}/{Module}` — the separate
business-registry tier introduced in v2.1.2 was unnecessary complexity.

`RegistryGenerator` now writes only to `registry_core.json` (Core modules) or
`registry.json` (System modules). `updateBusinessRegistry()`,
`removeFromBusinessRegistry()`, and their calls in `generate()` /
`removeFromRegistry()` have been removed.

Consumers must remove `REGISTRY_BUSINESS_FILE` and its `loadFile()` call from
their `Registry::getRegistry()`, reverting to the three-tier merge:
kernel → core → system.

---

## v2.1.2 — 2026-05-12

### Fixed — `RegistryGenerator`: business-module groups silently skipped

`RegistryGenerator::generate()` only wrote to `registry_core.json` (for `Core` groups)
and `registry.json` (for `System` groups). All other groups — Partners, Inventory, Sales,
Finance, HRPayroll, etc. — were silently skipped, leaving those modules unregistered.

**Impact**: `Registry::getRegistry()` merges four files; any module absent from all four
would throw `Module X not found in registry` at runtime when accessed via `ModuleResolver`.

**Fix**: added `updateBusinessRegistry()` / `removeFromBusinessRegistry()` writing to
`registry_business.json` for every group that is neither `Core` nor `System`. The method
is called from `generate()` and `removeFromRegistry()`.

Consumers must also load this new tier in `Registry::getRegistry()`:
```php
return array_merge(
    self::loadFile(self::REGISTRY_KERNEL_FILE),
    self::loadFile(self::REGISTRY_CORE_FILE),
    self::loadFile(self::REGISTRY_BUSINESS_FILE),  // ← add this line
    self::loadFile(self::REGISTRY_FILE)
);
```
Add `const REGISTRY_BUSINESS_FILE = 'registry_business.json';` to your `Registry` class.

---

## v2.1.1 — 2026-05-11

### Fixed — UX stubs: wrong toast import

`composite-page.stub` and `wizard-page.stub` were importing from
`@/components/ui/toast` (shadcn), which does not ship with SYSTEM_SHELL
projects. Both stubs now import `{ toast }` from `@/lib/toast` and call
`toast.success()` / `toast.error()` matching the project's Sonner wrapper.

---

## v2.1.0 — 2026-05-11

### Added — `Generators\Ux` sub-namespace (blueprint-driven UX generators)

A second generation pipeline driven by a **blueprint JSON** rather than a per-module config.
The four generators consume the `composites`, `wizards`, `shortcuts`, and `dashboard` keys
of the blueprint and emit Vue 3 + TypeScript files and Laravel service stubs.

| Class | Emits |
|---|---|
| `CompositeGenerator` | `{Module}CreatePage.vue` (multi-section, embedded CreateForms), `{Module}CompositeCreateService.php` |
| `WizardGenerator` | `{Wizard}WizardPage.vue`, `{Wizard}WizardService.php`, `pages/wizards/routes.ts` |
| `ShortcutGenerator` | `{Module}Shortcuts.vue`, patches `{Module}DetailsLayout.vue` |
| `DashboardGenerator` | `DashboardQuickActions.vue` |

### Added — `Generators\Templates\ux/` stubs

Six stubs bundled with the package and resolved through `PathManager::getUxTemplatePath()`:
`composite-page.stub`, `composite-service.stub`, `wizard-page.stub`,
`wizard-service.stub`, `shortcuts.stub`, `dashboard-quick-actions.stub`.
Projects can override any stub via `PathManager::setTemplateRoots(['ux' => '/path/to/stubs'])`.

### Added — `PathManager::getUxTemplatePath(): string`

Returns the resolved path for UX stubs: the `ux` key from `setTemplateRoots()`
if set, otherwise the bundled `Generators/Templates/ux/` directory.

### Added — `Commands\MakeUxFromBlueprintCommand`

Artisan command `make:ux-from-blueprint {blueprint}`. When invoked from inside a BACKEND
directory, auto-sets `PathManager` project root to `dirname(base_path())` so no manual
bootstrap is required.

### Added — `GeneratorEngineServiceProvider`

Registers `MakeUxFromBlueprintCommand` when running in console. Declared in
`extra.laravel.providers` for auto-discovery. Explicit registration in
`bootstrap/providers.php` is recommended for path-repository installs.

---

## v2.0.0 — 2026-05-10

### BREAKING CHANGE — `BaseGenerator::writeFile()` skips existing files by default

Previously `writeFile()` called `file_put_contents()` unconditionally. As of v2.0.0, if the target file already exists and `$this->force === false`, `writeFile()` returns `false` immediately without writing. Re-running a generator on an already-scaffolded module will skip (not overwrite) existing files, preserving any hand-edits.

**Upgrading:** Any caller that expects overwrite behaviour must call `$gen->setForce(true)` before `$gen->generate()`.

### Added — `BaseGenerator::setForce(bool $force): self`

Fluent setter that bypasses the skip guard when set to `true`. When `force` is `false` (the default), `generate()` returns `false` for any file that already exists on disk.

### Merge-aware generator exemption

The following generators internally set `$this->force = true` in their constructors and are unaffected by the guard — they load-then-merge existing JSON and must always overwrite:

- `RegistryGenerator`
- `MenusJsonGenerator`
- `ModulesJsonGenerator`
- `MobileAppModulesJsonGenerator`

---

## v1.0.0 — 2026-05-09

Initial public release.

- Public API: `PathManager`, `IntrospectionToConfig`, ~70 generators
- Apache 2.0 licensed
- PHP ^8.2, Laravel 11/12 support
