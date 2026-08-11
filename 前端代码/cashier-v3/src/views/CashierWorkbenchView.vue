<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  createCashierV3CommandId,
  formatMoney,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'
import {
  entitlementBenefitPoolId,
  entitlementCardHolderId,
  isCompleteCheckoutCompositionContract,
  normalizeEntitlementCommandContext,
  responseDataBlock,
  shouldPreservePendingEntitlementSelector
} from '@/services/cashierV3EntitlementDraftContract'
import { useCashierV3DraftCommandRecovery } from '@/services/cashierV3DraftCommandRecovery'
import { roomOpenIntentFromRouteQuery, roomOpenIntentHangPayload } from '@/services/cashierV3RoomOpenIntent'
import { clearCashierDraft, discardCashierCheckout, saveHangDraft } from '@/services/hangDraftApi'
import {
  authoritativeCheckoutResult,
  cashierV3ResponseEnvelope,
  cashierV3ResponseRequiresRefresh
} from '@/services/cashierV3CheckoutResultContract'
import { authoritativeHangOrderResult } from '@/services/cashierV3HangOrderResultContract'
import {
  mergeSalesOrderCenterProjection,
  salesOrderProjectionFromResult
} from '@/services/cashierV3OrderProjectionContract'
import CashierCheckoutOverlay from '@/components/cashier/CashierCheckoutOverlay.vue'
import CheckoutBusinessSourceOverlay from '@/components/cashier/CheckoutBusinessSourceOverlay.vue'
import CashierGuidedBusinessPanel from '@/components/cashier/CashierGuidedBusinessPanel.vue'
import RechargeOverlay from '@/components/member/RechargeOverlay.vue'
import EntitlementSelectorOverlay from '@/components/cashier/EntitlementSelectorOverlay.vue'
import HangOrderOverlay from '@/components/cashier/HangOrderOverlay.vue'
import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'
import CashierCouponSelectorOverlay from '@/components/cashier/CashierCouponSelectorOverlay.vue'
import { loadCheckoutBusinessCatalog } from '@/services/cashierBusinessConfigApi'
import { useRechargeCheckout } from '@/composables/useRechargeCheckout'

const state = useCashierV3State()
const route = useRoute()
const router = useRouter()
const cashierToday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date())

const keyword = ref('')
// 收银开单以项目为默认入口；“全部”会把卡项、产品等混在首屏，
// 既不符合门店服务开单习惯，也让项目目录不易发现。
const selectedType = ref('项目')
const selectedCategory = ref('')
const areCategoriesExpanded = ref(false)
const activeCartLineId = ref(null)
// 保留最近一次数量校验失败，避免后端拒绝超量后输入框被恢复为权威数量，
// 但结账按钮仍沿用旧购物车继续进入结账向导。
const cartQuantityValidationError = ref('')
const localExperienceState = ref({})
const previewCardOperation = ref(null)
const selectedCardOperationProjectKeys = computed(() => (previewCardOperation.value?.sources || []).map((source) => (
  `${entitlementCardHolderId(source)}:${entitlementBenefitPoolId(source)}`
)))
const guidedBusinessMode = ref('')
const isCustomCardConflictOpen = ref(false)
const isMemberRequiredOpen = ref(false)
const pendingCardPurchase = ref(null)
const isConfirmingCardPurchase = ref(false)
const personnelOverlay = ref(null)
const isSavingPersonnelAssignment = ref(false)
const localPersonnelAssignments = ref({})
const debtEditor = ref(null)
const debtEditorAmount = ref('')
const couponSelector = ref(null)
const isSavingLineCoupon = ref(false)
const moreActionEditor = ref(null)
const moreActionValue = ref('')
const moreActionReason = ref('')
const moreActionValidationMessage = ref('')
const isSavingMoreAction = ref(false)
const isClearingCart = ref(false)
const isCheckoutOpen = ref(false)
const checkoutBusinessSourceSelector = ref(null)
const isSavingCheckoutBusinessSource = ref(false)
const checkoutInlineBusinessSources = ref([])
const isLoadingCheckoutBusinessSources = ref(false)
const checkoutBusinessSourcesLoadError = ref('')
let checkoutBusinessSourcesLoadToken = 0
const isSavingCheckoutSalesDate = ref(false)
const isSavingRechargeDate = ref(false)
const rechargeSession = ref(null)
const rechargePreparationIdempotencyKey = ref('')
const isRechargeSubmitting = ref(false)
const isHangOrderOpen = ref(false)
const hangOrderPreparationId = ref(null)
const hangOrderSession = ref(null)
const isSavingHangDraft = ref(false)
const isPreparingServiceCompletion = ref(false)
const serviceCompletionPreparationId = ref(null)
const isPreparingCheckout = ref(false)
const checkoutPreparationId = ref(null)
const checkoutSession = ref(null)
const isEntitlementSelectorOpen = ref(false)
const isOpeningEntitlementSelector = ref(false)
const entitlementSelectorLoadState = ref('idle')
const entitlementSelectorLoadError = ref('')
const isAddingEntitlementLines = ref(false)
const isCreatingCustomCard = ref(false)
const isSubmittingCardOperation = ref(false)
const entitlementSelectorRequestId = ref(null)
const pendingEntitlementSelector = ref(false)
const pendingCustomCardEntry = ref(false)
const pendingEntitlementProjectKey = ref('')
const addedEntitlementProjectKey = ref('')
const addedEntitlementLineId = ref('')
const cashierContextEpoch = ref(0)
const entitlementSelectorSnapshot = ref(null)
const cashierDraftSnapshot = ref(null)
const draftCommandRecovery = useCashierV3DraftCommandRecovery(createCashierV3CommandId)
const cashierDraftHasUnresolvedCommand = ref(false)
let entitlementAddedTimer = null

const defaultTypes = ['项目', '产品', '卡项', '定制卡']

const cashier = computed(() => state.cashier || {})
const member = computed(() => cashier.value.member || null)
const currentMemberId = computed(() => member.value?.id || member.value?.memberId || '')
const {
  rechargeCheckout,
  acceptPreparedCheckout,
  closeRechargeCheckout,
  requestRechargeCheckoutAction,
  enqueueRechargeCheckoutAction
} = useRechargeCheckout({
  member,
  currentMemberId,
  stateContextId: computed(() => state.stateContextId || ''),
  openSourceSelector: (kind) => openCheckoutBusinessSourceSelector(kind)
})
const cashierScopeIdentity = computed(() => ({
  stateContextId: state.stateContextId || '',
  storeId: state.currentStore?.id || '',
  workspaceId: state.workspace?.id || '',
  workspaceVersion: state.workspace?.revision,
  customerMode: cashier.value.customerMode || '',
  memberId: currentMemberId.value
}))

// 工作台 revision 会在每次购物车命令成功后递增。它是并发版本，不是
// 门店、账号或会员的业务边界；不能因此关闭仍在使用中的权益选择区。
function cashierBusinessScopeKey(scope = {}) {
  return [
    scope.stateContextId || '',
    scope.storeId || '',
    scope.workspaceId || '',
    scope.customerMode || '',
    scope.memberId || ''
  ].join('|')
}

// 加入购物车会推进工作台的写入版本，但这不改变账号、门店、工作台或会员。
// 权益列表必须跨该版本推进保留；每条后续写命令仍只使用服务端下发的资源版本。
const currentCashierScopeKey = computed(() => cashierBusinessScopeKey(cashierScopeIdentity.value))
const draftRecoveryScopeKey = computed(() => [
  state.stateContextId || '',
  state.currentStore?.id || '',
  state.workspace?.id || '',
  cashier.value.customerMode || '',
  currentMemberId.value || ''
].join('|'))
// A successful draft command advances the workspace revision before its
// authoritative draft is returned.  Draft snapshots therefore belong to the
// stable business scope, while the revision remains part of each next command
// context for optimistic concurrency.
const currentCashierDraftScopeKey = computed(() => cashierBusinessScopeKey(cashierScopeIdentity.value))
const localEntitlementSelector = computed(() => (
  entitlementSelectorSnapshot.value?.scopeKey === currentCashierScopeKey.value
    ? entitlementSelectorSnapshot.value.snapshot
    : null
))
const localCashierDraft = computed(() => (
  cashierDraftSnapshot.value?.scopeKey === currentCashierDraftScopeKey.value
    ? cashierDraftSnapshot.value.snapshot
    : null
))
const catalog = computed(() => cashier.value.catalog || {})
const cart = computed(() => localCashierDraft.value
  ? {
      lines: localCashierDraft.value.lines,
      summary: localCashierDraft.value.summary,
      primaryAction: localCashierDraft.value.primaryAction,
      primaryActionLabel: localCashierDraft.value.primaryActionLabel
        || localCashierDraft.value.checkoutComposition?.primaryActionLabel
        || ''
    }
  : (cashier.value.cart || { lines: [], summary: {} }))
const cartLines = computed(() => Array.isArray(cart.value.lines) ? cart.value.lines : [])
const activeCardOperationUpgrade = computed(() => {
  for (const line of cartLines.value) {
    const binding = line?.cardOperationUpgrade || line?.authoritySnapshot?.cardOperationUpgrade
    if (binding && ['card_upgrade', 'project_upgrade'].includes(String(binding.operationType || ''))) return binding
  }
  return null
})
const checkoutDebtAmountCents = computed(() => cartLines.value
  .filter((line) => !isEntitlementLine(line))
  .reduce((total, line) => total + lineDebtAmountCents(line), 0))
const hasCartLines = computed(() => cartLines.value.length > 0)
const canSubmitCart = computed(() => hasCartLines.value && !cashierDraftHasUnresolvedCommand.value)
const summary = computed(() => cart.value.summary || {})
const entitlementSelector = computed(() => localEntitlementSelector.value || {})
const activeCheckoutComposition = computed(() => localCashierDraft.value?.checkoutComposition || cashier.value.checkoutComposition || null)
const checkoutEntryLabel = computed(() => activeCardOperationUpgrade.value
  ? '立即结账'
  : previewCardOperation.value?.mode === 'project-replacement'
    ? '确认替换'
    : (cart.value.primaryActionLabel || activeCheckoutComposition.value?.primaryActionLabel || '立即结账'))
const productTypes = computed(() => (
  previewCardOperation.value?.awaitingTarget
    ? [previewCardOperation.value.mode === 'card-upgrade' ? '卡项' : '项目']
    : (catalog.value.types && catalog.value.types.length ? catalog.value.types : defaultTypes)
))
const categories = computed(() => [
  '全部',
  ...(Array.isArray(catalog.value.categories) ? catalog.value.categories : [])
    .filter((category) => category && category !== '全部')
])

function selectCategory(category) {
  selectedCategory.value = category === '全部' ? '' : category
}

const filteredCatalogItems = computed(() => {
  const normalizedKeyword = keyword.value.trim().toLocaleLowerCase()
  const items = Array.isArray(catalog.value.items) ? [...catalog.value.items] : []
  // 定制卡是收银内的配置入口，不依赖某一条演示商品或 URL 参数。
  items.push({ id: 'custom-card-entry', name: '新建定制卡', kind: '定制卡', category: '全部', price: 0 })

  return items.filter((item) => {
    const expectedTargetKind = previewCardOperation.value?.awaitingTarget
      ? (previewCardOperation.value.mode === 'card-upgrade' ? '卡项' : '项目')
      : ''
    const typeMatched = item.kind === (expectedTargetKind || selectedType.value)
    const categoryMatched = !selectedCategory.value || item.category === selectedCategory.value
    const searchable = `${item.name || ''} ${item.code || ''}`.toLocaleLowerCase()
    const keywordMatched = !normalizedKeyword || searchable.includes(normalizedKeyword)
    return typeMatched && categoryMatched && keywordMatched
  })
})

function catalogItemSelectedQuantity(item) {
  return cartLines.value.reduce((total, line) => {
    const lineCatalogId = line.catalogItemId ?? line.productId ?? line.itemId ?? line.product_id
    const idMatched = lineCatalogId !== undefined && String(lineCatalogId) === String(item.id)
    const isExistingBenefit = cartLineRole(line) === 'entitlement_service'
    const fallbackMatched = !lineCatalogId
      && !isExistingBenefit
      && String(line.name || '') === String(item.name || '')
      && String(line.kind || '') === String(item.kind || '')
    return !isExistingBenefit && (idMatched || fallbackMatched) ? total + Number(line.quantity || 1) : total
  }, 0)
}

const emptyCartSummaries = new Set(['未选择', '未设置', '不适用', '无', '—', '-'])

function cartActionLabel(label, summary) {
  const value = String(summary || '').trim()
  return !value || emptyCartSummaries.has(value) ? label : `${label}:${value}`
}

const isServiceOrder = computed(() => Boolean(cashier.value.serviceOrder))
const serviceOrder = computed(() => cashier.value.serviceOrder || null)
const serviceOrderNeedsConfirmation = computed(() => Boolean(
  serviceOrder.value
  && !serviceOrder.value.serviceConfirmed
  && (serviceOrder.value.confirmationRequired || ['服务中', '待确认'].includes(serviceOrder.value.status))
))
const supplement = computed(() => {
  const committedSupplement = localCashierDraft.value?.supplement
  if (committedSupplement && typeof committedSupplement === 'object') {
    return committedSupplement.enabled ? committedSupplement : null
  }
  return cashier.value.supplement || null
})
const checkout = computed(() => cashier.value.checkout || {})
const isDebtRepaymentCheckout = computed(() => checkout.value.businessType === 'debt_repayment')
// 充值欠款与销售欠款共用统一结账界面，但最终领域命令不同：充值欠款
// 必须写入充值欠款补交事实并记录 recharge_debt_repayment 事件，不能落到
// 销售欠款的 debt_repayment 事件契约。准备快照的权威行名用于区分两者。
const isRechargeDebtRepaymentCheckout = computed(() => (
  isDebtRepaymentCheckout.value
  && Array.isArray(checkout.value.orderLines)
  && checkout.value.orderLines.some((line) => String(line?.name || '') === '充值欠款补交')
))
const checkoutLocalOutcome = ref({})
// A recovered draft can safely reopen on the final confirmation step only
// when the authoritative payment snapshot is already fully balanced.  This
// is a display position, never a settlement instruction.
const checkoutRecoveryActiveStep = ref(null)
const checkoutRequiresRootReload = ref(false)
const checkoutOverlayState = computed(() => ({
  ...checkout.value,
  ...checkoutLocalOutcome.value,
  ...(activeCardOperationUpgrade.value
    ? {
        cardOperationUpgrade: clonePlain(activeCardOperationUpgrade.value),
        balancePaymentAmount: (Number(activeCardOperationUpgrade.value.sourceRemainingValueCents || 0) / 100).toFixed(2)
      }
    : {}),
  ...(checkoutRecoveryActiveStep.value ? { activeStep: checkoutRecoveryActiveStep.value } : {})
}))
const hangOrderPreparation = computed(() => cashier.value.hangOrderPreparation || cashier.value.hangOrder || {})
const hangOrderOverlayState = computed(() => hangOrderSession.value?.snapshot || {})
const roomOpenIntent = computed(() => roomOpenIntentFromRouteQuery(route.query))

const checkoutStatuses = new Set([
  'editing',
  'processing',
  'pending',
  'pending_confirmation',
  'result_unknown',
  'payment_succeeded_service_pending',
  'failed',
  'succeeded'
])

const cardOperationTypeByMode = Object.freeze({
  'card-upgrade': 'card_upgrade',
  'card-extension': 'card_extension',
  'card-transfer': 'card_transfer',
  'card-disable': 'card_disable',
  'card-enable': 'card_enable',
  'project-replacement': 'project_replacement',
  'project-upgrade': 'project_upgrade'
})

const cardOperationUpgradeTypes = new Set(['card_upgrade', 'project_upgrade'])
const cardOperationProjectTypes = new Set(['project_replacement', 'project_upgrade'])
const cardOperationTargetCatalogTypes = new Set([
  'card_upgrade',
  'project_replacement',
  'project_upgrade'
])
const cardOperationTargetModes = new Set([
  'card-transfer',
  'project-replacement',
  'card-upgrade',
  'project-upgrade'
])
const cardOperationReasonModes = new Set([
  'card-extension',
  'card-transfer',
  'card-disable',
  'card-enable'
])

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function hasOwn(record, key) {
  return Object.prototype.hasOwnProperty.call(record, key)
}

function clonePlain(value = {}) {
  return JSON.parse(JSON.stringify(value && typeof value === 'object' ? value : {}))
}

function valueOf(source, keys) {
  for (const key of keys) {
    const value = source?.[key]
    if (value !== undefined && value !== null && String(value).trim() !== '') return value
  }
  return undefined
}

function hasMatchingCommandContext(contexts, kind, id, version) {
  if (!id || version === undefined || version === null) return false
  return contexts.some((context) => (
    context?.kind === kind
    && String(context.id) === String(id)
    && Number(context.expectedVersion ?? context.expected_version) === Number(version)
  ))
}

function isCompleteHangOrderPreparation(snapshot, preparationRequestId) {
  if (!isRecord(snapshot)) return false
  if (snapshot.preparationReady !== true && snapshot.snapshotReady !== true) return false
  if (String(snapshot.preparationRequestId || '') !== String(preparationRequestId)) return false
  if (!snapshot.preparationToken && !snapshot.snapshotToken) return false
  const contexts = Array.isArray(snapshot.commandContexts) ? snapshot.commandContexts : []
  const workspace = state.workspace || {}
  if (!hasMatchingCommandContext(contexts, 'cashier_workspace', workspace.id, workspace.revision)) return false
  if (!Array.isArray(snapshot.roomCandidates || snapshot.candidates || [])) return false
  return true
}

function adoptHangPreparationWorkspaceVersion(snapshot) {
  const contexts = Array.isArray(snapshot?.commandContexts) ? snapshot.commandContexts : []
  const workspaceContext = contexts.find((context) => context?.kind === 'cashier_workspace')
  const workspaceId = String(state.workspace?.id || '')
  const expectedVersion = Number(workspaceContext?.expectedVersion)
  if (!workspaceId
    || String(workspaceContext?.id || '') !== workspaceId
    || !Number.isInteger(expectedVersion)
    || expectedVersion <= 0) return false
  state.workspace = { ...state.workspace, revision: expectedVersion }
  return true
}

function isCompleteCheckoutState(checkoutSnapshot) {
  if (!isRecord(checkoutSnapshot) || !checkoutStatuses.has(checkoutSnapshot.status)) return false
  if (!Array.isArray(checkoutSnapshot.finalChanges)) return false

  const orderLines = checkoutOrderLines(checkoutSnapshot)
  if (!orderLines.length || !orderLines.every(isAuthoritativeCartLine)) return false
  const composition = isRecord(checkoutSnapshot.composition)
    ? checkoutSnapshot.composition
    : isRecord(activeCheckoutComposition.value)
      ? activeCheckoutComposition.value
      : null
  if (!isCompleteCheckoutComposition(composition, orderLines, checkoutSnapshot.businessType)) return false

  const payment = checkoutSnapshot.payment
  if (!isRecord(payment) || !Array.isArray(payment.methods) || !Array.isArray(payment.selectedLines)) return false
  if (!payment.methods.every((method) => isRecord(method) && method.id && method.name)) return false
  if (!payment.selectedLines.every(isAuthoritativeCheckoutPaymentLine)) return false

  const paymentSummary = payment.summary
  return isRecord(paymentSummary)
    && hasOwn(paymentSummary, 'receivableAmount')
    && hasOwn(paymentSummary, 'selectedAmount')
    && hasOwn(paymentSummary, 'remainingAmount')
}

function isAuthoritativeCheckoutPaymentLine(line = {}) {
  if (!isRecord(line)
    || !line.id
    || !line.name
    || !hasOwn(line, 'amount')
    || !line.status
    || typeof line.canEdit !== 'boolean'
    || typeof line.canRemove !== 'boolean') return false

  // 余额扣减是收银结算行，但不是外部记账收款：它没有第三方流水号或
  // 备注。恢复时必须接受后端明确的余额行形状，不能把它误判为残缺外部
  // 收款而重新准备同一张结账单。
  if (line.kind === 'balance_deduction') {
    return line.id === 'balance-deduction'
      && line.name === '余额支付'
      && line.editAction === 'update-balance-payment'
      && line.removalAction === 'remove-balance-payment'
  }

  return hasOwn(line, 'externalTransactionNo') && hasOwn(line, 'remark')
}

function checkoutRequestIdentity(snapshot = {}) {
  return valueOf(snapshot, ['checkoutRequestId', 'requestId'])
}

function checkoutRequestVersion(snapshot = {}) {
  return valueOf(snapshot, ['checkoutRequestVersion', 'revision', 'recordVersion'])
}

function checkoutPreparationToken(snapshot = {}) {
  return valueOf(snapshot, ['preparationToken', 'snapshotToken', 'recoveryToken'])
}

function checkoutOrderLines(snapshot = {}) {
  if (Array.isArray(snapshot.orderLines)) return snapshot.orderLines
  if (Array.isArray(snapshot.orderSnapshot?.lines)) return snapshot.orderSnapshot.lines
  if (Array.isArray(snapshot.snapshot?.orderLines)) return snapshot.snapshot.orderLines
  return []
}

function checkoutOrderSummary(snapshot = {}) {
  if (isRecord(snapshot.summary)) return snapshot.summary
  if (isRecord(snapshot.orderSummary)) return snapshot.orderSummary
  if (isRecord(snapshot.orderSnapshot?.summary)) return snapshot.orderSnapshot.summary
  if (isRecord(snapshot.snapshot?.summary)) return snapshot.snapshot.summary
  return {}
}

function isCheckoutPreparationReceiptForSnapshot(receipt, snapshot, preparationRequestId) {
  if (!isRecord(receipt) || !isRecord(snapshot)) return false
  if (String(receipt.preparationRequestId || '') !== String(preparationRequestId || '')) return false
  return String(receipt.checkoutRequestId || '') === String(checkoutRequestIdentity(snapshot) || '')
    && Number(receipt.checkoutRequestVersion || 0) === Number(checkoutRequestVersion(snapshot) || 0)
}

function isCompleteCheckoutPreparation(snapshot, preparationRequestId, { recovery = false } = {}) {
  if (!isCompleteCheckoutState(snapshot)) return false
  if (!checkoutRequestIdentity(snapshot) || !checkoutRequestVersion(snapshot)) return false
  if (!checkoutPreparationToken(snapshot)) return false
  if (!checkoutOrderLines(snapshot).length) return false
  const orderSummary = checkoutOrderSummary(snapshot)
  if (!hasOwn(orderSummary, 'receivableAmount') || !hasOwn(orderSummary, 'discountAmount')) return false

  if (recovery) {
    if (snapshot.resumeOnLoad !== true) return false
    if (snapshot.recoveryReady !== true && snapshot.snapshotReady !== true && snapshot.preparationReady !== true) return false
  } else {
    if (snapshot.preparationReady !== true && snapshot.snapshotReady !== true) return false
    if (!preparationRequestId || String(snapshot.preparationRequestId || '') !== String(preparationRequestId)) return false
  }

  const contexts = Array.isArray(snapshot.commandContexts) ? snapshot.commandContexts : []
  const workspace = state.workspace || {}
  if (!hasMatchingCommandContext(contexts, 'cashier_workspace', workspace.id, workspace.revision)) return false
  if (!hasMatchingCommandContext(
    contexts,
    'checkout_request',
    checkoutRequestIdentity(snapshot),
    checkoutRequestVersion(snapshot)
  )) return false
  if (
    serviceOrder.value
    && snapshot.businessType !== 'debt_repayment'
    && !hasMatchingCommandContext(
      contexts,
      'service_order',
      serviceOrder.value.id,
      serviceOrder.value.revision
    )
  ) return false
  return true
}

function activateCheckoutSession(snapshot, preparationRequestId, options = {}) {
  if (!isCompleteCheckoutPreparation(snapshot, preparationRequestId, options)) return false
  const session = {
    stateContextId: String(state.stateContextId || ''),
    preparationRequestId: String(
      preparationRequestId
      || snapshot.preparationRequestId
      || checkoutRequestIdentity(snapshot)
    ),
    preparationToken: String(checkoutPreparationToken(snapshot)),
    checkoutRequestId: String(checkoutRequestIdentity(snapshot)),
    checkoutRequestVersion: Number(checkoutRequestVersion(snapshot)),
    serviceOrderId: snapshot.businessType !== 'debt_repayment' && serviceOrder.value?.id ? String(serviceOrder.value.id) : '',
    snapshot: Object.freeze(clonePlain(snapshot))
  }
  checkoutSession.value = Object.freeze(session)
  return true
}

/**
 * 结账准备已经由服务端持久化时，只能恢复同一张待结账请求。
 * 刷新／路由往返后不得再次调用 prepare-checkout 生成第二张请求。
 */
function resumePersistedCheckout() {
  checkoutRecoveryActiveStep.value = null
  const snapshot = clonePlain(checkout.value)
  // An editing checkout can be safely revised, but only before its final
  // preparation.  Do not resume a snapshot whose debt intent no longer
  // matches the cart-level debt editor; openCheckout will submit a
  // version-checked draft revision against this same request instead.
  const persistedDebtAmountCents = Number.isSafeInteger(Number(snapshot.debtAmountCents))
    ? Number(snapshot.debtAmountCents)
    : Math.round(Number(snapshot.debtAmount || 0) * 100)
  if (persistedDebtAmountCents !== checkoutDebtAmountCents.value) return false
  if (!activateCheckoutSession(snapshot, snapshot.preparationRequestId, { recovery: true })) return false
  checkoutPreparationId.value = String(snapshot.preparationRequestId || '') || null
  const paymentSummary = isRecord(snapshot.payment?.summary) ? snapshot.payment.summary : {}
  const isFullyPaid = Number(paymentSummary.remainingAmount) === 0
    && Number(paymentSummary.selectedAmount) > 0
  const isEditingPaymentDraft = String(snapshot.requestStatus || '') === 'editing'
    && Number(paymentSummary.selectedAmount || 0) > 0
  // ready_for_submit is already frozen by the server; an editing balance
  // draft is only positioned here after its persisted amounts fully balance.
  checkoutRecoveryActiveStep.value = isFullyPaid
    && (isEditingPaymentDraft || String(snapshot.requestStatus || '') === 'ready_for_submit')
    ? 3
    : null
  isCheckoutOpen.value = true
  return true
}

