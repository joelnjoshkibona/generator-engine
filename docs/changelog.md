# Changelog

## v3.5.3 — 2026-08-25

### Fixed — `gen-frontend` was never exposed on `vendor/bin`

v3.5.0 shipped the CLI without a `bin` declaration in `composer.json`, so Composer never symlinked
it and `vendor/bin/gen-frontend` did not exist in a consuming project. The only way to reach it was
the full `vendor/blutrixx/generator-engine/bin/gen-frontend` path, which nothing documented.
Declared now, so it installs like any other package binary.

### Added — a Prototyping documentation page

[Prototyping](./prototyping) covers the whole `gen-frontend` workflow: the three accepted `--spec`
shapes, what the chassis is and what it excludes, prototype mode and the browser-resident SQLite
layer, the full `api-contract.json` shape, every CLI flag, the generator labels `--only=` matches
against, and how to promote a prototype back into a real schema.

Two things it documents that are easy to get wrong and expensive to discover:
`features.backend.*.endpoint` is **not** the route the backend registers for core CRUD (a real
`module.json` says `PUT /statuses` where the router says `PUT /statuses/{uuid}/edit`), and a
registry entry handed to `FrontendPipeline` should carry the module's full config — without it, a
delegation tab paginates over real rows while rendering no columns at all.

The install snippet on the home page moves to `^3.5`, since `^3.4` cannot resolve any of this.

## v3.5.2 — 2026-08-25

### Fixed — the artifacts change removed two imports the generated spec still needed

v3.4.27's shared-artifacts change (`#e2e-helpers/artifacts.js`) removed `crud.e2e.stub` /
`split.e2e.stub`'s own `import fs from 'node:fs'` and `import path from 'node:path'`, on the
assumption they existed only to compute the module-local screenshot directory that change was
replacing. They did not: `buildImportBlock()` — a separate piece of the same generated file,
spliced in from PHP and not visible in the stub's own text — downloads a module's real CSV import
template and re-saves it under its suggested filename via `path.join(path.dirname(...), ...)` and
`fs.existsSync(...)`, unrelated to screenshots entirely.

Every module with `features.backend.list.import` enabled therefore got a generated CRUD spec that
failed its first import step with `ReferenceError: path is not defined`. Both imports are restored
unconditionally — Node builtins, no install cost — rather than gated on whether import is enabled.

## v3.5.1 — 2026-08-25

### Fixed — a generated action smoke test never filled the action's own required fields

`buildActionSpecBody()` never referenced `actions.{name}.fields` in either UI shape (modal or
page); `writeActionSpecFile()` hardcoded `[]` for the fields its own helper functions are built
from. `handleSubmit()` only closes the dialog on a 2xx response — the exact success signal this
test relies on — so any action with a required field got a spec that submits blank forever, 422s,
and times out waiting for a dialog that was never going to close.

The bug was invisible for as long as the actions under test were still stub placeholders returning
unconditional success with no validation — the stub's own leniency masked a generator gap that real
validation immediately exposed. Confirmed live on a rental-CRM project across three independent
actions the moment their stubs were filled in.

Every field declared on the action now gets exactly the fill logic create/edit forms use — so it
inherits the `sample_value`/`in:`-rule precedence (v3.4.25), the optional-vs-required select
handling, and the number/date special cases for free — filled right after the form appears and
before the submit button is even located. The action's real `fields[]` also reach helper-function
emission now, so `fillSelectField()`/`fillNumberField()`/etc. are actually defined when a field
needs one.

## v3.5.0 — 2026-08-25

### Added — a standalone frontend generator, and a browser-resident backend

Seeing a generated UI has always required the whole stack: a live MySQL schema to introspect,
`make:module` scaffolding both halves, migrate, seed, boot Laravel, boot Vite. That is the right
workflow for building the real thing and the wrong one for answering "what would this feel like?".

Three pieces now answer that without a backend existing at all.

**`FrontendPipeline`** — the frontend generator sequence as its own unit, with no Laravel
dependency: no container, no `config()`, no `base_path()`, no database. It takes a config array plus
whatever context is already on `PathManager`. Generator labels and their order are part of its
contract, since `--only=` matches on them.

**`bin/gen-frontend`** — a CLI that bootstraps from this package's own autoloader and nothing else.

```bash
gen-frontend --spec=<file|dir> --out=<dir> [--chassis=DIR] [--no-chassis] [--only=LABEL] [--force]
```

`--spec` takes an app spec (`{"modules": [...]}`), a single module config, or a directory scanned
for any `.json` declaring a `module_name`. It is the **same** config `make:module --schema=`
consumes — no second config language, so a prototype and the real build can never describe different
apps. A 16-module app takes about 0.1s.

**`ApiContractGenerator`** → `FRONTEND/src/api-contract.json`: every route, column, validation rule
and permission, plus per-module MySQL **and** SQLite DDL. Its routes are parsed out of
`RoutesGenerator`'s own output rather than read from config, because
`features.backend.*.endpoint` disagrees with reality for core CRUD — a real module.json declares
`PUT /statuses` where the backend registers `PUT /statuses/{uuid}/edit`, and the same divergence
exists on list, view and delete. `ApiContractRouteParityTest` asserts the contract and the emitted
`Routes/api.php` agree across five module shapes, re-parsing the written file with its own regex so
a bug in the generator's parser cannot agree with itself.

`RoutesGenerator::buildContent()` is split out of `generate()` to make that possible: it renders the
routes file as a string without writing it.

### Fixed

- **`BulkActionConfigNormalizer` rejected the bare-string shorthand.** Real committed config ships
  `"bulk_actions": ["activate", "deactivate"]`, and every consumer funnels through `normalizeAll()`,
  so that module raised a `TypeError` — an `\Error`, not an `\Exception`, escaping the
  per-generator catch and aborting the whole run. Both shapes are accepted now, mirroring
  `DelegationConfigNormalizer`'s long-standing string-or-array `relatedModule`.
- **Two `Log::` facade calls in frontend generators** fataled with "A facade root has not been set"
  outside Laravel, turning an unresolvable delegation target from a skipped import into a dead run.
  Both go through `PathManager::reportIssue()`, which the scaffolding commands already wire to
  console output — strictly more visible than a line in `laravel.log` nobody reads mid-generation.
- **`DdlRenderer` could not emit SQLite.** `mapType()` took a `$driver` argument and never read it;
  every caller got MySQL regardless. There is now a real sqlite branch, plus `fullTable()`, which
  renders a **complete** physical table — `fromColumns()` emits only the business columns, since the
  project's authoring convention keeps `id`/`uuid`/timestamps out of module config entirely, so its
  output cannot actually be executed.
- **Delegation tabs rendered headerless and cell-less** while paginating correctly over real rows.
  `CustomFeatureTabComponentGenerator::loadRelatedModuleConfig()` resolves the related module's
  `module.json` from a **BACKEND** path, which a frontend-only generation has none of; it returned
  `[]` and emitted `const columns = []`. It now prefers a config the registry already carries
  (`$entry['config'] ?? $entry`, the same defensive dual-shape read `buildInlineInjectArray()` uses).

### Changed

- `FrontendPipeline` catches `\Throwable`, not `\Exception`. Scaffolding a whole app runs the
  pipeline over dozens of modules in one process, and one malformed config should not take the
  others down with it. Errors are still collected and still fail the command.
- Delegation components are gated on `--only=`, which they were not before. The consequence was not
  theoretical: a `--only=ApiContract` run rewrote a tracked, hand-tuned delegation tab as a side
  effect. `ActionComponent` was already gated; the two are now consistent.
- The vestigial composite-module branch is **not** carried into `FrontendPipeline`.
  `setCompositeModules()` had no callers, so its list was always empty, and its form rewrite searched
  for `inline: {default: false}` — a string in neither `create/form.stub` nor any generated form.
  Dead on both counts, left behind rather than copied into a new public seam.

## v3.4.27 — 2026-08-25

### Added — `actions.{name}.splash`

`create` and `edit` have had a splash endpoint for a long time — a GET the form calls on mount for
its option lists and defaults. An action's modal needs the same thing and had no way to ask for it,
so consuming projects hand-added a route to `Routes/api.php`, a fully regenerated file: the route
survived until the next `--force` and then silently vanished. Config under `actions.{name}.splash`
survives, because `actions` is a preserved key.

```json
"actions": {
  "settleDeposit": {
    "splash": { "splashData": [{ "key": "methods", "type": "model", "module": "PaymentMethods" }] }
  }
}
```

emits `GET /{module}/{uuid}/{action}/splash` → `{Module}{Action}SplashService`, plus the controller
method and its import.

Two differences from the create/edit splash, both following from an action acting on a row that
already exists: the endpoint takes `{uuid}`, so the service can derive choices from that record's own
state; and it is **not** gated on module `constants` — create's splash exists to serve
constant-backed dropdowns, while an action's is usually about the record, so that gate would refuse
the common case. The service is `writeFileOnce`, like the action's own Service: its body is
hand-written and a regenerate must not discard it.

### Fixed — three traps whose whole cost was the time they wasted

- **A `totals` written as a keyed map** produced `Cannot access offset of type string on string` from
  inside `BaseComponentGenerator`, naming neither the module nor the key. Now a shape check that
  names both and calls out the keyed-map form as the usual mistake.
- **`PhpUnitTestGenerator`'s class docblock** spoke of never clobbering hand-written test logic,
  which reads as a general promise. It is not one: every path it emits goes through `writeFile()`.
  `docs/examples/gotchas.md` already said so correctly — a correct canonical doc plus a contradicting
  docblock is worse than neither, because the reader nearest the code sees the wrong one. Both now
  agree and name the four filename shapes the generator owns.
- **Generated specs computed a module-local screenshot directory**, scattering PNGs through the
  source tree where CI does not collect them. They now call `#e2e-helpers/artifacts.js`, so the
  layout is a one-file change in the shell rather than a regenerate of every module.

### Docs

- `morphs.md`: never write `{Module}Model::MODULE` into a `*_type` column. `Relation::morphMap()`
  registers the snake_case alias, so `MODULE` stores a PascalCase value that resolves to no model —
  and nothing throws. The row simply never resolves and a morph-filtered delegation lists nothing.
  Use `getMorphClass()`, and assert the alias in a test.
- `gotchas.md`: `registry.json` is keyed by bare module **name**, so two modules can never share one
  across groups. Scaffolding a same-named module elsewhere overwrites the entry, and the *original*
  module's next regenerate bakes the winner's namespace into its own generated services — a 500 in a
  module the mistyped command never named, which deleting the stray directories does not repair.
- `gotchas.md`: `e2e/helpers/*.js` ships in SYSTEM_SHELL, not here. A changelog entry announcing a
  fix "to the picker helpers" cannot reach a consuming project through `composer update` at all —
  v3.4.18 did exactly that. When a change concerns those files, name SYSTEM_SHELL as the vehicle.

## v3.4.26 — 2026-08-25

### Added — `super-suite`, an integration fixture meant to be run rather than followed

The five existing fixtures each cover one mechanism and are manual, README-driven workflows. This
one covers the whole surface in a single scaffold and is driven by one command (SYSTEM_SHELL's
`run-fixture.sh`).

The reason it exists is the v3.4.25 batch: eight real defects shipped while this package's own suite
was fully green. That is a gap in KIND, not quantity — unit tests assert on generated **strings**,
and none of those eight is visible in a string. They appear when the generated code is **executed**
(a null model, an undefined relationship, a payload the validator rejects) or **bundled** (an import
of a file that was never written, which is unreachable from this repo entirely, because bundling
happens in the consuming project).

Every table exists because a specific defect escaped through it, and the README names the release
whose fix must fail if that coverage regresses — reduced operation sets, an inline_items block
carrying a local-options select *and* a splash select *and* a real FK side by side, a `*_by_id`
business column sitting beside the real audit columns, a status machine where `in:` domains and
schema defaults and a paired `after_or_equal:` date range all meet, a required UNIQUE FK, a field
constrained only by a `processing_service` normalizer, a delegation, a morph with two targets and a
morph-filtered delegation, and a table of column-type edge cases.

First green run against a real 13-module consuming project: 13 modules generated, 265 PHPUnit tests
/ 670 assertions, a production build at 5475 modules transformed, and a clean `git status`
afterwards. Three defects surfaced only by running it, one of them in the runner itself, which had
been reporting "11 migrations copied" after all 11 copies failed.

No `columns.php` here, unlike the other suites: that file lets `IntrospectionToConfig` be exercised
without a database, and this fixture's whole purpose is to run against a real one.

### Note for consuming projects

`make:modules-from-db` hard-fails unless every table in the database appears in some blueprint
group, so a fixture blueprint — which cannot know what else a project contains — is not directly
usable standalone. The runner works around it by emitting a full blueprint and merging, pushing
every non-fixture table into the empty-string skip group.

The better fix is open: the command already builds a table→group map from the modules on disk
(`ModuleScaffolder::buildTableToGroupMapFromFs()`) and uses it only for FK demotion, three lines
above the hard-fail that ignores it. Seeding the accounted-for set from that map — plus applying the
existing `FRAMEWORK_TABLES` const, since `getBareApplicationTables()` does not exclude `migrations`
or `password_reset_tokens` — would make any partial blueprint portable.

## v3.4.25 — 2026-08-25

Eight items from a real 13-module rental-CRM consuming project, all reproduced and all covered by
new tests (864 → 896 tests, 4109 → 4242 assertions).

The theme is worth stating, because it says where to look next: **every one of these was live while
the full unit suite was green.** Unit tests assert on generated *strings*, and none of these bugs is
visible in a string — they appear when the generated code is *executed* (a null model, an undefined
relationship, a payload the validator rejects) or *bundled* (an import of a file that was never
written). Three of the eight are also cases of the generator contradicting configuration it had
already written itself, which no amount of string assertion can catch.

### Fixed — a module with a reduced operation set could not be built for production at all

`{Module}DetailsLayout.vue` imported `./Components/{Module}{Edit,Delete}Form.vue` unconditionally.
EditFormGenerator/DeleteFormGenerator never write those files for a module with a reduced operation
set — an append-only ledger (list/view), a receipts table (create/list/view) — so the layout
imported files that do not exist.

`vite dev` tolerates it: the module is only resolved when someone opens that route, which is why a
fully green e2e suite never caught it. `vite build` resolves every import statically:

```
[UNRESOLVED_IMPORT] Could not resolve './Components/PaymentsEditForm.vue'
```

An application containing one such module could not produce a production bundle.
`ViewLayoutGenerator::generateCrudOperationBlocks()` now emits the toolbar, modals and imports only
for enabled operations, following `ListPageGenerator`'s identical, already-shipped pattern; with no
delete the `v-if`/`v-else` restore pair collapses rather than leaving an empty `<template v-if>`.
The frontend counterpart of the backend bug v3.4.17 fixed.

### Fixed — an edit-time `before_save` processor could not see the row it was editing

`BaseServiceGenerator::generateProcessorCalls()` passed a literal `null` for `$model` on `before_save`
for **both** create and edit, though `beforeUpdate($validData, array $validParams, {Module}Model $model)`
has the stored model in scope. The single most useful thing an edit-time processor does — compare
submitted data against what is persisted, for guards, locks, and values that must not be re-derived
on edit — was impossible without a caller-side backtrace hack.

`null` is now passed only where there genuinely is no model (`beforeCreate`, whose row does not exist
yet). This also makes `$model === null` at `before_save` a **reliable** "this is a create" signal,
which it previously was not: it meant "create, or edit, indistinguishably".

`ProcessorGenerationTest` had locked the old behaviour in as "a real, easy-to-get-wrong asymmetry
worth locking in". That was characterization of previously untested code, not a rationale — there is
no reason to withhold a model that is in scope. Both the code and that test were inverted.

### Fixed — an inline `select` carrying its own options was treated as a foreign key

`ViewServiceGenerator::extractInlineItemFkFields()` keyed off `type === 'select'` alone, stripped a
trailing `_id`, camelCased the result into a relation name and handed it to `::with()`. A field with
a literal `options` array, or one resolving through a `splash_key`, has no related model at all — the
View endpoint 500s on `Call to undefined relationship`. Both shapes are now skipped. The downstream
workaround had been to redeclare the field as a plain `input`, losing the dropdown entirely.

### Fixed — a string column ignored its own schema default, so every fixture started invalid

`FactoryGenerator` gave every plain string column `Str::limit(fake()->words(2, true), $length, '')`.
A `status` column backed by module `constants` is a plain varchar to the DB, so it was born as
`"incidunt sit"` — a value no guard, action or state machine in the application accepts. Every
factory-built fixture began in an impossible state, and each affected project patched its factories
by hand.

`constants` cannot fix this on its own: it is a flat `name => value` map with no column association
(one module had eight constants spanning `status` and `deposit_status`). The column's own `default`,
already read from the migration by `IntrospectionToConfig::buildColumn()`, does — and it is right for
every project, not only ones using constants. Booleans have honoured their default since
`buildScalarValue()` was written; this applies the same rule to strings.

Skipped for a `unique` column (every row would collide on the second insert), for a function-shaped
default (`CURRENT_TIMESTAMP` is computed at insert time, not a literal), and for a default longer
than the column, which would reintroduce the `SQLSTATE 22003` this generator's length handling exists
to prevent.

### Fixed — the generated edit step broke the cross-field rule the generator itself had written

The CRUD spec's edit step advanced its anchor date by a day and touched nothing else. When another
field carries `after:{anchor}` / `after_or_equal:{anchor}` — a start/end pair, the common case —
that single move violates the module's own generated validation. The update 422s with "The end date
field must be a date after or equal to start date", the dialog never closes, and the spec times out
on its row assertion. The failure reads exactly like a product bug: backend right, frontend right,
generated spec contradicting generated rules.

`buildDependentDateFills()` now reads those rules and moves every `after:`/`after_or_equal:` dependent
along with the anchor. Only forward dependents move: a `before:{anchor}` field is satisfied, not
broken, by the anchor advancing.

### Fixed — an `in:` rule's declared domain was ignored by both test generators

`billing_cycle_months` is `required|integer|in:1,2,3,6,12`; the numeric branch filled
`(1000000 + (stamp % 900000))` — a 7-digit value that fits the column perfectly and is rejected
outright by the rules this generator wrote. Every create 422'd with "The selected billing cycle
months is invalid", failing the spec on a later step it had never reached.

An `in:` rule names a column's entire valid domain, so nothing type-shaped can beat it. Both
generators now read it, ahead of every type branch. The e2e edit step picks a different member where
the domain has one, so the edit remains a real change. Enum columns were already covered through
`enum_values`; this is the validation-layer equivalent for a plain column.

### Added — `sample_value` for a field whose real constraint is not in its rules

Some constraints are unreachable from anything a generator can read. A field with a
`processing_service` is the clearest case: its value must satisfy a **normalizer**, not a validation
rule, so `nullable|string|max:255` is all the rules say while the service rejects everything that is
not a parseable phone number. Both generators already *knew* the field had a `processing_service` —
they weaken their assertions for exactly that reason — but had no way to be told what a good value
looks like, so every create submitted `Test Phone 68ab...` and 422'd.

Declared on the same `features.backend.{create,edit}.fields[]` entry that carries
`processing_service`:

```json
"sample_value": 1,
"sample_value_php": "'+25575' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT)",
"sample_value_js":  "('+2557' + String(stamp).slice(-8))"
```

A scalar renders as a literal in either language; the `_php`/`_js` forms carry a raw expression for a
value that must vary per run, which a **unique-indexed** column requires. The spec's own `stamp` const
is in scope at every JS fill site. Expressions are emitted verbatim, exactly as `processing_service`'s
class name is.

It overrides every derivation, including `in:`, so `docs/processors.md` is explicit that it is a last
resort: a stale `sample_value` silently outlives the schema change that invalidated it. Prefer a
source that cannot drift — an `in:` rule, a column `default`, `enum_values`. What genuinely needs it:
a normalizer; a `regex:` the default literal cannot satisfy; a guard on the *related* row (an FK that
must point at a particular kind of record); or a value that is legal but nonsensical, where the rules
permit a range the domain does not.

Note these keys live in `features.backend.*.fields`, which `mergePersistedFields()` does not preserve
— a full blueprint regenerate drops them, exactly as it drops `processing_service`.

### Changed — a rejected filter candidate now says which field it was, and why

`pickVisibleFilterField()` drops unusable candidates silently, so a module with misconfigured
`filterableFields` emitted a generic "no safe candidate" line naming none of them, and the operator
learned about it as a confusing e2e failure minutes into a suite run. Three distinct
misconfigurations land there, all derivable from the schema already in hand:

1. the field is not a visible list column (the filter step reads the row's current cell text);
2. it is FK-typed and its list column has no dot-path `data`, so the cell renders a raw id while the
   picker offers resolved labels — in practice, the referenced module has no `name` column;
3. the column is nullable, and a NULL renders "N/A", which is never a selectable option.

Each is now named individually with its own reason, following v3.4.23's approach for the unique-FK
case: a specific comment at the collision point. (3) is a caution rather than a rejection — a nullable
column still filters correctly for rows that have a value, so rejecting it would delete working
coverage.

## v3.4.24 — 2026-08-23

### Docs — the integration-fixture workflow told you to clean up its migrations too late to matter

Docs-only; no generator behaviour change. Released because consuming projects read these files
straight out of `vendor/blutrixx/generator-engine/tests/Fixtures/…` (this package ships its whole
repo — no `.gitattributes` `export-ignore`), so a correction to the workflow only reaches them
through a version bump.

The migration-collision gotcha and the five integration-fixture READMEs contradicted each other on
*when* to delete the fixture migrations you copy in for introspection:

```
gotchas.md:      "delete the top-level copy once scaffolding succeeds"
README step 3:   run make:module
README step 4:   Test                  <-- the collision breaks everything here
README step 5:   "Clean up when done: ... remove the copied migration files"
```

Follow a README literally and you are guaranteed to hit it, because the test run sits *between*
scaffolding and cleanup — and `RefreshDatabase` (a consuming project's `TestCase` runs
`db:migrate --fresh --seed` in `setUp()`) is precisely what trips it. `make:module` writes its own
copy of each migration under the module's `Migrations/` folder, a consuming project auto-loads those
*in addition to* the top-level folder, and two migrations creating one table fails the second one.

All five suite READMEs (items, orders, morphs, delegations, actions) now carry the deletion inline,
in the same step as their `make:module` commands, with the concrete `rm` and a note that later
`--force` regenerations rewrite the module-scoped copy only — so removing the top-level one early is
safe. Their step-5 cleanup sections drop the now-stale "remove the copied migration files" line and
instead cover what genuinely *is* still outstanding at that point: the generated module directories
plus the shared files `make:module` also writes to (`registry.json`, `modules.json`, `menus.json`),
which the old text never mentioned at all.

`docs/examples/gotchas.md` gains the timing rule plus the three properties that make this hard to
recognise — generation reports `Errors: 0` and is telling the truth (the conflict only exists once
both files sit on disk together); the failure is delayed to the next `--fresh` run; and it surfaces
as *unrelated* suites failing on shared `setUp()` while pure unit tests still pass, which reads like
an auth bug rather than a schema one — and a `find` one-liner to confirm it.

Recorded there too: documentation alone is not sufficient here. This was hit live again on
2026-08-23 *in the same session that edited that very file*. The real fix is `make:module` detecting
the duplicate at generation time and saying so; that remains open.

## v3.4.23 — 2026-08-23

Five items from an external maintainer report, all reproducible. Two doc-only, three code fixes.

### Fixed — a required, uniquely-constrained FK field silently got the same collision-prone fill as an ordinary FK

`fillSelectField()` always takes the first available option — fine for an ordinary many-to-one
relation (any number of rows may legitimately share the referenced record), but a required field
whose OWN column the schema marks `unique` is a true 1:1 relation: at most one row in this table may
ever reference a given related record, so option[0] is only ever free on the very first test run
against a given dev DB. Every run after that 422s against the column's own unique index.

Auto-fixing this generically (create a disposable related record, recursing into its own required
fields, which may themselves be relation-backed) was deliberately NOT attempted — there's no real
fixture anywhere in this repo with a single-column-unique FK to verify that against end-to-end, and
shipping an untested runtime behavior change into every future module regeneration is a worse
outcome than a clearly-flagged gap. New `isUniqueRequiredRelationField()` detects the case; the
generated spec now gets a specific, actionable comment right at the point of the collision-prone
call instead of an unmarked one — pointing at the ONE place this has actually been fixed and
verified end-to-end: `SYSTEM_SHELL/FRONTEND`'s hand-patched `user-locations-crud.e2e.js`
(`createThrowawayLocation()` + `fillSelectFieldByText()`), for the composite-unique-constraint
shape of this same bug.

New regression test confirms the comment lands on the unique FK and NOT on an adjacent ordinary
required FK in the same field list.

### Fixed — a fallback-path fixture literal silently overflowed a short varchar/char column

`fieldsSource()`'s fallback branch (modules with no `create`/`edit` fields to read validation rules
from — list+view-only, the same branch v3.1.6-era work already fixed for numeric/boolean/date types)
called `fallbackRuleForColumnType()`, whose `string`/`varchar`/`char` case returned a bare
`'required|string'` with no length awareness at all — unlike `IntrospectionToConfig::
buildBackendFields()`'s own `'string'` rules, which always append `max:{length}`. Without a `max:`
substring in the rules string, `buildFieldValueLiteral()`'s `max:(\d+)` regex never matched, so its
existing clamping (`buildMaxAwareUniqueStringLiteral()`, added for the exact same reason a session
ago) never fired, and the unclamped 24-char `'Test {Field} ' . uniqid()` literal silently overflowed
any column shorter than that.

Found live on a real varchar(20) `event` column in a list+view-only module — the ONE occurrence
across 16 modules in that project, since every other affected column happened to go through the
primary (introspected) path, which was never broken. `messages.category` in the same project clamps
correctly for exactly this reason.

