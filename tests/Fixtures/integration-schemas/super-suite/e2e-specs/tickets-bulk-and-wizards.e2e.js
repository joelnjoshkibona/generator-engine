'use strict';

import fs from 'node:fs';
import { test, expect } from '#e2e-helpers/fixtures.js';
import { loginWithRetry } from '#e2e-helpers/auth.js';
import { BASE_URL, API_URL } from '#e2e-helpers/config.js';

/**
 * super-fixture: what the generated SuiteTickets specs cannot assert.
 *
 * The generated list spec toggles batch mode, selects two rows, runs one bulk action and checks that a
 * result drawer opens -- it never looks at what happened to the rows, never selects "all N matching",
 * never sees a row fail. The generated action spec proves a wizard's dialog closes. Nothing generated
 * checks that a wizard's review step shows a person's NAME rather than their id, or reads a real
 * export. These do, against the effects the fixture's module-overlays/ give the write-once stubs
 * (`expedite` fails a BLOCKED row; `escalate` writes the wizard's input to the record).
 *
 * Titles start with "super-fixture" -- that is what run-fixture.sh --full greps for.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const dialog = (page) => page.locator('[role="dialog"]');

async function api(page, method, path, data) {
	const token = await page.evaluate(() => localStorage.getItem('auth_token'));
	const res = await page.request.fetch(`${API_URL}${path}`, {
		method,
		headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
		data: data === undefined ? undefined : JSON.stringify(data),
	});
	return { status: res.status(), body: await res.json().catch(() => null) };
}

async function makeTicket(page, title, priority = 'normal', reason = undefined) {
	const res = await api(page, 'POST', '/suite-tickets/create', { title, status_id: 1, priority, reason });
	expect(res.status, `creating ticket "${title}": ${JSON.stringify(res.body)}`).toBeLessThan(300);
	return res.body.data.uuid;
}

/** The tickets whose title contains `marker`, straight from the list endpoint. */
async function ticketsByMarker(page, marker) {
	const q = `filters[title][operator]=contains&filters[title][value]=${encodeURIComponent(marker)}&params[per_page]=100`;
	const res = await api(page, 'GET', `/suite-tickets/list?${q}`);
	expect(res.status).toBe(200);
	return res.body.data.data;
}

async function removeTickets(page, marker) {
	for (const row of await ticketsByMarker(page, marker)) {
		await api(page, 'DELETE', `/suite-tickets/${row.uuid}/delete`);
	}
}

async function openList(page, marker, perPage = 25) {
	await page.goto(
		`${BASE_URL}/suite-tickets/list?per_page=${perPage}&f[title][operator]=contains&f[title][value]=${encodeURIComponent(marker)}`,
	);
	await page.waitForFunction(() => document.querySelector('[data-testid^="suitetickets-view-"]') !== null, null, { timeout: 20000 });
	await sleep(400);
}

const selectBox = (page, title) =>
	page.locator('table tbody tr', { hasText: title }).locator('[data-testid^="suitetickets-bulk-select-"]');

/** Open an ApiSelect2/Select2 picker by its field label and choose an option; returns the option's first line. */
async function pickOption(page, label, matcher = null) {
	const trigger = dialog(page)
		.locator('label', { hasText: new RegExp('^' + label + '\\s*\\*?\\s*$') })
		.locator('xpath=..')
		.locator('[data-slot="select2-trigger"]');
	const before = await dialog(page).count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, before, { timeout: 8000 });
	const picker = dialog(page).last();
	const rows = picker.locator('.divide-y > div, .cursor-pointer');
	await rows.first().waitFor({ timeout: 10000 });
	const option = matcher ? rows.filter({ hasText: matcher }).first() : rows.first();
	const text = ((await option.innerText()).trim().split('\n')[0] || '').trim();
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, before, { timeout: 8000 });
	return text;
}

async function closeDrawer(page) {
	await page.keyboard.press('Escape');
	await page.locator('[data-testid="batch-result-drawer"]').waitFor({ state: 'hidden', timeout: 15000 });
}

