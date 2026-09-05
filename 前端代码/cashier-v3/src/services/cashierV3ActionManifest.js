/**
 * 收银 V3 客户端 action 清单。
 *
 * 与服务端 app/services/cashier/v3/manifest/ 下四个模块逐条一致，
 * 由自动核对测试保证（action 名、canonical、type、owner 四项全等），
 * 不靠人工比对。改动任意一侧都必须同步改另一侧，否则测试直接失败。
 *
 * 三件事只在这里定义：
 * 1. 这个 action 存不存在——不在清单里的 action，requestCashierV3Action 本地拒绝发送；
 * 2. 页面 action 的规范名——三个「去结账」在页面上仍叫 open-*，
 *    发送前映射成 prepare-*-checkout 写命令；
 * 3. 是写命令还是只读投影——决定要不要带 command 与 contexts。
 *
 * 不再按 action 前缀猜类型：open- 前缀里既有纯展示抽屉，也有会创建结账请求的
 * 「去结账」，靠前缀判断必然错一边。
 */

export const ACTION_TYPE_COMMAND = 'command'
export const ACTION_TYPE_PROJECTION = 'projection'

const C2 = 'C2'
const C3 = 'C3'
const C4 = 'C4'
const C5 = 'C5'

const FEATURE_CASHIER = 'cashier.v3.cashier'
const FEATURE_RECHARGE = 'cashier.v3.cashier.recharge'
const FEATURE_GIFT = 'cashier.v3.cashier.gift'
const FEATURE_CHECKOUT = 'cashier.v3.cashier.checkout'
const FEATURE_CARD_UPGRADE = 'cashier.v3.cashier.card.upgrade'
const FEATURE_CARD_PROJECT_UPGRADE = 'cashier.v3.cashier.card.project_upgrade'
const POLICY_CARD_OPERATION = 'policy:cashier_card_operation'
const FEATURE_WRITEOFF = 'cashier.v3.writeoff'
const FEATURE_ROOM = 'cashier.v3.room'
const FEATURE_RESERVATION = 'cashier.v3.reservation'
const FEATURE_MEMBER = 'cashier.v3.member'
// 门店端重构时已经把 action 统一登记在这份清单；第一期只替换其
// permission 锚点，页面 action 名和 canonical 不变。
const FEATURE_MEMBER_CREATE = 'cashier.v3.member.create'
const FEATURE_MEMBER_EDIT = 'cashier.v3.member.edit'
const FEATURE_MEMBER_BATCH = 'cashier.v3.member.batch'
const FEATURE_HANG = 'cashier.v3.hang'
const FEATURE_ORDER_CENTER = 'cashier.v3.order_center'
const FEATURE_MANAGEMENT = 'cashier.v3.management_center'
const FEATURE_ORDER_STAFF_ADJUST = 'cashier.v3.order.staff_adjust'
const FEATURE_ORDER_REFUND = 'cashier.v3.order.refund'
const FEATURE_ORDER_VOID = 'cashier.v3.order.void'
const FEATURE_ORDER_REOPEN = 'cashier.v3.order.reopen'
const FEATURE_ORDER_RECEIPT_PRINT = 'cashier.v3.order.receipt_print'
const FEATURE_ORDER_DEBT_VIEW = 'cashier.v3.order.debt_view'
const FEATURE_ORDER_SERVICE_DETAIL = 'cashier.v3.order.service_detail'
const FEATURE_ORDER_SERVICE_VOID = 'cashier.v3.order.service_void'