Fixed by appending `max:{length}` (falling back to 255, matching the primary path's own convention)
for string-like columns in this fallback branch too. New regression test reproduces the exact
`varchar(20)` 'event' case and asserts the clamped `'Test Ev' . uniqid()` literal.

### Fixed — two error messages actively misdiagnosed their own cause

`fillSelectField()`'s and `fillMorphSelectField()`'s "no selectable options ... — check seed data"
fires identically whether the picker settled on a genuine empty state OR simply never settled within
the 10s deadline (still loading, or a hung/slow API response) — the SAME polling loop these messages
sit behind was already fixed (v3.4.18) to tell those two outcomes apart internally, but the error
text never learned the distinction. A timeout got blamed on missing fixtures every time. Both helpers
(three call sites total: `fillSelectField()`, and `fillMorphSelectField()`'s type- and record-pickers)
now report which outcome actually happened.

Separately, `createFixtureRecord()`'s and `buildCreateBlock()`'s "could not capture a uuid" (added in
v3.4.20's DOM-position fix) fires identically whether the create request itself failed validation
(e.g. a 422 on a unique index) or genuinely succeeded with an unparseable response — the first case
is far more common in practice and the message pointed nowhere near it. All four call sites
(`buildCreateBlock()`'s no-anchor path, and `buildFixtureCreateBody()`'s both anchor and no-anchor
paths) now check the response status first and, on failure, report the real HTTP status and body
instead of a generic capture-failure message.

### Docs — `e2e/helpers/*.js` being entirely hand-maintained is easy to miss from a CHANGELOG entry alone

New gotcha (`docs/examples/gotchas.md`): `fixtures.js`/`auth.js`/`config.js`/`filters.js` are
imported by every generated spec but have no engine template and are never written by the package —
scaffolded once per project, by hand, and never touched by a version bump. A CHANGELOG entry can
legitimately describe the SAME bug existing in both the engine's own generated output and one of
these files (v3.4.18's fixed-sleep race is exactly this shape — `filters.js`'s
`setFilterSelect2Value()` mirrors `fillSelectField()` almost line for line) without the actual code
change reaching the hand-maintained copy at all. Also caught and fixed while investigating this:
`docs/changelog.md` (the VitePress-published copy) had silently fallen behind `CHANGELOG.md` by two
entries (v3.4.21, v3.4.22) — resynced.

**Consuming apps**: regenerate any module with a required, schema-unique FK field to pick up the new
warning comment; any module hitting `fieldsSource()`'s fallback path (no create/edit fields, a
short string/varchar/char column) to pick up the clamping fix. Both are purely additive/corrective —
no module without either shape sees any output change.

## v3.4.22 — 2026-08-23

### Fixed — the "missing required field" test was generated for modules with no required field

`firstRequiredField()` fell back to `$fields[0]` when no field's rules carried `required`:

```php
return $fields[0] ?? null;
```

Its single caller builds a test that omits that field and asserts `422`. That assertion is only
true if the field is genuinely required, so the fallback did not weaken the test — it **inverted**
it. A module whose create fields are all nullable got a test demanding a validation error its own
generated rules explicitly permit.

Found live on a `Categories` module with one nullable `name` column. The same config produced
`'name' => ["nullable", "string", "max:255"]` in `CategoriesCreateService` and
`test_create_category_validation_fails_with_missing_required_field` asserting 422 for omitting it.
The endpoint correctly returned 201. It was the only failure in a 1558-test suite, because every
other module has at least one genuinely required field for the fallback to skip past.

`firstRequiredField()` now returns `null` with no fallback, and `buildCreateValidationTestMethod()`
returns `?string`, skipping the test when nothing is required — `writeSplitFile()` already
`array_filter()`s nulls, the same "this gap doesn't apply to this module" signal
`findFieldByRule()`'s callers use.

`firstUniqueField()` deliberately KEEPS its `$fields[0]` fallback. The distinction is what the
caller does with the result: a payload builder needs *some* field and degrades gracefully with an
arbitrary one, whereas an assertion about a field's *rules* is simply wrong if the field doesn't
carry them. Same shape as v3.4.21's DeleteCheck gate — a fallback that silently manufactures a
false claim instead of signalling "not applicable".

Two regression tests added: the test is absent when every create field is nullable, and still
generated (still unsetting the right field) when one is required.

## v3.4.21 — 2026-08-23

### Fixed — the DeleteCheck test file was generated even when its route was not

`PhpUnitTestGenerator::generate()` gates every operation's test methods behind its own `$has*` flag
— except DeleteCheck, which built its method array unconditionally:

```php
$deleteCheckMethods = [$this->buildDeleteCheckTestMethod($routeBase)];
```

`RoutesGenerator` emits `/{uuid}/delete/check` only when `deleteCheck` or `delete` is declared, so
for any module without them the generator wrote a test asserting `200` against a route the router
never registered — a guaranteed 404.

This also made DeleteCheck the one suffix v3.4.17's `deleteDisabledOperationFileIfPresent()` could
never reach, despite that method's own SCOPE docblock listing it. The cleanup only runs when
`renderSplitTestFile()` returns `null`, which only happens for an empty method array — and this
array was never empty. The cleanup code was correct all along; it was simply unreachable. Nothing in
that method changed here, and its docblock is now accurate rather than aspirational.

Found on a real 49-module POS scaffold: v3.4.17 correctly removed 12 of the 16 stale files across
four modules reduced to list+view (ItemStock, ItemBatches, ItemSerials, StockMovements) and left
exactly the 4 DeleteCheck ones, which then failed with
`Expected response status code [200] but received 404` while `route:list` confirmed the routes
really were gone.

Gated on a new `$hasDeleteCheck`, which mirrors `RoutesGenerator`'s own condition rather than
reusing `$hasDelete` — a module may declare `deleteCheck` with no `delete` operation at all, and
that route still deserves coverage:

```php
$this->hasDeleteCheck = isset($backendFeatures['deleteCheck']) || !empty($backendFeatures['delete']);
```

The two halves use different emptiness tests on purpose, each matching the code it has to agree
with: `isset()` for `deleteCheck` mirrors `RoutesGenerator` (so an explicit `deleteCheck => false`
still counts as declared, exactly as it does there), while `!empty()` for `delete` matches the
sibling `$hasDelete`. Since `!empty()` implies `isset()`, the flag is always a subset of the routes
actually generated, so it cannot resurrect the 404 class of bug this gate exists to stop.

Two existing tests asserted the old behaviour and now assert the new one.
`test_generate_omits_delete_method_when_delete_feature_is_disabled` explicitly required the
DeleteCheck method and `/delete/check` to still be present, under a comment calling it
"pipeline-unconditional coverage". `test_generate_is_byte_for_byte_unchanged_for_a_module_with_no_enabled_backend_features`
expected `WidgetsDeleteCheckServiceTest.php` among exactly four files for a module with zero enabled
features, and carried a full byte-for-byte heredoc of its contents — the bug in its purest form.

Four regression tests added: the file is absent when delete is disabled; a previously generated one
is removed on a `--force` regenerate; it is still generated when delete IS enabled (guarding against
over-correcting); and it is generated for `deleteCheck` declared without `delete`.

Credit: independently confirmed in a parallel fork by another session, which also caught the
`deleteCheck`-without-`delete` case that gating on `$hasDelete` alone would have broken.

## v3.4.20 — 2026-08-23

### Fixed — a create-target row was found by DOM position, not by identity, when no plain text/number field exists

For a module with a plain text/number create field (e.g. `name`), the generated CRUD spec finds "the
row just created" by searching for that value's own text in the list — reliable regardless of sort
order. But when every create field is a select/checkbox/file-input (`pickAnchorField()` returns
`null`), `buildCreateBlock()`/`buildTargetRowBlock()`/`buildFixtureCreateBody()` all fell back to
`page.locator('table tbody tr').first()` — an assumption that the row at DOM position 0, after
sorting by id desc, is the one just created. That assumption silently breaks whenever any
pre-existing row sorts above every freshly created one for a reason unrelated to creation order.

Found live on `UserLocations`: a legacy pre-UUIDv7-format `id` (`1f7811c7-...`) lexicographically
sorts above every real UUIDv7 id (`0198...`/`01a...`) under `ORDER BY id DESC`, since `1` > `0` in
the first hex digit — regardless of when either row was actually created. `user-locations-crud.e2e.js`
kept grabbing that unrelated seeded row for every View/Edit/Delete step instead of the row the test
itself had just created; Delete then hit that row's own `is_primary`/`only_location` delete-check
guards and failed, masking the real bug behind what looked like a guard/cleanup failure.

Fixed by identifying the created record by the uuid the backend actually returned, never by DOM
position, whenever no anchor field exists: `buildCreateBlock()` now arms a
`page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'))`
listener immediately before the submit click (Promise created before the click, awaited after — no
race), reads `data.uuid` from the response body, and `buildTargetRowBlock()` filters
`table tbody tr` for the one containing that uuid's own `[data-testid="{module}-view-{uuid}"]`
button. `buildFixtureCreateBody()` (the shared `_fixtures.js` `createFixtureRecord()` used by
delegation/action specs) had the identical dual fallback and gets the identical fix — reading
`recordUuid` straight from the response instead of round-tripping through the DOM at all. A module
WITH an anchor field, or with create disabled entirely, is byte-for-byte unaffected.

2 new regression tests (`PlaywrightTestGeneratorTest`), covering both the CRUD spec and the shared
fixtures file. Full suite green: 856 tests, 4113 assertions.

**Consuming apps**: regenerate any module whose create form has no plain text/number field
(`make:module ... --force`, or a batch `make:modules-from-db --force`) to pick this up.

## v3.4.19 — 2026-08-23

### Fixed — a read-only module's generated CRUD spec clicked its next step into a still-open View dialog

`buildViewBlock()` opens the View dialog, logs `"view OK"`, and never closes it — nothing in that
method ever did. That's correct on purpose when an Edit step follows: `buildEditBlock()`'s Edit
button lives *inside* the still-open View dialog, and its own submit path already waits for the
dialog to close (`await page.waitForFunction(() => !document.querySelector('[role="dialog"]'))`)
before continuing. But for a read-only module (no `hasEdit`), nothing ever plays that role —
whatever runs next (BulkAction/Export/Import/Delete) clicks straight into a still-open modal
overlay, since none of those steps wait for or expect one.

Found live: every read-only module's next-configured step (Export, in the reported case) clicked
immediately after `"view OK"` with the View modal still on screen, while an editable module's next
step (Edit) always happened to survive, because Edit's own teardown incidentally closed it.

Fixed by closing the View dialog in `buildViewBlock()` itself whenever `!$this->hasEdit`, via
`page.locator('[role="dialog"]').getByRole('button', { name: 'Close' }).click()` — **not**
`page.keyboard.press('Escape')`. `AppDialog.vue` defaults `persistent: true`, and this dialog usage
never overrides it, so Escape is captured and `preventDefault()`'d and can never close it — the exact
same class of bug `buildCreateBlock()` already hit and fixed for this identical dialog (v3.4.2:
"confirmed live, every module's create step hung for 15s waiting on a dialog that Escape can never
close"). Reused that same proven Close-button click here instead of rediscovering it the hard way a
second time.

Note: `buildRelatedRecordBlock()`'s own `page.keyboard.press('Escape')` a few steps above View is
very likely just as inert on its dialog (`EntityModalProvider.vue` wraps the same `AppDialog` with
the same `persistent: true` default) — it happens to work anyway only because the very next line
does a hard `page.goto()`, which clears the dialog regardless of whether Escape did anything.
Deliberately not touched here — out of scope for this bug, documented so it isn't mistaken for a
working reference pattern in the future.

2 new regression tests (`PlaywrightTestGeneratorTest`): the View step's own Close-button teardown is
present and immediately follows `"view OK"` when no Edit step follows, and is absent — dialog stays
open — when one does. Full suite green: 854 tests, 4103 assertions.

**Consuming apps**: regenerate any read-only module's CRUD spec (`make:module ... --force`, or a
batch `make:modules-from-db --force`) to pick this up. An editable module's generated spec is
byte-for-byte identical — the fix only fires when `hasEdit` is false.

## v3.4.18 — 2026-08-23

### Fixed — the three generated select-picker helpers read the option list after a fixed sleep, so a slow fetch surfaced as "no selectable options"

`fillSelectField()`, `tryFillSelectField()` and `fillMorphSelectField()` (both of the latter's
pickers) all opened the picker, waited a hard-coded 300ms — and `setFilterSelect2Value()`, its
sibling in the consuming project's `e2e/helpers/filters.js`, waited 400ms after typing a search
term — then immediately counted `.divide-y > div` / `.cursor-pointer` rows.

ApiSelect2 mounts **three mutually exclusive branches** while its fetch is in flight: a spinner
(`.animate-spin` + "Loading..."), an empty state ("No results found" / "No options available"), or
the option list. Only the list has option rows at all. So counting rows after a fixed delay samples
whichever branch happened to be mounted at that instant, and a fetch slower than the sleep is
reported as `no selectable options found for "<label>" — check seed data`: a message that blames
the fixtures for what is really a timing bug.

Found on a real 15-module suite, and the diagnosis took two wrong turns worth recording. The
symptom was read first as a **selector mismatch** — the option was visibly rendered with exactly the
right text, so the selectors "must" be wrong. They were not: extracting the failing run's own
`trace.zip` and walking its DOM snapshots showed the option list rendering as
`.divide-y > div > .flex-1` exactly as the selectors expect, and showed the snapshot *at the moment
of failure* holding only `<div class="animate-spin">Loading...</div>` in the same list area. The
second tell was non-determinism: two specs swapped which one failed between consecutive runs of the
same code — the signature of a duration-based wait, never of a wrong selector. (Playwright's
accessibility snapshot cannot settle this either way; it collapses generic wrappers, so an option
that "looks" one level deep in the aria tree may be three levels deep in the DOM.)

All four sites now poll the component's own settled state — rows present, or not-loading *and* an
empty state shown — bounded by a 10s deadline so an unresolvable picker still falls through to the
helper's existing error rather than hanging until Playwright's global timeout. The two-tier
`.divide-y > div` then `.cursor-pointer` preference is unchanged, so local Select2 pickers behave
exactly as before.

4 new regression tests (`PlaywrightTestGeneratorTest`), each verified to fail when the fixed sleep
is reintroduced.

## v3.4.17 — 2026-08-23

### Fixed — disabling an operation left its generated test file behind forever, failing against routes that no longer exist

`generate()` gates each operation's methods behind its own `$has*` flag, so disabling one — Step 9's
"omit the operation entirely to disable it" — leaves `writeSplitFile()` holding an empty array.
`renderSplitTestFile()` returns null for that and `writeSplitFile()` did `return true` without
writing. Skipping the write is correct. What was missing is that the file generated back when the
operation WAS enabled just sat there.

Its routes are gone, so every test in it now asserts against a 404 — and the generator reported
success the entire time, because "wrote nothing" and "wrote successfully" were the same return value.

Found live on a real 49-module POS scaffold. Four modules were reduced to list+view (ItemStock,
ItemBatches, ItemSerials, StockMovements — a single shared write path means a create form on them
would be a second one). Between them they kept **16** Create/Edit/Delete/DeleteCheck test files and
failed **46** tests with `Expected response status code [201] but received 404`, while
`php artisan route:list | grep -c "item-stock/(create|edit|delete)"` returned 0, confirming the
routes really were gone and only the tests were stale.

`writeSplitFile()` now calls a new `deleteDisabledOperationFileIfPresent()` on the null-content path.
That is the same shape, the same `--force` guard and the same reasoning as the long-standing
`deleteStaleMonolithicFileIfPresent()` immediately below it: an ordinary run must never delete a file
it did not just decide to replace. One change covers all six operations `generate()` calls
`writeSplitFile()` for with a possibly-empty array — List, Create, Edit, View, Delete, DeleteCheck —
with no per-operation code. `ActivityListService` always passes a non-empty array and can never
orphan.

**Deliberately NOT covered — a delegation, action or bulk_action removed from config.** Those test
files are written inside `foreach ($this->config['delegations'] ...)` loops, so a removed key never
reaches `writeSplitFile()` at all and this fix cannot fire for it. Cleaning those up means scanning
the Tests directory and reconciling it against config, which is a materially bigger change than this
one; recorded here rather than left to be rediscovered.

`regenerateOnly()`'s `writeSplitFileAlways()` path was checked and deliberately left alone: it is
only ever called for one specific delegation/action key the caller has already validated exists, so
empty methods there mean a genuine failure and `return false` is right. It must not delete.

`PlaywrightTestGenerator` was checked for the same gap and does not have it — its CRUD spec is one
file per module rather than one per operation, so a reduced operation set regenerates that file in
place. Verified on the same four modules: `item-stock-crud.e2e.js` contains no create/edit/delete
helpers at all.

2 new regression tests: one proving a disabled operation's file is removed under `--force`, one
proving a non-`--force` run leaves it strictly alone. Verified failing before the fix and passing
after. Full suite green: 850 tests, 4073 assertions.

**Consuming apps**: any module that had an operation disabled AFTER it was first generated is
carrying dead test files right now. One `make:module {Group}/{Module} --force` per affected module
(or a batch `make:modules-from-db --force`) removes them. Modules that never disabled an operation
generate byte-for-byte identically to v3.4.16.

## v3.4.16 — 2026-08-23

### Fixed — Details/Overview pages showed raw FK ids and no status color whenever the View config never got a relationship dot-path

Three existing relationship-aware render paths — `BaseComponentGenerator::mapViewFieldsToInformationFields()`
(the Information card), `generateHeaderBadges()` (the header/status badge), and (unaffected in
practice, but the same latent gap) `generateCustomCellRenderersFromListFields()` (List page badges)
— only ever treated a field as a relationship when its configured `data`/`dataPath` string already
contained a dot (e.g. `location?.name`). None of them derived a relationship from column metadata
when that dot was simply never authored.

Found live on a real Expenses record: its View config stored the raw FK columns directly
(`{"data": "location_id"}`, `{"data": "status_id", "format": "status"}`) rather than a dot-path —
`format: "status"` was captured but never even read anywhere. Web's own generated Overview page
showed the identical raw ids for the identical fields, confirming this as a shared, cross-platform
config gap rather than a mobile-only rendering bug.

New `resolveColumnRelationship()` derives the relation + display field straight from column metadata
(`type: foreignId` + `relatedModule`) as a fallback wherever the dot is missing, reusing the exact
relation-naming logic `ModelGenerator`'s own `belongsTo()` generation uses
(`deriveRelationshipMethodName()`, moved from `ModelGenerator` up to the shared `BaseGenerator` so
both stay in sync instead of risking a second, drifting heuristic). A status-like relationship
(column literally named `..._status_id`) also gets a small color dot next to the resolved name, using
the same `.color` convention `StatusBadge`/`generateRelationshipBadge()` already established.
Existing explicit dot-path configs are completely untouched — they still hit the original branch
first, unchanged.

Also fixes the same root cause on the backend: `BaseServiceGenerator::generateEagerLoadRelationships()`
is driven entirely by a `features.backend.{feature}.eagerLoadRelationships` config value, and a
feature that never had one populated (confirmed on the same Expenses module: `list`'s was
auto-populated, `view`'s never was) fell straight to `creator`/`updater` only — so even with the
frontend correctly asking for `location?.name`, the API response had no `location` relation at all
to read. Now auto-includes every `foreignId` column's own relation when a feature has no explicit
config, same fallback signal as above.

11 new regression tests (`BaseComponentGeneratorTest`, `BaseServiceGeneratorTest`).

### Fixed — MobileApp's Overview page silently ignored the View-builder wizard's configured field list, present since the package's first commit

`MobileApp\Pages\ViewOverviewGenerator::generateOverviewSections()` checked
`$viewConfig['sections']` — an older, alternate custom-sections shape — but never
`$viewConfig['fields']`, the key the View-builder wizard actually writes. Every module configured via
the standard wizard had its field list/order/selection silently ignored on mobile, always falling
back to a raw `columns` iteration with generic auto-labels instead. This predates the current
Agrovet-style card redesign entirely (confirmed via `git blame`: the check dates to `e5f7105`,
2026-05-09) and happened to produce near-identical-looking output for an unconfigured module, which
is why it went unnoticed until a field's real configured title was compared against its
auto-generated label.

Now reads `viewConfig['fields']` (using each field's `title`, matching the same key web's
`mapViewFieldsToInformationFields()` already reads — an early draft of this fix used `label`, a key
this config shape never actually sets, and a regression test caught the resulting silent
auto-label-instead-of-title fallback before it shipped) as a second branch between the existing
`sections` check and the raw-`columns` fallback. `generateOverviewRow()` itself gained the same
`resolveColumnRelationship()` FK/status resolution described above, so both this new branch and the
pre-existing raw-`columns` fallback benefit from one change. 5 new regression tests
(`MobileApp\Pages\ViewOverviewGeneratorTest`, new file).

### Changed — mobile's Details page action row moved from a bottom-pinned footer to a top toolbar matching web's own Edit + More Actions pattern

Per direct user feedback after shipping the previous release's bottom-pinned Edit/Delete footer live:
mobile's `DetailsPageLayout.vue`/`details_layout.stub` now render Edit plus a "More Actions" dropdown
(Delete inside) at the very top of the page's content — mirroring web's own existing
`details_layout.stub` toolbar (Edit ghost-button + a `DropdownMenu` "More Actions" trigger with
Delete as its one item) instead of two full-width buttons pinned to the bottom via `AppShell`'s
footer slot. The footer slot usage is removed entirely; `[[headerActions]]` (custom per-module
action buttons) still renders inline alongside Edit, unchanged.

**Consuming apps**: regenerate any module's Details/Overview page (`make:module ... --force`, or a
batch `make:modules-from-db --force`) to pick up all three fixes above. A module whose View config
already used an explicit relationship dot-path, or whose mobile Overview already happened to look
correct, generates byte-for-byte identically except for the action-row restructure. Full suite green:
848 tests, 4065 assertions (828/4019 at v3.4.15, plus 20 new tests/46 new assertions here).

## v3.4.15 — 2026-08-23

### Fixed — an `enum` column inside a composite UNIQUE made every generated fixture call die on `Data truncated`

`buildCompositeUniqueVarianceLines()` picks one member of each composite unique constraint and
re-emits it in the fixture helper's array literal as a value that varies per call, so three
back-to-back `create{Singular}Fixture()` calls (`buildListTestMethod()`'s pattern, no overrides)
don't submit an identical tuple. The member is chosen by `firstNonFkCompositeUniqueMember()` — the
first one that is not FK-shaped.

It never considered that a column can be non-FK and *still* have no fresh value to invent. An
`enum` accepts only its declared values, so the fallback literal
`'{field}' => 'U' . $uniqueSequence` is not one of them and MySQL rejects the insert outright:

```
SQLSTATE[01000]: Warning: 1265 Data truncated for column 'order_type' at row 1
insert into `item_prices` (`item_id`, `price_list_id`, `order_type`, ...) values (37, 17, U11, ...)
```

Note the generated line is a *deliberate* duplicate array key (last-wins, see
`buildCompositeUniqueVarianceLines()`'s docblock), so it silently overwrote the perfectly valid
`'order_type' => 'cash'` that `buildPayloadLines()` had already emitted two lines above. The fixture
looked correct on inspection right up to the point it hit the database.

Found live on a real 49-module POS scaffold. `item_prices` has
`UNIQUE(item_id, price_list_id, order_type, location_id)`: three members are FKs, so the enum was
selected by elimination as the only non-FK candidate. 12 of that module's generated tests failed and
**no other module in the 49 was affected** — it was the only table in the schema with an enum inside
a composite unique key, which is exactly why this survived every previous release.

Fixed by disqualifying every *fixed-domain* type as a varying member, not just FKs:

| Type | Why it cannot vary |
|---|---|
| `foreignId` | varying it means fabricating a different parent row id per call (unchanged behaviour) |
| `enum` | accepts only its declared values; a sequence string is rejected by the database |
| `boolean` | only two values exist, so it cannot disambiguate three rows, and a string would coerce to 0/1 |

When no member qualifies the constraint is skipped entirely, exactly as an all-FK constraint already
was — the remaining members still vary on their own (a fresh FK row per fixture call), which is
usually enough to keep the tuple unique anyway. That is precisely what happens for `item_prices`:
`item_id` and `price_list_id` come from fresh factories on every call.

3 new regression tests in `PhpUnitTestGeneratorTest.php`, built on the real `item_prices` shape —
enum skipped, boolean skipped, and a guard proving a plain `string` member is *still* picked and
varied, so "skip the fixed-domain types" cannot quietly regress into "skip every constraint".
Verified failing before the fix and passing after. Full suite green: 828 tests, 4019 assertions (825 at v3.4.14, plus these 3).

`firstNonFkCompositeUniqueMember()` keeps its name even though it now reads narrower than the rule
it implements — it is `protected` and a consuming app may have overridden it. Its docblock carries
the full rule.

**Consuming apps**: regenerate any module whose table has an `enum` or `boolean` column inside a
composite UNIQUE constraint (`make:module ... --force`, or a batch `make:modules-from-db --force`).
Its `{Module}TestCase.php` currently contains a fixture helper that cannot insert a row. Modules
without such a constraint generate byte-for-byte identically to v3.4.14.

## v3.4.14 — 2026-08-23

### Fixed — a generated CRUD spec could never pass on a module with a bounded string column longer than 39 characters

`constrainToColumnLength()` only clamped a column when `length > 0 && $length < 40`, on the
assumption stated in its own docblock that a generated value is "~30-40 characters". That holds for
a short module name, and breaks as soon as the module name and the field label are both long:
`PackSizeUnits`' `abbreviation_singular` is a `varchar(50)`, but the generated value

    E2E PackSizeUnits Abbreviation Singular ${stamp}

is **53 characters** with a 13-digit `Date.now()` stamp — over the backend's own `max:50` rule. The
create POST returned 422, the row never appeared in the list, and the spec failed on the
`waitForFunction` that waits for it. `abbreviation_plural` (51 characters) failed the same way.
Deterministic, not flaky: it failed on every run.

Found live on a real 15-module scaffold, where the module's own PHPUnit suite was green throughout —
the module was correct, only its generated spec could not pass.

Fixed by clamping **any** bounded column, not only those under 40. Clamping a `varchar(255)` is a
harmless no-op (`slice(-255)` of a 45-character string is the whole string), so the guard bought
nothing while costing a whole class of silent 422s. 1 new regression test.

### Fixed — the FK filter step asserted an invariant that a many-to-one column can never satisfy

The Variant B (FK / `ApiSelect2`) filter step asserted the filtered list narrowed to **exactly one
row**:

    expect(filteredRowCount, `... to narrow the list to exactly 1 row ...`).toBe(1);

A foreign key is legitimately many-to-one, so that can never hold for a child table whose whole
purpose is many-per-parent. Confirmed live: an `item_images` table held two rows both carrying
`item_id=1` — one seeded as a cross-module fixture, one created by the spec itself — so filtering by
that item correctly returned 2, and the spec failed on a filter that was working exactly as
intended. Any module with a pre-seeded sibling sharing the filtered FK hits this.

The invariant that actually expresses "the filter works" is: at least one row survives, and *every*
surviving row carries the target value. The step now asserts that, and checks every visible row
rather than only the first. 1 new regression test.

**Consuming apps**: regenerate affected specs (`make:module ... --force`, or a batch
`make:modules-from-db --force`) to pick both fixes up.

## v3.4.13 — 2026-08-21

### Fixed — a modal action's generated e2e smoke test could report success without ever calling the action's real endpoint

`PlaywrightTestGenerator::buildActionSpecBody()`'s modal-`uiType` branch picked the submit button
via `actionDialog.locator('button').last()`. AppDialog renders its own chrome "Close" button inside
the same `[role="dialog"]` as the action form's Cancel/Submit pair, DOM-ordered *after* both — so
`.last()` silently resolved to Close, not Submit. Clicking Close does close the dialog (with zero
`pageerror`/`requestfailed` events), which is exactly the generated test's own success criterion —
so the test reported a passing "Approve" (or any other modal action) smoke test while the backend
never received the POST at all.

Found live building real demo data on a real 31-module project: a real Approve click via a hand
-written script closed its dialog same as the generated test would, but the PO's `status`/
`approved_by_id` never changed in the database. A diagnostic dump of the dialog's own buttons
showed the real DOM order: `["Cancel", "Approve", "Close"]` — three buttons, not two, with the
real submit button in the *middle*.

Fixed by matching the submit button by its own accessible name instead of DOM position — every
generated action form's submit button text is always exactly the action's own label (see
`features/action/form.stub`'s `{{ isSubmitting ? 'Processing…' : '[[ActionLabel]]' }}`), so
`actionDialog.getByRole('button', { name: '<label>', exact: true })` is unambiguous regardless of
how many other chrome buttons a dialog renders. 1 new regression test in
`PlaywrightTestGeneratorTest.php`.

The sibling page-`uiType` branch (`page.locator('form button').last()`) was checked and is not
affected the same way — the generated action form's root element is a plain `<div>`, not a
`<form>`, so a broken selector there would fail the test's own `.not.toHaveCount(0)` assertion
loudly rather than silently pass; left as-is.

**Consuming apps**: any already-generated `*-{action}.e2e.js` file predating this fix should be
regenerated (`make:module ... --force` or a batch `make:modules-from-db --force` picks it up
automatically) to get the corrected selector — a previously-"passing" modal action smoke test may
not actually have been exercising the real submit path.

## v3.4.12 — 2026-08-21

### Fixed — `inline_items` child rows never got `created_by_id`/`updated_by_id` unless the parent's config remembered to say so

`BaseServiceGenerator::buildInlineInjectArray()` only injected the audit column when an explicit
`child_has_creator_updater: true` was hand-set on the `inline_items` item config — a flag entirely
separate from, and easy to forget relative to, the CHILD module's own real `has_creator_updater`
setting. Forgetting it produces no validation error and no PHPUnit failure (the generated contract
test for a create service never exercises the inline_items child-row path with real data) — just a
live 500 on first real submit.

Found live building real demo data on a real 31-module project: submitting a real Purchase Order
with two line items 500'd with `SQLSTATE[HY000]: ... Field 'created_by_id' doesn't have a default
value`. `PurchaseOrders`' own `inline_items` entry for `PurchaseOrderItems` never set the flag,
despite `PurchaseOrderItems` genuinely having `has_creator_updater: true` in its own module.json —
the two facts had simply drifted apart, exactly the kind of authoring gap a hand-set duplicate flag
invites.

Fixed at the root: `child_has_creator_updater` is now only a manual *override*. When absent,
`buildInlineInjectArray()` derives the real answer from the child module's own already-known module
registry entry (`PathManager::findModuleInRegistry()`) — the same registry
`ModelGenerator::generateInverseHasManyRelationships()` and `buildChildNamespace()` already read for
an identical class of problem, using the same defensive `$entry['config'] ?? $entry` dual-shape read
so it works against both a batch-scaffold registry entry (nested under `config`) and a
filesystem-sourced one (flat, from `make:module`'s own `buildModuleRegistryFromFs()`). An explicit
`child_has_creator_updater` on the item config — true or false — still always wins over whatever the
registry says. Only when the child has no registry entry at all does this fall back to `false`,
preserving prior generated output for any project whose registry predates this fix.

**Consuming apps**: for this auto-derivation to work, each registry-population call site (SYSTEM_
SHELL's/a project's own `MakeModulesFromDb.php`, `ModuleScaffolder::buildModuleRegistryFromFs()`)
needs to include the module's real `has_creator_updater` value on the registry entries it builds —
the package itself only reads whatever key is there; it doesn't populate the registry. Consumers
that don't add this key simply keep getting the old opt-in-only, `false`-by-default behavior via the
"no registry entry" fallback — no regression, just no auto-derivation until updated.

6 new regression tests in `BaseServiceGeneratorTest.php` cover: auto-derive true/false from a nested
registry entry, auto-derive from a flat registry entry, fallback to `false` for an unregistered
child, and an explicit flag (either direction) overriding what the registry says.

## v3.4.11 — 2026-08-21

### Fixed — `inferFkByConvention()` never recognized a `*_by_id` business column as a foreign key

`approved_by_id`, `opened_by_id`, `recorded_by_id`, `sold_by_id`, `verified_by_id`-style columns (a
"which user did this business action" convention, distinct from the framework's own excluded
`created_by_id`/`updated_by_id` audit columns) were never detected as FKs by naming convention:
`inferFkByConvention()`'s generic match strips `_id` and checks `Str::plural()`/`Str::singular()` of
what's left against real table names, but `recorded_by` has no plural/singular form that spells
"users" — the exact same shape `parent_id` already gets a dedicated special case for, since a
self-referential hierarchy's column name never encodes its own table name either.

Found live building real demo data on a real 31-module project: a full empirical scan (comparing raw
introspection against the original, session-start column census) turned up 18 FK columns across 12
modules that a recent full-project regenerate had silently demoted to plain integers — 7 of the 18
shared this exact `*_by_id` shape. Fixed by adding the same kind of explicit convention `parent_id`
already has, gated on the target `users` table actually existing. No PHPUnit coverage added — like
`parent_id` itself, this needs a real Schema connection this suite doesn't set up; verified live
instead (confirmed `approved_by_id` on a real `purchase_orders` table detects correctly post-fix).

The remaining 11 of the 18 columns found in the same scan (prefix-qualified target tables like
`category_id` → `item_categories`, `batch_id` → `item_batches`; descriptively-qualified names like
`source_quotation_id` → `quotations`, `converted_to_sale_id` → `sales`) are a real, still-open gap —
flagged, not fixed here, since safely generalizing table-name matching to handle arbitrary prefixes/
qualifiers risks false-positive matches against the wrong table. Worked around per-project via
explicit `relatedModule`/`api_url` overrides in the affected modules' own config.

## v3.4.10 — 2026-08-21

### Fixed — an FK picker's `api_url` could reference a module name that doesn't exist, 403ing silently

`IntrospectionToConfig::buildFrontendFormFields()` derived a real FK column's `/select/{slug}` API
URL from an independently re-pluralized form of the *table* name (`toKebabPlural($foreignTable)`),
not from the real resolved module name — even though the correct module name was already computed
a few lines later via `resolveRelatedModule($col)` for the sibling `relatedModule` field.
`SelectController`'s `ModuleResolver::resolve()` converts a URL slug straight back to a module name
via a plain `Str::studly(str_replace('-', '_', $slug))`, with no pluralization awareness — so the two
derivations only coincidentally agreed when the table name's last word already looked plural (e.g.
`statuses`, `item_categories`), and silently diverged whenever it didn't.

Found live building real demo data: a real FK to `units_of_measure` (module `UnitsOfMeasure`, never
pluralized) produced `api_url: "/select/units-of-measures"`, which resolves back to a module
`"UnitsOfMeasures"` that doesn't exist — `SelectController` returned a 403 on every single picker
open. No PHPUnit coverage caught this before: it's a live-HTTP-only failure mode, and the picker's
own "no options" empty state looks identical to a genuinely empty target table, so nothing in the
generated output itself looks wrong.

Fixed by deriving the slug from the same resolved module name `relatedModule` already uses (kebab-
case of the real `Str::studly()`'d module name), for both the real-FK-constraint path and the
naming-convention-only fallback path — guaranteeing round-trip consistency with `ModuleResolver`'s
own conversion by construction, not by coincidence. The now-dead `toKebabPlural()`/`pluralizeSimple()`
pluralization helpers were removed rather than left orphaned. 1 new regression test (confirmed it
fails on the pre-fix code, reproducing the exact bug, before confirming it passes on the fix). Full
suite 816/816.

## v3.4.9 — 2026-08-21

### Fixed — a generated e2e edit-step could pick a field hidden via `defaultVisible: false`, timing out its own row-content check

`PlaywrightTestGenerator::pickEditField()` chose the field to edit-and-verify purely from
`features.frontend.edit.fields` (the first non-anchor scalar field), with no awareness at all of
`features.frontend.list.fields[].defaultVisible`. The generated edit step's own verification —
`row.textContent.includes(editedValue)`, checked against the record's `<tr>` — can only ever pass
for a field actually rendered as a `<td>` in that row. If the field `pickEditField()` picked
happened to be `defaultVisible: false`, the check waited the full 15s and timed out on every run,
with the real cause invisible from the error message alone.

Found live running a real 31-module `defaultVisible`/`filterableFields`/`group` display-config pass
(the Step 9 methodology from `docs/modules/bulk-generation.md`) against the Retail ERP project: 5 of
the resulting e2e failures (ProformaInvoices, Quotations, Sales, ItemBatches, TermTemplates) were
exactly this — each had hidden the specific field the generator happened to pick for editing.

Fixed by tracking which `list.fields` keys are actually list-visible (`defaultVisible !== false`)
and having `pickEditField()` prefer one of those over a hidden field, before falling back to its
previous field-order logic unchanged. For the residual edge case — every scalar edit field is
list-hidden, so no visible option exists at all — `buildEditBlock()` now skips the unwinnable
row-content check and falls back to the same lenient dialog-closed-only verification the non-scalar
field branch (select/checkbox/file-input) already used, rather than leaving a known dead end. 2 new
regression tests. Full suite 815/815 (was 813/813 — the increase is these two new tests).

## v3.4.8 — 2026-08-21

### Docs-only — comprehensive accuracy audit against v3.4.1-v3.4.7's real bugs

A systematic audit (three independent passes) of every page in `docs/` against the actual current
source, prompted by the same real-project session that produced v3.4.1-v3.4.7 — the goal being that
no future project should be able to hit the same bugs a correct doc would have prevented. No code
changed; every fix below is documentation, one bundled example fixture, and the bundled JSON schema.

**Corrected — actively wrong, not just missing:**
- `features-config.md`: `createSplash`/`editSplash` were described as gated on `constants` alone
  ("render a secondary form section") — corrected to the real AND condition (`constants` non-empty
  AND the matching key present) and the real behavior (pre-loads dropdown data, doesn't render UI).
- `features-config.md`: `splashKey` field-shape row incorrectly tied it to `constants[]` — it names a
  `splashData[].key` entry and has nothing to do with `constants`.
- `examples/actions.md`: `process()` shown as an instance method — corrected to `static`, matching
  the real stub and this session's own earlier fix to `docs/actions.md` (which this sibling page
  never received). Also corrected a false claim that bulk actions use a different (instance vs.
  static) calling convention than single actions — both are static.
- `examples/actions.md`: the `status_target` gotcha implied the referenced `constants` value must be
  numeric — the real bug is that the generated code always hardcodes a `status_id` **column** name,
  regardless of the constant's own type (string constants work identically).
- `examples/gotchas.md`: claimed generated PHPUnit/Playwright test files are "write-once" like the
  `inline_items` wrapper — they are not; they're overwritten by `--force` like any schema-driven
  file, which is the only delivery mechanism for v3.4.1-v3.4.5's own test-generator fixes reaching an
  already-scaffolded module.
- `module-config.md`: a warning box claimed `examples/module-config-full.json` "does not exist" —
  it exists, and was actually stale (`actions`/`delegations` as flat arrays instead of keyed objects,
  `seeder` as a flat row array instead of `{data, permissions}`) — fixed the file's actual content
  instead of the doc's false absence claim.
- `module-config.md`: `constants` described as feeding `createSplash`/`editSplash` dropdowns directly
  — it doesn't; `splashData` does. `constants` is purely a gate (one half of the AND condition above).
- `examples/inline-items.md`: claimed `inline_items` has no row on the main Module Config top-level
  table — it does, and links back here; the claim was circular/stale.

**Added — real mechanisms with no documentation at all:**
- `docs/actions.md`: `fields`, `wizard`, `confirm_step` were entirely absent from the Action Object
  Keys table despite being real, load-bearing config since v3.1.0/v3.2.0.
- `docs/actions.md`: the Generated Files table didn't mention `Form.vue`/`Page.vue` are also
  generated (not just the Service), or that all three are `writeFileOnce()`-protected since v3.1.7.
- `features-config.md`: `select` + `splashKey`/`splash_key` had no dedicated Field Types row — the
  exact combination v3.4.6 fixed for `actions[].fields`.
- `examples/gotchas.md`: four new entries — the v3.4.7 splash-gate trap, the v3.4.6 actions-field
  crash, the v3.4.1-v3.4.3 "create → view by default" e2e-spec trap, and `AppDialog`'s `persistent`
  prop making `Escape` a no-op (the root cause behind three of this cycle's fixes).
- `module-config.md` / bundled schema: `relations` (incl. `morphMany`, v3.4.0) and
  `skip_convention_check` were real, schema-validated keys with no prose documentation at all.
- Bundled `schema/module-config.schema.json`: `actions`/`delegations` were typed as JSON arrays
  (contradicting the docs' own keyed-object correction — an editor validating a *correct* config
  would flag it as an error); `seeder` was typed as a flat row array instead of `{data, permissions}`;
  `createSplash`/`editSplash`/`splashData` were entirely absent from `BackendFeatures`; `FrontendField`
  only declared camelCase `splashKey`, not the snake_case `splash_key` action fields also accept since
  v3.4.6; `Action` was missing `confirm_step`; eight real top-level keys (`connection`,
  `has_timestamps`, `has_soft_deletes`, `has_uuid`, `has_creator_updater`, `model_hand_maintained`,
  `inline_items`, `file_columns`) had no schema entry at all.
- `docs/README.md` / `docs/index.md`: no page anywhere linked to the changelog, and the highest
  version cited in any doc's prose was v2.51.0 — added Changelog rows to both landing pages' maps, an
  `examples/` row to `README.md`'s Guides table, four new rows to the "Common Agent Errors" table, and
  a version-tracking tip on the install instructions.

## v3.4.7 — 2026-08-21

### Fixed — every generated Create/Edit form with `constants` but no real splash config called an unregistered endpoint on every mount

`CreateFormGenerator`/`EditFormGenerator` gated their splash-plumbing (`refreshAndSet()`'s network
fetch, called unconditionally in `onMounted()`) on `!empty($config['constants'])` alone. The backend
route this hits (`RoutesGenerator.php`) requires **both** `constants` non-empty **and**
`features.backend.createSplash`/`editSplash` to be set before it registers the route at all. Since
`constants` is now populated on nearly every module (any activate/deactivate action needs it), while
real splash pre-load config is comparatively rare, this mismatch meant almost every generated
Create/Edit form in a real project called an endpoint the backend never registered — a 404 on every
single page load, masked behind a generic "Failed to load form data" toast that never threw, so no
automated test ever caught it (confirmed: present even in an otherwise fully green real-project e2e
run, user-reported live from watching the console output — "we kinda have some 404s, lots of them").

Fixed by requiring both conditions in `CreateFormGenerator`/`EditFormGenerator`'s own `$hasSplash`
computation, matching `RoutesGenerator.php`'s gate exactly. 1 new regression test; an existing test
fixture (`wizardConfigWithFkField()`) was also missing `constants` despite exercising the
"splash actually fires" path — added it, since that config could never have worked against a real
backend either. Full suite: 813/813 green (3 pre-existing warnings, unchanged).

## v3.4.6 — 2026-08-20

### Fixed — `actions[].fields` with `field_type: "select"` + `splash_key` crashed the generated form outright

`docs/modules/actions.md` documents `fields[]` as "same shape as `features.frontend.create.fields[]`/
`edit.fields[]`" — but a relation/FK-picker field written that way (`field_type: "select"` +
`splash_key`) rendered `:options="splash.account_ids"` in the generated action form, and `splash` is
never declared anywhere in that component. Every real user hit `TypeError: Cannot read properties of
undefined` the instant the form rendered.

Root-caused to 3 compounding bugs in the shared `BaseComponentGenerator` (used by Create/Edit/
inline_items/action forms alike):

1. `mapNewFormFieldsToLegacy()` (the actions-specific field-shape adapter) read `$field['splashKey']`
   (camelCase) — but the real, persisted, documented config key is `splash_key` (snake_case), so this
   was silently always `null` for every hand-authored action field.
2. Even with #1 fixed, `generateField()`'s `select`+`splashKey` resolution only ever emitted
   `ApiSelect2Field` when `splashKey` matched an entry in `features.backend.createSplash`/
   `editSplash` — a Create/Edit-only config block actions never populate. A field with `splash_key`
   but no such match silently fell through to the broken `splash.{field}` static-options branch.
   `inline_items` fields never had this problem in the first place — they resolve through a
   completely separate, **unconditional** runtime mechanism (`InlineItemsFieldRenderer.vue`:
   `field.type === 'select'` always → `ApiSelect2Field`, deriving its endpoint straight from
   `splashKey`, no splash-data lookup at all). Fixed `generateField()`'s two `select` branches to
   default to `api-select` the same way when no `createSplash`/`editSplash` match exists, deriving
   the endpoint via `Str::kebab(str_replace('_', '-', $splashKey))` (handles both the PascalCase
   module-name convention hand-authored `splash_key` values use, e.g. `"Accounts"`, and the
   snake_case convention auto-derived Create/Edit fields use, e.g. `"item_types"` — confirmed
   directly against a real Laravel install, since `Str::kebab()` alone only splits PascalCase and
   leaves existing underscores untouched). Applied the identical fallback to
   `generateFormFieldImports()`'s own, separate splash-match loop — without it, a field
   `generateField()` now correctly renders as `ApiSelect2Field` would still have no import for it.
3. Independently, in the same generated file: `NumberInputField` was referenced in the template but
   never imported (Vue only warns "Failed to resolve component," doesn't crash), and
   `:decimals="[[fieldDecimals]]"` was left as a literal, unsubstituted template placeholder token.
   `mapNewFormFieldsToLegacy()` drops `field_type` when mapping (keeps only the aliased `type`), and
   two downstream checks (`generateField()`'s decimals-substitution branch,
   `generateFormFieldImports()`'s import-collection switch) both compared against the *raw*
   `field_type` value (`'number'`, the canonical form `docs/modules/actions.md` documents) instead of
   the already-alias-resolved template type (`'number-input'`) — so neither branch ever fired for an
   action's own number field. Fixed both comparisons.

1 new regression test (`ActionComponentGeneratorTest`) reproducing the exact `PurchaseOrders.
recordPayment` shape (an `account_id` select+splash_key field and an `amount` number field), asserting
the crash-causing `splash.` pattern is gone, both fields resolve to their real components with correct
imports, and no unresolved `[[placeholder]]` tokens remain. Full suite: 812/812 green (3 pre-existing
warnings, unchanged).

## v3.4.5 — 2026-08-20

### Fixed — every remaining `page.keyboard.press('Escape')` dialog-close in generated e2e specs, silently broken for the same reason v3.4.1/v3.4.2 already fixed once

Found chasing the last real e2e failure bucket after v3.4.1-v3.4.4 (a nested-dialog stacking
symptom, confirmed live via a throwaway diagnostic script dumping `document.body.children`):
`tryFillSelectField()`'s own zero-options fallback closed the api-select picker popup via Escape —
but the picker is itself an `AppDialog` instance (same component the View dialog uses), and
`AppDialog`'s `persistent` prop defaults `true` with no override on the picker either, so Escape is
captured and `preventDefault()`'d, identically to the already-fixed View-dialog bug. Both the
Escape press and the follow-up close-wait were wrapped in `.catch(() => {})`, so the failure was
completely silent — the function returned `false` as if the field had been cleanly skipped, while
the picker dialog remained genuinely open (confirmed live: `data-state="open"`, not a
closing-animation remnant) and its overlay blocked every click on every field after it for the rest
of that create flow. This was the actual root cause of the "Items' own specs mysteriously time out
on later select2 fields" failures — not a reka-ui stacking/z-index bug as first suspected, though
investigating that hypothesis is what surfaced `role="dialog"` never existing on an overlay element
in the first place, a real gap in how the e2e helpers detect "is anything still open."

Same audit found 3 more identical `Escape`-based "leave nothing open" retry loops (delegation
create-flow cleanup, delegation-modal smoke test, action-modal smoke test) — none had ever actually
been capable of closing an `AppDialog`, only ever getting lucky when nothing was left open to begin
with. All 4 fixed identically: click the dialog's own Close button (`getByRole('button', { name:
'Close' })`), which v3.4.2 already established as the reliable mechanism. The remaining `Escape`
usages in the file were checked individually and left alone — they either close a Sheet/Drawer
component (a different primitive, not `persistent`-gated, and already verified working via an
un-caught assertion immediately after) or run right before a hard `page.goto()` navigation that
succeeds regardless of whether the Escape press did anything. 1 new regression test. Full suite:
811/811 green (3 pre-existing warnings, unchanged).

## v3.4.4 — 2026-08-20

### Fixed — select2 field label matching was a substring match, colliding with any overlapping field label

`fillSelectField()`/`tryFillSelectField()`/`fieldErrorLocator()` all located a field by
`page.locator('label', { hasText: labelText })` — Playwright treats a plain string `hasText` as a
case-insensitive *substring* match, so a module with both a "Status" field and any other field whose
own label merely *contains* "Status" (e.g. "Payment Status") hit a Playwright strict-mode violation
(locator resolved to 2 elements) on every single one of that module's e2e specs. Found live running
the generated suite against a real project: `PurchaseOrders`' create form has exactly this shape
(`status_id` labeled "Status", `payment_status` labeled "Payment Status").

Fixed by anchoring the match. Every label-rendering field component shares one template idiom
(`{{ label }} <span v-if="required">*</span>`), which Vue's whitespace-condense compiles down to a
text node that always ends in a trailing space, plus `*` when required — so `label.textContent()` is
never the bare label string, always `"labelText "` or `"labelText *"`. `hasText: new
RegExp('^' + escaped(labelText) + '\s*\*?\s*$')` matches exactly the intended field and nothing
whose label happens to end with the same word. 1 new regression test (constructs a real
PurchaseOrders-shaped config with both colliding fields, confirms each is filled under its own full
label and the stale substring-match shape is gone). Full suite: 810/810 green (3 pre-existing
warnings, unchanged).

## v3.4.3 — 2026-08-20

### Fixed — createFixtureRecord() (action/delegation smoke-test fixture setup) had the same stale post-create assertion as the CRUD spec's create step

v3.4.1/v3.4.2 fixed `PlaywrightTestGenerator::buildCreateBlock()` — the create step inside a
module's own `{module}-crud.e2e.js` — to account for `onCreated()`'s "create → view by default"
behavior. `buildFixtureCreateBody()` is a *separate* generated function (feeds `_fixtures.js`'s
`createFixtureRecord()`, shared by every action and delegation smoke-test spec to set up its own
fixture record) with its own independent post-submit assertion, never updated to match. Every
activate/deactivate/delegation smoke test in a real project hung 15s at fixture setup for exactly
the reason v3.4.1 already fixed for CRUD specs — found live running the full generated e2e suite a
second time, after the v3.4.2 fix confirmed the dominant CRUD-spec failure class was gone and a
smaller, distinct class of failures (all action/delegation smoke tests, zero plain-CRUD specs)
remained.

Fixed identically to v3.4.2's corrected approach from the start: assert the View dialog opens,
close it via its Close button (`getByRole('button', { name: 'Close' })`), not Escape. One
difference from the CRUD-spec fix: `_fixtures.js` imports nothing from `@playwright/test`, so the
"a dialog is expected to be open" assertion is a plain `if (...) throw new Error(...)`, matching
this function's own existing convention (e.g. its uuid-capture check), not `expect()`. 2 new
regression tests. Full suite: 809/809 green (3 pre-existing warnings, unchanged).

## v3.4.2 — 2026-08-20

### Fixed — v3.4.1's own create-step fix used Escape, which the View dialog blocks by design

v3.4.1 closed the View dialog that `onCreated()` opens after a successful create by pressing
`Escape`. That passed the unit tests (they only assert against the generated code, not a live
browser) but reproducibly hung for 15s on *every single module* the first time the generated suite
was actually run end-to-end: `AppDialog.vue`'s `persistent` prop defaults to `true`, and the View
dialog's own usage never overrides it, so `@escape-key-down` captures Escape and
`preventDefault()`s it on every press — the dialog can never close that way. Confirmed live: the
create request itself always succeeded and the View dialog always opened correctly; only the
close-and-continue step was broken.

Fixed by clicking the dialog's own Close button instead — `page.locator('[role="dialog"]').getByRole('button',
{ name: 'Close' }).click()`. reka-ui's `DialogClose` (used by every generated View dialog) has no
`data-testid`, but its accessible name is always literally "Close" via a visually-hidden span, so
`getByRole` is reliable across every module regardless of its own fields/content. 2 existing
regression tests updated to assert the Close-button click instead of the Escape press. Full suite:
807/807 green (3 pre-existing warnings, unchanged).

**Lesson**: a generator unit test that only inspects generated *text* cannot catch a real DOM/
interaction bug in what that text does at runtime. The only real verification is running the
generated suite against a live app.

## v3.4.1 — 2026-08-20

### Fixed — generated CRUD e2e create step never passes on a module with View enabled

Every `PlaywrightTestGenerator`-generated `{module}-crud.e2e.js`'s create step asserted
`!document.querySelector('[role="dialog"]')` after clicking submit — but `CrudListPanel.vue`'s
`onCreated()` (v3.2.2, "create → view by default") opens the new record's own View dialog
immediately whenever a View component is wired, so a dialog is *always* still present after a
successful create and that assertion could never pass. `PlaywrightTestGenerator` was never updated
to match when that feature shipped.

Found live running the generated suite for the first time in a real project (Retail ERP
fresh-redesign, 2026-08-20) — every single CRUD spec failed at the same step, across every module
regardless of restriction/feature shape, timing out waiting for a dialog count of zero that never
occurs. Confirmed the create request itself succeeds (the record is genuinely persisted, timestamped
exactly when the test ran) — this was a pure test-generation bug, not a real application defect.

Fixed by gating the create step's post-submit assertion on `$this->hasView`, mirroring
`onCreated()`'s own `props.viewComponent && payload?.[props.rowKey]` guard exactly: when true, expect
a dialog to *remain* present (now the View one) and close it via `Escape` before continuing; when
false (view disabled), keep the original "no dialog at all" assertion, since `onCreated()` itself
falls back to close-and-refresh-only in that case. 2 new regression tests. Full suite: 807/807 green.

## v3.4.0 — 2026-08-19

### Changed — reverse morph relations are now config-driven, not spliced into a generated file

v3.3.0 gave a morph TARGET module (e.g. `Vendors`, for `Payments.payable`) a way to get a real
`payments(): MorphMany` relation back, via a new `ModelRelationInjector` class that spliced the
method into an already-generated `VendorsModel.php` as a second pass, after both modules existed on
disk. It worked, but had no protection against a later regenerate: a plain `make:module Vendors
--force` rewrites the whole Model file fresh from `model.stub` and knows nothing about the spliced
method, silently wiping it. Found live, the first time a consuming project's own post-scaffold
refinement pass (icons/menu/CRUD-selectivity) touched a module with an injected relation.

Replaced entirely with a config-driven approach, extending the **existing** `relations.hasMany[]`/
`relations.belongsToMany[]` manual-relations escape hatch (`ModelGenerator::
generateManualInverseRelationships()`) with a sibling `relations.morphMany[]`:
```json
{"relations": {"morphMany": [{"module": "Payments", "method": "payments", "morphName": "payable"}]}}
```
Generated as part of the target module's own normal `ModelGenerator` pass — no second pass, no
cross-module file reach-in, and (for consuming apps whose `mergePersistedFields()`-style regenerate
guard already carries `constants`/`delegations`/`actions` forward — SYSTEM_SHELL's own
`ModuleScaffolder.php` updated in the same commit) survives every future regenerate the same way
those other hand-authored keys already do.

`ModelRelationInjector` (class + its dedicated test file) is removed — `relations.morphMany`
replaces it entirely, nothing else in this package referenced it. `model.stub`'s now-unused
`// [[extraRelations]]` marker removed too (a cosmetic diff in every future generated Model — no
functional change for anyone who wasn't using the removed class directly).

`schema/module-config.schema.json` gained a real `relations` definition (previously entirely
undocumented, despite `hasMany`/`belongsToMany` existing since before this session) covering all
three relation kinds.

3 new regression tests (throws-on-unresolvable-module, resolves-once-registered, and — the one that
actually matters — **survives a second `generate()` call with the same config**, the property the
old splice-based approach never had). Full suite: 805/805 green.

## v3.3.2 — 2026-08-19

### Fixed — `PhpUnitTestGenerator` produced type-blind fixtures for a module with no create/edit fields

`fieldsSource()`'s fallback branch (used when a module has neither `create` nor `edit` enabled — an
append-only ledger like Payments, restricted to list+view) hardcoded `'required|string'` for every
column regardless of its real type, since there was no `features.backend.create/edit.fields[]`
validation-rules string to read from. `buildFieldValueLiteral()` dispatches almost entirely off
substrings in that rules string (`integer`/`numeric`, `boolean`, `date`) — a `decimal` column got the
generic `'Test Field ' . uniqid()` string literal, which then failed inserting into the database at
the SQL level (this fixture bypasses validation via a direct `Model::create()` call, so it wasn't
even a 422 — a hard `SQLSTATE[HY000]: ... Incorrect decimal value`). Found live building Payments'
generated suite once Step 9's CRUD-selectivity review restricted it to list+view. Same bug class as
v3.1.6's datetime fix, different type category and call site.

New `fallbackRuleForColumnType()` derives a type-accurate rules string from the column's own real
`type` (decimal/float/double → numeric, every integer variant + foreignId → integer, boolean →
boolean, date/datetime/timestamp → date, json → array) instead of a single hardcoded default. 1 new
regression test. Full suite: 806/806 green.

## v3.3.1 — 2026-08-19

### Fixed — a morph-filtered delegation's own generated PHPUnit test 404'd against its own generated code

Found live building Vendors' reverse Payments delegation (the first real use of v3.3.0's
`morphFilter`) — `PhpUnitTestGenerator`'s view/edit/delete delegation test builders create their
`$related` fixture row scoped only by `filterKey` (`['payable_id' => $parent->id]`), never learning
about the second `morphFilter` where-clause `DelegationServiceGenerator` adds at runtime. The fixture
row exists but isn't scoped to the type the real query filters on (`payable_type = 'vendor'`), so the
auto-generated `test_can_view_payments_delegation_item` 404'd — a false failure against entirely
correct runtime code, not a real defect in the generated Service.

New `buildDelegationFixtureFields()` widens the factory `create([...])` call to also set the morph
type column when `morphFilter` is present, shared by the view/edit/delete test builders. 1 new
regression test. Full suite: 805/805 green.

## v3.3.0 — 2026-08-19

### Added — a morph target can now get a real reverse relation + a filtered delegation tab

Until now, `morphs[].targets[]` only ever wired the OWNING side (e.g. `Payments::payable(): MorphTo`)
— the target side (e.g. `Vendors`) got nothing back: no `payments(): MorphMany` relation, and no way
to show that vendor's payment history as a tab on its own view page, short of hand-writing both.
Documented as a known gap in `docs/examples/morphs.md`'s "What you still do NOT get" section; now a
real, opt-in capability.

Two independent pieces, since they solve different halves of the problem:

- **`DelegationServiceGenerator` gained `morphFilter`** — a new, optional sibling key to a
  delegation's existing `filterKey` (`{"column": "payable_type", "value": "vendor"}`). When set, every
  one of the ~8 query-building methods (`list`/`view`/`delete`/`deleteCheck`/`bulkAction`/`import`/
  etc.) appends a second, constant `->where($column, $value)` alongside the ordinary
  `->where($filterKey, $parent->{$parentIdField})` scope. Without it, a plain single-column filter on
  `payable_id` alone would also match a PurchaseOrder or Expense whose id happens to numerically
  collide with the vendor's — `payable_id` is shared across every polymorphic owner type. An ordinary
  (non-morph) delegation that never sets `morphFilter` is byte-identical to before — this is
  additive, not a behavior change for the existing mechanism. This is the whole fix needed for the
  delegation TAB itself: the frontend side (`DelegationTabComponentGenerator`/
  `CustomFeatureTabComponentGenerator`) needed no changes at all — filtering happens entirely
  server-side, and `filterKey` was already only ever used frontend-side for column-hiding/form-hiding,
  neither of which fires when a morph-reverse delegation is correctly scoped to `list`+`view` only.
- **New `ModelRelationInjector`** — the one deliberately cross-module generator in this package.
  Every other generator only ever writes inside its own module's directory; this one reaches into an
  ALREADY-GENERATED sibling module's own Model file (e.g. `VendorsModel.php`) to splice in a real
  `payments(): MorphMany` method — something nothing generating the OWNING module (`Payments`) could
  ever place there, since `ModelGenerator` only emits relations derived from its own config. Requires
  the target module to already exist on disk (unlike `morphTo()` generation itself, which is
  order-independent), so it's meant to run as a separate pass after a full scaffold run completes, not
  interleaved with generation. Idempotent — checks for the relation method's own signature before
  inserting, so a repeated `--force` re-scaffold never duplicates it. `model.stub` gained a permanent
  `// [[extraRelations]]` marker (right after `[[relationships]]`) as the splice point every future
  injection targets, re-emitting itself after each insertion so a second, different target relation
  (e.g. both `payments()` and `notes()` on the same model) has somewhere to go.

Also fixed, found while writing this: `schema/module-config.schema.json`'s `morphs[].targets[]` was
stale — plain `{"type": "string"}` items, not matching either the real shape `ModelGenerator`/
`docs/examples/morphs.md` actually use (`alias`/`model`/`module`/`label`/`option_label` objects) or
`scaffold-blueprint.schema.json`'s own already-correct version. Both schemas now agree, and both
gained the new `targets[].delegate: {enabled, relation_name, tab_label}` key documenting this
capability at the config level (workflow bookkeeping for a scaffold-domain-style caller — the actual
generation is driven by explicitly calling `ModelRelationInjector`/passing `morphFilter`, not by this
key being read automatically anywhere in this package; the consuming app's own orchestrator is
expected to read it and drive both pieces).

6 new regression tests (`DelegationServiceGeneratorTest`, `ModelRelationInjectorTest`, including a
real `php -l` syntax-validity check on the spliced Model file and an idempotency/multi-target check).
Full suite: 804/804 green.

## v3.2.2 — 2026-08-19

### Added — create → view by default, unconditionally, for every module

A successful Create now navigates to the newly-created record's own View instead of returning to
the List — both the page path (`create/page.stub`'s form, via `handleCreated`) and the modal path
(a create dialog opened from a list page). Neither path did this before: the page path always
`router.push(props.cancelLink)`'d back to `/{moduleRoute}/list`, and the modal path
(`CrudListPanel.vue`, a consuming app's own shared base component, not part of this package) just
closed the create dialog and refreshed the list.

`{Module}CreateService::process()` already returns the full `$model->fresh()` (see v3.1.7/v3.2.0's
own fix for the same underlying `Model::create()`-doesn't-reflect-DB-default-uuid gap), so
`response.data.uuid` was already available in the create response — `handleCreated(id)` just
wasn't looking at it. `handleCreated(id, uuid)` now redirects to `/{moduleRoute}/{uuid}/details`
(the real generated View route) when the module has `features.frontend.view` enabled, falling back
to the original `cancelLink` behavior when it doesn't — a module with no View page must never
redirect to a route that was never scaffolded. No config toggle: this is the new unconditional
default for every module, confirmed as the explicit choice over an opt-out flag.

2 new regression tests (`CreateFormGeneratorTest`): view-enabled redirects to the record's own
View; view-disabled falls back to the list. Full suite: 798/798 green.

## v3.2.1 — 2026-08-19

### Fixed — `ListPageGenerator` no longer hardcodes the Create/Edit/Delete/View form imports

`{Module}ListPage.vue` unconditionally imported and wired all four of `{Module}CreateForm`/`EditForm`/`DeleteForm`/`ViewModal` into `<CrudListPanel>`, regardless of whether `features.frontend.{create,edit,delete,view}` was actually present in config. Omitting an operation (e.g. `delete` on an append-only ledger table where deleting a row would corrupt a running balance) never stopped the file being imported — `CreateFormGenerator`/`EditFormGenerator`/`DeleteFormGenerator`/`ViewModalGenerator` correctly skip writing the file when their operation is disabled, so the list page's own unconditional `import {Module}DeleteForm from './Components/{Module}DeleteForm.vue'` pointed at a file that was never generated, breaking the Vite build the moment anyone actually relied on the omission.

`ListPageGenerator::generateCrudOperationBlocks()` now builds the `<CrudListPanel>` prop lines and the component imports per-operation, only for whichever of create/edit/delete/view are actually enabled — `CrudListPanel.vue`'s `createComponent`/`editComponent`/`deleteComponent`/`viewComponent` props were already optional, so nothing else needed to change. `list/page.stub` gained two placeholders (`[[crudPanelOperationProps]]`, `[[crudFormImports]]`) replacing the four hardcoded import lines and the four hardcoded prop blocks. 3 new regression tests confirm: a fully-CRUD module still emits all four exactly as before, omitting `delete` drops only that operation's import/prop (the other three unaffected), and a list-only module emits none of the four. Full suite: 796/796 green.

## v3.2.0 — 2026-08-18

### Added — a "Review & Confirm" step gates submit on every wizard, and is opt-in for flat forms too

`wizard.confirm_step` (Create/Edit/Actions) or the sibling top-level `confirm_step` (any form, wizard or not) appends a checkbox that must be checked before the real submit button enables. For wizard mode this is a real trailing step: an auto-generated summary of every earlier step's plain fields (<code v-pre>`{label}: {{ form.key }}`</code>) and inline_items blocks (`{label}: N item(s)`, the only thing genuinely knowable about an inline_items array without per-domain logic), plus a stable HTML-comment extension point a hand-edited action Form.vue can fill in with a custom step's own summary (e.g. Receive PO's line items). For a flat form, it's just the checkbox appended after the fields — no Stepper, nothing else changes.

**Defaults are asymmetric on purpose**: ON for every wizard (`wizard.enabled: true` with no `confirm_step` key at all still shows it — multiple steps mean the user never sees everything at once, so a review earns its keep), OFF for flat forms (everything's already visible on one screen; forcing a checkbox onto every 2-field edit just becomes something people stop reading). Either shape overrides via an explicit `confirm_step.enabled`.

Bug found and fixed while building this: `generateWizardSteps()`/`generateWizardStateBlock()` re-check `confirm_step.enabled` internally with their own `?? false` default, so a caller passing the ORIGINAL (still-empty) config object through a `$requiresConfirmation ? $confirmStepConfig : []` gate silently lost an on-by-default decision the moment the config key was omitted — the internal check saw no `enabled` key and defaulted back to false. Fixed by having every caller merge its resolved decision back into the config object (`$confirmStepConfig['enabled'] = $requiresConfirmation`) before passing it down, so the internal re-check always sees an explicit value. Caught by a dedicated new test asserting the confirm step actually renders with no config key present — the existing wizard tests all still passed throughout because none of them asserted step *count* or absence, only presence/ordering of specific strings.

Second gap found live: an FK select field's `form.key` only ever holds the bare id (e.g. `10`), which is meaningless in a review screen (`Vendor: 10`, `Status: 7`). `ApiSelect2Field` already emits `@selected-object` with the full chosen option, just unused until now — `generateField()` now wires that event into a parallel `fieldLabels` ref (only for `api-select`/`api-select-inline` templates, and only when a wizard's confirm-step summary is actually being fed), and the summary line prefers <code v-pre>`fieldLabels.key || form.key`</code>. `generateWizardSteps()`'s return grew a 3rd tuple element (`$hasFkFieldLabels`) so callers only declare `fieldLabels` when at least one FK field needs it — declaring it unconditionally would trip the consuming app's `noUnusedLocals` on every confirm-enabled wizard that happens to have zero FK fields.

Third gap, found live testing the Edit form specifically: `@selected-object` only fires on a LIVE user pick, so a value that arrives already populated from the loaded record (the normal edit case — most fields already have values) never triggers it. Fixed without touching the ViewService generator at all: every FK column's `belongsTo()` relation is already eager-loaded and returned as its own key in the View response (`BaseServiceGenerator::generateEagerLoadRelationships()`, e.g. `response.data.vendor.name`) — `EditFormGenerator` now seeds `fieldLabels` from that same data right after the loaded-record merge (`generateFieldLabelsSeedBlock()`, relation-name derivation mirrors `ViewServiceGenerator::extractInlineItemFkFields()` exactly). Placed with no forced line break in the stub so the common case (no FK fields tracked) stays byte-identical.

16 new regression tests across `CreateFormGeneratorTest`, `EditFormGeneratorTest`, `ActionComponentGeneratorTest`. Full suite: 793/793 green.

## v3.1.7 — 2026-08-18

### Fixed — an action's generated Service and Form/Page were force-overwritten on every regenerate, discarding hand-written logic

Found while building the first real action with genuine business logic on top of the wizard-mode capability (v3.1.0). An action's whole purpose — unlike Create/Edit's pure-CRUD services — is custom logic: the service stub's own "Add your custom logic here" comment assumes a developer fills it in by hand (`UsersForceResetPasswordService.php` is the real, shipped shape this grows into), and its form routinely needs hand-added markup a step's `fields[]`/`wizard` config can't express (a nested repeater, a fetched-from-another-module pre-fill, bespoke validation). Both `ActionServiceGenerator::generate()` and `ActionComponentGenerator::generateAction()`'s Form.vue/Page.vue writes used plain `writeFile()`, which force-overwrites whenever `$force` is true — and every consuming app's `ModuleGenerationService` sets `force(true)` on every generator it constructs except migrations. So any hand-written action logic was silently wiped back to the empty stub the next time its module regenerated for any unrelated reason (a schema tweak, a second action, an edit-wizard config change) — the same bug class already fixed for the inline-items wrapper component (v2.x).

Both now use `writeFileOnce()` instead: generated once, then left alone by every future regenerate regardless of `--force`, exactly like the inline-items wrapper and `LineItemsView` components. 4 new regression tests (`ActionServiceGeneratorTest`, 2 new tests on `ActionComponentGeneratorTest`) confirming a forced regenerate does not clobber hand-edited content. Full suite: 777/777 green.

## v3.1.6 — 2026-08-18

### Fixed — a datetime/timestamp column with no `date` in its validation rules got a broken test fixture value

Found live generating the Inventory cluster's `StockTransfers` module (11-module cluster, 13 test failures isolated entirely to this one). `PhpUnitTestGenerator::buildFieldValueLiteral()`'s date/datetime branch only triggered when the column's own validation `rules` string contained the substring `"date"`. That's usually true, but not always: V1's own bulk-gen wizard-port (`BackendFeatureDeriver::validations()`, a documented "faithful port" of `generator.ts`'s own `generateColumnsValidations()`) never emits a `date` rule at all for `datetime`/`timestamp` columns — only for a column whose type is *exactly* `date` — so a genuinely datetime-typed column (`picked_up_at`, `delivered_at`, `confirmed_at`, `issue_otp_expires_at`, all `["nullable"]`-only rules) fell through to the generic `'Test Field ' . uniqid()` string literal, which the model's real datetime cast then failed to parse (`Carbon\Exceptions\InvalidFormatException`).

Now also checks the column's own real declared type (`findColumnConfig()`, same source `isDateTimeField()` already uses) directly, independent of what the rules string says — closing this regardless of which upstream deriver produced (or omitted) the rule. 1 new regression test, full suite 773/773 green.

## v3.1.5 — 2026-08-18

### Fixed — `inline_items[]`, the documented/primary mechanism, was more limited than the older `field_type: 'inline-items'` pattern it's meant to replace

Found via an independent review of this whole subsystem. Two gaps, both closed:

- **Missing component-level config knobs.** `emptyMessage`, `viewModalTitle`, `deleteMessage`, and `canAdd`/`canEdit`/`canView`/`canDelete` are all real `InlineItemsComponent` props with zero config path from `inline_items[]` — only reachable by hand-editing the write-once wrapper. `generateInlineItemsBlock()` now wires `empty_message`/`view_modal_title`/`delete_message`/`can_add`/`can_edit`/`can_view`/`can_delete` from config, only emitted when actually set/false (byte-identical output otherwise).
- **Missing per-field options.** `buildInlineItemFieldsJs()` never read `readonly`/`disabled`/`default`/`input_type`/`option_label`/`option_value`/`option_subtitle_field`/`options` — all of which the older, "weaker" mechanism already passed straight through. Brought to parity, using this method's own existing snake_case config-key convention (`table_width`, `show_in_table`, `col_span`, `placeholder` were already supported but undocumented in the module-builder's own TS types — also fixed).

4 new regression tests, full suite 772/772 green.

### Fixed (companion, shared frontend component, not generator-engine) — a local (non-API) select field for `inline_items` silently never rendered

`InlineItemsFieldRenderer.vue` imported `Select2Field` but never actually used it anywhere in the template — `field.type === 'select'` always fell through to the API-driven `ApiSelect2Field` branch, so `field.options` (a real, declared `InlineItemField` prop) was dead on arrival: a config-driven `options: [...]` field would render nothing usable without an `api_url`/`splash_key`. Added a `Select2Field` branch, claimed only when `field.options` is actually present, so every existing `splash_key`/`api_url`-driven `'select'` field is completely unaffected.

### Other findings from the same review, also fixed

- The wrapper's own TODO comment named only `dynamicDisabled`/`showField`/`render` as real per-field hooks — `dynamicLabel`/`dynamicFilters` are equally real and wired, just never mentioned. Comment updated.
- `src/Generators/Templates/mobile_app/fields/inline-items.stub` was genuinely dead code — both mobile `CreateFormGenerator`/`EditFormGenerator` load `'fields/inline-items-wrapper'` from the `'frontend'` template group instead, so nothing ever read this file, and its content had drifted into a completely different (pre-wrapper-component) shape. Deleted.
- `docs/examples/inline-items.md` never documented `variant`/`totals` (a real, already-shipped feature since before this release) or any of the field-level/component-level options above. New "Table variant, running totals, and other component config" section added, with a per-field options table.

## v3.1.4 — 2026-08-18

### Fixed — an inline_items row's own FK field (e.g. `item_id`) always displayed as a raw numeric id, never a name

Found live on PurchaseOrders' View/Edit modals and its Details Overview page. Two compounding gaps, both in the read path (the write/create path was always correct):

- **`ViewServiceGenerator::generateInlineItemsLoad()`** never eager-loaded a child row's own FK relations before serializing. The frontend's `InlineItemsComponent` only ever resolves a select/api-select field's display label from an in-memory `{field}_object` the picker itself attaches at selection-time (`handleSelectedObject()`) — never persisted server-side (not a real column) — so the moment a record was reloaded from the API (View, or Edit reusing View's own data load), every child row's FK field fell back to its raw id. Fixed: for every inline_items field typed `select`/`api-select`, the generated `afterFetch()` now eager-loads that field's own `belongsTo()` relation (derivation mirrors `ModelGenerator::deriveRelationshipMethodName()` exactly) and re-attaches it under the exact `{field}_object` key the frontend already expects — zero frontend changes needed. 2 new regression tests.
- **`writeLineItemsViewComponent()`'s name-field heuristic** (the separate component backing the Details Overview page, distinct from `InlineItemsComponent`) only recognizes literal name-shaped field aliases (`name`/`product_name`/`item_name`/`description`/`title`). A module like PurchaseOrderItems has none of those — only `item_id`/`quantity`/`unit_cost` — so it fell back to the first configured field, which is very often the row's own primary FK reference, and rendered the raw id directly. Fixed: when the resolved name field is itself select/api-select-typed, the generated mapping now prefers that field's own `{field}_object.name` (populated by the fix above), falling back to the raw value only if the object is ever absent. 2 new regression tests (this method had zero test coverage before).

Full suite 770/770 green. Live-verified end-to-end on PurchaseOrders' real records — item names now resolve correctly in the View modal, Edit modal, and Details Overview page.

## v3.1.3 — 2026-08-18

### Fixed — inline_items block had no bottom padding in modal mode

Found live on PurchaseOrders' modal edit form. Non-modal mode gets bottom padding for free from `generateInlineItemsBlock()`'s inner content div's own unconditional `p-4` (all sides) — but modal mode's outer wrapper only carried `px-4 mt-2` (horizontal padding + a top margin, nothing below). Since the inline_items block is typically the last thing before a modal form's footer bar, its "+ Add Item" button rendered flush against the footer with zero breathing room. Changed the modal branch to `px-4 pb-4 mt-2`. 1 new regression test, full suite 766/766 green.

### Fixed (companion, shared frontend component, not generator-engine) — `LineItemsList.vue`'s per-row and grand totals silently showed 0.00 when a module's `total`/`line_total`-style field wasn't wired

Not a generator-engine bug (`LineItemsList.vue` is a static base-template component `line-items-view-wrapper.stub` imports, not generated output) — noted here because it surfaced from the same live PurchaseOrders investigation and is exactly the kind of thing this changelog exists to track. `LineItemsList.vue` used `item.total` directly for both the per-row display and the `grandTotal` sum; if the LineItemsView wrapper's own field-mapping heuristic never found a total-shaped field to map (e.g. a computed field added by hand after the wrapper's own one-time scaffold), `item.total` stayed `null` and every total rendered as 0.00 even though `quantity`/`unitPrice` were both present and correct. Now falls back to `quantity * unitPrice` whenever `total` is unset.

## v3.1.2 — 2026-08-18

### Fixed — wizard mode's Stepper and step fields rendered flush against a modal's edge

Found live on PurchaseOrders' modal create form (the wizard capability's own first real test case, see v3.1.0). `generateWizardSteps()`'s outer wrapper bundled its `p-4` padding together with the `!modal` border chrome (`!modal ? 'rounded-md border overflow-hidden p-4' : ''`), and a step's own fields grid — unlike `generateFormSection()`'s equivalent grid, which bakes `p-4` in unconditionally — had no padding of its own. In modal mode the wrapper's class resolves to `''`, so the Stepper and every step's fields rendered with zero padding, flush against the dialog edge, while the "You have N drafts" banner directly above it (a separate, already-correctly-padded element) stayed properly indented.

Fixed to match the already-working convention `generateFormSection()`/`generateInlineItemsBlock()` use: the outer wrapper stays chrome-only (the border genuinely is modal-conditional), padding is unconditional and lives on the content itself — the Stepper gets its own `p-4 pb-0` wrapper, each step's fields grid now carries `p-4`. 1 new regression test, full suite 765/765 green. Live-verified via headless Playwright screenshot before/after on PurchaseOrders' real create modal.

## v3.1.1 — 2026-08-18

### Fixed — two `MigrationUpdateGenerator` bugs breaking any UPDATE migration that makes a unique/non-string column nullable

Found live while building the first real wizard-mode create flow (`PurchaseOrders`, see v3.1.0), turning `po_number` (unique `string`) and `status_id` (`foreignId`) nullable via a normal config edit + regenerate.

- **Empty-string default sentinel emitted as a literal value.** `''` is this codebase's own "no default configured" sentinel throughout the pipeline (`ColumnTypeMapper`, `Pass1ConfigAssembler`'s `'default' => $c['default'] ?? ''`). `MigrationGenerator` (the CREATE-table path) already correctly excludes it (`$default !== null && $default !== ''`) — `MigrationUpdateGenerator::buildColumnSchema()` only checked `!== null`, so any column transitioning nullable via an UPDATE migration emitted a literal `->default('')` regardless of type. Harmless for a string column, but a hard `SQLSTATE[42000]` (1067, "Invalid default value") for `status_id` (`foreignId`/integer).
- **Duplicate unique index on `->change()`.** A chained `->unique()` on `->change()` compiles as a *separate* `ADD UNIQUE` statement — Laravel doesn't check whether one already exists at the DB level. `buildColumnSchema()` unconditionally re-chained `->unique()` for any column flagged unique in the new schema, even when it was **already** unique before this change (only an unrelated property, e.g. `nullable`, actually changed) — duplicating `po_number`'s already-existing index name and failing with `SQLSTATE[42000]` 1061 ("Duplicate key name").

`buildColumnSchema()` now also excludes `''` from the default check (matching `MigrationGenerator`'s existing pattern exactly), and only chains `->unique()` when the column is genuinely *becoming* unique (comparing against the old column's own `unique` flag) — not when it already was. 4 new regression tests, full suite 764/764 green.

## v3.1.0 — 2026-08-18

### Added — multi-step "wizard" mode for create/edit forms and actions

A create/edit form's fields, or an action's own form, can now optionally be presented as multiple steps instead of one flat form — narrower and different from the old cross-module `WizardGenerator` (part of the "UX Builder" subsystem removed as a breaking change in v3.0.0): steps live *within* one module's own form/action, not chained across separate modules' pages.

- `features.frontend.{create,edit}.wizard: <code v-pre>{enabled, submission_mode, steps[]}</code>` — each step references already-defined field keys (`field_keys`, matching `features.frontend.{create,edit}.fields[].field`) rather than duplicating field definitions. A step's `field_keys` also matches against `inline_items[].key`, so an inline-items block (e.g. line items) can be its own step, rendered via the existing `generateInlineItemsBlock()` machinery.
- `GeneratorAction.fields[]` / `GeneratorAction.wizard` — actions previously had **zero real field-authoring support**: the wizard UI's own "Frontend Config" tab was a dead stub (bound to a permanently-undefined model-value, nothing typed there ever persisted), and `ActionComponentGenerator` always emitted a permanently-empty `form = ref({})` with a hand-written "Add your form fields here" comment. Actions now reuse the same `generateFormFields()`/`generateWizardSteps()` building blocks Create/Edit already use.
- **Final-submit-only this release**: steps gate which fields are visible; one real submit at the end, identical backend/API shape to a non-wizard form — `generateFormFields()`/`generateSubmitCall()` are completely untouched. `submission_mode: 'per_step'` (a true partial-commit backend primitive) is reserved in the schema but not implemented — no existing precedent anywhere in this codebase family to build it against safely yet.
- Wizard mode's `goNext()` calls the SAME `saveDraft()` already generated into every Create/Edit form (when drafts are enabled) — an immediate save on step-complete, on top of the existing debounced <code v-pre>watch(form, ..., {deep:true})</code> autosave that already covers every keystroke regardless of wizard mode. No new backend endpoint, no new composable. Actions have no draft mechanism, so their wizard `goNext()` never references it.
- Uses the real, already-theme-aware `Stepper.vue` component already shipped in the base frontend template (`components/ui/stepper/`) — no new component needed.
- Additive/opt-in only: omitting `wizard` (or `enabled: false`) generates byte-identical output to today, verified via new regression tests. `composer test`: 760/760 green.

## v3.0.8 — 2026-08-17

### Fixed — nullable `location_id` rows silently excluded from location-scoped list results

Every generated `ListService` runs queries through `LocationContextService::applyLocationFiltering()`, which constrains results with `whereIn(location_id, $accessibleIds)` — and NULL never matches `whereIn()` under normal SQL semantics, so any row with `location_id: null` (an intentional "applies everywhere" state on a nullable column) silently vanished from list results for any user with assigned locations. The trait already had an opt-out (`$locationScopeIncludesNull = true`, added 2026-08-08), but no generator ever emitted it — confirmed live: zero modules anywhere declared it, including ones with a genuinely nullable `location_id` column. Found via a real PHPUnit failure surfaced by regenerating an existing module (ItemPrices) on the retail-ERP demo fixture.

`ListServiceGenerator` now auto-declares `protected static bool $locationScopeIncludesNull = true;` on the generated `ListService` whenever the module's own `location_id` column is nullable — a nullable column is exactly the signal that NULL is expected there, not missing data. Non-nullable `location_id` (or no `location_id` at all) generates unchanged. 4 new regression tests, full suite 747/747 green.

## v3.0.7 — 2026-08-17

### Fixed — `eagerLoadRelationships` correction missed a second broken-name shape (snake_case, column-derived)

`correctEagerLoadRelationshipName()`'s safety net (added v3.0.0-era, 2026-08-15) only recognized eager-load relation names shaped like the *related module's* name, garbled by V1's frontend (`"statuse"` for a `Statuses` relation). Found live on the retail-ERP demo fixture: a column like `default_price_list_id` produces a relation name derived correctly from the *column* itself but left snake_case (`"default_price_list"` instead of the real Eloquent method `defaultPriceList`) — a shape the safety net didn't check for, so it passed through unchanged and threw `Call to undefined relationship [default_price_list]` the moment ListService/ViewService eager-loaded it.

Generalized the check: in addition to the two known relatedModule-derived shapes, any relation name whose `Str::camel()` form equals the correct column-derived name is now corrected too — catching this shape and any future case-only variant regardless of which broken path in the frontend produced it. `composer test`: 743/743 green.

## v3.0.6 — 2026-08-17

### Fixed — modal-rendered `inline_items` collapsed the "Main Details" section into its own tiny scrollbox

`generateFormSection()`/`generateDefaultFormSection()` made each section its own `flex flex-col flex-1 min-h-0` box with an internally `overflow-y-auto` fields grid, so it could self-scroll inside a modal (`AppDialog`). That only works while a section is the *sole* flex child of `<form>` — as soon as `generateInlineItemsBlock()` spliced an `inline_items` `<Card>` in as a later sibling (or a module had more than one section), flexbox's default `min-height: auto` let that sibling keep its full natural height while the section — the only child willing to shrink, because it opted into `min-h-0` — absorbed *all* of the squeeze and collapsed into a tiny scrollbox, leaving the Items card sitting untouched below it. Confirmed live on Expenses' Create modal (has `inline_items`).

Fixed by moving scroll ownership off individual sections entirely: `create/form.stub`/`edit/form.stub` now wrap `[[draftBannerBlock]]` + `[[formSections]]` + `[[inlineItemsBlock]]` together in one `flex-1 min-h-0 overflow-y-auto` region (mirrors `AppDialog.vue`'s own header/body-scroll/footer split), with `[[formFooter]]` as a `shrink-0` sibling after it. Sections themselves render as plain, non-scrolling blocks in modal mode. `composer test`: 740/740 green.

### Fixed — inline_items rendered as a mismatched `<Card>` instead of the plain-div section every other part of the form uses

`generateInlineItemsBlock()` wrapped each `inline_items` entry in a shadcn `<Card>`/`<CardContent>` — its own shadow/rounded/padding rhythm doesn't match the flat `rounded-md border` + header-bar convention `generateFormSection()`'s "Main Details" card uses, reading as a visually mismatched nested box on the full-page Edit view. Fixed by switching to the same plain-div convention (border+header-bar when `!modal`, a bare label when `modal`). `MobileApp/Components/{Create,Edit}FormGenerator.php` override `generateInlineItemsBlock()` to keep the *old* `<Card>` behavior unchanged — MOBILE_APP's own "Main Details" convention is `<FormCard>`, itself a styled `<Card>`, so the Card-wrapped block was already the correct look there.

A follow-up: the modal-mode label lost its horizontal inset when the `<Card>` (whose `CardContent` supplied padding for free) was removed — "Items" rendered flush against the modal's edge while the item list below it (which gets its own `p-4`) stayed correctly indented. Fixed by giving the modal-mode wrapper its own `px-4`. Confirmed live on Expenses' Create modal and full-page Edit view.

### Added — `variant`/`totals` on `inline_items` — the financial line-items pattern

The common shape for financial line items (Description / Qty / Unit Price / Amount as real aligned columns, a bold totals row at the bottom, the parent's own "Total"-style field always matching that sum) is now a first-class, declarative capability instead of something every module hand-rolls:

- `inline_items[].variant: 'table'` renders `InlineItemsComponent`'s child rows as real aligned columns (numeric columns right-aligned) instead of the default compact card row.
- <code v-pre>`inline_items[].totals: [{ field, label?, sync_to? }]`</code> sums that child field across every row (rounded to the field's own `decimals`) and renders it in a footer row. `sync_to` names a top-level parent field that the generated form keeps permanently equal to that sum via an inline `@totals-change` handler, which also locks the field through the existing `disabledFieldsList`/`isFieldDisabled` mechanism.

Both are additive, optional config keys — omitting them keeps the existing default card row/no-footer behavior byte-for-byte.

Two real bugs found and fixed live building this out, both against Expenses' regenerated Create modal:
1. **Crash on mount whenever `totals` was set**: `Cannot access 'validItems' before initialization`. The new <code v-pre>`watch(totalsComputed, ..., { immediate: true })`</code> sat *above* `validItems`'s own declaration in `InlineItemsComponent.vue`'s `<script setup>` — `immediate: true` evaluates its getter synchronously during setup, hitting the temporal dead zone every time. Fixed by moving the whole totals block below `validItems`/`tableColumns`.
2. **Table variant silently clipped instead of scrolling on narrow viewports**: the outer wrapper is `overflow-hidden`, so a 4+ real-column table had nowhere to go on a phone-width screen. Fixed by scoping an `overflow-x-auto` div around just the `<table>` — the same pattern `ReportTable.vue` already uses on the List page.

`composer test`: 740/740 green throughout.

## v3.0.5 — 2026-08-16

### Fixed — inline_items rendered below the Save/Create buttons on Create and Edit pages

`generateFormSection()` baked the footer (Save Draft/Create or Save Draft/Save Changes buttons) into
the *last child of the "Main Details" card's own HTML*, and `[[inlineItemsBlock]]` was spliced into
`create/form.stub`/`edit/form.stub` as a LATER sibling — so any module with `inline_items` configured
had its "Items" child-row card render visually below the submit buttons instead of above them.
Confirmed live on the demo fixture's Expenses create page.

Data flow was already correct (`generateInlineItemsBlock()`'s wrapper component binds to a plain local
`form` field via `v-model`, and `generateSubmitCall()` posts the whole `form` object — parent and
children — in one atomic request; no `uuid`-before-children dependency), so this was a pure template-
ordering bug, not a design constraint. Fixed by emitting the footer as its own `[[formFooter]]` token,
placed after `[[inlineItemsBlock]]` in both stubs, instead of baking it into `generateFormSection()`'s
return value. `generateFormSection()` itself is unchanged — `CustomFeatureModalComponentGenerator`
(modal-embedded forms, which never splice `inline_items` in) and the `MobileApp/*` generators (which
never pass a footer to it at all) are unaffected.

Live-verified on the regenerated Expenses/Items create pages (screenshot: Items card now renders
between the main fields and the Save Draft/Create buttons). `composer test`: 740/740 green (2 new
regression tests — `CreateFormGeneratorTest`/`EditFormGeneratorTest` —
`test_inline_items_block_renders_before_the_footer_buttons`, asserting the inline_items block's string
position precedes the footer's submit button in the generated output).

### Changed — list/delegation columns no longer force a uniform 150px width

`generateColumnsFromListFields()` (shared by `ListPageGenerator`, delegation tabs, and delegation
modals) hardcoded `width: 150` on every column regardless of its actual content — a short "Status"
badge and a long free-text "Notes" field both got the same fixed width, producing cramped, wrapping
cells industry-wide across every generated list. `ReportTable.vue`'s own `width?: number` docblock says
a width is "Required for fixed columns; helps layout for all columns" — not required for the rest —
and only applies an explicit CSS width when `col.width` is truthy, falling back to natural/content-
based sizing otherwise. The primary column (`fixed: true`, pinned left) keeps its explicit `width: 150`
— genuinely required there for the sticky-column offset math — every other column now omits `width`
entirely. `composer test`: 740/740 green (existing `BaseComponentGeneratorTest` expectations updated
to match).

## v3.0.4 — 2026-08-16

### Changed — audit columns are never introspected for divergence; they're always added

`SchemaIntrospector::meta()` used to inspect a live table's real `id`/`uuid`/`created_at`/`updated_at`/
`deleted_at`/`created_by_id`/`updated_by_id` columns purely to verify they matched
`SchemaConventions::DEFAULT_FLAGS`, throwing `SchemaConventionDivergenceException` on any mismatch
(landed 2026-07-26, replacing an even earlier design that derived the flags from raw column presence
and silently lost SoftDeletes/timestamps/uuid/audit tracking for any table not yet hand-patched to
match). The divergence check itself became the problem it was meant to prevent: its only opt-out,
`SchemaConventions::SKIP_CHECK_META_KEY`, was never wired into either real caller
(`MakeModulesFromDb.php`, `ModuleScaffolder.php` both call `$introspector->meta()` with zero arguments)
— any table missing even one conventional column hard-failed introspection entirely, with no path
forward short of hand-patching this package's PHP source.

Decision: audit columns are not user-configurable business data any more than they're an option in the
module wizard UI — a module built through the UI never lets an author add or omit id/uuid/timestamps,
they simply aren't offered as something to configure. `meta()` now returns
`SchemaConventions::DEFAULT_FLAGS` unconditionally for an existing table, with **no** live-schema check
of the real audit columns at all — nothing to detect, nothing to diverge from, nothing to throw.
`SchemaConventionDivergenceException` is removed entirely, along with the now-dead
`SchemaIntrospector::hasTimestamps()`/`hasSoftDeletes()`/`hasUuid()`/`hasCreatorUpdater()`/
`hasRawColumn()` and `SchemaConventions::SKIP_CHECK_META_KEY`/`COLUMNS_BY_FLAG`/`SYSTEM_COLUMNS`/
`isConventionCheckSkipped()`, none of which have any remaining caller. A real table that doesn't yet
match convention is expected to be brought in line with it, or have its module's `module.json`
hand-edited after generation (`has_soft_deletes: false`, etc.) — not accommodated by introspection.

Live-verified against a real MySQL table deliberately missing `deleted_at`: `meta()` no longer throws
and correctly returns `has_soft_deletes: true` regardless. `composer test`: 738/738 green (5 tests in
`SchemaIntrospectorConventionTest` rewritten to cover the new unconditional behavior instead of the
removed divergence path; `IntrospectionToConfigMetaWiringTest`/`SchemaIntrospectorMetaTest` updated to
drop references to the removed methods).

`SYSTEM_SHELL/docs/modules/bulk-generation.md` gained an entire missing section:
`make:modules-from-db` — the whole-database bulk-scaffold command — had zero documentation anywhere
before this pass; now covers its two-stage flow, options, and this convention-vs-introspection
behavior explicitly.

## v3.0.3 — 2026-08-16

### Fixed — 2 more real bugs, found actually running the demo fixture's generated Playwright e2e suite

v3.0.2 got the demo fixture's generated PHPUnit suite to 689/689; this pass ran its generated
Playwright e2e suite for the first time (0/17 passing at the very start of this session, 6/17 after
v3.0.1/v3.0.2's fixes) and found two more real bugs invisible to PHPUnit entirely — both product
UI/UX bugs, not just test-generation ones.

- **`exists:{table},id` validation rules for a required FK still guessed the related table name via
  naive pluralization (`Str::snake(Str::plural($relatedModule))`), even after v3.0.2 fixed the
  *stale-frontend-string* half of this exact problem** — correct only when a module's real table name
  happens to follow strict Laravel default pluralization, which V1 never requires (a module's table
  name is set independently of its display name). Confirmed live: module "UnitsOfMeasure" was
  deliberately given the real table `units_of_measure` (already semantically plural as a whole
  phrase), but the guess pluralized only the last word, producing `units_of_measures` — a table that
  doesn't exist. Every Create/Edit request with that FK set produced a real 500
  (`SQLSTATE[42S02]: Base table or view not found`), not a validation rejection — worse than the
  v3.0.2 bug, since a legitimately valid ID could never even reach the point of being checked. Fixed
  with a new `PathManager::resolveTableNameForModule()` — looks up the related module's REAL,
  configured `table_name` from the module registry first (now includes `table_name`, populated by
  `ModuleGenerationService.php`'s per-generation-run registry population), falling back to the
  naming-convention guess only when the registry has no entry for it at all.
- **A Select2/ApiSelect2-shaped (FK) filter field was picked as a generated e2e spec's "Filter"
  step target even when its own list column has no way to show a resolved name** — `PlaywrightTestGenerator`'s
  "Variant B" filter strategy reads whatever text a just-created row's target column currently
  displays, then searches the filter's Select2 popup for an option with that exact text. For an FK
  column with no dot-path list-column shape (e.g. `"data": "status.name"`), the row instead displays
  the column's raw numeric id ("1") — and no real Select2 popup ever has an option literally labelled
  "1", so this was a **guaranteed** failure, not a flaky one, for any module whose only filterable
  business column happens to be a foreign key (confirmed live: PaymentTerms, ItemCategories,
  ItemPrices, Payments all hit this). Root cause is one level up — V1's wizard never emits the
  dot-path list-column shape `BaseComponentGenerator::generateCustomCellRenderersFromListFields()`'s
  own `isFk` branch needs to render a resolved `<RelatedRecordLink>` cell in the first place, so this
  is currently unreachable from any real V1 project; **not fixed here** (a real, separate, and
  substantially bigger V1-wizard + generator integration gap — flagged for a dedicated pass, not a
  test-generator patch). What IS fixed here: `pickVisibleFilterField()` now excludes an FK-typed
  candidate unless its list column resolves via a dot-path, and `buildFilterBlock()` skips the filter
  section entirely — with an explicit, honest log line, not a doomed assertion or a silently dropped
  one — when no safe candidate survives that exclusion.

`composer test`: 743/743 green (one existing unit test asserting the old, buggy FK-filter-picking
behavior on a deliberately FK-only fixture updated to assert the new skip-with-log behavior instead).

## v3.0.2 — 2026-08-15

### Fixed — 2 more real bugs, found actually running the demo fixture's generated PHPUnit suite

v3.0.1 fixed 2 namespace-resolution bugs found by inspecting generated code; this pass actually
provisioned the retail-ERP demo fixture as a running app (MySQL, real seeded data) and ran its own
generated PHPUnit suite (163 of 702 tests failing before this pass) to find what inspection alone
missed.

- **`generateValidationRules()`'s `exists:{table},id` rule used whatever table name V1's frontend
  had baked in, which could be stale or malformed** — `generateColumnsValidations()` in V1's
  `generator.ts` computes each field's rules once, the first time its wizard step mounts, and never
  recomputes if the column's `relatedModule` changes afterward; a mismatch produced garbage table
  names like `exists:itemcategories,id` (no underscore) instead of `item_categories`. Every Create/
  Edit request against such a field would reject any real, valid ID. Fixed by rebuilding the table
  name authoritatively server-side from `relatedModule` (`Str::snake(Str::plural(...))`) whenever a
  `foreignId` column config is available, overriding whatever the frontend sent.
- **`generateEagerLoadRelationships()` had the identical problem for relation *names*, not table
  names** — V1's frontend independently guesses an eager-load relation name by snake-casing the
  related module's name and chopping exactly one trailing character (`"Statuses"` → `"statuse"`,
  `"ItemCategories"` → `"item_categorie"`, `"PaymentTerms"` → `"payment_term"`), never matching the
  Model's real camelCase relation method (`status`, `itemCategory`, `paymentTerms`). Every List/View
  service eager-loading one of these threw `Call to undefined relationship [...]`. Fixed the same
  way: re-derive the correct relation name from `$config['columns']` (camelCase, `_id` stripped from
  the column name) whenever the frontend's string matches either the correct name or its known
  broken-chop shape; anything else (a hand-authored custom relation) passes through unchanged.
- **`PathManager::findModuleByTable()` could never find a Core/pre-shipped module** (Statuses,
  Locations, Users, ...) — they ship pre-built inside `core_backend.zip` and never get a `modules`
  DB row, so they never appear in the in-memory module registry this method searches. Added a
  `default_modules.json` fallback tier, matching `resolveBackendModuleNamespaceOrNull()`'s own
  existing multi-tier pattern for the identical problem — every Core entry there has a real generated
  Factory (confirmed: `StatusesFactory.php`, `UsersFactory.php`), so `PhpUnitTestGenerator::
  resolveCrossModuleFkLiteral()` can now build real `::factory()->create()->id` fixtures for a
  required FK into a Core module too, not just project-created ones.

- **Field-level `processing_service`'s return value was discarded** — `generateCustomFieldProcessing()`
  called `\{$namespace}::process($validData['{$fieldName}'])` for the side effect only, never
  assigning the result back to `$validData`. The whole point of this hook is to transform a field's
  value before save (hashing, normalizing, ...) — with the return value discarded, no
  `processing_service` could ever actually change what got saved, only side effects (e.g. logging)
  "worked". Fixed to assign the return value back (`$validData['{$fieldName}'] = ...::process(...)`),
  same as the analogous `processors[]` call already correctly does.

`composer test`: 743/743 green throughout (no test changes needed for this pass — these 4 fixes
change generated *output* only, not the package's own testable surface).

Also found and fixed the same session, **not in this package** (V1-side,
`PROJECT_GENERATOR_SYSTEMv1/BACKEND/app/Project/Generator/Services/ModuleGenerationService.php`):
- A stale defensive workaround forced `has_soft_deletes` to `true` whenever absent, on a premise
  (migration.stub unconditionally emits `softDeletes()` for every module) that stopped being true once
  this package's own migration template became conditional — actively reintroducing the exact
  Model/Migration cross-file mismatch that workaround was originally written to prevent, just inverted
  (a module with no `deleted_at` column got a Model with the `SoftDeletes` trait anyway). Removed;
  both generators already agree by construction via the shared `ModuleConfigContract::hasSoftDeletes()`.
- `FactoryGenerator` was never called anywhere in `ModuleGenerationService.php` — every module V1 has
  ever created shipped with zero `Factory` class, invisible until a generated PHPUnit test needed one
  for a required cross-module FK fixture (`RelatedModel::factory()->create()->id`) and hit `Class
  "...Factory" not found`. Only Core/pre-shipped modules had factories (hand-maintained inside
  `core_backend.zip`), which is why this was never noticed before — every FK an earlier passing test
  happened to target was a Core module, never a project-created one. Wired up, same pattern as
  `PhpUnitTestGenerator`/`PlaywrightTestGenerator`'s own original wiring gap fixed earlier this session.
- `ViewModalGenerator` was **also** never called for any project-created module — and
  `FrontendRoutesGenerator`'s own `routes.ts` output unconditionally references
  `./Components/{Module}ViewModal.vue` for every module regardless. Every module V1 has ever
  generated therefore shipped a frontend that fails to even **build** (`Module not found` at Vite
  build time) — the most severe finding of this pass, only surfaced by actually running
  `npm run dev`/`start.sh` for the generated project for the first time; every earlier check this
  whole session inspected generated source without ever building it. Wired up, same fix shape as
  `FactoryGenerator` above.

## v3.0.1 — 2026-08-15

### Fixed — two module-namespace resolution bugs, found building a real 13-module test fixture

Both found live: a curated retail-ERP demo project was built through V1's real wizard UI to exercise
every generator capability (morphs, inline_items, dual-delegation, composite unique_constraints,
processors, file_columns, etc.) end to end. Two generated-code bugs surfaced only once real,
non-Core-nested modules were involved — no prior unit test exercised either path.

- **`PathManager::resolveBackendModuleNamespaceOrNull()`'s `default_modules.json` fallback** only
  trusted that file's pre-resolved `namespace` field when the entry's `type === 'Kernel'` — but 24 of
  28 real pre-shipped entries (Core/System/Dev) also carry a correct `namespace` and never carry a
  `group`/`module_group` key at all, so the "correct" branch was dead weight and every FK-derived
  `belongsTo()` into a nested pre-shipped module (`Users`, `LocationTypes`, `UserInvitations`, ...)
  resolved to a truncated, non-existent class (`Core\Users\UsersModel` instead of the real
  `Core\Users\Users\UsersModel`). Fixed: trust `namespace` whenever present, not gated to Kernel-only.
- **`BaseServiceGenerator::buildChildNamespace()`** (inline_items) hand-assembled the child module's
  namespace from the config's own `child_group`/`child_group_name` strings. Real consumers only ever
  populate `child_group` (the module's group, e.g. "Demo"), never `child_group_name` — so the child's
  `module_type` segment ("System") was silently dropped, producing a namespace reference
  (`Modules\Demo\ItemImages`) to a class that doesn't exist (`Modules\System\Demo\ItemImages`).
  Fatal on every inline_items Create/Edit/Delete/View call. Fixed by routing through the same
  authoritative registry lookup `generateProcessorCalls()` already used for the identical problem
  (`PathManager::resolveBackendModuleNamespace()`) — signature simplified to just `(string $childModule)`,
  the 4 call sites (Create/Edit/View/DeleteServiceGenerator) updated to match.

`composer test`: 743/743 green (`InlineItemsEndToEndTest` updated to register a fake module in
`PathManager::setModuleRegistry()` before generating, matching what a real generation run does;
`BaseServiceGeneratorTest`'s 4 old `buildChildNamespace()`-signature tests replaced with 3 covering
the new registry-resolved behavior).

Also found, **not fixed here** (V1-side, not this package): V1's "update module" flow never calls
`setForce(true)` on any generator, and its own `backupExistingModule()` is dead code — so a plain
config update never actually overwrites already-generated files. Documented in the demo fixture's own
README (`PROJECT_GENERATOR_SYSTEMv1/_tests/demo-project/README.md`) since it isn't a generator-engine
bug, but it's why these two fixes needed a delete-and-regenerate to prove out rather than a plain update.

## v3.0.0 — 2026-08-15

### BREAKING — Removed the UX Builder subsystem (composites, wizards, shortcuts, dashboard quick-actions)

Product decision: this engine now supports CRUD, actions, and delegations only. Removed entirely,
not deprecated: `Generators\Ux\{BaseUxGenerator,CompositeGenerator,DashboardGenerator,
ShortcutGenerator,WizardGenerator}`, the `make:ux-from-blueprint` Artisan command
(`Commands\MakeUxFromBlueprintCommand` — this package now ships zero commands, so
`GeneratorEngineServiceProvider` no longer calls `$this->commands([...])` at all),
`schema/ux-blueprint.schema.json`, `docs/ux-blueprint.md`, `examples/ux-blueprint.json`, both UX
stub directories (`Generators/Templates/ux/`, `Generators/Templates/mobile_app/ux/`), the `ux-suite`
test fixture, and `PathManager::getUxTemplatePath()`/`getMobileUxTemplatePath()`.

One entanglement had to be resolved first: `MobileSyncComposableGenerator` (a generic mobile-CRUD
generator producing every module's offline-sync composable, unrelated to UX Builder) loaded its stub
via `getMobileUxTemplatePath()` purely because that stub file (`use-sync-status.stub`) happened to be
stored inside the UX template directory. Relocated it to
`Generators/Templates/mobile_app/backend/services/sync-status-composable.stub` and switched the
generator to the plain default `loadStub()` path (matching its sibling `MobileSyncServiceGenerator`)
before deleting anything else — mobile sync-composable generation for ordinary CRUD modules is
unaffected.

Confirmed via code search across both known consumers in this workspace before removing: neither
`PROJECT_GENERATOR` (V1) nor `SYSTEM_SHELL` calls any of these classes or the Artisan command from a
live runtime path, and neither has generated output on disk that this removal would strand.
`SYSTEM_SHELL`'s developer-facing docs (`BACKEND/README.md`'s UX Features section,
`.claude/commands/scaffold-domain.md`'s UX Extensions appendix, a dead route-loading guard in
`FRONTEND/src/router.ts`) referenced the removed command and were updated in the same pass as this
release.

Full test suite: 767 → 744 tests (removed exactly the 5 UX-specific test files' worth), all passing.

### Docs — full accuracy audit against the real source (not just the UX Builder removal)

Cross-checked every doc page under `docs/` plus the root `README.md` against the actual generator
classes/schemas they describe, rather than trusting the prose. Found and fixed real drift in every
file, including two that would have broken anyone following them: `module-config.md`'s `seeder`
shape was documented as a flat array (real shape is `{data: [...], permissions: [...]}`,
`SeederGenerator` reads it that way), and its `id_type` values were wrong (`"uuid"`/`"bigint"` doc'd,
real values are `"autoincrement"`/`"uuid"`/`"manual"`, read with no fallback). `columns.md` had zero
mention of the `enum` type despite it being fully wired (migration, cast, validation, frontend
field), claimed FK columns get a `->constrained()` DB constraint (never true — this project has no
hard FK constraints anywhere by convention), and mapped `json` columns to a `textarea` field when the
generator actually emits no field for them at all. `scaffold-blueprint.md`'s `delegations` example
was missing a whole nesting level (module-name → array, not a flat delegation-key map) and its
`morphs[].targets` were shown as bare class-name strings instead of the real `{alias,model,module,label?}`
objects. `README.md` still listed two classes deleted in v2.28.0/v2.29.0
(`ListComponentGenerator`/`DelegationRelatedFormGenerator`, web variants) as current, named a mobile
generator wrong (`MobileRoutesGenerator` → real `MobileAppRoutesGenerator`), and claimed v2.5.0 was
the current release with `^2.0` install instructions — three releases and 35+ versions stale.
`mobile-config.md` didn't warn that its own documented default (`mode: "online"`, skip the sync
generators) produces a Controller that unconditionally references a `SyncService` class that was
never generated. Also documented for the first time: `has_timestamps`/`has_soft_deletes`/`has_uuid`/
`has_creator_updater`/`model_hand_maintained`/`file_columns`/`unique_constraints`/`inline_items`
(all real, previously-undocumented top-level `module-config` keys), the `drafts` and `file-input`/
`morph-select` frontend field types in `features-config.md`, and the hand-maintained
`CrudListPanel.vue` prerequisite in `delegations.md`.

## v2.56.1 — 2026-08-13

### Fixed — `_fixtures.js`'s helper functions never included `fillDatePickerField` for a `date` field

Found live scaffolding PGS_v2's `Modules`/`GenerationQueue` modules and running their Playwright
suites end-to-end: `PlaywrightTestGenerator::buildFixtureHelperFunctions()` (builds the helper block
for every module's generated `_fixtures.js`, scoped to the specific field types its create form
uses) tracked whether a required/optional select, a number input, or a morph-select field was
present — but never checked for a `date`-type field. `createFixtureRecord()`'s own body (built by a
separate method) unconditionally emits a `fillDatePickerField(...)` call for any `date` create field
regardless, so any module with a datetime create field threw `ReferenceError: fillDatePickerField is
not defined` the moment its `_fixtures.js` was exercised by a delegation or action spec (the CRUD
spec itself was unaffected — it gets its helpers from `buildHelperFunctions()`, a separate method
that already included the date helper correctly). Fixed by adding the same `$hasDateField` tracking
and `dateFieldHelperBlock()` inclusion the other field types already had.

## v2.56.0 — 2026-08-12

### Fixed — composite unique constraint violations crashed with a raw 500 instead of a clean 422

Found live scaffolding all 5 integration-test suites simultaneously against a real consuming
project: `items-suite`'s `item_prices` fixture (composite `UNIQUE(item_id, currency)`, the exact
scenario the suite's own README calls out as a target scenario) — the migration correctly emits
the DB-level composite unique constraint, and `MigrationGenerator`/`IntrospectionToConfig` already
thread `unique_constraints` through to the migration and to `PhpUnitTestGenerator`'s own fixture
variance helper, but no validation rule was ever generated for it (Laravel's `unique:` rule is
single-column only), and every generated Service's `catch` block only handled `ApplicationException`
— a real `\Illuminate\Database\UniqueConstraintViolationException` bubbled straight through
uncaught. Fixed by adding a `catch (UniqueConstraintViolationException)` clause ahead of the
generic `catch (\Exception)` in `create/service.stub`, `edit/service.stub`, and `action/service.stub`,
returning the same clean 422 shape every other validation failure already returns.

### Added — `processors[]` test coverage (previously zero, at any level)

`processors[]` (module-wide `before_save`/`after_save`/`before_delete`/`after_delete` lifecycle
hooks) had no test coverage anywhere — confirmed via a full-tree grep. Worse: `docs/processors.md`
and `schema/module-config.schema.json` both described a completely different, non-existent API
(instance-based <code v-pre>`(new Service())->handle($data, $model)`</code>, a `method` config key, and
`before_validation`/`after_validation` stages) than what `BaseServiceGenerator::generateProcessorCalls()`
actually does — a static `{Namespace}::{Str::camel(stage)}(...)` call, no `method` key at all (always
ignored if supplied), and only the 4 real stages (`before_validation`/`after_validation` silently
produce zero generated code). Both docs corrected to match the real implementation. New
`ProcessorGenerationTest.php` (7 tests) locks in: the exact static-call shape, the `$model=null`
gotcha for `before_save` on both Create AND Edit (asymmetric with `after_save`, easy to get wrong),
`operations` gating, unsupported-stage no-op, and that `module` doesn't need to be an
already-scaffolded module (falls back to `App\Project\Modules\Core\{module}` with a warning).

### Added — cross-generator agreement test for `bulk_actions[].status_target` + `constants`

`BulkActionServiceGeneratorTest`'s existing `status_target` coverage runs `BulkActionServiceGenerator`
in total isolation, asserting only the generated call's raw text — it never proved the referenced
`{Module}Model::{CONST}` constant is actually emitted by `ModelGenerator` from the same config, or
even that the two generators agree on case. New `BulkActionStatusTargetModelAgreementTest.php` runs
both generators together against one config and confirms the constant is real, valid PHP, and
matches — plus documents the real, still-open gap: a typo'd `status_target` (case mismatch against
`constants`) generates cleanly on both sides and only fails at runtime ("Undefined constant"), since
nothing cross-validates the two config paths today. Also fixed `schema/module-config.schema.json`'s
`constants` definition, which described a nested <code v-pre>`{name, values:[{label,value}]}`</code> shape that
`ModelGenerator::generateConstants()` has never read — the real shape is a flat `{NAME: scalar}` map.

### Added — `CrossFileContractTest` now combines inline_items, morphs, file_columns, and
bulk_actions/export/import in its one fixture

Previously absent from the fixture entirely (confirmed by grep) — meaning the one test whose job is
catching bugs that only surface when multiple mechanisms compose in the same module never actually
combined them with the mechanisms most likely to interact (a delegation + a morph-select field + an
inline_items child + a file upload + a bulk action, all on one `Items` module). All 4 existing
mechanical checks (route↔Vue endpoint parity, permission↔seeder parity, import resolution,
locale-key resolution) pass with the expanded fixture — 138 assertions, up from the prior narrower
config.

### Fixed — `docs/ux-blueprint.md`, `schema/ux-blueprint.schema.json`, `examples/ux-blueprint.json` all described a blueprint shape the UX Builder code has never read

Confirmed by reading `BaseUxGenerator`/`CompositeGenerator`/`WizardGenerator`/`DashboardGenerator`/
`ShortcutGenerator`/`MakeUxFromBlueprintCommand` directly (`git log --follow` shows the docs/schema/
example were added in a later, separate commit that never touched the PHP). Three real mismatches,
all would have cost a live generation cycle if trusted: (1) top-level key is `groups`
(<code v-pre>`{GroupName: [snake_case_table_name]}`</code>), not `module_groups` (<code v-pre>`{ModuleName: GroupString}`</code>) — a
blueprint using the old shape silently produces 0 files for every composite/shortcut, no error; (2)
`make:ux-from-blueprint` takes a single positional argument with no `--force` flag, not
`--blueprint=`/`--force`; (3) shortcut `prefill` values only resolve when `$`-prefixed (`"$id"`,
`"$uuid"`, `"$fieldName"`) — the documented <code v-pre>`"{{record.id}}"`</code> syntax passes through unresolved. All
three fixed in the docs, schema, and example blueprint.

### Added — new `ux-suite` integration-test fixture (Quotes/QuoteItems) + `CompositeGeneratorTest`/`DashboardGeneratorTest`

Sibling to the other 5 `integration-schemas/` suites, but exercises the separate blueprint-JSON-driven
UX Builder pathway instead of `module.json`. Live-verified end-to-end against a real consuming
project immediately after the docs/schema corrections above: all 4 UX generators (composite, wizard,
dashboard, shortcut) ran cleanly on the first attempt — 14 files created, 0 errors — confirming the
corrected blueprint shape is actually right, not just re-documented. `CompositeGenerator` and
`DashboardGenerator` had zero test coverage of any kind before this session (confirmed by grep); new
`CompositeGeneratorTest.php` (4 tests) and `DashboardGeneratorTest.php` (5 tests) close that gap at
the unit level — including the silent-skip-when-`groups`-is-missing and silent-no-op-when-
`DashboardPage.vue`-doesn't-exist behaviors, both confirmed rather than assumed.

763/763 generator-engine suite (up from 746 at the start of this pass — 17 new tests, 0 regressions).

## v2.55.0 — 2026-08-10

### Fixed — generated import e2e step fed the downloaded template back with no file extension

SYSTEM_SHELL's shared import-modal file field (`ListTable.vue`) was moved from a raw
`<input type="file">` onto the codebase's standard `FileInputField` component (hand-maintained
runtime, not part of this package) so it conforms to the project's file-upload convention.
`FileInputField` applies a client-side accept-type check against the selected `File` object's
`name` — but `PlaywrightTestGenerator::buildImportBlock()`'s generated e2e step fed
`download.path()` straight into `setInputFiles()`, and Playwright's internal temp download path
carries no filename extension. The client-side check silently rejected the file, `importFileRef`
never got set, and the Import button stayed disabled — a real regression, confirmed live against a
freshly generated PurchaseOrders module (`purchase-orders-crud.e2e.js`'s import step timing out
waiting on the disabled submit button).

Fixed by re-saving the download under `download.suggestedFilename()` (which does carry the real
`.csv`/`.xlsx` extension) via `download.saveAs()` before feeding it back into the file input. New
regression assertions in `PlaywrightTestGeneratorTest` — 746/746 generator-engine suite.
Live-verified: PurchaseOrders' full generated e2e suite (create → filter → view → edit → import →
delete) passes end-to-end after regenerating against this fix.

## v2.54.1 — 2026-08-10

### Fixed — restoring/resuming a draft with a blank field broke `InputField`'s `modelValue` prop

Found live: a field left blank when a draft was autosaved round-trips through the backend's global
`ConvertEmptyStringsToNull` middleware as `null`, not `''` (the same failure mode already known and
fixed for the "load an existing record" path — see `EditFormGenerator`'s loaded-record coercion).
Neither `buildEditDraftBlocks()`'s `restoreDraft()` nor `buildCreateDraftBlocks()`'s
`handleResumeDraft()` had the equivalent guard, so merging a restored/resumed draft's payload
straight into `form.value` handed `InputField`'s `modelValue` prop (`String | Number` only) a raw
`null` for every field that was blank when the draft was saved — a real Vue prop-type warning on
every module with drafts, not just Users, for any restore/resume where at least one field was empty.

Fixed by coercing `null` back to `''` immediately after the merge in both paths, mirroring the
existing loaded-record pattern exactly. New regression assertions in `CreateFormGeneratorTest`/
`EditFormGeneratorTest` — 746/746 generator-engine suite. Live-verified: the warning is gone from
a real browser console across a full autosave → reload → resume cycle with blank fields present.

## v2.54.0 — 2026-08-10

### Added — multiple simultaneous drafts per Create form, with a picker (list/resume/delete)

Every generated Create form's draft autosave used to be a single upsert slot per module — opening
a second, unrelated create attempt for the same module (a different browser tab, a nested "+ Add
New" quick-create popup embedded in a different form, or just coming back to `/module/create` days
later after already starting one) collapsed onto the exact same server-side row, silently
overwriting whatever was there.

Fixed by giving every Create form mount its own fresh, client-generated draft key
(`useDraftList().newDraftKey()`) instead of a fixed sentinel, and replacing the single-draft
`DraftRestoreBanner` with `DraftListPanel` — a picker showing every draft the current create
context has:

- **Backend**: `DraftsService::save()`/`find()` already respected a caller-supplied `record_key`
  for creates (v2.52.1); this adds `DraftsService::listForContext()` (+ `GET /drafts/for-context`)
  returning every non-expired draft (uuid/record_key/updated_at, no payload) for a given
  module+module_group, so a picker UI has something to list.
- **Frontend**: `useDraft()`'s `recordKey` param now accepts a `Ref` (not just a plain value) so
  the "active" draft key can change during a form's lifetime without re-creating the composable —
  Create forms own that ref themselves. New `useDraftList()` composable (drafts list, load,
  delete, key generation) and `DraftListPanel.vue` component.
- **Generator**: `BaseComponentGenerator::buildDraftBlocks()` now branches by form type — Edit
  keeps the simple single-slot banner unchanged (a record's own uuid is already a unique key, only
  ever one possible draft); Create gets the full multi-draft picker wiring
  (`buildCreateDraftBlocks()`).
- Superseded and removed v2.52.1's `draftContext` prop / `draft-context="inline-{fieldKey}"`
  mechanism entirely — it existed to stop an inline "+ Add New" popup's draft from colliding with
  its parent module's standalone create page, which the fresh-key-per-mount design now prevents
  structurally for every create context, not just that one case.

Ported into `Users`' hand-wired reference `CreateForm.vue` (`EditForm.vue` untouched — no multi-draft
concept there). New regression coverage: `CreateFormGeneratorTest`/`BaseComponentGeneratorTest`
updated for the new picker output — 746/746 generator-engine suite, 469/469 SYSTEM_SHELL backend
suite. Live-verified end-to-end in a real browser: two simultaneous drafts for the same Create form,
picker correctly listing both, resuming the correct one, deleting the other without touching the
active form, final cleanup — real `/api/drafts/*` traffic throughout, zero page errors.

## v2.53.0 — 2026-08-10

### Changed — base CRUD permission seeding reverted back to `Helpers::saveModuleCRUDPermissions()` — ⚠️ needs a manual cleanup pass

**Deliberate reversal of v2.46.0's change**, found live while investigating an unrelated missing-seed-data
report: every one of the 17+ already-generated modules in the primary consuming project (SYSTEM_SHELL) had
quietly drifted back to calling `Helpers::saveModuleCRUDPermissions($module)` from their generated
`{Module}Seeder.php` *in addition to* looping the JSON-driven `permissions` list — the exact redundant
shape v2.46.0 removed from the template. Rather than treat that as 17 independent regressions to silently
re-fix one at a time, the call is reinstated as the permanent, deliberate design:

- `seeder.stub`'s `permissions()` now always calls `Helpers::saveModuleCRUDPermissions($moduleName)` first
  — creates `list/view/create/edit/delete/bulkAction` unconditionally, regardless of which backend features
  are actually enabled on that module.
- `SeederGenerator::mergeListPermissions()` no longer auto-derives any of those six into
  `{Module}SeederData.json`'s `permissions` array — that file is now for genuine extras only (`import`,
  custom `actions`, `deleteCheck`, anything the Helper doesn't cover).

**⚠️ Known, accepted tradeoff** — this is the reason it needed a deliberate decision, not just a revert:
`Helpers::saveModuleCRUDPermissions()` is feature-blind. A module with `delete` disabled still gets a
`{module}.delete` permission row it has no route or service for (v2.46.0 fixed exactly this
over-provisioning). **Every already-generated module needs a manual audit-and-cleanup pass** to prune any
bogus permission rows this reintroduces for modules with a partial CRUD feature set — not done as part of
this change, flagged here for whoever picks it up next.

Also folded `bulkAction`'s wording into `Helpers::saveModuleCRUDPermissions()` directly (previously only
ever JSON-derived, using different, friendlier title wording than the Helper's generic per-action loop —
kept as its own explicit call inside the Helper, not folded into its generic `$actions` loop, specifically
to preserve that wording).

Updated regression coverage: `SeederGeneratorNoRedundantCrudCallTest` inverted (see its own docblock for
the full history), `SeederGeneratorTest`'s humanization tests now exercise `deleteCheck` (the base CRUD set
they used to check is no longer JSON-derived), `CrossFileContractTest`'s permission cross-check now also
credits the Helper-covered base set as "seeded" — 746/746 generator-engine suite. Live-verified against
SYSTEM_SHELL: restored a real missing `ORGANIZATION` Location seed row (lost in an unrelated prior port,
found during this same investigation), stripped the now-redundant base-CRUD/bulkAction entries from 16
already-generated `SeederData.json` files, re-seeded, confirmed the exact same permission set still
results — 469/469 SYSTEM_SHELL backend suite.

## v2.52.1 — 2026-08-10

### Fixed — inline "+ Add New" quick-create drafts collided with the module's own standalone create-page draft

Found live while verifying v2.52.0's Drafts-by-default feature works correctly when a Create form is
rendered inside a modal: `Core/Drafts`' backend (`DraftsService::save()`/`find()`) unconditionally
forced every create-type draft's `record_key` to `NEW_RECORD_KEY`, ignoring whatever the frontend
sent. A module's own standalone `/module/create` page and a "+ Add New" quick-create popup nested
inside a *different* form (e.g. quick-adding a Location while filling out the Users create form,
via `ApiSelect2Field`'s `#add-new` slot) both resolve to the exact same draft row — opening either
surfaced the other's in-progress draft, and autosaving from either silently overwrote the other's
data. Not hypothetical: confirmed reproducible via a dedicated Feature test before the fix.

Fixed on both ends:
- **Backend**: `DraftsService` now respects a caller-supplied `record_key` for create drafts too,
  falling back to `NEW_RECORD_KEY` only when the caller doesn't send one — preserves the existing
  single-slot behavior for the primary create flow untouched.
- **Generator**: every generated `{Module}CreateForm.vue` (with drafts enabled) now takes a
  `draftContext` prop, threaded into `useDraft()` as its `record_key` — left `null` by the primary
  create flow (standalone page / CrudListPanel's own create dialog), so nothing changes there.
  `api-select-inline.stub`'s nested "+ Add New" embed now always passes `draft-context="inline-{fieldKey}"`,
  giving that popup its own draft slot regardless of which module it's quick-creating.
- Ported the same fix into `Users`' hand-wired reference implementation (`UsersCreateForm.vue`/
  `UsersEditForm.vue`'s three inline `#add-new` embeds — Locations/Roles/Statuses), so it stays a
  faithful worked example of what the generator now emits by default.

New regression coverage: `DraftsInlineContextTest` (Feature, real HTTP + DB — primary slot unchanged,
inline slot isolated both ways, edit drafts unaffected), plus generator-level assertions in
`CreateFormGeneratorTest`/`BaseComponentGeneratorTest` for the new prop and the `draft-context`
attribute — 744/744 generator-engine suite, 469/469 SYSTEM_SHELL backend suite, `vue-tsc --noEmit`
clean.

## v2.52.0 — 2026-08-10

### Added — "Save as Draft" wired into every generated Create/Edit form by default

The generic server-backed draft-autosave substrate (Core/Drafts backend + `useDraft.ts` composable
+ `DraftRestoreBanner.vue`) has existed in SYSTEM_SHELL for a while, but was only ever hand-wired
into `Users`' own CreateForm/EditForm as a reference implementation — every other generated module
got none of it. `useDraft(module, moduleGroup, formType, recordKey?)` was already fully generic
(nothing Users-specific inside it), so this wires the exact same pattern into `create/form.stub`
and `edit/form.stub`: a `DraftRestoreBanner` at the top of the form, a "Save Draft" button beside
Cancel/Submit, `checkForDraft()`/`discardDraft()` calls at the right lifecycle points, and a
debounced `watch(form, ..., { deep: true })` autosave gated on `!isLoading`.

On by default — opt out per module via `features.frontend.create.drafts: false` /
`.edit.drafts: false` in `module.json` (new `drafts` key on `FrontendMutateFeature` in
`module-config.schema.json`, defaulting to `true`). A module that opts out generates
byte-identical output to before this feature existed.

New regression coverage in `CreateFormGeneratorTest`/`EditFormGeneratorTest`: real end-to-end
generation (not placeholder-substitution logic in isolation) asserting the exact banner/button/
composable wiring appears by default and is fully absent when disabled — 744/744 full suite.
Live-verified: `vue-tsc --noEmit` clean across the whole SYSTEM_SHELL frontend after generating a
real fixture module with drafts on.

### Added — full override slots for `InlineItemsComponent`'s Add/Edit/View modal bodies

`InlineItemsComponent.vue` (the shared, hand-maintained parent-child-rows component every
`inline_items` wrapper renders) previously only let a consumer override the row layout (`#row`)
and modal titles (`add-modal-title`/etc, forwarded transparently) — the Add-form, Edit-form, and
View-detail bodies were hardcoded loops with no way to swap in a custom Form/List/View component
while keeping the underlying add/edit/delete/reorder/validation logic intact.

Added three new named slots — `#add-form`, `#edit-form`, `#view` — mirroring the existing `#row`/
`#empty` precedent exactly: scoped data matching what the default markup already uses (`fields`,
`currentItem`, `errors`, `isFieldVisible`, `modalFieldClass`, plus `updateField`/`selectObject` for
the two form slots and `resolveField` for the view slot), with the current default markup kept as
slot fallback content. 100% additive — no generator-side changes were needed (the wrapper stub
already does `v-bind="$attrs"` passthrough), so this is purely a `SYSTEM_SHELL/FRONTEND` +
`docs`/README change, live for the next module that materializes its wrapper.

Also added, alongside the same pass: a new `dynamicFilters?: (item) => Record<string,any>` field
hook (same shape as the existing `dynamicDisabled`/`dynamicLabel`/`showField` hooks), threaded
through `InlineItemsFieldRenderer` → `ApiSelect2Field`'s `filters` prop, so a line-item field's
own picker can be scoped by a sibling field's live value (e.g. a Batch field limited to the Item
already chosen on that row) — closing a gap found live during the Cobley session, where this had
no expression mechanism at all.

## v2.51.2 — 2026-08-10

### Fixed — three real generator bugs found during a live Cobley Spare Parts scaffold-and-build session, plus one already-fixed item confirmed still fixed

Catalogued as 29 numbered "gotchas" from a real scaffold session against a new domain. Triaged
against the current source: most were already fixed, by-design/framework behavior, or live in a
consuming project's own hand-maintained runtime rather than this package. Three were real,
reproducible-for-any-domain bugs in generator-engine itself:

- **`WizardGenerator`'s frontend import paths were wrong.** `generateFrontendPage()`/
  `generateMobileWizardPage()` hand-built each step's `CreateForm.vue` import as
  `@/pages/modules/{lowercased-first-segment}/{module}/Components/...` — lowercasing a
  PascalCase sub-group and omitting the mandatory top-level `system/` group segment entirely, so
  every wizard step's import failed to resolve at build time. Fixed by routing through
  `PathManager::resolveFrontendImportSegment()` — the same registry-backed resolver every other
  cross-module frontend reference in this codebase already uses.
- **A wizard step referencing an `inline_items` child generated a dead import.** Nothing
  cross-checked a step's `module` against the blueprint's own `inline_items` keys, so a step over
  a child that never gets its own scaffolded module (by design) still generated an import for a
  `CreateForm.vue` that will never exist. The same `resolveFrontendImportSegment()` swap fixes
  this too — an unregistered module now resolves to `''`, and the wizard step emits a comment
  directing the author to fetch/display the parent's existing child rows via the parent's own
  API instead, rather than a broken import.
- **Action routes: backend and frontend independently computed two different default routes.**
  When an `actions[].operations[].endpoint.path` is left unset, `RoutesGenerator::
  generateActionRoutes()` registers `/{module}/{action}/{params}/{op}` while
  `ActionComponentGenerator::buildEndpointExpression()` built `/{module}/{params}/{action}` — a
  different segment order and a missing operation segment, guaranteeing a 404 for every action
  left at its default endpoint. The frontend now derives its default from the exact same shape
  the backend registers, keyed off the specific operation that matched.
- **`buildChildNamespace()` couldn't express an `inline_items` child living under a sub-group.**
  It took only a top-level `$childGroup`, so a child module nested under a sub-group (e.g.
  `Modules\System\Inventory\{Child}`) generated a namespace missing that segment. Fixed via a new
  optional `child_group_name` key on an `inline_items` item (read by all four
  Create/Edit/View/DeleteServiceGenerator call sites), defaulting to the prior top-level-only
  behavior when absent — see `docs/examples/inline-items.md`.
- **Verified already fixed, no change needed**: the inline-items save loop never setting
  `created_by_id`/`updated_by_id` on child rows — this was fixed 2026-08-02 via the opt-in
  `child_has_creator_updater` config flag (`BaseServiceGenerator::buildInlineInjectArray()`); the
  live session that hit it simply hadn't set the flag.

New regression coverage: `WizardGeneratorTest` (import-segment resolution, including the
inline_items-child-resolves-to-empty-string case), `ActionComponentGeneratorTest`
(default-endpoint shape matching the backend, explicit-path passthrough), `BaseServiceGeneratorTest`
additions for `buildChildNamespace()`'s new sub-group parameter — 740/740 full suite.

## v2.51.1 — 2026-08-09

### Fixed — `morph-select` field rendered at a sliver of its intended width

Found live, immediately after shipping v2.51.0: the generated create/edit form's outer grid is
`md:grid-cols-2 lg:grid-cols-3`, and `MorphSelectField.vue` itself splits into a further
`sm:grid-cols-2` sub-grid for its type dropdown + record picker. Without claiming any extra grid
width for that sub-split, both pickers rendered squeezed into a fraction of an already-narrow
single grid cell — barely usable. Fixed the same way `inline-items.stub`/`file-input.stub` already
solve this exact shape of problem: `morph-select.stub` now wraps the field in a `col-span-2` div,
so it claims two grid cells' worth of width instead of one. New regression test
(`test_generate_field_morph_select_is_wrapped_in_col_span_2`) plus a real live re-verification
against the `morphs-suite` fixture (screenshots of the Create form before/after, both dropdowns now
rendering at a proper width, confirmed via a full create-payment browser pass).

## v2.51.0 — 2026-08-09

### Added — polymorphic type-selector UI for `morphs` fields

`morphs[].targets[]` was a documented-only, generator-ignored config key — generated create/edit
forms always rendered `payable_type`/`payable_id` as a plain text input (the raw class name, typed
by hand) and a plain number input, regardless of what `targets` said (see `docs/examples/morphs.md`).

`targets` is now a typed config object (`{alias, model, module, label, option_label?}`, not a bare
string), and populating it drives two things: (1) a `Relation::morphMap()` registration generated
inside the *owning* model's own `boot()` (the model with the morph columns, e.g. `PaymentsModel` —
confirmed against the only real precedent in the codebase, `NotificationSubscriptionsModel::boot()`,
which registers its own targets on itself, not on each target model), and (2) a new `morph-select`
field_type rendering a real type dropdown (`Select2Field`) plus an API-backed record picker scoped to
the selected type (`ApiSelect2Field`), composed into a new hand-authored `MorphSelectField.vue`.
Modules with empty `targets` (every module generated before this release) keep rendering the exact
same plain-input pair — zero regression.

A critical ordering bug was found and fixed before it could ship broken: `IntrospectionToConfig::build()`
bakes the plain-pair-vs-morph-select decision into the frontend fields array immediately, using
whatever `targets` it's handed at that exact moment — merging real targets onto the *returned*
config's `morphs` key afterward (the existing round-trip-from-module.json pattern) never
retroactively fixes that already-baked decision. Fixed via a new `$meta['existing_morph_targets']`
key, consulted before the frontend fields are built, and a new public `IntrospectionToConfig::mergeMorphTargets()`
(single source of truth for the merge-by-name logic).

Because `Relation::morphMap()` is a single Laravel-global registry, not scoped per table, two
independently-generated `boot()` methods registering the same alias for two different models is a
real correctness bug (last-generated silently wins). Generation now hard-fails on this:
`make:modules-from-db` validates every `morphs[].targets[].alias` across the whole blueprint before
generating anything; `make:module` validates this run's aliases against every already-generated
sibling module's `module.json` on disk (a documented, narrower check than the bulk path's
full-blueprint visibility — see `docs/examples/morphs.md`).

New regression coverage: `MorphAliasValidatorTest` (7 tests), `IntrospectionToConfigMorphFieldsTest`
additions (the critical ordering fix, end-to-end through `build()`), `ModelGeneratorBootMethodTest`
(7 tests, including the duplicate-alias throw and the harmless-duplicate no-throw), `BaseComponentGeneratorTest`
additions (`morph-select` field/form/import generation) — 727/727 full suite. Live-verified end-to-end
against the `morphs-suite` fixture (Payments → Suppliers/Customers) against a real SYSTEM_SHELL
checkout: real migration, real 3-module generation, `Relation::getMorphedModel()` confirmed resolving
both aliases at runtime, the generated Create form inspected and confirmed correct, the real generated
PHPUnit suite green (19/19 for Payments, 488/488 for the whole app with the fixture installed), a
deliberately conflicting alias confirmed to hard-fail with an accurate message naming both sources,
and a full real-browser Playwright pass (type dropdown → record picker → submit → list → filter →
view → edit → delete), all cycles green.

## v2.50.0 — 2026-08-09

### Added — grouped overview columns are now reachable through real config, not just internal plumbing

`generateInformationSection()` gained an optional `$groups` parameter back in 2026-08-02 (one Card, N side-by-side divided columns instead of a single stacked list) — but nothing on the documented, real-world config path (`features.frontend.view.fields[]`) ever populated it. `mapViewFieldsToInformationFields()` silently dropped any `group`-shaped key on its input, and `ViewOverviewGenerator::generate()` always built a flat, ungrouped `fields` section. A module could not actually configure a grouped overview despite the renderer supporting it for over a week.

Added: an optional `group` key (any string label) on entries in `features.frontend.view.fields[]`. Fields sharing the same `group` value render together in their own labeled column; the overview card becomes an N-column grid (one column per distinct group, first-appearance order). Fields with no `group` key are unaffected — omitting `group` everywhere produces byte-for-byte identical output to before this existed. Mixing grouped and ungrouped fields in one `fields[]` puts every ungrouped field into its own unlabeled column alongside the labeled ones, rather than a separate flat card — documented explicitly in `docs/features-config.md` since it's the one non-obvious behavior here.

Also: `generateInformationSection()`'s groups now support an optional `label` rendered as a small header above the column (previously groups were always headerless columns) — omitted `label` still renders exactly as before, so the one other real consumer of the raw `$groups` param is unaffected.

New regression coverage: `test_map_view_fields_preserves_group_key_on_plain_and_relation_fields`, `test_bucket_view_fields_into_groups_returns_flat_fields_when_none_are_grouped`, `test_bucket_view_fields_into_groups_buckets_by_group_label_preserving_first_seen_order`, `test_bucket_view_fields_into_groups_buckets_ungrouped_fields_into_their_own_unlabeled_column`, `test_grouped_overview_column_renders_its_label_when_set`, `test_end_to_end_view_fields_with_group_key_produce_a_grouped_overview_section` (`BaseComponentGeneratorTest`). Verified live against a real nested SYSTEM_SHELL module (`LocationTypes`) via a temporary path-repo override — confirmed the grouped, labeled column renders correctly in the generated `DetailsOverviewPage.vue`.

## v2.49.0 — 2026-08-09

### Fixed — `inline_items`' Add/Edit modal rendered zero visible fields

Found while capturing documentation screenshots of `orders-suite` — the exact fixture this bug lives in. `buildInlineItemFieldsJs()` emitted an `inline_items[].fields[]` entry's config `type` (`'text'`/`'number'`/`'boolean'` — the semantic value every fixture and every docs example uses) straight through as the generated wrapper's `type:` prop. `InlineItemsFieldRenderer.vue` only recognizes WIDGET-selector values there (`'input'`, `'number-input'`, `'checkbox'`, `'date'`, `'select'`/`'api-select'`, `'textarea'`) — the same `type`/`field_type` split used everywhere else in this generator (see `IntrospectionToConfig::buildMorphFrontendFields()` for the identical pairing). None of the semantic values matched any of the renderer's cases, so every field in the Add/Edit modal silently rendered nothing at all. Confirmed live: opening a real "Add Item" modal generated from this exact config shape showed zero visible form fields.

Fixed: an explicit `field_type` key (matching every other field config surface's convention) is honored first; otherwise a small map covers `text`/`string` → `input`, `number` → `number-input`, `boolean` → `checkbox`. Anything already a recognized widget value (a config that had worked around the bug by hand) passes through unchanged — zero regression.

New regression coverage: `InlineItemsEndToEndTest::test_orders_order_items_wrapper_maps_semantic_type_to_the_real_widget_type` (against the real `orders-suite` fixture), `BaseComponentGeneratorTest::test_generate_inline_items_block_maps_semantic_type_to_widget_type` (the mapping table directly, including `boolean` and the `field_type` override).

## v2.48.0 — 2026-08-09

### Added — per-column default visibility for generated list pages

`ReportTable.vue` (SYSTEM_SHELL/FRONTEND) has shipped a `defaultVisible` prop and a "View" toolbar dropdown with per-column show/hide checkboxes for a while — project-wide, for every module — but nothing in the generator ever emitted it: `BaseComponentGenerator::generateColumnsFromListFields()` had no way to read a per-field "hidden by default" flag from config, so a module author could not configure this without hand-editing the generated `{Module}ListPage.vue`, which `--force` regeneration would then need to specifically preserve.

Added: an optional `defaultVisible` key on entries in `features.frontend.list.fields[]`, matching `ReportColumn.defaultVisible`'s own prop name 1:1. `generateColumnsFromListFields()` now emits `defaultVisible: false` on the generated column literal when a field sets it explicitly false — omitted entirely otherwise, so every already-generated file's output stays byte-for-byte unchanged (omission ⇒ visible, same contract `ReportColumn` already uses). Never emitted for the primary/pinned column: `ReportTable.vue` excludes fixed columns from its configurable-columns list (always shown, never hideable), so the flag would be a silent no-op there.

This only sets the *starting* visibility — a user can still toggle any column back on via the existing "View" dropdown. Persisting that per-user choice across page reloads is a separate, SYSTEM_SHELL-side (not generator) change.

New regression coverage: `ListPageGeneratorTest::test_field_with_no_defaultvisible_key_omits_it_from_the_emitted_column`, `test_field_with_defaultvisible_false_emits_it_on_the_generated_column`, `test_defaultvisible_false_on_the_primary_field_is_not_emitted`.

## v2.47.0 — 2026-08-08

Same combined-suite exercise as v2.46.0 (all 5 integration-test suites scaffolded simultaneously against a real consuming project), taken further: the full Playwright e2e suite run against that combined state, not just PHPUnit. Found 5 real bugs, all invisible to isolated per-suite testing — either because they only trigger in combination with another module's state, or because nothing had exercised the affected code path against a real browser before.

### Fixed — `inline_items` broke every Create/Edit form it was added to with a hard Vue SFC compile error

`CreateFormGenerator`/`EditFormGenerator` appended each `inline_items[]` entry directly onto `$formFields` (`"\n\t{$item['key']}: [] as any[],"`), but `BaseComponentGenerator::generateFormFields()` joins regular fields with `",\n"` and never trails the last one with a comma. The result: `notes: ''` immediately followed by `order_items: [] as any[],` with nothing separating them — a hard `[vue/compiler-sfc] Unexpected token, expected ","` that broke `OrdersCreateForm.vue`/`OrdersEditForm.vue` outright, and, via Vite's global HMR error overlay, cascaded into unrelated modules' e2e test failures within the same dev-server session (confirmed live: an `OrderItems` e2e run failed on Orders' compile error despite never navigating to Orders' pages).

Fixed: both generators now append a trailing comma to `$formFields` before the `inline_items` loop when it's non-empty.

New regression coverage: `CreateFormGeneratorTest` (new file), `EditFormGeneratorTest::test_inline_items_field_is_comma_separated_from_the_last_regular_field`.

### Fixed — a `select_paginated` filter field with no resolvable related module was permanently broken

`generateFilterFields()`'s introspection fallback classifies any `_id`-suffixed column as a foreign key (`isForeignKey()`), including file/media reference columns (e.g. `image_media_id`) that aren't a relation the module registry can resolve. `buildSelectPaginatedFilterFieldConfig()` already returned `[]` for exactly this case (no `relatedModule`), but the caller emitted `'type' => 'select_paginated'` regardless — `DataTableFilter.vue` binds `:api-url="field.api_endpoint || ''"`, so with no `api_endpoint` every search on that filter fires against the bare API base URL and always fails. Confirmed live: a freshly generated `ItemImages` module's `image_media_id` filter threw `GET /api?page=1&per_page=20`, `net::ERR_FAILED`, on the very first list-page load.

Fixed: when `buildSelectPaginatedFilterFieldConfig()` returns no config, the field falls back to a plain `'number'` filter instead of a permanently-broken `select_paginated` one.

New regression coverage: `BaseServiceGeneratorTest::test_filter_fields_fallback_fk_column_with_no_related_module_falls_back_to_number` (replaces a test that previously asserted this exact breakage as expected behavior).

### Fixed — every `'date'` field's generated e2e test assumed a native `<input type="date">` that no longer exists

`renderFieldFill()`'s default branch (create step, and the edit step's scalar-field branch) called `fillField()`/`setInputValue()` — both require a fillable/readable `<input>`. SYSTEM_SHELL's date fields have rendered through the shadcn-vue `DatePickerField.vue` popover+Calendar component (`id={fieldId}` on a `<button>`, not an `<input>`) since that migration landed. Confirmed live: `fillField()` threw `"Element is not an <input>, <textarea>, <select>..."` on a freshly generated module's date field, on the very first create attempt — a hard failure for every module with a required `'date'` field, not a flake.

Fixed: a new `fillDatePickerField(page, dialogSelector, fieldId, dayOffset)` helper (mirrors the hand-written one already proven in a real project's `users-crud.e2e.js`) drives the popover's calendar grid instead — `dayOffset: 0` clicks `[data-today]` for the create step's value, any other offset locates the cell by day-of-month (advancing the calendar's "next month" control first if the target date crosses a month boundary) for the edit step's value.

New regression coverage: `PlaywrightTestGeneratorTest::test_date_type_field_uses_the_calendar_popover_helper_not_a_plain_fill`.

### Fixed — a numeric field's generated test value could exceed its own column's capacity

Every numeric field's create/edit value is a fixed formula, `(1000000 + (stamp % 900000))` / `(2000000 + (stamp % 900000))` — always a 7-digit integer. That fits a `decimal(12,2)` or plain `integer` column, but a narrower decimal — `unit_price decimal(10,4)` (6 integer digits, max `999999.9999`) — can never hold it. Confirmed live: a freshly generated `OrderItems` create request 500'd with `SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column 'unit_price'`.

Fixed: a new `constrainNumericExpr()` helper (the numeric counterpart to the existing `constrainToColumnLength()`, which already solves this for string fields) clamps the value to the column's `precision - scale` capacity when known — a plain integer/bigint column carries no `precision` key at all (`IntrospectionToConfig::buildColumn()` only threads it for a real decimal column) and is left at the original formula.

New regression coverage: `PlaywrightTestGeneratorTest::test_numeric_field_value_is_clamped_to_a_narrow_decimal_columns_capacity`.

### Fixed — the generated bulk-action/import e2e steps raced the result drawer's close animation

Both blocks closed the `batch-result-drawer` with `page.keyboard.press('Escape')` followed by a blind `sleep(500)`, then immediately proceeded to the next step. Under load, the Sheet's close animation can still be mid fade-out past 500ms — confirmed live against a freshly generated `PurchaseOrders` module: the very next click retried for the full 15s Playwright actionability timeout against `<div data-slot="dialog-overlay"> intercepts pointer events` because the drawer had not actually finished closing.

Fixed: both blocks now wait for the drawer element itself to reach `state: 'hidden'` instead of guessing a fixed delay.

New regression coverage: `PlaywrightTestGeneratorTest::test_bulk_action_and_import_blocks_wait_for_the_drawer_to_actually_close`.

Full generator-engine suite: 690/690 passing (up from 682 at the start of this investigation).

## v2.46.0 — 2026-08-08

Found while running all 5 integration-test suites simultaneously against a real consuming project for the first time (previously always verified one suite at a time, with teardown between each) — a check specifically aimed at catching cross-module collisions that per-suite isolation can't surface.

### Fixed — generated seeders redundantly (and, for disabled features, incorrectly) called `Helpers::saveModuleCRUDPermissions()`

`seeder.stub`'s generated `permissions()` method called BOTH the unconditional `Helpers::saveModuleCRUDPermissions($moduleName)` (creates all 5 standard CRUD permissions unconditionally) AND looped `$this->jsonData['permissions']`, which `SeederGenerator::mergeListPermissions()` already builds as a strict superset — every CRUD action `saveModuleCRUDPermissions()` creates (but only for features actually *enabled*, unlike the unconditional call), plus `bulkAction`/`import`/custom-action permissions the older helper doesn't know about at all.

`savePermission()` is idempotent (checks existence before insert), so this redundancy was harmless under normal single-pass seeding — every one of a real consuming project's existing modules has carried this exact pattern without incident. It surfaced as a hard `UniqueConstraintViolationException` only in combination with an unrelated, consumer-side incomplete-teardown bug (a phantom soft-deleted permission row, invisible to normal queries but still holding its `name` slot in the unique index) — but the redundant call was worth removing on its own merits regardless: fewer wasted queries per seed, and no more over-provisioning a permission for a feature that's actually disabled.

Fixed: removed the `Helpers::saveModuleCRUDPermissions()` call from `seeder.stub`. `Helpers::saveModuleCRUDPermissions()` itself is untouched — it remains available for a hand-written (non-scaffolded) module.

New regression coverage: `SeederGeneratorNoRedundantCrudCallTest::test_generated_seeder_does_not_call_the_unconditional_crud_permissions_helper`, `test_generated_seeder_never_mentions_the_helper_regardless_of_which_features_are_enabled`.

## v2.45.0 — 2026-08-08

Follow-up to v2.44.0's live-verification pass, scoped specifically to the actions/delegations/CRUD mechanisms already in active use (checked against all 17 real modules in the primary consuming project before doing any of this — everything else audited that day, e.g. Mobile App generation, Ux composites/wizards, `processors`, non-default `connection`, has zero real usage today and was deliberately left alone).

### Fixed — `PhpUnitTestGenerator::regenerateOnly()` had no `'bulk_action'` branch

`regenerateOnly(string $key, string $kind)` — the scoped single-key regeneration path used when a single new delegation/action is added to an already-scaffolded module — only ever branched on `$kind === 'delegation'` or `$kind === 'action'`. A `'bulk_action'` kind silently fell through to the unconditional `return false;`, with no error, even though `PhpUnitTestGenerator` already has everything needed to build a bulk action's contract test (`allGenericBulkActionKeys()`, `buildBulkActionIdsModeTestMethod()`, `buildBulkActionFilterModeTestMethod()`) — that machinery just wasn't wired into the scoped-regeneration entry point.

Fixed: added the `'bulk_action'` branch, looking up the matching `features.backend.list.bulk_actions[]` entry by key (same `status_target`-exclusion eligibility rule as `allGenericBulkActionKeys()`) and writing its test file via `writeSplitFileAlways()`, identically to the existing `'delegation'`/`'action'` branches.

New regression coverage: `PhpUnitTestGeneratorTest::test_regenerate_only_writes_exactly_the_target_bulk_action_file_and_nothing_else`, `test_regenerate_only_returns_false_for_a_status_target_bulk_action`.

### Cleaned up — `ModelGenerator::determineModuleGroup()` was dead code

`generateAutoRelationshipsFromForeignIds()` and `resolveManualRelationModuleGroup()` (the hand-authored `relations.hasMany[]`/`relations.belongsToMany[]` path) both computed a `module_group` value via `determineModuleGroup()` (~100 lines of registry/namespace-parsing fallback logic) and stored it on each relationship array. That value was threaded through `generateRelationshipMethod()` into `generateNamespacedClass($moduleName, $moduleType, $moduleGroup)` — which never actually read its third parameter: the self-referential branch uses `$this->moduleGroup` directly, and the general branch calls `PathManager::resolveBackendModuleNamespace($moduleName)`, taking only the module name. The whole `module_group` computation was dead from the point it was produced to the point it was silently discarded.

Not a behavioral bug (nothing consumed the wrong value, because nothing consumed it at all) but a real landmine: a future refactor that started trusting `$relationship['module_group']` again — reasonably, since it looks like real threaded config — would silently reintroduce exactly the kind of namespace-resolution bug the real resolver (`PathManager::resolveBackendModuleNamespace()`, fixed in v2.44.0) already handles correctly.

Removed `determineModuleGroup()` entirely, dropped `module_group` from all four relationship-array call sites, dropped the now-unused third parameter from `generateNamespacedClass()`, and renamed `resolveManualRelationModuleGroup()` to `assertManualRelationModuleResolves()` (its only remaining job — and the only thing it was ever actually needed for — is the fail-loud `guessedModuleExists()` validation for a hand-authored, human-typed module reference).

Full generator-engine suite (682 tests) and all `Delegation`-tagged tests re-confirmed green after the refactor — this class's relationship-generation behavior is unchanged, only the dead intermediate value is gone.

### Added — `actions-suite`'s second action, regression-locking the v2.44.0 coverage-gating rule against a real generated module

`actions-suite` (the integration-test fixture behind the [Custom Actions](/examples/actions) cookbook page) previously exercised only one `urlParams` shape (`['uuid']`, the one case that gets PHPUnit contract-test coverage). Added `archiveByYear`, a second action with `urlParams: ['uuid', 'year']` — the exact multi-param shape v2.44.0's fix left uncovered by design. Confirmed live: `Routes/api.php`/`Services/PurchaseOrdersArchiveByYearService.php`/the frontend form all scaffold correctly, and `Tests/PurchaseOrdersArchiveByYearServiceTest.php` is never written — the behavior generator-engine's own synthetic unit test already asserted, now also regression-locked against a real, permanently-reusable fixture.


## v2.44.0 — 2026-08-08

Five bugs found while systematically re-verifying all 5 integration-test suite fixtures (items/orders/morphs/delegations/actions) end-to-end against a real consuming project — each confirmed live (regeneration + real DB + real generated tests), not just by reading the generator source.

### Fixed — `morphs`: creating a record with a polymorphic relationship was impossible through the generated API

`IntrospectionToConfig::build()` deliberately strips a morph pair's two columns (`{prefix}_type`/`{prefix}_id`) out of `$userColumns` before `buildFeatures()` runs, so they never clutter list/filter/table UI (which has no generic polymorphic rendering) — but nothing ever put them back for create/edit, despite a doc comment on `build()` claiming `CreateServiceGenerator`/`EditServiceGenerator` already consumed the top-level `morphs` key for exactly this ("validation-rule override... logic"). They never did. Every one of `CreateService`'s validation rules, the frontend `CreateForm.vue`, and the generated PHPUnit create-test payload derives from `features.backend/frontend.create.fields` — with morph columns absent from all three, `PaymentsModel::create()` failed with `Field 'payable_type' doesn't have a default value` on a freshly-scaffolded `morphs-suite` module.

Fixed: new `buildMorphBackendFields()`/`buildMorphFrontendFields()` append plain (string, integer) field entries for each morph pair directly onto `create`/`edit` — not `list`/`view`/filter, preserving the original UI-clutter avoidance. Deliberately stops short of a full polymorphic type+FK picker (real feature work, not a bug fix) — `payable_type` is a plain text input, `payable_id` a plain number input, and `payable_id` gets no `exists:` validation rule since a polymorphic id can reference any of several tables with no way to know which without hand-authored `targets` config.

New regression coverage: `IntrospectionToConfigMorphFieldsTest` (3 tests) — morph columns get validation rules on create/edit, get frontend create-form fields, and stay excluded from list/filter fields.

### Fixed — `morphs`: the generated `ViewServiceTest` failed for a whole-number decimal fixture value

`buildViewTestMethod()`'s single-field assertion used strict `assertJsonPath('data.field', $fixture->field)` for every field type, including decimal/float/double columns. `ModelGenerator::getCastType()` casts those to PHP `'float'`, so a whole-number fixture value is `float(1.0)` — but the same value round-tripped through the HTTP response's `json_encode()`/`json_decode()` collapses to `int(1)`, and `assertJsonPath()`'s strict `===` fails comparing `int(1) !== float(1.0)`. The sibling `buildDecimalPrecisionTestMethod()` already sidesteps this exact issue with `assertEqualsWithDelta()` for its own decimal assertion — `buildViewTestMethod()` just never got the same treatment.

Fixed: `buildViewTestMethod()` now detects a decimal/float/double first field the same way it already detects a date-like one, and asserts it with `assertEqualsWithDelta()` in a separate statement instead of the strict-equality chain.

New regression coverage: `PhpUnitTestGeneratorTest::test_view_test_uses_delta_comparison_for_a_decimal_shaped_first_field`.

### Fixed — `inline_items`/general FK relations: scaffolding a child module before its FK-target parent exists silently guessed the wrong namespace type

Confirmed live on `orders-suite`: scaffolding `OrderItems` (FK to `Orders`, `Custom`-grouped) before `Orders` had ever been scaffolded — the order `inline_items` itself requires — produced a generated `belongsTo()` relation pointing at `\App\Project\Modules\Core\Orders\OrdersModel::class` instead of the real `Custom` namespace, with no way to tell from the generated code alone that it was wrong.

Root cause: `PathManager::resolveBackendModuleNamespaceOrNull()` only ever checked (1) the in-memory array-based module registry and (2) `default_modules.json` (SYSTEM_SHELL-shipped modules only) before its caller fell through to a bare `Core` guess. `ModelGenerator::guessedModuleExists()` (used only for FK names *guessed* from a column name with no real FK constraint) already had a fuller resolution chain — it also checks the generated project's own persisted `registry_core.json`/`registry.json` files and the actual on-disk module directory — but a module name resolved from a **real** FK constraint (the higher-confidence, common case) never got that same treatment.

Fixed: `resolveBackendModuleNamespaceOrNull()` gained a third resolution step reading `registry_core.json`/`registry.json` directly (each entry already carries a fully-resolved `namespace` string). This does **not** fix the exact live-reproduced ordering case — at the moment `OrderItems` is scaffolded, `Orders` genuinely doesn't exist in any registry file yet, so there's nothing for the new step to find; that specific case still only self-corrects by regenerating the child with `--force` after the parent exists (now documented in `orders-suite`'s README, with an explicit callout that this is a *different* bug from the `inline_items`-specific one `BaseServiceGenerator::buildChildNamespace()` already handles). What this does fix is the more general case: the target module *was* already scaffolded in an earlier, separate invocation, but the in-memory array registry — rebuilt fresh per CLI invocation from a filesystem snapshot — happened to miss it while the persisted registry file already had the right answer. The last-resort `Core` guess's warning message (`PathManager::reportIssue()`) is also now actionable, explicitly naming the `--force` regenerate-after-parent-exists fix instead of just noting the generated PHP "may fail to resolve."

New regression coverage: `PathManagerRegistryFallbackTest` (5 tests) — resolves from `registry.json`, resolves from `registry_core.json`, `resolveBackendModuleNamespace()` no longer falls back to Core when the registry file has the answer, still returns null when genuinely unresolvable anywhere, and the array registry still wins over the registry file when both are present.

### Fixed — `delegations`: the generated create test asserted the wrong HTTP status code

`buildDelegationCreateTestMethod()`'s generated `test_can_create_{delegation}_delegation_item()` asserted `assertStatus(200)`, but the real generated Create endpoint correctly returns `201` — every other Create test across the whole generator (the main-module `CreateServiceTest`, etc.) correctly asserts `201`. One-line fix.

### Fixed — `actions`: a single-row custom action with `urlParams` or a custom `endpoint.path` — i.e. virtually every real one — got zero PHPUnit coverage

`buildActionServiceTestMethodsForKey()` unconditionally returned `[]` (no contract test at all) whenever an `actions[]` entry had `urlParams` set or a custom `endpoint.path` — but a single-row action has to address one specific record somehow, so nearly every real custom action (e.g. an "Approve" action on one Purchase Order, `urlParams: ['uuid']` + `endpoint.path: '/purchase-orders/{uuid}/approve'`) hit this bail-out. Confirmed live on `actions-suite`: `PurchaseOrdersApproveServiceTest.php` was never generated at all, despite the sibling `bulk_actions` mechanism (`PurchaseOrdersArchiveServiceTest.php`) getting full coverage on the same module.

Fixed: `buildActionServiceTestMethodsForKey()` now mirrors `RoutesGenerator::generateActionRoutes()`'s own path-building for the single most common real-world shape — `urlParams: ['uuid']`, with or without a custom `endpoint.path` that embeds `{uuid}` — substituting a real fixture's uuid into the generated test's path expression. Every other `urlParams` shape (multiple params, or a param other than `uuid`) still returns `[]` rather than guess, unchanged from before.

New regression coverage: `PhpUnitTestGeneratorTest::test_generate_emits_action_contract_test_for_uuid_parametrized_route`.


## v2.43.0 — 2026-08-07

### Added — `ModuleConfigContract::isMobileAppEnabled()`, the config-level half of making Mobile App scaffolding opt-in

New `isMobileAppEnabled(array $config): bool`, reading `$config['features']['mobile_app']['enabled'] ?? false` — mirrors `isModelHandMaintained()`'s existing pattern (a developer *declaration*, not an introspected fact, defaulting to false/off).

This is the config-contract half of a change completed on the SYSTEM_SHELL side: every `make:module`/`make:modules-from-db` run used to scaffold a Mobile App backend counterpart (Model/Migration/Seeder/Controller/Services/Routes/Registry under the offline-sync mobile app) unconditionally, regardless of whether it was wanted. Confirmed live across a full session of module ports — the Mobile App counterpart fired, unwanted, on literally every single regenerate, requiring a manual revert (`rm -rf` the new module directory + `git checkout` the touched `registry.json`) every time. `features.mobile_app.mode` (`online`/`offline`/`both`) already existed to configure *how* mobile generation behaves once enabled, but nothing gated *whether* it ran at all.

SYSTEM_SHELL's `ModuleScaffolder::generate()` now gates its entire "Mobile App Backend" section behind this flag, `make:module` gained a `--mobile` opt-in flag (only ever turns it on, never explicitly off — so a module doesn't lose the flag on a later `--force` run that omits it), `make:modules-from-db` gained the equivalent for a whole batch, and `mergePersistedFields()` carries `features.mobile_app.enabled` forward the same way it already did for `.mode`.

New regression coverage: `ModuleConfigContractTest::test_contract_is_mobile_app_enabled_defaults_false_when_key_absent()` / `test_contract_is_mobile_app_enabled_defaults_false_when_mobile_app_block_present_but_no_enabled_key()` / `test_contract_is_mobile_app_enabled_trusts_explicit_true()` / `test_contract_is_mobile_app_enabled_trusts_explicit_false()`.


## v2.42.0 — 2026-08-07

### Fixed — an introspection-fallback `select_paginated` filter field had no `api_endpoint`, so every search on it silently failed

Found live re-applying business logic onto SYSTEM_SHELL's freshly-regenerated `Locations` module: the list page's `location_type_id`/`parent_id`/`status_id` filter dropdowns fired `GET /api?page=1&per_page=20` on every search — a request against the bare API base URL, with no `/select/{Module}` path segment at all. `DataTableFilter.vue` binds `:api-url="field.api_endpoint || ''"`; with `api_endpoint` absent, the fallback `''` produces exactly that malformed request.

Root cause: `generateFilterFields()`'s introspection fallback (used whenever a module has no hand-authored `features.backend.list.filterFields` in `module.json` — the common case for any DB-introspected module) correctly derives `type: 'select_paginated'` for an FK column via `getFilterFieldType()`, but only ever built the extra config a `'select'` type needs (`buildFilterFieldOptions()`'s `{name, id}[]` list) — nothing was built for `'select_paginated'` at all. A code comment on `buildFilterFieldOptions()` claimed select_paginated "needs none of this; it loads its own options live via ApiSelect2" — empirically false, confirmed against the real component: it loads options live *from the endpoint `api_endpoint` points at*, which has to come from somewhere. Every hand-authored `select_paginated` filterField elsewhere in this codebase (e.g. `LocationTypesListService`'s own `location_type_id`/`parent_id`/`status_id` entries) already carries the full shape by hand; the introspection fallback path never did.

Fixed: new `buildSelectPaginatedFilterFieldConfig()`, called alongside `getFilterFieldType()` in the fallback loop whenever the derived type is `select_paginated`. Builds `api_endpoint` from the column's `relatedModule` (`/select/{RelatedModule}`, the same `SelectController` route every `ApiSelect2Field` already calls) plus `search_param`/`id_search_param`/`per_page`/`multiple`/`paginate`/`option_label`/`option_value`, matching the hand-authored shape exactly. A column with no `relatedModule` (shouldn't happen for a real FK, but defensively handled) gets no endpoint config added — same as before, rather than emitting a broken partial config.

