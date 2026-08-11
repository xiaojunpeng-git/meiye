import { reactive } from 'vue'
import {
  CASHIER_V3_ACTION_MANIFEST,
  CHECKOUT_ACTION_ALIASES,
  canonicalCashierV3Action,
  hasCashierV3Action,
  isCashierV3CommandAction,
  PREPARATION_PROJECTION_ACTIONS
} from './cashierV3ActionManifest'
import { isCompleteCheckoutCompositionContract } from './cashierV3EntitlementDraftContract'

/** 三个去结账映射后的规范写命令名 */
const CHECKOUT_COMMAND_ACTIONS = CHECKOUT_ACTION_ALIASES

/**
 * 新收银台前端与后端的唯一适配边界。
 *
 * 页面只展示后端已经试算或确认的结果，不在浏览器中重算应收、优惠、余额、
 * 业绩或库存。Cursor 接入时只能在本文件提供的适配器位置补充接口调用，
 * 不得把接口、金额计算或业务状态判断散落到 Vue 页面中。
 *
 * action 的存在性、规范名与类型全部以 cashierV3ActionManifest.js 为准，
 * 与服务端 manifest 逐条一致。本文件不再按 action 前缀猜类型。
 */

const EMPTY_SUMMARY = {
  selectedCount: 0,
  originalAmount: 0,
  discountAmount: 0,
  receivableAmount: 0
}

const EMPTY_QUERY_ENTITY_PAGE = {
  records: [],
  total: 0,
  page: 1,
  pageSize: 20,
  isLoading: false
}

// 四个纯准备动作是受控 projection：只准备抽屉／确认界面，不改变业务状态。
// 集合从共享清单派生，避免这里和清单各写一份后逐渐分叉。
const SERVICE_COMPLETION_PREPARATION_ACTIONS = new Set(
  PREPARATION_PROJECTION_ACTIONS.filter((action) => action !== 'prepare-room-assignment')
)
const ROOM_ASSIGNMENT_PREPARATION_ACTIONS = new Set(
  PREPARATION_PROJECTION_ACTIONS.filter((action) => action === 'prepare-room-assignment')
)

// “准备结束服务／分配房间”是只读试算，但返回值会携带可继续提交的权威快照。
// 因此即使网络响应顺序颠倒，也必须先在适配边界拦截，不能等根状态已被覆盖后
// 再由某个页面决定是否打开弹层。
let latestServiceCompletionPreparationIntent = null
let latestRoomAssignmentPreparationIntent = null

/** 公开资源版本仓：绑定 stateContextId，供后续命令冻结 contexts */
let cashierV3PublicVersionStore = {
  stateContextId: '',
  versions: new Map()
}

/** 显式 context switch 意图：仅匹配最新 epoch／token 的响应可切换 */
let contextSwitchIntent = null
let contextSwitchEpoch = 0
// A workspace identity belongs to one live page instance. sessionStorage is
// copied when a browser tab is duplicated, so it cannot be used here.
let clientSessionId = ''
// A browser tab must retain its workspace identity across a reload so an
// unfinished checkout can be recovered. sessionStorage is deliberately
// tab-scoped and is cleared when that tab closes; it is not shared with other
// cashier tabs or persisted as a user/account preference.
const CASHIER_V3_CLIENT_SESSION_STORAGE_KEY = 'cashier-v3.client-session-id'

const TRUSTED_V3_RESULT_STATUSES = new Set(['success', 'failed', 'conflict', 'result_unknown'])

const RESULT_QUERY_ACTIONS = new Set([
  'query-checkout-result',
  'query-service-completion-result',
  'query-hang-order-result',
  'query-writeoff-result',
  'query-reservation-result',
  'query-unified-query-export-task'
])

const QUERY_RESULT_ACTION_BY_COMMAND = Object.freeze({
  'submit-checkout': 'query-checkout-result',
  'retry-checkout': 'query-checkout-result',
  // 充值欠款补交是独立的补交记录。提交失败直接回收银台重试，
  // 不把一条简单补交转成通用结账的“查询原支付请求”流程。
  'confirm-service-completion': 'query-service-completion-result',
  'retry-service-completion': 'query-service-completion-result',
  'submit-hang-order': 'query-hang-order-result',
  'submit-writeoff': 'query-writeoff-result',
  'create-reservation': 'query-reservation-result',
  'update-reservation': 'query-reservation-result',
  'create-unified-query-export': 'query-unified-query-export-task'
})

function allowsDirectResultLookup(action, payload = {}) {
  return action === 'query-unified-query-export-task'
    && Boolean(String(payload.taskId || payload.task_id || '').trim())
}

function currentPublicVersionContextId() {
  return String(cashierV3PublicVersionStore.stateContextId || '')
}

function versionStoreKey(kind, id) {
  return `${String(kind)}:${String(id)}`
}

function normalizePublicVersionRow(row) {
  if (!row || typeof row !== 'object') return null
  const kind = String(row.kind || '')
  const id = String(row.id || '')
  const version = Number(row.version)
  if (!kind || !id || !Number.isInteger(version) || version <= 0) return null
  return { kind, id, version }
}

/**
 * 合并公开 versions；同一 context、同一 kind+id 仅允许单调增加。
 * @returns {{ merged: number, refused: number }}
 */
export function mergeCashierV3PublicVersions(versions, stateContextId, options = {}) {
  const refusedDowngrade = options.refusedDowngrade !== false
  const requestContextId = String(options.requestStateContextId || '')
  const responseContextId = String(stateContextId || '')
  // 必须同时绑定请求上下文与响应上下文；缺任一拒绝，不得默认“当前”
  if (!responseContextId || !requestContextId || requestContextId !== responseContextId) {
    return { merged: 0, refused: Array.isArray(versions) ? versions.length : 0 }
  }
  if (!Array.isArray(versions)) {
    return { merged: 0, refused: 0 }
  }
  if (currentPublicVersionContextId() && currentPublicVersionContextId() !== responseContextId) {
    return { merged: 0, refused: versions.length }
  }
  cashierV3PublicVersionStore.stateContextId = responseContextId
  let merged = 0
  let refused = 0
  for (const row of versions) {
    const normalized = normalizePublicVersionRow(row)
    if (!normalized) {
      refused += 1
      continue
    }
    const key = versionStoreKey(normalized.kind, normalized.id)
    const existing = cashierV3PublicVersionStore.versions.get(key)
    if (existing !== undefined && normalized.version <= existing) {
      refused += 1
      if (refusedDowngrade) continue
    }
    cashierV3PublicVersionStore.versions.set(key, normalized.version)
    merged += 1
  }
  return { merged, refused }
}

/**
 * 完整根投影只在工作台上下文真实改变时重置版本仓。
 *
 * 某些只读详情会预先返回其命令资源版本，而随后一次完整工作台投影
 * 不会重复携带该版本。若同一 context 的根替换也清仓，下一条写命令
 * 会失去刚读取的对象版本，继而错误进入恢复循环。
 */
function replaceCashierV3PublicVersionStore(stateContextId, versions = null) {
  const contextId = String(stateContextId || '')
  if (currentPublicVersionContextId() !== contextId) {
    cashierV3PublicVersionStore = {
      stateContextId: contextId,
      versions: new Map()
    }
  }
  if (Array.isArray(versions) && versions.length) {
    mergeCashierV3PublicVersions(versions, contextId, { requestStateContextId: contextId })
  }
}

export function getCashierV3PublicVersion(kind, id) {
  const currentContextId = stateContextIdOf(cashierV3State)
  if (!currentContextId) return null
  if (currentPublicVersionContextId() && currentPublicVersionContextId() !== currentContextId) return null
  return cashierV3PublicVersionStore.versions.get(versionStoreKey(kind, id)) ?? null
}

export function clearCashierV3PublicVersions(stateContextId = '') {
  const targetContextId = String(stateContextId || '')
  if (!targetContextId || currentPublicVersionContextId() === targetContextId) {
    cashierV3PublicVersionStore = {
      stateContextId: '',
      versions: new Map()
    }
  }
}

function createContextSwitchToken() {
  if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID()
  }
  return fallbackUuidV4()
}

/**
 * 登记一次显式 context switch 意图（调店／换账号后的首份根投影）。
 * 生产入口请使用 requestCashierV3ContextSwitch；本函数保留供测试注入固定 token。
 * 开始切换时原子清空完整旧根：会员／购物车／结账／预约／权限／versions／浮层／导航意图。
 */
export function beginCashierV3ContextSwitch(token = '') {
  contextSwitchEpoch += 1
  contextSwitchIntent = {
    epoch: contextSwitchEpoch,
    token: token ? String(token) : createContextSwitchToken()
  }
  clearCashierV3PublicVersions()
  latestServiceCompletionPreparationIntent = null
  latestRoomAssignmentPreparationIntent = null
  // 原子清空完整旧根，pending／切换失败期间不得保留旧账号／旧门店业务数据
  Object.keys(cashierV3State).forEach((key) => {
    delete cashierV3State[key]
  })
  Object.assign(cashierV3State, normalizeBootstrap(EMPTY_BOOTSTRAP))
  return { ...contextSwitchIntent }
}

export function currentCashierV3ContextSwitchIntent() {
  return contextSwitchIntent ? { ...contextSwitchIntent } : null
}

/** 测试重置：版本仓、context switch 意图与准备态现场 */
export function resetCashierV3BridgeForTests() {
  clearCashierV3PublicVersions()
  contextSwitchIntent = null
  contextSwitchEpoch = 0
  latestServiceCompletionPreparationIntent = null
  latestRoomAssignmentPreparationIntent = null
}

// 经营看板只承接后端指标、趋势与排行结果；任何金额、业绩或数据口径都不在浏览器计算。
const EMPTY_BUSINESS_DASHBOARD = {
  mode: 'store',
  scope: {
    dateRange: {
      start: '',
      end: ''
    },
    organization: null,
    store: null,
    forcedRangeLabel: ''
  },
  cards: [],
  selectedMetricCode: 'cash_performance',
  trend: {
    metricCode: 'cash_performance',
    points: [],
    isLoading: false
  },
  ranking: {
    dimension: 'staff',
    sortBy: 'cash_performance',
    sortOrder: 'desc',
    sortOptions: [],
    columns: [],
    records: [],
    isLoading: false
  },
  metricVersion: '',
  dataAsOf: '',
  aggregationCaughtUp: null,
  coverageStart: ''
}

const EMPTY_CASHIER = {
  customerMode: 'unselected',
  member: null,
  catalog: {
    types: [],
    categories: [],
    items: []
  },
  cart: {
    lines: [],
    summary: EMPTY_SUMMARY
  },
  entitlementSelector: null,
  checkoutComposition: null,
  checkout: null,
  serviceOrder: null,
  supplement: null
}

// “确认本次服务”是跨收银、预约、房间和核销的唯一编辑现场。
// 必须由后端返回这一份明确快照，不能猜测当前收银购物车或其他页面详情。
const EMPTY_SERVICE_COMPLETION = {
  serviceOrder: null,
  lines: [],
  commandContexts: []
}

const EMPTY_BOOTSTRAP = {
  // 后端为当前浏览器工作台完整根投影签发的严格单调序号；不是业务资源版本号。
  stateContextId: '',
  stateRevision: '0',
  storeName: '当前门店',
  currentStore: {
    id: null,
    name: '当前门店'
  },
  featurePermissions: {
    'cashier.v3.cashier': false,
    'cashier.v3.writeoff': false,
    'cashier.v3.room': false,
    'cashier.v3.reservation': false,
    'cashier.v3.member': false,
    'cashier.v3.hang': false,
    'cashier.v3.order_center': false,
    'cashier.v3.management_center': false,
    'cashier.v3.member.create': false,
    'cashier.v3.member.batch': false,
    'cashier.v3.inventory.overview': false,
    'cashier.v3.inventory.inbound': false,
    'cashier.v3.inventory.outbound': false,
    'cashier.v3.inventory.stock': false,
    'cashier.v3.inventory.count': false,
    'cashier.v3.inventory.movement': false,
    'cashier.v3.inventory.statistics': false,
    'cashier.v3.inventory.request': false,
    'cashier.v3.inventory.transfer': false,
    'cashier.v3.inventory.usage': false,
    'cashier.v3.inventory.import': false
  },
  workspace: {
    id: null,
    revision: 0,
    status: 'editing',
    serverTime: null
  },
  operator: {
    name: '当前账号',
    roleName: ''
  },
  pendingHangCount: 0,
  cashier: EMPTY_CASHIER,
  serviceCompletion: EMPTY_SERVICE_COMPLETION,
  writeoff: {
    member: null,
    sources: [],
    summary: {
      selectedSourceCount: 0,
      selectedProjectTypeCount: 0,
      selectedTimes: 0,
      writeoffAmount: 0
    },
    activeServiceSession: null,
    pendingSubmittedRequest: null,
    confirmation: null,
    supplement: null
  },
  room: {
    refreshedAt: null,
    staleMessage: '',
    pendingAssignmentCount: 0,
    pendingAssignments: [],
    unassignedList: {
      records: [],
      total: 0,
      page: 1,
      pageSize: 20,
      refreshedAt: '',
      staleMessage: ''
    },
    categories: [],
    detail: null,
    assignment: null
  },
  reservation: {
    quickCounts: {},
    records: [],
    total: 0,
    page: 1,
    pageSize: 20,
    detail: null,
    editor: {
      draft: {},
      catalogOptions: [],
      craftsmenOptions: [],
      rooms: []
    },
    calendar: {
      date: null,
      resources: [],
      blocks: []
    }
  },
  memberCenter: {
    canBatchOperate: false,
    statusOptions: [],
    records: [],
    total: 0,
    page: 1,
    pageSize: 20,
    detail: null,
    debtSnapshot: null
  },
  memberSelector: {
    records: [],
    total: 0,
    page: 1,
    pageSize: 20,
    isLoading: false
  },
  queryEntitySelector: {
    person: EMPTY_QUERY_ENTITY_PAGE,
    store: EMPTY_QUERY_ENTITY_PAGE,
    organization: EMPTY_QUERY_ENTITY_PAGE
  },
  managementCenter: {
    entries: []
  },
  businessDashboard: EMPTY_BUSINESS_DASHBOARD,
  hangOrders: {
    statusOptions: [],
    records: [],
    total: 0,
    page: 1,
    pageSize: 20
  },
  orderCenter: {
    businessTypes: [],
    statusOptions: [],
    statusOptionsByType: {},
    salesOrders: [],
    rechargeOrders: [],
    supplementOrders: [],
    refundOrders: [],
    debtRecords: [],
    serviceRecords: [],
    giftRecords: [],
    projectReplacementRecords: [],
    cardUpgradeRecords: [],
    projectUpgradeRecords: [],
    cardOperationRecords: [],
    countsByType: {},
    recordsByType: {},
    pagesByType: {},
    querySettingsByType: {},
    total: 0,
    page: 1,
    pageSize: 20,
    salesOrderDetail: null
  }
}

