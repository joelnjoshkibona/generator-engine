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
| [ux-blueprint.md](ux-blueprint.md) | UX blueprint for `make:ux-from-blueprint` — composites, wizards, shortcuts, dashboard |

---

## Examples

| File | Description |
|------|-------------|
| [examples/module-config-full.json](../examples/module-config-full.json) | Complete annotated `Products` module config |
| [examples/scaffold-blueprint.json](../examples/scaffold-blueprint.json) | Full scaffold blueprint with groups, delegations, seeders, actions |
| [examples/ux-blueprint.json](../examples/ux-blueprint.json) | Full UX blueprint with composite, wizard, shortcuts, dashboard |

---

## JSON Schemas

Machine-readable schemas for editor validation and autocompletion (VS Code, IntelliJ, etc.).

| Schema | Validates |
|--------|-----------|
| [schema/module-config.schema.json](../schema/module-config.schema.json) | Single module config array |
| [schema/scaffold-blueprint.schema.json](../schema/scaffold-blueprint.schema.json) | Scaffold blueprint JSON |
| [schema/ux-blueprint.schema.json](../schema/ux-blueprint.schema.json) | UX blueprint JSON |

### Using schemas in VS Code

Add this to `.vscode/settings.json` in your project:

```json
{
  "json.schemas": [
    {
      "fileMatch": ["blueprint.json", "*-blueprint.json"],
      "url": "./vendor/blutrixx/generator-engine/schema/scaffold-blueprint.schema.json"
    },
    {
      "fileMatch": ["ux-blueprint.json", "*-ux-blueprint.json"],
      "url": "./vendor/blutrixx/generator-engine/schema/ux-blueprint.schema.json"
    }
  ]
}
```

---

## Quick Reference: Common Agent Errors

| Error | Cause | Fix |
|-------|-------|-----|
| UX generators produce 0 files | `module_groups` missing from ux-blueprint | Add every referenced module to `module_groups` |
| Dashboard produces 0 files | `quick_actions` is `[]` | Add at least one entry to `quick_actions` |
| FK field renders as plain input | `relatedModule` not set on column | Set `relatedModule` to the StudlyCase module name |
| Mobile list card shows wrong field | `card.titleField` not set | Set `features.mobile_app.list.card.titleField` |
| Processor not called | `operations` array missing operation name | Add `"create"`, `"edit"`, or `"delete"` to processor `operations` |
| Delegation tab not appearing | `enabled: false` on list operation | Set `operations.list.enabled: true` |