New regression coverage: `BaseServiceGeneratorTest::test_filter_fields_fallback_fk_column_gets_a_working_select_paginated_endpoint()`, `test_filter_fields_fallback_fk_column_with_no_related_module_gets_no_endpoint_config()`.

This affects every module relying on the introspection fallback with at least one FK filterable column — worth a broader sweep across already-regenerated modules this session (not performed as part of this fix; SYSTEM_SHELL-side, module-by-module).


## v2.41.0 — 2026-08-07

### Reverted — v2.40.0's FK cell-renderer "fix" was wrong; the snake_case behavior it replaced was correct all along

v2.40.0 changed `generateCustomCellRenderersFromListFields()`'s `isFk` branch to camelCase the FK relation accessor (`location_type_id` → `locationType`), on the claim that Eloquent's `relationsToArray()` keys a loaded relation under the exact string it was accessed by. That claim was wrong, and the test that seemed to support it was flawed: it only ever exercised a relation whose method name was *already* snake_case (`location_type`), so "Eloquent preserves the loaded key" and "Eloquent snake_cases the key" predicted the identical result for that one input — the test couldn't actually distinguish the two hypotheses.

Re-verified properly this time, against a real relation whose method is genuinely camelCase (`locationType()`): `$model->load('locationType'); $model->toArray()` still produces the key `location_type`, never `locationType`. Root cause: Laravel's base `Model` class declares `public static $snakeAttributes = true`, and `HasAttributes::relationsToArray()` unconditionally runs `$key = Str::snake($key)` on every loaded relation's array key before it reaches `toArray()`/JSON — regardless of what the relation method is actually named, or what string it was loaded/accessed by. SYSTEM_SHELL doesn't override `$snakeAttributes` anywhere, so this is the real, active behavior.

