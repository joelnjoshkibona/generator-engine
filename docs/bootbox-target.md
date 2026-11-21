# Bootbox target

The engine writes modules for two kinds of app. The original target is a SYSTEM_SHELL project (`app/Project/Modules`,
`make:module` in the app); it is frozen: bug fixes only. The **Bootbox target** writes modules for Bootbox, the starter
built from the platform shell kit, and for products made from it.

## How it works

Bootbox's own scaffolders write a module's **skeleton**: its folder, manifest, registration, permissions, menu entry,
scope and phone sync. The engine writes only its **body**, between named slots the scaffolders mark when run with
`--generated` (kit decision P123):

```php
// @generated:rules begin
'name' => ['required', 'string', 'max:200', 'unique_in_org:tasks,name'],
// @generated:rules end
```

What is between a slot's markers belongs to the generator and is replaced on every run. Everything outside belongs to
people and is never touched. The engine never runs a scaffolder with `--force` on a module that exists.

```
php artisan generator:module path/to/generator.json   # first run: skeleton, then the body
php artisan generator:module Tasks                     # later runs: the module's own generator.json
```

```
php artisan generator:modules                          # every Custom module's generator.json, in dependency order
php artisan generator:modules --dir=specs/             # a folder of specs: a new product's modules
php artisan generator:from-legacy module.json --out=specs/Vendors.json   # a V1 / SYSTEM_SHELL module config
```

`generator:modules` generates each module after the modules it points at (foreign keys, a `tab_on` parent, a
document's item module), one process each; two modules pointing at each other stop the run. `generator:from-legacy`
turns a legacy module config into a `generator.json` (scope from a `location_id` column, the first required text
column as the label, actions in the new shape; `features` and the legacy-only keys dropped) or lists every reason it
cannot, at once.

The commands exist only in an app whose `kit.json` names a `generator_contract` the engine supports (today `5`). A
different contract stops the run and says which side is behind.

## generator.json

Today's module config (`module_name`, `module_group_name`, `table_name`, `columns` with `type`, `length`,
`nullable`, `unique`, `relatedModule`; `features.backend.list.*` overrides) plus the starter's keys:

| Key | Meaning |
| --- | --- |
| `scope` | `organisation` (default), `location` or `global`: who the rows belong to |
| `label`, `label_column` | what one record is called (`Task`) and the column it is named by (default `name`; a required string) |
| `labels_sw` | the plural in Swahili, for the list's title |
| `description`, `icon`, `search` | the manifest's description, the menu and search icon, the search entry (`rank`) |
| `sync`, `keep_days` | the phone may keep the module offline (P102; needs `phone`). Set when the module is made: for a module that exists, a spec that disagrees with its `module.json` is refused, naming the key. Its sync test's `fields` slot gets the offline record's required fields (contract 3), and its `module.json` gets `sync.reference`: the modules its records point at, kept on the phone read-only (P126) |
| `workflow` | `{ key, statuses: [{ code, label, label_sw, tone, sort_order, is_terminal }] }`: a P47 status lookup |
| `tab_on` | `{ "Tasks": { key, via, tab, tab_sw } }`: this module's list as a tab on a parent's page (P86); `via` is its column pointing at the parent |
| `lines` | `{ kind, item_module, item_price, prices_include_vat, editable_status, label_sw }`: a document's lines (P115) |
| `number` | `{ column, prefix, label, label_sw }`: the document's number, made on create (`QT-2026-0001`) |
| `calendar` | `{ start, end? }`: date or datetime columns that put the records on the organisation's calendar (P128); written into `module.json` |
| `actions` | `{ "close": { label, label_sw, icon, button, danger, confirm, bulk, approval, apikey, effect, status, fields } }`: record actions (P84); `apikey` opens the action's route to API keys (P127) |

A document's lines live in its own module: a `{table}_lines` table and a `{Module}LineModel` (both rewritten every
run), saved with the header in one request. `kind` is `sale` (the default), `purchase` or `stock` (quantities only);
`item_price` names the item module's price column, which fills a new line's price; `editable_status` is the workflow
code past which the lines are shown, not changed (the server refuses them too). A priced document keeps `subtotal`,
`tax` and `total` of its own, so it may not have columns of those names. Lots, price overrides and conversions are
refused for now.