const DEV_PREVIEW_BOOTSTRAP = {
  stateContextId: 'preview-state-context-1',
  stateRevision: '1',
  storeName: '瑞昊一店',
  currentStore: {
    id: 'store-1',
    name: '瑞昊一店'
  },
  featurePermissions: {
    'cashier.v3.cashier': true,
    'cashier.v3.writeoff': true,
    'cashier.v3.room': true,
    'cashier.v3.reservation': true,
    'cashier.v3.member': true,
    'cashier.v3.hang': true,
    'cashier.v3.order_center': true,
    'cashier.v3.management_center': true,
    'cashier.v3.member.create': true,
    'cashier.v3.member.batch': true,
    'cashier.v3.inventory.overview': true,
    'cashier.v3.inventory.inbound': true,
    'cashier.v3.inventory.outbound': true,
    'cashier.v3.inventory.stock': true,
    'cashier.v3.inventory.count': true,
    'cashier.v3.inventory.movement': true,
    'cashier.v3.inventory.statistics': true,
    'cashier.v3.inventory.request': true,
    'cashier.v3.inventory.transfer': true,
    'cashier.v3.inventory.usage': true,
    'cashier.v3.inventory.import': true
  },
  workspace: {
    id: 'ws:store-1:operator-preview:preview-state-context-1',
    revision: 12,
    status: 'editing',
    serverTime: '2026-07-27T01:20:00+08:00'
  },
  operator: {
    name: '肖君鹏',
    roleName: '收银员'
  },
  pendingHangCount: 2,
  cashier: {
    member: {
      id: '10086',
      name: '肖君鹏',
      phone: '13800000000',
      storeName: '瑞昊一店',
      serviceAdvisorName: '李美容师',
      accountBalance: 2000,
      principalBalance: 1500,
      giftBalance: 500,
      cardBenefitAmount: 3600,
      remainingTimes: 28,
      activeCardCount: 3,
      totalAvailableAmount: 5600,
      totalBalanceAmount: '5600',
      outstandingDebtAmount: '0',
      outstandingDebtCount: 0,
      debtDataAsOf: '2026-07-27 10:35:00'
    },
    catalog: {
      types: ['项目', '产品', '卡项', '定制卡'],
      categories: ['全部', '面部', '身体', '头疗', '其他'],
      items: [
        { id: 'preview-project-1', name: '康养大师-热力通', kind: '项目', category: '面部', price: 300 },
        { id: 'preview-project-2', name: '康养大师-筋养通', kind: '项目', category: '面部', price: 180 },
        { id: 'preview-card-1', name: '康养大师-筋膜通', kind: '卡项', category: '全部', price: 1000 },
        { id: 'preview-product-1', name: '古法姜疗+清源平衡', kind: '产品', category: '其他', price: 260 },
        { id: 'preview-project-3', name: '脏腑灸+古法姜疗+臀疗', kind: '项目', category: '面部', price: 220 },
        { id: 'preview-project-4', name: '古法姜疗+脏腑灸', kind: '项目', category: '身体', price: 380 }
      ]
    },
    cart: {
      lines: [
        {
          id: 'preview-line-1',
          lineRole: 'sale',
          name: '康养大师-热力通',
          kind: '项目',
          quantity: 1,
          finalAmount: 300,
          originalAmount: 300,
          craftsmenSummary: '肖君鹏(点)',
          salespersonSummary: '李四(售前)',
          couponSummary: '未选择',
          debtSummary: '未设置',
          serviceRole: '主项目',
          serviceSource: '本次购买'
        },
        {
          id: 'preview-line-2',
          lineRole: 'sale',
          name: '康养大师-筋膜通',
          kind: '次卡',
          quantity: 1,
          finalAmount: 1000,
          originalAmount: 1000,
          salespersonSummary: '王五',
          couponSummary: '未选择',
          debtSummary: '未设置'
        },
        {
          id: 'preview-line-3',
          lineRole: 'entitlement_service',
          entitlementInstanceId: 'preview-card-1',
          entitlementInstanceType: 'card_project',
          entitlementSourceDetailId: 'preview-card-project-detail-1',
          entitlementSourceVersion: 3,
          projectId: 'preview-writeoff-project-1',
          projectVersion: 2,
          entitlementSourceName: '年度护理卡',
          fullCardNo: 'K202607270001',
          remainingTimes: 3,
          occupiedTimes: 0,
          availableTimes: 3,
          validThroughLabel: '有效期至 2026-12-31',
          name: '康养大师-热力通',
          kind: '项目',
          quantity: 1,
          finalAmount: 0,
          originalAmount: 0,
          writeoffAmount: 100,
          consumptionPerformanceAmount: 100,
          craftsmenSummary: '肖君鹏(主)',
          salespersonSummary: '—',
          couponSummary: '不适用',
          debtSummary: '不适用',
          serviceRole: '主项目',
          serviceSource: '卡内项目'
        },
        {
          id: 'preview-line-4',
          lineRole: 'sale',
          name: '康养大师-筋养通',
          kind: '项目',
          quantity: 1,
          finalAmount: 180,
          originalAmount: 180,
          craftsmenSummary: '张敏(点)',
          salespersonSummary: '李四(售前)',
          couponSummary: '未选择',
          debtSummary: '未设置',
          serviceSource: '本次购买'
        },
        {
          id: 'preview-line-5',
          lineRole: 'sale',
          name: '古法姜疗+清源平衡',
          kind: '产品',
          quantity: 1,
          finalAmount: 260,
          originalAmount: 260,
          salespersonSummary: '王五',
          couponSummary: '未选择',
          debtSummary: '未设置',
          serviceSource: '本次购买'
        },
        {
          id: 'preview-line-6',
          lineRole: 'sale',
          name: '脏腑灸+古法姜疗+臀疗',
          kind: '项目',
          quantity: 1,
          finalAmount: 220,
          originalAmount: 220,
          craftsmenSummary: '肖君鹏(点)',
          salespersonSummary: '李四(售前)',
          couponSummary: '未选择',
          debtSummary: '未设置',
          serviceSource: '本次购买'
        }
      ],
      summary: {
        selectedCount: 6,
        originalAmount: 1960,
        discountAmount: 0,
        receivableAmount: 1960
      },
      primaryAction: 'collect_and_complete',
      primaryActionLabel: '收款并完成服务'
    },
    checkoutComposition: {
      lineRoles: ['entitlement_service', 'sale'],
      hasSale: true,
      hasEntitlement: true,
      primaryAction: 'collect_and_complete',
      primaryActionLabel: '收款并完成服务',
      steps: [
        { key: 'order', number: 1, label: '确认本次内容' },
        { key: 'payment', number: 2, label: '收款信息' },
        { key: 'final', number: 3, label: '收款并完成服务' },
        { key: 'result', number: 4, label: '处理结果' }
      ]
    },
    checkout: {
      businessDate: '2026-07-27',
      sourceEnabled: true,
      sourceLabel: '到店',
      debtAmount: 0,
      cashPerformanceAmount: 1300,
      balancePaymentAmount: 0,
      payment: {
        availableBalance: 2000,
        principalBalance: 1500,
        giftBalance: 500,
        balanceAvailable: true,
        balanceVerification: {
          required: true,
          status: 'not_verified',
          label: '验证会员身份',
          description: '本门店已开启余额支付身份验证；本次仅保留交互入口，不做真实验证测试。'
        },
        summary: {
          receivableAmount: 1300,
          selectedAmount: 1300,
          remainingAmount: 0,
          validationMessage: '收款金额以结账前后端最终试算为准。'
        },
        methods: [
          { id: 'unionpay', name: '银联' },
          { id: 'wechat', name: '微信' },
          { id: 'alipay', name: '支付宝' },
          { id: 'dianping_voucher', name: '大众验券' },
          { id: 'douyin_voucher', name: '抖音验券' },
          { id: 'partner_collection', name: '合作方收款' },
          { id: 'other_collection', name: '其他收款' },
          { id: 'old_card_entry', name: '旧卡录入', canAdd: false, disabledReason: '旧卡录入需单独办理，不能与其他收款方式组合。', cashPerformanceEligible: false }
        ],
        selectedLines: [
          { id: 'preview-payment-1', name: '微信', amount: 1300, status: '待收款', canEdit: true, canRemove: true, noteSummary: '未填写备注' }
        ]
      }
    },
    serviceOrder: {
      id: 'FW202607270001',
      revision: 3,
      serviceNo: 'FW202607270001',
      status: '服务中',
      roomName: '普通房 02',
      confirmationRequired: true,
      sections: [
        { key: 'card-reservation', label: '卡内／预约项目', description: '服务完成后按实际完成情况核销。', lineIds: ['preview-line-3'] },
        { key: 'new-purchase', label: '本次购买', description: '本次新增消费，服务确认后再继续结账。', lineIds: ['preview-line-1', 'preview-line-2', 'preview-line-4', 'preview-line-5', 'preview-line-6'] }
      ],
      completion: {
        status: 'editing',
        preview: {
          completedProjectCount: 2,
          writeoffAmount: 100,
          consumptionPerformanceAmount: 100,
          laborPerformanceAmount: 100
        }
      }
    },
    supplement: null
  },
  writeoff: {
    draftId: 'WO202607270001',
    revision: 5,
    member: {
      id: '10086',
      name: '肖君鹏',
      phone: '13800000000',
      accountBalance: 2000,
      principalBalance: 1500,
      giftBalance: 500,
      cardBenefitAmount: 3600,
      remainingTimes: 28,
      activeCardCount: 3,
      totalAvailableAmount: 5600
    },
    sources: [
      {
        id: 'preview-card-1',
        entitlementInstanceId: 'preview-card-1',
        entitlementInstanceType: 'card_project',
        version: 3,
        sourceType: 'card',
        sourceTypeLabel: '次卡',
        sourceKind: 'count_card',
        sourceKindLabel: '次卡',
        name: '年度护理卡',
        reference: 'K202607270001',
        fullCardNo: 'K202607270001',
        status: '可用',
        expiryText: '有效期至 2026-12-31',
        expiryDate: '2026-12-31',
        availableProjectCount: 1,
        remainingTimes: 3,
        occupiedTimes: 0,
        availableTimes: 3,
        remainingAmount: 300,
        purchaseTimes: 10,
        purchaseAmount: 1000,
        orderRemark: '首次到店体验套餐',
        selectedProjectCount: 1,
        projects: [
          {
            id: 'preview-writeoff-project-1',
            projectId: 'preview-writeoff-project-1',
            entitlementSourceDetailId: 'preview-card-project-detail-1',
            version: 2,
            name: '水光护理',
            remainingTimes: 3,
            totalTimes: 10,
            remainingAmount: 300,
            purchaseTimes: 10,
            purchaseAmount: 1000,
            actualUnitAmount: '100.0000',
            amountCalculationVersion: 'preview-allocated-purchase-v1',
            reservedTimes: 0,
            occupiedTimes: 0,
            availableTimes: 3,
            expiryDate: '2026-12-31',
            selectedTimes: 1,
            writeoffAmount: 100,
            selected: true,
            serviceObject: '本人',
            craftsmen: [
              { id: 'staff-1', name: '肖君鹏', code: '0010', positionName: '手艺人', storeName: '瑞昊一店', selectable: true }
            ],
            craftsmenSummary: '肖君鹏(点)'
          }
        ]
      },
      {
        id: 'preview-gift-1',
        entitlementInstanceId: 'preview-gift-1',
        entitlementInstanceType: 'independent_gift',
        version: 4,
        sourceType: 'independent_gift',
        sourceTypeLabel: '赠送',
        sourceKind: 'gift',
        sourceKindLabel: '赠送',
        name: '赠送水光护理',
        reference: 'ZS202607270001',
        fullCardNo: 'ZS202607270001',
        status: '可用',
        expiryText: '有效期至 2026-08-15',
        expiryDate: '2026-08-15',
        expiring: true,
        availableProjectCount: 1,
        remainingTimes: 1,
        occupiedTimes: 0,
        availableTimes: 1,
        remainingAmount: 0,
        purchaseTimes: 1,
        purchaseAmount: 0,
        orderRemark: '到店礼',
        selectedProjectCount: 0,
        projects: [
          {
            id: 'preview-writeoff-project-2',
            projectId: 'preview-writeoff-project-2',
            entitlementSourceDetailId: 'preview-gift-project-detail-1',
            version: 1,
            name: '水光护理',
            remainingTimes: 1,
            totalTimes: 1,
            remainingAmount: 0,
            purchaseTimes: 1,
            purchaseAmount: 0,
            actualUnitAmount: '0.0000',
            amountCalculationVersion: 'preview-allocated-purchase-v1',
            reservedTimes: 0,
            occupiedTimes: 0,
            availableTimes: 1,
            expiryDate: '2026-08-15',
            selectedTimes: 0,
            writeoffAmount: 0,
            selected: false,
            serviceObject: '本人',
            craftsmenSummary: '待分配'
          }
        ]
      }
    ],
    summary: {
      selectedSourceCount: 1,
      selectedProjectTypeCount: 1,
      selectedTimes: 1,
      writeoffAmount: 100
    },
    activeServiceSession: null,
    pendingSubmittedRequest: null,
    supplement: null
  },
  room: {
    refreshedAt: '2026-07-27 01:30',
    staleMessage: '',
    pendingAssignmentCount: 2,
    pendingAssignments: [
      { id: 'preview-unassigned-1', memberName: '李佳', summary: '水光护理 · 14:30', type: '预约' }
    ],
    unassignedList: {
      total: 2,
      page: 1,
      pageSize: 20,
      refreshedAt: '2026-07-27 01:30',
      staleMessage: '',
      records: [
        {
          recordKey: 'reservation:reservation-1',
          recordType: 'reservation',
          assignmentScope: 'reservation_plan',
          sourceLabel: '预约',
          statusCode: 'pending_confirmation',
          statusLabel: '待确认',
          member: { id: 'member-4', name: '李佳' },
          time: { startAt: '2026-07-27 14:30:00', endAt: '2026-07-27 15:30:00', displayText: '今天 14:30–15:30' },
          projectSummary: { primaryProjectName: '水光护理', totalCount: 1, displayText: '水光护理' },
          craftsmanSummary: '肖君鹏',
          reservation: { id: 'reservation-1', no: 'YY202607270001', revision: 4 },
          serviceOrder: { id: 'service-reservation-1', no: 'FW202607270010', revision: 1 },
          roomAssignmentStatus: 'unassigned',
          actions: {
            view: { visible: true, enabled: true, code: 'open-reservation-detail', disabledReason: '' },
            assignRoom: { visible: true, enabled: true, code: 'prepare-room-assignment', mode: 'assign', assignmentScope: 'reservation_plan', disabledReason: '' }
          }
        },
        {
          recordKey: 'service:service-unassigned-1',
          recordType: 'service',
          assignmentScope: 'active_service',
          sourceLabel: '当前服务',
          statusCode: 'serving',
          statusLabel: '服务中',
          member: { id: 'member-5', name: '陈女士' },
          time: { startAt: '2026-07-27 11:00:00', endAt: '2026-07-27 12:30:00', displayText: '今天 11:00–12:30' },
          projectSummary: { primaryProjectName: '面部清洁', totalCount: 2, displayText: '面部清洁 + 1 项' },
          craftsmanSummary: '李美容师',
          reservation: null,
          serviceOrder: { id: 'service-unassigned-1', no: 'FW202607270011', revision: 3 },
          roomAssignmentStatus: 'unassigned',
          actions: {
            view: { visible: true, enabled: true, code: 'open-room-service-session', disabledReason: '' },
            assignRoom: { visible: true, enabled: true, code: 'prepare-room-assignment', mode: 'assign', assignmentScope: 'active_service', disabledReason: '' }
          }
        }
      ]
    },
    categories: [
      {
        id: 'ordinary',
        name: '普通房间',
        rooms: [
          { id: 'room-1', name: '普通房 01', status: '空闲', nextReservation: '14:30 李佳 · 水光护理' },
          { id: 'room-2', name: '普通房 02', status: '服务中', memberName: '肖君鹏', serviceDuration: '已服务 35 分钟', primaryCraftsman: '肖君鹏', pendingWriteoffCount: 1, newConsumptionAmount: 300, serviceOrderId: 'FW202607270001', serviceOrderRevision: 3 },
          { id: 'room-3', name: '普通房 03', status: '待结账', memberName: '王女士', serviceFinishedAt: '服务结束 10:20', pendingCheckoutAmount: 680, serviceOrderId: 'FW202607270002', serviceOrderRevision: 7 }
        ]
      },
      {
        id: 'vip',
        name: 'VIP 房间',
        rooms: [
          { id: 'vip-1', name: 'VIP 房 01', status: '空闲', nextReservation: '暂无后续预约' },
          { id: 'vip-2', name: 'VIP 房 02', status: '空闲', nextReservation: '16:00 陈女士 · 身体护理' }
        ]
      }
    ]
  },
  reservation: {
    quickCounts: { today: 6, pending: 1, serving: 2 },
    editor: {
      draft: {
        appointmentTime: '2026-07-27T14:30',
        projects: [],
        craftsmen: [],
        roomId: null,
        remark: ''
      },
      catalogOptions: [
        { id: 'editor-project-1', name: '水光护理', source: 'unpaid', projectServiceDuration: 60, addonServiceDuration: 30, appliedDurationMinutes: 60, durationDescription: '已使用商品预约设置中的项目服务时长。' },
        { id: 'editor-project-2', name: '面部清洁', source: 'card', projectServiceDuration: 45, addonServiceDuration: 20, appliedDurationMinutes: 45, durationDescription: '已使用商品预约设置中的项目服务时长。' },
        { id: 'editor-project-3', name: '补水护理', source: 'unpaid', projectServiceDuration: 0, addonServiceDuration: 30, appliedDurationLabel: '60分钟（系统默认）', durationDescription: '项目服务时长未配置，当前采用系统默认 60 分钟；作为明细项目时采用 30 分钟。' }
      ],
      craftsmenOptions: [
        { id: 'staff-1', name: '肖君鹏', storeName: '瑞昊一店', selectable: true },
        { id: 'staff-2', name: '李美容师', storeName: '瑞昊一店', selectable: true },
        { id: 'staff-3', name: '赵美容师', storeName: '瑞昊一店', selectable: true }
      ],
      rooms: [
        { id: 'room-1', name: '普通房 01', categoryName: '普通房间' },
        { id: 'vip-1', name: 'VIP 房 01', categoryName: 'VIP 房间' },
        { id: 'vip-2', name: 'VIP 房 02', categoryName: 'VIP 房间' }
      ]
    },
    records: [
      {
        id: 'reservation-1',
        revision: 4,
        reservationNo: 'YY202607270001',
        appointmentTime: '2026-07-27 14:30',
        memberName: '李佳',
        phone: '13900000001',
        projectSummary: '水光护理',
        projectSource: '未购项目',
        craftsmanSummary: '肖君鹏',
        roomName: '待分配房间',
        status: '待确认',
        remark: '到店前电话确认',
        source: '门店预约',
        creator: '肖君鹏'
      },
      {
        id: 'reservation-2',
        revision: 6,
        reservationNo: 'YY202607270002',
        appointmentTime: '2026-07-27 15:00',
        memberName: '陈女士',
        phone: '13900000002',
        projectSummary: '面部清洁 + 补水护理',
        projectSource: '卡内项目',
        craftsmanSummary: '李美容师',
        roomName: 'VIP 房 02',
        status: '已预约',
        remark: '',
        source: '电话预约',
        creator: '李美容师'
      },
      {
        id: 'reservation-3',
        revision: 3,
        reservationNo: 'YY202607270003',
        appointmentTime: '2026-07-27 10:00',
        memberName: '王女士',
        phone: '13900000003',
        projectSummary: '身体护理',
        projectSource: '本次购买',
        craftsmanSummary: '肖君鹏',
        roomName: '普通房 03',
        status: '待结账',
        remark: '',
        source: '门店预约',
        creator: '肖君鹏'
      }
    ],
    calendar: {
      date: '2026-07-27',
      resources: [
        { id: 'staff-1', name: '肖君鹏', type: 'staff' },
        { id: 'staff-2', name: '李美容师', type: 'staff' },
        { id: 'unassigned-staff', name: '待分配手艺人', type: 'unassigned_staff' },
        { id: 'room-1', name: '普通房 01', type: 'room' },
        { id: 'vip-2', name: 'VIP 房 02', type: 'room' },
        { id: 'unassigned-room', name: '待分配房间', type: 'unassigned_room' }
      ],
      blocks: [
        { id: 'reservation-1-staff', reservationId: 'reservation-1', resourceId: 'staff-1', start: '14:30', end: '15:30', memberName: '李佳', projectCount: 1, status: '待确认', roomName: '待分配房间' },
        { id: 'reservation-1-room', reservationId: 'reservation-1', resourceId: 'unassigned-room', start: '14:30', end: '15:30', memberName: '李佳', projectCount: 1, status: '待确认', roomName: '待分配房间' },
        { id: 'reservation-2-staff', reservationId: 'reservation-2', resourceId: 'staff-2', start: '15:00', end: '16:15', memberName: '陈女士', projectCount: 2, status: '已预约', roomName: 'VIP 房 02' },
        { id: 'reservation-2-room', reservationId: 'reservation-2', resourceId: 'vip-2', start: '15:00', end: '16:15', memberName: '陈女士', projectCount: 2, status: '已预约', roomName: 'VIP 房 02' }
      ]
    }
  },
  memberCenter: {
    canBatchOperate: true,
    statusOptions: [
      { value: '正常', label: '正常', normal: true },
      { value: '已停用', label: '已停用', normal: false },
      { value: '已注销', label: '已注销', normal: false }
    ],
    records: [
      {
        id: 'member-10086',
        name: '肖君鹏',
        phone: '13800000000',
        memberNo: 'HY202607270001',
        status: '正常',
        level: '金卡会员',
        tags: ['面部护理', '高频到店'],
        storeName: '瑞昊一店',
        exclusiveServiceStaff: '李美容师',
        accountBalance: 2000,
        activeCardCount: 3,
        remainingProjectTimes: 28,
        remainingProjectAmount: 3600,
        totalBalanceAmount: '5600',
        outstandingDebtAmount: '0',
        outstandingDebtCount: 0,
        debtDataAsOf: '2026-07-27 10:35:00',
        debtAmount: 0,
        totalConsumptionAmount: 9800,
        visitCount: 16,
        latestPurchaseDate: '2026-07-25',
        lastServiceStaff: '肖君鹏',
        latestVisitDate: '2026-07-26',
        createdAt: '2025-11-06 10:22'
      },
      {
        id: 'member-10087',
        name: '陈女士',
        phone: '13900000002',
        memberNo: 'HY202607270002',
        status: '正常',
        level: '银卡会员',
        tags: ['身体护理'],
        storeName: '瑞昊一店',
        exclusiveServiceStaff: '待分配',
        accountBalance: 860,
        activeCardCount: 1,
        remainingProjectTimes: 6,
        remainingProjectAmount: 1200,
        totalBalanceAmount: '2060',
        outstandingDebtAmount: '900',
        outstandingDebtCount: 2,
        debtDataAsOf: '2026-07-27 10:35:00',
        debtAmount: 900,
        totalConsumptionAmount: 4280,
        visitCount: 8,
        latestPurchaseDate: '2026-07-24',
        lastServiceStaff: '李美容师',
        latestVisitDate: '2026-07-24',
        createdAt: '2026-02-11 14:08'
      },
      {
        id: 'member-10088',
        name: '王女士',
        phone: '13900000003',
        memberNo: 'HY202607270003',
        status: '已停用',
        level: '普通会员',
        tags: ['需回访'],
        storeName: '瑞昊一店',
        exclusiveServiceStaff: '赵美容师',
        accountBalance: 0,
        activeCardCount: 0,
        remainingProjectTimes: 0,
        remainingProjectAmount: 0,
        debtAmount: 180,
        totalConsumptionAmount: 1360,
        visitCount: 3,
        latestPurchaseDate: '2026-06-13',
        lastServiceStaff: '赵美容师',
        latestVisitDate: '2026-06-13',
        createdAt: '2026-03-05 09:20'
      }
    ]
  },
  memberSelector: {
    total: 3,
    page: 1,
    pageSize: 20,
    isLoading: false,
    records: [
      { id: '10086', name: '肖君鹏', phone: '13800000000', memberNo: 'HY202607270001', status: '正常', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店' },
      { id: 'member-10087', name: '陈女士', phone: '13900000002', memberNo: 'HY202607270002', status: '正常', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店', accountBalance: 860, cardBenefitAmount: 1200, totalBalanceAmount: '2060', outstandingDebtAmount: '900', outstandingDebtCount: 2, debtDataAsOf: '2026-07-27 10:35:00' },
      { id: 'member-10088', name: '王女士', phone: '13900000003', memberNo: 'HY202607270003', status: '已停用', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店', selectable: false }
    ]
  },
  queryEntitySelector: {
    person: {
      total: 3,
      page: 1,
      pageSize: 20,
      isLoading: false,
      records: [
        { id: 'staff-1', name: '肖君鹏', code: '0010', mobile: '13800000000', positionName: '手艺人', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店' },
        { id: 'staff-2', name: '李美容师', code: '0011', mobile: '13900000002', positionName: '手艺人', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店' },
        { id: 'staff-3', name: '赵美容师', code: '0012', mobile: '13900000003', positionName: '销售人', storeName: '瑞昊一店', organizationName: '瑞昊美容集团 / 一店' }
      ]
    },
    store: {
      total: 1,
      page: 1,
      pageSize: 20,
      isLoading: false,
      records: [
        { id: 'store-1', name: '瑞昊一店', code: 'S001', organizationName: '瑞昊美容集团', address: '当前门店' }
      ]
    },
    organization: {
      total: 3,
      page: 1,
      pageSize: 20,
      isLoading: false,
      records: [
        { id: 'org-group', name: '瑞昊美容集团', code: 'ORG001', organizationPath: '瑞昊美容集团' },
        { id: 'org-division', name: '美业事业部', code: 'ORG010', organizationPath: '瑞昊美容集团 / 美业事业部' },
        { id: 'org-store', name: '瑞昊一店', code: 'ORG101', organizationPath: '瑞昊美容集团 / 美业事业部 / 瑞昊一店' }
      ]
    }
  },
  managementCenter: {
    entries: [
      {
        id: 'business-dashboard-v3',
        name: '经营看板',
        description: '查看当前门店的经营指标、趋势和员工排行。',
        category: '经营管理',
        mode: 'v3',
        status: 'available',
        allowed: true
      }
    ]
  },
  businessDashboard: {
    mode: 'store',
    scope: {
      dateRange: { start: '2026-07-01', end: '2026-07-27' },
      forcedRangeLabel: '当前登录门店：瑞昊一店'
    },
    cards: [
      { metricCode: 'salesperson_performance', name: '销售人业绩', value: 16800, unit: '元', description: '销售商品明细按最终分配规则归属给所选销售人的现金业绩。' },
      { metricCode: 'cash_performance', name: '现金业绩', value: 22860, unit: '元', description: '银联、微信、支付宝、大众验券、抖音验券、合作方收款、其他收款。' },
      { metricCode: 'actual_performance', name: '实际业绩', value: 18340, unit: '元', description: '现金业绩减去分配给“合作方”或“外包”销售人的业绩。销售人在员工管理中设置人员类型；普通内部员工分配的销售人业绩不扣。员工类型和分配结果按业务发生时保存，后来改人员类型不反改历史数据。' },
      { metricCode: 'consumption_performance', name: '消耗业绩', value: 9360, unit: '元', description: '项目真正完成服务并核销后才产生的项目消耗业绩。' },
      { metricCode: 'refund_amount', name: '退款金额', value: 300, unit: '元', description: '退款成功后形成的反向调整金额。' },
      { metricCode: 'recharge_amount', name: '储值金额', value: 7600, unit: '元', description: '充值成功写入会员账户的本金，赠送金额单独记录；使用七种记账方式充值时产生现金业绩。' },
      { metricCode: 'balance_deduction', name: '余额扣款', value: 4200, unit: '元', description: '使用会员已有储值余额支付订单的金额，不属于现金业绩。' },
      { metricCode: 'casual_customer_count', name: '散客数量', value: 12, unit: '人', description: '未绑定会员的到店消费人数。' },
      { metricCode: 'new_customer_count', name: '新客数量', value: 9, unit: '人', description: '统计期内首次形成有效消费的会员人数。' },
      { metricCode: 'reservation_customer_count', name: '预约客数', value: 26, unit: '人', description: '按预约服务日期和会员去重统计。' }
    ],
    selectedMetricCode: 'cash_performance',
    trend: {
      metricCode: 'cash_performance',
      points: [
        { label: '07-21', value: 820 }, { label: '07-22', value: 1360 }, { label: '07-23', value: 980 },
        { label: '07-24', value: 1720 }, { label: '07-25', value: 1210 }, { label: '07-26', value: 2140 }, { label: '07-27', value: 1760 }
      ],
      isLoading: false
    },
    ranking: {
      dimension: 'staff',
      sortBy: 'cash_performance',
      sortOrder: 'desc',
      sortOptions: [
        { value: 'cash_performance', label: '现金业绩' },
        { value: 'salesperson_performance', label: '销售人业绩' },
        { value: 'labor_performance', label: '劳动业绩' },
        { value: 'service_customer_count', label: '服务客户数' },
        { value: 'service_project_count', label: '服务项目数' }
      ],
      columns: [
        { key: 'rank', label: '排名', type: 'number' },
        { key: 'name', label: '员工名称' },
        { key: 'salespersonPerformance', label: '销售人业绩', type: 'money' },
        { key: 'cashPerformance', label: '现金业绩', type: 'money' },
        { key: 'laborPerformance', label: '劳动业绩', type: 'money' },
        { key: 'serviceCustomerCount', label: '服务客户数', type: 'number' },
        { key: 'serviceProjectCount', label: '服务项目数', type: 'number' }
      ],
      records: [
        { id: 'staff-1', rank: 1, name: '肖君鹏', salespersonPerformance: 6800, cashPerformance: 8600, laborPerformance: 4200, serviceCustomerCount: 23, serviceProjectCount: 31 },
        { id: 'staff-2', rank: 2, name: '李美容师', salespersonPerformance: 5600, cashPerformance: 7100, laborPerformance: 3890, serviceCustomerCount: 19, serviceProjectCount: 28 },
        { id: 'staff-3', rank: 3, name: '赵美容师', salespersonPerformance: 4400, cashPerformance: 5160, laborPerformance: 2270, serviceCustomerCount: 13, serviceProjectCount: 17 }
      ],
      isLoading: false
    },
    metricVersion: 'v3.0',
    dataAsOf: '2026-07-27 10:35:00',
    aggregationCaughtUp: true,
    coverageStart: '2026-07-27'
  },
  hangOrders: {
    statusOptions: ['普通挂单', '服务中', '待结账'],
    records: [
      {
        id: 'hang-1',
        revision: 2,
        hangAt: '2026-07-27 10:15',
        memberName: '陈女士',
        phone: '13900000002',
        itemCount: 2,
        receivableAmount: 680,
        operator: '李美容师',
        orderNote: '到店后继续确认优惠券',
        status: '普通挂单'
      },
      {
        id: 'hang-2',
        revision: 5,
        hangAt: '2026-07-27 10:32',
        memberName: '王女士',
        phone: '13900000003',
        itemCount: 1,
        receivableAmount: 300,
        operator: '肖君鹏',
        orderNote: '普通房 03 服务结束后结账',
        status: '待结账'
      }
    ]
  },
  orderCenter: {
    businessTypes: [
      '销售订单', '充值订单', '补交订单', '退款订单', '服务记录',
      '赠送记录', '项目替换记录', '卡项升级记录', '项目升级记录'
    ],
    statusOptions: [
      { value: '正常', label: '正常订单', normal: true },
      { value: '有欠款', label: '有欠款', normal: true },
      { value: '已作废', label: '已作废', normal: false }
    ],
    salesOrders: [
      {
        id: 'sales-1',
        salesOrderNo: 'XS202607270001',
        businessDate: '2026-07-27',
        memberName: '肖君鹏',
        phone: '13800000000',
        storeName: '瑞昊一店',
        itemSummary: '水光护理、年度次卡',
        itemCount: 2,
        receivableAmount: 1300,
        discountAmount: 0,
        debtAmount: 0,
        actualReceivedAmount: 1300,
        paymentSummary: '微信',
        salespersonSummary: '李四、王五',
        cashierName: '肖君鹏',
        sourcePrimary: '到店',
        sourceSecondary: '收银台',
        paymentStatus: '已支付',
        orderStatus: '正常',
        isSupplement: false,
        paymentCompletedAt: '2026-07-27 10:20'
      },
      {
        id: 'sales-2',
        salesOrderNo: 'XS202607260018',
        businessDate: '2026-07-26',
        memberName: '陈女士',
        phone: '13900000002',
        storeName: '瑞昊一店',
        itemSummary: '护肤产品',
        itemCount: 1,
        receivableAmount: 260,
        discountAmount: 20,
        debtAmount: 0,
        actualReceivedAmount: 240,
        paymentSummary: '余额支付',
        salespersonSummary: '李美容师',
        cashierName: '肖君鹏',
        sourcePrimary: '到店',
        sourceSecondary: '收银台',
        paymentStatus: '已支付',
        orderStatus: '正常',
        isSupplement: false,
        paymentCompletedAt: '2026-07-26 16:08'
      }
    ],
    rechargeOrders: [{
      id: 'recharge-1', rechargeOrderNo: 'CZ202607270006', businessDate: '2026-07-27',
      memberName: '陈女士', phone: '13900000002', storeName: '瑞昊一店', rechargePlan: '充 1000 赠 100',
      rechargeAmount: '1000', giftAmount: '100', actualReceivedAmount: '1000', paymentMethod: '微信',
      salespersonName: '李美容师', operatorName: '肖君鹏', paymentStatus: '已支付', orderStatus: '正常',
      paymentCompletedAt: '2026-07-27 09:42'
    }],
    supplementOrders: [{
      id: 'supplement-1', supplementOrderNo: 'BJ202607270003', businessDate: '2026-07-27',
      debtNo: 'QK202607180009-01', sourceOrderNo: 'XS202607180021', memberName: '陈女士', phone: '13900000002',
      debtSummary: '年度护理卡首笔欠款', supplementAmount: '300', paymentMethod: '银联', storeName: '瑞昊一店',
      operatorName: '肖君鹏', paymentStatus: '已支付', paymentCompletedAt: '2026-07-27 11:06'
    }],
    refundOrders: [{
      id: 'refund-1', refundOrderNo: 'TK202607260002', businessDate: '2026-07-26',
      sourceOrderNo: 'XS202607220011', memberName: '陈女士', phone: '13900000002', refundSummary: '舒敏修护项目 ×1',
      refundAmount: '260', refundMethod: '原路退回', storeName: '瑞昊一店', operatorName: '店长',
      refundStatus: '退款成功', refundCompletedAt: '2026-07-26 17:25'
    }],
    serviceRecords: [{
      id: 'service-1', serviceRecordNo: 'FW202607270020', businessDate: '2026-07-27',
      memberName: '陈女士', phone: '13900000002', serviceProject: '深层清洁护理', serviceSource: '卡内项目',
      cardName: '年度护理卡', cardNo: 'KH202607180021', usedTimes: 1, storeName: '瑞昊一店',
      craftsmenSummary: '李美容师（主要）', laborPerformanceAmount: '180', operatorName: '肖君鹏',
      statusLabel: '正常', isSupplement: false, completedAt: '2026-07-27 15:58'
    }],
    giftRecords: [{
      id: 'gift-1', giftRecordNo: 'ZS202607270005', businessDate: '2026-07-27', memberName: '陈女士',
      giftSource: 'XS202607270001', giftType: '优惠券', giftContent: '生日礼遇券', giftQuantity: 1,
      effectiveAt: '2026-07-27', expiresAt: '2026-08-27', statusLabel: '有效', storeName: '瑞昊一店',
      operatorName: '肖君鹏', giftReason: '生日关怀', createdAt: '2026-07-27 10:24'
    }],
    projectReplacementRecords: [{
      id: 'replacement-1', replacementRecordNo: 'TH202607250001', businessDate: '2026-07-25',
      memberName: '陈女士', phone: '13900000002', sourceProjectName: '深层清洁护理', sourceCardName: '焕颜护理次卡',
      replacementTimes: 2, replacementAmount: '520', targetProjectName: '舒敏补水护理', storeName: '瑞昊一店',
      operatorName: '肖君鹏', statusLabel: '有效', createdAt: '2026-07-25 16:22'
    }],
    cardUpgradeRecords: [{
      id: 'card-upgrade-1', cardUpgradeNo: 'KS202607240001', businessDate: '2026-07-24',
      memberName: '陈女士', phone: '13900000002', sourceCardName: '基础护理次卡', targetCardName: '年度综合护理卡',
      deductionAmount: '800', payableAmount: '1200', salesOrderNo: 'XS202607240018', storeName: '瑞昊一店',
      operatorName: '肖君鹏', statusLabel: '已完成', completedAt: '2026-07-24 14:36'
    }],
    projectUpgradeRecords: [{
      id: 'project-upgrade-1', projectUpgradeNo: 'XM202607230002', businessDate: '2026-07-23',
      memberName: '陈女士', phone: '13900000002', sourceProjectName: '基础补水护理', targetProjectName: '水光焕肤护理',
      deductionAmount: '180', payableAmount: '220', salesOrderNo: 'XS202607230009', storeName: '瑞昊一店',
      operatorName: '李美容师', statusLabel: '已完成', completedAt: '2026-07-23 12:18'
    }]
  }
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

const EMPTY_BOOTSTRAP_ROOT_KEYS = Object.freeze(Object.keys(EMPTY_BOOTSTRAP))

/** 完整根 state 必须包含 EMPTY_BOOTSTRAP 的全部顶层键 */
export function validateRootStateSchema(state = {}) {
  if (!state || typeof state !== 'object' || Array.isArray(state)) {
    return {
      valid: false,
      code: 'STATE_SCHEMA_INCOMPLETE',
      message: '后端完整状态结构不完整，已拒绝覆盖当前页面。',
      missingKeys: EMPTY_BOOTSTRAP_ROOT_KEYS
    }
  }
  const missingKeys = EMPTY_BOOTSTRAP_ROOT_KEYS.filter((key) => !(key in state))
  if (missingKeys.length) {
    return {
      valid: false,
      code: 'STATE_SCHEMA_INCOMPLETE',
      message: '后端完整状态结构不完整，已拒绝覆盖当前页面。',
      missingKeys
    }
  }

  const problems = []
  const requireObject = (key) => {
    const value = state[key]
    if (!value || typeof value !== 'object' || Array.isArray(value)) problems.push(`${key}_not_object`)
  }
  const requireString = (key, { nonEmpty = false } = {}) => {
    const value = state[key]
    if (typeof value !== 'string' || (nonEmpty && value.trim() === '')) problems.push(`${key}_invalid`)
  }
  const requireNonNegativeInteger = (key) => {
    const value = state[key]
    if (!Number.isInteger(value) || value < 0) problems.push(`${key}_invalid`)
  }
  const requirePositiveInteger = (value, code) => {
    if (!Number.isInteger(value) || value < 1) problems.push(code)
  }

  requireString('stateContextId', { nonEmpty: true })
  if (typeof state.stateRevision !== 'string' || !/^[1-9]\d*$/.test(state.stateRevision)) {
    problems.push('stateRevision_invalid')
  }
  if (typeof state.storeName !== 'string') problems.push('storeName_invalid')
  requireNonNegativeInteger('pendingHangCount')
  ;[
    'currentStore',
    'featurePermissions',
    'workspace',
    'operator',
    'cashier',
    'serviceCompletion',
    'writeoff',
    'room',
    'reservation',
    'memberCenter',
    'memberSelector',
    'queryEntitySelector',
    'managementCenter',
    'businessDashboard',
    'hangOrders',
    'orderCenter'
  ].forEach(requireObject)
  if (Object.prototype.hasOwnProperty.call(state, 'members')) problems.push('forbidden_key:members')

  const workspace = state.workspace
  if (workspace && typeof workspace === 'object' && !Array.isArray(workspace)) {
    if (typeof workspace.id !== 'string' || !workspace.id.startsWith('ws:')) problems.push('workspace_id_not_canonical')
    requirePositiveInteger(workspace.revision, 'workspace_revision_not_positive')
  }
  const validatePage = (page, code) => {
    if (!page || typeof page !== 'object' || Array.isArray(page)) {
      problems.push(`${code}_not_object`)
      return
    }
    if (!Array.isArray(page.records)) problems.push(`${code}_records_not_array`)
    if (!Number.isInteger(page.total) || page.total < 0) problems.push(`${code}_total_invalid`)
    requirePositiveInteger(page.page, `${code}_page_invalid`)
    requirePositiveInteger(page.pageSize, `${code}_pageSize_invalid`)
    if (typeof page.isLoading !== 'boolean') problems.push(`${code}_isLoading_invalid`)
  }
  validatePage(state.memberSelector, 'memberSelector')
  const entitySelector = state.queryEntitySelector
  if (entitySelector && typeof entitySelector === 'object' && !Array.isArray(entitySelector)) {
    ;['person', 'store', 'organization'].forEach((key) => validatePage(entitySelector[key], `queryEntitySelector_${key}`))
  }

  // 根分区允许业务模块逐步扩展未知字段，但已约定的高频集合字段必须保持
  // 数组／对象类型；否则 normalizeBootstrap 会把畸形值静默归一化，旧页面仍可能
  // 以“成功”继续导航，形成旧根与新业务结果混用。
  const isRecord = (value) => Boolean(value && typeof value === 'object' && !Array.isArray(value))
  const checkArrayField = (owner, key, code) => {
    if (owner && Object.prototype.hasOwnProperty.call(owner, key) && !Array.isArray(owner[key])) {
      problems.push(`${code}_not_array`)
    }
  }
  const checkObjectField = (owner, key, code) => {
    if (owner && Object.prototype.hasOwnProperty.call(owner, key)
      && !isRecord(owner[key]) && owner[key] !== null) {
      problems.push(`${code}_not_object`)
    }
  }
  const checkStringField = (owner, key, code, { allowNull = false } = {}) => {
    if (!owner || !Object.prototype.hasOwnProperty.call(owner, key)) return
    if (allowNull && owner[key] === null) return
    if (typeof owner[key] !== 'string') problems.push(`${code}_not_string`)
  }
  const checkIntegerField = (owner, key, code, { positive = false } = {}) => {
    if (!owner || !Object.prototype.hasOwnProperty.call(owner, key)) return
    const value = owner[key]
    if (!Number.isInteger(value) || (positive ? value < 1 : value < 0)) problems.push(`${code}_invalid`)
  }
  const checkBooleanField = (owner, key, code, { allowNull = false } = {}) => {
    if (!owner || !Object.prototype.hasOwnProperty.call(owner, key)) return
    if (allowNull && owner[key] === null) return
    if (typeof owner[key] !== 'boolean') problems.push(`${code}_invalid`)
  }

  checkStringField(state.currentStore, 'name', 'currentStore_name')
  if (isRecord(state.featurePermissions)) {
    Object.entries(state.featurePermissions).forEach(([key, value]) => {
      if (typeof value !== 'boolean') problems.push(`featurePermissions_${key}_invalid`)
    })
  }
  checkStringField(state.workspace, 'status', 'workspace_status')
  checkStringField(state.workspace, 'serverTime', 'workspace_serverTime', { allowNull: true })
  checkStringField(state.operator, 'name', 'operator_name')
  checkStringField(state.operator, 'roleName', 'operator_roleName')
  const cashier = state.cashier
  if (isRecord(cashier)) {
    const hasCustomerMode = Object.prototype.hasOwnProperty.call(cashier, 'customerMode')
    const effectiveCustomerMode = hasCustomerMode
      ? cashier.customerMode
      : isRecord(cashier.member)
        ? 'member'
        : 'unselected'
    // 旧完整根没有 customerMode 时只能安全推断为「已选会员」或
    // 「未选择」；只有后端明确返回 guest 才能开启游客结账。
    if (!['unselected', 'member', 'guest'].includes(effectiveCustomerMode)) {
      problems.push('cashier_customerMode_invalid')
    }
    checkObjectField(cashier, 'member', 'cashier_member')
    if (effectiveCustomerMode === 'member' && !isRecord(cashier.member)) {
      problems.push('cashier_member_required')
    }
    if (effectiveCustomerMode !== 'member' && cashier.member != null) {
      problems.push('cashier_member_mode_mismatch')
    }
    checkObjectField(cashier, 'catalog', 'cashier_catalog')
    checkObjectField(cashier, 'cart', 'cashier_cart')
    checkObjectField(cashier, 'checkout', 'cashier_checkout')
    checkObjectField(cashier, 'serviceOrder', 'cashier_serviceOrder')
    if (isRecord(cashier.catalog)) {
      checkArrayField(cashier.catalog, 'types', 'cashier_catalog_types')
      checkArrayField(cashier.catalog, 'categories', 'cashier_catalog_categories')
      checkArrayField(cashier.catalog, 'items', 'cashier_catalog_items')
    }
    if (isRecord(cashier.cart)) {
      checkArrayField(cashier.cart, 'lines', 'cashier_cart_lines')
      checkObjectField(cashier.cart, 'summary', 'cashier_cart_summary')
      if (Array.isArray(cashier.cart.lines)) {
        cashier.cart.lines.forEach((line, index) => {
          if (!isRecord(line)) {
            problems.push(`cashier_cart_line_${index}_not_object`)
            return
          }
          if (!['sale', 'entitlement_service'].includes(line.lineRole)) {
            problems.push(`cashier_cart_line_${index}_role_invalid`)
          }
          if (!line.id || !line.name || !Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1) {
            problems.push(`cashier_cart_line_${index}_identity_invalid`)
          }
          if (line.lineRole === 'entitlement_service') {
            const sourceVersion = Number(line.entitlementSourceVersion ?? line.sourceVersion)
            const projectVersion = Number(line.projectVersion)
            if (!line.entitlementInstanceId || !line.entitlementSourceDetailId) {
              problems.push(`cashier_cart_line_${index}_entitlement_source_missing`)
            }
            if (!Number.isInteger(sourceVersion) || sourceVersion < 1) {
              problems.push(`cashier_cart_line_${index}_entitlement_version_invalid`)
            }
            if (!Number.isInteger(projectVersion) || projectVersion < 1) {
              problems.push(`cashier_cart_line_${index}_project_version_invalid`)
            }
          }
        })
      }
    }
    checkObjectField(cashier, 'entitlementSelector', 'cashier_entitlementSelector')
    checkObjectField(cashier, 'checkoutComposition', 'cashier_checkoutComposition')
    if (isRecord(cashier.checkoutComposition)) {
      checkArrayField(cashier.checkoutComposition, 'lineRoles', 'cashier_checkoutComposition_lineRoles')
      const action = cashier.checkoutComposition.primaryAction
      if (action !== undefined && !['', 'collect_payment', 'complete_service', 'collect_and_complete'].includes(action)) {
        problems.push('cashier_checkoutComposition_primaryAction_invalid')
      }
      if (isRecord(cashier.cart)
        && Array.isArray(cashier.cart.lines)
        && !isCompleteCheckoutCompositionContract(cashier.checkoutComposition, cashier.cart.lines)) {
        problems.push('cashier_checkoutComposition_contract_mismatch')
      }
    }
    if (isRecord(cashier.entitlementSelector)) {
      const selector = cashier.entitlementSelector
      if (typeof selector.ready !== 'boolean') problems.push('cashier_entitlementSelector_ready_invalid')
      checkArrayField(selector, 'sources', 'cashier_entitlementSelector_sources')
      checkArrayField(selector, 'commandContexts', 'cashier_entitlementSelector_commandContexts')
      if (selector.ready === true) {
        const selectorMember = isRecord(selector.member) ? selector.member : null
        if (!selector.selectorRequestId || !selector.selectorToken || !selectorMember || !(selectorMember.id || selectorMember.memberId)) {
          problems.push('cashier_entitlementSelector_identity_incomplete')
        }
        ;(Array.isArray(selector.sources) ? selector.sources : []).forEach((source, sourceIndex) => {
          if (!isRecord(source)) {
            problems.push(`cashier_entitlementSelector_source_${sourceIndex}_not_object`)
            return
          }
          const sourceId = source.entitlementInstanceId || source.id
          const sourceVersion = Number(source.version ?? source.revision)
          if (!sourceId || !Number.isInteger(sourceVersion) || sourceVersion < 1) {
            problems.push(`cashier_entitlementSelector_source_${sourceIndex}_identity_invalid`)
          }
          if (!Array.isArray(source.projects)) {
            problems.push(`cashier_entitlementSelector_source_${sourceIndex}_projects_not_array`)
            return
          }
          source.projects.forEach((project, projectIndex) => {
            const prefix = `cashier_entitlementSelector_source_${sourceIndex}_project_${projectIndex}`
            if (!isRecord(project)) {
              problems.push(`${prefix}_not_object`)
              return
            }
            const projectId = project.projectId || project.id
            const detailId = project.entitlementSourceDetailId || project.sourceDetailId
            const projectVersion = Number(project.version ?? project.revision)
            if (!projectId || !detailId || !Number.isInteger(projectVersion) || projectVersion < 1) {
              problems.push(`${prefix}_identity_invalid`)
            }
            ;['remainingTimes', 'occupiedTimes', 'availableTimes'].forEach((key) => {
              const value = Number(project[key] ?? (key === 'occupiedTimes' ? project.reservedTimes : NaN))
              if (!Number.isFinite(value) || value < 0) problems.push(`${prefix}_${key}_invalid`)
            })
          })
        })
      }
    }
  }

  const serviceCompletion = state.serviceCompletion
  if (isRecord(serviceCompletion)) {
    checkArrayField(serviceCompletion, 'lines', 'serviceCompletion_lines')
    checkArrayField(serviceCompletion, 'commandContexts', 'serviceCompletion_commandContexts')
    checkObjectField(serviceCompletion, 'serviceOrder', 'serviceCompletion_serviceOrder')
  }

  const writeoff = state.writeoff
  if (isRecord(writeoff)) {
    checkArrayField(writeoff, 'sources', 'writeoff_sources')
    checkObjectField(writeoff, 'summary', 'writeoff_summary')
    checkObjectField(writeoff, 'activeServiceSession', 'writeoff_activeServiceSession')
    checkObjectField(writeoff, 'pendingSubmittedRequest', 'writeoff_pendingSubmittedRequest')
    checkObjectField(writeoff, 'confirmation', 'writeoff_confirmation')
  }

  const room = state.room
  if (isRecord(room)) {
    checkStringField(room, 'refreshedAt', 'room_refreshedAt', { allowNull: true })
    checkStringField(room, 'staleMessage', 'room_staleMessage')
    checkIntegerField(room, 'pendingAssignmentCount', 'room_pendingAssignmentCount')
    checkArrayField(room, 'pendingAssignments', 'room_pendingAssignments')
    checkArrayField(room, 'categories', 'room_categories')
    checkObjectField(room, 'unassignedList', 'room_unassignedList')
    checkObjectField(room, 'detail', 'room_detail')
    checkObjectField(room, 'assignment', 'room_assignment')
    if (isRecord(room.unassignedList)) checkArrayField(room.unassignedList, 'records', 'room_unassignedList_records')
  }

  const reservation = state.reservation
  if (isRecord(reservation)) {
    checkIntegerField(reservation, 'total', 'reservation_total')
    checkIntegerField(reservation, 'page', 'reservation_page', { positive: true })
    checkIntegerField(reservation, 'pageSize', 'reservation_pageSize', { positive: true })
    checkObjectField(reservation, 'quickCounts', 'reservation_quickCounts')
    checkArrayField(reservation, 'records', 'reservation_records')
    checkObjectField(reservation, 'detail', 'reservation_detail')
    checkObjectField(reservation, 'editor', 'reservation_editor')
    checkObjectField(reservation, 'calendar', 'reservation_calendar')
    if (isRecord(reservation.editor)) {
      checkArrayField(reservation.editor, 'catalogOptions', 'reservation_editor_catalogOptions')
      checkArrayField(reservation.editor, 'craftsmenOptions', 'reservation_editor_craftsmenOptions')
      checkArrayField(reservation.editor, 'rooms', 'reservation_editor_rooms')
    }
    if (isRecord(reservation.calendar)) {
      checkArrayField(reservation.calendar, 'resources', 'reservation_calendar_resources')
      checkArrayField(reservation.calendar, 'blocks', 'reservation_calendar_blocks')
    }
  }

  const memberCenter = state.memberCenter
  if (isRecord(memberCenter)) {
    checkBooleanField(memberCenter, 'canBatchOperate', 'memberCenter_canBatchOperate')
    checkIntegerField(memberCenter, 'total', 'memberCenter_total')
    checkIntegerField(memberCenter, 'page', 'memberCenter_page', { positive: true })
    checkIntegerField(memberCenter, 'pageSize', 'memberCenter_pageSize', { positive: true })
    checkArrayField(memberCenter, 'statusOptions', 'memberCenter_statusOptions')
    checkArrayField(memberCenter, 'records', 'memberCenter_records')
    checkObjectField(memberCenter, 'detail', 'memberCenter_detail')
  }

  const managementCenter = state.managementCenter
  if (isRecord(managementCenter)) checkArrayField(managementCenter, 'entries', 'managementCenter_entries')

  const dashboard = state.businessDashboard
  if (isRecord(dashboard)) {
    checkStringField(dashboard, 'mode', 'businessDashboard_mode')
    checkStringField(dashboard, 'selectedMetricCode', 'businessDashboard_selectedMetricCode')
    checkStringField(dashboard, 'metricVersion', 'businessDashboard_metricVersion')
    checkStringField(dashboard, 'dataAsOf', 'businessDashboard_dataAsOf')
    checkStringField(dashboard, 'coverageStart', 'businessDashboard_coverageStart')
    checkBooleanField(dashboard, 'aggregationCaughtUp', 'businessDashboard_aggregationCaughtUp', { allowNull: true })
    checkObjectField(dashboard, 'scope', 'businessDashboard_scope')
    checkArrayField(dashboard, 'cards', 'businessDashboard_cards')
    checkObjectField(dashboard, 'trend', 'businessDashboard_trend')
    checkObjectField(dashboard, 'ranking', 'businessDashboard_ranking')
    if (isRecord(dashboard.trend)) checkArrayField(dashboard.trend, 'points', 'businessDashboard_trend_points')
    if (isRecord(dashboard.ranking)) checkArrayField(dashboard.ranking, 'columns', 'businessDashboard_ranking_columns')
    if (isRecord(dashboard.ranking)) checkArrayField(dashboard.ranking, 'records', 'businessDashboard_ranking_records')
  }

  const hangOrders = state.hangOrders
  if (isRecord(hangOrders)) {
    checkIntegerField(hangOrders, 'total', 'hangOrders_total')
    checkIntegerField(hangOrders, 'page', 'hangOrders_page', { positive: true })
    checkIntegerField(hangOrders, 'pageSize', 'hangOrders_pageSize', { positive: true })
    checkArrayField(hangOrders, 'statusOptions', 'hangOrders_statusOptions')
    checkArrayField(hangOrders, 'records', 'hangOrders_records')
  }

  const orderCenter = state.orderCenter
  if (isRecord(orderCenter)) {
    checkIntegerField(orderCenter, 'total', 'orderCenter_total')
    checkIntegerField(orderCenter, 'page', 'orderCenter_page', { positive: true })
    checkIntegerField(orderCenter, 'pageSize', 'orderCenter_pageSize', { positive: true })
    checkArrayField(orderCenter, 'businessTypes', 'orderCenter_businessTypes')
    checkArrayField(orderCenter, 'statusOptions', 'orderCenter_statusOptions')
    checkArrayField(orderCenter, 'salesOrders', 'orderCenter_salesOrders')
    checkArrayField(orderCenter, 'rechargeOrders', 'orderCenter_rechargeOrders')
    checkArrayField(orderCenter, 'supplementOrders', 'orderCenter_supplementOrders')
    checkArrayField(orderCenter, 'refundOrders', 'orderCenter_refundOrders')
    checkArrayField(orderCenter, 'debtRecords', 'orderCenter_debtRecords')
    checkArrayField(orderCenter, 'serviceRecords', 'orderCenter_serviceRecords')
    checkArrayField(orderCenter, 'giftRecords', 'orderCenter_giftRecords')
    checkArrayField(orderCenter, 'projectReplacementRecords', 'orderCenter_projectReplacementRecords')
    checkArrayField(orderCenter, 'cardUpgradeRecords', 'orderCenter_cardUpgradeRecords')
    checkArrayField(orderCenter, 'projectUpgradeRecords', 'orderCenter_projectUpgradeRecords')
    checkArrayField(orderCenter, 'cardOperationRecords', 'orderCenter_cardOperationRecords')
    checkObjectField(orderCenter, 'countsByType', 'orderCenter_countsByType')
    checkObjectField(orderCenter, 'recordsByType', 'orderCenter_recordsByType')
    checkObjectField(orderCenter, 'pagesByType', 'orderCenter_pagesByType')
    checkObjectField(orderCenter, 'querySettingsByType', 'orderCenter_querySettingsByType')
    checkObjectField(orderCenter, 'salesOrderDetail', 'orderCenter_salesOrderDetail')
  }

  if (problems.length) {
    return {
      valid: false,
      code: 'STATE_SCHEMA_INVALID',
      message: '后端完整状态结构不符合约定，已拒绝覆盖当前页面。',
      missingKeys: [...missingKeys],
      problems
    }
  }
  return { valid: true, missingKeys: [], problems: [] }
}

function toNonNegativeInteger(value, fallback = 0) {
  const normalized = Number(value)
  return Number.isInteger(normalized) && normalized >= 0 ? normalized : fallback
}

function canonicalStateRevision(value) {
  if (typeof value === 'bigint') return value >= 0n ? value.toString() : ''
  if (typeof value === 'number') {
    return Number.isSafeInteger(value) && value >= 0 ? String(value) : ''
  }
  const text = String(value ?? '').trim()
  if (!/^\d+$/.test(text)) return ''
  return text.replace(/^0+(?=\d)/, '')
}

function stateRevisionOf(state = {}) {
  return canonicalStateRevision(
    state?.stateRevision
    ?? state?.state_revision
    ?? state?.responseSequence
    ?? state?.response_sequence
  )
}

function stateContextIdOf(state = {}) {
  const value = state?.stateContextId
    ?? state?.state_context_id
    ?? state?.projectionContextId
    ?? state?.projection_context_id
  return typeof value === 'string' ? value.trim() : ''
}

function compareStateRevision(left, right) {
  const a = canonicalStateRevision(left)
  const b = canonicalStateRevision(right)
  if (!a || !b) return null
  if (a.length !== b.length) return a.length > b.length ? 1 : -1
  if (a === b) return 0
  return a > b ? 1 : -1
}

function normalizeQueryEntityPage(rawPage = {}) {
  const source = rawPage && typeof rawPage === 'object' ? rawPage : {}
  return {
    ...EMPTY_QUERY_ENTITY_PAGE,
    ...source,
    records: Array.isArray(source.records) ? source.records : [],
    total: toNonNegativeInteger(source.total),
    page: Math.max(1, toNonNegativeInteger(source.page, 1)),
    pageSize: Math.max(1, toNonNegativeInteger(source.pageSize, 20)),
    isLoading: Boolean(source.isLoading)
  }
}

function normalizeBootstrap(rawBootstrap = {}) {
  const raw = rawBootstrap && typeof rawBootstrap === 'object' ? rawBootstrap : {}
  const base = clone(EMPTY_BOOTSTRAP)
  const incomingCashier = raw.cashier && typeof raw.cashier === 'object' ? raw.cashier : {}
  const incomingCashierMember = incomingCashier.member && typeof incomingCashier.member === 'object'
    ? incomingCashier.member
    : null
  const incomingCustomerMode = ['unselected', 'member', 'guest'].includes(incomingCashier.customerMode)
    ? incomingCashier.customerMode
    : incomingCashierMember
      ? 'member'
      : 'unselected'
  const rawServiceCompletion = raw.serviceCompletion || raw.service_completion
  const incomingServiceCompletion = rawServiceCompletion && typeof rawServiceCompletion === 'object'
    ? rawServiceCompletion
    : {}
  const incomingServiceCompletionOrder = incomingServiceCompletion.serviceOrder || incomingServiceCompletion.service_order
  const incomingServiceCompletionLines = incomingServiceCompletion.lines
    || incomingServiceCompletion.serviceLines
    || incomingServiceCompletion.service_lines
  const incomingServiceCompletionContexts = incomingServiceCompletion.commandContexts || incomingServiceCompletion.command_contexts
  const incomingCatalog = incomingCashier.catalog && typeof incomingCashier.catalog === 'object'
    ? incomingCashier.catalog
    : {}
  const incomingCart = incomingCashier.cart && typeof incomingCashier.cart === 'object'
    ? incomingCashier.cart
    : {}
  const incomingEntitlementSelector = incomingCashier.entitlementSelector && typeof incomingCashier.entitlementSelector === 'object'
    ? incomingCashier.entitlementSelector
    : null
  const incomingWorkspace = raw.workspace && typeof raw.workspace === 'object'
    ? raw.workspace
    : {}
  const incomingCurrentStore = raw.currentStore && typeof raw.currentStore === 'object'
    ? raw.currentStore
    : {}
  const incomingWriteoff = raw.writeoff && typeof raw.writeoff === 'object'
    ? raw.writeoff
    : {}
  const incomingRoom = raw.room && typeof raw.room === 'object'
    ? raw.room
    : {}
  const incomingRoomUnassignedList = incomingRoom.unassignedList && typeof incomingRoom.unassignedList === 'object'
    ? incomingRoom.unassignedList
    : {}
  const incomingRoomPendingAssignments = Array.isArray(incomingRoom.pendingAssignments)
    ? incomingRoom.pendingAssignments
    : []
  const incomingRoomUnassignedRecords = Array.isArray(incomingRoomUnassignedList.records)
    ? incomingRoomUnassignedList.records
    : incomingRoomPendingAssignments
  const incomingReservation = raw.reservation && typeof raw.reservation === 'object'
    ? raw.reservation
    : {}
  const incomingMemberCenter = raw.memberCenter && typeof raw.memberCenter === 'object'
    ? raw.memberCenter
    : {}
  const incomingMemberSelector = raw.memberSelector && typeof raw.memberSelector === 'object'
    ? raw.memberSelector
    : {}
  const incomingQueryEntitySelector = raw.queryEntitySelector && typeof raw.queryEntitySelector === 'object'
    ? raw.queryEntitySelector
    : {}
  const incomingManagementCenter = raw.managementCenter && typeof raw.managementCenter === 'object'
    ? raw.managementCenter
    : {}
  const incomingBusinessDashboard = raw.businessDashboard && typeof raw.businessDashboard === 'object'
    ? raw.businessDashboard
    : {}
  const incomingDashboardScope = incomingBusinessDashboard.scope && typeof incomingBusinessDashboard.scope === 'object'
    ? incomingBusinessDashboard.scope
    : {}
  const incomingDashboardDateRange = incomingDashboardScope.dateRange && typeof incomingDashboardScope.dateRange === 'object'
    ? incomingDashboardScope.dateRange
    : {}
  const incomingDashboardTrend = incomingBusinessDashboard.trend && typeof incomingBusinessDashboard.trend === 'object'
    ? incomingBusinessDashboard.trend
    : {}
  const incomingDashboardRanking = incomingBusinessDashboard.ranking && typeof incomingBusinessDashboard.ranking === 'object'
    ? incomingBusinessDashboard.ranking
    : {}
  const incomingHangOrders = raw.hangOrders && typeof raw.hangOrders === 'object'
    ? raw.hangOrders
    : {}
  const incomingOrderCenter = raw.orderCenter && typeof raw.orderCenter === 'object'
    ? raw.orderCenter
    : {}
  const incomingFeaturePermissions = raw.featurePermissions && typeof raw.featurePermissions === 'object'
    ? raw.featurePermissions
    : {}

  return {
    ...base,
    ...raw,
    stateContextId: stateContextIdOf(raw) || base.stateContextId,
    stateRevision: stateRevisionOf(raw) || base.stateRevision,
    storeName: typeof raw.storeName === 'string' && raw.storeName.trim() ? raw.storeName : base.storeName,
    currentStore: {
      ...base.currentStore,
      ...incomingCurrentStore,
      id: incomingCurrentStore.id || null,
      name: incomingCurrentStore.name || incomingCurrentStore.storeName || raw.storeName || base.currentStore.name
    },
    featurePermissions: {
      ...base.featurePermissions,
      ...incomingFeaturePermissions
    },
    workspace: {
      ...base.workspace,
      ...incomingWorkspace,
      id: typeof incomingWorkspace.id === 'string' && incomingWorkspace.id ? incomingWorkspace.id : null,
      revision: toNonNegativeInteger(incomingWorkspace.revision)
    },
    operator: {
      ...base.operator,
      ...(raw.operator && typeof raw.operator === 'object' ? raw.operator : {})
    },
    pendingHangCount: toNonNegativeInteger(raw.pendingHangCount),
    cashier: {
      ...base.cashier,
      ...incomingCashier,
      customerMode: incomingCustomerMode,
      member: incomingCustomerMode === 'member' ? incomingCashierMember : null,
      catalog: {
        ...base.cashier.catalog,
        ...incomingCatalog,
        types: Array.isArray(incomingCatalog.types) ? incomingCatalog.types : [],
        categories: Array.isArray(incomingCatalog.categories) ? incomingCatalog.categories : [],
        items: Array.isArray(incomingCatalog.items) ? incomingCatalog.items : []
      },
      cart: {
        ...base.cashier.cart,
        ...incomingCart,
        lines: Array.isArray(incomingCart.lines) ? incomingCart.lines : [],
        summary: {
          ...EMPTY_SUMMARY,
          ...(incomingCart.summary && typeof incomingCart.summary === 'object' ? incomingCart.summary : {})
        }
      },
      entitlementSelector: incomingEntitlementSelector
        ? {
            ...incomingEntitlementSelector,
            sources: Array.isArray(incomingEntitlementSelector.sources) ? incomingEntitlementSelector.sources : [],
            commandContexts: Array.isArray(incomingEntitlementSelector.commandContexts)
              ? incomingEntitlementSelector.commandContexts
              : []
          }
        : null
    },
    serviceCompletion: {
      ...base.serviceCompletion,
      ...incomingServiceCompletion,
      serviceOrder: incomingServiceCompletionOrder && typeof incomingServiceCompletionOrder === 'object'
        ? incomingServiceCompletionOrder
        : null,
      lines: Array.isArray(incomingServiceCompletionLines) ? incomingServiceCompletionLines : [],
      commandContexts: Array.isArray(incomingServiceCompletionContexts) ? incomingServiceCompletionContexts : []
    },
    writeoff: {
      ...base.writeoff,
      ...incomingWriteoff,
      sources: Array.isArray(incomingWriteoff.sources) ? incomingWriteoff.sources : [],
      confirmation: incomingWriteoff.confirmation && typeof incomingWriteoff.confirmation === 'object'
        ? incomingWriteoff.confirmation
        : null,
      summary: {
        ...base.writeoff.summary,
        ...(incomingWriteoff.summary && typeof incomingWriteoff.summary === 'object' ? incomingWriteoff.summary : {})
      }
    },
    room: {
      ...base.room,
      ...incomingRoom,
      pendingAssignmentCount: toNonNegativeInteger(
        incomingRoom.pendingAssignmentCount ?? incomingRoomUnassignedList.total,
        incomingRoomUnassignedRecords.length
      ),
      pendingAssignments: incomingRoomPendingAssignments,
      unassignedList: {
        ...base.room.unassignedList,
        ...incomingRoomUnassignedList,
        records: incomingRoomUnassignedRecords,
        total: toNonNegativeInteger(incomingRoomUnassignedList.total, incomingRoomUnassignedRecords.length),
        page: Math.max(1, toNonNegativeInteger(incomingRoomUnassignedList.page, 1)),
        pageSize: Math.max(1, toNonNegativeInteger(incomingRoomUnassignedList.pageSize, 20)),
        refreshedAt: incomingRoomUnassignedList.refreshedAt || incomingRoom.refreshedAt || '',
        staleMessage: incomingRoomUnassignedList.staleMessage || incomingRoom.staleMessage || ''
      },
      categories: Array.isArray(incomingRoom.categories) ? incomingRoom.categories : [],
      detail: incomingRoom.detail && typeof incomingRoom.detail === 'object'
        ? incomingRoom.detail
        : null,
      assignment: (incomingRoom.assignment || incomingRoom.roomAssignment) && typeof (incomingRoom.assignment || incomingRoom.roomAssignment) === 'object'
        ? (incomingRoom.assignment || incomingRoom.roomAssignment)
        : null
    },
    reservation: {
      ...base.reservation,
      ...incomingReservation,
      quickCounts: incomingReservation.quickCounts && typeof incomingReservation.quickCounts === 'object'
        ? incomingReservation.quickCounts
        : {},
      records: Array.isArray(incomingReservation.records) ? incomingReservation.records : [],
      total: toNonNegativeInteger(incomingReservation.total, Array.isArray(incomingReservation.records) ? incomingReservation.records.length : 0),
      page: Math.max(1, toNonNegativeInteger(incomingReservation.page, 1)),
      pageSize: Math.max(1, toNonNegativeInteger(incomingReservation.pageSize, 20)),
      detail: incomingReservation.detail && typeof incomingReservation.detail === 'object'
        ? incomingReservation.detail
        : null,
      editor: {
        ...base.reservation.editor,
        ...(incomingReservation.editor && typeof incomingReservation.editor === 'object' ? incomingReservation.editor : {}),
        draft: incomingReservation.editor?.draft && typeof incomingReservation.editor.draft === 'object'
          ? incomingReservation.editor.draft
          : {},
        catalogOptions: Array.isArray(incomingReservation.editor?.catalogOptions) ? incomingReservation.editor.catalogOptions : [],
        craftsmenOptions: Array.isArray(incomingReservation.editor?.craftsmenOptions) ? incomingReservation.editor.craftsmenOptions : [],
        rooms: Array.isArray(incomingReservation.editor?.rooms) ? incomingReservation.editor.rooms : []
      },
      calendar: {
        ...base.reservation.calendar,
        ...(incomingReservation.calendar && typeof incomingReservation.calendar === 'object' ? incomingReservation.calendar : {}),
        resources: Array.isArray(incomingReservation.calendar?.resources) ? incomingReservation.calendar.resources : [],
        blocks: Array.isArray(incomingReservation.calendar?.blocks) ? incomingReservation.calendar.blocks : []
      }
    },
    memberCenter: {
      ...base.memberCenter,
      ...incomingMemberCenter,
      canBatchOperate: Boolean(incomingMemberCenter.canBatchOperate),
      statusOptions: Array.isArray(incomingMemberCenter.statusOptions) ? incomingMemberCenter.statusOptions : [],
      records: Array.isArray(incomingMemberCenter.records) ? incomingMemberCenter.records : [],
      total: toNonNegativeInteger(incomingMemberCenter.total, Array.isArray(incomingMemberCenter.records) ? incomingMemberCenter.records.length : 0),
      page: Math.max(1, toNonNegativeInteger(incomingMemberCenter.page, 1)),
      pageSize: Math.max(1, toNonNegativeInteger(incomingMemberCenter.pageSize, 20)),
      detail: incomingMemberCenter.detail && typeof incomingMemberCenter.detail === 'object'
        ? incomingMemberCenter.detail
        : null
    },
    memberSelector: {
      ...base.memberSelector,
      ...incomingMemberSelector,
      records: Array.isArray(incomingMemberSelector.records) ? incomingMemberSelector.records : [],
      total: toNonNegativeInteger(incomingMemberSelector.total),
      page: Math.max(1, toNonNegativeInteger(incomingMemberSelector.page, 1)),
      pageSize: Math.max(1, toNonNegativeInteger(incomingMemberSelector.pageSize, 20)),
      isLoading: Boolean(incomingMemberSelector.isLoading)
    },
    queryEntitySelector: {
      person: normalizeQueryEntityPage(incomingQueryEntitySelector.person),
      store: normalizeQueryEntityPage(incomingQueryEntitySelector.store),
      organization: normalizeQueryEntityPage(incomingQueryEntitySelector.organization)
    },
    managementCenter: {
      ...base.managementCenter,
      ...incomingManagementCenter,
      entries: Array.isArray(incomingManagementCenter.entries) ? incomingManagementCenter.entries : []
    },
    businessDashboard: {
      ...base.businessDashboard,
      ...incomingBusinessDashboard,
      mode: incomingBusinessDashboard.mode === 'platform' ? 'platform' : 'store',
      scope: {
        ...base.businessDashboard.scope,
        ...incomingDashboardScope,
        dateRange: {
          ...base.businessDashboard.scope.dateRange,
          ...incomingDashboardDateRange
        }
      },
      cards: Array.isArray(incomingBusinessDashboard.cards) ? incomingBusinessDashboard.cards : [],
      selectedMetricCode: incomingBusinessDashboard.selectedMetricCode || incomingBusinessDashboard.selected_metric_code || base.businessDashboard.selectedMetricCode,
      trend: {
        ...base.businessDashboard.trend,
        ...incomingDashboardTrend,
        points: Array.isArray(incomingDashboardTrend.points) ? incomingDashboardTrend.points : [],
        isLoading: Boolean(incomingDashboardTrend.isLoading)
      },
      ranking: {
        ...base.businessDashboard.ranking,
        ...incomingDashboardRanking,
        sortOptions: Array.isArray(incomingDashboardRanking.sortOptions) ? incomingDashboardRanking.sortOptions : [],
        columns: Array.isArray(incomingDashboardRanking.columns) ? incomingDashboardRanking.columns : [],
        records: Array.isArray(incomingDashboardRanking.records) ? incomingDashboardRanking.records : [],
        isLoading: Boolean(incomingDashboardRanking.isLoading)
      },
      metricVersion: incomingBusinessDashboard.metricVersion || incomingBusinessDashboard.metric_version || '',
      dataAsOf: incomingBusinessDashboard.dataAsOf || incomingBusinessDashboard.data_as_of || '',
      aggregationCaughtUp: typeof incomingBusinessDashboard.aggregationCaughtUp === 'boolean'
        ? incomingBusinessDashboard.aggregationCaughtUp
        : typeof incomingBusinessDashboard.aggregation_caught_up === 'boolean'
          ? incomingBusinessDashboard.aggregation_caught_up
          : null,
      coverageStart: incomingBusinessDashboard.coverageStart || incomingBusinessDashboard.coverage_start || ''
    },
    hangOrders: {
      ...base.hangOrders,
      ...incomingHangOrders,
      statusOptions: Array.isArray(incomingHangOrders.statusOptions) ? incomingHangOrders.statusOptions : [],
      records: Array.isArray(incomingHangOrders.records) ? incomingHangOrders.records : [],
      total: toNonNegativeInteger(incomingHangOrders.total, Array.isArray(incomingHangOrders.records) ? incomingHangOrders.records.length : 0),
      page: Math.max(1, toNonNegativeInteger(incomingHangOrders.page, 1)),
      pageSize: Math.max(1, toNonNegativeInteger(incomingHangOrders.pageSize, 20))
    },
    orderCenter: {
      ...base.orderCenter,
      ...incomingOrderCenter,
      businessTypes: Array.isArray(incomingOrderCenter.businessTypes) ? incomingOrderCenter.businessTypes : [],
      statusOptions: Array.isArray(incomingOrderCenter.statusOptions) ? incomingOrderCenter.statusOptions : [],
      statusOptionsByType: incomingOrderCenter.statusOptionsByType && typeof incomingOrderCenter.statusOptionsByType === 'object'
        ? incomingOrderCenter.statusOptionsByType
        : {},
      salesOrders: Array.isArray(incomingOrderCenter.salesOrders) ? incomingOrderCenter.salesOrders : [],
      rechargeOrders: Array.isArray(incomingOrderCenter.rechargeOrders) ? incomingOrderCenter.rechargeOrders : [],
      supplementOrders: Array.isArray(incomingOrderCenter.supplementOrders) ? incomingOrderCenter.supplementOrders : [],
      refundOrders: Array.isArray(incomingOrderCenter.refundOrders) ? incomingOrderCenter.refundOrders : [],
      debtRecords: Array.isArray(incomingOrderCenter.debtRecords) ? incomingOrderCenter.debtRecords : [],
      serviceRecords: Array.isArray(incomingOrderCenter.serviceRecords) ? incomingOrderCenter.serviceRecords : [],
      giftRecords: Array.isArray(incomingOrderCenter.giftRecords) ? incomingOrderCenter.giftRecords : [],
      projectReplacementRecords: Array.isArray(incomingOrderCenter.projectReplacementRecords) ? incomingOrderCenter.projectReplacementRecords : [],
      cardUpgradeRecords: Array.isArray(incomingOrderCenter.cardUpgradeRecords) ? incomingOrderCenter.cardUpgradeRecords : [],
      projectUpgradeRecords: Array.isArray(incomingOrderCenter.projectUpgradeRecords) ? incomingOrderCenter.projectUpgradeRecords : [],
      cardOperationRecords: Array.isArray(incomingOrderCenter.cardOperationRecords) ? incomingOrderCenter.cardOperationRecords : [],
      countsByType: incomingOrderCenter.countsByType && typeof incomingOrderCenter.countsByType === 'object'
        ? incomingOrderCenter.countsByType
        : {},
      recordsByType: incomingOrderCenter.recordsByType && typeof incomingOrderCenter.recordsByType === 'object'
        ? incomingOrderCenter.recordsByType
        : {},
      pagesByType: incomingOrderCenter.pagesByType && typeof incomingOrderCenter.pagesByType === 'object'
        ? incomingOrderCenter.pagesByType
        : {},
      querySettingsByType: incomingOrderCenter.querySettingsByType && typeof incomingOrderCenter.querySettingsByType === 'object'
        ? incomingOrderCenter.querySettingsByType
        : {},
      total: toNonNegativeInteger(incomingOrderCenter.total, Array.isArray(incomingOrderCenter.salesOrders) ? incomingOrderCenter.salesOrders.length : 0),
      page: Math.max(1, toNonNegativeInteger(incomingOrderCenter.page, 1)),
      pageSize: Math.max(1, toNonNegativeInteger(incomingOrderCenter.pageSize, 20)),
      salesOrderDetail: incomingOrderCenter.salesOrderDetail && typeof incomingOrderCenter.salesOrderDetail === 'object'
        ? incomingOrderCenter.salesOrderDetail
        : null
    }
  }
}

function readInitialBootstrap() {
  const rawBootstrap = window.__CASHIER_V3_BOOTSTRAP__
  const previewMode = new URLSearchParams(window.location.search).get('preview')
  const isDevPreview = import.meta.env.DEV && Boolean(previewMode)

  if (!isDevPreview) {
    return normalizeBootstrap(rawBootstrap)
  }

  const previewBootstrap = clone(DEV_PREVIEW_BOOTSTRAP)
  // 开发预览也通过同一份服务确认快照驱动，避免让页面形成“回退当前购物车”的错误依赖。
  const previewServiceOrder = clone(previewBootstrap.cashier.serviceOrder)
  previewServiceOrder.completion = {
    ...(previewServiceOrder.completion || {}),
    confirmationReady: true,
    snapshotToken: 'preview-service-confirmation-v1'
  }
  const previewCartLines = new Map((previewBootstrap.cashier.cart.lines || []).map((line) => [line.id, line]))
  previewBootstrap.serviceCompletion = {
    source: 'cashier',
    preparationRequestId: 'preview-service-prepare-v1',
    commandContexts: [{ kind: 'service_order', id: previewServiceOrder.id, expectedVersion: previewServiceOrder.revision }],
    serviceOrder: previewServiceOrder,
    // 此处只作为前端视觉联调样例；结构与正式 V3 确认快照一致，不能改回读取购物车。
    lines: [
      {
        ...clone(previewCartLines.get('preview-line-1')),
        isServiceProject: true,
        serviceRole: '主项目',
        serviceSource: '本次购买',
        entitlementSource: '本次购买项目（待支付后正式完成）',
        completionStatus: '本次已完成',
        actualCompletedQuantity: 1,
        actualCraftsmen: [{ id: 'staff-1', name: '肖君鹏', isPrimary: true }],
        writeoffAmount: 0,
        consumptionPerformanceAmount: 0,
        laborPerformanceAmount: 0
      },
      {
        ...clone(previewCartLines.get('preview-line-3')),
        isServiceProject: true,
        serviceRole: '明细项目',
        serviceSource: '卡内项目',
        entitlementSource: { label: '年度护理卡 · K202607270001' },
        completionStatus: '本次已完成',
        actualCompletedQuantity: 1,
        actualCraftsmen: [{ id: 'staff-1', name: '肖君鹏', isPrimary: true }],
        writeoffAmount: 100,
        consumptionPerformanceAmount: 100,
        laborPerformanceAmount: 100
      }
    ]
  }
  if (['processing', 'pending', 'failed', 'success'].includes(previewMode)) {
    previewBootstrap.cashier.checkout.status = previewMode === 'success' ? 'succeeded' : previewMode
    previewBootstrap.cashier.checkout.requestNo = 'JZ202607270001'
    previewBootstrap.cashier.checkout.salesOrderNo = previewMode === 'success' ? 'XS202607270001' : undefined
    previewBootstrap.cashier.checkout.hasUnfinishedProject = previewMode === 'success'
    previewBootstrap.cashier.checkout.failureReason = previewMode === 'failed' ? '本次收款未成功，请核对后重新支付。' : undefined
  }

  return normalizeBootstrap(previewBootstrap)
}

export const cashierV3State = reactive(readInitialBootstrap())

export function useCashierV3State() {
  return cashierV3State
}

/**
 * 登录接口已在服务端完成员工任职、门店端入口和岗位规则三重校验。
 * 在首个工作台根投影回来前，路由只能使用这份登录响应决定可进入的首屏；
 * 仅接受已登记功能码，且不把客户端传入的未知码扩展成 UI 权限。
 */
export function applyCashierV3LoginFeatures(features = []) {
  const granted = new Set(Array.isArray(features)
    ? features.filter((feature) => typeof feature === 'string')
    : [])
  const known = Object.keys(EMPTY_BOOTSTRAP.featurePermissions)
  cashierV3State.featurePermissions = Object.fromEntries(known.map((feature) => [
    feature,
    granted.has(feature)
  ]))
}

/**
 * 打开统一查询使用的人员／门店／组织选择器。
 *
 * 选择器只帮助用户录入筛选值；最终查询仍由后端基于当前账号权限重新裁剪。
 * 因此这里不会把前端选择结果当成数据权限依据，也不会向业务主表写入任何数据。
 */
export function openCashierV3QueryEntitySelector(options = {}) {
  const entityType = options.entityType || options.field?.type
  if (!['person', 'store', 'organization'].includes(entityType) || typeof window === 'undefined') {
    return Promise.resolve(null)
  }

  const requestId = options.requestId || createCashierV3CommandId('QUERY_ENTITY')
  const scope = options.scope || options.selectionContext?.scope || 'query_filter'
  const selectorEntryByScope = {
    reservation_craftsmen: 'reservation',
    member_exclusive_service_staff: 'member'
  }
  const selectorEntry = options.selectorEntry || selectorEntryByScope[scope] || 'cashier'
  return new Promise((resolve) => {
    let settled = false
    const cleanup = () => {
      window.removeEventListener('cashier-v3:query-entity-selector-selected', handleSelected)
      window.removeEventListener('cashier-v3:query-entity-selector-closed', handleClosed)
    }
    const settle = (result) => {
      if (settled) return
      settled = true
      cleanup()
      resolve(result)
    }
    const handleSelected = (event) => {
      const detail = event.detail || {}
      if (detail.requestId !== requestId) return
      if (Array.isArray(detail.records)) {
        settle({ selected: detail.records })
        return
      }
      settle(detail.record ? { selected: detail.record } : null)
    }
    const handleClosed = (event) => {
      const detail = event.detail || {}
      if (detail.requestId !== requestId) return
      settle(null)
    }

    window.addEventListener('cashier-v3:query-entity-selector-selected', handleSelected)
    window.addEventListener('cashier-v3:query-entity-selector-closed', handleClosed)
    window.dispatchEvent(new CustomEvent('cashier-v3:open-query-entity-selector', {
      detail: {
        requestId,
        entityType,
        selectorEntry,
        title: options.title || '',
        multiple: options.multiple === true,
        selectedRecords: Array.isArray(options.selectedRecords) ? options.selectedRecords : [],
        selectionContext: {
          ...(options.selectionContext && typeof options.selectionContext === 'object' ? options.selectionContext : {}),
          scope,
          fieldKey: options.field?.key || options.fieldKey || '',
          currentValue: options.currentValue || null
        }
      }
    }))
  })
}

function inspectIncomingRootState(nextBootstrap, options = {}) {
  const allowContextSwitch = options.allowContextSwitch === true
  const incomingContextId = stateContextIdOf(nextBootstrap)
  const incomingRevision = stateRevisionOf(nextBootstrap)
  const currentContextId = stateContextIdOf(cashierV3State)
  const currentRevision = stateRevisionOf(cashierV3State)
  if (!incomingContextId) {
    return {
      accepted: false,
      applied: false,
      code: 'STATE_CONTEXT_MISSING',
      message: '后端完整状态缺少 stateContextId，已拒绝覆盖当前页面。'
    }
  }
  if (!incomingRevision) {
    return {
      accepted: false,
      applied: false,
      code: 'STATE_REVISION_MISSING',
      message: '后端完整状态缺少 stateRevision，已拒绝覆盖当前页面。'
    }
  }
  if (incomingRevision === '0') {
    return {
      accepted: false,
      applied: false,
      code: 'STATE_REVISION_INVALID',
      message: '后端完整状态的 stateRevision 必须从 1 开始。'
    }
  }
  // 上下文切换清空旧根后，普通在途响应不得复活旧账号／门店状态；
  // 只有显式且服务端绑定的切换响应可装载新根。
  if (contextSwitchIntent && !allowContextSwitch) {
    return {
      accepted: false,
      applied: false,
      stale: true,
      code: 'CONTEXT_SWITCH_PENDING',
      message: '工作台正在切换，已忽略切换前返回的旧页面状态。',
      stateContextId: incomingContextId,
      stateRevision: incomingRevision
    }
  }
  if (currentContextId && currentContextId !== incomingContextId) {
    if (!allowContextSwitch) {
      return {
        accepted: false,
        applied: false,
        stale: true,
        code: 'STATE_CONTEXT_MISMATCH',
        message: '该响应来自已切换前的账号、门店或工作台会话，系统已忽略。',
        stateContextId: incomingContextId,
        currentStateContextId: currentContextId,
        stateRevision: incomingRevision
      }
    }
    return {
      accepted: true,
      applied: false,
      contextChanged: true,
      stateContextId: incomingContextId,
      stateRevision: incomingRevision
    }
  }
  const comparison = compareStateRevision(incomingRevision, currentRevision)
  if (comparison !== null && comparison < 0) {
    return {
      accepted: false,
      applied: false,
      stale: true,
      code: 'STALE_ROOT_STATE',
      message: '该页面状态早于当前已装载状态，系统已忽略。'
    }
  }
  if (comparison === 0) {
    return {
      accepted: false,
      applied: false,
      duplicate: true,
      stale: true,
      code: 'DUPLICATE_ROOT_STATE',
      message: '该页面状态版本已处理，系统已忽略重复响应。',
      stateContextId: incomingContextId,
      stateRevision: incomingRevision
    }
  }
  return {
    accepted: true,
    applied: false,
    stateContextId: incomingContextId,
    stateRevision: incomingRevision
  }
}

/**
 * 供后端适配层在每次成功动作、重新试算或恢复挂单后替换展示状态。
 * 所有动作响应的完整根状态必须携带严格单调的 stateRevision。较旧响应或来自其他
 * stateContextId 的普通动作响应会在这里直接丢弃，避免房态轮询／旧详情等非准备请求
 * 晚到后清空已校验的服务或结账现场。只有显式的工作台上下文切换可传入
 * allowContextSwitch；切换时会先清空旧根状态及浏览器内临时现场。
 */
export function replaceCashierV3State(nextBootstrap, options = {}) {
  const inspection = inspectIncomingRootState(nextBootstrap, options)
  if (!inspection.accepted) return inspection
  const schemaValidation = validateRootStateSchema(nextBootstrap)
  if (!schemaValidation.valid) {
    return {
      accepted: false,
      applied: false,
      code: schemaValidation.code,
      message: schemaValidation.message,
      missingKeys: schemaValidation.missingKeys,
      problems: schemaValidation.problems
    }
  }
  const normalized = normalizeBootstrap(nextBootstrap)
  const incomingContextId = stateContextIdOf(normalized)
  if (inspection.contextChanged) {
    latestServiceCompletionPreparationIntent = null
    latestRoomAssignmentPreparationIntent = null
    clearCashierV3PublicVersions()
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('cashier-v3:state-context-changing', {
        detail: {
          previousStateContextId: stateContextIdOf(cashierV3State),
          stateContextId: inspection.stateContextId
        }
      }))
    }
    // 上下文切换不是一次普通的根状态替换：必须先撤销旧账号／门店／标签页的页面
    // 投影，才能接纳新上下文从 revision=1 开始的首个完整状态。
    Object.keys(cashierV3State).forEach((key) => {
      delete cashierV3State[key]
    })
    Object.assign(cashierV3State, normalizeBootstrap(EMPTY_BOOTSTRAP))
  }
  Object.keys(cashierV3State).forEach((key) => {
    delete cashierV3State[key]
  })
  Object.assign(cashierV3State, normalized)
  replaceCashierV3PublicVersionStore(incomingContextId)
  if (inspection.contextChanged && typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('cashier-v3:state-context-changed', {
      detail: { stateContextId: inspection.stateContextId }
    }))
  }
  return {
    ...inspection,
    applied: true,
    stateRevision: inspection.stateRevision
  }
}

/**
 * 仅供登录账号、强制门店或浏览器 V3 工作台会话已经完成切换的 bootstrap 调用。
 * 普通按钮、查询和写命令不得使用本入口接纳其他 stateContextId 的响应。
 */
export function replaceCashierV3StateForContextSwitch(nextBootstrap) {
  return replaceCashierV3State(nextBootstrap, { allowContextSwitch: true })
}

function fallbackUuidV4() {
  const bytes = new Uint8Array(16)
  if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
    window.crypto.getRandomValues(bytes)
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256)
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export function createCashierV3CommandId(prefix = 'CMD') {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return `${prefix}-${window.crypto.randomUUID()}`
  }
  return `${prefix}-${fallbackUuidV4()}`
}

/**
 * 是否只读投影。
 *
 * 以清单为准，不再按 action 前缀猜：open- 前缀里既有纯展示抽屉，
 * 也有会创建或复用结账请求的三个「去结账」，靠前缀判断必然错一边——
 * 要么后端等命令而前端不发，要么前端把写命令当只读发出去。
 *
 * 传入的应当是已经过别名映射的规范 action。
 */
function isReadOnlyAction(canonicalAction) {
  return !isCashierV3CommandAction(canonicalAction)
}

function getClientSessionId() {
  if (clientSessionId) return clientSessionId
  let persisted = ''
  try {
    persisted = String(window.sessionStorage?.getItem(CASHIER_V3_CLIENT_SESSION_STORAGE_KEY) || '')
  } catch (_) {
    // Storage can be disabled by the browser. A page-lifetime identity still
    // preserves normal command serialization in that constrained case.
  }
  clientSessionId = /^SESSION-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(persisted)
    ? persisted
    : createCashierV3CommandId('SESSION')
  if (clientSessionId !== persisted) {
    try {
      window.sessionStorage?.setItem(CASHIER_V3_CLIENT_SESSION_STORAGE_KEY, clientSessionId)
    } catch (_) {
      // See the read-side fallback above.
    }
  }
  return clientSessionId
}

function hasInvalidWriteContexts(contexts = []) {
  return !Array.isArray(contexts)
    || contexts.length === 0
    || contexts.some((context) => (
      !context
      || !context.kind
      || context.kind === 'none'
      || !context.id
      || !Number.isSafeInteger(context.expectedVersion)
      || context.expectedVersion <= 0
    ))
}

export function canUseCashierV3Feature(featureCode) {
  return cashierV3State.featurePermissions?.[featureCode] === true
}

function normalizeExpectedVersion(value) {
  const normalized = Number(value)
  return Number.isSafeInteger(normalized) && normalized > 0 ? normalized : null
}

function buildCommandContext(kind, id) {
  const fromStore = id ? getCashierV3PublicVersion(kind, id) : null
  return {
    kind,
    id: id || null,
    // 只从当前 stateContextId 绑定的可信公开版本仓取版本；仓内无值 → null（调用方拒绝发送）
    expectedVersion: normalizeExpectedVersion(fromStore)
  }
}

function findReservation(reservationId) {
  return cashierV3State.reservation?.records?.find((record) => record.id === reservationId) || null
}

function findHangOrder(hangOrderId) {
  return cashierV3State.hangOrders?.records?.find((record) => record.id === hangOrderId) || null
}

function findRoom(roomId) {
  const categories = cashierV3State.room?.categories || []
  for (const category of categories) {
    const room = category.rooms?.find((item) => item.id === roomId)
    if (room) return room
  }
  return null
}

function serviceOrderIdentity(serviceOrder) {
  return serviceOrder?.id || serviceOrder?.serviceOrderId || serviceOrder?.serviceSessionId || serviceOrder?.serviceNo || null
}

/**
 * 服务确认可从收银、预约、房间或核销进入。携带服务单 ID 时只能使用同 ID
 * 的快照版本，绝不能拿“当前收银台恰好打开的另一张服务单”代替。
 */
function findServiceOrder(serviceOrderId) {
  if (!serviceOrderId) return null
  const candidates = [
    cashierV3State.serviceCompletion?.serviceOrder,
    cashierV3State.cashier?.serviceOrder,
    cashierV3State.writeoff?.activeServiceSession,
    cashierV3State.reservation?.detail?.serviceOrder,
    cashierV3State.room?.detail?.serviceOrder
  ]
  return candidates.find((serviceOrder) => String(serviceOrderIdentity(serviceOrder)) === String(serviceOrderId)) || null
}

function normalizeCommandContext(context) {
  return {
    kind: context?.kind || 'unknown',
    id: context?.id || null,
    expectedVersion: normalizeExpectedVersion(context?.expectedVersion ?? context?.revision)
  }
}

function distinctCommandContexts(contexts) {
  const seen = new Set()
  return contexts.filter((context) => {
    const key = `${context.kind}:${context.id || ''}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function isCashierWorkspaceAction(action) {
  // 三个去结账映射后的规范命令同样发生在收银工作台上
  if (Object.values(CHECKOUT_COMMAND_ACTIONS).includes(action)) return true
  const cashierWorkspaceActions = [
    'choose-catalog-item',
    'open-member-selector',
    'select-cashier-member',
    // 预约选择会员同样只读取／校验当前收银工作台上下文；后端策略要求
    // cashier_workspace 版本，不能因入口不在收银页就漏传。
    'select-reservation-member',
    'create-member',
    'update-member',
    'deactivate-member',
    'set-guest-order',
    // 定制卡配置先由后端发现并锁定项目资源；客户端仍必须提交当前
    // 收银工作台版本，避免配置写入绕开工作台并发保护。
    'create-custom-card-configuration',
    'remove-cart-line',
    'clear-cart-lines',
    'change-cart-line-quantity',
    'update-cart-line-service-settings',
    'apply-cashier-salespeople-to-all-sale-lines',
    'apply-cashier-craftsmen-to-all-service-lines',
    'apply-cashier-personnel-to-all-lines',
    'update-cashier-line-debt',
    'apply-line-coupon',
    'remove-line-coupon',
    'update-cashier-order-note',
    'update-cashier-line-price',
    'update-cashier-supplement',
    'add-checkout-entitlement-lines',
    'open-line-assignment',
    'open-line-coupon',
    'open-line-debt',
    'open-price-change',
    'open-card-upgrade',
    'open-project-upgrade',
    'open-order-note',
    'open-supplement',
    'change-supplement-date',
    'exit-supplement',
    'open-hang-order',
    'submit-hang-order',
    'resume-hang-order',
    'void-hang-order',
    'prepare-service-completion',
    'prepare-checkout',
    'prepare-debt-repayment',
    'checkout-step-back',
    'checkout-step-next',
    'open-balance-payment',
    'open-balance-payment-identity-verification',
    'toggle-combination-payment',
    'add-payment-method',
    'open-payment-note',
    'update-payment-line',
    'remove-payment-line',
    'update-checkout-business-source',
    'apply-balance-payment',
    'remove-balance-payment',
    'update-balance-payment',
    'open-checkout-source-selector',
    'confirm-debt-warning',
    'confirm-checkout-final-changes',
    'submit-checkout',
    'submit-debt-repayment',
    'return-to-payment-edit',
    'retry-checkout',
    // 重开会把当前订单快照重新装入当前购物车，必须与普通购物车写入
    // 使用同一 cashier_workspace 版本锁，防止覆盖并发中的购物车编辑。
    'reopen-sales-order',
    'query-checkout-result',
    'continue-partial-payment-recovery',
    'go-to-writeoff-after-checkout',
    'view-sales-order',
    'finish-checkout-and-return'
  ]
  return cashierWorkspaceActions.includes(action)
    || action === 'submit-recharge'
    || action === 'prepare-recharge-checkout'
    || action === 'prepare-recharge-debt-repayment'
    || action === 'add-recharge-checkout-payment-method'
    || action === 'update-recharge-checkout-payment-line'
    || action === 'remove-recharge-checkout-payment-line'
    || action === 'update-recharge-checkout-business-source'
    || action === 'update-recharge-checkout-business-date'
    || action === 'reload-recharge-checkout'
    || action === 'submit-recharge-checkout'
    || action === 'submit-recharge-debt-repayment'
    || action === 'submit-direct-gift'
}

/**
 * 一条命令可能同时修改多个现场对象。例如“服务中换房”需要同时校验服务单和房间；
 * “服务单结账”需要同时校验服务单和收银工作台。
 *
 * 后端按 action + 规范化 payload 反推必需资源并校验 contexts 全部对象，
 * 也不再接受单个 command.context——少传一个对象就是少校验一个版本。
 */
function resolveCommandContexts(action, payload) {
  // 只接受完整的 commandContexts 数组；单个 commandContext 回退已删除
  const suppliedContexts = Array.isArray(payload.commandContexts) ? payload.commandContexts : []

  if (suppliedContexts.length) {
    const currentContextId = stateContextIdOf(cashierV3State)
    if (!currentContextId) {
      return { invalid: true, contexts: [] }
    }
    if (currentPublicVersionContextId() && currentPublicVersionContextId() !== currentContextId) {
      return { invalid: true, contexts: [] }
    }
    if (!currentPublicVersionContextId()) {
      cashierV3PublicVersionStore.stateContextId = currentContextId
    }
    const refreshed = []
    for (const context of distinctCommandContexts(suppliedContexts.map(normalizeCommandContext))) {
      if (!context.kind || context.kind === 'none' || !context.id) {
        return { invalid: true, contexts: [] }
      }
      const storeVersion = getCashierV3PublicVersion(context.kind, context.id)
      // 仓内没有则拒绝发送，不得回退信任调用方 suppliedVersion
      if (storeVersion === null) {
        return { invalid: true, contexts: [] }
      }
      refreshed.push({
        kind: context.kind,
        id: context.id,
        expectedVersion: storeVersion
      })
    }
    return { invalid: false, contexts: refreshed }
  }

  const contexts = []

  if (payload.reservationId) {
    contexts.push(buildCommandContext('reservation', payload.reservationId))
  }

  if (payload.serviceOrderId || payload.serviceSessionId) {
    const serviceOrderId = payload.serviceOrderId || payload.serviceSessionId
    contexts.push(buildCommandContext('service_order', serviceOrderId))
  }

  if (payload.writeoffDraftId || action.includes('writeoff')) {
    const writeoff = cashierV3State.writeoff || {}
    contexts.push(buildCommandContext('writeoff_draft', payload.writeoffDraftId || writeoff.draftId))
  }

  // The backend discovers and version-locks the hang from its stable ID.
  // A list-row revision is display data, never a trusted command context.
  if (payload.hangOrderId && !['resume-hang-order', 'void-hang-order'].includes(action)) {
    contexts.push(buildCommandContext('hang_order', payload.hangOrderId))
  }

  if (payload.roomId) {
    contexts.push(buildCommandContext('room', payload.roomId))
  }

  // Recharge-debt repayment locks and revalidates the authoritative debt row
  // inside its transaction. Its declared context policy contains the workspace,
  // member and member balance only, so sending a synthetic debt_record context
  // would be rejected before the command can reach that server-side guard.
  if ((payload.debtRecordId || payload.debtItemId)
    && !['submit-recharge-debt-repayment', 'prepare-recharge-debt-repayment'].includes(action)) {
    contexts.push(buildCommandContext('debt_record', payload.debtRecordId || payload.debtItemId))
  }

  // Reopening an existing editing checkout makes its persisted request a
  // required command identity. The current root projection has already
  // published that version; carry it with the workspace rather than asking
  // the server to accept an unversioned checkout request.
  if (payload.checkoutRequestId) {
    contexts.push(buildCommandContext('checkout_request', payload.checkoutRequestId))
  }

  if (payload.rechargeCheckoutRequestId) {
    contexts.push(buildCommandContext('recharge_checkout_request', payload.rechargeCheckoutRequestId))
  }

  if (['adjust-sales-order-personnel', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order'].includes(action)
    && payload.orderId) {
    contexts.push(buildCommandContext('sales_order', payload.orderId))
  }

  if (['refund-recharge-order', 'void-recharge-order'].includes(action) && payload.rechargeId && payload.memberId) {
    contexts.push(buildCommandContext('recharge_order', payload.rechargeId))
    contexts.push(buildCommandContext('member_balance', payload.memberId))
  }

  if ((action === 'submit-recharge' || action === 'prepare-recharge-checkout' || action === 'prepare-recharge-debt-repayment'
    || action === 'add-recharge-checkout-payment-method' || action === 'update-recharge-checkout-payment-line'
    || action === 'remove-recharge-checkout-payment-line' || action === 'update-recharge-checkout-business-source'
    || action === 'update-recharge-checkout-business-date'
    || action === 'submit-recharge-checkout'
    || action === 'reload-recharge-checkout'
    || action === 'submit-recharge-debt-repayment' || action === 'submit-direct-gift') && payload.memberId) {
    contexts.push(buildCommandContext('member', payload.memberId))
    if (action !== 'submit-direct-gift') contexts.push(buildCommandContext('member_balance', payload.memberId))
  }

  if (isCashierWorkspaceAction(action)) {
    const workspace = cashierV3State.workspace || {}
    contexts.push(buildCommandContext('cashier_workspace', workspace.id))
  }

  // 自动推导：任一对象仓内无版本即拒绝发送，禁止 page/payload revision 回退
  if (contexts.some((c) => !c.id || !Number.isSafeInteger(c.expectedVersion) || c.expectedVersion <= 0)) {
    return { invalid: true, contexts: [] }
  }

  return { invalid: false, contexts: distinctCommandContexts(contexts) }
}

function preparationKindForAction(action) {
  if (SERVICE_COMPLETION_PREPARATION_ACTIONS.has(action)) return 'service_completion'
  if (ROOM_ASSIGNMENT_PREPARATION_ACTIONS.has(action)) return 'room_assignment'
  return ''
}

function preparationRequestId(source = {}) {
  return source?.roomAssignmentPreparationId
    || source?.room_assignment_preparation_id
    || source?.preparationRequestId
    || source?.preparation_request_id
    || source?.prepareRequestId
    || source?.requestId
    || ''
}

function preparationServiceOrderId(source = {}) {
  return source?.serviceOrderId
    || source?.service_order_id
    || source?.serviceSessionId
    || source?.service_session_id
    || serviceOrderIdentity(source?.serviceOrder || source?.service_order || source?.serviceSession || source?.service)
    || null
}

function preparationReservationId(source = {}) {
  const reservation = source?.reservation || source?.reservation_record
  return source?.reservationId
    || source?.reservation_id
    || reservation?.id
    || reservation?.reservationId
    || reservation?.reservationNo
    || null
}

function preparationRoomAssignmentScope(source = {}) {
  const value = source?.assignmentScope || source?.assignment_scope
  return ['reservation_plan', 'active_service'].includes(value) ? value : ''
}

function preparationRoomAssignmentMode(source = {}) {
  const raw = source?.mode || source?.assignmentMode || source?.assignment_mode
  if (['assign', 'change', 'remove'].includes(raw)) return raw
  if (['assign-room', 'change-room', 'remove-room'].includes(raw)) return raw.replace('-room', '')
  return ''
}

function preparationSubject(kind, source = {}) {
  if (kind === 'service_completion') {
    const id = preparationServiceOrderId(source)
    return id ? { kind: 'service_order', id, key: `service_order:${id}`, scope: '' } : null
  }
  const scope = preparationRoomAssignmentScope(source)
  const id = scope === 'reservation_plan'
    ? preparationReservationId(source)
    : scope === 'active_service'
      ? preparationServiceOrderId(source)
      : null
  if (!id) return null
  const subjectKind = scope === 'reservation_plan' ? 'reservation' : 'service_order'
  return { kind: subjectKind, id, key: `${subjectKind}:${id}`, scope }
}

function registerPreparationIntent(action, payload = {}) {
  const kind = preparationKindForAction(action)
  if (!kind) return null
  const requestId = preparationRequestId(payload)
  const subject = preparationSubject(kind, payload)
  const mode = kind === 'room_assignment' ? preparationRoomAssignmentMode(payload) : ''
  if (!requestId || !subject || (kind === 'room_assignment' && !mode)) return null
  const intent = { kind, requestId, subject, mode, action }
  if (kind === 'service_completion') latestServiceCompletionPreparationIntent = intent
  if (kind === 'room_assignment') latestRoomAssignmentPreparationIntent = intent
  return intent
}

function unwrapCashierV3Response(rawResult) {
  if (!rawResult || typeof rawResult !== 'object') return {}
  // 顶层已是标准 V3 信封（含 result／state）：不得因业务字段 data 而误剥层
  if (rawResult.result && typeof rawResult.result === 'object') return rawResult
  if (rawResult.state && typeof rawResult.state === 'object' && (rawResult.stateContextId != null || rawResult.stateRevision != null)) {
    return rawResult
  }
  const nested = rawResult.data
  if (nested && typeof nested === 'object'
    && ((nested.result && typeof nested.result === 'object')
      || (nested.state && typeof nested.state === 'object')
      || TRUSTED_V3_RESULT_STATUSES.has(String(nested.status || '')))) {
    return nested
  }
  return rawResult
}

function responseResultStatus(response = {}) {
  const result = response?.result && typeof response.result === 'object' ? response.result : {}
  return result?.status || response?.status || ''
}

function responseOverlay(response = {}) {
  const result = response?.result && typeof response.result === 'object' ? response.result : {}
  const overlay = response?.overlay || result?.overlay
  return overlay && typeof overlay === 'object' ? overlay : null
}

function preparationSnapshot(response = {}, kind) {
  const rootState = response?.state && typeof response.state === 'object' ? response.state : null
  if (!rootState) return null
  if (kind === 'service_completion') {
    const snapshot = rootState.serviceCompletion || rootState.service_completion
    return snapshot && typeof snapshot === 'object' ? snapshot : null
  }
  const room = rootState.room && typeof rootState.room === 'object' ? rootState.room : null
  const snapshot = room?.assignment || room?.roomAssignment || room?.room_assignment
  return snapshot && typeof snapshot === 'object' ? snapshot : null
}

/**
 * 必须在 replaceCashierV3State 之前执行。页面层的弹窗校验只能决定“是否展示”，
 * 无法挽回已经被晚到响应覆盖的根状态。
 */
function validatePreparationResponse(intent, rawResult) {
  if (!intent) return { accepted: true }
  const latestIntent = intent.kind === 'service_completion'
    ? latestServiceCompletionPreparationIntent
    : latestRoomAssignmentPreparationIntent
  if (!latestIntent
    || latestIntent.requestId !== intent.requestId
    || latestIntent.subject?.key !== intent.subject?.key
    || latestIntent.subject?.scope !== intent.subject?.scope
    || latestIntent.mode !== intent.mode) {
    return {
      accepted: false,
      stale: true,
      code: 'STALE_PREPARATION_RESPONSE',
      message: '该准备响应已被更新的操作取代，系统已忽略。'
    }
  }

  const response = unwrapCashierV3Response(rawResult)
  if (['failed', 'conflict'].includes(responseResultStatus(response))) return { accepted: true }

  const snapshot = preparationSnapshot(response, intent.kind)
  const overlay = responseOverlay(response)
  const expectedOverlayName = intent.kind === 'service_completion' ? 'service-completion' : 'room-assignment'
  const overlayName = overlay?.name || overlay?.overlayName || overlay?.type || ''
  const snapshotRequestId = preparationRequestId(snapshot)
  const overlayRequestId = preparationRequestId(overlay)
  const snapshotSubject = preparationSubject(intent.kind, snapshot)
  const overlaySubject = preparationSubject(intent.kind, overlay)
  const snapshotMode = intent.kind === 'room_assignment' ? preparationRoomAssignmentMode(snapshot) : ''
  const overlayMode = intent.kind === 'room_assignment' ? preparationRoomAssignmentMode(overlay) : ''
  if (!snapshot
    || !overlay
    || overlayName !== expectedOverlayName
    || snapshotRequestId !== intent.requestId
    || overlayRequestId !== intent.requestId
    || snapshotSubject?.key !== intent.subject?.key
    || overlaySubject?.key !== intent.subject?.key
    || snapshotSubject?.scope !== intent.subject?.scope
    || overlaySubject?.scope !== intent.subject?.scope
    || (intent.kind === 'room_assignment' && (snapshotMode !== intent.mode || overlayMode !== intent.mode))) {
    return {
      accepted: false,
      stale: false,
      code: 'PREPARATION_RESPONSE_CONTRACT_INVALID',
      message: intent.kind === 'service_completion'
        ? '服务确认快照未按当前请求号和服务单返回，已拒绝装载。'
        : '房间安排快照未按当前请求号、安排范围和预约／服务对象返回，已拒绝装载。'
    }
  }

  return { accepted: true }
}

function emitCashierV3UiResult(rawResult, requestMeta = {}) {
  const outcome = applyCashierV3EnvelopeState(rawResult, requestMeta, { emitUi: false })
  const response = outcome.response || unwrapCashierV3Response(rawResult)
  const result = outcome.result || (response?.result && typeof response.result === 'object' ? response.result : {})
  const status = outcome.status || String(result?.status || response?.status || '')
  const isConflict = status === 'conflict'

  if (!outcome.accepted) {
    if (outcome.resultUnknown) {
      return {
        ...outcome,
        accepted: false,
        resultUnknown: true,
        status: 'result_unknown'
      }
    }
    if (outcome.envelopeRejected) {
      return outcome
    }
    // 根拒收后零副作用：禁止转发 overlay／navigation／versions
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: {
        status,
        code: result?.code || response?.code || outcome.code || '',
        message: result?.message || response?.message || outcome.message || '',
        feedback: null,
        navigation: null,
        overlay: null,
        latestState: null,
        conflict: null,
        versions: null,
        stateIgnored: true,
        ignoredRootState: true,
        ignoredRootStateCode: outcome.code || '',
        requiresRefresh: true
      }
    }))
    return {
      accepted: false,
      stateIgnored: true,
      ignoredRootState: true,
      requiresRefresh: true,
      code: outcome.code,
      message: outcome.message,
      stale: outcome.stale === true
    }
  }

  window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
    detail: {
      status,
      code: result?.code || response?.code || '',
      message: result?.message || response?.message || '',
      feedback: response?.feedback || result?.feedback || null,
      navigation: isConflict ? null : (response?.navigation || result?.navigation || null),
      overlay: isConflict ? null : (response?.overlay || result?.overlay || null),
      latestState: isConflict ? (response?.latestState || outcome.trustedConflictState) : null,
      conflict: isConflict ? (response?.conflict || result?.conflict || null) : null,
      versions: Array.isArray(response?.versions) ? response.versions : null,
      stateIgnored: outcome.stateIgnored === true,
      ignoredRootState: outcome.stateIgnored === true,
      requiresRefresh: Boolean(outcome.requiresRefresh || response?.requiresRefresh)
    }
  }))
  return {
    accepted: true,
    applied: outcome.stateApplied,
    stateIgnored: outcome.stateIgnored === true,
    ignoredRootState: outcome.stateIgnored === true,
    requiresRefresh: outcome.requiresRefresh === true
  }
}

function applyCashierV3EnvelopeState(rawResult, requestMeta = {}, options = {}) {
  const response = unwrapCashierV3Response(rawResult)
  const result = response?.result && typeof response.result === 'object' ? response.result : response?.result || {}
  const status = String(result?.status || response?.status || '')
  const isConflict = status === 'conflict'
  const actionType = String(requestMeta.actionType || '')
  const isWrite = actionType === 'command' || requestMeta.requireIdempotencyBinding === true
  let trustedConflictState = null
  let stateApplied = false
  let stateIgnored = false
  let requiresRefresh = false

  // 严格响应绑定：有／无 state、command／projection／result-query 全部校验
  const binding = assertResponseBinding(response, requestMeta)
  if (!binding.accepted) {
    if (isWrite && binding.code && String(binding.code).startsWith('ENVELOPE_')) {
      // 无绑定的写响应 → result_unknown（不可证明归属）
      return {
        accepted: false,
        envelopeRejected: true,
        resultUnknown: true,
        code: 'COMMAND_RESULT_UNKNOWN',
        message: '写响应缺少可信绑定，结果未知，请查询原结果后再决定是否重试。',
        bindingCode: binding.code,
        response,
        result: {
          status: 'result_unknown',
          code: 'COMMAND_RESULT_UNKNOWN',
          message: '写响应缺少可信绑定，结果未知，请查询原结果后再决定是否重试。'
        },
        status: 'result_unknown'
      }
    }
    return {
      accepted: false,
      envelopeRejected: true,
      code: binding.code,
      message: binding.message,
      response,
      result,
      status
    }
  }

  // context-switch：epoch/token 失败整包拒绝，禁止回退普通 replaceCashierV3State
  if (requestMeta.allowContextSwitch === true) {
    if (!shouldAllowContextSwitch(response)) {
      return {
        accepted: false,
        envelopeRejected: true,
        code: 'CONTEXT_SWITCH_TOKEN_REJECTED',
        message: '工作台切换校验失败，已拒绝整包响应，未应用任何中间状态。',
        response,
        result,
        status
      }
    }
  }

  if (response?.state) {
    const envelopeConsistency = assertStateEnvelopeConsistency(response, requestMeta)
    if (!envelopeConsistency.accepted) {
      const preserveBusiness = ['success', 'conflict'].includes(status)
        && [
          'STATE_CONTEXT_MISMATCH',
          'STATE_CONTEXT_MISSING',
          'STATE_REVISION_MISMATCH',
          'STATE_REVISION_MISSING',
          'STATE_REVISION_INVALID',
          'ENVELOPE_ACTION_MISMATCH',
          'ENVELOPE_IDEMPOTENCY_MISMATCH'
        ].includes(envelopeConsistency.code)
      return {
        accepted: false,
        envelopeRejected: !preserveBusiness,
        stateIgnored: preserveBusiness,
        requiresRefresh: preserveBusiness,
        code: envelopeConsistency.code,
        message: envelopeConsistency.message,
        response,
        result,
        status
      }
    }

    const allowContextSwitch = requestMeta.allowContextSwitch === true
      ? true
      : shouldAllowContextSwitch(response)
    if (isConflict) {
      const inspection = inspectIncomingRootState(response.state, { allowContextSwitch: false })
      if (inspection.accepted) {
        trustedConflictState = response.state
        if (Array.isArray(response.versions)) {
          const respCtx = stateContextIdOf(response.state)
          mergeCashierV3PublicVersions(response.versions, respCtx, {
            requestStateContextId: respCtx
          })
        }
      }
    } else {
      const replacement = allowContextSwitch
        ? replaceCashierV3StateForContextSwitch(response.state)
        : replaceCashierV3State(response.state)
      if (!replacement.accepted) {
        stateIgnored = true
        requiresRefresh = true
        return {
          accepted: false,
          envelopeRejected: false,
          stateIgnored: true,
          requiresRefresh: true,
          code: replacement.code,
          message: replacement.message,
          stale: replacement.stale === true,
          response,
          result,
          status
        }
      }
      stateApplied = replacement.applied === true
      if (Array.isArray(response.versions)) {
        const respCtx = stateContextIdOf(response.state)
        const reqCtx = String(requestMeta?.stateContextId || respCtx || '')
        mergeCashierV3PublicVersions(response.versions, respCtx, {
          requestStateContextId: allowContextSwitch ? respCtx : reqCtx
        })
      }
    }
  } else if (Array.isArray(response?.versions) && status === 'success') {
    const responseContextId = String(response.stateContextId || '')
    const requestContextId = String(requestMeta?.stateContextId || stateContextIdOf(cashierV3State) || '')
    if (!responseContextId || !requestContextId || responseContextId !== requestContextId) {
      // 整包忽略 versions，防止跨上下文污染
    } else {
      mergeCashierV3PublicVersions(response.versions, responseContextId, {
        requestStateContextId: requestContextId
      })
    }
  }

  return {
    accepted: true,
    envelopeRejected: false,
    stateApplied,
    stateIgnored,
    requiresRefresh,
    trustedConflictState,
    response,
    result,
    status
  }
}

/**
 * 全部响应严格绑定：action／幂等键／correlation／上下文。
 * projection 也要求 correlation；写响应缺绑定由调用方升为 result_unknown。
 */
function assertResponseBinding(response, requestMeta = {}) {
  if (!response || typeof response !== 'object') {
    return { accepted: false, code: 'ENVELOPE_MISSING', message: '响应信封缺失。' }
  }
  const expectedAction = String(requestMeta.expectedAction || requestMeta.canonicalAction || '')
  const actionType = String(requestMeta.actionType || '')
  if (expectedAction) {
    const bound = String(response.boundAction || response.boundCanonical || '')
    if (!bound || bound !== expectedAction) {
      return {
        accepted: false,
        code: 'ENVELOPE_ACTION_MISMATCH',
        message: '响应绑定的操作与本次请求不一致或缺失，已拒绝。'
      }
    }
  }
  if (actionType === 'command' || requestMeta.requireIdempotencyBinding) {
    const expectedKey = String(requestMeta.expectedIdempotencyKey || '')
    const boundKey = String(response.boundIdempotencyKey || '')
    if (!expectedKey || !boundKey || boundKey !== expectedKey) {
      return {
        accepted: false,
        code: 'ENVELOPE_IDEMPOTENCY_MISMATCH',
        message: '响应绑定的请求标识与本次不一致或缺失，已拒绝。'
      }
    }
  }
  const expectedCorrelation = String(requestMeta.correlationId || requestMeta.expectedCorrelationId || '')
  const requireCorrelation = actionType === 'command'
    || actionType === 'projection'
    || requestMeta.requireCorrelationBinding === true
    || expectedCorrelation !== ''
  if (requireCorrelation) {
    const boundCorr = String(response.boundCorrelationId || '')
    if (!expectedCorrelation || !boundCorr || boundCorr !== expectedCorrelation) {
      return {
        accepted: false,
        code: 'ENVELOPE_CORRELATION_MISMATCH',
        message: '响应绑定的 correlation 与本次不一致或缺失，已拒绝。'
      }
    }
  }
  const expectedCtx = String(requestMeta.stateContextId || requestMeta.expectedStateContextId || '')
  const requireCtx = expectedCtx !== '' || requestMeta.requireStateContextBinding === true
  if (requireCtx) {
    const boundCtx = String(response.stateContextId || '')
    if (!boundCtx || (expectedCtx && boundCtx !== expectedCtx)) {
      if (!(requestMeta.allowContextSwitch && response.contextSwitchServerBound === true)) {
        return {
          accepted: false,
          code: 'ENVELOPE_STATE_CONTEXT_MISMATCH',
          message: '响应 stateContextId 与本次请求不一致或缺失，已拒绝。'
        }
      }
    }
  }
  if (requestMeta.requireOriginalIdempotencyKey) {
    const original = String(requestMeta.originalIdempotencyKey || '')
    const boundOriginal = String(response.boundOriginalIdempotencyKey || '')
    if (!original || !boundOriginal || original !== boundOriginal) {
      return {
        accepted: false,
        code: 'ENVELOPE_ORIGINAL_KEY_MISMATCH',
        message: '结果追查绑定的原幂等键不一致或缺失，已拒绝。'
      }
    }
  }
  return { accepted: true }
}

function buildStateIgnoredResult(originalResult, envelopeOutcome = {}) {
  const response = unwrapCashierV3Response(originalResult)
  const resultBlock = response?.result && typeof response.result === 'object'
    ? { ...response.result }
    : (response?.result || {})
  // 业务结果可以保留，但任何依赖被拒收根状态的页面指令都必须剥离。
  // 否则调用方可能拿着旧根继续导航、打开浮层或污染版本仓。
  const {
    state: _state,
    stateRevision: _stateRevision,
    stateContextId: _stateContextId,
    versions: _versions,
    overlay: _overlay,
    navigation: _navigation,
    latestState: _latestState,
    ...responseWithoutRoot
  } = response && typeof response === 'object' ? response : {}
  const preserved = {
    ...responseWithoutRoot,
    result: resultBlock,
    stateIgnored: true,
    requiresRefresh: envelopeOutcome.requiresRefresh !== false,
    ignoredRootState: true,
    ignoredRootStateCode: envelopeOutcome.code || '',
    ignoredRootStateMessage: envelopeOutcome.message || ''
  }
  if (originalResult?.idempotencyKey) preserved.idempotencyKey = originalResult.idempotencyKey
  if (response?.idempotencyKey) preserved.idempotencyKey = response.idempotencyKey
  if (response?.businessNo) preserved.businessNo = response.businessNo
  if (resultBlock?.businessNo) preserved.businessNo = resultBlock.businessNo
  delete preserved.overlay
  delete preserved.navigation
  delete preserved.versions
  delete preserved.state
  delete preserved.stateRevision
  delete preserved.stateContextId
  return preserved
}

function assertStateEnvelopeConsistency(response, requestMeta = {}) {
  const state = response.state
  if (!state || typeof state !== 'object') {
    return { accepted: false, code: 'STATE_CONTEXT_MISSING', message: '完整根状态缺失。' }
  }
  const innerCtx = stateContextIdOf(state)
  const innerRev = stateRevisionOf(state)
  if (!innerCtx || !innerRev) {
    return {
      accepted: false,
      code: 'STATE_CONTEXT_MISSING',
      message: '后端完整状态缺少 stateContextId／stateRevision，已拒绝覆盖当前页面。'
    }
  }
  if (response.stateContextId != null && String(response.stateContextId) !== '' && String(response.stateContextId) !== innerCtx) {
    return {
      accepted: false,
      code: 'STATE_CONTEXT_MISMATCH',
      message: '响应顶层 stateContextId 与 state 内不一致，已拒绝。'
    }
  }
  if (response.stateRevision != null && String(response.stateRevision) !== '' && String(response.stateRevision) !== innerRev) {
    return {
      accepted: false,
      code: 'STATE_REVISION_MISMATCH',
      message: '响应顶层 stateRevision 与 state 内不一致，已拒绝。'
    }
  }
  // 严格信封：必需绑定字段缺失／类型非法／不一致一律整包拒绝（不可选比较）
  const expectedAction = String(requestMeta.expectedAction || requestMeta.canonicalAction || '')
  const actionType = String(requestMeta.actionType || '')
  if (expectedAction) {
    const bound = String(response.boundAction || response.boundCanonical || '')
    if (!bound || bound !== expectedAction) {
      return {
        accepted: false,
        code: 'ENVELOPE_ACTION_MISMATCH',
        message: '响应绑定的操作与本次请求不一致或缺失，已拒绝。'
      }
    }
  }
  if (actionType === 'command' || requestMeta.requireIdempotencyBinding) {
    const expectedKey = String(requestMeta.expectedIdempotencyKey || '')
    const boundKey = String(response.boundIdempotencyKey || '')
    if (!expectedKey || !boundKey || boundKey !== expectedKey) {
      return {
        accepted: false,
        code: 'ENVELOPE_IDEMPOTENCY_MISMATCH',
        message: '响应绑定的请求标识与本次不一致或缺失，已拒绝。'
      }
    }
  }
  const expectedCorrelation = String(requestMeta.correlationId || requestMeta.expectedCorrelationId || '')
  const requireCorrelation = actionType === 'command'
    || requestMeta.requireCorrelationBinding === true
    || expectedCorrelation !== ''
  if (requireCorrelation) {
    const boundCorr = String(response.boundCorrelationId || '')
    if (!expectedCorrelation || !boundCorr || boundCorr !== expectedCorrelation) {
      return {
        accepted: false,
        code: 'ENVELOPE_CORRELATION_MISMATCH',
        message: '响应绑定的 correlation 与本次不一致或缺失，已拒绝。'
      }
    }
  }
  const expectedCtx = String(requestMeta.stateContextId || requestMeta.expectedStateContextId || '')
  const requireCtx = expectedCtx !== '' || requestMeta.requireStateContextBinding === true
  if (requireCtx) {
    const boundCtx = String(response.stateContextId || '')
    if (!boundCtx || (expectedCtx && boundCtx !== expectedCtx)) {
      // context switch 允许新上下文：仅当 allowContextSwitch 且服务端绑定
      if (!(requestMeta.allowContextSwitch && response.contextSwitchServerBound === true)) {
        return {
          accepted: false,
          code: 'ENVELOPE_STATE_CONTEXT_MISMATCH',
          message: '响应 stateContextId 与本次请求不一致或缺失，已拒绝。'
        }
      }
    }
  }
  if (requestMeta.requireOriginalIdempotencyKey) {
    const original = String(requestMeta.originalIdempotencyKey || '')
    const boundOriginal = String(response.boundOriginalIdempotencyKey || '')
    if (!original || !boundOriginal || original !== boundOriginal) {
      return {
        accepted: false,
        code: 'ENVELOPE_ORIGINAL_KEY_MISMATCH',
        message: '结果追查绑定的原幂等键不一致或缺失，已拒绝。'
      }
    }
  }
  return { accepted: true }
}

function shouldAllowContextSwitch(response) {
  // 禁止 requestMeta 兜底：必须有服务端绑定标记 + epoch/token 匹配
  if (response?.contextChanged !== true) return false
  if (response?.contextSwitchServerBound !== true) return false
  const intent = contextSwitchIntent
  if (!intent) return false
  const responseEpoch = Number(response.contextSwitchEpoch)
  const responseToken = String(response.contextSwitchToken || '')
  const responseClientToken = String(response.contextSwitchClientToken || '')
  if (!Number.isFinite(responseEpoch) || responseEpoch !== intent.epoch) return false
  if (!responseToken || responseToken === intent.token || responseClientToken !== intent.token) return false
  contextSwitchIntent = null
  return true
}

/**
 * 临时事件桥仅服务于接口尚未接入前的页面联调。
 * Cursor 接入后可在 window.__CASHIER_V3_ADAPTER__.request 中调用 V3 后端；
 * 如果返回标准 V3 结果，本文件会按对象版本处理状态、导航与反馈。
 * 发生版本冲突时不静默替换当前状态，交由页面保留未保存输入并提示用户。
 */
export async function requestCashierV3Action(action, payload = {}) {
  const adapter = window.__CASHIER_V3_ADAPTER__
  const {
    commandContexts,
    idempotencyKey,
    silent,
    clientSessionId: ignoredClientSessionId,
    stateContextId: ignoredStateContextId,
    contextSwitchToken,
    contextSwitchEpoch: requestSwitchEpoch,
    __contextRecoveryAttempted: contextRecoveryAttempted = false,
    ...requestBody
  } = payload

  // 清单里没有的 action 本地就拒绝
  if (!hasCashierV3Action(action)) {
    const unknownAction = {
      result: {
        status: 'failed',
        code: 'UNKNOWN_COMMAND_ACTION',
        message: '该操作尚未开放，请联系管理员。'
      },
      unregisteredAction: action
    }
    if (!silent) emitCashierV3UiResult(unknownAction)
    return unknownAction
  }

  const canonicalAction = canonicalCashierV3Action(action)
  const readOnly = isReadOnlyAction(canonicalAction)
  // 预约只是单据资料保存。它不依赖收银工作台或预约资源版本，直接按
  // 服务端单据写入结果处理，也不应因工作台投影未同步而拒绝提交。
  const reservationDataWrite = ['create-reservation', 'update-reservation'].includes(canonicalAction)

  // 结果追查：必须带 originalIdempotencyKey；禁止猜测或创建新键
  if (RESULT_QUERY_ACTIONS.has(canonicalAction)) {
    const originalKey = String(requestBody.originalIdempotencyKey || '').trim()
    if (!originalKey && !allowsDirectResultLookup(canonicalAction, requestBody)) {
      const missing = {
        result: {
          status: 'failed',
          code: 'ORIGINAL_IDEMPOTENCY_KEY_REQUIRED',
          message: '结果追查缺少原命令标识，请刷新当前工作台后重试。'
        }
      }
      if (!silent) emitCashierV3UiResult(missing)
      return missing
    }
  }

  const resolvedContexts = readOnly || reservationDataWrite
    ? { invalid: false, contexts: [] }
    : resolveCommandContexts(canonicalAction, { ...requestBody, commandContexts })
  const contexts = resolvedContexts.contexts
  const resolvedIdempotencyKey = readOnly ? null : (idempotencyKey || createCashierV3CommandId())
  const command = readOnly
    ? null
    : {
        action: canonicalAction,
        idempotencyKey: resolvedIdempotencyKey,
        contexts
      }
  const requestPayload = {
    ...requestBody,
    action: canonicalAction,
    clientSessionId: getClientSessionId(),
    ...(stateContextIdOf(cashierV3State) ? { stateContextId: stateContextIdOf(cashierV3State) } : {}),
    ...(command ? { command } : {})
  }
  const correlationId = `CORR-${fallbackUuidV4()}`
  requestPayload.correlationId = correlationId
  const originalResultKey = String(requestBody.originalIdempotencyKey || '').trim()
  if (RESULT_QUERY_ACTIONS.has(canonicalAction) && originalResultKey) {
    requestPayload.originalIdempotencyKey = originalResultKey
  }
  const requestMeta = {
    contextSwitchToken,
    contextSwitchEpoch: requestSwitchEpoch,
    expectedAction: canonicalAction,
    canonicalAction,
    actionType: readOnly ? 'projection' : 'command',
    expectedIdempotencyKey: resolvedIdempotencyKey || '',
    requireIdempotencyBinding: !readOnly,
    correlationId,
    requireCorrelationBinding: true,
    stateContextId: stateContextIdOf(cashierV3State) || '',
    requireOriginalIdempotencyKey: RESULT_QUERY_ACTIONS.has(canonicalAction) && Boolean(originalResultKey),
    originalIdempotencyKey: originalResultKey
  }
  const preparationKind = preparationKindForAction(canonicalAction)
  const preparationIntent = registerPreparationIntent(canonicalAction, requestPayload)
  if (preparationKind && !preparationIntent) {
    const invalidPreparationRequest = {
      result: {
        status: 'failed',
        code: 'PREPARATION_REQUEST_CONTEXT_MISSING',
        message: preparationKind === 'room_assignment'
          ? '本次房间安排缺少请求号、安排范围或预约／服务对象，已拒绝发送。'
          : '本次准备操作缺少请求号或服务单上下文，已拒绝发送。'
      }
    }
    if (!silent) emitCashierV3UiResult(invalidPreparationRequest)
    return invalidPreparationRequest
  }
  if (!readOnly && !reservationDataWrite && (resolvedContexts.invalid || hasInvalidWriteContexts(contexts))) {
    // No command has been sent yet, so a single automatic root recovery is
    // safe. This covers the narrow interval after entering the cashier where
    // the UI is visible but the root's authoritative versions are still
    // loading. Final settlement and any command already sent are never
    // replayed through this path.
    if (!contextRecoveryAttempted && canonicalAction !== 'open-cashier-workbench') {
      const hasCurrentRoot = Boolean(stateContextIdOf(cashierV3State))
      const recovered = hasCurrentRoot
        ? await requestCashierV3Action('open-cashier-workbench', { silent: true })
        : await requestCashierV3ContextSwitch({
            reason: 'command_context_recovery',
            action: 'open-cashier-workbench',
            silent: true
          })
      const recoveryResult = recovered?.result || recovered?.data?.result || {}
      if (['success', 'succeeded'].includes(String(recoveryResult.status || '').toLowerCase())) {
        return requestCashierV3Action(action, {
          ...payload,
          idempotencyKey: resolvedIdempotencyKey,
          __contextRecoveryAttempted: true
        })
      }
    }
    const invalidCommandContext = {
      result: {
        status: 'failed',
        code: 'INVALID_COMMAND_CONTEXT',
        message: '本次写操作缺少有效的既有资源版本，请刷新当前工作台后重试。'
      }
    }
    if (!silent) emitCashierV3UiResult(invalidCommandContext)
    return invalidCommandContext
  }

  if (adapter && typeof adapter.request === 'function') {
    try {
      const result = await adapter.request(canonicalAction, requestPayload)
      // 预约创建／编辑是独立单据保存，不接入收银工作台回执绑定、根状态
      // 投影或结果追查。页面仅按服务端保存结果处理成功和普通失败。
      if (reservationDataWrite) return result || {}
      // 无可信标准 V3 结果信封时，写命令视为结果未知
      if (!readOnly && !isTrustedV3Envelope(result)) {
        return emitCommandResultUnknown(resolvedIdempotencyKey, canonicalAction, silent, '损坏或不可信的响应')
      }
      const preparationValidation = validatePreparationResponse(preparationIntent, result || {})
      if (!preparationValidation.accepted) {
        const rejectedPreparationResponse = {
          result: {
            status: 'failed',
            code: preparationValidation.code,
            message: preparationValidation.message
          },
          ignoredPreparationResponse: true
        }
        if (!silent && !preparationValidation.stale) emitCashierV3UiResult(rejectedPreparationResponse, requestMeta)
        return rejectedPreparationResponse
      }
      if (!silent) {
        const emission = emitCashierV3UiResult(result || {}, requestMeta)
        if (!emission.accepted) {
          if (emission.resultUnknown || emission.status === 'result_unknown') {
            return emitCommandResultUnknown(resolvedIdempotencyKey, canonicalAction, silent, emission.bindingCode || emission.code)
          }
          const businessStatus = String(unwrapCashierV3Response(result)?.result?.status
            || unwrapCashierV3Response(result)?.status
            || '')
          if (emission.stateIgnored && ['success', 'conflict'].includes(businessStatus)) {
            return buildStateIgnoredResult(result, emission)
          }
          const rejectedRootState = {
            result: {
              status: 'failed',
              code: emission.code,
              message: emission.message
            },
            ignoredRootState: true
          }
          if (!emission.stale) emitCashierV3UiResult(rejectedRootState, requestMeta)
          return rejectedRootState
        }
      } else {
        const envelopeOutcome = applyCashierV3EnvelopeState(result || {}, requestMeta, { emitUi: false })
        if (!envelopeOutcome.accepted) {
          if (envelopeOutcome.resultUnknown || envelopeOutcome.status === 'result_unknown') {
            return emitCommandResultUnknown(resolvedIdempotencyKey, canonicalAction, silent, envelopeOutcome.bindingCode || envelopeOutcome.code)
          }
          if (envelopeOutcome.stateIgnored && ['success', 'conflict'].includes(envelopeOutcome.status)) {
            return buildStateIgnoredResult(result, envelopeOutcome)
          }
          return {
            result: {
              status: 'failed',
              code: envelopeOutcome.code,
              message: envelopeOutcome.message
            },
            ignoredRootState: true,
            stale: envelopeOutcome.stale === true
          }
        }
      }
      return result || {}
    } catch (error) {
      if (reservationDataWrite) {
        return {
          result: {
            status: 'failed',
            code: 'RESERVATION_SAVE_FAILED',
            message: error?.message || '预约保存失败，请重试。'
          }
        }
      }
      if (readOnly) {
        const failed = {
          result: {
            status: 'failed',
            code: 'CLIENT_REQUEST_FAILED',
            message: error?.message || '本次查询未完成，请稍后重试。'
          }
        }
        if (!silent) emitCashierV3UiResult(failed, requestMeta)
        return failed
      }
      return emitCommandResultUnknown(resolvedIdempotencyKey, canonicalAction, silent, error?.message)
    }
  }

  window.dispatchEvent(new CustomEvent('cashier-v3:action', {
    detail: { action: canonicalAction, pageAction: action, payload: requestPayload }
  }))
  return { pendingIntegration: true }
}

function isTrustedV3Envelope(result) {
  if (!result || typeof result !== 'object') return false
  const envelope = unwrapCashierV3Response(result)
  if (!envelope || typeof envelope !== 'object') return false

  if (envelope.protocol != null) {
    const protocol = envelope.protocol
    if (typeof protocol !== 'string' && typeof protocol !== 'number') return false
  }
  if (envelope.protocolVersion != null) {
    const protocolVersion = envelope.protocolVersion
    if (typeof protocolVersion !== 'string' && typeof protocolVersion !== 'number') return false
  }

  const resultBlock = envelope.result
  if (!resultBlock || typeof resultBlock !== 'object') return false

  const status = String(resultBlock.status || envelope.status || '')
  if (!TRUSTED_V3_RESULT_STATUSES.has(status)) return false

  if (status === 'failed' || status === 'result_unknown') {
    if (!String(resultBlock.code || '').trim()) return false
    if (!String(resultBlock.message || '').trim()) return false
  }
  if (status === 'success') {
    if (resultBlock.code != null && typeof resultBlock.code !== 'string') return false
    if (resultBlock.message != null && typeof resultBlock.message !== 'string') return false
  }
  if (status === 'conflict') {
    const hasConflictPayload = Boolean(
      envelope.conflict
      || resultBlock.conflict
      || envelope.latestState
      || resultBlock.latestState
      || String(resultBlock.message || envelope.message || '').trim()
    )
    if (!hasConflictPayload) return false
  }

  if (Array.isArray(envelope.versions)) {
    if (!envelope.versions.every((row) => normalizePublicVersionRow(row))) return false
  }

  if (envelope.state != null && typeof envelope.state !== 'object') return false
  if (envelope.stateContextId != null && typeof envelope.stateContextId !== 'string' && typeof envelope.stateContextId !== 'number') {
    return false
  }
  if (envelope.stateRevision != null && String(envelope.stateRevision) === '') return false

  return true
}

function emitCommandResultUnknown(idempotencyKey, canonicalAction, silent, detailMessage) {
  const queryResultAction = QUERY_RESULT_ACTION_BY_COMMAND[canonicalAction] || null
  const recoveryMode = CASHIER_V3_ACTION_MANIFEST[canonicalAction]?.recovery?.mode || ''
  const canRetryWithSameKey = !queryResultAction && recoveryMode === 'same_idempotency_retry'
  const message = canonicalAction === 'prepare-recharge-checkout'
    ? '收款准备未完成，本次尚未进入收款或扣款，请核对充值金额后重试。'
    : canRetryWithSameKey
      ? '操作结果未知，请回到原操作重试；系统必须沿用原内容和原请求标识，不能新建请求。'
      : '操作结果未知，请查询原结果后再决定是否重试，请勿更换请求标识重复提交。'
  const unknown = {
    result: {
      status: 'result_unknown',
      code: 'COMMAND_RESULT_UNKNOWN',
      message,
      detail: detailMessage || ''
    },
    action: canonicalAction,
    canonicalAction,
    idempotencyKey: idempotencyKey || '',
    queryResultAction,
    canClose: false,
    canRetry: canRetryWithSameKey,
    retryIdempotencyMode: 'same'
  }
  if (!silent) {
    window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
      detail: {
        status: 'result_unknown',
        code: 'COMMAND_RESULT_UNKNOWN',
        action: canonicalAction,
        canonicalAction,
        message,
        feedback: null,
        navigation: null,
        overlay: null,
        latestState: null,
        conflict: null,
        canClose: false,
        canRetry: canRetryWithSameKey,
        retryIdempotencyMode: 'same',
        queryResultAction,
        idempotencyKey: idempotencyKey || ''
      }
    }))
  }
  return unknown
}

function finalizeCashierV3AdapterResult(result, options = {}) {
  const {
    silent = false,
    readOnly = false,
    requestMeta = {}
  } = options
  if (!readOnly && !isTrustedV3Envelope(result)) {
    return emitCommandResultUnknown('', '', silent, '损坏或不可信的响应')
  }
  if (!silent) {
    const emission = emitCashierV3UiResult(result || {}, requestMeta)
    if (!emission.accepted) {
      if (emission.resultUnknown || emission.status === 'result_unknown') {
        return emitCommandResultUnknown(
          requestMeta.expectedIdempotencyKey || '',
          requestMeta.canonicalAction || '',
          silent,
          emission.bindingCode || emission.code
        )
      }
      const businessStatus = String(unwrapCashierV3Response(result)?.result?.status
        || unwrapCashierV3Response(result)?.status
        || '')
      if (emission.stateIgnored && ['success', 'conflict'].includes(businessStatus)) {
        return buildStateIgnoredResult(result, emission)
      }
      return {
        result: {
          status: 'failed',
          code: emission.code,
          message: emission.message
        },
        ignoredRootState: true,
        stale: emission.stale === true
      }
    }
  } else {
    const envelopeOutcome = applyCashierV3EnvelopeState(result || {}, requestMeta, { emitUi: false })
    if (!envelopeOutcome.accepted) {
      if (envelopeOutcome.resultUnknown || envelopeOutcome.status === 'result_unknown') {
        return emitCommandResultUnknown(
          requestMeta.expectedIdempotencyKey || '',
          requestMeta.canonicalAction || '',
          silent,
          envelopeOutcome.bindingCode || envelopeOutcome.code
        )
      }
      if (envelopeOutcome.stateIgnored && ['success', 'conflict'].includes(envelopeOutcome.status)) {
        return buildStateIgnoredResult(result, envelopeOutcome)
      }
      return {
        result: {
          status: 'failed',
          code: envelopeOutcome.code,
          message: envelopeOutcome.message
        },
        ignoredRootState: true,
        stale: envelopeOutcome.stale === true
      }
    }
  }
  return result || {}
}

/**
 * context switch 唯一生产入口：生成 epoch + 强随机 token，绑定到本次 bootstrap／投影请求。
 */
export async function requestCashierV3ContextSwitch(payload = {}) {
  const {
    action,
    silent,
    contextSwitchToken: ignoredToken,
    contextSwitchEpoch: ignoredEpoch,
    clientSessionId: ignoredClientSessionId,
    ...requestBody
  } = payload
  const intent = beginCashierV3ContextSwitch()
  const correlationId = `CORR-${fallbackUuidV4()}`
  const clientSessionId = getClientSessionId()
  const switchPayload = {
    ...requestBody,
    silent,
    clientSessionId,
    contextSwitchEpoch: intent.epoch,
    contextSwitchToken: intent.token,
    correlationId
  }
  const adapter = typeof window !== 'undefined' ? window.__CASHIER_V3_ADAPTER__ : null
  // context switch 只走 adapter.bootstrap，禁止把任意动态 action 字面量直通 requestCashierV3Action
  if (!adapter || typeof adapter.bootstrap !== 'function') {
    const missingBootstrap = {
      result: {
        status: 'failed',
        code: 'CONTEXT_SWITCH_ADAPTER_REQUIRED',
        message: '工作台切换需要 adapter.bootstrap，已拒绝发送。'
      }
    }
    if (!silent) emitCashierV3UiResult(missingBootstrap, switchPayload)
    return missingBootstrap
  }
  try {
    const result = await adapter.bootstrap({
      ...switchPayload,
      ...(action ? { action } : {})
    })
    return finalizeCashierV3AdapterResult(result, {
      silent,
      readOnly: true,
      requestMeta: {
        contextSwitchEpoch: intent.epoch,
        contextSwitchToken: intent.token,
        allowContextSwitch: true,
        expectedAction: action || 'open-cashier-workbench',
        canonicalAction: action || 'open-cashier-workbench',
        actionType: 'projection',
        correlationId,
        requireCorrelationBinding: true,
        requireStateContextBinding: true,
        clientSessionId
      }
    })
  } catch (error) {
    const failed = {
      result: {
        status: 'failed',
        code: 'CLIENT_REQUEST_FAILED',
        message: error?.message || '工作台切换请求未完成，请稍后重试。'
      }
    }
    if (!silent) emitCashierV3UiResult(failed, switchPayload)
    return failed
  }
}

/**
 * 供契约核对测试读取：客户端实际允许提交的 action 集合。
 * 服务端 manifest 减去本集合必须为空，反之亦然。
 */
export function cashierV3ClientManifest() {
  return CASHIER_V3_ACTION_MANIFEST
}

export function formatMoney(value) {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '¥0'
  if (Number.isInteger(amount)) return `¥${amount.toLocaleString('zh-CN')}`
  return `¥${amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
