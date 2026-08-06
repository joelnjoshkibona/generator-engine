# Changelog

## v2.31.0 — 2026-08-06

### Added — `model_hand_maintained`: opt a module's Model.php out of regeneration entirely

Some modules' Models can't be expressed by the generator's plain-`BaseModel` template at all — the motivating case is a project's `Users` module, which extends `Authenticatable` with Sanctum (`HasApiTokens`), `Notifiable`, `SoftDeletes`, and a project-specific `HasHistory` trait, plus a set of hand-written relationships and a custom `getFillable()`. A `model_users.stub` template for exactly this Authenticatable shape has existed in this package for a while, but `ModelGenerator::generate()` hardcoded `$modelType = 'default'` — that branch was **unreachable dead code**, and even reachable it has no slot for the extra traits/relationships a real project's auth model actually needs. Teaching the generic template to express one project's exact auth-model shape was judged not worth it for what is, in practice, always a single module per project.

New `ModuleConfigContract::isModelHandMaintained(array $config): bool` reads a plain top-level `model_hand_maintained` boolean (same shape as `has_soft_deletes`/`has_timestamps`/etc., but a developer *declaration* with no introspected default — defaults `false`). `ModelGenerator::generate()`'s final write now branches on it: `false` (the default, unchanged behavior for every other module) still uses `writeFile()` (skips on a plain re-run, overwrites on `--force` — the generator owns Model.php as always); `true` uses `writeFileOnce()` instead — the same "no `--force` escape hatch at all" primitive already used for the InlineItems wrapper component (v2.23.0) — so a hand-maintained `Model.php` survives every future `--force` regenerate of the rest of the module, indefinitely, not just until the next run.

Migrations needed no equivalent change — `MigrationGenerator` already unconditionally guards against regenerating an existing table's create-migration via `createMigrationAlreadyExists()` (a table-name match, independent of `--force`). Model-level Observers (a plain Eloquent Observer class, not a generator concept for any module) also need no protection — the generator never writes to that path regardless.

## v2.30.0 — 2026-08-06

### Added — FK select fields default to a permission-gated "Add New" quick-create, no config needed

`ApiSelect2Field.vue` (SYSTEM_SHELL/FRONTEND) has long supported a `show-add-button` prop + `#add-new` slot — a quick-create modal launched from inside a FK dropdown so picking, say, a Location doesn't mean abandoning the current form. The generator already had a fully-built `api-select-inline.stub` for this, complete with the right permission gate (`hasPermission('{RelatedModule}.create')`), but it was opt-in only (`inline_create: true` per field) and effectively unused — confirmed live that `UsersCreateForm.vue`'s inline-create wiring for `location_id`/`role_id` was hand-written, never generated. This release makes it the default.

Two real gaps had to close first, not just flipping `?? false` to `?? true`. The related-module derivation was a naive, unverified guess off the column name (`Str::studly(str_replace('_id', '', $key))` — `location_id` → `Location`, singular, wrong; this project's modules are conventionally plural). New `BaseComponentGenerator::resolveInlineCreateModule(array $field): ?string` reads the already-correct `$field['relatedModule']` instead (real FK/`foreign_table` introspection via `IntrospectionToConfig::resolveRelatedModule()`, now also threaded onto `buildFrontendFormFields()`'s FK branch, which only populated it for LIST fields before). And the upgrade check only ever ran for splash-backed `select` fields — a direct `api-select` field never checked `inline_create` at all; both branches (and the import-collection loop building `$inlineCreateImports`, which had the same direct-`api-select` gap) now go through the same resolver.

Eligibility, in order: `inline_create` isn't explicitly `false`; an explicit `create_form_module` is honored unverified (same precedence as any other explicit override); otherwise `relatedModule` must be non-empty and not equal to the FK's own module (self-referential FKs, e.g. a `parent_id` back onto the same module, are excluded — a self-embedding quick-create modal isn't a coherent UI); and — the one that actually matters — the resolved `{RelatedModule}CreateForm.vue` must exist on disk. That last check is deliberate: a static Vue `import` is resolved at build time and can't degrade gracefully the way `RelatedRecordLink`'s runtime string-prop lookup can, so an unresolvable target must never be wired in. It's also why this checks the filesystem rather than a `module.json` feature flag — confirmed live that `Users/Users/module.json`'s `features.frontend` is entirely empty `{}` despite `UsersCreateForm.vue` existing and working, so trusting that flag would have made the default silently inert for exactly the modules most likely to be real targets. A field pointing at an ineligible or unresolvable module just silently stays a plain `api-select`/`select` — no error, no broken import, no opt-out needed for the common case.

Live-verified against a scratch module with three FK shapes: a real FK into a module with a `CreateForm.vue` (upgraded correctly, `vue-tsc`-clean), a naming-convention FK into a nonexistent table (never detected as FK at all, stays a plain field), and a self-referential FK (detected, stays plain `api-select`, no add button) — all three confirmed correct in both `CreateForm.vue` and `EditForm.vue`. **Not retroactive** to already-generated modules. **Mobile forms are out of scope** — no equivalent mechanism exists in `src/Generators/MobileApp/` today.

## v2.29.0 — 2026-08-06

### Added — `bulk_actions`/`export`/`import` are now wired into the live-generated frontend, for both standalone list pages and delegation tabs

`features.backend.list.{bulk_actions,export,import}` have had working backends for a while, but zero frontend wiring — a module with `export: true` had a real, working `GET /{module}/list/export` route and nobody could ever click a button to hit it. Both `ListPageGenerator` (standalone `page.stub`) and `CustomFeatureTabComponentGenerator` (delegation `tab_action.stub`) now compute `enableExport`/`enableBulkActions`/`enableImport`/`bulkActionsLiteral` from `features.backend.list` and wire them onto `<CrudListPanel>` — a new shared `BaseComponentGenerator::generateBulkActionsLiteral()` builds the JS `BulkAction[]` array identically for both, so standalone and delegation can't drift. `export` reuses the surface's own `.list` permission (no dedicated `:export-permission` prop exists at all, matching how standalone `export` already reuses `.list` server-side); `bulk_actions`/`import` each get their own `.bulkAction`/`.import` permission.

**`CrudListPanel.vue`** (SYSTEM_SHELL/FRONTEND, hand-maintained) gained `enableImport`/`bulkActionPermission`/`importPermission` props and `effectiveEnableBulkActions`/`effectiveEnableImport` computeds combining the raw prop with a `hasPermission()` check — the bulk toolbar and import dialog live inside `ListTable.vue`'s own template (unlike create/edit/view/delete, which `CrudListPanel` gates directly), so gating happens by controlling the boolean props forwarded down rather than a `v-if` on a button `CrudListPanel` itself renders.

**`ListTable.vue`** gained the actual UI: an import `<Dialog>` (file input, dry-run checkbox, CSV/XLSX template-download buttons — ported from the reference `ListPageBareTable.vue`), a "select all N matching" banner for filter-mode bulk dispatch (new `selectionMode` ref, `'ids'` or `'filter'`), and testids throughout. Bulk-action and import results both route through the shared `BatchResultDrawer.vue` (gained `data-testid="batch-result-drawer"`). `useListPage.ts` gained `buildFilterPayload()`/`downloadImportTemplate()` wrappers around methods `BaseListService` already had.

**Backend**: `RoutesGenerator` registers export/bulk-action/import routes for delegations the same way it already did for standalone modules, gated on `operations.list.backend.{export,bulk_actions,import}`; `ControllerGenerator`/`DelegationServiceGenerator` gained the matching controller methods and thin-proxy service methods (`bulkAction()`/`importTemplate()`/`import()`), all correctly gated on `operations.list.enabled` too (a bug caught before shipping — the first pass generated these even when the parent `list` operation itself was disabled). `{Module}ListService::execute_bulkAction()`/native list service gained a `?Builder $query = null` param so a delegation's bulk-action can scope to the parent record, mirroring the existing `execute()` precedent. `ListServiceTrait::processBulkAction()`'s `mode=ids` branch now intersects requested ids against the injected `$query` when one is present — closes a real permission-scoping gap a delegation's own bulk-action route would otherwise have exposed (a user holding only the delegation-scoped permission could otherwise act on any record system-wide via `mode=ids`).

New `BulkActionConfigNormalizer` (mirrors `ActionConfigNormalizer`'s shape but — unlike it — filters out empty-key entries itself in `normalizeAll()`, fixing a confirmed inconsistency where one of three hand-rolled call sites forgot to). `SeederGenerator` gained a `{Module}.import` auto-seed block.

### Changed — delegation permission scoping reversed: `{RelatedModule}.{op}`, not `{ParentModule}.{Delegation}.{op}`

The v2.28.0 redesign gave every delegation operation its own independent permission (`Warehouses.StockMovements.edit`), deliberately separate from the related module's own standalone permission (`StockMovements.edit`). Revisited and reversed: a delegation now checks the related module's own permission directly, so accessing `StockMovements` standalone and through any parent's delegation tab check the exact same permission — one to grant, not one per delegation a module happens to be embedded in.

This was a small change, not a re-do of the redesign: the native `CreateForm`/`EditForm`/`DeleteForm`/`ViewModal` a delegation tab already renders have always had a built-in fallback of `hasPermission(props.permissionOverride ?? '[[PermissionBaseName]].{op}')` — for `StockMovements`, that fallback already *is* `StockMovements.{op}`. The delegation tab's own permission consts were the only thing overriding that default to the old formula, so the fix is computing those consts differently, not touching the forms or `CrudListPanel` plumbing. `DelegationConfigNormalizer::resolveOperationPermission()`'s signature changed from `($moduleName, $delegationStudly, $op, $endpointConfig)` to `($relatedModuleName, $op, $endpointConfig)`; the override mechanism (`operations.{op}.endpoint.permission`) is unchanged. `SeederGenerator`'s delegation-specific permission-seeding loop is removed entirely — every permission a delegation could ever check is already seeded independently by the related module's own standalone seeding, whenever that module has the corresponding feature enabled.

**Not retroactive** — no real SYSTEM_SHELL module has ever had a non-empty `delegations` config, so nothing existing needed migration.

### Removed — `ListComponentGenerator` (backend + mobile) and its stubs; dead legacy delegation stubs

`Frontend\Components\ListComponentGenerator` (and its `MobileApp` subclass) generated a `List.vue` wrapping `<ListTable>` directly — confirmed zero instantiation sites anywhere in the codebase, fully superseded by `page.stub`/`CrudListPanel.vue`. Deleted both classes, `frontend/features/list/component.stub`, `mobile_app/features/list/component.stub` (orphaned once the Mobile class was gone), and the unused legacy `frontend/features/delegation/{tab,modal}.stub` pair (predating the v2.28.0 redesign, zero generator references).

### Fixed

- **`RoutesGenerator`**: the standalone module's own export/import routes carried a stray manually-concatenated trailing `"s"` (`/{routePath}s/list/export` instead of `/{routePath}/list/export`) — 404'd on every click. Never noticed because nothing had export/import enabled before this release's own live verification.
- **`PhpUnitTestGenerator`**: independently mirrored the same stray-`"s"` route bug in its own generated export/import-template/import-upload test bodies AND in three controller-method docblock comments (`ControllerGenerator`) — every generated PHPUnit test for those routes would have 404'd even after the routes themselves were fixed.
- **`BulkActionServiceGenerator`**: `strtoupper(Str::snake($statusTarget))` mangled an already-ALL-CAPS `status_target` (e.g. `'RECEIVED'`, the documented, conventional shape) into `R_E_C_E_I_V_E_D` — a reference to a PHP constant that could never exist. `ModelGenerator::generateConstants()` emits constant names verbatim from the `constants` config key, with zero case transformation; `status_target` now matches that — used verbatim.
- **`ListServiceGenerator`**: generated `validateData()` never declared a `params.paginate` validation rule, so Laravel's `validator($data, $rules)->validate()` (this project's own mass-assignment-safety convention) silently stripped it — broke `export` (which sets `params.paginate = false` to force unpaginated results) for every module with export enabled, and the pre-existing `processListQuery()` "support `params[paginate]=false`" feature for anyone else relying on it.
- **`ControllerGenerator`**: the generated import controller method called `{Module}ListService::importData()` directly — `importData()` is `protected`, callable only from within the class hierarchy. Fixed to call the existing public `execute_import()` wrapper (the same one delegation callers already use).
- **`PhpUnitTestGenerator`**: `delegationHttpMethod()`'s fallback collapsed every non-list/view operation onto a single generic `'post'` default, instead of matching `DelegationConfigNormalizer::getOperationDefaults()`'s real per-op defaults (`edit`→`PUT`, `delete`→`DELETE`) — every generated delegation delete/edit test called `postJson()` against a route registered with `->delete(...)`/`->put(...)` and always failed with 405. The exact same bug class `DelegationConfigNormalizer` itself was fixed for in v2.28.0, independently re-introduced in this generator's own separate HTTP-verb fallback.

All five of the above were caught via a real, live end-to-end verification pass (a scratch `PurchaseOrders` module for `actions-suite`, and a scratch `Warehouses`/`StockMovements` delegation) — none had ever been exercised by a real generated module before, only by string-assertion unit tests against generated source text. Each is fixed with a regression test pinned to the correct behavior.

**Two SYSTEM_SHELL-side fixes, not generator-engine, found during the same pass**: `ModuleScaffolder::mergePersistedFields()` never carried `features.backend.list.export`/`.import` forward across a `--force` regenerate — same bug class as the already-fixed `bulk_actions`/`inline_items` gaps, just never extended to these two new keys; and `ListServiceTrait::importData()`'s response `array_merge()` had a silent key collision between a legacy scalar `failed` count and the shared `BatchOutcome`'s own array-shaped `failed` key, so a caller reading `data.failed` as a count got an array instead — dropped the redundant scalar `imported`/`failed` keys, since the frontend's `BatchOutcome` contract never read them. Also fixed `phpunit.xml`'s Feature-testsuite discovery, still filtering on the pre-test-splitting `CrudTest.php` suffix — silently excluded every module's tests generated or regenerated since the 2026-07-30 test-splitting refactor (not just this session's scratch modules) from `php artisan test`.

### Docs

`actions-suite` fixture extended with a machine-readable `actions_config.php` sidecar (mirrors `orders-suite/inline_items_config.php`'s pattern) covering `bulk_actions`/`export`/`import` alongside its existing `actions` config, plus README updates. `docs/delegations.md`/`docs/scaffold-blueprint.md` updated for the permission-formula reversal — the `permission` override key drops out of the worked examples since it now matches the new default.

Live-verified end-to-end: a scratch `Custom/PurchaseOrders` module (25 tests) and a scratch `Warehouses`/`StockMovements` delegation (71 tests combined) against a real MySQL database — export/import/bulk-action over real HTTP for both standalone and delegation-scoped surfaces, permission formula confirmed via generated routes + seeders + frontend consts, `vue-tsc --noEmit` clean. 620 tests, 0 failures.

## v2.28.0 — 2026-08-05

### Changed — delegation services are now thin proxies over the related module's own native services; frontend delegation tabs reuse the related module's own native Create/Edit/Delete/View components

Delegations (a related module rendered as a scoped tab on a parent's view page) used to be a fully independent, duplicated code path: `DelegationServiceGenerator` reimplemented list/create/edit/view/delete directly against `{RelatedModule}Model`, skipping the related module's own service hooks (`beforeCreate`/`afterCreate`/etc.) and `DeleteCheckService` entirely; the frontend tab hand-rolled its own create/edit/view/delete modal state machine wired to delegation-specific forms.

That duplication was a live, confirmed bug, not just waste: `RelatedModuleFormGenerator` wrote the delegation's own Create/Edit forms to the **same file path** the related module's own standalone `CreateFormGenerator`/`EditFormGenerator` already used — generating a delegation after the module had been scaffolded standalone silently overwrote that module's own create/edit page with a different, incompatible field list. Documented (and now resolved) in `tests/Fixtures/integration-schemas/delegations-suite/README.md`.

**Backend**: `list`/`edit`/`view`/`delete`/`deleteCheck` native services all gain a trailing `?Builder $query = null`, used as the query's starting point instead of `{Module}Model::query()` — mirrors the existing `ListServiceTrait::processBulkAction()` precedent. `create`/`edit` repurpose their already-declared `array $params` parameter into a forced-field merge applied both before *and* after validation, so a delegation's parent FK survives even an undeclared rule key and can never be overridden by client input (edit gets the same anti-tampering treatment as create — no re-parenting through a tab's edit form). `DelegationServiceGenerator` now generates a thin proxy: resolve the parent, build a scoped query/forced-fields, call the related module's own native static service, return its result verbatim. Gains a `deleteCheck` method (previously delegations had no cascade/relationship-check equivalent at all). The delegation keeps its own separate, independently-permissioned route/controller/service — `Statuses.Locations.edit` stays distinct from `Locations.edit` — via a new shared `DelegationConfigNormalizer::resolveOperationPermission()` helper used identically by both `RoutesGenerator` and the frontend tab generator so the two can never drift apart.

**Frontend**: new hand-maintained `CrudListPanel.vue` (SYSTEM_SHELL/FRONTEND, alongside `ListTable`/`ReportTable` — never generated) wraps `ListTable` and owns the entire create/edit/view/delete modal state machine; both the standalone `page.stub` and the delegation `tab_action.stub` now render through it, resolving to the related module's real native `CreateForm`/`EditForm`/`DeleteForm`/`ViewModal` — the same files, the same field list, whether reached standalone or through a parent's delegation tab. `create/edit/delete/view` native form stubs gain an optional `permissionOverride` prop (default `null`, zero behavior change for non-delegation callers) so a delegation's own permission actually controls the form, not just the route. The delegation-tab view is unified onto the real `{Module}ViewModal.vue` — `RelatedModuleFormGenerator`, `DelegationRelatedFormGenerator`, and their three stubs are deleted entirely; there is nothing left that can write to the same path as a module's native forms.

**Two more bugs bundled in, both load-bearing for this design's own guarantees**: `throw new \Exception('Record not found', 404)` (used by native Edit/View/Delete services) does not actually produce HTTP 404 in Laravel 13 — a plain `\Exception` is never `isHttpException()`, so it silently rendered as 500. Fixed to `abort(404, ...)`; this is what makes "a wrong-parent lookup naturally returns not-found" true rather than a 500. Separately, `DelegationConfigNormalizer::getOperationDefaults()`'s HTTP-method default (`edit`→PUT, `delete`→DELETE, matching what the native forms this whole design now reuses actually send) was correct but dead for the bulk `make:module` path — `RoutesGenerator`'s constructor read `$config['delegations']` straight off the raw module.json, never through `DelegationConfigNormalizer::normalize()`, so every edit/delete delegation route was silently registered as POST regardless of config. Only the incremental `make:delegation` path (which normalizes before calling `addDelegationRoute()`) was ever correct. Caught live, not by any existing test — every prior test constructed `RoutesGenerator` with a config built by hand in PHP, easy to accidentally pre-normalize.

**Not retroactive** — only delegations generated or regenerated against this version pick up the new behavior; no live SYSTEM_SHELL module had a non-empty `delegations` config before this release, so nothing existing needed migration.

Live-verified end-to-end against a real scratch `Warehouses`/`StockMovements` delegation in SYSTEM_SHELL (the `delegations-suite` fixture's own scenario): parent-scoped create/edit/view/delete/deleteCheck over real HTTP, FK-tampering resistance on both create and edit (a spoofed foreign-parent id in the payload is silently overridden), a cross-parent lookup returning a clean 404, and differential permission gating (a role with only the related module's own standalone permission is correctly denied the delegation-scoped route, and vice versa) — plus the module's generated Playwright delegation spec run for real against live dev servers. That pass also caught a real `CrudListPanel.vue` bug: its per-column slot-forwarding loop included the `actions` column, whose dynamic `#[cell-${col.key}]` binding silently overrode the explicit `#cell-actions` template beneath it (same slot name registered twice on `ListTable` — Vue's `createSlots()` merges the dynamic entry over the static one), leaving every generated list's Actions column empty — no eye/edit/delete button at all. Fixed by excluding `actions` from that loop.

## v2.27.0 — 2026-08-05

### Added — `id`, `uuid`, and `created_at` are now default filters, not just `id`

Every generated table carries `id`, `uuid`, and `created_at` via the standard migration columns, but until now only `id` got a default filter on both sides — the backend allow-list (`generateFilterableFields()`) and the frontend filter-panel control (`generateFilterFields()`). `uuid` was deliberately backend-filterable-only ("hidden but filterable"), and `created_at` had no default filter capability at all — sortable, but not filterable.

`generateFilterableFields()` now always appends `created_at` alongside the existing `id`/`uuid` to the backend allow-list. `generateFilterFields()` now always appends a `uuid` (`text`) and `created_at` (`date`) frontend filter control alongside `id`, via a new `appendDefaultFilterField()` helper shared by all three call sites — it never overrides a hand-authored `filterFields` entry for the same key, so a config that already defines its own `uuid`/`created_at` filter is left untouched. `created_at` renders as a `date`-type filter (a plain date picker) despite being a full datetime column; a companion fix in the consuming app's list-query trait expands a single selected date into a whole-day range rather than requiring an exact-instant match.

**Not retroactive** — only modules generated or regenerated against this version or later pick up the new default filters. See [Features Config › `filterFields`](docs/features-config.md) for the full default-filter behavior.

Live-verified against a real generated scratch module with zero other filterable columns configured: the generated `filterFields` array correctly included `id`/`uuid`/`created_at` with no extra config needed. Also adds regression coverage for the `date`/`datetime`/`timestamp` → `'date'` branch in `getFilterFieldType()`'s fallback — it has existed since v2.26.3 but had never been exercised by a test, since that release's own live verification used a schema with no date/datetime column.

## v2.26.3 — 2026-08-04

### Fixed — every auto-derived filter field was typed `text`, regardless of the column's real type

`generateFilterFields()`'s fallback (the code path that derives frontend filter-panel fields from `filterableFields` when `filterFields` isn't hand-authored) hardcoded every field's `type` to `'text'` — an FK column, an enum column (e.g. an Order's `status`), a boolean, or a number all rendered as a plain text box running a `LIKE` search against a column that could never meaningfully match a free-text query.

