# Examples: Building Every Kind of Module

The reference pages ([Module Config](../module-config), [Delegations](../delegations),
[Actions](../actions), etc.) document every key in full. This section is
task-oriented instead: "I want to build a module that does X — what do I
actually write, and what commands do I run?"

Every recipe here points at a real, permanent, tested fixture in the
package's own repository — not a hypothetical snippet. Each fixture has
actually been generated against a real database and a real consuming
Laravel project at least once; several found and fixed real bugs in the
generator along the way (noted inline where relevant).

## The generate → hand-edit → regenerate loop

Every module — no matter which recipe below it follows — goes through the
same three-step loop. Understanding this up front makes every recipe a
variation on one theme, not a new mental model each time.

**Step 1 — introspect and generate.** Point the engine at a real table and
let it build everything schema alone can determine (columns, types,
required-ness, FK relationships by naming convention, `morphs` pairs by
column-pair convention):

```bash
php artisan make:module Custom/Orders
```

This writes a `module.json` next to the generated module — the persisted,
resolved config for that module. Everything after this point revolves
around that file.

**Step 2 — hand-edit what schema can't tell the engine.** Some config is
never introspected, because it isn't a fact about the database — it's a
decision a developer makes (which related modules to embed as sub-tabs,
which custom actions exist, how a parent-child relationship should behave).
Open the generated `module.json` and add the relevant key by hand:
`inline_items`, `delegations`, `actions`, `menu_config`, `morphs[].targets`,
etc.

**Step 3 — regenerate with `--force`.** Re-run the same command with
`--force` so the engine picks up the hand-edited config:

```bash
php artisan make:module Custom/Orders --force
```

The consuming project's own `ModuleScaffolder::mergePersistedFields()`
carries every hand-authored key forward from the existing `module.json`
into the fresh regenerate — that's what makes step 2's edit actually take
effect, and what makes it safe to run `--force` again later (e.g. after
adding a new column) without losing the hand-authored config. A small
number of generated files are explicitly **write-once** regardless of
`--force` (see [Gotchas](gotchas)) — those are meant to be hand-edited too,
just never overwritten again once they exist.

## Recipes

| Recipe | What it demonstrates |
|---|---|
| [Basic modules](basic-modules) | Lookup tables, FK relationships, self-referential trees, file uploads — all automatic, no hand-authored config |
| [Parent-child (`inline_items`)](inline-items) | Child rows edited inline, in the parent's own form, in one submit |
| [Polymorphic (`morphs`)](morphs) | One table shared by several unrelated parent types |
| [Related-record tabs (`delegations`)](delegations) | An independent module's records shown scoped, as a tab |
| [Custom actions & bulk actions](actions) | State-transition buttons, single-record or bulk |
| [Gotchas](gotchas) | Real problems hit while building these fixtures, and how to avoid them |

## Picking the right combination

These aren't mutually exclusive — a single module can combine several:

| You want... | Use |
|---|---|
| A record with no relationships | Nothing — plain generation |
| A record that references another module | FK naming convention, automatic |
| A tree/hierarchy within one module | Self-referential FK, automatic |
| A file/image field | `--file-columns` |
| Child rows edited *inline*, in the parent's own form, in one submit | `inline_items` |
| One table shared by several unrelated parent types | `morphs`, automatic |
| An independent module's records shown scoped, as a tab | `delegations` |
| A state-transition button, single record or bulk | `actions` / `bulk_actions` |

The deciding question between `inline_items` and `delegations` is usually
**"does this child data have any meaning or use outside its parent?"** — an
Order Item never exists independently of its Order (`inline_items`); a
Stock Movement is a real, independently-listable record that also happens
to relate to a Warehouse (`delegations`).
