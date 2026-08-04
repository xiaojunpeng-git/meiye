#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath, pathToFileURL } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const frontend = path.join(repo, '前端代码/cashier-v3')
const contractPath = path.join(frontend, 'src/services/cashierV3EntitlementDraftContract.js')
const recoveryPath = path.join(frontend, 'src/services/cashierV3DraftCommandRecovery.js')
const workbenchPath = path.join(frontend, 'src/views/CashierWorkbenchView.vue')
const selectorPath = path.join(frontend, 'src/components/cashier/EntitlementSelectorOverlay.vue')
const projectSelectorPath = path.join(frontend, 'src/components/writeoff/EntitlementProjectSelector.vue')
const shellPath = path.join(frontend, 'src/layouts/CashierShell.vue')
const fixturePath = path.join(frontend, 'src/dev/pdV3FixtureMain.js')
const stylesPath = path.join(frontend, 'src/styles/base.css')
const actionManifestPath = path.join(frontend, 'src/services/cashierV3ActionManifest.js')
const bridgePath = path.join(frontend, 'src/services/cashierV3Bridge.js')
const serviceCompletionPath = path.join(frontend, 'src/components/service/ServiceCompletionOverlay.vue')
const writeoffConfirmationPath = path.join(frontend, 'src/components/writeoff/WriteoffConfirmationOverlay.vue')
const writeoffWorkbenchPath = path.join(frontend, 'src/views/WriteoffWorkbenchView.vue')
const replacementPath = path.join(frontend, 'src/views/ProjectReplacementView.vue')
const checkoutOverlayPath = path.join(frontend, 'src/components/cashier/CashierCheckoutOverlay.vue')

let passed = 0
let failed = 0
function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    console.log(`  PASS  ${name}`)
  } else {
    failed += 1
    console.log(`  FAIL  ${name}${detail ? ` -> ${detail}` : ''}`)
  }
}

const contract = await import(pathToFileURL(contractPath).href)
const recoveryContract = await import(pathToFileURL(recoveryPath).href)

console.log('== C2 entitlement canonical contexts ==')
const suppliedContexts = [
  { kind: 'member_benefit_pool', id: 'pool-2', expectedVersion: 22 },
  { kind: 'cashier_workspace', id: 'workspace-1', expectedVersion: 3 },
  { kind: 'card_holder', id: 'holder-unused', expectedVersion: 99 },
  { kind: 'entitlement_instance', id: 'legacy-forbidden', expectedVersion: 1 },
  { kind: 'member', id: 'member-1', expectedVersion: 7 },
  { kind: 'card_holder', id: 'holder-1', expectedVersion: 11 },
  { kind: 'member_benefit_pool', id: 'pool-unused', expectedVersion: 100 },
  { kind: 'member_benefit_pool', id: 'pool-1', expectedVersion: 21 }
]
const selectedLines = [
  { cardHolderId: 'holder-1', memberBenefitPoolId: 'pool-2' },
  { cardHolderId: 'holder-1', memberBenefitPoolId: 'pool-1' }
]
const canonical = contract.canonicalEntitlementCommandContexts({
  suppliedContexts,
  selectedLines,
  workspaceId: 'workspace-1',
  memberId: 'member-1'
})
ok('只保留四类规范资源', canonical?.every((row) => [
  'cashier_workspace', 'member', 'card_holder', 'member_benefit_pool'
].includes(row.kind)), JSON.stringify(canonical))
ok('只锁本次选中的卡和权益池', JSON.stringify(canonical?.map((row) => `${row.kind}:${row.id}`)) === JSON.stringify([
  'cashier_workspace:workspace-1',
  'member:member-1',
  'card_holder:holder-1',
  'member_benefit_pool:pool-1',
  'member_benefit_pool:pool-2'
]), JSON.stringify(canonical))
ok('禁止 entitlement_instance 进入命令', !canonical?.some((row) => row.kind === 'entitlement_instance'))
ok('缺会员版本 fail-closed', contract.canonicalEntitlementCommandContexts({
  suppliedContexts: suppliedContexts.filter((row) => row.kind !== 'member'),
  selectedLines,
  workspaceId: 'workspace-1',
  memberId: 'member-1'
}) === null)
ok('同资源冲突版本 fail-closed', contract.canonicalEntitlementCommandContexts({
  suppliedContexts: [...suppliedContexts, { kind: 'card_holder', id: 'holder-1', expectedVersion: 12 }],
  selectedLines,
  workspaceId: 'workspace-1',
  memberId: 'member-1'
}) === null)
ok('旧展示身份只兼容映射为规范资源', JSON.stringify(contract.canonicalEntitlementCommandContexts({
  suppliedContexts,
  selectedLines: [{ entitlementInstanceId: 'holder-1', entitlementSourceDetailId: 'pool-1' }],
  workspaceId: 'workspace-1',
  memberId: 'member-1'
})?.map((row) => row.kind)) === JSON.stringify([
  'cashier_workspace', 'member', 'card_holder', 'member_benefit_pool'
]))

console.log('== C2 action-bound response ==')
ok('读取顶层标准信封 data', contract.responseDataBlock({
  result: { status: 'success' },
  data: { cashierDraft: { memberId: 'member-1' } }
}).cashierDraft?.memberId === 'member-1')
ok('读取 HTTP 包装内标准信封 data', contract.responseDataBlock({
  data: { result: { status: 'success' }, data: { entitlementSelector: { ready: true } } }
}).entitlementSelector?.ready === true)
ok('不把任意 data 当可信标准信封', Object.keys(contract.responseDataBlock({ data: { cashierDraft: {} } })).length === 0)
const scopeA = contract.cashierEntitlementScopeKey({
  stateContextId: 'ctx-1', storeId: 'store-1', workspaceId: 'workspace-1', workspaceVersion: 3,
  customerMode: 'member', memberId: 'member-1'
})
const scopeB = contract.cashierEntitlementScopeKey({
  stateContextId: 'ctx-1', storeId: 'store-1', workspaceId: 'workspace-1', workspaceVersion: 3,
  customerMode: 'member', memberId: 'member-2'
})
ok('会员变化使局部快照范围失效', scopeA !== scopeB)

