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
| `operations` | object | all disabled | Per-operation config — see below. |

::: warning Removed 2026-08-05: top-level `defaults`
Earlier versions of this page (and this key) suggested pre-filling a created
child's fields via a top-level `"defaults": [{ field, value }]` array. It was
dead code — nothing ever read it — and has been removed from
`DelegationConfigNormalizer::normalize()`. The parent FK is now **always**
forced server-side automatically (see "How the parent FK gets enforced"
below); the frontend tab auto-derives its own `hiddens`/`defaults` for that
FK column directly from `filterKey`, with no config needed. If you need to
pre-fill a *different* field on create, use the per-operation
`operations.create.frontend.hiddens`/`defaults` shape documented in
[features-config.md](features-config.md) instead — that key is unrelated to
the removed top-level one and still works exactly as documented there.
:::

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

Operation `backend` and `frontend` shapes are identical to [features-config.md](features-config.md) per-operation shapes, with one difference:

::: warning `operations.{create,edit}.backend.fields` no longer drives validation (since v2.28.0)
As of v2.28.0, delegation `create`/`edit` no longer run their own validation
at all — they delegate straight to the related module's own native
`CreateService`/`EditService`, whose own declared rules are the single
source of truth. `backend.fields` is still read (by `PhpUnitTestGenerator`,
to build realistic test payloads) but has **no effect on what's actually
enforced**. If a delegation's declared `fields` don't cover everything the
related module's native rules require, delegation-routed creates/edits can
422 even though the delegation's own config looks complete — keep this key
in sync with the related module's own `create`/`edit` field config as a
matter of hygiene, since nothing enforces the two staying aligned.
:::

---

## How the parent FK gets enforced

The related table's FK column (`filterKey`, e.g. `warehouse_id`) is **always**
forced server-side, unconditionally, regardless of what `operations.create`/
`edit` declare: the generated `{Module}{Delegation}Service` resolves the
parent, then merges `{filterKey: $parent->{parentIdField}}` into the payload
both before and after the related module's own validation runs — so it wins
over client input and survives even if the client never sends the field at
all. A spoofed FK in the request body (e.g. pointing at a different parent)
is silently overridden, not rejected. `edit` gets the identical treatment —
a delegation-tab edit can never re-parent a record to a different parent,
even if the related module's own edit rules expose that column.

Client-side, the generated tab component auto-derives `hiddens`/`defaults`
for that same FK column directly from `filterKey` — no config needed, and no
relation to the (removed) top-level `defaults` key above.

---

## Generated Files

::: warning Rewritten 2026-08-05 — delegation tabs no longer generate their own forms
Earlier versions of this page (and of the generator) had a delegation
generate its own `{RelatedModule}CreateForm.vue`/`EditForm.vue`/
`ViewComponent.vue`, written into the **related module's own** `Components/`
directory — the same path its own independent scaffold used. That was a
confirmed, live bug: generating a delegation after the related module had
already been scaffolded standalone silently overwrote that module's own
create/edit page with a different, incompatible field list. As of v2.28.0
that generator (`RelatedModuleFormGenerator`) is deleted entirely — see the
[delegations-suite fixture's README](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/delegations-suite)
for the full writeup.
:::

For each delegation on a module, the following files are generated:

| File | Purpose |
|------|---------|
| `{Module}{Delegation}Service.php` (in the **parent** module's own `Services/`) | A thin proxy: resolves the parent, builds a scoped query/forced FK, then calls the related module's own native `List`/`Create`/`Edit`/`View`/`Delete`/`DeleteCheck` static service and returns its result verbatim. |
| `{Module}{Delegation}Tab.vue` (in the **parent** module's own root, `uiType: "tab"`) | Renders the child list + CRUD inside a tab via the shared `CrudListPanel` component, wired into the view modal's tabs and the details page's nested route. |
| `{Module}{Delegation}Modal.vue` (`uiType: "modal"`) | Renders a modal with the child CRUD, wired into a header button. |

Nothing is generated into the **related module's own** directory at all. The
tab imports and renders that module's already-existing native
`{RelatedModule}CreateForm.vue`/`EditForm.vue`/`DeleteForm.vue`/
`ViewModal.vue` directly — the same files, the same field list, whether
reached standalone or through a parent's delegation tab. Each is rendered
with a `permissionOverride` prop set to the delegation's own resolved
permission (`{Module}.{Delegation}.{op}`, or the configured override), so a
delegation's own permission gates the form even though it's the exact same
component a standalone page also renders.

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
