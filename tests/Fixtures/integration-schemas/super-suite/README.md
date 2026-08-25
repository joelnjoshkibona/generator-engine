# super-suite integration-test schema fixture

The other five suites each cover one mechanism. This one covers the whole surface in a single
scaffold, and — unlike them — is meant to be **run by one command**, not followed by hand.

## Why this exists

Every bug in the v3.4.25 batch was live while the package's full unit suite was green: 864 tests,
4109 assertions, all passing, eight real defects shipped. That is not a gap in test *quantity*, it
is a gap in *kind*. Unit tests assert on generated **strings**; none of those eight is visible in a
string. They appear when the generated code is **executed** (a null model, an undefined
relationship, a payload the validator rejects) or **bundled** (an import of a file that was never
written — which no test in this repo can reach, because bundling happens in the consuming project).

So this fixture's job is not to describe the generator's features. It is to **run** them.

## The scenarios are derived from bugs, not from a feature list

A suite designed top-down from "what mechanisms exist" tests the happy path. Every table below
exists because a specific defect escaped through it into a real project. The right-hand column is
the acceptance criterion: if the named release's fix were reverted, that module must fail.

| Table(s) | Pins down | Escaped as |
|---|---|---|
| `suite_ledgers` (list/view only) | A reduced operation set must not import forms that were never generated, must not emit a DeleteCheck test for an absent route, and must not emit a missing-required-field test when no field is required | v3.4.25 (**production bundle could not build at all**), v3.4.21, v3.4.22 |
| `suite_receipts` (create/list/view) | A second reduced-operation shape; a read-only module's spec must not click its next step into a still-open View dialog | v3.4.25, v3.4.19 |
| `suite_order_types` | A plain lookup with a real `name` column — the only thing an FK filter can resolve through | v3.4.25 (filter diagnostics) |
| `suite_orders` + `suite_order_lines` | `inline_items` carrying a **local-options** select, a **splash_key** select and a real **FK** select side by side: only the last is a relation | v3.4.25 (View endpoint 500'd on an invented relationship) |
| `suite_orders.approved_by_id` | A `*_by_id` **business** column is a foreign key, not an audit column | v3.4.x `inferFkByConvention()` |
| `suite_warehouses` → `suite_movements` | A delegation tab | — (mechanism coverage) |
| `suite_suppliers`, `suite_customers`, `suite_settlements` | A morph target, its reverse relation, and a morph-filtered delegation | v3.4.x |
| `suite_contracts` | A status machine: `in:` rules, schema `default`s, a paired `start_date`/`end_date` with `after_or_equal:`, and processors on every stage × operation | v3.4.25 (**four separate defects**) |
| `suite_profiles` | A required, **uniquely-constrained** FK (a true 1:1), and a field whose real constraint lives in a `processing_service` normalizer rather than in any rule | v3.4.23, v3.4.25 (`sample_value`) |
| `suite_edge_cases` | A bounded string longer than 39 chars; an `enum` inside a **composite** unique; a `decimal(10,4)` too narrow for the default numeric fill; a `defaultVisible: false` column the edit step must not pick | v3.4.x × 4 |

### Deliberate choices that must not be "tidied up"

- **`suite_contracts.status` is `varchar` with a `default`, not an `enum`.** An enum column is
  covered by a different code path (`enum_values`). The whole point here is a plain string column
  whose valid domain lives only in an `in:` rule and whose sane starting value lives only in the
  schema default — the shape that produced `"incidunt sit"` fixtures in a real project.
- **`suite_edge_cases.price` is `decimal(10,4)`, never `(10,2)`.** `MigrationGenerator` falls back
  to `(10,2)`, so a `(10,2)` fixture matches correct output *by coincidence* even when precision is
  never introspected. Same reasoning as items-suite's `decimal(12,4)`.
- **`suite_profiles.owner_id` is `UNIQUE`.** An ordinary many-to-one FK can be filled with
  `option[0]` forever; a unique one is free only on the very first run against a given database.
  Only a unique FK exercises that path.
- **`suite_order_lines.line_kind` carries a literal `options` array and no relation.** A `select`
  that is not an FK is the entire point of the column; giving it a `*_id` name or a related table
  would delete the coverage.

## Two lanes

| Lane | Runs | Time | When |
|---|---|---|---|
| **fast** | generate → migrate → PHPUnit → `vite build` | ~5 min | every push |
| **full** | fast, plus Playwright | ~25 min | nightly, and before a release |

The fast lane alone catches the worst defect in the v3.4.25 batch — the unresolvable import — in
about thirty seconds, because `vite build` resolves every import statically while `vite dev` never
does. Any lane that omits a real production build cannot see that class of bug at all.

## Running it

The fixture is the schema and the blueprint; the **runner lives in SYSTEM_SHELL**, because the
generator cannot test itself end-to-end — it emits code that only means anything inside a shell
application, and SYSTEM_SHELL already owns `run-tests.sh`, `run-e2e.sh` and CI.

```bash
# from a SYSTEM_SHELL checkout
./run-fixture.sh super            # fast lane
./run-fixture.sh super --full     # + Playwright
```

::: warning Delete the copied migrations before you test, not after
`make:module` writes its own copy of each migration under the module's `Migrations/` folder, and a
consuming project auto-loads those *in addition to* the top-level folder — two migrations creating
one table, and the second fails. `RefreshDatabase` runs `db:migrate --fresh --seed` in `setUp()`,
so the collision lands in the **test** step, between scaffolding and any cleanup you had planned for
the end. The runner deletes the top-level copies immediately after generation for exactly this
reason; if you drive the fixture by hand, do the same. See `docs/examples/gotchas.md`.
:::
