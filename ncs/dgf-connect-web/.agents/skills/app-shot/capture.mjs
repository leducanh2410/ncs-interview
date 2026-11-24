#!/usr/bin/env node
// app-shot — authenticated screenshots of DGF Connect pages, served over the local network.
//
//   node capture.mjs <path> [options]
//
// Options:
//   --name <prefix>        output filename prefix (default: derived from path)
//   --viewports <list>     comma list of presets/WxH (default: desktop,mobile)
//                          presets: desktop=1440x900 laptop=1280x800 tablet=iPad mobile=Pixel 7
//   --click-text "<text>"  after the first shot, click the button/link/element whose
//                          accessible name or text matches <text> and take a second shot
//   --full                 full-page screenshots (default: just the viewport)
//   --wait <ms>            extra settle time after load (default: 3500)
//   --out <dir>            output dir (default: /tmp/app-shots)
//   --serve <port>         serve the out dir over the local network (default: 8899)
//   --no-serve             capture only, don't serve
//   --no-login             skip auth (public pages)
//   --relogin              ignore any saved e2e storageState; force a fresh API login
//
// Auth: reuses the Playwright e2e auth fixture (frontend/e2e/.auth/admin.json) when
// present, otherwise logs in via the API and synthesizes the localStorage Bearer state.
//
// Env overrides (defaults are the DGF dev stack):
//   ORIGIN          frontend origin      (default https://dgf.localhost:1355)
//   LOGIN_URL       admin login endpoint (default http://localhost:8085/auth/login/admin)
//   LOGIN_EMAIL     (default admin@example.com)
//   LOGIN_PASSWORD  (default YOUR_DEV_PASSWORD)
//   TOKEN_KEY       localStorage key     (default access_token)
//   CHROME_PATH     chrome binary        (autodetected)

import { existsSync, mkdirSync, writeFileSync, readFileSync } from 'fs';
import { execSync, spawn } from 'child_process';
import { createRequire } from 'module';
import os from 'os';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; // dev self-signed certs (node fetch only)

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// .claude/skills/app-shot -> repo root -> frontend
const FRONTEND_DIR = path.resolve(__dirname, '../../../frontend');
const SHARED_STORAGE_STATE = path.join(FRONTEND_DIR, 'e2e/.auth/admin.json');

// ---- config -------------------------------------------------------------
const cfg = {
    origin: process.env.ORIGIN || 'https://dgf.localhost:1355',
    loginUrl: process.env.LOGIN_URL || 'http://localhost:8085/auth/login/admin',
    email: process.env.LOGIN_EMAIL || 'admin@example.com',
    password: process.env.LOGIN_PASSWORD || 'YOUR_DEV_PASSWORD',
    tokenKey: process.env.TOKEN_KEY || 'access_token',
};

// Preset viewports. `device` presets use Playwright's device emulation (UA, DPR, touch);
// the others are plain WxH at 2x DPR.
const PRESETS = {
    desktop: { width: 1440, height: 900 },
    laptop: { width: 1280, height: 800 },
    tablet: { device: 'iPad (gen 7)' },
    mobile: { device: 'Pixel 7' },
};

