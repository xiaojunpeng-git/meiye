<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BadgeDollarSign from '@lucide/vue/dist/esm/icons/badge-dollar-sign.mjs'
import Boxes from '@lucide/vue/dist/esm/icons/boxes.mjs'
import CalendarDays from '@lucide/vue/dist/esm/icons/calendar-days.mjs'
import ChartNoAxesCombined from '@lucide/vue/dist/esm/icons/chart-no-axes-combined.mjs'
import ChevronDown from '@lucide/vue/dist/esm/icons/chevron-down.mjs'
import CircleUserRound from '@lucide/vue/dist/esm/icons/circle-user-round.mjs'
import ClipboardList from '@lucide/vue/dist/esm/icons/clipboard-list.mjs'
import DoorOpen from '@lucide/vue/dist/esm/icons/door-open.mjs'
import HeartHandshake from '@lucide/vue/dist/esm/icons/heart-handshake.mjs'
import WalletCards from '@lucide/vue/dist/esm/icons/wallet-cards.mjs'
import KeyRound from '@lucide/vue/dist/esm/icons/key-round.mjs'
import LogOut from '@lucide/vue/dist/esm/icons/log-out.mjs'
import PanelLeftClose from '@lucide/vue/dist/esm/icons/panel-left-close.mjs'
import PanelLeftOpen from '@lucide/vue/dist/esm/icons/panel-left-open.mjs'
import ReceiptText from '@lucide/vue/dist/esm/icons/receipt-text.mjs'
import Settings from '@lucide/vue/dist/esm/icons/settings.mjs'
import Store from '@lucide/vue/dist/esm/icons/store.mjs'
import Users from '@lucide/vue/dist/esm/icons/users.mjs'
import MemberSelectorOverlay from '@/components/common/MemberSelectorOverlay.vue'
import MemberSummaryCard from '@/components/common/MemberSummaryCard.vue'
import MemberDetailOverlay from '@/components/member/MemberDetailOverlay.vue'
import MemberDebtOverlay from '@/components/member/MemberDebtOverlay.vue'
import MemberDebtReminderOverlay from '@/components/member/MemberDebtReminderOverlay.vue'
import DirectGiftOverlay from '@/components/member/DirectGiftOverlay.vue'
import RechargeOverlay from '@/components/member/RechargeOverlay.vue'
import QueryEntitySelectorOverlay from '@/components/query/QueryEntitySelectorOverlay.vue'
import RoomAssignmentOverlay from '@/components/room/RoomAssignmentOverlay.vue'
import ServiceCompletionOverlay from '@/components/service/ServiceCompletionOverlay.vue'
import ServiceLineCompletionEditorOverlay from '@/components/service/ServiceLineCompletionEditorOverlay.vue'
import ServiceLineCraftsmenOverlay from '@/components/service/ServiceLineCraftsmenOverlay.vue'
import InventoryWorkbench from '@mohe/inventory-vue3'
import '@mohe/inventory-vue3/styles.css'
import {
  canUseCashierV3Feature,
  canUseCashierV3Operation,
  createCashierV3CommandId,
  openCashierV3QueryEntitySelector,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'
import { changeStoreV3Password, clearStoreV3Token, logoutStoreV3 } from '@/services/storeV3LoginApi'
import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'
import { requestCustomerCareAction } from '@/services/customerCareApi'
import { roomOpenIntentFromRouteQuery } from '@/services/cashierV3RoomOpenIntent'
import {
  mergeSalesOrderCenterProjection,
  salesOrderProjectionFromResult
} from '@/services/cashierV3OrderProjectionContract'
import { responseDataBlock } from '@/services/cashierV3EntitlementDraftContract'

const route = useRoute()
const router = useRouter()
const state = useCashierV3State()
const isOperationHelpOpen = ref(false)
const toolbarBusinessDate = ref(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date()))
const toolbarBusinessDateReason = ref('')
const toolbarBusinessSource = ref({ displayNameSnapshot: '', primarySourceId: 0, secondarySourceId: 0 })
const isMemberSelectorOpen = ref(false)
const memberSelectorContext = ref('cashier')
let memberSelectorQuerySequence = 0
const memberSelectorRequiresMember = ref(false)
// 推荐人查询独立于主会员选择器。新建会员面板本身位于主选择器内部，
// 不能通过切换主选择器来选择推荐人，否则会卸载并丢失正在填写的表单。
const isReferrerSelectorOpen = ref(false)
const referrerSelector = ref({ records: [], total: 0, page: 1, pageSize: 20, isLoading: false })
let referrerSelectorQuerySequence = 0
let referrerSelectorResolve = null
let referrerSelectorExcludedMemberId = 0
const isMemberDetailOpen = ref(false)
const memberDetailInitialTab = ref('profile')
const memberDetailFallback = ref({})
const isMemberDetailLoading = ref(false)
const activeMemberDetailId = ref(null)
let memberDetailLoadSequence = 0
const isDebtReminderOpen = ref(false)
const debtReminderMember = ref(null)
const deferredMemberSelection = ref(null)
const pendingDebtReminderAfterSource = ref(null)
const isMemberDebtOpen = ref(false)
const activeDebtMember = ref(null)
const initialDebtRecordId = ref('')
const isMemberDebtLoading = ref(false)
const isDebtRepaymentPreparing = ref(false)
const isQueryEntitySelectorOpen = ref(false)
const queryEntitySelectorRequest = ref(null)
const isServiceCompletionOpen = ref(false)
let serviceCompletionReturnFocusTarget = null
const activeServiceCompletionId = ref(null)
const activeServiceCompletionLineId = ref(null)
const isServiceLineCompletionEditorOpen = ref(false)
const isServiceLineCraftsmenOpen = ref(false)
const isServiceLineSubmitting = ref(false)
const isServiceCompletionActionSubmitting = ref(false)
const serviceCompletionActionIds = ref({})
const serviceCompletionPreparationRequests = ref({})
const latestServiceCompletionPreparationIntent = ref(null)
const isRoomAssignmentOpen = ref(false)
const activeRoomAssignmentSubjectKey = ref('')
const activeRoomAssignmentPreparationId = ref('')
const activeRoomAssignmentMode = ref('')
const isRoomAssignmentSubmitting = ref(false)
const roomAssignmentActionIds = ref({})
const roomAssignmentPreparationRequests = ref({})
const latestRoomAssignmentPreparationIntent = ref(null)
const pendingCashierWorkflowTarget = ref(null)
const pendingMemberTopAction = ref(null)
const memberSelectorInitialView = ref('selector')
const memberCreatorSchema = ref({})
const isCardOperationMenuOpen = ref(false)
const rechargeSession = ref(null)
const isRechargeSubmitting = ref(false)
const directGiftSession = ref(null)
const isDirectGiftSubmitting = ref(false)
const uiFeedback = ref(null)
const isAccountMenuOpen = ref(false)
const isPasswordDialogOpen = ref(false)
const isPasswordSubmitting = ref(false)
const passwordChange = ref({ account: '', currentPassword: '', newPassword: '', confirmation: '' })
const passwordChangeError = ref('')
let feedbackTimeoutId = null

function openToolbarBusinessSource() {
  window.dispatchEvent(new CustomEvent('cashier-v3:open-toolbar-business-source'))
}

function updateToolbarBusinessDate(event) {
  toolbarBusinessDate.value = String(event?.target?.value || '')
  window.dispatchEvent(new CustomEvent('cashier-v3:update-toolbar-business-date', {
    detail: {
      businessDate: toolbarBusinessDate.value,
      reason: toolbarBusinessDateReason.value
    }
  }))
}

function handleToolbarCheckoutContextUpdated(event) {
  const detail = event?.detail || {}
  if (detail.businessDate) toolbarBusinessDate.value = String(detail.businessDate)
  toolbarBusinessDateReason.value = String(detail.businessDateReason || '')
  toolbarBusinessSource.value = detail.source && typeof detail.source === 'object'
    ? { ...toolbarBusinessSource.value, ...detail.source }
    : toolbarBusinessSource.value
}

function handleCheckoutBusinessSourceCancelled(event = {}) {
  if (event.detail?.kind !== 'sale' || !isCashierPage.value) return
  pendingDebtReminderAfterSource.value = null
  pendingCashierWorkflowTarget.value = null
  pendingMemberTopAction.value = null
  applyLocalCashierCustomerSelection({ customerMode: 'guest' })
}

watch(
  () => state.cashier?.checkout,
  (checkout) => {
    if (!checkout || typeof checkout !== 'object') return
    // 游客订单不允许继承任何会员来源；根投影刷新时也不能把旧来源重新带回。
    if (String(state.cashier?.customerMode || '') === 'guest') {
      toolbarBusinessSource.value = {
        displayNameSnapshot: '',
        primarySourceId: 0,
        secondarySourceId: 0,
        rewardAmountCents: 0,
        sourceSelectionVersion: 0
      }
      return
    }
    if (checkout.businessDate) toolbarBusinessDate.value = String(checkout.businessDate)
    toolbarBusinessDateReason.value = String(checkout.businessDateReason || '')
    if (Number(checkout.primarySourceId || 0) > 0) {
      toolbarBusinessSource.value = {
        ...toolbarBusinessSource.value,
        primarySourceId: Number(checkout.primarySourceId || 0),
        secondarySourceId: Number(checkout.secondarySourceId || 0)
      }
    }
  },
  { immediate: true }
)

const sidebarPreferenceKey = 'cashier-v3-sidebar-collapsed'
const isSidebarCollapsed = ref(readSidebarPreference())
const isInventoryMenuExpanded = ref(false)
const managementRouteNames = ['cashier-v3-management-center', 'cashier-v3-staff-list', 'cashier-v3-room-settings', 'cashier-v3-business-dashboard']
const isManagementMenuExpanded = ref(managementRouteNames.includes(route.name))
// 库存工作区与收银台共用当前 V3 会话；初始化时就读取，避免 HMR/路由重载
// 期间库存组件先于点击事件挂载而拿到空 token。
const inventorySessionToken = ref(readStoreV3SessionToken())

const inventoryFeatureItems = [
  { key: 'overview', label: '首页', featureCode: 'cashier.v3.inventory.overview' },
  { key: 'inbound', label: '入库', featureCode: 'cashier.v3.inventory.inbound' },
  { key: 'outbound', label: '出库', featureCode: 'cashier.v3.inventory.outbound' },
  { key: 'stock', label: '库存', featureCode: 'cashier.v3.inventory.stock' },
  { key: 'count', label: '盘点', featureCode: 'cashier.v3.inventory.count' },
  { key: 'statistics', label: '统计', featureCode: 'cashier.v3.inventory.statistics' },
  { key: 'request', label: '请货', featureCode: 'cashier.v3.inventory.request' },
  { key: 'transfer', label: '调拨', featureCode: 'cashier.v3.inventory.transfer' },
  { key: 'usage', label: '院装', featureCode: 'cashier.v3.inventory.usage' },
  { key: 'import', label: '导入', featureCode: 'cashier.v3.inventory.import' },
  { key: 'presale-claim', label: '客户领用', featureCode: 'cashier.v3.inventory.outbound', to: { name: 'cashier-v3-presale-claim' } }
]
const visibleInventoryFeatureItems = computed(() => inventoryFeatureItems.filter((entry) => canUseFeature(entry.featureCode)))
const managementFeatureItems = [
  { key: 'staff', label: '人员管理', to: { name: 'cashier-v3-staff-list' } },
  { key: 'room-settings', label: '房间设置', to: { name: 'cashier-v3-room-settings' } },
  { key: 'business-dashboard', label: '经营看板', to: { name: 'cashier-v3-business-dashboard' } }
]
const isInventoryWorkspaceOpen = ref(false)
const activeInventoryFeatureKey = ref('overview')
const menuItems = [
  {
    key: 'cashier',
    label: '收银',
    icon: BadgeDollarSign,
    featureCode: 'cashier.v3.cashier',
    to: { name: 'cashier-v3-cashier' },
    activeRouteNames: ['cashier-v3-cashier', 'cashier-v3-writeoff', 'cashier-v3-replacement']
  },
  { key: 'hang', label: '挂单', icon: ClipboardList, featureCode: 'cashier.v3.hang', to: { name: 'cashier-v3-hang' } },
  { key: 'room', label: '房间', icon: DoorOpen, featureCode: 'cashier.v3.room', to: { name: 'cashier-v3-room' } },
  { key: 'reservation', label: '预约', icon: CalendarDays, featureCode: 'cashier.v3.reservation', to: { name: 'cashier-v3-reservation' } },
  { key: 'member', label: '会员', icon: Users, featureCode: 'cashier.v3.member', to: { name: 'cashier-v3-member' } },
  { key: 'fund', label: '费用', icon: WalletCards, featureCode: 'cashier.v3.member', to: { name: 'cashier-v3-fund' } },
  { key: 'care', label: '客情', icon: HeartHandshake, featureCode: 'cashier.v3.member', to: { name: 'cashier-v3-care' } },
  { key: 'order', label: '订单', icon: ReceiptText, featureCode: 'cashier.v3.order_center', to: { name: 'cashier-v3-order-center' } },
  {
    key: 'management',
    label: '管理',
    icon: Settings,
    featureCode: 'cashier.v3.management_center',
    to: { name: 'cashier-v3-staff-list' },
    activeRouteNames: ['cashier-v3-management-center', 'cashier-v3-staff-list', 'cashier-v3-room-settings', 'cashier-v3-business-dashboard']
  },
  {
    key: 'data',
    label: '数据',
    icon: ChartNoAxesCombined,
    featureCode: 'cashier.v3.management_center',
    to: { name: 'cashier-v3-store-business-reports' },
    activeRouteNames: ['cashier-v3-store-business-reports']
  },
  {
    key: 'targets',
    label: '目标',
    icon: ChartNoAxesCombined,
    featureCode: 'cashier.v3.management_center',
    to: { name: 'cashier-v3-store-target-dashboard' }
  },
  {
    key: 'inventory',
    label: '库存',
    icon: Boxes,
    // 库存是门店日常业务入口，不应因“管理”中心未授权而在收银侧栏消失。
    // 入口跟随已进入收银台的账号显示；库存应用自己的接口继续强制门店、
    // 仓位和成本字段权限，不能由这个导航入口扩大数据范围。
    featureCode: 'cashier.v3.cashier',
    submenu: inventoryFeatureItems
  }
]
const cashierWorkflowTabs = [
  { key: 'checkout', label: '结账收款', icon: '¥', featureCode: 'cashier.v3.cashier.checkout', to: { name: 'cashier-v3-cashier' } },
  { key: 'replacement', label: '项目替换', icon: '↔', featureCode: 'cashier.v3.writeoff', to: { name: 'cashier-v3-replacement' } }
]
// 卡操作统一走 V3 写命令。尚未完成当前权益写入/结账绑定的升级与项目
// 操作继续由后端 fail-closed，绝不回退调用旧收银接口。
const cardOperationItems = Object.freeze([
  { key: 'card-upgrade', label: '卡升级', featureCode: 'cashier.v3.cashier.card.upgrade' },
  { key: 'card-extension', label: '卡延期', featureCode: 'cashier.v3.cashier.card.extend' },
  { key: 'card-transfer', label: '卡转让', featureCode: 'cashier.v3.cashier.card.transfer' },
  { key: 'card-disable', label: '卡停用', featureCode: 'cashier.v3.cashier.card.disable' },
  { key: 'card-enable', label: '卡启用', featureCode: 'cashier.v3.cashier.card.enable' },
  { key: 'project-replacement', label: '项目替换', featureCode: 'cashier.v3.cashier.card.project_replace' },
  { key: 'project-upgrade', label: '项目升级', featureCode: 'cashier.v3.cashier.card.project_upgrade' }
])
const allowedTopActions = new Set([
  'open-recharge',
  'open-gift',
  'open-reservation-editor',
  'open-member-creator',
  'open-member-batch-actions'
])
const allowedMemberSelectorContexts = new Set(['cashier', 'writeoff', 'reservation', 'card-transfer', 'customer-care', 'customer-care-record', 'member-referrer'])
const memberSelectorActions = {
  writeoff: 'select-writeoff-member',
  reservation: 'select-reservation-member'
}
const memberDetailActionsByScope = {
  member: new Set(['open-member-more-actions', 'open-member-editor', 'open-recharge', 'open-gift']),
  'appointment-highlight': new Set(['open-reservation-detail']),
  card: new Set(['open-card-batch', 'open-card-benefits']),
  'balance-change': new Set(['open-operation-logs']),
  coupon: new Set(['open-operation-logs']),
  'point-change': new Set(['open-operation-logs']),
  'writeoff-record': new Set(['open-writeoff-records']),
  'sales-order': new Set(['open-sales-order-detail']),
  'exclusive-service-staff-change': new Set(['open-operation-logs']),
  'care-task': new Set(['open-operation-logs']),
  'care-record': new Set(['open-operation-logs']),
  'debt-record': new Set(['open-debt-settlements']),
  'gift-source': new Set(['open-sales-order-detail']),
  'gift-record': new Set(['open-gift-records', 'open-sales-order-detail']),
  'gift-coupon': new Set(['open-gift-records'])
}
const allowedServiceCompletionActions = new Set([
  'open-service-line-completion',
  'open-service-line-staff-allocation',
  'confirm-service-completion',
  'retry-service-completion',
  'query-service-completion-result',
  'continue-service-checkout',
  'finish-service-completion',
  'return-to-service-edit',
  'save-service-line-completion',
  'save-service-line-craftsmen'
])

function readSidebarPreference() {
  if (typeof window === 'undefined') return false
  try {
    return window.localStorage.getItem(sidebarPreferenceKey) === '1'
  } catch {
    return false
  }
}

function persistSidebarPreference() {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(sidebarPreferenceKey, isSidebarCollapsed.value ? '1' : '0')
  } catch {
    // The visual state still works when browser storage is unavailable.
  }
}

function toggleSidebar() {
  isSidebarCollapsed.value = !isSidebarCollapsed.value
  persistSidebarPreference()
}

function toggleInventoryMenu() {
  isInventoryMenuExpanded.value = !isInventoryMenuExpanded.value
}

function toggleManagementMenu() {
  isManagementMenuExpanded.value = !isManagementMenuExpanded.value
}