function entries(owner, type, table) {
  const out = {}
  const queryMap = {
    'submit-checkout': 'query-checkout-result',
    'retry-checkout': 'query-checkout-result',
    'submit-debt-repayment': 'query-debt-repayment-result',
    'confirm-service-completion': 'query-service-completion-result',
    'retry-service-completion': 'query-service-completion-result',
    'submit-hang-order': 'query-hang-order-result',
    'submit-writeoff': 'query-writeoff-result',
    'create-unified-query-export': 'query-unified-query-export-task'
  }
  for (const [action, permission] of Object.entries(table)) {
    let permissionPolicyId = permission
    let feature = permission
    if (typeof permission === 'string' && permission.startsWith('selector:')) {
      feature = null
      permissionPolicyId = permission
    } else if (typeof permission === 'string' && permission.startsWith('policy:')) {
      feature = null
      permissionPolicyId = permission
    } else if (typeof permission === 'string' && permission.startsWith('feature:')) {
      feature = permission.slice('feature:'.length)
      permissionPolicyId = permission
    } else {
      permissionPolicyId = `feature:${permission}`
      feature = permission
    }
    const row = { canonical: action, type, owner, permission: feature, permissionPolicyId }
    if (type === ACTION_TYPE_COMMAND) {
      row.recovery = queryMap[action]
        ? { mode: 'result_query', queryResultAction: queryMap[action] }
        : { mode: 'same_idempotency_retry' }
    }
    out[action] = row
  }
  return out
}

/**
 * 兼容其他业务入口的去结账动作映射。普通收银台不走这些别名；普通销售、权益、
 * 混合和卡操作都在浏览器中生成唯一快照，最终确认才发送 submit-checkout。
 */
export const CHECKOUT_ACTION_ALIASES = {
  'open-reservation-checkout': 'prepare-reservation-checkout',
  'open-room-service-checkout': 'prepare-room-service-checkout',
  'open-service-checkout': 'prepare-service-checkout'
}

/**
 * 四个纯准备动作：只准备抽屉／确认界面，不改变业务状态。
 * 受控 projection——不生成命令、不落回执、不推进业务资源版本。
 */
export const PREPARATION_PROJECTION_ACTIONS = [
  'prepare-service-completion',
  'prepare-reservation-service-completion',
  'prepare-room-service-completion',
  'prepare-room-assignment'
]

const C2_ACTIONS = {
  ...entries(C2, ACTION_TYPE_COMMAND, {
    'choose-catalog-item': FEATURE_CASHIER,
    'create-custom-card-configuration': FEATURE_CASHIER,
    'add-checkout-entitlement-lines': 'policy:checkout_entitlement',
    'remove-cart-line': FEATURE_CASHIER,
    'clear-cart-lines': FEATURE_CASHIER,
    'change-cart-line-quantity': FEATURE_CASHIER,
    'update-cart-line-service-settings': FEATURE_CASHIER,
    'apply-cashier-salespeople-to-all-sale-lines': FEATURE_CASHIER,
    'apply-cashier-craftsmen-to-all-service-lines': FEATURE_CASHIER,
    'apply-cashier-personnel-to-all-lines': FEATURE_CASHIER,
    'update-cashier-line-debt': FEATURE_CASHIER,
    'apply-line-coupon': FEATURE_CASHIER,
    'remove-line-coupon': FEATURE_CASHIER,
    'update-cashier-order-note': FEATURE_CASHIER,
    'update-cashier-line-price': FEATURE_CASHIER,
    'update-cashier-supplement': FEATURE_CASHIER,
    'submit-card-operation': POLICY_CARD_OPERATION,
    'select-cashier-member': FEATURE_CASHIER,
    'set-guest-order': FEATURE_CASHIER,
    'change-supplement-date': FEATURE_CASHIER,
    'exit-supplement': FEATURE_CASHIER,
    'prepare-debt-repayment': FEATURE_CHECKOUT,
    'checkout-step-back': FEATURE_CHECKOUT,
    'checkout-step-next': FEATURE_CHECKOUT,
    'toggle-combination-payment': FEATURE_CHECKOUT,
    'confirm-debt-warning': FEATURE_CHECKOUT,
    'confirm-checkout-final-changes': FEATURE_CHECKOUT,
    'submit-checkout': FEATURE_CHECKOUT,
    'submit-debt-repayment': FEATURE_CHECKOUT,
    'retry-checkout': FEATURE_CHECKOUT,
    'continue-partial-payment-recovery': FEATURE_CHECKOUT,
    'go-to-writeoff-after-checkout': FEATURE_CHECKOUT,
    'finish-checkout-and-return': FEATURE_CHECKOUT
  }),
  ...entries(C2, ACTION_TYPE_PROJECTION, {
    'open-line-assignment': FEATURE_CASHIER,
    'open-line-coupon': FEATURE_CASHIER,
    'open-local-line-coupon': FEATURE_CASHIER,
    'open-line-debt': FEATURE_CASHIER,
    'open-price-change': FEATURE_CASHIER,
    'open-card-upgrade': FEATURE_CARD_UPGRADE,
    'open-project-upgrade': FEATURE_CARD_PROJECT_UPGRADE,
    'open-order-note': FEATURE_CASHIER,
    'open-supplement': FEATURE_CASHIER,
    'open-balance-payment': FEATURE_CASHIER,
    'open-balance-payment-identity-verification': FEATURE_CASHIER,
    'open-payment-note': FEATURE_CASHIER,
    'open-checkout-source-selector': FEATURE_CASHIER,
    'open-add-service-consumption': FEATURE_CASHIER,
    'open-add-card-service-project': 'policy:checkout_entitlement',
    'query-checkout-result': FEATURE_CASHIER,
    'open-member-debt-repayment': FEATURE_CASHIER,
    'query-debt-repayment-result': FEATURE_CASHIER
  })
}

