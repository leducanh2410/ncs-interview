import path from 'path';

// Shared e2e configuration. Everything is overridable via env so the same suite
// can run against a different stack (CI, staging) without code changes.

/** Saved authenticated browser state (localStorage Bearer token). Reused by every authed project. */
export const STORAGE_STATE = path.join(__dirname, '.auth', 'admin.json');

/** Frontend origin. Must be the portless origin in dev so API calls aren't blocked by CORS. */
export const BASE_URL = process.env.E2E_BASE_URL ?? 'https://dgf.localhost:1355';

/** Backend API origin. Matches NEXT_PUBLIC_API_BASE_URL in the dev stack. */
export const API_URL = process.env.E2E_API_URL ?? 'https://api.dgf.localhost:1355';

/** localStorage key the app reads the Bearer token from (see helpers/api.helper.ts). */
export const TOKEN_KEY = process.env.E2E_TOKEN_KEY ?? 'access_token';

/** Seeded admin credentials (dev). TFA is off in dev, so login returns a token directly. */
export const ADMIN_EMAIL = process.env.E2E_EMAIL ?? 'admin@example.com';
export const ADMIN_PASSWORD = process.env.E2E_PASSWORD ?? 'YOUR_E2E_PASSWORD';