function openInventoryWorkspace(entry = visibleInventoryFeatureItems.value[0]) {
  if (!entry || !canUseFeature(entry.featureCode)) return
  activeInventoryFeatureKey.value = entry.key
  inventorySessionToken.value = readStoreV3SessionToken()
  isInventoryMenuExpanded.value = true
  isInventoryWorkspaceOpen.value = true
}

function closeInventoryWorkspace() {
  isInventoryWorkspaceOpen.value = false
}

function isInventoryFeatureActive(entry) {
  if (entry?.to?.name) return route.name === entry.to.name
  return activeInventoryFeatureKey.value === entry?.key && isInventoryWorkspaceOpen.value
}

function isManagementFeatureActive(entry) {
  return route.name === entry?.to?.name
}

function isMenuItemActive(item) {
  if (!item || !item.to || !item.to.name) return false
  const activeRouteNames = Array.isArray(item.activeRouteNames) && item.activeRouteNames.length
    ? item.activeRouteNames
    : [item.to.name]
  return activeRouteNames.includes(route?.name || '')
}

const isCashierPage = computed(() => route.name === 'cashier-v3-cashier')
const isCashierWorkflowPage = computed(() => ['cashier-v3-cashier', 'cashier-v3-writeoff', 'cashier-v3-replacement'].includes(route.name))
const isReservationPage = computed(() => route.name === 'cashier-v3-reservation')
const isMemberPage = computed(() => route.name === 'cashier-v3-member')
const isStaffPage = computed(() => route.name === 'cashier-v3-staff-list')
const isManagementCenterPage = computed(() => route.name === 'cashier-v3-management-center')
const isBusinessDashboardPage = computed(() => route.name === 'cashier-v3-business-dashboard')
const isStoreBusinessReportPage = computed(() => route.name === 'cashier-v3-store-business-reports')
const isStoreTargetDashboardPage = computed(() => route.name === 'cashier-v3-store-target-dashboard')
const activeCashierWorkflowMode = computed(() => {
  if (route.name === 'cashier-v3-writeoff') return 'writeoff'
  if (route.name === 'cashier-v3-replacement') return 'replacement'
  return 'checkout'
})
const cashierMember = computed(() => state.cashier?.member || null)
const cashierServiceOrder = computed(() => state.cashier?.serviceOrder || null)
const cashierServiceOrderRoomName = computed(() => String(cashierServiceOrder.value?.roomName || '').trim())
const cashierServiceOrderDisplayNo = computed(() => {
  const source = cashierServiceOrder.value || {}
  return String(source.serviceNo || source.no || source.serviceOrderNo || '').trim()
})
const cashierServiceOrderStatusText = computed(() => String(cashierServiceOrder.value?.status || '').trim())
const cashierRoomOpenIntent = computed(() => roomOpenIntentFromRouteQuery(route.query))
const hasCashierServiceOrderSummary = computed(() => Boolean(
  cashierRoomOpenIntent.value
  || cashierServiceOrderRoomName.value
  || cashierServiceOrderDisplayNo.value
  || cashierServiceOrderStatusText.value
))
const isGuestCustomer = computed(() => activeCashierWorkflowMode.value === 'checkout'
  && state.cashier?.customerMode === 'guest')
const writeoffMember = computed(() => state.writeoff?.member || null)
const workflowMember = computed(() => activeCashierWorkflowMode.value === 'checkout'
  ? cashierMember.value
  : writeoffMember.value)
const pageTitle = computed(() => (isCashierPage.value ? '收银台' : (route.meta.title || '门店端')))
const pendingHangCount = computed(() => Number(state.pendingHangCount) || 0)
const hasWorkflowCustomer = computed(() => Boolean(workflowMember.value) || isGuestCustomer.value)
const memberSelector = computed(() => state.memberSelector || {})
const memberDetail = computed(() => {
  const backendDetail = state.memberCenter?.detail
  const loadedDetail = memberDetailFallback.value || {}
  if (!backendDetail) return loadedDetail
  if (!activeMemberDetailId.value || String(memberDetailId(backendDetail)) === String(activeMemberDetailId.value)) {
    return mergeMemberDetail(backendDetail, loadedDetail)
  }
  return loadedDetail
})
const memberDebtSnapshot = computed(() => {
  const snapshot = state.memberCenter?.debtSnapshot
  const activeMemberId = memberDetailId(activeDebtMember.value)
  const snapshotMemberId = memberDetailId(snapshot?.member || snapshot)
  if (snapshot && (!activeMemberId || !snapshotMemberId || String(activeMemberId) === String(snapshotMemberId))) {
    return snapshot
  }
  return {
    memberId: activeMemberId,
    outstandingDebtAmount: memberDebtAmount(activeDebtMember.value),
    outstandingDebtCount: activeDebtMember.value?.outstandingDebtCount || 0,
    debtDataAsOf: activeDebtMember.value?.debtDataAsOf || '',
    records: Array.isArray(activeDebtMember.value?.debtRecords) ? activeDebtMember.value.debtRecords : []
  }
})

function applyAuthoritativeDebtSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== 'object') return
  const memberId = memberDetailId(snapshot.member || snapshot)
  if (!memberId) return
  const amount = Number(snapshot.outstandingDebtAmount ?? snapshot.totalDebtAmount ?? 0)
  const count = Number(snapshot.outstandingDebtCount ?? snapshot.total ?? 0)
  const dataAsOf = String(snapshot.debtDataAsOf ?? snapshot.dataAsOf ?? '')
  const patch = {
    outstandingDebtAmount: Number.isFinite(amount) ? amount : 0,
    outstandingDebtCount: Number.isFinite(count) ? count : 0,
    debtDataAsOf: dataAsOf
  }
  for (const key of ['cashier', 'writeoff']) {
    const current = state[key]?.member
    if (current && String(memberDetailId(current)) === String(memberId)) {
      state[key] = { ...state[key], member: { ...current, ...patch } }
    }
  }
}
const queryEntitySelectorType = computed(() => queryEntitySelectorRequest.value?.entityType || 'person')
const queryEntitySelector = computed(() => state.queryEntitySelector?.[queryEntitySelectorType.value] || {})
const serviceCompletionSnapshot = computed(() => state.serviceCompletion || {})
const hasMatchingServiceCompletionSnapshot = computed(() => {
  const expectedId = activeServiceCompletionId.value
  const snapshotOrder = serviceCompletionSnapshot.value.serviceOrder
  return Boolean(snapshotOrder && typeof snapshotOrder === 'object'
    && (!expectedId || String(serviceOrderId(snapshotOrder)) === String(expectedId)))
})
const serviceCompletionOrder = computed(() => {
  return hasMatchingServiceCompletionSnapshot.value ? serviceCompletionSnapshot.value.serviceOrder : {}
})
const serviceCompletionCartLines = computed(() => {
  if (hasMatchingServiceCompletionSnapshot.value && Array.isArray(serviceCompletionSnapshot.value.lines)) {
    return serviceCompletionSnapshot.value.lines
  }
  return []
})
const serviceCompletionStatus = computed(() => serviceCompletionOrder.value?.completion?.status || '')
const activeServiceCompletionLine = computed(() => {
  if (!activeServiceCompletionLineId.value) return null
  return serviceCompletionCartLines.value.find((line) => String(serviceLineId(line)) === String(activeServiceCompletionLineId.value)) || null
})
const roomAssignment = computed(() => state.room?.assignment || {})
const roomAssignmentScope = computed(() => ['reservation_plan', 'active_service'].includes(roomAssignment.value.assignmentScope)
  ? roomAssignment.value.assignmentScope
  : '')
const roomAssignmentServiceOrder = computed(() => roomAssignment.value.serviceOrder && typeof roomAssignment.value.serviceOrder === 'object'
  ? roomAssignment.value.serviceOrder
  : {})
const roomAssignmentReservation = computed(() => roomAssignment.value.reservation && typeof roomAssignment.value.reservation === 'object'
  ? roomAssignment.value.reservation
  : {})
const roomAssignmentCurrentRoom = computed(() => roomAssignment.value.currentRoom && typeof roomAssignment.value.currentRoom === 'object'
  ? roomAssignment.value.currentRoom
  : {})
const roomAssignmentCandidates = computed(() => Array.isArray(roomAssignment.value.candidates) ? roomAssignment.value.candidates : [])
const roomAssignmentMode = computed(() => normalizedRoomAssignmentMode(roomAssignment.value))
const hasMatchingRoomAssignmentSnapshot = computed(() => {
  const expectedKey = activeRoomAssignmentSubjectKey.value
  const expectedPreparationId = activeRoomAssignmentPreparationId.value
  const snapshotPreparationId = roomAssignmentPreparationId(roomAssignment.value)
  const snapshotKey = roomAssignmentSubjectKey(
    roomAssignmentScope.value,
    roomAssignmentReservation.value,
    roomAssignmentServiceOrder.value
  )
  return Boolean(snapshotKey
    && (!expectedKey || snapshotKey === expectedKey)
    && (!expectedPreparationId || snapshotPreparationId === expectedPreparationId)
    && (!activeRoomAssignmentMode.value || roomAssignmentMode.value === activeRoomAssignmentMode.value))
})
const operatorLabel = computed(() => state.operator.roleName
  ? `${state.operator.name} · ${state.operator.roleName}`
  : state.operator.name)
const hasPageHelp = computed(() => ['cashier-v3-cashier', 'cashier-v3-writeoff', 'cashier-v3-replacement', 'cashier-v3-room', 'cashier-v3-reservation', 'cashier-v3-member', 'cashier-v3-care', 'cashier-v3-hang', 'cashier-v3-order-center', 'cashier-v3-management-center', 'cashier-v3-staff-list', 'cashier-v3-business-dashboard', 'cashier-v3-store-business-reports', 'cashier-v3-store-target-dashboard'].includes(route.name))
watch(
  () => route.name,
  (routeName) => {
    isCardOperationMenuOpen.value = false
    if (managementRouteNames.includes(routeName)) {
      isManagementMenuExpanded.value = true
    }
    // 收银台 V3 的充值由 CashierWorkbenchView 统一接管，离开旧页面时
    // 清掉 Shell 兼容充值状态，避免旧弹层在路由切换后重新覆盖新 Checkout。
    if (routeName === 'cashier-v3-cashier') rechargeSession.value = null
  }
)
const helpContent = computed(() => {
  if (isStoreBusinessReportPage.value) {
    return {
      title: '经营报表说明',
      description: '门店运营六张报表共用 V3 统一事实服务，当前门店范围由登录会话固定，页面筛选不能扩大可见范围。',
      steps: [
        '上排功能页签切换合作方品项汇总、合作方品项明细、会员消费明细、门店品项分析、手艺人消耗和销售人业绩。',
        '所有报表只展示 V3 正式事实覆盖期；覆盖起始日、数据更新时间和口径版本显示在查询条件下方。',
        '顾客报表中的待确认指标不计算也不导出；销售和服务事实分别统计。',
        '导出复用当前筛选、固定字段和后端门店权限，不能由浏览器指定其他门店或字段。'
      ]
    }
  }
  if (isBusinessDashboardPage.value) {
    return {
      title: '运营概况说明',
      description: '经营看板只展示 V3 事实覆盖范围内的数据，平台和门店使用同一套指标与后端权限。',
      steps: [
        '顶部十张卡固定从“销售人业绩”开始，不再展示“新建档数”。',
        '默认趋势和排行仍是“现金业绩”降序；点击指标卡可切换趋势，明细由后端按相同口径下钻。',
        '“数据更新至”“口径版本”和“聚合是否追平”用于判断数据新鲜度；追平中不代表业务事实丢失。',
        '门店端固定当前登录门店；平台端才可以按授权选择组织或门店，页面选择不会扩大数据权限。'
      ]
    }
  }

  if (isStoreTargetDashboardPage.value) {
    return {
      title: '目标看板说明',
      description: '目标进度与员工业绩排行仅读取当前登录门店的统一事实数据，目标卡只读展示。',
      steps: [
        '默认统计本月，可切换今日、本年或自定义日期范围。',
        '现金排行按现金业绩降序，消耗排行按消耗业绩降序；成交人数和服务人数按顾客去重。',
        '目标、排行和合计均由后端统一事实服务返回，页面不自行计算或扩大门店范围。'
      ]
    }
  }

  if (isManagementCenterPage.value) {
    return {
      title: '管理中心说明',
      description: '这里逐步承接原门店后台的管理类功能，不会自动改变旧功能的权限或业务流程。',
      steps: [
        '只显示当前账号和当前门店已有权限的管理入口；入口是否可用由后端再次校验。',
        '标记为“兼容入口”的功能仍使用原功能逻辑，当前不代表已经完成 V3 重做。',
        '需要重做的旧功能会逐项确认后迁入，未确认的功能不会被擅自搬运或删除。',
        '管理中心不能改变当前办理门店；需要调店时仍按现有调店规则处理。'
      ]
    }
  }

  if (route.name === 'cashier-v3-member') {
    return {
      title: '会员列表说明',
      description: '这里查看会员资料、资产与到店情况；详细业务记录从会员详情进入。',
      steps: [
        '可按姓名、完整手机号或会员编号查询；默认只显示正常数据，可切换到全部数据。',
        '点击会员姓名或编号查看详情；停用、已注销会员可查询，但不能作为办理对象。',
        '平台端可按权限批量设置标签、等级、专属服务人或发券；提交前会再次确认目标会员。',
        '到店次数按同一会员同一天第一笔有效服务或核销记录计算。'
      ]
    }
  }

  if (route.name === 'cashier-v3-care') {
    return {
      title: '客情页面说明',
      description: '客情功能按会员详情的客情管理页签展示，后续再扩展独立客情列表。',
      steps: [
        '当前页面先作为客情入口保留，会员详情中可查看客情关系、跟进任务和客情时间轴。',
        '后续客情列表和跟进操作接入后，会继续沿用同一套会员权限和数据范围。'
      ]
    }
  }

  if (route.name === 'cashier-v3-replacement') {
    return {
      title: '项目替换说明',
      description: '项目替换不是消费，不产生核销订单；V3 业务页面待接口合同确认后接入。',
      steps: [
        '当前入口与核销共用收银顶部模式切换，不再单独占用左侧菜单。',
        '接入后将沿用“选择卡项→添加来源项目→选择新项目”的已确认流程。'
      ]
    }
  }

  if (route.name === 'cashier-v3-hang') {
    return {
      title: '挂单页面说明',
      description: '挂单菜单只用于查找、提取和处理已有挂单，不在这里另建订单。',
      steps: [
        '在收银台点击“挂单”创建普通挂单，或选择“挂单并开始服务”。',
        '提单时会恢复原来的商品、会员、人员、优惠和备注；结账前系统会重新校验权益与价格。',
        '服务中或待结账的内容仍属于同一张服务单，不能再为同一会员重复创建一张。',
        '删除入口只处理后端允许删除的挂单，不会删除已经发生的服务、收款或权益记录。'
      ]
    }
  }

  if (route.name === 'cashier-v3-order-center') {
    return {
      title: '订单页面说明',
      description: '订单中心是综合入口；当前首先展示销售订单。',
      steps: [
        '销售订单只在正式结账完成后出现，支付处理中、结果确认中和失败请求不会作为正式销售订单展示。',
        '点击订单号查看商品、收款、卡项、赠送、服务、退款或作废等关联记录。',
        '余额支付只显示“余额支付”和总额；本金、赠金拆分请到余额变更记录查看。',
        '小票打印入口会保留在订单详情，本次不测试实际打印。'
      ]
    }
  }

  if (route.name === 'cashier-v3-reservation') {
    return {
      title: '预约页面说明',
      description: '预约默认显示日历，列表用于集中查询预约记录。',
      steps: [
        '点击“新增预约”，依次选择会员、项目、时间、手艺人、房间和备注；房间可以暂不分配。',
        '每笔预约必须有一个主项目，其他项目作为明细项目；页面会展示实际采用的预约时长。',
        '预约只是安排时间，不会直接占用房间；点击“开始服务”后才进入服务中并占用已选房间。',
        '正常服务不显示“立即核销”；服务结束或结账时才会按实际完成情况触发核销。'
      ]
    }
  }

  if (route.name === 'cashier-v3-writeoff') {
    return {
      title: '核销操作说明',
      description: '按来源选择项目，核对后再提交核销。',
      steps: [
        '先选择会员，在左侧查看每张卡和每条赠送记录的可用项目。',
        '在右侧选择要核销的项目和次数；项目按具体来源分别处理。',
        '点击项目中的手艺人，完成实际服务人员和劳动业绩分配。',
        '确认无误后点击“核对并提交”；正常服务不在这里直接“立即核销”。'
      ]
    }
  }

  if (route.name === 'cashier-v3-room') {
    return {
      title: '房间操作说明',
      description: '房态图只显示当前门店启用中的房间。',
      steps: [
        '空闲房间不会被预约直接占用；只有开始服务后才进入“服务中”。',
        '服务中可结束服务；结束后有待收款则显示“待结账”。',
        '结账、纯卡内服务完成或合法作废后，房间才会恢复空闲。',
        '房间不是必选项；未分配的预约和服务可从“待分配房间”查看。'
      ]
    }
  }

  return {
    title: '收银操作说明',
    description: '按顺序完成开单、选购和结账即可。',
    steps: [
      '先选择会员；普通项目和产品也可以选择游客开单。',
      '在左侧搜索或点击商品、项目和卡项，重复点击会新增独立明细。',
      '需要时在商品行设置人员、优惠券或欠款；低频操作从“更多操作”进入。',
      '核对金额后点击“立即结账”，按页面步骤完成收款。'
    ]
  }
})

async function requestTopAction(action, payload = {}) {
  if (!allowedTopActions.has(action)) {
    return { result: { status: 'failed', code: 'TOP_ACTION_NOT_ALLOWED', message: '该顶部操作未进入前端允许清单。' } }
  }
  if (action === 'open-reservation-editor') {
    window.dispatchEvent(new CustomEvent('cashier-v3:open-reservation-editor', {
      detail: { mode: 'create' }
    }))
    // 预约页负责携带本次准备请求号并校验完整后端草稿；Shell 不再并行发第二次准备请求。
    return { success: true }
  }
  return requestCashierV3Action(action, payload)
}

