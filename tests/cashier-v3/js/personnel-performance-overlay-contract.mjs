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
const bridge = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'),
  'utf8'
)
const completionAuthority = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionAuthorityAdapter.php'),
  'utf8'
)
const idempotencyKeys = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/CashierV3IdempotencyKeyServices.php'),
  'utf8'
)

assert.match(component, /mode === 'simple'/, '统一弹窗必须保留简易选择模式')
assert.match(component, /mode === 'full'/, '统一弹窗必须保留完整分配模式')
assert.match(component, />手艺人<\/button>/, '完整模式必须可切换手艺人')
assert.match(component, />销售人<\/button>/, '完整模式必须可切换销售人')
assert.match(component, />导购<\/button>/, '完整模式必须可切换集团导购')
assert.match(component, />销售经理<\/button>/, '完整模式必须可切换集团销售经理')
assert.match(component, /guideSelections: selectedAttributionPayload/, '导购选择只提交归属快照，不提交业绩比例')
assert.match(component, /salesManagerSelections: selectedAttributionPayload/, '销售经理选择只提交归属快照，不提交业绩比例')
assert.match(component, /search-personnel/, '集团人员必须通过显式关键词搜索事件加载')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">点客/, '手艺人必须支持点客标记')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">售前/, '销售人必须支持售前标记')
assert.match(component, /salespeople\.value\.forEach/, '同一明细只能保留一个售前销售人')
assert.match(component, /合计为 100%/, '完整分配必须校验比例合计')
assert.match(component, /if \(props\.showSalespeople\) \{[\s\S]*assignment\.salespeople/, '未启用销售人时确认事件不得携带销售人')
assert.equal((component.match(/>应用全部人<\/button>/g) || []).length, 1, '应用全部人只能位于底部操作区')
assert.match(component, /saving: \{ type: Boolean, default: false \}/, '人员弹窗必须接收保存中状态')
assert.match(component, /:disabled="loading \|\| saving \|\| Boolean\(loadError\)"/, '保存中必须禁用确认与应用全部人')
assert.equal((component.match(/v-for="item in selectedRecords"/g) || []).length, 1, '完整分配列表只能渲染一层员工循环')
assert.match(component, /craftsmen: selectedCraftsmenPayload\(\)[\s\S]*salespeople: selectedSalespersonPayload\(\)/, '应用全部必须同时发送当前手艺人和销售人分配')
assert.match(component, /selectedCraftsmen\.length && selectedSalespeople\.length[\s\S]*\? 'personnel'/, '双角色均已选择时必须提交统一人员意图')
assert.doesNotMatch(component, /records\.forEach\(\(item\) => \{ item\.selected = true \}\)/, '应用全部人不得全选员工')

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
assert.match(workbench, /requestAction\('apply-cashier-personnel-to-all-lines',[\s\S]*craftsmen,[\s\S]*salespeople,/, '应用全部人必须一次提交双角色权威事务命令')
assert.match(
  idempotencyKeys,
  /'CASHIER_APPLY_PERSONNEL_ALL'/,
  '双角色应用全部的请求标识前缀必须在后端登记'
)
assert.match(workbench, /const isSavingPersonnelAssignment = ref\(false\)/, '人员写操作必须共享互斥状态')
assert.match(workbench, /async function confirmPersonnelAssignment[\s\S]*if \(isSavingPersonnelAssignment\.value\) return/, '单行确认必须拒绝重复提交')
assert.match(workbench, /async function applyPersonnelAssignmentToAll[\s\S]*if \(isSavingPersonnelAssignment\.value\) return/, '应用全部人必须拒绝与确认并发提交')
assert.match(workbench, /:saving="isSavingPersonnelAssignment"/, '人员弹窗必须展示权威保存中状态')
assert.match(
  bridge,
  /'apply-cashier-personnel-to-all-lines'[\s\S]*'update-cashier-order-note'[\s\S]*'update-cashier-line-price'[\s\S]*'update-cashier-supplement'/,
  '统一人员与更多操作写命令必须自动携带当前收银工作台版本'
)
assert.match(workspace, /function applySalespeopleToAllSaleLinesInTx\(/, '后端必须在同一事务中更新全部本次购买明细')
assert.match(workspace, /function applyCraftsmenToAllServiceLinesInTx\([\s\S]*?\$isSaleProject[\s\S]*?\$isEntitlementService[\s\S]*?'craftsmen_json'/, '后端必须在同一事务中更新项目和权益服务行的手艺人')
assert.match(workspace, /'laborWeight' => \$weights\[\$staffId\]/, '手艺人比例必须写入权威购物车草稿')
assert.match(completionAuthority, /'laborWeight' => \(int\)\(\$settings\['laborWeight'\]/, '结账权威快照必须继续使用购物车手艺人比例')
assert.match(workspace, /if \(\$isEntitlement && \(\$hasSalespeople \|\| \$hasGuides \|\| \$hasSalesManagers\)\)/, '事务服务必须拒绝权益行销售人及集团归属写入')
assert.match(workspace, /sales_manager_selections_json/, '工作台必须持久化销售经理选择快照')
assert.match(cashierModule, /'reason' => 'entitlement_salespeople_forbidden'/, '命令策略必须拒绝构造的权益销售人请求')
assert.match(cashierModule, /'apply-cashier-craftsmen-to-all-service-lines'/, '后端命令模块必须登记手艺人应用全部动作')
assert.match(cashierModule, /registerCommand\('apply-cashier-personnel-to-all-lines'[\s\S]*applyCraftsmenToAllServiceLinesInTx[\s\S]*applySalespeopleToAllSaleLinesInTx/, '后端统一命令必须在同一事务中依次应用手艺人和销售人')
assert.match(workspace, /\$salespeople = \$lineRole === self::ROLE_SALE[\s\S]*: \[\];/, '权益草稿投影不得加载或展示销售人快照')
assert.match(workspace, /if \(\$isSale && !\$isSaleProject\) \{[\s\S]*\$hasSalespeople/, '产品和普通卡项仍只允许销售人')

console.log('PASS personnel-performance-overlay-contract')