const C3_ACTIONS = {
  ...entries(C3, ACTION_TYPE_COMMAND, {
    'prepare-reservation-checkout': FEATURE_RESERVATION,
    'prepare-room-service-checkout': FEATURE_ROOM,
    'prepare-service-checkout': FEATURE_CASHIER,
    'confirm-service-completion': FEATURE_CASHIER,
    'save-service-line-completion': FEATURE_CASHIER,
    'save-service-line-craftsmen': FEATURE_CASHIER,
    'finish-service-completion': FEATURE_CASHIER,
    'retry-service-completion': FEATURE_CASHIER,
    'return-to-service-edit': FEATURE_CASHIER,
    'continue-service-checkout': FEATURE_CASHIER,
    'save-service-room-assignment': FEATURE_ROOM,
    'confirm-reservation': FEATURE_RESERVATION,
    'start-reservation-service': 'policy:reservation_operation',
    'end-reservation-service': 'policy:reservation_operation',
    'cancel-reservation': 'policy:reservation_operation',
    'reject-reservation': FEATURE_RESERVATION,
    'mark-reservation-no-show': FEATURE_RESERVATION,
    'create-reservation': 'policy:reservation_operation',
    'update-reservation': 'policy:reservation_operation',
    'select-reservation-member': 'policy:reservation_operation',
    'select-writeoff-member': FEATURE_WRITEOFF,
    'submit-hang-order': FEATURE_HANG,
    'resume-hang-order': FEATURE_HANG,
    'void-hang-order': FEATURE_HANG,
    'submit-writeoff': FEATURE_WRITEOFF,
    'toggle-writeoff-project': FEATURE_WRITEOFF,
    'change-writeoff-project-times': FEATURE_WRITEOFF,
    'change-writeoff-supplement-date': FEATURE_WRITEOFF,
    'exit-writeoff-supplement': FEATURE_WRITEOFF,
    'clear-writeoff-selection': FEATURE_WRITEOFF,
    'return-to-writeoff-edit': FEATURE_WRITEOFF,
    'start-service-from-writeoff': FEATURE_WRITEOFF
  }),
  ...entries(C3, ACTION_TYPE_PROJECTION, {
    'prepare-service-completion': FEATURE_CASHIER,
    'prepare-reservation-service-completion': FEATURE_RESERVATION,
    'prepare-room-service-completion': FEATURE_ROOM,
    'prepare-room-assignment': FEATURE_ROOM,
    'open-service-line-completion': FEATURE_CASHIER,
    'open-service-line-staff-allocation': FEATURE_CASHIER,
    'query-service-completion-result': FEATURE_CASHIER,
    'query-reservations': FEATURE_RESERVATION,
    'open-reservation-detail': FEATURE_RESERVATION,
    'open-reservation-editor': FEATURE_RESERVATION,
    'open-reservation-more-actions': FEATURE_RESERVATION,
    'open-reservation-member-selector': FEATURE_RESERVATION,
    'query-reservation-project-catalog': FEATURE_RESERVATION,
    'change-reservation-calendar-date': FEATURE_RESERVATION,
    'recalculate-reservation-plan': FEATURE_RESERVATION,
    'query-reservation-result': FEATURE_RESERVATION,
    'refresh-room-status': FEATURE_ROOM,
    'open-room-detail': FEATURE_ROOM,
    'open-room-more-actions': FEATURE_ROOM,
    'open-room-next-reservation': FEATURE_ROOM,
    'open-room-service-session': FEATURE_ROOM,
    'open-unassigned-room-list': FEATURE_ROOM,
    'prepare-empty-room-cashier': FEATURE_ROOM,
    'query-hang-orders': FEATURE_HANG,
    'open-hang-order': FEATURE_HANG,
    'open-hang-order-void-confirmation': FEATURE_HANG,
    'query-hang-order-result': FEATURE_HANG,
    'prepare-writeoff': FEATURE_WRITEOFF,
    'focus-writeoff-source': FEATURE_WRITEOFF,
    'open-writeoff-records': FEATURE_WRITEOFF,
    'open-writeoff-more-actions': FEATURE_WRITEOFF,
    'open-writeoff-member-selector': FEATURE_WRITEOFF,
    'open-writeoff-staff-allocation': FEATURE_WRITEOFF,
    'query-writeoff-result': FEATURE_WRITEOFF
  })
}

