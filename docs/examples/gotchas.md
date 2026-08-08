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

## "Write-once" generated files

A small number of generated files are explicitly meant to be hand-edited
exactly once and never touched again — currently: the `inline_items`
wrapper component (`{Module}{Key}InlineItems.vue`) and every generated
PHPUnit/Playwright test file. These use an unconditional skip-if-exists
primitive internally, **not** the normal write path (whose skip-if-exists
is gated on `--force` and will happily overwrite an existing file the
moment `--force` is passed — correct for a schema-driven file, wrong for a
hand-edited one). Confirmed live: before this was fixed, a `--force`
regenerate silently clobbered a hand-added `dynamicDisabled` hook in an
`inline_items` wrapper component back to its template, with no warning.

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