/**
 * A browser reload starts with no local overlay/session.  The cashier root
 * already returns one exact, version-checked editing checkout projection for
 * this workspace.  Reopen that one request only; never prepare or submit.
 */
const persistedEditingCheckoutRecoveryKey = computed(() => {
  const snapshot = checkout.value
  if (snapshot.resumeOnLoad !== true || String(snapshot.requestStatus || '') !== 'editing') return ''
  const requestId = String(checkoutRequestIdentity(snapshot) || '')
  const requestVersion = Number(checkoutRequestVersion(snapshot))
  const stateContextId = String(state.stateContextId || '')
  return requestId && Number.isInteger(requestVersion) && requestVersion > 0 && stateContextId
    ? `${stateContextId}:${requestId}:${requestVersion}`
    : ''
})

watch(
  persistedEditingCheckoutRecoveryKey,
  (recoveryKey) => {
    if (!recoveryKey || isCheckoutOpen.value || checkoutSession.value) return
    resumePersistedCheckout()
  },
  { immediate: true, flush: 'post' }
)

function currentCheckoutCommandContexts(session) {
  if (!session || session.stateContextId !== String(state.stateContextId || '')) return null
  const snapshot = checkout.value
  if (String(checkoutRequestIdentity(snapshot) || '') !== session.checkoutRequestId) return null
  if (session.serviceOrderId && String(serviceOrder.value?.id || '') !== session.serviceOrderId) return null
  const contexts = Array.isArray(snapshot.commandContexts) ? clonePlain(snapshot.commandContexts) : []
  const workspace = state.workspace || {}
  const requestVersion = checkoutRequestVersion(snapshot)
  if (!hasMatchingCommandContext(contexts, 'cashier_workspace', workspace.id, workspace.revision)) return null
  if (!hasMatchingCommandContext(contexts, 'checkout_request', session.checkoutRequestId, requestVersion)) return null
  if (
    session.serviceOrderId
    && !hasMatchingCommandContext(
      contexts,
      'service_order',
      session.serviceOrderId,
      serviceOrder.value?.revision
    )
  ) return null
  return {
    commandContexts: contexts,
    checkoutRequestVersion: Number(requestVersion)
  }
}

/**
 * Payment-draft commands return a narrow server projection after the write
 * commits. Applying it here avoids rebuilding the complete cashier root (and
 * its product catalog) for every payment-line click.
 */
function applyCheckoutDraftProjection(result) {
  const envelope = cashierV3ResponseEnvelope(result)
  const edit = isRecord(envelope?.data?.checkoutDraftEdit)
    ? envelope.data.checkoutDraftEdit
    : {}
  const projection = isRecord(edit.checkoutProjection) ? clonePlain(edit.checkoutProjection) : null
  const session = checkoutSession.value
  if (!projection || !session || String(envelope?.stateContextId || '') !== String(state.stateContextId || '')) {
    return false
  }
  if (String(checkoutRequestIdentity(projection) || '') !== session.checkoutRequestId
    || Number(checkoutRequestVersion(projection) || 0) !== Number(edit.checkoutRequestVersion || 0)
    || !isCompleteCheckoutState(projection)) {
    return false
  }
  const contexts = Array.isArray(projection.commandContexts) ? projection.commandContexts : []
  const workspace = state.workspace || {}
  const workspaceContext = contexts.find((context) => context?.kind === 'cashier_workspace')
  if (!workspaceContext
    || String(workspaceContext.id || '') !== String(workspace.id || '')
    || !Number.isInteger(Number(workspaceContext.expectedVersion))
    || Number(workspaceContext.expectedVersion) <= 0
    || !hasMatchingCommandContext(
      contexts,
      'checkout_request',
      session.checkoutRequestId,
      checkoutRequestVersion(projection)
    )) {
    return false
  }

  const previousWorkspace = clonePlain(workspace)
  const previousCheckout = clonePlain(checkout.value)
  state.workspace = {
    ...workspace,
    revision: Number(workspaceContext.expectedVersion)
  }
  state.cashier = {
    ...state.cashier,
    checkout: projection
  }
  if (activateCheckoutSession(projection, projection.preparationRequestId)) {
    checkoutPreparationId.value = String(projection.preparationRequestId || '') || null
    return true
  }

  state.workspace = previousWorkspace
  state.cashier = {
    ...state.cashier,
    checkout: previousCheckout
  }
  return false
}

function checkoutSubmissionCommandContexts(contexts) {
  const allowedKinds = new Set(['cashier_workspace', 'checkout_request'])
  const filtered = (Array.isArray(contexts) ? contexts : [])
    .filter((context) => allowedKinds.has(String(context?.kind || '')))
  return filtered.length === allowedKinds.size
    && new Set(filtered.map((context) => String(context.kind))).size === allowedKinds.size
    ? filtered
    : null
}

function isServiceCheckoutResumeReady(targetServiceOrderId) {
  if (checkout.value.businessType === 'debt_repayment') return false
  const currentServiceOrderId = serviceOrder.value?.id
  if (!targetServiceOrderId || !currentServiceOrderId) return false
  if (String(targetServiceOrderId) !== String(currentServiceOrderId)) return false

  // checkout 自带服务单号时也必须一致；未携带时仍以冻结契约中的
  // state.cashier.serviceOrder.id 作为本次完整收银现场的目标标识。
  if (
    checkout.value.serviceOrderId
    && String(checkout.value.serviceOrderId) !== String(currentServiceOrderId)
  ) return false

  return checkout.value.resumeOnLoad === true && isCompleteCheckoutState(checkout.value)
}

// 跨入口或刷新恢复只能打开后端已经完整装载的同一服务单结账现场。
// 处理中／失败等状态本身不能代替 resumeOnLoad，也不能绕过完整状态校验。
watch(
  () => isServiceCheckoutResumeReady(serviceOrder.value?.id),
  (resumeReady) => {
    if (!resumeReady) return
    const snapshot = clonePlain(checkout.value)
    if (activateCheckoutSession(snapshot, snapshot.preparationRequestId, { recovery: true })) {
      isCheckoutOpen.value = true
    }
  },
  { immediate: true }
)
// 普通收银也可能在提交“准备结账”后因刷新／路由返回重新装载。
// 该观察器只接受后端完整、可恢复的既有请求，绝不主动新建结账请求。
watch(
  () => [
    checkout.value.checkoutRequestId || checkout.value.requestId || '',
    checkout.value.checkoutRequestVersion ?? checkout.value.revision ?? checkout.value.recordVersion,
    checkout.value.status || '',
    state.workspace?.id || '',
    state.workspace?.revision ?? ''
  ],
  () => {
    if (isServiceOrder.value || isCheckoutOpen.value || isPreparingCheckout.value) return
    resumePersistedCheckout()
  },
  { immediate: true }
)
watch(
  () => [
    checkout.value.checkoutRequestId || checkout.value.requestId || '',
    checkout.value.revision ?? checkout.value.recordVersion,
    checkout.value.status
  ],
  ([requestId, revision, status], previous = []) => {
    const [previousRequestId, previousRevision] = previous
    if (
      (previousRequestId && requestId !== previousRequestId)
      || (previousRevision !== undefined && revision !== previousRevision)
      || ['failed', 'succeeded', 'success'].includes(status)
    ) {
      checkoutLocalOutcome.value = {}
    }
  }
)
watch(
  () => [state.workspace?.revision, serviceOrder.value?.id, serviceOrder.value?.revision],
  () => {
    serviceCompletionPreparationId.value = null
    if (!isPreparingCheckout.value) checkoutPreparationId.value = null
  }
)
const cartGroups = computed(() => {
  const saleLines = cartLines.value.filter((line) => cartLineRole(line) === 'sale')
  const entitlementLines = cartLines.value.filter((line) => cartLineRole(line) === 'entitlement_service')
  const groups = []
  // 分组只信任后端 lineRole：卡内权益在上，本次付款购买在下，不能从商品名称推断。
  if (entitlementLines.length) groups.push({ key: 'current-entitlement-service', label: '卡内项目', lines: entitlementLines })
  if (saleLines.length) groups.push({ key: 'current-sale', label: '本次购买', lines: saleLines })
  return groups
})

// 欠款、优惠券保留在购物车明细中；订单备注和改价直接置于结账栏。
const checkoutActions = [
  { key: 'open-order-note', label: '订单备注' },
  { key: 'open-price-change', label: '改价' }
]
const allowedCheckoutActions = new Set(checkoutActions.map((action) => action.key))

const serviceBoundActions = new Set([
  'choose-catalog-item',
  'remove-cart-line',
  'clear-cart-lines',
  'change-cart-line-quantity',
  'update-cart-line-service-settings',
  'update-cashier-line-debt',
  'apply-line-coupon',
  'remove-line-coupon',
  'open-line-assignment',
  'open-line-coupon',
  'open-line-debt',
  'open-order-note'
])

const checkoutRequestActions = new Set([
  'checkout-step-back',
  'checkout-step-next',
  'open-balance-payment',
  'open-balance-payment-identity-verification',
  'apply-balance-payment',
  'remove-balance-payment',
  'update-balance-payment',
  'toggle-combination-payment',
  'add-payment-method',
  'update-payment-line',
  'remove-payment-line',
  'update-checkout-business-source',
  'update-checkout-sales-date',
  'prepare-checkout-submission',
  'open-checkout-source-selector',
  'confirm-debt-warning',
  'confirm-checkout-final-changes',
  'submit-checkout',
  'submit-recharge-debt-repayment',
  'return-to-payment-edit',
  'retry-checkout',
  'query-checkout-result',
  'continue-partial-payment-recovery',
  'go-to-writeoff-after-checkout',
  'finish-checkout-and-return'
])

const checkoutExternalActions = new Set([
  'prepare-service-completion',
  'view-sales-order',
  'print-sales-order-receipt'
])
const checkoutDraftMutationActions = new Set([
  // The balance button emits the UI-facing action name. It is translated to
  // apply-balance-payment in requestCheckoutAction, but must enter this queue
  // before that translation so an immediate external-payment click cannot use
  // the stale checkout-request version.
  'open-balance-payment',
  'add-payment-method',
  'update-payment-line',
  'remove-payment-line',
  'apply-balance-payment',
  'update-balance-payment',
  'remove-balance-payment',
  'update-checkout-business-source',
  'update-checkout-sales-date'
])
let checkoutDraftMutationTail = Promise.resolve()
let cashierDraftMutationTail = Promise.resolve()

function checkoutSourceSelectorError(error) {
  return error instanceof Error ? error.message : '业务来源加载失败，请稍后重试。'
}

async function loadInlineCheckoutBusinessSources() {
  const current = checkout.value
  const loadToken = ++checkoutBusinessSourcesLoadToken
  if (!isCheckoutOpen.value || current.sourceEnabled !== true || current.sourceSelectable === false) {
    checkoutInlineBusinessSources.value = []
    checkoutBusinessSourcesLoadError.value = ''
    isLoadingCheckoutBusinessSources.value = false
    return
  }
  const requestId = String(checkoutRequestIdentity(current) || '')
  const stateContextId = String(state.stateContextId || '')
  checkoutBusinessSourcesLoadError.value = ''
  isLoadingCheckoutBusinessSources.value = true
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (loadToken !== checkoutBusinessSourcesLoadToken
      || !isCheckoutOpen.value
      || stateContextId !== String(state.stateContextId || '')
      || requestId !== String(checkoutRequestIdentity(checkout.value) || '')) return
    checkoutInlineBusinessSources.value = Array.isArray(catalog.sources) ? catalog.sources : []
  } catch (error) {
    if (loadToken === checkoutBusinessSourcesLoadToken) {
      checkoutInlineBusinessSources.value = []
      checkoutBusinessSourcesLoadError.value = checkoutSourceSelectorError(error)
    }
  } finally {
    if (loadToken === checkoutBusinessSourcesLoadToken) isLoadingCheckoutBusinessSources.value = false
  }
}

async function loadInlineRechargeBusinessSources() {
  const current = rechargeCheckout.value
  const loadToken = ++checkoutBusinessSourcesLoadToken
  if (!current || current.sourceEnabled !== true || current.sourceSelectable === false) return
  checkoutBusinessSourcesLoadError.value = ''
  isLoadingCheckoutBusinessSources.value = true
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (loadToken !== checkoutBusinessSourcesLoadToken || rechargeCheckout.value !== current) return
    checkoutInlineBusinessSources.value = Array.isArray(catalog.sources) ? catalog.sources : []
  } catch (error) {
    if (loadToken === checkoutBusinessSourcesLoadToken) {
      checkoutInlineBusinessSources.value = []
      checkoutBusinessSourcesLoadError.value = checkoutSourceSelectorError(error)
    }
  } finally {
    if (loadToken === checkoutBusinessSourcesLoadToken) isLoadingCheckoutBusinessSources.value = false
  }
}

watch(
  () => [
    isCheckoutOpen.value,
    checkoutRequestIdentity(checkout.value),
    checkout.value.sourceEnabled,
    checkout.value.sourceSelectable
  ],
  ([isOpen]) => {
    if (isOpen) {
      loadInlineCheckoutBusinessSources()
      return
    }
    checkoutBusinessSourcesLoadToken += 1
    checkoutInlineBusinessSources.value = []
    checkoutBusinessSourcesLoadError.value = ''
    isLoadingCheckoutBusinessSources.value = false
  },
  { immediate: true }
)

watch(
  () => [
    Boolean(rechargeCheckout.value),
    rechargeCheckout.value?.rechargeCheckoutRequestId || '',
    rechargeCheckout.value?.sourceEnabled,
    rechargeCheckout.value?.sourceSelectable
  ],
  ([isOpen]) => {
    if (isOpen) {
      loadInlineRechargeBusinessSources()
      return
    }
    if (!isCheckoutOpen.value) {
      checkoutBusinessSourcesLoadToken += 1
      checkoutInlineBusinessSources.value = []
      checkoutBusinessSourcesLoadError.value = ''
      isLoadingCheckoutBusinessSources.value = false
    }
  },
  { immediate: true }
)

async function openCheckoutBusinessSourceSelector(kind) {
  const current = kind === 'recharge' ? rechargeCheckout.value : checkout.value
  if (!current || current.sourceSelectable === false) {
    return { result: { status: 'failed', code: 'BUSINESS_SOURCE_NOT_SELECTABLE', message: '本次补交继承原订单来源，不能修改。' } }
  }
  checkoutBusinessSourceSelector.value = {
    kind,
    sources: [],
    primarySourceId: Number(current.primarySourceId || 0),
    secondarySourceId: Number(current.secondarySourceId || 0),
    loadError: ''
  }
  // ref 会将对象转成 Proxy；后续身份判断必须使用 ref 内的同一代理对象，
  // 否则成功加载的业务来源会被误判为选择器已经关闭。
  const selector = checkoutBusinessSourceSelector.value
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (checkoutBusinessSourceSelector.value !== selector) return { result: { status: 'failed', code: 'BUSINESS_SOURCE_SELECTOR_CLOSED', message: '业务来源选择已关闭。' } }
    selector.sources = catalog.sources
  } catch (error) {
    if (checkoutBusinessSourceSelector.value === selector) selector.loadError = checkoutSourceSelectorError(error)
  }
  return { result: { status: 'success', message: '业务来源已加载。' } }
}

async function reloadCheckoutBusinessSourceCatalog() {
  const selector = checkoutBusinessSourceSelector.value
  if (!selector) return
  selector.loadError = ''
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (checkoutBusinessSourceSelector.value === selector) selector.sources = catalog.sources
  } catch (error) {
    if (checkoutBusinessSourceSelector.value === selector) selector.loadError = checkoutSourceSelectorError(error)
  }
}

function closeCheckoutBusinessSourceSelector() {
  if (!isSavingCheckoutBusinessSource.value) checkoutBusinessSourceSelector.value = null
}

async function persistCheckoutBusinessSource(kind, selection = {}) {
  if (isSavingCheckoutBusinessSource.value) return null
  const current = kind === 'recharge' ? rechargeCheckout.value : checkout.value
  const primarySourceId = Number(selection.primarySourceId || 0)
  const secondarySourceId = Number(selection.secondarySourceId || 0)
  if (!current || primarySourceId <= 0 || secondarySourceId < 0) return null
  isSavingCheckoutBusinessSource.value = true
  try {
    let result
    if (kind === 'recharge') {
      result = await requestRechargeCheckoutAction({
        action: 'update-checkout-business-source',
        payload: {
          primarySourceId,
          secondarySourceId,
          sourceSelectionVersion: Number(current.sourceSelectionVersion || 0)
        }
      })
    } else {
      result = await enqueueCheckoutAction({
        action: 'update-checkout-business-source',
        payload: {
          primarySourceId,
          secondarySourceId,
          sourceSelectionVersion: Number(current.sourceSelectionVersion || 0),
          // 来源选择也是结账草稿命令，使用已登记的 CHECKOUT 前缀，
          // 不能自行派生 CHECKOUT_SOURCE 等未登记前缀。
          idempotencyKey: createCashierV3CommandId('CHECKOUT')
        }
      })
    }
    return result
  } finally {
    isSavingCheckoutBusinessSource.value = false
  }
}

async function saveCheckoutBusinessSource(selection = {}) {
  const selector = checkoutBusinessSourceSelector.value
  if (!selector) return
  const result = await persistCheckoutBusinessSource(selector.kind, selection)
  if (['success', 'succeeded'].includes(resultStatus(result))) checkoutBusinessSourceSelector.value = null
}

function saveInlineCheckoutBusinessSource(selection = {}) {
  return persistCheckoutBusinessSource('sale', selection)
}

function saveInlineRechargeBusinessSource(selection = {}) {
  return persistCheckoutBusinessSource('recharge', selection)
}

async function saveRechargeBusinessDate(selection = {}) {
  if (isSavingRechargeDate.value) return null
  const businessDate = String(selection.businessDate || '').trim()
  const reason = String(selection.reason || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) return null
  isSavingRechargeDate.value = true
  try {
    return await requestRechargeCheckoutAction({
      action: 'update-recharge-business-date',
      payload: { businessDate, reason }
    })
  } finally {
    isSavingRechargeDate.value = false
  }
}

async function saveCheckoutSalesDate(selection = {}) {
  if (isSavingCheckoutSalesDate.value) return null
  const businessDate = String(selection.businessDate || '').trim()
  const reason = String(selection.reason || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) return null
  isSavingCheckoutSalesDate.value = true
  try {
    return await enqueueCheckoutAction({
      action: 'update-checkout-sales-date',
      payload: {
        businessDate,
        reason,
        idempotencyKey: createCashierV3CommandId('CHECKOUT')
      }
    })
  } finally {
    isSavingCheckoutSalesDate.value = false
  }
}

function isCustomCardPurchase(line = {}) {
  return cartLineRole(line) === 'sale' && [
    line.kindCode,
    line.sourceKind,
    line.cardPurchaseSnapshot?.sourceKind,
    line.authoritySnapshot?.cardPurchase?.sourceKind
  ].some((value) => String(value || '') === 'custom_card')
}

function isProjectLine(line) {
  // `kind` is a display snapshot. Service prerequisites must use the
  // authoritative product type plus the purchase kind because the legacy
  // custom-card shell is physically a project product but never a service.
  return isEntitlementLine(line) || (Number(line.productType) === 6 && !isCustomCardPurchase(line))
}

function cartLineRole(line = {}) {
  return ['sale', 'entitlement_service'].includes(line.lineRole) ? line.lineRole : 'unknown'
}

function positiveVersion(value) {
  const version = Number(value)
  return Number.isInteger(version) && version > 0 ? version : null
}

function isAuthoritativeCartLine(line = {}) {
  const role = cartLineRole(line)
  if (!line.id || !line.name || role === 'unknown' || !Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1) return false
  if (role === 'sale') return true
  return Boolean(entitlementCardHolderId(line) && entitlementBenefitPoolId(line))
    && positiveVersion(line.cardHolderVersion ?? line.entitlementSourceVersion ?? line.sourceVersion) !== null
    && positiveVersion(line.memberBenefitPoolVersion ?? line.projectVersion) !== null
    && typeof line.actualAmount === 'string'
    && /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(line.actualAmount)
    && line.amountRole === 'entitlement_actual'
}

function isCompleteCheckoutComposition(composition, lines, businessType = '') {
  return isCompleteCheckoutCompositionContract(
    composition,
    lines,
    businessType === 'debt_repayment' ? { primaryActionLabel: '确认还款' } : {}
  )
}

function isEntitlementLine(line) {
  return cartLineRole(line) === 'entitlement_service'
}

function cartLineRoleLabel(line) {
  return isEntitlementLine(line) ? '使用权益' : '本次购买'
}

function getLineAmount(line) {
  return line.finalAmount ?? line.amount ?? 0
}

function linePriceChanged(line = {}) {
  return line.originalAmount !== undefined
    && Number(line.originalAmount) !== Number(getLineAmount(line))
}

function formatPlainAmount(value) {
  return String(Number(value || 0))
}

async function requestAction(action, payload = {}) {
  const contextPayload = isServiceOrder.value && serviceBoundActions.has(action) ? serviceOrderCommandPayload() : {}
  return requestCashierV3Action(action, { ...contextPayload, ...payload })
}

let automaticWorkbenchRefreshInFlight = false
const automaticBalanceConflictRecoveries = new Set()
async function refreshWorkbenchAfterContextConflict() {
  if (automaticWorkbenchRefreshInFlight) return
  automaticWorkbenchRefreshInFlight = true
  const shouldRecoverCheckout = isCheckoutOpen.value && Boolean(checkoutSession.value)
  // 当前结账令牌绑定旧资源版本；刷新根投影前必须先废弃，不能让下一次提交继续带旧 token。
  if (shouldRecoverCheckout) {
    isCheckoutOpen.value = false
    checkoutPreparationId.value = null
    checkoutSession.value = null
    checkoutLocalOutcome.value = {}
  }
  try {
    await requestAction('open-cashier-workbench', { silent: true })
    // 只恢复服务端仍明确存在的同一张待结账请求；恢复失败时保留购物车，禁止自动新建或提交结账。
    if (shouldRecoverCheckout) resumePersistedCheckout()
  } finally {
    automaticWorkbenchRefreshInFlight = false
  }
}

function resultStatus(result) {
  const nested = result?.data && typeof result.data === 'object' ? result.data : null
  const response = result?.result && typeof result.result === 'object'
    ? result
    : nested?.result && typeof nested.result === 'object'
      ? nested
      : nested && typeof nested.status === 'string'
        ? nested
      : result
  return response?.result?.status || response?.status || ''
}

function resultMessage(result, fallback) {
  const nested = result?.data && typeof result.data === 'object' ? result.data : {}
  const response = result?.result && typeof result.result === 'object'
    ? result.result
    : nested?.result && typeof nested.result === 'object'
      ? nested.result
      : nested
  return String(response?.message || result?.message || fallback || '保存失败，请重试。').trim()
}

function reportPersonnelAssignmentFailure(result, fallback) {
  const status = resultStatus(result)
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: status === 'conflict' ? 'conflict' : 'failed',
      message: resultMessage(result, fallback)
    }
  }))
}

function reportCartQuantityFailure(result, fallback) {
  const status = resultStatus(result)
  const message = resultMessage(result, fallback)
  cartQuantityValidationError.value = message
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: status === 'conflict' ? 'conflict' : 'failed',
      message
    }
  }))
}

function reportHangOrderFailure(result, fallback) {
  const status = resultStatus(result)
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: status === 'conflict' ? 'conflict' : 'failed',
      message: resultMessage(result, fallback)
    }
  }))
}

async function selectCatalogItem(item) {
  if (activeCardOperationUpgrade.value) {
    reportEntitlementContractError({ message: '当前只能完成本次升级结账；如需重新选择，请先清空购物车。' })
    return
  }
  if (item.id === 'custom-card-entry') {
    if (hasCartLines.value) isCustomCardConflictOpen.value = true
    else if (!currentMemberId.value || cashier.value.customerMode === 'guest') {
      isMemberRequiredOpen.value = true
    } else guidedBusinessMode.value = 'custom-card'
    return
  }
  const operation = previewCardOperation.value
  if (operation && operation.awaitingTarget !== true) {
    reportEntitlementContractError({ message: `当前只能办理${operation.label || '本次卡操作'}，请先完成或取消本次操作。` })
    return
  }
  if (operation?.awaitingTarget) {
    const targetAllowed = operation.mode === 'card-upgrade'
      ? item.kind === '卡项'
      : ['project-replacement', 'project-upgrade'].includes(operation.mode) && item.kind === '项目'
    if (!targetAllowed) {
      reportEntitlementContractError({ message: operation.mode === 'card-upgrade' ? '卡升级只能选择卡项' : '项目操作只能选择项目' })
      return
    }
    previewCardOperation.value = { ...operation, target: clonePlain(item), awaitingTarget: false }
    if (['card-upgrade', 'project-upgrade'].includes(operation.mode)) {
      let result
      try {
        result = await confirmPreviewCardOperation()
      } catch (error) {
        result = { status: 'failed', message: error?.message || '加入购物车失败，请重试。' }
        reportEntitlementContractError(result)
      }
      if (!['success', 'succeeded'].includes(resultStatus(result)) && previewCardOperation.value) {
        reportEntitlementContractError({
          code: result?.result?.code || result?.code || 'CARD_OPERATION_ADD_FAILED',
          message: resultMessage(result, '升级项目加入购物车失败，请重新选择。')
        })
        previewCardOperation.value = { ...previewCardOperation.value, target: null, awaitingTarget: true }
      }
    }
    return
  }
  if (item.kind === '卡项' && (!currentMemberId.value || cashier.value.customerMode === 'guest')) {
    isMemberRequiredOpen.value = true
    return
  }
  if (isRuleCardPurchase(item)) {
    pendingCardPurchase.value = clonePlain(item)
    return
  }
  await appendCatalogItemToDraft(item)
}

