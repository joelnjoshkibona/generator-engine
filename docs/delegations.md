# Delegations Reference

A delegation embeds a related module's list/create/edit/view/delete interface as a **tab** or **modal** panel inside the parent module's detail view.

Example: a `Sales` module with a `SaleItems` delegation renders an "Items" tab inside the sale detail page, showing all items belonging to that sale.

---

## Array Shape

::: warning Corrected 2026-08-02
`delegations` is a **map keyed by delegation key**, not a flat JSON array.
This page previously showed `"delegations": [{...}]` — copying that
literally breaks `ModuleScaffolder`'s
`foreach ($config['delegations'] as $delegationKey => $delegation)` loop (a
JSON array decodes to integer keys `0`, `1`, ... in PHP). Confirmed against
the real scaffolding code and every real test fixture that builds this
config; see
[generator-engine's own delegations-suite example](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/delegations-suite)
for a config that was actually generated and executed end-to-end.
:::

`delegations` is an object at the top level of the module config, keyed by
a unique delegation key (conventionally camelCase of `name`).

```json
"delegations": {
  "saleItems": {
    "name":           "SaleItems",
    "label":          "Sale Items",
    "uiType":         "tab",
    "relatedModule": {
      "name":  "SaleItems",
      "group": "Custom"
    },
    "parentKey":     "uuid",
    "filterKey":     "sale_id",
    "parentIdField": "id",
    "defaults":      [],
    "operations": {
      "list":   { "enabled": true,  "endpoint": { "method": "GET",    "path": "/sale-items",       "permission": "SaleItems.list"   }, "backend": {}, "frontend": {} },
      "create": { "enabled": true,  "endpoint": { "method": "POST",   "path": "/sale-items",       "permission": "SaleItems.create" }, "backend": {}, "frontend": {} },
      "edit":   { "enabled": true,  "endpoint": { "method": "PUT",    "path": "/sale-items/{uuid}", "permission": "SaleItems.edit"   }, "backend": {}, "frontend": {} },
      "view":   { "enabled": false, "endpoint": { "method": "GET",    "path": "/sale-items/{uuid}", "permission": "SaleItems.view"   }, "backend": {}, "frontend": {} },
      "delete": { "enabled": true,  "endpoint": { "method": "DELETE", "path": "/sale-items/{uuid}", "permission": "SaleItems.delete" }, "backend": {}, "frontend": {} }
    }
  }
}
```

---

## Delegation Object Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | **required** | StudlyCase name (e.g. `"SaleItems"`). Used to derive service/component class names. |
| `label` | string | same as `name` | Human-readable tab/panel label. |
| `uiType` | string | `"tab"` | `"tab"` renders as a detail tab; `"modal"` renders as a modal trigger button. |
| `relatedModule` | object\|string | **required** | The module that owns the delegated records. Object form: `{ name, group }`. String form (legacy): `"SaleItems"` assumes group `"Core"`. |
| `parentKey` | string | `"uuid"` | The parent record's identifier field passed to the child as the filter value. |
| `filterKey` | string | `"parent_id"` | The FK column on the **related** table used to filter the child list. |
| `parentIdField` | string | `"id"` | Field on the parent used when building the default endpoint paths. |
| `defaults` | array | `[]` | Fields pre-filled when creating a child record from the delegation panel: `[{ field, value }]`. |
| `operations` | object | all disabled | Per-operation config — see below. |

---

## `operations` Object

Each of the five CRUD operations (`list`, `create`, `edit`, `view`, `delete`) can be enabled independently.

```json
"operations": {
  "list": {
    "enabled": true,
    "endpoint": {
      "method":     "GET",
      "path":       "/sale-items",
      "permission": "SaleItems.list"
    },
    "backend": {
      "filterableFields":       "name,code",
      "sortableFields":         "created_at",
      "eagerLoadRelationships": "",
      "filterableRelationships": [],
      "filterFields": []
    },
    "frontend": {
      "primaryField": "name",
      "fields": [
        { "key": "name", "title": "Item Name", "sortable": true, "data": "name", "type": "text", "class": "" }
      ]
    }
  },
  "create": {
    "enabled": true,
    "endpoint": { "method": "POST", "path": "/sale-items", "permission": "SaleItems.create" },
    "backend": {
      "fields": [
        { "field": "name", "rules": "required|string|max:255" }
      ]
    },
    "frontend": {
      "fields": [
        { "field": "name", "label": "Item Name", "field_type": "input", "required": true }
      ],
      "button_text": "Add Item",
      "hiddens": [],
      "defaults": []
    }
  }
}
```

Operation `backend` and `frontend` shapes are identical to [features-config.md](features-config.md) per-operation shapes.

---

## Generated Files

::: warning Corrected 2026-08-02
The filenames below were wrong in a previous version of this page — verified
against real generator output (`WarehousesStockMovementsService.php`,
`WarehousesStockMovementsTab.vue`) from the delegations-suite fixture.
:::

For each delegation on a module, the following files are generated:

| File | Purpose |
|------|---------|
| `{Module}{Delegation}Service.php` (in the **parent** module's own `Services/`) | Backend service handling the scoped `list`/`create`/`edit`/`view`/`delete` queries |
| `{Module}{Delegation}Tab.vue` (in the **parent** module's own `Components/` or root, `uiType: "tab"`) | Renders the child list + CRUD inside a tab, wired into the view modal's tabs and the details page's nested route |
| `{Module}{Delegation}Modal.vue` (`uiType: "modal"`) | Renders a modal with the child CRUD, wired into a header button |
| `{RelatedModule}CreateForm.vue` / `EditForm.vue` / `ViewComponent.vue` (in the **related module's own** `Components/`, NOT the parent's) | The actual create/edit/view forms — written into the related module's own canonical path, the same one its own independent scaffold uses. **This means generating a delegation overwrites the related module's own standalone create/edit form** if it was already independently scaffolded — see the warning below. No `DeleteForm.vue` is generated here at all; the related module needs its own independently-scaffolded delete form. |

::: warning Delegation-target modules can lose standalone create/edit access
Because the related module's `CreateForm.vue`/`EditForm.vue` get overwritten
with the delegation's own field list (which deliberately excludes the
parent FK column — the tab supplies it via `hiddens`/`defaults` props
instead), a module used as a delegation target loses any way to set that FK
column if it's ever accessed **standalone**, outside the parent's tab. If a
module needs genuine standalone create/edit access as well as delegation
access, keep the FK field in both configs and rely on `hiddens`/`defaults`
rather than physically omitting it. See the
[Delegations Cookbook example](examples/delegations) for the full writeup.
:::

---

## Validation Rules

The `DelegationConfigNormalizer::validate()` method enforces:
- `name` must not be empty
- `uiType` must be `"tab"` or `"modal"`
- `relatedModule` must not be empty

---

## Quick Example: Invoice → Line Items

```json
{
  "module_name": "Invoices",
  "delegations": {
    "lineItems": {
      "name":    "LineItems",
      "label":   "Line Items",
      "uiType":  "tab",
      "relatedModule": { "name": "LineItems", "group": "Custom" },
      "parentKey":   "uuid",
      "filterKey":   "invoice_id",
      "defaults":    [{ "field": "invoice_id", "value": "{{parent.uuid}}" }],
      "operations": {
        "list":   { "enabled": true,  "endpoint": { "method": "GET",    "path": "/line-items",       "permission": "LineItems.list"   } },
        "create": { "enabled": true,  "endpoint": { "method": "POST",   "path": "/line-items",       "permission": "LineItems.create" } },
        "edit":   { "enabled": true,  "endpoint": { "method": "PUT",    "path": "/line-items/{uuid}", "permission": "LineItems.edit"   } },
        "delete": { "enabled": true,  "endpoint": { "method": "DELETE", "path": "/line-items/{uuid}", "permission": "LineItems.delete" } },
        "view":   { "enabled": false }
      }
    }
  }
}
```
