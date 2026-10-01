import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const service = read('后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php')
const controller = read('后端代码/app/controller/cashier/v3/StoreLogin.php')
const routes = read('后端代码/route/cashier-v3.php')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const api = read('前端代码/cashier-v3/src/services/storeV3LoginApi.js')

// 切店不是客户端改 store_id：候选、目标与新会话都必须由服务端按登录口径确认。
const assertions = [
  ['only authenticated sessions expose the candidates and switch routes', routes.includes("Route::get('session/stores', 'StoreLogin/switchableStores')") && routes.includes("Route::post('session/switch-store', 'StoreLogin/switchStore')") && routes.indexOf("Route::get('session/stores'") < routes.indexOf('AuthTokenMiddleware::class')],
  ['switch target is separate from enforced business store_id', controller.includes("$request->post('target_store_id', 0)") && api.includes('target_store_id: Number(targetStoreId || 0)') && !api.includes("{ store_id: Number(targetStoreId")],
  ['login keeps direct tenure while switching adds only separately authorized organization stores', service.includes('private function storeSwitchCandidates') && service.includes('return $this->eligibleStores($employeeId);') && service.includes('return $this->organizationLoginCandidates($employeeId);') && (service.match(/\$this->storeSwitchCandidates\(\$employeeId\)/g) || []).length === 2 && service.includes('count($stores) < 2') && service.indexOf('if ($direct)') < service.indexOf('private function storeSwitchCandidates')],
  ['server validates both current and destination before issuing a new session', service.includes('$currentAllowed') && service.includes('$targetAllowed') && service.includes('if (!$currentAllowed || !$targetAllowed || count($stores) < 2)') && service.includes('return $this->issueSelectedStore($employeeId, $targetStoreId, 0, $stores);')],
  ['multi-store UI reuses login organization picker and single-store UI stays unchanged', shell.includes('v-if="canSwitchStores"') && shell.includes('v-else class="cashier-brand"') && shell.includes('<OrganizationStoreScopePicker') && shell.includes(':single-store-only="true"')],
  ['client changes root context only after the new session and restores the old token on failure', shell.includes('persistStoreV3Token(nextToken)') && shell.includes("onCashierStoreOrAccountChanged({ reason: 'store', storeId, silent: true })") && shell.includes('persistStoreV3Token(previousToken)') && shell.includes('logoutStoreV3(previousToken)')],
  ['old inventory token and temporary selection are cleared on context change', shell.includes('isInventoryWorkspaceOpen.value = false') && shell.includes('inventorySessionToken.value = readStoreV3SessionToken()') && shell.includes('toolbarBusinessSource.value = { displayNameSnapshot:')]
]

let failed = 0
for (const [name, passed] of assertions) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}
console.log(`ASSERT_FAILED=${failed}`)
process.exit(failed ? 1 : 0)