const C4_ACTIONS = entries(C4, ACTION_TYPE_PROJECTION, {
  'query-business-dashboard-summary': FEATURE_MANAGEMENT,
  'query-business-dashboard-trend': FEATURE_MANAGEMENT,
  'query-business-dashboard-ranking': FEATURE_MANAGEMENT,
  'query-store-target-dashboard': FEATURE_MANAGEMENT,
  'open-business-dashboard-detail': FEATURE_MANAGEMENT,
  'export-business-dashboard': FEATURE_MANAGEMENT
})

const C5_ACTIONS = {
  ...entries(C5, ACTION_TYPE_COMMAND, {
    'create-member': FEATURE_MEMBER_CREATE,
    'update-member': FEATURE_MEMBER_EDIT,
    'deactivate-member': FEATURE_MEMBER,
    'submit-recharge': FEATURE_RECHARGE,
    'prepare-recharge-checkout': FEATURE_RECHARGE,
    'prepare-recharge-debt-repayment': FEATURE_RECHARGE,
    'add-recharge-checkout-payment-method': FEATURE_RECHARGE,
    'update-recharge-checkout-payment-line': FEATURE_RECHARGE,
    'remove-recharge-checkout-payment-line': FEATURE_RECHARGE,
    'reload-recharge-checkout': FEATURE_RECHARGE,
    'submit-recharge-checkout': FEATURE_RECHARGE,
    'submit-recharge-debt-repayment': FEATURE_RECHARGE,
    'submit-direct-gift': FEATURE_GIFT,
    'adjust-sales-order-personnel': FEATURE_ORDER_STAFF_ADJUST,
    'adjust-recharge-personnel': FEATURE_ORDER_STAFF_ADJUST,
    'adjust-supplement-personnel': FEATURE_ORDER_STAFF_ADJUST,
    'update-sales-order-note': FEATURE_ORDER_CENTER,
    'refund-sales-order': FEATURE_ORDER_REFUND,
    'void-sales-order': FEATURE_ORDER_VOID,
    'void-service-record': FEATURE_ORDER_SERVICE_VOID,
    'adjust-service-record-craftsmen': FEATURE_ORDER_SERVICE_DETAIL,
    'refund-recharge-order': FEATURE_ORDER_REFUND,
    'void-recharge-order': FEATURE_ORDER_VOID,
    'void-order-center-supplement': FEATURE_ORDER_VOID,
    'void-order-center-gift': FEATURE_ORDER_VOID,
    'reopen-sales-order': FEATURE_ORDER_REOPEN,
    'upgrade-sales-order': FEATURE_ORDER_CENTER,
    'print-sales-order-receipt': FEATURE_ORDER_RECEIPT_PRINT,
    'save-reservation-query-settings': FEATURE_RESERVATION,
    'save-hang-order-query-settings': FEATURE_HANG,
    'save-order-center-query-settings': FEATURE_ORDER_CENTER,
    'save-member-query-settings': 'policy:unified_query_page',
    'save-unified-query-settings': 'policy:unified_query_page',
    'save-unified-query-field-aliases': 'policy:unified_query_page',
    'save-unified-query-custom-field': 'policy:unified_query_page',
    'change-unified-query-custom-field-status': 'policy:unified_query_page',
    'archive-unified-query-custom-field': 'policy:unified_query_page',
    'upgrade-unified-query-field-reference': 'policy:unified_query_page',
    'create-unified-query-export': 'policy:unified_query_page'
  }),
  ...entries(C5, ACTION_TYPE_PROJECTION, {
    'query-members': FEATURE_MEMBER,
    'query-unified-query-capabilities': 'policy:unified_query_page',
    'query-unified-query-export-task': 'policy:unified_query_page',
    'query-member-selector': 'selector:member',
    'query-cashier-member-summary': FEATURE_CASHIER,
    'open-member-selector': FEATURE_CASHIER,
    'open-member-detail': FEATURE_MEMBER,
    'load-member-detail-tab': FEATURE_MEMBER,
    'open-member-more-actions': FEATURE_MEMBER,
    'open-member-editor': FEATURE_MEMBER_EDIT,
    'open-member-creator': FEATURE_MEMBER_CREATE,
    'open-member-batch-actions': FEATURE_MEMBER_BATCH,
    'open-recharge': FEATURE_RECHARGE,
    'query-sales-orders': FEATURE_ORDER_CENTER,
    'query-order-center-records': FEATURE_ORDER_CENTER,
    'open-recharge-personnel-adjustment': FEATURE_ORDER_STAFF_ADJUST,
    'open-supplement-personnel-adjustment': FEATURE_ORDER_STAFF_ADJUST,
    'open-sales-order-detail': FEATURE_ORDER_CENTER,
    'view-sales-order': FEATURE_ORDER_CENTER,
    'open-order-operation-logs': FEATURE_ORDER_CENTER,
    'open-operation-logs': FEATURE_ORDER_CENTER,
    'open-order-debt-settlements': FEATURE_ORDER_DEBT_VIEW,
    'open-sales-order-personnel-adjustment': FEATURE_ORDER_STAFF_ADJUST,
    'open-debt-settlements': FEATURE_ORDER_DEBT_VIEW,
    'open-order-refunds': FEATURE_ORDER_REFUND,
    'open-refunds': FEATURE_ORDER_REFUND,
    'open-order-void': FEATURE_ORDER_VOID,
    'open-void-record': FEATURE_ORDER_VOID,
    'open-order-reopenings': FEATURE_ORDER_REOPEN,
    'open-reopen-records': FEATURE_ORDER_REOPEN,
    'open-order-upgrades': FEATURE_ORDER_CENTER,
    'open-upgrade-records': FEATURE_ORDER_CENTER,
    'open-order-services': FEATURE_ORDER_SERVICE_DETAIL,
    'open-service-records': FEATURE_ORDER_SERVICE_DETAIL,
    'open-service-record-craftsman-adjustment': FEATURE_ORDER_SERVICE_DETAIL,
    'open-order-writeoffs': FEATURE_ORDER_CENTER,
    'open-order-gifts': FEATURE_ORDER_CENTER,
    'open-gift-records': FEATURE_ORDER_CENTER,
    'open-gift': FEATURE_GIFT,
    'open-card-batch': FEATURE_MEMBER,
    'open-card-benefits': FEATURE_MEMBER,
    'query-query-entities': 'selector:query_entities',
    'query-staff': FEATURE_MANAGEMENT,
    'open-management-entry': FEATURE_MANAGEMENT
  })
}

