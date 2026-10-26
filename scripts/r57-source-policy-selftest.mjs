import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'

// Local-only regression/fingerprint evidence. Does not stage, commit, deploy,
// migrate or submit business transactions; transport fixtures stay isolated.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const evidence = path.join(root, 'tests/cashier-v3/evidence/R57-source-policy')
fs.mkdirSync(evidence, { recursive: true })
const groups = {
  frontend: [
    '前端代码/mobile-vue3/src/shared/api/mobile-http.uts',
    '前端代码/admin/src/pages/setting/shop/base.vue',
    ...['services/cashierV3Bridge.js', 'views/CashierWorkbenchView.vue', 'layouts/CashierShell.vue',
      'components/cashier/CashierCheckoutOverlay.vue', 'components/member/MemberDebtOverlay.vue',
      'components/reservation/ReservationEditorOverlay.vue', 'components/reservation/ReservationDetailOverlay.vue',
      'views/ReservationListView.vue', 'views/OrderCenterView.vue', 'components/query/OrderCenterReportQueryToolbar.vue',
      'services/orderCenterReportFilters.js', 'components/order/SalesOrderDetailOverlay.vue'
    ].map(file => '前端代码/cashier-v3/src/' + file),
    ...['reservations', 'reservation-confirmations'].map(page => `前端代码/mobile-vue3/src/merchant/pages/${page}/index.uvue`)
  ],
  backend: [
    '后端代码/app/controller/admin/v1/system/MemberCommerceSettings.php',
    '后端代码/app/services/member/MemberCommerceSettingsServices.php',
    '后端代码/app/services/query/UnifiedQueryRuntime.php',
    ...['config/CashierV3BusinessSourcePolicyServices.php', 'projection/CashierV3RootDomainAssembler.php',
      'projection/CashierV3RootStateContract.php', 'settlement/CashierV3CheckoutBusinessConfigProjectionServices.php',
      'settlement/CashierV3CheckoutBusinessSourceSelectionServices.php', 'settlement/CashierV3CheckoutPreparationServices.php',
      'settlement/CashierV3DebtRepaymentServices.php', 'settlement/CashierV3RechargeDebtRepaymentServices.php',
      'member/CashierV3RechargeModule.php', 'member/CashierV3RechargeCheckoutModule.php', 'member/CashierV3MemberModule.php',
      'cashier/CashierV3MemberDebtProjectionServices.php', 'reservation/CashierV3ReservationModule.php',
      'reservation/CashierV3ReservationDetailQueryServices.php', 'order/CashierV3OrderCenterUnifiedQueryContract.php',
      'order/CashierV3OrderCenterUnifiedQueryRegistrar.php', 'order/CashierV3OrderCenterQueryExecutionServices.php',
      'order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php'
    ].map(file => '后端代码/app/services/cashier/v3/' + file)
  ],
  migrations: ['后端代码/database/upgrades/2026-10-25-业务来源策略/01-schema.sql'],
  scripts: ['scripts/r57-source-policy-local.php', 'scripts/r57-source-policy-selftest.mjs']
}
const js = ['tests/platform-admin/js/r57-business-source-settings.mjs',
  'tests/mobile-vue3/js/r57-h5-get-query.test.mjs',
  'tests/cashier-v3/js/r57-selected-member-source.mjs',
  'tests/order-center/r57-business-source-policy.mjs', 'tests/cashier-v3/js/r57-checkout-source-policy.mjs',
  'tests/cashier-v3/js/r57-reservation-source-policy.mjs', 'tests/cashier-v3/js/local-checkout-source-replay-contract.mjs',
  'tests/cashier-v3/js/checkout-source-required-contract.mjs', 'tests/cashier-v3/js/checkout-reservation-completion-contract.mjs',
  'tests/cashier-v3/js/reservation-lifecycle-frontend-contract.mjs', 'tests/cashier-v3/js/order-center-entitlement-card-source-contract.mjs',
  'tests/cashier-v3/js/debt-repayment-original-source-contract.mjs', 'tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs',
  'tests/cashier-v3/js/business-source-checkout-frontend-contract.mjs',
  'tests/cashier-v3/js/r11-reservation-default-schedule.mjs', 'tests/mobile-vue3/js/reservation-page.test.mjs']
const php = ['tests/platform-admin/php/r57-business-source-settings.php', 'tests/order-center/r57-source-runtime-cache.php',
  'tests/cashier-v3/php/r57-selected-member-source-projection.php',
  'tests/cashier-v3/php/r57-checkout-source-policy.php', 'tests/cashier-v3/php/r57-reservation-source-policy.php',
  'tests/cashier-v3/php/recharge-checkout-contract.php', 'tests/cashier-v3/php/repayment-source-snapshot-contract.php']
groups.tests = [...js, ...php, 'tests/order-center/r57-business-source-policy.php', 'tests/cashier-v3/php/r57-business-source-policy.php']
const run = (label, command, args, extra = {}) => {
  const result = spawnSync(command, args, { cwd: root, encoding: 'utf8', timeout: 180000,
    env: { ...process.env, R57_SCREENSHOT_DIR: path.join(evidence, 'screenshots') }, ...extra })
  const record = { label, passed: result.status === 0, status: result.status, error: result.error?.message,
    stdout: result.stdout?.trim(), stderr: result.stderr?.trim() }
  console.log(`${record.passed ? 'PASS' : 'FAIL'} ${label}`)
  return record
}
// Read all scoped inputs first. A post-test mismatch prevents a misleading
// fixed-version claim when another local task changes shared files mid-run.
const sha = file => createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex')
const fingerprints = Object.fromEntries(Object.entries(groups).map(([group, files]) => [group,
  files.map(file => ({ file, sha256: sha(file) }))]))
const results = js.map(file => run(file, process.execPath, [file]))
results.push(...php.map(file => run(file, 'php', ['-d', 'error_reporting=24575', file])))
results.push(run('policy PHP7.4', 'docker', ['exec', '-e', 'CASHIER_V3_BACKEND=/var/www/html', '-i', 'mohe-app', 'php'],
  { input: fs.readFileSync(path.join(root, 'tests/cashier-v3/php/r57-business-source-policy.php'), 'utf8') }))
results.push(run('local MySQL read-only', 'docker', ['exec', '-i', 'mohe-app', 'php', '--', 'probe'],
  { input: fs.readFileSync(path.join(root, 'scripts/r57-source-policy-local.php'), 'utf8') }))
results.push(...groups.backend.map(file => run(`syntax ${file}`, 'php', ['-d', 'error_reporting=24575', '-l', file])))
results.push(run('scoped whitespace', 'git', ['diff', '--check', '--', ...Object.values(groups).flat()]))
const changed = Object.values(fingerprints).flat().filter(item => sha(item.file) !== item.sha256).map(item => item.file)
const passed = results.every(item => item.passed) && changed.length === 0
const head = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim()
fs.writeFileSync(path.join(evidence, 'selftest.json'), JSON.stringify({ result: passed ? 'PASS' : 'FAIL', head,
  source: root, fingerprints, changedDuringTests: changed, results, online: 'not applicable: local only',
  acceptance: 'pending product manager', limits: ['Fixture transports are not business E2E acceptance.',
    'MySQL history/catalog probe is read-only; no real payment or reservation submitted.',
    'Mobile UTS setup/template checks do not replace the full native compiler.'] }, null, 2) + '\n')
process.exitCode = passed ? 0 : 1