async function openMemberCreator() {
  const result = await requestTopAction('open-member-creator')
  if (!isSucceededResult(result)) return result
  const schema = result?.result?.data?.creatorSchema
    || result?.data?.result?.data?.creatorSchema
    || result?.data?.data?.creatorSchema
    || result?.data?.creatorSchema
    || {}
  memberCreatorSchema.value = schema && typeof schema === 'object' ? schema : {}
  if (isMemberPage.value) {
    // 会员列表直接进入完整建档，不经过收银用的“查询/选择会员”流程。
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-list-creator', {
      detail: { creatorSchema: memberCreatorSchema.value }
    }))
    return result
  }
  return openMemberSelector({ context: 'cashier', initialView: 'creator' })
}

function openStaffCreator() {
  window.dispatchEvent(new CustomEvent('cashier-v3:open-staff-creator'))
}

async function openCashierMemberTopAction(action) {
  if (!isCashierPage.value || !allowedTopActions.has(action)) {
    return { result: { status: 'failed', code: 'TOP_ACTION_NOT_ALLOWED', message: '该收银操作当前不可用。' } }
  }

  const selectedMember = cashierMember.value
  const memberId = memberDetailId(selectedMember)
  if (isGuestCustomer.value || !memberId) {
    pendingMemberTopAction.value = action
    return openWorkflowMemberSelector('cashier', { preservePending: true })
  }

  const result = await requestTopAction(action, {
    memberId,
    selectorContext: 'cashier',
    selectorEntry: 'cashier'
  })
  // 充值／赠送只消费本次返回的会员浮层，不回放旧收银根状态。
  // 兼容适配器把 overlay 放在不同信封层级的情况，避免入口成功后无界面。
  const data = responseDataBlock(result)
  const overlay = result?.overlay
    || result?.data?.overlay
    || result?.result?.overlay
    || result?.result?.data?.overlay
  const normalizedOverlay = overlay && typeof overlay === 'object'
    ? overlay
    : action === 'open-gift' && data?.member && Array.isArray(data?.catalogItems)
      ? { name: 'direct-gift', ...data }
      : action === 'open-recharge' && data?.member && data?.balance
        ? { name: 'recharge', ...data }
        : null
  if (isSucceededResult(result) && normalizedOverlay && typeof normalizedOverlay === 'object') {
    if (normalizedOverlay.name === 'direct-gift') directGiftSession.value = normalizedOverlay
    if (normalizedOverlay.name === 'recharge') {
      if (isCashierPage.value) {
        window.dispatchEvent(new CustomEvent('cashier-v3:open-recharge', { detail: normalizedOverlay }))
      } else {
        rechargeSession.value = normalizedOverlay
      }
    }
  }
  return result
}

async function submitRecharge(payload = {}) {
  if (isRechargeSubmitting.value || !rechargeSession.value) return
  isRechargeSubmitting.value = true
  try {
    const result = await requestCashierV3Action('submit-recharge', payload)
    if (isSucceededResult(result)) rechargeSession.value = null
    return result
  } finally {
    isRechargeSubmitting.value = false
  }
}

async function submitDirectGift(payload = {}) {
  if (isDirectGiftSubmitting.value || !directGiftSession.value) return
  isDirectGiftSubmitting.value = true
  try {
    const result = await requestCashierV3Action('submit-direct-gift', payload)
    if (isSucceededResult(result)) directGiftSession.value = null
    return result
  } finally {
    isDirectGiftSubmitting.value = false
  }
}

function openCashierEntitlementSelector() {
  if (!isCashierPage.value) return false
  window.dispatchEvent(new CustomEvent('cashier-v3:open-entitlement-selector'))
  return true
}

function toggleCardOperationMenu() {
  isCardOperationMenuOpen.value = !isCardOperationMenuOpen.value
}

function openPreviewCardOperation(item) {
  isCardOperationMenuOpen.value = false
  window.dispatchEvent(new CustomEvent('cashier-v3:open-card-operation', {
    detail: { operation: item.key, label: item.label }
  }))
}

function openMemberSelector(payload = {}) {
  const detail = payload?.detail || payload
  const context = detail.context || detail.selectorContext
  if (!allowedMemberSelectorContexts.has(context)) return false
  deferredMemberSelection.value = null
  pendingDebtReminderAfterSource.value = null
  memberSelectorContext.value = context
  memberSelectorInitialView.value = detail.initialView === 'creator' ? 'creator' : 'selector'
  memberSelectorRequiresMember.value = detail.requireMember === true
  isMemberSelectorOpen.value = true
  return true
}

async function queryMemberSelector(query) {
  if (!allowedMemberSelectorContexts.has(memberSelectorContext.value)) {
    return { result: { status: 'failed', code: 'MEMBER_SELECTOR_CONTEXT_INVALID', message: '会员选择来源无效。' } }
  }
  if (['customer-care', 'customer-care-record'].includes(memberSelectorContext.value)) {
    const querySequence = ++memberSelectorQuerySequence
    const result = await requestCustomerCareAction('query-care-members', query)
    if (querySequence !== memberSelectorQuerySequence) return result
    const projection = result?.data && typeof result.data === 'object' ? result.data : {}
    state.memberSelector = {
      ...(state.memberSelector || {}),
      records: Array.isArray(projection.records) ? projection.records : [],
      total: Number(projection.total) || 0,
      page: Math.max(1, Number(projection.page) || 1),
      pageSize: Math.max(1, Number(projection.pageSize) || 20),
      isLoading: false
    }
    return result
  }
  const selectorContext = memberSelectorContext.value === 'card-transfer'
    ? 'cashier'
    : memberSelectorContext.value
  // member-referrer constrains the result set, but is not an authorization
  // entry. This selector is opened from cashier and must use its grant.
  const selectorEntry = selectorContext === 'member-referrer'
    ? 'cashier'
    : selectorContext
  const result = await requestCashierV3Action('query-member-selector', {
    ...query,
    selectorContext,
    selectorEntry
  })
  // 会员选择器是独立查询投影，不返回完整工作台根状态。将后端返回的
  // records/total/page/pageSize 明确写入选择器分区，避免页面仍展示旧空列表。
  const envelope = resultEnvelope(result)
  const projection = envelope?.data && typeof envelope.data === 'object' ? envelope.data : {}
  if (Array.isArray(projection.records)) {
    state.memberSelector = {
      ...(state.memberSelector || {}),
      records: projection.records,
      total: Number.isFinite(Number(projection.total)) ? Number(projection.total) : projection.records.length,
      page: Math.max(1, Number(projection.page) || 1),
      pageSize: Math.max(1, Number(projection.pageSize) || 20),
      isLoading: false
    }
  }
  return result
}

async function selectMemberFromSelector(record) {
  if (['card-transfer', 'customer-care', 'customer-care-record', 'reservation', 'member-referrer'].includes(memberSelectorContext.value)) {
    // 转让和预约都只回填选择结果。预约没有收银工作台上下文，也不需要
    // 为选择会员发送一笔服务端写命令；正式保存时仅写预约单据本身。
    isMemberSelectorOpen.value = false
    window.dispatchEvent(new CustomEvent('cashier-v3:member-selector-selected', {
      detail: { context: memberSelectorContext.value, record }
    }))
    return { result: { status: 'success' } }
  }
  if (memberSelectorContext.value === 'cashier') {
    // 收银客户属于浏览器草稿。选择会员不能在立即结账前写入
    // cashier_workspace，也不能从服务端读取一份待回放的购物车投影。
    // 本次结账的唯一服务端写入发生在最终确认时提交的完整快照。
    applyLocalCashierCustomerSelection({ customerMode: 'member', member: record })
    // The member row is already authoritative for this browser draft. Close
    // the selector immediately so a late selector response cannot replace the
    // selected member with the guest root projection.
    isMemberSelectorOpen.value = false
    await nextTick()
    // 会员选择仍然只改浏览器本地草稿；单独读取一次权威会员摘要，避免
    // 选择器基础资料缺少欠款汇总而漏掉提醒。该 projection 不写工作台、
    // 不创建结账请求，也不会改变最终唯一 checkoutSnapshot。
    let selectedMember = record
    const memberId = memberDetailId(record)
    if (memberId) {
      const summaryResult = await requestCashierV3Action('query-cashier-member-summary', {
        memberId,
        // 选客后的摘要只补齐余额/欠款；不能以查询回包的默认游客根状态
        // 覆盖浏览器正在编辑的收银客户。
        preserveRootState: true,
        silent: true
      })
      if (isSucceededResult(summaryResult)) {
        const summary = responseDataBlock(summaryResult)?.memberSummary
        if (summary && typeof summary === 'object') {
          selectedMember = { ...record, ...summary }
          applyLocalCashierCustomerSelection({ customerMode: 'member', member: selectedMember })
        }
      }
    }
    const selectedDetail = localCashierCustomerSelectionDetail(selectedMember)
    if (memberDebtAmount(selectedMember) > 0) {
      // Customer source is the first post-selection interaction. Keep the
      // debt reminder queued so the two modal layers never overlap.
      pendingDebtReminderAfterSource.value = { member: selectedMember }
    }
    await completeMemberSelection(selectedDetail)
    return { result: { status: 'success' } }
  }
  const action = memberSelectorActions[memberSelectorContext.value]
  if (!action || !allowedMemberSelectorContexts.has(memberSelectorContext.value)) {
    return { result: { status: 'failed', code: 'MEMBER_SELECTOR_CONTEXT_INVALID', message: '会员选择来源无效。' } }
  }
  const result = await requestCashierV3Action(action, {
    memberId: memberDetailId(record),
    selectorContext: memberSelectorContext.value,
    selectorEntry: memberSelectorContext.value
  })
  if (isSucceededResult(result)) {
    // 选择会员命令已经在同一事务内返回最新 cashierDraft。当前收银场景默认
    // 一位顾客只在一台收银电脑上开单，直接回填这份草稿即可，不再为同一次
    // 选择紧接着重新读取完整工作台。
    applyCashierMemberDraft(result, record)
    await nextTick()
    const selectedDetail = {
      context: memberSelectorContext.value,
      record,
      stateContextId: String(state.stateContextId || ''),
      workspaceId: String(state.workspace?.id || ''),
      storeId: String(state.storeId || state.store?.id || '')
    }
    if (memberSelectorContext.value === 'cashier') {
      const selectedMember = cashierMember.value || record
      if (memberDebtAmount(selectedMember) > 0) {
        // Customer source is the first post-selection interaction. Keep the
        // debt reminder queued so the two modal layers never overlap.
        pendingDebtReminderAfterSource.value = { member: selectedMember }
        await completeMemberSelection(selectedDetail)
        return result
      }
    }
    await completeMemberSelection(selectedDetail)
  }
  return result
}

function emptyLocalCashierCart() {
  return {
    ...(state.cashier?.cart || {}),
    lines: [],
    summary: {
      selectedCount: 0,
      originalAmount: 0,
      discountAmount: 0,
      receivableAmount: 0,
      orderNote: '',
      hasOrderNote: false
    },
    primaryAction: '',
    primaryActionLabel: ''
  }
}

function applyLocalCashierCustomerSelection({ customerMode, member = null } = {}) {
  const isMember = customerMode === 'member' && memberDetailId(member)
  state.cashier = {
    ...(state.cashier || {}),
    customerMode: isMember ? 'member' : 'guest',
    member: isMember ? member : null,
    cart: emptyLocalCashierCart(),
    checkoutComposition: null,
    orderNote: ''
  }
  // 来源是工具栏上的前端值。切换客户后不允许遗留到下一位客户；它只会
  // 在点击立即结账时和其他前端草稿字段一起进入最终快照。
  toolbarBusinessSource.value = {
    displayNameSnapshot: '',
    primarySourceId: 0,
    secondarySourceId: 0,
    rewardAmountCents: 0
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:clear-toolbar-business-source'))
}

function localCashierCustomerSelectionDetail(record) {
  return {
    context: 'cashier',
    record,
    stateContextId: String(state.stateContextId || ''),
    workspaceId: String(state.workspace?.id || ''),
    storeId: String(state.currentStore?.id || state.storeId || state.store?.id || '')
  }
}

function applyCashierMemberDraft(result, fallbackMember = null) {
  const data = responseDataBlock(result)
  const draft = data?.cashierDraft
  if (!draft || typeof draft !== 'object' || !Array.isArray(draft.lines)
    || !draft.workspaceId || String(draft.workspaceId) !== String(state.workspace?.id || '')) {
    return false
  }
  const customerMode = String(data.customerMode || draft.customerMode || 'member')
  const selectedMember = data.member && typeof data.member === 'object'
    ? data.member
    : fallbackMember
  state.cashier = {
    ...(state.cashier || {}),
    customerMode,
    member: customerMode === 'member' ? selectedMember : null,
    cart: {
      ...(state.cashier?.cart || {}),
      lines: draft.lines,
      summary: draft.summary || state.cashier?.cart?.summary || {},
    },
    checkoutComposition: draft.checkoutComposition || null,
  }
  const workspaceVersion = (resultEnvelope(result)?.versions || [])
    .find((row) => String(row?.kind || '') === 'cashier_workspace'
      && String(row?.id || '') === String(draft.workspaceId))?.version
  if (Number.isInteger(Number(workspaceVersion)) && Number(workspaceVersion) > Number(state.workspace?.revision || 0)) {
    state.workspace = { ...(state.workspace || {}), revision: Number(workspaceVersion) }
  }
  return true
}

async function completeMemberSelection(detail) {
  const contextMatches = detail
    && String(detail.stateContextId || '') === String(state.stateContextId || '')
    && String(detail.workspaceId || '') === String(state.workspace?.id || '')
    && String(detail.storeId || '') === String(state.currentStore?.id || state.storeId || state.store?.id || '')
  // Cashier member selection is a browser-local draft operation. A selector
  // response can cross a projection refresh while the member row itself is
  // still valid; do not silently discard that selection and leave the page in
  // guest mode. The final checkout/card command still validates the member on
  // the server.
  if (!contextMatches) {
    if (detail?.context === 'cashier' && memberDetailId(detail.record)) {
      applyLocalCashierCustomerSelection({ customerMode: 'member', member: detail.record })
      window.dispatchEvent(new CustomEvent('cashier-v3:member-selector-selected', {
        detail: { context: detail.context, record: detail.record }
      }))
      return true
    }
    return false
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:member-selector-selected', {
    detail: { context: detail.context, record: detail.record }
  }))
  // 会员选择后的固定顺序：先确认客户来源，再提示该会员的待还欠款。
  // 欠款提醒由 checkout-business-source-confirmed 事件串行打开，避免两层
  // 弹窗重叠，也确保充值／销售都会冻结同一份来源快照。
  if (detail.context === 'cashier' && !pendingMemberTopAction.value && !pendingCashierWorkflowTarget.value) {
    window.dispatchEvent(new CustomEvent('cashier-v3:open-toolbar-business-source', {
      detail: { reason: 'member-selected' }
    }))
  }
  const pendingTopAction = pendingMemberTopAction.value
  if (pendingTopAction) {
    pendingMemberTopAction.value = null
    return openCashierMemberTopAction(pendingTopAction)
  }
  const pendingTarget = pendingCashierWorkflowTarget.value
  if (pendingTarget) {
    pendingCashierWorkflowTarget.value = null
    await router.push(pendingTarget.to)
  }
  return true
}

async function selectGuestOrderFromSelector() {
  // 游客切换和会员选择一样，只更新浏览器草稿。不要在结账前调用
  // set-guest-order 或生成服务端工作台版本。
  applyLocalCashierCustomerSelection({ customerMode: 'guest' })

  // 游客不能进入核销或项目替换；成功切换后统一回到结账收款。
  pendingCashierWorkflowTarget.value = null
  pendingMemberTopAction.value = null
  deferredMemberSelection.value = null
  if (route.name !== 'cashier-v3-cashier') {
    await router.push({ name: 'cashier-v3-cashier' })
  }
  return { result: { status: 'success' } }
}

async function createMemberFromSelector(payload = {}) {
  // 新增会员是 V3 写命令：由后端在当前账号／门店上下文内落库、去重并回填
  // 完整会员投影。头像文件不直接进入 JSON 命令，当前先保留在表单内，避免把
  // 不可稳定序列化的 File 对象写入幂等哈希；头像上传将在独立媒体命令接入。
  const { avatarFile: _avatarFile, ...serializablePayload } = payload && typeof payload === 'object'
    ? payload
    : {}
  return requestCashierV3Action('create-member', serializablePayload)
}

async function selectMemberCreatorServicePerson({ selectedRecord = null } = {}) {
  const selection = await openCashierV3QueryEntitySelector({
    entityType: 'person',
    title: '选择专属服务人',
    description: '只显示当前门店有效任职的人员。',
    multiple: false,
    selectedRecords: selectedRecord ? [selectedRecord] : [],
    scope: 'member_exclusive_service_staff',
    selectionContext: {
      storeScope: 'current_store',
      memberCreate: true
    }
  })
  return selection?.selected || null
}

function resolveReferrerSelector(record = null) {
  const resolve = referrerSelectorResolve
  referrerSelectorResolve = null
  if (resolve) resolve(record)
}

async function queryReferrerSelector(query = {}) {
  const querySequence = ++referrerSelectorQuerySequence
  referrerSelector.value = { ...referrerSelector.value, isLoading: true }
  try {
    const result = await requestCashierV3Action('query-member-selector', {
      ...query,
      selectorContext: 'member-referrer',
      selectorEntry: 'cashier'
    })
    if (querySequence !== referrerSelectorQuerySequence) return result
    const envelope = resultEnvelope(result)
    const projection = envelope?.data && typeof envelope.data === 'object' ? envelope.data : {}
    referrerSelector.value = {
      records: Array.isArray(projection.records) ? projection.records : [],
      total: Number.isFinite(Number(projection.total)) ? Number(projection.total) : 0,
      page: Math.max(1, Number(projection.page) || 1),
      pageSize: Math.max(1, Number(projection.pageSize) || 20),
      isLoading: false
    }
    return result
  } catch (error) {
    if (querySequence === referrerSelectorQuerySequence) {
      referrerSelector.value = { ...referrerSelector.value, isLoading: false }
    }
    throw error
  }
}

