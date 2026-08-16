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
const FEATURE_WRITEOFF = 'cashier.v3.writeoff'
const FEATURE_ROOM = 'cashier.v3.room'
const FEATURE_RESERVATION = 'cashier.v3.reservation'
const FEATURE_MEMBER = 'cashier.v3.member'
// 本次改造只落功能入口权限；会员新增属于会员入口内的操作，不能假造
// 一个当前菜单系统不存在的细粒度 unique_auth。
const FEATURE_MEMBER_CREATE = 'cashier.v3.member'
const FEATURE_MEMBER_BATCH = 'cashier.v3.member.batch'
const FEATURE_HANG = 'cashier.v3.hang'
const FEATURE_ORDER_CENTER = 'cashier.v3.order_center'
const FEATURE_MANAGEMENT = 'cashier.v3.management_center'

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
 * 三个「去结账」页面动作 → 规范写命令。
 *
 * 它们会创建或复用 JZ 结账请求，不是纯展示，因此不能按 projection 剥离 command。
 * Vue 页面本轮不改，别名映射全部落在桥接层。
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
    'submit-card-operation': FEATURE_CASHIER,
    'select-cashier-member': FEATURE_CASHIER,
    'set-guest-order': FEATURE_CASHIER,
    'change-supplement-date': FEATURE_CASHIER,
    'exit-supplement': FEATURE_CASHIER,
    'prepare-checkout': FEATURE_CASHIER,
    'prepare-debt-repayment': FEATURE_CASHIER,
    'checkout-step-back': FEATURE_CASHIER,
    'checkout-step-next': FEATURE_CASHIER,
    'toggle-combination-payment': FEATURE_CASHIER,
    'add-payment-method': FEATURE_CASHIER,
    'update-payment-line': FEATURE_CASHIER,
    'remove-payment-line': FEATURE_CASHIER,
    // 来源选择会写入结账草稿；漏登记会被桥接层 fail-closed 拦截，导致来源快照为空。
    'update-checkout-business-source': FEATURE_CASHIER,
    'update-checkout-sales-date': FEATURE_CASHIER,
    // 余额按钮在页面层会映射为草稿写命令；实际扣款仍只发生在正式结账事务。
    'apply-balance-payment': FEATURE_CASHIER,
    'remove-balance-payment': FEATURE_CASHIER,
    'update-balance-payment': FEATURE_CASHIER,
    'prepare-checkout-submission': FEATURE_CASHIER,
    'confirm-debt-warning': FEATURE_CASHIER,
    'confirm-checkout-final-changes': FEATURE_CASHIER,
    'submit-checkout': FEATURE_CASHIER,
    'submit-debt-repayment': FEATURE_CASHIER,
    'return-to-payment-edit': FEATURE_CASHIER,
    'retry-checkout': FEATURE_CASHIER,
    'continue-partial-payment-recovery': FEATURE_CASHIER,
    'go-to-writeoff-after-checkout': FEATURE_CASHIER,
    'finish-checkout-and-return': FEATURE_CASHIER
  }),
  ...entries(C2, ACTION_TYPE_PROJECTION, {
    'open-line-assignment': FEATURE_CASHIER,
    'open-line-coupon': FEATURE_CASHIER,
    'open-local-line-coupon': FEATURE_CASHIER,
    'open-line-debt': FEATURE_CASHIER,
    'open-price-change': FEATURE_CASHIER,
    'open-card-upgrade': FEATURE_CASHIER,
    'open-project-upgrade': FEATURE_CASHIER,
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
  'open-business-dashboard-detail': FEATURE_MANAGEMENT,
  'export-business-dashboard': FEATURE_MANAGEMENT
})

const C5_ACTIONS = {
  ...entries(C5, ACTION_TYPE_COMMAND, {
    'create-member': FEATURE_MEMBER_CREATE,
    'update-member': FEATURE_MEMBER,
    'deactivate-member': FEATURE_MEMBER,
    'submit-recharge': FEATURE_MEMBER,
    'prepare-recharge-checkout': FEATURE_MEMBER,
    'prepare-recharge-debt-repayment': FEATURE_MEMBER,
    'add-recharge-checkout-payment-method': FEATURE_MEMBER,
    'update-recharge-checkout-payment-line': FEATURE_MEMBER,
    'remove-recharge-checkout-payment-line': FEATURE_MEMBER,
    'update-recharge-checkout-business-source': FEATURE_MEMBER,
    'update-recharge-checkout-business-date': FEATURE_MEMBER,
    'reload-recharge-checkout': FEATURE_MEMBER,
    'submit-recharge-checkout': FEATURE_MEMBER,
    'submit-recharge-debt-repayment': FEATURE_MEMBER,
    'submit-direct-gift': FEATURE_MEMBER,
    'adjust-sales-order-personnel': FEATURE_ORDER_CENTER,
    'refund-sales-order': FEATURE_ORDER_CENTER,
    'void-sales-order': FEATURE_ORDER_CENTER,
    'refund-recharge-order': FEATURE_ORDER_CENTER,
    'void-recharge-order': FEATURE_ORDER_CENTER,
    'reopen-sales-order': FEATURE_ORDER_CENTER,
    'upgrade-sales-order': FEATURE_ORDER_CENTER,
    'print-sales-order-receipt': FEATURE_ORDER_CENTER,
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
    'open-member-selector': FEATURE_CASHIER,
    'open-member-detail': FEATURE_MEMBER,
    'load-member-detail-tab': FEATURE_MEMBER,
    'open-member-more-actions': FEATURE_MEMBER,
    'open-member-editor': FEATURE_MEMBER,
    'open-member-creator': FEATURE_MEMBER_CREATE,
    'open-member-batch-actions': FEATURE_MEMBER_BATCH,
    'open-recharge': FEATURE_MEMBER,
    'query-sales-orders': FEATURE_ORDER_CENTER,
    'query-order-center-records': FEATURE_ORDER_CENTER,
    'open-sales-order-detail': FEATURE_ORDER_CENTER,
    'view-sales-order': FEATURE_ORDER_CENTER,
    'open-order-operation-logs': FEATURE_ORDER_CENTER,
    'open-operation-logs': FEATURE_ORDER_CENTER,
    'open-order-debt-settlements': FEATURE_ORDER_CENTER,
    'open-sales-order-personnel-adjustment': FEATURE_ORDER_CENTER,
    'open-debt-settlements': FEATURE_ORDER_CENTER,
    'open-order-refunds': FEATURE_ORDER_CENTER,
    'open-refunds': FEATURE_ORDER_CENTER,
    'open-order-void': FEATURE_ORDER_CENTER,
    'open-void-record': FEATURE_ORDER_CENTER,
    'open-order-reopenings': FEATURE_ORDER_CENTER,
    'open-reopen-records': FEATURE_ORDER_CENTER,
    'open-order-upgrades': FEATURE_ORDER_CENTER,
    'open-upgrade-records': FEATURE_ORDER_CENTER,
    'open-order-services': FEATURE_ORDER_CENTER,
    'open-service-records': FEATURE_ORDER_CENTER,
    'open-order-writeoffs': FEATURE_ORDER_CENTER,
    'open-order-gifts': FEATURE_ORDER_CENTER,
    'open-gift-records': FEATURE_ORDER_CENTER,
    'open-gift': FEATURE_MEMBER,
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
