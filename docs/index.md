---
layout: home

hero:
  name: "Generator Engine"
  text: "Config-driven code generation"
  tagline: "Laravel + Vue 3 + NativePHP Mobile — generate full-stack modules from a single config array."
  actions:
    - theme: brand
      text: Examples
      link: /examples/
    - theme: alt
      text: Module Config Reference
      link: /module-config
    - theme: alt
      text: Scaffold Blueprint
      link: /scaffold-blueprint

features:
  - icon: 🏗️
    title: Backend Generation
    details: Laravel models, migrations, controllers, services, routes, and seeders — all from config.
  - icon: 🖥️
    title: Frontend Generation
    details: Vue 3 list, create, edit, delete, and view pages with composables, components, and i18n keys.
  - icon: 📱
    title: Mobile Generation
    details: NativePHP Mobile pages plus a dedicated sync backend with offline support.
  - icon: 🧩
    title: UX Patterns
    details: Composites, wizards, shortcuts, and dashboard generators for complex multi-module flows.
  - icon: 🔗
    title: Delegations & Actions
    details: Embed child-list tabs and custom action buttons without writing boilerplate.
  - icon: ⚙️
    title: Processors
    details: Hook into before/after save and delete lifecycle stages for custom pipeline logic.
---

## Quick Start

Install via Composer:

```bash
composer require blutrixx/generator-engine:^2.0
```

Bootstrap `PathManager` (a static service-locator — all setters are static, there is no constructor), build a config array (or introspect from DB), then call any generator:

```php
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;

PathManager::setProjectRoot($outputPath);
PathManager::setProjectContext($projectContext);

(new ModelGenerator($moduleName, $moduleGroup, $moduleConfig))->generate();
```

## Documentation Map

| Section | What it covers |
|---------|----------------|
| [Examples](./examples/) | Task-oriented recipes — "I want to build X, what do I write?" Every recipe backed by a real, generated-and-verified fixture. |
| [Module Config](./module-config) | Top-level config structure — all keys, `menu_config`, `seeder`, `constants` |
| [Columns](./columns) | Column types, FK columns, morph pairs, `featureSelections`, field type mapping |
| [Features Config](./features-config) | `features.backend` and `features.frontend` per-operation config |
| [Mobile Config](./mobile-config) | `features.mobile_app` config, card layout, offline sync |
| [Delegations](./delegations) | Related-module tab/modal panel config |
| [Actions](./actions) | Custom action buttons and service config |
| [Processors](./processors) | Lifecycle pipeline hooks (before/after save/delete) |
| [Scaffold Blueprint](./scaffold-blueprint) | Blueprint JSON for `make:modules-from-db` |
| [UX Blueprint](./ux-blueprint) | Blueprint for `make:ux-from-blueprint` — composites, wizards, shortcuts |

## JSON Schemas

Machine-readable schemas for editor validation and autocompletion (VS Code, IntelliJ, etc.) are bundled with the package at `vendor/blutrixx/generator-engine/schema/`:

- `module-config.schema.json`
- `scaffold-blueprint.schema.json`
- `ux-blueprint.schema.json`
