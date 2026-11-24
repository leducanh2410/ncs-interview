import { expect, test } from '@playwright/test';

// Foundation smoke test: proves the auth fixture works end-to-end. With a valid
// saved token the admin should reach the app home instead of being bounced to login.
test('authenticated admin reaches the app home', async ({ page }) => {
    await page.goto('/');
    await expect(page).not.toHaveURL(/\/auth\/login/);
});
