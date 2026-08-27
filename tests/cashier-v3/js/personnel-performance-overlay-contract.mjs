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
  path.join(root, '后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php'),
  'utf8'
)
const selector = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php'),
  'utf8'
)
const craftsmanSnapshot = fs.readFileSync(
  path.join(root, '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php'),
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
assert.match(component, />导购<\/button>/, '完整模式必须提供独立导购角色页签')
assert.match(component, />销售经理<\/button>/, '完整模式必须提供独立销售经理角色页签')
assert.match(component, /查询导购/, '导购必须提供独立查询入口')
assert.match(component, /查询销售经理/, '销售经理必须提供独立查询入口')
assert.doesNotMatch(component, /@click="openAttributionSearch">查找人员/, '不得继续使用合并的查找人员按钮')
assert.match(component, /attributionSearchOpen/, '集团人员搜索必须在独立查找弹窗中进行')
assert.match(component, />加入导购<\/button>/, '搜索结果必须可逐人加入导购')
assert.match(component, />设为销售经理<\/button>/, '搜索结果必须可逐人设为销售经理')
assert.match(component, /function addGuide\(item\)[\s\S]*guide\.selected = true/, '导购必须支持多次搜索后持续追加')
assert.match(component, /function mergeWithLocalSelections\(candidates, selected, currentRecords, role\)/, '搜索新候选时必须合并并保留已添加的导购')
assert.match(component, /function setSalesManager\(item\)[\s\S]*salesManagers\.value\.forEach/, '销售经理设置必须覆盖上一位人员')
assert.doesNotMatch(component, /v-else class="personnel-group-search"/, '导购和销售经理外层不得保留搜索框')
assert.match(component, /salesManagers\.value\.forEach\(\(record\) => \{ record\.selected = false \}\)/, '销售经理选择第二人时必须替换前一人')
assert.match(component, /guideSelections: selectedGuidePayload/, '导购选择只提交归属快照，不提交业绩比例')
assert.match(component, /salesManagerSelections: selectedSalesManagerPayload/, '销售经理选择只提交归属快照，不提交业绩比例')
assert.match(component, /search-personnel/, '集团人员必须通过显式关键词搜索事件加载')
assert.match(component, /attributionSearchRole.value === 'salesManager'/, '查询弹窗必须按导购和销售经理角色切换')
assert.match(component, /target: attributionSearchRole.value/, '查询结果必须回填到当前归属角色')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">点客/, '手艺人必须支持点客标记')
assert.match(component, /setMarked\(item, \$event\.target\.checked\)">售前/, '销售人必须支持售前标记')
assert.match(component, /function setMarked\(item, checked\)\s*\{[\s\S]*item\.marked = checked/, '同一明细允许多名销售人分别标记售前')
assert.doesNotMatch(component, /if \(item\.role === 'salespeople' && checked\)[\s\S]*salespeople\.value\.forEach/, '售前标记不得强制清除其他销售人')
assert.match(component, /item\.role === 'salespeople' && checked && !item\.selected/, '售前复选框可直接加入未选中的销售人')
assert.doesNotMatch(component, /<input :checked="item\.marked" type="checkbox" :disabled="!item\.selected"[^>]*>售前/, '售前复选框不得因未先点整行而禁用')
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
assert.match(workbench, /const showSalespeople = roleScope === 'personnel' && !isEntitlementLine\(line\)/, '权益行不得加载销售人候选')
assert.match(workbench, /initialTab,\n\s+roleScope,/, '人员弹窗必须保留入口对应的角色范围')
assert.match(workbench, /async function openCartLineAttributions\(line\)/, '购物车必须提供导购/销售经理统一入口')
assert.match(workbench, /class="cart-line__meta-slot cart-line__meta-slot--attribution"/, '购物车必须展示导购/销售经理按钮')
assert.match(workbench, /loadPersonnelOverlay\(line, 'guides', 'attribution'\)/, '统一入口必须打开导购/销售经理选择范围')
assert.match(workbench, /\['group_attributions', 'cashier_other_craftsmen'\]\.includes\(scope\)/, '导购/销售经理必须共用一个集团搜索范围')
assert.match(workbench, /target === 'salesManager'[\s\S]*salesManagerCandidates: records/, '销售经理查询结果必须回填销售经理列表')
assert.match(workbench, /target === 'guide'[\s\S]*guideCandidates: records/, '导购查询结果必须回填导购列表')
assert.match(workbench, /const showGuides = !isEntitlementLine\(line\) && \['guide', 'attribution'\]/, '统一入口必须显示导购候选')
assert.match(workbench, /const showSalesManagers = !isEntitlementLine\(line\) && \['salesManager', 'attribution'\]/, '统一入口必须显示销售经理候选')
assert.match(workbench, /导购\/销售经理<span/, '未选择时入口不得显示待选择文案')
assert.match(workbench, /v-if="!isEntitlementLine\(line\)" class="cart-line__meta-slot cart-line__meta-slot--salesperson"/, '权益行不得展示销售人入口')
assert.match(workbench, /if \(!isEntitlementLine\(line\)\) payload\.salespeople = salespeople/, '权益行确认载荷不得携带销售人')
assert.match(
  workbench,
  /craftsmanPerformanceType: record\.craftsmanPerformanceType \|\| record\.craftsman_performance_type,[\s\S]*laborFeeCents: Number\(record\.laborFeeCents \?\? record\.labor_fee_cents \?\? 0\)/,
  '确认手艺人分配时不得丢失服务业绩类型和每人手工费'
)
assert.match(
  workbench,
  /appendLocalCashierDraftOperation\(\{ action: 'apply-cashier-personnel-to-all-lines', payload \}/,
  '应用全部人必须把双角色人员意图写入同一份本地结账快照'
)
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
assert.match(workspace, /'laborWeight' => \$effectiveWeight/, '手艺人比例必须写入权威购物车草稿')
assert.match(workspace, /'craftsmanPerformanceType' => \$type/, '手艺人服务业绩类型必须写入权威购物车草稿')
assert.match(workspace, /'laborFeeCents' => \$laborFeeCents/, '手工费必须按每人金额写入权威购物车草稿')
assert.match(completionAuthority, /'laborWeight' => \(int\)\(\$settings\['laborWeight'\]/, '结账权威快照必须继续使用购物车手艺人比例')
assert.match(workspace, /if \(\$isEntitlement && \(\$hasSalespeople \|\| \$hasGuides \|\| \$hasSalesManagers\)\)/, '事务服务必须拒绝权益行销售人及集团归属写入')
assert.match(workspace, /sales_manager_selections_json/, '工作台必须持久化销售经理选择快照')
assert.match(workspace, /count\(\$ids\) > 1[\s\S]*sales_manager_selection_limit_exceeded/, '服务端必须拒绝多名销售经理')
assert.match(cashierModule, /'reason' => 'entitlement_salespeople_forbidden'/, '命令策略必须拒绝构造的权益销售人请求')
assert.match(cashierModule, /'apply-cashier-craftsmen-to-all-service-lines'/, '后端命令模块必须登记手艺人应用全部动作')
assert.match(cashierModule, /registerCommand\('apply-cashier-personnel-to-all-lines'[\s\S]*applyCraftsmenToAllServiceLinesInTx[\s\S]*applySalespeopleToAllSaleLinesInTx/, '后端统一命令必须在同一事务中依次应用手艺人和销售人')
assert.match(workspace, /\$salespeople = \$lineRole === self::ROLE_SALE[\s\S]*: \[\];/, '权益草稿投影不得加载或展示销售人快照')
assert.match(
  workspace,
  /if \(\$isSale && !\$isSaleProject\) \{[\s\S]*\$hasGuides[\s\S]*authoritativeGuideSelectionsInTx[\s\S]*guide_selections_json/,
  '产品和普通卡项必须持久化导购归属快照'
)
assert.match(
  cashierModule,
  /\$hasAttributions = array_key_exists\('guideSelections', \$payload\)[\s\S]*?!\$hasSalespeople && !\$hasAttributions && !\$hasInventoryRule/,
  '产品和普通卡项的命令策略必须允许导购和销售经理归属保存'
)
assert.doesNotMatch(component, /本次手工费/, '完整模式不再显示独立的本次临时手工费说明')
assert.match(component, /<b>元\/次<\/b>/, '完整模式手工费单位必须显示为元\/次')
assert.doesNotMatch(component, /<b>元\/人<\/b>/, '完整模式不得继续显示元\/人')
assert.match(component, /personnel-allocation-input--labor/, '手工费输入格必须使用独立紧凑样式')
assert.match(component, /laborDefaultFee/, '手工费默认值必须接收项目固定手工费')
assert.match(workbench, /projectId: line\.projectId/, '人员候选查询必须携带当前项目 ID')
assert.match(component, /candidateDefault/, '手艺人候选返回的项目固定手工费必须作为默认值')
assert.match(selector, /labor_configured_unit_amount_cents/, '手艺人候选必须读取项目固定手工费')
assert.match(selector, /where\('tenant_id', \$dataScope->tenantId\(\)\)/, '候选手工费读取必须限定当前租户')
assert.match(
  workspace,
  /private function projectLaborDefaultCents\(int \$projectId\)[\s\S]*?where\('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID\)/,
  '项目固定手工费读取必须限定当前租户，不能被其他租户同项目配置覆盖'
)
assert.match(
  workspace,
  /private function laborProjectIdFromLine\(array \$line\)[\s\S]*catalog_product_id/,
  '历史项目行缺少 project_id 时必须用项目产品 ID 回读固定手工费'
)
assert.doesNotMatch(component, /laborFeeDirty\.value/, '完整模式不再提交独立的临时手工费覆盖值')
assert.match(component, /服务业绩类型/, '完整模式必须展示手艺人服务业绩类型')
assert.match(component, /手工费/, '完整模式必须固定展示手工费栏')
assert.match(craftsmanSnapshot, /craftsmanPerformanceType/, '结账快照必须保留手艺人服务业绩类型')
assert.match(craftsmanSnapshot, /laborFeeCents/, '结账快照必须保留每人手工费')
assert.match(workbench, /laborManualFee: line\.laborManualFee/, '工作台必须回读本次手工费快照')
assert.match(workbench, /payload\.laborManualFee = Number\(result\.laborManualFee\)/, '工作台必须把临时手工费写入权威草稿')
assert.match(workspace, /manual_labor_fee_cents/, '后端工作台必须持久化临时手工费快照')

console.log('PASS personnel-performance-overlay-contract')