This means `row.location_type` (snake_case, what v2.40.0 replaced) was correct the whole time. Reverted `$relationAccessor` back to the plain `preg_replace('/_id$/', '', $key)` strip, and the two `BaseComponentGeneratorTest` assertions v2.40.0 changed back to their original snake_case expectations.

The `RelationNotFoundException` that originally prompted v2.40.0 (found live regenerating SYSTEM_SHELL's `Locations` module) had a different, real root cause: `LocationsListService`/`LocationsViewService`'s freshly-regenerated `eagerLoadRelationships` assumed a camelCase relation *method* name (`locationType`, matching `ModelGenerator::deriveRelationshipMethodName()`'s current convention), but `LocationsModel` is `model_hand_maintained: true` and had kept an old snake_case method name (`location_type()`) from before that convention existed — a Service/Model method-name mismatch, unrelated to this renderer. The actual fix (SYSTEM_SHELL-side, not this package) was renaming the hand-maintained model's method to `locationType()` to match — which requires no frontend change at all, since the JSON key stays `location_type` either way per the `$snakeAttributes` behavior above.

Apologies for the churn — this should have been verified against a genuinely case-differing example before shipping v2.40.0, not generalized from a test case where both hypotheses happened to agree.


## v2.40.0 — 2026-08-07

### Fixed — every multi-word FK column's list cell renderer read a JSON key the API never returns

Found live regenerating SYSTEM_SHELL's `Locations` module: `location_type_id`'s FK cell renderer (`<RelatedRecordLink>`) read `row.location_type`, but the real API response keys the eager-loaded relation `locationType` — every FK badge/link on the list page silently rendered "N/A" for that column, and the underlying `->with(['locationType', ...])` eager-load in `LocationsListService`/`LocationsViewService` (correctly using the model's real relation method name) threw `RelationNotFoundException` the moment `LocationsModel`'s hand-maintained relation method itself got renamed to match.

Root cause: `generateCustomCellRenderersFromListFields()`'s `isFk` branch derived `$relationAccessor` via a bare `preg_replace('/_id$/', '', $key)` — stripping the `_id` suffix but never converting the remainder to camelCase. `ModelGenerator::deriveRelationshipMethodName()` (which actually names the `belongsTo()` method on the generated Model) always camelCases: `location_type_id` → `locationType`, `apk_media_id` → `apkMedia`, `payment_method_id` → `paymentMethod`. A code comment on the buggy line claimed "Eloquent's relationsToArray() snake-cases the JSON key anyway" — empirically false: Eloquent keys a loaded relation under the exact string it was accessed/loaded by (confirmed via `->load('location_type')` on a model whose method actually is `location_type()` — the key is `location_type` only because the method itself already was, not because of any case conversion). The mistaken belief traced to one hand-completed reference file (`LocationsListPage.vue`, before this session's port) whose Model happened to still use an old snake_case method name, so no case mismatch was ever visible to compare against.

Single-word FK columns (`status_id` → `status`, `role_id` → `role`) were never affected — camelCase and snake_case coincide when there's only one word, which is why this went unnoticed until a genuinely multi-word FK column was exercised live.

Fixed: `$relationAccessor` now runs through `lcfirst(Str::camel(...))` after the `_id` strip, matching `deriveRelationshipMethodName()` exactly. Applies to both the standalone `ListPageGenerator` output and delegation tab components, since both share this method.

Existing `BaseComponentGeneratorTest` coverage updated (`test_fk_field_relation_accessor_camel_cases_the_stripped_id_suffix`, formerly asserting the buggy snake_case output; `test_real_locations_fixture_fk_fields_each_produce_a_related_record_link_cell`'s `location_type_id` assertion) — single-word FK assertions (`status`, `parent`, `role`, `category`, `order`) were already camelCase-compatible and needed no change.


## v2.39.0 — 2026-08-07

### Fixed — a delegation tab's frontend route guarded on a permission that's never seeded, blocking navigation for every role

Found live wiring `Locations.UserLocations` (SYSTEM_SHELL's `Locations` module delegating a related-record tab to `UserLocations`, scoped by `location_id`): the tab's backend endpoints were all correctly reachable (`permission:UserLocations.list`/`.view`/`.delete`, via `RoutesGenerator::generateDelegationRoutes()` → `DelegationConfigNormalizer::resolveOperationPermission()`), but clicking the tab in the browser never loaded — the frontend router guard rejected it for every role, including `DEVELOPER`.

Root cause: `FrontendRoutesGenerator::generateCustomFeatureRoutes()` (its `$customFeatures` property is literally `config['delegations']` — "custom feature" is a legacy name for the same config) hardcoded the tab route's `meta.permission` as `"{$this->moduleName}.{$FeatureName}.list"` — e.g. `Locations.UserLocations.list` — instead of calling `DelegationConfigNormalizer::resolveOperationPermission()` like every other delegation code path does. `resolveOperationPermission()`'s whole design deliberately reuses the *related* module's own permission (`UserLocations.list`) so a role granted on a module works identically whether it's reached via that module's own list page or embedded in a parent's delegation tab — but this one call site never adopted that convention, so no permission record anywhere could ever satisfy both the backend guard and the frontend guard for the same delegation.

Fixed: `generateCustomFeatureRoutes()` now calls `DelegationConfigNormalizer::resolveOperationPermission($relatedModuleName, 'list', $listEndpoint)`, the exact same call `RoutesGenerator` makes for the backend route — reading `relatedModule.name` and `operations.list.endpoint` off the same delegation config both generators already receive. An explicit `operations.list.endpoint.permission` override, if a delegation ever declares one, is honored identically on both sides.

New regression coverage in `FrontendRoutesGeneratorTest`: `test_generate_uses_related_modules_own_permission_for_delegation_tab_route()`, `test_generate_respects_an_explicit_permission_override_for_delegation_tab_route()`.

### Changed — a module's pinned primary list column no longer renders wider than every other column

`generateColumnsFromListFields()` gave the primary column (the one pinned via `fixed: true` while scrolling) a hardcoded `width: 240`, while every other column in the same table used `width: 150`. On a table with several columns, the wider pinned column crowded the rest, leaving little room for the remaining columns to wrap text legibly. Now `width: 150`, matching every other column — same fixed-left pinning behavior, just no longer disproportionately wide. Applies to both the standalone `ListPageGenerator` output and delegation tab components (`DelegationTabComponentGenerator`/`CustomFeatureTabComponentGenerator`), since both share this same method.

Existing `BaseComponentGeneratorTest` assertions updated to expect `width: 150` for the primary column; no new test needed since the existing suite already asserts the primary column's exact generated string.

## v2.38.0 — 2026-08-07

### Fixed — `--force` regenerate silently wiped real seed data when module.json never declared it

Found live while porting SYSTEM_SHELL's `Countries` module: a routine `make:module Core/Locations/Countries --force` regenerate rewrote `CountriesSeederData.json`'s `data` array from its real 252-row ISO-3166 country list down to `[]`. The identical failure mode had already, independently, bitten Users' 2 bootstrap-account seed rows earlier in the same modernization pass (fixed there by hand-restoring the rows, without diagnosing a root cause at the time).

Root cause: `SeederGenerator`'s `$seedData` is sourced exclusively from `$config['seeder']['data']` — module.json's declared seed rows. In practice, real seed data is routinely hand-added directly to the already-generated `{Module}SeederData.json` file and never round-tripped back into module.json (confirmed for both Countries and Users — both modules' `module.json` still declares an empty `seeder`, despite the JSON data file carrying real rows). `generateJsonData()` unconditionally overwrote that file with `json_encode(['data' => processSeedData(), ...])` on every run; since `processSeedData()` iterates the empty config-only `$seedData`, this silently wrote `"data": []` over the real rows — no error, no warning, the regenerated seeder simply seeded nothing on the next fresh migrate.

Fixed: new `resolveSeedData()` preserves an existing, non-empty on-disk `data` array when config declares none, while still letting non-empty config data win outright — matching the same "config is authoritative when present, but a --force run must never silently destroy hand-added content it doesn't know about" principle already established by `writeFileOnce()` (the InlineItems wrapper-component fix, 2026-08-02). `generateSeederClass()`'s own `$jsonData` computation is untouched — confirmed dead code: `seeder.stub` never references the `[[jsonData]]` placeholder it's passed through, since the generated `{Module}Seeder.php` class reads `{Module}SeederData.json` at runtime instead of embedding data inline.

New regression coverage in `SeederGeneratorPreservesExistingDataTest`: `test_force_regenerate_with_empty_config_seed_data_preserves_existing_nonempty_file_data()`, `test_nonempty_config_seed_data_still_wins_over_existing_file_data()`, `test_brand_new_module_with_no_existing_file_and_no_config_data_writes_empty_array()`, `test_existing_file_with_empty_data_array_is_not_treated_as_preservable()`.

## v2.37.0 — 2026-08-07

### Fixed — generated e2e specs tried to `.fill()` Select2/ApiSelect2 filter controls and nonexistent JSON-column form fields

Found live running `PlaywrightTestGenerator`'s output against real, freshly-regenerated modules this session (Roles, UserLocations). Two independent bugs, both root-caused against the real rendered DOM rather than assumed from config.

**Bug 1 — the filter-panel fill step never learned about `select_paginated`/`select`.** `resolveFilterFields()` derives `features.backend.list.filterFields` from `filterableFields` whenever a module leaves the former empty (the common case for an introspected module) — and until now hardcoded every derived entry to `'type' => 'text'`. That exactly mirrored `BaseServiceGenerator::generateFilterFields()`'s own fallback... until 2026-08-03/04, when THAT method was fixed to derive a real per-column type via `getFilterFieldType()` (an FK column now renders as `'select_paginated'`, an enum/boolean as `'select'` — see that method's own docblock). `resolveFilterFields()` was never updated to match, so it silently drifted out of sync with the filter panel it exists to drive. Confirmed live: UserLocations' `user_id`/`location_id`/`role_id` FK filterable columns looked like plain text filters to `pickTextFilterField()`/`buildFilterVariantB()`, which called `setFilterTextValue()` against a Select2/ApiSelect2 control that renders as a `<div class="select2-container">`, not an `<input>` — Playwright threw `resolved to a <div>, not <input>` (`setFilterTextValue()`'s own guard in `filters.js`).

Fixed: new `inferFallbackFilterFieldType()`, ported from `BaseServiceGenerator::getFilterFieldType()`/`isForeignKey()`, gives `resolveFilterFields()`'s fallback the same FK/enum/boolean/date/number awareness the real filter panel now has. `pickVisibleFilterField()` threads the resolved type through, and `buildFilterVariantB()` dispatches on it — a `'select'`/`'select_paginated'` field now routes to a new `buildFilterVariantBSelect()`, which drives a new `setFilterSelect2Value()` helper (added to SYSTEM_SHELL/FRONTEND's hand-maintained `e2e/helpers/filters.js`, since that file is entirely hand-maintained — `PlaywrightTestGenerator` only ever imports from it, never writes it) instead of `setFilterTextValue()`. `setFilterSelect2Value()` opens the picker, types into its search box, clicks the option matching the target row's currently-displayed value, and — since `DataTableFilter.vue` forces an FK's `ApiSelect2` into multi-select mode whenever the field config omits `multiple: false`, requiring an explicit "Confirm" click, while a static enum/boolean `Select2` auto-closes on a single-value operator — handles both interaction shapes generically rather than assuming one.

A second, unrelated instance of the same "config didn't know a field renders as Select2" class of bug was found and fixed as plain data correction rather than a generator change: SYSTEM_SHELL's `Roles/module.json` carried `field_type: 'input'` for `role_type`, even though the hand-authored `RolesCreateForm.vue`/`RolesEditForm.vue` render it as a splash-backed `Select2Field` (a fixed system/custom option set, not a DB enum — `SchemaIntrospector` has no signal to detect it automatically). `PlaywrightTestGenerator`'s existing `field_type`-based dispatch (`SELECT_FIELD_TYPES`, unchanged by this release) was already correct; the config it was reading was stale. Corrected `role_type`'s `field_type` to `'select'` in both `create.fields` and `edit.fields`.

**Bug 2 — a JSON/array-typed column got a plain `'input'` create/edit form field with no real control behind it.** `IntrospectionToConfig::buildFrontendFormFields()` had no branch for `normalized_type === 'json'` at all, so a JSON column fell through to the default `'input'`/`'text'` case — identical treatment to a plain varchar. Confirmed live: UserLocations' `roles`/`granted_permissions`/`denied_permissions` JSON columns ended up with `field_type: 'input'` entries in `module.json`, despite having zero fillable representation anywhere in the real, hand-maintained `UserLocationsCreateForm.vue`/`EditForm.vue` — both carry an explicit comment that those three columns are managed exclusively via the dedicated Manage Roles/Manage Permissions endpoints, never as raw text input. The generated e2e spec dutifully tried to `fillField()` `#roles`/`#granted_permissions`/`#denied_permissions`, none of which exist in the DOM.

Fixed in two layers. `buildFrontendFormFields()` now excludes a JSON-typed column from `create`/`edit` fields entirely — consistent with `NON_FILTERABLE_TYPES` already excluding `'json'` from the filterable/sortable lists elsewhere in this same class; a JSON column simply has no generic UI representation, in either direction. Because a scoped e2e-only regenerate does not rewrite an already-persisted `module.json` (only a full `--force` module regenerate re-introspects it), `PlaywrightTestGenerator` also gained its own independent guard: new `excludeJsonColumnFields()`, applied to `$this->createFields`/`$this->editFields` in the constructor, cross-references `config['columns']` (the same source `constrainToColumnLength()` already reads) and strips any field whose column is JSON-typed — regardless of what `field_type` a stale config entry claims. Filtering at the constructor means every downstream method that reads those two properties (`pickAnchorField()`, `pickEditField()`, `buildFieldDeclarationsBlock()`, `buildFieldFillLines()`, and friends) is fixed by the one change, not patched individually.

New regression coverage: `IntrospectionToConfigTest::test_json_column_gets_no_create_form_field()` / `test_json_column_gets_no_edit_form_field()` / `test_plain_string_column_alongside_a_json_column_is_unaffected()`, and `PlaywrightTestGeneratorTest::test_fk_filterable_column_derived_from_filterable_fields_uses_the_select2_filter_helper()` / `test_plain_text_filterable_column_derived_from_filterable_fields_still_uses_plain_text_filter_value()` / `test_json_column_field_is_excluded_from_the_create_fill_step()`.

## v2.36.0 — 2026-08-06

### Fixed — FK-typed primary field showed a raw numeric id everywhere instead of the related record's name

Found live while porting SYSTEM_SHELL's `UserLocations` module: a pure junction/assignment table with no `name`/`title` column of its own. `IntrospectionToConfig::detectPrimaryFieldFromColumns()` falls back to "the first column" when a table has no non-FK string column — for `user_locations` that's `user_id`, a foreign key. Every generated surface that shows "the field identifying this record" assumed the primary field was always a plain scalar and broke the same way once it was an FK instead:

**Bug 1 — the List page's own pinned/primary column.** `ListPageGenerator` (`features/list/page.stub`) pins the primary field's `ReportColumn` via `fixed: true`, but relies entirely on `BaseComponentGenerator::generateCustomCellRenderersFromListFields()` for any FK/badge/boolean display logic — and that method unconditionally skipped generating a renderer for the primary field, on the (previously always true) assumption that a primary field is plain text needing no special rendering. With no `<template #cell-{key}>` override, `ReportTable.vue`'s default `row[col.key]` fallback rendered the bare FK id. Every *non-primary* FK column already got a proper `RelatedRecordLink` renderer — only the primary one, uniquely, didn't. Fixed: `generateCustomCellRenderersFromListFields()` gained an `$includePrimaryKey` parameter; `ListPageGenerator` passes `true` (its `page.stub` has no separate primary-column handling at all), while `CustomFeatureTabComponentGenerator` keeps the original `false` (its `tab_action.stub` already hand-emits its own dedicated primary-column cell block, so a second renderer for the same slot would be a broken duplicate). A plain scalar primary field still gets no renderer either way — byte-identical output for the overwhelming majority of modules.

**Bug 2 — every generated "title" of the record.** `IntrospectionToConfig::buildFrontendFeatures()` set `view.titleData` to the *bare* primary field name, even when that field is an FK. Every consumer of `titleData` just does `record?.{titleData}` with no FK-awareness of its own — `ViewLayoutGenerator` (`DetailsLayout.vue`'s `<CardTitle>` + its `document.title` watcher), `ViewModalGenerator` (the view modal's header + its `'loaded'` emit), and the mobile app's equivalent details screen all rendered the raw numeric id instead of a name. `buildFrontendListFields()` already resolves the correct FK-aware display path (e.g. `"user?.name"`) for this exact same column; `titleData` now reuses it instead of the bare column name.

New regression coverage: `ListPageGeneratorTest::test_fk_typed_primary_field_gets_a_related_record_link_cell_renderer()` / `test_plain_scalar_primary_field_still_gets_no_cell_renderer()`, and `IntrospectionToConfigTest::test_titledata_uses_the_fk_aware_display_path_when_the_primary_field_is_itself_an_fk()` / `test_titledata_stays_the_bare_field_name_when_the_primary_field_is_a_plain_column()`.

## v2.35.0 — 2026-08-06

### Fixed — DetailsLayout.vue header badges: wrong state variable (always broken) + false-positive relationship classification for plain scalar fields

Found live while re-wiring SYSTEM_SHELL's `Roles` module onto the current generator: the `role_type` header badge on `RolesDetailsLayout.vue` (the standalone `/roles/{uuid}` details page) rendered nothing, for any role.

**Bug 1 — wrong state variable, every module affected regardless of field.** `generateHeaderBadges()` and its two helpers (`generateRelationshipBadge()`, `generateTextBadge()`) hardcoded `data?.` in every emitted expression. Their only real caller, `ViewLayoutGenerator` (`details_layout.stub`, both the SYSTEM_SHELL and mobile-app variants), fetches its record into a ref literally named `record` — never `data`. Every header badge ever generated for a `DetailsLayout.vue` page referenced an undefined `data` variable; `v-if="data?.{field}"` is silently falsy (not a compile error), so the badge simply never rendered, for any module, regardless of which field triggered it. Fixed: `generateHeaderBadges(array $config, string $stateVar = 'data')` now threads the caller's real state-variable name through to both helpers; both `ViewLayoutGenerator`s (frontend and mobile) now pass `'record'` explicitly.

**Bug 2 — false-positive relationship classification.** The badge auto-detect matched any field whose data-path **last segment** merely contained the substring `'status'` or `'type'`, then unconditionally classified it `type: 'relationship'` — assuming a `.{displayPath}` sub-property always exists. True for a resolved FK display path like `"employee?.employment_status"`. False for `role_type`, a plain string column with no relationship at all — Roles' create/edit-splash's own two static options (`system`/`custom`), not a foreign key. The bare one-segment key `"role_type"` parsed through the old relationship fallback as `relationship="role_type"`, `displayPath="name"` (the default), emitting `record?.role_type?.name` — reading `.name` off a plain string is always `undefined`. Fixed: a field is only classified `'relationship'` when its data path is genuinely multi-segment (`str_contains($key, '.')`, e.g. `"status?.name"` or `"employee?.employment_status"`) — the real signal this config shape uses for "this is a resolved FK display path". A bare single-segment field name (any plain scalar column, `"type"`/`"status"`-named or not) now falls through to the plain-text badge branch instead, rendering its own value directly.

New regression coverage in `BaseComponentGeneratorTest`: `test_generate_header_badges_treats_bare_type_named_scalar_field_as_text_not_relationship()`, `test_generate_header_badges_still_treats_multi_segment_path_as_relationship()` (asserts the `record?.` prefix throughout, catching bug 1), and `test_generate_header_badges_defaults_state_var_to_data()` (backward-compat guard for a hypothetical caller that genuinely names its state `data`).

## v2.34.0 — 2026-08-06

### Fixed — inline-create's "Add New" flow never selected the newly created record when the module had no splash

Found while porting SYSTEM_SHELL's `Roles` module: clicking "Add New Status" from the `status_id` field's inline-create dropdown (default since v2.30.0) successfully created the Status but never selected it into the form — the field was left exactly as it was before, and the user had to search for the record they had just created.

Every inline-create-eligible FK field (`api-select-inline.stub`, and the splash-backed select's inline variant — both wired through `generateField()`'s `resolveInlineCreateModule()` path) emits an `@created` handler that calls `refreshAndSet('{fieldKey}', data.id)`, expecting the newly created related record's id to land in `form.{fieldKey}`. That call is unconditional — it does not care whether the module also happens to declare a `constants`-driven splash. `BaseComponentGenerator::buildSplashBlocks()`'s no-splash branch, however, generated a `refreshAndSet(key, value)` stub that dropped both arguments on the floor:

```ts
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    isLoading.value = false
}
```

Every module without a `constants`-based splash — the common case, since only modules with genuine custom static option lists need one — was affected. Fixed: the no-splash stub now still applies `key`/`value` to the form when given, it just has no splash endpoint to fetch:

```ts
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    if (key != null && value != null) (form.value as any)[key] = value
    isLoading.value = false
}
```

The with-splash branch was already correct — only the no-splash one dropped the assignment.

New regression coverage in `BaseComponentGeneratorTest`: `test_build_splash_blocks_without_splash_still_applies_key_value_to_form()` and a with-splash regression guard, `test_build_splash_blocks_with_splash_still_fetches_and_applies_key_value()`.

## v2.33.0 — 2026-08-06

### Fixed — delegation Service methods were non-static, same bug class as v2.32.0's actions fix

Found immediately after v2.32.0 by actually exercising `make:delegation` on a real case (a `Users` -> `UserLocations` delegation) rather than trusting the fix was isolated to `actions` — it wasn't. All 9 methods `DelegationServiceGenerator` emits (`list`, `create`, `edit`, `view`, `delete`, `deleteCheck`, `bulkAction`, `importTemplate`, `import`) were declared `public function` — instance methods — while every other generated Service in this codebase (Create/Edit/Delete/View/List, every hand-written one, and now every action Service since v2.32.0) is `public static function`. The 9 matching `Features/delegation/controller_method_*.stub` files each called `$service = new {Module}{Delegation}Service(); $service->{method}(...)`.

Both generators are entirely independent of each other and of `ActionServiceGenerator` — `actions` and `delegations` are separate config features with separate generator classes and separate stub templates, so fixing one never touched the other. All 9 methods and their matching controller stubs now use the static convention throughout. The Controller's own method (e.g. `listStockMovements`) is unaffected — Laravel Controllers are instantiated per-request, so that declaration correctly stays a plain instance method; only the call *inside* its body changed.

New regression coverage: `DelegationServiceGeneratorTest::test_all_delegation_service_methods_are_static()` asserts all 9 methods across a full-operations delegation config, and `ControllerGeneratorTest::test_delegation_controller_methods_call_the_delegation_service_statically()` asserts the Controller's call sites use `{Service}::{method}(...)`, never `new {Service}()`.

## v2.32.0 — 2026-08-06

### Fixed — `actions` config: permission casing and non-static Service/Controller calling convention

Found live during a real port of a hand-written module onto the `actions` config feature (migrating two existing dropdown actions via `make:action`).

**Permission casing.** `RoutesGenerator::generateActionRoutes()`, `SeederGenerator::generatePermissions()`, and `ViewModalGenerator::buildActionReplacements()` each independently built the action's permission string as `"{Module}.{actionName}"` using the raw StudlyCase action name (e.g. `Users.ForceResetPassword`) — internally self-consistent (all three agreed, so the permission still worked end-to-end for any action whose name happened to already be lowercase, e.g. the existing `approve` test fixture), but a silent violation of this app's own convention: every other permission in the codebase is camelCase after the dot (`Users.create`, the hand-written `Users.resendInvitation`) — a code comment in `RoutesGenerator` even claimed to match that exact convention while doing the opposite. All three now `lcfirst()` the action name. `ViewModalGenerator` had this bug in **two** separate places in the same method — the per-action permission string, and a second, independent condition built for the "More actions" menu's visibility check — both are fixed. The dialog-open ref variable name (`{name}Open`) is also now camelCase (`forceResetPasswordOpen`, not `ForceResetPasswordOpen`) for the same reason.

**Non-static Service.** The generated action Service (`Features/action/service.stub`) used `public function execute()` / `protected function process()` — instance methods — while every other generated Service in this codebase (Create/Edit/Delete/View/List, and every hand-written one) is `public static function execute()`. The Controller's generated glue method matched with `$service = new {Service}(); $service->execute(...)`. Confirmed live: a pre-existing hand-written Service being wired into `actions` for the first time already had a static `execute()`, and the generated Controller glue couldn't call it at all (no instance to `new`) without a hand-fix. Both `Features/action/service.stub` and `Features/action/controller_method.stub` now use the static convention throughout.

New regression coverage in `ActionGenerationTest`: a multi-word action name (`ForceResetPassword`) exercised end-to-end across Routes/Seeder/ViewModal (the existing `approve` fixture never caught this — `lcfirst('approve')` is a no-op), and a direct assertion that both the generated Service and the Controller's generated call site are static.

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

**Not retroactive** — only modules generated or regenerated against this version or later pick up the new default filters. See [Features Config › `filterFields`](features-config.md) for the full default-filter behavior.

Live-verified against a real generated scratch module with zero other filterable columns configured: the generated `filterFields` array correctly included `id`/`uuid`/`created_at` with no extra config needed. Also adds regression coverage for the `date`/`datetime`/`timestamp` → `'date'` branch in `getFilterFieldType()`'s fallback — it has existed since v2.26.3 but had never been exercised by a test, since that release's own live verification used a schema with no date/datetime column.

## v2.26.3 — 2026-08-04

### Fixed — every auto-derived filter field was typed `text`, regardless of the column's real type

`generateFilterFields()`'s fallback (the code path that derives frontend filter-panel fields from `filterableFields` when `filterFields` isn't hand-authored) hardcoded every field's `type` to `'text'` — an FK column, an enum column (e.g. an Order's `status`), a boolean, or a number all rendered as a plain text box running a `LIKE` search against a column that could never meaningfully match a free-text query.

`getFilterFieldType()` and `isForeignKey()` already existed with the right idea but had zero call sites anywhere in the codebase — grep confirmed it. Wiring them into the fallback surfaced their own latent bugs too: `isForeignKey()` checked a key shape (`is_fk`/`normalized_type`) that doesn't exist on `config['columns']` entries (`IntrospectionToConfig::buildColumn()`'s output collapses to a single normalized `type` key), and `getFilterFieldType()` compared against `'bigint'` instead of the normalized `'bigInteger'`, with no `enum` branch at all — the exact bug this method exists to prevent, for the exact column type most likely to need it. Fixed both, and wired in `buildFilterFieldOptions()` to supply the `{name, id}[]` option lists the frontend's filter dropdown needs to actually render a `select`-type field.

