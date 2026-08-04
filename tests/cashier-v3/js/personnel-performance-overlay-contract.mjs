import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const component = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'),
  'utf8'
)
const workbench = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)
const workspace = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php'),
  'utf8'
)
const cashierModule = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'),
  'utf8'
)
const completionAuthority = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionAuthorityAdapter.php'),
  'utf8'
)

assert.match(component, /mode === 'simple'/, '统一弹窗必须保留简易选择模式')
assert.match(component, /mode === 'full'/, '统一弹窗必须保留完整分配模式')
assert.match(component, />手艺人<\/button>/, '完整模式必须可切换手艺人')
assert.match(component, />销售人<\/button>/, '完整模式必须可切换销售人')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">点客/, '手艺人必须支持点客标记')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">售前/, '销售人必须支持售前标记')
assert.match(component, /salespeople\.value\.forEach/, '同一明细只能保留一个售前销售人')
assert.match(component, /合计为 100%/, '完整分配必须校验比例合计')
assert.match(component, /if \(props\.showSalespeople\) \{[\s\S]*assignment\.salespeople/, '未启用销售人时确认事件不得携带销售人')

assert.match(workbench, /queryPersonnelCandidates\('service_actual_craftsmen'/, '手艺人必须读取当前门店权威选择源')
assert.match(workbench, /queryPersonnelCandidates\('sales_performance_assignees'/, '销售人必须读取当前门店权威选择源')
assert.match(workbench, /Promise\.all\(\[/, '项目双角色候选应在同一弹窗加载')
assert.match(
  workbench,
  /mutateCashierDraft\('update-cart-line-service-settings', line, payload\)/,
  '人员确认必须一次写回购物车权威草稿'
)
assert.match(
  workbench,
  /let savedResult = await mutateCashierDraft\('update-cart-line-service-settings', line, payload\)[\s\S]*?resultStatus\(savedResult\) === 'result_unknown'[\s\S]*?const recovered = await recoverPendingDraftCommand\(\)[\s\S]*?savedResult = await mutateCashierDraft\('update-cart-line-service-settings', line, payload\)/,
  '旧草稿结果未知时，人员确认必须自动恢复原命令后继续保存本次分配'
)
assert.doesNotMatch(workbench, /SalespersonAllocationOverlay/, '不得恢复通用选择器后的二次比例弹窗')
assert.doesNotMatch(workbench, /openCashierV3QueryEntitySelector/, '购物车人员入口不得再打开通用人员选择器')
assert.match(workbench, /const showSalespeople = !isEntitlementLine\(line\)/, '权益行不得加载销售人候选')
assert.match(workbench, /initialTab: showSalespeople \? initialTab : 'craftsmen'/, '权益行完整模式必须固定停留在手艺人页签')
assert.match(workbench, /v-if="!isEntitlementLine\(line\)" class="cart-line__meta-slot cart-line__meta-slot--salesperson"/, '权益行不得展示销售人入口')
assert.match(workbench, /if \(!isEntitlementLine\(line\)\) payload\.salespeople = salespeople/, '权益行确认载荷不得携带销售人')
assert.match(workspace, /'laborWeight' => \$weights\[\$staffId\]/, '手艺人比例必须写入权威购物车草稿')
assert.match(completionAuthority, /'laborWeight' => \(int\)\(\$settings\['laborWeight'\]/, '结账权威快照必须继续使用购物车手艺人比例')
assert.match(workspace, /if \(\$isEntitlement && \$hasSalespeople\)/, '事务服务必须拒绝权益行销售人写入')
assert.match(cashierModule, /'reason' => 'entitlement_salespeople_forbidden'/, '命令策略必须拒绝构造的权益销售人请求')
assert.match(workspace, /\$salespeople = \$lineRole === self::ROLE_SALE[\s\S]*: \[\];/, '权益草稿投影不得加载或展示销售人快照')
assert.match(workspace, /if \(\$isSale && !\$isSaleProject\) \{[\s\S]*\$hasSalespeople/, '产品和普通卡项仍只允许销售人')

console.log('PASS personnel-performance-overlay-contract')
