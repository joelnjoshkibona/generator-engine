# Generator Engine Documentation

Reference documentation for `blutrixx/generator-engine`. All JSON structures accepted by the generator are documented here.

---

## Guides

| Document | What it covers |
|----------|----------------|
| [module-config.md](module-config.md) | Top-level module config structure — all keys, `menu_config`, `seeder`, `constants` |
| [columns.md](columns.md) | Column types, FK columns, morph pairs, `featureSelections`, frontend field type mapping |
| [features-config.md](features-config.md) | `features.backend` and `features.frontend` per-operation config (list, create, view, edit, delete) |
| [mobile-config.md](mobile-config.md) | `features.mobile_app` config, card layout, offline sync, generated file list |
| [delegations.md](delegations.md) | Related-module tab/modal panel config |
| [actions.md](actions.md) | Custom action buttons and service config |
| [processors.md](processors.md) | Lifecycle pipeline hooks (before/after save/delete) |
| [scaffold-blueprint.md](scaffold-blueprint.md) | Blueprint JSON for `make:modules-from-db`, `make:mobile-modules`, `make:mobile-scaffold` |
| [examples/](examples/index.md) | Task-oriented recipes (basic modules, actions, delegations, inline-items, morphs, gotchas) — each backed by a real fixture actually generated and verified end-to-end |
| [changelog.md](changelog.md) | Every release, newest first — check here when a doc's described behavior seems out of date |

---

## Examples

| File | Description |
|------|-------------|
| [examples/module-config-full.json](../examples/module-config-full.json) | Complete annotated `Products` module config — static reference, not itself generated/verified; see [examples/](examples/index.md) for that |
| [examples/scaffold-blueprint.json](../examples/scaffold-blueprint.json) | Full scaffold blueprint with groups, delegations, seeders, actions |

---

## JSON Schemas

Machine-readable schemas for editor validation and autocompletion (VS Code, IntelliJ, etc.).

| Schema | Validates |
|--------|-----------|
| [schema/module-config.schema.json](../schema/module-config.schema.json) | Single module config array |
| [schema/scaffold-blueprint.schema.json](../schema/scaffold-blueprint.schema.json) | Scaffold blueprint JSON |

### Using schemas in VS Code

Add this to `.vscode/settings.json` in your project:

```json
{
  "json.schemas": [
    {
      "fileMatch": ["blueprint.json", "*-blueprint.json"],
      "url": "./vendor/blutrixx/generator-engine/schema/scaffold-blueprint.schema.json"
    }
  ]
}
```

---

## Quick Reference: Common Agent Errors

| Error | Cause | Fix |
|-------|-------|-----|
| FK field renders as plain input | `relatedModule` not set on column | Set `relatedModule` to the StudlyCase module name |
| Mobile list card shows wrong field | `card.titleField` not set | Set `features.mobile_app.list.card.titleField` |
| Processor not called | `operations` array missing operation name | Add `"create"`, `"edit"`, or `"delete"` to processor `operations` |
| Delegation tab not appearing | `enabled: false` on list operation | Set `operations.list.enabled: true` |
| Create/Edit form 404s `/{module}/create/splash` (or `/edit/splash`) on every mount | `constants` is set but `features.backend.createSplash`/`editSplash` isn't (generator < v3.4.7 also had this bug even with both set correctly) | `composer update blutrixx/generator-engine` to ≥ v3.4.7, then `--force` regenerate; if you don't actually want splash data, remove `createSplash`/`editSplash` entirely rather than adding a matching one |
| Action form crashes with `TypeError: Cannot read properties of undefined (reading 'splash')` | `actions[].fields` uses `field_type: "select"` + `splash_key` on generator < v3.4.6 | `composer update blutrixx/generator-engine`, then `--force` regenerate the module |
| Generated e2e create-step spec times out (~15s) | Spec predates v3.4.1-v3.4.3's handling of the "create → view by default" dialog | `composer update`, then `--force` regenerate — generated test files are overwritten by `--force`, they are not write-once |
| Playwright strict-mode violation: locator resolved to 2+ elements on a select2 field | Spec predates v3.4.4's anchored label matching (two field labels share a substring, e.g. "Status" / "Payment Status") | `composer update`, then `--force` regenerate the specs |
