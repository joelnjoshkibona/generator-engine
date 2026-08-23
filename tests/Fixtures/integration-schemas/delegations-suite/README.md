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

   > **Delete the copied migrations NOW — before any test run.**
   > `make:module` has just written its own copy of each migration under the
   > module's `Migrations/` folder, and a consuming project like SYSTEM_SHELL
   > auto-loads those *in addition to* the top-level folder. Both copies now
   > create the same table, so the next `migrate --fresh` — exactly what
   > `RefreshDatabase` runs at the start of any test suite — dies with
   > "table already exists", surfacing as unrelated tests failing with
   > nothing pointing at fixture migrations. The `--force` regenerate in
   > step 3 rewrites the module-scoped copy only, so removing the top-level
   > one here is safe.
   >
   > ```bash
   > rm -f /path/to/consuming-project/BACKEND/database/migrations/2026_08_02_1100*_create_*_table.php
   > ```

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

## Fixed (2026-08-05) — delegation-target forms used to collide with standalone access

Previously, `RelatedModuleFormGenerator` (the generator that built separate
`create`/`edit` forms for the *related* module, as part of generating the
delegation on the *parent*) wrote `{RelatedModule}CreateForm.vue`/
`EditForm.vue` into the related module's **own** `Components/` directory —
the exact same file path that module's own standalone `CreateFormGenerator`/
`EditFormGenerator` already wrote to when it was scaffolded independently.
Generating a delegation after the module had already been scaffolded
standalone silently overwrote that module's own create/edit page with a
different, incompatible field list (`filterKey` columns were deliberately
stripped from the delegation's own version).

`RelatedModuleFormGenerator` (and the thin `DelegationRelatedFormGenerator`
adapter that called it) has been removed entirely. Delegation tabs now
embed the related module's own **native** `CreateForm`/`EditForm`/
`DeleteForm`/`ViewModal` directly (via the shared `CrudListPanel` frontend
component) — the same files, the same field list, whether reached
standalone or through a parent's delegation tab. The parent FK is forced
server-side (the delegation's backend service, now a thin proxy over the
related module's own native `CreateService`/`EditService`, merges it into
the validated data after validation — the client can't override it) and
hidden client-side via the native form's existing `hiddens`/`defaults`
props, exactly the first option this note used to propose as an unbuilt
fix. There is nothing left to configure — a module used as a delegation
target and accessed standalone behave identically now.

## How to use it: fast integration testing without a real DB

Same pattern as the other suites — `columns.php` in this directory is
dumped from a real `SchemaIntrospector` run, not hand-typed.
