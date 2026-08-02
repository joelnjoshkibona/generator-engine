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

## A generated file interacting with another generator's output

The `morphs` redundant-index bug and the [delegation-vs-standalone form
overwrite](delegations#known-limitation-don-t-mix-delegation-and-standalone-create-edit-access-without-a-plan)
issue are both examples of the same underlying lesson: two independent code
paths writing to the same output can silently interact in ways neither
path's own tests catch, because each was tested in isolation. Test the
*interaction*, not just each generator alone — which is exactly why every
recipe in this Examples section is backed by a fixture that was actually
generated and exercised end-to-end, not just unit-tested.