console.log('== C2 member selection intent continuity ==')
const guestCashierScope = {
  stateContextId: 'ctx-1',
  storeId: 'store-1',
  workspaceId: 'workspace-1',
  workspaceVersion: 7,
  customerMode: 'guest',
  memberId: ''
}
const selectedMemberCashierScope = {
  ...guestCashierScope,
  workspaceVersion: 8,
  customerMode: 'member',
  memberId: 'member-1'
}
ok('同工作台游客选择会员可跨工作台版本推进保留权益待打开意图', contract.shouldPreservePendingEntitlementSelector({
  pending: true,
  previous: guestCashierScope,
  current: selectedMemberCashierScope
}))
ok('没有待打开意图时不得因选择会员自行打开权益', !contract.shouldPreservePendingEntitlementSelector({
  pending: false,
  previous: guestCashierScope,
  current: selectedMemberCashierScope
}))
for (const identityKey of ['stateContextId', 'storeId', 'workspaceId']) {
  ok(`${identityKey}变化必须清除权益待打开意图`, !contract.shouldPreservePendingEntitlementSelector({
    pending: true,
    previous: guestCashierScope,
    current: { ...selectedMemberCashierScope, [identityKey]: `${selectedMemberCashierScope[identityKey]}-changed` }
  }))
}
ok('非游客来源不得借会员切换保留权益待打开意图', !contract.shouldPreservePendingEntitlementSelector({
  pending: true,
  previous: { ...guestCashierScope, customerMode: 'member', memberId: 'member-old' },
  current: selectedMemberCashierScope
}))
ok('未取得有效会员不得保留权益待打开意图', !contract.shouldPreservePendingEntitlementSelector({
  pending: true,
  previous: guestCashierScope,
  current: { ...selectedMemberCashierScope, memberId: '' }
}))

console.log('== C2 empty cart and checkout composition ==')
const emptyComposition = {
  lineRoles: [],
  hasSale: false,
  hasEntitlement: false,
  primaryAction: '',
  primaryActionLabel: '',
  steps: []
}
const saleLine = [{ id: 'sale-1', lineRole: 'sale' }]
const entitlementLine = [{ id: 'entitlement-1', lineRole: 'entitlement_service' }]
ok('合法空草稿合同可被完整接纳', contract.isCompleteCheckoutCompositionContract(emptyComposition, []))
ok('空草稿不得伪造收款动作', !contract.isCompleteCheckoutCompositionContract({
  ...emptyComposition,
  primaryAction: 'collect_payment',
  primaryActionLabel: '确认收款'
}, []))
ok('非空销售行不得声明空动作', !contract.isCompleteCheckoutCompositionContract(emptyComposition, saleLine))
ok('纯销售合同必须精确匹配 flags、动作、文案与步骤', contract.isCompleteCheckoutCompositionContract({
  lineRoles: ['sale'],
  hasSale: true,
  hasEntitlement: false,
  primaryAction: 'collect_payment',
  primaryActionLabel: '确认收款',
  steps: [
    { key: 'order', number: 1, label: '确认订单' },
    { key: 'payment', number: 2, label: '收款信息' },
    { key: 'final', number: 3, label: '确认收款' },
    { key: 'result', number: 4, label: '处理结果' }
  ]
}, saleLine))
ok('纯权益不得被伪装成完成服务以外的动作', !contract.isCompleteCheckoutCompositionContract({
  lineRoles: ['entitlement_service'],
  hasSale: false,
  hasEntitlement: true,
  primaryAction: 'collect_payment',
  primaryActionLabel: '确认收款',
  steps: [
    { key: 'order', number: 1, label: '确认本次内容' },
    { key: 'final', number: 3, label: '确认收款' },
    { key: 'result', number: 4, label: '处理结果' }
  ]
}, entitlementLine))
ok('重复或乱序步骤不可通过完整合同', !contract.isCompleteCheckoutCompositionContract({
  lineRoles: ['sale'],
  hasSale: true,
  hasEntitlement: false,
  primaryAction: 'collect_payment',
  primaryActionLabel: '确认收款',
  steps: [
    { key: 'order', number: 1, label: '确认订单' },
    { key: 'order', number: 1, label: '确认订单' },
    { key: 'result', number: 4, label: '处理结果' }
  ]
}, saleLine))

console.log('== C2 same-idempotency draft recovery ==')
const recoveryStorageState = new Map()
const recoveryStorage = {
  getItem(key) {
    return recoveryStorageState.has(key) ? recoveryStorageState.get(key) : null
  },
  setItem(key, value) {
    recoveryStorageState.set(key, String(value))
  },
  removeItem(key) {
    recoveryStorageState.delete(key)
  }
}
let recoveryKeySequence = 0
const makeRecovery = () => recoveryContract.createCashierV3DraftCommandRecovery(
  () => `RECOVERY-${++recoveryKeySequence}`,
  { storage: recoveryStorage, storageKey: 'c2-recovery-test' }
)
const recoveryA = makeRecovery()
const firstTicket = recoveryA.begin({
  operationKey: 'add:scope-1:project-1',
  scopeKey: 'scope-1',
  action: 'add-checkout-entitlement-lines',
  payload: { selectorToken: 'TOKEN-1', commandContexts: [{ id: 1, kind: 'cashier_workspace' }] }
})
recoveryA.settle(firstTicket, 'result_unknown')
const sameTicket = recoveryA.begin({
  operationKey: 'add:scope-1:project-1',
  scopeKey: 'scope-1',
  action: 'add-checkout-entitlement-lines',
  payload: { commandContexts: [{ kind: 'cashier_workspace', id: 1 }], selectorToken: 'TOKEN-1' }
})
ok('结果未知后同语义请求沿用原幂等键', sameTicket.accepted && sameTicket.reused
  && sameTicket.idempotencyKey === firstTicket.idempotencyKey)
const changedTicket = recoveryA.begin({
  operationKey: 'add:scope-1:project-1',
  scopeKey: 'scope-1',
  action: 'add-checkout-entitlement-lines',
  payload: { selectorToken: 'TOKEN-2', commandContexts: [{ kind: 'cashier_workspace', id: 2 }] }
})
ok('关闭重开后 token 变化仍重放第一次保存的完整请求', changedTicket.accepted
  && changedTicket.reused
  && changedTicket.requestedPayloadChanged === true
  && changedTicket.payload.selectorToken === 'TOKEN-1'
  && changedTicket.idempotencyKey === firstTicket.idempotencyKey)
const otherOperation = recoveryA.begin({
  operationKey: 'remove:scope-1:line-2',
  scopeKey: 'scope-1',
  action: 'remove-cart-line',
  payload: { lineId: 'line-2' }
})
ok('同工作台存在未知结果时阻断其它草稿写命令', !otherOperation.accepted
  && otherOperation.reason === 'scope_has_unresolved_command'
  && otherOperation.idempotencyKey === firstTicket.idempotencyKey)
const recoveryAfterRouteRemount = makeRecovery()
const restoredTicket = recoveryAfterRouteRemount.begin({
  operationKey: 'add:scope-1:project-1',
  scopeKey: 'scope-1',
  action: 'add-checkout-entitlement-lines',
  payload: { selectorToken: 'TOKEN-3' }
})
ok('路由卸载再挂载仍从会话恢复原请求和原键', restoredTicket.reused
  && restoredTicket.payload.selectorToken === 'TOKEN-1'
  && restoredTicket.idempotencyKey === firstTicket.idempotencyKey)
