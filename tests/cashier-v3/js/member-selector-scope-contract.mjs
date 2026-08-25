import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const module = read('后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php')

const checks = [
  ['cashier selector shows store/all scope radios', overlay.includes('showScopeToggle') && overlay.includes("value=\"store\"") && overlay.includes("value=\"all\"")],
  ['cashier selector defaults to store and sends scope on every query', overlay.includes("const memberScope = ref('store')") && overlay.includes('query.memberScope = memberScope.value')],
  ['scope changes reset to first page and query immediately', overlay.includes('memberScope.value = nextScope') && overlay.includes('runQuery(1)')],
  ['scope toggle is limited to cashier selector', shell.includes(':show-scope-toggle="memberSelectorContext === \'cashier\'"')],
  ['backend defaults cashier selector to current store', module.includes('$isCashierSelector') && module.includes('$isAllScope') && module.includes('self::currentStoreIds(')],
  ['member selector all scope remains explicitly opt-in', module.includes("'memberScope' => 'all'") || module.includes("$payload['memberScope']")],
]

let failed = 0
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}
process.exit(failed ? 1 : 0)
