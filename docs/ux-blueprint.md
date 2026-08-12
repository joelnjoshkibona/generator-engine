# UX Blueprint Reference

The UX blueprint drives `php artisan make:ux-from-blueprint` (run from SYSTEM_SHELL/BACKEND or inside MOBILE_APP). It generates four advanced UI patterns on top of the base module scaffold:

| Generator | Blueprint key | What it creates |
|-----------|---------------|-----------------|
| `CompositeGenerator` | `composites` | Multi-section create pages (parent + child records in one form) |
| `WizardGenerator` | `wizards` | Multi-step guided wizards |
| `ShortcutGenerator` | `shortcuts` | Quick-action shortcut panels on module detail views |
| `DashboardGenerator` | `dashboard` | Dashboard quick-action buttons |

> **Critical:** `CompositeGenerator` and `ShortcutGenerator` resolve their **host** module's group via the top-level `groups` key (`BaseUxGenerator::getGroupForModule()`). A composite or shortcuts entry whose host module isn't listed in `groups` is silently skipped — **0 files, no error**. `WizardGenerator` and `DashboardGenerator` do **not** consult `groups` at all — every module reference they need (`steps[].module`, `triggers_action.module`, `target`, `composite`) is an inline `"Group/ModuleName"` string (or bare StudlyCase name for `composite`), so `groups` can be entirely absent from a blueprint that only uses wizards/dashboard.

---

## Top-Level Structure

```json
{
  "groups":     {},
  "composites": {},
  "wizards":    {},
  "shortcuts":  {},
  "dashboard":  {}
}
```

---

## `groups`

Keyed by group name, valued as a list of the module's **snake_case table name** — not module name, and not a `{module: group}` map. Only needs entries for modules used as a top-level key under `composites` or `shortcuts`.

```json
"groups": {
  "Custom": ["sales", "sale_items", "customers", "products", "payments"],
  "Core":   ["users", "statuses"]
}
```

**If a `composites`/`shortcuts` host module's table name isn't found in any group's list, that generator skips it entirely and produces 0 files for that entry — no warning is logged.**

---

## `composites` Object

A composite allows creating a parent record and its child records in a single form submission.

**Key:** StudlyCase module name of the **parent** module.

```json
"composites": {
  "Sales": {
    "label": "New Sale",
    "sections": [
      {
        "name":     "details",
        "type":     "form",
        "module":   "Custom/Sales",
        "optional": false
      },
      {
        "name":     "items",
        "type":     "repeater",
        "module":   "Custom/SaleItems",
        "optional": false
      }
    ]
  }
}
```

### Composite Object

| Key | Type | Description |
|-----|------|-------------|
| `label` | string | Human-readable label for the composite form. |
| `sections` | array | Ordered list of form sections — see below. |

### Section Object

| Key | Type | Description |
|-----|------|-------------|
| `name` | string | Unique section identifier (used as a key/ref in the template). |
| `type` | string | `"form"` — single record form; `"repeater"` — inline list of child records. |
| `module` | string | `"Group/ModuleName"` path of the module backing this section. |
| `optional` | boolean | If `true`, the section can be skipped. |

### Section Types

| `type` | Behavior |
|--------|----------|
| `form` | Renders the module's create form fields as an inline section. |
| `repeater` | Renders a row-based repeating table for multiple child records. |

### Generated Files

| File | Location |
|------|----------|
| `{Module}CompositeCreateService.php` | Backend — handles saving parent + all child sections atomically |
| `{Module}CreatePage.vue` (overwritten) | Frontend — composite multi-section page (replaces plain CreatePage) |
| `{Module}CreatePage.vue` (mobile) | MOBILE_APP — mobile variant of the composite create page |

---

## `wizards` Object

A wizard guides the user through a sequence of steps before executing a final action.

**Key:** StudlyCase wizard name.

