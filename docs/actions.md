# Actions Reference

An action is a custom button that appears on one or more operations (list, view, etc.) and triggers a dedicated service method. Use actions for domain-specific operations like "Approve", "Send Notification", "Generate PDF", or "Mark as Paid".

---

## Array Shape

::: warning Corrected 2026-08-02
`actions` is a **map keyed by action key**, not a flat JSON array. This page
previously showed `"actions": [{...}]` — copying that literally breaks
`ModuleScaffolder`'s `foreach ($config['actions'] as $actionKey => $action)`
loop (a JSON array decodes to integer keys `0`, `1`, ... in PHP, not the
action's own name). Confirmed against the real scaffolding code and every
real test fixture that builds this config; see
[generator-engine's own actions-suite example](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/actions-suite)
for a config that was actually generated and executed end-to-end.
:::

`actions` is an object at the top level of the module config, keyed by a
unique action key (conventionally the same as `name`).

```json
"actions": {
  "approve": {
    "name":        "approve",
    "label":       "Approve",
    "hasUI":       false,
    "uiType":      null,
    "urlParams":   ["uuid"],
    "methodName":  "approve",
    "serviceName": "ApproveService",
    "operations": {
      "list":   { "enabled": false, "endpoint": { "method": "POST", "path": "/products/{uuid}/approve", "permission": "Products.approve" } },
      "view":   { "enabled": true,  "endpoint": { "method": "POST", "path": "/products/{uuid}/approve", "permission": "Products.approve" } },
      "create": { "enabled": false },
      "edit":   { "enabled": false },
      "delete": { "enabled": false }
    }
  }
}
```

---

## Action Object Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | **required** | camelCase or snake_case action name. Derives class and method names. |
| `label` | string | same as `name` | Button label shown in the UI. |
| `hasUI` | boolean | `false` | Set `true` if the action renders a modal/page for user input before executing. |
| `uiType` | string\|null | `null` | `"modal"` or `"page"` when `hasUI` is `true`. |
| `urlParams` | string[] | `[]` | URL path parameter names injected into the service method signature (e.g. `["uuid"]` → `string $uuid`). |
| `methodName` | string | `""` | Override for the generated PHP method name. Defaults to StudlyCase of `name`. |
| `serviceName` | string | `""` | Override for the generated service class name (without module prefix and without `"Service"` suffix). |
| `placement` | string | `"more"` | Where the button renders on the view page/modal. `"main"` places it in the primary button row next to Edit; anything else (including omitting the key) puts it in the "More actions" dropdown menu. Defaults to `"more"` so adding an action never silently promotes it into the primary row. |
| `icon` | string | `""` | [lucide-vue-next](https://lucide.dev) icon component name (e.g. `"CheckIcon"`) rendered next to the button/menu-item label. Falls back to `"ZapIcon"` when empty. |
| `destructive` | boolean | `false` | When `true` and `placement` is `"more"`, the dropdown menu item is styled with destructive (red) text classes — use for actions like "Revoke" or "Deactivate". No effect on `"main"`-placement buttons. |
| `operations` | object | all disabled | Which module operations show the action button. |
| `fields` | array | `[]` | Form fields for the generated modal/page when `hasUI` is `true`. Same shape as `features.frontend.create.fields[]` — see [Field Types](features-config.md#field-types) — with one difference: a `select`+`splash_key`/`splashKey` field accepts **either** casing here (both resolve identically since v3.4.6). |
| `wizard` | object | none | Splits `fields` across multiple steps: `{ enabled: true, steps: [{ title, field_keys: ["field_a", "field_b"] }, ...] }`. Each `field_keys` entry is a `key` from the top-level `fields[]` array. Renders a stepper UI instead of a flat form. **The step key is `field_keys`, not `fields`** — and `enabled` must be `true`, since the wizard is opt-in. Both are read literally: a step spelled `fields` renders with no fields at all, and no warning is emitted. |
| `confirm_step` | object | `{enabled: true}` for `wizard` actions, disabled otherwise | Adds a final "Review & Confirm" step/checkbox before submit, auto-summarising every earlier step. A **sibling** of `wizard`, not nested inside it — it applies to flat forms too. Set explicitly to override the default for either shape. |

---

## `operations` Object

```json
"operations": {
  "list":   { "enabled": true,  "endpoint": { "method": "POST", "path": "/products/{uuid}/action", "permission": "Products.action" } },
  "view":   { "enabled": true,  "endpoint": { "method": "POST", "path": "/products/{uuid}/action", "permission": "Products.action" } },
  "create": { "enabled": false },
  "edit":   { "enabled": false },
  "delete": { "enabled": false }
}
```

Each operation entry:

| Key | Type | Description |
|-----|------|-------------|
| `enabled` | boolean | Whether the action button appears on this operation's page. |
| `endpoint` | object | `{ method, path, permission }` — the API route for this action. |

---

## Generated Files

For each action, the generator creates:

| File | Class name pattern | Write-once? |
|------|-------------------|-------------|
| `Services/{Module}{ActionName}Service.php` | `ProductsApproveService` | Yes — `writeFileOnce()` since v3.1.7 |
| `Components/{Module}{ActionName}Form.vue` (when `hasUI: true`) | `ProductsApproveForm.vue` | Yes — `writeFileOnce()` since v3.1.7 |
| `{Module}{ActionName}Page.vue` (when `hasUI: true` and `uiType: "page"`) | `ProductsApprovePage.vue` | Yes — `writeFileOnce()` since v3.1.7 |

The controller gets a new method wired to the action endpoint (regenerated fresh on every `--force`, not write-once). `Form.vue` is always generated when `hasUI` is `true`, regardless of `uiType`; `Page.vue` is generated in addition when `uiType` is `"page"`. Write-once means a hand-edited Service/Form/Page survives every future `--force` regenerate of that module untouched — but also that it never picks up a later `fields`/`wizard` config change automatically; delete the file to force a fresh regenerate if you need that.

---

## `urlParams` Explained

::: warning Corrected 2026-08-15
This section previously showed an instance-method `process()` with no `$params` argument — that's
the shape `service.stub` had *before* v2.32.0's static-calling-convention fix. Verified against the
real stub, `backend/Features/action/service.stub`, below.
:::

When `urlParams: ["uuid"]`, the generated service receives the URL parameter as a typed PHP argument
on **both** `execute()` (the public entry point, called statically) and `process()` (protected, holds
your actual logic) — plus a third `$params` argument on both, reserved for server-forced field
overrides the same way `CreateService`/`EditService::execute()` accept one:

```php
class ProductsApproveService
{
    public static function execute(array $data, string $uuid, array $params = []): array
    {
        // ... try/catch wrapper, calls self::process() ...
    }

    protected static function process(array $data, string $uuid, array $params = []): array
    {
        $record = ProductsModel::where('uuid', $uuid)->firstOrFail();
        // ...
    }
}
```

Multiple params: `urlParams: ["uuid", "year"]` → both methods gain `string $uuid, string $year` before
the trailing `array $params = []`. Call it statically: `ProductsApproveService::execute($data, $uuid);`

---

## Examples

### Simple no-UI action (list + view)

```json
"actions": {
  "deactivate": {
    "name":      "deactivate",
    "label":     "Deactivate",
    "hasUI":     false,
    "urlParams": ["uuid"],
    "operations": {
      "list":   { "enabled": true,  "endpoint": { "method": "POST", "path": "/products/{uuid}/deactivate", "permission": "Products.deactivate" } },
      "view":   { "enabled": true,  "endpoint": { "method": "POST", "path": "/products/{uuid}/deactivate", "permission": "Products.deactivate" } },
      "create": { "enabled": false },
      "edit":   { "enabled": false },
      "delete": { "enabled": false }
    }
  }
}
```

### Modal-UI action (view only)

```json
"actions": {
  "sendNotification": {
    "name":    "sendNotification",
    "label":   "Send Notification",
    "hasUI":   true,
    "uiType":  "modal",
    "urlParams": ["uuid"],
    "fields": [
      {
        "field":       "message",
        "label":       "Message",
        "placeholder": "Enter notification message",
        "required":    true,
        "field_type":  "textarea",
        "type":        "text"
      },
      {
        "field":       "account_id",
        "label":       "Account",
        "required":    true,
        "field_type":  "select",
        "type":        "text",
        "splash_key":  "accounts"
      }
    ],
    "operations": {
      "view":   { "enabled": true, "endpoint": { "method": "POST", "path": "/users/{uuid}/send-notification", "permission": "Users.sendNotification" } },
      "list":   { "enabled": false },
      "create": { "enabled": false },
      "edit":   { "enabled": false },
      "delete": { "enabled": false }
    }
  }
}
```