let catalogItemAppendQueue = Promise.resolve()
async function appendCatalogItemToDraft(item) {
  const itemId = Number(item?.id || 0)
  const catalogKind = String(item?.kind || '').trim()
  const queued = catalogItemAppendQueue.then(async () => {
    const requestScopeKey = currentCashierDraftScopeKey.value
    // 每次用户点击都由 action bridge 生成新的命令标识，因此同一商品
    // 连续点击会追加多条独立购物车行；只有同一次请求的网络重试才复用
    // 原标识，交由后端幂等保护避免重复写入。
    // 普通项目／产品没有卡内组成资源，提示仅用于省去一次无意义的
    // 服务端目录展开；最终商品、上架和价格仍由事务内权威行锁定校验。
    // 缺失提示时后端按旧客户端兼容路径处理，不能把它当授权依据。
    const result = await requestAction('choose-catalog-item', { itemId, catalogKind })

    // Choosing a catalog item is a write command but intentionally returns a
    // scoped draft instead of replacing the entire workbench root projection.
    // Keep that authoritative draft locally so the cart reflects the committed
    // workspace mutation before the next command is prepared.
    if (['succeeded', 'success'].includes(resultStatus(result))) {
      const draft = responseDataBlock(result).cashierDraft
      // The action bridge may have already applied a verified full root
      // projection while the local scoped-draft contract rejects its response
      // (for example, after the root revision advances). Do not turn a
      // successfully rendered cart into a false user-facing failure.
      if (!await applyCommittedCashierDraft(draft, requestScopeKey)) {
        reportEntitlementContractError({
          code: 'CASHIER_DRAFT_INCOMPLETE',
          message: '购物车权威数据尚未完整返回，系统已自动刷新工作台；如仍未显示请稍后重试。'
        })
      }
    }
    return result
  })
  catalogItemAppendQueue = queued.catch(() => null)
  return queued
}

function renderedCashierDraftMatches(draft) {
  if (!isRecord(draft) || !Array.isArray(draft.lines)) return false
  if (String(draft.workspaceId || '') !== String(state.workspace?.id || '')) return false
  if (String(draft.stateContextId || '') !== String(state.stateContextId || '')) return false
  if (String(draft.customerMode || '') !== String(cashier.value.customerMode || '')) return false
  if (String(draft.memberId || '') !== String(currentMemberId.value || '')) return false

  const renderedCart = cashier.value.cart || {}
  const renderedLines = Array.isArray(renderedCart.lines) ? renderedCart.lines : []
  const expectedLineIds = draft.lines.map((line) => String(line?.id || '')).sort()
  const renderedLineIds = renderedLines.map((line) => String(line?.id || '')).sort()
  if (!expectedLineIds.length || expectedLineIds.join('|') !== renderedLineIds.join('|')) return false

  const renderedSummary = renderedCart.summary || {}
  return hasOwn(renderedSummary, 'selectedCount')
    && hasOwn(renderedSummary, 'receivableAmount')
    && Number(renderedSummary.selectedCount) === Number(draft.summary?.selectedCount)
    && String(renderedSummary.receivableAmount) === String(draft.summary?.receivableAmount)
}

async function ensureCashierDraftRendered(draft) {
  if (renderedCashierDraftMatches(draft)) return true
  await requestAction('open-cashier-workbench', { silent: true })
  return renderedCashierDraftMatches(draft)
}

async function applyCommittedCashierDraft(draft, requestScopeKey) {
  if (isCompleteCashierDraft(draft, requestScopeKey)
    || isResponseBoundCommittedCashierDraft(draft)) {
    cashierDraftSnapshot.value = Object.freeze({
      scopeKey: requestScopeKey,
      snapshot: Object.freeze(clonePlain(draft))
    })
    return true
  }
  return ensureCashierDraftRendered(draft)
}

function preserveEntitlementSelectorAfterDraftCommit(result = {}) {
  const response = responseDataBlock(result)
  const versions = Array.isArray(response?.versions)
    ? response.versions
    : (Array.isArray(result?.versions)
        ? result.versions
        : (Array.isArray(result?.result?.versions) ? result.result.versions : []))
  const workspaceId = String(state.workspace?.id || '')
  const workspaceVersion = versions.find((row) => (
    row?.kind === 'cashier_workspace'
    && String(row?.id || '') === workspaceId
    && Number.isInteger(Number(row?.version))
    && Number(row.version) > 0
  ))
  if (!workspaceVersion || !entitlementSelectorSnapshot.value) return false
  const snapshot = clonePlain(entitlementSelectorSnapshot.value.snapshot)
  const contexts = Array.isArray(snapshot.commandContexts) ? snapshot.commandContexts : []
  const context = contexts.find((row) => (
    row?.kind === 'cashier_workspace' && String(row?.id || '') === workspaceId
  ))
  if (!context) return false
  context.expectedVersion = Number(workspaceVersion.version)
  entitlementSelectorSnapshot.value = Object.freeze({
    scopeKey: entitlementSelectorSnapshot.value.scopeKey,
    snapshot: Object.freeze(snapshot)
  })
  state.workspace = { ...state.workspace, revision: Number(workspaceVersion.version) }
  return true
}

function isResponseBoundCommittedCashierDraft(draft) {
  // The V3 gateway binds the command receipt to its request. The caller keeps
  // this snapshot under that request's stable scope, so a later switch of
  // account, store, workspace, or member cannot render it in the new scope.
  // Do not compare mutable root projection fields here: the command itself
  // is allowed to rebuild them before its receipt reaches the page.
  if (!isRecord(draft)
    || draft.complete !== true
    || !String(draft.workspaceId || '')
    || !String(draft.stateContextId || '')
    || !['member', 'guest'].includes(draft.customerMode)) return false
  if (draft.customerMode === 'member' && Number(draft.memberId || 0) <= 0) return false
  if (draft.customerMode === 'guest' && Number(draft.memberId || 0) !== 0) return false
  if (!Array.isArray(draft.lines) || !draft.lines.every(isAuthoritativeCartLine)) return false
  if (!isRecord(draft.summary)
    || !hasOwn(draft.summary, 'selectedCount')
    || !hasOwn(draft.summary, 'receivableAmount')) return false
  return isRecord(draft.checkoutComposition)
    && typeof draft.primaryAction === 'string'
    && typeof draft.primaryActionLabel === 'string'
}

function isRuleCardPurchase(item = {}) {
  return item.kind === '卡项'
    && ['normal', 'choice_kind', 'choice_count', 'time'].includes(item.cardRuleType)
    && item.cardPreview
    && Array.isArray(item.cardPreview.components)
}

function cardPreviewRuleDescription(preview = {}) {
  if (preview.ruleType === 'choice_kind') return `卡内项目任选，最多选择 ${preview.choiceLimit} 种。`
  if (preview.ruleType === 'choice_count') return `卡内项目共享 ${preview.sharedTimes} 次。`
  if (preview.ruleType === 'time') return '有效期内不限次，每个项目按配置的单次核销金额核销。'
  return '卡内项目按各自配置的次数核销。'
}

function cardPreviewValidityText(validity = {}) {
  if (Number(validity.writeValid) === 1) return '长期有效'
  if (Number(validity.writeValid) === 2 && Number(validity.writeDays) > 0) {
    return `开卡后 ${validity.writeDays} 天有效`
  }
  if (Number(validity.writeValid) === 3 && Number(validity.writeStart) > 0 && Number(validity.writeEnd) > 0) {
    const format = (value) => new Intl.DateTimeFormat('zh-CN', {
      year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(new Date(Number(value) * 1000))
    return `${format(validity.writeStart)} 至 ${format(validity.writeEnd)} 有效`
  }
  return '按卡项配置的有效期执行'
}

function closePendingCardPurchase() {
  if (isConfirmingCardPurchase.value) return
  pendingCardPurchase.value = null
}

async function confirmPendingCardPurchase() {
  const item = pendingCardPurchase.value
  if (!item?.id || isConfirmingCardPurchase.value) return
  isConfirmingCardPurchase.value = true
  try {
    // 卡项预览确认也要传完整目录项，不能只传 id；新增的项目／产品
    // 加购优化会读取 kind 作为后端轻量路径提示，传 id 会把它丢失并
    // 被服务端当成“无效商品”拒绝。
    const result = await appendCatalogItemToDraft(item)
    if (['success', 'succeeded'].includes(resultStatus(result))) pendingCardPurchase.value = null
    return result
  } finally {
    isConfirmingCardPurchase.value = false
  }
}

async function handleOpenCardOperation(event = {}) {
  const detail = event.detail || {}
  if (!cardOperationTypeByMode[detail.operation]) {
    reportEntitlementContractError({ message: '该卡操作尚未进入当前收银流程，请重新选择。' })
    return
  }
  if (hasCartLines.value) {
    const cleared = await confirmClearCart()
    if (!['success', 'succeeded'].includes(resultStatus(cleared))) return
  }
  previewCardOperation.value = {
    mode: detail.operation || '',
    label: detail.label || '',
    sources: [],
    target: null,
    awaitingTarget: false,
    reason: '',
    selectorContexts: []
  }
  await openEntitlementSelector()
}

function handleOperationSource({ source } = {}) {
  const operation = previewCardOperation.value || {}
  const selectorContexts = clonePlain(entitlementSelector.value?.commandContexts || [])
  if (previewCardOperation.value?.mode === 'card-transfer') {
    previewCardOperation.value = {
      ...operation,
      sources: [source],
      target: null,
      awaitingTarget: true,
      selectorContexts
    }
    finalizeEntitlementSelector()
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'card-transfer', selectorContext: 'card-transfer' }
    }))
    return
  }
  previewCardOperation.value = {
    ...operation,
    sources: [source],
    target: null,
    awaitingTarget: true,
    selectorContexts
  }
  beginCardOperationTargetSelection(previewCardOperation.value)
  finalizeEntitlementSelector()
}

function handleOperationProject({ source, project } = {}) {
  const operation = previewCardOperation.value || {}
  const sourceCardHolderId = entitlementCardHolderId(source)
  const sourceCardHolderVersion = positiveVersion(source?.version ?? source?.revision)
  if (!sourceCardHolderId || sourceCardHolderVersion === null) {
    reportEntitlementContractError({ message: '原卡版本不完整，请重新打开使用权益后再办理。' })
    return
  }
  const detailId = entitlementBenefitPoolId(project)
  if (!detailId) {
    reportEntitlementContractError({ message: '原项目权益不完整，请重新打开使用权益后再办理。' })
    return
  }
  const selectedSource = {
    ...project,
    cardName: source?.name || '会员卡',
    cardHolderId: sourceCardHolderId,
    cardHolderVersion: sourceCardHolderVersion,
    entitlementSourceDetailId: detailId,
    quantity: 1
  }
  const existingSources = operation.mode === 'project-replacement'
    ? (operation.sources || [])
    : []
  if (existingSources.length && existingSources.some((selected) => String(entitlementCardHolderId(selected)) !== String(sourceCardHolderId))) {
    reportEntitlementContractError({ message: '项目替换只能选择同一张会员卡内的项目。' })
    return
  }
  if (existingSources.some((selected) => String(entitlementBenefitPoolId(selected)) === String(detailId))) return
  previewCardOperation.value = {
    ...operation,
    sources: [...existingSources, selectedSource],
    target: null,
    awaitingTarget: operation.mode === 'project-upgrade',
    selectorContexts: clonePlain(entitlementSelector.value?.commandContexts || [])
  }
  if (operation.mode === 'project-upgrade') {
    beginCardOperationTargetSelection(previewCardOperation.value)
    finalizeEntitlementSelector()
  }
}

function handleOperationTargetSelection() {
  const operation = previewCardOperation.value
  if (operation?.mode !== 'project-replacement' || !operation.sources?.length) return
  previewCardOperation.value = { ...operation, awaitingTarget: true }
  beginCardOperationTargetSelection(previewCardOperation.value)
  finalizeEntitlementSelector()
}

function sourceCardOperationContext(source = {}) {
  const holderId = String(entitlementCardHolderId(source) || '').trim()
  const version = positiveVersion(source.cardHolderVersion ?? source.version ?? source.revision)
  if (!holderId || version === null) return null
  return { kind: 'card_holder', id: holderId, expectedVersion: version }
}

function cardOperationCommandContexts(operation = {}, source = {}, operationType = '') {
  const sourceContext = sourceCardOperationContext(source)
  if (!sourceContext) return null

  const contexts = [sourceContext]
  if (!cardOperationUpgradeTypes.has(operationType)) return contexts

  const selectorContexts = Array.isArray(operation.selectorContexts) ? operation.selectorContexts : []
  const selectorWorkspace = selectorContexts
    .map(normalizeEntitlementCommandContext)
    .find((context) => context?.kind === 'cashier_workspace')
  const workspaceId = String(state.workspace?.id || selectorWorkspace?.id || '').trim()
  const workspaceVersion = positiveVersion(state.workspace?.revision)
    ?? positiveVersion(state.workspace?.version)
    ?? positiveVersion(selectorWorkspace?.expectedVersion)
  if (!workspaceId || workspaceVersion === null) return null
  contexts.push({ kind: 'cashier_workspace', id: workspaceId, expectedVersion: workspaceVersion })
  return contexts
}

function operationEndOfDayTimestamp(date = '') {
  const normalized = String(date || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(normalized)) return null
  const value = Date.parse(`${normalized}T23:59:59+08:00`)
  return Number.isFinite(value) ? Math.floor(value / 1000) : null
}

async function submitDirectCardOperation({ source, date = '', reason = '' } = {}) {
  const operation = previewCardOperation.value || {}
  const operationType = cardOperationTypeByMode[operation.mode]
  const sourceContext = sourceCardOperationContext(source)
  const normalizedReason = String(reason || '').trim()
  const reasonRequired = cardOperationReasonModes.has(operation.mode)
  const commandContexts = cardOperationCommandContexts(operation, source, operationType)
  if (!operationType || !sourceContext || !commandContexts || (reasonRequired && !normalizedReason)) {
    const message = !sourceContext
      ? '来源卡数据不完整，请重新选择。'
      : !commandContexts
        ? '当前收银购物车尚未准备完成，请刷新后重试。'
        : reasonRequired && !normalizedReason
          ? '请填写本次操作原因。'
          : '当前卡操作类型无效，请重新选择。'
    reportEntitlementContractError({ message })
    return { result: { status: 'failed', code: 'CARD_OPERATION_CONTEXT_INCOMPLETE', message } }
  }

  const payload = {
    operationType,
    sourceCardHolderId: sourceContext.id,
    sourceCardHolderVersion: sourceContext.expectedVersion,
    commandContexts,
    idempotencyKey: createCashierV3CommandId('CARD_OPERATION')
  }
  if (normalizedReason) payload.reason = normalizedReason
  if (operationType === 'card_extension') {
    const newWriteEnd = operationEndOfDayTimestamp(date)
    if (newWriteEnd === null) {
      reportEntitlementContractError({ message: '新的有效期无效，请重新选择。' })
      return { result: { status: 'failed', code: 'CARD_OPERATION_DATE_INVALID' } }
    }
    payload.newWriteEnd = newWriteEnd
  }
  if (operationType === 'card_transfer') {
    const targetMemberId = String(
      operation.target?.memberId ?? operation.target?.id ?? ''
    ).trim()
    if (!/^[1-9]\d*$/.test(targetMemberId)) {
      reportEntitlementContractError({ message: '请选择有效的接收会员。' })
      return { result: { status: 'failed', code: 'CARD_OPERATION_TARGET_MEMBER_MISSING' } }
    }
    payload.targetMemberId = targetMemberId
  }
  if (cardOperationTargetCatalogTypes.has(operationType)) {
    const targetCatalogId = String(
      operation.target?.catalogItemId ?? operation.target?.skuId ?? operation.target?.id ?? ''
    ).trim()
    if (!/^[1-9]\d*$/.test(targetCatalogId)) {
      const targetLabel = operationType === 'card_upgrade' ? '目标卡项' : '目标项目'
      reportEntitlementContractError({ message: `请选择有效的${targetLabel}。` })
      return {
        result: {
          status: 'failed',
          code: operationType === 'card_upgrade'
            ? 'CARD_OPERATION_TARGET_CARD_MISSING'
            : 'CARD_OPERATION_TARGET_PROJECT_MISSING'
        }
      }
    }
    payload.targetCatalogId = targetCatalogId
  }
  if (cardOperationProjectTypes.has(operationType)) {
    const linesByDetail = new Map()
    for (const selected of operation.sources || []) {
      if (String(entitlementCardHolderId(selected) || '') !== sourceContext.id) {
        reportEntitlementContractError({ message: '项目替换只能选择同一张会员卡内的项目。' })
        return { result: { status: 'failed', code: 'CARD_OPERATION_SOURCE_CARD_MISMATCH' } }
      }
      const detailId = String(entitlementBenefitPoolId(selected) || '').trim()
      if (!/^[1-9]\d*$/.test(detailId)) {
        reportEntitlementContractError({ message: '原项目权益不完整，请重新选择。' })
        return { result: { status: 'failed', code: 'CARD_OPERATION_SOURCE_PROJECT_MISSING' } }
      }
      linesByDetail.set(detailId, (linesByDetail.get(detailId) || 0) + 1)
    }
    payload.projectLines = Array.from(linesByDetail, ([sourceDetailId, quantity]) => ({ sourceDetailId, quantity }))
  }

  if (new URLSearchParams(window.location.search).get('preview') === '1') {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'success', message: `${operation.label || '卡操作'}预览已确认；预览模式不会写入业务数据。` }
    }))
    finalizeEntitlementSelector()
    previewCardOperation.value = null
    return { result: { status: 'success', preview: true } }
  }

  if (isSubmittingCardOperation.value) {
    return { result: { status: 'failed', code: 'CARD_OPERATION_SUBMITTING', message: '正在提交卡操作，请勿重复点击。' } }
  }
  isSubmittingCardOperation.value = true
  try {
    const result = await requestCashierV3Action('submit-card-operation', payload)
    if (['success', 'succeeded'].includes(resultStatus(result))) {
      const completedMode = operation.mode
      const cardOperation = responseDataBlock(result).cardOperation || {}
      const pendingCheckout = Boolean(cardOperation.requiresCheckout)
      finalizeEntitlementSelector()
      previewCardOperation.value = null
      if (pendingCheckout) {
        // 清空旧购物车时会留下一个本地空草稿快照。升级成功后必须先用
        // 当前命令返回的权威补价行替换它，不能把一次工作台重读当作展示
        // 的唯一来源，否则刷新响应尚未抵达时页面会继续显示空购物车。
        const committedDraft = responseDataBlock(result).cashierDraft
        const applied = await applyCommittedCashierDraft(
          committedDraft,
          currentCashierDraftScopeKey.value
        )
        if (!applied) {
          // 服务端命令已成功，不能把旧的空草稿继续展示为当前事实。
          cashierDraftSnapshot.value = null
        }
        // 根状态仍后台刷新，用于同步非购物车区域；本次升级行已由上面的
        // 命令回执接管，不依赖这次异步刷新才能显示。
        requestAction('open-cashier-workbench', { silent: true }).catch(() => undefined)
        window.dispatchEvent(new CustomEvent('cashier-v3:card-operation-awaiting-checkout', {
          detail: { cardOperation }
        }))
        window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
          detail: { status: 'success', message: '升级项目已加入购物车。' }
        }))
        return result
      }
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'success', message: `${operation.label || '卡操作'}已完成。请重新打开权益列表查看最新状态。` }
      }))
      if (completedMode === 'project-replacement') await openEntitlementSelector()
    }
    return result
  } finally {
    isSubmittingCardOperation.value = false
  }
}

async function handleOperationConfirm({ source, date, reason } = {}) {
  return submitDirectCardOperation({ source, date, reason })
}

function selectorMemberId(selector = entitlementSelector.value) {
  return selector.member?.id || selector.member?.memberId || selector.memberId || ''
}

function entitlementContextMap(contexts = []) {
  const normalized = new Map()
  for (const supplied of Array.isArray(contexts) ? contexts : []) {
    const context = normalizeEntitlementCommandContext(supplied)
    if (!context) return null
    const key = `${context.kind}:${context.id}`
    const previous = normalized.get(key)
    if (previous && previous.expectedVersion !== context.expectedVersion) return null
    normalized.set(key, context)
  }
  return normalized
}

function isCompleteEntitlementSelector(selector, requestId) {
  const sources = Array.isArray(selector?.sources) ? selector.sources : []
  if (!isRecord(selector)
    || selector.ready !== true
    || String(selector.selectorRequestId || '') !== String(requestId || '')
    || !Boolean(selector.selectorToken || selector.snapshotToken)
    || String(selectorMemberId(selector)) !== String(currentMemberId.value)
    || !Array.isArray(selector.commandContexts)) return false

  const contexts = entitlementContextMap(selector.commandContexts)
  if (!contexts) return false
  const workspaceId = String(state.workspace?.id || '')
  const memberId = String(currentMemberId.value || '')
  if (!contexts.has(`cashier_workspace:${workspaceId}`) || !contexts.has(`member:${memberId}`)) return false

  return sources.every((source) => {
    const cardHolderId = entitlementCardHolderId(source)
    const holderVersion = positiveVersion(source.cardHolderVersion ?? source.version ?? source.revision)
    const holderContext = contexts.get(`card_holder:${cardHolderId}`)
    if (!cardHolderId || holderVersion === null || holderContext?.expectedVersion !== holderVersion || !Array.isArray(source.projects)) return false
    return source.projects.every((project) => {
      const projectId = project.projectId || project.id
      const benefitPoolId = entitlementBenefitPoolId(project)
      const poolVersion = positiveVersion(project.memberBenefitPoolVersion ?? project.version ?? project.revision)
      const poolContext = contexts.get(`member_benefit_pool:${benefitPoolId}`)
      return Boolean(projectId && benefitPoolId)
        && poolVersion !== null
        && poolContext?.expectedVersion === poolVersion
    })
  })
}

function isCompleteCashierDraft(draft, requestScopeKey) {
  if (!isRecord(draft) || requestScopeKey !== currentCashierDraftScopeKey.value) return false
  if (draft.complete !== true
    || String(draft.workspaceId || '') !== String(state.workspace?.id || '')
    || String(draft.stateContextId || '') !== String(state.stateContextId || '')) return false
  const expectedMode = cashierScopeIdentity.value.customerMode
  if (draft.customerMode !== expectedMode) return false
  if (expectedMode === 'member' && String(draft.memberId || '') !== String(currentMemberId.value || '')) return false
  if (expectedMode === 'guest' && Number(draft.memberId || 0) !== 0) return false
  if (!Array.isArray(draft.lines) || !draft.lines.every(isAuthoritativeCartLine)) return false
  if (new Set(draft.lines.map((line) => String(line.id))).size !== draft.lines.length) return false
  if (!isRecord(draft.summary) || !hasOwn(draft.summary, 'selectedCount') || !hasOwn(draft.summary, 'receivableAmount')) return false
  if (!isCompleteCheckoutComposition(draft.checkoutComposition, draft.lines)) return false
  return String(draft.primaryAction || '') === String(draft.checkoutComposition.primaryAction || '')
}

function reportEntitlementContractError(error = {}) {
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: 'failed',
      code: error.code || 'ENTITLEMENT_RESPONSE_INCOMPLETE',
      message: error.message || '会员权益数据尚未完整加载，请关闭后重新打开。',
      feedback: null,
      navigation: null,
      overlay: null,
      requiresRefresh: true
    }
  }))
}

function unresolvedDraftCommandResult(ticket) {
  const result = {
    result: {
      status: 'result_unknown',
      code: 'COMMAND_RESULT_UNKNOWN_REQUIRES_SAME_RETRY',
      message: '上一次购物车操作结果未知，请回到原操作重试；系统会自动沿用原内容和原请求标识。'
    },
    idempotencyKey: ticket?.idempotencyKey || '',
    retryIdempotencyMode: 'same',
    canRetry: true
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      ...result.result,
      idempotencyKey: result.idempotencyKey,
      retryIdempotencyMode: 'same',
      canRetry: true,
      canClose: false
    }
  }))
  return result
}

function invalidateEntitlementSelector() {
  entitlementSelectorSnapshot.value = null
  isEntitlementSelectorOpen.value = false
  entitlementSelectorRequestId.value = null
  entitlementSelectorLoadState.value = 'idle'
  entitlementSelectorLoadError.value = ''
}

function showEntitlementSelectorLoadError(message = '') {
  entitlementSelectorSnapshot.value = null
  entitlementSelectorRequestId.value = null
  entitlementSelectorLoadState.value = 'error'
  entitlementSelectorLoadError.value = String(message || '会员权益加载失败，请重新加载后再试。')
  isEntitlementSelectorOpen.value = true
}