`getFilterFieldType()` and `isForeignKey()` already existed with the right idea but had zero call sites anywhere in the codebase — grep confirmed it. Wiring them into the fallback surfaced their own latent bugs too: `isForeignKey()` checked a key shape (`is_fk`/`normalized_type`) that doesn't exist on `config['columns']` entries (`IntrospectionToConfig::buildColumn()`'s output collapses to a single normalized `type` key), and `getFilterFieldType()` compared against `'bigint'` instead of the normalized `'bigInteger'`, with no `enum` branch at all — the exact bug this method exists to prevent, for the exact column type most likely to need it. Fixed both, and wired in `buildFilterFieldOptions()` to supply the `{name, id}[]` option lists the frontend's filter dropdown needs to actually render a `select`-type field.

Live-verified against a real generated scratch module covering FK, enum, boolean, `bigInteger`, and plain string columns, before and after the fix.

## v2.26.2 — 2026-08-02

### Docs — fixed real config-shape errors in `actions`/`delegations`/`constants`; added an Examples section to the docs site

Found while integrating the previous release's `COOKBOOK.md` into the real VitePress docs site properly, instead of leaving it as a disconnected root-level file:

- `actions.md` and `delegations.md` both documented their top-level config as a flat JSON array (`"actions": [{...}]`), but the real scaffolding code does `foreach ($config['actions'] as $actionKey => $action)` — a map keyed by action/delegation key. A flat array decodes to integer PHP keys, which breaks file and route naming. Confirmed against the real scaffolding source and every test fixture that builds this config, not just asserted.
- `module-config.md`'s `constants` example showed a named-group array shape (`[{"name": "STATUS", "values": [...]}]`); `ModelGenerator::generateConstants()` actually expects a flat `{CONST_NAME: value}` map.
- `delegations.md`'s "Generated Files" table listed filenames that don't match real generator output — corrected against a live generation of a delegation's Service and Tab component, and documented the delegation-vs-standalone-form-overwrite limitation that was previously missing entirely.

Also removed a dangling link to a nonexistent `examples/module-config-full.json`, and enriched `features-config.md`'s `bulk_actions` entry shape (previously documented as just `{key: string}`, now covers `status_target`/`label`/`icon`/etc).

Deleted the root-level `COOKBOOK.md` (written in an earlier, disconnected pass before the docs site was discovered) and folded its content into a new **Examples** nav section (`docs/examples/`) — 7 pages, one per recipe, cross-linking to the existing reference pages instead of duplicating them, closing off the exact kind of drift that produced the shape bugs above. Site builds clean (`npm run build`, VitePress's dead-link check passes).

## v2.26.1 — 2026-08-02

### Added — `COOKBOOK.md` and 3 new example fixtures (morphs, delegations, actions); fixed a redundant morph index

> Superseded one release later: `COOKBOOK.md` was folded into the docs site's Examples section in v2.26.2 above and no longer exists at the repo root.

Ships example-driven documentation covering every kind of module this engine supports: `COOKBOOK.md` walked through 9 recipes (lookup table, FK relationships, self-referential FK, file uploads, `inline_items`, morphs, delegations, actions/bulk actions), each pointing at a real, live-verified fixture rather than a hypothetical snippet.

Three new fixtures close real coverage gaps — a full scan of every `module.json` in the consuming project confirmed morphs, delegations, and actions/bulk_actions had no existing end-to-end test or real usage before this pass:

- **morphs-suite** — Payments polymorphically belonging to Suppliers or Customers. Found and fixed a real bug along the way: a regenerated migration correctly collapsed a morph pair into `$table->morphs(...)`, but never suppressed the pair's own already-covered composite index, producing a redundant (harmless but noisy) duplicate index on every regenerate of a morphs-bearing table.
- **delegations-suite** — Warehouses/StockMovements related-records tab. Documents a real design tension, deliberately not fixed: a module used as a delegation target has its own standalone create/edit form silently overwritten with the delegation's field list, permanently losing its FK picker for standalone access.
- **actions-suite** — PurchaseOrders custom action + bulk action. Documents the `bulk_actions[].status_target` convention precisely — it targets an integer `status_id` column plus a matching `constants` entry, not a plain string status column, an easy mistake to make.

All three fixtures live-verified end-to-end against a real scratch app instance (create/list through the delegation service, action and bulk-action execution, morph relationship and migration output), with full teardown afterward. 549 package tests (547 → 549).

## v2.26.0 — 2026-08-02

### Added — cascade-delete `inline_items` children when the parent is deleted

Resolves the delete-cascade decision deliberately deferred in v2.25.0. `DeleteServiceGenerator` now cascade-deletes every `inline_items` child unconditionally when the parent is deleted — deleting an Order deletes its `order_items`. Cascade, not block, is the correct default: an `inline_items` child has no independent lifecycle by design — `EditServiceGenerator`'s own sync logic already deletes any child row dropped from the parent form's payload on every edit, so cascading on parent delete is the same ownership rule applied once more. The generated call goes through the child's own Eloquent query builder, so it automatically respects the child model's soft/hard delete mode with no extra config needed.

Also confirmed, no code change needed: `DeleteCheckServiceGenerator`'s generic FK-graph dependent-count check already covers a typical `inline_items` `parent_fk` column via its naming-convention heuristic (`order_id` → `orders`) — the same as any other FK-shaped column, entirely independent of any `inline_items`-specific awareness.

Live-verified against a real scratch module: created an Order with one nested `order_item`, deleted the Order, and confirmed the `order_item` was cascade-soft-deleted (invisible to normal queries, recoverable via `withTrashed()`) — not orphaned, not hard-deleted. 548 package tests green (was 547).

## v2.25.0 — 2026-08-02

### Fixed — four real `inline_items` bugs found via end-to-end verification

A new `InlineItemsEndToEndTest`, plus a new `orders-suite` fixture mirroring `items-suite`, was built to verify the `inline_items` wrapper-component work shipped in v2.24.0 actually works against real generation and a real database — not just generated-source assertions. It found and fixed four bugs:

- `buildChildNamespace()` forced any `child_group` other than exactly `Core`/`System` under `System\{group}\...`, producing a namespace that doesn't exist — this package's own README documented `child_group => 'Custom'` as the canonical example, so following the docs literally broke.
- `CreateFormGenerator`/`EditFormGenerator`'s `inline_items` block still imported the shared `InlineItemsComponent` directly, stale since v2.24.0 introduced the wrapper-component mechanism — never caught because no test had exercised the full `generate()` pipeline for this code path before.
- `writeInlineItemsWrapperComponent()` used `writeFile()`, whose skip-if-exists is gated on `!$this->force` — so a real `make:module --force` (the normal case for an unrelated schema change) silently clobbered a developer's hand-edited wrapper back to the template. Added `BaseGenerator::writeFileOnce()`, a truly unconditional skip-if-exists primitive, independent of `--force`.
- `CreateServiceGenerator`/`EditServiceGenerator`'s `inline_items` save/sync never set `created_by_id`/`updated_by_id` on child rows, fatal-erroring against any child module using the project's standard creator/updater convention (confirmed via a live `OrdersCreateService::execute()` call). Added an opt-in `child_has_creator_updater` flag on the `inline_items` item config.

Also documents a known, deliberately-deferred limitation as of this release: `DeleteService`/`DeleteCheckService` have no `inline_items` awareness yet — resolved for delete-cascade one release later, in v2.26.0.

547 package tests green. Live-verified against a real scratch module: create/edit-sync/view of Orders+OrderItems, and a real `--force` regenerate correctly preserving both `inline_items` itself and a hand-edited wrapper component.

## v2.24.0 — 2026-08-02

### Added — modules.json carries type/group; SYSTEM_SHELL e2e tests filterable by module tier/domain group

`modules.json` (the frontend module registry `router.ts`/`RelatedRecordLink.vue` already read) only ever carried a bare `path` per module — no way to answer "every Core module" or "every module in the Locations group" from it, the exact thing needed to filter e2e test runs by module tier or business domain instead of hand-listing files or maintaining one `package.json` script per module.

`ModulesJsonGenerator::getModuleEntry()` now writes `'type' => $this->moduleGroup` (mirroring `RegistryGenerator`'s own identical `type` field, so frontend discovery shares the same Kernel/Core/System/Custom taxonomy the backend registry already has instead of inventing a second one) and, when the module has a sub-group, `'group' => $this->moduleSubGroup` (e.g. "Locations", "Notifications", or — for a Custom-tier module — its own business-domain name like "Expenses"). Omitted when there's no sub-group, so most entries stay a 2-key object rather than every one carrying `"group": null`.

Kernel-tier entries (Auth/Logs/Queue/Settings) are never emitted by this generator — same reason `RegistryGenerator` never writes `registry_kernel.json`: those are hand-authored framework modules that predate and sit outside the generator entirely, so a Kernel `modules.json` entry is always hand-backfilled.

SYSTEM_SHELL/FRONTEND's consuming side: backfilled all 23 existing `modules.json` entries with the correct `type`/`group` (cross-checked against `registry_kernel.json`/`registry_core.json`/`registry.json`'s own `type` values); replaced the 18 hand-maintained one-npm-script-per-module `e2e:*` entries with a single `scripts/e2e-select.js` that filters modules.json by `--type=`/`--group=`/`--module=` (comma-separated, AND across filters, OR within one), recursively resolves each match to its `e2e/*.e2e.js` file(s) (handles both the common one-level-deep case and Auth's two-level-deep `login/e2e/` nesting), and spawns `npx playwright test` with the resolved file list plus any passthrough flags. `--dry-run` previews the match without running (deliberately not named `--list` — Playwright's own native `--list` flag, a different granularity, still passes straight through). New `e2e:kernel`/`e2e:core`/`e2e:system` npm scripts wrap the three fixed tiers; anything else (a single module, a domain group) goes through `npm run e2e:select -- --group=Expenses` directly.

Verified: 6 new regression tests for `ModulesJsonGenerator` (backward-compat entry shape per type, subgroup presence/absence, Custom-tier domain group, `getModuleEntry()`/`generate()` parity); the new script hand-verified against every real filter combination (`--type=Core` → 16 modules/17 files, `--type=Kernel` → correctly finds Auth's nested `login/e2e/login.e2e.js` among 3 modules with no e2e coverage, `--group=Locations` → 4 modules/4 files, `--module=Users,Roles` → 2 modules/3 files, `--type=System` → 0 files handled gracefully, no-args → usage + available types/groups/modules); confirmed Playwright's own `--list` still passes through and correctly enumerates individual tests inside the resolved files, not just file-level matches.

## v2.23.0 — 2026-08-02

### Added — unify generated-form conventions; overview info-groups; InlineItems override support

Four independent pieces landing together (all opt-in/backward-compatible
except the field-stub `:disabled` fix, which changes generated form output):

**Wire the dead `isFieldDisabled()` runtime helper into every field.** The
`disabledFieldsList`/`isFieldDisabled()` block has existed in
`create/form.stub` since this package's first release, but no field-type
stub ever actually called it — every field's `:disabled` binding only ever
checked the static `[[fieldDisabled]]` config flag. Every field stub's
`:disabled` now combines all three real disable sources:
`isSubmitting || [[fieldDisabled]] || isFieldDisabled('[[fieldKey]]')`.
`select.stub` gained the missing `:disabled` binding it never had (already
had `hidden`); `file-input.stub`, `inline-items.stub`, `item-picker.stub`
gained the missing `v-if="[[fieldHiddenCondition]]"` wrapper they never had
(confirmed via git history: never present, not removed). `file-input.stub`
also gained the `:disabled` binding (`FileInputField.vue` supports it);
`inline-items.stub`/`item-picker.stub` deliberately do NOT get one — neither
target component exposes a component-level `disabled` prop, only per-field
hooks, so binding one would be a dead attribute, the same class of bug fixed
below. The `disabledFieldsList`/`isFieldDisabled()` block itself is now also
ported into `edit/form.stub` (net-new there — it never had it), wired to
auto-populate from `props.defaults` at the same point Edit already applies
them, post-load rather than at mount like Create.

**Fixed — every FK's display field was hardcoded to `'name'`.** Every FK
relation's display value (List cell links, List column data paths,
api-select dropdown option labels) assumed the related table has a `name`
column — silently wrong for a target with a differently-named display
column (e.g. an `orders` table shown by `order_number`), and worse than
cosmetic: the shared, hand-maintained `SelectController`'s default search
query also assumes `name` exists, so an affected FK's search could throw a
SQL error, not just render blank text. `IntrospectionToConfig::build()`
gains an optional third `$foreignPrimaryFields` parameter (a
`foreign_table => real display field` map); a new public static
`detectPrimaryFieldFromColumns()` lets a caller compute that map by running
the SAME "first non-FK string column, else first column, else 'name'" rule
this class already uses for the CURRENT table against each FK target's own
introspected columns. Omitting the map (existing callers, existing tests)
falls back to `'name'`, byte-identical to before. `BaseComponentGenerator`'s
FK cell renderer now reads the resolved `displayField` off each list field
entry instead of hardcoding `.name`.

**Overview info-groups.** `generateInformationSection()` gains an optional
`groups` parameter: `sections: [{key, title, groups: [{fields}, {fields}]}]`.
When present, renders ONE Card with N side-by-side divided columns
(`grid-cols-1 md:grid-cols-N divide-y md:divide-y-0 md:divide-x`) instead of
the default single stacked field list — matching a 3-column grouped info
panel reference. Omitting `groups` (the default) is byte-identical to
before. `header_metrics` stat cards were already fully built
(`ViewLayoutGenerator::generateMetricsConfigs()`) — nothing changed there.

**InlineItems: hand-edit-protected wrapper components + restyle.**
`InlineItemsComponent`'s extension hooks (`dynamicDisabled`, `showField`,
`render` per field, `field-change`/`item-change` events) were already fully
built and documented, but generated code had nowhere to use them — JSON
config can't express a JS function, and both places this generator binds
`<InlineItemsComponent>` spliced an inline `:fields="[...]"` literal
straight into the generated form. Both mechanisms — a `field_type:
'inline-items'` entry inside a normal field list, and the top-level
`inline_items` parent-child block config (e.g. Order Items) — now emit
`{Module}{Key}InlineItems.vue` once (skip-if-exists, same protection as
`{Module}TestCase.php`) with TODO-stubbed hooks, and bind that wrapper
instead of the shared component directly. `InlineItemsComponent.vue`'s own
default row rendering (SYSTEM_SHELL/FRONTEND, not generator-emitted) is
restyled from a `<table>` to a bordered-row-list look, preserving the full
`#row`/`#empty` slot contract and CRUD affordances. Also fixed: a dead
`color-scheme` attribute `inline-items.stub` passed but the component never
declared as a prop, and `types.ts`'s `InlineItemsEmits` missing the two
change events.

Verified: package suite green (533 tests, regression coverage for every
field-stub's combined `:disabled` expression, the 4 previously-gapped
stubs, `edit/form.stub`'s new disabled-fields block, FK display-field
resolution (with-map / map-omitted / table-absent-from-map), the `groups`
rendering and its no-`groups` backward-compat case, and wrapper-component
emission — write-once semantics for both InlineItems mechanisms).

## v2.22.1 — 2026-07-30

### Fixed — every generated Service returned HTTP 500 instead of 422 for ApplicationException

`ApplicationException` exists specifically for business-rule validation
failures — a duplicate check, a missing-related-record lookup, any custom
`throw new ApplicationException(...)` a consumer adds inside a Service's
`process()`/`beforeCreate()`/etc. Every one of the 12 `.stub` templates that
catch it (`create`, `edit`, `view`, `delete`, `createSplash`, `editSplash`,
`action`, `inner`, `inner/service_delegate`, `ux/composite-service`,
`ux/wizard-service`) hardcoded `Helpers::error($e->getMessage(), 500,
$e->getData())` — mapping every such failure to a generic 500 server error
instead of a 422 the frontend can actually render as a validation message.
`DelegationServiceGenerator` (which builds its create/edit/view/delete
methods as PHP heredocs rather than `.stub` files) had the identical bug
independently duplicated five — well, four, `list` has no catch block of its
own — times.

Confirmed pre-existing since each file's original authorship (every
affected line's git history is a single, untouched `+` addition) — not
caused by v2.22.0's test-splitting work. Found because a consumer app had
independently, manually corrected 66 of its 123 already-generated Service
files from 500 to 422 at some point (never fed back into the generator), and
asked why 57 others — including brand new custom validation checks — still
returned 500.

