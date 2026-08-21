# Common Gotchas

Things that have actually gone wrong while generating and verifying real
modules from this engine — not hypothetical warnings.

## Migration collision in the testing DB

After a module is scaffolded, it gets its own copy of the migration under
its own module `Migrations/` folder (a convention of consuming projects
like SYSTEM_SHELL — every registered module's migrations folder is
auto-loaded). If you also copied a fixture's migration into the top-level
`database/migrations/` for introspection, both copies now create the same
table — a fresh test-DB migration run hits "table already exists". Fix:
delete the top-level copy once scaffolding succeeds; the module-scoped copy
is now canonical.

## New permissions need seeding, not just scaffolding

`make:module` / `make:modules-from-db` only *write*
`{Module}SeederData.json`; nothing runs it automatically. The Create button
— and every permission-gated action — stays invisible until
`php artisan db:seed` actually runs. The seeder is idempotent (checks
existence before insert, safe to re-run).

## Verifying an unreleased package change without cutting a release

Add a temporary `path` repository to the consuming project's
`composer.json`:

```json
"repositories": [
  {"type": "path", "url": "../../packages/generator-engine", "options": {"symlink": true}}
]
```

and loosen the require constraint to `"*"`, then
`composer update blutrixx/generator-engine` — this symlinks
`vendor/blutrixx/generator-engine` straight to the local working tree.
Fully reversible: restore the original `composer.json`/`composer.lock`
bytes and run `composer install` to remove the symlink and re-fetch the
real dist archive.

**Don't manually delete the `vendor/` package directory mid-iteration**
while doing this — it can leave `composer.lock` in a confused state where a
scoped `composer update <pkg>` silently does nothing. If that happens,
restore from backup and retry cleanly (edit `composer.json`, run the scoped
update once) rather than deleting more things by hand.

**Packagist propagation lag.** If you're testing against a just-released
package version, pushing a new tag doesn't make it available to
`composer update` instantly — allow ~20-30s before assuming something is
broken.

## "Write-once" generated files — and generated tests are NOT one of them

A small number of generated files are explicitly meant to be hand-edited
exactly once and never touched again: the `inline_items` wrapper component
(`{Module}{Key}InlineItems.vue`), an action's `Service.php`/`Form.vue`/`Page.vue`
(web and mobile, since v3.1.7), the mobile Create/Edit form and
ViewOverview components, and a model with `model_hand_maintained: true`.
These use `writeFileOnce()` — an unconditional skip-if-exists primitive,
**not** the normal write path (whose skip-if-exists is gated on `--force`
and will happily overwrite an existing file the moment `--force` is passed
— correct for a schema-driven file, wrong for a hand-edited one). Confirmed
live: before this was fixed, a `--force` regenerate silently clobbered a
hand-added `dynamicDisabled` hook in an `inline_items` wrapper component
back to its template, with no warning.

**Generated PHPUnit/Playwright test files are the opposite — they are
force-overwritable, not write-once.** `PlaywrightTestGenerator.php` and
`PhpUnitTestGenerator.php` both use the normal `writeFile()` path, gated on
`--force` like any schema-driven file. This is deliberate: it's the only
delivery mechanism for a generator-level test-generation fix landing in an
already-scaffolded module (e.g. v3.4.1-v3.4.5's e2e-spec fixes reached real
projects purely through `composer update && make:module --force`). Hand-edit
a generated spec knowing a later `--force` will discard the edit; if you
need a permanent hand-written test, put it in a separate file the generator
doesn't own.

## Any hand-authored config key can suffer the "dropped by `--force`" bug — check nested paths too

Two confirmed instances: `inline_items` (a top-level key) and
`bulk_actions` (nested at `features.backend.list.bulk_actions`) were both
silently dropped by the consuming project's config-merge logic on the very
next `--force` regenerate, before being found and fixed. If you're adding a
new hand-authored config key anywhere in your own project's scaffolding
logic, verify it survives a live `--force` round-trip, not just a fresh
first-time generation — a config key that's only tested via first-time
generation will never exercise the merge path where this class of bug
lives.