Live-verified against a real generated scratch module covering FK, enum, boolean, `bigInteger`, and plain string columns, before and after the fix.

## v2.26.2 — 2026-08-02

### Docs — fixed real config-shape errors in `actions`/`delegations`/`constants`; added an Examples section to the docs site

Found while integrating the previous release's `COOKBOOK.md` into this VitePress docs site properly, instead of leaving it as a disconnected root-level file:

- `actions.md` and `delegations.md` both documented their top-level config as a flat JSON array (`"actions": [{...}]`), but the real scaffolding code does `foreach ($config['actions'] as $actionKey => $action)` — a map keyed by action/delegation key. A flat array decodes to integer PHP keys, which breaks file and route naming. Confirmed against the real scaffolding source and every test fixture that builds this config, not just asserted.
- `module-config.md`'s `constants` example showed a named-group array shape (`[{"name": "STATUS", "values": [...]}]`); `ModelGenerator::generateConstants()` actually expects a flat `{CONST_NAME: value}` map.
- `delegations.md`'s "Generated Files" table listed filenames that don't match real generator output — corrected against a live generation of a delegation's Service and Tab component, and documented the delegation-vs-standalone-form-overwrite limitation that was previously missing entirely.

Also removed a dangling link to a nonexistent `examples/module-config-full.json`, and enriched `features-config.md`'s `bulk_actions` entry shape (previously documented as just `{key: string}`, now covers `status_target`/`label`/`icon`/etc).

