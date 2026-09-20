'use strict';

import { test, expect } from '#e2e-helpers/fixtures.js';
import { loginWithRetry } from '#e2e-helpers/auth.js';
import { BASE_URL, API_URL } from '#e2e-helpers/config.js';

/**
 * super-fixture: the item-picker, in a browser.
 *
 * Its field is a JSON column, and introspection gives a JSON column no form field -- so the generated specs
 * never see the picker at all. This drives it: the splash catalog is listed (one name carries an apostrophe,
 * which used to be emitted as invalid PHP), a row is picked, its config modal takes a quantity, and the kit
 * is saved with the whole row.
 *
 * Titles start with "super-fixture" -- that is what run-fixture.sh --full greps for.
 */

async function api(page, method, path) {
	const token = await page.evaluate(() => localStorage.getItem('auth_token'));
	const res = await page.request.fetch(`${API_URL}${path}`, {
		method,
		headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
	});
	return { status: res.status(), body: await res.json().catch(() => null) };
}

test.describe('super-fixture / item-picker', () => {
	test('super-fixture / item-picker: a catalog row is picked with a quantity and saved with the kit', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXK ${Date.now()}`;
		let uuid = null;

		try {
			await page.goto(`${BASE_URL}/suite-kits/list`);
			await page.locator('[data-testid="suitekits-create"]').click();
			const form = page.locator('[role="dialog"]').first();
			await form.locator('#name').fill(marker);

			// The catalog came from the create splash; the apostrophe survived being emitted as PHP.
			const washer = form.locator('div.p-3', { hasText: "Washer 'M8'" });
			await expect(washer, 'the catalog lists the item whose name has an apostrophe').toHaveCount(1);
			await expect(form.getByText('No items selected yet')).toBeVisible();

			await washer.getByRole('button', { name: 'Add' }).click();
			const config = page.locator('[role="dialog"]').last();
			await expect(config.getByText('Configure Items').first()).toBeVisible();

			// Required: an empty quantity keeps the modal open.
			await config.getByRole('button', { name: 'Add', exact: true }).click();
			await expect(config.getByText('This field is required').first()).toBeVisible();

			await config.locator('#quantity').fill('3');
			await config.getByRole('button', { name: 'Add', exact: true }).click();
			await expect(page.locator('[role="dialog"]')).toHaveCount(1);

			await expect(form.locator('p.font-medium', { hasText: "Washer 'M8'" }), 'the picked row is listed as selected').toBeVisible();
			await expect(form.getByText('Quantity: 3')).toBeVisible();

			await form.locator('[data-testid="suitekits-submit"]').click();
			await expect(form.locator('#name'), 'the create dialog closes when the save succeeds').toHaveCount(0, { timeout: 15000 });

			const found = await api(page, 'GET', `/suite-kits/list?filters[name][operator]=eq&filters[name][value]=${encodeURIComponent(marker)}`);
			const row = (found.body?.data?.data ?? [])[0];
			expect(row, 'the kit was created').toBeTruthy();
			uuid = row.uuid;

			const stored = (await api(page, 'GET', `/suite-kits/${uuid}/view`)).body?.data;
			const items = typeof stored.items === 'string' ? JSON.parse(stored.items) : stored.items;
			expect(items).toHaveLength(1);
			expect(items[0]).toMatchObject({ id: 3, name: "Washer 'M8'", sku: 'W-8' });
			expect(Number(items[0].quantity)).toBe(3);
		} finally {
			if (uuid) await api(page, 'DELETE', `/suite-kits/${uuid}/delete`);
		}
	});
});