// ---- arg parsing --------------------------------------------------------
const argv = process.argv.slice(2);
const opts = {
    path: '/',
    name: null,
    viewports: 'desktop,mobile',
    clickText: null,
    full: false,
    wait: 3500,
    out: '/tmp/app-shots',
    serve: 8899,
    login: true,
    relogin: false,
};
for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--name') opts.name = argv[++i];
    else if (a === '--viewports') opts.viewports = argv[++i];
    else if (a === '--click-text') opts.clickText = argv[++i];
    else if (a === '--full') opts.full = true;
    else if (a === '--wait') opts.wait = Number(argv[++i]);
    else if (a === '--out') opts.out = argv[++i];
    else if (a === '--serve') opts.serve = Number(argv[++i]);
    else if (a === '--no-serve') opts.serve = 0;
    else if (a === '--no-login') opts.login = false;
    else if (a === '--relogin') opts.relogin = true;
    else if (!a.startsWith('--')) opts.path = a.startsWith('/') ? a : `/${a}`;
}
if (!opts.name) opts.name = opts.path.replace(/^\/+|\/+$/g, '').replace(/\//g, '-') || 'home';

const viewports = opts.viewports.split(',').map((tok) => {
    const t = tok.trim();
    if (PRESETS[t]) return { name: t, ...PRESETS[t] };
    const m = t.match(/^(\d+)x(\d+)$/);
    if (m) return { name: t, width: +m[1], height: +m[2] };
    throw new Error(`bad viewport "${t}" (use a preset or WxH)`);
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function findChrome() {
    if (process.env.CHROME_PATH) return process.env.CHROME_PATH;
    const cands = [
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];
    return cands.find((p) => existsSync(p)) || 'google-chrome';
}

function lanIp() {
    for (const ifaces of Object.values(os.networkInterfaces())) {
        for (const i of ifaces || []) {
            if (i.family === 'IPv4' && !i.internal) return i.address;
        }
    }
    return null;
}

// Address to print for opening the gallery: tailnet IP (phone over tailscale) if
// available, else the LAN IP, else localhost.
function serveIp() {
    try {
        const ip = execSync('tailscale ip -4', { encoding: 'utf8' }).trim().split('\n')[0];
        if (ip) return ip;
    } catch {
        /* tailscale not installed/up — fall through */
    }
    return lanIp() || 'localhost';
}

async function loadPlaywright() {
    // Reuse the frontend's Playwright install (one browser stack) — its @playwright/test
    // re-exports `chromium` and `devices`. Fall back to the skill's own playwright-core.
    const attempts = [
        () => {
            const req = createRequire(path.join(FRONTEND_DIR, 'package.json'));
            return import(pathToFileURL(req.resolve('@playwright/test')).href);
        },
        () => import('playwright-core'),
    ];
    for (const attempt of attempts) {
        try {
            const pw = await attempt();
            const m = pw.default ?? pw;
            if (m?.chromium) return m;
        } catch {
            /* try next */
        }
    }
    console.log('[app-shot] installing playwright-core (one-time)…');
    execSync('npm install playwright-core@1', { cwd: __dirname, stdio: 'inherit' });
    const pw = await import('playwright-core');
    return pw.default ?? pw;
}

async function apiLogin() {
    const res = await fetch(cfg.loginUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email: cfg.email, password: cfg.password }),
    });
    const json = await res.json().catch(() => ({}));
    const token = json?.data?.token;
    if (!token) throw new Error(`login failed (HTTP ${res.status}): ${JSON.stringify(json).slice(0, 200)}`);
    return token;
}

// Returns a Playwright storageState (file path or inline object) for the authed context,
// or null when --no-login. Prefers the e2e fixture; falls back to a fresh API login.
async function resolveStorageState() {
    if (!opts.login) return null;
    if (!opts.relogin && existsSync(SHARED_STORAGE_STATE)) {
        console.log('[app-shot] reusing e2e auth fixture:', SHARED_STORAGE_STATE);
        return SHARED_STORAGE_STATE;
    }
    const token = await apiLogin();
    console.log('[app-shot] fresh API login → synthesized auth state');
    return { cookies: [], origins: [{ origin: cfg.origin, localStorage: [{ name: cfg.tokenKey, value: token }] }] };
}

function contextOptions(vp, storageState, devices) {
    const base = { ignoreHTTPSErrors: true };
    if (storageState) base.storageState = storageState;
    if (vp.device) {
        const dev = devices[vp.device];
        if (!dev) throw new Error(`unknown device preset "${vp.device}"`);
        return { ...dev, ...base };
    }
    return { viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 2, ...base };
}

async function clickByText(page, text) {
    const candidates = [
        page.getByRole('button', { name: text }),
        page.getByRole('link', { name: text }),
        page.getByText(text, { exact: false }),
    ];
    for (const loc of candidates) {
        const first = loc.first();
        if ((await first.count()) > 0) {
            const label = (await first.textContent().catch(() => null))?.trim() || text;
            await first.click({ timeout: 5000 }).catch(() => {});
            return label;
        }
    }
    return null;
}

