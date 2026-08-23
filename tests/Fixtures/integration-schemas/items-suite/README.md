# items-suite integration-test schema fixture

A permanent, reusable schema fixture for validating `generator-engine` changes
end-to-end against a real consuming Laravel project (SYSTEM_SHELL). Unlike
the unit tests under `tests/Unit/`, this fixture exercises the full,
real-world pipeline: a live database, `SchemaIntrospector`, `make:module`'s
Artisan command, and the generated backend + frontend code actually running.

## What this fixture covers

A small but realistic multi-module, multi-relationship product-catalog
scenario:

| Table | Scenario exercised |
|---|---|
| `item_types` | A plain lookup table, no FKs (mirrors `LocationTypes`) |
| `item_categories` | A self-referential FK (`parent_id` → `item_categories.id`), mirrors `Locations.parent_id` |
| `items` | A main entity with **two** required FKs (`item_type_id`, `item_category_id`) |
| `item_images` | A child record of `items`, with a file-upload column (`image_media_id`) |
| `item_prices` | A second, independent child record of `items`, exercising `decimal(12,4)`, `date` and `enum` column types, plus a **composite unique** (`item_id, currency`) and a non-conventional index on `effective_date` |

Together these five tables let a single end-to-end run of `make:module`
exercise: lookup-table generation, self-referential FK handling, multi-FK
relationship resolution, one-to-many child records, file/media field
handling, and decimal/date field types — all in one pass, without needing
a different fixture per scenario.

### Why `price` is `decimal(12,4)`

Deliberately NOT `decimal(10,2)`. `MigrationGenerator` falls back to `(10,2)`
when precision/scale are absent from the config, so a `(10,2)` fixture column
produces correct-looking output even when precision is never introspected at
all. That is exactly what happened: the gap went unnoticed for several releases
because generated output matched the source *by coincidence*. Any fixture value
here must stay off the fallback defaults, or it cannot detect a regression.

The same reasoning applies to the composite unique and the `effective_date`
index: single-column uniques and the conventional uuid/audit indexes are emitted
by other code paths, so only a multi-column unique and a non-conventional index
actually exercise index introspection.

## Contents

- **`migrations/`** — 5 Laravel migration files, in FK-dependency order
  (`item_types` → `item_categories` → `items` → `item_images` →
  `item_prices`). Column types, uuid/timestamps/soft-deletes/audit-column
  patterns, and index conventions match SYSTEM_SHELL's real migrations
  exactly (see `LocationTypes`' and `Locations`' migrations under
  `app/Project/Modules/Core/Locations/...`). No hard DB-level foreign key
  constraints are used, matching this project's convention — FK columns
  are plain indexed `foreignId()` columns.
- **`columns.php`** — a companion PHP file returning an array keyed by
  table name, each value shaped exactly like `SchemaIntrospector::columns()`'s
  output, hand-derived from the migrations above.

## How to use it: full end-to-end validation

1. **Copy the migrations** into the consuming project's migrations folder
   (e.g. `SYSTEM_SHELL/BACKEND/database/migrations/`, or a scratch Laravel
   app wired to `generator-engine` via a path repository):

   ```bash
   cp tests/Fixtures/integration-schemas/items-suite/migrations/*.php \
      /path/to/consuming-project/BACKEND/database/migrations/
   ```

2. **Run the migrations** so the tables physically exist for introspection:

   ```bash
   php artisan migrate
   ```