function selectReferrerFromSelector(record) {
  const memberId = Number(record?.memberId || record?.uid || record?.id || 0)
  if (memberId <= 0 || memberId === referrerSelectorExcludedMemberId) {
    return { result: { status: 'failed', code: 'MEMBER_REFERRER_INVALID', message: '请选择其他会员作为推荐人。' } }
  }
  isReferrerSelectorOpen.value = false
  resolveReferrerSelector(record)
  return { result: { status: 'success' } }
}

function closeReferrerSelector() {
  isReferrerSelectorOpen.value = false
  referrerSelectorQuerySequence += 1
  resolveReferrerSelector(null)
}

function selectMemberReferrer({ excludeMemberId = 0 } = {}) {
  referrerSelectorExcludedMemberId = Number(excludeMemberId) || 0
  referrerSelector.value = { records: [], total: 0, page: 1, pageSize: 20, isLoading: false }
  isReferrerSelectorOpen.value = true
  return new Promise((resolve) => {
    resolveReferrerSelector(null)
    referrerSelectorResolve = resolve
  })
}

function closeMemberSelector(detail = {}) {
  const context = memberSelectorContext.value
  isMemberSelectorOpen.value = false
  memberSelectorRequiresMember.value = false
  if (detail?.reason !== 'selected') {
    pendingCashierWorkflowTarget.value = null
    pendingMemberTopAction.value = null
    deferredMemberSelection.value = null
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:member-selector-closed', {
    detail: { context, ...(detail || {}) }
  }))
}

function memberDetailId(detail) {
  const member = detail?.member && typeof detail.member === 'object' ? detail.member : detail || {}
  return member.id || member.memberId || member.uid || member.userId || null
}

async function openCashierForMember(event = {}) {
  const detail = event?.detail || event || {}
  const requestedMemberId = String(detail.memberId || memberDetailId(detail.member) || '').trim()
  if (!requestedMemberId) return
  try {
    const result = await requestCashierV3Action('query-member-selector', {
      keyword: requestedMemberId,
      page: 1,
      pageSize: 20,
      selectorContext: 'cashier',
      selectorEntry: 'cashier'
    })
    const projection = resultEnvelope(result)?.data && typeof resultEnvelope(result).data === 'object'
      ? resultEnvelope(result).data
      : {}
    const records = Array.isArray(projection.records) ? projection.records : []
    const selectedMember = records.find((record) => String(memberDetailId(record) || '') === requestedMemberId)
      || (memberDetailId(detail.member) && String(memberDetailId(detail.member)) === requestedMemberId ? detail.member : null)
    if (!selectedMember) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', code: 'RESERVATION_MEMBER_NOT_FOUND', message: '未找到预约对应的会员，未打开收银来源选择。' }
      }))
      return
    }
    applyLocalCashierCustomerSelection({ customerMode: 'member', member: selectedMember })
    await nextTick()
    await completeMemberSelection(localCashierCustomerSelectionDetail(selectedMember))
  } catch (error) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: { status: 'failed', code: 'RESERVATION_CASHIER_HANDOFF_FAILED', message: String(error?.message || '会员收银开单初始化失败，请重试。') }
    }))
  }
}

function memberDebtAmount(member) {
  return Number(
    member?.outstandingDebtAmount
    ?? member?.totalDebtAmount
    ?? member?.debtAmount
    ?? 0
  )
}

function showMemberDetail(payload = {}) {
  const detail = payload?.detail || payload
  const requestedMemberId = detail.memberId || memberDetailId(detail.member) || memberDetailId(detail.record) || memberDetailId(detail.fallback)
  const backendDetail = state.memberCenter?.detail
  const matchingBackendDetail = backendDetail && (!requestedMemberId || String(memberDetailId(backendDetail)) === String(requestedMemberId))
    ? backendDetail
    : null
  activeMemberDetailId.value = requestedMemberId || memberDetailId(matchingBackendDetail) || memberDetailId(detail.member) || memberDetailId(detail.record) || null
  memberDetailFallback.value = matchingBackendDetail || detail.member || detail.record || detail.fallback || {}
  memberDetailInitialTab.value = detail.initialTab || 'profile'
  isMemberDetailOpen.value = true
  const openedMemberId = requestedMemberId || memberDetailId(memberDetailFallback.value)
  if (openedMemberId) {
    void loadMemberDetailTab({ memberId: openedMemberId, tab: memberDetailInitialTab.value, keyword: '' })
  }
  return openedMemberId
}

function closeMemberDetail() {
  memberDetailLoadSequence++
  isMemberDetailOpen.value = false
  memberDetailFallback.value = {}
  isMemberDetailLoading.value = false
  activeMemberDetailId.value = null
  memberDetailInitialTab.value = 'profile'
}

function closeDebtReminder() {
  isDebtReminderOpen.value = false
  debtReminderMember.value = null
  deferredMemberSelection.value = null
  pendingDebtReminderAfterSource.value = null
}

function handleCheckoutBusinessSourceSettled() {
  const pending = pendingDebtReminderAfterSource.value
  if (!pending?.member) return
  pendingDebtReminderAfterSource.value = null
  debtReminderMember.value = pending.member
  isDebtReminderOpen.value = true
}

async function cancelDebtReminder() {
  const deferred = deferredMemberSelection.value
  isDebtReminderOpen.value = false
  debtReminderMember.value = null
  deferredMemberSelection.value = null
  if (deferred) await completeMemberSelection(deferred)
}

async function openMemberDebt(memberId = null) {
  const selectedMember = [
    debtReminderMember.value,
    workflowMember.value,
    cashierMember.value,
    writeoffMember.value
  ].find((member) => member && (!memberId || String(memberDetailId(member)) === String(memberId)))
    || (memberId ? { id: memberId, memberId } : null)
  if (!selectedMember) {
    return { result: { status: 'failed', code: 'MEMBER_DEBT_CONTEXT_MISSING', message: '未找到需要查看欠款的会员。' } }
  }

  // 欠款明细只是一个只读投影；部分适配器仍会在响应到达时广播游客根状态。
  // 预先保留当前收银草稿，确保读取欠款不会改变正在办理的会员或购物车。
  const cashierDraftBeforeDebtRead = state.cashier && typeof state.cashier === 'object'
    ? { ...state.cashier }
    : null

  const deferred = deferredMemberSelection.value
  // “去还款”优先于充值／赠送等后续意图，不能在偿还欠款浮层上叠加第二个业务入口。
  pendingMemberTopAction.value = null
  closeDebtReminder()
  if (deferred) {
    window.dispatchEvent(new CustomEvent('cashier-v3:member-selector-closed', {
      detail: { context: deferred.context, reason: 'debt_repayment' }
    }))
  }
  isMemberDebtLoading.value = true
  try {
    // 欠款明细与充值是互斥工作流。读取欠款只消费本函数返回的快照，
    // 不允许任何尚未处理的充值浮层指令覆盖当前用户意图。
    rechargeSession.value = null
    const result = await requestCashierV3Action('open-member-debt-repayment', {
      memberId: memberDetailId(selectedMember),
      selectorEntry: 'cashier',
      // 欠款明细是局部只读快照。后端回包携带的根状态并不代表当前收银
      // 会员，若整包替换会把已选会员误还原成游客；只采用下方 debtSnapshot。
      preserveRootState: true,
      silent: true
    })
    if (cashierDraftBeforeDebtRead?.customerMode === 'member'
      && memberDetailId(cashierDraftBeforeDebtRead.member)) {
      state.cashier = cashierDraftBeforeDebtRead
    }
    // HTTP responses use the common { status, data: <V3 envelope> } wrapper,
    // while the preview adapter returns the envelope directly.  Keep this
    // projection read compatible with both so a valid debt is not rendered as
    // an empty list merely because of the transport wrapper.
    const envelope = result?.result && typeof result.result === 'object'
      ? result
      : (result?.data?.result && typeof result.data.result === 'object' ? result.data : null)
    const snapshot = envelope?.data?.debtSnapshot || result?.data?.debtSnapshot
    if (isSucceededResult(result) && snapshot && typeof snapshot === 'object') {
      state.memberCenter = {
        ...(state.memberCenter || {}),
        debtSnapshot: snapshot
      }
      // 欠款入口与弹窗必须显示同一份服务端快照，不能继续保留选客时的旧汇总值。
      applyAuthoritativeDebtSnapshot(snapshot)
    }
    // 读取欠款时服务端会回传最新根状态。根状态替换可能清理所有浏览器内弹层，
    // 因此必须在读取完成后再打开，避免刚打开就被同一次响应关闭。
    activeDebtMember.value = snapshot?.member && typeof snapshot.member === 'object'
      ? snapshot.member
      : selectedMember
    isMemberDebtOpen.value = true
    return result
  } finally {
    isMemberDebtLoading.value = false
  }
}

async function handleOpenMemberDebt(event = {}) {
  const detail = event.detail || {}
  const result = await openMemberDebt(detail.memberId || null)
  const debtId = String(detail.debtId || detail.debtRecordId || '')
  initialDebtRecordId.value = isSucceededResult(result) ? debtId : ''
  return result
}

function closeMemberDebt() {
  if (isDebtRepaymentPreparing.value) return
  isMemberDebtOpen.value = false
  activeDebtMember.value = null
  initialDebtRecordId.value = ''
}

async function openDebtRepaymentCheckout(preparationRequestId, debtRecordId, checkoutSnapshot) {
  const handoff = {
    businessType: 'debt_repayment',
    preparationRequestId,
    debtRecordId,
    // 唯一来源：本次 prepare 命令返回的服务端持久化结账快照。
    checkoutSnapshot
  }
  isMemberDebtOpen.value = false
  activeDebtMember.value = null
  initialDebtRecordId.value = ''
  if (route.name !== 'cashier-v3-cashier') {
    // 跨页面事件会在工作台挂载前丢失。仅保存本次已准备草稿的交接标识，
    // 收银工作台挂载后自行消费并重新读取权威结账快照。
    window.sessionStorage.setItem('cashier-v3:prepared-checkout-handoff', JSON.stringify(handoff))
    window.location.hash = '#/cashier'
    return
  }
  window.dispatchEvent(new CustomEvent('cashier-v3:open-prepared-checkout', {
    detail: handoff
  }))
}

async function prepareDebtRepayment(payload = {}) {
  if (isDebtRepaymentPreparing.value) return { success: false, message: '正在准备收款，请勿重复操作。' }
  const preparationRequestId = createCashierV3CommandId('CHECKOUT_PREPARE')
  isDebtRepaymentPreparing.value = true
  try {
    const prepareAction = payload.rechargeDebt === true
      ? 'prepare-recharge-debt-repayment'
      : 'prepare-debt-repayment'
    const result = await requestCashierV3Action(prepareAction, {
      ...payload,
      // 欠款补交的日期由补交弹层单独选择，并在准备草稿时冻结；不能用
      // 收银工具栏的当前日期覆盖，否则历史补交会被错误落在当天。
      businessDate: String(payload.businessDate || toolbarBusinessDate.value || ''),
      preparationRequestId,
      idempotencyKey: preparationRequestId,
      silent: true
    })
    // 准备命令已经在服务端原子落下统一结账草稿；响应中的准备标识就是
    // 本次交接凭证。不要再用一次根状态刷新决定是否能跳转，否则会员欠款
    // 读模型的刷新可能清空弹层，却把已存在的收款草稿留在后台。
    const responseEnvelope = resultEnvelope(result)
    const prepared = responseEnvelope?.data?.debtRepaymentPreparation
      || result?.data?.debtRepaymentPreparation
      || result?.result?.data?.debtRepaymentPreparation
    const checkoutSnapshot = responseEnvelope?.data?.checkoutSnapshot
      || result?.data?.checkoutSnapshot
      || result?.result?.data?.checkoutSnapshot
    const preparedBusinessSucceeded = ['succeeded', 'success'].includes(resultStatus(result))
    if (preparedBusinessSucceeded && prepared?.preparationRequestId && checkoutSnapshot) {
      await openDebtRepaymentCheckout(
        String(prepared.preparationRequestId),
        payload.debtRecordId,
        checkoutSnapshot
      )
      return result
    }
    // 服务端无法给出同一笔锁定快照时禁止进入收款页；不回读根状态、不猜测
    // 历史草稿，也不以任何浏览器版本作为兜底。
    return result
  } finally {
    isDebtRepaymentPreparing.value = false
  }
}

async function loadMemberDetailTab(payload = {}) {
  if (!payload.memberId) return { success: false, message: '未找到会员。' }
  const loadSequence = ++memberDetailLoadSequence
  isMemberDetailLoading.value = true
  try {
    const result = await requestCashierV3Action('load-member-detail-tab', {
      memberId: payload.memberId,
      tab: payload.tab,
      keyword: String(payload.keyword || '').trim(),
      status: String(payload.status || '').trim(),
      dateFrom: String(payload.dateFrom || '').trim(),
      dateTo: String(payload.dateTo || '').trim(),
      silent: true
    })
    const detail = memberDetailFromResponse(result)
    if (loadSequence === memberDetailLoadSequence
      && isMemberDetailOpen.value
      && isSucceededResult(result)
      && detail
      && String(memberDetailId(detail)) === String(activeMemberDetailId.value)) {
      memberDetailFallback.value = mergeMemberDetail(memberDetailFallback.value, detail)
    }
    return result
  } finally {
    if (loadSequence === memberDetailLoadSequence) isMemberDetailLoading.value = false
  }
}

function memberDetailFromResponse(response = {}) {
  const envelope = response?.result && typeof response.result === 'object'
    ? response
    : (response?.data && typeof response.data === 'object' ? response.data : {})
  const detail = envelope?.data?.detail
  return detail && typeof detail === 'object' ? detail : null
}

function mergeMemberDetail(current = {}, incoming = {}) {
  const currentMember = current?.member && typeof current.member === 'object' ? current.member : current
  const incomingMember = incoming?.member && typeof incoming.member === 'object' ? incoming.member : {}
  return {
    ...current,
    ...incoming,
    member: { ...currentMember, ...incomingMember },
    summary: {
      ...(current?.summary && typeof current.summary === 'object' ? current.summary : {}),
      ...(incoming?.summary && typeof incoming.summary === 'object' ? incoming.summary : {})
    }
  }
}

async function handleMemberDetailAction(payload = {}) {
  const action = payload.action?.code || payload.action?.action || payload.actionCode
  const scope = String(payload.scope || '')
  if (!memberDetailActionsByScope[scope]?.has(action)) {
    return { result: { status: 'failed', code: 'MEMBER_DETAIL_ACTION_NOT_ALLOWED', message: '该会员操作未进入前端允许清单。' } }
  }
  const currentMemberId = activeMemberDetailId.value || memberDetailId(memberDetail.value)
  if (!currentMemberId || (payload.memberId && String(payload.memberId) !== String(currentMemberId))) {
    return { result: { status: 'failed', code: 'MEMBER_DETAIL_CONTEXT_MISMATCH', message: '会员详情已经变化，请重新打开后操作。' } }
  }
  if (scope === 'debt-record' && action === 'open-debt-settlements') {
    isMemberDetailOpen.value = false
    return openMemberDebt(currentMemberId)
  }
  if (scope === 'sales-order' && action === 'open-sales-order-detail') {
    const orderId = payload.record?.orderId || payload.record?.salesOrderId || payload.record?.id
    if (!orderId) {
      return { result: { status: 'failed', code: 'SALES_ORDER_ID_MISSING', message: '销售订单标识尚未加载，请刷新会员详情后重试。' } }
    }
    const result = await requestCashierV3Action(action, { orderId })
    if (!isSucceededResult(result)) return result
    const projection = salesOrderProjectionFromResult(result)
    const detail = projection?.salesOrderDetail
    const detailOrderId = detail?.id || detail?.orderId || detail?.salesOrderId
    if (!detail || String(detailOrderId) !== String(orderId)) {
      return { result: { status: 'failed', code: 'SALES_ORDER_DETAIL_INVALID', message: '销售订单详情尚未完整返回，请稍后重试。' } }
    }
    state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
    isMemberDetailOpen.value = false
    await router.push({ name: 'cashier-v3-order-center' })
    await nextTick()
    window.dispatchEvent(new CustomEvent('cashier-v3:open-sales-order-detail', {
      detail: { orderId, salesOrderId: orderId }
    }))
    return result
  }
  return requestCashierV3Action(action, {
    memberId: currentMemberId,
    detailScope: payload.scope,
    detailTab: payload.activeTab,
    relatedRecordId: payload.record?.id || payload.record?.recordId || null,
    relatedContentId: payload.content?.id || payload.content?.recordId || null
  })
}

function openQueryEntitySelector(payload = {}) {
  const detail = payload?.detail || payload
  const entityType = detail.entityType
  if (!['person', 'store', 'organization'].includes(entityType)) return
  queryEntitySelectorRequest.value = {
    requestId: detail.requestId,
    entityType,
    title: detail.title || '',
    description: detail.description || '',
    selectorEntry: detail.selectorEntry || 'cashier',
    multiple: detail.multiple === true,
    selectedRecords: Array.isArray(detail.selectedRecords) ? detail.selectedRecords : [],
    selectionContext: detail.selectionContext || {}
  }
  isQueryEntitySelectorOpen.value = true
  void queryQueryEntities({ page: 1, pageSize: 20, keyword: '' })
}

async function queryQueryEntities(query = {}) {
  const request = queryEntitySelectorRequest.value || {}
  const requestId = request.requestId
  const response = await requestCashierV3Action('query-query-entities', {
    entityType: request.entityType,
    selectorEntry: request.selectorEntry,
    selectorContext: request.selectionContext || {},
    keyword: query.keyword || '',
    page: query.page || 1,
    pageSize: query.pageSize || 20,
    silent: true
  })
  const envelope = response?.data?.result ? response.data : response
  const page = envelope?.data
  if (queryEntitySelectorRequest.value?.requestId !== requestId || !page || !Array.isArray(page.records)) {
    return response
  }
  state.queryEntitySelector = {
    ...(state.queryEntitySelector || {}),
    [request.entityType]: {
      records: page.records,
      total: Math.max(0, Number(page.total) || 0),
      page: Math.max(1, Number(page.page) || 1),
      pageSize: Math.max(1, Number(page.pageSize) || 20),
      isLoading: false
    }
  }
  return response
}