async function openEntitlementSelector({ preserveSnapshot = false } = {}) {
  if (!member.value || cashier.value.customerMode === 'guest') {
    pendingEntitlementSelector.value = true
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'cashier', selectorContext: 'cashier' }
    }))
    return { success: false, status: 'member_required' }
  }
  if (isOpeningEntitlementSelector.value) return { success: false, message: '正在加载会员权益，请勿重复操作。' }

  const requestEpoch = cashierContextEpoch.value
  const requestScopeKey = currentCashierScopeKey.value
  const selectorRequestId = createCashierV3CommandId('ENTITLEMENT_SELECTOR')
  if (!preserveSnapshot) entitlementSelectorSnapshot.value = null
  entitlementSelectorRequestId.value = selectorRequestId
  entitlementSelectorLoadState.value = 'loading'
  entitlementSelectorLoadError.value = ''
  isEntitlementSelectorOpen.value = true
  isOpeningEntitlementSelector.value = true
  try {
    const result = await requestAction('open-add-card-service-project', {
      memberId: member.value.id || member.value.memberId,
      selectorRequestId,
      cardOperationMode: previewCardOperation.value?.mode || '',
      ...serviceOrderCommandPayload()
    })
    if (requestEpoch !== cashierContextEpoch.value) {
      invalidateEntitlementSelector()
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次权益查询结果已忽略。' } }
    }
    if (['failed', 'conflict'].includes(resultStatus(result))) {
      showEntitlementSelectorLoadError(result?.result?.message || '会员权益加载失败，请重新加载后再试。')
      return result
    }
    const selector = responseDataBlock(result).entitlementSelector
    if (requestScopeKey !== currentCashierScopeKey.value || !isCompleteEntitlementSelector(selector, selectorRequestId)) {
      const invalidSelector = {
        result: {
          status: 'failed',
          code: 'ENTITLEMENT_SELECTOR_INCOMPLETE',
          message: '会员权益尚未完整加载，请重新打开后再选择。'
        },
        requiresRefresh: true
      }
      showEntitlementSelectorLoadError(invalidSelector.result.message)
      reportEntitlementContractError(invalidSelector.result)
      return invalidSelector
    }
    entitlementSelectorSnapshot.value = Object.freeze({
      scopeKey: requestScopeKey,
      snapshot: Object.freeze(clonePlain(selector))
    })
    entitlementSelectorLoadState.value = 'ready'
    isEntitlementSelectorOpen.value = true
    return result
  } catch (error) {
    invalidateEntitlementSelector()
    const unavailable = {
      result: {
        status: 'failed',
        code: 'ENTITLEMENT_SELECTOR_UNAVAILABLE',
        message: '会员权益加载失败，请重新打开后再选择。',
        detail: error instanceof Error ? error.message : String(error || '')
      }
    }
    showEntitlementSelectorLoadError(unavailable.result.message)
    reportEntitlementContractError(unavailable.result)
    return unavailable
  } finally {
    isOpeningEntitlementSelector.value = false
  }
}

async function addEntitlementLines(payload = {}) {
  if (isAddingEntitlementLines.value || !isCompleteEntitlementSelector(entitlementSelector.value, entitlementSelectorRequestId.value)) {
    return { result: { status: 'failed', code: 'ENTITLEMENT_SELECTOR_SESSION_EXPIRED', message: '权益选择会话已失效，请关闭后重新打开。' } }
  }
  const requestScopeKey = currentCashierDraftScopeKey.value
  const projectKey = String(payload.projectKey || '')
  const addIntentId = String(payload.addIntentId || createCashierV3CommandId('ENTITLEMENT_ADD'))
  // 普通“使用权益”在打开选择器时已经取得最新展示数据。这里仅确保该
  // 快照随着命令完整传递；不会为添加动作再次读取卡项、余次或库存。
  const commandPayload = {
    ...payload,
    lines: normalizeEntitlementAppendLines(payload.lines),
    addIntentId,
    mutationMode: 'append'
  }
  delete commandPayload.projectKey
  const existingLineIds = new Set(cartLines.value.map((line) => String(line?.id || '')))
  const retryTicket = draftCommandRecovery.begin({
    operationKey: `add-entitlement:${draftRecoveryScopeKey.value}:${projectKey}`,
    scopeKey: draftRecoveryScopeKey.value,
    action: 'add-checkout-entitlement-lines',
    payload: commandPayload,
    idempotencyPrefix: 'ADD_ENTITLEMENT'
  })
  cashierDraftHasUnresolvedCommand.value = true
  if (!retryTicket.accepted) {
    // A previous draft response can be lost while the server has already
    // accepted it. Recover that exact command first, then continue the
    // operator's current edit without asking them to decide what to retry.
    const recovered = await recoverPendingDraftCommand()
    if (!recovered) return unresolvedDraftCommandResult(retryTicket)
    return addEntitlementLines(payload)
  }
  pendingEntitlementProjectKey.value = projectKey
  isAddingEntitlementLines.value = true
  try {
    const result = await requestAction('add-checkout-entitlement-lines', {
      ...retryTicket.payload,
      idempotencyKey: retryTicket.idempotencyKey
    })
    const status = resultStatus(result)
    if (['failed', 'conflict'].includes(status)) {
      draftCommandRecovery.settle(retryTicket, status)
      cashierDraftHasUnresolvedCommand.value = Boolean(
        draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
      )
    }
    if (['succeeded', 'success'].includes(status)) {
      const draft = responseDataBlock(result).cashierDraft
      if (!await applyCommittedCashierDraft(draft, requestScopeKey)) {
        const invalidDraft = {
          result: {
            status: 'failed',
            code: 'CASHIER_DRAFT_INCOMPLETE',
            message: '购物车权威数据尚未完整返回，系统已自动刷新工作台；如仍未显示请稍后重试。'
          },
          requiresRefresh: true
        }
        reportEntitlementContractError(invalidDraft.result)
        return invalidDraft
      }
      draftCommandRecovery.settle(retryTicket, status)
      cashierDraftHasUnresolvedCommand.value = Boolean(
        draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
      )
      if (projectKey) {
        addedEntitlementProjectKey.value = projectKey
        const appendedLine = (draft.lines || []).find((line) => (
          line?.lineRole === 'entitlement_service'
          && !existingLineIds.has(String(line.id || ''))
        ))
        activeCartLineId.value = appendedLine?.id || null
        addedEntitlementLineId.value = String(appendedLine?.id || '')
        if (entitlementAddedTimer) window.clearTimeout(entitlementAddedTimer)
        entitlementAddedTimer = window.setTimeout(() => {
          addedEntitlementProjectKey.value = ''
          addedEntitlementLineId.value = ''
          entitlementAddedTimer = null
        }, 700)
      }
      // 添加草稿不会消耗权益；响应若未携带下一版工作台上下文，就在
      // 当前页面静默换取新的选择器会话，保留组件内的查询与筛选状态。
      if (!preserveEntitlementSelectorAfterDraftCommit(result)) {
        await openEntitlementSelector({ preserveSnapshot: true })
      }
    }
    return result
  } finally {
    pendingEntitlementProjectKey.value = ''
    isAddingEntitlementLines.value = false
  }
}

function normalizeEntitlementAppendLines(lines = []) {
  if (!Array.isArray(lines)) return []
  return lines.map((line) => {
    const current = line && typeof line === 'object' ? line : {}
    if (current.displaySnapshot && typeof current.displaySnapshot === 'object'
      && !Array.isArray(current.displaySnapshot)
      && Object.keys(current.displaySnapshot).length) {
      return current
    }

    const holderId = String(current.entitlementInstanceId || current.cardHolderId || '')
    const detailId = String(current.entitlementSourceDetailId || current.memberBenefitPoolId || '')
    const source = (entitlementSelector.value?.sources || []).find((candidate) => (
      String(candidate?.entitlementInstanceId || candidate?.cardHolderId || candidate?.id || '') === holderId
    )) || {}
    const project = (source.projects || []).find((candidate) => (
      String(candidate?.entitlementSourceDetailId || candidate?.memberBenefitPoolId || candidate?.sourceDetailId || candidate?.id || '') === detailId
    )) || {}
    const sourceVersion = Number(current.entitlementSourceVersion || source.version || source.revision || 0)
    const projectVersion = Number(current.projectVersion || project.version || project.revision || 0)
    return {
      ...current,
      entitlementSourceVersion: sourceVersion,
      projectVersion,
      displaySnapshot: {
        name: String(project.name || project.projectName || '项目'),
        kind: '项目',
        entitlementInstanceType: String(source.entitlementInstanceType || source.sourceType || ''),
        entitlementSourceKind: project.isGift ? 'gift' : String(source.sourceKind || ''),
        isGift: Boolean(project.isGift),
        giftSourceType: project.isGift ? 'holder_backed' : 'none',
        sourceDetailId: Number(detailId || 0),
        detailVersion: projectVersion,
        entitlementSourceName: String(source.name || ''),
        fullCardNo: String(source.fullCardNo || ''),
        remainingTimes: Number(project.remainingTimes || 0),
        occupiedTimes: Number(project.occupiedTimes || 0),
        availableTimes: Number(project.availableTimes || 0),
        purchaseAmount: String(project.purchaseAmount || ''),
        totalPurchaseTimes: Number(project.totalPurchaseTimes || 0),
        consumedTimesAtSelection: Number(project.consumedTimesAtSelection || 0),
        amountSourceVersion: projectVersion,
        amountCalculationVersion: String(project.amountCalculationVersion || ''),
        amountRole: 'entitlement_actual',
        validThroughLabel: String(project.validThroughLabel || source.validThroughLabel || ''),
        expiryDate: String(project.expiryDate || source.expiryDate || ''),
        debtRestrictionLabel: String(project.debtRestrictionLabel || ''),
        serviceSource: '卡内项目'
      }
    }
  })
}

function draftLineCommandContexts(line = {}, action = '') {
  const workspaceId = String(state.workspace?.id || '')
  if (!workspaceId) return []
  const contexts = [{ kind: 'cashier_workspace', id: workspaceId, expectedVersion: Number(state.workspace?.revision) }]
  if (!isEntitlementLine(line) || action === 'remove-cart-line') return contexts
  const memberId = String(line.memberId || currentMemberId.value || '')
  const holderId = String(line.entitlementInstanceId || line.cardHolderId || '')
  const detailId = String(line.entitlementSourceDetailId || line.memberBenefitPoolId || '')
  if (!memberId || !holderId || !detailId) return []
  contexts.unshift(
    { kind: 'member', id: memberId, expectedVersion: 1 },
    { kind: 'member_benefit_pool', id: detailId, expectedVersion: Number(line.projectVersion || 1) },
    { kind: 'card_holder', id: holderId, expectedVersion: Number(line.entitlementSourceVersion || 1) }
  )
  return contexts
}

function enqueueCashierDraftMutation(action, line, payload, executor) {
  const queuedScopeKey = currentCashierDraftScopeKey.value
  const queuedLineId = String(line?.id || '')
  const run = () => {
    if (!queuedScopeKey || currentCashierDraftScopeKey.value !== queuedScopeKey) {
      return {
        result: {
          status: 'failed',
          code: 'CASHIER_DRAFT_QUEUE_EXPIRED',
          message: '购物车已经切换，未提交之前排队的编辑。'
        }
      }
    }
    const latestLine = cartLines.value.find((item) => String(item?.id || '') === queuedLineId)
    if (!latestLine) {
      return action === 'remove-cart-line'
        ? { result: { status: 'succeeded', code: 'CASHIER_DRAFT_MUTATION_ALREADY_APPLIED', message: '' } }
        : { result: { status: 'failed', code: 'CASHIER_DRAFT_LINE_CHANGED', message: '购物车项目已经变化，本次编辑未提交。' } }
    }
    return executor(action, latestLine, payload)
  }
  const queued = cashierDraftMutationTail.then(run, run)
  cashierDraftMutationTail = queued.catch(() => undefined)
  return queued
}

function mutateCashierDraft(action, line, payload = {}) {
  return enqueueCashierDraftMutation(action, line, payload, executeCashierDraftMutation)
}

function mutateRootCashierDraft(action, line, payload = {}) {
  return enqueueCashierDraftMutation(action, line, payload, (queuedAction, latestLine, queuedPayload) => (
    requestAction(queuedAction, { ...queuedPayload, lineId: latestLine.id })
  ))
}

async function executeCashierDraftMutation(action, line, payload = {}) {
  const requestScopeKey = currentCashierDraftScopeKey.value
  const commandContexts = draftLineCommandContexts(line, action)
  if (!commandContexts.length) {
    return { result: { status: 'failed', code: 'INVALID_COMMAND_CONTEXT', message: '购物车版本尚未完整加载，请刷新后重试。' } }
  }
  const idempotencyPrefix = {
    'remove-cart-line': 'REMOVE_CART_LINE',
    'change-cart-line-quantity': 'CHANGE_CART_QUANTITY',
    'update-cart-line-service-settings': 'CART_SERVICE_SETTINGS',
    'update-cashier-line-debt': 'CASHIER_LINE_DEBT',
    'apply-line-coupon': 'APPLY_LINE_COUPON',
    'remove-line-coupon': 'REMOVE_LINE_COUPON'
  }[action]
  const commandPayload = {
    ...payload,
    lineId: line.id,
    commandContexts
  }
  const retryTicket = draftCommandRecovery.begin({
    operationKey: `draft-mutation:${draftRecoveryScopeKey.value}:${action}:${line.id}`,
    scopeKey: draftRecoveryScopeKey.value,
    action,
    payload: commandPayload,
    idempotencyPrefix: idempotencyPrefix || 'CASHIER_DRAFT'
  })
  cashierDraftHasUnresolvedCommand.value = true
  if (!retryTicket.accepted) return unresolvedDraftCommandResult(retryTicket)
  const result = await requestAction(action, {
    ...retryTicket.payload,
    idempotencyKey: retryTicket.idempotencyKey
  })
  const status = resultStatus(result)
  if (['failed', 'conflict'].includes(status)) {
    draftCommandRecovery.settle(retryTicket, status)
    cashierDraftHasUnresolvedCommand.value = Boolean(
      draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
    )
  }
  if (['succeeded', 'success'].includes(status)) {
    const draft = responseDataBlock(result).cashierDraft
    if (!await applyCommittedCashierDraft(draft, requestScopeKey)) {
      const invalid = {
        result: {
          status: 'failed',
          code: 'CASHIER_DRAFT_INCOMPLETE',
          message: '购物车权威数据尚未完整返回，系统已自动刷新工作台；如仍未显示请稍后重试。'
        },
        requiresRefresh: true
      }
      reportEntitlementContractError(invalid.result)
      return invalid
    }
    draftCommandRecovery.settle(retryTicket, status)
    cashierDraftHasUnresolvedCommand.value = Boolean(
      draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
    )
  }
  return result
}

function sameStaffIds(left = [], right = []) {
  const normalize = (records) => Array.from(new Set((Array.isArray(records) ? records : [])
    .map((record) => String(record?.staffId || record?.id || '').trim())
    .filter(Boolean))).sort()
  return JSON.stringify(normalize(left)) === JSON.stringify(normalize(right))
}

function resolveReflectedDraftCommand() {
  const ticket = draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  if (ticket?.action !== 'update-cart-line-service-settings') return false
  const line = cartLines.value.find((candidate) => String(candidate?.id || '') === String(ticket.payload?.lineId || ''))
  if (!line) return false

  // 仅当服务端当前权威购物车已包含完全相同的人员分配时，才处理旧页面
  // 断连前遗留的恢复锁；不能据此推断任何未反映到权威草稿的写命令已成功。
  const requestedCraftsmen = ticket.payload?.craftsmen
  if (Array.isArray(requestedCraftsmen) && !sameStaffIds(line.craftsmen, requestedCraftsmen)) return false
  const requestedSalespeople = ticket.payload?.salespeople
  if (Array.isArray(requestedSalespeople) && !sameStaffIds(line.salespeople, requestedSalespeople)) return false
  if (ticket.payload?.serviceObject && cartLineServiceObject(line) !== ticket.payload.serviceObject) return false
  if (Object.prototype.hasOwnProperty.call(ticket.payload || {}, 'isExperience')
    && Boolean(line.isExperience) !== Boolean(ticket.payload.isExperience)) return false

  draftCommandRecovery.settle(ticket, 'success')
  cashierDraftHasUnresolvedCommand.value = Boolean(
    draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  )
  return !cashierDraftHasUnresolvedCommand.value
}

/**
 * 草稿写入在响应丢失时必须由系统沿用原幂等键恢复，不能让收银员决定是否重试。
 * 重放后统一回读权威工作台；只有服务端明确给出终态才释放该恢复票据。
 */
async function recoverPendingDraftCommand() {
  const ticket = draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  if (!ticket) return true
  if (resolveReflectedDraftCommand()) return true

  const result = await requestAction(ticket.action, {
    ...(ticket.payload || {}),
    idempotencyKey: ticket.idempotencyKey
  })
  const status = resultStatus(result)
  if (['success', 'succeeded', 'failed', 'conflict'].includes(status)) {
    draftCommandRecovery.settle(ticket, status)
  }

  // 同键重放可能返回已保存的旧投影；重新读取完整根状态后再继续下一动作。
  await requestAction('open-cashier-workbench', { silent: true })
  cashierDraftHasUnresolvedCommand.value = Boolean(
    draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  )
  return !cashierDraftHasUnresolvedCommand.value
}

function cartLineCraftsmen(line = {}) {
  // 人员弹窗仅在服务端已成功写入权威草稿后才更新本地回执。根投影
  // 迟到时优先使用该回执，避免把已经保存的手艺人误判为未选择。
  const committed = localPersonnelAssignments.value[line.id]?.craftsmen
  if (Array.isArray(committed)) return committed
  return Array.isArray(line.craftsmen) ? line.craftsmen : []
}

function hasCartLineCraftsmen(line = {}) {
  return cartLineCraftsmen(line).length > 0
}

function firstCartLineMissingCraftsmen() {
  return cartLines.value.find((line) => (
    isProjectLine(line)
    && !isCustomCardPurchase(line)
    && !cardOperationUpgradeBinding(line)
    && !hasCartLineCraftsmen(line)
  )) || null
}

function cartLineServiceObject(line = {}) {
  return ['friend', '朋友'].includes(line.serviceObject) ? 'friend' : 'self'
}

async function queryPersonnelCandidates(scope, line) {
  const records = []
  let page = 1
  let total = 0
  do {
    const result = await requestAction('query-query-entities', {
      entityType: 'person',
      selectorEntry: 'cashier',
      selectorContext: {
        scope,
        lineId: line.id,
        lineRole: cartLineRole(line),
        memberId: line.memberId || currentMemberId.value || '',
        entitlementInstanceId: line.entitlementInstanceId || line.cardHolderId || '',
        entitlementSourceDetailId: line.entitlementSourceDetailId || line.memberBenefitPoolId || ''
      },
      keyword: '',
      page,
      pageSize: 100,
      silent: true
    })
    const status = resultStatus(result)
    const data = responseDataBlock(result)
    if (!['success', 'succeeded'].includes(status) || !Array.isArray(data.records)) {
      throw new Error(result?.result?.message || result?.data?.result?.message || '当前门店员工加载失败，请重试。')
    }
    records.push(...data.records)
    total = Math.max(0, Number(data.total) || records.length)
    page += 1
  } while (records.length < total && page <= 20)
  return records
}

async function loadPersonnelOverlay(line, initialTab) {
  // 卡/项目升级在收款成功前只形成销售草稿，不创建服务或劳动业绩。
  const showCraftsmen = isProjectLine(line) && !cardOperationUpgradeBinding(line)
  // 卡内权益只形成服务和劳动业绩，不形成销售业绩；不能加载或提交销售人。
  const showSalespeople = !isEntitlementLine(line)
  const requestKey = `${line.id}:${Date.now()}`
  personnelOverlay.value = {
    requestKey,
    line: clonePlain(line),
    initialTab: showSalespeople ? initialTab : 'craftsmen',
    showCraftsmen,
    showSalespeople,
    requireCraftsmen: showCraftsmen,
    craftsmenCandidates: [],
    salespersonCandidates: [],
    selectedCraftsmen: clonePlain(localPersonnelAssignments.value[line.id]?.craftsmen || cartLineCraftsmen(line)),
    selectedSalespeople: showSalespeople
      ? clonePlain(localPersonnelAssignments.value[line.id]?.salespeople || (Array.isArray(line.salespeople) ? line.salespeople : []))
      : [],
    loading: true,
    loadError: ''
  }
  try {
    const [craftsmenCandidates, salespersonCandidates] = await Promise.all([
      showCraftsmen ? queryPersonnelCandidates('service_actual_craftsmen', line) : Promise.resolve([]),
      showSalespeople ? queryPersonnelCandidates('sales_performance_assignees', line) : Promise.resolve([])
    ])
    if (personnelOverlay.value?.requestKey !== requestKey) return
    personnelOverlay.value = {
      ...personnelOverlay.value,
      craftsmenCandidates,
      salespersonCandidates,
      loading: false
    }
  } catch (error) {
    if (personnelOverlay.value?.requestKey !== requestKey) return
    personnelOverlay.value = {
      ...personnelOverlay.value,
      loading: false,
      loadError: error instanceof Error ? error.message : '当前门店员工加载失败，请重试。'
    }
  }
}

async function openCartLineCraftsmen(line) {
  if (!isProjectLine(line) || cardOperationUpgradeBinding(line)) return
  activeCartLineId.value = line.id
  await loadPersonnelOverlay(line, 'craftsmen')
}

async function openCartLineSalespeople(line) {
  activeCartLineId.value = line.id
  await loadPersonnelOverlay(line, 'salespeople')
}

async function retryPersonnelOverlay() {
  const current = personnelOverlay.value
  if (!current?.line) return
  await loadPersonnelOverlay(current.line, current.initialTab)
}

async function saveCartLineSalespeople(line, salespeople) {
  await mutateCashierDraft('update-cart-line-service-settings', line, { salespeople })
}

function openCartLineDebt(line) {
  if (isEntitlementLine(line)) return
  const memberId = String(line.memberId || currentMemberId.value || '').trim()
  if (!memberId || cashier.value.customerMode === 'guest') {
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'cashier', selectorContext: 'cashier' }
    }))
    return
  }
  const debtAmountCents = lineDebtAmountCents(line)
  debtEditorAmount.value = debtAmountCents > 0
    ? String(Math.trunc(debtAmountCents / 100))
    : ''
  debtEditor.value = { line: clonePlain(line) }
}

function normalizeCouponSelector(selector = {}, line = {}) {
  const coupons = (Array.isArray(selector.coupons) ? selector.coupons : [])
    .map((coupon) => ({
      ...coupon,
      couponId: String(coupon?.couponId || '')
    }))
    .filter((coupon) => coupon.couponId)
  return {
    ...selector,
    lineId: String(selector.lineId || line.id || ''),
    lineName: String(selector.lineName || line.name || ''),
    lineAmountCents: Number(selector.lineAmountCents ?? Math.round(Number(getLineAmount(line) || 0) * 100)),
    selectedCouponId: String(selector.selectedCouponId || ''),
    coupons
  }
}

async function openCartLineCoupon(line) {
  if (isEntitlementLine(line)) return
  activeCartLineId.value = line.id
  const result = await requestAction('open-line-coupon', { lineId: line.id })
  if (!['success', 'succeeded'].includes(resultStatus(result))) return
  const selector = responseDataBlock(result).couponSelector
  if (!selector || String(selector.lineId || '') !== String(line.id || '') || !Array.isArray(selector.coupons)) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: '优惠券数据未完整返回，请重试。' }
    }))
    return
  }
  couponSelector.value = normalizeCouponSelector(selector, line)
}

async function saveLineCoupon(action, couponId = '') {
  if (isSavingLineCoupon.value) return
  const lineId = String(couponSelector.value?.lineId || '')
  const line = cartLines.value.find((item) => String(item?.id || '') === lineId)
  if (!line || isEntitlementLine(line)) {
    couponSelector.value = null
    return
  }
  isSavingLineCoupon.value = true
  try {
    let result = await mutateCashierDraft(action, line, couponId ? { couponId } : {})
    if (resultStatus(result) === 'result_unknown') {
      const recovered = await recoverPendingDraftCommand()
      if (!recovered) return
      result = { result: { status: 'succeeded' } }
    }
    if (!['success', 'succeeded'].includes(resultStatus(result))) return
    checkoutPreparationId.value = null
    checkoutSession.value = null
    couponSelector.value = null
  } finally {
    isSavingLineCoupon.value = false
  }
}

function applyLineCoupon(couponId) {
  return saveLineCoupon('apply-line-coupon', String(couponId || ''))
}

function removeLineCoupon() {
  return saveLineCoupon('remove-line-coupon')
}

function lineDebtAmountCents(line = {}) {
  const amount = Number(line.debtAmountCents || 0)
  return Number.isSafeInteger(amount) && amount >= 0 ? amount : 0
}

function lineSaleAmountCents(line = {}) {
  const amount = Number(getLineAmount(line) || 0) * 100
  return Number.isSafeInteger(amount) && amount >= 0 ? amount : 0
}

function checkoutSaleAmountCents() {
  return cartLines.value
    .filter((line) => !isEntitlementLine(line))
    .reduce((total, line) => {
      const amount = Number(getLineAmount(line) || 0)
      return Number.isSafeInteger(amount) && amount >= 0 ? total + amount * 100 : total
    }, 0)
}

