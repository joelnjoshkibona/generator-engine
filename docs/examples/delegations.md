# Related-Record Tabs (`delegations`)

A module's view page shows a **different, independently-addressable**
module's records, scoped to it — e.g. a Warehouse's view page has a "Stock
Movements" tab showing every movement recorded against that warehouse.
Different from [`inline_items`](inline-items): a delegation's child rows
are **not** synced from the parent's own form submit — the child module has
its own full, independent CRUD (its own routes, controller, list page if
you navigate to it directly); the parent's view page just embeds a scoped
view of it.

**Reference fixture:**
[`delegations-suite`](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/delegations-suite) —
`Warehouses`/`StockMovements`.

For the full `delegations` config shape, see the
[Delegations reference](../delegations).

## Building it

Follow the [generate → hand-edit → regenerate loop](index#the-generate-hand-edit-regenerate-loop):

1. Generate both modules normally — `StockMovements` needs nothing special:
   ```bash
   php artisan make:module Custom/Warehouses
   php artisan make:module Custom/StockMovements
   ```
2. Hand-edit `Warehouses`' `module.json`, adding a `delegations` entry:
   ```json
   "delegations": {
     "stockMovements": {
       "name": "StockMovements",
       "label": "Stock Movements",
       "uiType": "tab",
       "relatedModule": {"name": "StockMovements", "group": "Custom"},
       "filterKey": "warehouse_id",
       "parentKey": "uuid",
       "parentIdField": "id",
       "operations": {
         "list":   {"enabled": true},
         "create": {
           "enabled": true,
           "backend":  {"fields": [
             {"field": "item_name", "rules": "required|string|max:255"},
             {"field": "quantity", "rules": "required|integer"}
           ]},
           "frontend": {"fields": [
             {"field": "item_name", "label": "Item", "field_type": "input", "type": "text", "required": true},
             {"field": "quantity", "label": "Quantity", "field_type": "number-input", "type": "number", "required": true}
           ]}
         },
         "view":   {"enabled": true},
         "delete": {"enabled": true}
       }
     }
   }
   ```
   `list` almost always leaves `frontend.fields` empty — the generated tab
   falls back to the **related module's own** persisted `module.json`
   `features.frontend.list.fields` for its columns. `create`/`edit` don't
   get that fallback: you must supply `frontend.fields` (the form) **and**
   `backend.fields` (the validation rules) — two separate things. Skipping
   `backend.fields` doesn't error; it silently validates away every
   submitted field except the parent FK and audit columns, because
   Laravel's `validate()` only returns declared rule keys. Confirmed the
   hard way while building this fixture — see [Gotchas](gotchas).
3. Regenerate with `--force`:
   ```bash
   php artisan make:module Custom/Warehouses --force
   ```

## What you get

A real nested backend endpoint per enabled operation
(`GET /warehouses/{uuid}/stock-movements/list`, etc.), a
`WarehousesStockMovementsService.php` scoping every query by
`warehouse_id = $parent->id`, and a `WarehousesStockMovementsTab.vue` wired
into both the view modal's tabs and the details page's nested route.

## Known limitation — don't mix delegation and standalone create/edit access without a plan

The generator writes the related module's `CreateForm.vue`/`EditForm.vue`
into that module's own, single canonical `Components/` path — the same
file its own independent scaffold already wrote. Generating the delegation
**overwrites** that file with a field list that deliberately excludes the
parent FK column (the tab supplies it via props instead). If
`StockMovements` is ever accessed **standalone** (not through the
Warehouses tab), its create/edit form has no way to set `warehouse_id` at
all — confirmed live while building this fixture. If a module needs both
delegation access and real standalone create/edit, this needs an explicit
decision on your part (keep the FK field in both field lists and rely on
`hiddens`/`defaults` props — the generated component already supports both
— rather than physically omitting the field).

See the fixture's own
[README](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/delegations-suite)
for the full verification steps.