async function selectQueryEntity(selection) {
  const request = queryEntitySelectorRequest.value
  const records = Array.isArray(selection) ? selection : [selection]
  const record = records[0]
  const recordId = record?.id || record?.staffId || record?.storeId || record?.organizationId
  if (!request || !recordId) return { success: false, message: '未取得有效的选择记录，请重新查询。' }
  window.dispatchEvent(new CustomEvent('cashier-v3:query-entity-selector-selected', {
    detail: {
      requestId: request.requestId,
      entityType: request.entityType,
      record,
      records: request.multiple ? records : undefined
    }
  }))
  return { success: true }
}

function closeQueryEntitySelector(detail = {}) {
  const request = queryEntitySelectorRequest.value
  isQueryEntitySelectorOpen.value = false
  queryEntitySelectorRequest.value = null
  if (!request) return
  window.dispatchEvent(new CustomEvent('cashier-v3:query-entity-selector-closed', {
    detail: {
      requestId: request.requestId,
      entityType: request.entityType,
      ...(detail || {})
    }
  }))
}

function serviceOrderId(serviceOrder = {}) {
  return serviceOrder?.id || serviceOrder?.serviceOrderId || serviceOrder?.serviceSessionId || serviceOrder?.serviceNo || null
}

function serviceOrderVersion(serviceOrder = {}) {
  return serviceOrder?.revision ?? serviceOrder?.recordVersion ?? serviceOrder?.version ?? null
}

function reservationId(reservation = {}) {
  return reservation?.id || reservation?.reservationId || reservation?.reservationNo || null
}

function reservationVersion(reservation = {}) {
  return reservation?.revision ?? reservation?.recordVersion ?? reservation?.version ?? null
}

function normalizedRoomAssignmentScope(source = {}) {
  const value = typeof source === 'string'
    ? source
    : source?.assignmentScope || source?.assignment_scope
  return ['reservation_plan', 'active_service'].includes(value) ? value : ''
}

function normalizedRoomAssignmentMode(source = {}) {
  const value = typeof source === 'string'
    ? source
    : source?.mode || source?.assignmentMode || source?.assignment_mode
  if (['assign', 'change', 'remove'].includes(value)) return value
  if (['assign-room', 'change-room', 'remove-room'].includes(value)) return value.replace('-room', '')
  return ''
}

function roomAssignmentSubjectKey(scope, reservation = {}, serviceOrder = {}) {
  if (scope === 'reservation_plan') {
    const id = reservationId(reservation)
    return id ? `reservation:${id}` : ''
  }
  if (scope === 'active_service') {
    const id = serviceOrderId(serviceOrder)
    return id ? `service_order:${id}` : ''
  }
  return ''
}

function roomAssignmentSubjectFrom(source = {}) {
  const scope = normalizedRoomAssignmentScope(source)
  const reservation = source?.reservation && typeof source.reservation === 'object' ? source.reservation : {}
  const serviceOrder = source?.serviceOrder && typeof source.serviceOrder === 'object'
    ? source.serviceOrder
    : source?.service && typeof source.service === 'object'
      ? source.service
      : {}
  const reservationSource = {
    ...reservation,
    id: source?.reservationId || source?.reservation_id || reservationId(reservation),
    revision: source?.reservationVersion ?? source?.reservationRevision ?? reservationVersion(reservation)
  }
  const serviceOrderSource = {
    ...serviceOrder,
    id: source?.serviceOrderId || source?.service_order_id || source?.serviceSessionId || serviceOrderId(serviceOrder),
    revision: source?.serviceOrderVersion ?? source?.serviceOrderRevision ?? serviceOrderVersion(serviceOrder)
  }
  return {
    scope,
    reservation: reservationSource,
    serviceOrder: serviceOrderSource,
    key: roomAssignmentSubjectKey(scope, reservationSource, serviceOrderSource)
  }
}

function serviceLineId(line = {}) {
  return line?.id || line?.lineId || line?.serviceLineId || line?.projectLineId || null
}

function roomAssignmentRoomId(room = {}) {
  return room?.id || room?.roomId || room?.currentRoomId || null
}

function roomAssignmentRoomVersion(room = {}) {
  return room?.revision ?? room?.recordVersion ?? room?.roomRevision ?? room?.version ?? null
}

function hasMatchingVersionedContext(contexts, kind, id, version) {
  if (!id || version === undefined || version === null || !Array.isArray(contexts) || !contexts.length) return false
  const context = contexts.find((item) => item?.kind === kind && String(item?.id) === String(id))
  if (!context) return false
  const expectedVersion = context.expectedVersion ?? context.revision ?? context.recordVersion
  return expectedVersion !== undefined && expectedVersion !== null && String(expectedVersion) === String(version)
}

function hasMatchingServiceCompletionContexts(serviceOrder = serviceCompletionOrder.value) {
  const id = serviceOrderId(serviceOrder)
  const snapshotOrder = serviceCompletionSnapshot.value?.serviceOrder
  if (!id || !snapshotOrder || String(serviceOrderId(snapshotOrder)) !== String(id)) return false

  const contexts = serviceCompletionSnapshot.value?.commandContexts
  return hasMatchingVersionedContext(contexts, 'service_order', id, serviceOrderVersion(serviceOrder))
}

function hasMatchingRoomAssignmentContexts(
  scope = roomAssignmentScope.value,
  reservation = roomAssignmentReservation.value,
  serviceOrder = roomAssignmentServiceOrder.value,
  currentRoom = roomAssignmentCurrentRoom.value,
  mode = roomAssignmentMode.value
) {
  const contexts = roomAssignment.value?.commandContexts
  const subjectMatches = scope === 'reservation_plan'
    ? hasMatchingVersionedContext(contexts, 'reservation', reservationId(reservation), reservationVersion(reservation))
    : scope === 'active_service'
      ? hasMatchingVersionedContext(contexts, 'service_order', serviceOrderId(serviceOrder), serviceOrderVersion(serviceOrder))
      : false
  if (!subjectMatches) return false

  const currentRoomId = roomAssignmentRoomId(currentRoom)
  if (!['change', 'remove'].includes(mode)) return true
  if (!currentRoomId || roomAssignmentRoomVersion(currentRoom) === null || roomAssignmentRoomVersion(currentRoom) === undefined) return false
  return hasMatchingVersionedContext(contexts, 'room', currentRoomId, roomAssignmentRoomVersion(currentRoom))
}

function showServiceCompletionContractError() {
  uiFeedback.value = {
    kind: 'error',
    title: '服务确认数据未就绪',
    message: '本次服务的确认快照或版本信息不完整，暂不能提交。请刷新后重新打开本次服务单。'
  }
}

function showRoomAssignmentContractError() {
  uiFeedback.value = {
    kind: 'error',
    title: '房间安排数据未就绪',
    message: '本次预约／服务、当前房间或版本信息不完整，暂不能保存。请刷新房态后重新打开。'
  }
}

function serviceCompletionPreparationId(detail = {}) {
  return detail?.preparationRequestId || detail?.prepareRequestId || detail?.requestId || ''
}

/**
 * 每次“准备结束服务”都带一个前端请求号。后端必须原样回传到
 * state.serviceCompletion 和 overlay。Shell 只接受全局最后一次“准备结束服务”
 * 意图；即使用户从 A 服务单迅速切到 B，A 的晚到响应也不能再打开覆盖层。
 */
function registerServiceCompletionPreparation(event = {}) {
  const detail = event.detail || event || {}
  const preparationRequestId = serviceCompletionPreparationId(detail)
  const requestedServiceOrderId = detail.serviceOrderId || detail.serviceSessionId
  if (!preparationRequestId || !requestedServiceOrderId) return

  serviceCompletionReturnFocusTarget = document.activeElement instanceof HTMLElement
    ? document.activeElement
    : null

  serviceCompletionPreparationRequests.value = {
    ...serviceCompletionPreparationRequests.value,
    [preparationRequestId]: {
      serviceOrderId: requestedServiceOrderId,
      createdAt: Date.now()
    }
  }
  latestServiceCompletionPreparationIntent.value = {
    preparationRequestId,
    serviceOrderId: requestedServiceOrderId
  }
}

function isLatestServiceCompletionPreparation(detail = {}) {
  const preparationRequestId = serviceCompletionPreparationId(detail)
  const requestedServiceOrderId = detail.serviceOrderId || detail.serviceSessionId || serviceOrderId(detail.serviceOrder || detail.service)
  if (!preparationRequestId || !requestedServiceOrderId) return false
  const request = serviceCompletionPreparationRequests.value[preparationRequestId]
  const latestIntent = latestServiceCompletionPreparationIntent.value
  return Boolean(request
    && String(request.serviceOrderId) === String(requestedServiceOrderId)
    && latestIntent
    && latestIntent.preparationRequestId === preparationRequestId
    && String(latestIntent.serviceOrderId) === String(requestedServiceOrderId))
}

/**
 * 服务结束确认是跨菜单的同一业务步骤：收银、预约、房间和核销都只能打开
 * 这一个覆盖层。具体项目、核销金额和两类业绩仍只读后端返回的服务单快照。
 */
function openServiceCompletion(payload = {}, options = {}) {
  const detail = payload?.detail || payload || {}
  // 恢复入口只能由下方监听后端根状态的 watcher 显式传入，普通 overlay 即使
  // 伪造 recovery 字段也不能绕过 preparationRequestId 校验。
  const isRecovery = options.recovery === true
  const requestedPreparationId = serviceCompletionPreparationId(detail)
  const requestedId = detail.serviceOrderId || detail.serviceSessionId || serviceOrderId(detail.serviceOrder || detail.serviceSession || detail.service)
    || (isRecovery ? serviceOrderId(serviceCompletionSnapshot.value?.serviceOrder) : null)
  const snapshotPreparationId = serviceCompletionSnapshot.value?.preparationRequestId || ''
  const preparationMatchesSnapshot = isRecovery || (requestedPreparationId && snapshotPreparationId === requestedPreparationId)
  if (!requestedId || (!isRecovery && !isLatestServiceCompletionPreparation(detail)) || !preparationMatchesSnapshot) {
    uiFeedback.value = {
      kind: 'error',
      title: '服务确认响应已过期',
      message: '本次服务的确认结果不是当前操作返回的最新快照，已忽略。请重新打开本次服务单。'
    }
    return { success: false, message: '服务确认响应已过期。' }
  }
  isServiceLineCompletionEditorOpen.value = false
  isServiceLineCraftsmenOpen.value = false
  activeServiceCompletionLineId.value = null
  activeServiceCompletionId.value = requestedId
  if (!hasMatchingServiceCompletionSnapshot.value || !hasMatchingServiceCompletionContexts()) {
    isServiceCompletionOpen.value = false
    showServiceCompletionContractError()
    return { success: false, message: '服务确认快照未按 V3 契约返回。' }
  }
  isServiceCompletionOpen.value = true
  return { success: true }
}

function roomAssignmentPreparationId(detail = {}) {
  return detail?.roomAssignmentPreparationId
    || detail?.preparationRequestId
    || detail?.prepareRequestId
    || detail?.requestId
    || ''
}

function registerRoomAssignmentPreparation(event = {}) {
  const detail = event.detail || event || {}
  const preparationRequestId = roomAssignmentPreparationId(detail)
  const subject = roomAssignmentSubjectFrom(detail)
  const mode = normalizedRoomAssignmentMode(detail)
  if (!preparationRequestId || !subject.key || !mode) return

  roomAssignmentPreparationRequests.value = {
    ...roomAssignmentPreparationRequests.value,
    [preparationRequestId]: {
      assignmentScope: subject.scope,
      subjectKey: subject.key,
      mode,
      roomId: detail.roomId || detail.currentRoomId || null,
      createdAt: Date.now()
    }
  }
  latestRoomAssignmentPreparationIntent.value = {
    preparationRequestId,
    assignmentScope: subject.scope,
    subjectKey: subject.key,
    mode
  }
}

function isLatestRoomAssignmentPreparation(detail = {}) {
  const preparationRequestId = roomAssignmentPreparationId(detail)
  const subject = roomAssignmentSubjectFrom(detail)
  const mode = normalizedRoomAssignmentMode(detail)
  if (!preparationRequestId || !subject.key || !mode) return false
  const request = roomAssignmentPreparationRequests.value[preparationRequestId]
  const latestIntent = latestRoomAssignmentPreparationIntent.value
  return Boolean(request
    && request.assignmentScope === subject.scope
    && request.subjectKey === subject.key
    && request.mode === mode
    && latestIntent
    && latestIntent.preparationRequestId === preparationRequestId
    && latestIntent.assignmentScope === subject.scope
    && latestIntent.subjectKey === subject.key
    && latestIntent.mode === mode)
}

function closeServiceCompletion() {
  isServiceCompletionOpen.value = false
  activeServiceCompletionId.value = null
  isServiceCompletionActionSubmitting.value = false
  serviceCompletionActionIds.value = {}
  closeServiceLineCompletionEditor()
  closeServiceLineCraftsmen()
  serviceCompletionReturnFocusTarget = null
}

function openRoomAssignment(payload = {}) {
  const detail = payload?.detail || payload || {}
  const requestedPreparationId = roomAssignmentPreparationId(detail)
  const requestedSubject = roomAssignmentSubjectFrom(detail)
  const requestedSubjectKey = requestedSubject.key
  const requestedMode = normalizedRoomAssignmentMode(detail)
  const snapshotPreparationId = roomAssignmentPreparationId(roomAssignment.value)
  const snapshotMode = normalizedRoomAssignmentMode(roomAssignment.value)
  if (!requestedSubjectKey
    || !requestedMode
    || !isLatestRoomAssignmentPreparation(detail)
    || !requestedPreparationId
    || requestedPreparationId !== snapshotPreparationId
    || requestedMode !== snapshotMode) {
    uiFeedback.value = {
      kind: 'error',
      title: '房间分配响应已过期',
      message: '本次房间分配结果不是当前操作返回的最新快照，已忽略。请从最新房态重新打开。'
    }
    return { success: false, message: '房间安排响应已过期。' }
  }
  activeRoomAssignmentSubjectKey.value = requestedSubjectKey
  activeRoomAssignmentPreparationId.value = requestedPreparationId
  activeRoomAssignmentMode.value = requestedMode
  if (!hasMatchingRoomAssignmentSnapshot.value || !hasMatchingRoomAssignmentContexts(
    requestedSubject.scope,
    requestedSubject.reservation,
    requestedSubject.serviceOrder,
    roomAssignmentCurrentRoom.value,
    activeRoomAssignmentMode.value
  )) {
    isRoomAssignmentOpen.value = false
    showRoomAssignmentContractError()
    return { success: false, message: '房间安排快照未按 V3 契约返回。' }
  }
  isRoomAssignmentOpen.value = true
  return { success: true }
}

function closeRoomAssignment() {
  isRoomAssignmentOpen.value = false
  activeRoomAssignmentSubjectKey.value = ''
  activeRoomAssignmentPreparationId.value = ''
  activeRoomAssignmentMode.value = ''
  isRoomAssignmentSubmitting.value = false
}

function handleStateContextChanged() {
  // 账号、强制门店或浏览器工作台会话切换后，旧根状态已经被替换；
  // 所有仅存在于浏览器内的编辑／弹层现场也必须一起关闭，不能带到新上下文。
  closeMemberSelector({ reason: 'state-context-changed' })
  closeMemberDetail()
  closeDebtReminder()
  isMemberDebtOpen.value = false
  activeDebtMember.value = null
  initialDebtRecordId.value = ''
  isDebtRepaymentPreparing.value = false
  closeQueryEntitySelector({ reason: 'state-context-changed' })
  closeServiceCompletion()
  closeRoomAssignment()
  roomAssignmentPreparationRequests.value = {}
  latestRoomAssignmentPreparationIntent.value = null
  serviceCompletionPreparationRequests.value = {}
  latestServiceCompletionPreparationIntent.value = null
}

function roomAssignmentCommandPayload() {
  const scope = roomAssignmentScope.value
  const reservation = roomAssignmentReservation.value
  const serviceOrder = roomAssignmentServiceOrder.value
  const currentRoom = roomAssignmentCurrentRoom.value
  const validatedMode = activeRoomAssignmentMode.value
  if (!hasMatchingRoomAssignmentSnapshot.value
    || !validatedMode
    || roomAssignmentMode.value !== validatedMode
    || !hasMatchingRoomAssignmentContexts(scope, reservation, serviceOrder, currentRoom, validatedMode)) return {}
  return {
    assignmentScope: scope,
    mode: validatedMode,
    reservationId: reservationId(reservation),
    reservationVersion: reservationVersion(reservation),
    serviceOrderId: serviceOrderId(serviceOrder),
    serviceOrderVersion: serviceOrderVersion(serviceOrder),
    currentRoomId: roomAssignmentRoomId(currentRoom),
    currentRoomVersion: roomAssignmentRoomVersion(currentRoom),
    commandContexts: roomAssignment.value.commandContexts
  }
}

