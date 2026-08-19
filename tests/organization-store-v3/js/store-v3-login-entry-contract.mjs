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
const dataScopeFactory = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/CashierV3DataScopeFactory.php'),
  'utf8',
)
const permissionGuard = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/registry/CashierV3PermissionGuard.php'),
  'utf8',
)
const dataScopeContext = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/CashierV3DataScopeContext.php'),
  'utf8',
)
const cashierRoleMiddleware = fs.readFileSync(
  path.join(root, '后端代码/app/http/middleware/cashier/CashierCheckRoleMiddleware.php'),
  'utf8',
)

const assertions = [
  ['store-v3 eligibility is resolved through the employee store-login filter', loginService.includes('listEligibleStoreV3Staff')],
  ['a missing employee channel override inherits the assigned position rules', !loginService.includes("->where('status', 1)\n            ->where('is_del', 0)\n            ->find();\n        if (!$entry) {")],
  ['an explicit disabled employee channel override still denies entry', loginService.includes("if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['feature resolution also inherits a missing employee channel override', featureResolver.includes("->where('is_del', 0)->find();\n            if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['role projection documents the same no-override inheritance rule', positionService.includes('无入口表时：以岗位规则为准')],
  ['store-v3 login uses the unique active tenure and never issues a selection ticket', cashierLogin.includes('resolveUniqueStoreV3Staff($employeeId)') && !cashierLogin.includes('SELECT_TICKET_PREFIX') && !cashierLogin.includes("'login_ticket'") && !cashierLogin.includes("'need_select_store' => true")],
  ['organization read-only entry binds one delegated store without cross-store selection', cashierLogin.includes('store_read_only') && cashierLogin.includes('count($delegated) > 1') && cashierLogin.includes('指定门店入口')],
  ['data-scope store uses a dedicated session instead of restoring staff tenure', cashierLogin.includes('cashier_v3_store_session') && cashierLogin.includes('issueDelegatedStore') && cashierLogin.includes("'_cashier_v3_delegated'" )],
  ['login-after-selection is locked and switch endpoint is denied', cashierLogin.includes('当前门店已在登录时固定，不支持登录后切换')],
  ['direct tenure is checked before any delegated read-only fallback', cashierLogin.includes('resolveUniqueStoreV3Staff($employeeId)') && cashierLogin.includes('activeTenureCount')],
  ['delegated store session is explicitly marked read-only', cashierLogin.includes("'_cashier_v3_read_only' => 1") && cashierLogin.includes("'read_only' => true") && cashierLogin.includes("'session_mode' => 'store_read_only'") && cashierLogin.includes("'read_only' => !empty($store['delegated'])")],
  ['data-scope context carries a server-derived read-only marker', dataScopeFactory.includes("!empty($operatorProfile['_cashier_v3_delegated'])") && dataScopeFactory.includes('$readOnlySession') && dataScopeContext.includes('isReadOnlySession')],
  ['all command actions are denied in delegated read-only sessions', permissionGuard.includes("$dataScope->isReadOnlySession()") && permissionGuard.includes("($definition['type'] ?? '') === 'command'") && permissionGuard.includes("store_read_only_session")],
  ['direct V3 write routes are denied for delegated sessions while logout remains available', cashierRoleMiddleware.includes("!empty($cashierInfo['_cashier_v3_delegated'])") && cashierRoleMiddleware.includes("!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)") && cashierRoleMiddleware.includes("$isLogout")],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