function writeGallery(outDir, shots, meta) {
    const groups = {};
    for (const s of shots) (groups[s.vp] ||= []).push(s);
    const fig = (s) =>
        `<figure><img src="${s.file}" alt="${s.file}"/><figcaption>${s.caption}</figcaption></figure>`;
    const sections = Object.entries(groups)
        .map(([vp, ss]) => `<h2>${vp}</h2>${ss.map(fig).join('')}`)
        .join('');
    writeFileSync(
        path.join(outDir, 'index.html'),
        `<!doctype html><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>app-shot · ${meta.path}</title>
<style>body{margin:0;font-family:-apple-system,system-ui,sans-serif;background:#0f1115;color:#e6e8eb}
header{padding:16px 20px;border-bottom:1px solid #232733;position:sticky;top:0;background:#0f1115}
h1{font-size:17px;margin:0}p.sub{margin:4px 0 0;color:#9aa3af;font-size:13px}
section{padding:18px 14px 8px}h2{font-size:13px;color:#9aa3af;margin:18px 0 10px;font-weight:600;text-transform:capitalize}
figure{margin:0 0 26px}img{width:100%;height:auto;border:1px solid #232733;border-radius:10px;display:block}
figcaption{color:#9aa3af;font-size:12px;margin-top:6px}</style>
<header><h1>${meta.path}</h1><p class="sub">${meta.origin} · captured ${new Date().toLocaleString()}</p></header>
<section>${sections}</section>`,
    );
}

function serve(outDir, port) {
    const pidFile = path.join(outDir, '.serve.pid');
    if (existsSync(pidFile)) {
        try {
            process.kill(Number(readFileSync(pidFile, 'utf8').trim()));
        } catch {
            /* already gone */
        }
    }
    const srv = spawn('python3', ['-m', 'http.server', String(port), '--bind', '0.0.0.0'], {
        cwd: outDir,
        detached: true,
        stdio: 'ignore',
    });
    srv.unref();
    writeFileSync(pidFile, String(srv.pid));
    return srv.pid;
}

// ---- run ----------------------------------------------------------------
async function main() {
    mkdirSync(opts.out, { recursive: true });
    // pre-flight: frontend reachable?
    await fetch(cfg.origin + '/', { method: 'GET' }).catch(() => {
        throw new Error(`cannot reach ${cfg.origin} — is the dev server up? run \`pnpm dev\` in ./frontend`);
    });

    const storageState = await resolveStorageState();
    const { chromium, devices } = await loadPlaywright();
    const browser = await chromium.launch({
        executablePath: findChrome(),
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const target = cfg.origin + opts.path;
    const shots = [];
    try {
        for (const vp of viewports) {
            const context = await browser.newContext(contextOptions(vp, storageState, devices));
            const page = await context.newPage();
            // websocket-heavy app — don't wait for networkidle (it never settles); settle manually.
            await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 90000 });
            await sleep(opts.wait);

            const base = `${opts.name}-${vp.name}-1.png`;
            await page.screenshot({ path: path.join(opts.out, base), fullPage: opts.full });
            shots.push({ vp: vp.name, file: base, caption: 'initial' });

            if (opts.clickText) {
                const hit = await clickByText(page, opts.clickText);
                console.log(`[${vp.name}] click "${opts.clickText}" →`, JSON.stringify(hit));
                await sleep(800);
                const after = `${opts.name}-${vp.name}-2.png`;
                await page.screenshot({ path: path.join(opts.out, after), fullPage: opts.full });
                shots.push({ vp: vp.name, file: after, caption: `after clicking "${opts.clickText}"` });
            }
            await context.close();
        }
    } finally {
        await browser.close();
    }

    writeGallery(opts.out, shots, { path: opts.path, origin: cfg.origin });
    console.log('\n[app-shot] captured:');
    for (const s of shots) console.log('  ', path.join(opts.out, s.file));

    if (opts.serve) {
        const pid = serve(opts.out, opts.serve);
        const ip = serveIp();
        console.log(`\n📱  http://${ip}:${opts.serve}/   (pid ${pid})`);
        console.log(`   stop: kill ${pid}`);
    }
}

main().catch((e) => {
    console.error('[app-shot] ERROR:', e.message || e);
    process.exit(1);
});
