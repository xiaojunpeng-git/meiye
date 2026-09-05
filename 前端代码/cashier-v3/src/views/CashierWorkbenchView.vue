<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  createCashierV3CommandId,
  formatMoney,
  getCashierV3PublicVersion,
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
import { clearCashierDraft, saveHangDraft } from '@/services/hangDraftApi'
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
import { salesOrderReceiptFromCheckout } from '@/services/salesOrderReceiptPrint'
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
// 游客开单优先展示项目；选中会员后切回卡项，便于先办理会员卡。
// 实际目录仍由后端按当前门店权限返回。
const preferredCatalogTypeOrder = ['卡项', '定制卡', '产品', '项目']
const selectedType = ref('项目')
const selectedCategory = ref('')
const areCategoriesExpanded = ref(false)
const activeCartLineId = ref(null)
// 保留最近一次数量校验失败，避免后端拒绝超量后输入框被恢复为权威数量，
// 但结账按钮仍沿用旧购物车继续进入结账向导。
const cartQuantityValidationError = ref('')
// 服务对象、朋友是否计客与体验标记是本次结账的行设置。点击时只更新当前
// 工作台投影，准备结账前再和其它草稿写入一起持久化，避免每次点选都阻塞收银员。
const localLineServiceSettings = ref({})
const isPersistingDeferredLineSettings = ref(false)
const previewCardOperation = ref(null)
const selectedCardOperationProjectKeys = computed(() => (previewCardOperation.value?.sources || []).map((source) => (
  `${entitlementCardHolderId(source)}:${entitlementBenefitPoolId(source)}`
)))
const guidedBusinessMode = ref('')
const isCustomCardConflictOpen = ref(false)
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
// 收银操作工具栏中的来源与业务日期属于浏览器编辑态；没有 checkout_request
// 时绝不调用服务端命令，首次点击立即结账才会把它们放进完整快照。
const localCheckoutBusinessDate = ref(cashierToday)
const localCheckoutBusinessDateReason = ref('')
const localCheckoutBusinessSource = ref({
  primarySourceId: 0,
  secondarySourceId: 0,
  rewardAmountCents: 0
})
const rechargeSession = ref(null)
const rechargePreparationIdempotencyKey = ref('')
const isRechargeSubmitting = ref(false)
const rechargePreparationError = ref('')
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
const pendingEntitlementAfterSource = ref(false)
const isCreatingCustomCard = ref(false)
const isSubmittingCardOperation = ref(false)
const cardOperationNotice = ref('')
const entitlementSelectorRequestId = ref(null)
const pendingEntitlementSelector = ref(false)
const pendingCustomCardEntry = ref(false)
const pendingEntitlementProjectKey = ref('')
const addedEntitlementProjectKey = ref('')
const addedEntitlementLineId = ref('')
// 权益选择器只读取会员权益，但其完整根投影可能在用户点击“添加”后才到达。
// 保存当前本地草稿，避免迟到的根投影把尚未同步的购买行替换成纯权益行。
const entitlementSelectorDraftCheckpoint = ref(null)
const cashierContextEpoch = ref(0)
const entitlementSelectorSnapshot = ref(null)
const cashierDraftSnapshot = ref(null)
// 收银编辑阶段只维护浏览器内的草稿。每一项操作保留为顺序命令，直到
// 收银员点击挂单或立即结账时才统一写入当前工作台。
const localCashierDraftOperations = ref([])
const localCashierPersistedLineIds = ref({})
const isSynchronizingLocalCashierDraft = ref(false)
const draftCommandRecovery = useCashierV3DraftCommandRecovery(createCashierV3CommandId)
const cashierDraftHasUnresolvedCommand = ref(false)
let entitlementAddedTimer = null
let cardOperationNoticeTimer = null
let localCashierDraftSyncFailureConsumed = false

const defaultTypes = [...preferredCatalogTypeOrder]

const cashier = computed(() => state.cashier || {})
const member = computed(() => cashier.value.member || null)
const currentMemberId = computed(() => member.value?.id || member.value?.memberId || '')
// Browser checkout identity is derived from the selected member itself. The
// root projection's customerMode can lag behind a member-selection response;
// it must never turn a member-bound final snapshot into a guest checkout.
const currentCustomerMode = computed(() => (
  Number(currentMemberId.value) > 0 ? 'member' : 'guest'
))

watch(
  currentCustomerMode,
  (mode) => {
    // 切换客户身份时回到该身份的明确默认目录，避免游客仍停在卡项，
    // 或会员延续游客的项目页。卡操作期间则由其目标选择流程自行锁定类型。
    if (previewCardOperation.value?.awaitingTarget) return
    selectedType.value = mode === 'member' ? '卡项' : '项目'
    selectedCategory.value = ''
  },
  { immediate: true }
)
const {
  rechargeCheckout,
  acceptPreparedCheckout,
  closeRechargeCheckout,
  requestRechargeCheckoutAction,
  enqueueRechargeCheckoutAction
} = useRechargeCheckout({
  member,
  currentMemberId,
  openSourceSelector: (kind) => openCheckoutBusinessSourceSelector(kind)
})
const cashierScopeIdentity = computed(() => ({
  stateContextId: state.stateContextId || '',
  storeId: state.currentStore?.id || '',
  workspaceId: state.workspace?.id || '',
  workspaceVersion: state.workspace?.revision,
  customerMode: currentCustomerMode.value,
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
  currentCustomerMode.value,
  currentMemberId.value || ''
].join('|'))
// A successful draft command advances the workspace revision before its
// authoritative draft is returned.  Draft snapshots therefore belong to the
// stable business scope, while the revision remains part of each next command
// context for optimistic concurrency.
const currentCashierDraftScopeKey = computed(() => cashierBusinessScopeKey(cashierScopeIdentity.value))
// Selector responses may omit workspace fields while they are being merged
// into the root projection. Keep the local draft bound to the stable account,
// store and member identity during that read-only window; a real account/store
// switch still changes stateContextId and is handled by the reset path.
function isEntitlementDraftScopeCompatible(scopeKey, scope = cashierScopeIdentity.value) {
  const expected = String(scopeKey || '').split('|')
  if (expected.length < 5) return false
  // A read-only selector response can temporarily omit identity fields while
  // its root projection is being merged. An omitted field is not a switch;
  // only an explicitly populated, different identity invalidates the local
  // browser cart. This is deliberately symmetric for member and store scope,
  // not just workspace id, because the selector response may be partial at
  // any of those levels.
  if (expected[0] && scope.stateContextId && expected[0] !== String(scope.stateContextId)) return false
  if (expected[1] && scope.storeId && expected[1] !== String(scope.storeId)) return false
  const currentMemberId = String(scope.memberId || '')
  if (expected[4] && currentMemberId && expected[4] !== currentMemberId) return false
  // customerMode is derived projection metadata. The member id is the only
  // explicit customer boundary here; a transient guest/member label cannot
  // invalidate the browser-owned cart while the selector is read-only.
  // Workspace ids/revisions are projection coordinates, not a business
  // switch. The selector is read-only, so a refreshed or temporarily absent
  // workspace projection must never invalidate the browser-owned cart.
  return true
}

function isReadOnlyEntitlementProjectionTransition(current = {}, previous = {}) {
  // Only an in-flight entitlement read may briefly replace the root with a
  // partial member projection. A completed checkout also clears the member,
  // but that is a real new-cashier boundary and must close the old panel.
  if (!isEntitlementSelectorOpen.value
    || !isOpeningEntitlementSelector.value
    || !entitlementSelectorDraftCheckpoint.value) return false
  if (!isEntitlementDraftScopeCompatible(entitlementSelectorDraftCheckpoint.value.scopeKey, current)) return false
  if (current.stateContextId && previous.stateContextId
    && String(current.stateContextId) !== String(previous.stateContextId)) return false
  if (current.storeId && previous.storeId
    && String(current.storeId) !== String(previous.storeId)) return false
  const previousMemberId = String(previous.memberId || '')
  const currentMemberId = String(current.memberId || '')
  return previousMemberId !== ''
    && (currentMemberId === '' || currentMemberId === previousMemberId)
}
const localEntitlementSelector = computed(() => (
  entitlementSelectorSnapshot.value?.scopeKey === currentCashierScopeKey.value
    ? entitlementSelectorSnapshot.value.snapshot
    : null
))
const localCashierDraft = computed(() => (
  cashierDraftSnapshot.value?.scopeKey === currentCashierDraftScopeKey.value
    ? cashierDraftSnapshot.value.snapshot
    // The entitlement selector is a read-only query. If a delayed root
    // projection clears the primary local snapshot while it is open, keep
    // rendering the checkpoint captured before the query. This makes the
    // purchase rows stable in the same render pass instead of waiting for an
    // async revision watcher to restore them after a transient pure-benefit
    // cart has already been painted.
    : isEntitlementSelectorOpen.value
      && isEntitlementDraftScopeCompatible(entitlementSelectorDraftCheckpoint.value?.scopeKey)
      ? entitlementSelectorDraftCheckpoint.value.snapshot
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
// 未决草稿命令必须允许从结账入口按原幂等键回放；若在这里禁用按钮，
// openCheckout() 内的恢复逻辑永远无法执行，工作台会被永久锁住。
const canSubmitCart = computed(() => hasCartLines.value)
const summary = computed(() => cart.value.summary || {})
const entitlementSelector = computed(() => localEntitlementSelector.value || {})
const activeCheckoutComposition = computed(() => localCashierDraft.value?.checkoutComposition || cashier.value.checkoutComposition || null)
const checkoutEntryLabel = computed(() => activeCardOperationUpgrade.value
  ? '立即结账'
  : previewCardOperation.value?.mode === 'project-replacement'
    ? '确认替换'
    : (cart.value.primaryActionLabel || activeCheckoutComposition.value?.primaryActionLabel || '立即结账'))
const productTypes = computed(() => {
  if (previewCardOperation.value?.awaitingTarget) {
    return [previewCardOperation.value.mode === 'card-upgrade' ? '卡项' : '项目']
  }
  const available = catalog.value.types && catalog.value.types.length
    ? catalog.value.types
    : defaultTypes
  const known = preferredCatalogTypeOrder.filter((type) => available.includes(type))
  const other = available.filter((type) => !preferredCatalogTypeOrder.includes(type))
  return [...known, ...other]
})
const categories = computed(() => [
  '全部',
  ...(Array.isArray(catalog.value.categories) ? catalog.value.categories : [])
    .filter((category) => category && category !== '全部')
])

function selectCategory(category) {
  selectedCategory.value = category === '全部' ? '' : category
}

function selectCatalogType(type) {
  if (type === '卡项' && currentCustomerMode.value === 'guest') {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'error', message: '游客无法选择卡项，请选择会员。' }
    }))
    return
  }
  selectedType.value = type
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
const checkoutLocalOutcome = ref({})
// Keep the immutable browser snapshot only while the success overlay is open.
// The workbench is cleared after settlement, but receipt printing still needs
// the exact sale/service rows that were submitted in that transaction.
const checkoutReceiptSnapshot = ref(null)
// 编辑阶段的结账预览不创建 checkout_request。第三步确认时才把本地购物车
// 与收款选择一次性写入既有结账流程，由服务端读取最新余额与权益后完成校验。
const localCheckoutPreview = ref(null)
const localCheckoutPaymentOperations = ref([])
// 欠款补交只消费 prepare 命令交接的持久化快照。根工作台没有也不应拥有
// 这笔浏览器会话，因此所有后续动作从该唯一快照判断业务类型。
const currentCheckoutSnapshot = computed(() => localCheckoutPreview.value || checkout.value)
const isDebtRepaymentCheckout = computed(() => currentCheckoutSnapshot.value.businessType === 'debt_repayment')
// 充值欠款与销售欠款共用统一结账界面，但最终领域命令不同：充值欠款
// 必须写入充值欠款补交事实并记录 recharge_debt_repayment 事件，不能落到
// 销售欠款的 debt_repayment 事件契约。准备快照的权威行名用于区分两者。
const isRechargeDebtRepaymentCheckout = computed(() => (
  isDebtRepaymentCheckout.value
  && Array.isArray(currentCheckoutSnapshot.value.orderLines)
  && currentCheckoutSnapshot.value.orderLines.some((line) => String(line?.name || '') === '充值欠款补交')
))
// A recovered draft can safely reopen on the final confirmation step only
// when the authoritative payment snapshot is already fully balanced.  This
// is a display position, never a settlement instruction.
const checkoutRecoveryActiveStep = ref(null)
const checkoutRequiresRootReload = ref(false)
const checkoutOverlayState = computed(() => ({
  ...(localCheckoutPreview.value || checkout.value),
  ...checkoutLocalOutcome.value,
  ...(activeCardOperationUpgrade.value
    ? {
        cardOperationUpgrade: clonePlain(activeCardOperationUpgrade.value),
        balancePaymentAmount: (Number(activeCardOperationUpgrade.value.sourceRemainingValueCents || 0) / 100).toFixed(2)
      }
    : {}),
  ...(checkoutRecoveryActiveStep.value ? { activeStep: checkoutRecoveryActiveStep.value } : {})
}))

function checkoutRequestIsPersisted(snapshot = checkout.value) {
  return Boolean(String(checkoutRequestIdentity(snapshot) || '').trim())
}

function publishToolbarCheckoutContext() {
  if (typeof window === 'undefined') return
  const source = checkoutSourceSnapshot(
    localCheckoutBusinessSource.value.primarySourceId,
    localCheckoutBusinessSource.value.secondarySourceId,
    localCheckoutBusinessSource.value.rewardAmountCents
  )
  window.dispatchEvent(new CustomEvent('cashier-v3:toolbar-context-updated', {
    detail: {
      businessDate: localCheckoutBusinessDate.value,
      businessDateReason: localCheckoutBusinessDateReason.value,
      source
    }
  }))
}
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

function localDraftBase() {
  const currentCart = cashier.value.cart || {}
  return {
    workspaceId: String(state.workspace?.id || ''),
    stateContextId: String(state.stateContextId || ''),
    customerMode: currentCustomerMode.value,
    memberId: Number(currentMemberId.value || 0),
    lines: clonePlain(currentCart.lines || []),
    summary: clonePlain(currentCart.summary || {}),
    primaryAction: String(currentCart.primaryAction || cashier.value.checkoutComposition?.primaryAction || ''),
    primaryActionLabel: String(currentCart.primaryActionLabel || cashier.value.checkoutComposition?.primaryActionLabel || ''),
    checkoutComposition: clonePlain(cashier.value.checkoutComposition || {}),
    orderNote: String(cashier.value.orderNote || currentCart.summary?.orderNote || ''),
    supplement: clonePlain(cashier.value.supplement || {})
  }
}

function moneyToCents(value) {
  const amount = Number(value)
  return Number.isFinite(amount) ? Math.max(0, Math.round(amount * 100)) : 0
}

function centsToMoney(value) {
  return Math.max(0, Number(value) || 0) / 100
}

function checkoutLineRole(line = {}) {
  return String(line?.lineRole || '')
}

function checkoutSnapshotReceivableAmount(lines = []) {
  const saleLines = (Array.isArray(lines) ? lines : [])
    .filter((line) => checkoutLineRole(line) === 'sale')
  const salesCents = saleLines.reduce((total, line) => (
    total + localDraftLineTotalAmountCents(line)
  ), 0)
  const debtCents = saleLines.reduce((total, line) => (
    total + lineDebtAmountCents(line)
  ), 0)
  return centsToMoney(Math.max(0, salesCents - debtCents))
}

function checkoutSnapshotPaymentLines(lines = []) {
  return (Array.isArray(lines) ? lines : []).filter((line) => (
    ['payment', 'balance_payment'].includes(checkoutLineRole(line))
  ))
}

function checkoutSnapshotBusinessLines(lines = []) {
  return (Array.isArray(lines) ? lines : []).filter((line) => {
    const explicitRole = checkoutLineRole(line)
    return explicitRole
      ? ['sale', 'entitlement_service', 'card_operation'].includes(explicitRole)
      : ['sale', 'entitlement_service', 'card_operation'].includes(cartLineRole(line))
  })
}

function localDraftLineTotalAmountCents(line = {}, amount = getLineAmount(line)) {
  const cents = moneyToCents(amount)
  // 服务端草稿的 amount/originalAmount 已经是该行合计；只有尚未保存的
  // local-* 行保留单价。服务设置等本地编辑不能把已落库行数量再乘一次。
  return isLocalCashierDraftLine(line)
    ? cents * Math.max(1, Number(line.quantity || 1))
    : cents
}

function recalculateLocalCashierDraft(draft) {
  const lines = (Array.isArray(draft.lines) ? draft.lines : []).map((line) => {
    const localSettings = localLineServiceSettings.value[String(line?.id || '')] || {}
    const localPersonnel = localPersonnelAssignments.value[String(line?.id || '')] || {}
    const next = { ...line }
    if (localSettings.serviceObject) {
      next.serviceObject = localSettings.serviceObject
      next.friendCountsAsCustomer = localSettings.serviceObject === 'friend'
        ? localSettings.friendCountsAsCustomer !== false
        : true
    }
    for (const field of ['isExperience', 'isPresale', 'inventoryOutboundRequired']) {
      if (typeof localSettings[field] === 'boolean') next[field] = localSettings[field]
    }
    if (Array.isArray(localPersonnel.craftsmen)) next.craftsmen = clonePlain(localPersonnel.craftsmen)
    if (Array.isArray(localPersonnel.salespeople)) next.salespeople = clonePlain(localPersonnel.salespeople)
    if (Array.isArray(localPersonnel.guideSelections)) next.guideSelections = clonePlain(localPersonnel.guideSelections)
    if (Array.isArray(localPersonnel.salesManagerSelections)) next.salesManagerSelections = clonePlain(localPersonnel.salesManagerSelections)
    return next
  })
  // Existing entitlement services remain in the cart and checkout preview, but
  // only new sales contribute to the amount payable in this checkout.
  const saleLines = lines.filter((line) => cartLineRole(line) === 'sale')
  const originalAmountCents = saleLines.reduce((total, line) => (
    total + localDraftLineTotalAmountCents(line, line.originalAmount ?? getLineAmount(line))
  ), 0)
  const saleAmountCents = saleLines.reduce((total, line) => (
    total + localDraftLineTotalAmountCents(line)
  ), 0)
  const debtAmountCents = saleLines.reduce((total, line) => (
    total + lineDebtAmountCents(line)
  ), 0)
  const receivableAmountCents = Math.max(0, saleAmountCents - debtAmountCents)
  draft.summary = {
    ...(draft.summary || {}),
    selectedCount: lines.reduce((total, line) => total + Math.max(1, Number(line.quantity || 1)), 0),
    originalAmount: centsToMoney(originalAmountCents),
    discountAmount: centsToMoney(Math.max(0, originalAmountCents - saleAmountCents)),
    receivableAmount: centsToMoney(receivableAmountCents),
    orderNote: String(draft.orderNote || ''),
    hasOrderNote: Boolean(String(draft.orderNote || '').trim())
  }
  // 本地草稿每次变更行集合后都要重建结账组成。权益选择器是只读根投影，
  // 不能把打开前的“纯购买”或“纯权益”动作遗留到当前混装购物车。
  const hasSale = saleLines.length > 0
  const hasEntitlement = lines.some((line) => cartLineRole(line) === 'entitlement_service')
  const primaryAction = hasSale && hasEntitlement
    ? 'collect_and_complete'
    : hasEntitlement
      ? 'complete_service'
      : hasSale
        ? 'collect_payment'
        : ''
  const primaryActionLabel = {
    collect_payment: '确认收款',
    complete_service: '确认完成服务',
    collect_and_complete: '收款并完成服务'
  }[primaryAction] || ''
  draft.primaryAction = primaryAction
  draft.primaryActionLabel = primaryActionLabel
  draft.checkoutComposition = {
    ...(draft.checkoutComposition || {}),
    lineRoles: [hasSale && 'sale', hasEntitlement && 'entitlement_service'].filter(Boolean),
    hasSale,
    hasEntitlement,
    primaryAction,
    primaryActionLabel,
    steps: primaryAction
      ? [
          { key: 'order', number: 1, label: hasEntitlement ? '确认本次内容' : '确认订单' },
          ...(hasSale ? [{ key: 'payment', number: 2, label: '收款信息' }] : []),
          { key: 'final', number: 3, label: primaryActionLabel },
          { key: 'result', number: 4, label: '处理结果' }
        ]
      : []
  }
  return draft
}

function canonicalCheckoutPositiveId(...values) {
  for (const value of values) {
    const numeric = Number(value)
    if (Number.isSafeInteger(numeric) && numeric > 0) return numeric
  }
  // Some legacy local rows use a prefixed DOM identity (for example
  // `staff-915`) while still carrying the numeric employee identity in the
  // same row. Accept the trailing numeric token only at the browser snapshot
  // boundary; the server continues to receive a strict positive integer.
  for (const value of values) {
    const match = String(value ?? '').match(/(\d+)$/)
    if (!match) continue
    const numeric = Number(match[1])
    if (Number.isSafeInteger(numeric) && numeric > 0) return numeric
  }
  return 0
}

// The browser may keep compact personnel rows for editing and display. A
// sale-project checkout snapshot has a stricter immutable shape, so expand
// those rows only at the final snapshot boundary. No server lookup or current
// workspace projection is used here; employee/store identity comes from the
// selection payload, with the active store as the final local fallback.
function canonicalCheckoutCraftsmen(records = []) {
  const storeId = Number(state.currentStore?.id || 0)
  const input = Array.isArray(records) ? records : []
  const rows = input.map((record, index) => {
    const staffId = canonicalCheckoutPositiveId(
      record?.staffId,
      record?.staff_id,
      record?.systemStoreStaffId,
      record?.id,
      record?.employeeId,
      record?.employee_id
    )
    const employeeId = canonicalCheckoutPositiveId(
      record?.employeeId,
      record?.employee_id,
      staffId
    )
    const personnelSource = record?.personnelSource === 'other' ? 'other' : 'store'
    // Organization craftsmen use a reserved virtual staff key only for the
    // staff-resource/profile lookup. The immutable checkout snapshot and all
    // performance facts must carry the real employee identity.
    const normalizedEmployeeId = personnelSource === 'other' && staffId > 1000000000
      ? staffId - 1000000000
      : employeeId
    const performanceType = String(record?.craftsmanPerformanceType || record?.craftsman_performance_type || '')
    const row = {
      id: staffId,
      staffId,
      employeeId: normalizedEmployeeId,
      storeId: Number(record?.storeId || record?.store_id || storeId),
      name: String(record?.name || record?.staffName || record?.employeeName || '').trim(),
      isPrimary: index === 0,
      sequence: index + 1,
      laborWeight: Math.max(0, Math.trunc(Number(record?.laborWeight ?? record?.performance ?? 0))),
      performanceAmountCents: Math.max(0, Math.trunc(Number(record?.performanceAmountCents ?? record?.performance_amount_cents ?? 0))),
      performanceAmountManual: Boolean(record?.performanceAmountManual ?? record?.performance_amount_manual),
      isPointCustomer: Boolean(record?.isPointCustomer ?? record?.marked)
    }
    if (personnelSource === 'other') row.personnelSource = 'other'
    row.craftsmanPerformanceType = ['commission', 'labor', 'commission_labor'].includes(performanceType)
      ? performanceType
      : 'commission_labor'
    row.laborFeeCents = Math.max(0, Math.trunc(Number(record?.laborFeeCents ?? record?.labor_fee_cents ?? 0)))
    const positionId = Number(record?.positionId ?? record?.position_id ?? 0)
    const explicitGroupKey = String(record?.allocationGroupKey || '').trim()
    const isIndependent = (record?.performanceIndependent === true
      || Number(record?.performanceIndependent ?? record?.performance_independent ?? 0) === 1
      || explicitGroupKey.startsWith('independent:'))
    if (positionId > 0) {
      row.positionId = positionId
      row.positionName = String(record?.positionName ?? record?.position_name ?? record?.position ?? '').trim()
    }
    if (isIndependent) {
      row.performanceIndependent = true
      row.allocationGroupKey = explicitGroupKey.startsWith('independent:')
        ? explicitGroupKey
        : `independent:${positionId > 0 ? positionId : staffId}`
    }
    if (Object.prototype.hasOwnProperty.call(record || {}, 'projectCountHalfUnits')
      || Object.prototype.hasOwnProperty.call(record || {}, 'project_count_half_units')) {
      row.projectCountHalfUnits = Math.max(0, Math.trunc(Number(record?.projectCountHalfUnits ?? record?.project_count_half_units ?? 0)))
    }
    return row
  })
  return rows
}

