<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BusinessRecordDetailOverlay from '@/components/order/BusinessRecordDetailOverlay.vue'
import SalesOrderDetailOverlay from '@/components/order/SalesOrderDetailOverlay.vue'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import {
  createCashierV3CommandId,
  formatMoney,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'
import {
  isUnavailableSalesOrderEconomics,
  mergeSalesOrderCenterProjection,
  nextSalesOrderQueryWithCursor,
  salesOrderPaginationFromPartition,
  shouldApplySalesOrderDetailResponse,
  salesOrderProjectionFromResult
} from '@/services/cashierV3OrderProjectionContract'

const field = (key, label, type = 'text', extra = {}) => ({ key, label, type, defaultVisible: true, ...extra })

const ORDER_TABS = [
  {
    key: 'sales',
    label: '销售订单',
    stateKey: 'salesOrders',
    primaryField: 'sales_order_no',
    searchPlaceholder: '搜索销售订单号、会员姓名、手机号或商品',
    emptyText: '暂无正式销售订单。完成结账后可在这里查询订单详情。',
    fields: [
      field('sales_order_no', '销售订单号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('member_name', '会员姓名／游客'), field('phone', '手机号'),
      field('store', '销售门店', 'store'), field('item_summary', '商品摘要'),
      field('item_count', '商品数量', 'number'), field('receivable_amount', '应收金额', 'money'),
      field('discount_amount', '优惠金额', 'money'), field('debt_amount', '欠款金额', 'money'),
      field('actual_received_amount', '现金业绩', 'money'), field('payment_method', '收款方式'),
      field('salesperson', '销售人', 'person'), field('cashier', '收银员／操作人', 'person'),
      field('source_primary', '一级来源'), field('source_secondary', '二级来源'),
      field('payment_status', '支付状态', 'status'), field('order_status', '订单状态', 'status'),
      field('supplement', '补单标记'), field('payment_completed_at', '支付完成时间', 'date')
    ]
  },
  {
    key: 'recharge',
    label: '充值订单',
    stateKey: 'rechargeOrders',
    primaryField: 'recharge_order_no',
    searchPlaceholder: '搜索充值订单号、会员姓名或手机号',
    emptyText: '暂无充值订单。',
    fields: [
      field('recharge_order_no', '充值订单号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('member_name', '会员姓名'), field('phone', '手机号'), field('store', '办理门店', 'store'),
      field('recharge_plan', '充值方案'), field('recharge_amount', '充值金额', 'money'),
      field('gift_amount', '赠送金额', 'money'), field('actual_received_amount', '现金业绩', 'money'),
      field('payment_method', '收款方式'), field('salesperson', '销售人', 'person'),
      field('operator', '操作人', 'person'), field('payment_status', '支付状态', 'status'),
      field('order_status', '订单状态', 'status'), field('payment_completed_at', '支付完成时间', 'date')
    ]
  },
  {
    key: 'refund',
    label: '退货订单',
    stateKey: 'refundOrders',
    primaryField: 'refund_order_no',
    searchPlaceholder: '搜索退货单号、来源订单、会员姓名或手机号',
    emptyText: '暂无退货订单。',
    fields: [
      field('refund_order_no', '退货单号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('source_order_no', '来源订单号'), field('member_name', '会员姓名／游客'),
      field('phone', '手机号'), field('refund_summary', '退货内容'), field('refund_amount', '退款金额', 'money'),
      field('refund_method', '退款方式'), field('store', '办理门店', 'store'),
      field('operator', '操作人', 'person'), field('refund_status', '退货状态', 'status'),
      field('refund_completed_at', '退款完成时间', 'date')
    ]
  },
  {
    key: 'debt',
    label: '欠款管理',
    stateKey: 'debtRecords',
    primaryField: 'debt_no',
    searchPlaceholder: '搜索欠款编号、来源订单、会员姓名或手机号',
    emptyText: '暂无欠款记录。欠款以权威欠款事实为准。',
    fields: [
      field('debt_no', '欠款编号', 'text', { defaultQuick: true }),
      field('member_name', '客户'), field('phone', '手机号'),
      field('source_type', '欠款来源'), field('source_order_no', '来源订单'),
      field('original_debt_amount', '原欠款', 'money'), field('repaid_amount', '已还', 'money'),
      field('remaining_amount', '剩余', 'money'), field('debt_status', '状态', 'status'),
      field('store', '欠款门店', 'store'), field('created_at', '创建时间', 'date')
    ]
  },
  {
    key: 'service',
    label: '服务记录',
    stateKey: 'serviceRecords',
    primaryField: 'service_record_no',
    searchPlaceholder: '搜索服务记录号、会员、项目、权益来源、卡号或手艺人',
    emptyText: '暂无真实完成的服务记录。未完成、已取消和已作废服务不会显示在这里。',
    fields: [
      field('service_record_no', '服务记录号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('member_name', '会员姓名'), field('service_project', '服务项目'),
      field('entitlement_source', '权益来源'), field('source_card', '来源卡名称'),
      field('source_card_no', '完整卡号'), field('used_times', '本次使用次数', 'number'),
      field('store', '服务门店', 'store'), field('craftsman', '手艺人', 'person'),
      field('labor_performance_amount', '劳动业绩', 'money'), field('operator', '操作人', 'person'),
      field('service_status', '状态', 'status'), field('service_completed_at', '服务完成时间', 'date')
    ]
  },
  {
    key: 'supplement',
    label: '补交记录',
    stateKey: 'supplementOrders',
    primaryField: 'supplement_order_no',
    searchPlaceholder: '搜索补交单号、欠款编号、来源订单或会员',
    emptyText: '暂无已完成的补交记录。会员未补交的欠款请从总欠款入口查看。',
    fields: [
      field('supplement_order_no', '补交单号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('debt_no', '欠款编号'), field('source_order_no', '来源订单号'),
      field('member_name', '会员姓名'), field('phone', '手机号'), field('debt_summary', '欠款摘要'),
      field('supplement_amount', '补交金额', 'money'), field('payment_method', '收款方式'),
      field('store', '补交门店', 'store'), field('operator', '操作人', 'person'),
      field('payment_status', '支付状态', 'status'), field('payment_completed_at', '支付完成时间', 'date')
    ]
  },
  {
    key: 'gift',
    label: '赠送记录',
    stateKey: 'giftRecords',
    primaryField: 'gift_record_no',
    searchPlaceholder: '搜索赠送记录号、来源、会员或赠送内容',
    emptyText: '暂无赠送记录。',
    fields: [
      field('gift_record_no', '赠送记录号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('member_name', '会员姓名'), field('gift_source', '赠送来源'),
      field('gift_type', '赠送类型'), field('gift_content', '赠送内容'), field('gift_quantity', '赠送数量', 'number'),
      field('effective_at', '生效时间', 'date'), field('expires_at', '到期时间', 'date'),
      field('gift_status', '赠送状态', 'status'), field('store', '办理门店', 'store'),
      field('operator', '操作人', 'person'), field('gift_reason', '赠送原因'), field('created_at', '创建时间', 'date')
    ]
  },
  {
    key: 'card_operation',
    label: '卡操作记录',
    stateKey: 'cardOperationRecords',
    primaryField: 'card_operation_no',
    searchPlaceholder: '搜索操作单号、会员、卡项、项目或操作人',
    emptyText: '暂无卡升级、停用、启用、延期、项目替换或项目升级记录。',
    fields: [
      field('card_operation_no', '操作单号', 'text', { defaultQuick: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true }),
      field('member_name', '会员姓名'), field('operation_type', '操作类型', 'status', { defaultQuick: true }),
      field('source_card', '原卡项／原项目'), field('target_content', '目标卡项／目标项目'),
      field('operation_amount', '补差／操作金额', 'money'), field('sales_order_no', '关联销售订单号'),
      field('store', '办理门店', 'store'), field('operator', '操作人', 'person'),
      field('record_status', '记录状态', 'status'), field('operation_reason', '操作原因'),
      field('completed_at', '完成时间', 'date')
    ]
  }
]

const FIELD_ALIASES = {
  sales_order_no: ['salesOrderNo', 'orderNo'], business_date: ['businessDate'], member_name: ['memberName'],
  store: ['storeName', 'businessStoreName'], item_summary: ['itemSummary'], item_count: ['itemCount'],
  receivable_amount: ['receivableAmount', 'payableAmount'], discount_amount: ['discountAmount'],
  debt_amount: ['debtAmount', 'outstandingDebtAmount'], actual_received_amount: ['actualReceivedAmount'],
  payment_method: ['paymentSummary', 'paymentMethod'], salesperson: ['salespersonSummary', 'salespersonName'],
  cashier: ['cashierName', 'operatorName'], source_primary: ['sourcePrimary'], source_secondary: ['sourceSecondary'],
  payment_status: ['paymentStatus'], order_status: ['orderStatus', 'statusLabel'], supplement: ['supplementLabel', 'isSupplement'],
  payment_completed_at: ['paymentCompletedAt', 'completedAt'], recharge_order_no: ['rechargeOrderNo', 'orderNo'],
  recharge_plan: ['rechargePlan', 'planName'], recharge_amount: ['rechargeAmount'], gift_amount: ['giftAmount'],
  operator: ['operatorName', 'operator'], supplement_order_no: ['supplementOrderNo', 'repayNo', 'orderNo'],
  debt_no: ['debtNo'], source_order_no: ['sourceOrderNo'], debt_summary: ['debtSummary', 'summary'],
  supplement_amount: ['supplementAmount', 'repayAmount'], refund_order_no: ['refundOrderNo', 'refundNo'],
  refund_summary: ['refundSummary', 'summary'], refund_amount: ['refundAmount'], refund_method: ['refundMethod'],
  refund_status: ['refundStatus', 'statusLabel'], refund_completed_at: ['refundCompletedAt', 'completedAt'],
  record_status: ['recordStatus', 'statusLabel', 'status'],
  completed_at: ['completedAt'], gift_record_no: ['giftRecordNo', 'giftNo'], gift_source: ['giftSource'],
  gift_type: ['giftType', 'typeLabel'], gift_content: ['giftContent', 'contentSummary'], gift_quantity: ['giftQuantity', 'quantity'],
  effective_at: ['effectiveAt'], expires_at: ['expiresAt'], gift_status: ['giftStatus', 'statusLabel'],
  gift_reason: ['giftReason', 'reason'], created_at: ['createdAt'],
  card_operation_no: ['cardOperationNo', 'operationNo'], operation_type: ['operationTypeLabel', 'operationType'],
  source_card: ['sourceCard', 'sourceCardName'], target_content: ['targetContent', 'targetCardName', 'targetProjectName'],
  operation_amount: ['operationAmount', 'amount'], operation_reason: ['operationReason', 'reason'],
  debt_no: ['debtNo'], source_type: ['sourceType'], original_debt_amount: ['originalDebtAmount', 'totalDebtAmount'],
  repaid_amount: ['repaidAmount'], remaining_amount: ['remainingAmount'], debt_status: ['debtStatus', 'statusLabel'],
  service_record_no: ['serviceRecordNo', 'serviceFactId'], service_project: ['serviceProject', 'projectName'],
  entitlement_source: ['entitlementSource'], source_card_no: ['sourceCardNo', 'sourceCode'],
  used_times: ['usedTimes', 'quantity'], craftsman: ['craftsmenSummary', 'craftsmen'],
  labor_performance_amount: ['laborPerformanceAmount'], service_status: ['serviceStatus'],
  service_completed_at: ['serviceCompletedAt', 'completedAt']
}

const state = useCashierV3State()
const router = useRouter()
const orderCenter = computed(() => state.orderCenter || {})
const activeTabKey = ref('sales')
const availableTabs = computed(() => {
  const advertised = Array.isArray(orderCenter.value.businessTypes) ? orderCenter.value.businessTypes : []
  if (!advertised.length) return ORDER_TABS.filter((tab) => tab.key === 'sales')
  const readyKeys = new Set()
  advertised.forEach((item) => {
    if (item && typeof item === 'object' && item.ready === false) return
    const key = typeof item === 'string' ? item : (item?.key || item?.label || '')
    const matched = ORDER_TABS.find((tab) => tab.key === key || tab.label === key)
    if (matched) readyKeys.add(matched.key)
  })
  const tabs = ORDER_TABS.filter((tab) => readyKeys.has(tab.key))
  return tabs.length ? tabs : ORDER_TABS.filter((tab) => tab.key === 'sales')
})
const activeTab = computed(() => availableTabs.value.find((tab) => tab.key === activeTabKey.value) || availableTabs.value[0])
const querySettings = ref({})
const queryModelByType = ref({})
const salesPageCursors = ref({})
const isSalesDetailOpen = ref(false)
const salesDetailOrder = ref({})
const isSalesDetailLoading = ref(false)
const genericDetailRecord = ref(null)
const salesOrderActionIds = ref({})
const rechargeOrderActionIds = ref({})
let salesQuerySequence = 0
let salesDetailSequence = 0
let recordQuerySequence = 0

const records = computed(() => {
  const explicit = orderCenter.value.recordsByType?.[activeTabKey.value]
  if (Array.isArray(explicit)) return explicit
  const legacy = orderCenter.value[activeTab.value.stateKey]
  return Array.isArray(legacy) ? legacy : []
})
const tabCount = (key) => Math.max(0, Number(orderCenter.value.countsByType?.[key]) || 0)
const pageMeta = computed(() => orderCenter.value.pagesByType?.[activeTabKey.value] || {})
const total = computed(() => Math.max(Number(pageMeta.value.total ?? (activeTabKey.value === 'sales' ? orderCenter.value.total : 0)) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(pageMeta.value.page ?? (activeTabKey.value === 'sales' ? orderCenter.value.page : 1)) || 1))
const pageSize = computed(() => Math.max(1, Number(pageMeta.value.pageSize ?? (activeTabKey.value === 'sales' ? orderCenter.value.pageSize : 20)) || 20))
const hasMore = computed(() => activeTabKey.value === 'sales' && (
  pageMeta.value.hasMore === true
  || (pageMeta.value.hasMore === undefined && orderCenter.value.hasMore === true)
))
const statusOptions = computed(() => {
  const options = orderCenter.value.statusOptionsByType?.[activeTabKey.value]
  return Array.isArray(options) ? options : Array.isArray(orderCenter.value.statusOptions) ? orderCenter.value.statusOptions : []
})
const queryFields = computed(() => activeTab.value.fields)
const visibleFields = computed(() => {
  const available = new Map(queryFields.value.map((item) => [item.key, item]))
  const configured = querySettings.value?.visibleFields
  const source = Array.isArray(configured)
    ? configured
    : queryFields.value.filter((item) => item.defaultVisible !== false).map((item) => item.key)
  const seen = new Set()
  return source.reduce((items, key) => {
    if (seen.has(key) || !available.has(key)) return items
    seen.add(key)
    items.push(available.get(key))
    return items
  }, [])
})

const allowedSalesOrderDetailActions = new Set([
  'print-sales-order-receipt', 'open-card-batch', 'open-card-benefits', 'open-order-debt-settlements',
  'open-order-refunds', 'open-order-void', 'open-order-reopenings', 'open-order-upgrades', 'open-order-gifts',
  'open-order-services', 'open-order-writeoffs', 'open-order-operation-logs', 'refund-sales-order',
  'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order', 'open-sales-order-personnel-adjustment',
  'adjust-sales-order-personnel'
])
const salesOrderDetailCommandActions = new Set([
  'print-sales-order-receipt', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order',
  'adjust-sales-order-personnel'
])

watch(
  () => [activeTabKey.value, orderCenter.value.querySettingsByType, orderCenter.value.querySettings],
  () => {
    const settings = orderCenter.value.querySettingsByType?.[activeTabKey.value]
      || (activeTabKey.value === 'sales' ? orderCenter.value.querySettings : null)
    querySettings.value = settings && typeof settings === 'object' ? { ...settings } : {}
  },
  { immediate: true, deep: true }
)

watch(availableTabs, (tabs) => {
  if (!tabs.some((tab) => tab.key === activeTabKey.value)) {
    activeTabKey.value = tabs[0]?.key || 'sales'
  }
}, { immediate: true })

watch(
  () => [
    pageMeta.value.page,
    pageMeta.value.paginationCursor?.current,
    pageMeta.value.paginationCursor?.next
  ],
  () => {
    if (activeTabKey.value !== 'sales') return
    const pagination = salesOrderPaginationFromPartition(orderCenter.value)
    const next = { ...salesPageCursors.value }
    if (pagination.current) next[pagination.page] = pagination.current
    if (pagination.next) next[pagination.page + 1] = pagination.next
    salesPageCursors.value = next
  },
  { immediate: true }
)

function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

function firstValue(record, keys) {
  for (const key of keys) {
    const value = record?.[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return undefined
}

function recordFieldValue(record, key) {
  if (key === 'supplement') return record?.isSupplement === true ? '补单' : (record?.isSupplement === false ? '正常办理' : firstValue(record, FIELD_ALIASES[key] || [key]))
  return firstValue(record, [...(FIELD_ALIASES[key] || []), key])
}

function displayRecordField(record, key) {
  const value = recordFieldValue(record, key)
  if (key === 'member_name') return value || (activeTabKey.value === 'sales' || activeTabKey.value === 'refund' ? '游客' : '—')
  if (key === 'payment_method') {
    if (activeTabKey.value === 'sales' && isUnavailableSalesOrderEconomics(record, value)) return '—'
    return value || '无需收款'
  }
  return value === undefined || value === null || value === '' ? '—' : value
}

function displayMoneyField(record, key) {
  const value = recordFieldValue(record, key)
  if ((activeTabKey.value === 'sales' || key === 'actual_received_amount')
    && isUnavailableSalesOrderEconomics(record, value)) return '—'
  return formatMoney(value)
}

function statusClass(value) {
  const status = String(value || '')
  if (/作废|取消|失败|拒绝/.test(status)) return 'order-status--voided'
  if (/欠款|待补|部分/.test(status)) return 'order-status--debt'
  if (/成功|完成|正常|有效|已支付|已补清/.test(status)) return 'order-status--paid'
  return 'order-status--neutral'
}

function tableCellClass(record, fieldItem) {
  const value = recordFieldValue(record, fieldItem.key)
  return {
    'order-center-list-table__summary': ['item_summary', 'debt_summary', 'refund_summary', 'gift_content'].includes(fieldItem.key),
    'order-center-list-table__money': fieldItem.type === 'money',
    'order-center-list-table__money--debt': fieldItem.key === 'debt_amount' && Number(value) > 0
  }
}

function recordKey(record, index) {
  return record?.id || record?.recordId || recordFieldValue(record, activeTab.value.primaryField) || `${activeTabKey.value}-${index}`
}

function applyQuerySettings(settings = {}) {
  querySettings.value = settings && typeof settings === 'object' ? { ...settings } : {}
}

function switchTab(tab) {
  if (!tab || tab.key === activeTabKey.value) return
  if (!availableTabs.value.some((available) => available.key === tab.key)) return
  activeTabKey.value = tab.key
  genericDetailRecord.value = null
  closeSalesDetail()
  if (tab.key !== 'sales') queryRecords({}, true)
}

async function queryRecords(query = {}, resetPage = true) {
  const recordType = activeTabKey.value
  if (recordType !== 'sales') {
    const currentQuery = queryModelByType.value[recordType] || {}
    const requestedPageSize = Math.max(1, Number(query.pageSize ?? query.limit) || pageSize.value)
    const pageSizeChanged = Number(currentQuery.pageSize || pageSize.value) !== requestedPageSize
    const targetPage = resetPage || pageSizeChanged ? 1 : Math.max(1, Number(query.page) || page.value)
    const nextQuery = {
      ...currentQuery,
      ...query,
      recordType,
      page: targetPage,
      pageSize: requestedPageSize
    }
    queryModelByType.value = { ...queryModelByType.value, [recordType]: nextQuery }
    const sequence = ++recordQuerySequence
    const result = await requestAction('query-order-center-records', nextQuery)
    const projection = salesOrderProjectionFromResult(result)
    if (projection && sequence === recordQuerySequence && activeTabKey.value === recordType) {
      state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
    }
    return result
  }
  const currentQuery = queryModelByType.value[recordType] || {}
  const requestedPageSize = Math.max(1, Number(query.pageSize ?? query.limit) || pageSize.value)
  const pageSizeChanged = Number(currentQuery.pageSize || pageSize.value) !== requestedPageSize
  const targetPage = resetPage || pageSizeChanged ? 1 : Math.max(1, Number(query.page) || page.value)
  if (resetPage || pageSizeChanged) salesPageCursors.value = {}
  const cursor = salesPageCursors.value[targetPage] || ''
  if (targetPage > 1 && !cursor) {
    return {
      result: {
        status: 'failed',
        code: 'SALES_ORDER_CURSOR_REQUIRED',
        message: '请按顺序翻页；当前页码没有可用的签名游标。'
      }
    }
  }
  const nextQuery = {
    ...currentQuery,
    ...query,
    recordType,
    page: targetPage,
    pageSize: requestedPageSize
  }
  const cursorQuery = nextSalesOrderQueryWithCursor(nextQuery, cursor)
  queryModelByType.value = { ...queryModelByType.value, sales: cursorQuery }

  const sequence = ++salesQuerySequence
  const result = await requestAction('query-sales-orders', cursorQuery)
  const projection = salesOrderProjectionFromResult(result)
  if (projection && sequence === salesQuerySequence && activeTabKey.value === recordType) {
    state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
    const pagination = salesOrderPaginationFromPartition(projection)
    const cursors = { ...salesPageCursors.value }
    if (pagination.current) cursors[pagination.page] = pagination.current
    if (pagination.next) cursors[pagination.page + 1] = pagination.next
    else delete cursors[pagination.page + 1]
    salesPageCursors.value = cursors
    queryModelByType.value = {
      ...queryModelByType.value,
      sales: cursorQuery
    }
  }
  return result
}

function saveQuerySettings(settings) {
  querySettings.value = settings && typeof settings === 'object' ? { ...settings } : {}
  return Promise.resolve({
    result: { status: 'success', code: 'LOCAL_SETTINGS_APPLIED', message: '本页查询设置已应用。' },
    data: { settings: querySettings.value }
  })
}

function actionStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function isTerminalActionStatus(status) {
  return ['success', 'succeeded', 'failed', 'conflict'].includes(String(status || ''))
}

function actionData(result) {
  const response = result?.data && typeof result.data === 'object' && result.data.result
    ? result.data
    : result
  return response?.data && typeof response.data === 'object' ? response.data : {}
}

function salesDetailBelongsToOrder(detail, orderId) {
  if (!detail || !orderId) return false
  return String(detail.id || detail.orderId || detail.salesOrderId || '') === String(orderId)
}

function showSalesOrderDetail(payload = {}) {
  salesDetailSequence++
  const detail = payload?.detail || payload
  const record = detail.orderId
    ? records.value.find((item) => String(item.id) === String(detail.orderId))
    : detail
  const orderId = detail.orderId || record?.id
  if (!orderId) return null
  const backendDetail = salesDetailBelongsToOrder(orderCenter.value.salesOrderDetail, orderId)
    ? orderCenter.value.salesOrderDetail
    : null
  salesDetailOrder.value = backendDetail || record || {}
  isSalesDetailOpen.value = true
  return { record, orderId }
}

async function openSalesOrderDetail(payload = {}) {
  const current = showSalesOrderDetail(payload)
  if (!current) return { success: false, message: '未找到销售订单。' }
  const requestSequence = ++salesDetailSequence
  const requestedOrderId = current.orderId
  isSalesDetailLoading.value = true
  try {
    const result = await requestAction('open-sales-order-detail', {
      orderId: current.orderId,
      recordVersion: current.record?.revision
    })
    const projection = salesOrderProjectionFromResult(result)
    const activeOrderId = salesDetailOrder.value.id
      || salesDetailOrder.value.orderId
      || salesDetailOrder.value.salesOrderId
    const canApply = shouldApplySalesOrderDetailResponse({
      requestSequence,
      currentSequence: salesDetailSequence,
      requestedOrderId,
      activeOrderId,
      isOpen: isSalesDetailOpen.value
    })
    if (projection && canApply) {
      state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
    }
    if (canApply
      && !['failed', 'conflict'].includes(actionStatus(result))
      && salesDetailBelongsToOrder(projection?.salesOrderDetail, requestedOrderId)) {
      salesDetailOrder.value = projection.salesOrderDetail
    }
    return result
  } finally {
    const activeOrderId = salesDetailOrder.value.id
      || salesDetailOrder.value.orderId
      || salesDetailOrder.value.salesOrderId
    if (shouldApplySalesOrderDetailResponse({
      requestSequence,
      currentSequence: salesDetailSequence,
      requestedOrderId,
      activeOrderId,
      isOpen: isSalesDetailOpen.value
    })) {
      isSalesDetailLoading.value = false
    }
  }
}

function closeSalesDetail() {
  salesDetailSequence++
  isSalesDetailOpen.value = false
  salesDetailOrder.value = {}
  isSalesDetailLoading.value = false
}

function openRecordDetail(record) {
  if (activeTabKey.value === 'sales') return openSalesOrderDetail({ orderId: record.id })
  genericDetailRecord.value = record
  return null
}

async function openDebtRepayment(record) {
  if (!record?.canRepay || !record.memberId || !record.debtId) return
  // The shell owns repayment preparation and the cross-route Checkout handoff.
  // It re-reads the member's authoritative debt snapshot before accepting a
  // payment amount, so this table never fabricates a payable draft locally.
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-debt-repayment', {
    // Sales debt has a verified V3 Checkout path. Recharge debt remains on
    // its own authority until its Checkout migration is complete; opening the
    // member debt sheet preserves the existing safe repayment path meanwhile.
    detail: record.sourceType === '销售订单'
      ? { memberId: record.memberId, debtId: record.debtId, debtNo: record.debtNo }
      : { memberId: record.memberId }
  }))
}

const rechargeLifecycleActions = computed(() => {
  if (activeTabKey.value !== 'recharge' || !genericDetailRecord.value
    || genericDetailRecord.value.economicsDataStatus !== 'ready'
    || genericDetailRecord.value.orderStatus !== '正常') return []
  return ['refund', 'void']
})

async function handleRechargeLifecycleAction(payload = {}) {
  const record = genericDetailRecord.value || {}
  const action = String(payload.action || '')
  if (!['refund-recharge-order', 'void-recharge-order'].includes(action)
    || !record.rechargeId || !record.memberId) {
    return { result: { status: 'failed', message: '充值订单资料已变化，请重新打开后操作。' } }
  }
  const key = `${action}:${record.rechargeId}`
  const idempotencyKey = rechargeOrderActionIds.value[key] || createCashierV3CommandId()
  rechargeOrderActionIds.value = { ...rechargeOrderActionIds.value, [key]: idempotencyKey }
  const result = await requestAction(action, {
    rechargeId: record.rechargeId,
    memberId: record.memberId,
    reason: payload.reason,
    cashRefundAmount: payload.cashRefundAmount,
    principalRefundAmount: payload.principalRefundAmount,
    bonusRefundAmount: payload.bonusRefundAmount,
    idempotencyKey
  })
  if (isTerminalActionStatus(actionStatus(result))) {
    rechargeOrderActionIds.value = { ...rechargeOrderActionIds.value, [key]: null }
  }
  if (['success', 'succeeded'].includes(actionStatus(result))) {
    genericDetailRecord.value = null
    await queryRecords({}, false)
  }
  return result
}

async function handleSalesOrderDetailAction(payload = {}) {
  const action = String(payload.action || '')
  if (!allowedSalesOrderDetailActions.has(action)) {
    return { result: { status: 'failed', code: 'SALES_ORDER_ACTION_NOT_ALLOWED', message: '该订单操作未进入前端允许清单。' } }
  }
  const currentOrderId = salesDetailOrder.value.id || salesDetailOrder.value.orderId || salesDetailOrder.value.salesOrderId
  const currentRevision = salesDetailOrder.value.revision ?? salesDetailOrder.value.recordVersion
  if (!currentOrderId || String(payload.orderId || '') !== String(currentOrderId)) {
    return { result: { status: 'failed', code: 'SALES_ORDER_CONTEXT_MISMATCH', message: '订单详情已经变化，请重新打开后操作。' } }
  }
  const commandKey = `${action}:${currentOrderId}:${currentRevision ?? 'no-version'}:${payload.relationId || ''}`
  const idempotencyKey = salesOrderDetailCommandActions.has(action)
    ? (salesOrderActionIds.value[commandKey] || createCashierV3CommandId())
    : null
  if (idempotencyKey) salesOrderActionIds.value = { ...salesOrderActionIds.value, [commandKey]: idempotencyKey }
  const result = await requestAction(action, {
    orderId: currentOrderId,
    recordVersion: currentRevision,
    relationType: payload.relationType,
    relationId: payload.relationId,
    cardBatchId: payload.cardBatchId,
    benefitEntryId: payload.benefitEntryId,
    reason: payload.reason,
    refundAmount: payload.refundAmount,
    actualRefundAmount: payload.actualRefundAmount,
    balancePrincipalRefundAmount: payload.balancePrincipalRefundAmount,
    balanceGiftRefundAmount: payload.balanceGiftRefundAmount,
    replaceWorkspace: payload.replaceWorkspace === true,
    personnel: payload.personnel,
    ...(idempotencyKey ? { idempotencyKey } : {})
  })
  const businessData = actionData(result)
  const adjustment = businessData.orderPersonnelAdjustment
  if (action === 'open-sales-order-personnel-adjustment' && adjustment) {
    salesDetailOrder.value = { ...salesDetailOrder.value, personnelAdjustment: adjustment }
  }
  const debtRepayment = businessData.orderDebtRepayment
  if (['open-order-debt-settlements', 'open-debt-settlements'].includes(action) && debtRepayment?.memberId) {
    window.dispatchEvent(new CustomEvent('cashier-v3:open-member-debt-repayment', {
      detail: {
        memberId: debtRepayment.memberId,
        debtId: debtRepayment.debtId,
        debtNo: debtRepayment.debtNo,
        sourceOrderId: debtRepayment.salesOrderId
      }
    }))
  }
  if (['adjust-sales-order-personnel', 'reopen-sales-order'].includes(action)
    && !['failed', 'conflict'].includes(actionStatus(result))) {
    await openSalesOrderDetail({ orderId: currentOrderId })
  }
  if (['refund-sales-order', 'void-sales-order'].includes(action)
    && ['success', 'succeeded'].includes(actionStatus(result))) {
    await queryRecords({}, false)
    await openSalesOrderDetail({ orderId: currentOrderId })
  }
  if (action === 'reopen-sales-order'
    && !['failed', 'conflict'].includes(actionStatus(result))
    && businessData.orderLifecycle?.cashierDraft) {
    await router.push({ name: 'cashier-v3-cashier' })
  }
  if (idempotencyKey && isTerminalActionStatus(actionStatus(result))) {
    salesOrderActionIds.value = { ...salesOrderActionIds.value, [commandKey]: null }
  }
  return result
}

function resetLocalContext() {
  salesQuerySequence++
  salesDetailSequence++
  recordQuerySequence++
  salesPageCursors.value = {}
  queryModelByType.value = {}
  isSalesDetailOpen.value = false
  salesDetailOrder.value = {}
  isSalesDetailLoading.value = false
  genericDetailRecord.value = null
  salesOrderActionIds.value = {}
  rechargeOrderActionIds.value = {}
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.addEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetLocalContext)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.removeEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetLocalContext)
})
</script>

<template>
  <section class="order-center-page" aria-label="订单中心">
    <nav class="order-center-tabs" role="tablist" aria-label="订单与业务记录类型">
      <button
        v-for="tab in availableTabs"
        :key="tab.key"
        type="button"
        role="tab"
        class="order-center-tabs__button"
        :class="{ 'order-center-tabs__button--active': activeTabKey === tab.key }"
        :aria-selected="activeTabKey === tab.key"
        @click="switchTab(tab)"
      >
        <span>{{ tab.label }}</span>
        <strong class="order-center-tabs__count">{{ tabCount(tab.key) }}</strong>
      </button>
    </nav>

    <UnifiedQueryToolbar
      :key="activeTabKey"
      :search-placeholder="activeTab.searchPlaceholder"
      :status-options="statusOptions"
      :fields="queryFields"
      :settings="querySettings"
      default-sort-field="业务日期"
      lock-store-selector
      :current-store="state.currentStore"
      @query="queryRecords"
      :on-save-settings="saveQuerySettings"
      @settings-applied="applyQuerySettings"
    />

    <main class="order-center-list-wrap">
      <table class="order-center-list-table" :style="{ '--order-column-count': visibleFields.length }">
        <thead>
          <tr>
            <th v-for="fieldItem in visibleFields" :key="fieldItem.key">{{ fieldItem.label }}</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(record, index) in records" :key="recordKey(record, index)">
            <td v-for="fieldItem in visibleFields" :key="fieldItem.key" :class="tableCellClass(record, fieldItem)">
              <button
                v-if="fieldItem.key === activeTab.primaryField"
                type="button"
                class="order-link"
                @click="openRecordDetail(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <span v-else-if="fieldItem.type === 'money'">{{ displayMoneyField(record, fieldItem.key) }}</span>
              <span v-else-if="fieldItem.type === 'status'" class="order-status" :class="statusClass(recordFieldValue(record, fieldItem.key))">
                {{ displayRecordField(record, fieldItem.key) }}
              </span>
              <span v-else>{{ displayRecordField(record, fieldItem.key) }}</span>
            </td>
            <td>
              <button type="button" class="button button--text" @click="openRecordDetail(record)">查看详情</button>
              <button
                v-if="activeTabKey === 'debt' && record.canRepay"
                type="button"
                class="button button--text"
                @click="openDebtRepayment(record)"
              >去还款</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!records.length" class="order-center-list-empty">{{ activeTab.emptyText }}</div>
    </main>

    <TablePagination
      :total="total"
      :page="page"
      :page-size="pageSize"
      :sequential="activeTabKey === 'sales'"
      :has-more="hasMore"
      @change="(pagination) => queryRecords(pagination, false)"
    />

    <SalesOrderDetailOverlay
      v-if="isSalesDetailOpen"
      :order="salesDetailOrder"
      :is-loading="isSalesDetailLoading"
      :cart-line-count="Array.isArray(state.cashier?.cart?.lines) ? state.cashier.cart.lines.length : 0"
      :on-action="handleSalesOrderDetailAction"
      @close="closeSalesDetail"
    />

    <BusinessRecordDetailOverlay
      v-if="genericDetailRecord"
      :title="`${activeTab.label}详情`"
      :record="genericDetailRecord"
      :fields="visibleFields"
      :resolve-value="recordFieldValue"
      :lifecycle-actions="rechargeLifecycleActions"
      :on-lifecycle-action="handleRechargeLifecycleAction"
      @close="genericDetailRecord = null"
    />
  </section>
</template>