const C1 = 'C1'

const C1_ACTIONS = {
  ...entries(C1, ACTION_TYPE_PROJECTION, {
    // 根投影只建立已登录门店端会话，不等同于进入收银。实际页面和动作仍
    // 各自按功能码校验，避免“仅会员”账号因无法初始化而被错误拒绝登录。
    'open-cashier-workbench': 'policy:store_v3_session'
  })
}

function buildManifest() {
  const merged = { ...C1_ACTIONS, ...C2_ACTIONS, ...C3_ACTIONS, ...C4_ACTIONS, ...C5_ACTIONS }
  for (const [pageAction, canonical] of Object.entries(CHECKOUT_ACTION_ALIASES)) {
    const target = merged[canonical]
    if (!target) throw new Error(`别名 ${pageAction} 指向未登记的规范 action ${canonical}`)
    merged[pageAction] = { ...target, canonical, aliasOf: canonical }
  }
  return Object.freeze(merged)
}

export const CASHIER_V3_ACTION_MANIFEST = buildManifest()

/**
 * 组件内部的 UI 键，不是可发送的 action。
 *
 * 它们在视图层先被 actionMap 翻译成上面清单里的 action，或者只驱动本地
 * 界面变化（例如 edit-reservation 只打开本地编辑器，不发请求）。
 * 显式列出来，静态核对脚本才能把「未登记的真实 action」和「本来就不是
 * action 的 UI 键」区分开，而不是靠猜。
 */
