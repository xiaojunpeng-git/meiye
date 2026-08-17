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
const cashierModule = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php')

assert.match(workbench, /if \(localCashierDraftOperations\.value\.length > 0\) \{[\s\S]*?await synchronizeLocalCashierDraft\(\{ deferProjection: true \}\)[\s\S]*?localCheckoutPreview\.value = null[\s\S]*?shouldForceFreshCheckout = true/,
  '本地编辑进入结账前完整同步当前工作台草稿，并在完成后一次更新页面')
assert.match(workbench, /let shouldForceFreshCheckout = forceFreshCheckout[\s\S]*?!shouldForceFreshCheckout && resumePersistedCheckout\(\)/,
  '有本地修改时不恢复旧结账快照，而是按最新工作台版本准备结账')
assert.match(workbench, /function applyDiscardedCheckoutProjection\(result = \{\}\)[\s\S]*?checkout: discarded[\s\S]*?return String\(checkoutRequestIdentity\(checkout\.value\) \|\| ''\) === ''/,
  '删除旧待结账草稿后直接采用接口返回的空结账投影')
assert.match(workbench, /if \(shouldForceFreshCheckout && String\(checkoutRequestIdentity\(checkout\.value\) \|\| ''\)\) \{[\s\S]*?await discardCashierCheckout\([\s\S]*?applyDiscardedCheckoutProjection\(discarded\)[\s\S]*?CHECKOUT_DRAFT_REPLACEMENT_INCOMPLETE/,
  '购物车修改后在同一次点击内删除旧待结账草稿并继续创建当前内容的新结账单')
assert.match(workbench, /async function closeCheckoutOverlay\(options = \{\}\)[\s\S]*?const shouldDiscardUnfinishedCheckout = options\?\.discardFailedCheckout === true/,
  '普通退出结账不删除待结账草稿')
assert.doesNotMatch(workbench.slice(workbench.indexOf('async function closeCheckoutOverlay'), workbench.indexOf('async function closeSucceededCheckoutAndRefreshWorkbench')),
  /\['editing', 'ready_for_submit', 'failed', 'processing', 'pending_confirmation', 'result_unknown'\]\.includes\(requestStatus\)/,
  '退出结账不因编辑态自动丢弃待结账草稿')
assert.match(workbench, /const hasLocalOperations = localCashierDraftOperations\.value\.length > 0[\s\S]*?\(localDraft \? \{ localDraft \} : \{\}\)/,
  '有本地操作的挂单保存操作队列快照')
assert.match(workbench, /function localDraftContainsPersistedCartLines[\s\S]*?async function openHangOrder[\s\S]*?hasPersistedLines[\s\S]*?await synchronizeLocalCashierDraft\(\)/,
  '旧服务端行与本地操作混合时先同步完整草稿，避免提单遗漏旧行')
assert.match(workbench, /const localDraft = Boolean\(localCashierDraft\.value\)[\s\S]*?'open-local-line-coupon'[\s\S]*?reservedCouponIds/,
  '本地草稿读取可选券但不要求服务端购物车行号')
assert.match(workbench, /function applyRestoredHangDraft[\s\S]*?localCashierDraftOperations\.value = clonePlain\(local\.operations\)/,
  '提单恢复本地操作队列')
assert.match(workbench, /function draftLineCommandContexts\(line = \{\}, action = ''\)[\s\S]*?const contexts = \[\{ kind: 'cashier_workspace'[\s\S]*?return contexts/,
  '权益行落库后的数量与服务设置仅携带工作台上下文')
assert.doesNotMatch(workbench.slice(workbench.indexOf('function draftLineCommandContexts'), workbench.indexOf('function enqueueCashierDraftMutation')),
  /member_benefit_pool|card_holder/,
  '权益的会员、卡和权益池上下文不混入已落库行的数量回放')
assert.match(workbench, /function persistedCashierDraftLineIds\(\)[\s\S]*?cashier\.value\.cart\?\.lines/,
  '本地草稿同步以服务端工作台行作为新增行映射基准')
assert.match(workbench, /if \(action === 'add-checkout-entitlement-lines'\) \{[\s\S]*?const previousIds = new Set\(cashierDraftLines\(deferredCashierDraft \|\| cashier\.value\)[\s\S]*?const appended = cashierDraftLines\(draft\)\.find\(\(line\) => !previousIds\.has/s,
  '权益新增只会映射为后端新增的对应行，并在加载期间不改写前端草稿')
assert.match(workbench, /function captureLocalCashierDraftForEntitlementSelector\(\)[\s\S]*?function restoreLocalCashierDraftAfterEntitlementSelector\(checkpoint = null\)[\s\S]*?localCashierDraftOperations\.value = clonePlain\(checkpoint\.operations\)/,
  '打开权益选择器只读取权益来源，延迟投影不能覆盖当前本地购买草稿')
assert.match(workbench, /await requestAction\('open-add-card-service-project'[\s\S]*?await nextTick\(\)[\s\S]*?restoreLocalCashierDraftAfterEntitlementSelector\(localDraftCheckpoint\)/,
  '权益查询后的草稿恢复等待根投影监听完成，避免首次添加权益时购买行被清除')
assert.match(workbench, /function appendLocalCashierDraftOperation[\s\S]*?localCashierDraftOperations\.value = \[\.\.\.localCashierDraftOperations\.value, clonePlain\(operation\)\][\s\S]*?isEntitlementSelectorOpen\.value\) refreshEntitlementSelectorDraftCheckpoint\(\)/,
  '权益选择器打开期间的每次本地编辑都会刷新完整草稿保护点')
assert.match(workbench, /const entitlementSelectorDraftCheckpoint = ref\(null\)[\s\S]*?watch\([\s\S]*?\(\) => state\.stateRevision[\s\S]*?restoreLocalCashierDraftAfterEntitlementSelector\(checkpoint\)/,
  '迟到的权益选择器根投影只能恢复同一工作台的完整本地草稿，不能短暂清空购买行')
assert.match(workbench, /function cartLineRole\(line = \{\}\)[\s\S]*?entitlementSourceDetailId[\s\S]*?return 'entitlement_service'/,
  '兼容权益行投影缺少 lineRole 时仍识别为权益服务')
assert.match(workbench, /function firstCartLineMissingCraftsmen\(\)[\s\S]*?isProjectLine\(line\)[\s\S]*?!hasCartLineCraftsmen\(line\)/,
  '纯权益行与购买项目一样必须参与手艺人前置检查')
assert.match(workbench, /const craftsmenRequiredLine = firstCartLineMissingCraftsmen\(\)[\s\S]*?await openCartLineCraftsmen\(craftsmenRequiredLine\)[\s\S]*?return result[\s\S]*?if \(cashierDraftHasUnresolvedCommand\.value\)[\s\S]*?recoverPendingDraftCommand\(\)[\s\S]*?if \(localCashierDraftOperations\.value\.length > 0\)/,
  '进入结账前先在前端检查手艺人，未选择时不启动恢复、草稿同步或结账请求')
assert.match(workbench, /const canSubmitCart = computed\(\(\) => hasCartLines\.value\)/,
  '未知草稿命令保留结账入口，使其能按原幂等键回放恢复')
assert.match(workbench, /if \(cashierDraftHasUnresolvedCommand\.value\) \{\s*const recovered = await recoverPendingDraftCommand\(\)/,
  '结账入口优先回放未知草稿命令，不允许创建新请求')
assert.match(workbench, /async function synchronizeLocalCashierDraft\(\{ deferProjection = false \} = \{\}\)[\s\S]*?if \(deferProjection && !await applyCommittedCashierDraft\(deferredCashierDraft/,
  '草稿同步完成后才一次性应用服务端完整投影')
assert.match(workbench, /function adoptLatestCashierWorkspaceRevision\(\)[\s\S]*?getCashierV3PublicVersion\('cashier_workspace', workspaceId\)[\s\S]*?if \(deferProjection\) adoptLatestCashierWorkspaceRevision\(\)/,
  '延迟同步完成后采用命令回执中的最新工作台版本，使首次点击直接继续准备结账')
assert.match(workbench, /if \(deferProjection\) \{[\s\S]*?draftCommandRecovery\.settle\(retryTicket, status\)[\s\S]*?cashierDraftHasUnresolvedCommand\.value = Boolean\([\s\S]*?return \{ \.\.\.result, deferredCashierDraft: draft \}/,
  '延迟投影模式的成功行级命令会释放恢复票据，后续数量和人员命令可以继续同步')
assert.match(workbench, /await persistDeferredLineServiceSettings\(\)[\s\S]*?if \(localCashierDraftOperations\.value\.length > 0\) \{[\s\S]*?synchronizeLocalCashierDraft\(\{ deferProjection: true \}\)/,
  '不出库、预售、体验和服务对象先进入本地操作队列，再随新增行按顺序一次性同步')
assert.match(workbench, /if \(action === 'create-custom-card-configuration'\)[\s\S]*?requestAction\(action,[\s\S]*?createCashierV3CommandId\('CASHIER_MORE'\)/,
  '定制卡同步使用后端已登记的根级收银请求标识前缀')
assert.doesNotMatch(workbench, /createCashierV3CommandId\('CUSTOM_CARD'\)/,
  '定制卡不再使用未登记的 CUSTOM_CARD 请求标识前缀')
assert.match(workbench, /function localDraftLineTotalAmountCents\(line = \{\}, amount = getLineAmount\(line\)\)[\s\S]*?isLocalCashierDraftLine\(line\)[\s\S]*?cents \* Math\.max\(1, Number\(line\.quantity \|\| 1\)\)[\s\S]*?: cents/,
  '本地汇总只对 local 行按数量乘单价，服务端行使用已返回的行合计')
assert.match(workbench, /localDraftLineTotalAmountCents\(line, line\.originalAmount \?\? getLineAmount\(line\)\)[\s\S]*?localDraftLineTotalAmountCents\(line\)/,
  '手艺人等服务设置本地回填后不会把购买行金额按数量重复计算')
const entitlementServiceSettingsPolicy = cashierModule.slice(
  cashierModule.indexOf("private static function registerUpdateServiceSettingsPolicy"),
  cashierModule.indexOf("private static function workspaceOnlyPolicyResult")
)
assert.match(entitlementServiceSettingsPolicy, /编辑权益服务行只修改当前工作台草稿[\s\S]*?return \$resolved;/,
  '权益行手艺人设置只写工作台草稿，不提前绑定会员、卡或权益次数')
assert.doesNotMatch(entitlementServiceSettingsPolicy, /member_benefit_pool|card_holder|\$entitlementIdentities/,
  '权益行手艺人设置不要求前端携带会员、权益池或持卡人 contexts')
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

console.log('LOCAL_DRAFT_FINAL_CONFIRMATION_CONTRACT passed=30 failed=0')
