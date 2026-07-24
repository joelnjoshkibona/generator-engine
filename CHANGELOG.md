# Changelog

## v2.10.13 — 2026-07-24

### Fixed — `RegistryGenerator`/`ModulesJsonGenerator`/`MenusJsonGenerator`/`MobileAppModulesJsonGenerator` silently skipped writing on a plain `make:module` run (no `--force`)

- This is a real, user-visible bug, not an internal refactor: on the default `make:module` invocation — i.e. every run that does **not** pass `--force`, which is the overwhelmingly common case — a freshly scaffolded module's entry was silently never written to the backend module registry, `FRONTEND/src/modules.json`, `FRONTEND/src/menus.json`, or the mobile app's own `modules.json`. The module's backend and frontend files generated fine; it just never showed up in the registry, the sidebar menu, the frontend module list, or the mobile app's module list — with no error, warning, or non-zero exit code of any kind.
- Root cause: `RegistryGenerator`, `ModulesJsonGenerator`, `MenusJsonGenerator`, and `MobileAppModulesJsonGenerator` each set `$this->force = true` in their own constructor — intentionally, since these four output files are shared, cumulative registries that must stay in sync on *every* generation run, forced or not. But the consuming app's generic scaffolder runner constructs each generator and then unconditionally calls `->setForce($force)` afterward using the CLI's own `--force` flag, silently clobbering the constructor's `true` back to `false` whenever `--force` wasn't passed. `BaseGenerator::writeFile()` refuses to overwrite a path that already exists unless `force` is true, and all four registry files already exist after the very first module any project ever scaffolds — so every one of these four generators has been a no-op on every ordinary `make:module` run since, while `generate()`'s register-only paths kept returning a truthy result regardless.
- Fix: new `BaseGenerator::writeFileAlways(string $path, string $content): bool` writes unconditionally, bypassing the force check entirely — mirroring the pattern `MobileRegistryGenerator` already used correctly (it writes via its own raw `file_put_contents()` call, never routed through `writeFile()`'s force gate, so it was never affected by this bug). The now-pointless `$this->force = true` constructor line was removed from all four affected generators, and every `writeFile()` call site across all four — both the register and unregister/remove paths in each — now calls `writeFileAlways()` instead.
- **Non-breaking for `--force` runs** — those writes always happened anyway, so behavior there is unchanged. For the default no-`--force` path this is an intentional behavior *change*, in the sense that these four registries now finally do what they always silently failed to do: stay in sync with every scaffolded module, not just forced ones.
- **No new dedicated regression test for the exact `setForce()`-after-construction scenario.** The one pre-existing test file among these four generators, `tests/Unit/Generators/Frontend/MenusJsonGeneratorTest.php`, continues to pass (confirmed via `composer test`) but never calls `setForce()` at all — it exercised the constructor's `force = true` before this fix and now exercises `writeFileAlways()` after it, without ever simulating the specific clobbering sequence (`new Generator(...)` then `->setForce(false)`) that was the actual bug. `RegistryGenerator`, `ModulesJsonGenerator`, and `MobileAppModulesJsonGenerator` have no dedicated PHPUnit coverage at all, before or after this change. Flagged as a follow-up, not blocking this release.

### Added — `RelatedRecordLink` auto-wired into generated FK list cells; modules self-register for it via a new `ModuleConfig` export

- Why: SYSTEM_SHELL's `Locations` module already had this behavior, but only because its three FK columns (`location_type_id`, `parent_id`, `status_id`) were hand-patched onto the generated list page, and the module was hand-added to `useEntityNavigation()` via a manually-written block in its own `routes.ts`, after scaffolding. There was previously no mechanism for a freshly generated FK list column to become a clickable link to its related record without that manual follow-up work. This release makes it automatic for every module scaffolded going forward.
- `src/Schema/IntrospectionToConfig.php` (`buildFrontendListFields()`): each list field's config entry now also carries a `relatedModule` key, computed via the same `resolveRelatedModule($col)` helper `buildColumn()` already calls to populate the top-level `columns[]` entry's own `relatedModule` — same raw `$col`, same resolver, so a list field and its column entry can never disagree about which module an FK targets.
- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateCustomCellRenderersFromListFields()`): new `isFk` branch, alongside the pre-existing badge/boolean branches, emits — for any non-primary field with `isFk: true` — a `<template #cell-{key}="{ row }">` wrapping the cell value in `<RelatedRecordLink module="{relatedModule}" :uuid="row.{relation}?.uuid">{{ row.{relation}?.name || 'N/A' }}</RelatedRecordLink>`. `{relation}` is the FK column key with its trailing `_id` suffix stripped (e.g. `location_type_id` → `location_type`), matching both the generated Model's `belongsTo()` relation-method naming convention and — regardless of whether that method name is itself camelCase or snake_case — the key Eloquent's `relationsToArray()` actually snake-cases the relation to in the real API response (confirmed against the hand-completed `LocationsListPage.vue` reference: `row.location_type`, `row.status`). Uses the `{ row }` slot prop, never the badge/boolean branches' `{ item }`. Display field defaults to `name`, correct for the large majority of this codebase's lookup/reference tables; a target with a different display column needs a manual tweak after generation.
- `src/Generators/Templates/frontend/features/list/page.stub`: added the `import RelatedRecordLink from '@/components/RelatedRecordLink.vue'` the new cell renderer needs.
- `src/Generators/Frontend/Routes/FrontendRoutesGenerator.php`: new `generateModuleConfigExport()` appends `export const {ModuleName}ModuleConfig: EntityModuleConfig = { mode: 'modal', route: '/{module-route}', detailsView: () => import('./Components/{ModuleName}ViewModal.vue'), modalSize: 'lg' }` to the bottom of the generated `routes.ts`, plus the `import type {EntityModuleConfig} from "@/composables/useEntityNavigation"` it depends on. This registers the module with `useEntityNavigation()` so any `RelatedRecordLink` pointing at it — including a module's own self-referential FKs, like `Locations`' `parent_id` — can actually open its record details. Only emitted when the `view` feature is enabled, since `detailsView` imports `{ModuleName}ViewModal.vue`, which only exists for view-enabled modules. Mirrors the hand-added block already present in the reference `Locations/Locations/routes.ts`.
- **Non-breaking.** `RelatedRecordLink` itself degrades to plain inert text whenever its target module isn't registered or the viewing user lacks permission, so emitting it is safe even before every related module has been regenerated with this version. The pre-existing badge/boolean rendering branch (`{ item }`, dot-notation) is completely untouched — the new branch only fires for non-primary fields with `isFk: true`. Already-generated files are untouched by `generate()`'s no-overwrite guard; only newly scaffolded modules and `--force` regenerations pick up the new cell markup and the `ModuleConfig` export.
- Added regression coverage: new `tests/Unit/Schema/IntrospectionToConfigTest.php` (5 tests — FK column gets `relatedModule` threaded onto its list field; non-FK field gets `isFk: false` and `relatedModule: ''`; the resolved name is plural StudlyCase, never a singularized guess; the list field's `relatedModule` matches the top-level column entry's exactly; an FK with no `foreign_table` resolves to `''` rather than a notice or a bogus guess); new fixture `tests/Fixtures/LocationsModule.json` (SYSTEM_SHELL's real, hand-completed `Locations` module config); 6 new tests in `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` (including one run against the real Locations fixture confirming all three of its FK columns each produce a correctly-targeted `RelatedRecordLink` cell, and that a primary column is still skipped even when it happens to be an FK itself); 2 new tests in `tests/Unit/Generators/Frontend/Routes/FrontendRoutesGeneratorTest.php` (the `ModuleConfig` export is emitted when `view` is enabled, and omitted — along with its `EntityModuleConfig` import — when it isn't) — see [Testing](README.md#testing).

## v2.10.12 — UNRELEASED (prepared 2026-07-23, pending `scripts/release-engine.sh`)

### Fixed — try-wrapped View/Edit/Delete steps in generated Playwright specs indented at the wrong depth

- Found while writing `PlaywrightTestGeneratorTest.php` — the dedicated PHPUnit coverage for `PlaywrightTestGenerator` that v2.10.11 shipped without (flagged there as a caveat, not blocking) — and confirmed live via a Tier 3 `make:module` smoke test. `buildTestBody()` wraps the View/Edit/Delete inner block in a `try { … } finally { … }` whenever `hasDelete` is true (the self-cleaning behavior v2.10.11 added), but `$innerBlock` itself — built by `buildViewBlock()`/`buildEditBlock()`/`buildDeleteBlock()` — is always authored at a fixed 2-tab depth, the depth that's only correct for the plain, non-wrapped `else` branch taken when `hasDelete` is false. Wrapping that same text in `try {}` nests it one level deeper structurally without re-indenting it textually, so every generated spec for a module with delete enabled had its whole View/Edit/Delete body sitting flush against the `try {`/`} finally {` lines instead of properly nested one tab in.
- `src/Generators/Frontend/Tests/PlaywrightTestGenerator.php`: new `protected function indentBlock(string $block, int $levels = 1): string` indents every non-blank line of a multi-line generated JS block by one extra tab (blank lines are left untouched so no trailing whitespace gets introduced). `buildTestBody()`'s try-wrapping branch now does `str_replace('__INNER__', $this->indentBlock($innerBlock), $wrapTpl)` instead of substituting `$innerBlock` raw; the non-wrapped (`hasDelete` false) branch is untouched.
- **Non-breaking.** Purely cosmetic — generated JavaScript carries no semantic meaning in its whitespace, so no previously-generated spec ever failed to run because of this; only its readability was wrong. Only modules with `delete` enabled are affected at all, since that's the only case that takes the try/finally branch; modules without delete were never touched by either the bug or the fix. Already-generated files are untouched by `generate()`'s no-overwrite guard — only newly scaffolded modules and `--force` regenerations of a delete-enabled module's e2e spec pick up the corrected indentation.
- Added regression coverage: `tests/Unit/Generators/Frontend/Tests/PlaywrightTestGeneratorTest.php` (new — first dedicated coverage for `PlaywrightTestGenerator`, closing the "no dedicated PHPUnit coverage exists yet" caveat from v2.10.11) asserts the generated spec's `try {` line is immediately followed by a triple-tab-indented `// ── View` comment, and never by that same comment at double-tab (flush) depth — see [Testing](README.md#testing).

## v2.10.11 — UNRELEASED (prepared 2026-07-23, pending `scripts/release-engine.sh`)

### Fixed — generated list pages had no way to keep FK/relation columns out of the default view, and the actions column was never titled

- Found while auditing the engine's frontend output against SYSTEM_SHELL's Users module (one of the two canonical reference implementations) after Users' `role_id` list column and untitled actions column were manually revised: removing `role_id` from the list entirely, and titling the actions column via `t('common.actions')`. Running `make:module` against any table with a foreign key would still reproduce the *old* Users shape — there was no mechanism to keep a relation column out of the default view short of hand-editing the generated file afterwards, same as Users itself needed.
- `src/Schema/IntrospectionToConfig.php` (`buildFrontendListFields()`): each field's config entry now carries an `isFk` boolean (previously computed locally for the cell's `data` path but discarded, not persisted onto the entry).
- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateColumnsFromListFields()`, shared by both `ListPageGenerator` and `ListComponentGenerator`): a non-primary field with `isFk: true` now gets `, defaultVisible: false` appended to its column entry — the column still exists (selectable via the list's own column-visibility picker), it just doesn't clutter the default view. The primary column is exempt even if (unusually) FK-derived, since a list can't function with its one identifying column hidden. The auto-appended actions column now emits `label: t('common.actions')` instead of `label: ""`.
- **Non-breaking for non-relation columns** — a field without `isFk` set gets no `defaultVisible` key at all, so the common case is byte-for-byte unchanged. Relation columns and the actions label *do* change shape for any module regenerated with `--force` going forward; already-generated files are untouched by `generate()`'s no-overwrite guard.
- **Investigated, not changed** — three other spots flagged during the same audit turned out to already match Users' actual (not just documented) behavior once checked directly against its live code, so no fix was needed: the Overview page's Timeline/System Information card split (Users' single-card `SystemInfoCard.vue` component is dead code, never imported — the live page already renders two separate cards identical in structure to `overview.stub`), the conditional splash endpoint gating on `!empty($config['constants'])` (Users hardcodes `hasSplash = true` only because its own config has constants — the conditional would correctly compute `true` for it too), and the Edit/Delete/Details breadcrumb's static last segment (Users itself uses `common.edit`/`common.delete`/a static breadcrumb label throughout, never the record's actual name).
- **Not checked this pass**: impact on THC_V2, the other consumer of this package. Flag as a follow-up if THC_V2 also generates modules with FK-derived list columns.
- Added regression coverage: extended `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` — 3 new tests (an `isFk` field gets `defaultVisible: false`; a field without the flag gets no `defaultVisible` key at all; an FK field used as the primary column stays visible), plus updated the 3 existing tests that hardcoded the old `label: ""` actions shape — see [Testing](README.md#testing).

### Added — `data-testid` attributes on generated interactive elements (previously none, anywhere)

- Why: the engine emitted zero `data-testid` hooks in any generated `.vue` file. Every one of SYSTEM_SHELL's 18 hand-written Playwright specs had its `data-testid`s hand-added to the generated markup after scaffolding, module by module, before the button/row in question could be targeted reliably instead of by brittle text or ARIA-role selectors. This closes that gap so a freshly scaffolded module ships with the same hooks by default.
- Convention: `[[moduleName]]-<action>` for a static per-page button, or a `` `[[moduleName]]-<action>-${uuid}` `` template literal for a button whose target is one specific record. `[[moduleName]]` resolves via `BaseGenerator::replacePlaceholders()`'s existing default (`strtolower($this->moduleName)`) — none of the three edited stubs override it — matching the plain-lowercase prefix SYSTEM_SHELL's own hand-written specs already used (e.g. `location-types-crud.e2e.js`'s `locationtypes-view-...`).
- `src/Generators/Templates/frontend/features/list/page.stub`: the toolbar's "Create" button gets `data-testid="[[moduleName]]-create"`; the per-row "View" button in the `#cell-actions` slot gets `` :data-testid="`[[moduleName]]-view-${row.uuid}`" ``.
- `src/Generators/Templates/frontend/features/view/modal.stub`: the "Edit" button gets `` :data-testid="`[[moduleName]]-edit-${uuid}`" ``; the "Delete" `DropdownMenuItem` gets `` :data-testid="`[[moduleName]]-delete-${uuid}`" ``.
- `src/Generators/Templates/frontend/features/delete/form.stub`: the destructive confirm button gets `data-testid="[[moduleName]]-confirm-delete"`.
- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateFormFooter()`): both the Create-form and Edit-form footers now emit `data-testid="[[moduleName]]-cancel"` / `data-testid="[[moduleName]]-submit"` on their Cancel/Submit buttons. This method builds its markup as plain PHP string concatenation rather than through a `.stub` file routed through `replacePlaceholders()`, so it gets its own new local `$moduleSlug = strtolower($this->moduleName)` instead of a `[[moduleName]]` token.
- **Non-breaking** — purely additive markup; no existing attribute, class, prop, or behavior is touched. Already-generated files are untouched by `generate()`'s no-overwrite guard; only newly scaffolded modules and `--force` regenerations pick up the new attributes.
- **No dedicated PHPUnit coverage for the markup itself** — `generateFormFooter()` has no unit test, and neither do the three edited `.stub` files or the generators that render them (`ListPageGenerator`, `ViewModalGenerator`, `DeleteFormGenerator` have no unit tests at all today, testid-related or otherwise). The new attributes are exercised indirectly by `PlaywrightTestGenerator`'s generated output below, which asserts against these exact selectors, but that output has not yet been run against a live app in this pass. Flagged as a follow-up, not blocking this release.

### Added — new `PhpUnitTestGenerator`: per-module PHPUnit Feature test scaffolding

- Why: the engine generated zero backend test scaffolding of any kind. All 18 of SYSTEM_SHELL's hand-written `*CrudTest.php` Feature tests were written entirely by hand after `make:module` ran, and — confirmed by grepping every one of them for a filter-shaped test method — only 1 of those 18 (`PermissionsCrudTest`) exercised the list-filter mechanism at all.
- `src/Generators/Backend/Tests/PhpUnitTestGenerator.php` (new): emits `<Module>CrudTest.php` with one method per enabled `features.backend.*` flag (`list`/`create`/`view`/`edit`/`delete`), plus two methods that are unconditional regardless of flags — a delete-check ("no blocking relationships") test, since the DeleteCheck/Activity generators run unconditionally in the pipeline, and a list-filter test, since every generated list carries filter support. A create-validation test (missing required field → expects `422` + `assertJsonValidationErrors`) is additionally emitted whenever `create` is enabled.
- Matches the hand-written reference conventions directly: Sanctum `Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER))` in `setUp()`, correct `200`/`201`/`422` status-code expectations per action, and — since this engine has no `FactoryGenerator` — a generated `create<Singular>Fixture()` helper building fixtures via direct `<Module>Model::create()` instead of an Eloquent factory.
- Field-value literals are type-aware (`buildFieldValueLiteral()`), checked in this order so a field that is simultaneously `unique` and `email`/`integer` still gets a value valid for its own rule rather than a generic string that would fail its own validation: an `email`-named field gets `'test' . uniqid() . '@example.com'`; an `integer`/`numeric` rule gets `1` (or `random_int(100000, 999999)` when also `unique`); `boolean` gets `true`; `date` gets `now()->toDateString()`; everything else falls back to `'Test <Field> ' . uniqid()`.
- New `src/Generators/Templates/backend/Tests/crud_test.stub` + `PathManager::getBackendTestsPath()`/`getBackendTestsNamespace()`: output lands at `tests/Feature/<SubGroup>/<Module>CrudTest.php`, or flat `tests/Feature/<Module>CrudTest.php` when the module has no sub-group — deliberately NOT nested under `moduleGroup` the way `getBackendModulePath()` is, verified against every existing hand-written Feature test in SYSTEM_SHELL, none of which have a group-level path segment.
- **New capability — nothing to be non-breaking against.** This generator only runs if a consuming project's scaffolder explicitly instantiates and calls it; no existing generated output changes as a result of this addition.
- **Caveat.** No dedicated PHPUnit coverage exists yet for `PhpUnitTestGenerator` itself — unlike the fixes above, this is new code with no prior behavior to regress against, so there's nothing yet asserting that its generated PHP is even syntactically valid or that the emitted assertions match the real backend's response shapes. Verifying its actual output against a real scaffolded module is a follow-up step, not part of this release.

### Added — new `PlaywrightTestGenerator`: per-module Playwright e2e test scaffolding

- Why: the same gap as above, on the frontend side. The engine generated zero e2e coverage, and every one of the 17 existing specs in `SYSTEM_SHELL/FRONTEND/e2e` was hand-written against that project's own helper infrastructure (`e2e/helpers/{fixtures,auth,config,filters}.js`).
- `src/Generators/Frontend/Tests/PlaywrightTestGenerator.php` (new) + `src/Generators/Templates/frontend/tests/crud.e2e.stub`: emits `e2e/<module-route>.e2e.js` driving login → (create) → filter → view → (edit) → (delete), gated on `features.frontend.{create,view,edit,delete}`; filter and view run unconditionally, mirroring `PhpUnitTestGenerator`'s equivalent treatment.
- Deliberately targets the CURRENT generator's view-modal-first DOM/testid shape — the `[[moduleName]]-view-${uuid}` / `-edit-${uuid}` / `-delete-${uuid}` / `-submit` / `-confirm-delete` hooks added above — rather than copying the older hand-written `location-types-crud.e2e.js` pattern, which predates those testids and has no view/submit testids of its own; called out explicitly in the class's own docblock.
- Two filter strategies, chosen automatically per module: "Variant A" (filter by the same plain-text field used to create/re-find the row, when `features.backend.list.filterFields[0]` matches that field) narrows the list and asserts exactly 1 row remains, then asserts the row count is restored after clearing filters; "Variant B" (id-based `Equals` filter) is the fallback whenever no matching text filter field is configured.
- A generic `fillSelectField()` helper (opens the Select2/ApiSelect2 trigger, waits for the stacked popup, clicks the first option) drives any `select`-type create/edit field — only emitted into the output when the module actually declares one — documented in the source as best-effort and possibly needing per-module adjustment for more elaborate pickers.
- Self-cleaning whenever `delete` is enabled: captures the created record's uuid immediately after creation, wraps the View/Edit/Delete steps in `try/finally`, and best-effort-deletes any record left over from a failed run via `cleanupStrayRecord()` — every failure inside cleanup itself is caught and logged as a warning, never thrown, so a cleanup failure can never mask the original test failure that triggered it.
- New `PathManager::getFrontendE2ePath()`: always flat under `FRONTEND/e2e`, deliberately ignoring `moduleGroup`/`moduleSubGroup` — verified against all 17 existing hand-written `*.e2e.js` files, none of which live in a per-group or per-sub-group subdirectory.
- **New capability — nothing to be non-breaking against**, same as `PhpUnitTestGenerator` above; only runs if wired into a consuming project's scaffolder.
- **Caveat.** No dedicated PHPUnit coverage exists yet for `PlaywrightTestGenerator` itself, for the same reason as `PhpUnitTestGenerator` above — new code, nothing prior to regress against. Its generated `.e2e.js` output has not yet actually been executed against a live app in this pass.

### Added — wiring status in consuming projects (informational — not part of this commit)

- `SYSTEM_SHELL/BACKEND/app/Project/_Src/Console/ModuleScaffolder.php` already imports and calls both new generators (`$run('PhpUnitTest', PhpUnitTestGenerator::class)`, `$run('PlaywrightTest', PlaywrightTestGenerator::class)`) — confirmed by reading that file directly. That wrapper change lives in SYSTEM_SHELL's own repo, not this one, and is out of scope for this commit; noted here purely so the two new generators' real-world availability is tracked accurately.
- SYSTEM_SHELL/BACKEND's own `composer.json` still constrains `blutrixx/generator-engine` to `^2.10.10`, and its `composer.lock` is pinned to the literal string `"2.10.10"`; its `vendor/blutrixx/generator-engine` is a real copied directory (not a symlink) and does not yet contain either new generator class. A `composer update blutrixx/generator-engine` there, once this v2.10.11 tag exists, is required before `PhpUnitTest`/`PlaywrightTest` can actually run in that project — a follow-up step, not part of this release.

## v2.10.10 — UNRELEASED (prepared 2026-07-22, pending `scripts/release-engine.sh`)

### Fixed — Migration/Model generators still ignore `has_uuid`/`has_creator_updater` for uuid, audit columns and creator()/updater() relations (second confirmed occurrence of the v2.10.8 defect class)

- Found while porting `StockTransfers` (a table with NO uuid, NO timestamps, NO soft-deletes and NO created_by_id/updated_by_id columns) — the same structural deviation already confirmed once on `PriceLists`. v2.10.8 fixed `ModelGenerator`'s `$timestamps` property and `SoftDeletes` trait/import to trust `has_timestamps`/`has_soft_deletes`, but two sibling defects in the same defect class were missed and had to be hand-corrected on both modules until now:
  1. `src/Generators/Backend/Migrations/MigrationGenerator.php` / `migration.stub`: unconditionally emitted `$table->timestamps();`, `$table->softDeletes();`, a separate `$table->uuid()->default(...)->unique();` column, `created_by_id`/`updated_by_id` (`generateAuditFields()`), and all four columns' indexes — regardless of the real schema. `MigrationGenerator` never consulted `has_timestamps`/`has_soft_deletes` at all (both already available in config since v2.10.8's `IntrospectionToConfig::build()` change), and no equivalent flag existed yet for the separate `uuid` column or the audit-tracking columns.
  2. `src/Generators/Backend/Models/ModelGenerator.php` (`generateAuditRelationships()`): unconditionally emitted `creator()`/`updater()` `BelongsTo` relations referencing `created_by_id`/`updated_by_id`, regardless of whether those columns exist — every eager-load or query touching either relation on an audit-less model threw a SQL error.
- New `SchemaIntrospector::hasUuid()` / `hasCreatorUpdater()` (mirroring `hasTimestamps()`/`hasSoftDeletes()`): inspect the raw table's actual columns for `uuid` / both `created_by_id` and `updated_by_id`, independent of `SKIP_COLUMNS`-filtered `columns()` output (both are already in `SKIP_COLUMNS`, same reason timestamps/soft-deletes needed the same treatment).
- `IntrospectionToConfig::build()` now accepts optional `$meta['has_uuid']`/`$meta['has_creator_updater']` and writes them into the generated config as `has_uuid`/`has_creator_updater`, defaulting to `true` for both when the caller omits them (matching this project's convention that most tables have a routing uuid and creator/updater tracking — mirrors the existing `has_timestamps` default-`true` precedent, not `has_soft_deletes`'s default-`false`, since uuid/audit columns are the norm here, not opt-in).
- `MigrationGenerator`: new `hasTimestamps()`/`hasSoftDeletes()`/`hasUuid()`/`hasCreatorUpdater()` trust the corresponding `$config` flags (defaulting `true`/`false`/`true`/`true` respectively — same defaults as `IntrospectionToConfig`). `generateAuditFields()` now returns `''` when `hasCreatorUpdater()` is false. New `generateTimestampsLine()`/`generateSoftDeletesLine()`/`generateUuidLine()`/`generateUuidImports()`/`generateSystemIndexes()` conditionally emit each schema line, its `Helpers`/`DB` facade imports (uuid only), and its index — all wired through five new `migration.stub` placeholders (`[[timestampsLine]]`, `[[softDeletesLine]]`, `[[uuidLine]]`, `[[uuidImports]]`, `[[systemIndexes]]`) replacing what used to be hardcoded lines in the stub.
- `ModelGenerator`: new `hasCreatorUpdater()` (same default-`true` convention); `generateAuditRelationships()` now returns `''` immediately when it's false, before building either relation method.
- **Caveat — action required in consuming projects**, same as v2.10.8's: these flags only reach the generators if a caller actually passes them through `$meta`/`$config`. THC_V2's `ModuleScaffolder::buildConfig()` was NOT updated as part of this fix (its currently-installed vendor copy of this package predates `hasUuid()`/`hasCreatorUpdater()` and calling them would fatal) — that wrapper update, plus a `composer update blutrixx/generator-engine`, must happen together once this version is actually tagged and released via `scripts/release-engine.sh`. Until then, THC_V2 continues the same hand-correction-after-scaffold pattern used for `PriceLists` and `StockTransfers`.
- **Non-breaking** for every table that does have all four (the overwhelming majority): defaults exactly reproduce prior unconditional output, confirmed by `test_migration_includes_timestamps_uuid_and_audit_columns_by_default_but_not_soft_deletes` and `test_creator_and_updater_relations_are_emitted_by_default` below.
- Added regression coverage: `tests/Unit/Generators/Backend/Migrations/MigrationGeneratorTest.php` (3 new tests — default output matches the old unconditional shape except soft-deletes, which correctly defaults off; all four columns/relations omitted together when a table has none of them, mirroring `stock_transfers`; flags gate independently, not all-or-nothing) and `tests/Unit/Generators/Backend/Models/ModelGeneratorTest.php` (3 new tests — creator/updater emitted by default, omitted when `has_creator_updater` is false, emitted when explicitly true) — see [Testing](README.md#testing).

## v2.10.9 — 2026-07-19

### Fixed — `__ID__` placeholder never substituted in Edit-service unique validation rules

- `src/Generators/Backend/Services/BaseServiceGenerator.php` (`generateValidationRules()`): passed each field's config `rules` string through verbatim into generated Edit/Create service validation, with no substitution of a `unique:table,column,__ID__`-style rule's `__ID__` placeholder. `src/Generators/Backend/Validation/ValidationGenerator.php` already implemented the correct substitution (`processUniqueRule()`) but was dead code — never instantiated or called anywhere in the real generation pipeline. Every Edit save on a unique field therefore emitted the literal, meaningless `__ID__` token straight into the generated PHP validation rule, so Laravel's `unique` rule always excluded a record with the literal id `"__ID__"` (i.e. never excluded the record actually being edited) — saving an unchanged record on a unique field always failed validation against its own existing value.
- `processUniqueRule()` is now `public static` (previously `protected`, instance-only, coupled to `$this->operation`) and takes an explicit `bool $isEdit` parameter instead of reading `$this->operation`, so it can be called from `BaseServiceGenerator` — a sibling generator, not a subclass — without a visibility violation. Its two original call sites inside `ValidationGenerator` were updated to pass `$this->operation === 'edit'`; its logic is otherwise unchanged: it parses `table`/`column` out of the rule and discards whatever (if anything) follows — `__ID__`, nothing, or a stray value — replacing it deterministically with real, unescaped `{$model->id}` PHP-interpolation syntax when `$isEdit` is true, or nothing at all when `$isEdit` is false.
- `BaseServiceGenerator::generateValidationRules(bool $edit = false)`: when `$edit` is true, any rule starting with `unique:` is now rebuilt via `ValidationGenerator::processUniqueRule($rule, $fieldName, true)` before being emitted. Create-service generation (`$edit = false`) never calls this at all — rules pass through completely untouched, so a Create rule with no `__ID__` token (the normal case) is byte-for-byte unaffected.
- **Non-breaking.** Already-generated files are untouched by `generate()`'s no-overwrite guard; only newly scaffolded modules and `--force` regenerations of Edit services pick up the fix.
- Added regression coverage: extended `tests/Unit/Generators/Backend/Services/BaseServiceGeneratorTest.php` with 4 new tests — Edit rules substitute `{$model->id}` for `__ID__`, Create rules never get the substitution, Edit rules append `{$model->id}` even when a rule has no `__ID__` placeholder at all (any Edit unique rule must exclude the record being edited, whether or not the config author remembered the placeholder), and non-unique rules pass through untouched — see [Testing](README.md#testing).

### Fixed — relation display data paths in generated Overview pages not case-normalized

- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`mapViewFieldsToInformationFields()`): built a foreignKey field's `dataPath` straight from the module config's raw `data` string (e.g. `"itemCategory?.name"`) with no case normalization, and `generateInformationSection()` echoed that `dataPath` verbatim into the generated Vue Overview page. Eloquent's `relationsToArray()` snake-cases relation keys in the actual JSON API response regardless of the camelCase relation method name (an `itemCategory()` relation method surfaces as `item_category` in the response), so every generated Overview page referencing a multi-word camelCase relation (`itemCategory?.name`, `itemType?.name`, etc.) pointed at a JSON key the real response never has — the field silently rendered "N/A" with no error. `generateInformationSection()` already had a working `Str::snake($relationship)` branch, but it lived in an `elseif` guarded on `dataPath` being empty — unreachable in practice, since both real callers (`ViewOverviewGenerator`, `RelatedModuleFormGenerator`) always route fields through `mapViewFieldsToInformationFields()` first, which always populates `dataPath`.
- `mapViewFieldsToInformationFields()` now runs the relationship segment of the path through `Str::snake()` before building both `key` and `dataPath` (e.g. `"itemCategory?.name"` → `dataPath` `"item_category.name"`), reusing the exact same `Str::snake()` call the existing (previously unreachable) branch already used — no new logic invented. An already-snake_case relation name (e.g. `district`) is unaffected, since `Str::snake()` is idempotent.
- **Non-breaking.** Already-generated `.vue` files are untouched by `generate()`'s no-overwrite guard; only newly scaffolded modules and `--force` regenerations of the Overview page pick up the fix.
- Added regression coverage: extended `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` with 3 new tests — a multi-word camelCase relation (`itemCategory?.name`) maps to a snake_cased `dataPath`/`key`, the full `generateInformationSection()` output renders the snake_cased path (and never the original camelCase string), and an already-snake_case relation path is left unchanged — see [Testing](README.md#testing).

### Verified — fresh end-to-end scaffold with both fixes active

- Scaffolded a genuinely fresh throwaway module (`ZzzGeneratorVerifyTest8`, table `zzz_generator_verify_test8` with a unique `name` column and a nullable `a_category_id` FK) in the consuming project (THC_V2) against a temporarily-patched vendor copy carrying both fixes. Confirmed: the generated `EditService` emitted `"unique:zzz_generator_verify_test8,name,{$model->id}"` (real interpolation, no `__ID__`) while the generated `CreateService` emitted `"unique:zzz_generator_verify_test8,name"` (no `{$model->id}`, no `__ID__`); the generated Overview page rendered `data?.item_category?.name` (snake_cased) instead of the config's raw `itemCategory?.name`. Cleaned up afterward — generated dirs (BACKEND/FRONTEND/MOBILE_APP), the DB table, and the mobile registry (the only registry/menu file the scaffold actually touched) restored to exact pre-test byte content; vendor copy reverted; `git status` confirmed byte-identical to pre-test.
- Also fixed the pre-existing `ItemCategories` and `Items` modules' `module.json` in the consuming project (THC_V2) — done at the consuming-project level, not by this package, for the same reason as every prior "config predates the fix" cleanup: a generator-engine version bump alone never touches already-generated files or their source config. `ItemCategories/module.json`'s Edit `name` rule's raw `,__ID__` was rewritten to `,{$model->id}` to match what `ItemCategoriesEditService.php` already had hand-patched; its `view.fields` `"Parent"` entry's `data` was corrected from `itemCategory?.name` (wrong relation — a leftover from a hand-patch that pointed at the wrong Eloquent relation entirely) to `parent?.name`, matching `ItemCategoriesDetailsOverviewPage.vue`. `Items/module.json`'s two Edit rules (`name`, `code`) got the same `__ID__` → `{$model->id}` correction, and its `view.fields` `itemType?.name`/`itemCategory?.name` were snake_cased to `item_type?.name`/`item_category?.name`, matching `ItemsEditService.php` and `ItemsDetailsOverviewPage.vue` respectively.

## v2.10.8 — 2026-07-19

### Fixed — duplicate "create" migrations generated on module regeneration

- `src/Generators/Backend/Migrations/MigrationGenerator.php`: `generate()` always wrote a new `{timestamp}_create_{table}_table.php` migration file, and `BaseGenerator::writeFile()`'s no-overwrite guard checks `file_exists()` against that exact filename — but the filename embeds the CURRENT timestamp (`date('Y_m_d_His')`), which is different on every invocation, so the guard could never detect that a "create" migration for this table already existed. Regenerating a module whose table already had a migration (hand-written or previously generated) therefore wrote a **second** "create" migration alongside the real one — sometimes with a slightly different (introspected, and occasionally wrong) schema. Confirmed for `LedgerTransactions` and `member_phones`.
- New `createMigrationAlreadyExists()` checks the module's `Migrations/` directory via `glob('*_create_{table}_table.php')` — matching by table name regardless of timestamp prefix — before generating; `generate()` now returns `false` (no-op) whenever a match is found.
- **Non-breaking.** A module whose table has no existing "create" migration generates exactly as before. Only regeneration of a module that already has one changes behavior — from "silently duplicate it" to "silently skip it."
- Added regression coverage: `tests/Unit/Generators/Backend/Migrations/MigrationGeneratorTest.php` (new) — see [Testing](README.md#testing).

### Fixed — generated models always assumed `SoftDeletes` + Eloquent timestamps, regardless of actual schema

- `src/Generators/Backend/Models/ModelGenerator.php` / `src/Generators/Templates/backend/model.stub`: the model stub unconditionally `use HasFactory, SoftDeletes;`, and `generateTimestamps()` inferred "no timestamps" by checking whether `created_at`/`created_date` appeared in `$this->fields` — but `created_at`/`updated_at`/`deleted_at` are deliberately excluded from `$config['columns']` (see `SchemaIntrospector::SKIP_COLUMNS`), so their absence from `$this->fields` was never actual evidence the table lacked them. In practice this meant `public $timestamps = false;` was emitted on almost every generated model regardless of its real migration (confirmed on `LedgerTransactionTypes`, `LedgerTransactions`, `MemberPhones` — all of which DO have real timestamp columns), while `SoftDeletes` was applied even to models with no `deleted_at` column at all (confirmed on `LedgerTransactionsModel` in both BACKEND and MOBILE_APP), breaking every query on those models with an "unknown column deleted_at" error.
- New `ModelGenerator::hasTimestamps()`/`hasSoftDeletes()` trust an explicit `$config['has_timestamps']`/`$config['has_soft_deletes']` flag when the caller provides one, and otherwise default to the Laravel migration convention (timestamps present, soft deletes absent). New `generateSoftDeletesImport()`/`generateSoftDeletesTrait()` conditionally emit the `use Illuminate\Database\Eloquent\SoftDeletes;` import and the `, SoftDeletes` trait fragment; `model.stub` now uses `[[softDeletesImport]]`/`[[softDeletesTrait]]` placeholders instead of hardcoding both unconditionally.
- `src/Schema/SchemaIntrospector.php`: new `hasTimestamps()`/`hasSoftDeletes()` inspect the raw table's actual columns (via a new private `hasRawColumn()` helper) for `created_at`+`updated_at` / `deleted_at`, independent of the `SKIP_COLUMNS`-filtered `columns()` output.
- `src/Schema/IntrospectionToConfig.php`: `build()` now accepts optional `$meta['has_timestamps']`/`$meta['has_soft_deletes']` and writes them into the generated config as `has_timestamps`/`has_soft_deletes` (defaulting to `true`/`false` respectively when the caller omits them), so `SchemaIntrospector::hasTimestamps()`/`hasSoftDeletes()` output can flow through to `ModelGenerator`.
- Added regression coverage: `tests/Unit/Generators/Backend/Models/ModelGeneratorTest.php` (new), `tests/Unit/Schema/SchemaIntrospectorNormalizeTypeTest.php` (new — also covers the `normalizeType()` fix below) — see [Testing](README.md#testing).
- **Caveat — action required in consuming projects.** `has_timestamps`/`has_soft_deletes` only reach `ModelGenerator` if a caller actually passes them through. Each consuming project's own `ModuleScaffolder` wrapper (THC_V2, SYSTEM_SHELL, PROJECT_GENERATOR_SYSTEMv1 — not owned by this package) needs updating to call the new `SchemaIntrospector::hasTimestamps()`/`hasSoftDeletes()` and pass the results into `IntrospectionToConfig::build()`'s `$meta`. Until that wrapper update lands in a given consumer, running a `--force` regeneration of an already-soft-deleted module in that project will silently fall back to the `has_soft_deletes = false` default and drop the `SoftDeletes` trait from the regenerated model. This is being fixed separately in each consumer project right now — called out here so it isn't lost.

### Fixed — FK-guessed `belongsTo()` relationships generated for nonexistent modules

- `src/Generators/Backend/Models/ModelGenerator.php`: when a column has no real FK metadata (`$field['relatedModule']` empty), `generateRelationships()` guesses a related module name from the column name alone (strip `_id`, pluralize, StudlyCase — e.g. `status_id` → `Statuses`) and previously emitted a `belongsTo()` for it unconditionally. A column merely NAMED like a foreign key (e.g. `external_trans_id`, a plain string idempotency key with no real FK) therefore got a guessed relation (`ExternalTrans`) pointing at a module that was never generated.
- New `guessedModuleExists()` resolves a guessed module name against the array-based module registry, the generated project's own registry files (`registry_core.json`/`registry.json`), the actual module directory structure, and (for backward compatibility) the generator system's own registry — mirroring the resolution chain `determineModuleGroup()` already uses. A guessed (as opposed to FK-metadata-resolved) relation is now skipped silently unless the guessed module resolves via one of these paths; relations built from real FK metadata (`$field['relatedModule']` non-empty) are unaffected either way.
- This is a symptom of the `SchemaIntrospector::normalizeType()` bug below — see that entry for the root cause.
- Added regression coverage: `tests/Unit/Generators/Backend/Models/ModelGeneratorTest.php` (new, shared with the timestamps/soft-deletes fix above) — see [Testing](README.md#testing).

### Fixed — literal `[[connection]]`/`[[timestamps]]` placeholder text leaking into generated mobile models

- `src/Generators/MobileApp/Backend/Models/MobileModelGenerator.php`: `mobile_app/backend/model.stub` references `[[connection]]`/`[[timestamps]]` placeholders (added in v2.9.0 for the backend model stub's equivalent), but `generate()`'s replacement array never included them for the MOBILE_APP model generator — every generated mobile model shipped with the literal text `[[connection]]`/`[[timestamps]]` still sitting in the file. Confirmed in `LedgerTransactionsModel.php` and `MemberPhonesModel.php` under MOBILE_APP.
- New `generateConnection()` emits `protected $connection = '...';` when `$config['connection']` is set, otherwise an empty string (mirroring the backend model generator). New `generateTimestamps()` always returns `''` — mobile migrations always emit `$table->timestamps()` unconditionally as part of the offline-sync architecture (see `migration.stub`/`MobileMigrationGenerator`), so Eloquent's default `$timestamps = true` is always correct there and no override is ever needed; the placeholder is kept (resolving to empty) for symmetry with the backend stub and as an escape hatch, but must be substituted rather than left as literal template text.
- Added regression coverage: `tests/Unit/Generators/MobileApp/Backend/Models/MobileModelGeneratorTest.php` (new) — see [Testing](README.md#testing).

### Fixed — `_id`-suffixed columns misclassified as foreign keys by name alone

- `src/Schema/SchemaIntrospector.php`: `normalizeType()` classified a column as `foreignId` if `$isFk` was true **or** the column name simply ended in `_id` (`Str::endsWith($colName, '_id')`) — the latter check fired for plain string/business columns that merely happen to end in `_id` with no matching target table (e.g. `external_trans_id`, a VARCHAR idempotency key). `$isFk` is already true whenever a genuine DB foreign key constraint exists **or** the "ends in `_id`" naming convention matched an ACTUALLY EXISTING target table (`inferFkByConvention()`), so the extra bare `Str::endsWith()` check was redundant for real FKs and actively wrong for everything else — this mislabeling is the root cause of the FK-guessed relationship bug above. Column naming alone must never override the actual DB column type; the bare-suffix check was removed and `normalizeType()` now trusts `$isFk` only.
- Added regression coverage: `tests/Unit/Schema/SchemaIntrospectorNormalizeTypeTest.php` (new) — see [Testing](README.md#testing).

### Added — regression coverage guarding page-wrapper/child-form stub prop consistency

- `tests/Unit/Generators/Frontend/PageFormPropConsistencyTest.php` (new): reads the raw `.stub` files directly and, for each page-wrapper/child-form stub pair (`edit/page.stub` ↔ `edit/form.stub`, `delete/page.stub` ↔ `delete/form.stub`, `view/details_layout.stub`'s Edit/Delete modals ↔ `edit/form.stub`/`delete/form.stub`, `create/page.stub` ↔ `create/form.stub`), asserts that every REQUIRED prop the child stub declares via `defineProps()` is passed under the exact same name by the page-wrapper's opening tag for that child component.
- Guards against a real, previously-unnoticed bug: from commit `96b4150` (v2.7.0, ~2026-06-16) through commit `9ce850c` (~2026-07-15), `edit/page.stub` rendered its EditForm child with `:id="id"` while `edit/form.stub`'s `defineProps()` declared the prop as `uuid` (required) — every module scaffolded during that ~month-long window got a broken standalone `/{module}/:uuid/edit` route, since the form's required `uuid` prop was always undefined there, even though the Edit MODAL flow (`view/details_layout.stub`, which correctly passes `:uuid="recordId"`) kept working the whole time. The bug was fixed incidentally by an unrelated styling commit, with no changelog entry and no test coverage at the time — this test exists so the same class of page/child prop-name drift can't reintroduce itself silently again.
- See [Testing](README.md#testing).

## v2.10.7 — 2026-07-19

### Fixed — generated ID column was last and never sortable/filterable; "uuid" was never backend-filterable at all

- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateColumnsFromListFields()`): the ID column entry (`id` or `uuid`, depending on `id_type`) was built and appended to `$columns` **after** every schema-derived field — landing right before the `actions` column instead of first — and was hardcoded `sortable: false`. Every freshly scaffolded module therefore buried its ID column at the end of the table and made it the one column a user could never sort by, unlike every other column (which gets `sortable` from its field config). The ID column entry is now built and appended **first**, before the field loop, and is always `sortable: true`. The `actions` column logic is unchanged and still lands last.
- `src/Generators/Backend/Services/BaseServiceGenerator.php`: `generateFilterableFields()` and `generateSortableFields()` never included `id`/`uuid` in the backend allow-lists (`$filterableFields`/`$sortableFields` on the generated `ListService`), so `?filters[id]=...`/`?params[sort]=id` were silently rejected by `ListServiceTrait::applyFilters()`/`applySorting()` (both gate on `in_array($column, $allowList)`) even after the frontend fix above made the column clickable. Both methods now always append `id` (both methods) and, for `generateFilterableFields()` only, `uuid` — via new private helpers `collectConfiguredFilterableFields()` (extracts the raw, pre-system-field key list shared by both methods' existing config-reading logic, unchanged) and `appendSystemFields()` (adds any not already present, preserving order, so a module that already explicitly configures `id`/`uuid` is never double-added). `uuid` is deliberately never added to `$sortableFields` — it is filterable-only, never a sortable/visible column.
- `generateFilterFields()` (same file) builds `$data['filterFields']`, the array `DataTableFilter.vue` renders its filter UI controls from — a **separate** mechanism from `$filterableFields` (confirmed by reading `ListServiceTrait`, `ListTable.vue`, and `DataTableFilter.vue`: sorting/filtering allow-lists gate the API, `$data['filterFields']` alone drives what filter *controls* the frontend shows). It now always appends an `{ 'key' => 'id', 'label' => 'ID', 'type' => 'text' }` entry (guarded against duplicates the same way) so ID gets an actual filter control, matching what every other filterable column already gets. `uuid` is deliberately **never** added here — this is what makes it "backend-filterable but hidden": queryable via the API but never rendered as a visible column (per the frontend fix above) or a user-facing filter control. This isn't a new mechanism — it's the same `$filterableFields`-vs-`$data['filterFields']` split every other filterable field already relies on, just applied to `uuid` on purpose.
- Confirmed via a fresh end-to-end scaffold (`php artisan make:module System/Masters/ZzzGeneratorVerifyTest3 --table=zzz_generator_verify_test3 --force` in the consuming project) that the real generated output matches: `columns` = `[id (sortable, first), name (fixed/primary), status_id, actions (last)]` with no `uuid` entry; `$filterableFields = ['name', 'status_id', 'id', 'uuid']`; `$sortableFields = ['created_at', 'name', 'status_id', 'id']` (no `uuid`); `$data['filterFields']` includes `name`, `status_id`, and `id` (no `uuid`). Cleaned up afterward (generated dirs, DB table, and the four registry/menu JSON files restored to their exact pre-test byte content).
- **Non-breaking.** `generate()`'s no-overwrite-without-`--force` guard means already-generated modules are untouched by this fix. Only newly scaffolded modules — and any module regenerated with `--force` — pick up the reordered/sortable ID column and the `id`/`uuid` allow-list entries automatically.
- Also fixed the pre-existing `ItemCategories` module in the consuming project (its `ItemCategoriesListPage.vue` `columns` array had `id` last with `sortable: false`; its `ItemCategoriesListService.php` had neither `id` nor `uuid` in `$filterableFields`/`$sortableFields`, and no `id` entry in `$data['filterFields']`) — done at the consuming-project level, not by this package, for the same reason as every prior module-name-leak/actions-column fix: the no-overwrite guard means a generator-engine version bump alone never touches already-generated files.
- Added regression coverage: extended `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` (all four existing cases updated for the new ordering/sortable value, still covering normal fields, zero fields, an already-present `"actions"` field, and `id_type: uuid`) and added `tests/Unit/Generators/Backend/Services/BaseServiceGeneratorTest.php` (new — 11 tests covering `generateFilterableFields()`, `generateSortableFields()`, and `generateFilterFields()`: `id`/`uuid` always present where expected, never duplicated when already configured, `uuid` never leaking into sortable fields or the frontend filter-fields output) — see [Testing](README.md#testing).

## v2.10.6 — 2026-07-19

### Fixed — raw PascalCase module names leaked unspaced into human-facing text

- Every generator that builds display text (menu labels, page/route titles, locale strings, permission titles) interpolated `$this->moduleName` — a raw PascalCase identifier like `ItemCategories` or `ZzzGeneratorVerifyTest` — directly into the string. A multi-word module name therefore rendered as a concatenated blob (`"page_create": "Create ZzzGeneratorVerifyTest"`) instead of readable English (`"Create Zzz Generator Verify Test"`).
- Added `BaseGenerator::humanize(string $name): string`, a thin wrapper around `Illuminate\Support\Str::headline()` (verified against real multi-word names — `Str::headline('ItemCategories')` → `"Item Categories"`, `Str::headline('ZzzGeneratorVerifyTest')` → `"Zzz Generator Verify Test"`, `Str::headline('UserLocations')` → `"User Locations"`) — spaces a raw PascalCase name into Title Case without touching grammatical number.
- Confirmed the real singular/plural convention against every hand-completed module (Users, Roles, Locations, Countries, Wards, Permissions, LocationTypes, UserLocations, Media, Broadcasts) before fixing: list-style text (page/menu list title) stays **plural** ("Roles", "Item Categories"); action/detail text (Create/Edit/Delete/Details/History, and every button/message in the locale file) uses the **singular** form ("Create Role", "Role Details", "Delete Item Category"). Auto-derived permission `title`/`description` are the one exception — real seeder data (`Bulk Actions on Users`, and the buggy-but-real `List ItemCategories`) confirms these stay **plural** throughout, matching admin-ACL naming conventions.
- Applied `humanize()` everywhere the raw-name leak was found:
  - `src/Generators/Frontend/FrontendLocaleGenerator.php`: the `title` key (plural) and every `create_btn`/`edit_btn`/`delete_btn`/`details_title`/`page_*`/`*_success`/`*_error`/`failed_load` key (singular) in both `en.json` and `sw.json`.
  - `src/Generators/Frontend/MenusJsonGenerator.php`: the default (no `menu_config`) menu item title, `createSimpleMenuItem()`, `createFromConfigItems()`'s fallback titles, and `createNestedMenuItem()`'s parent title (plural) plus its "All X" (plural)/"Create X" (singular) sub-item titles. `removeModuleFromMenus()`/`countModuleMenus()`/`moduleExistsInMenus()` previously matched existing menu entries by comparing `item['title']` against the raw `moduleName` — now compare against the same humanized title that gets written, so re-running (or `--force` regenerating) a module still replaces its one existing menu entry instead of duplicating it.
  - `src/Generators/Frontend/Routes/FrontendRoutesGenerator.php` and `src/Generators/MobileApp/Routes/MobileAppRoutesGenerator.php`: every `meta.title` (the browser tab title, per `router.ts`) — list stays plural, Create/Edit/Delete/Details/History/Overview switch to singular — plus the custom-feature/delegation tab route's label fallback (previously a raw `Str::studly()` feature name). Route `name:`/`permission:` fields are Vue Router/ACL identifiers, not display text, and are deliberately left untouched.
  - `src/Generators/Backend/Seeders/SeederGenerator.php`: the auto-derived CRUD/bulkAction/delegation/action permission `title`/`description` text (kept plural, per the confirmed convention). The permission `name`/`module` fields are identifiers — matched against route `meta.permission`, DB rows, and the Roles > Permissions tab's grouping key — and are deliberately left raw; humanizing them would require adding a separate display-label field, which is a larger, separate change (noted as a follow-up, not done here).
- **Non-breaking.** This only changes what freshly generated (or `--force`-regenerated) text looks like; it doesn't change any file-existence/overwrite behavior. Already-generated modules with hand-completed text (Roles, Users, Locations, Countries, Wards, Permissions, LocationTypes, UserLocations, Media, Broadcasts, etc.) are untouched — this fix was verified against exactly those modules to derive the convention it now replicates for freshly scaffolded ones.
- Added regression coverage: `tests/Unit/Generators/Frontend/FrontendLocaleGeneratorTest.php` (two new tests — a multi-word name and the plural-title/singular-action distinction), `tests/Unit/Generators/Frontend/MenusJsonGeneratorTest.php` (new), `tests/Unit/Generators/Frontend/Routes/FrontendRoutesGeneratorTest.php` (new), `tests/Unit/Generators/MobileApp/Routes/MobileAppRoutesGeneratorTest.php` (new), `tests/Unit/Generators/Backend/Seeders/SeederGeneratorTest.php` (new) — see [Testing](README.md#testing).
- End-to-end verified: scaffolded a fresh throwaway module (`php artisan make:module System/Masters/ZzzGeneratorVerifyTest2 --table=zzz_generator_verify_test2 --force` against a real DB table) in a consuming project and inspected the real generated `locales/en.json`, `routes.ts`, `menus.json`, and permission seeder data — all spaced correctly end-to-end. Cleaned up afterward (generated dirs, DB table, and the four registry/menu JSON files restored to their exact pre-test byte content).
- Also fixed the pre-existing `ItemCategories` module in the consuming project (its `locales/{en,sw}.json`, `routes.ts`, `menus.json` menu label, and permission seeder data all showed the raw unspaced "ItemCategories"/"ItemCategory" text from before this fix existed) — done at the consuming-project level, not by this package, since `generate()`'s no-overwrite-without-`--force` guard means already-generated modules are never touched by a generator-engine version bump alone.

## v2.10.5 — 2026-07-19

### Fixed — generated modal titles/save button/view-tabs rendered raw i18n keys instead of translated text

- `src/Generators/Frontend/FrontendLocaleGenerator.php`: the frontend stub templates (`list/page.stub`, `view/details_layout.stub`, `view/modal.stub`) unconditionally emit `$t('{route}.page_create')`, `.page_edit`, `.page_delete`, `.page_details`, `.tab_overview`, and `.tab_history` for every generated module's dialogs and view-modal tabs, and `BaseComponentGenerator::generateFormFooter('edit')` always emits `{{ isSubmitting ? $t('{route}.saving') : $t('{route}.save_changes') }}` for the edit-form submit button. `FrontendLocaleGenerator`'s `$enKeys`/`$swKeys` never produced matching locale entries, so every freshly scaffolded module's quick-edit modal title and save button — and view-modal tab labels — rendered the literal key string (e.g. `item-categories.page_edit`, `item-categories.save_changes`) instead of translated text. Field-level labels (driven by the separate `col_*` loop) were unaffected, which is why the bug looked like a partial, easy-to-miss gap rather than a total i18n failure.
- Confirmed as a generator-level gap, not a one-off: `ItemCategories/locales/{en,sw}.json` (scaffolded fresh) was missing all 8 keys (`page_create`, `page_edit`, `page_delete`, `page_details`, `save_changes`, `saving`, `tab_overview`, `tab_history`). Older modules (Roles, Locations) have working modals only because a human hand-added these same 8 keys after generation — `bulk_activate`/`bulk_deactivate`/`col_status_id` in ItemCategories' locale file were similarly hand-added later (v2.10.4-era UX fix) and are not — and still are not — generator output; they remain module-specific.
- `$enKeys`/`$swKeys` now include all 8 keys with real English/Swahili text (not placeholders): `page_create`/`page_edit`/`page_delete`/`page_details` reuse the existing `create_btn`/`edit_btn`/`delete_btn`/`details_title` phrasing, `saving`/`save_changes` match the existing "Saving.../Save Changes" convention already hand-added in Roles/Locations/Users, and `tab_overview`/`tab_history` are "Overview"/"History" ("Muhtasari"/"Historia" in Swahili).
- **Non-breaking.** `generate()` only writes `locales/en.json`/`sw.json` when the file doesn't already exist (or `--force`), so already-generated modules with hand-completed locale files (Roles, Locations, Users, Permissions, LocationTypes, UserLocations, etc.) are untouched by this fix. Only newly scaffolded modules — and any module regenerated with `--force` — pick up the full key set automatically.
- Added `tests/Unit/Generators/Frontend/FrontendLocaleGeneratorTest.php` (see [Testing](README.md#testing)): asserts `generate()` emits every standard key required by the stub templates (non-blank, in both `en.json`/`sw.json`), that dynamic `col_*` keys still work alongside the standard set, and that files are not clobbered without `force`.

## v2.10.4 — 2026-07-19

### Fixed — generated list columns never included the actions column, so View/Edit/Delete buttons silently never rendered

- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateColumnsFromListFields()`): the `list/page.stub` template always renders a `<template #cell-actions="{ row }">` slot with View/Edit/Delete buttons, but the generated `columns` array never declared a matching `{ key: "actions", ... }` entry. The report-table component only renders a cell slot for a key that exists in `columns`, so the slot — and its buttons — silently never appeared in any freshly scaffolded module. Confirmed missing in ItemCategories until hand-patched; present only because it had been hand-added in Users, Locations, Roles, Permissions, LocationTypes, and UserLocations (verified `key`/`label`/`align` identical across all of them, with `width` varying 60–120px depending on button count).
- `generateColumnsFromListFields()` now appends `{ key: "actions", label: "", width: 120, align: 'right' }` after the ID column, matching that established hand-added convention (120px chosen as the safe default). A guard checks the caller's field list first and skips the auto-append if a field keyed `"actions"` is already present, so it never double-adds a column. Zero-field modules still get `[ID, actions]` with no crash, and this applies identically whether `id_type` is `autoincrement` or `uuid`.
- **Non-breaking.** This only adds a previously-missing column to the generated output. Modules that already had the actions column hand-added are unaffected — the guard detects their existing `"actions"` field and leaves it alone. No consuming-project-side action is required.
- Added automated regression coverage: `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` (see [Testing](README.md#testing)) — the first PHPUnit test in this package, covering normal fields, zero fields, an already-present `"actions"` field, and `id_type: uuid`.

## v2.10.3 — 2026-07-16

### Fixed — generated EditForm warned on Vue prop type for nullable fields

- `src/Generators/Templates/frontend/features/edit/form.stub`: the `onMounted` data-loading block merged `response.data` (and, after it, `props.defaults`) straight into `form.value` with no null-guarding. Any nullable database column comes back as `null` from the view/show endpoint, gets merged verbatim into `form`, and then trips a Vue prop-type warning wherever it's bound to `InputField` (whose `modelValue` prop only accepts `String | Number`, not `null`). Present in every already-generated EditForm across multiple modules and both consuming projects. Now, immediately after the `response.data` merge and the subsequent `props.defaults` merge (still before `isLoading.value = false`), a generic pass walks `Object.keys(form.value)` and coerces any `null` value to `''` — a blanket loop, not a hand-maintained per-field list, so it stays correct regardless of a module's actual schema.
- `src/Generators/Templates/frontend/features/create/form.stub` was checked and does not need the same fix: its only external merge is `props.defaults`, which is always populated either from URL query-string values (already strings/numbers, parsed in `create/page.stub`) or from hardcoded foreign-key placeholders (e.g. `delegation/tab.stub`'s `createDefaults`/`editDefaults`, built from `uuid`/config, not a raw DB row) — never from a `response.data` view/show payload that could carry a raw nullable DB column. Left unchanged.
- This fix is fully self-contained in generator-engine — no consuming-project-side action is needed this time (unlike the AppDialog fix in v2.10.2).

## v2.10.2 — 2026-07-16

### Fixed — generated ViewModal couldn't establish a bounded flex-scroll layout

- `src/Generators/Templates/frontend/features/view/modal.stub`: the loaded-state content (tab bar, tab panels, footer) was wrapped in a bare `<template v-else-if="record">` fragment — a fragment has no rendered element, so it cannot carry the `flex flex-col` classes needed for its `flex-1 min-h-0` tab-panels child to actually size against a bounded parent (nested `flex-1`/`min-h-0` requires an immediate flex parent to have any effect). Now wraps the same three sibling divs (tab bar, tab panels, footer) in a real `<div v-else-if="record" class="flex flex-col flex-1 min-h-0">` element; no child markup or classes changed. The `v-if="isLoading"` block above it is untouched.
- **Action required in consuming projects:** this only completes half of the fix. Each consuming project's own `AppDialog.vue` (not owned by this package) must separately add `display:flex` (e.g. `flex flex-col`) to its slot-wrapper div — nested `flex-1`/`min-h-0` content dropped into the dialog cannot size correctly without a flex parent at that level too. This package cannot propagate that change automatically since `AppDialog.vue` lives in each consumer, not here.

## v2.10.1 — 2026-07-16

### Fixed — ViewModal "open full page" link no longer 404s

- `src/Generators/Templates/frontend/features/view/modal.stub`: the footer `<router-link :to="`/[[moduleRoute]]/${uuid}`">` pointed at a bare `/{module}/{uuid}` path, but `FrontendRoutesGenerator`'s `view` route block always registers `/{module}/:uuid/details` (no bare-path route exists). Every module generated with the `view` feature therefore got a ViewModal "open full page" link that 404s. Now emits `` `/[[moduleRoute]]/${uuid}/details` ``, matching the convention already used by the sibling `list/component.stub` and `view/details_layout.stub` stubs. `mobile_app/features/action/modal.stub` was checked and does not construct any detail-page link (it's a plain action-submission modal), so it was left unchanged.

## v2.9.3 — 2026-06-28

### Fixed — generated list columns now match the report-table `ReportColumn` contract (blank headers)

- The list page/component stubs import `ReportColumn` from `@/components/report-table` and type columns as `ReportColumn[]`, but `BaseComponentGenerator::generateColumnsFromListFields()` still emitted the old DataTable shape (`title` / `class` / `data` / `primaryHiddenClass`). `ReportColumn`/`ReportTable` read the header text from **`label`**, so the generated `title:` left every column header blank (the i18n keys resolved fine — they were just assigned to a property the table ignores). Columns now emit `{ key, label: t(...), sortable, width, [fixed] }`: pinned primary column, pixel widths, and no obsolete responsive classes (the report-table handles horizontal scroll + column visibility). The duplicate builder in `FrontendGenerator` is dead code (that class no longer generates list output) and was left as-is.

## v2.9.2 — 2026-06-28

### Fixed — generated ListService now emits filter fields

- `BaseServiceGenerator::generateFilterFields()` returned an empty array (`$data['filterFields'] = []`) whenever `features.backend.list.filterFields` was unset — always the case for introspected modules — so the frontend `DataTableFilter` rendered no filter UI. It now falls back to deriving plain `text` filters from `features.backend.list.filterableFields`. FK→`select` (with an options query) remains a manual refinement.

### Note — blank column headers were an app-side bug (no engine change)

- Generated list locales lacked `col_<field>` keys (→ blank column titles) because the consuming app's `ModuleScaffolder` constructed `FrontendLocaleGenerator` **without** passing `$config`, so the generator received an empty config. The `col_*` emission logic in `FrontendLocaleGenerator` was already correct — the fix is in the app (`new FrontendLocaleGenerator($name, $group, $config)`). Documented under generator conventions.

## v2.9.1 — 2026-06-28

### Fixed — escaped backticks in generated EditForm router-link

- `BaseComponentGenerator::generateFormFooter('edit')` built the modal "open full" `<router-link :to>` in PHP using `` \` `` (escaped backticks). PHP double-quoted strings don't treat the backtick as special, so the backslash was emitted verbatim — producing an invalid JS template literal (`:to="\`/route/${uuid}/edit\`"`) that breaks the Vite build. Now emits plain backticks. This affected every generated module's EditForm; all other `:to` bindings (which live in `.stub` files) were already correct. Only `$` needs escaping (`\$`) so PHP doesn't interpolate `${uuid}`.

## v2.9.0 — 2026-06-28

### Added — Explicit `[[connection]]` + legacy-timestamp model placeholders

- `src/Generators/Templates/backend/model.stub`, `model_users.stub`, and `src/Generators/Templates/mobile_app/backend/model.stub`: added `[[connection]]` and `[[timestamps]]` placeholders immediately after the `protected $table` line.
- `ModelGenerator::generateConnection()`: emits `protected $connection = '...'` when `config['connection']` or `config('generator.default_connection')` is set; otherwise empty string.
- `ModelGenerator::generateTimestamps()`: emits `const CREATED_AT`/`UPDATED_AT` for legacy `created_date`/`modified_date` columns, `public $timestamps = false` when no timestamp columns exist, or empty string for standard columns.
- `ModelGenerator::generateCasts()`: `created_date` and `modified_date` are now excluded from casts/fillable generation alongside `created_at`/`updated_at`.

### Added — Optional connection on `SchemaIntrospector`

- Constructor now accepts an optional `?string $connection = null` second parameter.
- New private `schema()` helper routes all instance-level Schema calls through the specified connection (or the default connection when null). All existing callers remain backward-compatible.

### Added — `SchemaDdlExtractor` (new class)

- `src/Schema/SchemaDdlExtractor.php`: extracts a faithful `CREATE TABLE` DDL for an existing table.
- Driver dispatch: MySQL/MariaDB (`SHOW CREATE TABLE`), SQLite (`sqlite_master`), PostgreSQL (reconstructed from `information_schema` + `pg_constraint` + `pg_indexes`).
- Throws `RuntimeException` on unsupported drivers or missing tables.

### Added — `DdlRenderer` (new class, fallback for brand-new tables)

- `src/Schema/DdlRenderer.php`: renders a skeleton `CREATE TABLE` DDL from normalized column metadata via `DdlRenderer::fromColumns()`. Includes leading SQL comment noting it is a skeleton that requires review.

### Added — Connection written to `module.json` and registry entries

- `ModuleConfigGenerator`: writes `'connection' => $this->config['connection'] ?? null` into the metadata block of `module.json`.
- `RegistryGenerator::getRegistryEntry()`: includes `'connection'` in every registry entry.

### Added — Publishable package config + stubs

- `config/generator.php`: new publishable config with `default_connection`, `generate_migrations`, and `schemas_path` keys.
- `GeneratorEngineServiceProvider::register()`: merges package config via `mergeConfigFrom`.
- `GeneratorEngineServiceProvider::boot()`: publishes config as `generator-config` tag and stubs as `generator-stubs` tag.

### Added — App-level stub override in `BaseGenerator::getStubPath()`

- Checks for a stub file at `base_path("stubs/generator/{subdir}/{stubName}.stub")` before falling back to the vendor path. `base_path` usage is guarded with `function_exists`.

---

## v2.8.0 — 2026-06-17

### Added — `features.mobile_app.mode` to gate sync-file generation

**`schema/module-config.schema.json`**

- New `mode` field under `features.mobile_app`: `"online"` (default) | `"offline"` | `"both"`.
- When `mode` is `"online"`, `SyncService` and `SyncComposable` are not generated — the module fetches data live from the API only.
- When `mode` is `"offline"` or `"both"`, both sync files are generated as before.

**`src/Generators/MobileApp/Backend/Services/Sync/MobileSyncServiceGenerator.php`** and **`MobileSyncComposableGenerator.php`**

- Both generators now check `$this->config['features']['mobile_app']['mode'] ?? 'online'` and skip output unless the mode is `offline` or `both`.

### Fixed — Sync composable output path

**`src/Generators/MobileApp/Backend/Services/Sync/MobileSyncComposableGenerator.php`**

- `use{Module}Sync.ts` is now written to the module-scoped path (`PathManager::getMobileAppModulePath(…)/composables/use{Module}Sync.ts`) instead of the shared `resources/js/src/composables/` directory.

### Fixed — Stub paths renamed across all mobile backend generators

All mobile backend generators were updated to load stubs from their canonical short names:

| Generator | Old stub path | New stub path |
|-----------|--------------|--------------|
| `MobileMigrationGenerator` | `migrations/create_table` | `migration` |
| `MobileApiRoutesGenerator` | `routes/api` | `routes` |
| `MobileSeederGenerator` | `seeders/seeder_data` | `seeder-data` |
| `MobileCreateServiceGenerator` | `services/create_service` | `services/create` |
| `MobileEditServiceGenerator` | `services/edit_service` | `services/edit` |
| `MobileDeleteServiceGenerator` | `services/delete_service` | `services/delete` |
| `MobileDeleteCheckServiceGenerator` | `services/delete_check_service` | `services/delete-check` |
| `MobileViewServiceGenerator` | `services/view_service` | `services/view` |
| `MobileListServiceGenerator` | `services/list_service` | `services/list` |
| `MobileActivityListServiceGenerator` | `services/activity_list_service` | `services/activity-list` |
| `MobileBulkActionServiceGenerator` | `services/bulk_action_service` | `services/bulk-action` |

### Docs

- `docs/mobile-config.md` — replaced "Offline Sync" section with a new "Sync Mode" section that documents the `mode` field, its three values, how to set it, and what the generated files do.
- `docs/features-config.md` — added `mode` row to the `features.mobile_app` reference table.

---

## v2.6.4 — 2026-06-14

### Changed — i18n column titles in generated list components

**`src/Generators/Templates/frontend/features/list/component.stub`**

- `title="[[ModuleName]]"` → `:title="$t('[[moduleRoute]].title')"` — list component title now uses vue-i18n.
- View button text `View` → `{{ $t('common.view') }}` — matches the global common key.
- `import { ref }` → `import { ref, computed }` — `computed` added for reactive column array.
- `import { useI18n } from 'vue-i18n'` added.
- `const { t } = useI18n()` added after `usePermissions()`.
- `const columns: Column[] = [...]` → `const columns = computed<Column[]>(() => [...])` — columns react to locale changes.
- `const bulkActions: BulkAction[] = [...]` → `const bulkActions = computed<BulkAction[]>(() => [...])` — same pattern for bulk actions.

**`src/Generators/Frontend/Components/BaseComponentGenerator.php`**

- `generateColumnsFromListFields`: column `title` values now emit `t('module.col_fieldkey')` instead of a hardcoded English string. The module route is derived from `Str::kebab($this->moduleName)` at generation time so the emitted key is concrete (e.g. `t('products.col_name')`), not a placeholder.
- `generatePrimaryCellContentFromListFields`: mobile responsive sub-labels now emit `{{ $t('module.col_fieldkey') }}` instead of a hardcoded English label string.

**`src/Generators/Frontend/FrontendLocaleGenerator.php`**

- Now reads `config.features.frontend.list.fields` and emits one `col_{field_key}` entry per list field into both `en.json` and `sw.json`. The English label is derived from the field's `title`/`label` or from humanising the field key (stripping `_id`/`_at` suffixes and title-casing). Swahili defaults to the English value for human translators to refine.

---

## v2.6.2 — 2026-06-06

### Added — VitePress documentation site and GitHub Pages CI

- `docs/index.md` — VitePress home page with hero section, feature cards, and full documentation map.
- `docs/.vitepress/config.ts` — sidebar covering all 9 reference pages; `base` set to `/generator-engine/` for GitHub Pages.
- `docs/package.json` — VitePress `^1.6.3` dependency with `"type": "module"`.
- `docs/CHANGELOG.md` — this file now linked from the sidebar.
- `.github/workflows/deploy-docs.yml` — GitHub Actions workflow: builds VitePress on every push to `main` that touches `docs/**` and deploys the output to GitHub Pages via `actions/deploy-pages`.
- Fixed `docs/ux-blueprint.md`: escaped <code v-pre>{{record.field}}</code> with `v-pre` to prevent Vue SSR interpolation during static build.

**Live docs:** `https://joelnjoshkibona.github.io/generator-engine/`

---

## v2.6.1 — 2026-06-06

### Added — `FrontendLocaleGenerator`

New class: `Blutrixx\GeneratorEngine\Generators\Frontend\FrontendLocaleGenerator`

Generates per-module i18n locale files alongside all other frontend output so every scaffolded module is immediately translatable without manual key wiring.

**Output** (at `{frontendModulePath}/locales/`):

| File | Content |
|------|---------|
| `en.json` | English strings for all generated pages (list, create, edit, delete, view) |
| `sw.json` | Swahili strings seeded with working defaults for human review |

**Key namespace** matches the `[[moduleRoute]]` stub placeholder (`Str::kebab` of the module name), so `import.meta.glob` in the consuming app's `i18n.ts` picks them up automatically.

**Keys emitted per module:**

```
title, singular, create_btn, edit_btn, delete_btn, details_title,
created_success, updated_success, deleted_success, restored_success,
created_error, updated_error, restored_error, failed_load,
delete_confirm_title, delete_confirm_message, delete_confirm_placeholder,
delete_confirm_keyword
```

`FrontendLocaleGenerator` is now used by `SYSTEM_SHELL`'s `make:module` command and by `PROJECT_GENERATOR_SYSTEMv1`'s `ModuleGenerationService` so all consumers get locale files automatically.

---

## v2.6.0 — 2026-06-06

### Added — vue-i18n v11 support across all frontend stubs

All 9 frontend feature stubs now use `$t()` / `t()` for every user-visible string instead of hardcoded English literals. Compatible with vue-i18n v11 `legacy: false` + composition API mode.

**Stubs updated:** `create/form.stub`, `create/page.stub`, `edit/form.stub`, `edit/page.stub`, `delete/form.stub`, `delete/page.stub`, `list/page.stub`, `view/details_layout.stub`, `view/overview.stub`

**Changes per stub:**

- `import { useI18n } from 'vue-i18n'` added to script block.
- `const { t } = useI18n()` added alongside other composable setup calls.
- Module-specific strings use namespaced keys: `t('[[moduleRoute]].created_success')`, `t('[[moduleRoute]].created_error')`, etc. — resolved at generation time by the `[[moduleRoute]]` substitution.
- Shared UI strings use `common.*` keys: `t('common.access_denied')`, `t('common.fix_validation')`, `t('common.back_to_list')`, `t('common.no_permission_form')`.
- Delete confirmation strings use `delete.*` keys: `t('delete.confirm_title')`, `t('delete.type_yes')`, etc.

`FrontendLocaleGenerator` (v2.6.1) generates the corresponding locale files so the keys resolve at runtime without manual setup.

---

## v2.5.1 — 2026-05-31

### Fixed — `details_layout.stub` overview tab re-fetch

Added a `watch` on `route.path` in `details_layout.stub` that calls `refresh()`
whenever the user navigates back to the `/overview` segment. Previously, returning
from a nested tab (e.g. history, a child record) left the overview panel showing
stale data until a manual page reload.

---

## v2.5.0 — 2026-05-27

### Added — Inline items full-stack support

Modules can now declare parent-child relational data (e.g. Order → Order Items) via a
top-level `inline_items` config key. The engine generates the full stack automatically.

**Schema** (`scaffold-blueprint.schema.json`):
- New `inline_items` map: `{ [moduleKey: string]: InlineItemConfig[] }`
- `InlineItemConfig` required keys: `key`, `child_module`, `child_group`, `parent_fk`,
  `primary_field`, `fields`. Optional: `label`, `inject_from_parent`, modal sizing/label options.
- `InlineItemField` keys: `key`, `label`, `type`, `required`, `table_width`, `show_in_table`,
  `splash_key`, `api_url`, `decimals`, `col_span`, `placeholder`, `default`

**Backend stubs** — three new placeholder blocks added to `create/service.stub`,
`edit/service.stub`, and `view/service.stub`:
- `[[inlineItemsExtract]]` — strips each inline key from `$data` before validation
- `[[inlineItemsSave]]` — in `CreateService.process()`: creates child records, injecting `parent_fk`
- `[[inlineItemsSync]]` — in `EditService.process()`: uuid-based `updateOrCreate` + `whereNotIn` delete for removed rows
- `[[inlineItemsLoad]]` — in `ViewService`: loads child records alongside the parent

**Frontend stubs** — two new placeholder blocks added to `create/form.stub` and `edit/form.stub`:
- `[[inlineItemsBlock]]` — renders `<InlineItemsComponent>` inside the form for each inline item
- `[[inlineItemsFieldDefs]]` — emits a typed `InlineItemField[]` const per item key

**`inject_from_parent`** — optional `[{ child_field, parent_field }]` array that propagates
parent model attributes to child records at save/sync time alongside `parent_fk`.

**`MakeModulesFromDb` integration** — the `inline_items` key from a scaffold blueprint is
injected into each module's `$config` before the generation pipeline runs.

---

### Fixed — Tab dialog modals use responsive widths

`delegation/tab.stub`: Create, Edit, View, and Delete dialogs now use
`sm:min-w-xl md:min-w-2xl lg:min-w-4xl` instead of a fixed `min-w-4xl`, so
modals scale gracefully on narrow viewports.

---

### Fixed — Details page action toolbar wraps on mobile

`details_layout.stub`: action button container changed from `flex items-center gap-3`
to `flex flex-wrap items-center gap-x-3 gap-y-2` so buttons wrap to a second line on
narrow screens instead of overflowing the viewport.

---

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

## v2.1.6 — 2026-05-17

### Added — Breadcrumbs on all page stubs; form footer inside Card; detail-view toolbar redesign

**Page stubs** (`create/page.stub`, `edit/page.stub`, `delete/page.stub`, `list/page.stub`, `view/details_layout.stub`):

- All page stubs now pass a `:breadcrumbs` prop to `MainLayout` so the navbar automatically renders the breadcrumb trail. The manually written back-button + `handleBack()` boilerplate is removed from every stub.

**`BaseComponentGenerator::generateFormFooter(string $formType = 'create'): string`**

New method that renders a `<div class="flex justify-end gap-3 px-4 py-3 border-t">` block containing a Cancel button and a loading-aware Submit button. The block is injected into the last `<Card>` section of every generated create and edit form, so Save/Cancel live inside the card visually rather than floating below it.

- `CreateFormGenerator` and `EditFormGenerator` now call `generateFormFooter()` when building form sections.
- Cancel button includes `v-if="modal"` so it is only visible when the form is embedded in a modal — standalone page users navigate away via the breadcrumb back-arrow instead.

**Detail-view header** (`view/details_layout.stub`):

- Header split into two rows: (1) title + status badge, (2) ghost-variant action toolbar.
- Delete action moved out of the top button row into a "More Actions" `DropdownMenu`, reducing visual clutter on record detail pages.

**Card sections:**

- All generated card wrappers switch to `rounded-none` with consistent `p-0` / `px-4 py-3` padding, matching the hand-authored module pages in SYSTEM_SHELL.

**Files changed:** `BaseComponentGenerator.php`, `CreateFormGenerator.php`, `EditFormGenerator.php`, and all 9 frontend feature stubs.

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
