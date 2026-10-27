// Local-only verification of canonical hot sources. This is not an isolated
// release candidate and does not authorize commit, remote access or deployment.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R55-member-filters';
const runtime = '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node';
const output = '/private/tmp/mohe-r55-member-filters-build-20261024';
const files = [
  '前端代码/cashier-v3/src/views/MemberListView.vue',
  '前端代码/cashier-v3/src/components/query/MemberReportQueryToolbar.vue',
  '前端代码/cashier-v3/src/services/memberReportFilters.js',
  '前端代码/shared/unified-query-vue3/src/components/UnifiedReportQueryToolbar.vue',
  '前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js',
  'tests/cashier-v3/js/r55-member-filters.mjs',
  'tests/cashier-v3/php/r55-member-filter-metadata.php',
  'tests/cashier-v3/js/unified-query-frontend-contract.mjs',
  'tests/report-basic/js/report-filter-contract.mjs',
  'scripts/r55-member-filters-verify.cjs'
];
const hash = b => crypto.createHash('sha256').update(b).digest('hex');
const digests = () => Object.fromEntries(files.map(file => [file, hash(fs.readFileSync(file))]));
const before = digests();
// Preserve the existing global-suite failure instead of loosening an unrelated
// facade line-count assertion. Both old and updated tests stop before R55 logic.
const globalTest = 'tests/cashier-v3/js/unified-query-frontend-contract.mjs';
const baseline = JSON.parse(fs.readFileSync(path.join(evidence, 'baseline.json')));
const baselineSource = cp.execFileSync('git', ['cat-file', 'blob', baseline.files[globalTest].blob]).toString();
const globalRuns = {};
for (const [variant, source] of [['baseline', baselineSource], ['current', fs.readFileSync(globalTest, 'utf8')]]) {
  const url = require('url').pathToFileURL(path.join(root, globalTest)).href;
  const result = cp.spawnSync(runtime, ['--input-type=module'], { input: source.replaceAll('import.meta.url', JSON.stringify(url)), encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
  const log = (result.stdout || '') + (result.stderr || '');
  fs.writeFileSync(path.join(evidence, 'global-contract-' + variant + '.log'), log);
  if (result.status === 0 || !log.includes('工具栏 facade 仅保留宿主适配')) throw Error('Global suite failure changed; inspect instead of masking it');
  globalRuns[variant] = { status: result.status, failure: '工具栏 facade 仅保留宿主适配' };
}
const tasks = [
  ['member-browser', ['tests/cashier-v3/js/r55-member-filters.mjs']],
  ['report-regression', ['tests/report-basic/js/report-filter-contract.mjs']],
  ['period-contract', ['tests/unified-query/js/date-range-contract.mjs']],
  ['member-create-retained', ['tests/cashier-v3/js/member-list-creator-contract.mjs']],
  ['member-edit-retained', ['tests/cashier-v3/js/member-edit-conflict-contract.mjs']],
  ['member-save-retained', ['tests/cashier-v3/js/r43-member-save-dialog-contract.mjs']],
  ['member-export-retained', ['tests/cashier-v3/js/r20-member-export-download-contract.mjs']],
  ['checkout-prebuild', ['tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs']],
  ['build', [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build', '--outDir', output, '--emptyOutDir']]
];
const results = [];
for (const [name, args] of tasks) {
  const result = cp.spawnSync(runtime, args, { cwd: name === 'build' ? path.join(root, '前端代码/cashier-v3') : root,
    env: { ...process.env, REPORT_FILTER_EVIDENCE_DIR: path.join(root, evidence, 'report-regression') }, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 180000 });
  fs.writeFileSync(path.join(evidence, name + '.log'), (result.stdout || '') + (result.stderr || ''));
  results.push({ name, result: result.status === 0 ? 'PASS' : 'FAIL' });
  console.log(name, results.at(-1).result);
  if (result.status !== 0) throw Error(name + ' failed: ' + result.stderr);
}
const after = digests(); if (JSON.stringify(before) !== JSON.stringify(after)) throw Error('Source changed during verification');
const assets = {};
const scan = dir => { for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
  const file = path.join(dir, entry.name); if (entry.isDirectory()) scan(file);
  else if (entry.isFile()) assets[path.relative(output, file)] = hash(fs.readFileSync(file));
  else throw Error('Unexpected generated asset');
} }; scan(output);
const record = { head: cp.execFileSync('git', ['rev-parse', 'HEAD']).toString().trim(), files: after, sourceStable: true,
  knownGlobalSuite: { result: 'PRE_EXISTING_FAILURE', globalRuns, changedFacade: false },
  results, build: { source: path.join(root, '前端代码/cashier-v3'), hotEntry: 'http://127.0.0.1:18091/view_cashier_v3/#/member', output, assets },
  scope: 'R55 local member list filters only; excludes unrelated hot-workspace changes from future commit/deployment',
  onlineInstances: 'Not applicable: no remote access', databaseMigration: false, commit: false, push: false, deployment: false };
fs.writeFileSync(path.join(evidence, 'fixed-files.json'), JSON.stringify(record, null, 2));
console.log('R55_LOCAL_FIXED_PASS files=' + files.length);
