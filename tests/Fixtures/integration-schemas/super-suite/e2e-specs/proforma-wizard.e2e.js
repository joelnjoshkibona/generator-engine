'use strict';

import { test, expect } from '#e2e-helpers/fixtures.js';
import { loginWithRetry } from '#e2e-helpers/auth.js';
import { BASE_URL, API_URL } from '#e2e-helpers/config.js';

/**
 * super-fixture: a CREATE WIZARD whose middle step is an INLINE-ITEMS table (suite_proforma_invoices).
 *
 * The generated create spec walks a wizard's plain fields and skips an `inline_items` key it finds in a
 * step's `field_keys` -- so it goes through the items step without adding a row, and the review step it then
 * ticks says "0 item(s)". What matters here is everything that only shows with rows in the table:
 *
 *   - rows added in step 2 stay there when the user goes Back and Next again,
 *   - the totals footer and the parent's `total` (the table's `sync_to`) follow the rows,
 *   - the Review & Confirm step summarises the table as "N item(s)",
 *   - ONE submit saves the proforma and every row,
 *   - each Next saves a draft, and a resumed draft brings the rows back.
 *
 * Titles start with "super-fixture" -- that is what run-fixture.sh --full greps for.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const dialog = (page) => page.locator('[role="dialog"]');
const next = (page) => page.locator('[data-testid="suiteproformainvoices-wizard-next"]');
const back = (page) => page.locator('[data-testid="suiteproformainvoices-wizard-back"]');

async function api(page, method, path) {
	const token = await page.evaluate(() => localStorage.getItem('auth_token'));
	const res = await page.request.fetch(`${API_URL}${path}`, {
		method,
		headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
	});
	return { status: res.status(), body: await res.json().catch(() => null) };
}

async function proformasByNo(page, marker) {
	const q = `filters[proforma_no][operator]=contains&filters[proforma_no][value]=${encodeURIComponent(marker)}&params[per_page]=100`;
	const res = await api(page, 'GET', `/suite-proforma-invoices/list?${q}`);
	expect(res.status).toBe(200);
	return res.body.data.data;
}

async function removeProformas(page, marker) {
	for (const row of await proformasByNo(page, marker)) {
		await api(page, 'DELETE', `/suite-proforma-invoices/${row.uuid}/delete`);
	}
}

async function openWizard(page) {
	await page.goto(`${BASE_URL}/suite-proforma-invoices/list`);
	await page.locator('[data-testid="suiteproformainvoices-create"]').waitFor({ timeout: 20000 });
	await page.locator('[data-testid="suiteproformainvoices-create"]').click();
	await next(page).waitFor({ timeout: 10000 });
	await sleep(500);
}

async function pickToday(page, fieldId) {
	await dialog(page).first().locator(`#${fieldId}`).click();
	const calendar = page.locator('[data-slot="calendar"]').last();
	await calendar.waitFor({ timeout: 8000 });
	await calendar.locator('[data-slot="calendar-cell-trigger"][data-today]').click();
	await calendar.waitFor({ state: 'hidden', timeout: 8000 }).catch(() => {});
	await sleep(300);
}

async function fillCustomerStep(page, marker) {
	const form = dialog(page).first();
	await form.locator('#proforma_no').fill(marker);
	await form.locator('#customer_name').fill('Acme Ltd');
	await form.locator('#currency').fill('EUR');
	await pickToday(page, 'valid_until');
}

/** Adds one row through the inline modal: the modal is a second dialog on top of the wizard. */
async function addItem(page, { description, quantity, unitPrice, lineTotal }) {
	const before = await dialog(page).count();
	await dialog(page).first().getByRole('button', { name: 'Add Item' }).click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, before, { timeout: 8000 });
	const modal = dialog(page).last();
	await modal.locator('#description').fill(description);
	await modal.locator('#quantity').fill(String(quantity));
	await modal.locator('#unit_price').fill(String(unitPrice));
	await modal.locator('#line_total').fill(String(lineTotal));
	await modal.getByRole('button', { name: 'Add', exact: true }).click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, before, { timeout: 8000 });
	await sleep(300);
}

const itemRows = (page) => dialog(page).first().locator('table tbody tr');

async function closeAllDialogs(page) {
	for (let i = 0; i < 4 && (await dialog(page).count()) > 0; i++) {
		await dialog(page).last().getByRole('button', { name: 'Close' }).click().catch(() => {});
		await sleep(500);
	}
}