// Entitlement rows persist only the compact service intent.  The sale-line
// personnel snapshot carries display/profile fields, but the entitlement
// authority contract intentionally accepts the five business fields below
// (plus the optional personnelSource marker for organization craftsmen).
function canonicalCheckoutEntitlementCraftsmen(records = []) {
  return canonicalCheckoutCraftsmen(records).map((row) => ({
    staffId: row.staffId,
    laborWeight: row.laborWeight,
    performanceAmountCents: row.performanceAmountCents,
    performanceAmountManual: row.performanceAmountManual,
    isPointCustomer: row.isPointCustomer,
    craftsmanPerformanceType: row.craftsmanPerformanceType,
    laborFeeCents: row.laborFeeCents,
    ...(row.positionId > 0 || row.performanceIndependent
      ? {
          ...(row.positionId > 0 ? { positionId: row.positionId } : {}),
          positionName: row.positionName,
          ...(row.performanceIndependent
            ? { performanceIndependent: true, allocationGroupKey: row.allocationGroupKey }
            : {})
        }
      : {}),
    ...(Object.prototype.hasOwnProperty.call(row, 'projectCountHalfUnits')
      ? { projectCountHalfUnits: row.projectCountHalfUnits }
      : {}),
    ...(row.personnelSource === 'other' ? { personnelSource: 'other' } : {})
  }))
}

function canonicalCheckoutSalespeople(records = []) {
  return (Array.isArray(records) ? records : []).map((record) => ({
    staffId: canonicalCheckoutPositiveId(record?.staffId, record?.staff_id, record?.systemStoreStaffId, record?.id, record?.employeeId, record?.employee_id),
    allocationWeight: Math.max(0, Math.trunc(Number(record?.allocationWeight ?? record?.allocation_weight ?? record?.performance ?? 0))),
    performanceAmountCents: Math.max(0, Math.trunc(Number(record?.performanceAmountCents ?? record?.performance_amount_cents ?? 0))),
    performanceAmountManual: Boolean(record?.performanceAmountManual ?? record?.performance_amount_manual),
    // 售前标记属于销售人快照的一部分；否则多选售前虽然能保存分配比例，
    // 最终销售订单和业绩事实会把它们误记成普通售后销售人。
    isPreSale: Boolean(record?.isPreSale ?? record?.is_presale ?? record?.marked),
    ...(Number(record?.positionId ?? record?.position_id ?? 0) > 0
      ? {
          positionId: Number(record.positionId ?? record.position_id),
          positionName: String(record?.positionName ?? record?.position_name ?? record?.position ?? '').trim(),
          ...(record?.performanceIndependent === true || Number(record?.performanceIndependent ?? record?.performance_independent ?? 0) === 1
            ? {
                performanceIndependent: true,
                allocationGroupKey: String(record?.allocationGroupKey || '').trim() || `independent:${Number(record.positionId ?? record.position_id)}`
              }
            : {})
        }
      : {})
  }))
}

function canonicalCheckoutAttributions(records = [], includeRound = false) {
  return (Array.isArray(records) ? records : []).map((record) => ({
    employeeId: canonicalCheckoutPositiveId(record?.employeeId, record?.employee_id, record?.staffId, record?.staff_id, record?.systemStoreStaffId, record?.id),
    name: String(record?.name || record?.employeeName || record?.employee_name || '').trim(),
    ...(includeRound ? {
      guideRoundNo: Math.max(0, Math.trunc(Number(record?.guideRoundNo ?? record?.guide_round_no ?? 0)))
    } : {})
  }))
}

function commitLocalCashierDraft(draft) {
  const next = recalculateLocalCashierDraft(draft)
  cashierDraftSnapshot.value = Object.freeze({
    scopeKey: currentCashierDraftScopeKey.value,
    snapshot: Object.freeze(clonePlain(next))
  })
}

function appendLocalCashierDraftOperation(operation, mutate) {
  const draft = clonePlain(localCashierDraft.value || localDraftBase())
  mutate(draft)
  commitLocalCashierDraft(draft)
  localCashierDraftOperations.value = [...localCashierDraftOperations.value, clonePlain(operation)]
  if (isEntitlementSelectorOpen.value) refreshEntitlementSelectorDraftCheckpoint()
}

function captureLocalCashierDraftForEntitlementSelector() {
  const draft = localCashierDraft.value
  if (!isRecord(draft) || !Array.isArray(draft.lines)) return null
  return {
    scopeKey: currentCashierDraftScopeKey.value,
    snapshot: clonePlain(draft),
    operations: clonePlain(localCashierDraftOperations.value)
  }
}

function refreshEntitlementSelectorDraftCheckpoint() {
  entitlementSelectorDraftCheckpoint.value = captureLocalCashierDraftForEntitlementSelector()
}

function restoreLocalCashierDraftAfterEntitlementSelector(checkpoint = null) {
  if (!checkpoint || checkpoint.scopeKey !== currentCashierDraftScopeKey.value) return false
  // Opening the selector is read-only. Its response must never replace rows
  // the cashier has already appended in the browser, even when a delayed root
  // projection reaches the page at the same time.
  cashierDraftSnapshot.value = Object.freeze({
    scopeKey: checkpoint.scopeKey,
    snapshot: Object.freeze(clonePlain(checkpoint.snapshot))
  })
  localCashierDraftOperations.value = clonePlain(checkpoint.operations)
  return true
}

function localDraftResult(message = '') {
  return { result: { status: 'succeeded', message } }
}

function isLocalCashierDraftLine(line = {}) {
  return String(line?.id || '').startsWith('local-')
}

// 纯本地草稿的行都能由 operations 重新建立。兼容旧工作台或旧挂单时，
// 草稿里可能已经有服务端行；只保存 operations 会在提单后遗漏这些行，
// 因此该兼容场景必须先物化为完整的服务端草稿再按原挂单流程保存。
function localDraftContainsPersistedCartLines() {
  const lines = localCashierDraft.value?.lines
  return Array.isArray(lines) && lines.some((line) => !isLocalCashierDraftLine(line))
}

function applyLocalCashierDraftMutation(action, line, payload = {}) {
  const lineId = String(line?.id || '')
  if (!lineId) return { result: { status: 'failed', code: 'CASHIER_DRAFT_LINE_MISSING', message: '当前商品不存在，请重新选择。' } }
  appendLocalCashierDraftOperation({ action, lineId, payload: clonePlain(payload) }, (draft) => {
    const target = (draft.lines || []).find((item) => String(item?.id || '') === lineId)
    if (!target) return
    if (action === 'remove-cart-line') {
      draft.lines = draft.lines.filter((item) => String(item?.id || '') !== lineId)
      return
    }
    if (action === 'change-cart-line-quantity') {
      target.quantity = Math.max(1, Number(target.quantity || 1) + Number(payload.delta || 0))
      if (isEntitlementLine(target) && isLocalCashierDraftLine(target)) {
        // The local row stores the amount for its whole selected quantity.
        // Rebuild it after a quantity edit so the final checkout snapshot
        // stays aligned with the server's per-occurrence allocation.
        const otherLines = (draft.lines || []).filter((item) => String(item?.id || '') !== lineId)
        target.actualAmount = localEntitlementAmount(otherLines, target)
        target.amount = target.actualAmount
        target.finalAmount = target.actualAmount
        target.originalAmount = target.actualAmount
      }
      return
    }
    if (action === 'update-cashier-line-debt') {
      target.debtAmountCents = Math.max(0, Number(payload.debtAmountCents || 0))
      return
    }
    if (action === 'update-cart-line-service-settings') {
      Object.assign(target, clonePlain(payload))
      return
    }
    if (action === 'update-card-operation-target-entitlement-quantity') {
      const nextQuantity = Math.max(1, Math.floor(Number(payload.targetEntitlementQuantity || 1)))
      if (isRecord(target.cardOperationUpgrade)
        && String(target.cardOperationUpgrade.operationType || '') === 'project_upgrade') {
        target.cardOperationUpgrade.targetEntitlementQuantity = nextQuantity
      }
      if (isRecord(target.localCardOperation)
        && String(target.localCardOperation.operationType || '') === 'project_upgrade') {
        target.localCardOperation.targetEntitlementQuantity = nextQuantity
      }
      return
    }
    if (action === 'apply-line-coupon') {
      target.couponId = String(payload.couponId || '')
      target.couponSummary = String(payload.couponSummary || '已选优惠券')
      const baseAmount = moneyToCents(target.couponBaseAmount ?? target.finalAmount ?? target.amount)
      target.couponBaseAmount = centsToMoney(baseAmount)
      target.couponDiscountCents = Math.min(baseAmount, Math.max(0, Number(payload.discountAmountCents || 0)))
      target.finalAmount = centsToMoney(baseAmount - target.couponDiscountCents)
      target.amount = target.finalAmount
      return
    }
    if (action === 'remove-line-coupon') {
      delete target.couponId
      delete target.couponSummary
      delete target.couponDiscountCents
      if (target.couponBaseAmount !== undefined) {
        target.finalAmount = target.couponBaseAmount
        target.amount = target.finalAmount
        delete target.couponBaseAmount
      }
    }
  })
  return localDraftResult()
}

function applyLocalCashierRootMutation(action, payload = {}) {
  appendLocalCashierDraftOperation({ action, payload: clonePlain(payload) }, (draft) => {
    if (action === 'clear-cart-lines') {
      draft.lines = []
      return
    }
    if (action === 'update-cashier-order-note') {
      draft.orderNote = String(payload.orderNote || '')
      return
    }
    if (action === 'update-cashier-supplement') {
      draft.supplement = clonePlain(payload)
      return
    }
    if (action === 'update-cashier-line-price') {
      const target = (draft.lines || []).find((line) => String(line?.id || '') === String(payload.lineId || ''))
      if (target) {
        target.originalAmount = Number(target.originalAmount ?? (getLineAmount(target) || 0))
        target.finalAmount = Number(payload.lineAmountCents || 0) / 100
        target.amount = target.finalAmount
        for (const line of draft.lines || []) line.debtAmountCents = 0
      }
    }
  })
  return localDraftResult()
}

function consumeSynchronizedLocalCashierOperation() {
  localCashierDraftOperations.value = localCashierDraftOperations.value.slice(1)
}

function persistedCashierDraftLineIds() {
  return new Set((cashier.value.cart?.lines || []).map((line) => String(line?.id || '')))
}

function cashierDraftLines(draft = {}) {
  if (Array.isArray(draft?.cart?.lines)) return draft.cart.lines
  return Array.isArray(draft?.lines) ? draft.lines : []
}

function restoreLocalCashierDraftAfterSyncFailure(draft) {
  const restored = clonePlain(draft)
  const persistedIds = localCashierPersistedLineIds.value
  for (const line of restored.lines || []) {
    const persistedId = persistedIds[String(line?.id || '')]
    if (persistedId) line.id = persistedId
  }
  commitLocalCashierDraft(restored)
}

function localCashierDraftSyncFailureAt() {
  if (!import.meta.env.DEV || typeof window === 'undefined') return 0
  if (!new Set(['127.0.0.1', 'localhost', '::1']).has(window.location.hostname)) return 0
  const query = new URLSearchParams(window.location.search)
  if (query.get('test') !== 'local-draft-sync-failure') return 0
  const operationIndex = Number(query.get('syncFailAt') || '0')
  return Number.isInteger(operationIndex) && operationIndex > 0 ? operationIndex : 0
}

function localCashierDraftSyncFailure(operationIndex, action) {
  if (localCashierDraftSyncFailureConsumed
    || localCashierDraftSyncFailureAt() !== operationIndex) return null
  // The fixture must exercise exactly one interrupted attempt. Remaining
  // queued operations need to reach the real hang/checkout flow on retry.
  localCashierDraftSyncFailureConsumed = true
  return {
    result: {
      status: 'failed',
      code: 'CASHIER_LOCAL_DRAFT_SYNC_TEST_FAILURE',
      message: `本地验收已在第${operationIndex}条草稿操作前中断（${action}）。`
    }
  }
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
  if (!isRecord(payment) || !Array.isArray(payment.methods)) return false
  if (!payment.methods.every((method) => isRecord(method) && method.id && method.name)) return false
  const paymentLines = checkoutSnapshotPaymentLines(checkoutSnapshot.lines)
  return paymentLines
    .filter((line) => checkoutLineRole(line) === 'payment')
    .every(isAuthoritativeCheckoutPaymentLine)
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


function currentCheckoutCommandContexts(session) {
  if (!session || session.stateContextId !== String(state.stateContextId || '')) return null
  // Debt repayment is edited entirely in the browser-owned snapshot after
  // the preparation handoff. The root projection still contains the old
  // payment rows, so resolving command contexts from it makes a valid local
  // snapshot look like an expired checkout session at final confirmation.
  const snapshot = session.snapshot?.businessType === 'debt_repayment'
    ? session.snapshot
    : checkout.value
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

// 结账向导没有服务端恢复入口。刷新或返回后必须从当前收银页面重新
// 点击“立即结账”，届时才复制一份新的浏览器快照。
watch(
  () => [
    checkout.value.checkoutRequestId || checkout.value.requestId || '',
    checkout.value.revision ?? checkout.value.recordVersion,
    checkout.value.status
  ],
  ([requestId, revision, status], previous = []) => {
    const [previousRequestId, previousRevision] = previous
    // A direct snapshot checkout returns its terminal receipt and clears the
    // workbench in the same response. The empty root projection can arrive
    // immediately afterwards with a different old checkout identity; it must
    // not replace the just-settled order number in the still-open result view.
    // The explicit return/new-checkout handlers own clearing this local receipt.
    const hasLocalTerminalReceipt = checkoutLocalOutcome.value.status === 'succeeded'
      && String(checkoutLocalOutcome.value.checkoutRequestId || '') !== ''
    if (hasLocalTerminalReceipt) return
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
  const cardOperationLines = cartLines.value.filter((line) => cartLineRole(line) === 'card_operation')
  const groups = []
  // 分组只信任后端 lineRole：卡内权益在上，本次付款购买在下，不能从商品名称推断。
  if (entitlementLines.length) groups.push({ key: 'current-entitlement-service', label: '卡内项目', lines: entitlementLines })
  if (cardOperationLines.length) groups.push({ key: 'current-card-operation', label: '卡操作', lines: cardOperationLines })
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
  'submit-checkout',
  'submit-recharge-debt-repayment',
  'retry-checkout',
  'query-checkout-result',
  'finish-checkout-and-return'
])

const checkoutExternalActions = new Set([
  'prepare-service-completion',
  'view-sales-order',
  'print-sales-order-receipt'
])

function checkoutSourceSelectorError(error) {
  return error instanceof Error ? error.message : '业务来源加载失败，请稍后重试。'
}

async function loadInlineCheckoutBusinessSources() {
  const current = checkoutOverlayState.value
  const loadToken = ++checkoutBusinessSourcesLoadToken
  if (!isCheckoutOpen.value || current.sourceEnabled !== true || current.sourceSelectable === false) {
    checkoutInlineBusinessSources.value = []
    checkoutBusinessSourcesLoadError.value = ''
    isLoadingCheckoutBusinessSources.value = false
    return
  }
  // Inline source catalog is a read-only directory. A local checkout preview
  // has no checkout request identity; its source selection is still valid and
  // must remain browser-owned until final snapshot submission.
  const requestId = localCheckoutPreview.value?.localDraftPreview === true
    ? 'local-checkout-preview'
    : String(checkoutRequestIdentity(current) || '')
  const stateContextId = String(state.stateContextId || '')
  checkoutBusinessSourcesLoadError.value = ''
  isLoadingCheckoutBusinessSources.value = true
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (loadToken !== checkoutBusinessSourcesLoadToken
      || !isCheckoutOpen.value
      || stateContextId !== String(state.stateContextId || '')
      || (localCheckoutPreview.value?.localDraftPreview !== true
        && requestId !== String(checkoutRequestIdentity(checkout.value) || ''))) return
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
    checkoutOverlayState.value.sourceEnabled,
    checkoutOverlayState.value.sourceSelectable
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

async function openCheckoutBusinessSourceSelector(kind) {
  const current = kind === 'recharge' ? rechargeCheckout.value : checkout.value
  const isLocalSaleEdit = kind !== 'recharge'
    && (localCheckoutPreview.value?.localDraftPreview === true
      || !isCheckoutOpen.value
      || !checkoutRequestIsPersisted(current))
  if (kind === 'recharge' && !current) {
    return { result: { status: 'failed', code: 'BUSINESS_SOURCE_NOT_SELECTABLE', message: '本次补交继承原订单来源，不能修改。' } }
  }
  if (!isLocalSaleEdit && current.sourceSelectable === false) {
    return { result: { status: 'failed', code: 'BUSINESS_SOURCE_NOT_SELECTABLE', message: '本次补交继承原订单来源，不能修改。' } }
  }
  const localSource = localCheckoutBusinessSource.value
  checkoutBusinessSourceSelector.value = {
    kind,
    sources: [],
    primarySourceId: Number((isLocalSaleEdit ? localSource.primarySourceId : current.primarySourceId) || 0),
    secondarySourceId: Number((isLocalSaleEdit ? localSource.secondarySourceId : current.secondarySourceId) || 0),
    rewardAmountCents: Number((isLocalSaleEdit ? localSource.rewardAmountCents : current.rewardAmountCents) || 0),
    loadError: ''
  }
  // ref 会将对象转成 Proxy；后续身份判断必须使用 ref 内的同一代理对象，
  // 否则成功加载的业务来源会被误判为选择器已经关闭。
  const selector = checkoutBusinessSourceSelector.value
  try {
    const catalog = await loadCheckoutBusinessCatalog()
    if (checkoutBusinessSourceSelector.value !== selector) return { result: { status: 'failed', code: 'BUSINESS_SOURCE_SELECTOR_CLOSED', message: '业务来源选择已关闭。' } }
    selector.sources = catalog.sources
    // Keep the catalog in memory for the browser snapshot and toolbar label;
    // this is a read-only directory cache, not a server-side checkout draft.
    checkoutInlineBusinessSources.value = Array.isArray(catalog.sources) ? catalog.sources : []
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
  if (!isSavingCheckoutBusinessSource.value) {
    const kind = checkoutBusinessSourceSelector.value?.kind || 'sale'
    checkoutBusinessSourceSelector.value = null
    if (kind === 'sale') {
      window.dispatchEvent(new CustomEvent('cashier-v3:checkout-business-source-cancelled', {
        detail: { kind }
      }))
    }
    window.dispatchEvent(new CustomEvent('cashier-v3:checkout-business-source-closed'))
  }
}

async function persistCheckoutBusinessSource(kind, selection = {}) {
  if (isSavingCheckoutBusinessSource.value) return null
  const current = kind === 'recharge' ? rechargeCheckout.value : checkout.value
  const primarySourceId = Number(selection.primarySourceId || 0)
  const secondarySourceId = Number(selection.secondarySourceId || 0)
  const rewardAmountCents = Number(selection.rewardAmountCents ?? current?.rewardAmountCents ?? 0)
  if ((kind === 'recharge' && !current) || primarySourceId <= 0 || secondarySourceId < 0) return null
  // 普通销售的来源始终是工具栏前端值；结账预览和最终快照都在浏览器内
  // 读取该值，不能因结账步骤变化而提前写入服务端 checkout_request。
  if (kind !== 'recharge') {
    localCheckoutBusinessSource.value = {
      primarySourceId,
      secondarySourceId,
      rewardAmountCents: Math.max(0, rewardAmountCents)
    }
    if (localCheckoutPreview.value?.localDraftPreview === true) {
      localCheckoutPreview.value.primarySourceId = primarySourceId
      localCheckoutPreview.value.secondarySourceId = secondarySourceId
      localCheckoutPreview.value.rewardAmountCents = Math.max(0, rewardAmountCents)
    }
    publishToolbarCheckoutContext()
    return localDraftResult('客户来源已回填到本地结账快照。')
  }
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
    }
    return result
  } finally {
    isSavingCheckoutBusinessSource.value = false
  }
}

async function saveCheckoutBusinessSource(selection = {}) {
  const selector = checkoutBusinessSourceSelector.value
  if (!selector) return
  // Before the sale checkout overlay opens, the toolbar source is only a
  // browser-side field. Do not route this interaction through any stale
  // checkout projection that may still be present in the root state.
  if (selector.kind !== 'recharge' && !isCheckoutOpen.value) {
    const primarySourceId = Number(selection.primarySourceId || 0)
    if (primarySourceId <= 0) return
    localCheckoutBusinessSource.value = {
      primarySourceId,
      secondarySourceId: Number(selection.secondarySourceId || 0),
      rewardAmountCents: Math.max(0, Number(selection.rewardAmountCents || 0))
    }
    publishToolbarCheckoutContext()
    checkoutBusinessSourceSelector.value = null
    window.dispatchEvent(new CustomEvent('cashier-v3:checkout-business-source-confirmed'))
    return
  }
  const result = await persistCheckoutBusinessSource(selector.kind, selection)
  if (['success', 'succeeded'].includes(resultStatus(result))) {
    checkoutBusinessSourceSelector.value = null
    window.dispatchEvent(new CustomEvent('cashier-v3:checkout-business-source-confirmed'))
  }
}

function saveInlineCheckoutBusinessSource(selection = {}) {
  return persistCheckoutBusinessSource('sale', selection)
}

async function saveCheckoutSalesDate(selection = {}) {
  if (isSavingCheckoutSalesDate.value) return null
  const businessDate = String(selection.businessDate || '').trim()
  const reason = String(selection.reason || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) return null
  // 业务日期与来源相同，只是工具栏当前值；点击立即结账时由
  // localCheckoutPreviewSnapshot 一次性读取，普通销售不维护服务端草稿。
  localCheckoutBusinessDate.value = businessDate
  localCheckoutBusinessDateReason.value = reason
  if (localCheckoutPreview.value?.localDraftPreview === true) {
    localCheckoutPreview.value.businessDate = businessDate
    localCheckoutPreview.value.businessDateReason = reason
  }
  publishToolbarCheckoutContext()
  return localDraftResult('业务日期已回填到工具栏结账上下文。')
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
  return isEntitlementLine(line)
    || (Number(line.productType) === 6 && !isCustomCardPurchase(line))
    || (String(line.kind || '') === '项目' && !isCustomCardPurchase(line))
}

function cartLineRole(line = {}) {
  const role = String(line.lineRole || '').trim().toLowerCase()
  if (role === 'sale') return 'sale'
  if (['entitlement_service', 'entitlement', 'benefit_service'].includes(role)) return 'entitlement_service'
  if (role === 'card_operation') return 'card_operation'
  // Older V3 projections did not always include lineRole. A benefit-pool
  // source identifies a card entitlement unambiguously and must retain the
  // same service prerequisites as a current entitlement_service row.
  if (
    String(line.entitlementSourceDetailId || line.memberBenefitPoolId || '').trim()
    && String(line.entitlementInstanceId || line.cardHolderId || '').trim()
    && (
      String(line.amountRole || '') === 'entitlement_actual'
      || String(line.serviceSource || '') === '卡内项目'
      || String(line.serviceRole || '') === '使用权益'
    )
  ) return 'entitlement_service'
  return 'unknown'
}

function isAuthoritativeCartLine(line = {}) {
  const role = cartLineRole(line)
  if (!line.id || !line.name || role === 'unknown' || !Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1) return false
  if (role === 'sale') return true
  return Boolean(entitlementCardHolderId(line) && entitlementBenefitPoolId(line))
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
  return line.finalAmount ?? line.actualAmount ?? line.amount ?? 0
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
    // The refreshed workbench is read-only. A stale checkout request is never
    // reopened automatically; the next checkout starts from the browser cart.
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

const guestAttributionBlockedMessage = '游客订单不能记录导购或销售经理，请先选择会员。'

function reportGuestAttributionBlocked() {
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: 'failed',
      message: guestAttributionBlockedMessage
    }
  }))
}

