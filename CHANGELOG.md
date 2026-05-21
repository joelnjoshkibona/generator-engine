# Changelog

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
