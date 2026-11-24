import { expect, test as setup } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, API_URL, STORAGE_STATE, TOKEN_KEY } from './constants';

// Runs once before the authed projects. Logs in via the API, injects the Bearer
// token into localStorage on the frontend origin, then saves storageState so the
// real tests start already authenticated (no login form replay per test).
setup('authenticate as admin', async ({ page, request }) => {
    const res = await request.post(`${API_URL}/auth/login/admin`, {
        data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
        headers: { Accept: 'application/json' },
    });
    expect(res.ok(), `login failed (HTTP ${res.status()}): ${await res.text()}`).toBeTruthy();

    const body = await res.json();
    const token = body?.data?.token;
    expect(token, 'login response had no data.token').toBeTruthy();

    // localStorage is per-origin, so we must be on the frontend origin to set it.
    await page.goto('/');
    await page.evaluate(([key, value]) => localStorage.setItem(key, value), [TOKEN_KEY, token] as const);

    await page.context().storageState({ path: STORAGE_STATE });
});
