# Changelog

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
