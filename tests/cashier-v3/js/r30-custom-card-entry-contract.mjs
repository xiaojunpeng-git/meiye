import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const workbench = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')

assert.match(workbench, /if \(type === '定制卡'\) \{[\s\S]*?selectCatalogItem\(\{ id: 'custom-card-entry' \}\)[\s\S]*?return/, '点击定制卡类型必须直接进入创建流程')
assert.doesNotMatch(workbench, /items\.push\(customCardEntry\)/, '定制卡不得再伪装成需要二次点击的目录商品')
// R50 replaces the obsolete clear-cart flow: normal sale rows and custom rows
// now share one final snapshot and are independently bound during issuance.
assert.doesNotMatch(workbench, /isCustomCardConflictOpen|continueCustomCard|清空并继续/, '进入定制卡不得清空已有购物车')
assert.doesNotMatch(workbench, />先挂当前订单<\/button>/, '定制卡冲突弹窗不得保留挂单入口')
assert.match(workbench, /pendingCustomCardEntry\.value = true[\s\S]*?cashier-v3:open-member-selector/, '游客点击定制卡时必须在选中会员后续接创建流程')
assert.match(workbench, /const preservePending = pendingCustomCardEntry\.value \|\| shouldPreservePendingEntitlementSelector/, '会员上下文切换不得清掉定制卡待办意图')

console.log('R30_CUSTOM_CARD_ENTRY_CONTRACT=PASS')
