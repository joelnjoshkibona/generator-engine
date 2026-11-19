::: warning Legacy target (frozen)
This page describes the SYSTEM_SHELL target, which takes bug fixes only (BOOTBOX_PLAN D8). New products use the Bootbox target. Copied from SYSTEM_SHELL `docs/modules/actions.md`.
:::

::: v-pre

# Custom Actions

## Overview

A **custom action** is a state-transition button that doesn't fit ordinary create/edit/delete — "Approve", "Send Notification", "Deactivate", "Generate PDF", "Mark as Paid". It scaffolds a route, a controller method, and a service class; you hand-fill the actual transition logic.

`actions` is a **completely separate mechanism** from `features.backend.list.bulk_actions` (list-page bulk actions applied to many selected rows at once — see [Bulk actions](#bulk-actions-—-a-different-nested-config-location) below). They happen to share the word "action" and nothing else — don't confuse the two.

::: warning Corrected 2026-08-08 — `actions` now has a real user, but not yet a copyable one
A previous version of this box (grounded in a 2026-08-05 scan) claimed zero non-empty real-world usage of `actions` anywhere in this project. That is no longer true: `Users/Users/module.json` now declares two real, non-empty entries — `forceResetPassword` and `resendInvitation` (added 2026-08-06 as part of the Users module port) — each with `urlParams: ["uuid"]`, `hasUI: true`, `uiType: "modal"`, real generated routes in `Routes/api.php`, and a real generated frontend form.

The original caveat still holds for the *implementation* underneath those configs, though: `UsersForceResetPasswordService.php` and `UsersResendInvitationService.php` (`BACKEND/app/Project/Modules/Core/Users/Users/Services/`) are still hand-written using the older static `Service::execute(...)` calling convention, called directly by `UsersController.php` (the same static shape `ActionServiceGenerator` emits today, shown below). The `module.json` config is now real and working end-to-end; neither existing Service file is generator-native code you can copy verbatim as a starting template for a brand-new action.

This page is grounded directly in the generator source (`packages/generator-engine/src/Generators/Backend/Services/Action/ActionServiceGenerator.php`, `.../Frontend/Components/Actions/ActionComponentGenerator.php`, `.../Helpers/ActionConfigNormalizer.php` — all real files that ship in the `blutrixx/generator-engine` package SYSTEM_SHELL depends on) and in generator-engine's own [`actions-suite`](https://github.com/joelnjoshkibona/generator-engine/tree/main/tests/Fixtures/integration-schemas/actions-suite) fixture. Treat the fixture's `PurchaseOrders` example below as illustrative of the generator-native shape, not as "a real SYSTEM_SHELL module already looks like this."
:::

---

## `module.json` Configuration

`actions` is a top-level object in `module.json`, alongside `delegations`, `inline_items`, `features`, and `columns` — keyed by a unique action key (conventionally the same as the action's own `name`).

```json
{
  "actions": {
    "approve": {
      "name":        "approve",
      "label":       "Approve",
      "hasUI":       true,
      "uiType":      "modal",
      "urlParams":   ["uuid"],
      "methodName":  "",
      "serviceName": "",
      "placement":   "main",
      "icon":        "CheckIcon",
      "destructive": false,
      "operations": {
        "list":   { "enabled": false },
        "create": { "enabled": true, "endpoint": { "method": "POST", "path": "/purchase-orders/{uuid}/approve", "permission": "PurchaseOrders.approve" } },
        "edit":   { "enabled": false },
        "view":   { "enabled": false },
        "delete": { "enabled": false }
      }
    }
  }
}
```

::: tip `module.json` is always merged
Like `delegations` and `inline_items`, `actions` is preserved on every `--force` re-run — it is one of the top-level developer-owned keys `ModuleScaffolder::mergePersistedFields()` carries forward.
:::

---

## Action Object Fields

`ActionConfigNormalizer::normalize()` (`packages/generator-engine/src/Helpers/ActionConfigNormalizer.php`) fills in every field below with its default before the generator reads it — a hand-authored entry only needs to set what it wants to override.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | `''` (must not be empty) | camelCase or snake_case action name. Drives the generated service class name and, unless overridden, the controller method name. |
| `label` | string | same as `name` | Button/menu-item label shown in the UI. |
| `hasUI` | boolean | `false` | `true` if the action needs a form before it executes (e.g. "Send Notification" needs a message body). `false` fires the action immediately on click with no intermediate form. |
| `uiType` | `'modal' \| 'page' \| null` | `null` | Only meaningful when `hasUI` is `true`. `'modal'` opens the form in a dialog from the view page; `'page'` navigates to a dedicated route. |
| `urlParams` | string[] | `[]` | URL path parameter names injected into the generated service's method signature — e.g. `["uuid"]` produces `execute(array $data, string $uuid, array $params = [])`. |
| `methodName` | string | `''` (→ StudlyCase of `name`) | Override the generated controller method name. |
| `serviceName` | string | `''` (→ StudlyCase of `name`) | Override the generated service class name segment — the module prefix and `Service` suffix are stripped from whatever you pass, then re-added, so `"ApproveService"` and `"approve"` both resolve to the same `{Module}ApproveService.php`. |
| `placement` | `'main' \| 'more'` | `'more'` | Where the button renders on the `{Module}ViewModal.vue`/details page. `'main'` places it in the primary button row next to Edit. Anything else — including simply omitting the key — puts it behind the "More actions" dropdown, so adding a new action never silently promotes it into the primary row. |
| `icon` | string | `''` (→ `'ZapIcon'`) | A [lucide-vue-next](https://lucide.dev) icon component name rendered next to the label. |
| `destructive` | boolean | `false` | When `true` **and** `placement` is `'more'`, the dropdown item is styled with destructive (red) text — e.g. "Revoke", "Deactivate". No effect on `'main'`-placement buttons. |
| `operations` | object | all five operations `enabled: false` | Which module pages show the action button, and the API route each fires. See below. |
| `fields` | array | `[]` | The form fields rendered inside the generated modal/page when `hasUI` is `true`. Same shape as `features.frontend.create.fields[]` — including `field_type: "select"` + `splash_key` for an FK picker. Actions had no field authoring at all before generator-engine v3.1.0; see [Multi-step actions and the confirm step](#wizard-actions). |
| `wizard` | object | `{}` (off) | `{ "enabled": true, "steps": [...] }` — splits `fields[]` across multiple steps, each step's `field_keys` referencing `fields[]` entries by `field` (or an `inline_items[].key`). Renders a real Stepper. See [below](#wizard-actions). |
| `confirm_step` | object | enabled for wizards, disabled for flat forms | A trailing "Review & Confirm" step summarising every entered value behind a confirmation checkbox — set `enabled` to override the shape-based default. Sibling to `wizard`, **not** nested inside it, so a flat form can opt in too. Since v3.2.0. |
| `splash` | boolean\|object | `false` | Adds a `GET {module}/{uuid}/{action}/splash` endpoint that pre-loads option lists before the action's form opens — not gated on module `constants`, unlike create/edit's splash. See generator-engine's own [Splash for an action](https://joelnjoshkibona.github.io/generator-engine/actions#splash-for-an-action-splash-true). As of engine v3.5.21 the route, controller method and generated splash service filename always agree, whatever `serviceName`/`methodName` you set. |

### Giving an action field a valid test value (`sample_value`)

The generated e2e smoke test fills each entry in `fields[]` with a generated string (or number/date, by
type), then submits. The dialog only closes on a 2xx response, so a value the action rejects makes the
spec time out. The generator can size a string to a real *column* — but an action field has no column,
and its real constraints usually live in your hand-written service, which the generator can't read.

Countries' `updateCountryCodes` is the worked example: the service enforces `iso3` as `max:3`, while the
schema says 255, so the generated ~40-character value was rejected with a 422. Declare the value on the
field itself:

```json
{ "field": "iso3", "label": "ISO3 Code", "field_type": "input", "type": "text", "required": true,
  "sample_value": "ZZZ" }
```

`sample_value` takes a literal (string, number or boolean). Use `sample_value_js` for a JS expression
that must vary per run — the spec's own `stamp` const is in scope, e.g.
`"sample_value_js": "String(stamp).slice(-3)"` — for example to keep a unique-indexed column unique.
Either one beats every generated value. The same two keys have long worked on
`features.backend.{create,edit}.fields[]`; honoring them on an action's own field needs
generator-engine **v3.5.20 or later**. After adding one, regenerate
just the specs: `php artisan make:module System/Sales/Orders --force --only=PlaywrightTest
--schema=app/Project/Modules/System/Sales/Orders/module.json`. This is for GENERATED frontends only: a Core
module's frontend is hand-written (`features.frontend.enabled: false`), so the engine skips it and this regenerates
nothing for it; write that spec by hand (see `FRONTEND/e2e/helpers/list.js`).

---

## `operations` Object

Every action declares its `operations` the same shape as a delegation's operations — one entry per `list`/`create`/`edit`/`view`/`delete`, each independently enabled with its own endpoint:

```json
"operations": {
  "list":   { "enabled": false },
  "create": { "enabled": true,  "endpoint": { "method": "POST", "path": "/purchase-orders/{uuid}/approve", "permission": "PurchaseOrders.approve" } },
  "edit":   { "enabled": false },
  "view":   { "enabled": false },
  "delete": { "enabled": false }
}
```

`ActionConfigNormalizer::normalizeOperations()` fills in an `endpoint.method` default per operation when you don't specify one: `GET` for `list`/`view`, `POST` for `create`/`edit`/`delete`. An operation left as just `{ "enabled": true }` (no `endpoint`) still gets a usable default method, and `path`/`permission` normalize to empty strings at that stage — but empty is not the final story for either: three independent generator sites compute and enforce a real, non-empty fallback further downstream whenever `endpoint.permission` is left blank:

- `RoutesGenerator.php:439` (the route's `permission:` middleware)
- `SeederGenerator.php:126` (the permission row actually seeded to the DB)
- `ViewModalGenerator.php:66` (the frontend button's `hasPermission()` guard)

All three independently compute the same string: **`{ModuleName}.{lcfirst(actionName)}`** — e.g. action `approve` on module `PurchaseOrders` gives permission `PurchaseOrders.approve`. This is confirmed live: Users' `forceResetPassword`/`resendInvitation` both leave `permission: ""` in their config, and the real generated routes carry `permission:Users.forceResetPassword`/`permission:Users.resendInvitation`. A blank `permission` is not inert — it resolves to this exact string, which is what you grant to a role.

`path` also has a real computed fallback when `endpoint.path` is left blank (`RoutesGenerator.php:441-447`): **`/{module-kebab}/{action-kebab}/{urlParams...}/{op}`** — e.g. action `approve` with `urlParams: ["uuid"]` on module `PurchaseOrders` falls back to `/purchase-orders/approve/{uuid}/create`. You'll still usually want to specify `path` explicitly for a readable, RESTful-looking route, but omitting it does not produce a broken or unreachable route.

| `endpoint` key | Description |
|---|---|
| `method` | HTTP verb for the generated route. |
| `path` | Route path, with `{uuid}`/other `urlParams` names as Laravel route placeholders. |
| `permission` | Permission key checked by the route's `permission:` middleware — and by the frontend button's `hasPermission()` guard. |

---

## Generated Files

For each action with at least one enabled operation, `ActionServiceGenerator` and `ActionComponentGenerator` produce:

| File | Location | Always generated? |
|---|---|---|
| `Services/{Module}{ActionName}Service.php` | `BACKEND/.../{Module}/Services/` | Yes |
| A controller method + a route entry | `{Module}Controller.php`, `Routes/api.php` | Yes |
| `Components/{Module}{ActionName}Form.vue` | `FRONTEND/.../{Module}/Components/` | Only when `hasUI` is `true` |
| `{Module}{ActionName}Page.vue` | `FRONTEND/.../{Module}/` | Only when `hasUI: true` **and** `uiType: "page"` |
| A button/menu item in `{Module}ViewModal.vue`'s footer | — | Only when `hasUI` is `true` (a no-UI action has nowhere generated to click — see the note below) |

::: tip The Form is always generated regardless of `uiType`
`ActionComponentGenerator::generateAction()` always writes the `{Module}{ActionName}Form.vue` component — it owns the fields and the submit call. `uiType` only decides the *container*: `'modal'` renders the form inside an `AppDialog` from `ViewModal.vue`; `'page'` additionally generates a thin page shell that mounts the same form. This mirrors the CRUD convention (one `{Module}CreateForm.vue`, rendered by either a modal or a routed page) — flipping `uiType` later never orphans hand-written form fields in a component the generator has stopped emitting.
:::

### `Services/{Module}{ActionName}Service.php` — generated shape

```php
<?php

namespace App\Project\Modules\Custom\PurchaseOrders\Services;

use App\Project\_Src\Helpers;
use App\Project\_Src\ApplicationException;

class PurchaseOrdersApproveService
{
    public static function execute(array $data, string $uuid, array $params = []): array
    {
        try {
            return self::process($data, $uuid, $params);
        } catch (ApplicationException $e) {
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected static function process(array $data, string $uuid, array $params = []): array
    {
        // Add your custom logic here
        return Helpers::success([], 'Operation completed successfully');
    }
}
```

::: tip Static, like every other generated service
An action service is `public static function execute(...)`, called as
`{Module}{ActionName}Service::execute(...)` — the same static convention as `{Module}CreateService`,
`{Module}EditService`, `{Module}ListService`, and every other generated service in this codebase.
`serviceMethod`/`serviceArgs` on the action config (engine v3.5.21+) change what that call looks like
— see [urlParams Explained](#urlparams-explained) below.
:::

You hand-fill `process()`. The `actions-suite` fixture's worked example, for a `status` string column:

```php
protected static function process(array $data, string $uuid, array $params = []): array
{
    $model = PurchaseOrdersModel::where('uuid', $uuid)->firstOrFail();
    $model->update(['status' => 'approved']);
    return Helpers::success($model->fresh(), 'Purchase order approved');
}
```

### `Components/{Module}{ActionName}Form.vue` — generated shape

A self-contained form + submit, following the same `modal` prop convention as `CreateForm.vue`/`EditForm.vue` (see [Module Structure — CreateForm, EditForm, DeleteForm, and ViewModal](/legacy/module-structure#createform-editform-deleteform-and-viewmodal)):

```vue
<script setup lang="ts">
const props = defineProps({
	uuid: { type: String, default: '' },
	parentUuid: { type: String, default: '' },
	modal: { type: Boolean, default: false },
	cancelLink: { default: '/purchase-orders/list' },
})
const emit = defineEmits(['success', 'cancel'])
const form = ref<Record<string, any>>({})
// handleSubmit() posts `form` to the action's own configured endpoint,
// with {uuid}/other urlParams substituted from `props` — NOT a hard-coded
// "/create" suffix.
</script>
<template>
	<div class="space-y-4 p-4">
		<p class="text-sm text-muted-foreground">{{ /* action label */ }}</p>
		<!-- Add your form fields here — bind them into `form`. -->
		<div class="flex justify-end gap-2"><!-- Cancel / Submit buttons --></div>
	</div>
</template>
```

You hand-fill the actual fields inside the empty template comment, binding each one into the pre-wired `form` ref — the submit call, endpoint construction, loading state, and toast handling are already generated and don't need touching.

---

An action can also opt into a splash endpoint the same way create/edit can — see the `splash` row
above and generator-engine's own docs. As of engine v3.5.21 the splash route, the controller method
it calls, and the generated splash service's class/file name always agree, whatever
`serviceName`/`methodName` you set on the action.

---

## Multi-step ("wizard") actions and the confirm step (generator-engine v3.1.0+) {#wizard-actions}

Everything above this section describes the original, flat-form action shape — one form, one
submit. Since v3.1.0, an action can also declare real fields and a multi-step wizard, reusing the
exact same mechanism Create/Edit forms already have:

```json
{
  "actions": {
    "receive": {
      "name": "receive",
      "label": "Receive PO",
      "hasUI": true,
      "uiType": "modal",
      "fields": [
        { "field": "received_date", "label": "Received Date", "field_type": "date", "required": true },
        { "field": "delivery_reference", "label": "Delivery Reference", "field_type": "input" },
        { "field": "notes", "label": "Notes", "field_type": "textarea" }
      ],
      "wizard": {
        "enabled": true,
        "steps": [
          { "id": "details", "label": "Delivery Details", "field_keys": ["received_date", "delivery_reference", "notes"] }
        ]
      },
      "operations": { "...": "same shape as any other action" }
    }
  }
}
```

- `fields[]` — same shape as `features.frontend.create.fields[]`/`edit.fields[]`. Before v3.1.0,
  actions had **zero** real field-authoring at all; `ActionComponentGenerator::generateAction()`
  always emitted an empty `form = ref({})` and a literal `<!-- Add your form fields here -->`
  comment (see above) regardless of anything in config. `fields[]` now actually populates the form.
  A relation/FK-picker field works identically to a Create/Edit field — either `field_type:
  "select"` + `splash_key` (e.g. `splash_key: "Accounts"`) or `field_type: "api-select"` with an
  explicit `api_url` both resolve to a self-fetching `ApiSelect2Field`, same as `vendor_id`/
  `location_id` on any generated Create form. (Fixed in generator-engine v3.4.6 — before that, the
  `select`+`splash_key` form crashed an action's own generated component outright with `TypeError:
  Cannot read properties of undefined`, since action forms never populate the Create/Edit-only
  `createSplash`/`editSplash` config block that resolution used to depend on. `inline_items` fields
  never had this problem — they always resolved through a separate, unconditional runtime mechanism.
  If you're on an older generator-engine version, `composer update` first.)
- `wizard.enabled` + `wizard.steps[]` — each step's `field_keys` references `fields[]` entries by
  `field`, or an `inline_items[].key` to render that item's block as its own step instead of a
  field grid. Reuses `generateWizardSteps()`/`generateWizardStateBlock()`, the same functions
  Create/Edit wizards use — a Stepper component, `goNext`/`goBack`, per-step `v-if` blocks.
- **A step whose logic can't be expressed as flat fields** (e.g. a per-line qty/batch/serial
  capture grid, auto-populated from another module's outstanding records) has no config
  mechanism — hand-edit the generated `{Module}{ActionName}Form.vue` directly to add that step's
  markup. This is safe: since v3.1.7, `ActionComponentGenerator` writes both the Service and the
  Form via `writeFileOnce()`, not `writeFile()` — a hand-edited action Service/Form survives every
  future regenerate of that module untouched (before v3.1.7 it did not — any hand-written action
  business logic was silently discarded on the next unrelated regenerate).

### The "Review & Confirm" step (v3.2.0)

A wizard action gets a trailing "Review & Confirm" step **by default**, no config needed —
`wizard.enabled: true` is enough. It auto-summarizes every earlier step's plain fields
(one line per field, showing its label and the value entered) and inline_items blocks (`{label}: N
item(s)`), plus a stable
HTML-comment extension point (a `Custom step summaries` marker a hand-edited step's own summary can
fill in with one extra element per hand-built step). A `CheckboxField` gates the real submit
button behind `confirmed`. Override with an explicit `confirm_step` key (sibling to `wizard`, not
nested in it) — `{ "enabled": false }` to opt out, or the same key works on a **flat**,
non-wizard form too (default off there, since forcing a checkbox onto a 2-3 field form adds
friction without earning it — only turn it on for a flat form that genuinely needs a review gate).

FK select fields summarize with the picked option's real display label (`Vendor: Acme Supplies`),
not the bare id, via `@selected-object` wired into a `fieldLabels` ref — and, for Edit forms, that
same ref is seeded from the already-eager-loaded View response on mount, so a pre-loaded value
(not freshly picked by the user) still shows its label correctly.

### A real gotcha this cost real debugging time to find

`Model::create([...])`'s in-memory return value does **not** reflect a DB-computed column default
(e.g. a `uuid` column with `$table->uuid()->default(DB::raw(...))`) — the model instance in hand
right after `::create()` has `uuid: null` until reloaded. **The standard, already-generated
`{Module}CreateService::process()` already handles this correctly** (it calls `$model->fresh()`
before returning) — so when a hand-written action needs to create a row in *another* module's own
CRUD-generated table (not a row that's this action's own business object), prefer calling that
module's `{Module}CreateService::process($data)` directly over a raw `{Module}Model::create($data)`
— build your own validated `$data` array (bypassing that service's own `validateData()`, which is
shaped for HTTP form input, not an internal caller), and you get the correct `->fresh()` reload,
plus that module's own `beforeCreate()`/`afterCreate()` hooks, for free.

### Splitting one line across multiple sources (FEFO-style)

A stock-consuming action (fulfilling a requested quantity from finite tracked batches or serials)
must not assume a single source can always cover the whole requested quantity — a schema's
`batch_id`/`serial_id` FK being a single nullable column per row is not a limitation here, no
migration is needed. The correct pattern (already proven for serial-tracked lines, which fan out
to one child row per unit — a request for 3 serialized units already becomes 3 separate child
rows, each pinning its own `serial_id`): walk candidate sources in the right order (FEFO =
`expiry_date ASC` for batches), greedily draw from each until the requested quantity is fully
met, and emit **one child row per source actually drawn from** rather than requiring one source to
cover the whole line. Only fail if the sum across every available source still can't meet the
requested quantity.

### Status/lifecycle values: `constants`, never a raw string

Any comparison against a status/lifecycle value (`$model->status?->name !== 'ACCEPTED'`,
`StatusesModel::firstOrCreate(['name' => 'DRAFT'], ...)`) should reference a named constant, not
an inline string — `module.json`'s top-level `constants` key already generates these for free, no
hand-editing: `{"constants": {"ACCEPTED": "ACCEPTED"}}` emits `public const ACCEPTED = 'ACCEPTED';`
directly onto the module's own generated Model (`ModelGenerator::generateConstants()`), on every
regenerate — see [`status_target`](#bulk-actions-—-a-different-nested-config-location) above for
the same mechanism used with a numeric FK id instead of a string. A workflow-specific status name
(e.g. Quotations' `ACCEPTED`, Sales' `COMPLETED`) belongs on **that module's own Model** via its
own `constants` entry — never bolted onto the shared `StatusesModel`, which only owns the generic
`ACTIVE`/`PENDING`/`INACTIVE` vocabulary every module's base `status_id` FK defaults to.

---

## `placement` and the view page's action row

`{Module}ViewModal.vue`'s `[[actionMainButtons]]`/`[[actionMoreItems]]` placeholders (see [Module Structure — `{Module}ViewModal.vue`](/legacy/module-structure#createform-editform-deleteform-and-viewmodal)) are populated entirely from `config['actions']`. Each action with `hasUI: true` gets a button gated by `hasPermission('{Module}.{name}')`:

- `placement: "main"` → a bordered `Button` in the primary row, next to Edit.
- `placement: "more"` (or omitted) → a `DropdownMenuItem` behind "More actions"; `destructive: true` styles it in red.
- A `'modal'`-`uiType` action's click opens an `AppDialog` wrapping its `Form.vue`. A `'page'`-`uiType` action's click is a `router.push()` to its generated route instead.

A `hasUI: false` action gets **no frontend button generated at all** — only the backend route/service/controller method. If you need a one-click, no-form action reachable from the UI, you must hand-wire your own button to call it. There is no real SYSTEM_SHELL example of this today — both of Users' actions (`forceResetPassword`, `resendInvitation`) use `hasUI: true` and get their "More actions" dropdown items generated automatically from `config['actions']`, per the note above.

---

## `urlParams` Explained

`urlParams: ["uuid"]` makes the generated service accept the URL segment as a typed argument:

```php
public static function execute(array $data, string $uuid, array $params = []): array
```

Multiple params — `urlParams: ["uuid", "year"]` — produce `execute(array $data, string $uuid, string $year, array $params = [])`. `ActionServiceGenerator` builds two separate placeholder forms internally (a typed signature form for the method declaration, an untyped args form for the call site) so the generated code is valid PHP in both places — pasting a typed declaration into a call expression would be a fatal error.

::: warning PHPUnit contract-test coverage requires `urlParams` to be `[]` or exactly `['uuid']`
`ActionServiceGenerator` will happily generate the signature above, but `PhpUnitTestGenerator::buildActionServiceTestMethodsForKey()` (`packages/generator-engine/src/Generators/Backend/Tests/PhpUnitTestGenerator.php`, since v2.44.0) only emits a contract test (route registered + requires its permission, and doesn't hard-fail) when `urlParams` is empty (the default route shape) **or** exactly `['uuid']`. Any other shape — multiple params, or a param name other than `uuid` — including the very `["uuid", "year"]` example just above — silently gets **zero** generated PHPUnit test methods for that action, with no warning at generation time. If backend test coverage matters, keep an action's `urlParams` to `[]` or `['uuid']`, or write the contract test by hand for anything else.
:::

### Hand-written service signatures

An action's service is write-once — the generator never overwrites an existing one — so it's normal
to reshape its public entry point by hand (a different method name, different arguments) after the
first generate. The controller method, though, is regenerated on every `--force` and by default
always calls `{Module}{ActionName}Service::execute($data[, ...urlParams])`. If you change the
service's entry point, declare `serviceMethod`/`serviceArgs` on the action in `module.json` (engine
v3.5.21+) so the regenerated controller keeps calling the right method with the right arguments — see
[generator-engine's actions docs](https://joelnjoshkibona.github.io/generator-engine/actions.html#calling-a-hand-written-service-signature-servicemethod-serviceargs-v3-5-17)
for the full vocabulary. Leaving both keys unset is exactly today's call — nothing changes for an
action that doesn't need this.

---

## Bulk actions — a different, nested config location

`features.backend.list.bulk_actions` is **not** part of `actions` — different top-level path, different shape, different calling convention, only related by sharing the word "action":

```json
{
  "features": {
    "backend": {
      "list": {
        "bulk_actions": [
          { "key": "archive", "label": "Archive" }
        ]
      }
    }
  }
}
```

This is consumed by `ListServiceTrait::processBulkAction()` (documented in [Module Structure — List service](/legacy/module-structure#list-service-—-listservicetrait)) — the single shared `POST /{module}/bulk-action` route dispatches by the `action` key in the request body to `{Module}{ActionPascal}Service::execute(array $data, array $params): array`, a **static** method (the same static convention as the single-action service above). There is no dedicated `*BulkActionService.php` base file — the generated service for a `bulk_actions` entry is a plain, static-`execute()` class, and dispatch/validation/batching all live in the trait itself.

**Since generator-engine v2.29.0, this also renders a real bulk-action toolbar** in the generated `{Module}ListPage.vue` (and in a delegation's own tab component, via `operations.list.backend.bulk_actions` — see [Delegations](/legacy/delegations)) — it appears once one or more rows are selected, plus a "select all N matching the filter" banner for dispatching against the whole filtered set rather than just the checked rows. Gated by `{Module}.bulkAction`. Before this, `bulk_actions` only ever produced the backend route/service above — there was no button anywhere to trigger it.

::: warning `status_target` needs a `status_id` FK column, not a string
A `bulk_actions` entry can optionally set `"status_target": "RECEIVED"`, which generates:
```php
$model->update(['status_id' => {Module}Model::RECEIVED]);
```
This hardcodes the column name `status_id` (an integer FK into a `Statuses`-style lookup module) and requires a matching numeric constant on the model — from the separate, hand-authored `constants` config key:
```json
{ "constants": { "RECEIVED": 3 } }
```
where `3` is the real, seeded row id — not the status name as a string. Against a plain string `status` column, `status_target` generates a reference to a `status_id` column that doesn't exist; skip it and hand-fill the transition yourself, same as a single custom action's `process()`.

`constants` isn't only for `status_target` — it's the general mechanism for naming any seeded row's id instead of hardcoding it. See Coding Conventions → Avoid Hardcoding for when to reach for it versus the Settings module.
:::

---

## Related Pages

- [Module structure](/legacy/module-structure) — the full generated-file reference this page builds on, including `{Module}ViewModal.vue`'s action-button rendering and `ListServiceTrait`'s bulk-action dispatch.
- [Delegations and child modules](/legacy/delegations) — the *other* related-record mechanism (`delegations`), unrelated to actions/bulk actions beyond sharing `module.json` as their config home.
- [Parent-child inline items](/legacy/inline-items) — a third, also-unrelated mechanism (`inline_items`).
- generator-engine's own [Actions reference](https://joelnjoshkibona.github.io/generator-engine/actions) and [Examples: Actions](https://joelnjoshkibona.github.io/generator-engine/examples/actions) — the package-level, fixture-verified reference this page is grounded in.

:::
