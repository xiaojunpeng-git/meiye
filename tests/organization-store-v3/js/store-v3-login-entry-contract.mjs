import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const loginService = fs.readFileSync(
  path.join(root, '后端代码/app/services/employee/EmployeeInternalLoginServices.php'),
  'utf8',
)
const positionService = fs.readFileSync(
  path.join(root, '后端代码/app/services/organization/StaffJobPositionServices.php'),
  'utf8',
)
const featureResolver = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php'),
  'utf8',
)
const cashierLogin = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php'),
  'utf8',
)

const assertions = [
  ['store-v3 eligibility is resolved through the employee store-login filter', loginService.includes('listEligibleStoreV3Staff')],
  ['a missing employee channel override inherits the assigned position rules', !loginService.includes("->where('status', 1)\n            ->where('is_del', 0)\n            ->find();\n        if (!$entry) {")],
  ['an explicit disabled employee channel override still denies entry', loginService.includes("if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['feature resolution also inherits a missing employee channel override', featureResolver.includes("->where('is_del', 0)->find();\n            if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['role projection documents the same no-override inheritance rule', positionService.includes('无入口表时：以岗位规则为准')],
  ['login unions direct tenure and effective data-scope stores', cashierLogin.includes('eligibleStores') && cashierLogin.includes('resolveEffectiveStoreIds') && cashierLogin.includes("'direct_tenure'") && cashierLogin.includes("'data_scope'")],
  ['multi-store login returns one-time selection ticket', cashierLogin.includes('cashier_v3_store_select:') && cashierLogin.includes('need_select_store') && cashierLogin.includes('Cache::delete(self::SELECT_TICKET_PREFIX . $ticket)')],
  ['data-scope store uses a dedicated session instead of restoring staff tenure', cashierLogin.includes('cashier_v3_store_session') && cashierLogin.includes('issueDelegatedStore') && cashierLogin.includes("'_cashier_v3_delegated'" )],
  ['login-after-selection is locked and switch endpoint is denied', cashierLogin.includes('当前门店已在登录时固定，不支持登录后切换')],
  ['direct tenure takes priority over data-scope candidates', cashierLogin.includes('有直接任职时优先使用直接任职') && cashierLogin.includes('count($direct) === 1')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