recoveryAfterRouteRemount.settle(restoredTicket, 'success')
const nextTicket = recoveryAfterRouteRemount.begin({
  operationKey: 'add:scope-1:project-1',
  scopeKey: 'scope-1',
  action: 'add-checkout-entitlement-lines',
  payload: { selectorToken: 'TOKEN-4' }
})
ok('权威成功后才释放原键并允许生成新请求', nextTicket.idempotencyKey !== firstTicket.idempotencyKey)
recoveryAfterRouteRemount.clear()

console.log('== C2 production source guards ==')
const workbench = fs.readFileSync(workbenchPath, 'utf8')
const selector = fs.readFileSync(selectorPath, 'utf8')
const projectSelector = fs.readFileSync(projectSelectorPath, 'utf8')
const shell = fs.readFileSync(shellPath, 'utf8')
const fixture = fs.readFileSync(fixturePath, 'utf8')
const styles = fs.readFileSync(stylesPath, 'utf8')
const actionManifest = fs.readFileSync(actionManifestPath, 'utf8')
const bridge = fs.readFileSync(bridgePath, 'utf8')
const serviceCompletion = fs.readFileSync(serviceCompletionPath, 'utf8')
const writeoffConfirmation = fs.readFileSync(writeoffConfirmationPath, 'utf8')
const writeoffWorkbench = fs.readFileSync(writeoffWorkbenchPath, 'utf8')
const replacement = fs.readFileSync(replacementPath, 'utf8')
const checkoutOverlay = fs.readFileSync(checkoutOverlayPath, 'utf8')
const cashierToolbarMatch = shell.match(/<div v-(?:else-)?if="isCashierPage" class="cashier-workflow-toolbar cashier-workflow-toolbar--cashier"[\s\S]*?\n        <div v-else-if="isCashierWorkflowPage"/)
const cashierToolbar = cashierToolbarMatch ? cashierToolbarMatch[0] : ''
const cashierOperationBlockMatch = cashierToolbar.match(/<section class="cashier-workflow-toolbar__operation-block"[\s\S]*?<\/section>/)
const cashierOperationBlock = cashierOperationBlockMatch ? cashierOperationBlockMatch[0] : ''
const entitlementTableHeadMatch = selector.match(/<div class="cashier-entitlement-table__head"[\s\S]*?<\/div>/)
const entitlementTableHead = entitlementTableHeadMatch ? entitlementTableHeadMatch[0] : ''
const entitlementProjectRowMatch = selector.match(/<article\s+v-for="project in source\.projects \|\| \[\]"[\s\S]*?<\/article>/)
const entitlementProjectRow = entitlementProjectRowMatch ? entitlementProjectRowMatch[0] : ''
const entitlementTableGridMatch = styles.match(/\.cashier-entitlement-selector-panel \.cashier-entitlement-table__head,\s*\.cashier-entitlement-selector-panel \.cashier-entitlement-table__row\s*\{[\s\S]*?grid-template-columns:\s*([\s\S]*?);/)
const entitlementTableGrid = entitlementTableGridMatch ? entitlementTableGridMatch[1] : ''
const entitlementProjectNameCss = [...styles.matchAll(/\.cashier-entitlement-selector-panel \.cashier-entitlement-table__project-name\s*\{([\s\S]*?)\}/g)]
  .map((match) => match[1])
  .join('\n')
const entitlementProjectGuideCssMatch = styles.match(/\.cashier-entitlement-selector-panel \.cashier-entitlement-table__project-name::before\s*\{([\s\S]*?)\}/)
const entitlementProjectGuideCss = entitlementProjectGuideCssMatch ? entitlementProjectGuideCssMatch[1] : ''
const checkoutBarCssMatch = styles.match(/\.cashier-checkout-bar\s*\{([\s\S]*?)\}/)
const checkoutBarCss = checkoutBarCssMatch ? checkoutBarCssMatch[1] : ''
const cashierOperationLayoutCssMatch = styles.match(/\.cashier-workflow-toolbar__operation-block\s*\{\s*display:\s*grid;([\s\S]*?)\}/)
const cashierOperationLayoutCss = cashierOperationLayoutCssMatch ? cashierOperationLayoutCssMatch[1] : ''
const entitlementLineMaximumMatch = workbench.match(/function entitlementLineMaximum\(line = \{\}\) \{([\s\S]*?)\n\}\n\nasync function setLineQuantity/)
const entitlementLineMaximumSource = entitlementLineMaximumMatch ? entitlementLineMaximumMatch[1] : ''
ok('新版页面不再向用户展示已取消的核销金额名称', ![
  serviceCompletion,
  writeoffConfirmation,
  writeoffWorkbench,
  replacement
].some((source) => source.includes('核销金额')))
ok('工作台消费 data.entitlementSelector', /responseDataBlock\(result\)\.entitlementSelector/.test(workbench))
ok('工作台消费 data.cashierDraft', /responseDataBlock\(result\)\.cashierDraft/.test(workbench))
ok('工作台只整体接纳 complete=true 草稿', /draft\.complete !== true/.test(workbench)
  && /cashierDraftSnapshot\.value = Object\.freeze/.test(workbench))
ok('工作台不再从完整根读取权益选择器', !/cashier\.value\.entitlementSelector/.test(workbench))
ok('工作台范围变化清局部权益与草稿', /entitlementSelectorSnapshot\.value = null/.test(workbench)
  && /cashierDraftSnapshot\.value = null/.test(workbench))
ok('根投影批量替换后才比较最终权益范围并只保留合法会员选择意图', /shouldPreservePendingEntitlementSelector\(\{[\s\S]*?pending: pendingEntitlementSelector\.value,[\s\S]*?current,[\s\S]*?previous/.test(workbench)
  && /\{ flush: 'pre' \}/.test(workbench)
  && !/const sameWorkbench[\s\S]*?workspaceVersion/.test(workbench))
ok('加入权益只因业务上下文变化关闭选择区，工作台版本递增后保留并刷新', /function cashierBusinessScopeKey\(scope = \{\}\)/.test(workbench)
  && /const requestScopeKey = currentCashierScopeKey\.value/.test(workbench)
  && /requestScopeKey !== currentCashierScopeKey\.value/.test(workbench)
  && /!previous \|\| cashierBusinessScopeKey\(current\) === cashierBusinessScopeKey\(previous\)\) return/.test(workbench)
  && /await openEntitlementSelector\(\{ preserveSnapshot: true \}\)/.test(workbench))
ok('未选会员使用权益会先选会员，选中后自动续接同一权益意图', /if \(!member\.value \|\| cashier\.value\.customerMode === 'guest'\) \{[\s\S]*?pendingEntitlementSelector\.value = true[\s\S]*?context: 'cashier'/.test(workbench)
  && /function handleMemberSelectedForEntitlement[\s\S]*?pendingEntitlementSelector\.value[\s\S]*?openEntitlementSelector\(\)/.test(workbench)
  && /function handleMemberSelectorClosedForEntitlement[\s\S]*?pendingEntitlementSelector\.value = false/.test(workbench))
ok('权益选择器具备加载、失败重试和空权益状态', /const isLoading = computed\(\(\) => props\.loadState === 'loading'\)/.test(selector)
  && /会员权益加载失败/.test(selector)
  && /@click="emit\('retry'\)"/.test(selector)
  && /当前会员暂无可使用权益/.test(selector))
ok('选择器失败会清除旧快照并保留可重试的失败状态', /function showEntitlementSelectorLoadError\(message = ''\) \{[\s\S]*?entitlementSelectorSnapshot\.value = null[\s\S]*?entitlementSelectorLoadState\.value = 'error'[\s\S]*?isEntitlementSelectorOpen\.value = true/.test(workbench)
  && /catch \(error\) \{\s*invalidateEntitlementSelector\(\)[\s\S]*?showEntitlementSelectorLoadError\(unavailable\.result\.message\)/.test(workbench)
  && /v-else-if="hasLoadError"[\s\S]*?@click="emit\('retry'\)"/.test(selector))
ok('选择器使用规范上下文过滤器且固定 append 单行意图', /canonicalEntitlementCommandContexts\(/.test(selector)
  && /commandContexts,\s*\n\s*mutationMode: 'append'/.test(selector)
  && /lines: \[line\]/.test(selector)
  && /quantity: 1/.test(selector))
ok('使用权益在左侧商品区原位切换，卡操作编辑才单独使用确认弹层', !/import EntitlementProjectSelector/.test(selector)
  && /class="cashier-entitlement-selector-panel"/.test(selector)
  && /class="cashier-entitlement-table"/.test(selector)
  && /<section class="cashier-entitlement-selector-panel" aria-label="使用权益">/.test(selector)
  && /cashier-card-operation-editor" role="dialog"/.test(selector)
  && /<EntitlementSelectorOverlay[\s\S]*?v-else-if="isEntitlementSelectorOpen"/.test(workbench))
ok('常规使用权益保留四个筛选按钮并默认有效卡，卡启用仅展示停用卡', /const sourceFilter = ref\('valid'\)/.test(selector)
  && />查询</.test(selector)
  && />有效卡</.test(selector)
  && />只看已选</.test(selector)
  && />全部</.test(selector)
  && /v-if="operationMode === 'card-enable'"[\s\S]*?>已停用卡<\/span>/.test(selector)
  && /props\.operationMode === 'card-enable'[\s\S]*?statusCode[\s\S]*?!== 'disabled'/.test(selector)
  && /placeholder="请选择客户需要服务的项目"/.test(selector))
ok('权益有效卡筛选兼容后端 enabled 状态', /\['可用', 'available', 'valid', 'enabled'\]\.includes\(status\)/.test(selector))
ok('使用权益固定七列，卡号并入卡名下方且类型标签紧随名称', ['卡项名称', '余次', '余额', '购买次数', '购买金额', '有效期']
  .every((label) => entitlementTableHead.includes(`>${label}</span>`))
  && (entitlementTableHead.match(/<span(?:\s[^>]*)?(?:>|\s*\/\>)/g) || []).length === 7
  && !entitlementTableHead.includes('>卡号</span>')
  && /cashier-entitlement-table__source-name[\s\S]*?<strong>\{\{ source\.name \|\| '卡项' \}\}<\/strong>\s*<span class="cashier-entitlement-table__type-tag"[\s\S]*?<small[^>]*>\{\{ sourceCardNo\(source\) \}\}<\/small>/.test(selector)
  && /v-for="project in source\.projects \|\| \[\]"/.test(selector)
  && /cashier-entitlement-table__row--project/.test(selector)
  && /cashier-entitlement-table__type-tag/.test(selector)
  && /cashier-entitlement-table__add/.test(selector)
  && !/>类型<\/span>|>剩余次数<\/span>|>剩余金额<\/span>|>备注<\/span>|cashier-entitlement-table__check/.test(selector))
ok('权益表七列不出现横向滚动', (entitlementTableGrid.match(/minmax\(/g) || []).length === 6
  && /52px/.test(entitlementTableGrid)
  && /minmax\(88px,\s*\.9fr\)/.test(entitlementTableGrid)
  && /\.cashier-entitlement-selector-panel \.cashier-entitlement-table\s*\{[\s\S]*?overflow-x: hidden/.test(styles)
  && /\.cashier-entitlement-selector-panel \.cashier-entitlement-table__head,[\s\S]*?min-width: 0/.test(styles))
ok('项目名称缩进一个汉字并以折线标明卡项层级', /padding-left:\s*calc\(1em\s*\+\s*4px\)/.test(entitlementProjectNameCss)
  && /display:\s*block/.test(entitlementProjectGuideCss)
  && /border-left:/.test(entitlementProjectGuideCss)
  && /border-bottom:/.test(entitlementProjectGuideCss))
ok('项目行有效期固定留空、对辅助技术隐藏且保留列对齐', /displayNumber\(project\.purchaseAmount\) \}\}<\/span>\s*<span\s+aria-hidden="true"\s*\/>\s*<button/.test(entitlementProjectRow)
  && !/projectExpiryDate\(project\)/.test(entitlementProjectRow))
ok('使用权益只接受次卡、时间卡、定制卡、赠送，数值不附加货币或次数单位', ['次卡', '时间卡', '定制卡', '赠送']
  .every((label) => selector.includes(`'${label}'`))
  && !/储值卡/.test(selector)
  && !/¥/.test(selector)
  && !/加入购物车\$\{/.test(selector)
  && /历史权益没有统一的细分卡类型字段/.test(selector)
  && /return '—'/.test(selector)
  && !/source\.cardType/.test(selector)
  && !/return \(source\.projects \|\| \[\]\)\.reduce/.test(selector)
  && !/长期有效/.test(selector))
ok('fixture 使用 action-bound data 响应', /actionDataEnvelope\(\{ entitlementSelector:/.test(fixture)
  && /actionDataEnvelope\(\{ cashierDraft:/.test(fixture))
ok('fixture 草稿声明完整两类行合同', /complete: true/.test(fixture)
  && /managedLineRoles: \['sale', 'entitlement_service'\]/.test(fixture))
ok('fixture 不再声明 entitlement_instance kind', !/['"]entitlement_instance['"]/.test(fixture))
ok('删除与改次数接纳完整服务端草稿', /mutateCashierDraft\('remove-cart-line'/.test(workbench)
  && /mutateCashierDraft\('change-cart-line-quantity'/.test(workbench)
  && /responseDataBlock\(result\)\.cashierDraft/.test(workbench))
ok('fixture 真实模拟草稿删除与改次数', /function mutateFixtureCashierDraft\(/.test(fixture)
  && /\['remove-cart-line', 'change-cart-line-quantity', 'update-cart-line-service-settings'\]\.includes\(action\)/.test(fixture))
ok('购物车只按后端权威 sale 与 entitlement_service 行分组', /cartLineRole\(line\) === 'sale'/.test(workbench)
  && /cartLineRole\(line\) === 'entitlement_service'/.test(workbench))
ok('购物车固定先卡内项目后本次购买，卡内件数由同组权威明细实时计算', /if \(entitlementLines\.length\) groups\.push\(\{ key: 'current-entitlement-service', label: '卡内项目', lines: entitlementLines \}\)/.test(workbench)
  && /if \(saleLines\.length\) groups\.push\(\{ key: 'current-sale', label: '本次购买', lines: saleLines \}\)/.test(workbench)
  && workbench.indexOf("label: '卡内项目'") < workbench.indexOf("label: '本次购买'")
  && /已添加 \{\{ group\.lines\.length \}\} 项/.test(workbench))
ok('欠款提醒优先并串行续接权益选择', /deferredMemberSelection\.value = selectedDetail/.test(shell)
  && /await completeMemberSelection\(selectedDetail\)/.test(shell)
  && /reason: 'debt_repayment'/.test(shell)
  && /@cancel="cancelDebtReminder"/.test(shell))
ok('卡内项目选择层不再采集服务对象和手艺人', !/serviceObjects|openStaffAllocation|open-staff-allocation|select-service-object/.test(selector)
  && !/>服务对象</.test(projectSelector)
  && !/>手艺人</.test(projectSelector))
ok('购物车统一保存服务对象、手艺人和体验标记', /update-cart-line-service-settings/.test(workbench)
  && /openCartLineCraftsmen\(line\)/.test(workbench)
  && /setCartLineServiceObject\(line, 'self'\)/.test(workbench)
  && /toggleCartLineExperience\(line\)/.test(workbench))
ok('服务设置 action 进入前后端清单与 workspace 绑定', /'update-cart-line-service-settings': FEATURE_CASHIER/.test(actionManifest)
  && /'update-cart-line-service-settings'/.test(bridge))
ok('权益行欠款优惠券销售人明确禁用', /:disabled="isEntitlementLine\(line\)"/.test(workbench)
  && /cart-line__meta-slot--debt/.test(workbench)
  && /cart-line__meta-slot--coupon/.test(workbench)
  && /cart-line__meta-slot--salesperson/.test(workbench))
ok('手艺人必填缺失与已选状态使用确认色', /is-required-missing/.test(workbench)
  && /\.cart-line__meta-action--craftsmen\.is-required-missing/.test(styles)
  && /#d48806/.test(styles)
  && /#409eff/.test(styles))
ok('来源位于名称标题块次行且体验位于项目服务设置区', /cart-line__title-block/.test(workbench)
  && /cart-line__source-detail/.test(workbench)
  && /cart-line__service-controls/.test(workbench)
  && /v-if="isProjectLine\(line\)" class="cart-line__service-controls"/.test(workbench)
  && /cart-line__experience[\s\S]*cart-line__service-object/.test(workbench))
ok('收银商品固定四列且右侧购物车更宽', /\.product-grid\s*\{[\s\S]*?repeat\(4, minmax\(0, 1fr\)\)/.test(styles)
  && /\.cashier-workbench__body\s*\{[\s\S]*?minmax\(430px, 4fr\)[\s\S]*?minmax\(560px, 6fr\)/.test(styles)
  && !/repeat\([67], minmax\(0, 1fr\)\)/.test(styles))
ok('未选会员预览由显式 guest 权威场景提供', /scenario === 'workbench-no-member'[\s\S]*?customerMode = 'guest'/.test(fixture)
  && !/const EMPTY_CASHIER = \{[\s\S]*?customerMode: 'guest'/.test(bridge))
ok('购物车分组显示已添加项且底部不重复已选种类', /已添加 \{\{ group\.lines\.length \}\} 项/.test(workbench)
  && !/selectedPurchaseKindCount/.test(workbench))
ok('正常价黑色且只有改价后的现价为红色', /cart-line__current-price--changed/.test(workbench)
  && /\.cart-line__amount > strong\s*\{[\s\S]*?color: #303133/.test(styles)
  && /\.cart-line__amount > \.cart-line__current-price--changed\s*\{[\s\S]*?color: #cf1322/.test(styles)
  && /cart-line__original-price/.test(workbench))
ok('本次新增购买统一展示为本次购买', !/serviceSource: '本次新买'/.test(bridge)
  && !/label: '本次新买'/.test(bridge))
ok('使用权益替代使用卡项并由顶部操作区打开', /data-testid="cashier-workflow-entitlement"/.test(cashierOperationBlock)
  && /class="button button--primary cashier-workflow-action-button cashier-workflow-entitlement-button"/.test(cashierOperationBlock)
  && /cashier-v3:open-entitlement-selector/.test(shell)
  && /cashier-v3:open-entitlement-selector/.test(workbench)
  && !/增加卡内项目/.test(workbench)
  && !/>使用卡项</.test(workbench)
  && /\{\{ operationLabel \|\| '使用权益' \}\}/.test(selector)
  && !/serviceOrder\.serviceNo \|\| serviceOrder\.id/.test(shell))
ok('房间摘要位于顶部操作区中间，使用权益固定在赠送左侧', /cashier-workflow-service-order/.test(cashierOperationBlock)
  && />房间：\{\{/.test(cashierOperationBlock)
  && />服务单：\{\{/.test(cashierOperationBlock)
  && cashierOperationBlock.indexOf('cashier-workflow-service-order') < cashierOperationBlock.indexOf('cashier-workflow-entitlement')
  && cashierOperationBlock.indexOf('cashier-workflow-entitlement') < cashierOperationBlock.indexOf('cashier-workflow-gift')
  && /grid-template-columns:\s*minmax\(0, 1fr\)\s*auto/.test(cashierOperationLayoutCss)
  && /justify-content:\s*initial/.test(cashierOperationLayoutCss)
  && /\.cashier-workflow-service-order\s*\{[\s\S]*?grid-column:\s*1[\s\S]*?justify-self:\s*center/.test(styles)
  && !/open-add-service-consumption/.test(workbench)
  && /\.service-order-banner--toolbar\s*\{[^}]*?min-height:\s*34px/.test(styles)
  && /\.service-order-banner--toolbar > div\s*\{[^}]*?white-space:\s*nowrap/.test(styles)
  && /\.service-order-banner--toolbar strong\s*\{[^}]*?white-space:\s*nowrap/.test(styles))
ok('购物车移除顶部权益工具条并让内容直接上顶', !/cart-entitlement-toolbar/.test(workbench)
  && /cashier-workflow-service-order/.test(shell)
  && /<div class="cart-panel__content">\s*<section v-if="previewCardOperation\?\.sources\?\.length" class="cashier-operation-preview"/.test(workbench)
  && /<div v-if="cartLines\.length" class="cart-line-list"/.test(workbench))
ok('收银顶部拆为会员与操作两块，移除结账收款和顶部挂单查询', /cashier-workflow-toolbar__member-block/.test(cashierToolbar)
  && /cashier-workflow-toolbar__operation-block/.test(cashierToolbar)
  && />选择会员</.test(cashierToolbar)
  && /data-testid="cashier-workflow-recharge"/.test(cashierToolbar)
  && !/>结账收款</.test(cashierToolbar)
  && !/cashier-hang-query/.test(cashierToolbar)
  && /cashier-workflow-toolbar--cashier[\s\S]*?grid-template-columns/.test(styles))
ok('充值和赠送先选择会员，再携带所选会员上下文发起动作', /openCashierMemberTopAction\('open-recharge'\)/.test(shell)
  && /openCashierMemberTopAction\('open-gift'\)/.test(shell)
  && /pendingMemberTopAction\.value = action/.test(shell)
  && /return requestTopAction\(action, \{[\s\S]*?memberId,[\s\S]*?selectorContext: 'cashier'/.test(shell)
  && /const pendingTopAction = pendingMemberTopAction\.value/.test(shell))
ok('选择会员去还款会明确关闭权益待打开意图', /reason: 'debt_repayment'/.test(shell)
  && /function handleMemberSelectorClosedForEntitlement[\s\S]*?event\.detail\?\.reason !== 'selected'[\s\S]*?pendingEntitlementSelector\.value = false/.test(workbench))
ok('挂单待结账数量只显示在左侧菜单', /item\.key === 'hang' && pendingHangCount > 0/.test(shell)
  && /cashier-nav__badge/.test(shell)
  && !/挂单查询/.test(cashierToolbar))
ok('卡操作七项均进入受控 V3 分步流程而非页面预览', ['卡升级', '卡延期', '卡转让', '卡停用', '卡启用', '项目替换', '项目升级']
  .every((label) => shell.includes(label))
  && /@click="openPreviewCardOperation\(item\)"/.test(shell)
  && /'card-upgrade': 'card_upgrade'/.test(workbench)
  && /'project-upgrade': 'project_upgrade'/.test(workbench)
  && /requestCashierV3Action\('submit-card-operation', payload\)/.test(workbench)
  && /cardOperationCommandContexts\(operation, source, operationType\)/.test(workbench)
  && /cardOperationUpgradeTypes\.has\(operationType\)/.test(workbench)
  && /const cardOperationTargetCatalogTypes = new Set\(\[\s*'card_upgrade',\s*'project_replacement',\s*'project_upgrade'\s*\]\)/.test(workbench)
  && /if \(cardOperationTargetCatalogTypes\.has\(operationType\)\)[\s\S]*?payload\.targetCatalogId = targetCatalogId/.test(workbench)
  && /if \(cardOperationProjectTypes\.has\(operationType\)\)[\s\S]*?payload\.projectLines = Array\.from/.test(workbench)
  && /return confirmPreviewCardOperation\(\)/.test(workbench)
  && !/已完成页面预览，未产生真实业务数据/.test(workbench))
ok('项目替换和项目升级只显示项目目标，卡升级只显示卡项，取消会清空操作上下文', /previewCardOperation\.value\?\.awaitingTarget[\s\S]*?mode === 'card-upgrade' \? '卡项' : '项目'/.test(workbench)
  && /operation\.mode === 'card-upgrade'[\s\S]*?item\.kind === '卡项'[\s\S]*?project-upgrade[\s\S]*?item\.kind === '项目'/.test(workbench)
  && /function abandonCardOperation\(\) \{[\s\S]*?previewCardOperation\.value = null[\s\S]*?selectedType\.value = '项目'[\s\S]*?finalizeEntitlementSelector\(\)/.test(workbench)
  && /cardOperationTargetPrompt\(operation\.mode\)/.test(workbench))
ok('顶部使用权益、赠送、卡操作和操作说明共用尺寸字重', /\.cashier-workflow-action-button(?:\s*,|\s*\{)[\s\S]*?height:\s*34px[\s\S]*?font-size:\s*13px[\s\S]*?font-weight:\s*700/.test(styles)
  && (cashierOperationBlock.match(/cashier-workflow-action-button/g) || []).length >= 4
  && /@media \(min-width:\s*1101px\) and \(max-width:\s*1300px\)[\s\S]*?\.cashier-workflow-toolbar__operation-actions\s*\{[\s\S]*?gap:\s*6px[\s\S]*?\.cashier-workflow-toolbar--cashier \.cashier-workflow-action-button\s*\{[\s\S]*?padding-right:\s*10px[\s\S]*?padding-left:\s*10px/.test(styles))
ok('使用权益与返回选购同为蓝底白字主按钮', /class="button button--primary cashier-workflow-action-button cashier-workflow-entitlement-button"[\s\S]*?data-testid="cashier-workflow-entitlement"/.test(cashierOperationBlock)
  && /class="button button--primary cashier-entitlement-selector-panel__return-button"[^>]*>返回选购<\/button>/.test(selector)
  && /\.button--primary\s*\{[\s\S]*?background:\s*#(?:1890ff|096dd9)[\s\S]*?color:\s*#fff/.test(styles)
  && /\.cashier-workflow-toolbar--cashier \.cashier-workflow-entitlement-button\s*\{[\s\S]*?background:\s*#1890ff[\s\S]*?color:\s*#fff/.test(styles)
  && /\.cashier-entitlement-selector-panel__header \.button\s*\{[\s\S]*?height:\s*34px[\s\S]*?background:\s*#096dd9[\s\S]*?color:\s*#fff/.test(styles))
ok('权益逐次点击以新 addIntentId 追加独立购物车行且实际权益金额不改应收', /mutationMode: 'append'/.test(selector)
  && /projectKey: projectKey\(source, project\)/.test(selector)
  && /@add="addEntitlementLines"/.test(workbench)
  && /const addIntentId = String\(payload\.addIntentId \|\| createCashierV3CommandId\('ENTITLEMENT_ADD'\)\)/.test(workbench)
  && /const commandPayload = \{ \.\.\.payload, addIntentId, mutationMode: 'append' \}/.test(workbench)
  && /const intentLineId = `entitlement:\$\{addIntentId\.slice\(-36\)\}`/.test(fixture)
  && /id: intentLineId,\s*\n\s*addIntentId,/.test(fixture)
  && /const lines = \[\.\.\.currentLines, \.\.\.selectedLines\]/.test(fixture)
  && !/existing\.quantity\s*\+=|existing\.quantity\s*=\s*Number\(existing\.quantity/.test(fixture)
  && /formatMoney\(line\.actualAmount\)/.test(workbench)
  && /line\.amountRole === 'entitlement_actual'/.test(workbench)
  && /saleLines\.reduce/.test(fixture)
  && /fixtureAllocatedEntitlementAmount\(/.test(fixture)
  && /BigInt\(/.test(fixture)
  && !/actualUnitAmount \* persistedQuantity|Number\(lines\[lineIndex\]\.actualUnitAmount\) \* nextQuantity/.test(fixture))
ok('新增权益动画只绑定服务端返回的新行且不跟随用户当前选中行', /const addedEntitlementLineId = ref\(''\)/.test(workbench)
  && /const existingLineIds = new Set\(cartLines\.value\.map\(\(line\) => String\(line\?\.id \|\| ''\)\)\)/.test(workbench)
  && /const appendedLine = \(draft\.lines \|\| \[\]\)\.find\(\(line\) => \([\s\S]*?!existingLineIds\.has\(String\(line\.id \|\| ''\)\)/.test(workbench)
  && /addedEntitlementLineId\.value = String\(appendedLine\?\.id \|\| ''\)/.test(workbench)
  && /'cart-line--just-added': isEntitlementLine\(line\)\s*&& addedEntitlementLineId\s*&& line\.id === addedEntitlementLineId/.test(workbench)
  && !/'cart-line--just-added':[\s\S]{0,180}line\.id === activeCartLineId/.test(workbench)
  && (workbench.match(/addedEntitlementLineId\.value = ''/g) || []).length >= 2)
ok('人员查询刷新完整根后仍保留会员与权益资源版本供手艺人回写', /addEntity\('member', state\?\.cashier\?\.member,[\s\S]*?\['recordVersion', 'revision', 'memberVersion'\]\)/.test(fixture)
  && /function seedFixtureMemberVersions\(state\)/.test(fixture)
  && /for \(const source of state\?\.writeoff\?\.sources \|\| \[\]\)/.test(fixture)
  && /add\('card_holder', source\?\.cardHolderId \|\| source\?\.entitlementInstanceId \|\| source\?\.id/.test(fixture)
  && /'member_benefit_pool',[\s\S]*?project\?\.memberBenefitPoolId \|\| project\?\.entitlementSourceDetailId/.test(fixture)
  && /for \(const line of state\?\.cashier\?\.cart\?\.lines \|\| \[\]\)/.test(fixture)
  && /add\('card_holder', line\?\.entitlementInstanceId \|\| line\?\.cardHolderId, line\?\.entitlementSourceVersion\)/.test(fixture)
  && /const memberVersion = positiveFixtureVersion\(/.test(fixture)
  && /\{ kind: 'member', id: memberId, expectedVersion: memberVersion \}/.test(fixture)
  && /seedFixtureMemberVersions\(cashierV3State\)/.test(fixture))
ok('权益行数量上限按卡实例、权益明细和项目扣除其他独立行', /const availableTimes = Number\(line\.availableTimes\)/.test(entitlementLineMaximumSource)
  && /const holderId = String\(line\.entitlementInstanceId \|\| line\.cardHolderId \|\| ''\)/.test(entitlementLineMaximumSource)
  && /const detailId = String\(line\.entitlementSourceDetailId \|\| line\.memberBenefitPoolId \|\| ''\)/.test(entitlementLineMaximumSource)
  && /const projectId = String\(line\.projectId \|\| ''\)/.test(entitlementLineMaximumSource)
  && /cartLines\.value\.reduce\(\(total, candidate\)/.test(entitlementLineMaximumSource)
  && /String\(candidate\.id \|\| ''\) !== String\(line\.id \|\| ''\)/.test(entitlementLineMaximumSource)
  && /String\(candidate\.entitlementInstanceId \|\| candidate\.cardHolderId \|\| ''\) === holderId/.test(entitlementLineMaximumSource)
  && /String\(candidate\.entitlementSourceDetailId \|\| candidate\.memberBenefitPoolId \|\| ''\) === detailId/.test(entitlementLineMaximumSource)
  && /String\(candidate\.projectId \|\| ''\) === projectId/.test(entitlementLineMaximumSource)
  && /sameSource \? total \+ Number\(candidate\.quantity \|\| 0\) : total/.test(entitlementLineMaximumSource)
  && /return Math\.max\(0, availableTimes - selectedByOtherLines\)/.test(entitlementLineMaximumSource)
  && /const maximum = entitlementLineMaximum\(line\)/.test(workbench))
ok('权益行服务设置始终经草稿命令发送完整资源版本上下文', /async function confirmPersonnelAssignment\(result = \{\}\)[\s\S]*?await mutateCashierDraft\('update-cart-line-service-settings', line, payload\)[\s\S]*?async function setCartLineServiceObject[\s\S]*?await mutateCashierDraft\('update-cart-line-service-settings', line, \{ serviceObject \}\)[\s\S]*?async function toggleCartLineExperience[\s\S]*?await mutateCashierDraft\('update-cart-line-service-settings', line, payload\)/.test(workbench)
  && !/if \(!localCashierDraft\.value\) \{\s*await requestAction\('update-cart-line-service-settings'/.test(workbench)
  && /function draftLineCommandContexts\(line = \{\}, action = ''\)[\s\S]*?\{ kind: 'member', id: memberId, expectedVersion: 1 \}[\s\S]*?\{ kind: 'member_benefit_pool', id: detailId[\s\S]*?\{ kind: 'card_holder', id: holderId/.test(workbench))
ok('缺手艺人先回到购物车分配，旧结账失败也只恢复为可编辑草稿', /function firstCartLineMissingCraftsmen\(\)/.test(workbench)
  && /function isProjectLine\(line\) \{[\s\S]*?isEntitlementLine\(line\) \|\| Number\(line\.productType\) === 6/.test(workbench)
  && /const committed = localPersonnelAssignments\.value\[line\.id\]\?\.craftsmen/.test(workbench)
  && /if \(Array\.isArray\(committed\)\) return committed/.test(workbench)
  && /const craftsmenRequiredLine = firstCartLineMissingCraftsmen\(\)/.test(workbench)
  && /await openCartLineCraftsmen\(craftsmenRequiredLine\)/.test(workbench)
  && /code: 'CASHIER_CRAFTSMAN_REQUIRED'/.test(workbench)
  && /const closeAfterEditReturn = approvedPayloadInput\.closeAfterEditReturn === true/.test(workbench)
  && /if \(action === 'return-to-payment-edit'\)[\s\S]*?if \(closeAfterEditReturn\) closeCheckoutOverlay\(\)/.test(workbench)
  && /const craftsmanValidationFailed = status === 'failed'/.test(workbench)
  && /canReturnToCashierEdit: craftsmanValidationFailed/.test(workbench)
  && /function returnToCashierEdit\(\)/.test(checkoutOverlay)
  && /返回收银补选手艺人/.test(checkoutOverlay))
ok('服务设置成功后工作台版本推进不会遗留未决草稿命令', /const requestScopeKey = currentCashierDraftScopeKey\.value/.test(workbench)
  && /if \(!await applyCommittedCashierDraft\(draft, requestScopeKey\)\)/.test(workbench)
  && /draftCommandRecovery\.settle\(retryTicket, status\)[\s\S]*?pendingForScope\(draftRecoveryScopeKey\.value\)/.test(workbench))
ok('权益选择器跨工作台版本推进保持同一业务范围，资源版本仍只来自命令上下文', /const currentCashierScopeKey = computed\(\(\) => cashierBusinessScopeKey\(cashierScopeIdentity\.value\)\)/.test(workbench)
  && /const currentCashierDraftScopeKey = computed\(\(\) => cashierBusinessScopeKey\(cashierScopeIdentity\.value\)\)/.test(workbench)
  && /const contexts = entitlementContextMap\(selector\.commandContexts\)/.test(workbench)
  && /holderContext\?\.expectedVersion !== holderVersion/.test(workbench)
  && /poolContext\?\.expectedVersion === poolVersion/.test(workbench))
ok('仅当权威购物车完整反映同一服务设置时才释放旧恢复锁', /function resolveReflectedDraftCommand\(\)[\s\S]*?ticket\?\.action !== 'update-cart-line-service-settings'[\s\S]*?sameStaffIds\(line\.craftsmen, requestedCraftsmen\)[\s\S]*?sameStaffIds\(line\.salespeople, requestedSalespeople\)[\s\S]*?draftCommandRecovery\.settle\(ticket, 'success'\)/.test(workbench)
  && /if \(cashierDraftHasUnresolvedCommand\.value\) \{\s*resolveReflectedDraftCommand\(\)/.test(workbench))
ok('会员顶部与下方商品区使用同一列轨道，搜索提示按确认文案缩小', /\.cashier-workflow-toolbar--cashier\s*\{[\s\S]*?minmax\(430px, 4fr\)[\s\S]*?minmax\(560px, 6fr\)/.test(styles)
  && /请选择客户所需要购买的卡、项目、产品/.test(workbench)
  && /\.catalog-panel__search \.search-field input::placeholder\s*\{[\s\S]*?font-size: 12px/.test(styles))
ok('项目的体验、本人、朋友保持体验在左且本人朋友同高小字', /cart-line__experience[\s\S]*?cart-line__service-object/.test(workbench)
  && /\.cart-line__service-object button\s*\{[\s\S]*?min-height: 22px[\s\S]*?font-size: 10px[\s\S]*?line-height: 20px/.test(styles))
ok('结账横条常规和矮屏使用固定紧凑高度且内容垂直居中', /align-items:\s*center/.test(checkoutBarCss)
  && /min-height:\s*56px/.test(checkoutBarCss)
  && /height:\s*56px/.test(checkoutBarCss)
  && /@media[^\{]*\{[\s\S]*?\.cashier-checkout-bar\s*\{[\s\S]*?min-height:\s*52px/.test(styles))
ok('菜单收缩按钮位于当前账号旁并各占菜单边框内外一半', /cashier-sidebar__footer[\s\S]*?cashier-account[\s\S]*?cashier-sidebar-toggle/.test(shell)
  && /\.cashier-sidebar__footer\s*\{[\s\S]*?display: flex/.test(styles)
  && /\.cashier-sidebar-toggle\s*\{[^}]*?flex: none/.test(styles)
  && /\.cashier-sidebar-toggle\s*\{[^}]*?margin-right: -27px/.test(styles)
  && /\.cashier-sidebar--collapsed \.cashier-sidebar-toggle\s*\{[^}]*?margin-right: -22px/.test(styles)
  && !/\.cashier-sidebar-toggle\s*\{[^}]*?position: absolute/.test(styles))
ok('成功响应必须在完整权威草稿接纳后才释放同幂等键', /cashierDraftSnapshot\.value = Object\.freeze\(\{[\s\S]*?draftCommandRecovery\.settle\(retryTicket, status\)/.test(workbench)
  && /if \(\['failed', 'conflict'\]\.includes\(status\)\) \{[\s\S]*?draftCommandRecovery\.settle\(retryTicket, status\)/.test(workbench))
ok('结果未知跨路由保留且其它草稿命令被同工作台阻断', /useCashierV3DraftCommandRecovery/.test(workbench)
  && !/onBeforeUnmount\(\(\) => \{[\s\S]*?draftCommandRecovery\.clear\(\)/.test(workbench)
  && /scopeKey: draftRecoveryScopeKey\.value/.test(workbench)
  && /retryTicket\.payload/.test(workbench)
  && /cashierDraftHasUnresolvedCommand/.test(workbench))
ok('空购物车在界面与函数双层禁止挂单和结账', /if \(!hasCartLines\.value\) \{[\s\S]*?CASHIER_CART_EMPTY/.test(workbench)
  && /:disabled="!canSubmitCart"/.test(workbench)
  && /:disabled="!canSubmitCart \|\| isPreparingServiceCompletion \|\| isPreparingCheckout"/.test(workbench))
ok('根状态也使用同一精确购物车 composition 合同', /isCompleteCheckoutCompositionContract/.test(bridge)
  && /cashier_checkoutComposition_contract_mismatch/.test(bridge))
ok('结账后续来源只保留在版本上下文且不夹进业务 payload', /const approvedPayloadInput = \{ \.\.\.\(isRecord\(payload\) \? payload : \{\}\) \}/.test(workbench)
  && /for \(const sourceKey of \[[\s\S]*?'serviceOrderId'[\s\S]*?'reservationId'[\s\S]*?'roomId'[\s\S]*?'hangOrderId'[\s\S]*?\]\) \{\s*delete approvedPayloadInput\[sourceKey\]/.test(workbench)
  && /const approvedPayload = \{\s*\.\.\.approvedPayloadInput,[\s\S]*?commandContexts: current\.commandContexts/.test(workbench)
  && !/const approvedPayload = \{[\s\S]{0,260}serviceOrderCommandPayload\(\)/.test(workbench))

console.log(`C2_ENTITLEMENT_FRONTEND_SUMMARY passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
console.log('GATE_PASS=C2-A1-FE-01')
console.log('GATE_PASS=C2-A1-FE-02')
console.log('GATE_PASS=C2-A1-FE-03')
console.log('GATE_PASS=C2-A1-FE-04')
console.log('GATE_PASS=C2-A1-FE-05')
console.log('GATE_PASS=C2-A1-FE-06')
console.log('GATE_PASS=C2-A1-FE-07')
console.log('RUNNER_OK=js/c2-entitlement-frontend-contract.mjs')