test.describe('super-fixture / create wizard with an inline-items step', () => {
	test('super-fixture / the items step keeps its rows across Back/Next, feeds the review step, and one submit saves everything', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `PFW-${Date.now()}`;

		try {
			await openWizard(page);

			// Step 1: Customer.
			await fillCustomerStep(page, marker);
			await next(page).click();
			await sleep(300);

			// Step 2: Items. The table starts empty; two rows go in through the modal.
			await expect(itemRows(page), 'no rows yet').toHaveCount(0);
			await addItem(page, { description: 'Widget', quantity: 2, unitPrice: 5, lineTotal: 10 });
			await addItem(page, { description: 'Gadget', quantity: 1, unitPrice: 7.5, lineTotal: 7.5 });
			await expect(itemRows(page)).toHaveCount(2);

			// The totals footer sums the rows, and the parent's `total` (the table's sync_to) follows it.
			await expect(dialog(page).first().locator('tfoot')).toContainText('17.5');
			await expect(dialog(page).first().locator('#total'), 'the parent total is kept equal to the rows\' sum').toHaveValue(/^17\.5/);

			// Back to step 1 and forward again: the rows are form state, not step state.
			await back(page).click();
			await sleep(300);
			await expect(dialog(page).first().locator('#proforma_no')).toHaveValue(marker);
			await next(page).click();
			await sleep(300);
			await expect(itemRows(page), 'the rows survive leaving the step').toHaveCount(2);

			// Step 3: Notes.
			await next(page).click();
			await sleep(300);
			await dialog(page).first().locator('#notes').fill('Net 30');
			await next(page).click();
			await sleep(300);

			// Review & Confirm names the table by its size and shows the customer step's values.
			const review = dialog(page).first();
			await expect(review).toContainText('Items: 2 item(s)');
			await expect(review).toContainText('Customer Name: Acme Ltd');
			await expect(review).toContainText('Total: 17.5');
			await expect(review).toContainText('Notes: Net 30');

			await dialog(page).first().locator('#wizard-confirm').click();
			await dialog(page).first().locator('[data-testid="suiteproformainvoices-submit"]').click();
			await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 });
			await sleep(500);

			const rows = await proformasByNo(page, marker);
			expect(rows, 'the wizard created exactly one proforma').toHaveLength(1);
			expect(Number(rows[0].total)).toBe(17.5);
			expect(rows[0].currency).toBe('EUR');

			// One request saved the parent AND both rows: read them back through the view endpoint.
			const view = (await api(page, 'GET', `/suite-proforma-invoices/${rows[0].uuid}/view`)).body?.data;
			const items = view?.proforma_items ?? [];
			expect(items.map((i) => i.description).sort()).toEqual(['Gadget', 'Widget']);
		} finally {
			await closeAllDialogs(page);
			await removeProformas(page, marker);
		}
	});

	test('super-fixture / each Next saves a draft, and resuming it puts the items table back', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `PFD-${Date.now()}`;

		try {
			await openWizard(page);
			await fillCustomerStep(page, marker);
			await next(page).click();
			await sleep(300);
			await addItem(page, { description: 'Drafted', quantity: 3, unitPrice: 4, lineTotal: 12 });
			await expect(itemRows(page)).toHaveCount(1);

			// Next on step 2 saves a draft (goNext -> saveDraft) that now holds the items array too.
			await next(page).click();
			await sleep(1200);
			await closeAllDialogs(page);

			// A new form gets a new draft key, so the saved draft is resumable from the banner.
			await openWizard(page);
			await expect(dialog(page).first().locator('#proforma_no')).toHaveValue('');
			await dialog(page).first().locator('[data-testid="drafts-view"]').click();
			await page.locator('[data-testid="draft-resume"]').first().click();
			await expect(dialog(page).first().locator('#proforma_no'), 'the customer step is restored').toHaveValue(marker);

			await next(page).click();
			await sleep(300);
			await expect(itemRows(page), 'and so is the items table').toHaveCount(1);
			await expect(itemRows(page).first()).toContainText('Drafted');

			// Leave nothing behind: the resumed draft is the active one, so delete it from the banner list.
			await dialog(page).first().locator('[data-testid="drafts-view"]').click();
			await page.locator('[data-testid="draft-delete"]').first().click();
			await expect(page.locator('[data-testid="drafts-view"]')).toHaveCount(0);
		} finally {
			await closeAllDialogs(page);
			await removeProformas(page, marker);
		}
	});
});
