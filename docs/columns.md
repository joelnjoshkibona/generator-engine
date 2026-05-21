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
| `json` | `JSON` | JSON column. **Not SQLite-safe** — avoid in MOBILE_APP. |
| `foreignId` | `BIGINT UNSIGNED` | FK column. Requires `relatedModule` to be set. |
| `uuid` | `CHAR(36)` | UUID reference column (not the PK). |

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
- Emit `$table->foreignId('category_id')->constrained('categories')` in the migration
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

The generator will emit `$table->morphs('commentable')` in the migration when a morph pair is detected.

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
| `foreignId` | `api-select` | Async dropdown |
| `json` | `textarea` | Raw JSON textarea |

You can override `field_type` per-column inside `features.frontend.create.fields[].field_type`.
