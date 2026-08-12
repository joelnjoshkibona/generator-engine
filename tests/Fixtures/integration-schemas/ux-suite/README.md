# ux-suite integration-test schema fixture

A permanent, reusable fixture for validating `generator-engine`'s **UX
Builder** pathway (composites, wizards, dashboard quick-actions, shortcuts)
end-to-end against a real consuming Laravel project (SYSTEM_SHELL). Sibling
to `items-suite`/`orders-suite`/`morphs-suite`/`delegations-suite`/
`actions-suite` — but a structurally different pathway: those five are all
`module.json`-driven (one schema, `make:module`/`make:modules-from-db`);
this one is driven by a **separate blueprint JSON**, consumed by
`php artisan make:ux-from-blueprint`, that runs as a **second pass** over
modules the module.json pathway already scaffolded.

## What this fixture covers

A simple quoting scenario: a **Quote** (parent) with **Quote Items**
(child, plain independent FK — same "no special schema marker" shape as
delegations-suite's `stock_movements`). Deliberately has no `inline_items`/
`delegations`/`morphs`/`actions` of its own, so it tests the UX Builder
pathway in isolation from the other five suites' mechanisms.

| Table | Scenario exercised |
|---|---|
| `quotes` | Parent — the UX blueprint's composite host, wizard `triggers_action` target, and dashboard quick-action target |
| `quote_items` | Child — the composite's `repeater` section and the wizard's `repeater` step |

`ux-blueprint.json` in this directory exercises all four UX generators
against the same two modules at once:

- **`CompositeGenerator`** (`composites.Quotes`) — a 2-section composite
  create page (`details` form + `items` repeater), overwriting Quotes' plain
  `QuotesCreatePage.vue`.
- **`WizardGenerator`** (`wizards.QuoteWizard`) — a 2-step wizard
  (`form` + `repeater`), with `triggers_action` pointing at Quotes.
- **`ShortcutGenerator`** (`shortcuts.Quotes`) — an "Add Item" shortcut on
  Quotes' detail view, targeting QuoteItems, with a `$id`-prefixed prefill.
- **`DashboardGenerator`** (`dashboard.quick_actions`) — a "New Quote"
  dashboard button that opens the composite via the wizard.

## A real, confirmed documentation bug this fixture exists to catch

Before this fixture existed, `docs/ux-blueprint.md`, `schema/ux-blueprint.schema.json`,
and `examples/ux-blueprint.json` **all** described a blueprint shape that
does not match the actual generator code, in three ways — confirmed by
reading `BaseUxGenerator`/`CompositeGenerator`/`WizardGenerator`/
`DashboardGenerator`/`ShortcutGenerator`/`MakeUxFromBlueprintCommand`
directly, not by trusting the docs:

1. **Top-level key.** Docs/schema/example all used `module_groups`
   (`{ModuleName: GroupString}`). The real code
   (`BaseUxGenerator::getGroupForModule()`) reads `groups`
   (`{GroupName: [snake_case_table_name, ...]}`) — keyed the opposite way,
   and valued as table names, not module names. A blueprint written per the
   old docs silently produces **0 files** for every composite/shortcut (no
   error, no warning — `if (!$group) continue;`).
2. **CLI invocation.** Docs showed `--blueprint=path --force`. The real
   signature is a single **positional** argument, `{blueprint}`, with
   **no** `--force` option at all.
3. **`prefill` syntax.** Docs/example used `"{{record.id}}"`. The real
   runtime (`shortcuts.stub`) only resolves `$`-prefixed values (`"$id"`,
   `"$uuid"`, `"$fieldName"`) — a literal `{{record.id}}` passes through
   unresolved.

All three were fixed in `docs/ux-blueprint.md`, `schema/ux-blueprint.schema.json`,
and `examples/ux-blueprint.json` alongside this fixture. `ux-blueprint.json`
in this directory uses the corrected shape throughout and is confirmed to
actually generate — see "How to use it" below.

## How to use it: full end-to-end validation

1. **Copy the migrations**, run them, generate both modules normally first
   — **child before parent**, then regenerate the child once the parent
   exists (same reasoning as orders-suite's `OrderItems`/`Orders`: a
   real-FK child scaffolded before its parent exists guesses the wrong
   namespace for the `belongsTo()` relation until a forced regenerate):
   ```bash
   cp tests/Fixtures/integration-schemas/ux-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   php artisan migrate
   php artisan make:module Custom/QuoteItems
   php artisan make:module Custom/Quotes
   php artisan make:module Custom/QuoteItems --force
   ```
2. **Run the UX blueprint** — positional argument, no flags:
   ```bash
   php artisan make:ux-from-blueprint tests/Fixtures/integration-schemas/ux-suite/ux-blueprint.json
   ```
   (Strip the `_comment` key first — it's documentation, not blueprint data.)
3. **Confirm** (all reproduced live 2026-08-12, first attempt, zero errors,
   14 files created):
   - `Services/QuotesCompositeCreateService.php` exists, valid PHP, section
     payload references `data['details']`/`data['items']`.
   - `QuotesCreatePage.vue` was overwritten (no longer the plain single-form
     page) and imports both `QuotesCreateForm.vue` and
     `QuoteItemsCreateForm.vue` from their real, already-scaffolded paths.
   - `Services/QuoteWizardWizardService.php` exists with `step(int, array,
     array)` dispatching to `step1()`/`step2()`, and a `commit()` wrapping
     `processCommit()` in `DB::transaction()`. Confirmed live via
     `php artisan tinker`: `(new QuoteWizardWizardService())->step(1, [...])`
     and `->step(2, [...])` both return `{"status":true,...}`; `->step(99,
     [])` returns a clean `{"status":false,"code":400,...}` for an
     out-of-range step; `->commit([...])` returns success.
   - `FRONTEND/src/pages/wizards/QuoteWizardWizardPage.vue` exists, and
     `FRONTEND/src/pages/wizards/routes.ts` registers
     `path: '/wizards/quote-wizard'`, `name: 'QuoteWizard'`.
   - `QuotesShortcuts.vue` exists; `QuotesDetailsLayout.vue` has both the
     `import QuotesShortcuts from './QuotesShortcuts.vue'` line and the
     `<QuotesShortcuts :record="record" .../>` tag patched into its
     `shortcut-import`/`shortcuts` regions.
   - `FRONTEND/src/pages/dashboard/DashboardQuickActions.vue` exists;
     `.../Analytics/Dashboard/DashboardPage.vue` has both the import and
     `<DashboardQuickActions />` patched into its `quick-actions-import`/
     `quick-actions` regions.
   - Every mobile counterpart (`MOBILE_APP/resources/js/src/pages/...`)
     written alongside its web equivalent.
4. **Clean up** when done: drop the two tables, delete the generated module
   directories, remove the UX-generated files listed above (composite pages
   revert to whatever `make:module` alone would produce; wizard/shortcut/
   dashboard files and the patched regions have no automatic revert — restore
   from git or re-run `make:module --force` for the plain create page, and
   manually strip the patched regions' content back to empty if the consuming
   project's own `DashboardPage.vue`/`{Module}DetailsLayout.vue` need to go
   back to a clean state).

## Real, confirmed non-idempotency to be aware of when re-running

Not a bug — documented behavior, confirmed against `BaseUxGenerator`'s single
`writeFile()` primitive (see `docs/ux-blueprint.md`'s CLI section):

- Composite create pages and **both** `wizards/routes.ts` files (web +
  mobile) are unconditionally **overwritten** on every run.
- `wizards/routes.ts` is rebuilt from only the wizards present in the
  **current run's** blueprint — re-running with a blueprint that omits a
  previously-generated wizard silently drops that wizard's route (the page
  `.vue` file itself is untouched, just unreachable via the router).
- Every other generated file (services, wizard/dashboard/shortcut
  components) is written once and silently skipped on every subsequent run,
  `--force` or not — there is no `--force` flag for this command at all.
  Delete the file first to force regeneration.

## Ordering rule this suite confirms live, not just from reading source

`groups` only needs entries for modules used as a `composites`/`shortcuts`
**host key** — `BaseUxGenerator::getGroupForModule()` is the only consumer.
`wizards.*.steps[].module`, `triggers_action.module`, and every
`shortcuts`/`dashboard` `target`/`composite` reference carry their own
inline `"Group/ModuleName"` (or bare StudlyCase name for `composite`) and
never consult `groups` at all — confirmed by `ux-blueprint.json` in this
directory, whose `groups` key lists only `quotes`/`quote_items` even though
`Quotes`/`QuoteItems` also appear throughout `wizards`/`shortcuts`/
`dashboard` by their own inline paths.

## How to use it: fast unit testing without a live consuming project

`CompositeGenerator`/`DashboardGenerator` are exercised directly (temp
`PathManager` root, no live DB, no Artisan command) by
`tests/Unit/Generators/Ux/CompositeGeneratorTest.php` and
`tests/Unit/Generators/Ux/DashboardGeneratorTest.php` — both had **zero**
test coverage of any kind before those files were added alongside this
fixture. `WizardGenerator`/`ShortcutGenerator` already had narrower coverage
(`WizardGeneratorTest.php`, `ShortcutGeneratorTest.php`); this fixture's live
run is what actually proves their full `generate()` output, not just the
internal helper methods those two files exercise.
