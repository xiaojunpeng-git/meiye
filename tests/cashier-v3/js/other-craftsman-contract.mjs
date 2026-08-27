import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.cwd())
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const selector = read('后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php')
const workspace = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php')
const completion = read('后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php')
const rebuilder = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php')

const checks = [
  ['入口文案为添加其他手艺人', overlay.includes('添加其他手艺人') && !overlay.includes('添加其他人员')],
  ['入口使用独立搜索 scope', overlay.includes("scope: 'cashier_other_craftsmen'")],
  ['收银工作台传递其他手艺人候选', workbench.includes(':other-craftsman-candidates="personnelOverlay.otherCraftsmanCandidates"')],
  ['后端声明其他手艺人选择 scope', selector.includes("'cashier_other_craftsmen' => false")],
  ['其他手艺人查询不依赖资格开关', selector.includes("$requiresCraftsmanEligibility = $selectorScope === 'service_actual_craftsmen'")],
  ['结账保留门店和在职校验但不再校验资格开关', workspace.includes("->where('ss.store_id', $operatorScope->storeId())") && completion.includes("$row['active'] !== true || $row['storeId'] !== $storeId")],
  ['权益快照只提交紧凑手艺人字段', workbench.includes('function canonicalCheckoutEntitlementCraftsmen(records = [])') && workbench.includes('craftsmen: entitlementLine\n              ? canonicalCheckoutEntitlementCraftsmen(craftsmen)')],
  ['权益快照保留其他手艺人来源标记', rebuilder.includes("'personnelSource',") && rebuilder.includes("$assignment['personnelSource'] = 'other'")]
]

for (const [label, ok] of checks) {
  if (!ok) throw new Error(`contract failed: ${label}`)
}
console.log('other craftsman contract ok')
