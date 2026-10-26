import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const root = new URL('../../../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const projection = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php')
const repository = read('后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php')
const debtService = read('后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const cashierModule = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php')

const between = (source, start, end) => {
  const first = source.indexOf(start)
  const last = source.indexOf(end, first + start.length)
  assert.ok(first >= 0 && last > first, `missing contract boundary: ${start}`)
  return source.slice(first, last)
}

const preparation = between(workbench, 'function isCompleteCheckoutPreparation(', 'function activateCheckoutSession(')
const currentContexts = between(workbench, 'function currentCheckoutCommandContexts(', 'function applyCheckoutDraftProjection(')
const handoff = between(workbench, 'async function openPreparedCheckout(', 'function consumePreparedCheckoutHandoff(')
const prepareRequest = between(shell, 'async function prepareDebtRepayment(', 'async function loadMemberDetailTab(')
const exactRead = between(repository, 'public function readEditingProjectionByRequestId(', 'public function lockCurrentForKernelInTx(')

assert.match(preparation, /snapshot\.businessType !== 'debt_repayment'\s*&& !hasMatchingCommandContext\(contexts, 'cashier_workspace'/)
assert.match(preparation, /snapshot\.businessType === 'debt_repayment'\s*&& String\(snapshot\.stateContextId/)
assert.match(currentContexts, /snapshot\.businessType === 'debt_repayment'[\s\S]*?return \{ commandContexts: \[\], checkoutRequestVersion:/)
assert.match(handoff, /detail\.businessType !== 'debt_repayment' && !isCompleteCheckoutPreparation/)
assert.match(workbench, /const checkoutContexts = isDebtRepaymentCheckout\.value\s*\? \[\]/)
assert.match(workbench, /commandContexts: \[\]\s*\}\s*\}\s*if \(!actionSession/)
assert.match(projection, /\$workspaceCurrentVersion = \$isServerLockedDebtSnapshot \? 0 : self::positiveInt/)
assert.match(projection, /'commandContexts' => array_merge\(\$isServerLockedDebtSnapshot \? \[\] :/)
assert.match(exactRead, /\$workspaceVersion = \$isDebtRepayment \? 0 : \(int\)Db::name/)
assert.match(exactRead, /\$workspaceVersionAfter = \$isDebtRepayment \? 0 : \(int\)Db::name/)
assert.doesNotMatch(prepareRequest, /open-cashier-workbench|state\.workspace\s*=/)

// “下一步”只交接浏览器内这笔金额，不能调用创建编辑草稿的准备命令。
assert.match(prepareRequest, /localRepayment: \{ \.\.\.payload, businessSource: \{ \.\.\.source \} \}/)
assert.doesNotMatch(prepareRequest, /requestCashierV3Action|requestAction|prepare-debt-repayment|prepare-recharge-debt-repayment/)
const localCheckout = between(workbench, "if (detail.businessType === 'debt_repayment' && isRecord(detail.localRepayment)) {", 'let snapshot = clonePlain(detail.checkoutSnapshot)')
assert.match(localCheckout, /localCheckoutPreview\.value = \{/)
assert.doesNotMatch(localCheckout, /requestAction|checkoutRequestVersion/)
const directSubmit = between(debtService, 'public function submitInTx(', 'public function submitRechargeDebtCheckoutInTx(')
assert.match(directSubmit, /checkoutSnapshot.*?debt_repayment_checkout_snapshot_required/s)
assert.doesNotMatch(directSubmit, /cashier_v3_debt_repayment_draft|cashier_v3_checkout_line_draft|lockAggregateForEditInTx/)
assert.match(workbench, /requestAction\(payload\.checkoutSnapshot\.rechargeDebt === true[\s\S]*?submit-debt-repayment', \{\s*checkoutSnapshot:/)
assert.match(workbench, /rechargeDebt: intent\.rechargeDebt === true/)
assert.match(debtService, /private function submitRechargeDebtSnapshotInTx\(/)
assert.match(debtService, /return \$this->rechargeSnapshotResult\(\$rechargeRow, true\)/)
assert.match(bridge, /action === 'submit-recharge-debt-repayment' && isRecord\(payload\?\.checkoutSnapshot\)/)
assert.match(cashierModule, /submit-recharge-debt-repayment'[\s\S]*?allows_empty_contexts' => true/)

const browserState = { stateContextId: 'CTX-1', workspace: { id: 'STORE-WORKSPACE', revision: 2 } }
const hasMatchingCommandContext = (contexts, kind, id, version) => contexts.some((item) =>
  item.kind === kind && item.id === id && item.expectedVersion === version)
const completePreparation = runInNewContext(`${preparation}; isCompleteCheckoutPreparation`, {
  state: browserState,
  isCompleteCheckoutState: () => true,
  checkoutRequestIdentity: (snapshot) => snapshot.checkoutRequestId,
  checkoutRequestVersion: (snapshot) => snapshot.checkoutRequestVersion,
  checkoutPreparationToken: (snapshot) => snapshot.preparationToken,
  checkoutOrderLines: () => [{}],
  checkoutOrderSummary: () => ({ receivableAmount: 1200, discountAmount: 0 }),
  hasOwn: (value, key) => Object.prototype.hasOwnProperty.call(value, key),
  hasMatchingCommandContext,
  serviceOrder: { value: null }
})
const preparedDebt = {
  businessType: 'debt_repayment', stateContextId: 'CTX-1',
  checkoutRequestId: 'REQ-1', checkoutRequestVersion: 3,
  preparationRequestId: 'PREP-1', preparationToken: 'SERVER-TOKEN', preparationReady: true,
  commandContexts: [{ kind: 'cashier_workspace', id: 'STORE-WORKSPACE', expectedVersion: 99 }]
}
assert.equal(completePreparation(preparedDebt, 'PREP-1'), true, '欠款收款不得因旧工作台版本停在第一步')
assert.equal(completePreparation({ ...preparedDebt, stateContextId: 'CTX-OTHER' }, 'PREP-1'), false, '仍须隔离其他浏览器会话')
assert.equal(completePreparation({ ...preparedDebt, preparationRequestId: 'PREP-OTHER' }, 'PREP-1'), false, '仍须绑定本次准备请求')
assert.equal(completePreparation({ ...preparedDebt, businessType: 'checkout' }, 'PREP-1'), false, '普通结账仍保留工作台并发校验')

const currentDebtContexts = runInNewContext(`${currentContexts}; currentCheckoutCommandContexts`, {
  state: browserState,
  checkout: { value: {} },
  serviceOrder: { value: null },
  checkoutRequestIdentity: (snapshot) => snapshot.checkoutRequestId,
  checkoutRequestVersion: (snapshot) => snapshot.checkoutRequestVersion,
  clonePlain: (value) => JSON.parse(JSON.stringify(value)),
  hasMatchingCommandContext
})({ stateContextId: 'CTX-1', checkoutRequestId: 'REQ-1', serviceOrderId: '', snapshot: preparedDebt })
assert.equal(currentDebtContexts.checkoutRequestVersion, 3)
assert.equal(currentDebtContexts.commandContexts.length, 0, '后续补交命令不得透传工作台版本')

console.log('DEBT_REPAYMENT_UNIQUE_SNAPSHOT_CONTRACT_OK')
