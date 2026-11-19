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

The command exists only in an app whose `kit.json` names a `generator_contract` the engine supports (today `1`). A
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
| `sync`, `keep_days` | the phone may keep the module offline |
| `workflow` | `{ key, statuses: [{ code, label, label_sw, tone, sort_order, is_terminal }] }`: a P47 status lookup |

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
  person); the view's eager loads; the test's fields; a workflow's statuses migration (a file of its own); the
  manifest's description, label, references, search and workflows. PHP imports have no slot: the engine adds the ones
  it needs and runs Pint, which orders them and removes unused ones.
- **Web:** the row and input types; the list's sorts, status quick tabs with counts, columns and cells; the form's
  fields (text, number, switch, date, select, picker), checks and payload; the details page's header and Overview;
  locale keys (a missing Swahili label never replaces a translation). Each web script's imports slot holds exactly
  the lines its body uses.

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

Not yet in this target, and refused if a `generator.json` asks for them: delegations, actions, inline items, json,
morph and file columns. Wizards, import and the phone app's screens are not generated either.