## Redundant duplicate index on `morphs`-bearing tables (fixed)

A morph pair's two columns collapse into a single `$table->morphs($name)`
call on regenerate, which itself creates a composite index — but the
generator's "don't re-emit an index the schema builder already created"
reconciliation didn't originally cover morph pairs, so a real introspected
composite index for the same two columns got emitted a second time under a
different name. Harmless in MySQL (duplicate indexes are legal) but real
generated-output noise. Fixed via `morphs-suite`'s live verification.

## No hard DB-level FK constraints, by convention

Every fixture in this package (and every real module in the consuming
project) uses a plain indexed `foreignId()`/`unsignedBigInteger()` column,
never `->constrained()`. Relationship detection does not require a real
constraint — it works off the naming-convention heuristic described in
[Basic Modules](basic-modules) whenever a real constraint isn't present,
falling back to real constraint introspection first when one is.

## Five bugs found re-verifying every Examples fixture end-to-end (v2.44.0)

Systematically regenerating all five fixtures behind this Examples section
against a real consuming project — not just reading generator source —
turned up five more real bugs, all fixed in v2.44.0:

- **`morphs`: create was impossible through the generated API.** Covered in
  full on the [Polymorphic](morphs#what-you-get-from-the-generated-api-as-of-v2-44-0)
  page — `payable_type`/`payable_id` were excluded from validation and the
  create/edit form entirely, not just from list/filter/view.
- **`morphs`: the generated `ViewServiceTest` failed on a whole-number
  decimal fixture value.** `assertJsonPath()`'s strict `===` compared
  `float(1.0)` (the model's cast) against `int(1)` (the same value
  round-tripped through the HTTP response's JSON). Fixed by switching to
  `assertEqualsWithDelta()`, the same tolerant comparison the decimal-precision
  test already used.
- **`inline_items`: the child module's FK relation can misresolve its
  namespace.** Covered in full on the
  [Parent-Child Modules](inline-items#known-limitation-the-childs-own-fk-relation-can-misresolve-its-namespace)
  page — not fully fixed, still requires a follow-up `--force` regenerate
  after the parent exists.
- **`delegations`: the generated create test asserted the wrong status
  code.** `test_can_create_{delegation}_delegation_item()` asserted `200`;
  the real endpoint correctly returns `201`, matching every other Create
  test across the generator. One-line fix.
- **`actions`: PHPUnit coverage silently required `urlParams` to be `[]`
  or exactly `['uuid']`.** Covered in full on the
  [Custom Actions](actions#single-custom-action-actions-config-key-hand-authored)
  page — before v2.44.0 this restriction was even tighter (`['uuid']` alone
  was also uncovered), so almost no real single-row action had any backend
  test coverage at all.

## `constants` alone does not enable a Create/Edit splash pre-fetch (fixed v3.4.7)

Adding a top-level `constants` key (e.g. for a `status_target` bulk action)
does **not**, by itself, make the generated Create/Edit form fetch anything
on mount. The splash pre-fetch route (`GET /{module}/create/splash`) is only
registered — and, since v3.4.7, only called — when **both** `constants` is
non-empty **and** the matching `features.backend.createSplash`/`editSplash`
key is present. Before v3.4.7, `CreateFormGenerator`/`EditFormGenerator`
fired the network call whenever `constants` alone was set, hitting a route
the backend never registered — a 404 on every single form mount, silently
swallowed by a generic "Failed to load form data" toast that never threw.
Confirmed live: this was present even in an otherwise fully green real-project
e2e run, since no automated test asserts on the absence of a network error
toast. If a module only needs `constants` for a status workflow, leave
`createSplash`/`editSplash` out entirely.

## `actions[].fields` `select` + `splash_key` crashed the generated form (fixed v3.4.6)

A `field_type: "select"` field with `splash_key` inside `actions[].fields`
used to generate a Vue component referencing an undefined `splash` object —
a hard `TypeError: Cannot read properties of undefined` on render, three
compounding root causes: `mapNewFormFieldsToLegacy()` only read the
camelCase `splashKey`, silently `null` for the conventional snake_case
`splash_key`; there was no fallback to `api-select` when no `createSplash`/
`editSplash.splashData` entry matched the key; and a `field_type: "number"`/
`"number-input"` alias gap left `[[fieldDecimals]]` unresolved on the
neighboring field. `inline_items` fields never had this problem in the
first place — they resolve through a completely separate, **unconditional**
runtime mechanism (`InlineItemsFieldRenderer.vue`: `field.type === 'select'`
always resolves to `ApiSelect2Field`, no `createSplash`/`editSplash` lookup
at all) — which is what made this fixable as a small, localized fallback
rather than new machinery. Both `splashKey` and `splash_key` are now
accepted on `actions[].fields`, and an unmatched `splash_key` falls back to
`api-select` deriving `/select/{Str::kebab($splashKey)}`.

## Generated e2e create steps must expect a View dialog, not zero dialogs (fixed v3.4.1-v3.4.3)

The frontend's `onCreated()` behavior opens a View dialog for the
just-created record by default (a real, pre-existing frontend feature, not
a generator bug) — a generated e2e spec that asserts "no dialog present
after create" fails against every module. v3.4.1 first fixed this by
closing the auto-opened dialog via `Escape` before the assertion — but
`AppDialog.vue`'s `persistent` prop defaults `true` and the View dialog
usage never overrides it, so `Escape` is captured and `preventDefault()`'d
and never actually closes anything; every create step instead hung for the
full 15s Playwright timeout, masked behind an unrelated `afterEach`
cleanup-hook failure in the test report. Fixed for real in v3.4.2 by
clicking the dialog's actual Close button
(`getByRole('button', { name: 'Close' })`) instead of pressing Escape. The
identical bug existed independently in `createFixtureRecord()` (a separate
generated function, shared by every action/delegation smoke-test spec to
seed its own fixture record) — fixed the same way in v3.4.3.

## `AppDialog`'s `persistent` prop makes `Escape` a no-op — close via the real Close button

Reusable beyond generated specs: any `AppDialog` usage that doesn't
explicitly set `persistent: false` swallows `Escape` (and a backdrop click)
via `preventDefault()`. A hand-written Playwright script — or hand-edited
generated spec — that tries to "leave nothing open" via
`page.keyboard.press('Escape')` will silently hang rather than fail loudly
if the target dialog is `persistent` (the default). Prefer clicking the
dialog's own Close/Cancel button. This exact mistake recurred four times in
one generator release cycle (v3.4.1/v3.4.2's create-step fix, then
independently in `createFixtureRecord()` and three more "leave nothing
open" retry-loops inside `tryFillSelectField()`'s zero-options fallback,
fixed in v3.4.5) before being fully swept.

## A generated file interacting with another generator's output

The `morphs` redundant-index bug and the (now-[fixed](delegations#fixed-2026-08-05-delegation-and-standalone-access-now-share-one-form-safely))
delegation-vs-standalone form overwrite are both examples of the same
underlying lesson: two independent code paths writing to the same output
can silently interact in ways neither path's own tests catch, because each
was tested in isolation. Test the *interaction*, not just each generator
alone — which is exactly why every recipe in this Examples section is
backed by a fixture that was actually generated and exercised end-to-end,
not just unit-tested. The delegation fix itself came from the same
discipline one level up: v2.28.0's redesign was verified by regenerating a
real scratch delegation and running it through actual HTTP requests and a
real Playwright browser session, which is what caught a *second*,
unrelated bug in the same pass — a hand-maintained shared frontend
component (`CrudListPanel.vue`) had a Vue slot-name collision that left
every generated list's row-actions column silently empty. Neither bug was
visible from generated *source code* alone.
