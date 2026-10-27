// Rerun accepted assertions against immutable canonical Git exports. Dependency
// mounts are readonly; backend tests receive the fixed app, not hot PHP sources.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const e = 'tests/cashier-v3/evidence/R57-release';
const plan = JSON.parse(fs.readFileSync(e + '/trees.json'));
const prior = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R57-source-policy/selftest.json'));
const tree = plan.candidateTree;
const target = path.join(process.env.HOME, '.cache/mohe-r57-source-policy-tests', tree);
const git = args => cp.execFileSync('git', args, { maxBuffer: 512 * 1024 * 1024 });
const packages = ['后端代码/app', '后端代码/config', '后端代码/route', '后端代码/mohe', '后端代码/composer.json',
  '后端代码/database', 'tests', 'scripts', '前端代码/admin', '前端代码/cashier-v3', '前端代码/shared', '前端代码/mobile-vue3', '前端代码/inventory-vue3'];
const entries = git(['ls-tree', '-rz', tree, '--', ...packages]).toString().split('\0').filter(Boolean).map(line => {
  const [, blob, file] = /^\d+ blob ([a-f0-9]+)\t(.+)$/s.exec(line); return { blob, file };
});
function freeze(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isSymbolicLink()) continue;
    if (entry.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444);
  }
  fs.chmodSync(dir, 0o555);
}
if (!fs.existsSync(target)) {
  fs.mkdirSync(target, { recursive: true });
  cp.execFileSync('tar', ['-xf', '-', '-C', target], { input: git(['archive', tree, ...packages]), maxBuffer: 512 * 1024 * 1024 });
  for (const name of ['admin', 'cashier-v3', 'inventory-vue3']) fs.symlinkSync(path.join(root, '前端代码', name, 'node_modules'), path.join(target, '前端代码', name, 'node_modules'));
  for (const name of ['vendor', 'runtime']) fs.mkdirSync(path.join(target, '后端代码', name));
  fs.writeFileSync(path.join(target, '后端代码/.env'), '');
  freeze(target);
}
function verify() {
  for (const { file, blob } of entries) {
    const b = fs.readFileSync(path.join(target, file));
    if (crypto.createHash('sha1').update(`blob ${b.length}\0`).update(b).digest('hex') !== blob) throw Error('Frozen source drift: ' + file);
  }
}
verify();
const results = [];
function run(file, bin, args, env = {}) {
  const p = cp.spawnSync(bin, args, { cwd: target, encoding: 'utf8', timeout: 240000, maxBuffer: 16 * 1024 * 1024,
    env: { ...process.env, GIT_DIR: path.join(root, '.git'), R57_SCREENSHOT_DIR: path.join(root, e, 'fixed-screenshots'), ...env } });
  const output = (p.stdout || '') + (p.stderr || '');
  fs.writeFileSync(path.join(root, e, 'fixed-' + path.basename(file) + '.log'), output);
  results.push({ file, passed: p.status === 0, status: p.status });
  console.log(p.status === 0 ? 'PASS' : 'FAIL', file);
}
// Only generated evidence directories are writable; every tracked input is
// hashed again afterwards, including tests that store browser screenshots.
const outputParent = path.join(target, 'tests/order-center/evidence');
fs.chmodSync(path.dirname(outputParent), 0o755);
if (!fs.existsSync(outputParent)) fs.mkdirSync(outputParent);
fs.chmodSync(outputParent, 0o755);
const priorResults = prior.results.filter(r => r.label.startsWith('tests/'));
for (const { label: file } of priorResults) {
  if (file.endsWith('.mjs')) run(file, process.execPath, [path.join(target, file)]);
  // This retained source-only contract uses PHP8 str_contains; production
  // business contracts below still execute with the deployed PHP7.4 runtime.
  else if (file === 'tests/cashier-v3/php/recharge-checkout-contract.php') run(file, 'php', [path.join(target, file)]);
  else run(file, 'docker', ['run', '--rm', '--network', 'none', '--entrypoint', 'php', '-w', '/var/www/html',
    '-v', target + ':/fixed:ro', '-v', path.join(target, '后端代码') + ':/var/www/html:ro',
    '-v', path.join(root, '后端代码/vendor') + ':/var/www/html/vendor:ro',
    '-v', path.join(root, '后端代码/vendor') + ':/fixed/后端代码/vendor:ro',
    'mohe-app:local', '/fixed/' + file]);
}
const policy = 'tests/cashier-v3/php/r57-business-source-policy.php';
run(policy, 'docker', ['run', '--rm', '--network', 'none', '--entrypoint', 'php', '-w', '/var/www/html',
  '-v', target + ':/fixed:ro', '-v', path.join(target, '后端代码') + ':/var/www/html:ro',
  '-v', path.join(root, '后端代码/vendor') + ':/var/www/html/vendor:ro',
  '-v', path.join(root, '后端代码/vendor') + ':/fixed/后端代码/vendor:ro',
  'mohe-app:local', '/fixed/' + policy]);
verify();
fs.writeFileSync(e + '/fixed-source-tests.json', JSON.stringify({ tree, target, sourceStable: true,
  result: results.every(r => r.passed) ? 'PASS' : 'FAIL', results, businessWrites: false }, null, 2) + '\n');
process.exitCode = results.every(r => r.passed) ? 0 : 1;
