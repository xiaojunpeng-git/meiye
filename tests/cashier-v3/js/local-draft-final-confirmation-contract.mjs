import assert from 'node:assert/strict'
import fs from 'node:fs'

const root = new URL('../../../', import.meta.url)
const read = (path) => fs.readFileSync(new URL(path, root), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const selector = read('前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue')
const checkoutOverlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const preparation = read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php')
const submission = read('后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php')
const resume = read('后端代码/app/services/cashier/v3/hang/CashierV3HangResumeServices.php')

assert.match(workbench, /if \(localCashierDraftOperations\.value\.length > 0\) \{[\s\S]*?localCheckoutPreview\.value = localCheckoutPreviewSnapshot\(\)[\s\S]*?return localDraftResult\('已进入结账预览。'\)/,
  '本地编辑点击立即结账只打开预览')
assert.match(workbench, /async function finalizeLocalCheckoutPreview[\s\S]*?await synchronizeLocalCashierDraft\(\)[\s\S]*?await openCheckout\(\)[\s\S]*?requestCheckoutAction\(\{ action: 'submit-checkout'/,
  '第三步确认才同步草稿并进入最终结账')
assert.match(workbench, /function localCheckoutPreviewSnapshot\(\)[\s\S]*?const debtAmount = centsToMoney\([\s\S]*?debtAmount,[\s\S]*?cashPerformanceAmount: 0,[\s\S]*?balancePaymentAmount: 0,[\s\S]*?finalChanges: \[\]/,
  '本地预览补齐第三步展示所需快照并带入行级欠款')
assert.match(workbench, /localCheckoutPaymentOperations\.value = \[\.\.\.localCheckoutPaymentOperations\.value, \{ action, payload \}\][\s\S]*?cashier-v3:checkout-draft-mutation-result[\s\S]*?status: 'succeeded'/,
  '本地收款编辑回传成功回执，释放预览页的输入与下一步按钮')
assert.match(checkoutOverlay, /hasPendingPaymentLineAmountDraft = computed\(\(\) => \([\s\S]*?props\.checkout\.localDraftPreview !== true[\s\S]*?paymentLineAmountDrafts\.value/,
  '本地预览按即时收款值进入第三步，不等待不存在的服务端草稿投影')
assert.match(workbench, /const hasLocalOperations = localCashierDraftOperations\.value\.length > 0[\s\S]*?\(localDraft \? \{ localDraft \} : \{\}\)/,
  '有本地操作的挂单保存操作队列快照')
assert.match(workbench, /function localDraftContainsPersistedCartLines[\s\S]*?async function openHangOrder[\s\S]*?hasPersistedLines[\s\S]*?await synchronizeLocalCashierDraft\(\)/,
  '旧服务端行与本地操作混合时先同步完整草稿，避免提单遗漏旧行')
assert.match(workbench, /const localDraft = Boolean\(localCashierDraft\.value\)[\s\S]*?'open-local-line-coupon'[\s\S]*?reservedCouponIds/,
  '本地草稿读取可选券但不要求服务端购物车行号')
assert.match(workbench, /function applyRestoredHangDraft[\s\S]*?localCashierDraftOperations\.value = clonePlain\(local\.operations\)/,
  '提单恢复本地操作队列')
const projectDisabledSource = selector.slice(selector.indexOf('function projectDisabled'), selector.indexOf('function addProject'))
assert.doesNotMatch(projectDisabledSource, /project\.selectable === false/, '权益选择不因剩余次数提前禁用')
assert.doesNotMatch(preparation, /\$this->assertEntitlementRowsAfterGatewayLocks\(/, '立即结账不校验权益次数')
assert.match(submission, /private function saveLocalDraftInTx[\s\S]*?local_draft_snapshot_json[\s\S]*?clearLinesInTx\(/,
  '本地挂单只保存快照并清空工作台')
assert.match(submission, /\$workspaceId = \\app\\services\\cashier\\v3\\CashierV3CheckoutWorkspaceIdentity::id\([\s\S]*?if \(is_array\(\$payload\['localDraft'\] \?\? null\)\) \{[\s\S]*?\$workspaceId/,
  '本地挂单在进入快照分支前已取得工作台标识')
assert.ok(
  resume.includes("'local_draft'")
    && resume.includes('restoreLocalHangDraftShellInTx')
    && resume.includes("'localDraft' => $localDraft"),
  '本地挂单提单恢复会员壳和前端快照'
)

console.log('LOCAL_DRAFT_FINAL_CONFIRMATION_CONTRACT passed=14 failed=0')