`DeleteCheckServiceGenerator`'s `ApplicationException` catch intentionally
stays 404 (a delete-check failure always means "the record you're checking
doesn't exist") — correctly excluded from this fix, not a member of the bug.

Verified: package suite green (515 tests, 8 new regression tests covering
`CreateServiceGenerator`, `EditServiceGenerator`, `DeleteServiceGenerator`,
`ViewServiceGenerator`, `CreateSplashServiceGenerator`,
`EditSplashServiceGenerator`, `ActionServiceGenerator`, and
`DelegationServiceGenerator`'s four CRUD operations).

## v2.22.0 — 2026-07-30

### Added — split PhpUnitTestGenerator/PlaywrightTestGenerator output: one file per Service/delegation/action key

Both generators emitted exactly ONE test/spec file per module, bundling
every CRUD operation and every delegation's coverage into it.
`MakeDelegation.php`/`MakeAction.php` (consumer-side) had to
force-regenerate that whole file to pick up one newly-added delegation or
action, wholesale clobbering any hand-edited test logic elsewhere in it —
`BaseGenerator::writeFile()`'s skip-if-exists/`--force` contract is a binary
overwrite-or-skip, there is no merge. Actions also got almost no PHPUnit
coverage (a contract test for the *first* enabled action only) and zero
Playwright coverage at all.

**PhpUnitTestGenerator**: now emits one file per dedicated Service class
(`{Module}ListServiceTest.php`, `CreateServiceTest`, `EditServiceTest`,
`ViewServiceTest`, `DeleteServiceTest`, `DeleteCheckServiceTest`,
`ActivityListServiceTest`), one per named bulk-action key, one per
delegation key, and one per action key — every action now gets its own
file, removing the old first-enabled-action-only restriction. All split
files extend a new, freely-regenerated `{Module}TestCase` base class
(`setUp()` + the module's own fixture builder); the generic
permission-denial helper every module used to duplicate is now a single
hand-written `Tests\Support\ActsWithoutPermission` trait.

**PlaywrightTestGenerator**: the CRUD flow (create → filter → view →
related-record → edit → delete) stays whole in a renamed
`{module-route}-crud.e2e.js`. Delegation coverage moves OUT into its own
`{module-route}-{delegation-key}.e2e.js` per key — including net-new
coverage for `uiType: 'modal'` (header-action) delegations, which had zero
coverage of any kind before. Actions get net-new
`{module-route}-{action-key}.e2e.js` coverage (modal and page uiTypes), a
surface with no prior Playwright coverage at all. A new shared
`_fixtures.js` per module (`createFixtureRecord()`/`cleanupRecord()`) lets
every split spec build and tear down its own fixture record independently,
instead of depending on the CRUD spec having run first.

**Both generators** gained `regenerateOnly(string $key, string $kind)`
(`kind` = `'delegation'|'action'`) — writes only the ONE target key's file
via `writeFileAlways()`, leaving every other split file (including
hand-edited ones) untouched — and `deleteStaleMonolithicFileIfPresent()`,
which removes the pre-split legacy file (`{Module}CrudTest.php` /
`{module-route}.e2e.js`) only under `--force`, only after the new split
files wrote successfully. Consumers' `MakeDelegation.php`/`MakeAction.php`
should call `regenerateOnly()` instead of `setForce(true)->generate()` and
can drop their own `--force` gate on test regeneration entirely — scoped by
construction, it is exactly as safe as the additive route/controller
patching those commands already do unconditionally.

Verified against a real generated module in a consumer app: a
`make:delegation`/`make:action` run with no `--force` creates only the new
key's files, every other file (including deliberately hand-edited ones)
survives byte-identical, and a `--force` full regen correctly deletes
simulated legacy monolithic files only after the new split files exist.

### Fixed — four bugs in the "assumes has_soft_deletes/has_creator_updater are always true" family

All four surfaced generating a real module with `has_soft_deletes: false` +
`has_creator_updater: false` — a combination no module in the consumer app
this was verified against currently uses (every real module there has both
enabled), which is exactly why none of these had been caught before.

- `Features/view/service.stub` called `{Model}::withTrashed()`
  unconditionally — a `BadMethodCallException` the moment a module without
  the `SoftDeletes` trait ran its ViewService. Now resolved via a
  `[[withTrashedCall]]` placeholder gated on
  `ModuleConfigContract::hasSoftDeletes()`.
- `BaseServiceGenerator::generateEagerLoadRelationships()` appended
  `'creator'/'updater'` unconditionally in every branch — a
  `RelationNotFoundException` for a module whose model has no such
  relations. Now gated on `ModuleConfigContract::hasCreatorUpdater()`.
- `DelegationServiceGenerator::buildEagerLoadRelationships()` — an
  independently-duplicated copy of the same bug (this class doesn't reuse
  `BaseServiceGenerator`'s method) — fixed the same way, using the parent
  module's `has_creator_updater` flag as the best available proxy (no
  per-related-module schema signal exists anywhere in this generator).
- `PhpUnitTestGenerator::buildDeleteTestMethod()` always asserted
  `assertSoftDeleted(...)` in the generated delete test regardless of
  `hasSoftDeletes()` (already computed and used correctly elsewhere in the
  same class) — a fatal "Unknown column 'deleted_at'" `QueryException` for
  a module with no such column. Now emits `assertDatabaseMissing(...)`
  instead when the module has no soft deletes.

Verified: package suite green (507 tests), plus a live re-run of the same
generated module against the exact config that originally surfaced these —
now fully passing.

## v2.21.0 — 2026-07-29

### Added — incremental route/controller wiring for delegations and actions

`RoutesGenerator`/`ControllerGenerator` were monolithic full-file rewrites:
`config['delegations']`/`config['actions']` only ever got a route or a
controller dispatch method during a *full* module generation. A delegation
or action added to an already-generated module (e.g. via a consumer's
`make:delegation` command) got a working service and, for delegations, a
frontend tab — but no route and no controller method, so it was completely
unreachable. Confirmed live against this repo's own hand-customized `Users`
module (283-line controller, 21 methods, several hand-written) before
fixing, to make sure the fix couldn't regress into the same problem it
solves.

Added `RoutesGenerator::addDelegationRoute()`/`addActionRoute()` and
`ControllerGenerator::addDelegationMethods()`/`addActionMethods()` — genuinely
additive, idempotent appends to an already-generated module's `Routes/api.php`
and `{Module}Controller.php`, built on the existing `PatchesRegions` trait
(already used by `DashboardGenerator`/`ShortcutGenerator`) rather than a new
mechanism. `generate()` now wraps delegation/action routes, imports, and
methods in named regions unconditionally (even when empty), so every freshly
generated module already carries the markers; a module generated before this
version self-heals the markers in on first incremental use, verified against
both a synthetic legacy file and the real pre-existing `Users` controller.

### Fixed — three bugs in the same family, found while building the above

All three came from `DelegationConfigNormalizer`/`ActionConfigNormalizer`
always populating `endpoint.method`/`endpoint.permission`/`serviceName`/
`methodName` with a concrete default (never leaving them `null`), which
silently defeated every `?? $fallback` downstream in `RoutesGenerator`/
`ControllerGenerator`/`ActionServiceGenerator` that assumed an unset value,
not an empty one. All three affect the full-generation path too (`make:module`,
`make:modules-from-db`, and any consumer's own generation UI), not just the
new incremental methods above — confirmed live, not just in unit tests.

- Every delegation/action `create`/`edit`/`delete` operation was registered
  as a `GET` route regardless of what the caller configured (`GET
  ['method' => 'GET', ...]` was the blanket default for *all five*
  operations, not just `list`/`view`), because a concrete `'GET'` beat the
  `?? ($op === 'list' || 'view' ? 'get' : 'post')` fallback every time. Now
  the normalizers themselves default `method` per-operation.
- Every delegation/action route shipped with `permission:''` — an empty
  permission gate — instead of the intended `{Module}.{Delegation}.{op}` /
  `{Module}.{action}` default, for the same `?? ''` reason.
- Every action left without an explicit `serviceName`/`methodName` generated
  a *generic*, collision-prone name (a plain `create()` method calling a
  bare `{Module}Service`) instead of one scoped to the action itself (e.g.
  `createApprove()` calling `{Module}{Action}Service`) — a second such
  action on the same module would have silently overwritten the first
  one's service file. Also fixed a drift between `RoutesGenerator` and
  `ControllerGenerator`'s `methodName` fallback (one used the service name,
  the other the action name) that the empty-string bug had been masking —
  once `serviceName` customization actually took effect, the two would have
  generated routes and methods with different names for the same config.

## v2.20.2 — 2026-07-29

Found while auditing the package's own docs for staleness: mobile list
generation has been fataling for every module since the v1.0.0 initial
release. `MobileApp\Components\ListComponentGenerator::generate()` calls
`$this->generateStatsConfigs(...)`, a method that never existed anywhere in
this class or its ancestry — `ModuleGenerationService` in consumers catches
the resulting `\Throwable` and logs it as a generation error, so it fails
silently instead of crashing the run, and `{ModuleName}List.vue` simply never
gets written for mobile. Added `generateStatsConfigs()`, reusing the exact
`Stat{title,value,icon,color,bgColor}` shape the mobile `component.stub` and
consumers' `Stat` TS interface expect (same shape the still-dead
`FrontendGenerator::generateStats()` produces, but built from
`$this->moduleName` directly rather than `[[ModuleName]]` placeholder
tokens — those get replaced by `BaseGenerator::replacePlaceholders()`'s
single `str_replace` pass *before* this method's return value is spliced in,
so a literal token here would have leaked into the generated file
unreplaced). Verified with a standalone script covering both the
empty-stats default-fallback branch and the configured-stats branch.

Also brings `docs/` back in sync with source: `docs/changelog.md` had been
frozen at v2.6.2 for 14 releases, `docs/index.md`'s Quick Start called a
`PathManager` constructor that doesn't exist, `README.md`'s flagship
`IntrospectionToConfig` example never mentioned `::strict()`/
`ModuleConfigContract`, and `docs/actions.md` was missing the `placement`/
`icon`/`destructive` action keys.

## v2.20.1 — 2026-07-28

Found while manually regenerating v2.20.0's fix into SYSTEM_SHELL and
inspecting the actual output: boolean create/edit fields had the identical
defect the v2.20.0 numeric fix addressed, for the identical reason.
`getFieldDefaultValue()`'s `$type` is the field_type-derived component
selector (`'checkbox'`), not the semantic type (`'boolean'`), so
`case 'boolean':` was equally unreachable — every boolean field's `CheckboxField`
(`modelValue: Boolean`) was seeded with `''` instead of `false`. Extended the
same `dataType`-based check added for the numeric case to also cover
`dataType === 'boolean'`.

## v2.20.0 — 2026-07-28

Closes out the defects left after v2.19.0's checkpoint, plus the structural
fix that release's commit message named directly: a mechanical, generated
test for the class of bug that lives in the *relationship* between two
generated files rather than inside either one.

### Fixed — numeric create/edit fields still defaulted to `''`, not `null`

`getFieldDefaultValue()`'s `case 'number':` (added to return
`null as number | null`, matching the `Number | Null` prop every
`number-input` field declares) was never reached. `mapNewFormFieldsToLegacy()`
aliases the raw field's `type` to `field_type`'s value one method earlier —
by design, since `generateField()`/`resolveFieldType()` key off that same
`type` entry to pick a stub (`select`, `checkbox`, ...) — so by the time
`getFieldDefaultValue()` ran, a numeric field's `type` read `'number-input'`,
not `'number'`. Fixed by preserving the true semantic type under its own
`dataType` key rather than overloading `type` a second way; every other
type-dispatch method (`generateField()`, `resolveFieldType()`,
`isBooleanFieldType()`) is untouched.

### Fixed — the view modal's title never showed the record

`detailsTitleKey` is a static per-module i18n fallback
(`"{Module} Details"`) by design, so the modal never regressed to a blank
header — but nothing ever bubbled the fetched record up to replace it, on
either surface that opens a view modal: the list page's own eye action, and
`RelatedRecordLink`/`EntityModalProvider` elsewhere in the app. The details
PAGE always showed the record's name because it computes it in the same
component scope as the fetch; the modal fetches in a child component and
had no way to hand that value back up. `ViewModalGenerator` now emits a
`loaded` event carrying the record's `titleData` field (the same field the
details page's `primaryDisplayField` already uses), consumed by
`list/page.stub`'s own reactive title ref and, for the `RelatedRecordLink`
path, by `EntityModalProvider.vue` directly (hand-maintained SYSTEM_SHELL
code, not generated — no release/bump needed for that half of the fix).

### Fixed — a page-type action's breadcrumb referenced a locale key nothing generates

`action/page.stub` read `$t('{moduleRoute}.page_title')` for its breadcrumb;
every other generated page shell (list/create/edit/delete) uses
`$t('{moduleRoute}.title')`, and `FrontendLocaleGenerator` only ever emits
`title`. Every page-type action's breadcrumb rendered the raw untranslated
key. Found by the new contract test below, not by inspection.

### Added — CrossFileContractTest: four mechanical cross-file checks

`ViewSurfaceParityTest`/`ActionGenerationTest` (v2.19.0) each caught one
such relationship by hand. This generalizes the idea: one fixture — a module
nested under `System/Custom`, an FK-select field, a delegation with every
CRUD operation enabled, and one modal + one page action — generated inside
the package's own PHPUnit run (never against a `vendor/` copy), checked four
ways at once:

1. every backend endpoint literal referenced in generated Vue resolves to a
   `Route::` path actually registered in a generated `api.php`;
2. every `permission:X` (routes) / `hasPermission('X')` (Vue) reference
   exists in a generated seeder's permissions JSON;
3. every relative or `@/pages/modules/...` import resolves to a file this
   run actually wrote;
4. every `t('module.key')` call (excluding hand-maintained shared namespaces
   like `common`/`delete`) exists in a generated locale file.

Package test count: 477 → 481.

## v2.19.0 — 2026-07-28

Checkpoint release. Found by generating a real nested module suite and
clicking through it — every defect below passed a fully green package suite,
because each generator's own tests assert its file in isolation while these
lived in the relationship between files.

**Actions** (config → UI was previously a dead end): route guard and seeder
emitted different permission strings (`{Module}.{Studly}.{op}` vs
`{Module}.{name}.execute}`) so the demanded permission was never created —
both now emit `{Module}.{actionName}`. Nothing in the frontend read
`config['actions']` at all; `ViewModalGenerator` now renders them into the
footer (`placement: main|more`, icon, destructive), and one
`{Module}{Action}Form.vue` is always generated with the container following
`uiType`, mirroring the Create/Edit convention. The action stub also POSTed
to a hardcoded path the backend never registered.

**Delegations** (every tab was non-functional in some way): blueprint
delegations were hardcoded list-only; `--force` was silently swallowed by an
inner generator defaulting to non-force; the FK default sent the parent's
uuid into an integer column (422 on every child create); the child form
rendered without `modal`, tearing down the parent modal on save; columns
were read off the delegation entry (which never carries list fields) instead
of the related module's `module.json`, so every tab rendered an empty table;
column labels used the parent's i18n namespace and printed raw keys; the
parent FK column was left in the child tab, always showing the same value.

**View surfaces**: the modal's tab registry was a hardcoded overview+history
literal while the details page built tabs from delegations — child lists
existed on the page and not in the modal most people open first.
`EntityModalProvider` (what `RelatedRecordLink` opens) was a bare `Dialog`
with no title, so the same `ViewModal` looked like two different dialogs
depending on how it was reached; it now renders through `AppDialog` with
`detailsTitleKey`/`modalSize` from the module config. Delegation tab ids were
StudlyCase while their routes were kebab-case, so every tab link resolved to
nothing.

**Menus**: generated groups were appended as new top-level groups, but
`DynamicNavMenu` flattens those and never renders the label — generation now
always targets the "Main" section.

**Tests**: adds `ViewSurfaceParityTest` (modal/page tab-id parity, every tab
id has a registered route, no delegation tab ever generates empty columns)
and `ActionGenerationTest` (the permission contract, main/more placement).
Generated Playwright specs now exercise the `RelatedRecordLink` jump and the
delegation child list end to end.

## v2.18.0 — 2026-07-28

Delegations had no browser coverage — a generated Playwright spec drove the
parent module's own CRUD and never opened a child tab. Adding that coverage
immediately exposed two independent naming drifts that made every delegation
tab non-functional:

1. `generateTabNavigation()` emitted the tab id as the raw StudlyCase feature
   name while `FrontendRoutesGenerator` registers the child route as
   `Str::kebab(...)`; Vue Router paths are case-sensitive, so the nav link
   rendered and went nowhere.
2. The frontend route meta declared `permission: {Module}.{kebab}.list`
   while `RoutesGenerator` guards the endpoint with `{Module}.{Studly}.list`
   — no single permission record could satisfy both, so the tab 403'd for
   every role.

The new generated Playwright block navigates to the tab route, asserts the
nav link points at the registered path (not just that a click didn't error),
waits for the child table, then returns to the list.

## v2.17.1 — 2026-07-27

Four defects, each found only by regenerating into a live consumer and
running the generated suite — the package suite was green throughout.
`FactoryGenerator` ignored a string column's `length`, overflowing narrow
varchar columns; `PhpUnitTestGenerator` emitted date-only literals for
datetime/timestamp columns and asserted them as raw strings (wrong under a
non-UTC app timezone) and asserted `assertIsInt` on a file-upload column the
API returns as a numeric string; `DeleteCheckServiceGenerator` now emits a
dependent whose column is absent from the live schema as commented-out with
a warning, instead of a query that 500s.

## v2.17.0 — 2026-07-27

Two defects reported from a live project, both silent, both fixed at the root.

### Fixed — the FK graph scanned the entire database server

`globalForeignKeys()` called `Schema::getTableListing()` with no schema argument, then **deliberately stripped the schema qualifier**:

```php
$bare = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
```

So `otherproject_main.items` collapsed onto `items` and merged into the graph. Measured on the reporting machine:

```
Schema::getTableListing()            -> 3,817 tables across 111 schemas
Schema::getTableListing(<db>, false) ->    25 tables
```

One project saw `items` arrive five times from five unrelated databases, and its emitted blueprint recorded table and column names belonging to other projects entirely — not merely noise, but cross-schema information leaking into a committed artefact.

Two further problems surfaced in the same method: it is `public static` with no connection parameter, and its three schema calls used the `Schema::` facade — so it **ignored `--connection` completely** and always read the default connection, even when generating against another. The class's own connection-aware `schema()` helper was bypassed.

`globalForeignKeys(?string $connection = null)` now resolves a connection-aware builder and scopes every lookup to that connection's own database. Measured after: 6 target tables, 17 references, all within the application's own 25 tables.

### Changed — generation is now two explicit phases, so a single pass is correct

A module generated before the modules that reference it could not resolve them. `ItemCategories` generated before `Items` was registered emitted:

```php
// Could not resolve module for table 'items' — add it to the registry.
```

A second `--force` run, with no source change, emitted the real `ItemsModel::where(...)->count()`. One project's tests went **205 → 212 purely from re-running**, and the first pass reported `Warnings/Errors: 0` throughout. Users were being told to always generate a new group twice.

Root cause: the module registry was built *as a side effect of generating*, so a module could never see anything generated after it — nor itself.

`make:modules-from-db` stage 2 now runs as:

- **Phase A** — build the complete in-memory registry from the blueprint, which already declares every module before anything is written. Logs `Registering N modules…`. No files created.
- **Phase B** — generate, with resolution already complete.

**The on-disk registry is deliberately not pre-seeded.** `registry.json`/`registry_core.json` are still written per-module after each succeeds, inside Phase B. Pre-seeding those would let a mid-run failure leave the registry advertising modules that were never created — trading a visible placeholder comment for a reference to a class that does not exist, which is strictly worse than the bug being fixed.

Skip-group tables never enter the pre-seed, so a table that is intentionally not generated cannot resolve to a path that will never exist.

An unresolved dependent is now reported through the existing `PathManager::reportIssue()` channel as a **warning** rather than passing silently — the module remains usable, since the placeholder is a comment, so aborting would be wrong, but silence is what produced this report. Skip-listed tables are exempt.

**Proof**: in the reporting project, pass 1 and pass 2 now both report **459 passed / 1446 assertions — identical**. Forward references (`ItemCategories→Items`, `PriceLists→ItemPrices`, `PaymentProviders→PaymentMethods`) resolve to real model calls on the first pass.

### Not changed, deliberately

`ModelGenerator::generateNamespacedClass()`'s self-reference special case and `DelegationServiceGenerator`'s caller-declared sub-group fallback (both v2.13.5) exist because of the same constraint this release removes, and a complete registry should make them redundant. They work today, and "this fix makes that fix unnecessary" is a claim worth verifying on its own rather than bundling into the change that makes it — left for a separate, independently-testable follow-up.

`MobileDeleteCheckServiceGenerator` carries the same latent placeholder pattern and was out of scope.

Package test count: 439 → 444.

## v2.16.4 — 2026-07-27

### Fixed — generated tests sent a string to every JSON column, failing their own service's validation

A `json` column is validated by the generated service as `["nullable","array"]`, but `buildFieldValueLiteral()` had no array branch: after enum, email, `exists:` FK, `integer|numeric`, `boolean` and `date`, everything fell through to a generic `'Test {Field} ' . uniqid()` string. So the generated test failed against the generated service — self-inconsistent output.

The class docblock explains the branch ordering was chosen so "every literal stays valid for its declared rule". Array was simply missed, and it is not rare: the reporting project has **18 JSON columns** across Sales, Documents, Marketing and Support.

- A new branch, gated on `preg_match('/\barray\b/', $rules)` (word-boundary, matching the existing `integer|numeric` discipline), emits `['test']`. `BaseServiceGenerator::generateValidationRules()` was read in full to confirm it emits no `{field}.*` nested-element rules, so no element constraint can be violated; a non-empty literal exercises a real create/edit/persist/response round-trip where `[]` would not. Unlike the boolean branch this is not gated on `$isMultipart` — Laravel's in-process `TestCase::post()` populates the request's ParameterBag from the PHP array rather than serialising to wire format, so arrays survive both paths.

### The part that mattered more — `assertDatabaseHas`

`assertDatabaseHas` binds values into a where clause, so an array value throws. Both call sites could receive one: the create test's single-field assertion falls back to `$fields[0]`, and the edit test builds a multi-field block from the whole payload. Fixing only the literal would have traded a validation failure for a query error.

The first attempt — comparing against `json_encode($payload[...])` — was **also wrong**, and only a live database caught it: MySQL's native JSON type does not compare equal to a bound string even when the content is byte-identical.

```sql
applies_to = '["test"]'              -- 0 rows
applies_to = CAST('["test"]' AS JSON) -- 1 row
```

So array-typed fields are now excluded from the where clause entirely: a new `firstDbAssertableField()` (mirroring `firstUniqueField()`'s ordering but skipping array fields) for the create test, and a per-field `isArrayField()` guard for the edit test's block. Every other field keeps its exact-match check, and a degenerate all-array module omits the assertion rather than emitting a fatal one.

`assertJsonPath` needed no change: the model casts `json` → `array`, so response and payload are both plain PHP arrays compared with `===`, never touching the database — the MySQL comparison quirk does not apply. Verified with a dedicated test rather than assumed.

Verified end-to-end against the reporting project: `TermsAndConditions` went from 2 failed / 16 passed to **18 passed**.

Package test count: 434 → 439.

## v2.16.3 — 2026-07-27

### Fixed — `MobileRegistryGenerator` referenced an undefined variable

v2.16.2's switch to `encodeJsonPreservingIndent()` was applied across seven call sites; six took the correct path variable, but this one received `$path`, which does not exist in `generate()` — the local is `$registryPath`. Every module generation then reported:

```
Failed: [MobileRegistry] Undefined variable $path
Done. Created: 46, Skipped: 1, Errors: 1
```

and the mobile registry was never written. Self-inflicted, and caught within minutes because the generator **reported the error** rather than swallowing it — the opposite of the silent-success pattern that has produced most of this project's defects.

### Added — the mobile registry generator now has tests at all

It had none, which is precisely why the above shipped: the package's 431 unit tests passed both before and after the regression, because not one of them executed this method. It surfaced only by running `make:module` against the real app.

Three tests now cover it — the module is written with the right namespace/path/group, an existing registry is merged into rather than overwritten, and the file's indentation width survives a rewrite. Verified to genuinely catch the defect: reintroducing `$path` turns all three red with a `TypeError`.

Package test count: 431 → 434.

## v2.16.2 — 2026-07-27

### Fixed — shared JSON config files were reformatted wholesale on every write

Scaffolding one module produced a **439-line diff in `menus.json`** that was almost entirely whitespace. `menus.json` is stored with 2-space indentation, but PHP's `JSON_PRETTY_PRINT` always emits 4, so every write reindented the entire file.

That matters beyond noise: a whole-file diff makes review impractical, and it turns every shared config file into a guaranteed merge conflict the moment two people scaffold on the same branch.

`BaseGenerator::encodeJsonPreservingIndent()` now detects the file's existing indent unit and rescales the encoded output to match, falling back to PHP's native 4 when the file is new or its indentation can't be determined. Applied to all seven write sites across `MenusJsonGenerator`, `ModulesJsonGenerator`, `RegistryGenerator` and `MobileRegistryGenerator`.

Measured on a real scaffold: `menus.json` **439 → +39/-4 lines**, and what remains is genuine content — the new module's entry and its parent section's aggregated permission list. `modules.json` is +4/-1. (`modules.json` was already 4-space, so it never suffered from this; the helper protects it if that ever changes.)

## v2.16.1 — 2026-07-26

### Fixed — four stubs rendered `<RelatedRecordLink>` without importing it

Every stub receiving the `[[customCellRenderers]]` placeholder gets its foreign-key cells wrapped in `<RelatedRecordLink>`, so each must import that component. Only `list/page.stub` did:

| Stub | Imported? |
|---|---|
| `list/page.stub` | yes |
| `list/component.stub` | **no** |
| `custom/tab_action.stub` | **no** |
| `delegation/tab.stub` | **no** |
| `inner/list.stub` | **no** |

An unresolved component renders nothing, so an FK cell in any of those would have shown blank where the related record's name belongs.

It never bit in practice because generated modules route to `{Module}ListPage.vue` (from `page.stub`, which has the import) and nothing imports the generated `{Module}List.vue`. But the hand-built convention is Page-imports-List — `LocationsListPage.vue` imports `LocationsList.vue`, and that hand-built list imports `RelatedRecordLink` itself — so the generated component was one wiring change away from silently dropping every FK link.

A regression test now walks the whole `Templates/` tree and asserts that any stub using `<RelatedRecordLink>` directly, or receiving `[[customCellRenderers]]`, imports it. That test found three of the four; only `list/component.stub` had been identified by hand.

Package test count: 430 → 431.

## v2.16.0 — 2026-07-26

Three gaps closed in one pass: a newly scaffolded module is now usable in the UI immediately, enum columns are wired through the last two layers, and four previously-skipped test families are generated.

### Added — a scaffolded module grants its own permissions, so it works immediately

A generated module created its `{Module}.{feature}` permission rows but attached them to **no role**. The frontend router guard checks the user's role permissions literally, so every freshly generated module rendered **Page Not Found** until someone granted them by hand. The backend has a developer bypass, which is why generated PHPUnit tests always passed and never surfaced it — a frontend-only failure, hit three separate times in one session, each needing manual SQL.

`seeder.stub` now grants the module's permissions to the developer role from its existing `permissions()` hook.

**The storage shape was not what it looked like**: `RolesModel.permissions` is cast `'array'` but holds permission **ID integers**, not name strings — `RolesManagePermissionsService` validates `permissions.*` as `integer|exists:permissions,id`, and `resolvePermissionId()` maps name→id before checking. The grant therefore looks up `PermissionsModel::where('module', ...)->pluck('id')` and merges IDs.

- **Additive** — merges into the role's existing permissions, never replaces.
- **Idempotent** — `array_unique` over the merge; re-running adds nothing.
- **Scoped** — only the developer role is ever touched; no ordinary role gains anything.
- **Order-safe** — if the roles table has no developer row yet (roles seeder not run), it logs and returns rather than throwing.

Verified live: developer role permissions 173 → 179 on first run, six new rows granted, and still 179 after re-running.

### Added — enum columns reach the model cast and the list badge

The last two layers that fell back to plain string:

- `ModelGenerator::getCastType()` gained an explicit `case 'enum': return 'string'`, replacing an accidental fallthrough to no cast. No PHP backed-enum class exists anywhere in the pipeline to cast to, so `'string'` is the honest answer and now documents the closed-string-set intent, matching the `Rule::in()` and `Select2Field` the rest of the pipeline emits. Nullable enums are unaffected — Laravel's `castAttribute()` short-circuits `null` before applying any primitive cast.
- Enum list columns now render as a **badge**, reusing the existing boolean/badge cell-renderer path, with labels humanised through the same `columnLabel()` helper the form's select options use — so the badge text matches the form. A single consistent badge style is used rather than the boolean path's two-tone ternary, which does not generalise past two values. Per-value colour mapping was deliberately not attempted: no colour-assignment concept exists elsewhere in the generator.

Generated output for a `price_tier` enum:
```vue
<template #cell-price_tier="{ row }">
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
        {{ ({ 'standard': 'Standard', 'premium': 'Premium', 'wholesale': 'Wholesale' })[row.price_tier] ?? row.price_tier }}
    </span>
</template>
```

### Added — four previously-skipped test families

These were skipped on the rule that a generated test which cannot pass is worse than a missing one. Generated factories (v2.11.1) changed what is now derivable, so most became feasible:

- **Delete-check with blocking relationships** — the dependent-count branch was never exercised. Now emitted when a dependent table resolves via `PathManager::findModuleByTable()`, building the blocking row through the child's own `{Module}Factory` so no child field-shape knowledge is needed.
- **Delegation routes** — `list` whenever the delegation enables it; `view`/`delete` additionally gated on the related module resolving to a real model; `create`/`edit` additionally on that delegation declaring its own fields.
- **Import upload** — the parser reads the uploaded file's own header row, so a single-column CSV is deterministic.
- **Filter-mode bulk actions** — an empty `filters: []` under `mode=filter` matches every row, which is universally safe.
- **Custom `actions[]`** — contract tests only (403 without permission, non-5xx with it), because the generated action body is still an explicit developer TODO. Restricted to the default route shape.

**Still skipped — composite unique violation.** Verified that `BaseServiceGenerator` derives no `Rule::unique(...)->where(...)` from `unique_constraints`, so enforcement remains DB-only and a duplicate surfaces as an uncaught `QueryException` (500), not a 422. The missing piece is a *validation rule*, not a test; emitting a test that expects a 500 would encode the bug. Recorded as the required follow-up.

Generated backend tests for the five-module fixture: **99 → 106 passing, 0 failing**.

Package test count: 415 → 430.

## v2.15.1 — 2026-07-26

### Fixed — generated Playwright specs read an ID column that v2.15.0 removed

Removing the raw ID column from generated lists (v2.15.0) broke every generated e2e spec, because the filter step captured the row's id from that column:

```
Error: getRowColumnValue: no <th> found with text "ID"
```

All 5 generated module specs failed. Fixed without reintroducing the ID column and without weakening any assertion.

**The deeper cause**: `PlaywrightTestGenerator` read `features.backend.list.filterFields` from `module.json`, which is **empty** for every introspected module. The runtime filter panel is not built from that key — `BaseServiceGenerator::generateFilterFields()` has a fallback deriving text filters from `filterableFields`, which IS populated (e.g. `ItemTypes` → `['name','code']`). So the generator and the runtime disagreed about which filters exist, and the generator fell through to its id-based variant for modules that actually had perfectly good text filters.

- `resolveFilterFields()` now mirrors the runtime fallback, so the spec drives filters that genuinely exist in the panel.
- `pickTextFilterField()` now prefers the filterField matching the record's anchor field rather than the first one — needed for `ItemPrices` (anchor `currency`, but `item_id` came first) and `ItemImages` (anchor `sort_order`) to select the correct filter.
- The remaining id-based variant, still reachable for a module where no filterField matches the anchor, no longer hardcodes the `'ID'` header. `pickVisibleFilterField()` selects a filterField that is also a currently-rendered list column and filters by that column's live value.

Filtering by `uuid` was considered and rejected: `BaseServiceGenerator` documents that uuid is deliberately backend-filterable only — "never shown as a visible column or a user-facing filter control" — so no `filter-value-uuid` control exists for a browser to drive.

Verified against the running app: **5 of 5 generated specs pass**.

Package test count: 415 (unchanged — no existing test asserted on the id-variant's literal content).

## v2.15.0 — 2026-07-26

### Changed — generated lists show related data instead of raw IDs

Observed in a real browser, on a generated `Items` list:

```
headers  : ["ID","Name","Sku","Description","Is Active","Actions"]
first row: ["1","Demo Item","SKU-DEMO-1","Demo","Yes",""]
clickable cells in table body: 0
```

Two decisions in `BaseComponentGenerator` combined badly: an **ID column was emitted unconditionally**, and **every foreign-key column carried `defaultVisible: false`**. So a user saw a meaningless internal integer and never the related record — and `RelatedRecordLink`, which was being generated correctly all along, never rendered, because the columns it lives in were hidden. Zero clickable cells in the body.

Both now match the hand-built references, which were checked rather than assumed:

| | `LocationsList.vue` (hand-built, has FKs) | Generated, before | Generated, now |
|---|---|---|---|
| ID column | none | always visible | **none** |
| `location_type_id` / `parent_id` | visible | `defaultVisible: false` | **visible** |
| `RelatedRecordLink` | used 7× | never rendered | **renders** |

`UsersList.vue` and `LocationTypesList.vue` likewise emit no ID column. The old comment justified hiding relations by citing Users' list — but Users hides only secondary *scalars* (`phone`, `last_logged_in_at`); its relation column `status_id` is visible. The comment cited evidence that did not support it, and has been rewritten.

Removing the ID column was checked against everything that might depend on it: the generated `test_can_filter_{plural}_list_by_id` filters through the API and asserts on JSON only; the Playwright spec's `?sort=id&order=desc` is a query parameter that drives backend ordering and needs no rendered column; and nothing in the package indexes list columns positionally. Six existing unit tests asserted the old ID-column behaviour and were corrected rather than worked around.

Generated `Items` list is now `name · sku · description · item_type_id · item_category_id · is_active · actions`, with both FK columns visible and their `RelatedRecordLink` slots live.

Package test count: 415 (assertions 1902 → 1904).

## v2.14.3 — 2026-07-26

### Fixed — mobile module paths wrongly nested a sub-group

`getMobileAppModulePath()` appended `self::$moduleSubGroup`, emitting `system/Custom/ItemTypes` where the mobile app expects `system/ItemTypes`.

The MOBILE tree is deliberately **flat** — `{group}/{Module}`, no sub-group — and the web tree's sub-groups are collapsed there:

```
web    core/Locations/{Countries,Locations,LocationTypes,Wards}
mobile core/Countries, core/Locations, core/LocationTypes, core/Wards
```

All 18 mobile modules in the real app sit at exactly two levels, and `core/Locations` on mobile is the Locations **module** (it has its own `routes.ts`), not a Locations sub-group.

This was self-inflicted. The v2.13.x sub-group casing work read those flat `{group}/{Module}` paths as `{group}/{SubGroup}` and "corrected" mobile to preserve a sub-group segment it never had — the accompanying comment even cited `core/Locations`, `core/Users`, `core/Permissions` as evidence of PascalCase sub-groups when every one of them is a module. It also diverged from `getMobileAppBackendModulePath()`, which never nested, so the mobile backend and mobile frontend would have disagreed about where the same module lives.

The two unit tests asserting the nested behaviour encoded the same misreading and have been corrected to assert flatness.

The defect never reached a real app: mobile frontend pages were not generated for the affected modules, so nothing on disk was wrong — but any project generating mobile pages for a sub-grouped module would have hit it.

Package test count: 415 (2 assertions corrected).

## v2.14.2 — 2026-07-26

Two defects a user spotted by simply looking at the running application — neither was visible to any automated test.

### Fixed — re-scaffolding duplicated menu entries

Generating the same modules twice with `--force` produced **9 menu nodes for 5 modules**: four appeared twice in `menus.json`. `RegistryGenerator` and `ModulesJsonGenerator` are immune because they write keyed objects, so a re-run overwrites; `menus.json` nests items in arrays, so a re-run appended.

`addModuleToMenus()` now finds-and-replaces in place, preserving existing order, and prunes pre-existing duplicates on the next run. Identity is the item's route URL — checked against both the constructed item's own url and the module's deterministic `/{kebab-name}/list` default, so an entry whose title was hand-edited still dedupes (the old code compared humanized titles, which is why raw titles like `"ItemCategories"` never matched). Only for `'#'` wrapper nodes, which have no route, does it fall back to title matching — safe, since such a node can only ever be that module's own nested parent. Two distinct modules cannot collide because they cannot share a list URL.

`countModuleMenus()`, `moduleExistsInMenus()` and `removeModuleFromMenus()` were rewired onto the same identity logic. `MobileAppMenusJsonGenerator` inherits all of it and needed no change.

### Fixed — every generated module's menu icon was `File`

`getModuleIcon()` was a hardcoded 12-entry map; everything unmatched returned `'File'`, leaving 9 of ~18 icons in the real app identical. The map was also stale — `Entities`, `EntityTypes`, `UserEntities`, `UserEntityRoles`, `UserEntityPermissions` and `States` refer to modules that no longer exist in the consuming app.

- An explicitly configured icon now wins on **every** emission path. The blueprint schema has always declared an `icon` field for menu entries, but the default single-item path and both nested subitems ("All X" / "Create X") called `getModuleIcon()` unconditionally and silently discarded it.
- The stale map is reduced to three genuinely-correct exact matches (`Dashboard`, `Reports`, `Statuses`), with an ordered word-stem heuristic behind it covering ~30 stems — `image|photo|media` → `Image`, `price|payment|invoice` → `Banknote`, `categor|type|tag` → `Tag`, `location|ward|country` → `MapPin`, `broadcast` → `Megaphone`, and so on.
- Every icon name was verified to exist in `lucide-vue-next`'s type definitions before use; none were invented. `File` remains the last-resort fallback.

Package test count: 394 → 415.

### Note for consumers — validation messages

Not a generator issue, but found in the same pass and worth recording: an app whose `lang/{locale}/` contains only JSON files and no published `validation.php` will return raw keys (`validation.required`) instead of messages, for every module, generated or not. `php artisan lang:publish` fixes it.

## v2.14.1 — 2026-07-26

### Fixed — nested modules registered no frontend route and 404'd in the browser

`ModulesJsonGenerator` lowercased the sub-group when writing each module's `path` into `modules.json`:

```
modules.json : /modules/system/custom/ItemTypes
on disk      : /modules/system/Custom/ItemTypes
```

`router.ts` resolves a module's routes by that exact string — `routeModules['/src/pages' + module.path + '/routes.ts']` — so the lookup missed, no route was registered, and every nested generated module rendered **Page Not Found** in the UI. The menu entry still appeared, because `menus.json` is built independently, which made the failure look like a routing quirk rather than a registration one.

v2.13.x corrected this casing in `PathManager` for the filesystem paths, but `ModulesJsonGenerator` was a second, independent site that kept lowercasing. Both call sites now preserve the sub-group's PascalCase, matching `PathManager::getFrontendModulePath()`; the top-level group stays lowercase, which is what is genuinely on disk.

**How it was found**: a headed Playwright run against the real app. Every backend test passed throughout — 393 package tests, 247 app tests and 99 generated module tests — because this defect lives entirely in frontend route registration. No amount of PHP testing could see it.

A regression test now asserts `modules.json`'s path casing matches `getFrontendModulePath()` and that the generator no longer lowercases the sub-group.

Package test count: 393 → 394.

## v2.14.0 — 2026-07-26

Removes the asymmetry behind this release line's worst bugs: audit columns were treated as a **convention** when writing schema, but re-derived by **introspection** when reading it.

### Changed — the standard audit columns are now a convention, and introspection verifies rather than decides

The project's own domain-scaffolding workflow already states the convention when authoring schema SQL: *"Business columns only — do NOT include `created_at`, `updated_at`, `deleted_at`, `uuid`, `created_by_id`, `updated_by_id` (the generator adds these to every module automatically)."*

But `SchemaIntrospector` then went back to the live table and re-derived them — `has_soft_deletes` was literally "does a `deleted_at` column exist at this instant". That asymmetry caused:

- **Silent SoftDeletes loss in two independent forks** of the bulk command. Every generated module lost SoftDeletes, timestamps, uuid and audit columns, undetected across three generation waves, because a caller omitted the flags and they defaulted to `false`.
- **An operational workaround**: users had to run `ALTER TABLE ... ADD COLUMN deleted_at` by hand before every generation run, purely so introspection would report a fact the generator already knew by convention.

- New `SchemaConventions` declares the standard system columns (`id`, `uuid`, `created_at`, `updated_at`, `deleted_at`, `created_by_id`, `updated_by_id`) and the default of each derived flag — all four `true`. The set matches `SchemaIntrospector::SKIP_COLUMNS` exactly and was cross-checked against real generated migrations in the consuming app, which emit all of these unconditionally.
- `SchemaIntrospector::meta()` now sources the flags from the convention and inspects the live table only to **detect divergence**.
- Divergence throws `SchemaConventionDivergenceException`, naming the table and column. A structured warning was considered and rejected: the existing issue-handler channel is a no-op unless a caller opts in, which is precisely the silent-failure pattern this change exists to remove.
- A genuinely non-conforming legacy or third-party table opts out explicitly via a `skip_convention_check` meta key. An opt-out a caller sets deliberately is fine; a silent default is not.
- The table-does-not-exist-yet case is unchanged — the bulk command generates modules for tables about to be created, so convention applies and no divergence check is attempted.
- `IntrospectionToConfig::strict()` semantics are preserved: an explicitly-supplied caller flag still wins over the convention default, which projects rely on to force `has_timestamps`/`has_uuid`/`has_creator_updater` true for not-yet-migrated tables.

**Backward compatibility**: a config supplying all four flags explicitly generates byte-for-byte identical output, proven by regression test. This changes what happens when flags are ABSENT, not when they are present.

Package test count: 381 → 393.

## v2.13.5 — 2026-07-26

Fixes a HIGH-severity namespace bug affecting the primary bulk workflow. Found by running `make:modules-from-db` for the first time — every prior verification this release line used the single-module `make:module` command, which does not exercise nested sub-groups.

### Fixed — nested modules resolved every cross-module reference to `Core`

`make:modules-from-db` nests any domain group under `System`, so a blueprint group `Custom` generates modules at `App\Project\Modules\System\Custom\{Module}`. Every cross-module and self-referential reference in those modules pointed at `App\Project\Modules\Core\{Module}` instead:

```php
// ItemCategoriesModel.php — self-referential parent_id
$this->belongsTo(\App\Project\Modules\Core\ItemCategories\ItemCategoriesModel::class, 'parent_id');
// delegation services
use App\Project\Modules\Core\Items\ItemsModel;
```

Three generated tests failed with `Class ... not found` (500). The four delegation services were **latently** broken — no generated test exercises delegations (that coverage family was deliberately skipped as unsafe to generate), so they would have failed only in production.

**Root cause**: the backend module registry is populated *as modules are generated* — the bulk command appends each module after it finishes. A delegation normally points from a parent (scaffolded first, since it is the FK target) to a child (scaffolded later), so the target is never in the registry at lookup time. `PathManager::resolveBackendModuleNamespace()` then silently defaulted to `Core`. The self-referential case failed identically: a module cannot find *itself* in the registry while it is still being generated.

- `PathManager` gained `resolveBackendModuleNamespaceOrNull()`, which returns null rather than silently defaulting; the existing method keeps its old behaviour for current callers.
- `ModelGenerator::generateNamespacedClass()` special-cases self-reference, building the namespace from the module's own group/sub-group instead of a registry lookup that cannot succeed.
- `DelegationServiceGenerator` resolves in order: self-delegation → registry → the caller-declared sub-group parsed from the blueprint up front (order-independent) → `RuntimeException` naming the module, matching how `resolveManualRelationModuleGroup()` already fails loudly.
- `RelatedModuleFormGenerator` had the same root cause on the frontend side and got the same treatment.

The mobile generators were checked and are unaffected — none of them resolves another module's namespace.

### Fixed — `--force` silently no-opped for all delegation output

Found while verifying the above: the consuming app's `ModuleScaffolder` never called `setForce()` on the three delegation generators, unlike every other generator it runs. Delegation services, tab/modal components and related forms were therefore never regenerated on a `--force` run, while the command reported success. Another instance of the pattern this release line has been eliminating. Fixed consumer-side.

Package test count: 373 → 381.

## v2.13.4 — 2026-07-26

Test-suite only; no change to generated output. v2.13.3's generator fix was correct, but two of the package's own assertions still expected the pre-fix multipart call string (`$this->post(..., $payload)` without the `Accept` header), so v2.13.3 was tagged with a red suite. The expectations now match the corrected output.

Recording the process failure honestly, since it is the more useful lesson: the release command chained `phpunit | tail -3 && git commit && git tag && git push`, and `tail`'s exit code masked phpunit's, so a failing suite did not stop the release. Gate on the runner's own exit status, never on a pipeline that ends in a formatting command.

Package test count: 373 (unchanged; 2 assertions corrected).

## v2.13.3 — 2026-07-26

### Fixed — multipart validation tests got a 302 redirect instead of a JSON 422

The last failure in the generated suite. `$this->post()` — which file-carrying modules must use, since `postJson()` destroys an `UploadedFile` — sends no `Accept: application/json` header, unlike `postJson()`. Laravel therefore treats a validation failure as a browser request and REDIRECTS rather than returning JSON:

```
Expected response status code [422] but received 302.
Failed asserting that 302 is identical to 422.
The following errors occurred during the last request: validation.required
```

Validation had fired correctly all along; only the response shape differed. All four multipart call sites now pass `['Accept' => 'application/json']` explicitly. The happy-path multipart tests were unaffected, which is why this surfaced only on the validation paths.

A regression test now scans the generator's own source for every emitted `$this->post(` call site and asserts each declares JSON acceptance, so a future multipart call site cannot silently reintroduce it.

Package test count: 372 → 373.

## v2.13.2 — 2026-07-26

Two bugs in v2.13.0's generated test output, both found by running the GENERATED tests against a real MySQL database. A five-module fixture suite scored 82 passed / 17 failed; these two causes accounted for all 17. Neither was visible to the package's own 367 unit tests.

### Fixed — enum columns got a garbage value in every test except the enum-specific ones

v2.13.0 added enum accept/reject tests but never taught the GENERIC field-value literal builder about `enum_values`. For a `price_tier` column allowing `standard|premium|wholesale`, every generated payload contained:

```php
'price_tier' => 'Test PriceTier ' . uniqid(),
```

Two consequences, both observed live:

- HTTP tests posted an invalid value, so v2.13.0's own new `Rule::in([...])` rule 422'd them — create, edit, view, list, delete, delete-check, activity and filter all failed. The release's two features broke each other.
- Worse, `create{Module}Fixture()` calls `Model::create()` directly, bypassing validation, so the invalid value reached MySQL: `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'price_tier' at row 1`.

`buildFieldValueLiteral()` now resolves `enum_values` and emits the first value deterministically, escaped with `var_export()` as `FactoryGenerator` already does. Both the HTTP payload and the direct-`create()` fixture path route through that one method, so a single fix covers both. The accept/reject tests are unaffected — the former still uses a real value, the latter still deliberately sends a bad one.

Fixing this also turned up a latent bug in the "accepts a valid value" test, which interpolated its literal unescaped; an enum value containing a quote would have produced corrupt PHP. Now escaped identically.

### Fixed — multipart boolean assertions compared a string against a boolean

v2.11.4 correctly serializes booleans as `'1'`/`'0'` for file-carrying modules, since multipart carries everything as strings. But the response assertion still compared against the payload value, while the model casts the column back to boolean:

```
Failed asserting that true is identical to '1'.
```

A new `buildResponseAssertLine()` asserts against a literal `true`/`false` for boolean columns on the multipart path only. The payload serialization is unchanged — `'1'`/`'0'` is correct there — and non-multipart modules keep the original comparison.

Integer, decimal and date literals were checked for the same class of mismatch and are not affected: they are emitted as unquoted PHP literals independently of the multipart flag, so payload and response types already agree.

Package test count: 367 → 372.

## v2.13.1 — 2026-07-26

Fixes a HIGH-severity bug shipped hours earlier in v2.13.0. Caught by regenerating against a real application — it passed all 363 unit tests.

### Fixed — enum selects emitted malformed Vue

v2.13.0 made enum columns render as a static `Select2Field`. The generated markup was invalid:

```vue
:options="splash."
```

This broke the create AND edit forms of every module containing an enum column.

**Root cause** — `BaseComponentGenerator::mapNewFormFieldsToLegacy()` line 360:

```php
'options' => $field['splashKey'] ?? Str::plural($key),
```

Static-options fields carry `splashKey` as `""` — an empty string, not null — and `??` only falls through on null. So `options` was pre-set to `""`. The "preserve all additional properties" loop immediately below guards on `if (!isset($mappedField[$prop]))`, so it then skipped copying the field's real inline `options` array from the config, the key already being set. `generateField()` fell into the splash-fallback branch and emitted `"splash." . ""`.

The config was correct throughout; the loss happened in normalisation. Fixed there rather than special-casing enum at the emitter — a lossy normaliser would silently break the next field type to use this path in exactly the same way. `options` is now preserved when it is a real array, falling back to `splashKey` via `!empty()` rather than `??` so an empty string correctly falls through.

One normaliser is shared by the frontend `CreateFormGenerator`, `EditFormGenerator`, `CustomFeatureModalComponentGenerator` and `RelatedModuleFormGenerator`, plus the mobile create/edit generators that extend them — all fixed by the single change. The list and view mapping paths are separate and were confirmed unaffected.

Also hardened `arrayToJsObjectString()`, which swaps `"` for `'` after `json_encode`. Since `json_encode` never escapes apostrophes, an enum value such as `o'brien` would have prematurely terminated the generated JS string. Apostrophes are now escaped first.

### Note on how this was found

This is the third time in this release line that a defect survived a green unit-test suite and was caught only by generating against a live application. The pattern is consistent enough to be worth stating: **unit tests here verify that the generator emits what it intends to emit; only regeneration verifies that what it emits is correct.** The `:options="splash."` string was perfectly stable and perfectly wrong.

It is also the same defect shape as several already fixed in this line — a guard that does not match the real value (`??` against `""`, a blacklist against a literal never emitted, a dead boolean key). Worth watching for specifically.

Package test count: 363 → 367.

## v2.13.0 — 2026-07-26

A coverage and correctness release. Generated modules now ship substantially more of their own test coverage, enum columns are validated rather than merely stored, and a sweep for this codebase's signature defect — *an operation that does nothing while reporting success* — closed the remaining live instances.

### Added — enum columns are now validated and rendered as a choice

v2.12.0 captured `enum_values` and reached the migration, but stopped there.

- `BaseServiceGenerator` emits `\Illuminate\Validation\Rule::in([...])` from the column's real values, appended alongside the existing `required`/`nullable`/`max:` rules rather than replacing them. `Rule::in()` was chosen over a flat `"in:a,b,c"` string because a value containing a comma or pipe cannot be safely expressed in Laravel's colon-rule syntax. Values are escaped with `var_export()`, not `addslashes()`, which mis-escapes double quotes inside single-quoted PHP.
- `IntrospectionToConfig::buildFrontendFormFields()` now renders enum columns as a static `Select2Field` with the allowed values as options and humanised labels (`in_progress` → "In Progress") via the existing `columnLabel()` helper, with the raw value still submitted. Previously an enum rendered as a free-text box against a fixed value set — the user could only find valid values by guessing into a 422.

### Added — substantially expanded generated test coverage

Generated modules previously shipped 9 PHPUnit methods, all happy-path, all authenticated as a full-access developer. New generated coverage, each gated on the config that makes it meaningful:

- **Authorization**: an `actingAsUserWithoutPermission()` helper plus a 403 test per enabled CRUD feature. Every module seeds `{Module}.{feature}` permissions and previously not one was ever exercised.
- **Validation** beyond missing-required: duplicate value on a `unique` column, over-length on a `max:`-constrained column, and a non-existent foreign key — each emitted only when the module has such a column.
- **Audit columns**: `created_by_id`/`updated_by_id` asserted on create and edit.
- **Soft deletes**: a deleted row is confirmed absent from the list, not merely `assertSoftDeleted`.
- **File columns**: editing other fields without re-uploading leaves the existing file reference intact.
- **Enum**: a valid value is accepted and an invalid one 422s.
- **Decimal**: a value at the column's real scale survives create → view unchanged.
- **Route families** previously untested: the activity route, export, the import template, and createSplash/editSplash.
- **Bulk actions**: valid `ids`-mode dispatch, a 422 for a missing `ids` array, and a 403 without `{Module}.bulkAction`.

Playwright specs gained five steps: validation-error display (the only path that exercises the generated frontend's error rendering at all), soft-deleted row disappearing from the list, required-file rejection, wrong delete-confirmation text, and setting an optional relation picker.

**Deliberately not generated**, because a generated test that cannot pass is worse than a missing one — it turns every consuming project's suite red: delete-check-with-dependents and delegation routes (a child/parent module's field shape is not derivable at generation time, so no fixture row can be built safely); composite-unique violations (enforcement is DB-only, so a duplicate 500s rather than 422s); import file upload (no deterministic file matching a module's custom `processImportRow()` contract); custom `actions[]` (the generated service body is an explicit developer TODO); and filter-mode bulk actions.

### Fixed — the "reports success without doing the work" sweep

An audit for the pattern behind this release line's worst bugs found the remaining live instances, all clustered in the write/patch layer — `BaseGenerator` already checked its writes correctly, but that discipline had never been propagated to the parallel `Ux` hierarchy.

- `Ux/BaseUxGenerator::writeFile()` discarded `file_put_contents()`'s return value and always returned `true`, logging the path as created. It now returns the real result and only records success when the write succeeded.
- `Ux/ShortcutGenerator::patchDetailsLayout()` / `patchMobileDetailsLayout()` ran up to two individually-guarded `str_replace` patches, then unconditionally rewrote the file and logged "(shortcuts patched)". If no anchor matched, the file was rewritten byte-identical and still reported as patched, leaving the shortcut component silently orphaned. A patch matching no anchor is now recorded as skipped, not created.
- `FrontendLocaleGenerator` set its `$wrote` flag immediately after writing without checking the result.
- `ModelGenerator`'s manual `relations.hasMany[]`/`belongsToMany[]` path called `determineModuleGroup()` with no existence check, silently defaulting an unresolvable module to `'Core'` and emitting a relation pointing at a class that does not exist — surfacing much later as a runtime class-not-found. It now fails loudly, naming the module, config path and owning model. The auto-derived FK path already guarded this; the hand-authored path, which is the more typo-prone of the two, did not.
- `BaseServiceGenerator::isForeignKey()` tested `isset($field['foreignId'])` as a boolean, a key no producer in this package ever sets — dead code. Corrected to check the real convention (`type === 'foreignId'`), so a genuine FK column not ending in `_id` is now detected.

### Fixed — `ControllerGenerator` ignored `features.backend`

`RoutesGenerator` gates each standard feature on `isset($backendFeatures[$featureName])`, but `ControllerGenerator` hardcoded all six and emitted them unconditionally, so disabling `create` still produced a `create{Module}()` method with no route pointing at it. Both now derive the condition identically, including the `deleteCheck`-follows-`delete` special case and the createSplash/editSplash gating, which had a second narrower divergence of its own.

A **drift-guard test** now runs both generators end-to-end against the same config and asserts the emitted controller-method set matches the emitted route set, so these two cannot silently disagree again. Verified safe: both real config entry points always populate `features.backend` fully — `IntrospectionToConfig::buildBackendFeatures()` emits all CRUD features unconditionally, and the form-driven path defaults them to enabled.

### Fixed — the Restore button had no permission guard

On the generated view page the Restore button was the only toolbar action without a `hasPermission()` check; Edit and Delete both had one. It is now gated on `{Module}.delete`, matching what the Trash backend already enforces.

### Investigated and reverted — per-module restore/force-delete endpoints

Work in progress toward this release added per-module `restore` and `force-delete` routes, services and controller methods, on the basis that no such per-module route existed. That was literally true but misleading: the consuming app already provides the whole capability through a **Trash module** (`/trash/{module}/{uuid}/restore`, `restore-bulk`, `force-delete`) with its own UI, and the generated view page's Restore button already calls it.

The generated endpoints were therefore redundant — and the force-delete half was worse than redundant. It guarded only on `permission:{Module}.delete`, while `TrashForceDeleteService` additionally requires the DEVELOPER role ("Permanent deletion is restricted to the Developer role."). That would have shipped a second, weaker-guarded path to permanently destroying data. All of it — services, stubs, routes, controller methods and tests — was removed before release.

### Fixed — the `items-suite` fixture masked the bug it should have caught

The fixture's `price` column was `decimal(10, 2)` — exactly `MigrationGenerator`'s fallback when precision is absent — so generated output matched the source *by coincidence*, and the precision defect survived several releases undetected. It is now `decimal(12, 4)`, with an enum column, a composite unique (`item_id, currency`) and a non-conventional index on `effective_date`, since single-column uniques and the conventional uuid/audit indexes are emitted by other code paths and never actually exercised index introspection. The README documents why fixture values must stay off the fallback defaults.

Package test count: 280 → 363.

## v2.12.1 — 2026-07-26

A structural fix for a bug class that had now recurred three times, found by verifying v2.12.0 against a live database rather than trusting its 272 passing unit tests.

### Fixed — `index_groups` was never passed by any consumer, so v2.12.0's index and unique-constraint work did nothing

`SchemaIntrospector::indexGroups()` existed and `IntrospectionToConfig::build()` read `$meta['index_groups']`, but no consumer ever supplied it. Verified live: a table carrying a real composite unique (`uq_item_prices_item_currency` on `item_id, currency`) and a real index (`idx_item_prices_effective_date`) generated a `module.json` with `indexes: []` and `unique_constraints: []`, so neither reached the migration.

Tellingly, the per-column facts from the same release — `precision`, `scale`, `enum_values`, `indexed` — all worked, because those travel with `$columns`. Only `$meta` was affected.

### The underlying cause, and why this is a structural change rather than one more wired field

`$meta` was assembled BY HAND at every call site in every consumer. So each time this package learned to introspect something new, every consumer had to be updated in lockstep, and a missed update failed silently. That has now happened three times:

1. The four schema flags were omitted by a consumer — every generated module silently lost SoftDeletes, timestamps, uuid and audit columns (fixed in v2.11.0 by `IntrospectionToConfig::strict()`).
2. `file_columns` was added but passed by nobody, so inference did nothing until hand-wired.
3. `index_groups`, above.

Strict mode could not catch (2) or (3) because it only guarded the four booleans.

- **Added `SchemaIntrospector::meta()`**, returning the complete schema-derived meta payload in exactly the shape `build()` expects: `has_timestamps`, `has_soft_deletes`, `has_uuid`, `has_creator_updater`, `file_columns`, `index_groups`. It deliberately excludes caller-supplied, non-schema keys (`module_name`, `module_type`, `table_name`, `id_type`), and returns safe defaults without throwing when the table does not yet exist. Consumers now call `array_merge($introspector->meta(), [...caller keys...])`, so a future introspection capability reaches every consumer without touching any of them.
- **Widened `REQUIRED_STRICT_KEYS`** from the four booleans to all six keys. `file_columns` and `index_groups` were precisely this bug class, so strict mode now guards them too — a consumer that hand-builds `$meta` and omits one gets an exception instead of silence.

Package test count: 272 → 280.

### Consumer note

Both consumers' call sites (`ModuleScaffolder`, `MakeModulesFromDb`, `MakeMobileModules`) are updated to the merge form, preserving each site's deliberate overrides: THC_V2 still forces `has_timestamps`/`has_uuid`/`has_creator_updater` true for freshly-scaffolded tables and its blueprint `has_soft_deletes` override still wins; SYSTEM_SHELL still gates on whether the table exists; explicit caller-supplied `file_columns` still take precedence over inference. Because the widened strict-key set is enforced, consumers must be on this version before those call sites will run.

## v2.12.0 — 2026-07-26

Closes the lossy-introspection gap that had been deferred since v2.11.0, plus the file-column detection gap open since v2.10.17. The deferral was on the grounds that no reported bug depended on it; a live run disproved that — one of these was silently generating wrong schema.

### Fixed — every decimal column regenerated as `decimal(10, 2)`

The highest-severity item here, and a silent-wrong-value bug rather than a missing-feature one. `MigrationGenerator` read `$field['precision'] ?? null` and then fell back to `?? 10`, while `IntrospectionToConfig` never populated `precision` or `scale` at all — so the fallback WAS the normal path and every decimal regenerated as `(10, 2)` regardless of its real type. A `decimal(12, 4)` column silently lost scale on every regeneration.

This hid from testing because the `items-suite` fixture's `price` column happens to be `decimal(10, 2)`, so generated output matched the source by coincidence. Precision and scale are now extracted from the real column and threaded through the config; the `?? 10`/`?? 2` fallback now only applies to hand-authored configs that genuinely lack the data.

### Added — indexes, composite uniques, and enums now survive introspection

- **Indexes.** `SchemaIntrospector::parseIndexedColumns()` already parsed real indexes and discarded them: `IntrospectionToConfig` hardcoded every column's `indexed` to `false` and always emitted an empty top-level `indexes` array. Both are now populated, and `MigrationGenerator` emits the corresponding `$table->index(...)` calls, reconciled against the indexes it already emits conventionally (`idx_{table}_uuid`, `idx_{table}_created_by_id`, …) so nothing is declared twice.
- **Composite unique constraints.** `parseUniqueColumns()` filtered to `count($index['columns']) === 1`, dropping every multi-column unique. They now flow through a new top-level `unique_constraints` array and render as `$table->unique([...], 'name')`. Single-column unique behaviour is unchanged.
- **Enums.** `normalizeType()` had no enum branch, so enums collapsed to `string` and their allowed values were lost. A new `enum` normalized type carries its values and emits `$table->enum('col', [...])`.

**Deliberate compromise on enum, stated plainly:** the new type is wired through the migration path and `FactoryGenerator` only. `ModelGenerator`, the validation-rule builder in `BaseServiceGenerator`, and the frontend field-type logic fall back to string/text behaviour — **explicitly, not accidentally**. Full enum support across those layers is a larger job; this release makes the migration correct without half-wiring the rest.

### Added — file-column inference now matches this codebase's real convention

v2.11.0 shipped inference matching string/text columns by name (`*_path`, `*_image`, …). A live run showed it returning `[]` for `item_images.image_media_id`, because the convention actually in use — established in v2.10.17 and used by the hand-built `MobileReleases` (`apk_media_id`, `ota_media_id`) — is an `unsignedBigInteger` holding a `media` row id. The inference worked; it simply did not cover the shape that matters.

- Inference now also matches integer/bigInteger/foreignId columns by `*_media_id` suffix or a bare `media_id`. The previous blanket "FK-shaped columns are never file columns" rule had to be relaxed for exactly this case; ordinary FKs (`item_type_id`, `parent_id`, `category_id`) remain excluded because they do not match the pattern, and `*_url`/bare `path` stay excluded as before.
- A secondary FK-target-aware signal (`is_fk` plus `foreign_table === 'media'`) exists for forward-compatibility but is **not currently exercised by any real schema path**, since these columns carry no DB-level FK constraint and the `_id`-suffix table-guessing convention cannot resolve `apk_media_id` to `media` anyway. Name-matching is the authoritative signal today.
- Explicit caller-supplied `file_columns` still take precedence over inference.

### Fixed — `FactoryGenerator` ignored the enum values it was given

It read `$column['options']`/`$column['values']`, neither of which is populated. It now reads the real `enum_values` key and emits `fake()->randomElement([...])` drawn from the actual list, so a generated factory can no longer violate the column's enum constraint. Values are escaped with `var_export()` rather than `addslashes()`, which mis-escapes double quotes inside single-quoted PHP output; a test covers a value containing both quotes and backslashes.

### Investigated and deliberately not changed — mobile models and `newFactory()`

v2.11.1 noted that `mobile_app/backend/model.stub` lacked the `newFactory()` override the backend stub received. Investigation showed adding it would have been actively harmful: no `MobileFactoryGenerator` exists, mobile models never get a co-located factory, and the mobile stub does not even use `HasFactory` or extend the shared `BaseModel`. The override would have referenced a class that is never generated — a latent fatal. A regression test now locks in the current, correct behaviour. If mobile factories are ever wanted, the order must be: build the generator first, then add the override.

Package test count: 231 → 272.

## v2.11.4 — 2026-07-26

Completes v2.11.3. That release taught the PHPUnit generator to emit a fake `UploadedFile` for file columns, but left the request transport alone — so the file was destroyed in transit and the fix could not actually work.

### Fixed — generated tests JSON-encoded a payload containing an `UploadedFile`

`postJson()`/`putJson()` serialize the payload as JSON, which cannot carry an `UploadedFile`. In the live run the request arrived as `content-type: application/json` with `content-length: 34`: the upload was gone, and the sibling `item_id` field went with it, surfacing as `{"errors":{"item_id":["validation.required"]}}` rather than anything mentioning files.

- Modules carrying at least one file column now issue real multipart requests: `$this->post(...)` for create (and the create-validation test, which shares the same payload and route).
- Edit routes are registered as `PUT`, but PHP never populates `$_FILES` on a PUT, so multipart must travel over POST. The generated edit test now sends `$this->post("/api/{route}/{$fixture->uuid}/edit", $payload + ['_method' => 'PUT'])`, relying on Laravel's `enableHttpMethodParameterOverride()` — enabled unconditionally in `Request::capture()`, which the test client also passes through. This is the identical mechanism the frontend has used since v2.10.17, where `BaseComponentGenerator::generateSubmitCall()` adds `_method: 'PUT'` because `sendFormDataRequest` always issues a POST.
- Multipart carries every value as a string, so boolean fields are emitted as `'1'`/`'0'` — again mirroring the v2.10.17 frontend fix. This applies only on the multipart HTTP path; the fixture helper's direct `Model::create()` call still receives a real PHP `true`.
- Modules with no file column are byte-for-byte unchanged and still use `postJson()`/`putJson()`, guarded by an explicit regression test.

Package test count: 227 → 231.

## v2.11.3 — 2026-07-25

Closes the last gap surfaced by running the `items-suite` fixture end-to-end against a live database: 38 of 40 generated tests passed after v2.11.2, with both remaining failures on the file-upload column.

### Fixed — generated PHPUnit tests sent an integer where the backend demanded a file

Since v2.10.17 the backend generators fully support file columns: for a column in `file_columns` the generated service emits a `["required", "file"]` rule and `beforeCreate()`/`beforeUpdate()` logic converting an `UploadedFile` into a media row via `MediaService::createFile()`. `PhpUnitTestGenerator` never learned about any of it, so it treated `image_media_id` as an ordinary FK-shaped integer and emitted `'image_media_id' => 1`, failing with `validation.file`.

- Added `isFileColumn()`/`buildFileUploadLiteral()`, checked **before** the FK/`exists:` inference. Ordering matters: a file column is typically FK-shaped (`*_media_id` is an `unsignedBigInteger`), so the FK branch would otherwise claim it first.
- HTTP-payload contexts (create/edit/validation tests) now emit `\Illuminate\Http\UploadedFile::fake()->image('test.jpg')` when the column name hints at an image, otherwise `->create('document.pdf', 100)`. The generated rule is `file`, not `image`, so a generic fake file suffices.
- The fixture helper, which calls `Model::create()` directly rather than issuing an HTTP request, deliberately keeps the old integer logic — no validation or file conversion happens on that path.
- The generated response assertion needed handling too, or the fix would merely trade a validation failure for an assertion failure: `assertJsonPath('data.<col>', $payload['<col>'])` cannot hold for a file column, because the backend replaces the uploaded file with the integer id of the media row it created. For file columns that assertion is now `assertIsInt($response->json('data.<col>'))`.

`FactoryGenerator` was checked and deliberately left unchanged: factories write straight to the database, bypassing HTTP validation and file conversion, so a plain integer for a `*_media_id` column is correct there.

Package test count: 223 → 227.

## v2.11.2 — 2026-07-25

Fixes a bug in v2.11.1's own new `FactoryGenerator`, found the same way v2.11.1's bugs were: by running the generated output against a real MySQL database. 27 of 40 generated tests failed with `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'id' at row 1`.

### Fixed — every generated factory wrote a UUID into an auto-increment `id`

- `FactoryGenerator::buildIdLine()` decided whether to emit an explicit `id` by blacklisting the single literal string `'autoincrement'`. But `IntrospectionToConfig::build()` only ever emits `'uuid'` or `'bigint'` for `id_type` — `'autoincrement'` exists solely as the constructor's fallback for a config missing the key, and never appears in a real generated `module.json`. The blacklist therefore never matched, and every factory fell through to `'id' => (string) Str::uuid()` regardless of the column's real type, which MySQL rejects outright on a `bigint unsigned AUTO_INCREMENT` primary key.
- Inverted to a whitelist: an explicit `id` is emitted only when `id_type` is `'uuid'` or `'string'`. Everything else — `'bigint'`, `'integer'`, and the `'autoincrement'` fallback — omits `id` entirely and lets the database assign it, matching every hand-built reference factory (`StatusesFactory`, `LocationsFactory`, `MediaFactory`, `MobileReleasesFactory`), none of which set `id`.
- `'uuid' => (string) Str::uuid()` is still emitted for modules carrying a uuid column, since that column relies on a DB-level default expression Eloquent does not read back on `create()`.

Package test count: 220 → 223.

## v2.11.1 — 2026-07-25

Both fixes here were found by actually running v2.11.0's output — generating the five-module `items-suite` fixture into the real consuming app, migrating a live database, and running the generated PHPUnit tests. Neither was findable by asserting on generated source text, and one of them is a bug in v2.11.0's own headline fix.

The run also confirmed v2.11.0's core work landed correctly: all five generated modules carried `has_soft_deletes`/`has_timestamps`/`has_uuid`/`has_creator_updater` as `true`, `$table->softDeletes()` reached the generated migrations, `use HasFactory, SoftDeletes;` reached the models, and cross-module plus self-referential FKs resolved to the right related modules. 31 of 40 generated tests passed, with every module lacking a required cross-module FK passing outright.

### Fixed — `RegistryGenerator` silently skipped every module group except `Core` and `System`

The most severe fix here: it made the package's own documented `Custom/` workflow produce modules that could not run at all, while reporting success.

- `updateCoreRegistry()` returned early for any group other than `Core`, and `updateSystemRegistry()` returned early for any group other than `System` — **both returning `true`**. A `Custom` module therefore matched neither branch and was written to no registry, yet `make:module` reported `Created: 47, Skipped: 0, Errors: 0`.
- The consequence is total, not cosmetic. The consuming app's `ApplicationServiceProvider::registerModules()` iterates `Registry::getRegistry()` and only then calls `loadMigrationsFrom()` and registers each module's `Routes/api.php`. An unregistered module has no routes and no migrations loaded, so **all 40 generated tests for the five-module suite failed with 404** until registry entries were added by hand. Adding only those entries made all 40 routes appear, isolating the registry write as the single missing step.
- This is the same failure shape v2.11.0 was released to eliminate — a no-op that returns a success value — just in a different file, which is a useful reminder that the pattern was never limited to config defaulting.
- Fixed by collapsing the two near-duplicate write methods into one `updateRegistryForGroup()` (and the two removal methods into `removeFromRegistryForGroup()`) routed through a shared `registryFileForGroup()` helper: `Core` still goes to `registry_core.json`, and **every other group** — `System`, `Custom`, or anything a project invents — goes to the general `registry.json` tier. `registry_kernel.json` is untouched, as that tier is hand-maintained. Entries now derive `path` and `type` from the actual module group rather than per-method hardcoded literals, so a `Custom` module gets `"type": "Custom"` and a path under `/Custom/`, and nested sub-groups resolve correctly.
- Worth recording: the frontend `modules.json`/`menus.json` generators and the mobile `registry.json` generator all handled `Custom` correctly in the same run. Only the backend registry was group-gated.

### Fixed — v2.11.0's cross-module FK fixture fix did not fix the case it was written for

v2.11.0 replaced a hardcoded `1` with a registry-resolved lookup:

```php
'item_type_id' => \App\...\ItemTypesModel::query()->value('id') ?? 1,
```

Generated CRUD tests use `RefreshDatabase`, so the referenced parent table is **empty** at fixture time. `value('id')` returns null, the `?? 1` fallback engages, and `1` does not exist — failing with `validation.exists`. This accounted for all 9 remaining failures in the live run. The lookup only ever worked when the parent table happened to be pre-seeded, which is precisely not the case the fix was written for. Looking up a row was the wrong strategy; the test must create one.

Generated modules had no way to satisfy this: they ship no factory at all, and their generated seeders contain `"data": []` (correct — the generator cannot invent business data). The hand-built modules this scaffolding imitates work only because they ship *both* a factory and real seed rows.

- **Added `src/Generators/Backend/Factories/FactoryGenerator.php`**, emitting a `{Module}Factory.php` co-located in the module directory — matching `StatusesFactory`/`LocationsFactory`/`MobileReleasesFactory`, not `database/factories/`. `definition()` is derived from the module's own column config: per-type literals for string/text/boolean/date/datetime/decimal/integer/enum/json, `fake()->unique()` variants for unique columns, auto-managed columns skipped, `uuid` and `created_by_id` emitted only when `ModuleConfigContract` reports the module carries them. FK columns follow the same three-way rule as the test generator: self-referential and nullable resolve to `null`, a required FK resolves to the related module's factory, and an unresolvable related module falls back to a literal.
- **`PhpUnitTestGenerator`** now emits `\App\...\{Related}Model::factory()->create()->id` for required cross-module FKs. Self-referential and nullable handling, both checked first, are unchanged, as is the graceful fallback when the module registry cannot resolve the related module.
- **`backend/model.stub` gained the `newFactory()` override.** This was a hard blocker rather than a nicety: Laravel resolves factories as `Database\Factories\{Model}Factory`, which can never match a co-located `App\Project\Modules\...\{Module}Factory`, so `{Module}Model::factory()` would have thrown. Shipping the FK change without it would have converted a `validation.exists` failure into an outright fatal — strictly worse than the bug being fixed. The override copies `StatusesModel::newFactory()` verbatim and needed no `ModelGenerator` code change, since the stub's existing placeholders already resolve the namespace through the same helper `FactoryGenerator` uses. Notably `model_users.stub` already carried this pattern; only the default `model.stub` lacked it.

### Consumer note

`FactoryGenerator` requires one wiring line in each consuming app's `ModuleScaffolder`:

```php
$run('Factory', \Blutrixx\GeneratorEngine\Generators\Backend\Factories\FactoryGenerator::class);
```

### Known gaps, unchanged in this release

- **File-column inference still misses this codebase's real convention.** v2.11.0's heuristic matches string/text columns (`*_path`, `*_image`, …), but the established convention — set in v2.10.17 and used by the hand-built `MobileReleases` — is an `unsignedBigInteger` `*_media_id`. A live run against `item_images.image_media_id` returned `file_columns = []`, so the explicit `--file-columns=` marker remains mandatory. The inference works; it simply does not cover the shape that matters here.
- **Decimal precision is silently wrong, not merely absent.** `MigrationGenerator` reads `$field['precision'] ?? null` then defaults to `10`, and `IntrospectionToConfig` never populates `precision`/`scale`, so every decimal regenerates as `(10, 2)`. The `items-suite` fixture's `price` is `decimal(10, 2)`, so the output matches by coincidence and masks the bug — a `decimal(12, 4)` column would silently lose scale on regeneration. This moves the deferred lossy-introspection work (indexes, composite uniques, enums, precision/scale) from "no reported bug depends on it" to actively producing wrong schema.
- `mobile_app/backend/model.stub`, used by the separate `MobileModelGenerator`, has no `newFactory()` override.

Package test count: **206 → 220**.

## v2.11.0 — 2026-07-25

A structural release. Every bug fixed here is a symptom of the same underlying defect — the module config was a loose associative array whose schema (`schema/module-config.schema.json`) was documentation only, never enforced at runtime — and the primary work of this release is closing that hole rather than patching the symptoms individually.

The evidence that this was structural and not a coding slip: **the identical silent-SoftDeletes-loss bug existed independently in two separate forks of the same command.** It was already known in THC_V2's `MakeModulesFromDb` (where it survived three generation waves, each worked around by hand-patching migrations and manually running `ALTER TABLE ... ADD COLUMN deleted_at` before every `--force` run). Auditing for the root cause turned up the same bug, never previously reported, in SYSTEM_SHELL's own `MakeModulesFromDb`: its `$meta` array omitted **all four** schema flags (`has_soft_deletes`, `has_timestamps`, `has_uuid`, `has_creator_updater`), so every bulk-generated SYSTEM_SHELL module had been silently losing SoftDeletes, timestamps, UUID and creator/updater audit columns. Neither fork had any way to notice, because an omitted key and an intentional `false` were indistinguishable to every consumer.

### Added — `IntrospectionToConfig::strict()`: an omitted schema flag is now an error, not a silent `false`

- `src/Schema/IntrospectionToConfig.php` gained a `$strict` constructor flag with `::strict()` / `::lenient()` named constructors, and a `validateMeta()` step that runs at the top of `build()`.
- In **strict** mode, `$meta` must explicitly carry `has_timestamps`, `has_soft_deletes`, `has_uuid` and `has_creator_updater`. Omitting any of them throws an `InvalidArgumentException` naming the missing keys. An explicit `false` is still perfectly valid and is preserved — the point is to distinguish *"the caller decided false"* from *"the caller forgot"*, which is precisely the distinction the old `?? false` could not express.
- In **both** modes, unknown/typo'd top-level `$meta` keys are rejected with a `levenshtein`-based nearest-match suggestion, so `has_soft_delete` now reports itself instead of silently doing nothing. This check is unconditional because no existing caller passes unknown keys, making it purely additive.
- The default constructor remains **lenient**, so the change is backward-compatible for out-of-package callers that have not yet migrated. All six call sites across SYSTEM_SHELL and THC_V2 have been switched to `::strict()` — see the consumer note at the end.

### Added — `ModuleConfigContract`: one resolution rule for the derived module flags

Before this release the same fact was re-derived in three places with three different defaulting semantics: `SchemaIntrospector::hasSoftDeletes()` checked the live DB for a `deleted_at` column; `ModelGenerator::hasSoftDeletes()` read the config flag *and separately rescanned the field list*, so it could disagree with the flag it had just read; `MigrationGenerator::hasSoftDeletes()` read the flag with a bare `?? false` and never rescanned. A model and its own migration could therefore disagree about whether a table had soft deletes.

- New `src/Schema/ModuleConfigContract.php` exposes `hasSoftDeletes()`, `hasTimestamps()`, `hasUuid()` and `hasCreatorUpdater()` as the single sanctioned way to read these facts, each with its one resolution rule documented in a docblock. `ModelGenerator`'s field-rescan fallback is folded in as an explicit, documented part of that rule rather than an accident of one consumer.
- `ModelGenerator` and `MigrationGenerator` are reduced to one-line delegations. This is a de-duplication, not a behaviour change: generated output for an already-correct config is unchanged.

### Added — `FkGroupDemoter`: the FK-demotion fix no longer lives in only one fork

`demoteFksToSkipGroupTables()` — which strips `relatedModule`/`foreignId` from FKs pointing at skip-group tables, and (per THC_V2's Wave 3 fix) cross-checks the on-disk module registry so it does *not* demote FKs whose target table already has a real scaffolded module — existed only in THC_V2. SYSTEM_SHELL had no equivalent and no way to receive the fix.

- New `src/Schema/FkGroupDemoter.php` with a static `demote(array $columns, array $skipTables, array $tableToGroupMap = [])`. The default empty `$tableToGroupMap` reproduces SYSTEM_SHELL's existing unconditional-demotion behaviour exactly, so making the capability shared does not change SYSTEM_SHELL's runtime behaviour; THC_V2 keeps passing its map and its Wave 3 semantics are preserved unchanged.

### Added — schema-level file-column inference

v2.10.17 shipped the full file-upload *wiring* but explicitly left the *detection* gap open, still requiring a manual `--file-columns=` marker at `make:module` time. That gap is now closed.

- `SchemaIntrospector::fileColumns()` (live-schema wrapper) and the pure static `SchemaIntrospector::filterFileColumns(array $columns)` (directly unit-testable against a `columns()`-shaped array) infer file columns from schema shape. Detection is deliberately conservative — false negatives are preferable to a plain string column being turned into an upload widget: string/text/longText columns only, FK columns always excluded, matched either by suffix (`*_file`, `*_path`, `*_image`, `*_photo`, `*_avatar`, `*_logo`, `*_attachment`, `*_document`) or exact bare name (`image`, `photo`, `avatar`, `logo`, `file`). Both pattern lists are class constants so they are easy to extend.
- `*_url` and a bare `path` column are excluded by construction rather than by special-case, since the former is typically an external link and the latter is usually a routing/menu column.
- Explicitly caller-supplied `file_columns` still take precedence over inference — the marker path is unchanged, it simply is no longer mandatory.

### Fixed — cell-renderer slot prop was hardcoded, and wrong in one of its two contexts

The original diagnosis of this bug (recorded as a stub-vs-stub `{item}`/`{row}` mismatch) was **wrong**, and worth recording as such. Every stub was already correct: they wrap two genuinely different components with genuinely different slot contracts — `ReportTable.vue`/`ListTable.vue` expose `<slot :name="cell-…" :row="row">`, while `ListPageBareTable.vue`/`ListPageBareCards.vue` expose `:item="item"`. Each stub matched the component it actually wraps.

The real defect was in PHP. `BaseComponentGenerator`'s shared cell-renderer methods hardcoded `row.` accessors — correct when spliced into the `ListTable`-wrapping list stubs, but wrong when spliced into `custom/tab_action.stub`, which wraps the `item`-scoped `ListPageBareTable`. The same cross-context reuse that v2.10.16 fixed in one direction was still live in the other.

- `generateCustomCellRenderersFromListFields()` and `generatePrimaryCellContentFromListFields()` both now take an explicit `string $slotProp = 'row'` parameter. `CustomFeatureTabComponentGenerator` passes `'item'`; `ListComponentGenerator` relies on the `'row'` default. Neither generator hardcodes or post-processes the prop name any more.
- `frontend/features/list/component.stub` was additionally stale in its own right — it wrapped the legacy `<ListPageBareTable>` with `{ item }` while the `[[customCellRenderers]]` placeholder injected into that same file already emitted `{ row }`, an internal contract break within a single generated file. Rewritten to wrap `<ListTable>` with `{ row }` throughout, matching the two live hand-completed references (`LocationTypesList.vue`, `PermissionsList.vue`).

### Fixed — mobile `file-input.stub` referenced a component that has never existed

- `mobile_app/fields/file-input.stub` rendered `<FileInputFieldWithCropper>`, but `BaseComponentGenerator` imports `FileInputField` from `@/components/form-fields/FileInputField.vue` for both frontend and mobile targets, and `FileInputFieldWithCropper` has no definition anywhere in SYSTEM_SHELL. The frontend twin was corrected in v2.10.15; the mobile stub was left behind. Now renders `FileInputField`, with all mobile-specific `v-model` and crop/upload props preserved.

### Fixed — `PathManager` lowercased the domain sub-group in three of its path derivations

On disk, generated module folders use a **lowercase top-level group** (`core`, `system`, `dev`) but a **PascalCase sub-group** (`Locations`, `Access`, `Users`, `Notifications`) — confirmed against the real trees under `SYSTEM_SHELL/FRONTEND/src/pages/modules/` and `MOBILE_APP/.../modules/`, and against the import specifiers in real generated `.vue` files. `getBackendModulePath()` already preserved this correctly; the frontend, mobile and import-segment derivations each applied an extra `strtolower()` to the sub-group, so regenerating an existing nested module wrote to a wrong lowercase path and silently created a duplicate folder alongside the real one.

- `getFrontendModulePath()`, `getMobileAppModulePath()` and `resolveFrontendImportSegment()` no longer lowercase the sub-group segment. The lowercase top-level group segment is preserved in all three, since that is what is genuinely on disk. Flat (non-sub-grouped) modules are unaffected.

### Fixed — generated PHPUnit fixtures hardcoded `1` for required cross-module foreign keys

`PhpUnitTestGenerator` already returned `null` for self-referential and nullable FKs, but a **required** FK pointing at another module still fell through to a literal `1`, so the generated test failed against any fresh database where the referenced table had no row with id 1 — the normal case.

- Required cross-module FKs now resolve the related module through `PathManager::findModuleByTable()` / `resolveBackendModuleNamespace()` — the same mechanism `DelegationServiceGenerator` already uses for cross-module FQCN resolution — and emit `\App\Project\Modules\…\{Related}Model::query()->value('id') ?? 1`, which picks up a real already-seeded row (correct for the lookup/reference tables these FKs overwhelmingly point at).
- When the module registry has no entry for the referenced table, it falls back to the previous literal `1` rather than emitting unresolvable PHP. Self-referential and nullable handling is checked first and is untouched.

### Test coverage — the contracts that had nothing asserting them now do

This bug class recurs because stub-to-generator and path-derivation agreements were plain strings across file boundaries with no test holding them together. New regression suites close that:

- `ListSlotPropConsistencyTest` scans the **entire** `src/Generators/Templates/` tree (not just the list feature) asserting every `#cell-*` slot's destructured prop matches what its wrapping component actually provides.
- `StubComponentImportabilityTest` asserts no stub references a component the generators do not import — `FileInputFieldWithCropper` specifically can never come back.
- `PathManagerSubgroupCasingTest` asserts the frontend, mobile, import-segment and backend derivations agree on sub-group casing.
- Plus `IntrospectionToConfigStrictModeTest`, `ModuleConfigContractTest` (including a data-provider test asserting `ModelGenerator` and `MigrationGenerator` return identical `hasSoftDeletes()` for every combination of flag-present/absent × `deleted_at`-present/absent), `FkGroupDemoterTest`, and `SchemaIntrospectorFileColumnsTest`.

Package test count: **146 → 206**.

### Not included — the lossy-introspection gap

Deliberately deferred to its own release, because all four parts change `MigrationGenerator` output — the one generator where a mistake produces a bad schema you have to migrate back out of — and no currently-reported bug depends on any of them:

- `indexed` is still hardcoded `false` (`IntrospectionToConfig`) and the module-level `indexes[]` is still always empty, even though `SchemaIntrospector::parseIndexedColumns()` already parses the real indexes and throws them away.
- Composite unique constraints are still dropped — `parseUniqueColumns()` keeps only single-column uniques.
- `enum` columns still collapse to `string` with their values discarded.
- Decimal `precision`/`scale` are captured by the introspector but have no config fields to carry them, so they never reach the migration.

### Consumer note — this release requires a coordinated bump

SYSTEM_SHELL and THC_V2 both consume this package as a tagged dist dependency (no path repository), so their `vendor/` copies do not track local edits. Both have been updated to call `IntrospectionToConfig::strict()`, to thread all four schema flags explicitly, to feed `fileColumns()` into `$meta['file_columns']`, and to delegate to `FkGroupDemoter`. **That consumer code will fatal against v2.10.17 or earlier**, so their constraints move from `^2.10.10` to `^2.11.0` in the same cycle rather than relying on the caret range that would otherwise have permitted an older resolve.

THC_V2 additionally gains an optional `has_soft_deletes` map in its emitted blueprint JSON: when a module has an entry it wins outright, and when absent the value falls back to live introspection exactly as before, so existing blueprints keep working untouched. This removes the structural need for the manual `ALTER TABLE … ADD COLUMN deleted_at` step that every generation wave has required until now — soft-deletes can finally be declared as intent instead of being inferred from whatever the live table happened to look like at the instant `--force` ran.

## v2.10.17 — 2026-07-24

The file-upload gap tracked since v2.10.15/16 (`items-suite`'s `ItemImages` module carrying a file-marked column with no real upload wiring behind it) is now genuinely closed end-to-end: a generated module with a `--file-columns`-marked column gets a real multipart create/edit form, a real backend upload-to-media pipeline, and a real `belongsTo(Media)` relationship — proven with a live, browser-driven Playwright run (login → create with a real file upload → edit without re-uploading) and a direct DB check confirming a real `media` row was created and correctly linked back via the `_media_id` column, with zero manual workarounds.

### Added — full file-upload flow: marker → multipart frontend → backend `MediaService` wiring → Model relationship

This threads a single new signal — `IntrospectionToConfig`'s existing `file_columns` meta option (previously only reaching the frontend field-type decision) — through to every layer that needs to know a column is a file upload, not a plain FK, matching the hand-built `MobileReleases` module's convention exactly at each step:

- **Marker, threaded wider**: `src/Schema/IntrospectionToConfig.php` now also copies `file_columns` to the top level of the generated `module.json` config (previously buried only inside `features.frontend.create.fields[]`), so backend generators that read `$config` directly — not just the frontend field-builder — can see it.
- **Frontend — conditional multipart switch**: `BaseComponentGenerator` gained `hasFileInputField()`/`extractFileInputFields()`/`generateFileRefsBlock()`/`generateSubmitCall()`/`generateRequestImportLine()`. Any form containing a `file-input` field now declares a separate `ref<File | null>` per file field (a `File` instance can't round-trip through the reactive `form` object the way plain values do), switches its `@/helpers` import to `sendFormDataRequest`, converts boolean fields to `'1'`/`'0'` strings (a `FormData`-only requirement), and — for edit forms — adds a `_method: 'PUT'` override field, since `sendFormDataRequest` always issues a POST and Laravel's HTTP-method-override spoofing is what actually routes it to the registered `PUT` edit route. `CreateFormGenerator`/`EditFormGenerator` wire these into three new template placeholders (`[[requestImportLine]]`, `[[fileRefsBlock]]`, `[[submitCall]]`) in `create/form.stub`/`edit/form.stub`. Forms with **no** file-input field are byte-for-byte unaffected — this is a strict conditional add, not a rewrite of the submit path.
- **Backend — real upload-then-store-id logic**: `BaseServiceGenerator::generateValidationRules()` now emits a `file` rule (`required`+`file` on create, always `nullable`+`file` on edit, matching the hand-built `MobileReleasesEditService` convention that every file field is optional on re-edit) for any column named in `file_columns`, instead of whatever FK/integer rule its column type would otherwise get. The new `generateFileColumnUploads()` emits inline `beforeCreate()`/`beforeUpdate()` logic — run first, ahead of any other before-save processing — that converts the raw `UploadedFile` Laravel's `Request::all()` merges in under the same wire key into a `media` row via `MediaService::createFile()` and stores the returned int id back under that same column key; on edit, when no new file was sent, the key is unset entirely so `$model->update()` leaves the existing `*_media_id` column untouched. `CreateServiceGenerator`/`EditServiceGenerator` now call this ahead of their existing legacy/processor-array before-save chains.
- **Model relationship**: `ModelGenerator::generateFileColumnRelationships()` emits a `belongsTo(\App\Project\Modules\Core\Media\MediaModel::class, '<column>')` method for every column named in `file_columns` that the module actually owns, method-named by stripping the column's `_id` suffix and camelCasing it (e.g. `image_media_id` → `imageMedia`) — the exact same derivation `ModelGenerator` already uses for real FK columns, and matching the hand-written `MobileReleasesModel::apkMedia()`/`otaMedia()` convention column-for-column. Media's namespace is hardcoded (mirroring `MobileReleasesModel`'s own hardcoded reference) rather than resolved via the module registry, since Media is a fixed Core module not guaranteed to carry a registry entry the way introspected business modules do.

### Fixed — `items-suite`'s `ItemImages` fixture carried the wrong column shape for the scenario it claims to exercise

- `item_images.image_path` (a plain `varchar(255)`) was never a realistic stand-in for a file-upload column — every real convention in this codebase (`MobileReleases.apk_media_id`/`ota_media_id`, and now every generator-emitted file column) stores an `unsignedBigInteger` FK-shaped reference to a `media` row, not a raw path string. Corrected to `image_media_id` (`unsignedBigInteger`, indexed, no hard FK constraint per this project's convention) across `columns.php`, the `create_item_images_table` migration, and `README.md`'s `--file-columns=` usage instructions. This was a fixture-only correction needed to make the feature above testable end-to-end at all — not a behavioral change to any generator.

### Fixed — two bugs found only by running the file-upload flow live, not by asserting on generated source text

- **Generated Playwright fixture wasn't a real image**: `PlaywrightTestGenerator`'s `file-input` fill step embedded only an 8-byte PNG magic-number prefix (`Buffer.from([0x89, 0x50, ...])`) as the entire "file." Real MIME sniffing — PHP's `finfo`, exactly what Laravel's `file`/`image` validation rules use server-side — reads actual file content, not just a magic number, and rejected the truncated buffer outright, so a generated file-upload e2e test could never pass against the real backend. Replaced with a genuine, complete, valid 1×1 transparent PNG (70 bytes), base64-embedded and decoded via `Buffer.from(..., 'base64')`; confirmed live with `finfo_file()` reporting `image/png` for the exact decoded bytes.
- **Generated Edit form's mount-time load leaked file columns' raw values back into the request**: `edit/form.stub`'s `onMounted()` did `form.value = { ...form.value, ...response.data }` — a wholesale merge of every key the view endpoint returned. Since file-input fields are deliberately excluded from `form`'s initial declaration (their `File` ref is the single source of truth — see `generateFormFields()`), this merge wrote the loaded record's raw `*_media_id` integer back into `form.value` anyway, which then got submitted and 422'd against the backend's `["nullable","file"]` rule on a plain edit that touched no file. Fixed to filter the merge to only keys already declared on `form.value` before spreading, so an untouched file column is never re-submitted.

### Not included — automatic schema-shape detection for file columns

This release still requires an explicit `--file-columns=<column>` marker at `make:module` time; `SchemaIntrospector`/`IntrospectionToConfig` do not yet infer a file/media column automatically from its shape (e.g. an `unsignedBigInteger` column named `*_media_id`) the way boolean/FK/self-referential conventions are already auto-detected. Automatic detection remains a separate, smaller, still-open future enhancement — this release closes the *wiring* gap (marker → working upload flow end-to-end), not the *detection* gap.

### Test coverage

New/expanded coverage across `ModelGeneratorTest`, `BaseServiceGeneratorTest`, `BaseComponentGeneratorTest`, `PlaywrightTestGeneratorTest`, plus new `CreateServiceGeneratorTest`/`EditServiceGeneratorTest`/`EditFormGeneratorTest`. Package test count: 100 → 146 across this release's steps.

## v2.10.16 — 2026-07-24

Every fix in this release was found the same way: generating and end-to-end testing 5 realistic, interrelated modules (`ItemTypes` → `ItemCategories` → `Items` → `ItemImages` → `ItemPrices`, the `items-suite` fixture added in v2.10.15) and running the generated PHPUnit + Playwright output for real, not just asserting on generated source text. All five bugs below only surfaced because the modules actually had FK relationships, a self-referential hierarchy, boolean/date/number columns, and were run against a live app.

### Fixed — generated list pages crashed outright the instant a boolean/badge column had a real row to render (real, currently-shipping bug — not test-generator polish)

- This is the most severe fix in this release: it affects every already-generated module with a boolean or "badge-style" list column, not just today's new test modules, and it crashes the *entire* list page, not just one cell. `BaseComponentGenerator::generateCustomCellRenderersFromListFields()` emitted `<template #cell-{key}='{ item }'>` for badge/boolean columns, referencing an `item` slot prop. But `ListTable`/`ReportTable.vue` — the only components that ever render this slot — only ever provide a `row` prop (`<slot :name="`cell-${col.key}`" :row="row" ...>`); `item` is undefined there and always has been. The sibling FK-cell branch (added in v2.10.13) already correctly used `{ row }`; this older badge/boolean branch predates it and was never brought in line. Confirmed live: a generated `ItemsListPage.vue` threw `Cannot read properties of undefined (reading 'is_active')` and took down the whole list the instant a real row existed — `is_active` is a boolean column and defaults to visible.
- `src/Generators/Frontend/Components/BaseComponentGenerator.php`: both the relationship-badge branch and the direct-field badge branch now emit `<template #cell-{key}="{ row }">` and reference `row.*` throughout, matching the FK-cell branch.
- **Action required for already-generated modules**: any module with a boolean or badge-rendered list column needs a `--force` regeneration of its list page component to pick up this fix — the underlying template file is otherwise untouched by `generate()`'s no-overwrite guard.
- Added regression coverage in `tests/Unit/Generators/Frontend/Components/BaseComponentGeneratorTest.php` asserting `{ row }` (never `{ item }`) and `row.*` references for both the relationship and direct-field badge branches.

### Fixed — `SchemaIntrospector` never recognized MySQL's `tinyint(1)` boolean convention or the universal `parent_id` self-referential-hierarchy convention

- Both gaps blocked the two bugs above/below from ever being reachable via real schema introspection, not just hand-rolled config: `$rawType` was built from the bare type name only (`tinyint`, with the `(1)` display-width suffix discarded), so `normalizeType()`'s existing `'tinyint(1)' => 'boolean'` mapping could never actually fire — every `$table->boolean()` migration column introspected as a plain integer, never as boolean. Separately, `inferFkByConvention()` matched an FK column's base name against pluralized/singularized table names (e.g. `item_type_id` → `item_types`), which structurally can never match `parent_id` — there is no "parents" table; a self-referential hierarchy FK doesn't encode its target table's name at all.
- `src/Schema/SchemaIntrospector.php`: recovers the display width from the column's full `type` string before normalizing, and `inferFkByConvention()` now special-cases `parent_id` as a self-reference to the table currently being introspected.
- Both were prerequisites for reproducing the `ModelGenerator`/`BaseComponentGenerator` fixes above/below and the `PhpUnitTestGenerator` fix below against real, introspected schema — not synthetic config — during this release's validation exercise.

### Fixed — `IntrospectionToConfig`'s FK-to-relation-name derivation could diverge from the Model's actual relation method name, producing config that reads a relation that doesn't exist

- `buildRelations()`/list/view field `data`-path derivation all singularized the FK's *target table name* into a relation name (`foreignTableToRelationName()`), while `ModelGenerator` independently derives the Model's actual `belongsTo()` method name from the *column* name. The two always agreed by coincidence for a conventionally-named FK (`item_type_id` → table `item_types` → both derive `itemType`) but diverge for `parent_id`: the table-name path singularizes the FK's target table (e.g. `item_categories`) into `itemCategory`, while the Model's real relation method — derived from the column — is `parent`. Config built with the wrong name produces an `eagerLoadRelationships` entry and list/view `data` paths pointing at a relation the Model never defines — a hard `Call to undefined relationship [itemCategory]` at runtime.
- `src/Schema/IntrospectionToConfig.php`: replaced `foreignTableToRelationName(string $foreignTable)` with `columnToRelationName(string $columnName)`, deriving from the column name exactly like `ModelGenerator` does, so the two can no longer disagree. The now-unused naive `singularize()` helper was removed along with it.

### Fixed — `module.json` persisted a scaffold-time-only `_introspection` bookkeeping key, ballooning every generated module's config file with the entire database's FK graph

- `ModuleConfigGenerator` wrote `$this->config` to `module.json` verbatim, including any leading-underscore transient key (e.g. `_introspection`) a caller injects purely so in-run relation resolution can see it. Nothing ever reads such a key back from a persisted `module.json` afterward. Left in place, every generated module's config file grows by the size of the whole database's (or, on a shared DB server, every co-hosted project's) FK graph regardless of that module's own column count, and leaks unrelated schema metadata into a file that's normally committed to source control.
- `src/Generators/Backend/Config/ModuleConfigGenerator.php`: strips any leading-underscore key before writing `module.json`.

### Fixed — generated PHPUnit fixtures hardcoded FK id `1` even for a self-referential, nullable hierarchy FK, guaranteeing a first-insert validation failure

- `PhpUnitTestGenerator::buildFieldValueLiteral()` treated every integer FK field the same as a plain integer column, always emitting the literal `1`. For a self-referential hierarchy FK (e.g. `item_categories.parent_id → item_categories.id`) this fails unconditionally on the very first insert into an empty table — there is categorically no row yet for id `1` to reference, so `exists:item_categories,id` always rejects it. Confirmed live: generated `test_can_create_item_category`/`test_can_edit_item_category` both 422'd with `validation.exists` before this fix.
- The generator now detects an `exists:table,column` rule and emits `null` whenever the FK is self-referential or the rule is `nullable` — both cases where `null` is guaranteed safe. A required (non-nullable) FK to a *different* module's table is a distinct, deeper gap this fix does not attempt (see "Not included" below) and still falls through to the literal `1`, unchanged.

### Fixed — `ModelGenerator`'s bare `'date'` cast silently shifted a stored calendar date back a day under a non-UTC app timezone

- Eloquent's plain `'date'` cast serializes to JSON via Carbon's `toJSON()`, which converts to UTC before formatting. Confirmed live: a generated `ItemPrices` module's `effective_date` (stored as `2026-07-24`) came back from the real API as `"2026-07-23T21:00:00.000000Z"` under this project's actual `Africa/Dar_es_Salaam` (UTC+3) app timezone — the wrong calendar day, silently, for every consumer including the generated test's own assertion and any real frontend rendering the value.
- `getCastType('date')` now returns `'date:Y-m-d'` — Eloquent's parameterized date cast, which formats with `->format($format)` directly and is not timezone-converted. `datetime`/`timestamp` columns are untouched; they carry a real time-of-day component, where UTC-converting serialization is correct.

### Fixed — every generated e2e fill step for a foreign-key field was broken; optional relation fields assumed a row that may not exist; date inputs got an unparseable value

- `PlaywrightTestGenerator` only ever checked `field_type === 'select'` when deciding how to fill a field, but `IntrospectionToConfig::buildFrontendFields()` — the only real producer of this config — always emits `'api-select'` for a foreign-key/relation column, never bare `'select'`. Confirmed live: a generated e2e test tried `fillField(page, '...#item_type_id', ...)` against an `ApiSelect2Field` control that has no element with that id (the component only applies `id` to its `<label for>`), so every generated test timed out on its first FK field.
- A required-relation field still needs `fillSelectField()`, which clicks whatever option is first available — but for an *optional* relation field, forcing a selection fails outright the moment the referenced table has zero rows, which is guaranteed on a self-referential hierarchy FK's very first record (e.g. `item_categories.parent_id`, nothing yet to pick as "parent"). Optional relation fields are now left unset in the generated fill step instead. A required relation to another module's table is a distinct, still-open gap (no mechanism to seed a row in that table) and is unchanged.
- A `'date'`-typed field previously fell through to the generic `` `E2E __MODULE__ __LABEL__ ${stamp}` `` template — not valid `yyyy-mm-dd` syntax, which a native `<input type="date">` silently rejects. Both the create-fill and edit-fill value expressions now have a dedicated `'date'` branch producing a real ISO date string (the edit branch offset by one day so it's distinguishable from the create value).
- `src/Generators/Frontend/Tests/PlaywrightTestGenerator.php`: new `SELECT_FIELD_TYPES = ['select', 'api-select']` constant used everywhere the generator previously checked `'select'` alone (`isScalarField()`, `hasAnySelectFieldType()`, fill/skip logic).

### Added — comma-tolerant `fillNumberField()` e2e helper for `NumberInputField.vue`'s Cleave.js-formatted values; fixed a type-mismatch in the edit-block readback comparison

- `NumberInputField.vue` formats its displayed value through Cleave.js with a thousands-separator (`delimiter: ','`), so `.inputValue()` legitimately returns `"1,875,449"` for an underlying value of `1875449`. The plain `fillField()` helper's exact-string readback check can never pass for a `number-input` field. Confirmed live: a generated `ItemImages` e2e test threw `Could not fill #sort_order: stuck at "1,875,449", expected "1875449"` on its first attempt.
- `PlaywrightTestGenerator` now emits a `fillNumberField()` helper (comma-stripped comparison, same fill-then-verify-then-`setInputValue`-fallback contract as `fillField()`) and routes every `field_type === 'number-input'` fill through it.
- Separately, the generated edit-block's readback comparison did a bare `editedActual !== editedValue`, where `editedActual` is always a string (`.inputValue()`) but `editedValue` is a raw JS expression that for a numeric field is a number literal — a type mismatch that fails for every numeric edit field regardless of actual value. Both sides are now normalized (`String(...)`, commas stripped) before comparing. Confirmed live against a generated `ItemPrices` "price" edit field.

### Not included in this release — confirmed still open, deliberately out of scope

- **Required cross-module FK fixture values.** A required (non-nullable) FK to a *different* module's table still gets the hardcoded literal `1` in generated PHPUnit fixtures — safely satisfying it means creating a fixture row in that other module's own table using its own required columns, information a single-module generator invocation doesn't have. Not touched this release.
- **File-upload multipart submission.** Even with the `--file-columns` hint (v2.10.15) correctly marking a column and rendering a real `FileInputField`, the generated Create/Edit form still submits via `sendPostRequest` (plain JSON), not `sendFormDataRequest` — a real `File` object can never serialize to the backend this way. `CreateFormGenerator`/`EditFormGenerator` still need to detect any `file-input` field and switch submission accordingly. Not touched this release.

## v2.10.15 — 2026-07-24

### Fixed — generated file-upload fields imported a Vue component that does not exist in the consuming project (`FileInputFieldWithCropper.vue`)

- `src/Generators/Templates/frontend/fields/file-input.stub` and `src/Generators/Frontend/Components/BaseComponentGenerator.php` (the `case 'file-input':` branch of its import-emitting logic) both referenced `FileInputFieldWithCropper.vue`. That component does not exist in SYSTEM_SHELL/FRONTEND — it only ever existed in an unrelated legacy project. The real component every file-input field needs is `FileInputField.vue`, confirmed present at `SYSTEM_SHELL/FRONTEND/src/components/form-fields/FileInputField.vue`. Every module scaffolded with a file-input field therefore generated a page whose import could never resolve.
- Stub: `<FileInputFieldWithCropper ...>` → `<FileInputField ...>`; the prop list itself is unchanged.
- `BaseComponentGenerator.php`: the import line now emits `import FileInputField from '@/components/form-fields/FileInputField.vue';`. A new comment documents that every prop the stub passes — `multiple`, `accept`, `max-size`, `max-files`, `preview`, and the crop-related `enable-crop`/`aspect-ratio`/`crop-shape`/`upload-mode` — maps onto real props on the real component, which already has its own built-in `ImageCropperModal`, so retargeting the import loses no cropper functionality.
- **Non-breaking, corrective only.** No previously-generated file-input field could ever have worked (its import target never existed), so there is no working prior behavior to regress. Already-generated modules with a file-input field need a `--force` regeneration to pick up the corrected import, same as any other stub fix; `generate()`'s no-overwrite guard leaves them untouched otherwise.
- **No dedicated regression test.** Neither the stub's rendered output nor `BaseComponentGenerator`'s file-input import line has PHPUnit coverage before or after this change — flagged as a follow-up, not blocking.
- **Not checked this pass.** `src/Generators/Templates/mobile_app/fields/file-input.stub` still references the same nonexistent `FileInputFieldWithCropper` name, and MOBILE_APP has its own real `FileInputField.vue` at the equivalent path. No generator source currently emits an import statement for the mobile stub's file-input field the way `BaseComponentGenerator.php` does for the frontend one, so the fix's shape there needs its own look — out of scope for this release.

### Added — `file_columns` `$meta` hint: force a column to render as a file-input field regardless of inferred type

- Why: `IntrospectionToConfig::buildFrontendFormFields()` infers `field_type` purely from a column's own SQL type and FK-ness; there was no way to mark a plain `string`/`varchar` column (e.g. `avatar`, `image_path`) as a file/media upload — introspection alone cannot tell "this string holds a path to an uploaded file" apart from any other string column.
- `IntrospectionToConfig::build()`, `buildFeatures()`, `buildFrontendFeatures()`, and `buildFrontendFormFields()` now thread an optional `array $meta` parameter all the way down to where fields are actually built, mirroring the existing `has_uuid`/`has_creator_updater` `$meta`-hint precedent (v2.10.10) rather than inventing a new mechanism. `buildFrontendFormFields(array $userColumns, array $meta = [])` reads `$meta['file_columns'] ?? []` and, for any column named in that list, forces `field_type = 'file-input'` / `type = 'file'` — checked first, ahead of the existing FK/boolean/date branches, so the hint wins even when the named column is itself a real foreign key.
- Consuming-app change, not part of this package: `SYSTEM_SHELL/BACKEND`'s `MakeModule.php` gained a `--file-columns=col1,col2` comma-separated CLI option, parsed and passed through `ModuleScaffolder::buildConfig()`'s new `array $fileColumns = []` parameter into the config's `file_columns` key.
- **Non-breaking.** `$meta` defaults to `[]` at every new parameter, and `file_columns` defaults to `[]` when absent from `$meta` — every caller before this release never passed it, so inference for them is byte-for-byte unchanged.
- Added regression coverage: 4 new tests in `tests/Unit/Schema/IntrospectionToConfigTest.php` — a hinted column gets `field_type: 'file-input'`/`type: 'file'`; an unlisted plain column and an unlisted real FK column both keep their normal inferred type (the hint doesn't leak onto other columns); the hint wins even when the named column is itself a real FK, since the new branch is checked before the `isFk` branch; and omitting `file_columns` from `$meta` entirely doesn't break normal inference, the common case for every existing caller — see [Testing](README.md#testing).

### Added — `items-suite`: a permanent, reusable multi-table integration-test schema fixture

- New `tests/Fixtures/integration-schemas/items-suite/`: 5 FK-dependency-ordered migrations (`item_types` → `item_categories` → `items` → `item_images` → `item_prices`) covering a plain lookup table, a self-referential FK (`item_categories.parent_id`), a main entity with two required FKs, and two independent child tables — one exercising the new `file_columns` hint (`item_images.image_path`), the other exercising `decimal`/`date` column types (`item_prices.price`/`effective_date`). A companion `columns.php` provides the same schema pre-shaped as `SchemaIntrospector::columns()` output, for fast unit tests against `IntrospectionToConfig` without a real database.
- Intended for validating future generator changes end-to-end (real DB + `make:module` + generated code) in a single pass, without needing a fresh one-off fixture per scenario. Full usage instructions — copying the migrations into a consuming project, the required scaffolding order, and the `--file-columns=image_path` flag `ItemImages` needs — live in the fixture's own `README.md`, not repeated here.
- `README.md` (main package): new "Reusable integration-test schema" subsection under Testing, cross-referencing the fixture.

## v2.10.14 — 2026-07-24

### Changed — `PhpUnitTestGenerator`/`PlaywrightTestGenerator` now emit self-contained, module-local tests instead of writing into shared central directories

- Why: v2.10.11 shipped both generators writing into central, module-agnostic locations — `tests/Feature/<SubGroup>/<Module>CrudTest.php` (namespace `Tests\Feature[\<SubGroup>]`) and a flat `FRONTEND/e2e/<module-route>.e2e.js` — the one part of a module's generated output that did NOT live inside that module's own directory tree, unlike every other generator in the pipeline (`ModelGenerator`, `RoutesGenerator`, `ListPageGenerator`, etc., all of which write under the module's own `modulePath`/`getFrontendModulePath()`). Deleting or relocating a module meant separately hunting down and removing its test files from these unrelated central directories; the flat `FRONTEND/e2e/` directory also does not scale — it becomes a single unsorted pile as module count grows, with no per-group/per-module structure.
- `src/Generators/Backend/Tests/PhpUnitTestGenerator.php`: `generate()` now writes to `{$this->modulePath}/Tests/{$this->moduleName}CrudTest.php` and the class namespace is `{$this->getNamespace()}\Tests` (e.g. `App\Project\Modules\Core\LocationTypes\Tests`) — both reuse the exact same `modulePath`/`getNamespace()` every other backend generator already writes into; no new path-resolution logic was invented. `PathManager::getBackendTestsPath()`/`getBackendTestsNamespace()` are no longer called by this generator (see dead-code caveat below). The now-unused `use Blutrixx\GeneratorEngine\Generators\PathManager;` import was removed.
- `src/Generators/Frontend/Tests/PlaywrightTestGenerator.php`: `generate()` now writes to `PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . '/e2e/' . Str::kebab($this->moduleName) . '.e2e.js'` — e.g. `FRONTEND/src/pages/modules/core/LocationTypes/e2e/location-types.e2e.js` — reusing the same `getFrontendModulePath()` every frontend page/component generator already calls. `PathManager::getFrontendE2ePath()` is no longer called by this generator (see dead-code caveat below).
- `src/Generators/Templates/frontend/tests/crud.e2e.stub`: the four shared-helper imports (`fixtures.js`, `auth.js`, `config.js`, `filters.js`) now resolve via the `#e2e-helpers/*` Node subpath-import specifier (e.g. `import { test, expect } from '#e2e-helpers/fixtures.js'`) instead of a relative `'./helpers/xxx.js'` path. A relative path was only ever correct because every hand-written spec lived flat, side by side with `helpers/`, directly under `FRONTEND/e2e/`; a spec now generated arbitrarily deep under `FRONTEND/src/pages/modules/<group>/[<subgroup>/]<Module>/e2e/` cannot reach `FRONTEND/e2e/helpers/` via any single fixed relative path. A subpath import specifier resolves identically regardless of the importing file's nesting depth, so the generated spec never needs to know how deep it lives.
- **Caveat — depends on a consuming project's own `package.json`.** The `#e2e-helpers/*` specifier only resolves if the consuming project's `FRONTEND/package.json` declares a matching `"imports"` entry (e.g. `"imports": { "#e2e-helpers/*": "./e2e/helpers/*" }`) — Node/Vite's subpath-imports feature is opt-in per `package.json`, this package cannot inject it. SYSTEM_SHELL/FRONTEND's `package.json` already carries this entry (added alongside the hand-migration of its existing 17 specs into their modules' own folders, done separately from this package). A consuming project without it will see a generated spec fail to resolve its helper imports at collection time, not at generation time.
- **Dead code left behind, not removed.** `PathManager::getBackendTestsPath()`, `PathManager::getBackendTestsNamespace()`, and `PathManager::getFrontendE2ePath()` are no longer called anywhere in `src/` or `tests/` as of this release, but were left in place rather than deleted — removing public `PathManager` methods is a breaking change for any consumer that may call them directly, and no such removal was in scope for this release. Flagged as a follow-up cleanup candidate, not blocking.
- **Breaking only for a consuming project's already-generated tests, never for already-generated application code.** This changes where *newly generated* test/e2e files land and what namespace/imports they use going forward; it has no effect whatsoever on any previously generated backend or frontend module code (models, controllers, pages, components, routes, etc. are entirely untouched by this change). SYSTEM_SHELL's 18 pre-existing hand-written PHPUnit tests and 17 pre-existing Playwright specs were separately, manually migrated into this exact module-local shape (same target paths and namespace convention this release now automates) — that migration is consuming-project work, not part of this package, and is already complete as of this release.
- Updated regression coverage: `tests/Unit/Generators/Backend/Tests/PhpUnitTestGeneratorTest.php` and `tests/Unit/Generators/Frontend/Tests/PlaywrightTestGeneratorTest.php` — both updated in place (not net-new) to assert the module-local path/namespace and the `#e2e-helpers/*` import specifiers respectively, replacing their previous central-path/relative-import assertions — see [Testing](README.md#testing).

### Fixed — generated `PhpUnitTestGenerator` fixture helper never read back a DB-generated `uuid` default, so every uuid-path test method 404'd against a real app

- Found live, during this release's own `make:module` smoke test (see Verified section below) — not caught by this package's own unit suite, since that suite never runs a generated fixture against a real MySQL connection with a real DB-level column default. `buildFixtureHelper()`'s generated `create<Singular>Fixture()` returns `<Module>Model::create(array_merge([...], $overrides))` with no explicit `uuid` value and no refresh. This codebase's own uuid convention (every generated/hand-written migration: `$table->uuid()->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique()`) computes that default in MySQL, not PHP — Eloquent's `create()` never reads a DB-computed default back onto the in-memory model it returns unless told to. `$fixture->uuid` was therefore null on every fixture the generated test built, and every test method that interpolates it into a request path (`test_can_view_*`, `test_can_edit_*`, `test_delete_check_reports_no_blocking_relationships`, `test_can_delete_*`) sent a malformed URL (a bare double slash where the uuid segment belongs) and got back a 404 — 4 of the 8 methods a fully-featured module generates.
- This is specifically a test-generator bug, not an application bug: every generated `<Module>CreateService::process()` already calls `$model->fresh()` before returning its response (confirmed by reading `ZzzGeneratorVerifyTestCreateService.php` from the live smoke test below), so real API responses have always returned a correct `uuid`. Only the generated test's own fixture-building helper was affected.
- `src/Generators/Backend/Tests/PhpUnitTestGenerator.php` (`buildFixtureHelper()`): the generated helper now ends with `->fresh()` — `return <Module>Model::create(array_merge([...], $overrides))->fresh();`. Mirrors what every generated `CreateService` already does, rather than inventing a test-only workaround (e.g. an explicit `'uuid' => Str::uuid()` override, the approach the hand-written `LocationTypesFactory` uses). Generalizes correctly to any other DB-generated default column, not just uuid, so no `has_uuid` conditional was needed.
- **Non-breaking.** Already-generated `*CrudTest.php` files are untouched by `generate()`'s no-overwrite guard; only newly scaffolded modules and `--force` regenerations of a module's PHPUnit test pick up the fix.
- Added regression coverage: `tests/Unit/Generators/Backend/Tests/PhpUnitTestGeneratorTest.php` asserts the generated `createLocationTypeFixture()` method body contains `->fresh()` — see [Testing](README.md#testing).

### Verified — live `make:module` smoke test against a real running SYSTEM_SHELL app, both generated tests actually executed (not just placed)

- Scaffolded a genuinely fresh throwaway module (`Core/ZzzGeneratorVerifyTest`, table `zzz_generator_verify_tests` with a unique `name` column, full uuid/timestamps/soft-deletes/creator-updater) in SYSTEM_SHELL via the real `php artisan make:module` command — not a standalone script — against a temporary `path` repository override in `BACKEND/composer.json` (symlinked `vendor/blutrixx/generator-engine` straight at this package's working tree).
- Confirmed the generated PHPUnit test landed at `BACKEND/app/Project/Modules/Core/ZzzGeneratorVerifyTest/Tests/ZzzGeneratorVerifyTestCrudTest.php` with namespace `App\Project\Modules\Core\ZzzGeneratorVerifyTest\Tests`, and the generated Playwright spec landed at `FRONTEND/src/pages/modules/core/ZzzGeneratorVerifyTest/e2e/zzz-generator-verify-test.e2e.js` importing via `#e2e-helpers/{fixtures,auth,config,filters}.js`.
- Ran both for real, not just placement checks: `php artisan test --filter=ZzzGeneratorVerifyTestCrudTest` — all 8 generated methods passed (23 assertions) once the fixture-helper fix above was applied (4 failed with 404s beforehand, confirming the bug above live before fixing it). `npx playwright test .../zzz-generator-verify-test.e2e.js --list` confirmed the spec collects cleanly (proving the `#e2e-helpers/*` subpath imports actually resolve); a full headed-off (`HEADLESS=true`) real run against the actual dev backend+frontend servers then passed end to end — login, create, id-filter, view, edit, delete, zero page errors, zero failed requests.
- Also confirmed SYSTEM_SHELL's `ModuleScaffolder.php` needed **no changes at all** for either generator's new output location — its `$run()` closure constructs every generator generically (`new $generatorClass($moduleName, $moduleGroup, $config)`), so both path changes above took effect purely from the version bump.
- Cleaned up afterward: generated directories (BACKEND/FRONTEND/MOBILE_APP), the throwaway migration and table, the seeded permission rows this module's live smoke test required (force-deleted — `PermissionsModel` is soft-deleting, a plain `delete()` alone is not enough to make it disappear from a raw/non-Eloquent count), the touched registry/`modules.json`/`menus.json` files, and the temporary `composer.json`/`composer.lock` path-repository override — `git status` confirmed byte-identical to pre-test in all three trees afterward.
- **Not checked this pass**: MOBILE_APP's own `MobileMigrationGenerator` does not have the same "skip if a create-migration for this table already exists" guard `MigrationGenerator` gained in v2.10.8 — regenerating (or, as here, generating twice while iterating) left two duplicate mobile migration files for the same table. Pre-existing, unrelated to this release's change, and out of scope for it; flagged as a follow-up.

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
