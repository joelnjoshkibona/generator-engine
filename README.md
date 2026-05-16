# blutrixx/generator-engine

A config-driven code generation engine for Laravel + Vue 3 projects. Given a structured module-configuration array, the package emits a full set of backend (Laravel) and frontend (Vue 3) source files for a module.

The engine is config-source-agnostic. It can be driven by a UI that produces a config array (as in PROJECT_GENERATOR), by an Artisan command that introspects a database (as in SYSTEM_SHELL), or by any custom code that produces the same shape.

Primary namespace: `Blutrixx\GeneratorEngine`

Sub-namespaces:

- `Blutrixx\GeneratorEngine\Generators\Backend\...`
- `Blutrixx\GeneratorEngine\Generators\Frontend\...`
- `Blutrixx\GeneratorEngine\Generators\MobileApp\...`
- `Blutrixx\GeneratorEngine\Generators\Ux\...`
- `Blutrixx\GeneratorEngine\Commands\...`
- `Blutrixx\GeneratorEngine\Schema\...`
- `Blutrixx\GeneratorEngine\Helpers\...`

---

## Architecture Overview

Generation follows a three-step pipeline:

```
Source of truth
      │
      ▼
  Config array  (GeneratorModule shape)
      │
      ▼
  Generators    (one class per artifact)
      │
      ▼
  Emitted files (PHP / Vue / JSON)
```

**Config sources.** The engine is config-driven: it does not care how the config array was produced.

- V1 builds the config through its database-backed UI (`ModuleGenerationService`).
- SHELL builds the config by introspecting a live database with `SchemaIntrospector`, then passing the result through `IntrospectionToConfig::build()`.

Both paths produce the same GeneratorModule-shaped array and pass it to the same generator classes.

**PathManager.** A static service-locator that holds all cross-cutting context: the project output root, the project context, the module registry, the FK graph, and the template root paths. Generators read from `PathManager` rather than accepting these as constructor arguments.

**Generators.** Each generator receives `($moduleName, $moduleGroup, $config)` and writes one or more files to the path computed by `PathManager`. Generators are standalone — they do not call each other, and they do not hit the database.

---

## Installation

The package is published on Packagist:

```bash
composer require blutrixx/generator-engine:^2.0
```

That's the only step needed for any standard Laravel application.

**Pinning a version** in `composer.json`:

```json
{
  "require": {
    "blutrixx/generator-engine": "^2.0"
  }
}
```

