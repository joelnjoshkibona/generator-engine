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
    "stage":   "after_save",
    "service": "SendWelcomeEmailProcessor",
    "method":  "handle",
    "operations": ["create"]
  },
  {
    "stage":   "before_delete",
    "service": "CheckDependenciesProcessor",
    "method":  "handle",
    "operations": ["delete"]
  }
]
```

---

## Processor Object Keys

| Key | Type | Description |
|-----|------|-------------|
| `stage` | string | When to fire — see [Stages](#stages) below. |
| `service` | string | PHP class name of the processor (resolved from the module's namespace). |
| `method` | string | Method to call on the processor class. Default `"handle"`. |
| `operations` | string[] | Which operations invoke this processor: `"create"`, `"edit"`, `"delete"`. |

---

## Stages

| Stage | Fires in | Description |
|-------|----------|-------------|
| `before_validation` | Create, Edit | Before Laravel validation runs. Use to normalize input. |
| `after_validation` | Create, Edit | After validation passes but before the model is touched. |
| `before_save` | Create, Edit | After validation, before `$model->save()`. |
| `after_save` | Create, Edit | After `$model->save()` succeeds. |
| `before_delete` | Delete | Before `$model->delete()`. Use to check dependencies or archive. |
| `after_delete` | Delete | After `$model->delete()` completes. |

---

## Processor Class Contract

The generator emits a call like:

```php
(new SendWelcomeEmailProcessor())->handle($data, $model);
```

Your processor class must have the `handle` method with that signature:

```php
class SendWelcomeEmailProcessor
{
    public function handle(array $data, Model $model): void
    {
        // ...
    }
}
```

For `before_validation` and `after_validation` stages, `$model` may be `null` on create.

---

## Generated Code

The generator injects processor calls at the appropriate service stage.

**Example — `CreateService.php` with `after_save` processor:**

```php
$model->save();

// Processors: after_save
(new SendWelcomeEmailProcessor())->handle($data, $model);

return ['status' => true, 'data' => $model, 'message' => 'Created successfully.'];
```

---

## Examples

### Send welcome email after user creation

```json
{
  "stage":      "after_save",
  "service":    "SendWelcomeEmailProcessor",
  "method":     "handle",
  "operations": ["create"]
}
```

### Validate no open invoices before deleting a customer

```json
{
  "stage":      "before_delete",
  "service":    "CheckOpenInvoicesProcessor",
  "method":     "handle",
  "operations": ["delete"]
}
```

### Normalize phone number before validation on create and edit

```json
{
  "stage":      "before_validation",
  "service":    "NormalizePhoneProcessor",
  "method":     "handle",
  "operations": ["create", "edit"]
}
```

---

## Difference from Field-Level Processing

Column definitions (see [columns.md](columns.md)) support a `processing_service` key that runs per-field on assignment, not at a lifecycle stage. Use that for field transformations (hashing, formatting). Use `processors` for cross-cutting concerns that need access to the full record after save/delete.
