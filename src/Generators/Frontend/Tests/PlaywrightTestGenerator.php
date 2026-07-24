<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates a Playwright e2e test stub (e2e/<module-route>.e2e.js) for a
 * module: list -> create -> filter -> view -> edit -> delete, gated by
 * which features.frontend.* flags the module actually declares.
 *
 * Structurally mirrors the hand-written reference pattern in
 * SYSTEM_SHELL/FRONTEND/e2e/location-types-crud.e2e.js (retry/settle
 * helpers, self-cleaning try/finally) while targeting the view-modal-first
 * DOM shape that the CURRENT frontend generator actually produces (see
 * Templates/frontend/features/list/page.stub, features/view/modal.stub,
 * features/delete/form.stub, and BaseComponentGenerator's submit-button
 * markup) rather than the older hand-written LocationTypes page, which
 * predates that pattern and has no view/submit testids of its own:
 *   - list row "view" button:            [[moduleName]]-view-{uuid}
 *   - view-modal "edit" button:          [[moduleName]]-edit-{uuid}
 *   - view-modal "More Actions" -> item: [[moduleName]]-delete-{uuid}
 *   - create/edit form submit button:    [[moduleName]]-submit
 *   - delete-form confirm button:        [[moduleName]]-confirm-delete
 */
class PlaywrightTestGenerator extends BaseGenerator
{
    protected bool $hasCreate;
    protected bool $hasView;
    protected bool $hasEdit;
    protected bool $hasDelete;

    /** @var array<int, array<string, mixed>> features.frontend.create.fields */
    protected array $createFields;
    /** @var array<int, array<string, mixed>> features.frontend.edit.fields */
    protected array $editFields;