function lineHasCustomerAttribution(line = {}) {
  const guides = line.guideSelections ?? line.guide_selections
  const managers = line.salesManagerSelections ?? line.sales_manager_selections
  return (Array.isArray(guides) && guides.length > 0)
    || (Array.isArray(managers) && managers.length > 0)
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

function reportCheckoutEntryFailure(result, fallback = '结账资料保存失败，请重试。') {
  const status = resultStatus(result)
  if (!['failed', 'conflict'].includes(status)) return result
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status: status === 'conflict' ? 'conflict' : 'failed',
      code: result?.result?.code || result?.code || '',
      message: resultMessage(result, fallback)
    }
  }))
  return result
}

async function selectCatalogItem(item) {
  if (activeCardOperationUpgrade.value) {
    reportEntitlementContractError({ message: '当前只能完成本次升级结账；如需重新选择，请先清空购物车。' })
    return
  }
  if (item.id === 'custom-card-entry') {
    if (hasCartLines.value) isCustomCardConflictOpen.value = true
    else if (currentCustomerMode.value === 'guest') {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
        detail: { context: 'cashier', selectorContext: 'cashier' }
      }))
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
    const target = clonePlain(item)
    if (operation.mode === 'project-replacement') {
      target.targetQuantity = cardOperationReplacementTargetQuantity(operation)
    } else if (operation.mode === 'project-upgrade') {
      target.targetEntitlementQuantity = 1
    }
    previewCardOperation.value = { ...operation, target, awaitingTarget: false }
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
  if (item.kind === '卡项' && currentCustomerMode.value === 'guest') {
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'cashier', selectorContext: 'cashier' }
    }))
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
  const queued = catalogItemAppendQueue.then(() => {
    if (!itemId || !catalogKind) {
      return { result: { status: 'failed', code: 'CASHIER_CATALOG_ITEM_INVALID', message: '商品信息不完整，请重新选择。' } }
    }
    const localLineId = `local-sale-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const amount = Number(item.price || 0)
    const line = {
      id: localLineId,
      lineRole: 'sale',
      catalogItemId: itemId,
      productId: itemId,
      itemId,
      name: String(item.name || ''),
      kind: catalogKind,
      productType: Number(item.productType || (catalogKind === '项目' ? 6 : 0)),
      quantity: 1,
      amount,
      finalAmount: amount,
      originalAmount: amount,
      debtAmountCents: 0,
      cardPurchaseSnapshot: clonePlain(item.cardPurchaseSnapshot || {}),
      localCatalogItem: { itemId, catalogKind }
    }
    appendLocalCashierDraftOperation({ action: 'choose-catalog-item', localLineId, itemId, catalogKind }, (draft) => {
      draft.lines.push(line)
    })
    return { result: { status: 'succeeded', message: '商品已加入本次购物车。' } }
  })
  catalogItemAppendQueue = queued.catch(() => null)
  return queued
}

function renderedCashierDraftMatches(draft) {
  if (!isRecord(draft) || !Array.isArray(draft.lines)) return false
  if (String(draft.workspaceId || '') !== String(state.workspace?.id || '')) return false
  if (String(draft.stateContextId || '') !== String(state.stateContextId || '')) return false
  if (String(draft.customerMode || '') !== currentCustomerMode.value) return false
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

function adoptLatestCashierWorkspaceRevision() {
  const workspaceId = String(state.workspace?.id || '')
  const revision = Number(getCashierV3PublicVersion('cashier_workspace', workspaceId))
  if (!workspaceId || !Number.isInteger(revision) || revision <= 0) return false
  if (Number(state.workspace?.revision || 0) === revision) return true
  state.workspace = { ...state.workspace, revision }
  return true
}

// A deferred local-draft replay deliberately keeps the cart projection in the
// browser until the batch finishes, but command contexts must still advance
// after every successful server mutation. Otherwise the next queued command
// reuses the previous workspace revision and is rejected as stale.
function adoptCashierWorkspaceRevisionFromResult(result = {}) {
  const response = responseDataBlock(result)
  const versions = Array.isArray(response?.versions)
    ? response.versions
    : (Array.isArray(result?.versions) ? result.versions : [])
  const workspaceId = String(state.workspace?.id || '')
  const row = versions.find((version) => (
    version?.kind === 'cashier_workspace'
    && String(version?.id || '') === workspaceId
    && Number.isInteger(Number(version?.version))
    && Number(version.version) > 0
  ))
  if (!row || !workspaceId) return adoptLatestCashierWorkspaceRevision()
  state.workspace = { ...state.workspace, revision: Number(row.version) }
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
  if (!sourceCardHolderId) {
    reportEntitlementContractError({ message: '原卡数据不完整，请重新打开使用权益后再办理。' })
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

function cardOperationSourceMaximum(source = {}) {
  const availableTimes = Number(source.availableTimes ?? source.remainingTimes ?? 0)
  return Number.isInteger(availableTimes) && availableTimes > 0 ? availableTimes : 0
}

function cardOperationReplacementTargetQuantity(operation = {}) {
  // A replacement is a one-for-one target right by default. The operator can
  // explicitly increase it in the target stepper when the business requires it.
  return 1
}

function changeCardOperationSourceQuantity(source, delta) {
  const operation = previewCardOperation.value
  const detailId = String(entitlementBenefitPoolId(source) || '').trim()
  const maximum = cardOperationSourceMaximum(source)
  if (!operation || !detailId || maximum <= 0) return
  const current = Math.max(1, Math.floor(Number(source.quantity || 1)))
  const next = Math.min(maximum, Math.max(1, current + Number(delta || 0)))
  if (next === current) return
  previewCardOperation.value = {
    ...operation,
    sources: operation.sources.map((candidate) => (
      String(entitlementBenefitPoolId(candidate) || '').trim() === detailId
        ? { ...candidate, quantity: next }
        : candidate
    ))
  }
}

function setCardOperationSourceQuantity(source, event) {
  const maximum = cardOperationSourceMaximum(source)
  const current = Math.max(1, Math.floor(Number(source.quantity || 1)))
  const requested = Math.max(1, Math.floor(Number(event?.target?.value) || 1))
  const next = maximum > 0 ? Math.min(maximum, requested) : current
  if (event?.target) event.target.value = String(next)
  changeCardOperationSourceQuantity(source, next - current)
}

function changeCardOperationTargetQuantity(delta) {
  const operation = previewCardOperation.value
  if (operation?.mode !== 'project-replacement' || !operation.target) return
  const current = Math.max(1, Math.floor(Number(operation.target.targetQuantity || 1)))
  const next = Math.max(1, current + Number(delta || 0))
  if (next === current) return
  previewCardOperation.value = {
    ...operation,
    target: { ...operation.target, targetQuantity: next }
  }
}

function setCardOperationTargetQuantity(event) {
  const operation = previewCardOperation.value
  const current = Math.max(1, Math.floor(Number(operation?.target?.targetQuantity || 1)))
  const next = Math.max(1, Math.floor(Number(event?.target?.value) || 1))
  if (event?.target) event.target.value = String(next)
  changeCardOperationTargetQuantity(next - current)
}

function changeCardOperationUpgradeEntitlementQuantity(line, delta) {
  const lineId = String(line?.id || '')
  if (!lineId || cardOperationUpgradeBinding(line)?.operationType !== 'project_upgrade') return
  const current = cardOperationUpgradeTargetEntitlementQuantity(cardOperationUpgradeBinding(line))
  const next = Math.max(1, current + Number(delta || 0))
  if (next === current) return
  applyLocalCashierDraftMutation('update-card-operation-target-entitlement-quantity', line, {
    targetEntitlementQuantity: next
  })
}

function setCardOperationUpgradeEntitlementQuantity(line, event) {
  const current = cardOperationUpgradeTargetEntitlementQuantity(cardOperationUpgradeBinding(line))
  const next = Math.max(1, Math.floor(Number(event?.target?.value) || 1))
  if (event?.target) event.target.value = String(next)
  changeCardOperationUpgradeEntitlementQuantity(line, next - current)
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
  return holderId ? { kind: 'card_holder', id: holderId } : null
}

// This is only the selected card identity. It deliberately carries no
// version/revision value; the final submit transaction reads the current row.
function cardOperationCommandContexts(operation = {}, source = {}, operationType = '') {
  const sourceContext = sourceCardOperationContext(source)
  if (!sourceContext) return null
  const contexts = entitlementContextMap(operation.selectorContexts)
  const sourceVersion = contexts?.get(`${sourceContext.kind}:${sourceContext.id}`)
  // 卡操作的正式命令只依赖来源卡；版本仍以权益选择器返回的权威上下文为准，
  // 不允许退回到展示行上的 revision 或自行猜测一个版本。
  return sourceVersion ? [sourceVersion] : [sourceContext]
}

function operationEndOfDayTimestamp(date = '') {
  const normalized = String(date || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(normalized)) return null
  const value = Date.parse(`${normalized}T23:59:59+08:00`)
  return Number.isFinite(value) ? Math.floor(value / 1000) : null
}

function cardOperationSourceBalance(source = {}, quantity = 1) {
  const remainingValue = Math.max(0, Number(source.remainingAmount ?? source.remainingValue ?? 0))
  const remainingTimes = Math.floor(Number(source.availableTimes ?? source.remainingTimes ?? 0))
  const selectedQuantity = Math.max(1, Math.floor(Number(quantity || 1)))
  if (!remainingValue || remainingTimes <= 0 || selectedQuantity >= remainingTimes) return remainingValue
  // The selector exposes the current remaining value. Reconstruct the
  // immutable unit value before allocating only the edited operation count;
  // otherwise an upgrade of two out of three rights would credit all three.
  return Math.round((remainingValue * selectedQuantity / remainingTimes) * 100) / 100
}

async function submitDirectCardOperation({ source, date = '', reason = '' } = {}) {
  const operation = previewCardOperation.value || {}
  const operationType = cardOperationTypeByMode[operation.mode]
  const sourceContext = sourceCardOperationContext(source)
  const normalizedReason = String(reason || '').trim()
  const reasonRequired = cardOperationReasonModes.has(operation.mode)
  // Project replacement is intentionally a live entitlement operation. It
  // sends only the page intent; the server locks and checks current rows in
  // its transaction. Upgrade and other card operations retain versioned
  // command contexts.
  const commandContexts = operationType === 'project_replacement'
    ? null
    : cardOperationCommandContexts(operation, source, operationType)
  if (!operationType || !sourceContext || (operationType !== 'project_replacement' && !commandContexts) || (reasonRequired && !normalizedReason)) {
    const message = !sourceContext
      ? '来源卡数据不完整，请重新选择。'
      : reasonRequired && !normalizedReason
        ? '请填写本次操作原因。'
        : '当前卡操作类型无效，请重新选择。'
    reportEntitlementContractError({ message })
    return { result: { status: 'failed', code: 'CARD_OPERATION_CONTEXT_INCOMPLETE', message } }
  }

  const targetSnapshot = {
    // The catalogue selector's stable identity is `id`. Keep it in the
    // checkout line snapshot; do not recover the target from a later
    // projection when the checkout is submitted.
    catalogId: Number(
      operation.target?.catalogItemId
      ?? operation.target?.productId
      ?? operation.target?.catalogProductId
      ?? operation.target?.id
      ?? 0
    ),
    skuId: Number(operation.target?.skuId ?? operation.target?.id ?? 0),
    name: String(
      operation.target?.name
      || operation.target?.storeName
      || operation.target?.store_name
      || operation.target?.productInfo?.store_name
      || ''
    ),
    priceCents: moneyToCents(Math.max(0, Number(operation.target?.price || operation.target?.amount || 0))),
    memberId: String(operation.target?.memberId ?? operation.target?.id ?? ''),
    ...(operationType === 'project_replacement'
      ? { targetQuantity: Math.max(1, Math.floor(Number(operation.target?.targetQuantity || 1))) }
      : {})
  }
  const replacementSnapshot = operationType === 'project_replacement'
    ? {
        member: {
          id: Number(currentMemberId.value || 0),
          name: String(member.value?.name || member.value?.realName || member.value?.nickname || '')
        },
        sourceCard: { id: Number(sourceContext.id || 0) },
        sourceLines: [],
        target: {
          projectId: Number(
            operation.target?.projectId
            ?? operation.target?.productId
            ?? operation.target?.catalogProductId
            ?? 0
          ),
          skuId: Number(
            operation.target?.skuId
            ?? operation.target?.catalogItemId
            ?? operation.target?.id
            ?? 0
          ),
          projectName: String(operation.target?.name || ''),
          quantity: Math.max(1, Math.floor(Number(operation.target?.targetQuantity || 1)))
        }
      }
    : null
  const payload = operationType === 'project_replacement'
    ? {
        operationType,
        idempotencyKey: createCashierV3CommandId('CARD_OPERATION'),
        replacementSnapshot
      }
    : {
        operationType,
        sourceCardHolderId: sourceContext.id,
        idempotencyKey: createCashierV3CommandId('CARD_OPERATION'),
        // This intent is retained locally until final checkout. Persist the
        // selection exactly as displayed so final settlement never re-prices it
        // from a later catalogue projection.
        targetSnapshot,
        ...(commandContexts ? { commandContexts } : {})
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
    if (operationType === 'project_replacement') {
      // Replacement carries the target identity only inside replacementSnapshot.
    } else {
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
      const quantity = Math.floor(Number(selected.quantity || 0))
      const maximum = cardOperationSourceMaximum(selected)
      if (!Number.isInteger(quantity) || quantity < 1 || maximum < quantity) {
        reportEntitlementContractError({ message: '项目操作次数已变化，请重新选择来源项目。' })
        return { result: { status: 'failed', code: 'CARD_OPERATION_SOURCE_QUANTITY_INVALID' } }
      }
      linesByDetail.set(detailId, (linesByDetail.get(detailId) || 0) + quantity)
    }
    if (operationType !== 'project_replacement') {
      payload.projectLines = Array.from(linesByDetail, ([sourceDetailId, quantity]) => ({ sourceDetailId, quantity }))
    }
    if (operationType === 'project_replacement') {
      const targetQuantity = Math.floor(Number(operation.target?.targetQuantity || 0))
      if (!Number.isInteger(targetQuantity) || targetQuantity < 1) {
        reportEntitlementContractError({ message: '目标项目次数无效，请重新选择。' })
        return { result: { status: 'failed', code: 'CARD_OPERATION_TARGET_QUANTITY_INVALID' } }
      }
      replacementSnapshot.sourceLines = (operation.sources || []).map((selected) => ({
        detailId: Number(entitlementBenefitPoolId(selected) || 0),
        projectId: Number(selected.projectId || selected.productId || selected.id || 0),
        projectName: String(selected.name || selected.projectName || '项目'),
        quantity: Math.max(1, Math.floor(Number(selected.quantity || 1)))
      }))
      replacementSnapshot.target.quantity = targetQuantity
    } else if (operationType === 'project_upgrade') {
      const targetEntitlementQuantity = Math.max(1, Math.floor(Number(operation.target?.targetEntitlementQuantity || 1)))
      payload.targetEntitlementQuantity = targetEntitlementQuantity
    }
  }

  if (new URLSearchParams(window.location.search).get('preview') === '1') {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'success', message: `${operation.label || '卡操作'}预览已确认；预览模式不会写入业务数据。` }
    }))
    finalizeEntitlementSelector()
    previewCardOperation.value = null
    return { result: { status: 'success', preview: true } }
  }

  // 项目替换是纯卡权益变更，不产生销售金额或收款单。确认替换时
  // 直接提交正式卡操作事务；只有项目升级仍需保留到结账时收取补差价。
  if (operationType === 'project_replacement') {
    isSubmittingCardOperation.value = true
    cardOperationNotice.value = '正在替换…'
    if (cardOperationNoticeTimer) window.clearTimeout(cardOperationNoticeTimer)
    await nextTick()
    const selectedMember = member.value ? clonePlain(member.value) : null
    try {
      const result = await requestAction('submit-card-operation', {
        ...payload,
        // Project replacement owns its local status UI. Do not let a failed
        // command emit the shell-wide refresh event and overwrite the local
        // member selection with a guest root projection.
        silent: true,
        // The replacement transaction returns its own command result, but it
        // does not own the browser's complete cashier projection. Keep the
        // selected member/cart root and let the local success branch close
        // this preview explicitly.
        preserveRootState: true,
      })
      // The command response may carry a guest-shaped root projection because
      // the replacement itself does not persist browser customer selection.
      // Restore the local member draft before any success/failure branch.
      if (selectedMember) {
        state.cashier = {
          ...(state.cashier || {}),
          customerMode: 'member',
          member: selectedMember
        }
      }
      const operationStatus = String(resultStatus(result) || '').toLowerCase()
      if (['success', 'succeeded'].includes(operationStatus)) {
        finalizeEntitlementSelector()
        previewCardOperation.value = null
        cardOperationNotice.value = '项目替换成功'
        if (cardOperationNoticeTimer) window.clearTimeout(cardOperationNoticeTimer)
        cardOperationNoticeTimer = window.setTimeout(() => {
          cardOperationNotice.value = ''
          cardOperationNoticeTimer = null
        }, 2800)
        // 项目替换事务已经锁定并更新了权威权益行。这里不能调用
        // open-cashier-workbench 重开完整
        // cashier workspace：会员选择属于浏览器本地草稿，根投影刷新会
        // 将它错误地覆盖成游客态。下次打开权益选择器时再读取最新权益。
        window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
          detail: { status: 'success', message: '项目替换成功。' }
        }))
      } else {
        if (selectedMember) {
          state.cashier = {
            ...(state.cashier || {}),
            customerMode: 'member',
            member: selectedMember
          }
        }
        cardOperationNotice.value = resultMessage(result, '项目替换未完成，请重试。')
        if (cardOperationNoticeTimer) window.clearTimeout(cardOperationNoticeTimer)
        cardOperationNoticeTimer = window.setTimeout(() => {
          cardOperationNotice.value = ''
          cardOperationNoticeTimer = null
        }, 4200)
      }
      return result
    } catch (error) {
      if (selectedMember) {
        state.cashier = {
          ...(state.cashier || {}),
          customerMode: 'member',
          member: selectedMember
        }
      }
      cardOperationNotice.value = String(error?.message || '项目替换未完成，请重试。')
      if (cardOperationNoticeTimer) window.clearTimeout(cardOperationNoticeTimer)
      cardOperationNoticeTimer = window.setTimeout(() => {
        cardOperationNotice.value = ''
        cardOperationNoticeTimer = null
      }, 4200)
      throw error
    } finally {
      isSubmittingCardOperation.value = false
    }
  }

  if (cardOperationUpgradeTypes.has(operationType)) {
    const localLineId = `local-card-operation-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const targetAmount = Math.max(0, Number(operation.target?.price || operation.target?.amount || 0))
    const sourceQuantity = cardOperationProjectTypes.has(operationType)
      ? (operation.sources || []).reduce((total, selected) => total + Math.max(1, Math.floor(Number(selected.quantity || 1))), 0)
      : 1
    const targetEntitlementQuantity = operationType === 'project_upgrade'
      ? Math.max(1, Math.floor(Number(operation.target?.targetEntitlementQuantity || 1)))
      : 1
    const sourceBalance = cardOperationSourceBalance(source, sourceQuantity)
    const receivableAmount = Math.max(0, targetAmount - sourceBalance)
    appendLocalCashierDraftOperation({
      action: 'submit-card-operation',
      localLineId,
      payload
    }, (draft) => {
      draft.lines.push({
        id: localLineId,
        lineRole: 'sale',
        catalogItemId: Number(payload.targetCatalogId || 0),
        productId: Number(payload.targetCatalogId || 0),
        name: String(operation.target?.name || (operationType === 'card_upgrade' ? '升级卡项' : '升级项目')),
        kind: String(operation.target?.kind || (operationType === 'card_upgrade' ? '卡项' : '项目')),
        productType: Number(operation.target?.productType || (operationType === 'project_upgrade' ? 6 : 0)),
        quantity: 1,
        amount: receivableAmount,
        finalAmount: receivableAmount,
        originalAmount: targetAmount,
        debtAmountCents: 0,
        cardOperationUpgrade: {
          operationType,
          sourceRemainingValueCents: moneyToCents(sourceBalance),
          targetPriceCents: moneyToCents(targetAmount),
          settlementDeltaCents: moneyToCents(receivableAmount),
          targetAmountCents: moneyToCents(targetAmount),
          deltaAmountCents: moneyToCents(receivableAmount),
          // The sale row is a single settlement fact. Its quantity must not
          // multiply the payable amount. Carry the separately generated
          // target-right count so the cart shows the same count that final
          // settlement will create.
          targetEntitlementQuantity
        },
        localCardOperation: clonePlain(payload)
      })
    })
    finalizeEntitlementSelector()
    previewCardOperation.value = null
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'success', message: '升级项目已加入本次购物车。' }
    }))
    return localDraftResult('升级项目已加入本次购物车。')
  }

  // Other non-upgrade card operations remain browser draft intents. Project
  // replacement has already returned above after its direct rights mutation.
  const localLineId = `local-card-operation-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  appendLocalCashierDraftOperation({
    action: 'submit-card-operation',
    localLineId,
    payload
  }, (draft) => {
    draft.lines.push({
      id: localLineId,
      lineRole: 'card_operation',
      name: String(operation.label || '卡操作'),
      kind: '卡操作',
      quantity: 1,
      amount: 0,
      finalAmount: 0,
      originalAmount: 0,
      localCardOperation: clonePlain(payload)
    })
  })
  finalizeEntitlementSelector()
  previewCardOperation.value = null
  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: { status: 'success', message: '卡操作已加入本次购物车。' }
  }))
  return localDraftResult('卡操作已加入本次购物车。')
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
    || !Array.isArray(selector.sources)) return false

  return sources.every((source) => {
    const cardHolderId = entitlementCardHolderId(source)
    if (!cardHolderId || !Array.isArray(source.projects)) return false
    return source.projects.every((project) => {
      const projectId = project.projectId || project.id
      const benefitPoolId = entitlementBenefitPoolId(project)
      return Boolean(projectId && benefitPoolId)
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
  if (!member.value || currentCustomerMode.value === 'guest') {
    pendingEntitlementSelector.value = true
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
      detail: { context: 'cashier', selectorContext: 'cashier' }
    }))
    return { success: false, status: 'member_required' }
  }
  if (isOpeningEntitlementSelector.value) return { success: false, message: '正在加载会员权益，请勿重复操作。' }

  const requestEpoch = cashierContextEpoch.value
  const requestScopeKey = currentCashierScopeKey.value
  const localDraftCheckpoint = captureLocalCashierDraftForEntitlementSelector()
  entitlementSelectorDraftCheckpoint.value = localDraftCheckpoint
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
    // A projection can replace the root synchronously while the workbench
    // watcher clears local snapshots on Vue's following flush. Restore only
    // after that flush, otherwise the watcher erases the protected purchase
    // lines just before the first entitlement is appended.
    await nextTick()
    if (requestEpoch !== cashierContextEpoch.value) {
      invalidateEntitlementSelector()
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次权益查询结果已忽略。' } }
    }
    restoreLocalCashierDraftAfterEntitlementSelector(localDraftCheckpoint)
    refreshEntitlementSelectorDraftCheckpoint()
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
  const projectKey = String(payload.projectKey || '')
  const addIntentId = String(payload.addIntentId || createCashierV3CommandId('ENTITLEMENT_ADD'))
  // addIntentId identifies the business row. Its command key must survive a
  // local-draft retry, otherwise the gateway correctly rejects the same row
  // as a second append with a different idempotency key.
  const idempotencyKey = String(payload.idempotencyKey || createCashierV3CommandId('ADD_ENTITLEMENT'))
  // 普通“使用权益”在打开选择器时已经取得最新展示数据。这里仅确保该
  // 快照随着命令完整传递；不会为添加动作再次读取卡项、余次或库存。
  const commandPayload = {
    ...payload,
    lines: normalizeEntitlementAppendLines(payload.lines),
    addIntentId,
    mutationMode: 'append'
  }
  delete commandPayload.projectKey
  const appendLine = commandPayload.lines[0]
  if (!appendLine) {
    return { result: { status: 'failed', code: 'ENTITLEMENT_LINE_INVALID', message: '权益项目数据不完整，请重新打开后选择。' } }
  }
  pendingEntitlementProjectKey.value = projectKey
  isAddingEntitlementLines.value = true
  try {
    const localLineId = `local-entitlement-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const localLine = localEntitlementDraftLine(appendLine, localLineId)
    appendLocalCashierDraftOperation({
      action: 'add-checkout-entitlement-lines',
      localLineId,
      payload: commandPayload,
      idempotencyKey
    }, (draft) => {
      localLine.actualAmount = localEntitlementAmount(draft.lines || [], localLine)
      localLine.amount = localLine.actualAmount
      localLine.finalAmount = localLine.actualAmount
      localLine.originalAmount = localLine.actualAmount
      draft.lines.push(localLine)
    })
    // Keep the newly appended benefit line together with all pending purchases
    // until the selector closes. A late selector/root response is read-only and
    // must never temporarily render a pure-entitlement cart.
    refreshEntitlementSelectorDraftCheckpoint()
    if (projectKey) {
      addedEntitlementProjectKey.value = projectKey
      activeCartLineId.value = localLineId
      addedEntitlementLineId.value = localLineId
      if (entitlementAddedTimer) window.clearTimeout(entitlementAddedTimer)
      entitlementAddedTimer = window.setTimeout(() => {
        addedEntitlementProjectKey.value = ''
        addedEntitlementLineId.value = ''
        entitlementAddedTimer = null
      }, 700)
    }
    return localDraftResult('卡内项目已加入本次购物车。')
  } finally {
    pendingEntitlementProjectKey.value = ''
    isAddingEntitlementLines.value = false
  }
}