async function requestRoomAssignment(request = {}) {
  if (request.action !== 'save-service-room-assignment') {
    return { result: { status: 'failed', message: '该房间操作暂未接入。' } }
  }
  const assignmentPayload = roomAssignmentCommandPayload()
  const assignmentSubjectKey = roomAssignmentSubjectKey(
    assignmentPayload.assignmentScope,
    { id: assignmentPayload.reservationId },
    { id: assignmentPayload.serviceOrderId }
  )
  if (!assignmentSubjectKey || !assignmentPayload.commandContexts?.length) {
    showRoomAssignmentContractError()
    return { result: { status: 'failed', code: 'ROOM_ASSIGNMENT_CONTEXT_MISSING', message: '房间安排数据未就绪，请刷新后重试。' } }
  }
  if (isRoomAssignmentSubmitting.value) {
    return { result: { status: 'failed', code: 'ROOM_ASSIGNMENT_PENDING', message: '房间分配正在保存，请勿重复提交。' } }
  }

  const payload = request.payload || {}
  const targetRoomKey = payload.targetRoomId === null ? 'unassigned' : payload.targetRoomId || 'missing-target'
  const subjectVersion = assignmentPayload.assignmentScope === 'reservation_plan'
    ? assignmentPayload.reservationVersion
    : assignmentPayload.serviceOrderVersion
  const actionKey = `${assignmentSubjectKey}:${subjectVersion}:${assignmentPayload.mode}:${targetRoomKey}`
  const idempotencyKey = roomAssignmentActionIds.value[actionKey]
    || createCashierV3CommandId('ROOM_ASSIGNMENT')
  roomAssignmentActionIds.value = {
    ...roomAssignmentActionIds.value,
    [actionKey]: idempotencyKey
  }

  isRoomAssignmentSubmitting.value = true
  try {
    const result = await requestCashierV3Action(request.action, {
      ...payload,
      // 安排范围、权威对象版本与 commandContexts 只能来自当前后端快照；子组件不得覆盖。
      ...assignmentPayload,
      // 保存只使用本轮“准备房间安排”已校验的模式，不能接受子组件的任意 mode。
      mode: assignmentPayload.mode,
      idempotencyKey
    })
    if (isSucceededResult(result)) {
      closeRoomAssignment()
    } else if (resultRequiresRefresh(result) || ['failed', 'conflict'].includes(resultStatus(result))) {
      roomAssignmentActionIds.value = { ...roomAssignmentActionIds.value, [actionKey]: null }
    }
    return result
  } finally {
    isRoomAssignmentSubmitting.value = false
  }
}

function openServiceLineCompletionEditor(payload = {}) {
  const detail = payload?.detail || payload || {}
  const lineId = detail.lineId || serviceLineId(detail.line)
  if (!lineId) return { success: false, message: '未找到需要确认的服务项目。' }
  activeServiceCompletionLineId.value = lineId
  isServiceLineCraftsmenOpen.value = false
  isServiceLineCompletionEditorOpen.value = true
  return { success: true }
}

function closeServiceLineCompletionEditor() {
  isServiceLineCompletionEditorOpen.value = false
  if (!isServiceLineCraftsmenOpen.value) activeServiceCompletionLineId.value = null
}

function openServiceLineCraftsmen(payload = {}) {
  const detail = payload?.detail || payload || {}
  const lineId = detail.lineId || serviceLineId(detail.line)
  if (!lineId) return { success: false, message: '未找到需要确认的服务项目。' }
  activeServiceCompletionLineId.value = lineId
  isServiceLineCompletionEditorOpen.value = false
  isServiceLineCraftsmenOpen.value = true
  return { success: true }
}

function closeServiceLineCraftsmen() {
  isServiceLineCraftsmenOpen.value = false
  if (!isServiceLineCompletionEditorOpen.value) activeServiceCompletionLineId.value = null
}

function serviceCompletionCommandPayload(serviceOrder = serviceCompletionOrder.value) {
  const id = serviceOrderId(serviceOrder)
  if (!id || !hasMatchingServiceCompletionSnapshot.value || !hasMatchingServiceCompletionContexts(serviceOrder)) return {}
  const suppliedContexts = Array.isArray(serviceCompletionSnapshot.value?.commandContexts)
    ? serviceCompletionSnapshot.value.commandContexts
    : []
  return {
    serviceOrderId: id,
    serviceOrderVersion: serviceOrderVersion(serviceOrder),
    commandContexts: suppliedContexts
  }
}

const RESULT_ENVELOPE_STATUSES = new Set(['succeeded', 'success', 'failed', 'conflict', 'result_unknown'])

function resultEnvelope(result) {
  const nested = result?.data
  if (nested && typeof nested === 'object') {
    const nestedStatus = nested?.result?.status || nested?.status || ''
    if ((nested.result && typeof nested.result === 'object') || RESULT_ENVELOPE_STATUSES.has(String(nestedStatus))) {
      return nested
    }
  }
  return result || {}
}

function resultStatus(result) {
  const response = resultEnvelope(result)
  return response?.result?.status || response?.status || ''
}

function resultRequiresRefresh(result) {
  const response = resultEnvelope(result)
  return result?.stateIgnored === true
    || result?.requiresRefresh === true
    || response?.stateIgnored === true
    || response?.requiresRefresh === true
}

function isSucceededResult(result) {
  // 业务可能已经成功，但完整根投影被拒收或需要刷新；不能把它当成
  // 页面现场已应用，否则会导航或关闭当前编辑弹层。
  return ['succeeded', 'success'].includes(resultStatus(result)) && !resultRequiresRefresh(result)
}

async function requestServiceCompletionAction({ action, payload = {} }) {
  if (!allowedServiceCompletionActions.has(action)) {
    return { result: { status: 'failed', code: 'SERVICE_COMPLETION_ACTION_NOT_ALLOWED', message: '该服务确认操作未进入前端允许清单。' } }
  }
  const completionPayload = serviceCompletionCommandPayload()
  const completionId = serviceOrderId(serviceCompletionOrder.value)
  if (!completionId || !completionPayload.commandContexts?.length) {
    showServiceCompletionContractError()
    return { result: { status: 'failed', code: 'SERVICE_COMPLETION_CONTEXT_MISSING', message: '服务确认数据未就绪，请刷新后重试。' } }
  }

  const mutatingActions = new Set([
    'confirm-service-completion',
    'retry-service-completion',
    'continue-service-checkout',
    'finish-service-completion',
    'return-to-service-edit',
    'save-service-line-completion',
    'save-service-line-craftsmen'
  ])
  const isMutatingAction = mutatingActions.has(action)
  if (isMutatingAction && isServiceCompletionActionSubmitting.value) {
    return { result: { status: 'failed', code: 'SERVICE_COMPLETION_ACTION_PENDING', message: '本次服务正在处理中，请勿重复提交。' } }
  }

  const actionKey = `${completionId}:${completionPayload.serviceOrderVersion}:${serviceCompletionSnapshot.value?.serviceOrder?.completion?.snapshotToken || ''}:${action}`
  const suppliedPayload = payload || {}
  const idempotencyKey = suppliedPayload.idempotencyKey
    || serviceCompletionActionIds.value[actionKey]
    || createCashierV3CommandId('SERVICE_ACTION')
  if (isMutatingAction && !suppliedPayload.idempotencyKey) {
    serviceCompletionActionIds.value = {
      ...serviceCompletionActionIds.value,
      [actionKey]: idempotencyKey
    }
  }

  if (isMutatingAction) isServiceCompletionActionSubmitting.value = true
  let result
  try {
    result = await requestCashierV3Action(action, {
      ...completionPayload,
      ...suppliedPayload,
      ...(isMutatingAction ? { idempotencyKey } : {})
    })
    if (!isSucceededResult(result)) return result

    if (action === 'continue-service-checkout') {
      if (route.name !== 'cashier-v3-cashier') {
        await router.push({ name: 'cashier-v3-cashier' })
        await nextTick()
      }
      // “继续结账”只完成服务确认后的页面交接。结账编辑全部保留在
      // 收银页面本地，最终确认时才由 CashierWorkbenchView 生成唯一快照。
      const latestCompletionPayload = serviceCompletionCommandPayload()
      if (!latestCompletionPayload.serviceOrderId) {
        showServiceCompletionContractError()
        return { result: { status: 'failed', code: 'SERVICE_CHECKOUT_CONTEXT_MISSING', message: '服务确认后的结账现场未就绪，请刷新后重试。' } }
      }
      closeServiceCompletion()
      window.dispatchEvent(new CustomEvent('cashier-v3:open-checkout', {
        detail: { serviceOrderId: latestCompletionPayload.serviceOrderId, localSnapshot: true }
      }))
    } else if (['finish-service-completion', 'return-to-service-edit'].includes(action)) {
      closeServiceCompletion()
    }

    return result
  } finally {
    if (isMutatingAction) isServiceCompletionActionSubmitting.value = false
  }
}

async function handleServiceCompletionRequest(request = {}) {
  if (request.action === 'open-service-line-completion') return openServiceLineCompletionEditor(request.payload)
  if (request.action === 'open-service-line-staff-allocation') return openServiceLineCraftsmen(request.payload)
  return requestServiceCompletionAction(request)
}

async function saveServiceLineRequest(request = {}) {
  isServiceLineSubmitting.value = true
  try {
    const result = await requestServiceCompletionAction(request)
    if (isSucceededResult(result)) {
      closeServiceLineCompletionEditor()
      closeServiceLineCraftsmen()
    }
    return result
  } finally {
    isServiceLineSubmitting.value = false
  }
}

async function selectServiceLineCraftsmen({ line, serviceOrder, selectedCraftsmen = [] } = {}) {
  const selection = await openCashierV3QueryEntitySelector({
    entityType: 'person',
    title: '选择实际手艺人',
    description: '只显示当前门店可作为手艺人的在职人员；第一位会自动成为主要手艺人。',
    multiple: true,
    selectedRecords: Array.isArray(selectedCraftsmen) ? selectedCraftsmen : [],
    scope: 'service_actual_craftsmen',
    selectionContext: {
      serviceOrderId: serviceOrderId(serviceOrder),
      serviceOrderVersion: serviceOrderVersion(serviceOrder),
      serviceLineId: serviceLineId(line),
      lineVersion: line?.revision ?? line?.recordVersion ?? line?.lineVersion ?? null
    }
  })
  return selection
}

function canUseFeature(featureCode) {
  return canUseCashierV3Feature(featureCode)
}

function canUseOperation(featureCode) {
  const operationAllowed = canUseCashierV3Operation(featureCode)
  // 叶子操作同时出现在服务端完整根投影的 featurePermissions 中时，
  // 以该权威页面快照再次收窄入口；这样即使旧登录响应仍带着旧操作快照，
  // 已明确禁止的叶子功能也不会在工作台短暂显示。
  if (Object.prototype.hasOwnProperty.call(state.featurePermissions || {}, featureCode)) {
    return operationAllowed && state.featurePermissions[featureCode] === true
  }
  return operationAllowed
}

const visibleCardOperationItems = computed(() => cardOperationItems.filter((item) => canUseOperation(item.featureCode)))

async function navigateCashierWorkflow(tab) {
  if (!tab || !canUseFeature(tab.featureCode)) return
  const selectedMember = workflowMember.value
  const targetContext = tab.key === 'checkout' ? 'cashier' : 'writeoff'
  if (tab.key === 'checkout' && isGuestCustomer.value) {
    pendingCashierWorkflowTarget.value = null
    await router.push(tab.to)
    return { success: true }
  }
  if (!selectedMember) {
    pendingCashierWorkflowTarget.value = tab
    return openWorkflowMemberSelector(targetContext, { preservePending: true })
  }

  const selectedMemberId = memberDetailId(selectedMember)
  const targetMember = targetContext === 'cashier' ? cashierMember.value : writeoffMember.value
  if (!selectedMemberId) {
    pendingCashierWorkflowTarget.value = tab
    return openWorkflowMemberSelector(targetContext, { preservePending: true })
  }

  if (String(memberDetailId(targetMember) || '') !== String(selectedMemberId)) {
    const result = await requestCashierV3Action(memberSelectorActions[targetContext], {
      memberId: selectedMemberId,
      selectorContext: targetContext,
      selectorEntry: targetContext
    })
    if (!isSucceededResult(result)) return result
  }

  pendingCashierWorkflowTarget.value = null
  await router.push(tab.to)
  return { success: true }
}

function currentWorkflowSelectorContext() {
  return activeCashierWorkflowMode.value === 'checkout' ? 'cashier' : 'writeoff'
}

async function openWorkflowMemberSelector(context = currentWorkflowSelectorContext(), { preservePending = false } = {}) {
  if (!preservePending) {
    pendingCashierWorkflowTarget.value = null
    pendingMemberTopAction.value = null
  }
  if (!openMemberSelector({ context })) {
    return { result: { status: 'failed', code: 'MEMBER_SELECTOR_CONTEXT_INVALID', message: '会员选择来源无效。' } }
  }
  if (context === 'writeoff') {
    return requestCashierV3Action('open-writeoff-member-selector', {
      writeoffDraftId: state.writeoff?.draftId,
      recordVersion: state.writeoff?.revision,
      selectorEntry: 'writeoff'
    })
  }
  // 收银会员选择器只是读取会员列表，打开弹层不能生成或更新服务端
  // cashier_workspace。当前会员和购物车在浏览器草稿中维护，直到最终确认
  // 才提交唯一结账快照。
  return { result: { status: 'success' } }
}

async function openWorkflowMemberDetail(memberId) {
  if (!memberId) return
  const member = workflowMember.value && String(memberDetailId(workflowMember.value)) === String(memberId)
    ? workflowMember.value
    : { id: memberId }
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-detail', {
    detail: { memberId, member }
  }))
  return requestCashierV3Action('open-member-detail', { memberId })
}

function selectGuestOrderFromWorkflow() {
  return selectGuestOrderFromSelector()
}

function dismissFeedback() {
  uiFeedback.value = null
  if (feedbackTimeoutId) {
    window.clearTimeout(feedbackTimeoutId)
    feedbackTimeoutId = null
  }
}

function clearCashierNegativeState() {
  dismissFeedback()
}

function openPasswordDialog() {
  isAccountMenuOpen.value = false
  passwordChange.value = { account: String(state.operator.account || ''), currentPassword: '', newPassword: '', confirmation: '' }
  passwordChangeError.value = ''
  isPasswordDialogOpen.value = true
}

function closePasswordDialog() {
  if (isPasswordSubmitting.value) return
  isPasswordDialogOpen.value = false
  passwordChange.value = { account: '', currentPassword: '', newPassword: '', confirmation: '' }
  passwordChangeError.value = ''
}

async function submitPasswordChange() {
  if (isPasswordSubmitting.value) return
  const values = passwordChange.value
  if (!values.account || !values.currentPassword || !values.newPassword || !values.confirmation) {
    passwordChangeError.value = '请完整填写登录账号和密码。'
    return
  }
  if (values.newPassword !== values.confirmation) {
    passwordChangeError.value = '两次输入的新密码不一致。'
    return
  }
  isPasswordSubmitting.value = true
  passwordChangeError.value = ''
  try {
    await changeStoreV3Password(values.account, values.currentPassword, values.newPassword)
    clearStoreV3Token()
    isPasswordDialogOpen.value = false
    passwordChange.value = { account: '', currentPassword: '', newPassword: '', confirmation: '' }
    await router.replace({ name: 'cashier-v3-login' })
  } catch (error) {
    passwordChangeError.value = error instanceof Error ? error.message : '密码修改失败，请稍后重试。'
  } finally {
    isPasswordSubmitting.value = false
  }
}

async function logoutCurrentAccount() {
  if (!window.confirm('确定退出当前账号吗？')) return
  isAccountMenuOpen.value = false
  try {
    await logoutStoreV3()
  } catch (_) {
    // 会话可能已由服务端失效，本地仍应完成退出，避免继续使用过期工作台。
  } finally {
    clearStoreV3Token()
    window.dispatchEvent(new CustomEvent('cashier-v3:state-context-changing'))
    await router.replace({ name: 'cashier-v3-login' })
  }
}

function operatorFeedback(detail, conflictText) {
  const status = String(detail.status || '')
  const code = String(detail.code || '')
  const action = String(detail.action || detail.canonicalAction || '')
  const fallbackMessage = detail.feedback?.message || detail.message || conflictText

  if (status === 'result_unknown' || code === 'COMMAND_RESULT_UNKNOWN') {
    if (action === 'prepare-recharge-checkout') {
      return {
        kind: 'error',
        title: '收款准备未完成',
        message: '本次尚未进入收款，也未扣款。请核对充值金额后，再点“下一步：收款信息”重试。',
        persistent: true
      }
    }
    return {
      kind: 'error',
      title: '正在确认操作结果',
      message: '操作可能已经受理，请勿重复提交。请使用原请求查询结果。',
      persistent: true
    }
  }
  if ((detail.stateIgnored === true || detail.requiresRefresh === true) && ['success', 'conflict'].includes(status)) {
    return {
      kind: status === 'conflict' ? 'conflict' : 'info',
      title: status === 'conflict' ? '内容已更新' : '操作已完成',
      message: status === 'conflict'
        ? conflictText
        : '操作已完成，但页面数据需要更新。请刷新当前页面后继续。',
      persistent: true
    }
  }
  if (/^(ENVELOPE_|STATE_CONTEXT_|STATE_REVISION_|ROOT_STATE_)/.test(code)) {
    return {
      kind: 'error',
      title: '页面数据未能打开',
      message: '本次没有开始收款或服务操作，请重新加载当前页面后再试。',
      persistent: true
    }
  }
  if (['INVALID_COMMAND_CONTEXT', 'RESOURCE_VERSION_NOT_READY', 'COMMAND_CONTEXT_VERSION_REQUIRED'].includes(code)) {
    return {
      kind: 'error',
      title: '数据正在更新',
      message: '数据尚未准备好，本次操作没有提交。请重新加载当前内容后再试。',
      persistent: true
    }
  }
  return {
    kind: status === 'conflict' ? 'conflict' : status === 'failed' ? 'error' : 'info',
    title: detail.feedback?.title || (status === 'conflict' ? '内容已更新' : '操作提示'),
    message: fallbackMessage,
    persistent:
      detail.feedback?.persistent === true ||
      detail.stateIgnored === true ||
      detail.requiresRefresh === true ||
      ['failed', 'conflict'].includes(status)
  }
}