3. **Run `make:module` once per table, in the order listed above.** This
   order is not cosmetic: `ModuleScaffolder::buildModuleRegistryFromFs()`
   builds the related-module registry by scanning already-scaffolded
   modules' `module.json` files on disk. If `ItemTypes` hasn't been
   scaffolded yet when `Items` is generated, `Items.item_type_id` cannot
   resolve a correct related-module namespace/import — generating in
   dependency order avoids that entirely.

   ```bash
   php artisan make:module Custom/ItemTypes
   php artisan make:module Custom/ItemCategories
   php artisan make:module Custom/Items
   php artisan make:module Custom/ItemImages --file-columns=image_media_id
   php artisan make:module Custom/ItemPrices
   ```

   `--file-columns=image_media_id` on `ItemImages` is required — it's the
   one column in this suite meant to exercise the file/media-upload field
   path (`field_type=file-input`), per `IntrospectionToConfig`'s
   `file_columns` meta option.

   (`Custom` above is just an example module group/type — use whatever
   group the consuming project's convention expects. `--table=` is
   available if a module's default snake_plural table-name guess doesn't
   match, though it shouldn't be needed here since all five module names
   are already plural.)

   > **Delete the copied migrations NOW — before step 4, not at step 5.**
   >
   > ```bash
   > rm -f /path/to/consuming-project/BACKEND/database/migrations/2025_09_01_0900*_create_item*_table.php
   > ```
   >
   > `make:module` has just written its OWN copy of each migration under the
   > module's `Migrations/` folder, and a consuming project like SYSTEM_SHELL
   > auto-loads every registered module's migrations folder *in addition to*
   > the top-level one. Both copies now create the same table, so the next
   > `migrate --fresh` — which is exactly what `RefreshDatabase` runs at the
   > start of any test suite — dies with "table already exists".
   >
   > The module-scoped copy is canonical from this point on; the top-level
   > copy has already done its only job (letting `SchemaIntrospector` see a
   > live table in step 2). Leaving it until the step-5 cleanup is too late:
   > step 4's whole point is running tests, and the collision breaks them
   > first. The failure is also badly misattributed — generation reports
   > `0 errors`, and what you actually see is unrelated tests
   > (`LoginOtpTest`, etc.) failing on the shared `RefreshDatabase` setup,
   > with nothing pointing at fixture migrations. Confirmed live 2026-08-23.

4. **Test.** Exercise the generated CRUD end-to-end: list/create/edit/view/
   delete for each module through the generated API and (if frontend
   generators were also run) the generated Vue pages. Confirm:
   - `ItemCategories` create/edit forms offer a parent-category picker.
   - `Items` create/edit forms offer both an item-type and item-category
     picker, and both resolve/display correctly on the list and view pages.
   - `ItemImages`' `image_media_id` field renders as a file upload, not a
     plain text input.
   - `ItemPrices`' `price`/`effective_date` fields render with the correct
     input types.

5. **Clean up** when done: drop the five tables, delete the generated module
   directories, and revert the shared files `make:module` also writes to
   (`BACKEND/app/Project/_Src/registry.json`, `FRONTEND/src/modules.json`,
   `FRONTEND/src/menus.json`). The copied top-level migrations are already
   gone — step 3 removed them. This fixture's own copies under `migrations/`
   are never modified by any of this — they're the permanent source of truth
   for the next run.

   ```bash
   rm -rf BACKEND/app/Project/Modules/Custom/{ItemTypes,ItemCategories,Items,ItemImages,ItemPrices}
   rm -rf FRONTEND/src/pages/modules/custom/{ItemTypes,ItemCategories,Items,ItemImages,ItemPrices}
   git -C BACKEND  checkout app/Project/_Src/registry.json
   git -C FRONTEND checkout src/modules.json src/menus.json
   ```

   Then confirm `git status --short` is clean in both — these modules are
   throwaway and must never be committed.

## How to use it: fast unit testing without a real DB

For tests that only need to exercise `IntrospectionToConfig` (not a live
DB, not `make:module`, not file generation), load `columns.php` directly:

```php
$allTables = require __DIR__ . '/../../Fixtures/integration-schemas/items-suite/columns.php';

$columns = $allTables['items'];

$config = (new \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig())->build($columns, [
    'module_name' => 'Items',
    'module_type' => 'Custom',
    'table_name'  => 'items',
    'id_type'     => 'uuid',
]);
```

This is the same pattern used by `tests/manual/run_introspection_to_config_smoke.php`
and `tests/Unit/Schema/IntrospectionToConfigTest.php`, just against this
suite's richer, multi-table schema instead of a two-column ad hoc array.