function localEntitlementAmount(lines = [], line = {}) {
  const sourceKey = `${line.entitlementInstanceId || line.cardHolderId || ''}:${line.entitlementSourceDetailId || line.memberBenefitPoolId || ''}`
  const selectedBefore = (Array.isArray(lines) ? lines : []).reduce((total, current) => {
    if (!isEntitlementLine(current)) return total
    const currentKey = `${current.entitlementInstanceId || current.cardHolderId || ''}:${current.entitlementSourceDetailId || current.memberBenefitPoolId || ''}`
    return currentKey === sourceKey ? total + Math.max(1, Number(current.quantity || 1)) : total
  }, 0)
  const totalCents = moneyToCents(line.purchaseAmount)
  const totalTimes = Number(line.totalPurchaseTimes || 0)
  const consumedTimes = Number(line.consumedTimesAtSelection || 0) + selectedBefore
  const quantity = Math.max(1, Number(line.quantity || 1))
  // 编辑态只按当前展示权益计算应收，不以剩余次数阻止加入；实际余次由
  // 第三步确认时的权威结账事务重新读取并校验。
  if (!totalCents || !Number.isInteger(totalTimes) || totalTimes <= 0) return 0
  const centCapable = String(line.displaySnapshot?.sourceType || '').startsWith('cashier_v3_project_')
    || String(line.amountCalculationVersion || '').startsWith('operation-cent-')
    || String(line.displaySnapshot?.amountCalculationVersion || '').startsWith('operation-cent-')
  if (centCapable) {
    const regularCents = Math.floor(totalCents / totalTimes)
    const cumulative = (times) => times >= totalTimes
      ? totalCents
      : regularCents * Math.max(0, times)
    return centsToMoney(cumulative(consumedTimes + quantity) - cumulative(consumedTimes))
  }
  const totalWholeYuan = Math.floor(totalCents / 100)
  const regularWholeYuan = Math.floor(totalWholeYuan / totalTimes)
  const cumulative = (times) => times >= totalTimes
    ? totalWholeYuan * 100
    : regularWholeYuan * 100 * Math.max(0, times)
  return centsToMoney(cumulative(consumedTimes + quantity) - cumulative(consumedTimes))
}