async function handleUiResult(event) {
  const detail = event.detail || {}
  const navigation = detail.navigation || {}
  const overlay = detail.overlay
  const overlayName = typeof overlay === 'string' ? overlay : overlay?.name

  if (['INVALID_COMMAND_CONTEXT', 'RESOURCE_VERSION_NOT_READY', 'COMMAND_CONTEXT_VERSION_REQUIRED', 'CASHIER_DRAFT_INCOMPLETE'].includes(String(detail.code || ''))) {
    window.dispatchEvent(new CustomEvent('cashier-v3:refresh-workbench'))
    return
  }
  // 根状态被拒收时，与该根绑定的导航和浮层也必须拒绝，避免形成“旧根 + 新浮层”。
  const canApplyUiDirective = detail.stateIgnored !== true && detail.requiresRefresh !== true
  if (canApplyUiDirective && overlayName === 'member-selector') openMemberSelector(overlay)
  if (canApplyUiDirective && overlayName === 'service-completion') openServiceCompletion(overlay)
  if (canApplyUiDirective && overlayName === 'room-assignment') openRoomAssignment(overlay)
  const overlayEventMap = {
    // “打开编辑器”是用户意图；后端返回的 overlay 表示同一准备请求已经完成。
    // 两者必须使用不同事件，否则返回一个 editor overlay 会再次发起准备请求。
    'reservation-editor': 'cashier-v3:reservation-editor-prepared',
    'reservation-detail': 'cashier-v3:open-reservation-detail',
    'sales-order-detail': 'cashier-v3:open-sales-order-detail',
    'writeoff-confirmation': 'cashier-v3:open-writeoff-confirmation',
    'member-detail': 'cashier-v3:open-member-detail',
    'room-detail': 'cashier-v3:open-room-detail'
  }
  // V3 收银台已经使用 prepare-recharge-checkout + CashierCheckoutOverlay。
  // Shell 只负责把准备好的充值快照交给 Workbench；非收银台页面仍保留
  // 旧充值兼容入口，避免第一步表单误走 submit-recharge。
  if (canApplyUiDirective && overlayName === 'recharge') {
    if (isCashierPage.value) {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-recharge', {
        detail: overlay
      }))
    } else {
      rechargeSession.value = typeof overlay === 'object' ? overlay : null
    }
  }
  if (canApplyUiDirective && overlayName === 'direct-gift') {
    directGiftSession.value = typeof overlay === 'object' ? overlay : null
  }
  if (canApplyUiDirective && overlayName === 'query-entity-selector') openQueryEntitySelector(overlay)
  if (canApplyUiDirective && navigation.routeName) {
    try {
      await router.push({ name: navigation.routeName, params: navigation.params || {}, query: navigation.query || {} })
      await nextTick()
    } catch {
      // 导航失败时仍保留当前页面；后端反馈会继续显示，不能静默伪造打开结果。
    }
  } else if (canApplyUiDirective && navigation.hash) {
    window.location.hash = navigation.hash
  }
  if (canApplyUiDirective && overlayEventMap[overlayName]) {
    window.dispatchEvent(new CustomEvent(overlayEventMap[overlayName], {
      detail: typeof overlay === 'object' ? overlay : {}
    }))
  }

  // Most successful commands remain silent. A successful command may still
  // carry an explicit business warning (for example service ended while an
  // indebted entitlement was deliberately left unconsumed); that warning must
  // reach the operator instead of being discarded with the generic success.
  if (['success', 'succeeded'].includes(String(detail.status || '')) && !detail.feedback) {
    if (uiFeedback.value?.persistent !== true) dismissFeedback()
    return
  }
  if (!detail.message && !detail.feedback && detail.status !== 'conflict') return
  const conflictText = detail.conflict?.message || '该内容已被其他人员修改。已保留你当前未保存的输入，请先查看最新内容后再处理。'
  const feedback = operatorFeedback(detail, conflictText)
  uiFeedback.value = feedback
  if (feedbackTimeoutId) window.clearTimeout(feedbackTimeoutId)
}

watch(
  () => [
    roomAssignmentPreparationId(roomAssignment.value),
    roomAssignmentSubjectKey(
      roomAssignmentScope.value,
      roomAssignmentReservation.value,
      roomAssignmentServiceOrder.value
    ),
    activeRoomAssignmentPreparationId.value,
    activeRoomAssignmentSubjectKey.value
  ],
  ([snapshotPreparationId, snapshotSubjectKey, expectedPreparationId, expectedSubjectKey]) => {
    if (!isRoomAssignmentOpen.value) return
    if ((expectedPreparationId && snapshotPreparationId !== expectedPreparationId)
      || (expectedSubjectKey && snapshotSubjectKey !== expectedSubjectKey)) {
      closeRoomAssignment()
      showRoomAssignmentContractError()
    }
  }
)

watch(
  () => [
    serviceOrderId(serviceCompletionSnapshot.value?.serviceOrder),
    serviceCompletionStatus.value,
    serviceCompletionSnapshot.value?.resumeOnLoad === true
  ],
  ([serviceOrderIdValue, status, resumeOnLoad]) => {
    const recoverableStatuses = ['processing', 'pending', 'pending_confirmation', 'result_unknown']
    if (!resumeOnLoad || !serviceOrderIdValue || !recoverableStatuses.includes(status)) return
    if (isServiceCompletionOpen.value && String(activeServiceCompletionId.value) === String(serviceOrderIdValue)) return
    openServiceCompletion({ serviceOrderId: serviceOrderIdValue }, { recovery: true })
  },
  { immediate: true }
)

onMounted(() => {
  window.addEventListener('cashier-v3:ui-result', handleUiResult)
  window.addEventListener('cashier-v3:clear-negative-state', clearCashierNegativeState)
  window.addEventListener('cashier-v3:state-context-changing', handleStateContextChanged)
  window.addEventListener('cashier-v3:state-context-changed', handleStateContextChanged)
  window.addEventListener('cashier-v3:open-member-selector', openMemberSelector)
  window.addEventListener('cashier-v3:open-cashier-member', openCashierForMember)
  window.addEventListener('cashier-v3:open-member-detail', showMemberDetail)
  window.addEventListener('cashier-v3:open-member-debt-repayment', handleOpenMemberDebt)
  window.addEventListener('cashier-v3:open-query-entity-selector', openQueryEntitySelector)
  window.addEventListener('cashier-v3:open-service-completion', openServiceCompletion)
  window.addEventListener('cashier-v3:register-service-completion-request', registerServiceCompletionPreparation)
  window.addEventListener('cashier-v3:register-room-assignment-request', registerRoomAssignmentPreparation)
  window.addEventListener('cashier-v3:toolbar-context-updated', handleToolbarCheckoutContextUpdated)
  window.addEventListener('cashier-v3:checkout-business-source-cancelled', handleCheckoutBusinessSourceCancelled)
  window.addEventListener('cashier-v3:checkout-business-source-confirmed', handleCheckoutBusinessSourceSettled)
  window.addEventListener('cashier-v3:checkout-business-source-closed', handleCheckoutBusinessSourceSettled)
})
onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:ui-result', handleUiResult)
  window.removeEventListener('cashier-v3:clear-negative-state', clearCashierNegativeState)
  window.removeEventListener('cashier-v3:state-context-changing', handleStateContextChanged)
  window.removeEventListener('cashier-v3:state-context-changed', handleStateContextChanged)
  window.removeEventListener('cashier-v3:open-member-selector', openMemberSelector)
  window.removeEventListener('cashier-v3:open-cashier-member', openCashierForMember)
  window.removeEventListener('cashier-v3:open-member-detail', showMemberDetail)
  window.removeEventListener('cashier-v3:open-member-debt-repayment', handleOpenMemberDebt)
  window.removeEventListener('cashier-v3:open-query-entity-selector', openQueryEntitySelector)
  window.removeEventListener('cashier-v3:open-service-completion', openServiceCompletion)
  window.removeEventListener('cashier-v3:register-service-completion-request', registerServiceCompletionPreparation)
  window.removeEventListener('cashier-v3:register-room-assignment-request', registerRoomAssignmentPreparation)
  window.removeEventListener('cashier-v3:toolbar-context-updated', handleToolbarCheckoutContextUpdated)
  window.removeEventListener('cashier-v3:checkout-business-source-cancelled', handleCheckoutBusinessSourceCancelled)
  window.removeEventListener('cashier-v3:checkout-business-source-confirmed', handleCheckoutBusinessSourceSettled)
  window.removeEventListener('cashier-v3:checkout-business-source-closed', handleCheckoutBusinessSourceSettled)
  closeQueryEntitySelector({ reason: 'shell-unmounted' })
  closeServiceCompletion()
  closeRoomAssignment()
  if (feedbackTimeoutId) window.clearTimeout(feedbackTimeoutId)
})
</script>