An action's `button` is `header` or `more` (the default; the legacy `placement: main` reads as `header`, `destructive`
as `danger`). `status` moves the record to that workflow code; `fields` lists the module's own columns it asks for in
its dialog (required there). `bulk` puts it on the list's batch bar (no fields); `approval` gates it by the
organisation's approval workflow (P107), whose `effect` text the approver reads. Wizards, page actions, splash data,
`operations` and other URL parameters are refused.

A column may add `label` (list and filters), `form_label` (the form and the sort), `label_sw`, `values` and
`values_sw` for an enum. A column of `type: workflow` is the workflow's status.

```json
{
  "module_name": "Tasks", "module_group_name": "Work", "scope": "location", "label": "Task",
  "workflow": { "key": "task", "statuses": [{ "code": "open", "label": "Open", "tone": "info", "sort_order": 1 }] },
  "columns": [
    { "name": "name", "type": "string", "length": 200, "unique": true },
    { "name": "task_status_id", "type": "workflow", "label": "Status" },
    { "name": "due_date", "type": "date", "nullable": true, "label": "Due", "form_label": "Due date" },
    { "name": "assignee_id", "type": "foreignId", "relatedModule": "Users", "nullable": true }
  ]
}
```

## What it writes

- **API:** the model's traits, constants, casts and relations; the factory; the migration's columns; the list's
  allowlists and filters; create and edit rules (`exists_in_org`, `unique_in_org`, `MemberOfOrganization` for a
  person); the view's eager loads; the test's fields; the list's CSV columns (`{Module}Csv`, the web list's columns with its words; contract 4); a workflow's statuses migration (a file of its own); the
  manifest's description, label, references, search and workflows. PHP imports have no slot: the engine adds the ones
  it needs and runs Pint, which orders them and removes unused ones. With `actions`: the manifest entries (routes,
  permissions, bulk and approval come from them) and a contract test, rewritten every run (each action needs its
  permission and a record in reach, its fields are validated, an approval action waits); and **once**, each action's
  service, `Services/{Module}{Action}Service.php`, which moves the status and saves the fields it was given, or leaves
  `handle()` for you. A file written once is yours: later runs say "kept" and never touch it. With `lines`: the model's
  `LINES` (and `NUMBER`), the header's totals and number columns, the line rules, the view's `lines.item`, a line in
  the test's fields, and the lines table and line model.
- **Web:** the row and input types; the list's sorts, status quick tabs with counts, columns and cells; the form's
  fields (text, number, switch, date, select, picker), checks and payload; the details page's header and Overview;
  locale keys (a missing Swahili label never replaces a translation). Each web script's imports slot holds exactly
  the lines its body uses. With `tab_on`, the extra tab in `module.ts` and its type entry; with `actions`, the
  `module.ts` entries and, once, a dialog for each action with fields. With `lines`: the editor in the form (one draft,
  one save; a record opened from the list loads its lines first), the number and total in the list and the Overview,
  the lines read-only on the Overview and on a Lines tab.

## How it is checked

Bootbox keeps a module written by hand to the kit, `tools/golden/tasks/`, with its `generator.json`.
`GENERATOR_ENGINE=… bash tools/golden/check.sh --generated` generates it in a throwaway worktree, compares every file
with the hand-written one (markers removed, Pint on both), and runs the API tests, types, lint, strings, build and the
browser test on the generated module.

Bootbox also keeps a capability fixture, `tools/golden/fixture/`: ten modules of other shapes (every scope, foreign keys
between generated modules, labels other than `name`, enums, decimals, dates, two workflows), generated together by
`check.sh --fixture`, with a browser run that creates, opens and deletes a record in each.

Before a release of this package or of Bootbox:

```
BOOTBOX=/path/to/bootbox bin/release-check
```

A column of `type: json` (P125) is stored and cast to an array, validated as one, never a form field (on the web or
the phone; code or its own editor writes it), and shown read-only on the Overview as a code block.

Not yet in this target, and refused if a `generator.json` asks for them: morph and file columns, action wizards
and pages, lots and price overrides on lines. A legacy `delegations` block is pointed at `tab_on`, `inline_items` at
`lines`. Import is not generated either; on the phone (`phone: true`) the list, the form, the record
page and its actions are (P129), but not child tabs or lines yet.