    /**
     * features.backend.list.filterFields — NOT features.frontend.list.
     * frontend.list only carries display `fields` + `primaryField`; the
     * filterFields the list's filter panel actually exposes live under
     * backend.list (confirmed against the real LocationTypes/Locations
     * module.json files, and independently against how
     * PhpUnitTestGenerator::firstFilterFieldKey() reads this same key for
     * the analogous backend test generator).
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $filterFields;

    protected ?string $primaryField;

    protected ?string $anchorField = null;
    protected bool $anchorFieldResolved = false;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        $frontend = $config['features']['frontend'] ?? [];
        $this->hasCreate = !empty($frontend['create']);
        $this->hasView   = !empty($frontend['view']);
        $this->hasEdit   = !empty($frontend['edit']);
        $this->hasDelete = !empty($frontend['delete']);

        $this->createFields = $frontend['create']['fields'] ?? [];
        $this->editFields    = $frontend['edit']['fields'] ?? [];
        $this->filterFields  = $config['features']['backend']['list']['filterFields'] ?? [];
        $this->primaryField  = $frontend['list']['primaryField'] ?? null;
    }

    public function generate(): bool
    {
        $stub = $this->getTemplateContent('tests/crud.e2e', 'frontend');

        $content = str_replace(
            ['[[helperFunctions]]', '[[testDescription]]', '[[testBody]]'],
            [$this->buildHelperFunctions(), $this->buildTestDescription(), $this->buildTestBody()],
            $stub
        );

        $content = $this->replacePlaceholders($content);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . '/e2e/' . Str::kebab($this->moduleName) . '.e2e.js';

        return $this->writeFile($filePath, $content);
    }

    // ------------------------------------------------------------------
    // Field selection helpers
    // ------------------------------------------------------------------

    protected function hasFieldType(string $type): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (($field['field_type'] ?? 'input') === $type) {
                return true;
            }
        }
        return false;
    }

    protected function isScalarField(array $field): bool
    {
        return !in_array($field['field_type'] ?? 'input', ['select', 'file-input', 'checkbox'], true);
    }

    /**
     * First plain scalar (text/number) create field — preferring
     * features.frontend.list.primaryField when it points at one — used as
     * the row we can reliably re-find by visible text after creation.
     * Memoized: called from several block-builders.
     */
    protected function pickAnchorField(): ?string
    {
        if ($this->anchorFieldResolved) {
            return $this->anchorField;
        }
        $this->anchorFieldResolved = true;

        $byKey = [];
        foreach ($this->createFields as $field) {
            if (!empty($field['field'])) {
                $byKey[$field['field']] = $field;
            }
        }

        if ($this->primaryField && isset($byKey[$this->primaryField]) && $this->isScalarField($byKey[$this->primaryField])) {
            $this->anchorField = $this->primaryField;
            return $this->anchorField;
        }

        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                $this->anchorField = $field['field'];
                return $this->anchorField;
            }
        }

        return null;
    }

    /**
     * The one edit field the EDIT block changes. Prefers a plain scalar
     * field that ISN'T the anchor field used to find/filter the row (so the
     * row stays reliably re-findable by its original text throughout the
     * View step, mirroring location-types-crud.e2e.js editing "code" while
     * leaving "name" — its search key — untouched); falls back to the
     * anchor field itself, then to whatever the first edit field is, rather
     * than skip editing entirely.
     */
    protected function pickEditField(): ?array
    {
        $anchor = $this->pickAnchorField();

        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $field['field'] !== $anchor && $this->isScalarField($field)) {
                return $field;
            }
        }
        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                return $field;
            }
        }
        return $this->editFields[0] ?? null;
    }

    /** Chosen filterFields entry for the FILTER block's Variant A: first non-id text-type entry, or null. */
    protected function pickTextFilterField(): ?array
    {
        foreach ($this->filterFields as $ff) {
            $key = $ff['key'] ?? '';
            if ($key !== '' && $key !== 'id' && ($ff['type'] ?? '') === 'text') {
                return $ff;
            }
        }
        return null;
    }

    /** JS expression (as a PHP string) for a field's create-time unique value. References the in-scope `stamp` const. */
    protected function fieldValueExpr(array $field): string
    {
        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));

        if (in_array($type, $numericTypes, true)) {
            return '(1000000 + (stamp % 900000))';
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ ${stamp}`
JS;
        return str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );
    }

    /** Like fieldValueExpr() but produces a distinguishable value for the EDIT block's one changed field. */
    protected function editedFieldValueExpr(array $field): string
    {
        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));

        if (in_array($type, $numericTypes, true)) {
            return '(2000000 + (stamp % 900000))';
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ EDIT ${stamp}`
JS;
        return str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );
    }

    /**
     * Renders the JS statement(s) that fill one create/edit field inside the
     * open `[role="dialog"]`, dispatching on field_type. `$valueExpr` is
     * used for the default (plain input) branch only.
     */
    protected function renderFieldFill(array $field, string $valueExpr = ''): string
    {
        $key = (string) ($field['field'] ?? '');
        if ($key === '') {
            return '';
        }
        $fieldType = $field['field_type'] ?? 'input';
        $label = (string) ($field['label'] ?? $key);

        if ($fieldType === 'select') {
            $tpl = <<<'JS'
		await fillSelectField(page, '[role="dialog"]', '__LABEL__');
JS;
            return str_replace('__LABEL__', addcslashes($label, "'\\"), $tpl);
        }

        if ($fieldType === 'checkbox') {
            $tpl = <<<'JS'
		await page.locator('[role="dialog"] #__KEY__').click();
JS;
            return str_replace('__KEY__', $key, $tpl);
        }

        if ($fieldType === 'file-input') {
            return <<<'JS'
		await page.locator('[role="dialog"] input[type="file"]').setInputFiles({ name: 'e2e-fixture.png', mimeType: 'image/png', buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]) });
JS;
        }

        $tpl = <<<'JS'
		await fillField(page, '[role="dialog"] #__KEY__', __VALUE__);
