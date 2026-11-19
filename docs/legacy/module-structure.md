::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/module-structure.md`.
:::

::: v-pre

# Module Anatomy

## Overview

A SYSTEM_SHELL **module** is the complete vertical slice for one database table. A single `make:module` run produces:

- a PHP **backend** — controller, model, migration, seeder, factory, and one service class per operation
- a Vue 3 **frontend** — list page, detail layout, create/edit/delete pages, form components, locales, and a route file
- a **`module.json`** config file that persists your choices and drives every subsequent re-generation

The module name is always StudlyCase (e.g. `Statuses`). Everything else — file names, permission keys, API paths, Vue component names, i18n keys — is derived from it consistently and automatically.

```
Database table: statuses
        │
        ▼
module.json (config, persisted)
        │
        ├── BACKEND/app/Project/Modules/Core/Statuses/
        │       Controller · Model · Routes · Migration · Seeder · Services
        │
        └── FRONTEND/src/pages/modules/core/Statuses/
                Pages · Components · Locales · routes.ts
```

::: tip One source of truth
`module.json` is the canonical description of a module. Every generated file is derived from it. When you need to change the shape of a module — add a column, toggle a feature, change a menu icon — edit `module.json` and re-run with `--force`. The generator applies the config; it does not try to reverse-engineer existing files.
:::

---

## `module.json` Reference

`module.json` lives in the backend module directory and is **never overwritten** wholesale by the generator. On re-runs it is merged — but the merge preserves an **explicit, enumerated list** of developer-owned keys, not "any key you added". The preserved set (`ModuleScaffolder::mergePersistedFields()`) is `delegations`, `actions`, `processors`, `constants`, `inline_items`, `relations`, `model_hand_maintained`, `menu_config`, and `morphs[].targets`, plus a handful of nested paths — `features.backend.list.bulk_actions`/`export`/`import` and `features.mobile_app.mode`/`enabled`. A key outside that list is rebuilt from introspection on every run, so do not assume an invented top-level key survives — see [What is hand-editable vs generated](#what-is-hand-editable-vs-generated).

### Top-level fields

| Field | Type | Description |
|---|---|---|
| `id` | `string` | Stable unique identifier for the module. Format: `{table_name}_{timestamp}`. Set on first generation; never changed. |
| `table_name` | `string` | The database table this module operates on (snake_case, plural). |
| `module_name` | `string` | StudlyCase name. Drives all file names, class names, route segments, and permission keys. |
| `id_type` | `"bigint"` \| `"uuid"` \| `"string"` | Detected from the table's primary key. Controls whether URL segments use integer IDs or UUIDs. |
| `module_type` | `string` | The group this module belongs to (e.g. `"Core"`). Determines the directory structure on both backend and frontend. |
| `columns` | `Column[]` | One entry per database column. Drives migration, validation rules, form inputs, and table columns. |
| `features` | `FeaturesConfig` | Which CRUD operations are active for backend, frontend, and mobile — includes the nested `features.backend.list.bulk_actions` array. |
| `menu_config` | `MenuConfig` | Controls whether and where the module appears in the nav menu. |
| `delegations` | `object` (keyed by name) | Related-module tabs/modals embedded in the detail view — hand-authored, never introspected. See [Delegations](/legacy/delegations). |
| `inline_items` | `array` (flat list) | Parent-child rows edited inline in the parent's own form — hand-authored. See [Parent-Child Inline Items](/legacy/inline-items). |
| `actions` | `object` (keyed by name) | Custom state-transition action buttons — hand-authored, distinct from `features.backend.list.bulk_actions`. |
| `morphs` | `array` (flat list) | Polymorphic relationship pairs, auto-detected from `{prefix}_type`/`{prefix}_id` column pairs. The nested `targets[]` is hand-authored and drives a real morph-select picker + `Relation::morphMap()`. See [Morphs](/legacy/morphs). |
| `relations` | `object` (`morphMany` / `hasMany` / `belongsToMany` lists) | Hand-authored model relations introspection cannot derive — most commonly the reverse `morphMany` on a morph target. Emitted by `ModelGenerator::generateManualInverseRelationships()`; preserved across `--force`. See [Morphs → reverse morph relations](/legacy/morphs#reverse-morph-relations-relations-morphmany). |
| `processors` | `array` | Before/after save/delete pipeline hooks — hand-authored. |
| `constants` | `object` (flat `{NAME: value}` map) | Named constant values emitted as `public const` **on the generated model** (`ModelGenerator::generateConstants()`) — use this instead of hardcoding a seeded row's id in code. Also the gate for the splash endpoints: an empty `constants` means no create/edit splash route is registered at all, no matter what `features.backend.createSplash` says. See Coding Conventions → Avoid Hardcoding. |

### `columns[]` entry

```json
{
  "id": "col_name",
  "name": "name",
  "type": "string",
  "length": "100",
  "unique": false,
  "default": null,
  "indexed": false,
  "nullable": false,
  "comment": "Human-readable status name",
  "featureSelections": {
    "backend": { "list": true, "create": true, "edit": true, "view": true, "delete": false },
    "frontend": { "list": true, "create": true, "edit": true, "view": true, "delete": false }
  }
}
```

| Field | Description |
|---|---|
| `id` | Stable identifier for the column entry (prefixed with `col_`). |
| `name` | Database column name (snake_case). |
| `type` | Logical type: `string`, `integer`, `boolean`, `text`, `decimal`, `date`, `datetime`, `uuid`, etc. |
| `length` | Maximum length for string columns. `null` for other types. |
| `unique` | Adds a `UNIQUE` index to the migration and a `unique` validation rule on create. |
| `default` | Default value written into the migration column definition. `null` means no default. |
| `indexed` | Adds a plain index to the migration (useful for FK-like columns that are not declared as FK constraints). |
| `nullable` | If `true`, the migration column is `.nullable()` and the validation rule uses `nullable` rather than `required`. |
| `comment` | Human-readable description stored as a DB column comment. |
| `featureSelections` | Per-operation field visibility — see below. |

#### `featureSelections` — per-column operation visibility

`featureSelections` controls which CRUD operations include this column. It is evaluated separately for `backend` and `frontend`.

```json
"featureSelections": {
  "backend": { "list": true, "create": true, "edit": true, "view": true, "delete": false },
  "frontend": { "list": true, "create": true, "edit": true, "view": true, "delete": false }
}
```

| Key | Effect when `true` |
|---|---|
| `list` | Column appears in the list query's `SELECT`, is returned in list API responses, and renders as a table column on the frontend. |
| `create` | Column is included in create-form validation rules (backend) and the create form input (frontend). |
| `edit` | Column is included in edit-form validation rules (backend) and the edit form input (frontend). |
| `view` | Column is included in the single-record view response (backend) and the detail overview component (frontend). |
| `delete` | Column is included in the delete confirmation payload. Usually `false` for most columns. |

::: tip Selective regeneration
The generator respects `featureSelections` when regenerating files with `--force`. If you enable a previously-disabled feature for a column and re-run, only the affected service/component is updated. Files for disabled features are not touched.
:::

### `features` block

Controls which backend and frontend operations are generated and which API routes are registered.

```json
"features": {
  "backend": {
    "list": true, "create": true, "view": true, "edit": true, "delete": true,
    "createSplash": true, "editSplash": true
  },
  "frontend": {
    "list": true, "create": true, "view": true, "edit": true, "delete": true
  },
  "mobile_app": { "mode": "online", "enabled": false }
}
```

`createSplash` and `editSplash` are distinct from `create` and `edit` — they control whether the reference-data pre-fetch endpoints are generated. They are **not sufficient on their own**: the splash route is only registered when the top-level `constants` key is also non-empty (`RoutesGenerator` skips both splash features outright when `constants` is empty), and the generated form only calls the endpoint when both conditions hold. See [Splash endpoints](#splash-endpoints) for details.

`features.frontend.create` (and its `features.frontend.edit` twin) also accept nested keys the block above omits for brevity: `drafts` (defaults to `true` — generates draft autosave plus a `DraftRestoreBanner` into the form), `wizard` (`{ enabled, steps }` — splits the form into steps), and `confirm_step` (`{ enabled }` — a final Review & Confirm step; defaults ON for wizards, OFF for flat forms).

### `menu_config` block

```json
"menu_config": {
  "enabled": true,
  "section": "main",
  "items": [
    {
      "id": "statuses-list",
      "title": "Statuses",
      "icon": "TagIcon",
      "url": "statuses/list",
      "permission": "Statuses.list",
      "order": 100
    }
  ]
}
```

| Field | Description |
|---|---|
| `enabled` | If `false`, the module is excluded from `menus.json` and no nav item is rendered. |
| `section` | The nav section to append to. Typically `"main"`. |
| `items[].icon` | Icon component name used by the frontend nav renderer. |
| `items[].permission` | The menu item is hidden from users who lack this permission. |
| `items[].order` | Sort order within the section. Lower numbers appear first. |

---

## Backend File Tree

```
BACKEND/app/Project/Modules/Core/Statuses/
├── module.json                             # Config — preserved on every re-run
├── StatusesController.php                  # Thin HTTP layer — delegates to services
├── StatusesModel.php                       # Eloquent model
├── StatusesFactory.php                     # Laravel model factory for testing/seeding
├── Routes/
│   └── api.php                             # Module route definitions
├── Migrations/
│   └── 2025_06_18_071855_create_statuses_table.php
├── Seeders/
│   ├── StatusesSeeder.php                  # Seeder class
│   └── StatusesSeederData.json             # Default seed records
└── Services/
    ├── StatusesListService.php             # Paginated index with filters
    ├── StatusesCreateService.php           # Validation + insert
    ├── StatusesCreateSplashService.php     # Reference data for create form
    ├── StatusesEditService.php             # Validation + update
    ├── StatusesEditSplashService.php       # Reference data for edit form (not the record)
    ├── StatusesDeleteService.php           # Soft or hard delete
    ├── StatusesDeleteCheckService.php      # Pre-delete FK constraint check
    ├── StatusesViewService.php             # Single-record fetch
    └── StatusesActivityListService.php     # Activity log entries for history tab