async function confirmCheckoutDebt() {
  const raw = String(debtEditorAmount.value || '').trim()
  if (!/^(?:0|[1-9]\d*)$/.test(raw)) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: '欠款金额必须为整数元。' }
    }))
    return
  }
  const amountCents = Number(raw) * 100
  const line = debtEditor.value?.line
  if (!line?.id) return
  const saleAmountCents = lineSaleAmountCents(line)
  if (!Number.isSafeInteger(amountCents) || amountCents < 0 || amountCents > saleAmountCents) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: '欠款金额不能超过该商品金额。' }
    }))
    return
  }
  let result = await mutateCashierDraft('update-cashier-line-debt', line, {
    debtAmountCents: amountCents
  })
  // The draft command may commit while its response is delayed. Resolve the
  // original idempotent command before keeping the editor open; otherwise the
  // operator sees “操作已受理” indefinitely even though the debt was saved.
  if (resultStatus(result) === 'result_unknown') {
    const recovered = await recoverPendingDraftCommand()
    if (!recovered) return
    // recoverPendingDraftCommand re-reads the authoritative workbench with
    // the original idempotency key; do not issue a second write.
    result = { result: { status: 'succeeded' } }
  }
  if (!['success', 'succeeded'].includes(resultStatus(result))) return
  checkoutPreparationId.value = null
  checkoutSession.value = null
  debtEditor.value = null
}

function checkoutDebtSummary(line) {
  const amount = lineDebtAmountCents(line)
  return amount > 0
    ? String(amount / 100)
    : ''
}

async function confirmPersonnelAssignment(result = {}) {
  if (isSavingPersonnelAssignment.value) return
  const line = personnelOverlay.value?.line
  if (!line?.id) return
  isSavingPersonnelAssignment.value = true
  try {
    const craftsmen = (result.craftsmen || []).map((record) => ({
      staffId: record.staffId || record.id,
      laborWeight: Number(record.laborWeight),
      isPointCustomer: Boolean(record.isPointCustomer ?? record.marked)
    }))
    const salespeople = (result.salespeople || []).map((record) => ({
      staffId: record.staffId || record.id,
      allocationWeight: Number(record.allocationWeight)
    }))
    const payload = {}
    if (isProjectLine(line) && !cardOperationUpgradeBinding(line)) payload.craftsmen = craftsmen
    if (!isEntitlementLine(line)) payload.salespeople = salespeople
    let savedResult = await mutateCashierDraft('update-cart-line-service-settings', line, payload)
    if (resultStatus(savedResult) === 'result_unknown') {
      const recovered = await recoverPendingDraftCommand()
      if (!recovered) {
        reportPersonnelAssignmentFailure(savedResult, '人员分配保存结果仍在确认中，请稍后重试。')
        return
      }
      savedResult = await mutateCashierDraft('update-cart-line-service-settings', line, payload)
    }
    if (!['success', 'succeeded'].includes(resultStatus(savedResult))) {
      reportPersonnelAssignmentFailure(savedResult, '人员分配保存失败，请保留当前选择后重试。')
      return
    }
    localPersonnelAssignments.value = {
      ...localPersonnelAssignments.value,
      [line.id]: isEntitlementLine(line)
        ? { craftsmen: clonePlain(result.craftsmen || []) }
        : clonePlain(result)
    }
    personnelOverlay.value = null
  } finally {
    isSavingPersonnelAssignment.value = false
  }
}

async function applyPersonnelAssignmentToAll(result = {}) {
  if (isSavingPersonnelAssignment.value) return
  const craftsmen = (result.craftsmen || []).map((record) => ({
    staffId: record.staffId || record.id,
    laborWeight: Number(record.laborWeight),
    isPointCustomer: Boolean(record.isPointCustomer ?? record.marked)
  }))
  const salespeople = (result.salespeople || []).map((record) => ({
    staffId: record.staffId || record.id,
    allocationWeight: Number(record.allocationWeight)
  }))
  if (!craftsmen.length && !salespeople.length) return
  isSavingPersonnelAssignment.value = true
  try {
    const requestScopeKey = currentCashierDraftScopeKey.value
    const response = await requestAction('apply-cashier-personnel-to-all-lines', {
      craftsmen,
      salespeople,
      idempotencyKey: createCashierV3CommandId('CASHIER_APPLY_PERSONNEL_ALL')
    })
    if (!['success', 'succeeded'].includes(resultStatus(response))) {
      reportPersonnelAssignmentFailure(response, '应用全部人员失败，请保留当前选择后重试。')
      return
    }
    const draft = responseDataBlock(response).cashierDraft
    if (!await applyCommittedCashierDraft(draft, requestScopeKey)) {
      reportPersonnelAssignmentFailure(null, '人员分配已提交，但权威购物车未完整返回，系统正在刷新。')
      return
    }
    const assignments = { ...localPersonnelAssignments.value }
    for (const line of Array.isArray(draft?.lines) ? draft.lines : []) {
      const current = { ...(assignments[line.id] || {}) }
      if (craftsmen.length && isProjectLine(line) && !isCustomCardPurchase(line)) {
        current.craftsmen = clonePlain(result.craftsmen || [])
      }
      if (salespeople.length && !isEntitlementLine(line)) {
        current.salespeople = clonePlain(result.salespeople || [])
      }
      assignments[line.id] = current
    }
    localPersonnelAssignments.value = assignments
    personnelOverlay.value = null
  } finally {
    isSavingPersonnelAssignment.value = false
  }
}

function craftsmenDisplaySummary(line = {}) {
  const records = localPersonnelAssignments.value[line.id]?.craftsmen
  if (!Array.isArray(records)) return hasCartLineCraftsmen(line) ? line.craftsmenSummary : '待选择手艺人'
  if (!records.length) return '待选择手艺人'
  const first = `手艺人：${records[0].name}(${records[0].marked ? '点' : '轮'})`
  return records.length > 1 ? `${first}等${records.length}人` : first
}

function salespersonDisplaySummary(line = {}) {
  const records = localPersonnelAssignments.value[line.id]?.salespeople
  if (!Array.isArray(records)) return line.salespersonSummary || '待分配'
  if (!records.length) return '待分配'
  const first = `${records[0].name}${records[0].marked ? '(售前)' : ''}`
  return records.length > 1 ? `${first}${records.length}人` : first
}

async function confirmPreviewCardOperation() {
  const operation = previewCardOperation.value
  if (!operation
    || !['card-transfer', 'project-replacement', 'card-upgrade', 'project-upgrade'].includes(operation.mode)
    || !operation.sources?.length
    || !operation.target) return
  return submitDirectCardOperation({
    source: operation.sources[0],
    reason: String(operation.reason || '')
  })
}

function cardOperationTargetPrompt(mode = '') {
  if (mode === 'card-upgrade') return '请选择要升级的新卡'
  if (mode === 'card-transfer') return '请选择接收会员'
  if (mode === 'project-replacement') return '请选择要替换的项目'
  if (mode === 'project-upgrade') return '请选择要升级的新项目'
  return '请选择目标对象'
}

function cardOperationReasonLabel(mode = '') {
  if (mode === 'card-transfer') return '转让原因'
  return '原因'
}

function cardOperationConfirmLabel(mode = '') {
  if (mode === 'card-transfer') return '确认转让'
  if (mode === 'project-replacement') return '确认替换'
  return '确认操作'
}

async function setCartLineServiceObject(line, serviceObject) {
  if (!isProjectLine(line) || !['self', 'friend'].includes(serviceObject) || cartLineServiceObject(line) === serviceObject) return
  activeCartLineId.value = line.id
  await mutateCashierDraft('update-cart-line-service-settings', line, { serviceObject })
}

async function toggleCartLineExperience(line) {
  if (!isProjectLine(line)) return
  activeCartLineId.value = line.id
  const previous = cartLineExperienceSelected(line)
  const payload = { isExperience: !previous }
  localExperienceState.value = { ...localExperienceState.value, [line.id]: payload.isExperience }
  if (new URLSearchParams(window.location.search).get('preview') === '1') return
  const result = await mutateCashierDraft('update-cart-line-service-settings', line, payload)
  if (!['success', 'succeeded'].includes(resultStatus(result))) {
    localExperienceState.value = { ...localExperienceState.value, [line.id]: previous }
  }
}

function cartLineExperienceSelected(line = {}) {
  return Object.prototype.hasOwnProperty.call(localExperienceState.value, line.id)
    ? localExperienceState.value[line.id]
    : line.isExperience === true
}

function cardOperationUpgradeBinding(line = {}) {
  const binding = line?.cardOperationUpgrade || line?.authoritySnapshot?.cardOperationUpgrade
  return binding && ['card_upgrade', 'project_upgrade'].includes(String(binding.operationType || '')) ? binding : null
}

function cardOperationUpgradeLabel(binding = {}) {
  return binding.operationType === 'project_upgrade' ? '项目升级' : '卡升级'
}

function cardOperationUpgradeMoney(binding = {}, field = '') {
  return Number(binding?.[field] || 0) / 100
}

async function removeCartLine(line) {
  if (!localCashierDraft.value) {
    return mutateRootCashierDraft('remove-cart-line', line)
  }
  return mutateCashierDraft('remove-cart-line', line)
}

async function confirmClearCart() {
  if (!hasCartLines.value || isClearingCart.value) return
  isClearingCart.value = true
  try {
    const saved = await clearCashierDraft(String(state.stateContextId || ''))
    const draft = saved?.cashierDraft
    if (!await applyCommittedCashierDraft(draft, currentCashierDraftScopeKey.value)) {
      throw new Error('购物车已清空，但空草稿状态尚未完整返回，请刷新收银台。')
    }
    cashierDraftHasUnresolvedCommand.value = false
    resetCashierLocalContext()
    // resetCashierLocalContext 清理结账/权益现场时会同时丢弃临时草稿投影；
    // 保留这次服务端返回的空草稿，避免等待后台刷新期间旧购物车闪回。
    cashierDraftSnapshot.value = Object.freeze({
      scopeKey: currentCashierDraftScopeKey.value,
      snapshot: Object.freeze(clonePlain(draft))
    })
    requestAction('open-cashier-workbench', { silent: true }).catch(() => undefined)
    return { result: { status: 'succeeded' }, data: saved }
  } catch (error) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: String(error?.message || '购物车清空失败，请重试。') }
    }))
    return { result: { status: 'failed', message: String(error?.message || '购物车清空失败，请重试。') } }
  } finally {
    isClearingCart.value = false
  }
}

function closeEntitlementSelector() {
  if (isAddingEntitlementLines.value) return
  if (previewCardOperation.value) {
    abandonCardOperation()
    return
  }
  finalizeEntitlementSelector()
}

function finalizeEntitlementSelector() {
  isEntitlementSelectorOpen.value = false
  entitlementSelectorRequestId.value = null
  entitlementSelectorSnapshot.value = null
  entitlementSelectorLoadState.value = 'idle'
  entitlementSelectorLoadError.value = ''
}

function retryEntitlementSelector() {
  if (isOpeningEntitlementSelector.value || !member.value || cashier.value.customerMode === 'guest') return
  return openEntitlementSelector()
}

function beginCardOperationTargetSelection(operation = {}) {
  selectedType.value = operation.mode === 'card-upgrade' ? '卡项' : '项目'
  selectedCategory.value = ''
  keyword.value = ''
}

function abandonCardOperation() {
  previewCardOperation.value = null
  // 目录只在卡操作期间被限制为项目或卡项；取消后回到常规收银默认，
  // 避免下一次普通选购误继承上一次替换/升级的目标上下文。
  selectedType.value = '项目'
  selectedCategory.value = ''
  keyword.value = ''
  finalizeEntitlementSelector()
}

function handleMemberSelectedForEntitlement(event = {}) {
  if (event.detail?.context === 'card-transfer' && previewCardOperation.value?.mode === 'card-transfer') {
    previewCardOperation.value = {
      ...previewCardOperation.value,
      target: clonePlain(event.detail.record || {}),
      awaitingTarget: false
    }
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'success', message: '已选择接收会员，请填写转让原因后确认。' }
    }))
    return
  }
  if (event.detail?.context !== 'cashier') return
  if (pendingCustomCardEntry.value) {
    pendingCustomCardEntry.value = false
    nextTick(() => { guidedBusinessMode.value = 'custom-card' })
  }
  if (pendingEntitlementSelector.value) {
    nextTick(() => {
      if (!member.value || cashier.value.customerMode === 'guest') return
      pendingEntitlementSelector.value = false
      openEntitlementSelector()
    })
  }
}

function openGuidedBusiness(event = {}) {
  guidedBusinessMode.value = event.detail?.mode || ''
}

function openRecharge(event = {}) {
  const session = event.detail && typeof event.detail === 'object' ? event.detail : {}
  const memberId = session.member?.id || session.member?.memberId || session.balance?.memberId || ''
  if (!memberId || String(memberId) !== String(currentMemberId.value)) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', code: 'RECHARGE_MEMBER_CONTEXT_MISMATCH', message: '当前会员已变化，请重新选择会员后充值。' }
    }))
    return
  }
  rechargePreparationIdempotencyKey.value = ''
  rechargeSession.value = session
}

function closeRecharge() {
  rechargeSession.value = null
  rechargePreparationIdempotencyKey.value = ''
}

async function submitRecharge(payload = {}) {
  if (isRechargeSubmitting.value || !rechargeSession.value) return
  isRechargeSubmitting.value = true
  try {
    const response = await requestCashierV3Action('prepare-recharge-checkout', {
      ...payload,
      memberId: payload.memberId || currentMemberId.value,
      ...(rechargePreparationIdempotencyKey.value
        ? { idempotencyKey: rechargePreparationIdempotencyKey.value }
        : {})
    })
    const responseStatus = resultStatus(response)
    if (responseStatus === 'result_unknown') {
      rechargePreparationIdempotencyKey.value = String(response.idempotencyKey || '')
    } else if (responseStatus === 'success' || responseStatus === 'succeeded' || responseStatus === 'failed' || responseStatus === 'conflict') {
      rechargePreparationIdempotencyKey.value = ''
    }
    const prepared = acceptPreparedCheckout(response)
    if ((responseStatus === 'success' || responseStatus === 'succeeded') && prepared) {
      closeRecharge()
    }
    return response
  } finally {
    isRechargeSubmitting.value = false
  }
}

async function confirmGuidedBusiness(result = {}) {
  if (result.mode === 'custom-card') {
    if (isCreatingCustomCard.value) return
    isCreatingCustomCard.value = true
    try {
      const response = await requestAction('create-custom-card-configuration', result.payload || {})
      if (resultStatus(response) === 'success') {
        // The configuration command mutates the authoritative workspace draft.
        // Its compact success response intentionally omits a full root state, so
        // immediately reload the workbench before hiding the guided panel.
        await requestAction('open-cashier-workbench', { silent: true })
        guidedBusinessMode.value = ''
      }
    } finally {
      isCreatingCustomCard.value = false
    }
    return
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: { status: 'success', message: `${result.title || '业务'}页面预览已确认，未产生真实业务数据` }
  }))
  guidedBusinessMode.value = ''
}

function continueCustomCard() {
  isCustomCardConflictOpen.value = false
  guidedBusinessMode.value = 'custom-card'
}

function handleMemberSelectorClosedForEntitlement(event = {}) {
  if (event.detail?.context === 'cashier' && event.detail?.reason !== 'selected') {
    pendingEntitlementSelector.value = false
    pendingCustomCardEntry.value = false
  }
}

function handleOpenEntitlementSelector() {
  return openEntitlementSelector()
}

async function openMoreAction(action) {
  const actionKey = typeof action === 'string' ? action : action?.key
  const actionDefinition = typeof action === 'string'
    ? checkoutActions.find((item) => item.key === action)
    : action
  if (!allowedCheckoutActions.has(actionKey)) {
    return { result: { status: 'failed', code: 'CASHIER_MORE_ACTION_NOT_ALLOWED', message: '该收银操作未进入前端允许清单。' } }
  }
  if (actionDefinition?.disabled) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'warning', message: actionDefinition.disabledReason || '该功能暂未开放。' }
    }))
    return { result: { status: 'failed', code: 'CASHIER_MORE_ACTION_DISABLED', message: actionDefinition.disabledReason || '该功能暂未开放。' } }
  }
  if (actionKey === 'open-order-note') {
    moreActionValue.value = String(
      localCashierDraft.value?.orderNote
      || cashier.value.orderNote
      || summary.value.orderNote
      || ''
    )
    moreActionReason.value = ''
    moreActionEditor.value = { type: 'order-note', title: '订单备注' }
    return
  }
  if (actionKey === 'open-price-change') {
    const saleLines = cartLines.value.filter((line) => !isEntitlementLine(line))
    const line = saleLines.find((item) => String(item.id) === String(activeCartLineId.value)) || saleLines[0]
    if (!line) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', message: '请先选择要改价的本次购买商品。' }
      }))
      return
    }
    activeCartLineId.value = line.id
    moreActionValue.value = formatPlainAmount(getLineAmount(line))
    moreActionReason.value = ''
    moreActionEditor.value = { type: 'price-change', title: '改价', line: clonePlain(line) }
    return
  }
  if (actionKey === 'open-supplement') {
    const current = supplement.value || {}
    moreActionValue.value = String(current.businessDate || cashierToday)
    moreActionReason.value = String(current.reason || '')
    moreActionEditor.value = { type: 'supplement', title: current.businessDate ? '修改补单日期' : '补单' }
  }
}

function closeMoreActionEditor() {
  if (isSavingMoreAction.value) return
  moreActionEditor.value = null
  moreActionValue.value = ''
  moreActionReason.value = ''
  moreActionValidationMessage.value = ''
}

async function saveMoreActionEditor() {
  const editor = moreActionEditor.value
  if (!editor || isSavingMoreAction.value) return
  let action = ''
  let payload = {}
  if (editor.type === 'order-note') {
    const note = String(moreActionValue.value || '').trim()
    if (note.length > 500) return
    action = 'update-cashier-order-note'
    payload = { orderNote: note }
  } else if (editor.type === 'price-change') {
    const rawAmount = String(moreActionValue.value || '').trim()
    const reason = String(moreActionReason.value || '').trim()
    if (!/^[1-9]\d*$/.test(rawAmount) || !reason) {
      moreActionValidationMessage.value = !reason ? '请输入改价原因。' : '改价金额必须为正整数。'
      return
    }
    const lineAmountCents = Number(rawAmount) * 100
    if (!Number.isSafeInteger(lineAmountCents)) return
    action = 'update-cashier-line-price'
    payload = { lineId: editor.line.id, lineAmountCents, reason }
  } else if (editor.type === 'supplement') {
    const businessDate = String(moreActionValue.value || '').trim()
    const reason = String(moreActionReason.value || '').trim()
    if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate) || !reason) {
      moreActionValidationMessage.value = !reason ? '请输入补单原因。' : '请选择有效的业务日期。'
      return
    }
    action = 'update-cashier-supplement'
    payload = { businessDate, reason }
  }
  if (!action) return
  isSavingMoreAction.value = true
  const requestScopeKey = currentCashierDraftScopeKey.value
  try {
    const result = await requestAction(action, {
      ...payload,
      idempotencyKey: createCashierV3CommandId('CASHIER_MORE')
    })
    if (!['success', 'succeeded'].includes(resultStatus(result))) return
    const draft = responseDataBlock(result).cashierDraft
    if (!await applyCommittedCashierDraft(draft, requestScopeKey)) return
    isSavingMoreAction.value = false
    closeMoreActionEditor()
  } finally {
    isSavingMoreAction.value = false
  }
}

function openSupplementDateEditor() {
  const current = supplement.value || {}
  moreActionValue.value = String(current.businessDate || cashierToday)
  moreActionReason.value = String(current.reason || '')
  moreActionEditor.value = { type: 'supplement', title: '修改补单日期' }
}

async function changeLineQuantity(line, delta) {
  activeCartLineId.value = line.id
  if (!localCashierDraft.value) {
    const result = await mutateRootCashierDraft('change-cart-line-quantity', line, { delta })
    if (['failed', 'conflict'].includes(resultStatus(result))) {
      reportCartQuantityFailure(result, '购物车数量更新失败，请重试。')
    } else {
      cartQuantityValidationError.value = ''
    }
    return result
  }
  const result = await mutateCashierDraft('change-cart-line-quantity', line, { delta })
  if (['failed', 'conflict'].includes(resultStatus(result))) {
    reportCartQuantityFailure(result, '购物车数量更新失败，请重试。')
  } else {
    cartQuantityValidationError.value = ''
  }
  return result
}

function entitlementLineMaximum(line = {}) {
  const availableTimes = Number(line.availableTimes)
  if (!Number.isInteger(availableTimes) || availableTimes < 0) return 0
  const holderId = String(line.entitlementInstanceId || line.cardHolderId || '')
  const detailId = String(line.entitlementSourceDetailId || line.memberBenefitPoolId || '')
  const projectId = String(line.projectId || '')
  if (!holderId || !detailId || !projectId) return 0
  const selectedByOtherLines = cartLines.value.reduce((total, candidate) => {
    const sameSource = isEntitlementLine(candidate)
      && String(candidate.id || '') !== String(line.id || '')
      && String(candidate.entitlementInstanceId || candidate.cardHolderId || '') === holderId
      && String(candidate.entitlementSourceDetailId || candidate.memberBenefitPoolId || '') === detailId
      && String(candidate.projectId || '') === projectId
    return sameSource ? total + Number(candidate.quantity || 0) : total
  }, 0)
  return Math.max(0, availableTimes - selectedByOtherLines)
}

async function setLineQuantity(line, event) {
  activeCartLineId.value = line.id
  const currentQuantity = Math.max(1, Number(line.quantity || 1))
  const requestedQuantity = Math.max(1, Math.floor(Number(event.target.value) || 1))
  const nextQuantity = requestedQuantity
  event.target.value = String(nextQuantity)
  if (nextQuantity === currentQuantity) {
    cartQuantityValidationError.value = ''
    return
  }
  const result = !localCashierDraft.value
    ? await mutateRootCashierDraft('change-cart-line-quantity', line, { delta: nextQuantity - currentQuantity })
    : await mutateCashierDraft('change-cart-line-quantity', line, { delta: nextQuantity - currentQuantity })
  if (['failed', 'conflict'].includes(resultStatus(result))) {
    const authoritativeLine = cartLines.value.find((candidate) => String(candidate?.id || '') === String(line?.id || ''))
    event.target.value = String(Math.max(1, Number(authoritativeLine?.quantity || currentQuantity)))
    reportCartQuantityFailure(result, '购物车数量更新失败，请重试。')
  } else {
    cartQuantityValidationError.value = ''
  }
  return result
}

