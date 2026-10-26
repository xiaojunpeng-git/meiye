// Local-only fixed-input evidence. SQL fixtures roll back, and this runner
// never commits, deploys, pays, confirms a real reservation, or contacts clients.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const e = 'tests/cashier-v3/evidence/R58-custom-service'; fs.mkdirSync(e, { recursive: true });
const groups = {
  frontend: ['前端代码/uniapp/api/order.js', '前端代码/uniapp/pages.json',
    '前端代码/uniapp/pages/activity/reservation/index.vue', '前端代码/uniapp/pages/activity/reservation_custom_services/index.vue',
    '前端代码/uniapp/pages/goods/reservation_details/index.vue',
    '前端代码/mobile-vue3/src/merchant/pages/reservation-confirmations/index.uvue',
    '前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue',
    '前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue'],
  backend: ['后端代码/app/services/store/ServiceNameSettingsServices.php',
    '后端代码/app/services/cashier/v3/reservation/ReservationCustomServiceServices.php',
    '后端代码/app/services/cashier/v3/reservation/MemberV3ReservationServices.php',
    '后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php',
    '后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php',
    '后端代码/app/controller/api/v1/order/V3ReservationOrder.php', '后端代码/route/api.php'],
  migrations: ['后端代码/database/upgrades/2026-10-25-预约定制服务/01-schema.sql'],
  tests: ['tests/cashier-v3/php/r58-reservation-custom-service.php', 'tests/cashier-v3/js/r58-custom-service.mjs'],
  scripts: ['scripts/r58-custom-service-selftest.cjs']
};
const sha = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const before = Object.fromEntries(Object.values(groups).flat().map(file => [file, sha(file)]));
const results = [];
function run(label, bin, args, input) {
  const r = cp.spawnSync(bin, args, { input, encoding: 'utf8', timeout: 180000, maxBuffer: 4 * 1024 * 1024 });
  fs.writeFileSync(e + '/' + label.replace(/[^a-z0-9_-]/gi, '-') + '.log', (r.stdout || '') + (r.stderr || ''));
  results.push({ label, passed: r.status === 0, status: r.status, error: r.error?.message });
  console.log(r.status === 0 ? 'PASS' : 'FAIL', label);
}
run('r58-backend', 'docker', ['exec', '-i', 'mohe-app', 'php'], fs.readFileSync(groups.tests[0]));
for (const file of [groups.tests[1], 'tests/member-reservation-v3/member-reservation-v3-contract.mjs',
  'tests/member-reservation-v3/historical-addon-contract.cjs', 'tests/cashier-v3/js/r57-reservation-source-policy.mjs',
  'tests/mobile-vue3/js/reservation-page.test.mjs']) run(path.basename(file), process.execPath, [file]);
for (const file of groups.backend) run('syntax-' + path.basename(file), 'docker', ['exec', '-i', 'mohe-app', 'php', '-l'], fs.readFileSync(file));
run('whitespace', 'git', ['diff', '--check', '--', ...Object.values(groups).flat()]);
const changed = Object.entries(before).filter(([file, digest]) => sha(file) !== digest).map(([file]) => file);
const passed = results.every(r => r.passed) && !changed.length;
fs.writeFileSync(e + '/selftest.json', JSON.stringify({ result: passed ? 'PASS' : 'FAIL',
  head: cp.execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(), groups,
  filesSHA256: before, changedDuringTests: changed, results, online: 'not applicable: local only',
  localMigration: groups.migrations[0], noHistoricalBackfill: true, acceptance: 'pending product manager',
  limitations: ['Vue2 browser tests isolate transport and map native view/text tags to HTML.',
    'MySQL service fixtures roll back; not a real member booking or payment.',
    'Not an independent release candidate or mini-program upload.'] }, null, 2) + '\n');
process.exitCode = passed ? 0 : 1;