**Installing directly from Git** (for forks, pre-release branches, or environments that don't use Packagist):

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/joelnjoshkibona/generator-engine" }
  ],
  "require": {
    "blutrixx/generator-engine": "^2.0"
  }
}
```

**Runtime requirements** (from `composer.json`):

| Dependency | Constraint |
|---|---|
| PHP | `^8.2` |
| `illuminate/support` | `^11.0 \| ^12.0` |
| `illuminate/filesystem` | `^11.0 \| ^12.0` |

The package has zero `App\` dependencies at runtime. It works in any Laravel 11 / 12 application without modification, and in standalone PHP scripts that happen to have the two `illuminate/*` packages on their classpath.

---

## Public API

### 1. Bootstrap PathManager

`PathManager` must be seeded before any generator is invoked. All setters are static.

```php
use Blutrixx\GeneratorEngine\Generators\PathManager;

// Required: absolute path to the output root.
// Backend files land in {root}/BACKEND, frontend in {root}/FRONTEND.
PathManager::setProjectRoot('/var/www/my-project');

// Required: project identity used by some generators.
PathManager::setProjectContext(['id' => 42, 'uuid' => 'abc-123-...']);

// Optional but strongly recommended: module registry.
// Enables accurate PHP namespace and Vue import-path resolution for FKs.
PathManager::setModuleRegistry([
    ['name' => 'Users',    'module_type' => 'Core',   'table_name' => 'users'],
    ['name' => 'Products', 'module_type' => 'Custom',  'table_name' => 'products'],
    // ...
]);

// Optional: wire up a callable to receive warnings during generation.
PathManager::setIssueHandler(function (string $message, string $level): void {
    // e.g. forward to Artisan output, a logger, or a collection
    logger()->{$level}($message);
});

// Optional: FK graph for delete-check generation.
// Shape: [target_table => [['source_table' => string, 'source_column' => string], ...]]
PathManager::setForeignKeyGraph($graph);

// Optional: set a module sub-group (injected into output paths).
PathManager::setModuleSubGroup('Finance');  // → .../Custom/Finance/Invoices
```

### 2. Build a config from DB introspection

`IntrospectionToConfig` converts raw `SchemaIntrospector::columns()` output into a GeneratorModule config array.

```php
use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;

$columns = $schemaIntrospector->columns('products');

$config = (new IntrospectionToConfig())->build($columns, [
    'module_name' => 'Products',   // StudlyCase singular
    'module_type' => 'Custom',     // StudlyCase group
    'table_name'  => 'products',   // snake plural
    'id_type'     => 'uuid',       // 'uuid' | 'bigint'
    'group_name'  => null,         // optional sub-group
]);
```

The returned `$config` is the same shape that V1's UI produces — pass it directly to generators.

### 3. Run generators

Each generator follows the same constructor / `generate()` pattern:

```php
use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;

$moduleName  = 'Products';
$moduleGroup = 'Custom';

(new ModelGenerator($moduleName, $moduleGroup, $config))->generate();
(new MigrationGenerator($moduleName, $moduleGroup, $config))->generate();
(new ListPageGenerator($moduleName, $moduleGroup, $config))->generate();
// ... repeat for each desired generator
```

All output paths are resolved through `PathManager` using the project root set earlier.

By default, `generate()` returns `false` and skips writing when the target file already exists (preserving hand-edits). To overwrite existing files, call `setForce(true)` before `generate()`:

```php
// Re-running on an existing module: files are skipped unless force is set.
$gen = new ModelGenerator($moduleName, $moduleGroup, $config);
$gen->setForce(true); // overwrite any existing file
$gen->generate();
```

### 4. PathManager utility methods

| Method | Returns | Purpose |
|---|---|---|
| `PathManager::getProjectRoot()` | `?string` | Current project root |
| `PathManager::getBackendBasePath()` | `string` | `{root}/BACKEND` |
| `PathManager::getFrontendBasePath()` | `string` | `{root}/FRONTEND` |
| `PathManager::getBackendModulePath($group, $name)` | `string` | Full backend module directory |
| `PathManager::getFrontendModulePath($group, $name)` | `string` | Full frontend module directory |
| `PathManager::findModuleInRegistry($name)` | `?array` | Look up a module by name |
| `PathManager::findModuleByTable($table)` | `?array` | Look up a module by table name |
| `PathManager::resolveBackendModuleNamespace($name)` | `string` | Resolve PHP namespace for a related module |
| `PathManager::resolveFrontendImportSegment($name)` | `string` | Resolve Vue import path segment |
| `PathManager::getForeignKeyGraph()` | `array` | Current FK graph |
| `PathManager::normalizeGroupName($group)` | `string` | PascalCase normalization of a module type |
| `PathManager::resetProjectRoot()` | `void` | Clear project root + context |

---

## Configuration Shape

The GeneratorModule config array is the contract between config producers (V1 UI, `IntrospectionToConfig`) and generators. Top-level keys:

| Key | Type | Description |
|---|---|---|
| `module_name` | `string` | StudlyCase singular name, e.g. `"Products"` |
| `module_type` | `string` | Module group/category, e.g. `"Custom"`, `"Core"` |
| `table_name` | `string` | Database table, snake plural, e.g. `"products"` |
| `id_type` | `string` | Primary key strategy: `"uuid"` or `"bigint"` |
| `columns` | `array` | Column definitions including type, nullable, FK metadata, and per-feature visibility flags |
| `morphs` | `array` | Polymorphic morph pairs auto-detected from `*_type` + `*_id` column pairs |
| `delegations` | `array` | Related modules rendered as embedded sub-tabs on the view page |
| `actions` | `array` | Custom action definitions (state transitions, etc.) |
| `seeder` | `array` | Seed record definitions used by `SeederGenerator` |
| `menu_config` | `array\|null` | Sidebar menu entry configuration used by `MenusJsonGenerator` |
| `features.backend.*` | `array` | Per-operation backend config: endpoint paths, permissions, filterable/sortable fields, validation rules |
| `features.frontend.*` | `array` | Per-operation frontend config: list columns, form fields, view fields |

`features` contains sub-keys: `backend` (with `list`, `create`, `view`, `edit`, `delete`) and `frontend` (same operations). Each sub-key holds the relevant field lists and endpoint metadata consumed by the corresponding generator.

---

## Generator Catalog

### Backend

All backend generators live under `Blutrixx\GeneratorEngine\Generators\Backend\`.

| Generator | Namespace segment | Emits |
|---|---|---|
| `ModelGenerator` | `Models\` | Eloquent model with relationships, fillable, casts |
| `MigrationGenerator` | `Migrations\` | `create_{table}` migration |
| `MigrationUpdateGenerator` | `Migrations\` | `update_{table}` migration (add/alter columns) |
| `ControllerGenerator` | `Controller\` | Resource controller wiring services |
| `RoutesGenerator` | `Routes\` | API route file for the module |
| `ListServiceGenerator` | `Services\` | Paginated list query with filters and sorts |
| `CreateServiceGenerator` | `Services\` | Record creation with validation |
| `EditServiceGenerator` | `Services\` | Record update with validation |
| `ViewServiceGenerator` | `Services\` | Single-record fetch with eager loads |
| `DeleteServiceGenerator` | `Services\` | Soft/hard delete |
| `DeleteCheckServiceGenerator` | `Services\` | FK constraint check before delete |
| `ActionServiceGenerator` | `Services\Action\` | Custom action handler |
| `BulkActionServiceGenerator` | `Services\` | Bulk operation handler |
| `DelegationServiceGenerator` | `Services\Delegation\` | Related sub-resource list service |
| `SeederGenerator` | `Seeders\` | Database seeder for seed data |
| `ModuleConfigGenerator` | `Config\` | Module config file registered in the app |
| `ActivityServiceGenerator` | `Services\` | Activity-log service stub |
| `CreateSplashServiceGenerator` | `Services\` | Splash/constants-driven create service |
| `EditSplashServiceGenerator` | `Services\` | Splash/constants-driven edit service |

### Frontend

All frontend generators live under `Blutrixx\GeneratorEngine\Generators\Frontend\`.

| Generator | Namespace segment | Emits |
|---|---|---|
| `ListPageGenerator` | `Pages\` | Vue list page with data table |
| `ListComponentGenerator` | `Components\` | Reusable list data-table component |
| `CreatePageGenerator` | `Pages\` | Vue create page wrapper |
| `CreateFormGenerator` | `Components\` | Create form with field bindings |
| `EditPageGenerator` | `Pages\` | Vue edit page wrapper |
| `EditFormGenerator` | `Components\` | Edit form with pre-populated fields |
| `ViewLayoutGenerator` | `Pages\` | View page layout shell |
| `ViewOverviewGenerator` | `Components\` | Overview panel on the view page |
| `DeletePageGenerator` | `Pages\` | Delete confirmation page |
| `DeleteFormGenerator` | `Components\` | Delete confirmation form |
| `FrontendRoutesGenerator` | `Routes\` | Vue Router route definitions |
| `MenusJsonGenerator` | — | Adds module entry to `menus.json` |
| `ActionComponentGenerator` | `Components\Actions\` | Vue component for a custom action |
| `DelegationTabComponentGenerator` | `Components\Delegations\` | Tab component for a delegated sub-resource |
| `DelegationModalComponentGenerator` | `Components\Delegations\` | Modal for delegation interaction |
| `DelegationRelatedFormGenerator` | `Components\Delegations\` | Form embedded inside a delegation tab |

---

## Bundled Stub Templates

The package ships stub files under `src/Generators/Templates/`:

```
src/Generators/Templates/
├── backend/       PHP stubs for models, services, controllers, etc.
├── frontend/      Vue 3 / JS stubs for pages and components
└── mobile_app/    Mobile app stubs
```

`PathManager::getBackendTemplatePath()`, `getFrontendTemplatePath()`, and `getMobileAppTemplatePath()` default to these bundled paths.

Consumers can override any or all of them by passing an array of absolute paths:

```php
PathManager::setTemplateRoots([
    'backend'  => '/path/to/custom/backend/stubs',
    'frontend' => '/path/to/custom/frontend/stubs',
    'mobile'   => '/path/to/custom/mobile_app/stubs',
]);
```

Only the keys that need overriding must be supplied; omitted keys continue to use the bundled stubs.

### Frontend stub assumptions

The bundled frontend stubs assume the following conventions in the target Vue 3 project:

| Assumption | Detail |
|---|---|
| **UUID source in child tabs** | Tab components (`delegation/tab.stub`, `custom/tab_action.stub`) read the parent record ID from `route.params.[[idParam]]` via `useRoute()`. The parent layout must define a route with the corresponding named parameter (e.g. `:uuid`). |
| **Loading skeleton component** | `details_layout.stub` imports `CardSkeleton` from `@/components/ui/loading/CardSkeleton.vue`. The target project must provide this component. |
| **Toast utility** | Action and UX stubs import `{ toast }` from `@/lib/toast` (a Sonner wrapper). The shadcn `@/components/ui/toast` module is not used. |
| **Tabs strip styling** | The generated `*DetailsLayout` tabs wrapper uses the CSS class `tabs-header border-y bg-card`. The `tabs-header` class should be defined in the project's global stylesheet to handle the `-mb-px` tab underline trick. |

---

## Auto-Detection Features

When `IntrospectionToConfig` processes raw column data, it applies the following heuristics automatically:

- **Convention-based FK detection.** Columns ending in `_id` where the implied table (column name minus `_id`, pluralized) exists in the schema are flagged as foreign keys and given a `relatedModule` value derived from the foreign table name.
- **Polymorphic pair detection.** When a `{prefix}_type` (string) column and a `{prefix}_id` (integer) column share the same prefix, they are grouped into a `morphs` entry (`morphTo` relationship) and excluded from form/validation field lists.
- **Reverse FK graph.** The caller may pass a pre-computed FK graph (`PathManager::setForeignKeyGraph()`) built by `SchemaIntrospector::globalForeignKeys()`. `DeleteCheckServiceGenerator` reads this graph to emit referential-integrity checks before a delete is executed.
- **Index-presence warnings.** If a FK column is detected without a corresponding index, the engine emits a warning via `PathManager::reportIssue()` so the consumer's logger or Artisan output can surface it.
- **Non-filterable type exclusion.** Columns of type `text`, `longText`, `mediumText`, and `json` are automatically excluded from `filterableFields` and `sortableFields` in the generated list service, as full-text search on these types is generally undesirable.

---

## Issue Handling

Generators and `PathManager` use a single internal reporting channel. When the engine encounters a non-fatal issue (an unresolvable FK module reference, a missing index, an ambiguous namespace), it calls:

```php
PathManager::reportIssue(string $message, string $level = 'warning'): void
```

This calls the registered handler if one is set, or silently no-ops if none is registered.

**Registering a handler:**

```php
// Artisan command context
PathManager::setIssueHandler(function (string $message, string $level) use ($output): void {
    $output->writeln("<comment>[{$level}] {$message}</comment>");
});

// Laravel log context
PathManager::setIssueHandler(fn($msg, $lvl) => logger()->{$lvl}($msg));
```

**Resetting to default (no-op):**

```php
PathManager::setIssueHandler(null);
```

---

## Decoupling Notes

The package is deliberately free of consumer-side dependencies:

- No `App\` classes are imported at runtime.
- No Eloquent models (`Module`, `GenerationQueue`, etc.) — all data is passed as plain arrays.
- No Laravel facades (`Log::`, `DB::`, `Storage::`, etc.).
- No V1 or SHELL service classes.
- `illuminate/support` and `illuminate/filesystem` are required only for `Str` helpers and filesystem writes — both are available in any standard Laravel application.

The package works identically when bootstrapped from a web-request context (e.g. a UI-driven generator), an Artisan command (e.g. a database-introspection scaffolder), or a standalone PHP script.

---

## Testing

A manual smoke test is available at:

```
tests/manual/run_introspection_to_config_smoke.php
```

Run it from the package root:

```bash
php tests/manual/run_introspection_to_config_smoke.php
```

The script exercises `IntrospectionToConfig::build()` with a synthetic column set and prints the resulting config array for visual inspection. There is no PHPUnit harness at this time.

---

## UX Generators (blueprint-driven)

In addition to the per-module pipeline, the engine ships a second pipeline driven by a
**blueprint JSON file**. The blueprint describes higher-level UX constructs: multi-section
create flows (composites), step-by-step wizards, record shortcuts, and dashboard quick-action
buttons.

### Running the command

```bash
php artisan make:ux-from-blueprint database/schema/my_blueprint.json
```

The command is registered automatically via `GeneratorEngineServiceProvider`. When running
from inside a BACKEND directory the project root is inferred (`dirname(base_path())`); no
manual `PathManager::setProjectRoot()` call is needed.

### Blueprint keys consumed

| Key | Generator | Outputs |
|---|---|---|
| `composites` | `CompositeGenerator` | `{Module}CreatePage.vue`, `{Module}CompositeCreateService.php` |
| `wizards` | `WizardGenerator` | `{Wizard}WizardPage.vue`, `{Wizard}WizardService.php`, `pages/wizards/routes.ts` |
| `shortcuts` | `ShortcutGenerator` | `{Module}Shortcuts.vue`, patches `{Module}DetailsLayout.vue` |
| `dashboard.quick_actions` | `DashboardGenerator` | `DashboardQuickActions.vue` |

### Stub overrides

UX stubs live in `Generators/Templates/ux/`. Override any stub per-project:

```php
PathManager::setTemplateRoots([
    'ux' => app_path('Project/_Src/Stubs/Ux'),
]);
```

---

## Status

Actively maintained. v2.1.5 is the current stable release.

| | |
|---|---|
| Source | https://github.com/joelnjoshkibona/generator-engine |
| Packagist | https://packagist.org/packages/blutrixx/generator-engine |
| License | Apache-2.0 |
| Issues | GitHub issues on the source repo |

## Contributing

Clone, branch, PR. The package has no PHPUnit harness yet; the manual smoke test under `tests/manual/` is the existing safety net. Keep changes free of `App\` imports and Laravel facades — see "Decoupling Notes" above.
