import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (file) => readFileSync(join(root, file), 'utf8');

function assertFile(file) {
  assert.ok(existsSync(join(root, file)), `${file} should exist`);
}

assertFile('index.html');
assertFile('liteinsurance.php');
assertFile('demo.html');
assertFile('docs.html');
assertFile('compare.html');
assertFile('robots.txt');
assertFile('sitemap.xml');
assertFile('llms.txt');

const gitignore = read('.gitignore');
assert.match(gitignore, /^ref_\*\/$/m, 'reference folders should be ignored');

const landing = read('index.html');
const pages = ['index.html', 'demo.html', 'docs.html', 'compare.html'];
const repoUrl = 'https://github.com/tanzir71/liteinsurance';
const expectedHeaderLinks = [
  ['Home', './index.html'],
  ['Demo', './demo.html'],
  ['Docs', './docs.html'],
  ['Compare', './compare.html'],
  ['GitHub', repoUrl],
];

for (const page of pages) {
  const html = read(page);
  const header = html.match(/<header[\s\S]*?<\/header>/)?.[0] ?? '';
  assert.ok(header, `${page} should include a header`);
  for (const [label, href] of expectedHeaderLinks) {
    assert.match(header, new RegExp(`href="${href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"[^>]*>${label}<`), `${page} header should include ${label}`);
  }
  assert.match(header, /class="btn btn-primary" href="https:\/\/github\.com\/tanzir71\/liteinsurance"/, `${page} header GitHub CTA should use the canonical button classes`);
  assert.doesNotMatch(header, /Open app|href="\.\/liteinsurance\.php"|href="liteinsurance\.php"|#workflow/, `${page} header should not use changing or app-local CTA links`);
  assert.match(html, /header\s+\.container\s*\{[^}]*max-width:\s*1152px;?[^}]*\}/, `${page} header container should use the canonical width`);
  assert.match(html, /\.header-inner\s+\.btn\s*\{[^}]*min-height:\s*44px;[^}]*padding:\s*0 18px;[^}]*\}/, `${page} header CTA sizing should be canonical`);
  assert.doesNotMatch(html, /\.header-inner\s*\{[^}]*align-items:\s*flex-start|\.header-inner\s*\{[^}]*padding:\s*12px 0/, `${page} should not override header sizing at mobile widths`);
}

assert.match(landing, /LiteInsurance -- Insurance LTV and Risk Segmentation in One PHP File/, 'landing title should be buyer-ready');
assert.match(landing, /<meta name="description" content="[^"]{80,160}"/, 'landing should have a concise meta description');
assert.match(landing, /<link rel="canonical" href="https:\/\/tanzir71\.github\.io\/liteinsurance\/">/, 'landing should define canonical URL');
assert.match(landing, /SoftwareApplication/, 'landing should include SoftwareApplication JSON-LD');
assert.match(landing, /FAQPage/, 'landing should include FAQPage JSON-LD');
assert.match(landing, /href="\.\/demo\.html"/, 'landing should link to the no-install demo');
assert.match(landing, /href="\.\/docs\.html"/, 'landing should link to docs');
assert.match(landing, /href="\.\/compare\.html"/, 'landing should link to compare page');
assert.doesNotMatch(landing, /<REPO-NAME>|Vibe-coded|border-radius:\s*(?:6|8|999)px/, 'landing should not contain placeholders or rounded prototype styling');
assert.doesNotMatch(landing, /grid-template-columns:\s*1fr\s+1\.4fr\s+1fr/, 'evaluation cards should use equal-width columns');
assert.match(landing, /@media \(min-width: 980px\) \{ \.paths \{ grid-template-columns: repeat\(3, minmax\(0, 1fr\)\); \} \}/, 'evaluation cards should align on three equal desktop columns');
assert.match(landing, /\.path p \{[^}]*min-height: 88px;/, 'evaluation card descriptions should reserve equal vertical space before code blocks');
assert.match(landing, /\.pre-block \{[^}]*height: 128px;/, 'evaluation code boxes should have equal height');
assert.match(landing, /\.flex-copy \{[^}]*display: flex;[^}]*flex-direction: column;[^}]*min-height: 100%;/, 'flexibility section left column should stretch vertically');
assert.match(landing, /\.flex-support-grid \{[^}]*margin-top: auto;/, 'flexibility section support cards should anchor to the bottom of the right column');
assert.match(landing, /<div class="flex-copy">[\s\S]*<div class="grid grid-2 flex-support-grid">/, 'flexibility support cards should use the bottom-anchored grid class');