async function openCheckout() {
  try {
    if (cartQuantityValidationError.value) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', message: cartQuantityValidationError.value }
      }))
      return { result: { status: 'failed', code: 'CASHIER_CART_QUANTITY_INVALID', message: cartQuantityValidationError.value } }
    }
    if (previewCardOperation.value) {
    const operation = previewCardOperation.value
    if (!operation.sources?.length || !operation.target) {
      reportEntitlementContractError({ message: cardOperationTargetPrompt(operation.mode) })
      return
    }
    if (['card-transfer', 'project-replacement', 'card-upgrade', 'project-upgrade'].includes(operation.mode)) {
      return confirmPreviewCardOperation()
    }
  }
    if (cashierDraftHasUnresolvedCommand.value) {
    resolveReflectedDraftCommand()
  }
    if (cashierDraftHasUnresolvedCommand.value) {
    const recovered = await recoverPendingDraftCommand()
    if (!recovered) {
      return unresolvedDraftCommandResult(
        draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
      )
    }
  }
    if (!hasCartLines.value) {
    return { result: { status: 'failed', code: 'CASHIER_CART_EMPTY', message: '请先添加需要结算或服务的项目。' } }
  }
    const craftsmenRequiredLine = firstCartLineMissingCraftsmen()
    if (craftsmenRequiredLine) {
      activeCartLineId.value = craftsmenRequiredLine.id
      await openCartLineCraftsmen(craftsmenRequiredLine)
      const result = {
        result: {
          status: 'failed',
          code: 'CASHIER_CRAFTSMAN_REQUIRED',
          message: '请先为本次服务选择手艺人后再确认完成服务。'
        }
      }
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', { detail: result.result }))
      return result
    }
    if (resumePersistedCheckout()) {
    return { result: { status: 'success', code: '', message: '已恢复本次待收款结账单。' } }
  }
    const requestEpoch = cashierContextEpoch.value
    checkoutLocalOutcome.value = {}
    if (serviceOrderNeedsConfirmation.value) {
    if (isPreparingServiceCompletion.value) return { success: false, message: '正在准备服务确认，请勿重复操作。' }
    // 后端必须先返回匹配的根级 serviceCompletion 快照与 overlay；
    // 不允许以前台当前购物车作为临时确认数据，避免错单或重复核销。
    if (!serviceCompletionPreparationId.value) {
      serviceCompletionPreparationId.value = createCashierV3CommandId('SERVICE_PREPARE')
    }
    const preparationRequestId = serviceCompletionPreparationId.value
    const context = serviceOrderCommandPayload()
    window.dispatchEvent(new CustomEvent('cashier-v3:register-service-completion-request', {
      detail: { preparationRequestId, serviceOrderId: context.serviceOrderId }
    }))
    isPreparingServiceCompletion.value = true
    try {
      const result = await requestAction('prepare-service-completion', {
        ...context,
        preparationRequestId,
        idempotencyKey: preparationRequestId
      })
      if (requestEpoch !== cashierContextEpoch.value) {
        return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次准备结果已忽略。' } }
      }
      if (['failed', 'conflict'].includes(resultStatus(result))) {
        serviceCompletionPreparationId.value = null
      }
      return result
    } finally {
      isPreparingServiceCompletion.value = false
    }
  }
    if (isPreparingCheckout.value) return { success: false, message: '正在准备结账，请勿重复操作。' }
    if (!checkoutPreparationId.value) checkoutPreparationId.value = createCashierV3CommandId('CHECKOUT_PREPARE')
    const preparationRequestId = checkoutPreparationId.value
    const editingCheckoutRequestId = String(checkout.value?.checkoutRequestId || checkout.value?.requestId || '').trim()
    const editingCheckoutRequestVersion = Number(checkout.value?.checkoutRequestVersion || checkout.value?.requestVersion || 0)
    const reviseEditingCheckout = editingCheckoutRequestId !== ''
    && Number.isInteger(editingCheckoutRequestVersion)
    && editingCheckoutRequestVersion > 0
    && String(checkout.value?.requestStatus || checkout.value?.status || '') === 'editing'
    checkoutSession.value = null
    isPreparingCheckout.value = true
    try {
    const result = await requestAction('prepare-checkout', {
      ...serviceOrderCommandPayload(),
      preparationRequestId,
      idempotencyKey: preparationRequestId,
      ...(reviseEditingCheckout
        ? {
            checkoutRequestId: editingCheckoutRequestId,
            checkoutRequestVersion: editingCheckoutRequestVersion
          }
        : {})
    })
    if (requestEpoch !== cashierContextEpoch.value) {
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次结账准备结果已忽略。' } }
    }
    const status = resultStatus(result)
    if (['failed', 'conflict'].includes(status)) {
      checkoutPreparationId.value = null
      isCheckoutOpen.value = false
      return result
    }

    const preparationReceipt = responseDataBlock(result).checkoutPreparation || {}
    let snapshot = clonePlain(checkout.value)
    // Compact preparation responses carry the persisted request in `data` but
    // deliberately omit the full root projection. Re-read the same workbench
    // before opening the payment overlay; this never creates another request.
    if (!isCompleteCheckoutPreparation(snapshot, preparationRequestId)) {
      await requestAction('open-cashier-workbench', { silent: true })
      snapshot = clonePlain(checkout.value)
    }
    // Reopening an editing request acknowledges the *new* prepare command in
    // the response, while the persisted checkout deliberately retains its
    // original creation key as preparationRequestId.  Payment drafts are
    // required to use that stable key, never the transient reopen command.
    const sessionPreparationRequestId = reviseEditingCheckout
      && isCheckoutPreparationReceiptForSnapshot(preparationReceipt, snapshot, preparationRequestId)
      ? String(snapshot.preparationRequestId || '')
      : preparationRequestId
    if (!activateCheckoutSession(snapshot, sessionPreparationRequestId)) {
      isCheckoutOpen.value = false
      if (['processing', 'pending', 'pending_confirmation', 'result_unknown'].includes(status)) return result
      return {
        result: {
          status: 'failed',
          code: 'CHECKOUT_PREPARATION_INCOMPLETE',
          message: '结账准备数据尚未完整加载，请使用同一请求重新查询，禁止新建结账请求。'
        }
      }
    }
    checkoutPreparationId.value = sessionPreparationRequestId
    isCheckoutOpen.value = true
    return result
    } finally {
    isPreparingCheckout.value = false
    }
  } catch (error) {
    checkoutPreparationId.value = null
    isCheckoutOpen.value = false
    const message = String(error?.message || '').trim() || '结账准备发生异常，请稍后重试。'
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', code: 'CHECKOUT_PREPARATION_CLIENT_ERROR', message }
    }))
    return { result: { status: 'failed', code: 'CHECKOUT_PREPARATION_CLIENT_ERROR', message } }
  }
}

async function openHangOrder() {
  if (!hasCartLines.value || isSavingHangDraft.value) {
    return { result: { status: 'failed', code: 'CASHIER_CART_EMPTY', message: '请先添加需要挂单的项目。' } }
  }
  // 挂单是纯草稿保存：不创建服务、不占用或校验房间，也不重做目录、
  // 库存、权益、价格等结账校验。从房间进入时只把房间写进草稿关联。
  isSavingHangDraft.value = true
  try {
    const intent = roomOpenIntent.value
    const saved = await saveHangDraft({
      stateContextId: String(state.stateContextId || ''),
      idempotencyKey: createCashierV3CommandId('HANG_DRAFT'),
      mode: 'normal',
      roomId: intent?.roomId || '',
      roomNameSnapshot: intent?.roomName || '',
      roomVersion: intent?.roomVersion || 0,
      roomTimeSlotId: intent?.roomTimeSlotId || '',
      roomTimeSlotVersion: intent?.roomTimeSlotVersion || 0
    })
    const draft = saved?.cashierDraft
    if (!await applyCommittedCashierDraft(draft, currentCashierDraftScopeKey.value)) {
      throw new Error('挂单已保存，但购物车清空状态尚未完整返回，请刷新收银台。')
    }
    cashierDraftHasUnresolvedCommand.value = false
    hangOrderPreparationId.value = null
    hangOrderSession.value = null
    isHangOrderOpen.value = false
    // 关联已随挂单快照保存；新购物车不应继续继承这一次房间入口。
    const query = { ...route.query }
    for (const key of [
      'roomOpenIntent',
      'roomOpenIntentSource',
      'roomOpenIntentId',
      'preferredRoomId',
      'preferredRoomName',
      'preferredRoomVersion',
      'preferredRoomTimeSlotId',
      'preferredRoomTimeSlotVersion'
    ]) delete query[key]
    router.replace({ query }).catch(() => undefined)
    // 重新读取同一工作台只同步服务端已清空的草稿，不会重新校验或创建结账。
    requestAction('open-cashier-workbench', { silent: true }).catch(() => undefined)
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'succeeded', message: '挂单成功，购物车已清空。' }
    }))
    return { result: { status: 'succeeded' }, data: saved }
  } catch (error) {
    const message = String(error?.message || '挂单草稿保存失败，请重试。')
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', code: 'HANG_DRAFT_SAVE_FAILED', message }
    }))
    return { result: { status: 'failed', code: 'HANG_DRAFT_SAVE_FAILED', message } }
  } finally {
    isSavingHangDraft.value = false
  }
}

async function submitHangOrder(payload) {
  const session = hangOrderSession.value
  // 兼容旧挂单弹层的直接草稿回调。正常入口已在 openHangOrder 直接保存，
  // 不再打开该弹层；房间字段也只表示草稿关联，绝不表示占房或开始服务。
  if (session?.directDraft === true) {
    if (session.stateContextId !== String(state.stateContextId || '')) {
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，请重新打开挂单。' } }
    }
    try {
      const saved = await saveHangDraft({
        stateContextId: session.stateContextId,
        idempotencyKey: payload.idempotencyKey,
        mode: 'normal',
        roomId: payload.roomId,
        roomNameSnapshot: session.snapshot.roomCandidates?.[0]?.name || '',
        roomVersion: session.snapshot.preferredRoomVersion,
        roomTimeSlotId: session.snapshot.preferredRoomTimeSlotId,
        roomTimeSlotVersion: session.snapshot.preferredRoomTimeSlotVersion
      })
      const draft = saved?.cashierDraft
      if (!await applyCommittedCashierDraft(draft, currentCashierDraftScopeKey.value)) {
        throw new Error('挂单已保存，但购物车清空状态尚未完整返回，请刷新收银台。')
      }
      cashierDraftHasUnresolvedCommand.value = false
      // 同步最新工作台版本，避免下一次加购沿用清空前的上下文版本。
      requestAction('open-cashier-workbench', { silent: true }).catch(() => undefined)
      return { result: { status: 'succeeded' }, data: saved }
    } catch (error) {
      return {
        result: {
          status: 'failed',
          code: 'HANG_DRAFT_SAVE_FAILED',
          message: String(error?.message || '挂单草稿保存失败，请重试。')
        }
      }
    }
  }
  if (
    !session
    || session.stateContextId !== String(state.stateContextId || '')
    || session.preparationRequestId !== hangOrderPreparationId.value
  ) {
    return { result: { status: 'failed', code: 'HANG_ORDER_SESSION_EXPIRED', message: '挂单准备会话已失效，请关闭后重新打开。' } }
  }
  const commandContexts = clonePlain(session.commandContexts)
  const preparedRoomSubmission = session.snapshot.preferredRoomId
    ? {
        preparedRoomId: session.snapshot.preferredRoomId,
        preparedRoomVersion: Number(session.snapshot.preferredRoomVersion),
        preparedRoomTimeSlotId: String(session.snapshot.preferredRoomTimeSlotId || ''),
        preparedRoomTimeSlotVersion: Number(session.snapshot.preferredRoomTimeSlotVersion)
      }
    : {}
  let roomSubmission = {}
  if (payload.mode === 'start_service' && payload.roomId) {
    const candidates = session.snapshot.roomCandidates || session.snapshot.candidates || []
    const room = candidates.find((candidate) => String(candidate.id || candidate.roomId) === String(payload.roomId))
    if (!room || (room.selectable !== true && room.canSelect !== true)) {
      return { result: { status: 'failed', code: 'HANG_ORDER_ROOM_NOT_SELECTABLE', message: '该房间已不可用，请重新准备挂单。' } }
    }
    const candidateContexts = Array.isArray(room.commandContexts)
      ? room.commandContexts
      : [
          {
            kind: 'room',
            id: room.id || room.roomId,
            expectedVersion: room.revision ?? room.roomVersion
          },
          {
            kind: 'room_time_slot',
            id: room.roomTimeSlotId || room.timeSlotId,
            expectedVersion: room.roomTimeSlotVersion ?? room.timeSlotVersion
          }
        ]
    const roomId = room.id || room.roomId
    const timeSlotId = room.roomTimeSlotId || room.timeSlotId
    if (
      !hasMatchingCommandContext(candidateContexts, 'room', roomId, room.revision ?? room.roomVersion)
      || !hasMatchingCommandContext(
        candidateContexts,
        'room_time_slot',
        timeSlotId,
        room.roomTimeSlotVersion ?? room.timeSlotVersion
      )
    ) {
      return { result: { status: 'failed', code: 'HANG_ORDER_ROOM_CONTEXT_MISSING', message: '房间版本数据不完整，请重新选择房间。' } }
    }
    roomSubmission = {
      roomId,
      roomVersion: Number(room.revision ?? room.roomVersion),
      roomTimeSlotId: String(timeSlotId),
      roomTimeSlotVersion: Number(room.roomTimeSlotVersion ?? room.timeSlotVersion)
    }
  }
  const result = await requestAction('submit-hang-order', {
    ...payload,
    ...preparedRoomSubmission,
    ...roomSubmission,
    preparationRequestId: session.preparationRequestId,
    preparationToken: session.preparationToken,
    cartLineFingerprint: String(session.snapshot.cartLineFingerprint || ''),
    commandContexts
  })
  if (['succeeded', 'success'].includes(resultStatus(result))) {
    const query = { ...route.query }
    for (const key of [
      'roomOpenIntent',
      'roomOpenIntentSource',
      'roomOpenIntentId',
      'preferredRoomId',
      'preferredRoomName',
      'preferredRoomVersion',
      'preferredRoomTimeSlotId',
      'preferredRoomTimeSlotVersion'
    ]) delete query[key]
    // The write has already succeeded. URL cleanup is best-effort and must
    // never turn a persisted hang order into an unknown client result.
    hangOrderSession.value = null
    hangOrderPreparationId.value = null
    try {
      await router.replace({ query })
    } catch (error) {
      console.warn('Failed to clear completed hang-order route intent.', error)
    }
  }
  return result
}

async function queryHangOrderResult(command = {}) {
  const session = hangOrderSession.value
  const originalIdempotencyKey = command.originalIdempotencyKey
  if (!session || session.stateContextId !== String(state.stateContextId || '') || !originalIdempotencyKey) {
    return {
      success: false,
      status: 'blocked',
      message: '未找到原挂单请求标识，请勿重新挂单。'
    }
  }
  const { queryAction: ignoredQueryAction, idempotencyKey: ignoredIdempotencyKey, ...payload } = command
  const result = await requestAction('query-hang-order-result', {
    ...payload,
    originalIdempotencyKey,
    preparationRequestId: session.preparationRequestId
  })
  const envelope = cashierV3ResponseEnvelope(result)
  const transportStatus = resultStatus(result)
  if (['failed', 'conflict'].includes(transportStatus)) return result

  const projected = authoritativeHangOrderResult(result, {
    originalIdempotencyKey,
    stateContextId: session.stateContextId
  })
  if (projected) {
    return {
      ...envelope,
      result: {
        ...(isRecord(envelope.result) ? envelope.result : {}),
        status: projected.status,
        code: projected.code,
        message: projected.message
      },
      data: {
        ...(isRecord(envelope.data) ? envelope.data : {}),
        hangOrderResult: projected
      }
    }
  }

  // A response that cannot prove the same original request must keep the
  // overlay locked. It cannot be treated as a successful recovery query.
  return {
    ...envelope,
    result: {
      ...(isRecord(envelope.result) ? envelope.result : {}),
      status: 'result_unknown',
      code: 'HANG_ORDER_RESULT_UNVERIFIABLE',
      message: '挂单结果暂时无法完整核验，请继续查询原请求，禁止重复挂单。'
    }
  }
}

function enqueueCheckoutAction(event = {}) {
  const action = String(event?.action || '')
  if (!checkoutDraftMutationActions.has(action)) {
    const direct = requestCheckoutAction(event)
    if (direct && typeof direct.then === 'function') {
      direct.then((result) => event?.resolve?.(result), (error) => event?.resolve?.({
        result: { status: 'failed', message: error?.message || '结账操作未完成，请重试。' }
      }))
    }
    return direct
  }

  // Draft edits advance the same checkout_request version. Serialize only
  // those edits so repeated payment-method clicks remain intentional lines,
  // while every following request uses the projection returned by its prior one.
  const queuedCheckoutRequestId = String(checkoutSession.value?.checkoutRequestId || '')
  const queuedStateContextId = String(checkoutSession.value?.stateContextId || '')
  const run = () => {
    if (!queuedCheckoutRequestId
      || checkoutSession.value?.checkoutRequestId !== queuedCheckoutRequestId
      || checkoutSession.value?.stateContextId !== queuedStateContextId) {
      return {
        result: {
          status: 'failed',
          code: 'CHECKOUT_DRAFT_QUEUE_EXPIRED',
          message: '结账对象已经切换，未提交之前排队的收款编辑。'
        }
      }
    }
    return requestCheckoutDraftMutationWithSingleConflictReplay(event)
  }
  const queued = checkoutDraftMutationTail.then(run, run)
  checkoutDraftMutationTail = queued.catch(() => undefined)
  queued.then(
    (result) => {
      event?.resolve?.(result)
      window.dispatchEvent(new CustomEvent('cashier-v3:checkout-draft-mutation-result', {
        detail: {
          action,
          payload: isRecord(event?.payload) ? event.payload : {},
          status: resultStatus(result),
          message: cashierV3ResponseEnvelope(result)?.result?.message || result?.result?.message || ''
        }
      }))
    },
    (error) => {
      event?.resolve?.({ result: { status: 'failed', message: error?.message || '收款金额没有保存，已恢复原金额。' } })
      window.dispatchEvent(new CustomEvent('cashier-v3:checkout-draft-mutation-result', {
        detail: {
          action,
          payload: isRecord(event?.payload) ? event.payload : {},
          status: 'failed',
          message: '收款金额没有保存，已恢复原金额。'
        }
      }))
    }
  )
  return queued
}

function checkoutDraftConflict(result) {
  if (resultStatus(result) !== 'conflict') return false
  const envelope = cashierV3ResponseEnvelope(result)
  const resultBlock = isRecord(envelope?.result) ? envelope.result : {}
  return String(resultBlock.code || envelope?.code || '') === 'RESOURCE_VERSION_CONFLICT'
}

function checkoutBalanceVersionConflict(result) {
  if (resultStatus(result) !== 'conflict') return false
  const envelope = cashierV3ResponseEnvelope(result)
  const resultBlock = isRecord(envelope?.result) ? envelope.result : {}
  const conflict = isRecord(envelope?.conflict)
    ? envelope.conflict
    : (isRecord(resultBlock.conflict) ? resultBlock.conflict : {})
  return String(resultBlock.code || envelope?.code || '') === 'RESOURCE_VERSION_CONFLICT'
    && String(conflict.reason || '') === 'member_balance_version_conflict'
}

async function reloadLatestCheckoutDraftForMutation(session) {
  if (!session
    || session.stateContextId !== String(state.stateContextId || '')
    || session.checkoutRequestId !== String(checkoutRequestIdentity(checkout.value) || '')) {
    return false
  }
  // This is a read-only root refresh. It never prepares a new checkout and it
  // never sends a final-payment command. The retry below uses only the newest
  // persisted editing request and its newly signed command contexts.
  const refreshed = await requestAction('open-cashier-workbench', { silent: true })
  if (!['success', 'succeeded'].includes(resultStatus(refreshed))) return false
  const latest = clonePlain(checkout.value)
  if (String(checkoutRequestIdentity(latest) || '') !== session.checkoutRequestId
    || String(latest.requestStatus || latest.status || '') !== 'editing'
    || !activateCheckoutSession(latest, latest.preparationRequestId, { recovery: true })) {
    return false
  }
  checkoutPreparationId.value = String(latest.preparationRequestId || '') || null
  isCheckoutOpen.value = true
  return true
}

async function requestCheckoutDraftMutationWithSingleConflictReplay(event = {}) {
  const originalSession = checkoutSession.value
  const first = await requestCheckoutAction(event)
  if (!checkoutDraftConflict(first)) return first
  if (!await reloadLatestCheckoutDraftForMutation(originalSession)) {
    return first
  }
  // A conflict means the original draft command was not committed. Retrying
  // once with the latest persisted checkout version is safe. Do not expand
  // this to prepare-checkout-submission or submit-checkout: those operations
  // have separate idempotency and final-settlement semantics.
  return requestCheckoutAction(event)
}

