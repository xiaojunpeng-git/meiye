import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.cwd())
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const view = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const api = read('前端代码/cashier-v3/src/services/storeV3LoginApi.js')
const controller = read('后端代码/app/controller/cashier/v3/StoreLogin.php')
const accounts = read('后端代码/app/services/employee/EmployeeInternalAccountServices.php')
const rootAssembler = read('后端代码/app/services/cashier/v3/projection/CashierV3RootDomainAssembler.php')

const checks = [
  ['弹窗包含登录账号字段', view.includes('v-model.trim="passwordChange.account"')],
  ['前端提交账号和密码', api.includes('account: String(account ||')],
  ['控制器接收账号字段', controller.includes("['current_password', ''], ['account', ''], ['new_password', '']")],
  ['服务端执行账号唯一性检查', accounts.includes('changeOwnCredentials') && accounts.includes('该登录账号已被其他员工使用')],
  ['根投影提供当前账号用于回显', rootAssembler.includes("'account' => (string)($dataScope->operatorProfile()['account'] ?? '')")]
]

for (const [label, ok] of checks) {
  if (!ok) throw new Error(`contract failed: ${label}`)
}
console.log('account credentials contract ok')