```json
"wizards": {
  "SaleWizard": {
    "label": "New Sale Wizard",
    "steps": [
      {
        "step":   1,
        "name":   "customer",
        "label":  "Select Customer",
        "module": "Custom/Customers",
        "type":   "select_or_create"
      },
      {
        "step":   2,
        "name":   "items",
        "label":  "Add Items",
        "module": "Custom/SaleItems",
        "type":   "repeater"
      },
      {
        "step":   3,
        "name":   "payment",
        "label":  "Payment Details",
        "module": "Custom/Payments",
        "type":   "form"
      }
    ],
    "triggers_action": {
      "module": "Custom/Sales"
    }
  }
}
```

### Wizard Object

| Key | Type | Description |
|-----|------|-------------|
| `label` | string | Wizard title shown at the top of the wizard page. |
| `steps` | array | Ordered step definitions — see below. |
| `triggers_action` | object | `{ module: "Group/ModuleName" }` — the module whose create action finalizes the wizard. |

### Step Object

| Key | Type | Description |
|-----|------|-------------|
| `step` | number | 1-based step number. Determines display order. |
| `name` | string | Unique step key (used as Vue ref and service key). |
| `label` | string | Step label shown in the wizard progress indicator. |
| `module` | string | `"Group/ModuleName"` — module backing this step. |
| `type` | string | See Step Types below. |

### Step Types

| `type` | Behavior |
|--------|----------|
| `form` | Renders the module's create form fields for this step. |
| `repeater` | Renders a row-based table for multiple child records. |
| `select_or_create` | Renders a searchable dropdown with an optional inline create form. |

### Generated Files

| File | Location |
|------|----------|
| `{Wizard}WizardService.php` | Backend — validates + persists each step's data |
| `{Wizard}WizardPage.vue` | Frontend — multi-step wizard page component |
| `{Wizard}WizardPage.vue` (mobile) | MOBILE_APP — mobile wizard page |
| `wizards/routes.ts` | Frontend + Mobile — wizard routes registration |

---

## `shortcuts` Object

Shortcuts add quick-action buttons to a module's detail (view) layout. Each shortcut can open a composite, launch a wizard, or navigate to a related module.

**Key:** StudlyCase module name whose detail view receives the shortcuts.

**Value:** Array of shortcut definitions.

```json
"shortcuts": {
  "Customers": [
    {
      "label":   "New Sale",
      "icon":    "ShoppingCart",
      "target":  "Custom/Sales",
      "wizard":  "SaleWizard",
      "prefill": { "customer_id": "$id" }
    },
    {
      "label":   "View Invoices",
      "icon":    "Receipt",
      "target":  "Custom/Invoices",
      "wizard":  null,
      "prefill": {}
    }
  ]
}
```

### Shortcut Object

| Key | Type | Description |
|-----|------|-------------|
| `label` | string | Button label. |
| `icon` | string | Lucide icon name. |
| `target` | string | `"Group/ModuleName"` — where to navigate when the shortcut is tapped. |
| `wizard` | string\|null | Wizard name to launch (from `wizards` key). `null` to navigate directly. |
| `prefill` | object | Field values to pre-fill on the target form, passed as query params. A value starting with `$` is resolved against the current record at runtime: `"$id"` -> the record's id, `"$uuid"` -> its uuid, `"$fieldName"` -> `props.record[fieldName]`. A value with no leading `$` is used as a literal string. |

### Generated Files

| File | Location |
|------|----------|
| `{Module}Shortcuts.vue` | Frontend — shortcut buttons component |
| `{Module}Shortcuts.vue` (mobile) | MOBILE_APP — mobile shortcut component |
| `{Module}DetailsLayout.vue` | Patched to inject shortcuts above `<!-- Main Content Area -->` |

---

## `dashboard` Object

The dashboard key configures quick-action buttons on the main dashboard page.