```

::: warning `module.json` is never overwritten
All other files in this tree are regenerated by `--force`. `module.json` is always merged. Never delete it — it is the only persistent record of your customisations.
:::

---

## Frontend File Tree

```
FRONTEND/src/pages/modules/core/Statuses/
├── routes.ts                               # Vue Router route definitions for this module
├── locales/
│   ├── en.json                             # { "statuses": { "title": "Statuses", ... } }
│   └── sw.json                             # Swahili translations
├── StatusesListPage.vue                    # Route-level page — wraps List component in MainLayout
├── StatusesCreatePage.vue                  # Route-level page — wraps CreateForm
├── StatusesEditPage.vue                    # Route-level page — wraps EditForm
├── StatusesDeletePage.vue                  # Route-level page — wraps DeleteForm
├── StatusesDetailsLayout.vue              # Tab container (Overview · History · Delegation tabs)
├── StatusesDetailsOverviewPage.vue        # Overview tab content
├── StatusesDetailsHistoryPage.vue         # Activity history tab
└── Components/
    ├── StatusesList.vue                    # Orphaned — see tip below, not part of current output
    ├── StatusesCreateForm.vue              # Controlled create form
    ├── StatusesEditForm.vue                # Controlled edit form (pre-populated)
    ├── StatusesDeleteForm.vue              # Delete confirmation UI
    └── StatusesViewModal.vue               # Read-only record detail display, opened as a modal
