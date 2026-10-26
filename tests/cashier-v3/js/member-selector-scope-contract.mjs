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
  ['scope toggle is enabled for cashier, reservation and refund selectors', shell.includes(':show-scope-toggle="[\'cashier\', \'reservation\', \'imported-card-refund\'].includes(memberSelectorContext)"')],
  ['refund scope follows and aligns beside the search field without moving other selectors', overlay.indexOf('class="member-selector__search-field"') < overlay.indexOf('v-if="showScopeToggle" class="member-selector__scope-toggle"') && overlay.includes("'member-selector__search--scope-right': alignScopeRight") && shell.includes(':align-scope-right="memberSelectorContext === \'imported-card-refund\'"') && !shell.includes('scope-toggle-before-search')],
  ['refund keeps order-center authorization while preserving its own selector context', shell.includes("? 'imported-card-refund'") && shell.includes("selectorContext === 'imported-card-refund' ? 'order_center' : selectorContext")],
  ['backend defaults explicitly scoped selectors to current store', module.includes('$supportsExplicitStoreScope') && module.includes("['cashier', 'reservation', 'imported-card-refund']") && module.includes('$isAllScope') && module.includes('self::currentStoreIds(')],
  ['refund all scope preserves its existing order-center cross-store search', module.includes("$selectorContext !== 'member-referrer'") && module.includes('(!$supportsExplicitStoreScope || $isAllScope)') && shell.includes("selectorContext === 'imported-card-refund' ? 'order_center' : selectorContext")],
  ['refund retains its former keyword hint and cancelled-member handling', overlay.includes('showScopeToggle && showNameInitialsHint') && shell.includes(':show-name-initials-hint="memberSelectorContext !== \'imported-card-refund\'"') && module.includes("$supportsNameInitials = in_array($selectorContext, ['cashier', 'reservation'], true)")],
  ['member selector all scope remains explicitly opt-in', module.includes("'memberScope' => 'all'") || module.includes("$payload['memberScope']")],
]

let failed = 0
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}
process.exit(failed ? 1 : 0)
