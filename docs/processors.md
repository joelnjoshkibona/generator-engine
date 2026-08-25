# Processors Reference

Processors are module-wide pipeline hooks. They fire at defined stages of the create/edit/delete lifecycle and allow you to run custom PHP logic before or after the core operation.

> **Processors vs. field-level `processing_service`**
> Column-level `processing_service` fires per-field during assignment (e.g. hashing a password).
> Processors fire at module-wide lifecycle stages (e.g. sending a notification after a record is saved).

---

## Array Shape

`processors` is an array at the top level of the module config.

```json
"processors": [
  {
    "stage":      "after_save",
    "service":    "SendWelcomeEmailProcessor",
    "module":     "Users",
    "operations": ["create"]
  },
  {
    "stage":      "before_delete",
    "service":    "CheckDependenciesProcessor",
    "module":     "Customers",
    "operations": ["delete"]
  }
]
```

---

## Processor Object Keys

| Key | Type | Description |
|-----|------|-------------|
| `stage` | string | When to fire — exactly one of the 4 real stages, see [Stages](#stages) below. |
| `service` | string | PHP class name of the processor. |
| `module` | string | **Required.** StudlyCase module name the processor's namespace is resolved against (`{ResolvedNamespace}\Services\{service}`). Does not need to be an already-generated module — see [Module resolution](#module-resolution) below. |
| `operations` | string[] | Which operations invoke this processor: `"create"`, `"edit"`, `"delete"`. |
| `fields` | array | Optional. Passed through verbatim as the processor method's 3rd argument. |
| `config` | object | Optional. Passed through verbatim as the processor method's 4th argument. |

There is no `method` key — the invoked method name is always derived from `stage` (see below), and cannot be overridden.

---

## Stages

**Only these 4 stages actually fire.** `before_validation`/`after_validation` are not implemented by any generator — a processor entry using either stage silently produces zero generated code.

| Stage | Fires in | Method called | `$model` arg |
|-------|----------|----------------|--------------|
| `before_save` | Create, Edit | `beforeSave` | Always `null` — even on Edit, where the real model is in scope. |
| `after_save` | Create, Edit | `afterSave` | The saved model. |
| `before_delete` | Delete | `beforeDelete` | The model about to be deleted. |
| `after_delete` | Delete | `afterDelete` | The deleted model. |

The method name is always `Str::camel($stage)` — `before_save` → `beforeSave`, etc. — computed from `stage`, never read from config.

---

## Processor Class Contract

The generator emits a **static** call, not an instance call:

```php
$validData = \App\Project\Modules\Core\Users\Services\SendWelcomeEmailProcessor::afterSave($validData, $model, json_decode('[]', true), json_decode('{}', true));
```

Your processor class must expose a **static** method matching the stage, taking exactly this signature and **returning the (possibly-mutated) data array**:

```php
class SendWelcomeEmailProcessor
{
    public static function afterSave(array $data, ?Model $model, array $fields = [], array $config = []): array
    {
        // ... side effects ...
        return $data; // required -- the return value replaces $validData in the caller
    }
}
```

A processor that forgets to `return $data` (or returns something else) will null out every field the rest of the pipeline still needed after it runs.

---

## Module resolution

`module` is resolved the same way a delegation/inline_items child module is: in-memory registry → `storage_path('app/templates/default_modules.json')` → the consuming project's own `registry_core.json`/`registry.json` → **fallback**: `App\Project\Modules\Core\{module}`, with a non-fatal warning, if none of those know the name. This means `module` does **not** have to be an already-scaffolded module — point it at any StudlyCase name not present in your registry (e.g. `"module": "Helpers"`) and hand-place your processor class at the resulting fallback namespace's path (`app/Project/Modules/Core/Helpers/Services/{Service}.php` for that example) if you'd rather not generate a whole module just to host cross-cutting logic.

---

## Generated Code

The generator injects processor calls at the appropriate service stage, always **after** any file-column upload handling and legacy field-level `processing_service` calls.

**Example — `CreateService.php` with an `after_save` processor:**

```php
public static function afterCreate($validData, UsersModel $model): UsersModel {
    // ... other afterCreate logic ...
    // Processor: Users\SendWelcomeEmailProcessor::afterSave
    $validData = \App\Project\Modules\Core\Users\Services\SendWelcomeEmailProcessor::afterSave($validData, $model, json_decode('[]', true), json_decode('{}', true));
    return $model;
}
```

---

## Examples

### Send welcome email after user creation

```json
{
  "stage":      "after_save",
  "service":    "SendWelcomeEmailProcessor",
  "module":     "Users",
  "operations": ["create"]
}
```

### Block deleting a customer with open invoices

```json
{
  "stage":      "before_delete",
  "service":    "CheckOpenInvoicesProcessor",
  "module":     "Customers",
  "operations": ["delete"]
}
```

---

## Difference from Field-Level Processing

Column definitions (see [columns.md](columns.md)) support a `processing_service` key that runs per-field on assignment, not at a lifecycle stage. Use that for field transformations (hashing, formatting). Use `processors` for cross-cutting concerns that need access to the full record after save/delete.

## Test values for a processed field — `sample_value`

A field with a `processing_service` has a constraint that no generated test can guess. Its value
must satisfy a **normalizer**, not a validation rule, so `nullable|string|max:255` is all the rules
say while the service rejects everything that is not, say, a parseable phone number. Both test
generators already detect the `processing_service` — they weaken their assertions because of it —
but until v3.4.25 there was no way to tell them what a good value looks like, so every generated
create submitted `Test Phone 68ab...` and 422'd on a step the spec never got past.

Declare the value on the same `features.backend.{create,edit}.fields[]` entry:

```json
{
  "field": "phone",
  "rules": "required|string|max:255|unique:tenants,phone",
  "processing_service": "TenantsPhoneNumberService",
  "sample_value_php": "'+25575' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT)",
  "sample_value_js": "('+2557' + String(stamp).slice(-8))"
}
```

| Key | Used by | Shape |
|---|---|---|
| `sample_value` | both | A scalar — rendered as a literal in either language. Use this whenever the value does not need to vary. |
| `sample_value_php` | `PhpUnitTestGenerator` | Raw PHP, emitted verbatim into the generated test payload. |
| `sample_value_js` | `PlaywrightTestGenerator` | Raw JS, emitted verbatim into the generated spec. The spec's own `stamp` const is in scope at every fill site. |

Use the `_php`/`_js` forms when the value must vary per run — a **unique-indexed** column needs one,
or the second insert collides. Use the plain scalar otherwise.

An expression is emitted verbatim, exactly as `processing_service`'s own class name is: this config
is authored by the same developer who writes the module's services.

### Reach for it only when nothing else can answer

`sample_value` overrides every derivation, so a stale one silently outlives the schema change that
invalidated it. Prefer a source that cannot drift:

- **An `in:` rule already names the entire valid domain**, and both generators read it directly since
  v3.4.25 — an `integer` field constrained to `in:1,2,3,6,12` needs no `sample_value` at all.
- **A column's schema `default`** drives its factory value (see [columns.md](columns.md)).
- **`enum_values`** drives an enum column's fixture.

What genuinely needs `sample_value`: a `processing_service` normalizer; a `regex:` the generator's
default literal cannot satisfy; a guard on the *related* row (an FK that must point at a particular
kind of record); or a value that is legal but nonsensical, where the rules permit a range the domain
does not — a `nullable|integer` offset is valid at seven digits and will still generate due dates
thousands of years out.

::: warning Not preserved by a full regenerate
Like `processing_service`, these keys live in `features.backend.*.fields`, which
`mergePersistedFields()` does not preserve. A full `make:modules-from-db --blueprint` drops them, so
a consuming project must re-apply them through its own post-scaffold `--force --schema=` pass.
:::
