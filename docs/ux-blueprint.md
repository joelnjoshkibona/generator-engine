# UX Blueprint Reference

The UX blueprint drives `php artisan make:ux-from-blueprint` (run from SYSTEM_SHELL/BACKEND or inside MOBILE_APP). It generates four advanced UI patterns on top of the base module scaffold:

| Generator | Blueprint key | What it creates |
|-----------|---------------|-----------------|
| `CompositeGenerator` | `composites` | Multi-section create pages (parent + child records in one form) |
| `WizardGenerator` | `wizards` | Multi-step guided wizards |
| `ShortcutGenerator` | `shortcuts` | Quick-action shortcut panels on module detail views |
| `DashboardGenerator` | `dashboard` | Dashboard quick-action buttons |

> **Critical:** All four generators require `module_groups` to resolve module paths. Without it they produce **0 files**.

---

## Top-Level Structure

```json
{
  "module_groups": {},
  "composites":    {},
  "wizards":       {},
  "shortcuts":     {},
  "dashboard":     {}
}
```

---

## `module_groups` (REQUIRED)

Maps every module name to its group. Used by all four generators to locate the target module's output directory.

```json
"module_groups": {
  "Sales":     "Custom",
  "SaleItems": "Custom",
  "Customers": "Custom",
  "Products":  "Custom",
  "Payments":  "Custom",
  "Users":     "Core",
  "Statuses":  "Core"
}
```

**If `module_groups` is missing or a module is not listed, the generator will skip that module entirely and produce 0 files.**

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
      "prefill": { "customer_id": "{{record.id}}" }
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
| `prefill` | object | Field values to pre-fill on the target form. Use <code v-pre>"{{record.field}}"</code> to reference the current record. |

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
| Generators run but produce 0 files | `module_groups` key missing or module not listed | Add every module name to `module_groups` |
| Dashboard produces 0 files | `quick_actions` is an empty array | Add at least one quick action entry |
| Shortcuts not appearing on detail view | `{Module}DetailsLayout.vue` not yet generated | Run base scaffold first; shortcuts patch requires the layout to exist |
| Wizard step module not found | Module path in step `module` doesn't match `module_groups` | Ensure `module_groups` entry matches exactly |

---

## Complete Example

See [examples/ux-blueprint.json](../examples/ux-blueprint.json) for a full working example.

---

## CLI

```bash
# Run from SYSTEM_SHELL/BACKEND or inside MOBILE_APP
php artisan make:ux-from-blueprint --blueprint=ux-blueprint.json

# Force overwrite existing files
php artisan make:ux-from-blueprint --blueprint=ux-blueprint.json --force
```

The command sets `PathManager::setProjectRoot(dirname(base_path()))` automatically so path resolution works correctly regardless of where it is run from.