const demo = read('demo.html');
assert.match(demo, /const POLICY_COUNT = 200/, 'demo should generate a 200-policy sample');
assert.match(demo, /function generatePolicies\(/, 'demo should generate policies client-side');
assert.match(demo, /id="simRate"/, 'demo should include live simulator controls');
assert.match(demo, /downloadSegmentCsv/, 'demo should export segment CSV client-side');
assert.match(demo, /id="dataFile"[^>]+accept="\.csv,text\/csv"/, 'demo should accept local CSV upload for policyholder data');
assert.match(demo, /id="rulesEditor"/, 'demo should include an in-browser rules JSON editor');
assert.match(demo, /class="file-input"[^>]+id="dataFile"/, 'demo should hide the native file input chrome');
assert.match(demo, /id="dataFileName"/, 'demo should show selected upload filename in custom UI');
assert.match(demo, /id="dataPreview"/, 'data tab should fill the right column with a live dataset preview');
assert.match(demo, /id="dataHealth"/, 'data tab should show data health metrics beside the uploader');
assert.match(demo, /LOCAL_DEMO_STORAGE_KEY/, 'demo should persist imported demo data locally');
assert.match(demo, /function parseCsv\(/, 'demo should parse real CSV uploads in the browser');
assert.match(demo, /function applyUserRows\(/, 'demo should replace seeded policies with uploaded CSV rows');
assert.match(demo, /function renderDataPreview\(/, 'demo should render the data preview panel from active policies');
assert.match(demo, /function customFieldKey\(/, 'demo should derive stable custom field keys from arbitrary CSV headers');
assert.match(demo, /custom_fields/, 'demo should preserve unmapped CSV columns as local custom fields');
assert.match(demo, /custom\./, 'demo rules should be able to reference custom fields with custom.field_key');
assert.match(demo, /data-inline-cell/, 'demo should make the visible data tables directly editable inline');
assert.match(demo, /function applyInlineCellEdit\(/, 'demo should persist inline table edits through the active policy data model');
assert.match(demo, /document\.addEventListener\('input'[\s\S]*inlineDirty/, 'demo should track spreadsheet-style dirty cell edits before blur/change');
assert.match(demo, /event\.key === 'Enter'[\s\S]*applyInlineCellEdit\(input\)/, 'demo should commit inline cell edits when the user presses Enter');
assert.match(demo, /renderSegmentInlineRows/, 'segments should expose editable policy rows instead of only summary cards');
assert.doesNotMatch(demo, /id="customSheetHead"|id="customSheetRows"|Custom field sheet/, 'demo should not push edits into a separate custom-field sheet');
assert.match(demo, /Custom fields preserved locally/, 'demo should explain that arbitrary uploaded CSV columns are preserved locally');
assert.match(demo, /Real policyholder data replaces the seed sample/, 'demo should explicitly explain that uploaded real data replaces the seed sample');
assert.match(demo, /CSV rows hold people; rules JSON holds conditions and actions/, 'demo should distinguish policyholder CSV data from rules JSON');
assert.doesNotMatch(demo, /Policy JSON|policy JSON|dataEditor|Apply JSON to demo|Load seed JSON|edited policy JSON|CSV\/JSON import|Upload CSV or JSON|local CSV\/JSON upload|policy\/rule JSON/, 'demo should not imply policyholder data is the editable JSON surface');
assert.match(demo, /CSV import[^]*Rules JSON editor/, 'demo should present CSV import separately from rules JSON editing');

const php = read('liteinsurance.php');
assert.match(php, /const SAMPLE_POLICY_COUNT = 200;/, 'server sample should target 200 rows');
assert.match(php, /function sample_csv\(\): string/, 'server sample should be generated deterministically');
assert.match(php, /function sample_segments_seed\(/, 'sample loader should seed useful segments');
assert.match(php, /CREATE TABLE IF NOT EXISTS custom_field_defs/, 'server should catalog custom CSV fields without dynamic SQL columns');
assert.match(php, /function custom_field_key\(/, 'server should sanitize arbitrary CSV headers into stable custom field keys');
assert.match(php, /function extract_custom_fields\(/, 'server should preserve unmapped CSV columns in metadata.custom_fields');
assert.match(php, /function value_for_rule_field\(/, 'server rules should resolve custom.field_key values');
assert.match(php, /function setup_doctor_report\(/, 'server should expose setup doctor checks for deployment readiness');
assert.match(php, /Record ID \(required\)/, 'import mapping should use flexible Record ID language');
assert.match(php, /Display name/, 'import mapping should allow a display name separate from the record ID');
assert.match(php, /custom\.agent_code/, 'server docs/examples should show custom field rule references');
assert.match(php, /metadata\['custom_fields'\]/, 'server should keep custom fields when recomputing metadata');
assert.match(php, /policy_cell_update/, 'server should expose an inline cell update action for profile rows');
assert.match(php, /function handle_policy_cell_update\(/, 'server should persist inline profile cell edits');
assert.match(php, /data-policy-cell/, 'profile table cells should be editable in place rather than through a separate form');
assert.match(php, /e\.key==="Enter"[\s\S]*save\(input\)/, 'profile table cells should commit inline edits on Enter');
assert.doesNotMatch(php, /const SAMPLE_CSV = /, 'server should not ship a five-row static sample constant');

assert.match(landing, /custom CSV fields preserved locally/, 'landing should promise custom CSV fields only when the app preserves them');
assert.match(landing, /setup doctor validates deployment/, 'landing should mention the setup doctor for self-hosted deployment');

const docs = read('docs.html');
assert.match(docs, /custom\.renewal_probability/, 'docs should show custom fields in segment filters');
assert.match(docs, /Setup doctor/, 'docs should document the setup doctor');

const readme = read('README.md');
assert.match(readme, /custom CSV fields/, 'README should mention custom CSV field preservation');
assert.match(readme, /setup doctor/, 'README should mention setup doctor checks');

const llms = read('llms.txt');
assert.match(llms, /LiteInsurance/, 'llms.txt should describe the product');
assert.match(llms, /200-policy demo dataset/, 'llms.txt should mention demo dataset scale');

const sample = spawnSync('php', ['liteinsurance.php', 'action=download_sample_csv'], {
  cwd: root,
  encoding: 'utf8',
});
assert.equal(sample.status, 0, `sample download should exit cleanly: ${sample.stderr || sample.stdout}`);
assert.match(sample.stdout, /^policy_number,name,dob,gender,policy_type,region,premium_amount,tenure_months,sum_assured/m, 'sample CSV should include expected header');
assert.equal(sample.stdout.trim().split(/\r?\n/).length - 1, 200, 'sample CSV should contain 200 policy rows');

console.log('commercial readiness checks passed');