```

::: tip Component naming: `{Module}ViewModal.vue`, not `{Module}View.vue`
The generator's `ViewModalGenerator` class always writes this file as `{Module}ViewModal.vue` — confirmed against every currently-generated module in this codebase (`LocationTypesViewModal.vue`, `UsersViewModal.vue`, `RolesViewModal.vue`, and 9 others). A stray `StatusesView.vue` also exists on disk in this one module, but nothing imports it — not `routes.ts`, not `StatusesListPage.vue` — it's a stale, orphaned file from an earlier convention. Use `{Module}ViewModal.vue` as the name; see [CreateForm, EditForm, DeleteForm, and ViewModal](#createform-editform-deleteform-and-viewmodal) below for its full worked example.
:::

::: tip `StatusesList.vue` is also an orphan (and the generator that wrote it no longer exists)
Same story as `StatusesView.vue` above: nothing imports `Components/StatusesList.vue` — `StatusesListPage.vue` uses `ListPanel` directly (see [ListPage → ListPanel relationship](#listpage-→-listpanel-relationship)). This file used to be regenerated dead weight on every `--force` run (written by `ListComponentGenerator`, driven by `component.stub`, imported by nothing) — as of generator-engine v2.29.0, `ListComponentGenerator` (both the backend and mobile-app classes) and its stub were deleted outright, confirmed zero instantiation sites. A module regenerated against v2.29.0+ no longer gets a new `Components/{Module}List.vue` at all; `StatusesList.vue` here is a leftover from before that removal. Delete it (or leave it — nothing references it either way) rather than treating it as something to keep in sync.
:::

::: tip Page vs Component distinction
**Pages** (`*Page.vue`) are router-mounted and own layout, breadcrumbs, and the title. They are thin — they wrap a **Component** (`Components/*.vue`) and provide context. All real rendering logic lives in Components. This separation makes components reusable inside delegation tabs without duplicating layout.
:::

---

## Backend Patterns

### Controller — thin HTTP delegator

The controller has one responsibility: translate an HTTP request into a service call and return the service's response as JSON. No business logic lives in the controller.

```php
class StatusesController extends Controller
{
    use HasActivityHistory;
    protected string $activityServiceClass = StatusesActivityListService::class;

    public function listStatuses(Request $request): JsonResponse
    {
        $result = StatusesListService::execute($request->all());
        return response()->json($result, $result['code']);
    }

    public function createStatuses(Request $request): JsonResponse
    {
        $result = StatusesCreateService::execute($request->all());
        return response()->json($result, $result['code']);
    }
}
```

Key points:

- Every action is one line: call the service, return the result.
- The `HasActivityHistory` trait wires `GET /{uuid}/activity` to the activity service declared in `$activityServiceClass`. No manual `activityHistory` method is needed.
- The controller never catches exceptions — that is the service's job.

### Routes — auth + permission middleware, UUID identifiers

```php
// All routes require authentication via Sanctum.
// Each action requires its specific named permission.

Route::middleware(['auth:sanctum', 'permission:Statuses.list'])
    ->get('/statuses/list', [StatusesController::class, 'listStatuses']);

Route::middleware(['auth:sanctum', 'permission:Statuses.view'])
    ->get('/statuses/{uuid}/view', [StatusesController::class, 'viewStatuses']);

Route::middleware(['auth:sanctum', 'permission:Statuses.edit'])
    ->get('/statuses/edit/splash', [StatusesController::class, 'editSplash']);

Route::middleware(['auth:sanctum', 'permission:Statuses.edit'])
    ->put('/statuses/{uuid}/edit', [StatusesController::class, 'editStatuses']);

// Note: the activity-history route carries auth:sanctum ONLY — no permission middleware.
Route::middleware(['auth:sanctum'])
    ->get('/statuses/{uuid}/activity', [StatusesController::class, 'activityHistory']);
```

Rules enforced on every route:

1. `auth:sanctum` — always present. Unauthenticated requests are rejected before reaching the controller.
2. `permission:{ModuleName}.{action}` — the exact permission key that must be granted to the authenticated user. **Not universal**: `RoutesGenerator` emits the activity-history route with `auth:sanctum` alone, so any authenticated user can read a record's activity log without holding `{ModuleName}.view`. If you are auditing access control, treat that route as authenticated-only, and add the middleware yourself if your project needs it gated.
3. **UUIDs in URLs** — record identifiers in path segments are always `{uuid}`, never raw integer IDs, regardless of `id_type`. This applies to view, edit, delete, activity, and delete-check routes.

::: tip Permission key format
Permission keys follow the `{ModuleName}.{action}` convention. The full set for any module is: `list`, `view`, `create`, `edit`, `delete`, `restore`, `bulkAction`. They are registered automatically on first generation and appear in the permissions management UI without any manual seed steps.
:::

### Service — execute / validateData / process trinity

Every non-list service follows the same three-method structure:

```php
class StatusesCreateService
{
    public static function execute(array $data, array $params = []): array
    {
        DB::beginTransaction();
        try {
            $validated = self::validateData($data);
            $result    = self::process($validated);
            DB::commit();
            return Helpers::success($result, 'Status created successfully', 201);
        } catch (ApplicationException $e) {
            DB::rollBack();
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Exception $e) {
            DB::rollBack();
            return Helpers::error($e->getMessage(), 500);
        }
    }

    protected static function validateData(array $data): array
    {
        $rules = ['name' => 'required|string|max:100', ...];
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ApplicationException('Validation failed', $validator->errors()->toArray());
        }
        return $validator->validated();
    }

    protected static function process(array $validated): array
    {
        $validated['created_by_id'] = Auth::id();
        $record = StatusesModel::create($validated);
        $record->load('creator', 'updater');
        return $record->toArray();
    }
}
```

| Method | Responsibility |
|---|---|
| `execute()` | Entry point. Owns the transaction boundary. Catches `ApplicationException` (user-facing 422) and generic `\Exception` (server 500). Never re-throws. |
| `validateData()` | Declares all validation rules. Throws `ApplicationException` on failure — never returns false or null. Returns `$validator->validated()`, which contains **only declared keys**, ensuring untrusted fields never reach the model. |
| `process()` | Pure business logic. Receives only validated data. Performs DB writes, loads eager relations, and returns an array. |

::: warning Mass-assignment safety
`StatusesModel` uses `guarded = []` — the mass-assignment guard is removed at the model layer. The service layer is the only guard: `validateData()` always returns `$validator->validated()`, which whitelists fields. Never pass raw `$request->all()` or `$data` directly to `Model::create()` or `$model->update()`. Always pass the return value of `validateData()`.

This applies to every generated module, not just `Statuses` — see Coding Conventions → Mass Assignment for the full write-up with a correct/incorrect example.
:::

### Edit, Delete, View, and Activity services

The remaining operation services each adapt the Create trinity above to their own shape. The examples below are the real, generated `LocationTypes` services (`BACKEND/app/Project/Modules/Core/Locations/LocationTypes/Services/`) — a different reference module from the `Statuses` examples elsewhere on this page, chosen because its services are close to the plain generated template with minimal hand-editing.

#### `{Module}EditService` — validate params + data, protect immutable fields, update

```php
class LocationTypesEditService
{
    public static function execute(array $data, array $params)
    {
        DB::beginTransaction();
        try {
            $validParams = self::validateParams($params);
            $model = LocationTypesModel::query()->where(['uuid' => $validParams['uuid']])->firstOrFail();
            $validData = self::validateData($data, $model);
            self::protectImmutableFields($validData, $model);
            $result = self::process($validData, $validParams, $model);
            DB::commit();
            return $result;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return Helpers::error(__('messages.not_found', ['resource' => 'Record']), 404);
        } catch (ApplicationException $e) {
            DB::rollBack();
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected static function validateData(array $data, $model): array { /* 'unique:location_types,name,{$model->id}' — excludes the record being edited */ }
    protected static function validateParams(array $params): array { /* 'uuid' => 'required|string|exists:location_types,uuid' */ }
    public static function process(array $validData, array $validParams, LocationTypesModel $model): array { /* beforeUpdate() -> $model->update() -> afterUpdate() */ }
    public static function beforeUpdate($validData, array $validParams, LocationTypesModel $model) { /* stamps updated_by_id = Auth::id() */ }
    public static function afterUpdate($validData, array $validParams, LocationTypesModel $model): LocationTypesModel { /* hook, returns $model unchanged by default */ }
    protected static function protectImmutableFields(array &$validated, LocationTypesModel $record): void { /* throws ApplicationException if id/uuid/created_at/created_by_id are being changed; unsets all four regardless */ }
}
```

Differences from `{Module}CreateService` worth noting:

- `execute()` takes **two** arrays — `$data` (the form body) and `$params` (the route params, i.e. `{uuid}`) — where Create takes only `$data`.
- `validateData()` receives the already-loaded `$model` as a second argument, so its `unique` rules can exclude the current record.
- `protectImmutableFields()` runs after validation and throws `ApplicationException` if the submitted payload tries to change `id`, `uuid`, `created_at`, or `created_by_id` — and always strips all four from `$validated` regardless of whether they were actually changed.
- `process()` calls `beforeUpdate()`/`afterUpdate()` hooks around `$model->update()`, the update-lifecycle equivalent of Create's `process()`.

#### `{Module}EditSplashService` — same contract as CreateSplash, different endpoint

```php
class LocationTypesEditSplashService
{
    public static function execute(array $data = [])
    public static function process(array $data = []): array
}
```

Identical shape and purpose to `{Module}CreateSplashService` — both return whatever `splashData` sources are configured in `module.json` (see [Splash endpoints](#splash-endpoints) below). `LocationTypes` has none configured, so `process()` returns an empty `data: {}` success response. The only functional difference from the create-splash service is which route calls it (`GET /location-types/edit/splash` vs `GET /location-types/create/splash`) — the generated code itself comes from the same template.

#### `{Module}DeleteService` — pre-delete guard hook + delete

```php
class LocationTypesDeleteService
{
    public static function execute(array $data, array $params)
    protected static function validateParams(array $params): array
    public static function process(array $data, array $validParams, LocationTypesModel $model): array
    public static function beforeDelete($data, array $validParams, LocationTypesModel $model)
    public static function afterDelete($data, array $validParams, LocationTypesModel $model)
}
```

The generated `beforeDelete()`/`afterDelete()` hooks are empty (`//Before Delete` / `//After Delete`, plus a placeholder for `processors` injection) — `process()` calls `beforeDelete()`, then `$model->delete()`, then `afterDelete()`. `LocationTypesDeleteService.php`'s `beforeDelete()` has been hand-extended past the generated stub to add a real FK guard:

```php
public static function beforeDelete($data, array $validParams, LocationTypesModel $model) {
    if ($model->locations()->exists()) {
        throw new ApplicationException(
            'Cannot delete location type. It is assigned to ' . $model->locations()->count() . ' location(s). ' .
            'Please reassign or delete locations first.',
            ['field' => 'location_type_id', 'code' => 'IN_USE']
        );
    }
    return $data;
}
```

This is the pattern for any module that needs a delete-blocking rule beyond what `{Module}DeleteCheckService` (below) reports to the frontend ahead of time — throwing `ApplicationException` from `beforeDelete()` is what actually stops the deletion at the database layer; `DeleteCheckService` only informs the UI in advance so the user doesn't hit this error after already confirming.

#### `{Module}DeleteCheckService` — read-only pre-flight for the delete confirmation UI

```php
class LocationTypesDeleteCheckService
{
    public static function execute(array $params): array
    protected static function getActiveRelationships(LocationTypesModel $locationType): array
}
```

