'use strict';

import { test, expect } from '#e2e-helpers/fixtures.js';
import { loginWithRetry } from '#e2e-helpers/auth.js';
import { BASE_URL } from '#e2e-helpers/config.js';

/**
 * super-fixture: drafts, on and off.
 *
 * No generated spec mentions drafts. Every create form ships with "Save Draft" and a banner listing the
 * user's saved drafts (server-backed, one fresh key per form mount), and `drafts: false` in a module's
 * config removes all of it. Nothing had ever shown either half working in a browser.
 *
 *   suite_order_types  drafts on (the default)
 *   suite_policies     `drafts: false` on create and edit (blueprint module_overrides)
 *
 * Titles start with "super-fixture" -- that is what run-fixture.sh --full greps for.
 */

const dialog = (page) => page.locator('[role="dialog"]').first();

async function openCreate(page, testid, list) {
	await page.goto(`${BASE_URL}${list}`);
	const button = page.locator(`[data-testid="${testid}"]`);
	await button.waitFor({ timeout: 20000 });
	await button.click();
	await dialog(page).locator('#name').waitFor({ timeout: 10000 });
}

async function closeDialog(page) {
	await dialog(page).getByRole('button', { name: 'Close' }).click();
	await expect(page.locator('[role="dialog"]')).toHaveCount(0);
}

test.describe('super-fixture / drafts', () => {
	test('super-fixture / drafts on: Save Draft stores the form, a new form can resume it, and it can be deleted', async ({ page }) => {
		await loginWithRetry(page);
		const marker = `FXD ${Date.now()}`;

		await openCreate(page, 'suiteordertypes-create', '/suite-order-types/list');
		await expect(page.locator('[data-testid="suiteordertypes-save-draft"]')).toBeVisible();
		await expect(page.locator('[data-testid="drafts-view"]'), 'no drafts yet, so no banner').toHaveCount(0);

		await dialog(page).locator('#name').fill(marker);
		await page.locator('[data-testid="suiteordertypes-save-draft"]').click();
		await expect(page.getByText('Draft saved').first()).toBeVisible();
		await closeDialog(page);

		// A new form mount gets a NEW draft key, so the saved draft is "someone else's" and resumable.
		await openCreate(page, 'suiteordertypes-create', '/suite-order-types/list');
		await expect(dialog(page).locator('#name')).toHaveValue('');
		await dialog(page).locator('[data-testid="drafts-view"]').click();
		await page.locator('[data-testid="draft-resume"]').first().click();
		await expect(dialog(page).locator('#name'), 'resuming puts the saved values back in the form').toHaveValue(marker);

		// The resumed draft is now the active one; deleting it empties the list and hides the banner.
		await dialog(page).locator('[data-testid="drafts-view"]').click();
		await page.locator('[data-testid="draft-delete"]').first().click();
		await expect(page.locator('[data-testid="drafts-view"]')).toHaveCount(0);
	});

	test('super-fixture / drafts off: the form has no Save Draft and no draft banner', async ({ page }) => {
		await loginWithRetry(page);

		await openCreate(page, 'suitepolicies-create', '/suite-policies/list');
		await expect(dialog(page).locator('#name'), 'the form itself is there').toBeVisible();
		await expect(page.locator('[data-testid="suitepolicies-save-draft"]')).toHaveCount(0);
		await expect(page.locator('[data-testid="drafts-view"]')).toHaveCount(0);
	});
});