export const CASHIER_V3_UI_ONLY_KEYS = Object.freeze({
  'assign-room': '房间详情按钮键，映射为 prepare-room-assignment',
  'change-room': '房间详情按钮键，映射为 prepare-room-assignment',
  'remove-room': '房间详情按钮键，映射为 prepare-room-assignment',
  'end-service': '预约详情按钮键，映射为 end-reservation-service',
  'go-checkout': '房间／预约详情按钮键，映射为 open-*-checkout 后再走别名',
  'start-service': '预约详情按钮键，映射为 start-reservation-service',
  'view-service-order': '房间详情按钮键，映射为 open-room-service-session',
  'mark-no-show': '预约详情按钮键，映射为 mark-reservation-no-show',
  'edit-reservation': '仅在本地打开预约编辑器，不发送请求',
  'go-writeoff': '会员卡片的组件事件名，不是 action',
  'open-detail': '会员卡片的组件事件名，不是 action',
  'card-reservation': '桥接层内的卡项来源分类键',
  'new-purchase': '桥接层内的购买来源分类键',
  'current-order': '购物车分组键',
  'current-service-order': '购物车分组键',
  'ungrouped-current-sale': '购物车分组键',
  'management-center': '导航目标键',
  'pending-checkout': '房间状态键',
  'service-confirmation-lines': '服务确认组件内的分组键'
})

export function hasCashierV3Action(action) {
  return typeof action === 'string' && Object.prototype.hasOwnProperty.call(CASHIER_V3_ACTION_MANIFEST, action)
}

export function cashierV3ActionDefinition(action) {
  return hasCashierV3Action(action) ? CASHIER_V3_ACTION_MANIFEST[action] : null
}

/** 页面 action → 规范 action；未登记时原样返回，由调用方 fail-closed */
export function canonicalCashierV3Action(action) {
  const definition = cashierV3ActionDefinition(action)
  return definition ? definition.canonical : action
}

export function isCashierV3CommandAction(action) {
  const definition = cashierV3ActionDefinition(action)
  return Boolean(definition) && definition.type === ACTION_TYPE_COMMAND
}

export function isCashierV3ProjectionAction(action) {
  const definition = cashierV3ActionDefinition(action)
  return Boolean(definition) && definition.type === ACTION_TYPE_PROJECTION
}