<template>
  <div class="cashier-shell" :class="{ 'cashier-shell--sidebar-collapsed': isSidebarCollapsed }">
    <div v-if="isPasswordDialogOpen" class="cashier-account-dialog-backdrop" @click.self="closePasswordDialog">
      <form class="cashier-account-dialog" aria-label="修改登录账号和密码" @submit.prevent="submitPasswordChange">
        <header><h2>修改登录账号和密码</h2><button type="button" class="cashier-account-dialog__close" aria-label="关闭" @click="closePasswordDialog">×</button></header>
        <label>登录账号<input v-model.trim="passwordChange.account" type="text" autocomplete="username" :disabled="isPasswordSubmitting" /></label>
        <label>原密码<input v-model="passwordChange.currentPassword" type="password" autocomplete="current-password" :disabled="isPasswordSubmitting" /></label>
        <label>新密码<input v-model="passwordChange.newPassword" type="password" autocomplete="new-password" :disabled="isPasswordSubmitting" /></label>
        <label>确认新密码<input v-model="passwordChange.confirmation" type="password" autocomplete="new-password" :disabled="isPasswordSubmitting" /></label>
        <p v-if="passwordChangeError" class="cashier-account-dialog__error" role="alert">{{ passwordChangeError }}</p>
        <footer><button type="button" class="button button--secondary" :disabled="isPasswordSubmitting" @click="closePasswordDialog">取消</button><button type="submit" class="button button--primary" :disabled="isPasswordSubmitting">{{ isPasswordSubmitting ? '保存中…' : '确认' }}</button></footer>
      </form>
    </div>
    <RechargeOverlay
      v-if="rechargeSession && !isCashierPage"
      :session="rechargeSession"
      :business-date="toolbarBusinessDate"
      :submitting="isRechargeSubmitting"
      @close="rechargeSession = null"
      @submit="submitRecharge"
    />
    <DirectGiftOverlay
      v-if="directGiftSession"
      :session="directGiftSession"
      :submitting="isDirectGiftSubmitting"
      @close="directGiftSession = null"
      @submit="submitDirectGift"
    />
    <aside class="cashier-sidebar" :class="{ 'cashier-sidebar--collapsed': isSidebarCollapsed }" aria-label="门店端菜单">
      <div class="cashier-brand" :title="isSidebarCollapsed ? state.storeName : undefined">
        <span class="cashier-brand__mark" aria-hidden="true"><Store :size="21" :stroke-width="1.9" /></span>
        <div v-if="!isSidebarCollapsed" class="cashier-brand__copy">
          <strong>{{ state.storeName }}</strong>
        </div>
      </div>

      <nav id="cashier-primary-nav" class="cashier-nav" aria-label="日常业务">
        <span v-if="!isSidebarCollapsed" class="cashier-nav__section">日常业务</span>
        <template v-for="item in menuItems" :key="item.key">
          <div v-if="item.key === 'management' && canUseFeature(item.featureCode)" class="cashier-management-group">
            <div class="cashier-management-entry" :class="{ 'cashier-management-entry--open': isManagementMenuExpanded }">
              <RouterLink
                :to="item.to"
                class="cashier-nav__item cashier-nav__item--management"
                :class="{ 'cashier-nav__item--active': isMenuItemActive(item) }"
                :aria-label="item.label"
                :title="isSidebarCollapsed ? item.label : undefined"
                @click="closeInventoryWorkspace"
              >
                <component :is="item.icon" class="cashier-nav__icon" :size="20" :stroke-width="1.9" aria-hidden="true" />
                <span v-if="!isSidebarCollapsed" class="cashier-nav__label">{{ item.label }}</span>
              </RouterLink>
              <button
                v-if="!isSidebarCollapsed"
                type="button"
                class="cashier-management-toggle"
                :aria-label="isManagementMenuExpanded ? '收起管理功能' : '展开管理功能'"
                :aria-expanded="isManagementMenuExpanded"
                @click="toggleManagementMenu"
              >
                <ChevronDown class="cashier-nav__management-chevron" :class="{ 'cashier-nav__management-chevron--open': isManagementMenuExpanded }" :size="16" aria-hidden="true" />
              </button>
            </div>
            <div v-if="isManagementMenuExpanded && !isSidebarCollapsed" class="cashier-management-grid" aria-label="管理功能">
              <RouterLink
                v-for="entry in managementFeatureItems"
                :key="entry.key"
                class="cashier-management-grid__item"
                :class="{ 'cashier-management-grid__item--active': isManagementFeatureActive(entry) }"
                :to="entry.to"
                @click="closeInventoryWorkspace"
              >{{ entry.label }}</RouterLink>
            </div>
          </div>
          <div v-else-if="item.key === 'inventory' && canUseFeature(item.featureCode) && item.submenu && visibleInventoryFeatureItems.length" class="cashier-inventory-group">
            <div class="cashier-inventory-entry" :class="{ 'cashier-inventory-entry--open': isInventoryMenuExpanded }">
              <a
                href="#/cashier"
                class="cashier-nav__item cashier-nav__item--inventory"
                :aria-label="item.label"
                :title="isSidebarCollapsed ? item.label : undefined"
                @click.prevent="openInventoryWorkspace()"
              >
                <component :is="item.icon" class="cashier-nav__icon" :size="20" :stroke-width="1.9" aria-hidden="true" />
                <span v-if="!isSidebarCollapsed" class="cashier-nav__label">{{ item.label }}</span>
              </a>
              <button
                v-if="!isSidebarCollapsed"
                type="button"
                class="cashier-inventory-toggle"
                :aria-label="isInventoryMenuExpanded ? '收起库存功能' : '展开库存功能'"
                :aria-expanded="isInventoryMenuExpanded"
                @click="toggleInventoryMenu"
              >
                <ChevronDown class="cashier-nav__inventory-chevron" :class="{ 'cashier-nav__inventory-chevron--open': isInventoryMenuExpanded }" :size="16" aria-hidden="true" />
              </button>
            </div>
            <div v-if="isInventoryMenuExpanded && !isSidebarCollapsed" class="cashier-inventory-grid" aria-label="库存管理功能">
              <template v-for="entry in visibleInventoryFeatureItems" :key="entry.key">
                <RouterLink
                  v-if="entry.to"
                  class="cashier-inventory-grid__item"
                  :class="{ 'cashier-inventory-grid__item--active': isInventoryFeatureActive(entry) }"
                  :to="entry.to"
                  @click="closeInventoryWorkspace"
                >{{ entry.label }}</RouterLink>
                <a
                  v-else
                  class="cashier-inventory-grid__item"
                  :class="{ 'cashier-inventory-grid__item--active': isInventoryFeatureActive(entry) }"
                  href="#/cashier"
                  @click.prevent="openInventoryWorkspace(entry)"
                >{{ entry.label }}</a>
              </template>
            </div>
          </div>
          <a
            v-else-if="canUseFeature(item.featureCode) && item.href"
            :href="item.href"
            class="cashier-nav__item"
            :aria-label="item.label"
            :title="isSidebarCollapsed ? item.label : undefined"
            @click="closeInventoryWorkspace"
          >
            <component :is="item.icon" class="cashier-nav__icon" :size="20" :stroke-width="1.9" aria-hidden="true" />
            <span v-if="!isSidebarCollapsed" class="cashier-nav__label">{{ item.label }}</span>
          </a>
          <RouterLink
            v-else-if="canUseFeature(item.featureCode) && item.to"
            :to="item.to"
            class="cashier-nav__item"
            :class="{ 'cashier-nav__item--active': isMenuItemActive(item) }"
            :aria-label="item.label"
            :title="isSidebarCollapsed ? item.label : undefined"
            @click="closeInventoryWorkspace"
            >
            <component :is="item.icon" class="cashier-nav__icon" :size="20" :stroke-width="1.9" aria-hidden="true" />
            <span v-if="!isSidebarCollapsed" class="cashier-nav__label">{{ item.label }}</span>
            <span
              v-if="item.key === 'hang' && pendingHangCount > 0"
              class="cashier-nav__badge"
              :aria-label="`待结账挂单 ${pendingHangCount} 笔`"
            >{{ pendingHangCount }}</span>
          </RouterLink>
        </template>
      </nav>

      <div class="cashier-sidebar__footer">
        <div class="cashier-account" :class="{ 'cashier-account--open': isAccountMenuOpen }">
          <button type="button" class="cashier-account__trigger" :aria-expanded="isAccountMenuOpen" :title="operatorLabel" @click="isAccountMenuOpen = !isAccountMenuOpen">
          <CircleUserRound class="cashier-account__icon" :size="20" :stroke-width="1.9" aria-hidden="true" />
          <div v-if="!isSidebarCollapsed" class="cashier-account__copy">
            <span class="cashier-account__label">V26083001</span>
            <strong>{{ operatorLabel }}</strong>
          </div>
          </button>
          <div v-if="isAccountMenuOpen" class="cashier-account__menu" role="menu">
            <button type="button" role="menuitem" @click="openPasswordDialog"><KeyRound :size="16" aria-hidden="true" />修改密码</button>
            <button type="button" role="menuitem" @click="logoutCurrentAccount"><LogOut :size="16" aria-hidden="true" />退出登录</button>
          </div>
        </div>
        <button
          type="button"
          class="cashier-sidebar-toggle"
          :aria-label="isSidebarCollapsed ? '展开菜单栏' : '收起菜单栏'"
          :aria-expanded="!isSidebarCollapsed"
          aria-controls="cashier-primary-nav"
          :title="isSidebarCollapsed ? '展开菜单栏' : '收起菜单栏'"
          @click="toggleSidebar"
        >
          <PanelLeftOpen v-if="isSidebarCollapsed" :size="16" :stroke-width="2" aria-hidden="true" />
          <PanelLeftClose v-else :size="16" :stroke-width="2" aria-hidden="true" />
        </button>
      </div>
    </aside>

    <section class="cashier-main" :class="{ 'cashier-main--workflow': isCashierWorkflowPage || isInventoryWorkspaceOpen }">
      <header v-if="!isCashierWorkflowPage && !isInventoryWorkspaceOpen" class="cashier-main__header">
        <div class="cashier-page-identity">
          <div>
            <h1>{{ pageTitle }}</h1>
          </div>
        </div>

        <div class="cashier-main__header-center" />

        <div v-if="isReservationPage" class="cashier-header-actions">
          <button type="button" class="button button--primary" @click="requestTopAction('open-reservation-editor')">
            新增预约
          </button>
          <button type="button" class="button button--help" @click="isOperationHelpOpen = true">
            页面说明
          </button>
        </div>
        <div v-else-if="isMemberPage" class="cashier-header-actions">
          <button v-if="canUseFeature('cashier.v3.member')" type="button" class="button button--primary" @click="openMemberCreator">
            新增会员
          </button>
          <button v-if="canUseFeature('cashier.v3.member.batch')" type="button" class="button button--secondary" @click="requestTopAction('open-member-batch-actions')">
            批量操作
          </button>
          <button type="button" class="button button--help" @click="isOperationHelpOpen = true">
            页面说明
          </button>
        </div>
        <div v-else-if="isStaffPage" class="cashier-header-actions">
          <button type="button" class="button button--primary" @click="openStaffCreator">
            新增员工
          </button>
          <button type="button" class="button button--help" @click="isOperationHelpOpen = true">
            页面说明
          </button>
        </div>
        <div v-else-if="hasPageHelp" class="cashier-header-actions">
          <button type="button" class="button button--help" @click="isOperationHelpOpen = true">
            页面说明
          </button>
        </div>
      </header>

      <main class="cashier-main__content" :class="{ 'cashier-main__content--with-member': isCashierWorkflowPage && !isInventoryWorkspaceOpen }">
        <section v-if="isInventoryWorkspaceOpen" class="cashier-inventory-workspace" aria-label="库存管理工作区">
          <InventoryWorkbench
            entry-mode="store"
            :entry-page="activeInventoryFeatureKey"
            :embedded="true"
            :session-token="inventorySessionToken"
          />
        </section>
        <template v-else>
        <div v-if="isCashierPage" class="cashier-workflow-toolbar cashier-workflow-toolbar--cashier" aria-label="当前办理会员与收银功能">
          <section class="cashier-workflow-toolbar__member-block" aria-label="本次办理会员">
            <MemberSummaryCard
              :member="cashierMember"
              :customer-mode="isGuestCustomer ? 'guest' : (cashierMember ? 'member' : 'unselected')"
              :show-actions="false"
              :show-empty-actions="false"
              empty-description="当前为游客开单；需要会员服务时请选择会员。"
              @select="openWorkflowMemberSelector('cashier')"
              @open-detail="openWorkflowMemberDetail"
              @open-debt="openMemberDebt"
            />
            <div class="cashier-workflow-member-actions" aria-label="会员操作">
              <button
                type="button"
                class="button button--primary cashier-workflow-member-button"
                aria-label="选择本次办理会员"
                data-testid="cashier-workflow-member"
                @click="openWorkflowMemberSelector('cashier')"
              >
                <span class="cashier-workflow-member-button__icon" aria-hidden="true" />
                <span>选择会员</span>
              </button>
              <button
                v-if="canUseOperation('cashier.v3.cashier.recharge')"
                type="button"
                class="button button--secondary cashier-workflow-action-button"
                data-testid="cashier-workflow-recharge"
                @click="openCashierMemberTopAction('open-recharge')"
              >充值</button>
            </div>
          </section>

          <section class="cashier-workflow-toolbar__operation-block" aria-label="收银操作">
            <div
              v-if="hasCashierServiceOrderSummary"
              class="service-order-banner service-order-banner--toolbar cashier-workflow-service-order"
              data-testid="cashier-workflow-service-order"
              aria-label="当前服务单"
            >
              <div>
                <template v-if="cashierRoomOpenIntent">
                  <span>房间：{{ cashierRoomOpenIntent.roomName }}</span>
                  <strong>待挂单</strong>
                </template>
                <template v-else>
                  <span v-if="cashierServiceOrderRoomName">房间：{{ cashierServiceOrderRoomName }}</span>
                  <strong v-if="cashierServiceOrderStatusText">{{ cashierServiceOrderStatusText }}</strong>
                  <span v-if="cashierServiceOrderDisplayNo">服务单：{{ cashierServiceOrderDisplayNo }}</span>
                </template>
              </div>
            </div>
            <div class="cashier-workflow-toolbar__context-settings" aria-label="本次结账信息">
              <label class="cashier-workflow-context-date">
                <CalendarDays :size="14" aria-hidden="true" />
                <span>业务日期</span>
                <input
                  v-model="toolbarBusinessDate"
                  type="date"
                  aria-label="业务日期"
                  @change="updateToolbarBusinessDate"
                >
              </label>
              <button
                type="button"
                class="cashier-workflow-context-source"
                aria-label="选择客户来源"
                @click="openToolbarBusinessSource"
              >
                <span class="cashier-workflow-context-source__label">来源</span>
                <strong>{{ toolbarBusinessSource.displayNameSnapshot || '未选择' }}</strong>
              </button>
            </div>
            <div class="cashier-workflow-toolbar__operation-actions">
              <button
                type="button"
                class="button button--primary cashier-workflow-action-button cashier-workflow-entitlement-button"
                data-testid="cashier-workflow-entitlement"
                @click="openCashierEntitlementSelector"
              >使用权益</button>
              <button
                v-if="canUseOperation('cashier.v3.cashier.gift')"
                type="button"
                class="button button--secondary cashier-workflow-action-button"
                data-testid="cashier-workflow-gift"
                @click="openCashierMemberTopAction('open-gift')"
              >赠送</button>
              <div v-if="visibleCardOperationItems.length" class="cashier-card-operation">
                <button
                  type="button"
                  class="button button--secondary cashier-workflow-action-button cashier-card-operation__trigger"
                  :aria-expanded="isCardOperationMenuOpen"
                  aria-haspopup="menu"
                  @click="toggleCardOperationMenu"
                >
                  <span>卡操作</span>
                  <span class="cashier-card-operation__chevron" aria-hidden="true">⌄</span>
                </button>
                <div v-if="isCardOperationMenuOpen" class="cashier-card-operation__menu" role="menu" aria-label="卡操作菜单">
                  <button
                    v-for="item in visibleCardOperationItems"
                    :key="item.key"
                    type="button"
                    role="menuitem"
                    class="cashier-card-operation__menu-item"
                    @click="openPreviewCardOperation(item)"
                  >
                    <span>{{ item.label }}</span>
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="button button--secondary cashier-workflow-action-button"
                @click="isOperationHelpOpen = true"
              >操作说明</button>
            </div>
          </section>
        </div>

        <div v-else-if="isCashierWorkflowPage" class="cashier-workflow-toolbar cashier-workflow-toolbar--legacy" aria-label="当前办理会员与收银功能">
          <MemberSummaryCard
            :member="workflowMember"
            :customer-mode="isGuestCustomer ? 'guest' : (workflowMember ? 'member' : 'unselected')"
            :show-actions="false"
            :show-empty-actions="false"
            empty-description="请点击“选择会员”后继续操作。"
            @select="openWorkflowMemberSelector()"
            @open-detail="openWorkflowMemberDetail"
            @open-debt="openMemberDebt"
          />

          <div
            class="cashier-workflow-tabs"
            role="tablist"
            aria-label="收银功能切换"
            data-testid="cashier-workflow-tabs"
          >
            <button
              type="button"
              class="cashier-workflow-tabs__button cashier-workflow-member-button"
              :aria-label="hasWorkflowCustomer ? '更换当前办理会员' : '选择本次办理会员'"
              data-testid="cashier-workflow-member"
              @click="openWorkflowMemberSelector()"
            >
              <span class="cashier-workflow-member-button__icon" aria-hidden="true" />
              <span>{{ hasWorkflowCustomer ? '更换会员' : '选择会员' }}</span>
            </button>
            <button
              v-for="tab in cashierWorkflowTabs"
              :key="tab.key"
              type="button"
              role="tab"
              class="cashier-workflow-tabs__button"
              :class="{ 'cashier-workflow-tabs__button--active': activeCashierWorkflowMode === tab.key }"
              :aria-selected="activeCashierWorkflowMode === tab.key"
              :disabled="!canUseFeature(tab.featureCode)"
              :data-testid="`cashier-workflow-${tab.key}`"
              @click="navigateCashierWorkflow(tab)"
            >
              <span class="cashier-workflow-tabs__icon" aria-hidden="true">{{ tab.icon }}</span>
              <span>{{ tab.label }}</span>
            </button>
          </div>

          <div class="cashier-header-actions cashier-workflow-toolbar__actions">
            <button type="button" class="button button--help" @click="isOperationHelpOpen = true">
              页面说明
            </button>
          </div>
        </div>
        <div class="cashier-main__view">
          <RouterView />
        </div>
        </template>
      </main>
    </section>
  </div>


  <Teleport to="body">
    <div v-if="isOperationHelpOpen" class="help-modal" role="dialog" aria-modal="true" aria-label="收银操作说明">
      <div class="help-modal__backdrop" @click="isOperationHelpOpen = false" />
      <section class="help-modal__card">
        <div class="help-modal__header">
          <div>
            <h2>{{ helpContent.title }}</h2>
            <p>{{ helpContent.description }}</p>
          </div>
          <button type="button" class="button button--secondary" @click="isOperationHelpOpen = false">关闭</button>
        </div>
        <ol class="help-modal__steps">
          <li v-for="step in helpContent.steps" :key="step">{{ step }}</li>
        </ol>
      </section>
    </div>
  </Teleport>

  <MemberSelectorOverlay
    v-if="isMemberSelectorOpen"
    :records="memberSelector.records || []"
    :total="memberSelector.total || 0"
    :page="memberSelector.page || 1"
    :page-size="memberSelector.pageSize || 20"
    :is-loading="Boolean(memberSelector.isLoading)"
    :allow-guest="isCashierWorkflowPage && memberSelectorContext !== 'reservation' && !memberSelectorRequiresMember"
    :allow-create="canUseFeature('cashier.v3.member') && (isCashierWorkflowPage || memberSelectorInitialView === 'creator')"
    :show-scope-toggle="memberSelectorContext === 'cashier'"
    :current-store-name="state.storeName || ''"
    :creator-schema="memberCreatorSchema"
    :initial-view="memberSelectorInitialView"
    :on-query="queryMemberSelector"
    :on-select="selectMemberFromSelector"
    :on-select-guest="selectGuestOrderFromSelector"
    :on-create-member="createMemberFromSelector"
    :on-select-service-person="selectMemberCreatorServicePerson"
    :on-select-referrer="selectMemberReferrer"
    @close="closeMemberSelector"
  />

  <MemberSelectorOverlay
    v-if="isReferrerSelectorOpen"
    :records="referrerSelector.records"
    :total="referrerSelector.total"
    :page="referrerSelector.page"
    :page-size="referrerSelector.pageSize"
    :is-loading="Boolean(referrerSelector.isLoading)"
    title="选择推荐人"
    :allow-guest="false"
    :allow-create="false"
    :current-store-name="state.storeName || ''"
    :on-query="queryReferrerSelector"
    :on-select="selectReferrerFromSelector"
    @close="closeReferrerSelector"
  />

  <MemberDetailOverlay
    v-if="isMemberDetailOpen"
    :detail="memberDetail"
    :initial-tab="memberDetailInitialTab"
    :is-loading="isMemberDetailLoading"
    :on-action="handleMemberDetailAction"
    @tab-change="loadMemberDetailTab"
    @close="closeMemberDetail"
  />

  <MemberDebtReminderOverlay
    v-if="isDebtReminderOpen && debtReminderMember"
    :member="debtReminderMember"
    :amount="memberDebtAmount(debtReminderMember)"
    @cancel="cancelDebtReminder"
    @repay="openMemberDebt(memberDetailId(debtReminderMember))"
  />

  <MemberDebtOverlay
    v-if="isMemberDebtOpen && activeDebtMember"
    :member="activeDebtMember"
    :snapshot="memberDebtSnapshot"
    :initial-debt-id="initialDebtRecordId"
    :business-date="toolbarBusinessDate"
    :is-loading="isMemberDebtLoading"
    :is-preparing="isDebtRepaymentPreparing"
    @close="closeMemberDebt"
    @repay="prepareDebtRepayment"
  />

  <QueryEntitySelectorOverlay
    v-if="isQueryEntitySelectorOpen"
    :entity-type="queryEntitySelectorType"
    :title="queryEntitySelectorRequest?.title || ''"
    :description="queryEntitySelectorRequest?.description || ''"
    :multiple="queryEntitySelectorRequest?.multiple === true"
    :selected-records="queryEntitySelectorRequest?.selectedRecords || []"
    :records="queryEntitySelector.records || []"
    :total="queryEntitySelector.total || 0"
    :page="queryEntitySelector.page || 1"
    :page-size="queryEntitySelector.pageSize || 20"
    :is-loading="Boolean(queryEntitySelector.isLoading)"
    :on-query="queryQueryEntities"
    :on-select="selectQueryEntity"
    @close="closeQueryEntitySelector"
  />

  <ServiceCompletionOverlay
    v-if="isServiceCompletionOpen"
    :service-order="serviceCompletionOrder"
    :cart-lines="serviceCompletionCartLines"
    :is-action-submitting="isServiceCompletionActionSubmitting"
    :return-focus-to="serviceCompletionReturnFocusTarget"
    @close="closeServiceCompletion"
    @request="handleServiceCompletionRequest"
  />

  <ServiceLineCompletionEditorOverlay
    v-if="isServiceLineCompletionEditorOpen && activeServiceCompletionLine"
    :line="activeServiceCompletionLine"
    :service-order="serviceCompletionOrder"
    :is-submitting="isServiceLineSubmitting"
    @close="closeServiceLineCompletionEditor"
    @request="saveServiceLineRequest"
  />

  <ServiceLineCraftsmenOverlay
    v-if="isServiceLineCraftsmenOpen && activeServiceCompletionLine"
    :line="activeServiceCompletionLine"
    :service-order="serviceCompletionOrder"
    :is-submitting="isServiceLineSubmitting"
    :on-select-craftsmen="selectServiceLineCraftsmen"
    @close="closeServiceLineCraftsmen"
    @request="saveServiceLineRequest"
  />

  <RoomAssignmentOverlay
    v-if="isRoomAssignmentOpen"
    :assignment-scope="roomAssignmentScope"
    :mode="roomAssignmentMode"
    :service-order="roomAssignmentServiceOrder"
    :reservation="roomAssignmentReservation"
    :current-room="roomAssignmentCurrentRoom"
    :candidates="roomAssignmentCandidates"
    :is-submitting="isRoomAssignmentSubmitting"
    @close="closeRoomAssignment"
    @request="requestRoomAssignment"
  />

  <Teleport to="body">
    <section v-if="uiFeedback" class="cashier-ui-feedback-backdrop" role="presentation">
      <section
        class="cashier-ui-feedback"
        :class="`cashier-ui-feedback--${uiFeedback.kind}`"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cashier-ui-feedback-title"
      >
        <div>
          <strong id="cashier-ui-feedback-title">{{ uiFeedback.title }}</strong>
          <span>{{ uiFeedback.message }}</span>
        </div>
        <footer>
          <button type="button" class="button button--primary" @click="dismissFeedback">确认</button>
        </footer>
      </section>
    </section>
  </Teleport>
</template>

<style scoped>
.cashier-nav__item--management {
  width: 100%;
  background: transparent;
}

.cashier-management-group {
  display: grid;
  gap: 4px;
}

.cashier-management-entry {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 34px;
  align-items: center;
  border: 1px solid transparent;
  border-radius: 10px;
}

.cashier-management-entry--open {
  border-color: rgba(185, 212, 255, .26);
  background: rgba(234, 244, 255, .1);
}

.cashier-management-entry--open .cashier-nav__item--management {
  color: #fff;
}

.cashier-management-toggle {
  display: inline-grid;
  width: 30px;
  height: 30px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: rgba(255, 255, 255, .76);
}

.cashier-management-toggle:hover {
  background: rgba(234, 244, 255, .12);
  color: #fff;
}

.cashier-nav__management-chevron {
  flex: none;
  transition: transform .16s ease;
}

.cashier-nav__management-chevron--open {
  transform: rotate(180deg);
}

.cashier-management-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 5px 8px;
  padding: 1px 4px 8px;
}

.cashier-management-grid__item {
  min-width: 0;
  padding: 5px 7px;
  border-radius: 6px;
  color: rgba(255, 255, 255, .72);
  font-size: 13px;
  line-height: 20px;
  text-decoration: none;
  white-space: nowrap;
}

.cashier-management-grid__item:hover {
  background: rgba(234, 244, 255, .1);
  color: #fff;
}

.cashier-management-grid__item--active {
  background: rgba(143, 199, 255, .2);
  color: #fff;
}

.cashier-nav__item--inventory {
  width: 100%;
  background: transparent;
}

.cashier-inventory-group {
  display: grid;
  gap: 4px;
}

.cashier-inventory-entry {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 34px;
  align-items: center;
  border: 1px solid transparent;
  border-radius: 10px;
}

.cashier-inventory-entry--open {
  border-color: rgba(185, 212, 255, .26);
  background: rgba(234, 244, 255, .1);
}

.cashier-inventory-entry--open .cashier-nav__item--inventory {
  color: #fff;
}

.cashier-inventory-toggle {
  display: inline-grid;
  width: 30px;
  height: 30px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: rgba(255, 255, 255, .76);
}

.cashier-inventory-toggle:hover {
  background: rgba(234, 244, 255, .12);
  color: #fff;
}

.cashier-nav__inventory-chevron {
  flex: none;
  transition: transform .16s ease;
}

.cashier-nav__inventory-chevron--open {
  transform: rotate(180deg);
}

.cashier-inventory-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 5px 8px;
  padding: 1px 4px 8px;
}

.cashier-inventory-grid__item {
  min-width: 0;
  padding: 5px 7px;
  border-radius: 6px;
  color: rgba(255, 255, 255, .72);
  font-size: 13px;
  line-height: 20px;
  text-decoration: none;
  white-space: nowrap;
}

.cashier-inventory-grid__item:hover {
  background: rgba(234, 244, 255, .1);
  color: #fff;
}

.cashier-inventory-grid__item--active {
  background: rgba(143, 199, 255, .2);
  color: #fff;
}

.cashier-inventory-workspace {
  width: 100%;
  height: 100%;
  min-height: 0;
  background: #f2f1ef;
}
</style>