JS;
        return str_replace(['__KEY__', '__VALUE__'], [$key, $valueExpr], $tpl);
    }

    // ------------------------------------------------------------------
    // Helper-function block builders (top of file, above test.describe)
    // ------------------------------------------------------------------

    protected function buildHelperFunctions(): string
    {
        $blocks = [];

        if ($this->hasCreate || $this->hasEdit || $this->hasDelete) {
            $blocks[] = $this->fillFieldHelpersBlock();
        }

        if ($this->hasFieldType('select')) {
            $blocks[] = $this->selectFieldHelperBlock();
        }

        $blocks[] = $this->rowHelpersBlock();

        if ($this->hasDelete) {
            $blocks[] = $this->cleanupHelperBlock();
        }

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    protected function fillFieldHelpersBlock(): string
    {
        return <<<'JS'
/** Replace an input's value directly and dispatch a real `input`/`change`
 * event (avoids selection/keystroke quirks with select-all + retype on
 * Vue-controlled inputs). */
async function setInputValue(page, selector, value) {
	await page.evaluate(
		({ sel, val }) => {
			const el = document.querySelector(sel);
			el.value = val;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		},
		{ sel: selector, val: String(value) },
	);
}

/**
 * Fill a field, then verify the value actually landed — falls back to a
 * direct value-set if `.fill()` dropped keystrokes or a background
 * re-render reset the field. Throws with a clear diagnostic rather than
 * letting a blank field silently reach form submit.
 */
async function fillField(page, selector, value) {
	const expected = String(value);
	await page.locator(selector).fill(expected);
	let actual = await page.locator(selector).inputValue();
	if (actual !== expected) {
		await setInputValue(page, selector, expected);
		actual = await page.locator(selector).inputValue();
		if (actual !== expected) {
			throw new Error(`Could not fill ${selector}: stuck at "${actual}", expected "${expected}"`);
		}
	}
}
JS;
    }

    protected function selectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort driver for a Select2Field/ApiSelect2Field control inside
 * `dialogSelector`, located by its <label> text (mirrors the nested-dialog
 * pattern used by helpers/filters.js's setFilterOperator and the ApiSelect2
 * helpers in locations.e2e.js). Opens the trigger, waits for the picker
 * popup stacked on top, and clicks the first selectable option — enough to
 * satisfy a required relation/select field without knowing real seed data.
 * Generic across both the local Select2Field (`.cursor-pointer` option rows)
 * and the API-backed ApiSelect2Field (`.divide-y > div` option rows) shapes;
 * may need per-module adjustment for more elaborate pickers.
 */
async function fillSelectField(page, dialogSelector, labelText) {
	const trigger = page
		.locator(dialogSelector)
		.locator('.space-y-2', { has: page.locator('label', { hasText: labelText }) })
		.locator('.select2-trigger button');
	if ((await trigger.count()) === 0) {
		throw new Error(`fillSelectField: no select2 trigger found for label "${labelText}" in "${dialogSelector}"`);
	}

	const beforeCount = await page.locator('[role="dialog"]').count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });
	await new Promise((r) => setTimeout(r, 300));

	const popup = page.locator('[role="dialog"]').last();
	const apiOptions = popup.locator('.divide-y > div');
	const option = (await apiOptions.count()) > 0 ? apiOptions.first() : popup.locator('.cursor-pointer').first();
	if ((await option.count()) === 0) {
		throw new Error(`fillSelectField: no selectable options found for "${labelText}" — check seed data`);
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
}
JS;
    }

    protected function rowHelpersBlock(): string
    {
        return <<<'JS'
/** Locator for the row(s) whose text contains `text`. */
function rowLocator(page, text) {
	return page.locator('table tbody tr', { hasText: text });
}

async function rowExists(page, name) {
	return (await rowLocator(page, name).count()) > 0;
}

/** Extract the uuid segment from a `data-testid="[[moduleName]]-{action}-{uuid}"` attribute. */
function uuidFromTestId(testId, action) {
	if (!testId) return null;
	const prefix = `[[moduleName]]-${action}-`;
	return testId.startsWith(prefix) ? testId.slice(prefix.length) : null;
}
JS;
    }

    protected function cleanupHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort delete of a record left over from a failed run. Invoked from a
 * `finally` block once the record's uuid has been captured right after
 * creation, so this runs regardless of whether the view/edit/delete steps
 * after creation succeeded or threw.
 *
 * Every failure here is swallowed and logged as a WARNING rather than
 * thrown — this executes during failure unwinding and must never replace or
 * mask the original error that triggered it.
 */