Folded `COOKBOOK.md`'s content into a new **Examples** nav section (`docs/examples/`) — 7 pages, one per recipe, cross-linking to the existing reference pages instead of duplicating them, closing off the exact kind of drift that produced the shape bugs above. Site builds clean (VitePress's dead-link check passes).

## v2.26.1 — 2026-08-02

### Added — `COOKBOOK.md` and 3 new example fixtures (morphs, delegations, actions); fixed a redundant morph index

> Superseded one release later: `COOKBOOK.md` was folded into the docs site's Examples section in v2.26.2 above and no longer exists at the repo root.

Ships example-driven documentation covering every kind of module this engine supports: 9 recipes (lookup table, FK relationships, self-referential FK, file uploads, `inline_items`, morphs, delegations, actions/bulk actions), each pointing at a real, live-verified fixture rather than a hypothetical snippet.

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

## v2.22.1 — 2026-08-01

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
  as a `GET` route regardless of what the caller configured (`'GET'` was the
  blanket default for *all five* operations, not just `list`/`view`),
  because a concrete `'GET'` beat the `?? ($op === 'list' || 'view' ? 'get'
  : 'post')` fallback every time. Now the normalizers themselves default
  `method` per-operation.
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
<code v-pre>$this->generateStatsConfigs(...)</code>, a method that never existed anywhere in
this class or its ancestry — `ModuleGenerationService` in consumers catches
the resulting `\Throwable` and logs it as a generation error, so it fails
silently instead of crashing the run, and <code v-pre>{ModuleName}List.vue</code> simply never
gets written for mobile. Added `generateStatsConfigs()`, reusing the exact
<code v-pre>Stat{title,value,icon,color,bgColor}</code> shape the mobile `component.stub` and
consumers' `Stat` TS interface expect (same shape the still-dead
`FrontendGenerator::generateStats()` produces, but built from
`$this->moduleName` directly rather than <code v-pre>[[ModuleName]]</code> placeholder
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
- `src/Generators/Frontend/Components/BaseComponentGenerator.php` (`generateCustomCellRenderersFromListFields()`): new `isFk` branch, alongside the pre-existing badge/boolean branches, emits — for any non-primary field with `isFk: true` — a `<template #cell-{key}="{ row }">` wrapping the cell value in `<RelatedRecordLink module="{relatedModule}" :uuid="row.{relation}?.uuid">`<code v-pre>{{ row.{relation}?.name || 'N/A' }}</code>`</RelatedRecordLink>`. `{relation}` is the FK column key with its trailing `_id` suffix stripped (e.g. `location_type_id` → `location_type`), matching both the generated Model's `belongsTo()` relation-method naming convention and — regardless of whether that method name is itself camelCase or snake_case — the key Eloquent's `relationsToArray()` actually snake-cases the relation to in the real API response (confirmed against the hand-completed `LocationsListPage.vue` reference: `row.location_type`, `row.status`). Uses the `{ row }` slot prop, never the badge/boolean branches' `{ item }`. Display field defaults to `name`, correct for the large majority of this codebase's lookup/reference tables; a target with a different display column needs a manual tweak after generation.
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

