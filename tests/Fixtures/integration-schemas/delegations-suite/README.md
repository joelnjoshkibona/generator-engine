# delegations-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine`'s
`delegations` feature (related modules rendered as embedded sub-tabs on a
parent's view page) end-to-end against a real consuming Laravel project
(SYSTEM_SHELL). Sibling to `items-suite`/`orders-suite`/`morphs-suite`.

## What this fixture covers

A POS/inventory-flavored scenario: viewing a **Warehouse** shows every
**Stock Movement** recorded against it as a related-records tab — not
inline-managed rows synced from the Warehouse's own form (that's what
`inline_items` is for, see orders-suite), but a real, independently
addressable related module (its own routes, controller, full CRUD, its own
`StockMovementsListPage.vue` if accessed directly) rendered inside the
parent's view.

| Table | Scenario exercised |
|---|---|
| `warehouses` | Parent — gets `stock_movements` embedded as a tab via its own hand-authored `delegations` config |
| `stock_movements` | An ordinary, independently-scaffolded module. `warehouse_id` is just a normal FK, detected the same way any other FK is — nothing in the schema marks this relationship as special |

Like `inline_items`, `delegations` is **entirely hand-authored** — nothing
here is introspected. Unlike `inline_items`, the README previously had
**zero worked examples of this shape anywhere** (confirmed by searching the
whole package) — this fixture and its config are the first.

## How to use it: full end-to-end validation

1. **Copy the migrations**, run them, generate both modules normally first —
   `StockMovements` needs no special config of its own:
   ```bash
   cp tests/Fixtures/integration-schemas/delegations-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   php artisan migrate
   php artisan make:module Custom/Warehouses
   php artisan make:module Custom/StockMovements
   ```
2. **Hand-edit Warehouses' `module.json`**, adding a `delegations` entry:
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
         "create": {"enabled": true,
           "backend":  {"fields": [
             {"field": "item_name", "rules": "required|string|max:255"},
             {"field": "quantity", "rules": "required|integer"},
             {"field": "movement_type", "rules": "required|string|max:16"}
           ]},
           "frontend": {"fields": [
             {"field": "item_name", "label": "Item", "field_type": "input", "type": "text", "required": true},
             {"field": "quantity", "label": "Quantity", "field_type": "number-input", "type": "number", "required": true},
             {"field": "movement_type", "label": "Type", "field_type": "input", "type": "text", "required": true}
           ]}
         },
         "edit": { "...": "same shape as create" },
         "view":   {"enabled": true},
         "delete": {"enabled": true}
       }
     }
   }
   ```
   **The `backend.fields` block is not optional if you want `create`/`edit`
   to actually persist anything.** `frontend.fields` alone only builds the
   *form* — validation is separately gated by `backend.fields`, and Laravel's
   `validator($data, $rules)->validate()` returns **only the declared rule
   keys** (this project's own mass-assignment-safety convention). Forget
   `backend.fields` and every submitted field silently vanishes before
   `Model::create()` ever sees it — confirmed the hard way while building
   this fixture: the first pass only set `frontend.fields`, and a live
   `create()` call persisted nothing but the FK and audit columns.
3. **Regenerate the parent with `--force`**:
   ```bash
   php artisan make:module Custom/Warehouses --force
   ```
4. **Confirm**:
   - `Services/WarehousesStockMovementsService.php` exists, with `list`/
     `create`/`edit`/`view`/`delete` methods, each scoping `StockMovements`
     by `warehouse_id = $parent->id`.
   - `Routes/api.php` has nested routes:
     `GET /warehouses/{uuid}/stock-movements/list`,
     `POST /warehouses/{uuid}/stock-movements/create`, etc.
   - `WarehousesStockMovementsTab.vue` exists, wired into both
     `WarehousesViewModal.vue`'s tabs and `WarehousesDetailsLayout.vue`'s
     child route.
   - Create/list a stock movement via the service directly (or the real
     endpoint) and confirm it's correctly scoped to the parent warehouse.

## Known limitation — delegation-target forms and standalone access can conflict

`RelatedModuleFormGenerator` (the generator that builds `create`/`edit`
forms for the *related* module, as part of generating the delegation on the
*parent*) writes `{RelatedModule}CreateForm.vue`/`EditForm.vue` into the
related module's **own** `Components/` directory — the **exact same file
path** that module's own standalone `CreateFormGenerator`/`EditFormGenerator`
already wrote to when it was scaffolded independently.

Confirmed live with this fixture: `StockMovements` was generated first, on
its own — its `CreateForm.vue` at that point would have shown a normal FK
picker for `warehouse_id` (it's a real FK column). Generating the
`Warehouses` delegation afterward **overwrote** that same file with the
delegation's own field list (`item_name`/`quantity`/`movement_type` only —
`filterKey` columns are deliberately stripped from a delegation's field
list, since the tab always supplies `warehouse_id` itself via `hiddens`/
`defaults` props). The result: `StockMovementsCreateForm.vue` no longer has
*any* way to set `warehouse_id` at all. Accessed through the Warehouses tab,
this is invisible and correct (the tab passes `warehouse_id` in via props).
Accessed **standalone** — navigating directly to `/stock-movements/create`,
outside any warehouse context — the form silently has no way to select a
warehouse, and submitting it would violate `warehouse_id`'s `NOT NULL`
constraint.

**Practical implication:** if a module is going to be used as a delegation
target, decide up front whether it also needs genuine standalone
create/edit access. If yes, this needs a deliberate design choice this
generator does not make for you (e.g. keep the FK field in the config used
for BOTH standalone and delegated generation, relying on `hiddens`/
`defaults` — which the generated form component already supports as props —
rather than physically omitting the field; or accept that the module is
delegation-only for create/edit and route its standalone `Create`/`Edit`
pages elsewhere). Not fixed here — a genuine design question, not a
one-line patch, same category as the `inline_items` delete-cascade decision
before it was resolved.

## How to use it: fast integration testing without a real DB

Same pattern as the other suites — `columns.php` in this directory is
dumped from a real `SchemaIntrospector` run, not hand-typed.
