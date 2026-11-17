// Regression checks for the v1.1 hotfixes and upgrade paths.
// Static checks always run; the end-to-end flow runs when `php` is on PATH.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { copyFileSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createHmac } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const php = readFileSync(join(root, 'liteinsurance.php'), 'utf8');

// --- static -------------------------------------------------------------
assert.doesNotMatch(php, /const CURRENCY_SYMBOL\s*=/, 'CURRENCY_SYMBOL collides with a PHP built-in constant');
assert.match(php, /const LI_CURRENCY_SYMBOL = /, 'currency default should use a prefixed constant');
assert.match(php, /function currency_symbol\(\): string/, 'currency should be resolved through settings');
assert.match(php, /function request_action\(\): string/, 'actions should be resolved POST-first');
assert.doesNotMatch(php, /\$_GET\['action'\] \?\? \(\$_POST\['action'\]/, 'GET action must not shadow POST action');
assert.doesNotMatch(php, /\son(click|submit|change)="/, 'inline event handlers are blocked by the nonce CSP');
assert.match(php, /PRAGMA user_version = 11/, 'v1.1 migration should be versioned');
for (const col of ['expiration_date', 'household_id', 'consent_email', 'opt_out_at', 'renewal_status', 'prior_premium']) {
  assert.match(php, new RegExp(`'${col}' => '`), `migration should add ${col}`);
}
assert.doesNotMatch(php, /fonts\.googleapis|fonts\.gstatic/, 'fonts should be self-hosted (no third-party requests)');
assert.match(php, /PRAGMA user_version = 12/, 'v1.2 migration should be versioned');
for (const fn of ['totp_match', 'handle_totp_verify', 'render_tab_account', 'run_retention', 'handle_retention_action', 'parity_vectors', 'render_tab_renewals', 'render_policy_panel', 'handle_renewal_export_ics', 'handle_suppression_apply', 'segment_stats', 'ltv_breakdown', 'confidence_breakdown', 'guess_import_mapping']) {
  assert.match(php, new RegExp(`function ${fn}\\(`), `${fn} should exist`);
}

// --- end to end ---------------------------------------------------------
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
if (!hasPhp) {
  console.log('hotfix regression: static checks passed (php not found, skipped end-to-end flow)');
  process.exit(0);
}

const dir = mkdtempSync(join(tmpdir(), 'liteinsurance-test-'));
copyFileSync(join(root, 'liteinsurance.php'), join(dir, 'liteinsurance.php'));
const port = 18000 + Math.floor(Math.random() * 2000);
const server = spawn('php', ['-S', `127.0.0.1:${port}`], { cwd: dir, stdio: 'ignore' });
const base = `http://127.0.0.1:${port}/liteinsurance.php`;
let cookie = '';

function totp(secretB32, stepOffset = 0) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secretB32.replace(/[^A-Z2-7]/g, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const step = Math.floor(Date.now() / 30000) + stepOffset;
  const msg = Buffer.alloc(8);
  msg.writeUInt32BE(Math.floor(step / 2 ** 32), 0);
  msg.writeUInt32BE(step % 2 ** 32, 4);
  const h = createHmac('sha1', Buffer.from(bytes)).update(msg).digest();
  const o = h[19] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function request(url, opts = {}) {
  for (let i = 0; i < 6; i++) {
    const res = await fetch(url, { ...opts, redirect: 'manual', headers: { ...(opts.headers || {}), ...(cookie ? { cookie } : {}) } });
    const set = res.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
      url = new URL(res.headers.get('location'), url).toString();
      opts = {};
      continue;
    }
    return { status: res.status, url, text: await res.text(), headers: res.headers };
  }
  throw new Error('too many redirects');
}
const token = (html) => (html.match(/name="csrf_token" value="([^"]+)"/) || [])[1];
const form = (obj) => new URLSearchParams(obj);
const flash = (html) => (html.match(/class="flash[^"]*">([^<]+)/) || [])[1] || '';

try {
  let page;
  for (let i = 0; i < 40; i++) {
    try { page = await request(base); break; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  assert.ok(page, 'php -S should start');
  assert.match(page.text, /Create account/, 'fresh install should show the register form');

  // v1.0 bug: posting to ?action=register_form re-rendered the form forever.
  page = await request(`${base}?action=register_form`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'register', email: 'admin@example.com', password: 'password123', password2: 'password123' }) });
  assert.match(page.text, /Admin account created/, 'first registration should succeed and sign in');
  assert.match(page.text, /<h1 class="h1">Today<\/h1>/, 'admin should land on Today');

  page = await request(`${base}?tab=import`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'load_sample' }) });
  const mapping = {};
  for (const m of page.text.matchAll(/name="map\[(\w+)\]">(?:(?!<\/select>)[\s\S])*?<option value="([^"]*)" selected/g)) mapping[`map[${m[1]}]`] = m[2];
  assert.equal(mapping['map[expiration_date]'], 'expiration_date', 'renewal columns should be auto-mapped');
  assert.equal(mapping['map[consent_email]'], 'email_opt_in', 'consent columns should be auto-mapped');
  const preview = { csrf_token: token(page.text), action: 'import_preview', premium_period: 'monthly', 'defaults[region]': 'Unknown', 'defaults[policy_type]': 'term', ...mapping };
  page = await request(`${base}?tab=import`, { method: 'POST', body: form(preview) });
  page = await request(`${base}?tab=import`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'import_commit' }) });
  assert.match(flash(page.text), /200 new, 0 updated/, 'first import should insert 200 rows');

  page = await request(`${base}?tab=dashboard`);
  assert.doesNotMatch(page.text, /262145/, 'money must not render the colliding built-in constant');
  assert.match(page.text, /Renewal worklist/, 'Today should show the renewal worklist');

  for (const tab of ['renewals', 'profiles', 'segments', 'campaigns', 'import', 'rules', 'settings']) {
    const r = await request(`${base}?tab=${tab}`);
    assert.equal(r.status, 200, `${tab} should render`);
    assert.doesNotMatch(r.text, /Server error|Unknown tab/, `${tab} should not error`);
  }
  page = await request(`${base}?tab=profiles&id=3`);
  assert.match(page.text, /Lifetime value/, 'policy panel should explain LTV');
  assert.match(page.text, /Data confidence/, 'policy panel should explain confidence');

  const count = await request(`${base}?tab=segments`, { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: form({ csrf_token: token(page.text), action: 'segment_count', where: 'days_to_expiry >= 0 AND days_to_expiry <= 90 AND opted_out = 0' }) });
  const stats = JSON.parse(count.text);
  assert.equal(stats.ok, true, `segment_count should accept 0 and computed fields: ${count.text}`);
  assert.ok(stats.count > 0 && stats.email <= stats.count, 'segment_count should return consent-aware counts');

  const blocked = await request(`${base}?tab=segments`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'export_segment_prepare', id: '1', channel: 'all' }) });
  assert.match(flash(blocked.text), /Consent-filtered exports are required/, 'unfiltered exports should be blocked by default');

  // Re-import updates in place instead of rejecting duplicates.
  page = await request(`${base}?tab=import`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'load_sample' }) });
  page = await request(`${base}?tab=import`, { method: 'POST', body: form({ ...preview, csrf_token: token(page.text) }) });
  page = await request(`${base}?tab=import`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'import_commit' }) });
  assert.match(flash(page.text), /0 new, 200 updated/, 're-import should update existing records');

  // Registration is closed once an admin exists (unless an admin opens it).
  const adminCookie = cookie;
  cookie = '';
  let anon = await request(`${base}?action=register_form`);
  assert.doesNotMatch(anon.text, /name="password2"/, 'register form should be hidden when registration is closed');
  anon = await request(`${base}?action=register_form`, { method: 'POST', body: form({ csrf_token: token(anon.text), action: 'register', email: 'x@example.com', password: 'password123', password2: 'password123' }) });
  assert.match(flash(anon.text), /Registration is closed/, 'closed registration should be enforced server-side');
  cookie = adminCookie;

  // Two-step verification: enrol, sign out, sign back in with a TOTP code.
  page = await request(`${base}?tab=account`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'totp_begin' }) });
  const secret = (page.text.match(/class="secretKey mono">([^<]+)</) || [])[1]?.replace(/\s+/g, '');
  assert.ok(secret && secret.length === 32, 'TOTP setup should show a 160-bit base32 key');
  page = await request(`${base}?tab=account`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'totp_enable', code: totp(secret) }) });
  assert.match(flash(page.text), /Two-step verification is on/, 'valid TOTP should enable two-step verification');
  assert.match(page.text, /class="codeBlock recoveryCodes">[0-9A-F]{5}-[0-9A-F]{5}/, 'recovery codes should be shown once');
  page = await request(base, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'logout' }) });
  page = await request(base, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'login', email: 'admin@example.com', password: 'password123' }) });
  assert.match(page.text, /Two-step verification/, 'password alone should lead to the TOTP challenge');
  assert.doesNotMatch(page.text, /Renewal worklist/, 'no app access before the second factor');
  page = await request(`${base}?action=totp_form`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'totp_verify', code: totp(secret, 1) }) });
  assert.match(flash(page.text), /Signed in/, 'next-step TOTP code should complete sign-in (enrolment code cannot be replayed)');

  // Erasure request.
  page = await request(`${base}?tab=settings`, { method: 'POST', body: form({ csrf_token: token(page.text), action: 'privacy_erase', identifier: 'P-3003' }) });
  assert.match(flash(page.text), /Erased 1 record/, 'erase should remove the matching policy');

  const cron = spawnSync('php', ['liteinsurance.php', 'action=cron_jobs'], { cwd: dir, encoding: 'utf8' });
  assert.match(cron.stdout, /Updated policyholders: 199/, 'CLI cron should recompute every remaining policy');
  assert.match(cron.stdout, /Retention removed:/, 'cron should run retention');

  console.log('hotfix regression checks passed');
} finally {
  server.kill();
  rmSync(dir, { recursive: true, force: true });
}
