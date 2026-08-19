import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const router = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/router/index.js'), 'utf8')
const loginView = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/StoreLoginView.vue'), 'utf8')
const bridge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'), 'utf8')
const sessionLifecycle = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3SessionLifecycle.js'), 'utf8')
const frontendManifest = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'), 'utf8')
const backendManifest = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/manifest/CashierV3C1WorkbenchModule.php'), 'utf8')
const backendBootstrap = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php'), 'utf8')
const policies = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/permission/CashierV3PermissionPolicyRegistry.php'), 'utf8')
const cashierRoleMiddleware = fs.readFileSync(path.join(root, '后端代码/app/http/middleware/cashier/CashierCheckRoleMiddleware.php'), 'utf8')

const assertions = [
  ['route feature mapping exists', router.includes('const routeFeatureCodes = Object.freeze({')],
  ['member route is tied to member feature', router.includes("'cashier-v3-member': 'cashier.v3.member'")],
  ['cashier route is tied to cashier feature', router.includes("'cashier-v3-cashier': 'cashier.v3.cashier'")],
  ['guard redirects an empty browser session before rendering a cashier shell', router.includes("if (!hasCashierV3Session()) return { name: 'cashier-v3-login' }")],
  ['guard accepts the server-issued login snapshot before lightweight bootstrap', router.includes("if (featureCode && canUseCashierV3Feature(featureCode)) return true\n\n  await ensureCashierV3RouterBootstrap")],
  ['guard waits for server-backed bootstrap', router.includes("await ensureCashierV3RouterBootstrap({ reason: 'route-permission', silent: true })")],
  ['guard redirects unauthorized routes', router.includes('const fallbackRoute = firstGrantedRouteName()')],
  ['guard fails closed to login when no feature is granted', router.includes("return { name: 'cashier-v3-login' }")],
  ['login applies visible and operation snapshots before navigation', loginView.includes('applyCashierV3LoginFeatures(result.features, {') && loginView.includes('visibleFeatures:') && loginView.includes('operationFeatures:')],
  ['login snapshot separates visible and operation permissions', bridge.includes('export function applyCashierV3LoginFeatures(features = [], options = {})') && bridge.includes('options.visibleFeatures') && bridge.includes('options.operationFeatures') && bridge.includes('cashierV3State.operationPermissions')],
  ['login snapshot marks delegated sessions read-only', loginView.includes('readOnly: result.read_only === true') && bridge.includes('options.readOnly === true')],
  ['lightweight bootstrap returns server-resolved feature codes', backendBootstrap.includes("'features' => array_values($dataScope->grantedFeatures())")],
  ['lightweight bootstrap refreshes visible and operation snapshots', sessionLifecycle.includes('function applyBootstrapFeatureSnapshot(result)') && sessionLifecycle.includes('visibleFeatures: features') && sessionLifecycle.includes('operationFeatures:') && sessionLifecycle.includes('applyCashierV3LoginFeatures(features, {')],
  ['frontend bootstrap uses session policy rather than cashier feature', frontendManifest.includes("'open-cashier-workbench': 'policy:store_v3_session'")],
  ['backend bootstrap uses matching session policy', backendManifest.includes("'open-cashier-workbench' => self::POLICY_STORE_V3_SESSION")],
  ['session policy requires at least one server-resolved feature', policies.includes("register('policy:store_v3_session'") && policies.includes('$scope->grantedFeatures()')],
  ['delegated read-only middleware leaves action gateway for manifest-level projection/command checks', cashierRoleMiddleware.includes('$isV3ActionGateway') && cashierRoleMiddleware.includes('workbenches/actions') && cashierRoleMiddleware.includes('!$isV3ActionGateway')],
  ['direct employee save routes have separate create/edit gates', cashierRoleMiddleware.includes("'cashier.v3.staff.edit' : 'cashier.v3.staff.create'") && cashierRoleMiddleware.includes('cashierapi/v3/management/staff/')],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