async function requestCheckoutAction({ action, payload }) {
  if (!checkoutRequestActions.has(action) && !checkoutExternalActions.has(action)) {
    return {
      result: {
        status: 'failed',
        code: 'CHECKOUT_ACTION_NOT_ALLOWED',
        message: '该结账操作未进入前端允许清单，已阻止发送。'
      }
    }
  }

  // The visible balance button applies the server-calculated maximum to this
  // draft. It does not move money; submit-checkout revalidates and debits the
  // same authoritative member balance inside its final transaction.
  if (action === 'open-balance-payment') action = 'apply-balance-payment'

  if (action === 'open-checkout-source-selector') return openCheckoutBusinessSourceSelector('sale')

  if (action === 'prepare-service-completion') {
    if (!serviceOrder.value?.id) {
      return { result: { status: 'failed', code: 'SERVICE_ORDER_MISSING', message: '未找到需要完成的服务单。' } }
    }
    const preparationRequestId = createCashierV3CommandId('SERVICE_PREPARE')
    serviceCompletionPreparationId.value = preparationRequestId
    const requestEpoch = cashierContextEpoch.value
    window.dispatchEvent(new CustomEvent('cashier-v3:register-service-completion-request', {
      detail: { preparationRequestId, serviceOrderId: serviceOrder.value.id }
    }))
    const result = await requestAction('prepare-service-completion', {
      ...serviceOrderCommandPayload(),
      completionIntent: payload?.completionIntent,
      serviceStartOption: payload?.serviceStartOption,
      preparationRequestId,
      idempotencyKey: preparationRequestId
    })
    if (requestEpoch !== cashierContextEpoch.value) {
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次准备结果已忽略。' } }
    }
    return result
  }

  if (action === 'view-sales-order' || action === 'print-sales-order-receipt') {
    const salesOrderId = checkout.value.salesOrderId
    if (!salesOrderId) {
      return { result: { status: 'failed', code: 'SALES_ORDER_ID_MISSING', message: '销售订单标识尚未加载，不能执行该操作。' } }
    }
    const result = await requestAction('open-sales-order-detail', { orderId: salesOrderId })
    if (['failed', 'conflict', 'result_unknown'].includes(resultStatus(result))) return result
    const projection = salesOrderProjectionFromResult(result)
    const detail = projection?.salesOrderDetail
    const detailOrderId = detail?.id || detail?.orderId || detail?.salesOrderId
    if (!detail || String(detailOrderId) !== String(salesOrderId)) {
      return {
        result: {
          status: 'failed',
          code: 'SALES_ORDER_DETAIL_INVALID',
          message: '销售订单详情尚未完整返回，请稍后重试。'
        }
      }
    }
    if (action === 'view-sales-order') {
      // This is a read-only partial projection, so the bridge does not replace
      // the cashier root state. Preserve it before navigating to the order page.
      state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
      closeCheckoutOverlay()
      await router.push({ name: 'cashier-v3-order-center' })
      await nextTick()
      window.dispatchEvent(new CustomEvent('cashier-v3:open-sales-order-detail', {
        detail: { orderId: salesOrderId, salesOrderId }
      }))
      return result
    }
    // The success overlay itself only has a concise result projection.  Return
    // the verified authority detail to its local print adapter so its receipt
    // always includes items, payment rows and the frozen money summary.
    return { ...result, receiptOrder: detail }
  }

  const session = checkoutSession.value
  const current = currentCheckoutCommandContexts(session)
  if (!session || !current) {
    return {
      result: {
        status: 'failed',
        code: 'CHECKOUT_SESSION_EXPIRED',
        message: '结账版本或准备会话已失效，请关闭后使用原结账请求重新进入。'
      }
    }
  }

  const approvedPayloadInput = { ...(isRecord(payload) ? payload : {}) }
  const closeAfterEditReturn = approvedPayloadInput.closeAfterEditReturn === true
  delete approvedPayloadInput.closeAfterEditReturn
  for (const sourceKey of [
    'serviceOrderId',
    'service_order_id',
    'reservationId',
    'reservation_id',
    'roomId',
    'room_id',
    'hangOrderId',
    'hang_order_id'
  ]) {
    delete approvedPayloadInput[sourceKey]
  }
  const approvedPayload = {
    ...approvedPayloadInput,
    checkoutRequestId: session.checkoutRequestId,
    checkoutRequestVersion: current.checkoutRequestVersion,
    preparationRequestId: session.preparationRequestId,
    preparationToken: String(checkoutPreparationToken(checkout.value) || ''),
    commandContexts: current.commandContexts
  }
  if (isRechargeDebtRepaymentCheckout.value && ['submit-checkout', 'retry-checkout'].includes(action)) {
    // Dedicated recharge-debt submission derives member/balance/request locks
    // from these stable identities. Do not send the generic two-context draft
    // subset, which would omit the member balance required by its policy.
    approvedPayload.memberId = checkout.value.member?.id || checkout.value.member?.memberId || ''
    approvedPayload.debtRecordId = checkout.value.sourceDocumentId || ''
    delete approvedPayload.commandContexts
  }
  if (checkoutDraftMutationActions.has(action)) {
    const draftContexts = checkoutSubmissionCommandContexts(current.commandContexts)
    if (!draftContexts) {
      return {
        result: {
          status: 'failed',
          code: 'CHECKOUT_DRAFT_CONTEXT_STALE',
          message: '收款编辑版本不完整，请关闭后重新打开本次结账。'
        }
      }
    }
    // Follow-up draft edits expose only the workspace and checkout request.
    // Debt/service/reservation authorities are rebuilt from the persisted
    // request inside the server transaction and must not be client-supplied.
    approvedPayload.commandContexts = draftContexts
    // Draft editing failures are handled inside the checkout overlay. They
    // must not also create a global payment-result notification.
    approvedPayload.silent = true
  }
  if (action === 'query-checkout-result' && !approvedPayload.originalIdempotencyKey) {
    return {
      result: {
        status: 'result_unknown',
        code: 'ORIGINAL_IDEMPOTENCY_KEY_MISSING',
        message: '未找到原结账请求标识，禁止创建新请求或重新扣款。'
      }
    }
  }

  const effectiveAction = isDebtRepaymentCheckout.value
    ? ({
        'submit-checkout': isRechargeDebtRepaymentCheckout.value ? 'submit-recharge-debt-repayment' : 'submit-debt-repayment',
        'retry-checkout': isRechargeDebtRepaymentCheckout.value ? 'submit-recharge-debt-repayment' : 'submit-debt-repayment',
        'query-checkout-result': 'query-debt-repayment-result'
      }[action] || action)
    : action
  let result
  if (action === 'submit-checkout' && !isDebtRepaymentCheckout.value) {
    const submissionKey = String(approvedPayload.idempotencyKey || '')
    const recoveredPreparedCheckout = String(checkout.value?.requestStatus || '') === 'ready_for_submit'
      && String(checkout.value?.originalIdempotencyKey || '') === submissionKey
    if (recoveredPreparedCheckout) {
      const submitContexts = checkoutSubmissionCommandContexts(current.commandContexts)
      if (!submitContexts) {
        result = {
          result: {
            status: 'failed',
            code: 'CHECKOUT_SUBMISSION_CONTEXT_STALE',
            message: '结账资料已完成校验，但最终提交版本不完整，请关闭后重新打开结账。'
          }
        }
      } else {
        approvedPayload.commandContexts = submitContexts
        result = await requestAction(effectiveAction, approvedPayload)
      }
    } else {
      const prepareKey = submissionKey.replace(/^CHECKOUT-/, 'CHECKOUT_PREPARE-')
      if (prepareKey === submissionKey) {
        result = {
          result: {
            status: 'failed',
            code: 'CHECKOUT_IDEMPOTENCY_KEY_INVALID',
            message: '本次结账请求标识无效，请关闭结账页面后重试。'
          }
        }
      } else {
        // Final preparation rediscovers every sale/entitlement/card-operation
        // resource from the persisted checkout request. Never forward resource
        // identities retained by an older root projection into that discovery.
        const preparationContexts = checkoutSubmissionCommandContexts(current.commandContexts)
        const prepared = preparationContexts
          ? await requestAction('prepare-checkout-submission', {
              ...approvedPayload,
              commandContexts: preparationContexts,
              idempotencyKey: prepareKey
            })
          : {
              result: {
                status: 'failed',
                code: 'CHECKOUT_SUBMISSION_CONTEXT_STALE',
                message: '结账最终校验版本不完整，请关闭后重新打开结账。'
              }
            }
        const preparationStatus = resultStatus(prepared)
        // 最终校验是无经营事实的安全准备命令。若它已在服务端成功、但响应根
        // 因版本切换未能被页面接收，不能把它误判为“顾客已扣款结果未知”。
        // 只读重取同一工作台后，仍须同时确认同一请求已经推进至
        // ready_for_submit，才允许继续发送原结账幂等键。
        const mayRecoverPreparedCheckout = ['success', 'succeeded', 'result_unknown'].includes(preparationStatus)
        if (mayRecoverPreparedCheckout) {
          // 最终校验会推进 checkout_request 与 workspace 的版本。即使命令
          // 响应因页面时序未带完整根，也必须只读重取同一工作台，不能沿用旧
          // 版本、更不能重新创建或提交另一张结账请求。
          let refreshed = currentCheckoutCommandContexts(checkoutSession.value)
          // A normal preparation response already replaces the complete root
          // state with the newly signed checkout version. Re-bootstrap only if
          // that response was unavailable, otherwise this extra read can switch
          // the active workbench before the final command is sent.
          if (!refreshed || String(checkout.value?.requestStatus || '') !== 'ready_for_submit') {
            await requestAction('open-cashier-workbench', { silent: true })
            refreshed = currentCheckoutCommandContexts(checkoutSession.value)
          }
          const serverPrepared = refreshed
            && String(checkout.value?.requestStatus || '') === 'ready_for_submit'
            // 同一幂等键重放时，最终校验已经在此前成功推进版本；重取的版本会
            // 与当前快照相等。只要仍是同一服务端请求且明确 ready_for_submit，
            // 即可继续发送该次原结账键，不能要求它再次递增。
            && Number(refreshed.checkoutRequestVersion) >= Number(current.checkoutRequestVersion)
          if (!serverPrepared) {
            result = {
              result: {
                status: 'failed',
                code: preparationStatus === 'result_unknown'
                  ? 'CHECKOUT_SUBMISSION_PREPARATION_UNCONFIRMED'
                  : 'CHECKOUT_SUBMISSION_CONTEXT_STALE',
                message: preparationStatus === 'result_unknown'
                  ? '结账最终校验的结果尚未确认，未发送收款请求。请重新打开本次结账后查询原结果。'
                  : '结账资料已完成校验，但页面版本未刷新，请关闭后重新打开结账。'
              }
            }
          } else {
            const submitContexts = checkoutSubmissionCommandContexts(refreshed.commandContexts)
            if (!submitContexts) {
              result = {
                result: {
                  status: 'failed',
                  code: 'CHECKOUT_SUBMISSION_CONTEXT_STALE',
                  message: '结账资料已完成校验，但最终提交版本不完整，请关闭后重新打开结账。'
                }
              }
            } else {
              approvedPayload.checkoutRequestVersion = refreshed.checkoutRequestVersion
              approvedPayload.commandContexts = submitContexts
              approvedPayload.preparationToken = String(checkoutPreparationToken(checkout.value) || '')
              result = await requestAction(effectiveAction, approvedPayload)
            }
          }
        } else {
          result = prepared
        }
      }
    }
  } else {
    result = await requestAction(effectiveAction, approvedPayload)
  }
  if (checkoutDraftMutationActions.has(action)
    && ['success', 'succeeded'].includes(resultStatus(result))
    && !applyCheckoutDraftProjection(result)) {
    // A committed edit must never leave the operator with an old request
    // version. Narrow projection failures automatically fall back to one
    // full workbench read; no manual refresh decision is exposed to the user.
    await reloadLatestCheckoutDraftForMutation(session)
  }
  if (
    action === 'submit-checkout'
    && !isDebtRepaymentCheckout.value
    && Number(checkout.value?.balancePaymentAmount || 0) > 0
    && checkoutBalanceVersionConflict(result)
  ) {
    const recoveryKey = [
      session.checkoutRequestId,
      current.checkoutRequestVersion,
      String(approvedPayload.idempotencyKey || '')
    ].join(':')
    if (!automaticBalanceConflictRecoveries.has(recoveryKey)) {
      automaticBalanceConflictRecoveries.add(recoveryKey)
      const recovery = await requestCheckoutAction({
        action: 'return-to-payment-edit',
        payload: { idempotencyKey: createCashierV3CommandId('CHECKOUT_BALANCE_RECOVERY') }
      })
      if (['success', 'succeeded'].includes(resultStatus(recovery))) {
        checkoutLocalOutcome.value = {}
        window.dispatchEvent(new CustomEvent('cashier-v3:checkout-returned-to-payment-edit'))
        return recovery
      }
    }
  }
  if (action === 'finish-checkout-and-return') {
    if (['success', 'succeeded'].includes(resultStatus(result))) closeCheckoutOverlay()
    return result
  }
  if (action === 'return-to-payment-edit') {
    if (['success', 'succeeded'].includes(resultStatus(result))) {
      checkoutLocalOutcome.value = {}
      window.dispatchEvent(new CustomEvent('cashier-v3:checkout-returned-to-payment-edit'))
      if (closeAfterEditReturn) closeCheckoutOverlay()
    }
    return result
  }
  if (!['submit-checkout', 'retry-checkout', 'query-checkout-result'].includes(action)) return result
  const originalIdempotencyKey = String(
    approvedPayload.originalIdempotencyKey
    || approvedPayload.idempotencyKey
    || checkoutLocalOutcome.value.originalIdempotencyKey
    || checkout.value.originalIdempotencyKey
    || ''
  )
  let response = cashierV3ResponseEnvelope(result)
  const hasInitialCheckoutState = isRecord(response?.state?.cashier?.checkout)
  const shouldQueryCommittedResult = !isDebtRepaymentCheckout.value
    && ['submit-checkout', 'retry-checkout'].includes(action)
    && !hasInitialCheckoutState
    && cashierV3ResponseRequiresRefresh(result)
    && ['success', 'succeeded'].includes(resultStatus(result))
    && originalIdempotencyKey.startsWith('CHECKOUT-')
  if (shouldQueryCommittedResult) {
    result = await requestAction('query-checkout-result', {
      checkoutRequestId: session.checkoutRequestId,
      requestNo: checkout.value.requestNo,
      originalIdempotencyKey
    })
    response = cashierV3ResponseEnvelope(result)
  }
  // A lost submit response is resolved once against the original request.
  // If the read-only query proves that no receipt exists, return the cashier
  // to the cart automatically; never expose the technical result-unknown
  // state or force a low-literacy operator to understand idempotency.
  let resultQueryAttempted = action === 'query-checkout-result' || shouldQueryCommittedResult
  if (!isDebtRepaymentCheckout.value
    && ['submit-checkout', 'retry-checkout'].includes(action)
    && resultStatus(result) === 'result_unknown'
    && originalIdempotencyKey.startsWith('CHECKOUT-')) {
    result = await requestAction('query-checkout-result', {
      checkoutRequestId: session.checkoutRequestId,
      requestNo: checkout.value.requestNo,
      originalIdempotencyKey,
      queryOnly: true
    })
    response = cashierV3ResponseEnvelope(result)
    resultQueryAttempted = true
  }
  const resultEnvelope = response?.result && typeof response.result === 'object' ? response.result : {}
  const hasAuthoritativeCheckoutState = isRecord(response?.state?.cashier?.checkout)
  if (hasAuthoritativeCheckoutState) {
    // 命令信封的 success 只表示命令已被可靠处理，不能解释成顾客已经支付成功。
    // 支付领域状态只能由已通过根状态门禁的 cashier.checkout.status 驱动。
    checkoutLocalOutcome.value = {}
    return result
  }
  const debtRepaymentResult = isDebtRepaymentCheckout.value
    ? responseDataBlock(result).debtRepayment
    : null
  if (isRecord(debtRepaymentResult) && debtRepaymentResult.status === 'succeeded') {
    checkoutRequiresRootReload.value = true
    checkoutLocalOutcome.value = {
      status: 'succeeded',
      message: response?.message || '欠款补交成功。',
      completionDescription: `补交单 ${debtRepaymentResult.repaymentNo || ''} 已完成。`.trim(),
      requestNo: debtRepaymentResult.repaymentNo || '',
      checkoutRequestId: debtRepaymentResult.checkoutRequestId || session.checkoutRequestId,
      originalIdempotencyKey,
      canClose: true,
      canRetry: false
    }
    return result
  }
  // Debt repayment is a dedicated business record, not a generic sales
  // checkout.  A transport/command failure must return the operator to the
  // cashier immediately with a plain retry message.  Do not show the generic
  // “正在确认支付结果” / query-original-request flow for a single repayment.
  if (isDebtRepaymentCheckout.value && ['submit-checkout', 'retry-checkout'].includes(action)) {
    checkoutRequiresRootReload.value = false
    checkoutLocalOutcome.value = {}
    closeCheckoutOverlay()
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: '补交失败，请重新操作。' }
    }))
    return result
  }
  const projectedResult = !isDebtRepaymentCheckout.value
    ? authoritativeCheckoutResult(result, {
        originalIdempotencyKey,
        checkoutRequestId: session.checkoutRequestId,
        stateContextId: session.stateContextId
      })
    : null
  if (projectedResult?.status === 'succeeded') {
    checkoutRequiresRootReload.value = true
    checkoutLocalOutcome.value = {
      status: 'succeeded',
      message: projectedResult.message || '结账已完成。',
      completionDescription: projectedResult.message || '本单已正式完成，可以继续后续操作。',
      requestNo: projectedResult.salesOrder.orderNo,
      checkoutRequestId: projectedResult.checkoutRequest.requestId,
      salesOrderId: projectedResult.salesOrder.orderId,
      salesOrderNo: projectedResult.salesOrder.orderNo,
      originalIdempotencyKey,
      canClose: true,
      canRetry: false
    }
    return result
  }
  let status = resultEnvelope.status || response.status || ''
  const code = resultEnvelope.code || response.code || ''
  if (projectedResult) status = projectedResult.status
  if (resultQueryAttempted && resultEnvelope.phase === 'not_found') {
    checkoutLocalOutcome.value = {}
    closeCheckoutOverlay()
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', message: '结账失败，未产生收款，请重新操作。' }
    }))
    return result
  }
  if (['succeeded', 'success', 'processing', 'pending', 'pending_confirmation'].includes(status)) {
    // 没有可信根状态时，不能仅凭传输层结果推断支付终态。
    status = 'result_unknown'
  } else if (!['failed', 'conflict', 'result_unknown'].includes(status)) {
    status = 'result_unknown'
  }
  const resultMessage = projectedResult?.message
    || (resultQueryAttempted && ['success', 'succeeded'].includes(resultStatus(result))
      ? '结账结果暂时无法完整核验，请继续查询原请求，禁止重复收款。'
      : resultEnvelope.message || response.message || '')
  const craftsmanValidationFailed = status === 'failed'
    && code === 'INVALID_COMMAND_CONTEXT'
    && resultMessage.includes('选择手艺人')
  checkoutLocalOutcome.value = {
    status: status === 'conflict' ? 'failed' : status,
    message: resultMessage,
    failureReason: resultMessage,
    requestNo: response.requestNo || checkout.value.requestNo,
    checkoutRequestId: response.checkoutRequestId || checkout.value.checkoutRequestId,
    originalIdempotencyKey,
    canClose: response.canClose === true || resultEnvelope.canClose === true,
    canRetry: response.canRetry === true || resultEnvelope.canRetry === true,
    // This exact validation runs before any settlement fact. Reopen the same
    // request as an editable draft, then return to the cart to select staff.
    canReturnToPaymentEdit: craftsmanValidationFailed,
    canReturnToCashierEdit: craftsmanValidationFailed
  }
  return result
}

async function openCheckoutFromService(event = {}) {
  const detail = event.detail || {}
  const requestedServiceOrderId = detail.serviceOrderId
  const snapshot = clonePlain(checkout.value)
  const sessionReady = checkoutSession.value?.stateContextId === String(state.stateContextId || '')
    && checkoutSession.value?.checkoutRequestId === String(checkoutRequestIdentity(snapshot) || '')
  if (
    !isServiceCheckoutResumeReady(requestedServiceOrderId)
    || (!sessionReady && !activateCheckoutSession(snapshot, snapshot.preparationRequestId, { recovery: true }))
  ) {
    // 这表示后端没有把“继续结账”返回的工作台切换到同一张服务单；
    // 或只返回了不完整 checkout。两种情况都不允许以前一张收银单代替。
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: {
        status: 'failed',
        code: 'SERVICE_ORDER_CHECKOUT_CONTEXT_MISSING',
        message: '服务确认后的结账现场尚未完整装载，请重新打开本次服务单后再结账。',
        feedback: null,
        navigation: null,
        overlay: null
      }
    }))
    return
  }
  isCheckoutOpen.value = true
}

async function openPreparedCheckout(event = {}) {
  const detail = event.detail || {}
  let snapshot = clonePlain(checkout.value)
  if (!isCompleteCheckoutPreparation(snapshot, detail.preparationRequestId)) {
    await requestAction('open-cashier-workbench', { silent: true })
    snapshot = clonePlain(checkout.value)
  }
  if (!detail.preparationRequestId
    || detail.businessType !== snapshot.businessType
    || !activateCheckoutSession(snapshot, detail.preparationRequestId)) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: {
        status: 'failed',
        code: 'PREPARED_CHECKOUT_CONTEXT_MISSING',
        message: '本次收款现场尚未完整装载，请返回欠款明细后重试。',
        feedback: null,
        navigation: null,
        overlay: null
      }
    }))
    return
  }
  isCheckoutOpen.value = true
}

function consumePreparedCheckoutHandoff() {
  const key = 'cashier-v3:prepared-checkout-handoff'
  try {
    const raw = window.sessionStorage.getItem(key)
    window.sessionStorage.removeItem(key)
    const handoff = raw ? JSON.parse(raw) : null
    if (!handoff || handoff.businessType !== 'debt_repayment' || !handoff.preparationRequestId) return null
    return handoff
  } catch (_) {
    window.sessionStorage.removeItem(key)
    return null
  }
}

function serviceOrderCommandPayload() {
  if (!serviceOrder.value) return {}
  return {
    serviceOrderId: serviceOrder.value.id,
    serviceOrderVersion: serviceOrder.value.revision,
    recordVersion: serviceOrder.value.revision
  }
}

function clearEntitlementBoundSnapshots({ preservePending = false } = {}) {
  if (entitlementAddedTimer) window.clearTimeout(entitlementAddedTimer)
  entitlementAddedTimer = null
  isEntitlementSelectorOpen.value = false
  isOpeningEntitlementSelector.value = false
  isAddingEntitlementLines.value = false
  entitlementSelectorLoadState.value = 'idle'
  entitlementSelectorLoadError.value = ''
  pendingEntitlementProjectKey.value = ''
  addedEntitlementProjectKey.value = ''
  addedEntitlementLineId.value = ''
  entitlementSelectorRequestId.value = null
  entitlementSelectorSnapshot.value = null
  cashierDraftSnapshot.value = null
  cashierDraftHasUnresolvedCommand.value = Boolean(
    draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  )
  if (!preservePending) pendingEntitlementSelector.value = false
  if (!preservePending) pendingCustomCardEntry.value = false
}

function resetCashierLocalContext() {
  cashierContextEpoch.value += 1
  previewCardOperation.value = null
  isCheckoutOpen.value = false
  isHangOrderOpen.value = false
  hangOrderPreparationId.value = null
  hangOrderSession.value = null
  isPreparingServiceCompletion.value = false
  serviceCompletionPreparationId.value = null
  isPreparingCheckout.value = false
  checkoutPreparationId.value = null
  checkoutSession.value = null
  checkoutRecoveryActiveStep.value = null
  debtEditor.value = null
  debtEditorAmount.value = ''
  couponSelector.value = null
  isSavingLineCoupon.value = false
  checkoutLocalOutcome.value = {}
  checkoutRequiresRootReload.value = false
  clearEntitlementBoundSnapshots()
}

async function closeCheckoutOverlay(options = {}) {
  if (checkoutRequiresRootReload.value) {
    checkoutRequiresRootReload.value = false
    window.location.reload()
    return
  }
  if (options?.discardFailedCheckout === true) {
    try {
      await discardCashierCheckout(String(state.stateContextId || ''))
      // discard-checkout 是独立 HTTP 命令，不能让前端继续保留它之前的
      // ready/failed 投影，否则下次会把已删请求误当成可恢复草稿复用。
      // 只读重建当前工作台会保留购物车，且服务端明确返回空 checkout。
      await requestAction('open-cashier-workbench', { silent: true })
      if (String(checkoutRequestIdentity(checkout.value) || '') !== '') {
        throw new Error('旧结账草稿尚未清理完成，请刷新后重试。')
      }
    } catch (error) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', message: String(error?.message || '结账草稿清理失败，请重试。') }
      }))
      return
    }
  }
  isCheckoutOpen.value = false
  checkoutPreparationId.value = null
  checkoutSession.value = null
  checkoutRecoveryActiveStep.value = null
  checkoutLocalOutcome.value = {}
}

async function closeSucceededCheckoutAndRefreshWorkbench(submissionResponse = {}) {
  // submit-checkout returns the committed, empty cashier draft in its command
  // receipt. Keep it as the immediate local projection before closing the
  // overlay. A root refresh can be delayed or rejected as stale by the bridge;
  // it must never make the pre-settlement cart visible again.
  const committedDraft = responseDataBlock(submissionResponse).cashierDraft
  const canRenderCommittedDraft = isResponseBoundCommittedCashierDraft(committedDraft)
  checkoutRequiresRootReload.value = false
  await closeCheckoutOverlay()
  // Settlement has already completed. Re-read the same workbench so the
  // cashier never returns to a stale cart that could be submitted again.
  // The draft snapshot is only an optimistic local projection. Once the
  // checkout reaches its success terminal state it can never remain eligible
  // for another submission, even if the following root-state read is delayed.
  cashierDraftSnapshot.value = canRenderCommittedDraft
    ? Object.freeze({
        scopeKey: currentCashierDraftScopeKey.value,
        snapshot: Object.freeze(clonePlain(committedDraft))
      })
    : null
  cashierDraftHasUnresolvedCommand.value = false
  await requestAction('open-cashier-workbench', { silent: true })
}

function closeHangOrderOverlay() {
  isHangOrderOpen.value = false
  hangOrderPreparationId.value = null
  hangOrderSession.value = null
}

function applyRestoredHangDraft(detail = {}) {
  const draft = detail?.cashierDraft
  if (!isResponseBoundCommittedCashierDraft(draft)) return false
  if (String(draft.stateContextId || '') !== String(state.stateContextId || '')) return false
  cashierDraftSnapshot.value = Object.freeze({
    scopeKey: currentCashierDraftScopeKey.value,
    snapshot: Object.freeze(clonePlain(draft))
  })
  cashierDraftHasUnresolvedCommand.value = false
  return true
}

function handleRestoredHangDraft(event) {
  applyRestoredHangDraft(event?.detail || {})
}

watch(
  cashierScopeIdentity,
  (current, previous) => {
    if (!previous || cashierBusinessScopeKey(current) === cashierBusinessScopeKey(previous)) return
    const businessScopeChanged = true
    const preservePending = shouldPreservePendingEntitlementSelector({
      pending: pendingEntitlementSelector.value,
      current,
      previous
    })
    if (businessScopeChanged) {
      cashierContextEpoch.value += 1
      debtEditor.value = null
      debtEditorAmount.value = ''
      couponSelector.value = null
      isSavingLineCoupon.value = false
    }
    clearEntitlementBoundSnapshots({ preservePending })
  },
  // Root projections are replaced by deleting and reassigning reactive keys.
  // Compare only the final state of that synchronous replacement batch.
  { flush: 'pre' }
)

onMounted(() => {
  cashierDraftHasUnresolvedCommand.value = Boolean(
    draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
  )
  window.addEventListener('cashier-v3:open-checkout', openCheckoutFromService)
  window.addEventListener('cashier-v3:open-prepared-checkout', openPreparedCheckout)
  window.addEventListener('cashier-v3:open-entitlement-selector', handleOpenEntitlementSelector)
  window.addEventListener('cashier-v3:open-card-operation', handleOpenCardOperation)
  window.addEventListener('cashier-v3:open-guided-business', openGuidedBusiness)
  window.addEventListener('cashier-v3:open-recharge', openRecharge)
  window.addEventListener('cashier-v3:member-selector-selected', handleMemberSelectedForEntitlement)
  window.addEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosedForEntitlement)
  window.addEventListener('cashier-v3:state-context-changing', resetCashierLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetCashierLocalContext)
  window.addEventListener('cashier-v3:refresh-workbench', refreshWorkbenchAfterContextConflict)
  window.addEventListener('cashier-v3:hang-draft-restored', handleRestoredHangDraft)
  if (window.__cashierV3PendingHangDraft) {
    const pending = window.__cashierV3PendingHangDraft
    delete window.__cashierV3PendingHangDraft
    applyRestoredHangDraft(pending)
  }
  const handoff = consumePreparedCheckoutHandoff()
  if (handoff) void openPreparedCheckout({ detail: handoff })
})

onBeforeUnmount(() => {
  if (entitlementAddedTimer) window.clearTimeout(entitlementAddedTimer)
  window.removeEventListener('cashier-v3:open-checkout', openCheckoutFromService)
  window.removeEventListener('cashier-v3:open-prepared-checkout', openPreparedCheckout)
  window.removeEventListener('cashier-v3:open-entitlement-selector', handleOpenEntitlementSelector)
  window.removeEventListener('cashier-v3:open-card-operation', handleOpenCardOperation)
  window.removeEventListener('cashier-v3:open-guided-business', openGuidedBusiness)
  window.removeEventListener('cashier-v3:open-recharge', openRecharge)
  window.removeEventListener('cashier-v3:member-selector-selected', handleMemberSelectedForEntitlement)
  window.removeEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosedForEntitlement)
  window.removeEventListener('cashier-v3:state-context-changing', resetCashierLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetCashierLocalContext)
  window.removeEventListener('cashier-v3:refresh-workbench', refreshWorkbenchAfterContextConflict)
  window.removeEventListener('cashier-v3:hang-draft-restored', handleRestoredHangDraft)
})
</script>