Powers `GET /location-types/{uuid}/delete/check`, called by `DeleteForm.vue` before it will show a delete button (see [`DeleteForm.vue`](#createform-editform-deleteform-and-viewmodal) below). Returns `{ record, relationships: [{name, count, label, message}], can_delete: bool }` in the generated template — `getActiveRelationships()` starts as a commented-out example and is hand-filled per module. `LocationTypesDeleteCheckService.php` deviates from the generated template's default `'record'` response key, using a more specific `'location_type'` key instead (matched on the frontend by `LocationTypesDeleteForm.vue`'s `record.value = response.data.location_type`) — a reminder that the key name is whatever the hand-filled implementation chooses, not a fixed contract.

An `inline_items` child is **not** counted (engine v3.5.31+): the parent's `DeleteService` cascade-deletes those rows, so a parent with items reports `can_delete: true`, and the generated file carries a comment naming the skipped child. Another foreign key from the same child module, or any other module, still counts. The generated template no longer includes a `getUpdatedRecordsCount()` stub (it returned `0` and nothing called it); a module that needs one defines its own.

`relationships` here must be kept in sync **by hand** with `DeleteService::beforeDelete()`'s actual guard — the generator does not derive one check from the other; `LocationTypesDeleteCheckService`'s `locations` count check and `LocationTypesDeleteService`'s `locations()->exists()` guard were written to match, but nothing enforces that they stay that way.

The generated dependent counts (`getCreatedRecordsCount()`) are written only when **that** module is
generated — adding a referring module later never updated them on its own until this project's own
`App\Project\Commands\DeleteCheckRefresher`: after `make:module`/`make:modules-from-db` generates
a module, every OTHER module its table now references gets its delete check refreshed automatically,
but only when the file on disk is still generator-shaped; a hand-edited one is never touched, only
warned about, naming the exact `--force --only=DeleteCheckService` command to regenerate it
deliberately. `php artisan modules:refresh-delete-checks [--dry-run]` is the project-wide catch-up
sweep for everything neither per-run refresh reaches.

#### `{Module}ViewService` — single-record fetch, `withTrashed()`

```php
class LocationTypesViewService
{
    protected static array $eagerLoadRelationships = ["creator", "updater"];

    public static function execute(array $params)
    protected static function validateParams(array $params): array
    public static function afterFetch(array $validParams, LocationTypesModel $model): array
    public static function process(array $validParams, LocationTypesModel $model): array
}
```

Notably queries `LocationTypesModel::withTrashed()->where(['uuid' => ...])` — a soft-deleted record can still be viewed (e.g. from an activity-log entry, or a related record's FK display), just not listed. `afterFetch()` is a separate eager-loading hook ahead of `process()`, so a module can override just the loading step without re-implementing the whole response envelope.

#### `{Module}ActivityListService` — thin `BaseActivityListService` subclass

```php
class LocationTypesActivityListService extends BaseActivityListService
{
    protected function getModelClass(): string
    {
        return LocationTypesModel::class;
    }
}
```

The entire generated file is this one method — all activity-log query logic (pagination, formatting the change diff, etc.) lives in the shared `BaseActivityListService` base class (`BACKEND/app/Project/_Src/`). The per-module subclass exists only to point it at the right model. It is wired into the controller automatically via the `HasActivityHistory` trait shown above — no manual route or controller method is written for it.

### List service — `ListServiceTrait`

List services declare the query shape and delegate all mechanics to `ListServiceTrait` (`BACKEND/app/Project/_Src/ListServiceTrait.php`):

```php
class StatusesListService
{
    use ListServiceTrait;

    // 'id', 'uuid', and 'created_at' at the end are auto-appended by the generator
    // on every module regardless of config — see "Filter fields" below. 'name',
    // 'code', 'module', 'is_default' are this module's own hand-configured
    // filterable columns.
    protected static array $filterableFields        = ['name', 'code', 'module', 'is_default', 'id', 'uuid', 'created_at'];
    protected static array $sortableFields          = ['name', 'code', 'module', 'sort_order', 'created_at'];
    protected static array $filterableRelationships = [[]];
    protected static array $eagerLoadRelationships  = ['creator', 'updater'];

    public static function execute(array $data, bool $export = false): array
    {
        $validData = self::validateData($data);
        if ($export) return self::export($validData);
        return self::process($validData);
    }

    protected static function process(array $data): array
    {
        $query = StatusesModel::query();
        $data  = self::processListQuery($data, $query);
        $data['filterFields'] = [
            ['key' => 'name',       'label' => 'Name',       'type' => 'text'],
            ['key' => 'is_default', 'label' => 'Is Default', 'type' => 'select'],
            ['key' => 'id',         'label' => 'ID',         'type' => 'text'],
            ['key' => 'uuid',       'label' => 'UUID',       'type' => 'text'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'date'],
        ];
        return Helpers::success($data, 'Records fetched successfully');
    }
}
```

#### `processListQuery()` — the trait's main entry point

Every list service calls `self::processListQuery($data, $query)` once, inside its own `process()`. It, in order:

1. **Eager loads** every relation in `$eagerLoadRelationships` via `$query->with(...)`.
2. **Applies filters** — `applyFilters($query, $data['filters'], $filterableFields)` walks each `{operator, value}` entry, skips anything whose operator isn't in the trait's `$operators` map or whose column isn't in `$filterableFields`, then dispatches to `applyFilter()` for a plain column, `applyRelationshipFilter()` for a dot-notation column that resolves to a real Eloquent relationship (e.g. `creator.name`), or `applyExternalTableFilter()` for a dot-notation column that is a raw `table.column`/`database.table.column` reference — the caller is responsible for having already joined that table.
3. **Applies `$defaultListFilters`** (see below) — only for columns the user did **not** explicitly filter.
4. **Applies location filtering** — delegates to `LocationContextService::applyLocationFiltering()`, scoping the query to the authenticated user's accessible locations.
5. **Sorts** — reads `params.sort`/`params.order` from the request, falling back to `created_at`/`desc` whenever the requested column isn't in `$sortableFields`. The raw request value is never trusted directly — this is what prevents arbitrary `ORDER BY` injection.
6. **Paginates** — `per_page` is capped at 100; `params[paginate]=false` (or `'false'`/`'0'`) returns every matching row, unpaginated, in the same response envelope.

Location filtering above only ever reached list/export queries. Single-record fetches — view, edit,
delete, delete-check, a uuid-taking action, the shared activity-history endpoint, and bulk
`mode=ids` — go through `LocationContextService::applyRecordScope()` instead, whenever the fetched
model declares `protected static bool $locationBearing = true;` (see `BaseModel::$locationBearing`).
The scope uses the same consolidated location set (assigned locations plus all descendants) and the
same NULL-location rule as the sibling `{Module}ListService::$locationScopeIncludesNull` flag, so a
record behaves exactly like the list it's paired with — just for one row instead of many. An
out-of-scope uuid is "not found", identically to a genuinely missing one, never a 403. A
hand-written endpoint that already has the record loaded (rather than building its fetch through
`applyRecordScope()` directly) should call `Model::applyRecordScope(Model::query())` before its own
`where('uuid', ...)`, or guard with `LocationContextService::canAccessRecord($record)` inside a
`catch (\Exception)` block — `abort()` there would otherwise surface as a 500, not a 404.

A `location_id` write on a location-bearing module is rejected (422) unless the value is one of the
acting user's own accessible locations (`App\Project\_Src\Rules\AccessibleLocation`, class-exists-
guarded so an app on an older engine sees byte-identical validation rules). A delegation's parent
segment is scoped the same way a native fetch is, so a foreign parent 404s before any child row is
ever touched. `/select/{module}` pickers (including the `id[]=` force-include path) never surface a
row outside the acting user's locations. Edit/delete on a location-bearing module return 422, not
404, for both "doesn't exist" and "exists, not yours" — the uuid's own `exists:` rule already
confirms the row exists somewhere in the table by the time that check runs, so a 404 there would
otherwise let a caller tell the two cases apart.

**Opting a module out.** A table with a `location_id` column must declare itself one way or the
other (`LocationBearingDeclarationTest`): `$locationBearing = true` for scoped, or
`"location_bearing": false` in `module.json` when the column records where a row happened without
restricting who may see it. The list filter used to key on the column alone and ignore that
declaration, so an opted-out module's list stayed scoped while its by-uuid view did not. A module
that declares `false` **and** has the column now gets
`protected static bool $locationScopeDisabled = true;` in its `{Module}ListService`, and
`ListServiceTrait::applyLocationFiltering()` returns early when it is set (regenerate the list
service to pick it up). It is opt-in per service on purpose: making the trait treat every model whose
flag is `false` as unscoped would silently unscope any older module that never declared anything.

The switcher's selection reaches this filter as `location_filter` (a location id, plus
`include_descendants=0` to exclude its children) and the permission gate as `X-Location-Id` — see
`useServerTable`. Both are checked against generated modules
by the `super-suite` fixture's `LocationScopeIsolationTest`.

#### Other methods on the trait

| Method | Responsibility |
|---|---|
| `applyFilter()` | Applies one `{operator, value}` pair to a plain column via a `switch` over the 14 operators. See [Filter fields](#filter-fields) below for the whole-day date-expansion behaviour of the `eq`/`neq`/`lte`/`between` branches. |
| `applyRelationshipFilter()` / `applyRelationshipSorting()` | Resolves a dot-notation `relation.column` filter/sort into a `whereHas()` closure or a `leftJoin()`, distinguishing `BelongsTo` from other relationship types to build the correct join condition — including an aliased join for self-referential relations (parent table === related table). |
| `applyExternalTableFilter()` / `applyExternalTableSorting()` | Same dot-notation syntax, but for a `table.column` or `database.table.column` reference that is **not** a declared Eloquent relationship. The caller must have already joined the table itself. |
| `applyDefaultListFilters()` | Applies a `protected static array $defaultListFilters = ['column' => ['operator' => ..., 'value' => ...]]` declared on the ListService — but only for a column the user did **not** already supply a filter for (user-supplied filters always win; see the method's own docblock for the full "user-override-wins" contract). |
| `exportData()` / `exportToCsv()` / `exportToExcel()` / `exportToPdf()` | Re-runs `processListQuery()` unpaginated, then streams a CSV/XLSX/PDF file download. Wired up by the service's own `export()` method (see the `StatusesListService` example above, whose `execute($data, bool $export)` branches into it). |
| `downloadImportTemplate()` / `importData()` | Generate a blank import template and process an uploaded CSV/XLSX file row-by-row via a `processImportRow()` hook the ListService itself defines. `importData()` supports `dry_run=1`, which runs the full validation/processing pipeline inside a transaction that is always rolled back, never committed. |
| `processBulkAction()` | Dispatches a `{action, mode, ids\|filter}` bulk-action request — see ListTable's Bulk-action endpoint for the request/response shape. `mode: 'ids'` operates on an explicit id list (the default — every row-checkbox selection); `mode: 'filter'` re-applies the list's own filters server-side (via a caller-supplied base query) and is what `ListTable`'s own "select all N matching the filter" banner sends (since v2.29.0 — see the linked section). Requires a `protected static array $bulkActions = ['activate', ...]` declaration on the ListService; each key dispatches to `{Module}{ActionPascal}Service::execute()` — e.g. `'activate'` → `StatusesActivateService`. There is no dedicated `*BulkActionService.php` file: dispatch happens entirely inside the trait, through one shared `/bulk-action` route per module. |

The service's own job stays unchanged:

1. Provide the static arrays that configure the trait's behaviour (`$filterableFields`, `$sortableFields`, `$filterableRelationships`, `$eagerLoadRelationships`, and optionally `$defaultListFilters`/`$bulkActions`/`$importColumns`).
2. Build the base `Eloquent\Builder` query object (`StatusesModel::query()`).
3. Append `filterFields` to the response so the frontend knows what filter controls to render.

### Splash endpoints

A **splash** endpoint is a `GET` route that returns the reference data needed to render a form — dropdown options, related record lists, default values — without the record itself.

| Endpoint | Returns |
|---|---|
| `GET /statuses/create/splash` | All reference data required to render the create form (e.g. foreign-key options). |
| `GET /statuses/edit/splash` | The same reference data for the edit form. **Does not return the record being edited.** |

The record for an edit form is always loaded separately via `GET /statuses/{uuid}/view`. This separation means the frontend can:

- show the form skeleton immediately (from splash data),
- then populate field values (from view data),
- without a single large response that mixes both concerns.

::: danger Splash is opt-in on BOTH sides — the frontend does not always call it
The splash endpoint is generated **only when both** of these hold:

1. the top-level `constants` key in `module.json` is non-empty, **and**
2. `features.backend.createSplash` / `editSplash` is set.

`RoutesGenerator` skips both splash features outright when `constants` is empty, and `CreateFormGenerator`/`EditFormGenerator` mirror the same gate exactly — `$hasSplash = !empty($config['constants']) && !empty($config['features']['backend']['createSplash'])`. When `$hasSplash` is false the generated form's `refreshAndSet()` is a no-op and no HTTP call is made at all.

This matters because the two sides must agree. An earlier version of this box claimed "the frontend always calls the splash endpoint regardless"; that is false, and believing it produced a real production bug (generator-engine v3.4.7) — every Create/Edit form mount fired a request to a route that was never registered, 404ing silently behind a toast. If you enable `createSplash`/`editSplash`, declare `constants` too, and regenerate both backend and frontend together.
:::

---

## Frontend Patterns

### ListPage → ListPanel relationship

::: warning Superseded pattern below this line was removed
This section used to describe a `{Module}ListPage.vue` → `Components/{Module}List.vue` wrapper → `ListPageBareTable` split. That wrapper component doesn't exist in current generated output, and `ListPageBareTable` has zero live consumers anywhere in the app — see ListTable for the full writeup.
:::

`{Module}ListPage.vue` is router-mounted and embeds `ListPanel`
(`FRONTEND/src/components/list-panel/ListPanel.vue`, hand-maintained, never
generated) directly — `MainLayout`, breadcrumbs, page title, columns, and
cell slots all live in the one file. `ListPanel` owns the whole CRUD surface:
the table, filters, sort, pager, and the create/edit/view/delete dialogs
(each rendering the module's own native `{Module}CreateForm.vue` /
`EditForm.vue` / `DeleteForm.vue` / `ViewModal.vue`, passed in as props):

```vue
<MainLayout :title="$t('statuses.title')" :breadcrumbs="[...]">
    <ListPanel
        ref="panelRef"
        endpoint="/statuses/list"
        :columns="columns"
        mode="history"
        :create-component="StatusesCreateForm"
        :edit-component="StatusesEditForm"
        :delete-component="StatusesDeleteForm"
        :view-component="StatusesViewModal"
        :create-permission="'Statuses.create'"
        :view-permission="'Statuses.view'"
        test-id-prefix="statuses"
        :enable-export="false"
        :enable-bulk-actions="false"
        :bulk-actions="bulkActions"
        :enable-import="false"
        :bulk-action-permission="'Statuses.bulkAction'"
        :import-permission="'Statuses.import'"
    >
        <!-- custom cell renderers for badge/boolean/FK columns, if any -->
    </ListPanel>
</MainLayout>
```

The same component serves a delegation's tab: a delegation tab is its own
generated component with its own `ListPanel` instance (`mode="ajax"`) pointed
at the delegation's nested endpoint (`/{parent}/{uuid}/{delegation}/list`) —
which is exactly what makes create/edit/view/delete resolve to the related
module's own native forms whether reached standalone or through a parent's
tab (see [Delegations](/legacy/delegations)). Before generator-engine v3.5.22 the tab
imported the older `CrudListPanel` from `@/components/list-table` instead; the
two share one prop/slot/emit contract.

`filterFields` is not passed explicitly in the generated stub — `ListPanel`
picks it up from the list endpoint's own response automatically (see
[Filter fields](#filter-fields) below). `enableExport`/`enableBulkActions`/
`enableImport` and the matching permission props ARE always computed and passed
by the generator (literal `false`/an empty `bulkActions` array when the
corresponding `features.backend.list.{export,bulk_actions,import}` config key
isn't set, not omitted) — see [Features Config › Frontend wiring for bulk
actions, export, and import](https://joelnjoshkibona.github.io/generator-engine/features-config#frontend-wiring-for-bulk-actions-export-and-import).
See List Pages (ListPanel) for the complete prop,
slot, emit and endpoint-contract reference.

### Column definition

Generated columns use `ReportColumn` (`FRONTEND/src/components/report-table`), not the older `Column` type:

```typescript
const columns = computed<ReportColumn[]>(() => [
    { key: "name",  label: "Name",  sortable: true, fixed: true, width: 240 },
    { key: "color", label: "Color", sortable: true, width: 150 },
])
```

| Property | Description |
|---|---|
| `key` | Unique identifier for the column. Used as the slot name (`#cell-{key}`) for custom cell rendering. |
| `label` | Column header text. |
| `sortable` | If `true`, clicking the header sends a sort request to the API. Must match a field in the service's `$sortableFields`. |
| `fixed` | Pins the column to the left so it stays visible while scrolling horizontally. Requires `width`. |
| `width` | Pixel width. |
| `align` | `'left' \| 'right' \| 'center'`. |
| `cellClass` / `headerClass` | Extra CSS classes on body cells / the header cell, respectively — replaces the old single `class` prop. |

There's no `data` field for reading a differently-named API key, and no `primaryHiddenClass` for suppressing a column from the mobile card view — use a `cell-{key}` slot for value transforms, and control card-view visibility with `defaultVisible`/`fixed` instead. See ListTable — Column Interface for the full type and the responsive-behaviour details.

### Filter fields

The list API response includes a `filterFields` array alongside the paginated records. `ListTable` reads it automatically — there's no `@data-loaded` handler to wire up for this (that event still fires, but filter fields no longer depend on a page catching it):

```typescript
// ListTable already does this internally — shown for reference only:
const effectiveFilterFields = props.filterFields.length ? props.filterFields : serverFilterFields.value
```

Each `filterFields` entry has a `key`, `label`, and `type` — one of `text`, `number`, `select`, `multi-select`, `select_paginated`, or `date`. See ListTable's FilterField Interface for the full field reference and Query string reference for the operator restrictions and defaults each type gets. This means the filter UI is always in sync with what the backend actually supports — there is no separate frontend filter configuration to maintain.

#### `id`, `uuid`, and `created_at` are always filterable

As of generator-engine 2026-08-05, `BaseServiceGenerator` (`packages/generator-engine/src/Generators/Backend/Services/BaseServiceGenerator.php`) always appends these three fields, regardless of what `module.json` configures:

- **Backend** — `generateFilterableFields()` appends `id`, `uuid`, and `created_at` to the generated `$filterableFields` allow-list (deduplicated against whatever the module's own config already declared, via a shared `appendSystemFields()` helper). This is why the `LocationTypesListService` example above ends in `'id', 'uuid', 'created_at'` even though `module.json` only configured `name`, `code`, `module`, `is_default`.
- **Frontend** — `generateFilterFields()` appends the matching UI entries to `filterFields` via `appendDefaultFilterField()`: `{key: 'id', label: 'ID', type: 'text'}`, `{key: 'uuid', label: 'UUID', type: 'text'}`, and `{key: 'created_at', label: 'Created At', type: 'date'}`. Each append is skipped if a field with that `key` is already present — a hand-authored `filterFields` entry (or one already derived from `filterableFields`) is never overridden.

`created_at` renders as a `date`-type filter even though the underlying column is a full `datetime` — see the whole-day expansion below for why an exact match still works against it.

::: warning Not retroactive
This only affects modules generated (or re-generated with `--force`) on or after 2026-08-05. `LocationTypesListService.php` (generated December 2025, before this change) has `id`/`uuid` in its backend `$filterableFields` from an earlier, narrower version of this same auto-append logic, but its frontend `filterFields` array stops at `id` — no `uuid` or `created_at` entry at all. Re-run `make:module ... --force` to pick up the current behaviour on an existing module.
:::

#### Whole-day expansion for bare date filters

A native `<input type="date">` (what the `date` filter type renders) only ever sends a bare `"YYYY-MM-DD"` string — never a time component. Comparing that directly against a `datetime`/`timestamp` column (e.g. `created_at`) with `=` would almost never match, since the stored value always carries a time-of-day. `ListServiceTrait::isBareDateValue()` detects this shape (`preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)`), and `applyFilter()`/`applyFilterOnQualifiedColumn()` expand it into a whole-day range using `startOfDay()` (`"{$date} 00:00:00"`) and `endOfDay()` (`"{$date} 23:59:59"`) for exactly these operators:

| Operator | Behaviour when the value is a bare date |
|---|---|
| `eq` | `whereBetween($column, [startOfDay, endOfDay])` instead of `where($column, '=', $value)` |
| `neq` | `where($column, '<', startOfDay)->orWhere($column, '>', endOfDay)` instead of `where($column, '!=', $value)` |
| `lte` | `where($column, '<=', endOfDay)` instead of `where($column, '<=', $value)` |
| `between` | Each bound of the two-value array is independently expanded — `startOfDay` for the first value, `endOfDay` for the second — before `whereBetween()` |

`gt`, `gte`, and `lt` do **not** get this expansion — they run a plain comparison against the bare date string as-is. This applies to both plain-column filtering (`applyFilter()`) and the relationship/external-table-safe qualified-column path (`applyFilterOnQualifiedColumn()`), so the same whole-day behaviour holds whether the datetime column belongs to the module's own table or a joined one.

### Permission-gated actions in `#cell-actions`

Row-level action buttons are placed in the `#cell-actions` slot and guarded by `hasPermission()`:

```vue
<template #cell-actions="{ item }">
    <router-link
        :to="`/statuses/${item.uuid}/details`"
        v-if="hasPermission('Statuses.view')"
    >
        <Button variant="outline" size="xs">View</Button>
    </router-link>
    <router-link
        :to="`/statuses/${item.uuid}/edit`"
        v-if="hasPermission('Statuses.edit')"
    >
        <Button variant="outline" size="xs">Edit</Button>
    </router-link>
</template>
```

`hasPermission` is provided by the `usePermissions` composable. A user who lacks `Statuses.edit` never sees the Edit button — the element is not rendered, not just hidden via CSS.

### CreateForm, EditForm, DeleteForm, and ViewModal

Every generated module ships four `Components/` files that together implement create, edit, delete, and read-only viewing as **modals opened from the list page**, with an escape hatch to a full standalone page for each. The examples below are the real, generated `LocationTypes` components (`FRONTEND/src/pages/modules/core/Locations/LocationTypes/Components/`) — a different reference module from `Statuses` elsewhere on this page; see the note in [Frontend File Tree](#frontend-file-tree) above for why `{Module}ViewModal.vue`, not `{Module}View.vue`, is the correct name to use.

#### The shared `modal` prop convention

`CreateForm`, `EditForm`, and `DeleteForm` all take a `modal: boolean` prop (default `false`) that changes how the component reports success/cancel:

| `modal` | Cancel | Success |
|---|---|---|
| `true` (opened inside an `AppDialog`) | `emit('cancel')` — the parent closes the dialog | `emit('created')` / `emit('updated')` / `emit('deleted')` — the parent closes the dialog and refreshes the list |
| `false` (opened as its own routed page) | `router.push(props.cancelLink)` | same — `router.push(props.cancelLink)` |

`LocationTypesListPage.vue` renders all three as modals:

```vue
<AppDialog v-model:open="createOpen" :title="$t('loc_types.page_create')" size="lg" persistent>
  <LocationTypesCreateForm modal @cancel="createOpen = false" @created="onCreated" />
</AppDialog>
<AppDialog v-model:open="editOpen" :title="$t('loc_types.page_edit')" size="lg" persistent>
  <LocationTypesEditForm v-if="selectedUuid" :uuid="selectedUuid" modal @cancel="editOpen = false" @updated="onUpdated" />
</AppDialog>
<AppDialog v-model:open="deleteOpen" :title="$t('loc_types.page_delete')" size="md">
  <LocationTypesDeleteForm v-if="selectedUuid" :uuid="selectedUuid" modal @cancel="deleteOpen = false" @deleted="onDeleted" />
</AppDialog>
```

The same three components are also mounted, without `modal`, by their own routed `{Module}CreatePage.vue`/`EditPage.vue`/`DeletePage.vue` — reachable directly by URL, and via the "open full" link each modal form renders in its own footer when `modal` is `true`.

#### `CreateForm.vue`

Props (`LocationTypesCreateForm.vue`):

| Prop | Type | Default | Purpose |
|---|---|---|---|
| `modal` | `boolean` | `false` | See above. |
| `cancelLink` | `string` | `/location-types/list` | Where to navigate on cancel/success when `modal` is `false`. |
| `title` | `string` | `'Basic Information'` | Section heading (non-modal layout only). |
| `hiddens` | `object` | `{}` | Field keys to omit from the form entirely — `v-if="!props.hiddens?.['name']"` wraps each field. Used by a delegation-embedded create form to hide the parent FK field. |
| `defaults` | `object` | `{}` | Values merged into `form` on mount — pre-fills fields, e.g. a delegation's <code v-pre>{{parent.uuid}}</code> injection. |
| `disabledFields` | `string[]` | `[]` | Field keys rendered read-only (`isFieldDisabled()` checks membership). |
| `submitUrl` / `splashUrl` | `string \| null` | `null` | Override the create/splash endpoint — falls back to the module's own default (`/location-types/create`, `/location-types/create/splash`) when unset. |
| `successMessage` | `string \| null` | `null` | Override the default toast message. |

Emits: `cancel`, `created` (payload `{ id }`).

**Validation-error surfacing**: each field is the shared `InputField` component (`FRONTEND/src/components/form-fields/InputField.vue`) bound with `:error="errors.name"`. On a 422 response carrying a `response.errors` object, `CreateForm` assigns it wholesale to a local `errors` ref, keyed by field name. `InputField` normalises whatever it receives (a string or a string array — see `toErrorList()` in `@/lib/utils`) into one `<p class="text-destructive">` per message below the input, and adds a red border (`border-red-500`) to the input itself. No field-by-field error wiring is needed beyond passing the one `errors` object down.

**Submit-disabled-during-loading**: `isSubmitting` gates the submit `Button`'s `:disabled` and every field's `:disabled="isSubmitting || isFieldDisabled(...)"`; the button swaps its label/icon to a spinner + "Creating…" while the request is in flight.

**Permission gate**: the whole form is replaced by an access-denied block when `!hasPermission('LocationTypes.create')` — a user without the permission never sees the form's fields rendered, not merely disabled ones.

**Reference-data loading**: `refreshAndSet()` runs `onMounted` and populates a local `splash` ref from the splash endpoint — but only when the module-level `hasSplash` constant is `true`. The generator computes that as `!empty(constants) && !empty(features.backend.createSplash)` — **both**, not either — so a module with an empty `constants` (as with `LocationTypes`) gets `hasSplash = false` and a one-line no-op `refreshAndSet()` (`isLoading.value = false; return`) instead of an HTTP call. This mirrors `RoutesGenerator`'s own gate, which is why the pair stays in sync; see [Splash endpoints](#splash-endpoints).

**Draft autosave**: on by default. Unless `features.frontend.create.drafts` is explicitly `false`, the generated form autosaves in-progress input and renders a `DraftRestoreBanner` offering to restore it on the next mount. `EditForm.vue` has the identical opt-out under `features.frontend.edit.drafts`. Nothing in the prop table above exposes this — it is purely config-driven, so check `module.json` rather than the component if you are wondering where a "restore draft" banner came from.

#### `EditForm.vue`

Same prop set as `CreateForm`, plus a required `uuid: string` and a `viewUrl` override (default `/location-types/{uuid}/view`). Emits `cancel`, `updated` (payload `{ id }`).

Two behavioural differences from `CreateForm`:

1. **Loads before it can be submitted.** `onMounted` calls `refreshAndSet()` (splash), then `GET`s `viewEndpoint` to populate `form` with the existing record — merging the response into `form.value` and replacing any `null` value with `''` so a `v-model`-bound `Input` never renders `null`. Submits via `sendPutRequest`, not POST.
2. **`defaults` wins over loaded data, and auto-disables what it sets.** `defaults` is applied *after* the record loads, overwriting whatever the fetched value was — and if the caller didn't pass an explicit `disabledFields`, every key present in `defaults` is auto-added to `disabledFieldsList`. This is how a delegation-embedded edit form locks the parent FK field to its current value without the caller separately declaring it as both a default and a disabled field.

`LocationTypesEditForm.vue` does **not** gate on `hasPermission()` the way `CreateForm` does — there is no import of `usePermissions` in the file at all. This is a real, observed asymmetry between the two generated forms in this codebase, not a documented convention either way — don't assume every generated `EditForm.vue` omits the check, and don't assume it's guaranteed present either; verify the specific module.

#### `DeleteForm.vue`

Props: `modal`, `uuid` (required), `cancelLink`. No `hiddens`/`defaults`/`disabledFields`/`submitUrl` — a delete confirmation has no fields to prefill. Emits `cancel`, `deleted`.

The confirm-dialog pattern is a two-phase flow, not a single "are you sure?" prompt:

1. **On mount**, `loadRecord()` calls `GET {module}/{uuid}/delete/check` (see `{Module}DeleteCheckService` above) and stores the response's `relationships` array and `can_delete` boolean.
2. **If `relationships` is non-empty** (`can_delete === false`), the form renders each blocking relationship (`name`/`count`/`label`/`message`) inside a destructive `Alert` and never shows a delete button at all — there is nothing for the user to confirm yet.
3. **If `can_delete` is `true`**, the form shows a "type `YES` to confirm" `InputField` (`confirmText`) and a destructive delete `Button` whose `:disabled` is `!isConfirmValid || isDeleting`, where `isConfirmValid` is a computed `confirmText.value === 'YES'`. Only an exact, case-sensitive `"YES"` enables the button.
4. **On submit**, `sendDeleteRequest` hits `DELETE {module}/{uuid}/delete`. If the backend independently finds relationships the check missed (a race between the check and the delete itself), the response's `data.relationships` re-populates the same blocking-relationships UI instead of showing a generic error toast.

#### `{Module}ViewModal.vue` — the read-only display component

Props: `uuid: string` (required). Emits `edit`, `delete` — it does **not** open the edit/delete modals itself, it only signals intent. The caller (`{Module}ListPage.vue`) owns the actual `editOpen`/`deleteOpen` state and closes the view modal in the same handler that opens the next one:

```typescript
// LocationTypesListPage.vue
function onViewEdit()   { viewOpen.value = false; editOpen.value = true }
function onViewDelete() { viewOpen.value = false; deleteOpen.value = true }
```

Internally, `LocationTypesViewModal.vue` fetches the record itself (`GET /location-types/{uuid}/view`, `onMounted`) and renders its own **self-contained** tab bar — a local `activeTab` ref and a `tabs` computed array, not Vue Router child routes. Each tab entry is `{ id, label, icon, component, props(), lazy? }`; a `lazy: true` tab (e.g. History) mounts its component only on first click and then stays mounted (`v-show` toggling, not `v-if`), so switching back doesn't re-fetch. The Overview tab reuses the same `{Module}DetailsOverviewPage.vue` component that the full details page (below) uses for its own routed Overview tab.

**This is a different surface from `{Module}DetailsLayout.vue`.** `{Module}DetailsLayout.vue` is a fully routed page at `/{module}/:uuid`, with real Vue Router child routes for its Overview/History tabs and its own Edit/Delete `AppDialog`s. `{Module}ViewModal.vue` is a lighter, modal-only quick look — opened from a list row's eye icon, or by `RelatedRecordLink` (via each module's `{Module}ModuleConfig.detailsView` export in `routes.ts`) whenever a foreign-key cell elsewhere in the app links to this record. The two intentionally duplicate the Overview/History tab structure rather than sharing a router outlet, since one lives inside a dialog and the other owns a full page.

---

## Standard API Route Table

Every module exposes up to thirteen routes. Which routes are registered depends on the `features` block in `module.json`.

| Method | Path | Permission | Description |
|---|---|---|---|
| `GET` | `/module-name/list` | `ModuleName.list` | Paginated, filterable, sortable index |
| `GET` | `/module-name/create/splash` | `ModuleName.create` | Reference data for create form — registered only when `constants` is non-empty **and** `features.backend.createSplash` is set |
| `POST` | `/module-name/create` | `ModuleName.create` | Create a new record |
| `GET` | `/module-name/{uuid}/view` | `ModuleName.view` | Fetch a single record |
| `GET` | `/module-name/edit/splash` | `ModuleName.edit` | Reference data for edit form — registered only when `constants` is non-empty **and** `features.backend.editSplash` is set |
| `PUT` | `/module-name/{uuid}/edit` | `ModuleName.edit` | Update a record |
| `GET` | `/module-name/{uuid}/delete/check` | `ModuleName.delete` | Check FK constraints before delete |
| `DELETE` | `/module-name/{uuid}/delete` | `ModuleName.delete` | Delete a record |
| `GET` | `/module-name/{uuid}/activity` | *(none — `auth:sanctum` only)* | Activity log for the record. The generated route carries **no** `permission:` middleware; any authenticated user can read it. |
| `POST` | `/module-name/bulk-action` | `ModuleName.bulkAction` | Bulk operation on selected records (always registered — the route exists even with `bulk_actions` empty, it just has nothing valid to dispatch) |
| `GET` | `/module-name/list/export` | `ModuleName.list` | Export the (filtered) list to CSV/XLSX/PDF — only when `features.backend.list.export` is `true`. Reuses the list's own permission, no dedicated export permission. |
| `GET` | `/module-name/import/template` | `ModuleName.import` | Download a blank import template with the correct column headers — only when `features.backend.list.import` is `true`. |
| `POST` | `/module-name/import` | `ModuleName.import` | Import records from an uploaded CSV/XLSX (supports `dry_run`) — only when `features.backend.list.import` is `true`. |

All routes require `auth:sanctum`. Routes for disabled features are not registered — the middleware chain is not evaluated for non-existent routes.

---

## Response Format

All service classes return a uniform response shape via `Helpers::success()` and `Helpers::error()`. The controller passes the `code` field directly to `response()->json()` as the HTTP status code.

### Success

```json
{
  "status": true,
  "message": "Status created successfully",
  "data": { "uuid": "...", "name": "Active", ... },
  "code": 201
}
```

### Validation error (422)

```json
{
  "status": false,
  "message": "Validation failed",
  "errors": {
    "name": ["The name field is required."]
  },
  "code": 422
}
```

### Server error (500)

```json
{
  "status": false,
  "message": "An unexpected error occurred.",
  "code": 500
}
```

::: warning Never expose raw exceptions
The service's `execute()` method always catches `\Exception` and maps it to `Helpers::error(..., 500)`. Stack traces and internal error details never reach the HTTP response. If you add a new service, follow the same `try/catch` structure in `execute()`.
:::

---

## What Is Hand-Editable vs Generated

Understanding which files survive a `--force` re-run is essential before making manual edits.

### Survives `--force` (always safe to edit)

| File | What to customise |
|---|---|
| `module.json` | Feature flags, column config, menu settings, delegations, `inline_items`, custom actions and bulk actions, `morphs[].targets`, `relations`, processors, constants, `model_hand_maintained`, mobile app mode and `features.mobile_app.enabled`, and `features.backend.list.export`/`features.backend.list.import`. This is the primary customisation surface. |
| `StatusesSeederData.json` | Default seed records. The generator never touches this file after initial creation. |
| `{Module}{Key}InlineItems.vue` | The `inline_items` wrapper component — genuinely **write-once**. Not touched by `--force` at all once it exists, even when the rest of the module regenerates. See [Parent-Child Inline Items](/legacy/inline-items). |
| `{Module}{Action}Service.php` | An action's backend service — **write-once** (`writeFileOnce()`, since generator-engine v3.1.7). The generated file is a stub with a "your logic here" TODO; a plain overwrite would silently wipe whatever you filled in. See [Custom Actions](/legacy/actions). |
| `{Module}{Action}Form.vue` / `{Module}{Action}Page.vue` | An action's frontend form and its page shell — **write-once** for the same reason. Note this protection covers *action* forms only, not the CRUD forms. |
| Hand-written routes, controller methods and `use` lines, inside `// [generator:region:hand-routes\|hand-methods\|hand-imports]` markers in `Routes/api.php` / `{Module}Controller.php` | Copied verbatim on every `--force` (generator-engine v3.5.17+). The sibling `custom-*` regions are **not** this — they are rebuilt from `module.json`'s `delegations`/`actions` on every run. A copy of a delegation/action left in `hand-*` shadows later edits to it in `module.json` (warned every run); delete the copy to apply the edit. |

### Regenerated by `--force` (edits will be overwritten)

Everything else in the backend and frontend trees is regenerated from templates on a `--force` run, **except** the write-once files listed above (the `inline_items` wrapper and the action Service/Form/Page). This includes:

- `StatusesController.php` (except the `hand-*` regions)
- All `Services/*.php` (the CRUD services; an action's own `{Module}{Action}Service.php` is write-once)
- `StatusesModel.php` (unless `model_hand_maintained: true` is set in `module.json`)
- `Routes/api.php` (except the `hand-*` regions)
- All `*.vue` pages and components (except a written `{Module}{Key}InlineItems.vue` and the action Form/Page)
- `routes.ts`, locale files
- **Every generated PHPUnit and Playwright test file.** These are *not* write-once: `PhpUnitTestGenerator` and `PlaywrightTestGenerator` both go through the ordinary `writeFile()` path, which overwrites when `--force` is passed. That is deliberate — it is how a fix to the test generators themselves reaches an already-scaffolded module. Hand-edits to a generated spec do not survive; put bespoke assertions in a separate, non-generated test file.

::: tip Safe workflow for extending generated files
If you need behaviour that the generator cannot produce, the recommended approach is:

1. **Backend**: Create a new service class alongside the generated ones (e.g. `StatusesPublishService.php`). Put its controller method in `hand-methods` and its route in `hand-routes`; the generator never rebuilds those regions and never touches files it did not create.
2. **Frontend**: Create additional components in a subdirectory (e.g. `Components/Custom/`). Import them in the generated page or layout manually.
3. **Config-driven additions**: For delegations, `inline_items`, custom action buttons/bulk actions, `morphs[].targets`, and `relations`, add them to `module.json`. The generator weaves them into the regenerated files automatically on the next `--force` run — all of them are real, read mechanisms, and all survive the merge. See [Polymorphic Relations (morphs)](/legacy/morphs) for what `targets`/`relations` produce.

Never edit `module.json`-driven content (routes, CRUD services, form fields) directly in the generated files — your changes will be lost. Drive those changes through `module.json` instead.

**There is no escape hatch for the CRUD forms.** `{Module}CreateForm.vue`, `{Module}EditForm.vue`, and `{Module}ViewModal.vue` have **no** `writeFileOnce()` protection and **no** `model_hand_maintained`-style opt-out — unlike an action's `Form.vue`, or the model. A hand-edited CRUD form is destroyed by the very next `--force`, including a `--force` run for an unrelated reason (a new column, another module's regenerate sweep). If the form needs behaviour the config cannot express, either drive it through `module.json`, or move the custom part into an `inline_items` wrapper component / a `Components/Custom/` file the generator never writes and import it from there.
:::

::: warning Migrations are never regenerated
`Migrations/` files are written once and never overwritten, even with `--force`. Modifying a migration after it has been run will cause inconsistencies. For schema changes to an existing table, create a new migration manually.
:::

---

## Related Pages

- [Creating modules](/legacy/creating-modules) — `make:module` CLI reference, bulk generation, and the emit-config / schema workflow.
- Permissions — how auto-registered permission keys map to policy gates and how to add custom permissions.
- [Delegations and child modules](/legacy/delegations) — configure `module.json` delegations to embed child-list tabs inside a detail page.
- [Custom Actions](/legacy/actions) — the `actions` config key (single state-transition buttons) and `features.backend.list.bulk_actions` (the separate, nested bulk-action mechanism `ListServiceTrait::processBulkAction()` dispatches).
- Email View Templates — module-owned `Views/emails/` and `Views/texts/` folders for notification Mailables, a hand-authored convention layered on top of the generated file tree rather than part of `make:module`'s own output.
- [Polymorphic Relations (morphs)](/legacy/morphs) — auto-detected `{prefix}_type`/`{prefix}_id` column pairs; the plain text+number inputs they render as with empty `targets`, versus the real `morph-select` picker and generated `Relation::morphMap()` you get once `morphs[].targets` is populated; plus `relations.morphMany` for the reverse relation on a morph target.

:::
