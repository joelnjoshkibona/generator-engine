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
| `methodName` | string | `""` | Base of the generated **controller** method name; non-list operations get the operation prefixed (`methodName: "send"` on `create` → `createSend`). Defaults to the service name segment. |
| `serviceName` | string | `""` | Override for the generated service class name (without module prefix and without `"Service"` suffix). |
| `serviceMethod` | string | `"execute"` | Which static method on the action's service the controller calls. See [Calling a hand-written service signature](#calling-a-hand-written-service-signature-servicemethod-serviceargs-v3-5-17) below. |
| `serviceArgs` | string[]\|null | `null` | Call arguments, in order, for `serviceMethod`. `null` (the default) is exactly today's call: `["data", "param:<each urlParams entry>"]`. See the section below for the full vocabulary. |
| `placement` | string | `"more"` | Where the button renders on the view page/modal. `"main"` places it in the primary button row next to Edit; anything else (including omitting the key) puts it in the "More actions" dropdown menu. Defaults to `"more"` so adding an action never silently promotes it into the primary row. |
| `icon` | string | `""` | [lucide-vue-next](https://lucide.dev) icon component name (e.g. `"CheckIcon"`) rendered next to the button/menu-item label. Falls back to `"ZapIcon"` when empty. |
| `destructive` | boolean | `false` | When `true` and `placement` is `"more"`, the dropdown menu item is styled with destructive (red) text classes — use for actions like "Revoke" or "Deactivate". No effect on `"main"`-placement buttons. |
| `operations` | object | all disabled | Which module operations show the action button. |
| `fields` | array | `[]` | Form fields for the generated modal/page when `hasUI` is `true`. Same shape as `features.frontend.create.fields[]` — see [Field Types](features-config.md#field-types) — with one difference: a `select`+`splash_key`/`splashKey` field accepts **either** casing here (both resolve identically since v3.4.6). |
| `wizard` | object | none | Splits `fields` across multiple steps: `{ enabled: true, steps: [{ title, field_keys: ["field_a", "field_b"] }, ...] }`. Each `field_keys` entry is a `key` from the top-level `fields[]` array. Renders a stepper UI instead of a flat form. **The step key is `field_keys`, not `fields`** — and `enabled` must be `true`, since the wizard is opt-in. A step's title is `title` or `label` (an `id` is derived from it if omitted). A wizard *partitions* the form: a field named by no step is not rendered, and the generator reports it — a required one left out makes every submit fail validation. Both are read literally: a step spelled `fields` renders with no fields at all, and no warning is emitted. |
| `confirm_step` | object | `{enabled: true}` for `wizard` actions, disabled otherwise | Adds a final "Review & Confirm" step/checkbox before submit, auto-summarising every earlier step. A **sibling** of `wizard`, not nested inside it — it applies to flat forms too. Set explicitly to override the default for either shape. |
| `splash` | boolean\|object | `false` | Adds `GET {module}/{uuid}/{action}/splash` to pre-load option lists before the action's form opens (not gated on module `constants`, unlike create/edit's splash). Pass `{ splashData: [...] }` or `true`. See "Splash for an action" below. |

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
| `Services/{Module}{ServiceBase}SplashService.php` (when `splash` is set) | `ProductsApproveSplashService` | Yes — `writeFileOnce()` |

The controller gets a new method wired to the action endpoint (regenerated fresh on every `--force`, not write-once — put any hand-written replacement in the controller's `hand-methods` region (v3.5.17+)). `Form.vue` is always generated when `hasUI` is `true`, regardless of `uiType`; `Page.vue` is generated in addition when `uiType` is `"page"`. Write-once means a hand-edited Service/Form/Page survives every future `--force` regenerate of that module untouched — but also that it never picks up a later `fields`/`wizard` config change automatically; delete the file to force a fresh regenerate if you need that.

---

## Splash for an action (`splash: true`)

::: tip Since v3.5.21
Verified directly against `RoutesGenerator::generateActionRoutes()`,
`ControllerGenerator::generateActionMethods()`/`generateActionImport()` and
`ActionSplashServiceGenerator::generate()`, all of which now resolve their action names through
`BaseGenerator::resolveActionServiceNameRaw()`/`resolveActionBaseMethod()`.
:::

An action with `splash: true` gets a second endpoint — `GET {module}/{uuid}/{action}/splash` — that
pre-loads option lists before the action's form opens. It takes the record's `uuid`, unlike create's
splash which has no record yet, because an action's own choices usually depend on the row's current
state. It is not gated on module `constants`, since it's about the record rather than
constant-backed dropdowns. Permission is the action's own — anyone who may run the action may load
its splash.

**The naming rule (v3.5.21+):** the splash route handler, the controller method it calls, and the
generated splash service's class/file name always agree, whatever `serviceName`/`methodName` you
set on the action:

- `ServiceBase` is the same value `serviceName` (or the action's own name) resolves to for the
  action's own non-splash service — module prefix and a trailing `Service` suffix stripped.
- `methodOrServiceBase` is `methodName` when set, else `ServiceBase` — the same rule the action's
  own non-splash controller method already follows.
- Route handler and controller method: `{methodOrServiceBase}Splash`.
- Splash service class/file: `{Module}{ServiceBase}SplashService`.

Before v3.5.21, these three were computed independently and diverged the moment an action combined
`splash: true` with a custom `serviceName` and/or `methodName` — the route pointed at a controller
method that was never declared, and/or the controller's own import named a splash service file that
was never generated. Every action using default naming (no `serviceName`/`methodName` override) was
unaffected; regenerating one of those produces byte-identical output before and after this fix.

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

## Calling a hand-written service signature — serviceMethod / serviceArgs (v3.5.21)

An action's service is write-once, so developers reshape it (a public API entry point with a
different signature than the console needs, for example). The generated controller method, though,
is regenerated on every `--force` and by default always calls `{Module}{Action}Service::execute($request->all()[, ...urlParams])`
— so a `--force` used to produce a controller calling a method that no longer exists, or with the
wrong arguments: a runtime error on the first click, with no warning while generating.

`serviceMethod` and `serviceArgs` record the real call shape. `serviceArgs` is a JSON array of
strings, in call order, built from a closed vocabulary:

| Token | Controller argument | First-time service parameter | Forwarded to `process()` |
|---|---|---|---|
| `data` | `$request->all()` | `array $data` | `$data` |
| `request` | `$request` | `\Illuminate\Http\Request $request` | `$request` |
| `user` | `$request->user()` | `?\Illuminate\Contracts\Auth\Authenticatable $user` | `$user` |
| `param:<name>` | `$<name>` | `string $<name>` | `$<name>` |

Rules:
- Omitting both keys (or leaving `serviceMethod` empty and `serviceArgs` `null`) is exactly today's
  call — nothing changes for an action that doesn't need this.
- The vocabulary order is the call order. The service always gets a trailing `array $params = []`,
  and `process()` always gets `$params`, exactly as today — neither is part of `serviceArgs`.
- The controller is regenerated on every `--force` while the service is write-once, so **changing
  these keys never edits an existing service file** — you change the service by hand yourself.
- An invalid `serviceMethod` (not a valid PHP method name, or `process` in any case) or an invalid
  `serviceArgs` entry (not in the vocabulary, a `param:<name>` not present in `urlParams`, a
  duplicate, or a reserved url param name) fails loudly while generating — printed as
  `Failed: [Controller] Action '<key>': <reason>` — and leaves the previously-generated controller
  in place rather than writing a broken one.
- A project stub override (`stubs/generator/backend/Features/action/controller_method.stub` or
  `.../service.stub`) that predates these placeholders is warned, not silently ignored: copy the
  `[[serviceMethod]]`/`[[serviceArgs]]` (controller) or `[[serviceMethod]]`/`[[serviceParams]]`
  (service) placeholders from the engine's own stub into the override.
- **Interaction with hand-* regions**: changing `serviceMethod`/`serviceArgs` on an *existing*
  action changes the generated method body. The hand-region migration (see the routes/controller
  hand-region docs) then moves the *old* generated method into the `hand-methods` region, where it
  shadows the newly regenerated one — warned every run — until a human deletes the shadowing entry.
  This is the same mechanism that protects any other hand-edited method; it is not specific to this
  feature.

Worked example — NJIWA's Messages `Send` action, whose service has a `sendFromConsole(array $data)`
entry point instead of `execute()`:

```json
"send": {
    "name": "send",
    "serviceMethod": "sendFromConsole",
    "serviceArgs": ["data"]
}
```

generates a controller call of `MessagesSendService::sendFromConsole($request->all())`.

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