<template>
  <section
    class="cashier-workbench"
    :class="{ 'cashier-workbench--with-supplement': supplement }"
    aria-label="收银工作台"
  >
    <RechargeOverlay
      v-if="rechargeSession"
      :session="rechargeSession"
      :submitting="isRechargeSubmitting"
      @close="closeRecharge"
      @submit="submitRecharge"
    />
    <div v-if="supplement" class="supplement-banner">
      <div>
        <strong>正在补单</strong>
        <span>业务日期：{{ supplement.businessDate }}</span>
      </div>
      <div class="supplement-banner__actions">
        <button type="button" class="button button--text" @click="openSupplementDateEditor">修改日期</button>
        <button type="button" class="button button--text" @click="requestAction('exit-supplement')">退出补单</button>
      </div>
    </div>

    <div class="cashier-workbench__body">
      <section class="catalog-panel" aria-label="选择商品和项目">
        <CashierGuidedBusinessPanel
          v-if="guidedBusinessMode"
          :mode="guidedBusinessMode"
          :catalog-items="catalog.items || []"
          :submitting="isCreatingCustomCard"
          @close="guidedBusinessMode = ''"
          @confirm="confirmGuidedBusiness"
        />
        <EntitlementSelectorOverlay
          v-else-if="isEntitlementSelectorOpen"
          :selector="entitlementSelector"
          :submitting="isAddingEntitlementLines || isSubmittingCardOperation"
          :workspace-id="state.workspace?.id"
          :current-member-id="currentMemberId"
          :cart-lines="cartLines"
          :pending-project-key="pendingEntitlementProjectKey"
          :added-project-key="addedEntitlementProjectKey"
          :operation-mode="previewCardOperation?.mode || ''"
          :operation-label="previewCardOperation?.label || ''"
          :selected-operation-project-keys="selectedCardOperationProjectKeys"
          :load-state="entitlementSelectorLoadState"
          :load-error-message="entitlementSelectorLoadError"
          @close="closeEntitlementSelector"
          @retry="retryEntitlementSelector"
          @add="addEntitlementLines"
          @contract-error="reportEntitlementContractError"
          @operation-source="handleOperationSource"
          @operation-project="handleOperationProject"
          @operation-target="handleOperationTargetSelection"
          @operation-confirm="handleOperationConfirm"
        />
        <template v-else>
          <div class="catalog-panel__search">
          <label class="search-field">
            <span class="sr-only">请选择客户所需要购买的卡、项目、产品</span>
            <input v-model="keyword" type="search" placeholder="请选择客户所需要购买的卡、项目、产品" autocomplete="off">
          </label>
        </div>

          <div class="catalog-panel__filters">
          <div class="filter-chip-group" aria-label="商品类型">
            <button
              v-for="type in productTypes"
              :key="type"
              type="button"
              class="filter-chip"
              :class="{ 'filter-chip--active': selectedType === type }"
              @click="selectedType = type"
            >
              {{ type }}
            </button>
          </div>
          <div
            v-if="categories.length"
            class="catalog-categories"
            :class="{ 'is-expanded': areCategoriesExpanded }"
            aria-label="商品分类"
          >
            <span>分类：</span>
            <div class="catalog-category-options">
              <button
                v-for="category in categories"
                :key="category"
                type="button"
                class="category-link"
                :class="{ 'category-link--active': category === '全部' ? !selectedCategory : selectedCategory === category }"
                @click="selectCategory(category)"
              >
                {{ category }}
              </button>
            </div>
            <button
              v-if="categories.length > 1"
              type="button"
              class="catalog-categories__toggle"
              :aria-expanded="areCategoriesExpanded"
              @click="areCategoriesExpanded = !areCategoriesExpanded"
            >
              {{ areCategoriesExpanded ? '收起' : '展开' }}
            </button>
          </div>
        </div>

          <div class="catalog-panel__content">
          <div v-if="filteredCatalogItems.length" class="product-grid">
            <button
              v-for="item in filteredCatalogItems"
              :key="item.id"
              type="button"
              class="product-card"
              :class="{ 'product-card--selected': catalogItemSelectedQuantity(item) > 0 }"
              :disabled="item.disabled"
              :title="item.disabled ? (item.disabledReason || '当前不可加入') : `加入${item.name}`"
              @click="selectCatalogItem(item)"
            >
              <span
                v-if="catalogItemSelectedQuantity(item) > 0"
                class="product-card__selected-count"
                :aria-label="`已选${catalogItemSelectedQuantity(item)}件`"
              >{{ catalogItemSelectedQuantity(item) }}</span>
              <span class="product-card__name">{{ item.name }}</span>
              <span v-if="item.cardRuleLabel || item.specification" class="product-card__specification">
                {{ [item.cardRuleLabel, item.specification].filter(Boolean).join(' · ') }}
              </span>
              <strong class="product-card__price">{{ formatMoney(item.price) }}</strong>
              <span v-if="item.stockText" class="product-card__stock" :class="{ 'product-card__stock--warning': item.stockWarning }">
                {{ item.stockText }}
              </span>
            </button>
          </div>
          <div v-else class="catalog-empty-state">
            <strong>暂无可售商品</strong>
            <span>请修改搜索条件或商品分类后再试。</span>
          </div>
          </div>
        </template>
      </section>

      <section class="cart-panel" aria-label="会员和购物车">
        <div class="cart-panel__content">
          <section v-if="previewCardOperation?.sources?.length" class="cashier-operation-preview" :aria-label="previewCardOperation.label">
            <header>
              <strong>{{ previewCardOperation.label }}</strong>
              <span>{{ cardOperationTargetModes.has(previewCardOperation.mode) ? '待确认' : '正在确认' }}</span>
            </header>
            <div v-for="source in previewCardOperation.sources" :key="source.id || source.projectId || source.name" class="cashier-operation-preview__source">
              <strong>{{ source.name }}</strong>
              <span v-if="source.cardName">来源：{{ source.cardName }}</span>
            </div>
            <div class="cashier-operation-preview__arrow" aria-hidden="true">↓</div>
            <div class="cashier-operation-preview__target" :class="{ 'is-filled': previewCardOperation.target }">
              <template v-if="previewCardOperation.target">
                <strong>{{ previewCardOperation.target.name }}</strong>
                <span>{{ previewCardOperation.mode === 'card-transfer' ? '新会员' : previewCardOperation.target.kind }}</span>
              </template>
              <span v-else>{{ cardOperationTargetPrompt(previewCardOperation.mode) }}</span>
            </div>
            <label v-if="cardOperationReasonModes.has(previewCardOperation.mode) && previewCardOperation.target" class="cashier-operation-preview__reason">
              <span>{{ cardOperationReasonLabel(previewCardOperation.mode) }}</span>
              <textarea v-model.trim="previewCardOperation.reason" rows="2" maxlength="500" :placeholder="`请填写${cardOperationReasonLabel(previewCardOperation.mode)}`"></textarea>
            </label>
            <button
              v-if="['card-transfer', 'project-replacement'].includes(previewCardOperation.mode) && previewCardOperation.target"
              type="button"
              class="button button--primary cashier-operation-preview__confirm"
              :disabled="isSubmittingCardOperation || (cardOperationReasonModes.has(previewCardOperation.mode) && !String(previewCardOperation.reason || '').trim())"
              @click="confirmPreviewCardOperation"
            >{{ cardOperationConfirmLabel(previewCardOperation.mode) }}</button>
            <span v-if="isSubmittingCardOperation && ['card-upgrade', 'project-upgrade'].includes(previewCardOperation.mode)" role="status">正在加入购物车…</span>
            <button
              type="button"
              class="button button--secondary cashier-operation-preview__cancel"
              :disabled="isSubmittingCardOperation"
              @click="abandonCardOperation"
            >取消本次操作</button>
          </section>
          <div v-if="cartLines.length" class="cart-line-list">
            <section v-for="group in cartGroups" :key="group.key" class="cart-line-group">
              <header class="cart-line-group__header">
                <strong>{{ group.label }}</strong>
                <span>已添加 {{ group.lines.length }} 项</span>
              </header>
              <article
                v-for="line in group.lines"
                :key="line.id"
                class="cart-line"
                :class="{
                  'cart-line--active': activeCartLineId === line.id,
                  'cart-line--upgrade': Boolean(cardOperationUpgradeBinding(line)),
                  'cart-line--just-added': isEntitlementLine(line)
                    && addedEntitlementLineId
                    && line.id === addedEntitlementLineId
                }"
                @click="activeCartLineId = line.id"
              >
              <section
                v-if="cardOperationUpgradeBinding(line)"
                class="cart-line__upgrade-flow"
                :aria-label="cardOperationUpgradeLabel(cardOperationUpgradeBinding(line))"
              >
                <div class="cart-line__upgrade-heading">
                  <strong>{{ cardOperationUpgradeLabel(cardOperationUpgradeBinding(line)) }}</strong>
                  <span>原权益抵扣后结账</span>
                </div>
                <div class="cart-line__upgrade-route">
                  <div>
                    <small>原卡</small>
                    <strong>{{ cardOperationUpgradeBinding(line).sourceCardName || '原卡' }}</strong>
                    <span v-if="cardOperationUpgradeBinding(line).sourceCardNo">卡号 {{ cardOperationUpgradeBinding(line).sourceCardNo }}</span>
                  </div>
                  <span class="cart-line__upgrade-arrow" aria-hidden="true">→</span>
                  <div>
                    <small>目标{{ cardOperationUpgradeBinding(line).operationType === 'project_upgrade' ? '项目' : '卡' }}</small>
                    <strong>{{ line.name }}</strong>
                  </div>
                </div>
                <div class="cart-line__upgrade-amounts">
                  <span>旧权益抵扣 <strong>-{{ formatMoney(cardOperationUpgradeMoney(cardOperationUpgradeBinding(line), 'sourceRemainingValueCents')) }}</strong></span>
                  <span>本次应收 <strong>{{ formatMoney(cardOperationUpgradeMoney(cardOperationUpgradeBinding(line), 'settlementDeltaCents')) }}</strong></span>
                </div>
              </section>
              <div class="cart-line__header">
                <div class="cart-line__title-block">
                  <div class="cart-line__title-row">
                    <strong>{{ line.name }}</strong>
                    <span v-if="isEntitlementLine(line)" class="cart-line__role" :class="`cart-line__role--${cartLineRole(line)}`">{{ cartLineRoleLabel(line) }}</span>
                    <span
                      v-if="!isEntitlementLine(line) && line.serviceSource && line.serviceSource !== '本次购买'"
                      class="cart-line__source"
                    >{{ line.serviceSource }}</span>
                    <span
                      v-if="line.serviceRole && !['本次购买', '本次使用权益', '使用权益'].includes(line.serviceRole)"
                      class="cart-line__service-role"
                    >{{ line.serviceRole }}</span>
                  </div>
                  <span
                    v-if="isEntitlementLine(line)"
                    class="cart-line__source-detail"
                    :title="line.entitlementSourceName || line.sourceName || line.serviceSource"
                  >
                    来源：{{ line.entitlementSourceName || line.sourceName || line.serviceSource || '会员权益' }}
                    <template v-if="line.fullCardNo || line.cardNo"> · 卡号：{{ line.fullCardNo || line.cardNo }}</template>
                    · 剩余 {{ line.remainingTimes ?? '—' }} · 占用 {{ line.occupiedTimes ?? line.reservedTimes ?? 0 }} · 可用 {{ line.availableTimes ?? '—' }}
                  </span>
                </div>
                <div class="cart-line__header-actions">
                  <div class="cart-line__amount">
                    <span v-if="linePriceChanged(line)" class="cart-line__original-price">
                      {{ formatMoney(line.originalAmount) }}
                    </span>
                    <strong
                      v-if="!isEntitlementLine(line)"
                      :class="{ 'cart-line__current-price--changed': linePriceChanged(line) }"
                    >{{ formatMoney(cardOperationUpgradeBinding(line) ? line.originalAmount : getLineAmount(line)) }}</strong>
                    <strong
                      v-else
                      class="cart-line__entitlement-amount"
                      title="权益实际金额，不计入本次应收"
                    >{{ formatMoney(line.actualAmount) }}</strong>
                  </div>
                  <button
                    type="button"
                    class="cart-line__delete"
                    :disabled="Boolean(cardOperationUpgradeBinding(line))"
                    :aria-label="`删除${line.name}`"
                    title="删除"
                    @click.stop="removeCartLine(line)"
                  >
                    <span aria-hidden="true">×</span>
                  </button>
                </div>
              </div>

              <div class="cart-line__meta">
                <div class="cart-line__secondary-meta" :class="{ 'is-guest-order': cashier.customerMode === 'guest' }">
                  <div v-if="cashier.customerMode !== 'guest'" class="cart-line__meta-slot cart-line__meta-slot--debt">
                    <button
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled"
                      :disabled="isEntitlementLine(line)"
                      :title="cartActionLabel('欠款', checkoutDebtSummary(line))"
                      @click="openCartLineDebt(line)"
                    >
                      {{ cartActionLabel('欠款', checkoutDebtSummary(line)) }}
                    </button>
                  </div>
                  <div class="cart-line__meta-slot cart-line__meta-slot--coupon">
                    <button
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled"
                      :disabled="isEntitlementLine(line)"
                      :title="cartActionLabel('优惠券', line.couponSummary)"
                      @click="openCartLineCoupon(line)"
                    >
                      {{ cartActionLabel('优惠券', line.couponSummary) }}
                    </button>
                  </div>
                  <div v-if="!isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--salesperson">
                    <button
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled"
                      :disabled="isEntitlementLine(line)"
                      :title="`销售人:${salespersonDisplaySummary(line)}`"
                      @click="openCartLineSalespeople(line)"
                    >
                      销售人:{{ salespersonDisplaySummary(line) }}
                    </button>
                  </div>
                  <div class="cart-line__meta-slot cart-line__meta-slot--craftsmen">
                    <button
                      v-if="isProjectLine(line) && !cardOperationUpgradeBinding(line)"
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled cart-line__meta-action--craftsmen"
                      :class="{ 'is-required-missing': !hasCartLineCraftsmen(line) }"
                      :title="`手艺人:${craftsmenDisplaySummary(line)}`"
                      @click="openCartLineCraftsmen(line)"
                    >
                      {{ craftsmenDisplaySummary(line) }}
                    </button>
                  </div>
                  <div class="cart-line__meta-slot cart-line__meta-slot--service-object">
                    <div v-if="isProjectLine(line) && !cardOperationUpgradeBinding(line)" class="cart-line__service-controls">
                      <button
                        type="button"
                        class="cart-line__experience"
                        :class="{ 'is-active': cartLineExperienceSelected(line) }"
                        :aria-pressed="cartLineExperienceSelected(line)"
                        :title="cartLineExperienceSelected(line) ? '取消体验项目标记' : '标记为体验项目'"
                        @click.stop="toggleCartLineExperience(line)"
                      >体验</button>
                      <div class="cart-line__service-object" aria-label="服务对象">
                        <button
                          type="button"
                          :class="{ 'is-active': cartLineServiceObject(line) === 'self' }"
                          @click="setCartLineServiceObject(line, 'self')"
                        >本人</button>
                        <button
                          type="button"
                          :class="{ 'is-active': cartLineServiceObject(line) === 'friend' }"
                          @click="setCartLineServiceObject(line, 'friend')"
                        >朋友</button>
                      </div>
                    </div>
                  </div>
                  <div class="quantity-stepper quantity-stepper--editable" aria-label="数量操作">
                    <button
                      type="button"
                      aria-label="减少数量"
                      :disabled="Boolean(cardOperationUpgradeBinding(line)) || Number(line.quantity || 1) <= 1"
                      @click="changeLineQuantity(line, -1)"
                    >−</button>
                    <input
                      :value="line.quantity || 1"
                      type="number"
                      min="1"
                      inputmode="numeric"
                      :disabled="Boolean(cardOperationUpgradeBinding(line))"
                      :aria-label="isEntitlementLine(line) ? '本次使用次数' : '商品数量'"
                      @focus="activeCartLineId = line.id"
                      @change="setLineQuantity(line, $event)"
                    >
                    <button
                      type="button"
                      aria-label="增加数量"
                      :disabled="Boolean(cardOperationUpgradeBinding(line))"
                      @click="changeLineQuantity(line, 1)"
                    >＋</button>
                  </div>
                </div>
              </div>
              </article>
            </section>
          </div>

          <div v-else class="cart-empty-state">
            <strong>还没有选择商品</strong>
            <span>请在左侧点击项目、产品或卡项加入本单。</span>
          </div>
        </div>

      <footer class="cashier-checkout-bar">
      <div class="cashier-checkout-bar__purchase">
        <div class="cashier-checkout-summary">
          <span>原价<strong>{{ formatPlainAmount(summary.originalAmount) }}</strong></span>
          <span>优惠<strong>{{ formatPlainAmount(summary.discountAmount) }}</strong></span>
          <span class="cashier-checkout-summary__receivable">应收<strong>{{ formatMoney(summary.receivableAmount) }}</strong></span>
        </div>

        <div class="cashier-checkout-actions">
        <button
          type="button"
          class="button button--secondary cashier-checkout-actions__clear"
          :disabled="!hasCartLines || isClearingCart"
          @click="confirmClearCart"
        >{{ isClearingCart ? '清空中…' : '清空' }}</button>
        <button
          type="button"
          class="button button--secondary cashier-checkout-actions__price"
          :disabled="Boolean(activeCardOperationUpgrade)"
          @click="openMoreAction('open-price-change')"
        >改价</button>
        <button
          type="button"
          class="button button--secondary cashier-checkout-actions__note"
          :class="{ 'cashier-checkout-actions__note--noted': summary.hasOrderNote }"
          :disabled="Boolean(activeCardOperationUpgrade)"
          @click="openMoreAction('open-order-note')"
        >备注</button>
        <button type="button" class="button button--secondary cashier-checkout-actions__hang" :disabled="!hasCartLines || isSavingHangDraft || Boolean(activeCardOperationUpgrade)" @click="openHangOrder">{{ isSavingHangDraft ? '挂单中…' : '挂单' }}</button>
        <button type="button" class="button button--primary cashier-checkout-actions__submit" :disabled="!canSubmitCart || isPreparingServiceCompletion || isPreparingCheckout" @click="openCheckout">
          {{ isPreparingServiceCompletion ? '正在准备服务确认…' : isPreparingCheckout ? '正在准备结账…' : checkoutEntryLabel }}
        </button>
        </div>
      </div>
      </footer>
      </section>
    </div>

    <Teleport to="body">
      <CashierCheckoutOverlay
        v-if="isCheckoutOpen"
        :checkout="checkoutOverlayState"
        :is-service-order="isServiceOrder && !isDebtRepaymentCheckout"
        :business-sources="checkoutInlineBusinessSources"
        :business-sources-loading="isLoadingCheckoutBusinessSources"
        :business-sources-load-error="checkoutBusinessSourcesLoadError"
        :business-source-saving="isSavingCheckoutBusinessSource"
        :sales-date-max="cashierToday"
        :sales-date-saving="isSavingCheckoutSalesDate"
        @close="closeCheckoutOverlay"
        @completed="closeSucceededCheckoutAndRefreshWorkbench"
        @request="enqueueCheckoutAction"
        @business-source-change="saveInlineCheckoutBusinessSource"
        @retry-business-sources="loadInlineCheckoutBusinessSources"
        @sales-date-change="saveCheckoutSalesDate"
      />
    </Teleport>

    <CheckoutBusinessSourceOverlay
      v-if="checkoutBusinessSourceSelector"
      :sources="checkoutBusinessSourceSelector.sources"
      :primary-source-id="checkoutBusinessSourceSelector.primarySourceId"
      :secondary-source-id="checkoutBusinessSourceSelector.secondarySourceId"
      :saving="isSavingCheckoutBusinessSource"
      :load-error="checkoutBusinessSourceSelector.loadError"
      @close="closeCheckoutBusinessSourceSelector"
      @confirm="saveCheckoutBusinessSource"
      @retry="reloadCheckoutBusinessSourceCatalog"
    />

    <Teleport to="body">
      <CashierCheckoutOverlay
        v-if="rechargeCheckout"
        :checkout="rechargeCheckout"
        :business-sources="checkoutInlineBusinessSources"
        :business-sources-loading="isLoadingCheckoutBusinessSources"
        :business-sources-load-error="checkoutBusinessSourcesLoadError"
        :business-source-saving="isSavingCheckoutBusinessSource"
        :sales-date-max="cashierToday"
        :sales-date-saving="isSavingRechargeDate"
        @close="closeRechargeCheckout"
        @completed="closeRechargeCheckout"
        @request="enqueueRechargeCheckoutAction"
        @business-source-change="saveInlineRechargeBusinessSource"
        @retry-business-sources="loadInlineRechargeBusinessSources"
        @recharge-date-change="saveRechargeBusinessDate"
      />
    </Teleport>

    <Teleport to="body">
      <PersonnelPerformanceOverlay
        v-if="personnelOverlay"
        :initial-tab="personnelOverlay.initialTab"
        :show-craftsmen="personnelOverlay.showCraftsmen"
        :show-salespeople="personnelOverlay.showSalespeople"
        :require-craftsmen="personnelOverlay.requireCraftsmen"
        :craftsmen-candidates="personnelOverlay.craftsmenCandidates"
        :salesperson-candidates="personnelOverlay.salespersonCandidates"
        :selected-craftsmen="personnelOverlay.selectedCraftsmen"
        :selected-salespeople="personnelOverlay.selectedSalespeople"
        :loading="personnelOverlay.loading"
        :saving="isSavingPersonnelAssignment"
        :load-error="personnelOverlay.loadError"
        @close="personnelOverlay = null"
        @confirm="confirmPersonnelAssignment"
        @apply-all="applyPersonnelAssignmentToAll"
        @retry="retryPersonnelOverlay"
      />
    </Teleport>

    <HangOrderOverlay
      v-if="isHangOrderOpen"
      :member="member"
      :cart-lines="cartLines"
      :summary="summary"
      :hang-order="hangOrderOverlayState"
      :service-order="serviceOrder"
      :on-submit="submitHangOrder"
      :on-query="queryHangOrderResult"
      @close="closeHangOrderOverlay"
    />

    <Teleport to="body">
      <CashierCouponSelectorOverlay
        v-if="couponSelector"
        :selector="couponSelector"
        :saving="isSavingLineCoupon"
        @close="couponSelector = null"
        @apply="applyLineCoupon"
        @remove="removeLineCoupon"
      />
    </Teleport>

    <Teleport to="body">
      <div
        v-if="moreActionEditor"
        class="cashier-card-operation-editor"
        role="dialog"
        aria-modal="true"
        :aria-label="moreActionEditor.title"
        @click.self="closeMoreActionEditor"
      >
        <div class="cashier-card-operation-editor__panel">
          <header><strong>{{ moreActionEditor.title }}</strong></header>
          <label v-if="moreActionEditor.type === 'order-note'">
            <span>备注内容</span>
            <textarea v-model="moreActionValue" rows="4" maxlength="500" placeholder="填写本单备注"></textarea>
          </label>
          <template v-else-if="moreActionEditor.type === 'price-change'">
            <span>{{ moreActionEditor.line.name }}，当前金额 {{ formatMoney(getLineAmount(moreActionEditor.line)) }}</span>
            <label>
              <span>改价后金额（整数元）</span>
              <input v-model="moreActionValue" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="0">
            </label>
            <label>
              <span>改价原因</span>
              <textarea v-model.trim="moreActionReason" rows="3" maxlength="255" placeholder="必填"></textarea>
            </label>
          </template>
          <template v-else>
            <label>
              <span>业务日期</span>
              <input v-model="moreActionValue" type="date" :max="cashierToday">
            </label>
            <label>
              <span>补单原因</span>
              <textarea v-model.trim="moreActionReason" rows="3" maxlength="255" placeholder="必填"></textarea>
            </label>
          </template>
          <p v-if="moreActionValidationMessage" class="cashier-card-operation-editor__error" role="alert">{{ moreActionValidationMessage }}</p>
          <footer>
            <button type="button" class="button button--secondary" :disabled="isSavingMoreAction" @click="closeMoreActionEditor">取消</button>
            <button
              type="button"
              class="button button--primary"
              :disabled="isSavingMoreAction"
              @click="saveMoreActionEditor"
            >{{ isSavingMoreAction ? '保存中…' : '保存' }}</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="debtEditor"
        class="cashier-card-operation-editor"
        role="dialog"
        aria-modal="true"
        aria-label="设置商品欠款"
        @click.self="debtEditor = null"
      >
        <div class="cashier-card-operation-editor__panel">
          <header><strong>设置商品欠款</strong></header>
          <label class="cashier-debt-editor__field">
            <span>欠款金额</span>
            <input
              v-model="debtEditorAmount"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              placeholder="0"
              @keydown.enter.prevent="confirmCheckoutDebt"
            >
          </label>
          <span>该商品金额 {{ formatMoney(lineSaleAmountCents(debtEditor.line) / 100) }}，结账时只收剩余金额。</span>
          <footer>
            <button type="button" class="button button--secondary" @click="debtEditor = null">取消</button>
            <button
              v-if="lineDebtAmountCents(debtEditor.line) > 0"
              type="button"
              class="button button--secondary"
              @click="debtEditorAmount = '0'; confirmCheckoutDebt()"
            >清除欠款</button>
            <button type="button" class="button button--primary" @click="confirmCheckoutDebt">确认</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="isMemberRequiredOpen" class="cashier-card-operation-editor" role="dialog" aria-modal="true" aria-label="购买卡项需要会员档案" @click.self="isMemberRequiredOpen = false">
        <div class="cashier-card-operation-editor__panel">
          <header><strong>购买卡项需要会员档案</strong></header>
          <span>请先创建会员档案，再购买卡项。</span>
          <footer>
            <button type="button" class="button button--primary" @click="isMemberRequiredOpen = false">我知道了</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="pendingCardPurchase"
        class="cashier-card-operation-editor"
        role="dialog"
        aria-modal="true"
        aria-label="确认卡项内容"
        @click.self="closePendingCardPurchase"
      >
        <div class="cashier-card-operation-editor__panel cashier-card-purchase-preview">
          <header>
            <strong>确认卡项内容</strong>
            <span>{{ pendingCardPurchase.name }} · {{ pendingCardPurchase.cardRuleLabel }}</span>
          </header>
          <p>{{ cardPreviewRuleDescription(pendingCardPurchase.cardPreview) }}</p>
          <p>有效期：{{ cardPreviewValidityText(pendingCardPurchase.cardPreview.validity) }}</p>
          <section class="cashier-card-purchase-preview__components" aria-label="卡内项目">
            <div v-for="component in pendingCardPurchase.cardPreview.components" :key="`${component.name}-${component.specification}`">
              <span>{{ component.name }}<template v-if="component.specification"> · {{ component.specification }}</template></span>
              <strong v-if="pendingCardPurchase.cardPreview.ruleType === 'time'">单次核销 {{ formatPlainAmount(component.writeoffAmountCents / 100) }}</strong>
              <strong v-else-if="pendingCardPurchase.cardPreview.ruleType !== 'choice_count'">{{ component.writeTimes }} 次</strong>
            </div>
          </section>
          <footer>
            <button type="button" class="button button--secondary" :disabled="isConfirmingCardPurchase" @click="closePendingCardPurchase">取消</button>
            <button type="button" class="button button--primary" :disabled="isConfirmingCardPurchase" @click="confirmPendingCardPurchase">
              {{ isConfirmingCardPurchase ? '正在加入…' : '确认加入购物车' }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="isCustomCardConflictOpen" class="cashier-card-operation-editor" role="dialog" aria-label="当前购物车已有内容">
        <div class="cashier-card-operation-editor__panel">
          <header><strong>当前购物车已有内容</strong></header>
          <span>定制卡不能与其他商品同时结账，请先处理当前订单。</span>
          <footer>
            <button type="button" class="button button--secondary" @click="isCustomCardConflictOpen = false">取消</button>
            <button type="button" class="button button--secondary" @click="isCustomCardConflictOpen = false; openHangOrder()">先挂当前订单</button>
            <button type="button" class="button button--primary" @click="continueCustomCard">清空并继续</button>
          </footer>
        </div>
      </div>
    </Teleport>
  </section>
</template>
