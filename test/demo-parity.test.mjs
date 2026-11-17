// The browser demo must score the sample book exactly like the PHP app.
// Runs demo.html's <script id="li-engine"> in Node and compares it with `php liteinsurance.php action=parity_vectors`.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const demo = readFileSync(join(root, 'demo.html'), 'utf8');
const engineSrc = demo.match(/<script id="li-engine">([\s\S]*?)<\/script>/)?.[1];
assert.ok(engineSrc, 'demo.html should embed the scoring engine as <script id="li-engine">');
const sandbox = {};
vm.runInNewContext(engineSrc, { window: sandbox });
const Engine = sandbox.LiteEngine;
assert.ok(Engine && typeof Engine.scoreSample === 'function', 'engine should expose scoreSample()');
assert.match(demo, /Engine\.scoreBook\(raw, rules/, 'demo should score through the shared engine');

const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
if (!hasPhp) {
  console.log('demo parity: engine loads (php not found, skipped PHP comparison)');
  process.exit(0);
}

for (const today of ['2026-11-17', '2027-02-28', '2028-02-29']) {
  const csv = spawnSync('php', ['liteinsurance.php', 'action=download_sample_csv', `today=${today}`], { cwd: root, encoding: 'utf8' });
  assert.equal(csv.status, 0, csv.stderr);
  assert.equal(Engine.sampleCsv(today), csv.stdout, `demo sample CSV should equal the PHP sample byte for byte (${today})`);

  const out = spawnSync('php', ['liteinsurance.php', 'action=parity_vectors', `today=${today}`], { cwd: root, encoding: 'utf8' });
  assert.equal(out.status, 0, out.stderr);
  const php = JSON.parse(out.stdout).policies;
  const js = Engine.scoreSample(today);
  assert.equal(js.length, php.length, 'same number of policies');
  php.forEach((p, i) => {
    const d = js[i];
    const where = `${p.policy_number} @ ${today}`;
    assert.equal(d.policy_number, p.policy_number, where);
    assert.equal(d.risk_tier, p.risk_tier, `risk tier ${where}`);
    assert.equal(d.confidence_score, p.confidence_score, `confidence ${where}`);
    assert.deepEqual(Array.from(d.rule_hits), p.rule_hits, `rule hits ${where}`);
    assert.deepEqual(Array.from(d.imputed_fields), p.imputed_fields, `imputed fields ${where}`);
    assert.equal(d.expiration_date, p.expiration_date, `expiration ${where}`);
    if (p.ltv_estimate === null) assert.equal(d.ltv_estimate, null, `ltv ${where}`);
    else assert.ok(Math.abs(d.ltv_estimate - p.ltv_estimate) < 0.011, `ltv ${where}: ${d.ltv_estimate} vs ${p.ltv_estimate}`);
  });
}
console.log('demo parity checks passed (sample CSV identical, 200/200 policies match on 3 dates)');
