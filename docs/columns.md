# Columns Reference

Each entry in the `columns` array describes one DB column and how it participates in each generated operation.

---

## Column Object Shape

```json
{
  "id":              "uuid-v5-of-column-name",
  "name":            "status_id",
  "type":            "foreignId",
  "relatedModule":   "Statuses",
  "length":          "255",
  "default":         "",
  "unique":          false,
  "nullable":        true,
  "indexed":         true,
  "comment":         "",
  "featureSelections": {
    "backend":  { "create": true, "list": true, "view": true, "edit": true, "delete": false },
    "frontend": { "create": true, "list": true, "view": true, "edit": true, "delete": false }
  }
}
```

| Key | Type | Description |
|-----|------|-------------|
| `id` | string | UUID v5 computed from the column name. Auto-set by introspection. |
| `name` | string | Exact DB column name (snake_case). |
| `type` | string | Normalized column type — see [Column Types](#column-types) below. |
| `relatedModule` | string | StudlyCase module name for FK columns (e.g. `"Statuses"`). Empty string for non-FK. |
| `length` | string | Column length as a string (e.g. `"255"`). Empty string if not applicable. |
| `default` | string | Default value as a string, or empty string for none. |
| `unique` | boolean | Whether this column has a unique constraint. |
| `nullable` | boolean | Whether `NULL` is allowed. |
| `indexed` | boolean | Whether a plain index exists on this column. |
| `comment` | string | Human-readable note (not emitted to DB). |
| `enum_values` | string[] | **Required when `type` is `"enum"`**, ignored otherwise. An empty/missing array with `type: "enum"` emits `$table->enum('name', [])`, which fails at the DB level. |
| `featureSelections` | object | Which operations include this column — see below. |

---

## Column Types

| `type` value | DB equivalent | Notes |
|---|---|---|
| `string` | `VARCHAR(255)` | Default for short text. Use `length` to override. |
| `text` | `TEXT` | Medium-length text. |
| `longText` | `LONGTEXT` | Large text / rich content. |
| `integer` | `INT` | 32-bit signed integer. |
| `bigInteger` | `BIGINT` | 64-bit signed integer. |
| `decimal` | `DECIMAL(8,2)` | Currency / precision numbers. |
| `boolean` | `TINYINT(1)` | Checkbox. Rendered as toggle in frontend. |
| `date` | `DATE` | Date only (no time). |
| `datetime` | `DATETIME` | Date + time. |
| `time` | `TIME` | Time only. |
| `json` | `JSON` | JSON column. **Not SQLite-safe** — avoid in MOBILE_APP. Gets no create/edit frontend field at all (deliberate — no generic UI for editing raw JSON); cast to `array` on the model. |
| `foreignId` | `BIGINT UNSIGNED` | FK column. Requires `relatedModule` to be set. |
| `uuid` | `CHAR(36)` | A plain data column holding a UUID reference — unrelated to `id_type: "uuid"` (the module's own primary key setting). |
| `enum` | `ENUM(...)` | Requires `enum_values: string[]` (see the Column Object Shape table above). Cast to `string` on the model; `Rule::in(enum_values)` added to validation; renders as a static `select` frontend field. |

> **SQLite / MOBILE_APP note:** Use `text` instead of `json` for mobile backend modules. The mobile backend generators substitute `TEXT` automatically, but specifying `text` explicitly avoids confusion.

---

## `featureSelections` Object

Controls whether this column appears in each operation's generated code.

```json
"featureSelections": {
  "backend":  { "create": true, "list": true, "view": true, "edit": true, "delete": false },
  "frontend": { "create": true, "list": true, "view": true, "edit": true, "delete": false }
}
```

| Operation | Backend effect | Frontend effect |
|-----------|----------------|-----------------|
| `list` | Added to `$fillable`, returned in list query results | Shown as a column in the list table |
| `create` | Added to validation rules and `CreateService` assignment | Rendered as a form field in `CreateForm` component |
| `edit` | Added to validation rules and `EditService` assignment | Rendered as a form field in `EditForm` component |
| `view` | Added to `ViewService` eager load + return | Displayed in the view/details layout |
| `delete` | No effect on backend | Shown as a confirmation field in `DeleteForm` |

Setting any operation to `false` suppresses that column entirely from the corresponding generated file.
Note the example above shows `delete: false` on both sides to illustrate that the operations are
independently toggleable — schema-introspected columns default every operation, including `delete`,
to `true`.

---

## FK Columns

When `type` is `"foreignId"`, set `relatedModule` to the StudlyCase module name:

```json
{
  "name": "category_id",
  "type": "foreignId",
  "relatedModule": "Categories",
  "nullable": false
}
```

The generator will:
- Emit `$table->foreignId('category_id')` in the migration — **no `->constrained()`, deliberately**:
  this project's convention is plain unsigned-bigint columns with no real DB-level FK constraint
  anywhere, enforced at the application layer instead
- Add a `belongsTo(CategoryModel::class)` relationship to the model
- Render an `api-select` field (infinite scroll dropdown) in frontend forms
- Use `category?.name` as the display path in list tables

---

## Polymorphic (Morph) Columns

Morph pairs are declared on the parent module's `morphs` array (see [module-config.md](module-config.md)), not as individual columns. The introspector auto-detects `{name}_type` + `{name}_id` pairs and tags them with `morph_role`.

If you need to manually declare them, add the pair to `columns` with:

```json
[
  { "name": "commentable_type", "type": "string",  "nullable": false },
  { "name": "commentable_id",   "type": "bigInteger", "nullable": false }
]
```

**Both of these `columns` entries are required, not optional**, if you also want the migration to
collapse them: `MigrationGenerator` only emits `$table->morphs('commentable')` when it finds columns
named exactly `type_column`/`id_column` while iterating `columns[]` — the top-level `morphs[]`
declaration (in [module-config.md](module-config.md)) is relation *metadata* (drives the Model's
`morphTo()` + `morphMap()`), not something that auto-adds these two columns for you.

---

## Field Type Mapping (Frontend)

The frontend form field type is inferred from the column `type`:

| Column type | `field_type` in form | Component rendered |
|---|---|---|
| `string` | `input` | Text input |
| `text`, `longText` | `textarea` | Textarea |
| `integer`, `bigInteger`, `decimal` | `number-input` | Numeric input |
| `boolean` | `checkbox` | Toggle switch |
| `date` | `date` | Date picker |
| `datetime` | `date` | Datetime picker |
| `time` | `input` | Plain text input (no dedicated time picker) |
| `foreignId` | `api-select` | Async dropdown |
| `uuid` | `input` | Plain text input (no dedicated UUID widget) |
| `enum` | `select` | Static dropdown built from `enum_values` |
| `json` | *(none)* | No create/edit field is generated at all — deliberate, there's no generic UI for editing raw JSON. Add a hand-authored field entry yourself if you need one. |

Two field types exist that no column `type` ever maps to automatically — both are only ever added by
hand as an entry in `features.frontend.create.fields[]` / `.edit.fields[]`:

| `field_type` | Component rendered | Notes |
|---|---|---|
| `morph-select` | Type dropdown + API-backed record picker | Represents a `morphs[]` relation's `type_column` + `id_column` pair as one field entry — see [module-config.md § morphs](module-config.md#morphs-array). |
| `file-input` | File/image upload widget | Also requires the column to be listed in the module config's top-level `file_columns` array so the backend routes it to a `file` validation rule instead of its column type's normal rule. |

You can override `field_type` per-column inside `features.frontend.create.fields[].field_type` —
see [features-config.md](features-config.md) for the full field-shape reference and every other
available `field_type` (`select`, `api-select`, `checkbox`, `item-picker`, `inline-items`, etc).