function localEntitlementDraftLine(line = {}, id = '') {
  const snapshot = clonePlain(line.displaySnapshot || {})
  // Display metadata is copied as-is, but revision fields are projection
  // coordinates, not checkout facts. Strip them at the browser snapshot
  // boundary so the final command contains one business snapshot only.
  ;[
    'version', 'revision', 'recordVersion', 'detailVersion', 'sourceVersion',
    'projectVersion', 'entitlementSourceVersion', 'amountSourceVersion'
  ].forEach((key) => { delete snapshot[key] })
  return {
    id,
    lineRole: 'entitlement_service',
    memberId: currentMemberId.value,
    cardHolderId: String(line.cardHolderId || ''),
    memberBenefitPoolId: String(line.memberBenefitPoolId || ''),
    entitlementInstanceId: String(line.entitlementInstanceId || line.cardHolderId || ''),
    entitlementInstanceType: String(line.entitlementInstanceType || snapshot.entitlementInstanceType || ''),
    entitlementSourceDetailId: String(line.entitlementSourceDetailId || line.memberBenefitPoolId || ''),
    projectId: String(line.projectId || ''),
    name: String(snapshot.name || '项目'),
    kind: String(snapshot.kind || '项目'),
    entitlementSourceName: String(snapshot.entitlementSourceName || ''),
    fullCardNo: String(snapshot.fullCardNo || ''),
    // These three identity labels are part of the browser-owned checkout
    // snapshot. The final entitlement authority uses them to persist the
    // human-readable source/project audit values without rereading a stale
    // cashier projection.
    sourceNameSnapshot: String(snapshot.entitlementSourceName || ''),
    sourceCodeSnapshot: String(snapshot.fullCardNo || ''),
    projectNameSnapshot: String(snapshot.name || '项目'),
    remainingTimes: Number(snapshot.remainingTimes || 0),
    occupiedTimes: Number(snapshot.occupiedTimes || 0),
    availableTimes: Number(snapshot.availableTimes || 0),
    productType: 6,
    quantity: Math.max(1, Number(line.quantity || 1)),
    purchaseAmount: snapshot.purchaseAmount,
    totalPurchaseTimes: Number(snapshot.totalPurchaseTimes || 0),
    consumedTimesAtSelection: Number(snapshot.consumedTimesAtSelection || 0),
    sourceConsumedTimesAtSelection: Number(snapshot.consumedTimesAtSelection || 0),
    amountCalculationVersion: String(snapshot.amountCalculationVersion || ''),
    amountRole: 'entitlement_actual',
    serviceObject: '本人',
    craftsmen: [],
    craftsmenSummary: '待选择手艺人',
    isExperience: false,
    serviceSource: '卡内项目',
    displaySnapshot: snapshot,
    actualAmount: 0,
    amount: 0,
    finalAmount: 0,
    originalAmount: 0
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
    return {
      ...current,
      displaySnapshot: {
        name: String(project.name || project.projectName || '项目'),
        kind: '项目',
        entitlementInstanceType: String(source.entitlementInstanceType || source.sourceType || ''),
        entitlementSourceKind: project.isGift ? 'gift' : String(source.sourceKind || ''),
        isGift: Boolean(project.isGift),
        giftSourceType: project.isGift ? 'holder_backed' : 'none',
        sourceType: String(project.sourceType || ''),
        sourceDetailId: Number(detailId || 0),
        entitlementSourceName: String(source.name || ''),
        fullCardNo: String(source.fullCardNo || ''),
        remainingTimes: Number(project.remainingTimes || 0),
        occupiedTimes: Number(project.occupiedTimes || 0),
        availableTimes: Number(project.availableTimes || 0),
        purchaseAmount: String(project.purchaseAmount || ''),
        totalPurchaseTimes: Number(project.totalPurchaseTimes || 0),
        consumedTimesAtSelection: Number(project.consumedTimesAtSelection || 0),
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
  // Adding an entitlement uses its selector-issued member/card/pool contexts.
  // Once the row exists, its quantity and service settings are workspace-row
  // mutations, whose command contract accepts only the workspace context.
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
  return Promise.resolve(applyLocalCashierDraftMutation(action, line, payload))
}

function mutateRootCashierDraft(action, line, payload = {}) {
  return Promise.resolve(applyLocalCashierDraftMutation(action, line, payload))
}

async function executeCashierDraftMutation(action, line, payload = {}, { deferProjection = false } = {}) {
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
    if (deferProjection) {
      // 延迟替换页面投影不代表命令仍未完成；必须先释放恢复票据，
      // 否则后续数量、人员等命令会被误判为等待前一条命令恢复。
      draftCommandRecovery.settle(retryTicket, status)
      cashierDraftHasUnresolvedCommand.value = Boolean(
        draftCommandRecovery.pendingForScope(draftRecoveryScopeKey.value)
      )
      return { ...result, deferredCashierDraft: draft }
    }
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

function duplicateEntitlementLineId(result = {}) {
  const candidates = [result, result?.data, result?.data?.data]
  for (const candidate of candidates) {
    const conflict = candidate?.conflict
    if (String(conflict?.reason || '') === 'add_intent_reused_with_new_key'
      && String(conflict?.line_id || '').trim() !== '') {
      return String(conflict.line_id).trim()
    }
  }
  return ''
}

function authoritativeCashierDraftFromCurrentRoot() {
  return {
    ...localDraftBase(),
    complete: true
  }
}

async function recoverDuplicateEntitlementDraftOperation(result, operation, lineIdMap) {
  const persistedLineId = duplicateEntitlementLineId(result)
  if (!persistedLineId) return null

  const refreshed = await requestAction('open-cashier-workbench', { silent: true })
  if (!['success', 'succeeded'].includes(resultStatus(refreshed))) return null
  const persistedLine = cashierDraftLines(cashier.value)
    .find((line) => String(line?.id || '') === persistedLineId)
  if (!persistedLine) return null

  lineIdMap.set(String(operation.localLineId || ''), persistedLineId)
  localCashierPersistedLineIds.value = {
    ...localCashierPersistedLineIds.value,
    [String(operation.localLineId || '')]: persistedLineId
  }
  return authoritativeCashierDraftFromCurrentRoot()
}

// A deferred command can advance the server draft before the response used by
// the replay loop has been projected locally. Refresh the authoritative draft
// once before treating a missing mapped line as a real cart change.
async function resolveDeferredCashierDraftLine(deferredDraft, persistedLineId) {
  const current = cashierDraftLines(deferredDraft || {})
    .find((line) => String(line?.id || '') === String(persistedLineId || ''))
  if (current) return { draft: deferredDraft, line: current }

  const refreshed = await requestAction('open-cashier-workbench', { silent: true })
  if (!['success', 'succeeded'].includes(resultStatus(refreshed))) {
    return { draft: deferredDraft, line: null }
  }
  const refreshedDraft = responseDataBlock(refreshed).cashierDraft
  const refreshedLine = cashierDraftLines(refreshedDraft || {})
    .find((line) => String(line?.id || '') === String(persistedLineId || ''))
  return { draft: refreshedDraft || deferredDraft, line: refreshedLine || null }
}

async function synchronizeLocalCashierDraft({ deferProjection = false } = {}) {
  // The editable cart is browser-owned until final confirmation.  This
  // compatibility entrypoint remains for hang/recovery callers, but must
  // never replay local lines or settings into cashier_workspace.
  void deferProjection
  localCashierPersistedLineIds.value = {}
  return localDraftResult()
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
  if (Object.prototype.hasOwnProperty.call(ticket.payload || {}, 'friendCountsAsCustomer')
    && cartLineFriendCountsAsCustomer(line) !== Boolean(ticket.payload.friendCountsAsCustomer)) return false
  if (Object.prototype.hasOwnProperty.call(ticket.payload || {}, 'isExperience')
    && Boolean(line.isExperience) !== Boolean(ticket.payload.isExperience)) return false
  if (Object.prototype.hasOwnProperty.call(ticket.payload || {}, 'isPresale')
    && cartLinePresaleSelected(line) !== Boolean(ticket.payload.isPresale)) return false

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
  if (cashierDraftHasUnresolvedCommand.value) {
    resolveReflectedDraftCommand()
    if (!cashierDraftHasUnresolvedCommand.value) return true
  }
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
  // 结账入口只读取当前收银页面已经渲染出来的按钮文字。
  // 不读取后端快照、不调用接口，也不根据另一套业务规则猜测。
  const rows = Array.from(document.querySelectorAll('.cart-line-list article.cart-line'))
  const waitingRow = rows.find((row) => Array.from(
    row.querySelectorAll('.cart-line__meta-slot--craftsmen button')
  ).some((button) => button.textContent.trim() === '待选择手艺人'))
  if (waitingRow) return waitingRow
  return (document.body?.innerText || '').includes('待选择手艺人')
    ? { source: 'cashier-page' }
    : null
}

function cartLineServiceObject(line = {}) {
  const local = localLineServiceSettings.value[line.id]
  if (local?.serviceObject) return local.serviceObject
  return ['friend', '朋友'].includes(line.serviceObject) ? 'friend' : 'self'
}

function cartLineFriendCountsAsCustomer(line = {}) {
  const local = localLineServiceSettings.value[line.id]
  if (typeof local?.friendCountsAsCustomer === 'boolean') return local.friendCountsAsCustomer
  // 旧草稿没有该快照时保持原“朋友单独计客”的既有口径。
  return !(line.friendCountsAsCustomer === false || Number(line.friendCountsAsCustomer) === 0)
}

function isProductLine(line = {}) {
  return cartLineRole(line) === 'sale' && (Number(line.productType) === 0 || line.kind === '产品')
}

function isInventoryManagedProductLine(line = {}) {
  return isProductLine(line) && !isCustomCardPurchase(line)
}

function canSetCartLineDebt(line = {}) {
  const memberId = String(line.memberId || currentMemberId.value || '').trim()
  return memberId !== ''
    && currentCustomerMode.value !== 'guest'
    && cartLineRole(line) === 'sale'
    && !isProjectLine(line)
    && (isProductLine(line) || line.kind === '卡项')
}

function cartLinePresaleSelected(line = {}) {
  const local = localLineServiceSettings.value[line.id]
  return typeof local?.isPresale === 'boolean'
    ? local.isPresale
    : line.isPresale === true || Number(line.isPresale) === 1
}

function cartLineInventoryOutboundRequired(line = {}) {
  if (isCustomCardPurchase(line)) return false
  // Presale and physical outbound are mutually exclusive. Treat the
  // browser snapshot as presale-owned even if an older projection contains
  // both flags.
  if (cartLinePresaleSelected(line)) return false
  const local = localLineServiceSettings.value[line.id]
  if (typeof local?.inventoryOutboundRequired === 'boolean') return local.inventoryOutboundRequired
  return line.inventoryOutboundRequired !== false && Number(line.inventoryOutboundRequired) !== 0
}

async function queryPersonnelCandidates(scope, line, keyword = '') {
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
        projectId: line.projectId || line.catalogProductId || line.productId || line.catalog_product_id || '',
        memberId: line.memberId || currentMemberId.value || '',
        entitlementInstanceId: line.entitlementInstanceId || line.cardHolderId || '',
        entitlementSourceDetailId: line.entitlementSourceDetailId || line.memberBenefitPoolId || ''
      },
      keyword,
      page,
      pageSize: scope.startsWith('group_') ? 20 : 100,
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

async function loadPersonnelOverlay(line, initialTab, roleScope = 'personnel') {
  // 卡/项目升级在收款成功前只形成销售草稿，不创建服务或劳动业绩。
  const showCraftsmen = roleScope === 'personnel' && isProjectLine(line) && !cardOperationUpgradeBinding(line)
  // 卡内权益只形成服务和劳动业绩，不形成销售业绩；不能加载或提交销售人。
  const showSalespeople = roleScope === 'personnel' && !isEntitlementLine(line)
  const showGuides = !isEntitlementLine(line) && ['guide', 'attribution'].includes(roleScope)
  const showSalesManagers = !isEntitlementLine(line) && ['salesManager', 'attribution'].includes(roleScope)
  const requestKey = `${line.id}:${Date.now()}`
  personnelOverlay.value = {
    requestKey,
    line: clonePlain(line),
    initialTab,
    roleScope,
    showCraftsmen,
    showSalespeople,
    showGuides,
    showSalesManagers,
    allowLaborOverride: showCraftsmen,
    laborDefaultFee: Number(line.laborDefaultFee ?? line.laborConfiguredUnitAmount ?? 0),
    laborManualFee: line.laborManualFee === null || line.laborManualFee === undefined
      ? null
      : Number(line.laborManualFee),
    performanceBaseAmountCents: personnelPerformanceBaseAmountCents(line),
    projectCountTotal: Math.max(1, Number(line.quantity || 1)),
    requireCraftsmen: showCraftsmen,
    craftsmenCandidates: [],
    otherCraftsmanCandidates: [],
    salespersonCandidates: [],
    guideCandidates: [],
    salesManagerCandidates: [],
    selectedCraftsmen: clonePlain(localPersonnelAssignments.value[line.id]?.craftsmen || cartLineCraftsmen(line)),
    selectedSalespeople: showSalespeople
      ? clonePlain(localPersonnelAssignments.value[line.id]?.salespeople || (Array.isArray(line.salespeople) ? line.salespeople : []))
      : [],
    selectedGuides: showGuides
      ? clonePlain(localPersonnelAssignments.value[line.id]?.guideSelections || (Array.isArray(line.guideSelections) ? line.guideSelections : []))
      : [],
    selectedSalesManagers: showSalesManagers
      ? clonePlain(localPersonnelAssignments.value[line.id]?.salesManagerSelections || (Array.isArray(line.salesManagerSelections) ? line.salesManagerSelections : []))
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

async function searchPersonnelOverlay({ scope, keyword, target } = {}) {
  const current = personnelOverlay.value
  if (!current?.line || !['group_attributions', 'cashier_other_craftsmen'].includes(scope)) return
  const requestKey = current.requestKey
  try {
    const records = await queryPersonnelCandidates(scope, current.line, keyword)
    if (personnelOverlay.value?.requestKey !== requestKey) return
    personnelOverlay.value = {
      ...personnelOverlay.value,
      ...(target === 'otherCraftsmen'
        ? { otherCraftsmanCandidates: records }
        : target === 'salesManager'
          ? { salesManagerCandidates: records }
          : target === 'guide'
            ? { guideCandidates: records }
            : { guideCandidates: records, salesManagerCandidates: records })
    }
  } catch (error) {
    if (personnelOverlay.value?.requestKey !== requestKey) return
    personnelOverlay.value = {
      ...personnelOverlay.value,
      loadError: error instanceof Error ? error.message : '集团人员搜索失败，请重试。'
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

async function openCartLineAttributions(line) {
  if (isEntitlementLine(line)) return
  if (currentCustomerMode.value === 'guest') {
    reportGuestAttributionBlocked()
    return
  }
  activeCartLineId.value = line.id
  await loadPersonnelOverlay(line, 'guides', 'attribution')
}

async function retryPersonnelOverlay() {
  const current = personnelOverlay.value
  if (!current?.line) return
  await loadPersonnelOverlay(current.line, current.initialTab, current.roleScope)
}

async function saveCartLineSalespeople(line, salespeople) {
  await mutateCashierDraft('update-cart-line-service-settings', line, { salespeople })
}

function openCartLineDebt(line) {
  const debtAmountCents = lineDebtAmountCents(line)
  debtEditorAmount.value = debtAmountCents > 0
    ? String(Math.trunc(debtAmountCents / 100))
    : ''
  debtEditor.value = { line: clonePlain(line) }
}

function handleDebtEditorAmountInput(event) {
  const amount = String(event?.target?.value || '').replace(/\D/g, '')
  debtEditorAmount.value = amount
  if (event?.target) event.target.value = amount
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
  // 本地草稿可能同时投影旧服务端行和新临时行。只要当前存在本地草稿，
  // 所有券选择都必须写入同一份本地快照，不能按单行 ID 退回旧接口。
  const localDraft = Boolean(localCashierDraft.value)
  const reservedCouponIds = localDraft
    ? cartLines.value
      .filter((candidate) => String(candidate?.id || '') !== String(line.id || ''))
      .map((candidate) => Number(candidate?.couponId || 0))
      .filter((couponId) => Number.isInteger(couponId) && couponId > 0)
    : []
  const lineAmountCents = moneyToCents(getLineAmount(line))
  const couponThresholdCents = Math.max(
    lineAmountCents,
    Number(line?.cardOperationUpgrade?.targetAmountCents || 0)
  )
  const result = await requestAction(localDraft ? 'open-local-line-coupon' : 'open-line-coupon', localDraft
    ? { lineId: line.id, lineAmountCents, couponThresholdCents, reservedCouponIds }
    : { lineId: line.id })
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
    const coupon = (couponSelector.value?.coupons || []).find((item) => String(item?.couponId || '') === String(couponId || ''))
    const payload = couponId
      ? {
          couponId,
          couponSummary: String(coupon?.name || '已选优惠券'),
          discountAmountCents: Math.max(0, Number(coupon?.discountAmountCents || 0))
        }
      : {}
    let result = await mutateCashierDraft(action, line, payload)
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

function personnelPerformanceBaseAmountCents(line = {}) {
  if (!isEntitlementLine(line)) return lineSaleAmountCents(line)
  const amount = Number(
    line.entitlementActualAmountCents
      ?? line.actualEntitlementAmountCents
      ?? line.amountCents
      ?? 0
  )
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
  const amountCents = Number(debtEditorAmount.value || 0) * 100
  const line = debtEditor.value?.line
  if (!line?.id) return
  const saleAmountCents = lineSaleAmountCents(line)
  if (amountCents > saleAmountCents) {
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

// 人员比例与手工金额都是本次结账快照的一部分。比例不再要求各组凑满
// 100%，但仍必须是可解释的 0-100 整数；最终金额由后端保存为事实快照。
function personnelAllocationGroupsAreValid(records = [], weightKey = 'laborWeight') {
  if (!Array.isArray(records) || !records.length) return true
  for (const record of records) {
    const performanceType = String(record?.craftsmanPerformanceType ?? record?.craftsman_performance_type ?? '')
    const isLabor = performanceType === 'labor'
    const weight = Number(record?.[weightKey])
    if (!Number.isInteger(weight) || weight < 0 || weight > 100) return false
    if (isLabor) {
      if (weight !== 0) return false
      continue
    }
    const amount = Number(record?.performanceAmountCents ?? record?.performance_amount_cents ?? 0)
    if (!Number.isSafeInteger(amount) || amount < 0) return false
  }
  return true
}

async function confirmPersonnelAssignment(result = {}) {
  if (isSavingPersonnelAssignment.value) return
  const line = personnelOverlay.value?.line
  if (!line?.id) return
  const roleScope = personnelOverlay.value?.roleScope || 'personnel'
  isSavingPersonnelAssignment.value = true
  try {
    const craftsmen = (result.craftsmen || []).map((record) => ({
      staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
      employeeId: canonicalCheckoutPositiveId(record.employeeId, record.employee_id, record.staffId, record.id),
      name: String(record.name || record.staffName || record.employeeName || '').trim(),
      laborWeight: Number(record.laborWeight),
      performanceAmountCents: Math.max(0, Math.trunc(Number(record.performanceAmountCents ?? record.performance_amount_cents ?? 0))),
      performanceAmountManual: Boolean(record.performanceAmountManual ?? record.performance_amount_manual),
      marked: Boolean(record.marked ?? record.isPointCustomer),
      isPointCustomer: Boolean(record.isPointCustomer ?? record.marked),
      craftsmanPerformanceType: record.craftsmanPerformanceType || record.craftsman_performance_type,
      laborFeeCents: Number(record.laborFeeCents ?? record.labor_fee_cents ?? 0),
      projectCountHalfUnits: Math.max(0, Number(record.projectCountHalfUnits ?? record.project_count_half_units ?? 0)),
      positionId: Number(record.positionId ?? record.position_id ?? 0),
      positionName: record.positionName || record.position_name || record.position || '',
      performanceIndependent: record.performanceIndependent === true
        || Number(record.performanceIndependent ?? record.performance_independent ?? 0) === 1
        || String(record.allocationGroupKey || '').trim().startsWith('independent:'),
      allocationGroupKey: record.allocationGroupKey || '',
      ...(record.personnelSource === 'other' ? { personnelSource: 'other' } : {})
    }))
    const salespeople = (result.salespeople || []).map((record) => ({
      staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
      allocationWeight: Number(record.allocationWeight),
      performanceAmountCents: Math.max(0, Math.trunc(Number(record.performanceAmountCents ?? record.performance_amount_cents ?? 0))),
      performanceAmountManual: Boolean(record.performanceAmountManual ?? record.performance_amount_manual),
      isPreSale: Boolean(record.isPreSale ?? record.is_presale ?? record.marked),
      positionId: Number(record.positionId ?? record.position_id ?? 0),
      positionName: record.positionName || record.position_name || record.position || '',
      performanceIndependent: record.performanceIndependent === true
        || Number(record.performanceIndependent ?? record.performance_independent ?? 0) === 1
        || String(record.allocationGroupKey || '').trim().startsWith('independent:'),
      allocationGroupKey: record.allocationGroupKey || ''
    }))
    const guideSelections = (result.guideSelections || []).map((record) => ({
      staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
      employeeId: canonicalCheckoutPositiveId(record.employeeId, record.staffId, record.id, record.employee_id),
      name: record.name,
      guideRoundNo: Number(record.guideRoundNo ?? record.guide_round_no ?? 0)
    }))
    const salesManagerSelections = (result.salesManagerSelections || []).map((record) => ({
      staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
      employeeId: canonicalCheckoutPositiveId(record.employeeId, record.staffId, record.id, record.employee_id),
      name: record.name
    }))
    const payload = {}
    if (roleScope === 'personnel') {
      if (isProjectLine(line) && !cardOperationUpgradeBinding(line)) payload.craftsmen = craftsmen
      if (!isEntitlementLine(line)) payload.salespeople = salespeople
    } else if (roleScope === 'guide') {
      payload.guideSelections = guideSelections
    } else if (roleScope === 'salesManager') {
      payload.salesManagerSelections = salesManagerSelections
    } else if (roleScope === 'attribution') {
      payload.guideSelections = guideSelections
      payload.salesManagerSelections = salesManagerSelections
    }
    if (roleScope === 'personnel' && payload.craftsmen?.length
      && !personnelAllocationGroupsAreValid(payload.craftsmen, 'laborWeight')) {
      reportPersonnelAssignmentFailure(
        null,
        '手艺人业绩比例仅可填写 0 至 100 的整数，业绩金额不能为负数。'
      )
      return
    }
    if (roleScope === 'personnel' && payload.salespeople?.length
      && !personnelAllocationGroupsAreValid(payload.salespeople, 'allocationWeight')) {
      reportPersonnelAssignmentFailure(
        null,
        '销售人业绩比例仅可填写 0 至 100 的整数，业绩金额不能为负数。'
      )
      return
    }
    if (Object.prototype.hasOwnProperty.call(result, 'laborManualFee')) {
      payload.laborManualFee = Number(result.laborManualFee)
    }
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
      [line.id]: {
        ...(localPersonnelAssignments.value[line.id] || {}),
        ...(roleScope === 'personnel'
          ? (isEntitlementLine(line) ? { craftsmen: clonePlain(craftsmen) } : clonePlain(result))
          : roleScope === 'guide'
            ? { guideSelections: clonePlain(result.guideSelections || []) }
            : roleScope === 'salesManager'
              ? { salesManagerSelections: clonePlain(result.salesManagerSelections || []) }
              : {
                  guideSelections: clonePlain(result.guideSelections || []),
                  salesManagerSelections: clonePlain(result.salesManagerSelections || [])
                })
      }
    }
    personnelOverlay.value = null
  } finally {
    isSavingPersonnelAssignment.value = false
  }
}

async function applyPersonnelAssignmentToAll(result = {}) {
  if (isSavingPersonnelAssignment.value) return
  const hasPurchaseLines = cartLines.value.some((line) => !isEntitlementLine(line))
  const craftsmen = (result.craftsmen || []).map((record) => ({
    staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
    employeeId: canonicalCheckoutPositiveId(record.employeeId, record.employee_id, record.staffId, record.id),
    name: String(record.name || record.staffName || record.employeeName || '').trim(),
    laborWeight: Number(record.laborWeight),
    performanceAmountCents: Math.max(0, Math.trunc(Number(record.performanceAmountCents ?? record.performance_amount_cents ?? 0))),
    performanceAmountManual: Boolean(record.performanceAmountManual ?? record.performance_amount_manual),
    marked: Boolean(record.marked ?? record.isPointCustomer),
    isPointCustomer: Boolean(record.isPointCustomer ?? record.marked),
    craftsmanPerformanceType: record.craftsmanPerformanceType || record.craftsman_performance_type,
    laborFeeCents: Number(record.laborFeeCents ?? record.labor_fee_cents ?? 0),
    projectCountHalfUnits: Math.max(0, Number(record.projectCountHalfUnits ?? record.project_count_half_units ?? 0)),
    positionId: Number(record.positionId ?? record.position_id ?? 0),
    positionName: record.positionName || record.position_name || record.position || '',
    performanceIndependent: record.performanceIndependent === true
      || Number(record.performanceIndependent ?? record.performance_independent ?? 0) === 1
      || String(record.allocationGroupKey || '').trim().startsWith('independent:'),
    allocationGroupKey: record.allocationGroupKey || '',
    ...(record.personnelSource === 'other' ? { personnelSource: 'other' } : {})
  }))
  const salespeople = (hasPurchaseLines ? (result.salespeople || []) : []).map((record) => ({
    staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
    allocationWeight: Number(record.allocationWeight),
    performanceAmountCents: Math.max(0, Math.trunc(Number(record.performanceAmountCents ?? record.performance_amount_cents ?? 0))),
    performanceAmountManual: Boolean(record.performanceAmountManual ?? record.performance_amount_manual),
    isPreSale: Boolean(record.isPreSale ?? record.is_presale ?? record.marked),
    positionId: Number(record.positionId ?? record.position_id ?? 0),
    positionName: record.positionName || record.position_name || record.position || '',
    performanceIndependent: record.performanceIndependent === true
      || Number(record.performanceIndependent ?? record.performance_independent ?? 0) === 1
      || String(record.allocationGroupKey || '').trim().startsWith('independent:'),
    allocationGroupKey: record.allocationGroupKey || ''
  }))
  const guideSelections = (result.guideSelections || []).map((record) => ({
    staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
    employeeId: canonicalCheckoutPositiveId(record.employeeId, record.staffId, record.id, record.employee_id),
    name: record.name,
    guideRoundNo: Number(record.guideRoundNo ?? record.guide_round_no ?? 0)
  }))
  const salesManagerSelections = (result.salesManagerSelections || []).map((record) => ({
    staffId: canonicalCheckoutPositiveId(record.staffId, record.id, record.employeeId, record.employee_id),
    employeeId: canonicalCheckoutPositiveId(record.employeeId, record.staffId, record.id, record.employee_id),
    name: record.name
  }))
  if (craftsmen.length && !personnelAllocationGroupsAreValid(craftsmen, 'laborWeight')) {
    reportPersonnelAssignmentFailure(
      null,
      '手艺人业绩比例仅可填写 0 至 100 的整数，业绩金额不能为负数。'
    )
    return
  }
  if (salespeople.length && !personnelAllocationGroupsAreValid(salespeople, 'allocationWeight')) {
    reportPersonnelAssignmentFailure(
      null,
      '销售人业绩比例仅可填写 0 至 100 的整数，业绩金额不能为负数。'
    )
    return
  }
  if (!craftsmen.length && !salespeople.length && !guideSelections.length && !salesManagerSelections.length) return
  isSavingPersonnelAssignment.value = true
  try {
    const payload = {
      craftsmen,
      salespeople,
      guideSelections,
      salesManagerSelections
    }
    appendLocalCashierDraftOperation({ action: 'apply-cashier-personnel-to-all-lines', payload }, (draft) => {
      for (const line of draft.lines || []) {
        if (craftsmen.length && isProjectLine(line) && !isCustomCardPurchase(line)) line.craftsmen = clonePlain(craftsmen)
        if (salespeople.length && !isEntitlementLine(line)) line.salespeople = clonePlain(result.salespeople || [])
        if (!isEntitlementLine(line)) {
          line.guideSelections = clonePlain(result.guideSelections || [])
          line.salesManagerSelections = clonePlain(result.salesManagerSelections || [])
        }
      }
    })
    const assignments = { ...localPersonnelAssignments.value }
    for (const line of cartLines.value) {
      const current = { ...(assignments[line.id] || {}) }
      if (craftsmen.length && isProjectLine(line) && !isCustomCardPurchase(line)) {
        current.craftsmen = clonePlain(craftsmen)
      }
      if (salespeople.length && !isEntitlementLine(line)) {
        current.salespeople = clonePlain(result.salespeople || [])
      }
      if (!isEntitlementLine(line)) {
        current.guideSelections = clonePlain(result.guideSelections || [])
        current.salesManagerSelections = clonePlain(result.salesManagerSelections || [])
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
  if (!Array.isArray(records)) return ''
  if (!records.length) return ''
  const first = `${records[0].name}${records[0].marked ? '(售前)' : ''}`
  return records.length > 1 ? `${first}${records.length}人` : first
}

function attributionDisplaySummary(line = {}, key = '', fallback = '') {
  const localRecords = localPersonnelAssignments.value[line.id]?.[key]
  const records = Array.isArray(localRecords)
    ? localRecords
    : (Array.isArray(line[key]) ? line[key] : [])
  if (!records.length) return fallback
  const names = records
    .map((record) => record?.name || record?.staffName || record?.employeeName || '')
    .filter(Boolean)
  if (!names.length) return fallback
  return names.length > 2 ? `${names.slice(0, 2).join('、')}等${names.length}人` : names.join('、')
}

function guideDisplaySummary(line = {}) {
  return attributionDisplaySummary(line, 'guideSelections', '')
}

function salesManagerDisplaySummary(line = {}) {
  return attributionDisplaySummary(line, 'salesManagerSelections', '')
}

function groupAttributionDisplaySummary(line = {}) {
  return [guideDisplaySummary(line), salesManagerDisplaySummary(line)].filter(Boolean).join('、')
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

function setCartLineServiceObject(line, serviceObject, friendCountsAsCustomer = true) {
  if (!isProjectLine(line) || !['self', 'friend'].includes(serviceObject)) return
  if (cartLineServiceObject(line) === serviceObject
    && (serviceObject !== 'friend' || cartLineFriendCountsAsCustomer(line) === friendCountsAsCustomer)) return
  activeCartLineId.value = line.id
  localLineServiceSettings.value = {
    ...localLineServiceSettings.value,
    [line.id]: {
      ...(localLineServiceSettings.value[line.id] || {}),
      serviceObject,
      friendCountsAsCustomer: serviceObject === 'friend' ? friendCountsAsCustomer : true
    }
  }
}

function toggleCartLineExperience(line) {
  if (!isProjectLine(line)) return
  activeCartLineId.value = line.id
  localLineServiceSettings.value = {
    ...localLineServiceSettings.value,
    [line.id]: {
      ...(localLineServiceSettings.value[line.id] || {}),
      isExperience: !cartLineExperienceSelected(line)
    }
  }
}

function setCartLineInventoryMode(line, mode) {
  if (!isInventoryManagedProductLine(line)) return
  activeCartLineId.value = line.id
  const isPresale = mode === 'presale'
  localLineServiceSettings.value = {
    ...localLineServiceSettings.value,
    [line.id]: {
      ...(localLineServiceSettings.value[line.id] || {}),
      isPresale,
      inventoryOutboundRequired: mode === 'outbound'
    }
  }
}

function cartLineExperienceSelected(line = {}) {
  const local = localLineServiceSettings.value[line.id]
  return typeof local?.isExperience === 'boolean'
    ? local.isExperience
    : line.isExperience === true
}

async function persistDeferredLineServiceSettings() {
  // Service settings are part of the browser checkout snapshot.  Keep this
  // compatibility hook for callers and contracts, but never write a mutable
  // workspace projection before the final confirmation transaction.
  return true
}

function cardOperationUpgradeBinding(line = {}) {
  const binding = line?.cardOperationUpgrade || line?.authoritySnapshot?.cardOperationUpgrade
  return binding && ['card_upgrade', 'project_upgrade'].includes(String(binding.operationType || '')) ? binding : null
}

// A local card-operation payload is meaningful on a sale row only when that
// exact row is the browser-created upgrade row. Normal goods, projects and
// ordinary card purchases must never inherit a stale local operation field and
// be routed through the upgrade settlement branch at final confirmation.
function isLocalUpgradeOperationLine(line = {}) {
  const binding = cardOperationUpgradeBinding(line)
  const operation = line?.localCardOperation
  return Boolean(
    binding
    && isRecord(operation)
    && cardOperationUpgradeTypes.has(String(operation.operationType || ''))
    && String(operation.operationType || '') === String(binding.operationType || '')
  )
}

function cardOperationUpgradeLabel(binding = {}) {
  return binding.operationType === 'project_upgrade' ? '项目升级' : '卡升级'
}

function cardOperationUpgradeMoney(binding = {}, field = '') {
  return Number(binding?.[field] || 0) / 100
}

function cardOperationUpgradeTargetEntitlementQuantity(binding = {}) {
  const explicit = Math.floor(Number(binding?.targetEntitlementQuantity || 0))
  if (explicit > 0) return explicit
  return (Array.isArray(binding?.projectMutations) ? binding.projectMutations : [])
    .reduce((total, mutation) => total + Math.max(0, -Math.floor(Number(mutation?.quantityDelta || 0))), 0) || 1
}

async function removeCartLine(line) {
  if (!localCashierDraft.value) {
    return mutateRootCashierDraft('remove-cart-line', line)
  }
  return mutateCashierDraft('remove-cart-line', line)
}

async function confirmClearCart() {
  // “清空”同时是收银台的恢复出口。即使当前购物车已经为空，仍需让
  // 收银员释放未完成结账草稿、前端遮罩和恢复票据，避免失败页或强刷后
  // 的旧状态继续锁住下一单。
  if (isClearingCart.value) return
  isClearingCart.value = true
  try {
    applyLocalCashierRootMutation('clear-cart-lines')
    draftCommandRecovery.clear()
    cashierDraftHasUnresolvedCommand.value = false
    window.dispatchEvent(new CustomEvent('cashier-v3:clear-negative-state'))
    return localDraftResult()
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
  entitlementSelectorDraftCheckpoint.value = null
  entitlementSelectorRequestId.value = null
  entitlementSelectorSnapshot.value = null
  entitlementSelectorLoadState.value = 'idle'
  entitlementSelectorLoadError.value = ''
}

function retryEntitlementSelector() {
  if (isOpeningEntitlementSelector.value || !member.value || currentCustomerMode.value === 'guest') return
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
  selectedType.value = currentCustomerMode.value === 'member' ? '卡项' : '项目'
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
    // Shell opens the source dialog immediately after a normal member
    // selection. Queue the entitlement selector until that dialog is
    // confirmed so the two overlays never compete for focus.
    pendingEntitlementAfterSource.value = true
    if (!checkoutBusinessSourceSelector.value) {
      nextTick(() => openEntitlementSelectorAfterSource())
    }
  }
}

function openEntitlementSelectorAfterSource() {
  if (!pendingEntitlementAfterSource.value
    || !member.value
    || currentCustomerMode.value === 'guest') return
  pendingEntitlementAfterSource.value = false
  pendingEntitlementSelector.value = false
  openEntitlementSelector()
}

function handleCheckoutBusinessSourceConfirmed() {
  nextTick(() => openEntitlementSelectorAfterSource())
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
  rechargePreparationError.value = ''
  // 客户来源在选会员后已由收银顶部确认。充值第一步只保存这一份浏览器
  // 快照；后续“下一步”不得再读取可能被异步根投影清掉的工具栏状态，
  // 更不能要求员工重复选择来源。
  rechargeSession.value = {
    ...session,
    businessSourceSnapshot: {
      primarySourceId: Number(localCheckoutBusinessSource.value.primarySourceId || 0),
      secondarySourceId: Number(localCheckoutBusinessSource.value.secondarySourceId || 0),
      rewardAmountCents: Math.max(0, Number(localCheckoutBusinessSource.value.rewardAmountCents || 0))
    }
  }
}

function closeRecharge() {
  rechargeSession.value = null
  rechargePreparationIdempotencyKey.value = ''
  rechargePreparationError.value = ''
}

async function submitRecharge(payload = {}) {
  if (isRechargeSubmitting.value || !rechargeSession.value) return
  const capturedSource = rechargeSession.value.businessSourceSnapshot || {}
  const livePrimarySourceId = Number(localCheckoutBusinessSource.value.primarySourceId || 0)
  const liveSecondarySourceId = Number(localCheckoutBusinessSource.value.secondarySourceId || 0)
  const primarySourceId = livePrimarySourceId > 0
    ? livePrimarySourceId
    : Number(capturedSource.primarySourceId || 0)
  const secondarySourceId = livePrimarySourceId > 0
    ? liveSecondarySourceId
    : Number(capturedSource.secondarySourceId || 0)
  const rewardAmountCents = livePrimarySourceId > 0
    ? Math.max(0, Number(localCheckoutBusinessSource.value.rewardAmountCents || 0))
    : Math.max(0, Number(capturedSource.rewardAmountCents || 0))
  // 选会员流程尚未确认来源时，保持当前充值表单并给出明确提示；不在
  // “下一步”叠加第二个来源弹窗，避免误以为来源被重复要求。
  if (primarySourceId <= 0 || secondarySourceId < 0) {
    rechargePreparationError.value = '请先在收银页面顶部选择客户来源。'
    return {
      result: {
        status: 'failed',
        code: 'RECHARGE_BUSINESS_SOURCE_REQUIRED',
        message: rechargePreparationError.value
      }
    }
  }
  isRechargeSubmitting.value = true
  try {
    // 客户来源只在收银顶部选择一次。准备充值结账时将该选择和业务日期、
    // 销售人同时冻结；结账页不再出现第二套来源选择控件。
    const businessSource = {
      primarySourceId,
      secondarySourceId,
      rewardAmountCents
    }
    const response = await requestCashierV3Action('prepare-recharge-checkout', {
      ...payload,
      memberId: payload.memberId || currentMemberId.value,
      businessSource,
      ...(rechargePreparationIdempotencyKey.value
        ? { idempotencyKey: rechargePreparationIdempotencyKey.value }
        : {})
    })
    const responseStatus = resultStatus(response)
    rechargePreparationError.value = ['success', 'succeeded'].includes(responseStatus)
      ? ''
      : resultMessage(response, '充值收款准备失败，请核对资料后重试。')
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
      const payload = clonePlain(result.payload || {})
      const amount = Math.max(0, Number(result.amount || 0))
      if (!String(payload.cardName || '').trim() || !amount) return
      const localLineId = `local-custom-card-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      appendLocalCashierDraftOperation({
        action: 'create-custom-card-configuration',
        localLineId,
        payload
      }, (draft) => {
        draft.lines.push({
          id: localLineId,
          lineRole: 'sale',
          name: String(payload.cardName || result.title || '定制卡'),
          kind: '卡项',
          kindCode: 'custom_card',
          sourceKind: 'custom_card',
          productType: 0,
          quantity: 1,
          amount,
          finalAmount: amount,
          originalAmount: amount,
          debtAmountCents: 0,
          cardPurchaseSnapshot: customCardPurchaseSnapshot(payload),
          localCustomCardConfiguration: payload
        })
      })
      guidedBusinessMode.value = ''
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

function closeMoreActionEditor({ force = false } = {}) {
  if (isSavingMoreAction.value && !force) return
  moreActionEditor.value = null
  moreActionValue.value = ''
  moreActionReason.value = ''
  moreActionValidationMessage.value = ''
}

function handleMoreActionReasonInput(event) {
  moreActionReason.value = String(event?.target?.value || '')
  moreActionValidationMessage.value = ''
}

async function saveMoreActionEditor() {
  const editor = moreActionEditor.value
  if (!editor || isSavingMoreAction.value) return
  let action = ''
  let payload = {}
  let savedPriceAmountCents = null
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
    savedPriceAmountCents = lineAmountCents
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
  try {
    applyLocalCashierRootMutation(action, payload)
    // The save guard prevents an accidental second click, but the successful
    // save itself must still close the editor. Use the explicit force path so
    // the updated amount is visible in the cart immediately.
    closeMoreActionEditor({ force: true })
    if (savedPriceAmountCents !== null) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: {
          status: 'info',
          message: `改价已保存：${formatMoney(savedPriceAmountCents / 100)}；确认收款时将使用该金额。`
        }
      }))
    }
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

function localCheckoutEntitlementAvailabilityFailure(snapshot = {}) {
  // “立即结账”只可使用已经展示在本页的权益快照。按卡、权益明细和项目
  // 汇总数量，避免同一权益被拆成多行后绕过当前页面的可用次数。
  // 这里绝不重新读取权益，也不向服务端发起校验请求；最终确认收款仍由同一
  // 快照随唯一的 submit-checkout 命令进入结算事务。
  const usageBySource = new Map()
  const lines = Array.isArray(snapshot?.lines) ? snapshot.lines : []
  for (const line of lines) {
    if (!isEntitlementLine(line)) continue
    const holderId = String(line.entitlementInstanceId || line.cardHolderId || '').trim()
    const detailId = String(line.entitlementSourceDetailId || line.memberBenefitPoolId || '').trim()
    const projectId = String(line.projectId || '').trim()
    const availableTimes = Math.floor(Number(line.availableTimes))
    const quantity = Math.floor(Number(line.quantity || 0))
    if (!holderId || !detailId || !projectId || !Number.isInteger(availableTimes) || availableTimes < 0 || quantity < 1) {
      return {
        code: 'CHECKOUT_ENTITLEMENT_SNAPSHOT_INCOMPLETE',
        message: '当前页面的权益快照不完整，请返回购物车重新选择权益。'
      }
    }
    const key = `${holderId}:${detailId}:${projectId}`
    const current = usageBySource.get(key) || { availableTimes, quantity: 0 }
    // 同一权益在本次快照中的可用次数必须一致；不接受混合不同投影的数据。
    if (current.availableTimes !== availableTimes) {
      return {
        code: 'CHECKOUT_ENTITLEMENT_SNAPSHOT_INCONSISTENT',
        message: '当前页面的权益快照不一致，请返回购物车重新选择权益。'
      }
    }
    current.quantity += quantity
    usageBySource.set(key, current)
  }
  for (const source of usageBySource.values()) {
    if (source.quantity > source.availableTimes) {
      return {
        code: 'CHECKOUT_ENTITLEMENT_TIMES_EXCEEDED',
        message: `当前页面权益快照可用 ${source.availableTimes} 次，本次已选择 ${source.quantity} 次，请调整后再结账。`
      }
    }
  }
  return null
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
  const result = await mutateCashierDraft(
    'change-cart-line-quantity',
    line,
    { delta: nextQuantity - currentQuantity }
  )
  if (['failed', 'conflict'].includes(resultStatus(result))) {
    const authoritativeLine = cartLines.value.find((candidate) => String(candidate?.id || '') === String(line?.id || ''))
    event.target.value = String(Math.max(1, Number(authoritativeLine?.quantity || currentQuantity)))
    reportCartQuantityFailure(result, '购物车数量更新失败，请重试。')
  } else {
    cartQuantityValidationError.value = ''
  }
  return result
}

function localCheckoutPaymentMethods() {
  return [
    ['unionpay', '银联'], ['wechat', '微信'], ['alipay', '支付宝'],
    ['dianping_voucher', '大众验券'], ['douyin_voucher', '抖音验券'],
    ['partner_collection', '合作方收款'], ['other_collection', '其他收款']
  ].map(([id, name]) => ({ id, name, canAdd: true }))
}

function localCheckoutPreviewSnapshot() {
  // 行服务设置和人员分配分别保存在浏览器编辑态。只在“立即结账”这
  // 一个入口把它们合并进本次结账草稿；选择按钮本身不能触发服务端写入。
  // Recalculate on a clone so the preview contains the latest UI values even
  // when the last interaction was a service-setting toggle with no cart command.
  const draft = recalculateLocalCashierDraft(
    clonePlain(localCashierDraft.value || localDraftBase())
  )
  const checkoutProjection = clonePlain(cashier.value.checkout || {})
  const lines = (Array.isArray(draft.lines) ? draft.lines : []).map((line) => {
    const projectLine = isProjectLine(line)
    const saleLine = cartLineRole(line) === 'sale'
    const entitlementLine = cartLineRole(line) === 'entitlement_service'
    const localPersonnel = localPersonnelAssignments.value[String(line?.id || '')] || {}
    const craftsmen = Array.isArray(localPersonnel.craftsmen)
      ? localPersonnel.craftsmen
      : (Array.isArray(line.craftsmen) ? line.craftsmen : [])
    const salespeople = Array.isArray(localPersonnel.salespeople)
      ? localPersonnel.salespeople
      : (Array.isArray(line.salespeople) ? line.salespeople : [])
    const guideSelections = Array.isArray(localPersonnel.guideSelections)
      ? localPersonnel.guideSelections
      : (Array.isArray(line.guideSelections) ? line.guideSelections : [])
    const salesManagerSelections = Array.isArray(localPersonnel.salesManagerSelections)
      ? localPersonnel.salesManagerSelections
      : (Array.isArray(line.salesManagerSelections) ? line.salesManagerSelections : [])
    // Read the service-object controls from the current browser cart at the
    // snapshot boundary. This prevents a stale projected line field from
    // replacing a just-selected 本人/朋友/朋友算口径.
    return {
      ...line,
      // Keep card-upgrade intent explicit across the local preview boundary;
      // the final submit transaction materializes it before settlement.
      ...(isRecord(line.cardOperationUpgrade)
        ? { cardOperationUpgrade: clonePlain(line.cardOperationUpgrade) }
        : {}),
      ...(isRecord(line.localCardOperation)
        ? { localCardOperation: clonePlain(line.localCardOperation) }
        : {}),
      // Both sale-project and entitlement rows cross the same immutable
      // snapshot boundary. Normalize staff identities here so browser-local
      // string IDs cannot reach the final authority as invalid staffId values.
      ...(saleLine || entitlementLine
        ? {
            craftsmen: entitlementLine
              ? canonicalCheckoutEntitlementCraftsmen(craftsmen)
              : canonicalCheckoutCraftsmen(craftsmen)
          }
        : {}),
      ...(saleLine
        ? {
            salespeople: canonicalCheckoutSalespeople(salespeople),
            guideSelections: canonicalCheckoutAttributions(guideSelections, true),
            salesManagerSelections: canonicalCheckoutAttributions(salesManagerSelections)
          }
        : {}),
      // These switches live in the browser until final confirmation. Freeze
      // their effective values in the preview rather than relying on an
      // earlier draft merge that a later projection could overwrite.
      ...(saleLine && projectLine
        ? { isExperience: cartLineExperienceSelected(line) }
        : {}),
      ...(saleLine && isInventoryManagedProductLine(line)
        ? {
            isPresale: cartLinePresaleSelected(line),
            inventoryOutboundRequired: cartLineInventoryOutboundRequired(line)
          }
        : {}),
      ...(projectLine
        ? {
            serviceObject: cartLineServiceObject(line),
            friendCountsAsCustomer: cartLineFriendCountsAsCustomer(line)
          }
        : {})
    }
  })
  const composition = clonePlain(draft.checkoutComposition || {})
  const customerMode = currentCustomerMode.value
  const checkoutSourceProjection = customerMode === 'guest' ? {} : checkoutProjection
  const hasSale = lines.some((line) => String(line?.lineRole || '') === 'sale')
  const hasEntitlement = lines.some((line) => String(line?.lineRole || '') === 'entitlement_service')
  const hasCardOperation = lines.some((line) => isRecord(line?.localCardOperation))
  const primaryAction = hasSale && hasEntitlement
    ? 'collect_and_complete'
    : (hasEntitlement
      ? 'complete_service'
      : (hasSale ? 'collect_payment' : 'complete_card_operation'))
  const primaryActionLabel = {
    collect_payment: '确认收款', complete_service: '确认完成服务',
    collect_and_complete: '收款并完成服务', complete_card_operation: '确认卡操作'
  }[primaryAction]
  const receivableAmount = checkoutSnapshotReceivableAmount(lines)
  const debtAmount = centsToMoney(lines.reduce((total, line) => (
    total + lineDebtAmountCents(line)
  ), 0))
  return {
    localDraftPreview: true,
    status: 'editing',
    requestStatus: 'editing',
    businessType: 'sale',
    member: clonePlain(member.value || {}),
    memberId: Number(draft.memberId || currentMemberId.value || 0),
    customerMode,
    // This is fixed when the user opens checkout. Final confirmation must not
    // replace the snapshot's business time with submission-time clock data.
    occurredAt: Math.floor(Date.now() / 1000),
    businessDate: String(localCheckoutBusinessDate.value || cashier.value.checkout?.businessDate || cashierToday || ''),
    businessDateReason: String(localCheckoutBusinessDateReason.value || cashier.value.checkout?.businessDateReason || ''),
    sourceEnabled: checkoutProjection.sourceEnabled !== false,
    sourceSelectable: checkoutProjection.sourceSelectable !== false,
    primarySourceId: Number(localCheckoutBusinessSource.value.primarySourceId || checkoutSourceProjection.primarySourceId || 0),
    secondarySourceId: Number(localCheckoutBusinessSource.value.secondarySourceId || checkoutSourceProjection.secondarySourceId || 0),
    rewardAmountCents: Number(localCheckoutBusinessSource.value.rewardAmountCents || checkoutSourceProjection.rewardAmountCents || 0),
    // This is the one browser-owned checkout snapshot. Payment selections are
    // appended to this same array below; do not create a parallel authority
    // for the confirmation screen.
    lines,
    summary: clonePlain(draft.summary || {}),
    orderSummary: clonePlain(draft.summary || {}),
    // The preview deliberately has no server checkout request yet.  These
    // values make its third step a complete local projection; the final click
    // replaces it with the authoritative checkout snapshot before submission.
    debtAmount,
    debtAmountCents: moneyToCents(debtAmount),
    cashPerformanceAmount: 0,
    balancePaymentAmount: 0,
    finalChanges: [],
    composition: {
      ...composition,
      lineRoles: [hasSale && 'sale', hasEntitlement && 'entitlement_service', hasCardOperation && 'card_operation'].filter(Boolean),
      hasSale, hasEntitlement, hasCardOperation, primaryAction, primaryActionLabel,
      steps: [
        { key: 'order', number: 1, label: hasEntitlement ? '确认本次内容' : '确认订单' },
        ...(hasSale ? [{ key: 'payment', number: 2, label: '收款信息' }] : []),
        { key: 'final', number: 3, label: primaryActionLabel },
        { key: 'result', number: 4, label: '处理结果' }
      ]
    },
    payment: {
      methods: localCheckoutPaymentMethods(),
      availableBalance: Number(checkoutProjection.payment?.availableBalance || checkoutProjection.availableBalance || 0)
    }
  }
}

function checkoutSourceSnapshot(primarySourceId, secondarySourceId, rewardAmountCents) {
  const roots = Array.isArray(checkoutInlineBusinessSources.value)
    ? checkoutInlineBusinessSources.value
    : []
  const primary = roots.find((item) => Number(item?.id || 0) === Number(primarySourceId || 0)) || null
  const children = Array.isArray(primary?.children) ? primary.children : []
  const secondary = children.find((item) => Number(item?.id || 0) === Number(secondarySourceId || 0)) || null
  const primaryNameSnapshot = String(primary?.name || '')
  const secondaryNameSnapshot = String(secondary?.name || '')
  return {
    primarySourceId: Number(primarySourceId || 0),
    primarySourceNameSnapshot: primaryNameSnapshot,
    secondarySourceId: Number(secondarySourceId || 0),
    secondarySourceNameSnapshot: secondaryNameSnapshot,
    displayNameSnapshot: [primaryNameSnapshot, secondaryNameSnapshot].filter(Boolean).join(' / '),
    rewardAmountCents: Math.max(0, Number(rewardAmountCents || 0))
  }
}

function customCardPurchaseSnapshot(configuration = {}) {
  const validityEnd = String(configuration.validityEnd || '').trim()
  const expiryAt = Date.parse(`${validityEnd}T23:59:59+08:00`)
  const components = Array.isArray(configuration.components) ? configuration.components : []
  return {
    sourceKind: 'custom_card',
    cardName: String(configuration.cardName || '').trim(),
    activateOnPurchase: configuration.activateOnPurchase === true,
    validity: {
      writeValid: 3,
      writeDays: 0,
      writeStart: 0,
      writeEnd: Number.isFinite(expiryAt) ? Math.floor(expiryAt / 1000) : 0
    },
    components: components.map((component) => ({
      skuId: Number(component?.skuId || 0),
      writeTimes: Number(component?.times || 0),
      configuredAmountCents: Math.max(0, Number(component?.amount || 0)) * 100
    }))
  }
}

function checkoutCardPurchaseSnapshot(line = {}) {
  if (isCustomCardPurchase(line)) {
    // Custom cards have no catalogue card definition. Carry the selections
    // made in the browser line into the one final checkout snapshot.
    return customCardPurchaseSnapshot(
      line.localCustomCardConfiguration || line.customCardConfiguration || {}
    )
  }
  const embedded = line.cardPurchaseSnapshot || line.authoritySnapshot?.cardPurchase
  if (isRecord(embedded) && Object.keys(embedded).length) return clonePlain(embedded)
  const itemId = line.catalogItemId ?? line.itemId ?? line.skuId ?? line.productId
  const currentItem = (Array.isArray(catalog.value.items) ? catalog.value.items : [])
    .find((item) => String(item?.id || '') === String(itemId || ''))
  return clonePlain(currentItem?.cardPurchaseSnapshot || {})
}

function buildCheckoutSnapshot(preview = {}) {
  const snapshot = clonePlain(preview)
  delete snapshot.localDraftPreview
  // The final request boundary is authoritative for browser-local personnel
  // values. Re-normalize here as well as in the preview builder so recovered
  // or hot-reloaded previews cannot reintroduce string staff IDs.
  const snapshotLines = Array.isArray(snapshot.lines)
    ? snapshot.lines
    : (Array.isArray(snapshot.orderLines) ? snapshot.orderLines : [])
  const lines = checkoutSnapshotBusinessLines(snapshotLines).map((line) => {
    const role = cartLineRole(line)
    if (role === 'card_operation') {
      // Standalone operations deliberately have no sale amount. Preserve only
      // their browser-selected intent for the one final submit transaction.
      const operation = isRecord(line.localCardOperation)
        ? clonePlain(line.localCardOperation)
        : null
      return operation ? { ...line, localCardOperation: operation } : { ...line }
    }
    if (role !== 'sale' && role !== 'entitlement_service') return { ...line }
    const upgradeOperation = role === 'sale' && isLocalUpgradeOperationLine(line)
    const next = {
      ...line,
      craftsmen: role === 'entitlement_service'
        ? canonicalCheckoutEntitlementCraftsmen(line.craftsmen)
        : canonicalCheckoutCraftsmen(line.craftsmen),
      ...(upgradeOperation
        ? { cardOperationUpgrade: clonePlain(line.cardOperationUpgrade) }
        : {}),
      ...(upgradeOperation
        ? { localCardOperation: clonePlain(line.localCardOperation) }
        : {})
    }
    ;[
      'version', 'revision', 'recordVersion', 'productVersion', 'skuVersion',
      'sourceVersion', 'projectVersion', 'entitlementSourceVersion',
      'memberBenefitPoolVersion', 'cardHolderVersion', 'detailVersion',
      'amountSourceVersion', 'sourceSelectionVersion'
    ].forEach((key) => { delete next[key] })
    if (role === 'sale') {
      // Cart sale rows keep a unit amount for local editing. The final
      // snapshot carries immutable line totals so settlement cannot mistake
      // a unit price for the whole line when quantity is greater than one.
      next.lineAmountCents = localDraftLineTotalAmountCents(line, getLineAmount(line))
      next.originalLineAmountCents = localDraftLineTotalAmountCents(
        line,
        line.originalAmount ?? getLineAmount(line)
      )
      next.salespeople = canonicalCheckoutSalespeople(line.salespeople)
      next.guideSelections = canonicalCheckoutAttributions(line.guideSelections, true)
      next.salesManagerSelections = canonicalCheckoutAttributions(line.salesManagerSelections)
      // Repeat the browser-owned switches at the final HTTP boundary. This
      // makes the submitted snapshot independent of intermediate preview
      // object merges and keeps "not outbound" out of stock settlement.
      if (isProjectLine(line)) next.isExperience = cartLineExperienceSelected(line)
      if (isInventoryManagedProductLine(line)) {
        next.isPresale = cartLinePresaleSelected(line)
        next.inventoryOutboundRequired = cartLineInventoryOutboundRequired(line)
      }
      if (isCustomCardPurchase(line)) {
        // Custom cards issue card rights; they are never physical stock sales.
        next.isPresale = false
        next.inventoryOutboundRequired = false
      }
      // Card components, validity and rule values are business data selected
      // on the page. Freeze them now; final issuance must not reload a newer
      // catalogue definition after the checkout wizard has opened.
      next.cardPurchaseSnapshot = checkoutCardPurchaseSnapshot(line)
    }
    return next
  })
  // A pure entitlement checkout has no collection phase. Keep only payment
  // rows that already live in this one browser snapshot; no payment summary
  // or selected-lines side channel may influence final submission.
  const hasSaleLines = lines.some((line) => cartLineRole(line) === 'sale')
  const zeroReceivableSale = hasSaleLines
    && moneyToCents(checkoutSnapshotReceivableAmount(lines)) === 0
  const paymentLines = hasSaleLines
    ? checkoutSnapshotPaymentLines(snapshotLines)
      // For a normal receivable, a newly added zero-value method is only an
      // editable placeholder.  For an exactly-zero sale, however, the method
      // selected by the cashier is required bookkeeping evidence: retain it
      // in the final snapshot so the server can validate the route without
      // creating a zero-value collection or payment fact.
      .filter((line) => Number(line?.amount || 0) > 0 || (
        zeroReceivableSale
        && checkoutLineRole(line) === 'payment'
        && String(line?.method || '').trim() !== ''
      ))
      .map((line) => ({
        ...clonePlain(line),
        lineRole: checkoutLineRole(line),
        quantity: 1
      }))
    : []
  const sourceSnapshot = isRecord(snapshot.source)
    ? clonePlain(snapshot.source)
    : checkoutSourceSnapshot(
        snapshot.primarySourceId,
        snapshot.secondarySourceId,
        snapshot.rewardAmountCents
      )
  const finalSnapshot = {
    businessType: String(snapshot.businessType || ''),
    // Debt repayment projections expose the member identity under `member`;
    // freeze that same identity into the one final browser snapshot so the
    // server can bind the payment lines to the selected member.
    memberId: Number(snapshot.memberId || snapshot.member?.id || snapshot.member?.memberId || 0),
    customerMode: String(snapshot.customerMode || 'guest'),
    occurredAt: Number(snapshot.occurredAt || 0),
    businessDate: String(snapshot.businessDate || ''),
    businessDateReason: String(snapshot.businessDateReason || ''),
    lines: [...lines, ...paymentLines],
    sourceDocument: isRecord(snapshot.sourceDocument)
      ? clonePlain(snapshot.sourceDocument)
      : undefined,
    source: sourceSnapshot,
    orderNote: String(snapshot.orderNote || ''),
    supplement: clonePlain(snapshot.supplement || {})
  }
  // Never forward generated projection/version metadata from any nested
  // browser object. The final command carries business values only; current
  // authority versions are discovered inside the settlement transaction.
  const stripGeneratedMetadata = (value) => {
    if (Array.isArray(value)) return value.map(stripGeneratedMetadata)
    if (!isRecord(value)) return value
    const output = {}
    for (const [key, nested] of Object.entries(value)) {
      // These values are generated by the old persisted-draft/projection
      // protocol. They are never business intent and must not cross the one
      // browser-snapshot boundary, including when an older tab still sends
      // camelCase or snake_case aliases.
      const normalizedKey = String(key).replace(/[_-]/g, '')
      if (/(?:version|revision|token|commandcontexts|resumeonload|recoveryready|preparationready|snapshotready)$/i.test(normalizedKey)
        || /^(?:checkoutrequestid|preparationrequestid|statecontextid|staterevision|workspaceid|requeststatus)$/i.test(normalizedKey)) continue
      output[key] = stripGeneratedMetadata(nested)
    }
    return output
  }
  return stripGeneratedMetadata(finalSnapshot)
}

async function openCheckout() {
  const craftsmenRequiredLine = firstCartLineMissingCraftsmen()
  if (craftsmenRequiredLine) {
    const blocked = {
      result: {
        status: 'failed',
        code: 'CASHIER_CRAFTSMAN_REQUIRED',
        message: '请先选择手艺人'
      }
    }
    reportCheckoutEntryFailure(blocked, '请先选择手艺人')
    return blocked
  }
  if (previewCardOperation.value) {
    const operation = previewCardOperation.value
    if (!operation.sources?.length || !operation.target) {
      reportEntitlementContractError({ message: cardOperationTargetPrompt(operation.mode) })
      return
    }
    // Card operations are browser-owned intents. Confirming the operation
    // only appends its displayed values to the local cart; it must then enter
    // the same checkout snapshot flow as every other cashier action.
    const staged = await confirmPreviewCardOperation()
    if (['failed', 'conflict'].includes(resultStatus(staged))) return staged
  }
  if (!hasCartLines.value) {
    return { result: { status: 'failed', code: 'CASHIER_CART_EMPTY', message: '请先添加需要结算或服务的项目。' } }
  }
  if (currentCustomerMode.value === 'guest' && cartLines.value.some(lineHasCustomerAttribution)) {
    reportGuestAttributionBlocked()
    return { result: { status: 'failed', code: 'GUEST_ATTRIBUTION_NOT_ALLOWED', message: guestAttributionBlockedMessage } }
  }
  // The checkout wizard is a browser-only projection. The first write for this
  // order is the final submit-checkout carrying the complete snapshot.
  const preview = localCheckoutPreviewSnapshot()
  const entitlementFailure = localCheckoutEntitlementAvailabilityFailure(preview)
  if (entitlementFailure) {
    const blocked = { result: { status: 'failed', ...entitlementFailure } }
    reportCheckoutEntryFailure(blocked, entitlementFailure.message)
    return blocked
  }
  localCheckoutPreview.value = preview
  // Keep the exact page rows before any checkout-step mutation can replace or
  // clear the workbench projection. The result-page receipt is a pure render
  // of this browser snapshot; it must never depend on a follow-up order query.
  checkoutReceiptSnapshot.value = clonePlain(salesOrderReceiptFromCheckout(preview))
  localCheckoutPaymentOperations.value = []
  checkoutLocalOutcome.value = {}
  checkoutRecoveryActiveStep.value = null
  isCheckoutOpen.value = true
  return localDraftResult()
}

function guardedOpenCheckout(event) {
  // 事件入口再次以页面当前显示内容为准，确保不会先进入结账弹层。
  const craftsmenRequiredLine = firstCartLineMissingCraftsmen()
  if (craftsmenRequiredLine) {
    event?.preventDefault?.()
    event?.stopImmediatePropagation?.()
    const blocked = {
      result: {
        status: 'failed',
        code: 'CASHIER_CRAFTSMAN_REQUIRED',
        message: '请先选择手艺人'
      }
    }
    reportCheckoutEntryFailure(blocked, '请先选择手艺人')
    return blocked
  }
  return openCheckout()
}

async function openHangOrder() {
  if (!hasCartLines.value || isSavingHangDraft.value) {
    return { result: { status: 'failed', code: 'CASHIER_CART_EMPTY', message: '请先添加需要挂单的项目。' } }
  }
  // 挂单是纯草稿保存：不创建服务、不占用或校验房间，也不重做目录、
  // 库存、权益、价格等结账校验。从房间进入时只把房间写进草稿关联。
  isSavingHangDraft.value = true
  try {
    const hasLocalOperations = localCashierDraftOperations.value.length > 0
    const hasPersistedLines = hasLocalOperations && localDraftContainsPersistedCartLines()
    if (hasPersistedLines) {
      const synchronized = await synchronizeLocalCashierDraft()
      if (!['success', 'succeeded'].includes(resultStatus(synchronized))) return synchronized
    }
    const remainingLocalOperations = localCashierDraftOperations.value.length > 0
    const localDraft = remainingLocalOperations
      ? {
          ...clonePlain(localCashierDraft.value || localDraftBase()),
          operations: clonePlain(localCashierDraftOperations.value)
        }
      : null
    if (!remainingLocalOperations) {
    if (!await persistDeferredLineServiceSettings()) {
      return { result: { status: 'failed', code: 'CASHIER_LINE_SERVICE_SETTINGS_SAVE_FAILED', message: '本次服务设置保存失败，请重试。' } }
    }
      const synchronized = await synchronizeLocalCashierDraft()
      if (!['success', 'succeeded'].includes(resultStatus(synchronized))) return synchronized
    }
    const intent = roomOpenIntent.value
    const saved = await saveHangDraft({
      stateContextId: String(state.stateContextId || ''),
      idempotencyKey: createCashierV3CommandId('HANG_DRAFT'),
      mode: 'normal',
      roomId: intent?.roomId || '',
      roomNameSnapshot: intent?.roomName || '',
      roomVersion: intent?.roomVersion || 0,
      roomTimeSlotId: intent?.roomTimeSlotId || '',
      roomTimeSlotVersion: intent?.roomTimeSlotVersion || 0,
      ...(localDraft ? { localDraft } : {})
    })
    const draft = saved?.cashierDraft
    if (!await applyCommittedCashierDraft(draft, currentCashierDraftScopeKey.value)) {
      throw new Error('挂单已保存，但购物车清空状态尚未完整返回，请刷新收银台。')
    }
    cashierDraftHasUnresolvedCommand.value = false
    localCashierDraftOperations.value = []
    localCashierPersistedLineIds.value = {}
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

async function finalizeLocalCheckoutPreview(event = {}) {
  const preview = localCheckoutPreview.value
  // 欠款补交仍需保留这份服务端交接快照直到提交返回，以便最终动作被
  // 正确路由到 submit-debt-repayment；不能在发送前清空并误走普通销售。
  try {
    const checkoutSnapshot = buildCheckoutSnapshot(preview || {})
    // Freeze the printable projection before the single settlement request.
    // This also covers successful responses whose envelope does not expose a
    // printable line list; the lines are already the values shown in step 2.
    checkoutReceiptSnapshot.value = clonePlain(
      salesOrderReceiptFromCheckout({
        ...checkoutSnapshot,
        lines: Array.isArray(preview?.lines) ? preview.lines : checkoutSnapshot.lines,
        payment: preview?.payment || checkoutSnapshot.payment
      })
    )
    const receivableCents = moneyToCents(checkoutSnapshotReceivableAmount(checkoutSnapshot.lines))
    const selectedCents = checkoutSnapshotPaymentLines(checkoutSnapshot.lines).reduce(
      (total, line) => total + moneyToCents(line?.amount),
      0
    )
    if (receivableCents !== selectedCents) {
      const blocked = {
        result: {
          status: 'failed',
          code: 'CHECKOUT_PAYMENT_AMOUNT_UNBALANCED',
          message: '本次应收与收款金额不一致，请返回收款信息调整后再确认。'
        }
      }
      event?.resolve?.(blocked)
      return blocked
    }
    const synchronized = localDraftResult()
    if (!['success', 'succeeded'].includes(resultStatus(synchronized))) {
      event?.resolve?.(synchronized)
      return synchronized
    }
    // The final button is the first and only HTTP write for this local
    // checkout. The complete browser snapshot, including payment rows, is
    // consumed by the server in the same transaction as settlement.
    const submitted = await requestCheckoutAction({
      action: 'submit-checkout',
      payload: {
        ...(isRecord(event?.payload) ? event.payload : {}),
        checkoutSnapshot
      }
    })
    if (['success', 'succeeded'].includes(resultStatus(submitted))) {
      // Keep a presentation projection beside the exact command snapshot.
      // buildCheckoutSnapshot intentionally strips UI/projection metadata for
      // the server, but the result page still needs the submitted sale,
      // service and payment rows immediately for printing.
      const previewLines = Array.isArray(preview?.lines) ? preview.lines : []
      const fallbackLines = Array.isArray(checkout.value?.orderLines)
        ? checkout.value.orderLines
        : (Array.isArray(cartLines.value) ? cartLines.value : [])
      const receiptPreview = {
        ...preview,
        // A hot-reloaded or legacy projection can omit `lines` from the local
        // preview while the visible cashier cart still has the same rows.
        // Use that already-rendered page value only as a print projection;
        // never substitute it into the submitted business snapshot.
        lines: previewLines.length ? previewLines : fallbackLines
      }
      checkoutReceiptSnapshot.value = clonePlain({
        ...checkoutSnapshot,
        ...salesOrderReceiptFromCheckout(receiptPreview)
      })
    }
    event?.resolve?.(submitted)
    return submitted
  } finally {
    if (preview && !checkoutRequiresRootReload.value) {
      localCheckoutPreview.value = preview
      isCheckoutOpen.value = true
    }
  }
}

function localCheckoutPaymentAmount(value) {
  const raw = String(value ?? '').trim()
  return /^(?:0|[1-9]\d*)$/.test(raw) ? raw : null
}

function enqueueCheckoutAction(event = {}) {
  const action = String(event?.action || '')
  if (localCheckoutPreview.value?.localDraftPreview === true) {
    const payload = isRecord(event?.payload) ? clonePlain(event.payload) : {}
    const preview = clonePlain(localCheckoutPreview.value)
    const payment = preview.payment || { methods: [] }
    const checkoutLines = Array.isArray(preview.lines) ? preview.lines : []
    const lines = checkoutSnapshotPaymentLines(checkoutLines)
    const receivable = checkoutSnapshotReceivableAmount(checkoutLines)
    if (action === 'add-payment-method') {
      const method = (payment.methods || []).find((item) => String(item?.id || '') === String(payload.paymentMethodId || ''))
      if (!method || lines.some((line) => String(line.method || '') === String(method.id))) {
      const result = { result: { status: 'failed', message: '请选择未重复的收款方式。' } }
        event?.resolve?.(result)
        return result
      }
      // No collection method is selected by default. When the cashier chooses
      // one, assign this browser snapshot's current remaining amount so a
      // single-method payment can proceed without retyping the receivable.
      const selectedCollectionAmount = lines
        .filter((line) => checkoutLineRole(line) === 'payment')
        .reduce(
        (total, line) => total + Math.max(0, Number(line.amount || 0)),
        0
      )
      const balancePaymentAmount = lines
        .filter((line) => checkoutLineRole(line) === 'balance_payment')
        .reduce((total, line) => total + Math.max(0, Number(line.amount || 0)), 0)
      const remainingAmount = Math.max(0, receivable - selectedCollectionAmount - balancePaymentAmount)
      checkoutLines.push({ id: `local-payment-${Date.now()}`, lineRole: 'payment', method: method.id, name: method.name, amount: remainingAmount, quantity: 1, status: 'editing', canEdit: true, canRemove: true })
    } else if (action === 'remove-payment-line') {
      preview.lines = checkoutLines.filter((line) => String(line.id) !== String(payload.paymentLineId || ''))
    } else if (action === 'update-payment-line') {
      const line = lines.find((item) => String(item.id) === String(payload.paymentLineId || ''))
      if (line) Object.assign(line, { amount: Number(payload.amount || 0), externalTransactionNo: String(payload.externalTransactionNo || ''), remark: String(payload.remark || '') })
    } else if (action === 'update-checkout-business-source') {
      // 来源是结账预览的一部分。这里只更新浏览器内快照，最终确认时由
      // finalizeLocalCheckoutPreview 随完整 checkoutSnapshot 一次性发送。
      payment.primarySourceId = Number(payload.primarySourceId || 0)
      payment.secondarySourceId = Number(payload.secondarySourceId || 0)
      payment.rewardAmountCents = Math.max(0, Number(payload.rewardAmountCents || 0))
      preview.primarySourceId = payment.primarySourceId
      preview.secondarySourceId = payment.secondarySourceId
      preview.rewardAmountCents = payment.rewardAmountCents
      localCheckoutBusinessSource.value = {
        primarySourceId: payment.primarySourceId,
        secondarySourceId: payment.secondarySourceId,
        rewardAmountCents: payment.rewardAmountCents
      }
      publishToolbarCheckoutContext()
    } else if (action === 'open-balance-payment') {
      const available = Math.max(0, Number(payment.availableBalance || 0))
      const selectedAmount = lines
        .filter((line) => checkoutLineRole(line) === 'payment')
        .reduce((total, line) => total + Math.max(0, Number(line.amount || 0)), 0)
      const amount = Math.min(available, Math.max(0, receivable - selectedAmount))
      preview.lines = checkoutLines.filter((line) => checkoutLineRole(line) !== 'balance_payment')
      if (amount > 0) {
        preview.lines.push({
          id: 'balance-deduction', lineRole: 'balance_payment', kind: 'balance_deduction',
          name: '余额支付', amount, quantity: 1, status: 'editing', canEdit: true, canRemove: true
        })
      }
    } else if (action === 'remove-balance-payment') {
      preview.lines = checkoutLines.filter((line) => checkoutLineRole(line) !== 'balance_payment')
    } else if (action === 'update-checkout-sales-date') {
      preview.businessDate = String(payload.businessDate || '')
      preview.businessDateReason = String(payload.reason || '')
      localCheckoutBusinessDate.value = preview.businessDate
      localCheckoutBusinessDateReason.value = preview.businessDateReason
      publishToolbarCheckoutContext()
    } else if (action === 'submit-checkout') {
      return finalizeLocalCheckoutPreview(event)
    } else if ([
      'checkout-step-back',
      'checkout-step-next',
      'toggle-combination-payment',
      'confirm-debt-warning',
      'confirm-checkout-final-changes',
      'return-to-payment-edit',
      'finish-checkout-and-return'
    ].includes(action)) {
      // These are overlay navigation/acknowledgement actions only. They must
      // never create or revise a server checkout draft; the current browser
      // preview remains the sole source until final submit-checkout.
      const result = localDraftResult()
      event?.resolve?.(result)
      return result
    } else {
      const result = { result: { status: 'failed', message: '该收款操作将在确认收款时按最新结账单处理。' } }
      event?.resolve?.(result)
      return result
    }
    // The final-confirmation screen reads the top-level checkout snapshot.
    // Keep that projection in lockstep with the payment rows which will be
    // submitted as this same browser snapshot. Balance payment is a separate
    // settlement method and must not be included in cash performance.
    const checkoutPaymentLines = checkoutSnapshotPaymentLines(preview.lines || [])
    const cashPerformanceAmount = checkoutPaymentLines
      .filter((line) => checkoutLineRole(line) === 'payment')
      .reduce(
      (total, line) => total + Math.max(0, Number(line.amount || 0)),
      0
    )
    const balancePaymentAmount = checkoutPaymentLines
      .filter((line) => checkoutLineRole(line) === 'balance_payment')
      .reduce((total, line) => total + Math.max(0, Number(line.amount || 0)), 0)
    preview.cashPerformanceAmount = cashPerformanceAmount
    preview.balancePaymentAmount = balancePaymentAmount
    payment.cashPerformanceAmount = cashPerformanceAmount
    preview.payment = payment
    localCheckoutPreview.value = preview
    localCheckoutPaymentOperations.value = [...localCheckoutPaymentOperations.value, { action, payload }]
    const result = localDraftResult()
    // CashierCheckoutOverlay uses this receipt to release its temporary
    // “添加中” and amount-input states.  Local preview mutations have no
    // server response, so provide the same successful receipt immediately.
    window.dispatchEvent(new CustomEvent('cashier-v3:checkout-draft-mutation-result', {
      detail: { action, payload, status: 'succeeded', message: '' }
    }))
    event?.resolve?.(result)
    return result
  }
  const result = {
    result: {
      status: 'failed',
      code: 'CHECKOUT_SNAPSHOT_REQUIRED',
      message: '结账编辑只保留在前端，必须通过最终快照一次性确认收款。'
    }
  }
  event?.resolve?.(result)
  return result
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

  // The browser-owned checkout contract has one submission shape only. A
  // submit/retry without the complete snapshot is an obsolete persisted-draft
  // path; stop it locally instead of manufacturing request versions/tokens or
  // issuing a prepare/recovery request.
  if (['submit-checkout', 'retry-checkout'].includes(action)
    && !isRecord(payload?.checkoutSnapshot)) {
    return {
      result: {
        status: 'failed',
        code: 'CHECKOUT_SNAPSHOT_REQUIRED',
        message: '结账必须使用当前页面生成的唯一快照，请返回收银页重新点击立即结账。'
      }
    }
  }

  // The visible balance button applies the server-calculated maximum to this
  // draft. It does not move money; submit-checkout revalidates and debits the
  // same authoritative member balance inside its final transaction.
  if (action === 'open-balance-payment') action = 'apply-balance-payment'

  if (action === 'open-checkout-source-selector') return openCheckoutBusinessSourceSelector('sale')

  // Local preview submissions have no checkout session or server draft by
  // design. They bypass the follow-up command context builder and send the
  // immutable snapshot directly to the final settlement action.
  if (action === 'submit-checkout'
    && isRecord(payload?.checkoutSnapshot)
    && String(payload.checkoutSnapshot.businessType || '') !== 'debt_repayment') {
    const direct = await requestAction('submit-checkout', {
      ...(isRecord(payload) ? payload : {}),
      idempotencyKey: String(payload?.idempotencyKey || createCashierV3CommandId('CHECKOUT'))
    })
    const submitted = responseDataBlock(direct).checkoutSubmission
    const submittedOrder = isRecord(submitted?.salesOrder) ? submitted.salesOrder : {}
    const submittedEntitlement = isRecord(submitted?.entitlementCompletion) ? submitted.entitlementCompletion : {}
    const businessNo = String(
      submittedOrder.orderNo
      || submittedEntitlement.receiptId
      || submitted?.completionReferenceId
      || ''
    )
    if (['success', 'succeeded'].includes(resultStatus(direct)) && businessNo !== '') {
      checkoutRequiresRootReload.value = true
      checkoutLocalOutcome.value = {
        status: 'succeeded',
        message: resultMessage(direct, '结账已完成。'),
        completionDescription: resultMessage(direct, '本单已正式完成，可以继续后续操作。'),
        requestNo: businessNo,
        checkoutRequestId: String(submitted?.checkoutRequestId || ''),
        salesOrderId: String(submittedOrder.orderId || ''),
        salesOrderNo: String(submittedOrder.orderNo || ''),
        originalIdempotencyKey: String(payload?.idempotencyKey || ''),
        canClose: true,
        canRetry: false
      }
    }
    return direct
  }

  // 普通收银不再有服务端结账草稿、准备请求或恢复请求。所有普通销售、
  // 权益、混合和卡操作必须已经走本地快照分支；没有快照时直接阻断，
  // 不允许旧版本/旧投影路径重新进入主流程。
  if (!isDebtRepaymentCheckout.value) {
    return {
      result: {
        status: 'failed',
        code: 'CHECKOUT_SNAPSHOT_REQUIRED',
        message: '结账编辑只保留在前端，必须通过最终快照一次性确认收款。'
      }
    }
  }

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
    const salesOrderId = String(
      payload?.salesOrderId
      || checkoutOverlayState.value.salesOrderId
      || checkout.value.salesOrderId
      || ''
    ).trim()
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
  const debtRepaymentSubmission = isDebtRepaymentCheckout.value
  const current = currentCheckoutCommandContexts(session)
  const debtSnapshotCandidates = [
    localCheckoutPreview.value,
    session?.snapshot,
    checkout.value
  ].filter(isRecord)
  const debtSnapshotFallback = debtSnapshotCandidates.find((candidate) => (
    String(candidate.businessType || '') === 'debt_repayment'
    || String(candidate.sourceDocumentType || '') === 'debt_repayment'
    || String(candidate.sourceDocument?.type || '') === 'debt_repayment'
  )) || null
  const queryCanUseOverlayIdentity = action === 'query-checkout-result'
    && (!session || !current)
  let actionSession = queryCanUseOverlayIdentity
    ? {
        checkoutRequestId: String(payload?.checkoutRequestId || payload?.requestId || checkout.value?.checkoutRequestId || checkout.value?.requestId || ''),
        stateContextId: String(state.stateContextId || ''),
        preparationRequestId: '',
      }
    : session
  let actionCurrent = queryCanUseOverlayIdentity
    ? { checkoutRequestVersion: 0, commandContexts: [] }
    : current
  // The debt wizard keeps editing in localCheckoutPreview. If a hot reload or
  // root projection refresh drops the in-memory session, recover the command
  // identity from that same snapshot instead of declaring it expired.
  if ((!actionSession || !actionCurrent) && debtSnapshotFallback) {
    actionSession = {
      checkoutRequestId: String(checkoutRequestIdentity(debtSnapshotFallback) || ''),
      stateContextId: String(state.stateContextId || ''),
      preparationRequestId: String(debtSnapshotFallback.preparationRequestId || ''),
      preparationToken: String(checkoutPreparationToken(debtSnapshotFallback) || '')
    }
    actionCurrent = {
      checkoutRequestVersion: Number(checkoutRequestVersion(debtSnapshotFallback) || 0),
      commandContexts: Array.isArray(debtSnapshotFallback.commandContexts)
        ? clonePlain(debtSnapshotFallback.commandContexts)
        : []
    }
  }
  if (!actionSession || !actionCurrent) {
    return {
      result: {
        status: 'failed',
        code: 'CHECKOUT_SESSION_EXPIRED',
        message: '本地结账快照未找到，请返回收银页重新点击立即结账。'
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
    checkoutRequestId: actionSession.checkoutRequestId,
    checkoutRequestVersion: actionCurrent.checkoutRequestVersion,
    preparationRequestId: actionSession.preparationRequestId,
    preparationToken: String(
      actionSession.preparationToken
      || checkoutPreparationToken(checkout.value)
      || checkoutPreparationToken(debtSnapshotFallback)
      || ''
    ),
    commandContexts: actionCurrent.commandContexts
  }
  if (checkoutRequestActions.has(action)
    && action !== 'query-checkout-result'
    && !isRechargeDebtRepaymentCheckout.value) {
    // The checkout projection also contains the server-built resource plan.
    // Follow-up commands must not replay that plan as client contexts: their
    // authorities are rebuilt from the persisted checkout request instead.
    const checkoutContexts = isDebtRepaymentCheckout.value
      ? actionCurrent.commandContexts
      : checkoutSubmissionCommandContexts(actionCurrent.commandContexts)
    if (!isDebtRepaymentCheckout.value && !checkoutContexts) {
      return {
        result: {
          status: 'failed',
          code: 'CHECKOUT_REQUEST_CONTEXT_STALE',
          message: '本地结账快照未找到，请返回收银页重新点击立即结账。'
        }
      }
    }
    approvedPayload.commandContexts = checkoutContexts
  }
  if (isRechargeDebtRepaymentCheckout.value && ['submit-checkout', 'retry-checkout'].includes(action)) {
    // Dedicated recharge-debt submission derives member/balance/request locks
    // from these stable identities. Do not send the generic two-context draft
    // subset, which would omit the member balance required by its policy.
    approvedPayload.memberId = checkout.value.member?.id || checkout.value.member?.memberId || ''
    approvedPayload.debtRecordId = checkout.value.sourceDocumentId || ''
    delete approvedPayload.commandContexts
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
  let result = await requestAction(effectiveAction, approvedPayload)
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
      checkoutRequestId: actionSession.checkoutRequestId,
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
      checkoutRequestId: actionSession.checkoutRequestId,
      requestNo: checkout.value.requestNo,
      originalIdempotencyKey,
      queryOnly: true
    })
    response = cashierV3ResponseEnvelope(result)
    resultQueryAttempted = true
  }
  const resultEnvelope = response?.result && typeof response.result === 'object' ? response.result : {}
  const hasAuthoritativeCheckoutState = isRecord(response?.state?.cashier?.checkout)
  const submittedCheckout = responseDataBlock(result).checkoutSubmission
  const submittedCheckoutRequestId = String(submittedCheckout?.checkoutRequestId || '')
  const submittedCheckoutStatus = String(submittedCheckout?.requestStatus || '').toLowerCase()
  const submittedSalesOrder = isRecord(submittedCheckout?.salesOrder)
    ? submittedCheckout.salesOrder
    : null
  const submittedEntitlement = isRecord(submittedCheckout?.entitlementCompletion)
    ? submittedCheckout.entitlementCompletion
    : null
  const submittedBusinessNo = String(
    submittedSalesOrder?.orderNo
      || submittedEntitlement?.receiptId
      || submittedCheckout?.completionReferenceId
      || ''
  )
  const hasCommittedSubmitReceipt = action === 'submit-checkout'
    && !debtRepaymentSubmission
    && submittedCheckoutRequestId !== ''
    && submittedCheckoutRequestId === String(session.checkoutRequestId || '')
    && submittedCheckoutStatus === 'succeeded'
    && submittedBusinessNo !== ''
  if (hasAuthoritativeCheckoutState && !debtRepaymentSubmission) {
    // 命令信封的 success 只表示命令已被可靠处理，不能解释成顾客已经支付成功。
    // 支付领域状态只能由已通过根状态门禁的 cashier.checkout.status 驱动。
    // submit-checkout also returns the committed empty cashier draft. That
    // root state is intentionally no longer a checkout result, so use the
    // same request-bound submission receipt before returning. Otherwise the
    // cart clears while the overlay remains stuck on the final confirmation.
    if (hasCommittedSubmitReceipt) {
      checkoutRequiresRootReload.value = true
      checkoutLocalOutcome.value = {
        status: 'succeeded',
        message: resultEnvelope.message || '结账已完成。',
        completionDescription: resultEnvelope.message || '本单已正式完成，可以继续后续操作。',
        requestNo: submittedSalesOrder?.orderNo || submittedEntitlement?.receiptId || submittedBusinessNo,
        checkoutRequestId: submittedCheckoutRequestId,
        salesOrderId: submittedSalesOrder?.orderId || '',
        salesOrderNo: submittedSalesOrder?.orderNo || '',
        originalIdempotencyKey,
        canClose: true,
        canRetry: false
      }
    } else {
      checkoutLocalOutcome.value = {}
    }
    return result
  }
  // A successful repayment may have committed its business row while the
  // response envelope is rejected for a stale root projection.  Never retry
  // the write in that case: resolve the same request once through the
  // read-only result projection, bound to the original request key.
  let debtRepaymentResult = debtRepaymentSubmission
    ? responseDataBlock(result).debtRepayment
    : null
  if (!isRecord(debtRepaymentResult)
    && debtRepaymentSubmission
    && ['submit-checkout', 'retry-checkout'].includes(action)
    && originalIdempotencyKey
    && actionSession.checkoutRequestId) {
    const queried = await requestAction('query-debt-repayment-result', {
      checkoutRequestId: actionSession.checkoutRequestId,
      originalIdempotencyKey
    })
    const queriedResult = responseDataBlock(queried).debtRepayment
    if (isRecord(queriedResult)) {
      result = queried
      debtRepaymentResult = queriedResult
    }
  }
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
  if (debtRepaymentSubmission && ['submit-checkout', 'retry-checkout'].includes(action)) {
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
        checkoutRequestId: actionSession.checkoutRequestId,
        stateContextId: actionSession.stateContextId
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
  const checkoutFailureMessage = projectedResult?.message
    || (resultQueryAttempted && ['success', 'succeeded'].includes(resultStatus(result))
      ? '结账结果暂时无法完整核验，请继续查询原请求，禁止重复收款。'
      : resultEnvelope.message || response.message || '')
  const craftsmanValidationFailed = status === 'failed'
    && code === 'INVALID_COMMAND_CONTEXT'
    && checkoutFailureMessage.includes('选择手艺人')
  checkoutLocalOutcome.value = {
    status: status === 'conflict' ? 'failed' : status,
    message: checkoutFailureMessage,
    failureReason: checkoutFailureMessage,
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
  if (!requestedServiceOrderId || String(serviceOrder.value?.id || '') !== String(requestedServiceOrderId)) {
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
  // 服务确认只完成服务事实；结账仍从当前页面购物车构造本地快照。
  // 不读取、不恢复任何服务端 checkout_request 或旧版本投影。
  return openCheckout()
}

async function openPreparedCheckout(event = {}) {
  const detail = event.detail || {}
  let snapshot = clonePlain(detail.checkoutSnapshot)
  if (detail.businessType !== 'debt_repayment' && !isCompleteCheckoutPreparation(snapshot, detail.preparationRequestId)) {
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
  // 欠款补交从这里开始只保留一份浏览器快照。把服务端投影的业务行和
  // 已选收款行合并到同一份 lines，后续收款方式、金额编辑和最终提交都
  // 只改这份快照，不再回写或拼接旧 checkout 草稿字段。
  if (snapshot.businessType === 'debt_repayment') {
    const orderLines = Array.isArray(snapshot.lines)
      ? snapshot.lines
      : (Array.isArray(snapshot.orderLines) ? snapshot.orderLines : [])
    const paymentLines = Array.isArray(snapshot.payment?.selectedLines)
      ? snapshot.payment.selectedLines.map((line) => ({
          ...clonePlain(line),
          lineRole: String(line?.lineRole || 'payment'),
          quantity: 1,
          status: String(line?.status || 'editing')
        }))
      : []
    localCheckoutPreview.value = {
      ...snapshot,
      lines: [...clonePlain(orderLines), ...paymentLines],
      source: isRecord(snapshot.source)
        ? clonePlain(snapshot.source)
        : {
            primarySourceId: Number(snapshot.primarySourceId || 0),
            primarySourceNameSnapshot: '',
            secondarySourceId: Number(snapshot.secondarySourceId || 0),
            secondarySourceNameSnapshot: '',
            displayNameSnapshot: String(snapshot.sourceLabel || ''),
            rewardAmountCents: Number(snapshot.rewardAmountCents || 0)
          },
      sourceDocument: isRecord(snapshot.sourceDocument)
        ? clonePlain(snapshot.sourceDocument)
        : {
            type: String(snapshot.sourceDocumentType || 'debt_repayment'),
            id: String(snapshot.sourceDocumentId || ''),
            no: String(snapshot.sourceDocumentNo || '')
          },
      // 收款金额仅在本次会话内编辑；最终提交仍使用上方服务端 request_id
      // 对应的唯一快照，不会创建普通销售草稿或读取根工作台。
      localDraftPreview: true
    }
    localCheckoutPaymentOperations.value = []
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
  entitlementSelectorDraftCheckpoint.value = null
  cashierDraftSnapshot.value = null
  localCashierDraftOperations.value = []
  localCashierPersistedLineIds.value = {}
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
  checkoutReceiptSnapshot.value = null
  localCheckoutBusinessDate.value = cashierToday
  localCheckoutBusinessDateReason.value = ''
  localCheckoutBusinessSource.value = {
    primarySourceId: 0,
    secondarySourceId: 0,
    rewardAmountCents: 0
  }
  checkoutRequiresRootReload.value = false
  localLineServiceSettings.value = {}
  clearEntitlementBoundSnapshots()
  publishToolbarCheckoutContext()
}

async function closeCheckoutOverlay(options = {}) {
  if (options?.clearFailedCheckout === true) {
    // 失败结果页的“清空”与工作台清空共用同一个原子服务端动作：
    // 未完成结账草稿、购物车和本地恢复票据一起释放，避免强刷后旧遮罩再次恢复。
    // 该选项只由子组件在未产生成功收款的失败态发出。
    return confirmClearCart()
  }
  if (checkoutRequiresRootReload.value) {
    checkoutRequiresRootReload.value = false
    window.location.reload()
    return
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
  let resolvedCommittedDraft = committedDraft
  let canRenderCommittedDraft = isResponseBoundCommittedCashierDraft(resolvedCommittedDraft)
  // 某些成功回执只包含订单结果，未附带已清空的工作台投影。结账已经
  // 成功时必须让当前工作台落到空车，不能继续保留可再次提交的旧行。
  if (!canRenderCommittedDraft) {
    try {
      const cleared = await clearCashierDraft(String(state.stateContextId || ''))
      resolvedCommittedDraft = cleared?.cashierDraft
      canRenderCommittedDraft = isResponseBoundCommittedCashierDraft(resolvedCommittedDraft)
    } catch (error) {
      console.warn('Checkout succeeded but the workbench clear receipt was unavailable.', error)
    }
  }
  checkoutRequiresRootReload.value = false
  await closeCheckoutOverlay()
  checkoutReceiptSnapshot.value = null
  // Settlement has already completed. Re-read the same workbench so the
  // cashier never returns to a stale cart that could be submitted again.
  // The draft snapshot is only an optimistic local projection. Once the
  // checkout reaches its success terminal state it can never remain eligible
  // for another submission, even if the following root-state read is delayed.
  cashierDraftSnapshot.value = canRenderCommittedDraft
    ? Object.freeze({
        scopeKey: currentCashierDraftScopeKey.value,
        snapshot: Object.freeze(clonePlain(resolvedCommittedDraft))
      })
    : null
  cashierDraftHasUnresolvedCommand.value = false
  await requestAction('open-cashier-workbench', { silent: true })
}

async function closeSucceededRechargeCheckoutAndRefreshWorkbench() {
  // 充值结账是独立草稿，完成后不会携带收银主工作台投影。先冻结本次
  // 会员身份；随后根工作台刷新和会员摘要查询都不能将其降级成游客。
  const completedMember = clonePlain(rechargeCheckout.value?.member || member.value || {})
  const completedMemberId = Number(
    completedMember.id || completedMember.memberId || currentMemberId.value || 0
  )
  closeRechargeCheckout()
  await requestAction('open-cashier-workbench', { silent: true })
  if (completedMemberId <= 0) return
  const summaryResult = await requestCashierV3Action('query-cashier-member-summary', {
    memberId: completedMemberId,
    // 这只是独立会员摘要，不能用其诊断性根投影覆盖已完成充值后的收银现场。
    preserveRootState: true,
    silent: true
  })
  if (!['success', 'succeeded'].includes(resultStatus(summaryResult))) return
  const summary = responseDataBlock(summaryResult)?.memberSummary
  if (!summary || typeof summary !== 'object') return
  const visibleMemberId = Number(member.value?.id || member.value?.memberId || 0)
  // 用户若在异步刷新期间已经切换到另一位会员，绝不回写本次充值的摘要；
  // 空会员则代表后端投影缺失，恢复本次已确认完成的会员快照。
  if (visibleMemberId > 0 && visibleMemberId !== completedMemberId) return
  state.cashier = {
    ...(state.cashier || {}),
    customerMode: 'member',
    member: {
      ...completedMember,
      ...(member.value || {}),
      ...summary
    }
  }
}

function closeHangOrderOverlay() {
  isHangOrderOpen.value = false
  hangOrderPreparationId.value = null
  hangOrderSession.value = null
}

function applyRestoredHangDraft(detail = {}) {
  const local = detail?.localDraft
  if (isRecord(local) && Array.isArray(local.lines) && Array.isArray(local.operations)) {
    commitLocalCashierDraft(clonePlain(local))
    localCashierDraftOperations.value = clonePlain(local.operations)
    localCashierPersistedLineIds.value = {}
    cashierDraftHasUnresolvedCommand.value = false
    return true
  }
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

function handleToolbarBusinessSourceOpen() {
  void openCheckoutBusinessSourceSelector('sale')
}

function handleToolbarBusinessSourceClear() {
  localCheckoutBusinessSource.value = {
    primarySourceId: 0,
    secondarySourceId: 0,
    rewardAmountCents: 0
  }
  if (localCheckoutPreview.value?.localDraftPreview === true) {
    localCheckoutPreview.value.primarySourceId = 0
    localCheckoutPreview.value.secondarySourceId = 0
    localCheckoutPreview.value.rewardAmountCents = 0
    if (localCheckoutPreview.value.payment && typeof localCheckoutPreview.value.payment === 'object') {
      localCheckoutPreview.value.payment.primarySourceId = 0
      localCheckoutPreview.value.payment.secondarySourceId = 0
      localCheckoutPreview.value.payment.rewardAmountCents = 0
    }
  }
  publishToolbarCheckoutContext()
}

function handleToolbarBusinessDateChange(event) {
  const detail = event?.detail || {}
  void saveCheckoutSalesDate({
    businessDate: detail.businessDate,
    reason: detail.reason || ''
  })
}

watch(
  cashierScopeIdentity,
  (current, previous) => {
    if (!previous || cashierBusinessScopeKey(current) === cashierBusinessScopeKey(previous)) return
    // Selector reads may momentarily return an incomplete member projection.
    // Once that read has completed, a member-to-guest transition is a real
    // checkout reset and stale entitlement data must not remain on screen.
    if (isReadOnlyEntitlementProjectionTransition(current, previous)) {
      return
    }
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

// 会员权益选择是只读投影。它与本地购物车共享同一个根状态通道，迟到的
// 选择器响应不能在同一业务范围内把尚未同步的购买草稿替换掉。
watch(
  () => state.stateRevision,
  async () => {
    const checkpoint = entitlementSelectorDraftCheckpoint.value
    if (!isEntitlementSelectorOpen.value || isSynchronizingLocalCashierDraft.value || !checkpoint) return
    await nextTick()
    if (isEntitlementSelectorOpen.value
      && !isSynchronizingLocalCashierDraft.value
      && entitlementSelectorDraftCheckpoint.value === checkpoint
      && isEntitlementDraftScopeCompatible(checkpoint.scopeKey)) {
      restoreLocalCashierDraftAfterEntitlementSelector(checkpoint)
    }
  },
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
  window.addEventListener('cashier-v3:checkout-business-source-confirmed', handleCheckoutBusinessSourceConfirmed)
  window.addEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosedForEntitlement)
  window.addEventListener('cashier-v3:state-context-changing', resetCashierLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetCashierLocalContext)
  window.addEventListener('cashier-v3:refresh-workbench', refreshWorkbenchAfterContextConflict)
  window.addEventListener('cashier-v3:hang-draft-restored', handleRestoredHangDraft)
  window.addEventListener('cashier-v3:open-toolbar-business-source', handleToolbarBusinessSourceOpen)
  window.addEventListener('cashier-v3:clear-toolbar-business-source', handleToolbarBusinessSourceClear)
  window.addEventListener('cashier-v3:update-toolbar-business-date', handleToolbarBusinessDateChange)
  publishToolbarCheckoutContext()
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
  if (cardOperationNoticeTimer) window.clearTimeout(cardOperationNoticeTimer)
  window.removeEventListener('cashier-v3:open-checkout', openCheckoutFromService)
  window.removeEventListener('cashier-v3:open-prepared-checkout', openPreparedCheckout)
  window.removeEventListener('cashier-v3:open-entitlement-selector', handleOpenEntitlementSelector)
  window.removeEventListener('cashier-v3:open-card-operation', handleOpenCardOperation)
  window.removeEventListener('cashier-v3:open-guided-business', openGuidedBusiness)
  window.removeEventListener('cashier-v3:open-recharge', openRecharge)
  window.removeEventListener('cashier-v3:member-selector-selected', handleMemberSelectedForEntitlement)
  window.removeEventListener('cashier-v3:checkout-business-source-confirmed', handleCheckoutBusinessSourceConfirmed)
  window.removeEventListener('cashier-v3:member-selector-closed', handleMemberSelectorClosedForEntitlement)
  window.removeEventListener('cashier-v3:state-context-changing', resetCashierLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetCashierLocalContext)
  window.removeEventListener('cashier-v3:refresh-workbench', refreshWorkbenchAfterContextConflict)
  window.removeEventListener('cashier-v3:hang-draft-restored', handleRestoredHangDraft)
  window.removeEventListener('cashier-v3:open-toolbar-business-source', handleToolbarBusinessSourceOpen)
  window.removeEventListener('cashier-v3:clear-toolbar-business-source', handleToolbarBusinessSourceClear)
  window.removeEventListener('cashier-v3:update-toolbar-business-date', handleToolbarBusinessDateChange)
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
      :business-date="localCheckoutBusinessDate"
      :submitting="isRechargeSubmitting"
      :submit-error="rechargePreparationError"
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
    <div
      v-if="cardOperationNotice"
      class="cashier-card-operation-notice"
      role="status"
      aria-live="polite"
    >
      {{ cardOperationNotice }}
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
              @click="selectCatalogType(type)"
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
              <div class="cashier-operation-preview__source-meta">
                <span v-if="source.cardName">来源：{{ source.cardName }}</span>
                <template v-if="['project-replacement', 'project-upgrade'].includes(previewCardOperation.mode)">
                  <span>本次 {{ previewCardOperation.mode === 'project-replacement' ? '替换' : '升级' }} {{ source.quantity || 1 }} 次 / 可用 {{ cardOperationSourceMaximum(source) }} 次</span>
                  <div class="quantity-stepper cashier-operation-preview__quantity-stepper" aria-label="本次项目操作次数">
                    <button
                      type="button"
                      aria-label="减少本次次数"
                      :disabled="Number(source.quantity || 1) <= 1"
                      @click="changeCardOperationSourceQuantity(source, -1)"
                    >−</button>
                    <input
                      :value="source.quantity || 1"
                      type="number"
                      min="1"
                      :max="cardOperationSourceMaximum(source)"
                      inputmode="numeric"
                      aria-label="本次项目操作次数"
                      @change="setCardOperationSourceQuantity(source, $event)"
                    >
                    <button
                      type="button"
                      aria-label="增加本次次数"
                      :disabled="Number(source.quantity || 1) >= cardOperationSourceMaximum(source)"
                      @click="changeCardOperationSourceQuantity(source, 1)"
                    >＋</button>
                  </div>
                </template>
              </div>
            </div>
            <div class="cashier-operation-preview__arrow" aria-hidden="true">↓</div>
            <div class="cashier-operation-preview__target" :class="{ 'is-filled': previewCardOperation.target }">
              <template v-if="previewCardOperation.target">
                <strong>{{ previewCardOperation.target.name }}</strong>
                <span>{{ previewCardOperation.mode === 'card-transfer' ? '新会员' : previewCardOperation.target.kind }}</span>
                <div v-if="previewCardOperation.mode === 'project-replacement'" class="quantity-stepper cashier-operation-preview__quantity-stepper" aria-label="目标项目次数">
                  <button
                    type="button"
                    aria-label="减少目标项目次数"
                    :disabled="Number(previewCardOperation.target.targetQuantity || 1) <= 1"
                    @click="changeCardOperationTargetQuantity(-1)"
                  >−</button>
                  <input
                    :value="previewCardOperation.target.targetQuantity || 1"
                    type="number"
                    min="1"
                    inputmode="numeric"
                    aria-label="目标项目次数"
                    @change="setCardOperationTargetQuantity($event)"
                  >
                  <button
                    type="button"
                    aria-label="增加目标项目次数"
                    @click="changeCardOperationTargetQuantity(1)"
                  >＋</button>
                </div>
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
            >{{ isSubmittingCardOperation && previewCardOperation.mode === 'project-replacement'
              ? '正在替换…'
              : cardOperationConfirmLabel(previewCardOperation.mode) }}</button>
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
                  <div
                    v-if="isProjectLine(line) && !cardOperationUpgradeBinding(line)"
                    class="cart-line__service-object cart-line__service-object--header"
                    aria-label="服务对象与客数"
                  >
                    <button
                      type="button"
                      :class="{ 'is-active': cartLineServiceObject(line) === 'self' }"
                      @click="setCartLineServiceObject(line, 'self', true)"
                    >本人</button>
                    <button
                      type="button"
                      :class="{ 'is-active': cartLineServiceObject(line) === 'friend' && cartLineFriendCountsAsCustomer(line) }"
                      @click="setCartLineServiceObject(line, 'friend', true)"
                    >朋友算</button>
                    <button
                      type="button"
                      :class="{ 'is-active': cartLineServiceObject(line) === 'friend' && !cartLineFriendCountsAsCustomer(line) }"
                      @click="setCartLineServiceObject(line, 'friend', false)"
                    >朋友不算</button>
                  </div>
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
                  <div class="cart-line__meta-slot cart-line__meta-slot--debt">
                    <button
                      v-if="canSetCartLineDebt(line)"
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled"
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
                      :title="salespersonDisplaySummary(line) ? `销售人:${salespersonDisplaySummary(line)}` : '销售人'"
                      @click="openCartLineSalespeople(line)"
                    >
                      <template v-if="salespersonDisplaySummary(line)">销售人:{{ salespersonDisplaySummary(line) }}</template>
                      <template v-else>销售人</template>
                    </button>
                  </div>
                  <div v-if="!isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--attribution">
                    <button
                      type="button"
                      class="cart-line__meta-action cart-line__meta-action--enabled"
                      :title="groupAttributionDisplaySummary(line) ? `导购/销售经理:${groupAttributionDisplaySummary(line)}` : '导购/销售经理'"
                      @click="openCartLineAttributions(line)"
                    >
                      导购/销售经理<span v-if="groupAttributionDisplaySummary(line)">:{{ groupAttributionDisplaySummary(line) }}</span>
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
                    <div
                      v-if="(isProjectLine(line) && !cardOperationUpgradeBinding(line)) || isInventoryManagedProductLine(line)"
                      class="cart-line__service-controls"
                    >
                      <button
                        v-if="isInventoryManagedProductLine(line)"
                        type="button"
                        class="cart-line__experience cart-line__presale"
                        :class="{ 'is-active': cartLineInventoryOutboundRequired(line) }"
                        :aria-pressed="cartLineInventoryOutboundRequired(line)"
                        :disabled="cartLinePresaleSelected(line)"
                        :title="cartLinePresaleSelected(line) ? '预售状态不可出库，请先选择不出库' : '本单正常出库并扣减库存'"
                        @click.stop="setCartLineInventoryMode(line, 'outbound')"
                      >出库</button>
                      <button
                        v-if="isInventoryManagedProductLine(line)"
                        type="button"
                        class="cart-line__experience cart-line__presale"
                        :class="{ 'is-active': !cartLineInventoryOutboundRequired(line) && !cartLinePresaleSelected(line) }"
                        :aria-pressed="!cartLineInventoryOutboundRequired(line) && !cartLinePresaleSelected(line)"
                        title="本单销售但不扣减库存"
                        @click.stop="setCartLineInventoryMode(line, 'no-outbound')"
                      >不出库</button>
                      <button
                        v-if="isInventoryManagedProductLine(line)"
                        type="button"
                        class="cart-line__experience cart-line__presale"
                        :class="{ 'is-active': cartLinePresaleSelected(line) }"
                        :aria-pressed="cartLinePresaleSelected(line)"
                        title="预售自动不出库"
                        @click.stop="setCartLineInventoryMode(line, 'presale')"
                      >预售</button>
                      <button
                        v-if="isProjectLine(line) && !cardOperationUpgradeBinding(line)"
                        type="button"
                        class="cart-line__experience"
                        :class="{ 'is-active': cartLineExperienceSelected(line) }"
                        :aria-pressed="cartLineExperienceSelected(line)"
                        :title="cartLineExperienceSelected(line) ? '取消体验项目标记' : '标记为体验项目'"
                        @click.stop="toggleCartLineExperience(line)"
                      >体验</button>
                    </div>
                  </div>
                  <div
                    v-if="cardOperationUpgradeBinding(line)?.operationType === 'project_upgrade'"
                    class="cart-line__upgrade-entitlement-quantity"
                    aria-label="升级后生成权益次数"
                  >
                    <span>升级后生成</span>
                    <div class="quantity-stepper quantity-stepper--editable" aria-label="升级后生成权益次数">
                      <button
                        type="button"
                        aria-label="减少升级后生成次数"
                        :disabled="cardOperationUpgradeTargetEntitlementQuantity(cardOperationUpgradeBinding(line)) <= 1"
                        @click.stop="changeCardOperationUpgradeEntitlementQuantity(line, -1)"
                      >−</button>
                      <input
                        :value="cardOperationUpgradeTargetEntitlementQuantity(cardOperationUpgradeBinding(line))"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        aria-label="升级后生成权益次数"
                        @focus="activeCartLineId = line.id"
                        @change="setCardOperationUpgradeEntitlementQuantity(line, $event)"
                      >
                      <button
                        type="button"
                        aria-label="增加升级后生成次数"
                        @click.stop="changeCardOperationUpgradeEntitlementQuantity(line, 1)"
                      >＋</button>
                    </div>
                    <span>次</span>
                  </div>
                  <div v-else class="quantity-stepper quantity-stepper--editable" aria-label="数量操作">
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
          :disabled="isClearingCart"
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
        <button type="button" class="button button--primary cashier-checkout-actions__submit" :disabled="!canSubmitCart || isPreparingServiceCompletion || isPreparingCheckout || isSynchronizingLocalCashierDraft || isPersistingDeferredLineSettings" @click="guardedOpenCheckout">
          {{ isPreparingServiceCompletion ? '正在准备服务确认…' : isSynchronizingLocalCashierDraft ? '正在加载…' : isPreparingCheckout ? '正在准备结账…' : checkoutEntryLabel }}
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
        :receipt="checkoutReceiptSnapshot"
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
      :reward-amount-cents="checkoutBusinessSourceSelector.rewardAmountCents"
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
        :sales-date-max="cashierToday"
        @close="closeRechargeCheckout"
        @completed="closeSucceededRechargeCheckoutAndRefreshWorkbench"
        @request="enqueueRechargeCheckoutAction"
      />
    </Teleport>

    <Teleport to="body">
      <PersonnelPerformanceOverlay
        v-if="personnelOverlay"
        :initial-tab="personnelOverlay.initialTab"
        :show-craftsmen="personnelOverlay.showCraftsmen"
        :show-salespeople="personnelOverlay.showSalespeople"
        :show-guides="personnelOverlay.showGuides"
        :show-sales-managers="personnelOverlay.showSalesManagers"
        :allow-other-craftsmen="personnelOverlay.showCraftsmen"
        :require-craftsmen="personnelOverlay.requireCraftsmen"
        :store-id="Number(state.currentStore?.id || 0)"
        :craftsmen-candidates="personnelOverlay.craftsmenCandidates"
        :other-craftsman-candidates="personnelOverlay.otherCraftsmanCandidates"
        :salesperson-candidates="personnelOverlay.salespersonCandidates"
        :guide-candidates="personnelOverlay.guideCandidates"
        :sales-manager-candidates="personnelOverlay.salesManagerCandidates"
        :selected-craftsmen="personnelOverlay.selectedCraftsmen"
        :selected-salespeople="personnelOverlay.selectedSalespeople"
        :selected-guides="personnelOverlay.selectedGuides"
        :selected-sales-managers="personnelOverlay.selectedSalesManagers"
        :labor-default-fee="personnelOverlay.laborDefaultFee"
        :labor-manual-fee="personnelOverlay.laborManualFee"
        :allow-labor-override="personnelOverlay.allowLaborOverride"
        :project-count-total="personnelOverlay.projectCountTotal"
        :performance-base-amount-cents="personnelOverlay.performanceBaseAmountCents"
        :loading="personnelOverlay.loading"
        :saving="isSavingPersonnelAssignment"
        :load-error="personnelOverlay.loadError"
        @close="personnelOverlay = null"
        @confirm="confirmPersonnelAssignment"
        @apply-all="applyPersonnelAssignmentToAll"
        @retry="retryPersonnelOverlay"
        @search-personnel="searchPersonnelOverlay"
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
              <textarea :value="moreActionReason" rows="3" maxlength="255" placeholder="必填" @input="handleMoreActionReasonInput"></textarea>
            </label>
          </template>
          <template v-else>
            <label>
              <span>业务日期</span>
              <input v-model="moreActionValue" type="date" :max="cashierToday">
            </label>
            <label>
              <span>补单原因</span>
              <textarea :value="moreActionReason" rows="3" maxlength="255" placeholder="必填" @input="handleMoreActionReasonInput"></textarea>
            </label>
          </template>
          <p v-if="moreActionValidationMessage" class="cashier-card-operation-editor__error" role="alert">{{ moreActionValidationMessage }}</p>
          <footer>
            <button type="button" class="button button--secondary" :disabled="isSavingMoreAction" @click="closeMoreActionEditor">取消</button>
            <button
              type="button"
              class="button button--primary"
              :disabled="isSavingMoreAction"
              @click.stop.prevent="saveMoreActionEditor"
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
              :value="debtEditorAmount"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              placeholder="0"
              @input="handleDebtEditorAmountInput"
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
