// Execute immutable Git inputs with the whole application root mounted readonly.
// Only the explicitly local MySQL fixture network is available; financial
// fixtures roll back, and no customer/remote service is contacted by this tool.
const fs = require('fs'), path = require('path'), os = require('os'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R53-release';
const plan = JSON.parse(fs.readFileSync(evidence + '/trees.json'));
const tree = plan.candidateTree;
const target = path.join(os.homedir(), '.cache/mohe-r53-card-balance-tests', tree);
const git = args => cp.execFileSync('git', args, { maxBuffer: 256 * 1024 * 1024 });
const packages = ['后端代码/app', '后端代码/config', '后端代码/route', '后端代码/mohe',
  '后端代码/composer.json', '后端代码/database', 'tests/cashier-v3/php', 'tests/cashier-v3/js',
  'tests/report-basic/php', 'tests/cashier-v3-checkout-result-query', '前端代码/cashier-v3', '前端代码/shared'];
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const network = cp.execFileSync('docker', ['inspect', '-f', '{{range $name, $v := .NetworkSettings.Networks}}{{$name}}{{"\\n"}}{{end}}', 'mohe-app'], { encoding: 'utf8' }).trim().split('\n')[0];
if (network !== 'mohe-net') throw Error('Unexpected local fixture network');
const freeze = directory => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) continue;
    if (entry.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444);
  }
  fs.chmodSync(directory, 0o555);
};
fs.mkdirSync(path.dirname(target), { recursive: true });
if (!fs.existsSync(target)) {
  fs.mkdirSync(target);
  cp.execFileSync('tar', ['-xf', '-', '-C', target], { input: git(['archive', tree, ...packages]), maxBuffer: 256 * 1024 * 1024 });
  for (const name of ['vendor', 'runtime']) fs.mkdirSync(path.join(target, '后端代码', name));
  fs.writeFileSync(path.join(target, '后端代码/.env'), '');
  for (const name of ['cashier-v3', 'shared/unified-query-vue3']) {
    const dependency = path.join(root, '前端代码', name, 'node_modules');
    if (fs.existsSync(dependency)) fs.symlinkSync(dependency, path.join(target, '前端代码', name, 'node_modules'));
  }
  freeze(target);
}
function verify() {
  for (const entry of git(['ls-tree', '-rz', tree, '--', ...packages]).toString().split('\0').filter(Boolean)) {
    const [, blob, relative] = /^[0-9]+ blob ([a-f0-9]+)\t(.+)$/s.exec(entry);
    const bytes = fs.readFileSync(path.join(target, relative));
    if (crypto.createHash('sha1').update(`blob ${bytes.length}\0`).update(bytes).digest('hex') !== blob) throw Error('Fixed source drift: ' + relative);
  }
}
verify();
const tests = [
  ['r53-card-to-balance-gateway.php', 'PASS R53 normalizer/permission/context'],
  ['r53-card-to-balance-entry.php', 'R53_CARD_TO_BALANCE_ENTRY_OK'],
  ['r53-permission-install-local.php', 'PASS R53 local permission install'],
  ['r53-conversion-replay-scope.php', 'PASS R53 Gateway conversion replay scope'],
  ['r53-conversion-reversal-guard.php', 'PASS R53 conversion reversal guards'],
  ['r53-card-history-sqlite.php', 'R53_CARD_HISTORY_SQLITE=PASS'],
  ['r53-historical-scope-contract.php', 'R53_HISTORICAL_SCOPE_SQLITE_OK'],
  ['r53-card-to-balance-mysql.php', 'R53_CARD_TO_BALANCE_MYSQL_OK checks=63'],
  ['r53-legacy-reversal-mysql.php', '19 checks; fixtures rolled back'],
  ['r53-card-origin-lock-order-mysql.php', 'R53_ORIGIN_LOCK_ORDER_MYSQL_OK checks=11'],
  ['member-detail-v2-contract.php', 'PASS'],
  // The R94 tree retained the pre-whole-yuan kernel fixture. Keep that failed
  // diagnostic log, and run the unchanged matching contract from the exact
  // canonical commit that supplies the preserved deployed kernel instead.
  ['card-operation-kernel-preserved-contract.php', '16/0 PASS', 'd27d1317:tests/cashier-v3/php/card-operation-kernel-contract.php'],
  ['card-operation-upgrade-resource-context-contract.php', 'PASS'],
  ['c2-entitlement-completion-contract.php', 'PASS'],
  ['receipt-post-writeoff-times-contract.php', 'PASS'],
];
const results = [];
for (const [name, marker, preservedTest] of tests) {
  const file = 'tests/cashier-v3/php/' + name;
  const testInput = preservedTest ? git(['show', preservedTest]) : undefined;
  const run = cp.spawnSync('docker', ['run', '--rm', '-i', '--network', network, '--entrypoint', 'php', '-w', '/var/www/html',
    '-v', target + ':/fixed:ro', '-v', path.join(target, '后端代码') + ':/var/www/html:ro',
    '-v', path.join(root, '后端代码/vendor') + ':/var/www/html/vendor:ro',
    '-v', path.join(root, '后端代码/vendor') + ':/fixed/后端代码/vendor:ro',
    '-v', path.join(root, '后端代码/.env') + ':/var/www/html/.env:ro',
    '-v', path.join(root, '后端代码/.env') + ':/fixed/后端代码/.env:ro',
    '--tmpfs', '/var/www/html/runtime:rw,noexec,nosuid,size=128m',
    '--tmpfs', '/fixed/后端代码/runtime:rw,noexec,nosuid,size=128m',
    '-e', 'R53_LOCAL_ONLY=1', '-e', 'R53_GATEWAY_TEST=1', 'mohe-app:local', ...(preservedTest ? [] : ['/fixed/' + file])],
    { input: testInput, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, timeout: 240000 });
  const output = (run.stdout || '') + (run.stderr || '');
  fs.writeFileSync(evidence + '/fixed-' + name + '.log', output);
  results.push({ file, result: run.status === 0 && output.includes(marker) ? 'PASS' : 'FAIL', status: run.status, marker,
    ...(preservedTest ? { canonicalPreservedTest: preservedTest, testSHA256: hash(testInput) } : {}) });
  console.log(name, results.at(-1).result, output.split('\n').slice(-3).join(' '));
  fs.writeFileSync(evidence + '/fixed-tests.json', JSON.stringify({ tree, target, wholeApplicationRootReadonly: true,
    results, sourceStable: false, complete: false }, null, 2) + '\n');
  if (results.at(-1).result !== 'PASS') break;
}
verify();
const complete = results.length === tests.length;
fs.writeFileSync(evidence + '/fixed-tests.json', JSON.stringify({ tree, target, wholeApplicationRootReadonly: true,
  results, sourceStable: true, complete, result: complete && results.every(row => row.result === 'PASS') ? 'PASS' : 'FAIL' }, null, 2) + '\n');
if (!complete || results.some(row => row.result !== 'PASS')) process.exitCode = 1;