```json
"dashboard": {
  "quick_actions": [
    {
      "label":     "New Sale",
      "icon":      "ShoppingCart",
      "composite": "Sales",
      "target":    "Custom/Sales",
      "wizard":    "SaleWizard"
    },
    {
      "label":     "Receive Stock",
      "icon":      "PackageCheck",
      "composite": null,
      "target":    "Custom/Products",
      "wizard":    null
    }
  ]
}
```

> **Note:** If `quick_actions` is an empty array `[]`, the DashboardGenerator returns early and generates **0 files**. This is by design.

### Quick Action Object

| Key | Type | Description |
|-----|------|-------------|
| `label` | string | Button label. |
| `icon` | string | Lucide icon name. Mapped to an emoji in mobile cards. |
| `composite` | string\|null | StudlyCase module name if this action opens a composite form. |
| `target` | string | `"Group/ModuleName"` navigation target. |
| `wizard` | string\|null | Wizard name to launch. `null` to navigate directly. |

### Icon → Emoji Map (mobile)

| Icon | Emoji |
|------|-------|
| `ShoppingCart` | 🛒 |
| `PackageCheck` | 📦 |
| `Receipt` | 🧾 |
| `Calculator` | 🧮 |
| `UserPlus` | 👤 |
| `FileText` | 📄 |

### Generated Files

| File | Location |
|------|----------|
| `DashboardQuickActions.vue` | Frontend — `FRONTEND/src/pages/dashboard/DashboardQuickActions.vue` |
| `DashboardQuickActions.vue` (mobile) | MOBILE_APP — mobile dashboard quick actions component |

---

## Common Pitfalls

| Symptom | Cause | Fix |
|---------|-------|-----|
| `composites`/`shortcuts` entry produces 0 files | Host module's table name missing from `groups`, or listed under the wrong group | Add the module's snake_case table name to the correct group's list in `groups` |
| Dashboard produces 0 files | `quick_actions` is an empty array or `dashboard` key is absent | Add at least one quick action entry |
| Shortcuts not appearing on detail view | `{Module}DetailsLayout.vue` not yet generated | Run base scaffold first; shortcuts patch requires the layout to exist |
| Dashboard quick-actions component generated but button doesn't show | `DashboardPage.vue` missing, or missing the `quick-actions`/`quick-actions-import` region markers | Confirm the target Dashboard page exists with both markers before running — the patch step is a silent no-op otherwise |
| Wizard step's form/repeater panel falls back to a TODO comment | Step's `module` isn't registered in the project's module registry yet | Scaffold that module first (`make:module`/`make:modules-from-db`), then re-run — this is a deliberate safe fallback, not an error |
| Re-running the command drops a previously-generated wizard's route | `wizards/routes.ts` is fully regenerated from only the wizards present in the CURRENT run's blueprint | Include every wizard you want reachable in every run, not just the ones you're adding |

---

## Complete Example

See [examples/ux-blueprint.json](../examples/ux-blueprint.json) for a full working example.

---

## CLI

```bash
# Run from SYSTEM_SHELL/BACKEND or inside MOBILE_APP
# The blueprint path is a single positional argument -- there is no --blueprint= flag.
php artisan make:ux-from-blueprint /path/to/ux-blueprint.json
```

There is no `--force` flag. Re-running is always safe (never errors on existing files), but write behavior differs per file: composite create pages and both `wizards/routes.ts` files are unconditionally overwritten on every run; every other generated file (services, wizard/dashboard/shortcut components) is written once and silently skipped on subsequent runs even if the blueprint changes — delete the file first to force regeneration. Region-based patches (`DashboardPage.vue`, `{Module}DetailsLayout.vue`) are always re-applied if their markers are present.

The command sets `PathManager::setProjectRoot(dirname(base_path()))` automatically so path resolution works correctly regardless of where it is run from.

**Ordering matters:** run the normal module.json-driven scaffold (`make:module` / `make:modules-from-db`) for every module referenced anywhere in the UX blueprint FIRST, then run `make:ux-from-blueprint` SECOND. Every UX generator patches or extends output that only exists after a normal scaffold (routes, detail layouts, create pages, the module registry).
