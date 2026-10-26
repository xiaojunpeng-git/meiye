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
const loginController = fs.readFileSync(
  path.join(root, '后端代码/app/controller/cashier/v3/StoreLogin.php'),
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
const workspace = fs.readFileSync(
  path.join(root, '前端代码/admin/src/pages/store/region/workspace/index.vue'),
  'utf8',
)

// 门店端不再有员工个人入口覆盖；测试同时约束登录、功能授权、写入接口和预览。
const channelEntryCheck = loginService.split('public function staffHasChannelEntry(')[1]?.split('public function employeeHasPlatformEntry(')[0] || ''
const directFeatureCheck = featureResolver.split('private function storeV3GrantedFeatures(')[1]?.split('public function employeeStoreV3GrantedFeatures(')[0] || ''

const assertions = [
  ['store-v3 eligibility is resolved through the employee store-login filter', loginService.includes('listEligibleStoreV3Staff')],
  ['a missing employee channel override inherits the assigned position rules', !loginService.includes("->where('status', 1)\n            ->where('is_del', 0)\n            ->find();\n        if (!$entry) {")],
  ['store-v3 ignores historical employee entry overrides while other channels retain their gate', channelEntryCheck.includes('if ($channel !== JobPositionPolicyServices::CHANNEL_STORE_V3) {') && channelEntryCheck.includes("if ($entry && (int)($entry['status'] ?? 0) !== 1) {")],
  ['store-v3 feature authorization ignores historical employee entry overrides', !directFeatureCheck.includes("Db::name('staff_channel_entry')") && directFeatureCheck.includes("->where('position.status', 1)->where('position.use_store', 1)")],
  ['employee entry writes allow only mobile, including the complete-save path', positionService.includes('if ($ch !== JobPositionPolicyServices::CHANNEL_MOBILE) {') && positionService.includes('foreach (array_keys($normalized) as $channel) {')],
  ['employee permission preview no longer shows a separate store-v3 entry status', !workspace.includes("entryStatusText(row.staff_id, 'store_v3')") && !workspace.includes("{ channel: 'store_v3', status:")],
  ['store-v3 eligibility and feature resolution both require an enabled position', channelEntryCheck.includes('->where($col, 1)') && directFeatureCheck.includes("->where('position.status', 1)->where('position.use_store', 1)")],
  ['store-v3 login keeps unique active tenure as a direct entry but restores a one-time organization selection ticket', cashierLogin.includes('resolveUniqueStoreV3Staff($employeeId)') && cashierLogin.includes('SELECT_TICKET_PREFIX') && cashierLogin.includes("'login_ticket'") && cashierLogin.includes("'need_select_store' => true")],
  ['login controller passes only the selection ticket and selected store back to the server', loginController.includes("['login_ticket', '']") && loginController.includes('(string)$ticket')],
  ['organization entry presents multiple data-scope stores then binds one selected store', cashierLogin.includes('organizationLoginCandidates') && cashierLogin.includes('count($organization) > 1') && cashierLogin.includes('issueSelectedStore')],
  ['organization selection tree is limited to candidate stores and their necessary organization ancestors', cashierLogin.includes("'organization_tree' => $this->presentOrganizationTree($organization)") && cashierLogin.includes('private function presentOrganizationTree') && cashierLogin.includes('不下发该组织下其他未获授权门店')],
  ['data-scope store uses a dedicated organization session instead of restoring staff tenure', cashierLogin.includes('cashier_v3_store_session') && cashierLogin.includes('issueOrganizationStore') && cashierLogin.includes("'_cashier_v3_organization'")],
  ['store switch recomputes direct-tenure and organization candidates and issues only an authorized target session', cashierLogin.includes('public function switchableStores') && cashierLogin.includes('public function switchStore') && cashierLogin.includes('private function storeSwitchCandidates') && (cashierLogin.match(/\$this->storeSwitchCandidates\(\$employeeId\)/g) || []).length === 2 && cashierLogin.includes('$targetAllowed') && cashierLogin.includes('$currentAllowed') && cashierLogin.includes('return $this->issueSelectedStore($employeeId, $targetStoreId, 0, $stores);')],
  ['direct tenure is checked before any delegated read-only fallback', cashierLogin.includes('resolveUniqueStoreV3Staff($employeeId)') && cashierLogin.includes('activeTenureCount')],
  ['organization session is normal-mode and derives current enabled store-v3 features from the organization position', cashierLogin.includes("'session_mode' => 'store_organization'") && cashierLogin.includes("'read_only' => false") && featureResolver.includes("'_cashier_v3_organization'") && featureResolver.includes('employeeStoreV3GrantedFeatures') && featureResolver.includes("->where('position.status', 1)->where('position.use_store', 1)")],
  ['legacy delegated sessions remain read-only', dataScopeFactory.includes("!empty($operatorProfile['_cashier_v3_delegated'])") && dataScopeFactory.includes('$readOnlySession') && dataScopeContext.includes('isReadOnlySession')],
  ['legacy delegated commands remain denied except the trusted mobile reservation gate', permissionGuard.includes("$dataScope->isReadOnlySession()") && permissionGuard.includes("($definition['type'] ?? '') === 'command'") && permissionGuard.includes('isTrustedMobileReservationCommand') && permissionGuard.includes("store_read_only_session")],
  ['direct V3 write routes still deny legacy delegated sessions while the organization marker resolves normal features', cashierRoleMiddleware.includes("!empty($cashierInfo['_cashier_v3_delegated'])") && cashierRoleMiddleware.includes("!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)") && cashierRoleMiddleware.includes("$isLogout") && cashierLogin.includes("'cashier_v3_organization'")],
]

let failed = 0
for (const [name, passed] of assertions) {
  process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${name}\n`)
  if (!passed) failed += 1
}
process.stdout.write(`ASSERT_FAILED=${failed}\n`)
process.exit(failed === 0 ? 0 : 1)