- `src/Generators/Frontend/FrontendLocaleGenerator.php`: the frontend stub templates (`list/page.stub`, `view/details_layout.stub`, `view/modal.stub`) unconditionally emit `$t('{route}.page_create')`, `.page_edit`, `.page_delete`, `.page_details`, `.tab_overview`, and `.tab_history` for every generated module's dialogs and view-modal tabs, and `BaseComponentGenerator::generateFormFooter('edit')` always emits <code v-pre>{{ isSubmitting ? $t('{route}.saving') : $t('{route}.save_changes') }}</code> for the edit-form submit button. `FrontendLocaleGenerator`'s `$enKeys`/`$swKeys` never produced matching locale entries, so every freshly scaffolded module's quick-edit modal title and save button — and view-modal tab labels — rendered the literal key string (e.g. `item-categories.page_edit`, `item-categories.save_changes`) instead of translated text. Field-level labels (driven by the separate `col_*` loop) were unaffected, which is why the bug looked like a partial, easy-to-miss gap rather than a total i18n failure.
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
- View button text `View` → <code v-pre>{{ $t('common.view') }}</code> — matches the global common key.
- `import { ref }` → `import { ref, computed }` — `computed` added for reactive column array.
- `import { useI18n } from 'vue-i18n'` added.
- `const { t } = useI18n()` added after `usePermissions()`.
- `const columns: Column[] = [...]` → `const columns = computed<Column[]>(() => [...])` — columns react to locale changes.
- `const bulkActions: BulkAction[] = [...]` → `const bulkActions = computed<BulkAction[]>(() => [...])` — same pattern for bulk actions.

**`src/Generators/Frontend/Components/BaseComponentGenerator.php`**

- `generateColumnsFromListFields`: column `title` values now emit `t('module.col_fieldkey')` instead of a hardcoded English string. The module route is derived from `Str::kebab($this->moduleName)` at generation time so the emitted key is concrete (e.g. `t('products.col_name')`), not a placeholder.
- `generatePrimaryCellContentFromListFields`: mobile responsive sub-labels now emit <code v-pre>{{ $t('module.col_fieldkey') }}</code> instead of a hardcoded English label string.

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
(e.g. <code v-pre>{{ item.customer_id }}</code>), ignoring the `data` path already resolved by
`IntrospectionToConfig` (e.g. `customer?.name`).

The primary `<span>` now reads the field's `data` property first and falls back
to the column key only when no `data` path is set. For any FK column used as the
primary list field, the generated template will now emit
<code v-pre>{{ item.customer?.name }}</code> instead of <code v-pre>{{ item.customer_id }}</code>.

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