test.describe('super-fixture / tickets', () => {
	test('super-fixture / batch mode starts off, select-all-matching honours exclusions, and a failing row is reported without undoing the others', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXB-${Date.now()}`;
		try {
			// Created A, B, C, D: the list sorts newest first, so with two per page D and C are on page 1.
			// C and D are BLOCKED, which `expedite` refuses; D is excluded from the selection anyway.
			await makeTicket(page, `${marker} A`, 'normal');
			await makeTicket(page, `${marker} B`, 'normal');
			await makeTicket(page, `${marker} C`, 'normal', 'BLOCKED');
			await makeTicket(page, `${marker} D`, 'normal', 'BLOCKED');

			await openList(page, marker, 2);

			// batchModeDefault is false: no selection column until the toggle is used.
			expect(await page.locator('[data-testid^="suitetickets-bulk-select-"]').count(), 'no select column by default').toBe(0);
			await page.locator('[data-testid="batch-mode-toggle"]').click();
			// One checkbox per row (the header's own select-all box is not a row).
			await expect(page.locator('table tbody tr [data-testid^="suitetickets-bulk-select-"]')).toHaveCount(2);

			// Checking the whole page (the header box) offers "select all N matching" for the rows beyond it.
			await page.locator('[data-testid="suitetickets-bulk-select-all"]').click();
			await expect(page.locator('[data-testid="bulk-selected-count"]')).toContainText('2 selected');
			await page.locator('[data-testid="bulk-select-all-matching"]').click();
			await expect(page.locator('[data-testid="bulk-selected-count"]')).toContainText('4 selected');

			// Un-check D: it is excluded from "all matching".
			await selectBox(page, `${marker} D`).click();
			await expect(page.locator('[data-testid="bulk-selected-count"]')).toContainText('3 selected');

			await page.locator('[data-testid="suitetickets-bulk-action-expedite"]').click();
			await page.locator('[data-testid="suitetickets-bulk-confirm"]').click();
			const drawer = page.locator('[data-testid="batch-result-drawer"]');
			await drawer.waitFor({ timeout: 15000 });

			// Filter mode is announced, and the outcome is per row: A and B succeed, C (blocked) fails.
			await expect(drawer).toContainText('Applied to every record matching the current filter');
			await expect(drawer).toContainText('2 succeeded');
			await expect(drawer).toContainText('1 failed');
			await expect(drawer).toContainText('Blocked tickets cannot be expedited');
			await closeDrawer(page);

			const byTitle = Object.fromEntries((await ticketsByMarker(page, marker)).map((r) => [r.title.slice(marker.length + 1), r.priority]));
			expect(byTitle, 'A and B were expedited; C failed and is untouched; D was excluded').toEqual({ A: 'high', B: 'high', C: 'normal', D: 'normal' });
		} finally {
			await removeTickets(page, marker);
		}
	});

	test('super-fixture / a status_target bulk action changes the status of every selected row', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXC-${Date.now()}`;
		try {
			await makeTicket(page, `${marker} A`);
			await makeTicket(page, `${marker} B`);

			await openList(page, marker);
			await page.locator('[data-testid="batch-mode-toggle"]').click();
			await selectBox(page, `${marker} A`).click();
			await selectBox(page, `${marker} B`).click();
			await page.locator('[data-testid="suitetickets-bulk-action-close"]').click();
			await page.locator('[data-testid="suitetickets-bulk-confirm"]').click();

			const drawer = page.locator('[data-testid="batch-result-drawer"]');
			await drawer.waitFor({ timeout: 15000 });
			await expect(drawer).toContainText('2 succeeded');
			await expect(drawer).toContainText('0 failed');
			await closeDrawer(page);

			const rows = await ticketsByMarker(page, marker);
			expect(rows).toHaveLength(2);
			for (const row of rows) expect(Number(row.status_id), `${row.title} is DONE`).toBe(2);
		} finally {
			await removeTickets(page, marker);
		}
	});

	test('super-fixture / the create wizard review step shows the picked assignee by name, and the record is created with it', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXW-${Date.now()}`;
		try {
			await page.goto(`${BASE_URL}/suite-tickets/list`);
			await page.locator('[data-testid="suitetickets-create"]').waitFor({ timeout: 20000 });
			await page.locator('[data-testid="suitetickets-create"]').click();
			await dialog(page).locator('[data-testid="suitetickets-wizard-next"]').waitFor({ timeout: 10000 });
			await sleep(500);

			// Step 1: Basics.
			await dialog(page).locator('#title').fill(`${marker} wizard`);
			await pickOption(page, 'Status'); // status_id is inferred as a foreign key (to statuses), so it is a picker
			await dialog(page).locator('#priority').fill('high');
			await dialog(page).locator('[data-testid="suitetickets-wizard-next"]').click();
			await sleep(300);

			// Step 2: Assignment. The picker writes the person's NAME into fieldLabels for the review step.
			const chosen = await pickOption(page, 'Assignee');
			await dialog(page).locator('#reason').fill('Reviewed in the wizard');
			await dialog(page).locator('[data-testid="suitetickets-wizard-next"]').click();
			await sleep(300);

			// Step 3: Review & Confirm names the person -- the raw id is what it printed when fieldLabels was
			// never declared (the picker also threw, and stayed open, on every choice).
			const review = dialog(page).last();
			await expect(review).toContainText(`Assignee: ${chosen}`);
			await expect(review).toContainText('Reason: Reviewed in the wizard');

			await dialog(page).locator('#wizard-confirm').click();
			await dialog(page).locator('[data-testid="suitetickets-submit"]').click();
			await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 });
			await sleep(400);

			const rows = await ticketsByMarker(page, marker);
			expect(rows, 'the wizard created exactly one ticket').toHaveLength(1);
			expect(rows[0].reason).toBe('Reviewed in the wizard');
			expect(rows[0].assignee_id, 'and it carries the assignee that was picked').not.toBeNull();
			expect(rows[0].priority).toBe('high');
		} finally {
			for (let i = 0; i < 3 && (await dialog(page).count()) > 0; i++) {
				await dialog(page).last().getByRole('button', { name: 'Close' }).click().catch(() => {});
				await sleep(500);
			}
			await removeTickets(page, marker);
		}
	});

	test('super-fixture / a wizard action writes its input to the record, and the review step names the assignee', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXE-${Date.now()}`;
		try {
			const uuid = await makeTicket(page, `${marker} escalate`, 'low');
			await openList(page, marker);

			await page.locator(`[data-testid="suitetickets-view-${uuid}"]`).click();
			await dialog(page).first().waitFor({ timeout: 10000 });
			await sleep(500);
			await dialog(page).getByRole('button', { name: 'More Actions' }).click();
			await page.locator(`[data-testid="suitetickets-action-escalate-${uuid}"]`).click();
			await sleep(700);

			await pickOption(page, 'Priority', /^High$/);
			await dialog(page).getByRole('button', { name: 'Next', exact: true }).click();
			await sleep(300);
			const assignee = await pickOption(page, 'Assign to');
			await dialog(page).locator('#reason').fill('Raised through the wizard');
			await dialog(page).getByRole('button', { name: 'Next', exact: true }).click();
			await sleep(300);

			await expect(dialog(page).last()).toContainText(assignee);
			await dialog(page).locator('#wizard-confirm').click();
			await dialog(page).last().getByRole('button', { name: 'Escalate', exact: true }).click();
			await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 });
			await sleep(500);

			const [row] = await ticketsByMarker(page, marker);
			expect(row.priority, 'the wizard raised the priority').toBe('high');
			expect(row.reason).toBe('Raised through the wizard');
			expect(row.assignee_id).not.toBeNull();
		} finally {
			for (let i = 0; i < 3 && (await dialog(page).count()) > 0; i++) {
				await dialog(page).last().getByRole('button', { name: 'Close' }).click().catch(() => {});
				await sleep(500);
			}
			await removeTickets(page, marker);
		}
	});

	test('super-fixture / export downloads the real rows as csv, headed by the module columns', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXX-${Date.now()}`;
		try {
			await makeTicket(page, `${marker} exported`, 'high');
			await openList(page, marker);

			// The generated spec MOCKS this response; here the real file is read.
			await page.locator('[data-testid="suitetickets-export-open"]').click();
			const [download] = await Promise.all([
				page.waitForEvent('download', { timeout: 20000 }),
				page.locator('[data-testid="suitetickets-export-csv"]').click(),
			]);
			const body = fs.readFileSync(await download.path(), 'utf8');

			expect(body.split('\n')[0].toLowerCase(), 'the header row names the columns').toContain('title');
			expect(body, 'the filtered row is in the file').toContain(`${marker} exported`);
		} finally {
			await removeTickets(page, marker);
		}
	});
});
