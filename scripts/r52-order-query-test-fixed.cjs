// Test an immutable export of the candidate tree, not the dirty hot workspace. No application/DB boot.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const release = path.join(root, 'tests/order-center/evidence/r52-release-v2');
const plan = JSON.parse(fs.readFileSync(path.join(release, 'trees.json')));
const target = '/private/tmp/mohe-r52-order-query-fixed-tests-' + plan.candidateTree.slice(0, 12);
const packages = ['前端代码/admin', '前端代码/cashier-v3', '前端代码/shared', '前端代码/inventory-vue3', '后端代码/app',
  'tests/order-center/r52-report-filters.mjs', 'tests/order-center/r52-dynamic-metadata.php', 'tests/report-basic/js/report-filter-contract.mjs'];
const git = args => cp.execFileSync('git', args, { maxBuffer: 256 * 1024 * 1024 });
if (fs.existsSync(target)) throw Error('Retain previous fixed test input; refusing overwrite');
fs.mkdirSync(target);
cp.execFileSync('tar', ['-xf', '-', '-C', target], { input: git(['archive', plan.candidateTree, ...packages]), maxBuffer: 256 * 1024 * 1024 });
for (const name of ['admin', 'cashier-v3', 'inventory-vue3']) fs.symlinkSync(path.join(root, '前端代码', name, 'node_modules'), path.join(target, '前端代码', name, 'node_modules'));
const generated = path.join(target, 'tests/order-center/evidence/r52-report-filters');
fs.mkdirSync(generated, { recursive: true });
const entries = git(['ls-tree', '-rz', '--full-tree', plan.candidateTree, '--', ...packages]).toString().split('\0').filter(Boolean).map(line => {
  const match = /^(100644|100755) blob ([a-f0-9]+)\t(.+)$/s.exec(line);
  if (!match) throw Error('Nonregular fixed input');
  return { blob: match[2], file: match[3] };
});
const verify = () => {
  for (const entry of entries) {
    const bytes = fs.readFileSync(path.join(target, entry.file));
    const blob = crypto.createHash('sha1').update(`blob ${bytes.length}\0`).update(bytes).digest('hex');
    if (blob !== entry.blob) throw Error('Fixed test source drift: ' + entry.file);
  }
};
function freeze(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (file === generated || file.startsWith(generated + '/')) continue;
    if (entry.isDirectory()) freeze(file); else if (!entry.isSymbolicLink()) fs.chmodSync(file, 0o444);
  }
  fs.chmodSync(dir, 0o555);
}
verify(); freeze(target);
const outcomes = [];
for (const file of ['tests/order-center/r52-report-filters.mjs', 'tests/report-basic/js/report-filter-contract.mjs']) {
  try {
    const output = cp.execFileSync(process.execPath, [path.join(target, file)], { cwd: target,
      env: { ...process.env, GIT_DIR: path.join(root, '.git'), GIT_WORK_TREE: target, REPORT_FILTER_EVIDENCE_DIR: path.join(release, 'fixed-shared-regression') },
      encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
    outcomes.push({ file, result: 'PASS', output }); console.log(output.trim());
  } catch (error) { outcomes.push({ file, result: 'FAIL', output: String(error.stdout || '') + String(error.stderr || '') }); console.log(outcomes.at(-1)); }
}
verify();
const resultFile = path.join(generated, 'result.json');
const componentResult = fs.existsSync(resultFile) ? JSON.parse(fs.readFileSync(resultFile)) : null;
fs.writeFileSync(path.join(release, 'fixed-tests.json'), JSON.stringify({ candidateTree: plan.candidateTree, target, inputFiles: entries.length, sourceStable: true,
  outcomes, componentResult, result: outcomes.every(item => item.result === 'PASS') ? 'PASS' : 'FAIL', realBusinessWrite: false }, null, 2), { flag: 'wx' });
if (outcomes.some(item => item.result !== 'PASS')) process.exitCode = 1;
