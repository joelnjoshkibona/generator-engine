'use strict';

import { test, expect } from '#e2e-helpers/fixtures.js';
import { loginWithRetry } from '#e2e-helpers/auth.js';
import { BASE_URL, API_URL } from '#e2e-helpers/config.js';

/**
 * super-fixture: list features no generated spec drives.
 *
 * A generated list spec navigates to `?sort=id&order=desc` and never opens the Sort dialog, and it never
 * looks at which columns are visible. So a column configured `defaultVisible: false` was never seen
 * hidden, and sorting was never seen to reorder anything.
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

async function headerTexts(page) {
	await page.locator('table thead th').first().waitFor({ timeout: 20000 });
	return (await page.locator('table thead th').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
}

test.describe('super-fixture / list columns and sort', () => {
	test('super-fixture / a defaultVisible:false column is hidden until the Columns menu turns it on', async ({ page }) => {
		await loginWithRetry(page);
		await page.goto(`${BASE_URL}/suite-edge-cases/list`);

		const before = await headerTexts(page);
		expect(before, 'the ordinary columns are shown').toContain('Long Label');
		expect(before, 'internal_ref is configured defaultVisible:false').not.toContain('Internal Ref');

		// Hidden, not dropped: it is still offered in the Columns menu.
		await page.locator('[data-testid="columns-toggle-btn"]').click();
		const toggle = page.locator('[data-testid="column-toggle-internal_ref"]');
		await expect(toggle, 'a hidden column must still be reachable').toBeVisible();
		await toggle.click();
		await page.keyboard.press('Escape');

		await expect(page.locator('table thead th', { hasText: 'Internal Ref' })).toBeVisible();
		expect(await headerTexts(page)).toContain('Internal Ref');
	});

	test('super-fixture / the Sort dialog reorders the list both ways and puts the choice in the URL', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXS-${Date.now()}`;
		try {
			// Created out of alphabetical order, so neither the id nor created_at order equals title order.
			for (const t of ['b', 'c', 'a']) {
				const res = await api(page, 'POST', '/suite-tickets/create', { title: `${marker} ${t}`, status_id: 1, priority: 'normal' });
				expect(res.status).toBeLessThan(300);
			}

			await page.goto(`${BASE_URL}/suite-tickets/list?f[title][operator]=contains&f[title][value]=${encodeURIComponent(marker)}`);
			await page.waitForFunction(() => document.querySelector('[data-testid^="suitetickets-view-"]') !== null, null, { timeout: 20000 });
			const order = async () =>
				(await page.locator('table tbody tr').allInnerTexts())
					.map((t) => (t.match(new RegExp(`${marker} (\\w)`)) || [])[1])
					.filter(Boolean);

			async function sortBy(label, descending) {
				await page.locator('[data-testid="sort-trigger-btn"]').click();
				await dialog(page).getByText('Sort By').first().waitFor({ timeout: 8000 });

				const trigger = dialog(page).locator('[data-slot="select2-trigger"]').first();
				const before = await dialog(page).count();
				await trigger.click();
				await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, before, { timeout: 8000 });
				await dialog(page).last().locator('.cursor-pointer', { hasText: new RegExp(`^\\s*${label}\\s*$`) }).first().click();
				await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, before, { timeout: 8000 });

				const sw = dialog(page).getByRole('switch');
				if ((await sw.getAttribute('aria-checked')) !== String(descending)) await sw.click();
				await dialog(page).getByRole('button', { name: 'Apply', exact: true }).click();
				await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, 0, { timeout: 8000 });
				await sleep(600);
			}

			await sortBy('Title', false);
			expect(page.url(), 'the sort is in the URL').toContain('sort=title');
			expect(page.url()).toContain('order=asc');
			expect(await order()).toEqual(['a', 'b', 'c']);

			await sortBy('Title', true);
			expect(page.url()).toContain('order=desc');
			expect(await order()).toEqual(['c', 'b', 'a']);

			// A reload keeps it: the URL is the state.
			await page.reload();
			await page.waitForFunction(() => document.querySelector('[data-testid^="suitetickets-view-"]') !== null, null, { timeout: 20000 });
			await sleep(500);
			expect(await order()).toEqual(['c', 'b', 'a']);
		} finally {
			const q = `filters[title][operator]=contains&filters[title][value]=${encodeURIComponent(marker)}&params[per_page]=100`;
			const list = await api(page, 'GET', `/suite-tickets/list?${q}`);
			for (const row of list.body?.data?.data ?? []) await api(page, 'DELETE', `/suite-tickets/${row.uuid}/delete`);
		}
	});
});
