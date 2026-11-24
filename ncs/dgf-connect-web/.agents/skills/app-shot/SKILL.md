---
name: app-shot
description: Use when the user wants screenshots of the running DGF Connect app (any page, including auth-gated ones) — to review a UI change, share with someone, or "see it on my phone". Logs in automatically, drives headless Chrome, and serves the images over the local network so a phone can open them. Triggers on "screenshot the X page", "capture the app", "let me see it on my phone", "serve it over the network", "show me the new UI".
version: 1.0.0
user-invocable: true
argument-hint: "<page-path> [--click-text \"Filter\"] [--viewports desktop,mobile] [--full]"
allowed-tools:
  - Bash(node .claude/skills/app-shot/capture.mjs*)
  - Bash(npm install*)
  - Bash(pnpm dev*)
  - Bash(tailscale*)
  - Bash(kill*)
---

Capture authenticated screenshots of DGF Connect pages and serve them over the local network for phone viewing. One command does login → headless capture → gallery → serve.

## Prerequisites (check, don't assume)

1. **Backend up** — `docker compose ps` should show the `php` service on `:8085`. If not: `docker compose up -d`.
2. **Frontend dev server up** — needs portless serving `https://dgf.localhost:1355`. Check with `ss -tlnp | grep :1355`. If absent, start it in the background: `cd frontend && pnpm dev` (give it ~10s to compile). The script also pre-flights this and tells you if it's down.
3. **TFA** — admin TFA is off in dev (`AUTH_TFA_ENABLED=false`), so login returns a token directly. If a future env turns it on, this skill's auto-login breaks and you'll need to read the OTP from Mailpit (`:18026`).

## Usage

```bash
node .claude/skills/app-shot/capture.mjs <page-path> [options]
```

The script authenticates as the seeded admin (reusing the Playwright e2e auth fixture if present, else logging in via the API and injecting the Bearer token the app reads from `localStorage.access_token`), loads the page at each viewport, screenshots it, optionally clicks an element to reveal a popover/menu and screenshots again, then serves everything.

It runs on **Playwright** (the same browser stack as the e2e suite in `frontend/`), reusing the frontend's install — no separate browser download.

**Relay the printed `📱 http://<ip>:<port>/` URL to the user** — that's what they open on their phone. The IP is the tailnet IP when tailscale is up, otherwise the detected LAN IP (falling back to `localhost`).

### Examples

```bash
# Shift planner, opening the Filter popover, desktop + mobile
node .claude/skills/app-shot/capture.mjs /manage/shift-planning --click-text "Filter"

# A single custom width, full-page
node .claude/skills/app-shot/capture.mjs /manage/shift --viewports 1280x800 --full

# Public page, no login
node .claude/skills/app-shot/capture.mjs /login --no-login
```

### Options

- `--click-text "<text>"` — after the first shot, click the button/link/element whose accessible name or text matches `<text>` (Playwright `getByRole`/`getByText`, case-insensitive substring), then take a second shot. Great for popovers/menus that can't be reached by URL. Pass the actual rendered label (e.g. the localized text) for reliable matching.
- `--viewports <list>` — comma list of presets or `WxH` (default `desktop,mobile`). Presets: `desktop` (1440×900), `laptop` (1280×800), `tablet` (iPad device emulation), `mobile` (Pixel 7 device emulation).
- `--full` — full-page instead of just the viewport.
- `--wait <ms>` — settle time after load (default 3500; bump it for slow/heavy pages).
- `--out <dir>` — output dir (default `/tmp/app-shots`).
- `--serve <port>` / `--no-serve` — serve port (default 8899) or capture only.
- `--no-login` — skip auth (public pages).
- `--relogin` — ignore any saved e2e storageState and force a fresh API login (use if the saved token is stale and pages bounce to login).

### Config (env overrides; defaults match the dev stack)

`ORIGIN`, `LOGIN_URL`, `LOGIN_EMAIL`, `LOGIN_PASSWORD`, `TOKEN_KEY`, `CHROME_PATH`.

## Notes & gotchas

- **Browser stack**: reuses the frontend's Playwright (`@playwright/test`, run `pnpm install` in `./frontend` if missing) via the system Google Chrome (`channel: chrome`) — no Playwright browser download. If the frontend install is unavailable it falls back to installing `playwright-core` into the skill dir (one-time). `.claude/` is gitignored, so the skill stays local.
- **Auth fixture**: prefers `frontend/e2e/.auth/admin.json` (produced by `pnpm test:e2e`'s `auth.setup`). If it's missing or stale, pass `--relogin` (or it auto-falls-back to an API login when the file is absent).
- **Empty grids**: a page can render fine but show no rows if the seeded data is thin (e.g. the `[Test] TEST` workplace has no active members). The chrome/toolbar still captures correctly — say so rather than treating it as a bug. To get populated data, pick a workplace that has members or seed some.
- **Stopping the server**: the script prints the pid and the `kill <pid>` line. A re-run kills the previous server (tracked in `<out>/.serve.pid`) before starting a new one, so the URL/port stays stable.
- **Why portless and not a quick `next dev`**: the API lives on `api.dgf.localhost:1355` and CORS is pinned to `APP_FRONT_URL` (`https://dgf.localhost:1355`), so the frontend must be served from that exact origin for API calls to succeed.