async function cleanupStrayRecord(page, uuid) {
	try {
		if ((await page.locator('[role="dialog"]').count()) > 0) {
			await page.keyboard.press('Escape').catch(() => {});
			await sleep(500);
		}

		const stillPresent = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count().catch(() => 0)) > 0;
		if (!stillPresent) {
			console.log(`[${MODULE_LABEL}] cleanup: uuid ${uuid} no longer in the list — nothing to do`);
			return;
		}

		console.log(`[${MODULE_LABEL}] cleanup: attempting best-effort delete of uuid ${uuid} after failure`);

		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`cleanup view button (data-testid="[[moduleName]]-view-${uuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${uuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await sleep(500);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		const stillThere = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count()) > 0;
		if (stillThere) {
			console.log(`[${MODULE_LABEL}] WARNING: cleanup delete did not remove uuid ${uuid} — manual removal may be required`);
		} else {
			console.log(`[${MODULE_LABEL}] cleanup: deleted stray record (uuid ${uuid})`);
		}
	} catch (cleanupErr) {
		console.log(
			`[${MODULE_LABEL}] WARNING: cleanup failed for uuid ${uuid} — manual removal may be required:`,
			cleanupErr && cleanupErr.stack ? cleanupErr.stack : cleanupErr,
		);
	}
}
JS;
    }

    // ------------------------------------------------------------------
    // Test description + body
    // ------------------------------------------------------------------

    protected function buildTestDescription(): string
    {
        $steps = [];
        if ($this->hasCreate) {
            $steps[] = 'create';
        }
        $steps[] = 'filter';
        $steps[] = 'view';
        if ($this->hasEdit) {
            $steps[] = 'edit';
        }
        if ($this->hasDelete) {
            $steps[] = 'delete';
        }

        return implode(' -> ', $steps) . ' cycle (auto-generated)';
    }

    protected function buildTestBody(): string
    {
        $sections = [];

        $createBlock = $this->buildCreateBlock();
        if (trim($createBlock) !== '') {
            $sections[] = $createBlock;
        }

        $sections[] = $this->buildTargetRowBlock();
        $sections[] = $this->buildFilterBlock();
        $sections[] = $this->buildUuidCaptureBlock();

        $inner = [];
        $inner[] = $this->buildViewBlock();
        if ($this->hasEdit) {
            $inner[] = $this->buildEditBlock();
        }
        if ($this->hasDelete) {
            $inner[] = $this->buildDeleteBlock();
        }
        $innerBlock = implode("\n\n", array_filter($inner, fn ($s) => trim($s) !== ''));

        if ($this->hasDelete) {
            $wrapTpl = <<<'JS'
		let recordCleanedUp = false;
		try {
__INNER__

			recordCleanedUp = true;
		} finally {
			// Guaranteed to run whether the steps above succeeded or threw. If
			// the Delete block above already ran to completion, recordCleanedUp
			// is true and there is nothing to do — this only fires for failure paths.
			if (recordUuid && !recordCleanedUp) {
				await cleanupStrayRecord(page, recordUuid);
			}
		}
JS;
            // buildViewBlock()/buildEditBlock()/buildDeleteBlock() are written at the
            // test body's base 2-tab depth (the same depth as the try/finally lines
            // above) since that's also where they land verbatim when hasDelete is
            // false (the plain `$innerBlock` branch below). Wrapping them in try{}
            // here nests them one level deeper, so — only in this branch — every
            // line needs an extra tab or the generated spec reads with the whole
            // View/Edit/Delete body flush against `try {`/`} finally {` themselves.
            $sections[] = str_replace('__INNER__', $this->indentBlock($innerBlock), $wrapTpl);
        } else {
            $sections[] = $innerBlock;
        }

        return implode("\n\n", array_filter($sections, fn ($s) => trim($s) !== ''));
    }

    /**
     * Indent every non-blank line of a multi-line generated JS block by one
     * extra tab level. Blank lines are left untouched so no trailing
     * whitespace gets introduced.
     */
    protected function indentBlock(string $block, int $levels = 1): string
    {
        $prefix = str_repeat("\t", $levels);
        $lines = explode("\n", $block);
        foreach ($lines as &$line) {
            if ($line !== '') {
                $line = $prefix . $line;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Individual step blocks
    // ------------------------------------------------------------------

    protected function buildCreateBlock(): string
    {
        if (!$this->hasCreate) {
            return '';
        }

        $open = <<<'JS'
		// ── Create ────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			() => page.locator('[data-testid="[[moduleName]]-create"]').click(),
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '02-create-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing
JS;

        $declLines = [];
        foreach ($this->createFields as $field) {
            $key = $field['field'] ?? '';
            $fieldType = $field['field_type'] ?? 'input';
            if ($key === '' || in_array($fieldType, ['select', 'file-input', 'checkbox'], true)) {
                continue;
            }
            $declLines[] = "\t\t\t{$key}: " . $this->fieldValueExpr($field) . ',';
        }

        $declBlock = '';
        if (!empty($declLines)) {
            $declBlock = "\n\n\t\tconst createValues = {\n" . implode("\n", $declLines) . "\n\t\t};";
        }

        $fillLines = [];
        foreach ($this->createFields as $field) {
            $key = $field['field'] ?? '';
            if ($key === '') {
                continue;
            }
            $fieldType = $field['field_type'] ?? 'input';
            $valueExpr = in_array($fieldType, ['select', 'file-input', 'checkbox'], true) ? '' : ('createValues.' . $key);
            $line = $this->renderFieldFill($field, $valueExpr);
            if ($line !== '') {
                $fillLines[] = $line;
            }
        }
        $fillBlock = implode("\n", $fillLines);

        $submit = <<<'JS'
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
JS;

        $anchor = $this->pickAnchorField();
        if ($anchor !== null) {
            $confirmTpl = <<<'JS'

		const createdRowText = String(createValues.__ANCHOR__);
		await page.waitForFunction(
			(rowText) => Array.from(document.querySelectorAll('table tbody tr')).some((tr) => tr.textContent.includes(rowText)),
			createdRowText,
			{ timeout: 15000 },
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" did not appear in the list`).toBe(true);
		console.log(`[${MODULE_LABEL}] create OK — row "${createdRowText}" present`);
JS;
            $confirm = str_replace('__ANCHOR__', $anchor, $confirmTpl);
        } else {
            $confirm = <<<'JS'

		console.log(`[${MODULE_LABEL}] create submitted (no plain text/number field available — skipping row-text assertion)`);
JS;
        }

        $shotLine = "\n\t\tawait shot(page, '03-after-create');";

        return $open . $declBlock . "\n\n" . $fillBlock . "\n\n" . $submit . $confirm . $shotLine;
    }

    protected function buildTargetRowBlock(): string
    {
        if ($this->hasCreate && $this->pickAnchorField() !== null) {
            return <<<'JS'
		const targetRow = rowLocator(page, createdRowText).first();
JS;
        }

        return <<<'JS'
		const targetRow = page.locator('table tbody tr').first();
JS;
    }

    protected function buildFilterBlock(): string
    {
        $textFilter = $this->pickTextFilterField();
        $anchor = $this->pickAnchorField();
        $useVariantA = $textFilter !== null && $this->hasCreate && $anchor !== null && $textFilter['key'] === $anchor;

        if ($useVariantA) {
            return $this->buildFilterVariantA((string) $textFilter['key']);
        }

        return $this->buildFilterVariantB();
    }

    protected function buildFilterVariantA(string $filterKey): string
    {
        $tpl = <<<'JS'
		// ── Filter (Variant A: plain text field "__KEY__") ───────────────────
		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterTextValue(page, '__KEY__', createdRowText);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after filtering by __KEY__`).toBe(true);
		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by __KEY__="${createdRowText}" to narrow the list to 1 row, got ${filteredRowCount}`).toBe(1);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__ narrowed the list to exactly 1 row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after clearing filters`).toBe(true);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace('__KEY__', $filterKey, $tpl);
    }

    protected function buildFilterVariantB(): string
    {
        return <<<'JS'
		// ── Filter (Variant B: id-based) ─────────────────────────────────────
		const targetId = await getRowColumnValue(page, targetRow, 'ID');
		console.log(`[${MODULE_LABEL}] filter: captured target row's ID = "${targetId}"`);

		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterOperator(page, 'id', 'Equals');
		await setFilterTextValue(page, 'id', targetId);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by id=${targetId} (Equals) to narrow the list to exactly 1 row, got ${filteredRowCount}`).toBe(1);
		const onlyRow = page.locator('table tbody tr').first();
		const onlyRowId = await getRowColumnValue(page, onlyRow, 'ID');
		expect(
			onlyRowId,
			`Expected the sole remaining row after filtering by id=${targetId} to be the target row, got id="${onlyRowId}"`,
		).toBe(targetId);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by id="${targetId}" (Equals) narrowed the list to exactly the target row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;
    }

    protected function buildUuidCaptureBlock(): string
    {
        return <<<'JS'
		const recordTestId = await targetRow
			.locator('[data-testid^="[[moduleName]]-view-"]')
			.first()
			.getAttribute('data-testid')
			.catch(() => null);
		const recordUuid = uuidFromTestId(recordTestId, 'view');
		if (recordUuid) {
			console.log(`[${MODULE_LABEL}] captured uuid ${recordUuid} — view/edit/delete steps below are now targetable`);
		} else {
			console.log(`[${MODULE_LABEL}] WARNING: could not capture a record uuid — view/edit/delete steps below may fail`);
		}
JS;
    }

    protected function buildViewBlock(): string
    {
        $tpl = <<<'JS'
		// ── View ──────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 }).catch(() => {});
		await sleep(500);
		await shot(page, '04-view-modal');
__VIEW_ASSERT__
JS;

        if ($this->hasCreate && $this->pickAnchorField() !== null) {
            $assert = <<<'JS'

		const viewText = (await page.locator('[role="dialog"]').textContent()) || '';
		expect(viewText.includes(createdRowText), `View modal did not render expected value "${createdRowText}"`).toBe(true);
		console.log(`[${MODULE_LABEL}] view OK — modal shows the created record`);
JS;
        } else {
            $assert = <<<'JS'

		console.log(`[${MODULE_LABEL}] view OK — modal opened for the target record`);
JS;
        }

        return str_replace('__VIEW_ASSERT__', $assert, $tpl);
    }

    protected function buildEditBlock(): string
    {
        $field = $this->pickEditField();
        if ($field === null || empty($field['field'])) {
            return <<<'JS'
		// ── Edit ──────────────────────────────────────────────────────────────
		console.log(`[${MODULE_LABEL}] edit skipped — no editable fields declared in features.frontend.edit.fields`);
JS;
        }

        $key = (string) $field['field'];
        $fieldType = $field['field_type'] ?? 'input';

        $openTpl = <<<'JS'
		// ── Edit (via the view modal's Edit button) ──────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const editBtn = page.locator(`[data-testid="[[moduleName]]-edit-${recordUuid}"]`);
				if ((await editBtn.count()) === 0) throw new Error(`Edit button (data-testid="[[moduleName]]-edit-${recordUuid}") not found`);
				await editBtn.click();
			},
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '05-edit-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before editing
JS;

        if (!$this->isScalarField($field)) {
            // Non-scalar edit field (select/checkbox/file-input): reuse the
            // same fill logic as create, submit, and only assert the flow
            // completed — there's no deterministic text value to diff
            // against for these field types the way a plain input has.
            $fillLine = $this->renderFieldFill($field, '');
            $submitTpl = <<<'JS'

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
		console.log(`[${MODULE_LABEL}] edit OK — submitted an updated __KEY__ value`);
		await shot(page, '06-after-edit');
JS;
            $submit = str_replace('__KEY__', $key, $submitTpl);

            return $openTpl . "\n" . $fillLine . $submit;
        }

        $valueExpr = $this->editedFieldValueExpr($field);
        $fillEditTpl = <<<'JS'

		const editedValue = __VALUE__;
		await setInputValue(page, '[role="dialog"] #__KEY__', editedValue);
		const editedActual = await page.locator('[role="dialog"] #__KEY__').inputValue();
		if (editedActual !== editedValue) {
			throw new Error(`Could not set edit #__KEY__: stuck at "${editedActual}", expected "${editedValue}"`);
		}

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		await page.waitForFunction(
			({ uuid, value }) => {
				const btn = document.querySelector(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				const row = btn ? btn.closest('tr') : null;
				return !!row && row.textContent.includes(value);
			},
			{ uuid: recordUuid, value: editedValue },
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] edit OK — record now shows the updated __KEY__ value`);
		await shot(page, '06-after-edit');
JS;
        $fillEdit = str_replace(['__VALUE__', '__KEY__'], [$valueExpr, $key], $fillEditTpl);

        return $openTpl . $fillEdit;
    }

    protected function buildDeleteBlock(): string
    {
        $reopen = '';
        if ($this->hasEdit) {
            $reopen = <<<'JS'
		// Edit closed the view modal — reopen it before reaching Delete (mirrors
		// locations.e2e.js / wards.e2e.js: view -> edit -> view again -> delete).
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found before delete`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

JS;
        }

        $body = <<<'JS'
		// ── Delete (via the view modal's "More Actions" -> Delete) ───────────
		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${recordUuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await shot(page, '07-delete-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		expect(await page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`).count(), 'Row still present after delete').toBe(0);
		console.log(`[${MODULE_LABEL}] delete OK — record gone`);
		await shot(page, '08-after-delete');
JS;

        return $reopen . $body;
    }
}
