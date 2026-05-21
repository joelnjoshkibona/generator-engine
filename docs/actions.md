# Actions Reference

An action is a custom button that appears on one or more operations (list, view, etc.) and triggers a dedicated service method. Use actions for domain-specific operations like "Approve", "Send Notification", "Generate PDF", or "Mark as Paid".

---

## Array Shape

`actions` is an array at the top level of the module config.

```json
"actions": [
  {
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
]
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
| `operations` | object | all disabled | Which module operations show the action button. |

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

| File | Class name pattern |
|------|-------------------|
| `Services/{Module}{ActionName}Service.php` | `ProductsApproveService` |

The controller gets a new method wired to the action endpoint. If `hasUI` is `true`, a modal or page component is also generated.

---

## `urlParams` Explained

When `urlParams: ["uuid"]`, the generated service receives the URL parameter as a typed PHP argument:

```php
public function process(array $data, string $uuid): array
{
    $record = ProductsModel::where('uuid', $uuid)->firstOrFail();
    // ...
}
```

Multiple params: `urlParams: ["uuid", "year"]` → `process(array $data, string $uuid, string $year)`.

---

## Examples

### Simple no-UI action (list + view)

```json
{
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
```

### Modal-UI action (view only)

```json
{
  "name":    "sendNotification",
  "label":   "Send Notification",
  "hasUI":   true,
  "uiType":  "modal",
  "urlParams": ["uuid"],
  "operations": {
    "view":   { "enabled": true, "endpoint": { "method": "POST", "path": "/users/{uuid}/send-notification", "permission": "Users.sendNotification" } },
    "list":   { "enabled": false },
    "create": { "enabled": false },
    "edit":   { "enabled": false },
    "delete": { "enabled": false }
  }
}
```
