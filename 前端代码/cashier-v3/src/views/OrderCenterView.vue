<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BusinessRecordDetailOverlay from '@/components/order/BusinessRecordDetailOverlay.vue'
import ReceiptPrinterSetupOverlay from '@/components/order/ReceiptPrinterSetupOverlay.vue'
import SalesOrderDetailOverlay from '@/components/order/SalesOrderDetailOverlay.vue'
import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import Printer from '@lucide/vue/dist/esm/icons/printer.mjs'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import { useUnifiedQueryPage } from '@mohe/unified-query-vue3/composable'
import { queryPreferenceKey, readQueryPreferences, mergeQueryRefresh } from '@mohe/unified-query-vue3'
import {
  createCashierV3CommandId,
  canUseCashierV3Operation,
  formatMoney,
  mergeCashierV3PublicVersions,
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
import {
  openSalesOrderReceiptPrint
} from '@/services/salesOrderReceiptPrint'
import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'
import { queryPlatformOrderCenterScope } from '@/services/platformOrderCenterApi'
import { salespeoplePerformanceText, serviceCraftsmenPerformanceText } from '@/services/orderCenterPersonnelDisplay'

const field = (key, label, type = 'text', extra = {}) => ({ key, label, type, defaultVisible: true, ...extra })
const isPrinterSetupOpen = ref(false)
const serviceVoidNotice = ref('')
const serviceVoidRecord = ref(null)
const serviceVoidReason = ref('')
const serviceVoidError = ref('')
const serviceVoidSubmitting = ref(false)
const serviceVoidCommandIds = ref({})
const servicePrintError = ref('')
const serviceCraftsmanRecord = ref(null)
const serviceCraftsmanEntry = ref(null)
const serviceCraftsmanEditorOpen = ref(false)
const serviceCraftsmanLoading = ref(false)
const serviceCraftsmanPendingAssignment = ref(null)
const serviceCraftsmanReason = ref('')
const serviceCraftsmanError = ref('')
const serviceCraftsmanSubmitting = ref(false)
const serviceCraftsmanCommandIds = ref({})
const salesPersonnelEntry = ref(null)
const salesPersonnelTarget = ref(null)
const salesPersonnelEditorOpen = ref(false)
const salesPersonnelPendingAssignment = ref(null)
const salesPersonnelReason = ref('')
const salesPersonnelError = ref('')
const salesPersonnelSubmitting = ref(false)
const salesPersonnelCommandIds = ref({})
const salesPersonnelSearchLoading = ref(false)
const salesPersonnelSearchError = ref('')
let salesPersonnelSearchSerial = 0
// Recharge and debt-repayment personnel adjustments use the same personnel
// selector, but their authority records are not sales-order resources.
const recordPersonnelEntry = ref(null)
const recordPersonnelTarget = ref(null)
const recordPersonnelEditorOpen = ref(false)
const recordPersonnelPendingAssignment = ref(null)
const recordPersonnelReason = ref('')
const recordPersonnelError = ref('')
const recordPersonnelSubmitting = ref(false)
const recordPersonnelCommandIds = ref({})
const salesOrderNoteRecord = ref(null)
const salesOrderNoteValue = ref('')
const salesOrderNoteError = ref('')
const salesOrderNoteSubmitting = ref(false)
const salesOrderNoteCommandIds = ref({})
const salesDetailFocus = ref({ lifecycleAction: '', personnelRole: '', personnelLineId: '' })
const salesDetailActionOnly = ref(false)

const ORDER_TABS = [
  {
    key: 'sales',
    pageCode: 'order_center_sales',
    label: '消费订单',
    stateKey: 'salesOrders',
    primaryField: 'sales_order_no',
    searchPlaceholder: '搜索销售订单号、会员姓名、手机号或商品',
    emptyText: '暂无正式销售订单。完成结账后可在这里查询订单详情。',
    fields: [
      field('sales_order_no', '销售订单号', 'text', { quickFilterHidden: true }),
      // 销售日期仍筛选权威 business_date；实际下单时间只用于追溯，不改变归属日。
      field('business_date', '销售日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名／游客'), field('phone', '手机号'),
      field('store', '下单门店', 'store'), field('item_summary', '商品摘要'),
      // 明细集合参与整单筛选；列名与下方表头共用，不能只增加一个无后端映射的选项。
      field('item_name', '商品'), field('unit_price', '单价', 'money'), field('quantity', '数量', 'number'),
      field('craftsman', '手艺人（类型，业绩，手工、项目数）', 'person'),
      field('sales_manager', '销售经理', 'person'), field('guide', '导购', 'person'),
      field('line_amount', '金额', 'money'), field('occurred_at', '实际下单时间', 'date'),
      field('item_count', '商品数量', 'number'), field('receivable_amount', '应收金额', 'money'),
      field('discount_amount', '优惠金额', 'money'), field('debt_amount', '欠款', 'money'),
      field('actual_received_amount', '已收金额', 'money'), field('payment_method', '记账收款'),
      field('salesperson', '销售人（业绩）', 'person'), field('cashier', '收银员／操作人', 'person'),
      field('source', '客户来源'),
      field('payment_status', '支付状态', 'status'), field('order_status', '状态', 'status'),
      field('supplement', '补单标记'), field('payment_completed_at', '支付完成时间', 'date')
    ]
  },
  {
    key: 'recharge',
    pageCode: 'order_center_recharge',
    label: '充值订单',
    stateKey: 'rechargeOrders',
    primaryField: 'recharge_order_no',
    searchPlaceholder: '搜索充值订单号、会员姓名或手机号',
    emptyText: '暂无充值订单。',
    fields: [
      field('recharge_order_no', '充值订单号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('phone', '手机号'), field('store', '办理门店', 'store'),
      field('recharge_plan', '充值方案'), field('salesperson', '销售人', 'person'),
      field('recharge_amount', '充值金额', 'money'), field('gift_amount', '赠送金额', 'money'),
      field('actual_received_amount', '现金业绩', 'money'), field('payment_method', '收款方式'),
      field('operator', '操作人', 'person'), field('payment_status', '支付状态', 'status'),
      field('order_status', '订单状态', 'status'), field('payment_completed_at', '支付完成时间', 'date')
    ]
  },
  {
    key: 'refund',
    pageCode: 'order_center_refund',
    label: '退款记录',
    stateKey: 'refundOrders',
    primaryField: 'refund_order_no',
    searchPlaceholder: '搜索退款单号、来源订单、会员姓名或手机号',
    emptyText: '暂无退款记录。',
    fields: [
      field('refund_order_no', '退款单号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('source_order_no', '来源订单号'), field('member_name', '会员姓名／游客'),
      field('phone', '手机号'), field('refund_summary', '退款内容'), field('refund_amount', '退款金额', 'money'),
      field('refund_method', '退款方式'), field('store', '办理门店', 'store'),
      field('operator', '操作人', 'person'), field('refund_status', '退款状态', 'status'),
      field('refund_completed_at', '退款完成时间', 'date')
    ]
  },
  {
    key: 'debt',
    pageCode: 'order_center_debt',
    label: '欠款管理',
    stateKey: 'debtRecords',
    primaryField: 'debt_no',
    searchPlaceholder: '搜索欠款编号、来源订单、会员姓名或手机号',
    emptyText: '暂无欠款记录。欠款以权威欠款事实为准。',
    fields: [
      field('debt_no', '欠款编号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '客户'), field('phone', '手机号'),
      field('source_type', '欠款来源'), field('source_order_no', '来源订单'),
      field('original_debt_amount', '原欠款', 'money'), field('repaid_amount', '已还', 'money'),
      field('remaining_amount', '剩余', 'money'), field('debt_status', '状态', 'status'),
      field('store', '欠款门店', 'store'), field('created_at', '创建时间', 'date')
    ]
  },
  {
    key: 'service',
    pageCode: 'order_center_service',
    label: '服务记录',
    stateKey: 'serviceRecords',
    primaryField: 'service_record_no',
    searchPlaceholder: '搜索服务记录号、会员、项目、权益来源、卡号或手艺人',
    emptyText: '暂无服务记录。',
    fields: [
      field('service_record_no', '服务记录号', 'text', { quickFilterHidden: true }),
      field('source', '来源'),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('service_project', '服务项目'),
      field('entitlement_source', '权益来源'), field('source_card', '来源卡名称'),
      field('source_card_no', '完整卡号'), field('used_times', '本次使用次数', 'number'),
      field('detail_remark', '明细备注', 'text', { defaultVisible: false }),
      field('store', '服务门店', 'store'), field('craftsman', '手艺人（类型，业绩，手工、项目数）', 'person'),
      field('labor_performance_type', '服务业绩类型'),
      field('labor_performance_ratio', '业绩比例'), field('labor_performance_amount', '消耗业绩', 'money'),
      field('operator', '操作人', 'person'),
      field('service_status', '状态', 'status'), field('service_completed_at', '服务完成时间', 'date'),
      field('voided_at', '作废时间', 'date'), field('void_reason', '作废原因'), field('void_operator', '作废操作人', 'person')
    ]
  },
  {
    key: 'supplement',
    pageCode: 'order_center_supplement',
    label: '补交记录',
    stateKey: 'supplementOrders',
    primaryField: 'supplement_order_no',
    searchPlaceholder: '搜索补交单号、欠款编号、来源订单或会员',
    emptyText: '暂无已完成的补交记录。会员未补交的欠款请从总欠款入口查看。',
    fields: [
      field('supplement_order_no', '补交单号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('phone', '手机号'), field('debt_summary', '欠款摘要'),
      field('salesperson', '销售人', 'person'), field('supplement_amount', '补交金额', 'money'), field('payment_method', '收款方式'),
      field('store', '补交门店', 'store'), field('operator', '操作人', 'person'),
      field('payment_status', '支付状态', 'status'), field('payment_completed_at', '支付完成时间', 'date'),
      field('debt_no', '欠款编号'), field('source_order_no', '来源订单号')
    ]
  },
  {
    key: 'gift',
    pageCode: 'order_center_gift',
    label: '赠送记录',
    stateKey: 'giftRecords',
    primaryField: 'gift_record_no',
    searchPlaceholder: '搜索赠送记录号、来源、会员或赠送内容',
    emptyText: '暂无赠送记录。',
    fields: [
      field('gift_record_no', '赠送记录号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('gift_source', '赠送来源'),
      field('gift_type', '赠送类型'), field('gift_content', '赠送内容'), field('gift_quantity', '赠送数量', 'number'),
      field('effective_at', '生效时间', 'date'), field('expires_at', '到期时间', 'date'),
      field('gift_status', '赠送状态', 'status'), field('store', '办理门店', 'store'),
      field('operator', '操作人', 'person'), field('gift_reason', '赠送原因'), field('created_at', '创建时间', 'date')
    ]
  },
  {
    key: 'card_operation',
    pageCode: 'order_center_card_operation',
    label: '卡操作记录',
    stateKey: 'cardOperationRecords',
    primaryField: 'card_operation_no',
    searchPlaceholder: '搜索操作单号、会员、卡项、项目或操作人',
    emptyText: '暂无卡升级、停用、启用、延期、项目替换或项目升级记录。',
    fields: [
      field('card_operation_no', '操作单号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('operation_type', '操作类型', 'status', { quickFilterHidden: true }),
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
  cashier: ['cashierName', 'operatorName'], source: ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary'],
  payment_status: ['paymentStatus'], order_status: ['orderStatus', 'statusLabel'], supplement: ['supplementLabel', 'isSupplement'],
  occurred_at: ['occurredAt'], payment_completed_at: ['paymentCompletedAt', 'completedAt'], recharge_order_no: ['rechargeOrderNo', 'orderNo'],
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
  labor_fee_amount: ['laborFeeAmount', 'manualLaborFeeAmount'],
  labor_performance_type: ['laborPerformanceTypeLabel', 'laborPerformanceType'],
  labor_performance_ratio: ['laborPerformanceRatio'],
  labor_performance_amount: ['laborPerformanceAmount'], service_status: ['serviceStatus'],
  project_count: ['projectCount'],
  detail_remark: ['detailRemark'],
  report_performance_amount: ['reportPerformanceAmount'],
  report_labor_amount: ['reportLaborAmount'],
  report_performance_facts: ['reportPerformanceFacts'],
  service_completed_at: ['serviceCompletedAt', 'completedAt'], voided_at: ['voidedAt'],
  void_reason: ['voidReason'], void_operator: ['voidOperatorName']
}

const state = useCashierV3State()
const router = useRouter()
const route = useRoute()
const isPlatformReadOnly = computed(() => route.meta.platformReadOnly === true)
const serviceReportDrilldown = computed(() => {
  if (String(route.query.tab || '') !== 'service') return null
  const employeeId = Number(route.query.report_employee_id)
  const storeId = Number(route.query.report_store_id)
  const from = String(route.query.report_start_date || '')
  const to = String(route.query.report_end_date || '')
  const dayOfMonth = Number(route.query.report_day_of_month || 0)
  if (!Number.isSafeInteger(employeeId) || employeeId <= 0 || !Number.isSafeInteger(storeId) || storeId <= 0
    || !/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || from > to
    || !Number.isInteger(dayOfMonth) || dayOfMonth < 0 || dayOfMonth > 31) return null
  return { employeeId, storeId, from, to, dayOfMonth }
})
const salesReportDrilldown = computed(() => {
  if (String(route.query.tab || '') !== 'sales') return null
  const employeeId = Number(route.query.report_salesperson_id)
  const storeId = Number(route.query.report_store_id)
  const from = String(route.query.report_start_date || '')
  const to = String(route.query.report_end_date || '')
  const dayOfMonth = Number(route.query.report_day_of_month || 0)
  if (!Number.isSafeInteger(employeeId) || employeeId <= 0 || !Number.isSafeInteger(storeId) || storeId <= 0
    || !/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || from > to
    || !Number.isInteger(dayOfMonth) || dayOfMonth < 0 || dayOfMonth > 31) return null
  return { employeeId, storeId, from, to, dayOfMonth }
})
function reportDateLabel(drill) {
  if (!drill) return ''
  if (drill.dayOfMonth === 0) return `${drill.from} 至 ${drill.to}`
  return drill.from.slice(0, 7) === drill.to.slice(0, 7)
    ? `${drill.from.slice(0, 7)}-${String(drill.dayOfMonth).padStart(2, '0')}`
    : `${drill.from} 至 ${drill.to} 中的每月 ${drill.dayOfMonth} 日`
}
const serviceReportDateLabel = computed(() => {
  return reportDateLabel(serviceReportDrilldown.value)
})
const salesReportDateLabel = computed(() => reportDateLabel(salesReportDrilldown.value))
const serviceReportReturnLocation = computed(() => {
  const drill = serviceReportDrilldown.value
  if (!drill) return null
  const reportRouteName = isPlatformReadOnly.value
    ? 'cashier-v3-platform-store-business-reports'
    : 'cashier-v3-store-business-reports'
  const returnTo = route.query.report_return_to
  if (typeof returnTo === 'string' && returnTo.startsWith('/') && !returnTo.startsWith('//') && returnTo.length <= 4096) {
    const resolved = router.resolve(returnTo)
    // 仅接受本端的手艺人消耗报表，不能把 URL 参数当成任意跳转地址。
    if (resolved.name === reportRouteName && resolved.params.report === 'store_craftsman_consumption') return resolved.fullPath
  }
  // 旧下钻链接没有返回地址时，仍可返回同一报表及日期范围。
  return {
    name: reportRouteName,
    params: { report: 'store_craftsman_consumption' },
    query: { start_date: drill.from, end_date: drill.to }
  }
})
const salesReportReturnLocation = computed(() => {
  const drill = salesReportDrilldown.value
  if (!drill) return null
  const reportRouteName = isPlatformReadOnly.value
    ? 'cashier-v3-platform-store-business-reports'
    : 'cashier-v3-store-business-reports'
  const returnTo = route.query.report_return_to
  if (typeof returnTo === 'string' && returnTo.startsWith('/') && !returnTo.startsWith('//') && returnTo.length <= 4096) {
    const resolved = router.resolve(returnTo)
    // 返回地址只接受本端销售人业绩报表，避免查询参数形成任意站内跳转。
    if (resolved.name === reportRouteName && resolved.params.report === 'store_salesperson_performance') return resolved.fullPath
  }
  return {
    name: reportRouteName,
    params: { report: 'store_salesperson_performance' },
    query: { start_date: drill.from, end_date: drill.to }
  }
})
const platformScopePicker = ref({ loading: false, tree: [], allowedStoreIds: [], label: '当前权限范围' })
const platformScopeStoreIds = ref([])
const orderCenter = computed(() => state.orderCenter || {})
const requestedPlatformTab = String(route.query.tab || route.query.recordType || '').trim()
const activeTabKey = ref(ORDER_TABS.some((tab) => tab.key === requestedPlatformTab) ? requestedPlatformTab : 'sales')
const availableTabs = computed(() => {
  // 平台的首个查询响应只对应当前页签，不能在它尚未带全量 businessTypes
  // 时把其余七个入口隐藏。
  if (isPlatformReadOnly.value) return ORDER_TABS
  const advertised = Array.isArray(orderCenter.value.businessTypes) ? orderCenter.value.businessTypes : []
  if (!advertised.length) return serviceReportDrilldown.value ? ORDER_TABS : ORDER_TABS.filter((tab) => tab.key === 'sales')
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
const executedQueryByType = ref({})
const isOrderQueryLoading = ref(false)
const salesPageCursors = ref({})
const isSalesDetailOpen = ref(false)
const salesDetailOrder = ref({})
const isSalesDetailLoading = ref(false)
const salesDetailLoadError = ref('')
const SALES_DETAIL_REQUEST_TIMEOUT_MS = 15000
const genericDetailRecord = ref(null)
const salesPrintLoading = ref({})
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
  const fields = source.reduce((items, key) => {
    if (seen.has(key) || !available.has(key)) return items
    seen.add(key)
    items.push(available.get(key))
    return items
  }, [])
  if (activeTabKey.value === 'service') {
    // “来源”是服务记录的固定审计列。旧账号保存的可见列清单早于该字段，
    // 因此这里补入并固定到服务记录号右侧，同时保留其余个性化列顺序。
    const sourceIndex = fields.findIndex((item) => item.key === 'source')
    if (sourceIndex >= 0) fields.splice(sourceIndex, 1)
    const recordNoIndex = fields.findIndex((item) => item.key === 'service_record_no')
    fields.splice(recordNoIndex >= 0 ? recordNoIndex + 1 : 0, 0, available.get('source'))
  }
  // 销售人是充值/补交金额的归属字段，固定显示在金额左侧；旧版保存的
  // 查询设置可能仍把它排在金额后面，这里只纠正这两个字段的相对位置。
  if (['recharge', 'supplement'].includes(activeTabKey.value)) {
    const salespersonIndex = fields.findIndex((item) => item.key === 'salesperson')
    const amountIndex = fields.findIndex((item) => item.key === (activeTabKey.value === 'recharge' ? 'recharge_amount' : 'supplement_amount'))
    if (salespersonIndex >= 0 && amountIndex >= 0 && salespersonIndex > amountIndex) {
      const [salesperson] = fields.splice(salespersonIndex, 1)
      fields.splice(amountIndex, 0, salesperson)
    }
  }
  if (activeTabKey.value === 'service' && serviceReportDrilldown.value) {
    // 下钻列只出现在报表入口；普通服务记录列表不改变原有字段配置。
    fields.push(field('report_performance_amount', '本次报表消耗', 'money'))
    fields.push(field('report_labor_amount', '本次报表手工', 'money'))
    fields.push(field('report_performance_facts', '本次报表逐笔明细'))
  }
  return fields
})
const detailFields = computed(() => {
  if (activeTabKey.value !== 'service') return visibleFields.value
  const present = new Set(visibleFields.value.map((item) => item.key))
  const detailOnlyKeys = genericDetailRecord.value?.voidedAt
    ? ['detail_remark', 'voided_at', 'void_reason', 'void_operator']
    : ['detail_remark']
  const extras = queryFields.value.filter((item) => detailOnlyKeys.includes(item.key) && !present.has(item.key))
  // The two values remain available in service detail; only the separate
  // list columns are replaced by the per-craftsman combined cell.
  const performanceExtras = [field('labor_fee_amount', '手工费', 'money'), field('project_count', '工资项目数', 'number')]
    .filter((item) => !present.has(item.key))
  return [...visibleFields.value, ...extras, ...performanceExtras]
})

const allowedSalesOrderDetailActions = new Set([
  'print-sales-order-receipt', 'open-card-batch', 'open-card-benefits', 'open-order-debt-settlements',
  'open-order-refunds', 'open-order-void', 'open-order-reopenings', 'open-order-upgrades', 'open-order-gifts',
  'open-order-services', 'open-order-writeoffs', 'open-order-operation-logs', 'refund-sales-order',
  'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order', 'open-sales-order-personnel-adjustment',
  'adjust-sales-order-personnel', 'update-sales-order-note'
])
const salesOrderDetailCommandActions = new Set([
  'print-sales-order-receipt', 'refund-sales-order', 'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order',
  'adjust-sales-order-personnel', 'update-sales-order-note'
])
// Only these actions are backed by the V3 sales-order lifecycle resource.
// Other historical detail affordances keep their existing display-ID contract.
const salesOrderLifecycleResourceActions = new Set([
  'adjust-sales-order-personnel', 'update-sales-order-note', 'refund-sales-order',
  'void-sales-order', 'reopen-sales-order', 'open-order-debt-settlements', 'open-debt-settlements'
])

watch(
  () => [activeTabKey.value, state.operator?.account, state.currentStore?.id, orderCenter.value.querySettingsByType, orderCenter.value.querySettings],
  () => {
    // 本地配置优先；操作响应中的旧根投影不能覆盖用户刚保存的查询规则。
    const localKey = queryPreferenceKey(state.operator?.account, state.currentStore?.id, activeTab.value.pageCode)
    const settings = readQueryPreferences(localKey)?.settings || orderCenter.value.querySettingsByType?.[activeTabKey.value]
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

// The normal list projection remains the order-center authority. This companion
// capability is only used to freeze that successfully executed query for the
// shared async export worker; it never exports browser rows.
const unifiedQueryPages = Object.fromEntries(ORDER_TABS.map((tab) => [tab.key, useUnifiedQueryPage({
  pageCode: tab.pageCode,
  pageName: tab.label,
  baseFields: tab.fields,
  requestAction
})]))
const activeUnifiedQuery = computed(() => unifiedQueryPages[activeTabKey.value] || unifiedQueryPages.sales)
const platformOrderCenterDefaultDateRanges = computed(() => {
  if (!isPlatformReadOnly.value) return {}
  const today = orderCenterToday()
  // 平台与门店必须使用同一首屏口径：日期框显示今天时，真实请求也只能
  // 查询今天，不能用隐藏的整月范围扩大结果集。
  return { business_date: { min: today, max: today } }
})
const activeQueryCapability = computed(() => {
  const capability = activeUnifiedQuery.value?.capability?.value || {}
  if (!isPlatformReadOnly.value) return capability
  // 平台入口只提供列表查询；即使后端复用了门店的统一查询能力描述，也不
  // 显示导出、字段管理或其他会写入个人配置的工具栏操作。
  return {
    ...capability,
    commandContext: null,
    exportCapability: { ...(capability.exportCapability || {}), enabled: false },
    permissions: { ...(capability.permissions || {}), renameFields: false, createCustomField: false, editCustomField: false }
  }
})
const activeQueryFields = computed(() => activeUnifiedQuery.value?.fields?.value || queryFields.value)
const executedQuery = computed(() => executedQueryByType.value[activeTabKey.value] || null)

function exportQuerySnapshot(recordType, query = {}) {
  // Legacy list aliases are not part of the unified-query contract. Preserve
  // their effective date range as structured filters before removing aliases.
  const {
    recordType: ignoredRecordType, queryCursor, pageSize, status: ignoredStatus,
    dateFrom, dateTo, businessDateFrom, businessDateTo,
    date_from, date_to, business_date_from, business_date_to,
    ...rest
  } = query || {}
  const { from, to } = businessDateRangeFromQuery(query)
  const topFilters = [...(Array.isArray(rest.topFilters) ? rest.topFilters : [])]
  for (const [operator, value] of [['gte', from], ['lte', to]]) {
    if (value && !topFilters.some((filter) => filter?.field === 'business_date'
      && filter?.operator === operator && filter?.value === value)) {
      topFilters.push({ field: 'business_date', operator, value })
    }
  }
  const tab = ORDER_TABS.find((item) => item.key === recordType)
  return {
    ...rest,
    topFilters,
    pageCode: tab?.pageCode || '',
    page: Math.max(1, Number(rest.page) || 1),
    limit: Math.max(1, Number(rest.limit ?? pageSize) || 20)
  }
}

function saveExecutedQuery(recordType, query) {
  executedQueryByType.value = {
    ...executedQueryByType.value,
    [recordType]: exportQuerySnapshot(recordType, query)
  }
}

function createActiveExport(payload) {
  return activeUnifiedQuery.value?.createExport(payload)
}

function queryActiveExportTask(payload) {
  return activeUnifiedQuery.value?.queryExportTask(payload)
}

function exportDownloadFileName(value) {
  const normalized = String(value || '查询结果.xlsx').replace(/[\\\\/:*?"<>|]+/g, '_').trim() || '查询结果.xlsx'
  return /\.xlsx$/i.test(normalized) ? normalized : `${normalized}.xlsx`
}

async function downloadActiveExport({ url, fileName, task } = {}) {
  const endpoint = new URL(String(url || ''), window.location.origin)
  if (endpoint.origin !== window.location.origin) throw new Error('导出文件地址无效，请刷新后重试。')
  const pageCode = String(task?.pageCode || activeTab.value?.pageCode || '').trim()
  if (!pageCode) throw new Error('导出任务页面信息缺失，请刷新后重试。')
  endpoint.searchParams.set('pageCode', pageCode)
  const token = readStoreV3SessionToken()
  const response = await fetch(endpoint.href, {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      ...(token ? { Authorization: `Bearer ${token}`, 'Authori-zation': `Bearer ${token}` } : {})
    }
  })
  const blob = await response.blob()
  const type = String(response.headers.get('content-type') || '').toLowerCase()
  if (!response.ok || type.includes('json')) {
    const message = await blob.text().then((text) => JSON.parse(text)?.msg || JSON.parse(text)?.message || '').catch(() => '')
    throw new Error(message || '导出文件下载失败，请稍后重试。')
  }
  const header = new Uint8Array(await blob.slice(0, 4).arrayBuffer())
  if (blob.size < 4 || header[0] !== 0x50 || header[1] !== 0x4b || header[2] !== 0x03 || header[3] !== 0x04) {
    throw new Error('导出文件格式无效，请重新创建导出任务。')
  }
  const objectUrl = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = exportDownloadFileName(fileName)
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
}

function firstValue(record, keys) {
  for (const key of keys) {
    const value = record?.[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return undefined
}

function recordFieldValue(record, key) {
  // The service list and detail must show the same per-person snapshot; the
  // editable action still receives the untouched authority record.
  if (key === 'craftsman' && activeTabKey.value === 'service') {
    // 报表下钻的作废服务显示所点日期的历史分配；普通列表仍显示当前有效分配。
    const historical = serviceReportDrilldown.value && record?.serviceStatus === '已作废'
      ? record?.reportCraftsmenListAllocations : null
    if (Array.isArray(historical) && historical.length) {
      return serviceCraftsmenPerformanceText({ craftsmenListAllocations: historical })
    }
    return serviceCraftsmenPerformanceText(record)
  }
  if (key === 'report_performance_facts') {
    return (Array.isArray(record?.reportPerformanceFacts) ? record.reportPerformanceFacts : [])
      .map((fact) => `${fact.businessDate} ${fact.direction} 消耗 ${Number(fact.amountCents) > 0 ? '+' : ''}${formatMoney(fact.amount)}、手工 ${Number(fact.laborFeeCents) > 0 ? '+' : ''}${formatMoney(fact.laborFee)}`)
      .join('；')
  }
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

function memberDetailPayload(record = {}) {
  const memberId = firstValue(record, ['memberId', 'member_id', 'uid', 'userId'])
  if (memberId === undefined || memberId === null || String(memberId).trim() === '' || Number(memberId) <= 0) return null
  return {
    memberId,
    member: {
      id: memberId,
      memberId,
      name: displayRecordField(record, 'member_name'),
      phone: displayRecordField(record, 'phone') === '—' ? '' : displayRecordField(record, 'phone')
    }
  }
}

function canOpenMemberDetail(record) {
  return !isPlatformReadOnly.value && memberDetailPayload(record) !== null
}

function openMemberDetail(record) {
  const payload = memberDetailPayload(record)
  if (!payload) return
  // Member detail remains owned by the shell so every entry loads the same
  // authorization-scoped detail projection and tab contract.
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-detail', { detail: payload }))
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

// The unified query adds a display-scoped record_id (for example
// `gift:v3-direct-gift:<fact>`).  Lifecycle writes must never use that wrapper
// or the human-facing ZS number; recover the canonical V3 fact identity from
// the explicit field first, then from the legacy query wrapper for compatibility.
function giftVoidRecordId(record) {
  const candidates = [
    record?.voidRecordId,
    record?.void_record_id,
    record?.id,
    record?.recordId,
    record?.record_id
  ]
  for (const candidate of candidates) {
    const value = String(candidate || '')
    const marker = 'v3-direct-gift:'
    const markerIndex = value.indexOf(marker)
    if (markerIndex >= 0) return value.slice(markerIndex)
  }
  return ''
}

// 固定明细布局只保存字段键，标签复用查询定义，避免表格增加列后设置仍是另一套名称。
const salesOrderListColumnKeys = ['item_name', 'unit_price', 'quantity', 'craftsman', 'salesperson', 'sales_manager', 'guide', 'line_amount', 'receivable_amount', 'debt_amount', 'actual_received_amount', 'payment_method', 'store', 'order_status']
const salesOrderListColumns = salesOrderListColumnKeys.map((key) => ORDER_TABS[0].fields.find((item) => item.key === key).label)

function salesOrderItems(record) {
  if (Array.isArray(record?.items) && record.items.length) return record.items
  return [{
    id: `${recordKey(record, 0)}-summary`,
    name: displayRecordField(record, 'item_summary'),
    itemType: '',
    quantity: displayRecordField(record, 'item_count'),
    unitPrice: null,
    payableAmount: recordFieldValue(record, 'receivable_amount'),
    craftsmen: [],
    salespeople: [],
    salesManagers: [],
    guides: []
  }]
}

function personnelNames(item, key) {
  const records = Array.isArray(item?.[key]) ? item[key] : []
  return records.map((person) => person?.name || person?.employeeName || person?.employee_name_snapshot || '').filter(Boolean).join('、') || '—'
}

function salesItemCraftsmenText(item) {
  // 销售单中的关联服务行与“服务记录”共用同一展示契约，
  // 避免同一服务在两个页签显示不同的业绩、手工费和项目数。
  if (Array.isArray(item?.craftsmenListAllocations)) return serviceCraftsmenPerformanceText(item)
  return personnelNames(item, 'craftsmen')
}

function salesItemCraftsmanAdjustable(item = {}) {
  return !isPlatformReadOnly.value
    && serviceCraftsmanAdjustable(item)
    && canUseCashierV3Operation('cashier.v3.order.service_detail')
}

function salesPurchaseCraftsmanAdjustable(record = {}, item = {}) {
  return !isPlatformReadOnly.value
    && !item?.serviceFactId
    && ['project', '项目'].includes(String(item?.itemType || ''))
    && salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')
}

function openSalesItemCraftsmanEditor(record, item) {
  // 有服务事实的权益行继续走服务记录调整；只有没有服务事实的直接购买
  // 项目才走销售订单行调整，避免同一劳动事实出现两个写入口。
  if (salesItemCraftsmanAdjustable(item)) {
    openServiceCraftsmanAdjustment(item)
    return
  }
  if (salesPurchaseCraftsmanAdjustable(record, item)) openSalesOrderPersonnelEditor(record, item, 'craftsmen')
}

function salesOrderActionAvailable(record, action, permission) {
  if (isPlatformReadOnly.value) return false
  const actions = record?.availableActions
  const available = Array.isArray(actions) ? actions.includes(action) : actions?.[action] === true
  return available && (!permission || canUseCashierV3Operation(permission))
}

/**
 * 订单列表可保留旧订单展示 ID，但所有生命周期写命令必须使用后端签发的
 * V3 销售订单资源 ID。两者混用会造成 expectedVersion 锁定到错误资源。
 */
function salesOrderLifecycleId(record) {
  const explicit = record?.lifecycleOrderId || record?.lifecycle_order_id
    || record?.authorityOrderId || record?.authority_order_id || record?.salesOrderId
  if (explicit) return String(explicit)
  const fallback = String(record?.id || record?.orderId || '')
  return /^[A-Za-z][A-Za-z0-9_-]{1,63}$/.test(fallback) ? fallback : ''
}

function salesOrderDisplayId(record) {
  return String(record?.id || record?.orderId || record?.salesOrderId || '')
}

function salesOrderPersonnelLineId(item) {
  return String(item?.id || item?.orderItemId || item?.lineId || '')
}

function salesOrderPersonnelTargetRole(role) {
  return role === 'salespeople' ? 'salesperson'
    : role === 'craftsmen' ? 'craftsman'
      : role === 'salesManagers' ? 'sales_manager' : 'guide'
}

async function openSalesOrderPersonnelEditor(record, item, role) {
  const orderId = salesOrderLifecycleId(record)
  const lineId = salesOrderPersonnelLineId(item)
  if (!orderId || !lineId || !salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')) return
  salesPersonnelError.value = ''
  salesPersonnelSearchError.value = ''
  salesPersonnelSearchLoading.value = false
  salesPersonnelSearchSerial += 1
  salesPersonnelEntry.value = null
  salesPersonnelTarget.value = { orderId: String(orderId), lineId, role: salesOrderPersonnelTargetRole(role), uiRole: role, recordVersion: record?.revision ?? record?.recordVersion }
  salesPersonnelSubmitting.value = false
  const result = await requestAction('open-sales-order-personnel-adjustment', {
    orderId,
    recordVersion: record?.revision ?? record?.recordVersion,
    targetOrderLineId: lineId,
    targetRole: salesOrderPersonnelTargetRole(role)
  })
  const entry = actionData(result).orderPersonnelAdjustment
  if (!entry || !['success', 'succeeded'].includes(String(actionStatus(result)))) {
    salesPersonnelError.value = result?.result?.message || result?.data?.result?.message || '人员资料读取失败，请刷新后重试。'
    return
  }
  const target = salesPersonnelTarget.value
  const line = Array.isArray(entry.lines) ? entry.lines.find((candidate) => String(candidate.orderLineId) === lineId) : null
  if (!target || !line) {
    salesPersonnelError.value = '订单明细已变化，请刷新后重试。'
    return
  }
  const authoritativeVersion = Number(entry.recordVersion)
  const stateContextId = String(state.stateContextId || '')
  if (!Number.isSafeInteger(authoritativeVersion) || authoritativeVersion <= 0 || !stateContextId) {
    salesPersonnelError.value = '订单版本读取失败，请刷新后重新打开。'
    return
  }
  // This projection is opened outside the root order-center query. Register
  // the server-issued version explicitly so the subsequent single-line
  // personnel command cannot fall back to the stale table-row revision.
  mergeCashierV3PublicVersions([{
    kind: 'sales_order', id: String(entry.salesOrderId || orderId), version: authoritativeVersion
  }], stateContextId, { requestStateContextId: stateContextId })
  salesPersonnelEntry.value = { ...entry, lines: [line] }
  salesPersonnelEditorOpen.value = true
}

function salesPersonnelCandidates(role) {
  const entry = salesPersonnelEntry.value || {}
  return role === 'guide' ? (entry.guides || [])
    : role === 'sales_manager' ? (entry.salesManagers || [])
      : role === 'craftsman' ? (entry.craftsmen || []) : (entry.salespeople || [])
}

async function searchSalesPersonnelAttributions({ scope, keyword, target } = {}) {
  const role = target === 'guide' ? 'guide' : target === 'salesManager' ? 'sales_manager' : ''
  const entry = salesPersonnelEntry.value
  const activeTarget = salesPersonnelTarget.value
  const searchKeyword = String(keyword || '').trim()
  if (scope !== 'group_attributions' || !role || activeTarget?.role !== role
    || !salesPersonnelEditorOpen.value || !entry || searchKeyword.length < 2) return
  const serial = ++salesPersonnelSearchSerial
  salesPersonnelSearchError.value = ''
  salesPersonnelSearchLoading.value = true
  try {
    const records = []
    let page = 1
    let total = 0
    do {
      const result = await requestAction('query-query-entities', {
        entityType: 'person',
        // 订单中心只提交关键词和固定业务入口；集团范围与在职资格均由服务端判定。
        selectorEntry: 'order_center',
        selectorContext: {
          scope: 'group_attributions',
          lineId: activeTarget.lineId,
          lineRole: 'sale',
          memberId: entry.memberId || ''
        },
        keyword: searchKeyword,
        page,
        pageSize: 20,
        silent: true
      })
      const data = actionData(result)
      if (!['success', 'succeeded'].includes(String(actionStatus(result))) || !Array.isArray(data.records)) {
        throw new Error(result?.result?.message || result?.data?.result?.message || '集团人员搜索失败，请重试。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page += 1
    } while (records.length < total && page <= 20)
    // 弹窗切换人员、关闭或再次搜索后，旧响应不得覆盖当前候选与已选归属。
    if (salesPersonnelSearchSerial !== serial || salesPersonnelEntry.value !== entry
      || salesPersonnelTarget.value !== activeTarget || !salesPersonnelEditorOpen.value) return
    salesPersonnelEntry.value = {
      ...entry,
      ...(role === 'guide' ? { guides: records } : { salesManagers: records })
    }
  } catch (error) {
    if (salesPersonnelSearchSerial !== serial || salesPersonnelEntry.value !== entry
      || salesPersonnelTarget.value !== activeTarget || !salesPersonnelEditorOpen.value) return
    salesPersonnelSearchError.value = error instanceof Error && error.message
      ? error.message : '集团人员搜索失败，请重试。'
  } finally {
    if (salesPersonnelSearchSerial === serial) salesPersonnelSearchLoading.value = false
  }
}

function salesPersonnelSelected(role, line) {
  if (role === 'guide') return line?.currentGuides || []
  if (role === 'sales_manager') return line?.currentSalesManagers || []
  if (role === 'craftsman') return line?.currentCraftsmen || []
  return line?.currentSalespeople || []
}

function salesPersonnelInitialSelection(role, line) {
  const candidates = salesPersonnelCandidates(role)
  return salesPersonnelSelected(role, line).map((selected) => {
    const employeeId = Number(selected.employeeId || selected.staffId || selected.id || 0)
    const candidate = candidates.find((entry) => Number(entry.employeeId || entry.staffId || entry.id || 0) === employeeId)
    return {
      ...selected,
      ...(candidate || {}),
      id: candidate?.staffId || employeeId,
      staffId: candidate?.staffId || employeeId,
      employeeId,
      performanceAmountCents: Math.max(0, Math.trunc(Number(
        selected.performanceAmountCents ?? selected.performance_amount_cents ?? 0
      ))),
      performanceAmountManual: Boolean(
        selected.performanceAmountManual ?? selected.performance_amount_manual
      ),
      selected: true
    }
  })
}

function closeSalesPersonnelEditor(force = false) {
  if (salesPersonnelSubmitting.value && !force) return
  salesPersonnelSearchSerial += 1
  salesPersonnelSearchLoading.value = false
  salesPersonnelSearchError.value = ''
  salesPersonnelEditorOpen.value = false
  salesPersonnelEntry.value = null
  salesPersonnelTarget.value = null
}

function prepareSalesPersonnelReason(assignment = {}) {
  salesPersonnelPendingAssignment.value = assignment
  salesPersonnelReason.value = ''
  salesPersonnelError.value = ''
  salesPersonnelEditorOpen.value = false
}

function cancelSalesPersonnelReason() {
  if (salesPersonnelSubmitting.value) return
  salesPersonnelPendingAssignment.value = null
  salesPersonnelReason.value = ''
  salesPersonnelError.value = ''
  salesPersonnelEditorOpen.value = Boolean(salesPersonnelEntry.value)
}

function salesPersonnelPayload() {
  const target = salesPersonnelTarget.value
  const assignment = salesPersonnelPendingAssignment.value || {}
  if (!target) return []
  if (target.role === 'salesperson') {
    return (assignment.salespeople || []).map((item) => ({
      orderLineId: target.lineId,
      role: target.role,
      staffId: Number(item.staffId || item.id || 0),
      allocationWeight: Number(item.allocationWeight || item.performance || 0),
      isPreSale: Boolean(item.isPreSale || item.marked),
      positionId: Number(item.positionId || item.position_id || 0),
      positionName: item.positionName || item.position_name || item.position || '',
      performanceIndependent: Boolean(item.performanceIndependent || item.performance_independent),
      allocationGroupKey: item.allocationGroupKey || '',
      // “业绩金额”是订单调整的最终业务输入，必须与手工标记成对提交；
      // 只传比例会让服务端按原订单金额重新计算，覆盖用户刚输入的金额。
      performanceAmountCents: Math.max(0, Math.trunc(Number(item.performanceAmountCents || 0))),
      performanceAmountManual: Boolean(item.performanceAmountManual)
    }))
  }
  if (target.role === 'guide') {
    return (assignment.guideSelections || []).map((item) => ({
      orderLineId: target.lineId,
      role: target.role,
      staffId: Number(item.employeeId || item.staffId || item.id || 0),
      guideRoundNo: Number(item.guideRoundNo || 0)
    }))
  }
  if (target.role === 'craftsman') {
    return (assignment.craftsmen || []).map((item) => ({
      orderLineId: target.lineId,
      role: target.role,
      staffId: Number(item.staffId || item.id || 0),
      isPointCustomer: Boolean(item.isPointCustomer || item.marked),
      // 销售订单直接购买项目没有服务事实，三个劳动指标必须以用户
      // 输入的最终值提交，不能再按售价 ¥120 或百分比重新推导。
      allocationAmountCents: Math.max(0, Math.trunc(Number(item.allocationAmountCents || item.performanceAmountCents || 0))),
      laborFeeCents: Math.max(0, Math.trunc(Number(item.laborFeeCents || 0))),
      projectCount: String(item.projectCount ?? item.projectCountText ?? '0')
    }))
  }
  return (assignment.salesManagerSelections || []).map((item) => ({
    orderLineId: target.lineId,
    role: target.role,
    staffId: Number(item.employeeId || item.staffId || item.id || 0)
  }))
}

async function submitSalesPersonnelAdjustment() {
  const target = salesPersonnelTarget.value
  const entry = salesPersonnelEntry.value
  const reason = String(salesPersonnelReason.value || '').trim()
  const personnel = salesPersonnelPayload()
  if (!target || !entry) return
  if (!reason) { salesPersonnelError.value = '请填写修改原因。'; return }
  if (reason.length > 255) { salesPersonnelError.value = '修改原因不能超过255字。'; return }
  if (!personnel.length) { salesPersonnelError.value = '请至少选择一名人员。'; return }
  const key = `${target.orderId}:${target.lineId}:${target.role}:${entry.recordVersion || target.recordVersion || ''}`
  const idempotencyKey = salesPersonnelCommandIds.value[key] || createCashierV3CommandId()
  salesPersonnelCommandIds.value = { ...salesPersonnelCommandIds.value, [key]: idempotencyKey }
  salesPersonnelSubmitting.value = true
  salesPersonnelError.value = ''
  try {
    const result = await requestAction('adjust-sales-order-personnel', {
      orderId: target.orderId,
      recordVersion: entry.recordVersion ?? target.recordVersion,
      targetOrderLineId: target.lineId,
      targetRole: target.role,
      personnel,
      reason,
      idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) salesPersonnelCommandIds.value = { ...salesPersonnelCommandIds.value, [key]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeSalesPersonnelEditor(true)
      salesPersonnelPendingAssignment.value = null
      // A personnel write changes this order's lifecycle version. The current
      // sales cursor is a signed pre-write snapshot, so reuse would be
      // rejected even though the write succeeded. Refresh the first page with
      // a new cursor instead of showing a false failure message.
      // 写命令已成功时不得再把后续列表回刷异常展示为
      // “修改失败”。回刷使用新的首页游标且静默执行，写入结果
      // 仍以上方已校验的服务端命令回执为准。
      await queryRecords({ silent: true }, true)
      return
    }
    salesPersonnelError.value = result?.result?.message || result?.data?.result?.message || '人员修改未完成，请稍后重试。'
  } finally {
    salesPersonnelSubmitting.value = false
  }
}

function recordPersonnelActionNames(type) {
  return type === 'recharge'
    ? { open: 'open-recharge-personnel-adjustment', adjust: 'adjust-recharge-personnel' }
    : { open: 'open-supplement-personnel-adjustment', adjust: 'adjust-supplement-personnel' }
}

function recordPersonnelId(record, type) {
  if (type === 'recharge') return String(record?.rechargeId || String(record?.id || '').replace(/^recharge:/, ''))
  return String(record?.repaymentId || String(record?.id || '').replace(/^v3-(?:sales-)?supplement:/, ''))
}

function recordPersonnelSelected(line = {}) {
  return Array.isArray(line.currentSalespeople) ? line.currentSalespeople : []
}

function recordPersonnelCandidates(entry = {}) {
  return Array.isArray(entry.salespeople) ? entry.salespeople : []
}

function recordPersonnelInitialSelection(line = {}) {
  const candidates = recordPersonnelCandidates(recordPersonnelEntry.value || {})
  return recordPersonnelSelected(line).map((selected) => {
    const employeeId = Number(selected.employeeId || selected.staffId || selected.id || 0)
    const candidate = candidates.find((item) => Number(item.employeeId || item.staffId || item.id || 0) === employeeId)
    // 充值／补交打开的是已落账记录。此处必须把服务端事实金额直接交给
    // 分配组件，不能因候选员工只有岗位资料而退回默认 0 或按当前比例重算。
    const performanceAmountCents = Math.max(0, Math.trunc(Number(
      selected.performanceAmountCents
        ?? selected.performance_amount_cents
        ?? selected.amountCents
        ?? selected.amount_cents
        ?? 0
    )))
    return {
      ...selected,
      ...(candidate || {}),
      id: candidate?.staffId || selected.staffId || employeeId,
      staffId: candidate?.staffId || selected.staffId || employeeId,
      employeeId,
      performanceAmountCents,
      performanceAmountLocked: Boolean(
        selected.performanceAmountLocked
          ?? selected.performance_amount_locked
          ?? Object.prototype.hasOwnProperty.call(selected, 'amountCents')
      ),
      selected: true
    }
  })
}

async function openRecordPersonnelEditor(record) {
  const type = activeTabKey.value
  if (!['recharge', 'supplement'].includes(type) || !record
    || !canUseCashierV3Operation('cashier.v3.order.staff_adjust')) return
  const recordId = recordPersonnelId(record, type)
  if (!recordId) return
  const actions = recordPersonnelActionNames(type)
  recordPersonnelError.value = ''
  recordPersonnelEntry.value = null
  recordPersonnelTarget.value = { type, recordId }
  recordPersonnelEditorOpen.value = false
  recordPersonnelSubmitting.value = false
  try {
    const result = await requestAction(actions.open, { recordId })
    const entry = actionData(result).personnelAdjustment
    if (!entry || !['success', 'succeeded'].includes(String(actionStatus(result)))) {
      recordPersonnelError.value = result?.result?.message || result?.data?.result?.message || '销售人资料读取失败，请刷新后重试。'
      return
    }
    const recordVersion = Number(entry.recordVersion)
    const stateContextId = String(state.stateContextId || '')
    if (!Number.isSafeInteger(recordVersion) || recordVersion <= 0 || !stateContextId) {
      recordPersonnelError.value = '销售人记录版本读取失败，请刷新后重新打开。'
      return
    }
    const kind = type === 'recharge' ? 'recharge_order' : 'debt_repayment'
    mergeCashierV3PublicVersions([{ kind, id: String(entry.recordId || recordId), version: recordVersion }], stateContextId, { requestStateContextId: stateContextId })
    recordPersonnelEntry.value = entry
    recordPersonnelEditorOpen.value = true
  } catch (error) {
    recordPersonnelError.value = error?.message || '销售人资料读取失败，请刷新后重试。'
  }
}

function closeRecordPersonnelEditor(force = false) {
  // A successful command resolves while the submitting flag is still true.
  // Keep the guard for user-initiated close actions, but allow the confirmed
  // success path to clear the editor immediately.
  if (recordPersonnelSubmitting.value && !force) return
  recordPersonnelEntry.value = null
  recordPersonnelTarget.value = null
  recordPersonnelEditorOpen.value = false
  recordPersonnelPendingAssignment.value = null
  recordPersonnelReason.value = ''
}

function prepareRecordPersonnelReason(assignment = {}) {
  recordPersonnelPendingAssignment.value = assignment
  recordPersonnelReason.value = ''
  recordPersonnelError.value = ''
  recordPersonnelEditorOpen.value = false
}

function cancelRecordPersonnelReason() {
  if (recordPersonnelSubmitting.value) return
  recordPersonnelPendingAssignment.value = null
  recordPersonnelReason.value = ''
  recordPersonnelError.value = ''
  recordPersonnelEditorOpen.value = Boolean(recordPersonnelEntry.value)
}

async function submitRecordPersonnelAdjustment() {
  const entry = recordPersonnelEntry.value
  const target = recordPersonnelTarget.value
  const assignment = recordPersonnelPendingAssignment.value || {}
  const reason = String(recordPersonnelReason.value || '').trim()
  if (!entry || !target) return
  if (!reason) { recordPersonnelError.value = '请填写修改原因。'; return }
  if (reason.length > 255) { recordPersonnelError.value = '修改原因不能超过255字。'; return }
  const personnel = (assignment.salespeople || []).map((item) => ({
    staffId: Number(item.staffId || item.id || 0),
    allocationWeight: Number(item.allocationWeight || item.performance || 0),
    isPreSale: Boolean(item.isPreSale || item.marked),
    performanceAmountCents: Math.max(0, Math.trunc(Number(item.performanceAmountCents || 0))),
    performanceAmountManual: Boolean(item.performanceAmountManual),
    positionId: Number(item.positionId || item.position_id || 0),
    positionName: item.positionName || item.position_name || item.position || '',
    performanceIndependent: Boolean(item.performanceIndependent || item.performance_independent),
    allocationGroupKey: item.allocationGroupKey || ''
  }))
  if (!personnel.length) { recordPersonnelError.value = '请至少选择一名销售人。'; return }
  const actions = recordPersonnelActionNames(target.type)
  const key = `${target.type}:${target.recordId}:${entry.recordVersion || ''}`
  const idempotencyKey = recordPersonnelCommandIds.value[key] || createCashierV3CommandId()
  recordPersonnelCommandIds.value = { ...recordPersonnelCommandIds.value, [key]: idempotencyKey }
  recordPersonnelSubmitting.value = true
  recordPersonnelError.value = ''
  try {
    const result = await requestAction(actions.adjust, {
      recordId: target.recordId,
      recordVersion: entry.recordVersion,
      personnel,
      reason,
      idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) recordPersonnelCommandIds.value = { ...recordPersonnelCommandIds.value, [key]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeRecordPersonnelEditor(true)
      await queryRecords({}, false)
      return
    }
    recordPersonnelError.value = result?.result?.message || result?.data?.result?.message || '销售人修改未完成，请稍后重试。'
  } finally {
    recordPersonnelSubmitting.value = false
  }
}

function openSalesOrderNoteEditor(record) {
  if (!record?.id && !record?.orderId) return
  salesOrderNoteRecord.value = record
  salesOrderNoteValue.value = String(record.orderNote ?? record.remark ?? record.note ?? '')
  salesOrderNoteError.value = ''
}

function closeSalesOrderNoteEditor() {
  if (salesOrderNoteSubmitting.value) return
  salesOrderNoteRecord.value = null
  salesOrderNoteValue.value = ''
  salesOrderNoteError.value = ''
}

async function submitSalesOrderNote() {
  const record = salesOrderNoteRecord.value
  if (!record) return
  const orderId = salesOrderLifecycleId(record)
  if (!orderId) { salesOrderNoteError.value = '订单资源未绑定，请刷新订单列表后重试。'; return }
  const note = String(salesOrderNoteValue.value || '').trim()
  if (note.length > 500) { salesOrderNoteError.value = '备注不能超过500字。'; return }
  const key = `${orderId}:${record.revision ?? record.recordVersion ?? ''}`
  const idempotencyKey = salesOrderNoteCommandIds.value[key] || createCashierV3CommandId()
  salesOrderNoteCommandIds.value = { ...salesOrderNoteCommandIds.value, [key]: idempotencyKey }
  salesOrderNoteSubmitting.value = true
  salesOrderNoteError.value = ''
  try {
    const result = await requestAction('update-sales-order-note', {
      orderId,
      recordVersion: record.revision ?? record.recordVersion,
      orderNote: note,
      idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) salesOrderNoteCommandIds.value = { ...salesOrderNoteCommandIds.value, [key]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeSalesOrderNoteEditor()
      // See personnel adjustment above: a lifecycle write invalidates the
      // signed list cursor, therefore refresh from a fresh first-page query.
      await queryRecords({}, true)
      return
    }
    salesOrderNoteError.value = result?.result?.message || result?.data?.result?.message || '备注保存失败，请稍后重试。'
  } finally {
    salesOrderNoteSubmitting.value = false
  }
}

async function openSalesOrderLifecycle(record, action, permission) {
  const orderId = record?.id || record?.orderId
  if (!orderId || !salesOrderActionAvailable(record, action, permission)) return
  await openSalesOrderDetail({ orderId, actionOnly: true, initialLifecycleAction: action })
}

function itemMoney(value) {
  return value === undefined || value === null || value === '' ? '—' : formatMoney(value)
}

function orderPaymentDetails(record) {
  return Array.isArray(record?.paymentDetails)
    ? record.paymentDetails.filter((payment) => payment && payment.methodName && payment.amount !== undefined && payment.amount !== null)
    : []
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
  queryRecords(defaultOrderCenterDateQuery(), true)
}

function orderCenterToday() {
  const now = new Date()
  const offset = now.getTimezoneOffset() * 60000
  return new Date(now.getTime() - offset).toISOString().slice(0, 10)
}

function businessDateRangeFromQuery(query = {}) {
  let from = String(query.businessDateFrom ?? query.business_date_from ?? query.dateFrom ?? query.date_from ?? '').trim()
  let to = String(query.businessDateTo ?? query.business_date_to ?? query.dateTo ?? query.date_to ?? '').trim()
  for (const filter of Array.isArray(query.topFilters) ? query.topFilters : []) {
    if (String(filter?.field || '') !== 'business_date') continue
    const value = String(filter?.value ?? '').trim()
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) continue
    if (filter?.operator === 'gte') from = value
    if (filter?.operator === 'lte') to = value
    if (filter?.operator === 'eq') {
      from = value
      to = value
    }
  }
  return { from, to }
}

function normalizeOrderCenterDateQuery(query = {}) {
  const { from, to } = businessDateRangeFromQuery(query)
  return {
    ...query,
    ...(from ? { dateFrom: from, businessDateFrom: from } : {}),
    ...(to ? { dateTo: to, businessDateTo: to } : {})
  }
}

function defaultOrderCenterDateQuery() {
  // 八个页签的首屏日期框都显示今天，发送给后端的日期别名和结构化
  // business_date 过滤也必须同时锁定今天，避免展示范围与真实数据不一致。
  const today = orderCenterToday()
  return {
    dataScope: 'normal',
    businessStatus: '',
    dateFrom: today,
    dateTo: today,
    businessDateFrom: today,
    businessDateTo: today,
    topFilters: [
      { field: 'business_date', operator: 'gte', value: today },
      { field: 'business_date', operator: 'lte', value: today }
    ]
  }
}

function reportServiceDateQuery() {
  const drill = serviceReportDrilldown.value
  if (!drill) return null
  return {
    // 手艺人消耗是当前有效服务报表；作废服务只留在审计查询中，
    // 不能通过下钻重新混入已排除作废数据的经营结果。
    dataScope: 'normal', businessStatus: '', status: '', keyword: '', sorts: [], storeIds: [drill.storeId],
    dateFrom: drill.from, dateTo: drill.to,
    businessDateFrom: drill.from, businessDateTo: drill.to,
    topFilters: [
      { field: 'business_date', operator: 'gte', value: drill.from },
      { field: 'business_date', operator: 'lte', value: drill.to }
    ],
    servicePerformanceDrilldown: {
      employeeId: drill.employeeId, storeId: drill.storeId,
      from: drill.from, to: drill.to, dayOfMonth: drill.dayOfMonth
    }
  }
}

function reportSalesDateQuery() {
  const drill = salesReportDrilldown.value
  if (!drill) return null
  return {
    // 销售人业绩包含退款或人员调整的反向事实，不能用“正常订单”状态
    // 提前隐藏来源订单；最终订单集合由后端按同一人员业绩事实精确收敛。
    dataScope: 'all', businessStatus: '', status: '', keyword: '', sorts: [], storeIds: [drill.storeId],
    dateFrom: drill.from, dateTo: drill.to,
    businessDateFrom: drill.from, businessDateTo: drill.to,
    topFilters: [
      { field: 'business_date', operator: 'gte', value: drill.from },
      { field: 'business_date', operator: 'lte', value: drill.to }
    ],
    salesPerformanceDrilldown: {
      employeeId: drill.employeeId, storeId: drill.storeId,
      from: drill.from, to: drill.to, dayOfMonth: drill.dayOfMonth
    }
  }
}

function initialOrderCenterQuery() {
  // 首屏复用本地规则，但不恢复过去的日期或人员输入；默认业务日仍是当天。
  const settings = querySettings.value || {}
  return reportServiceDateQuery() || reportSalesDateQuery() || {
    ...defaultOrderCenterDateQuery(),
    filters: settings.filters || [], filterRelation: settings.filterRelation || 'and',
    sorts: settings.sorts || [], visibleFields: settings.visibleFields || [],
    groupBy: settings.groupBy || [], summaries: settings.summaries || []
  }
}

function leaveServiceReportDrilldown() {
  queryModelByType.value = { ...queryModelByType.value, service: {} }
  router.replace({ name: route.name, query: { tab: 'service' } })
}

function returnToServiceReport() {
  if (serviceReportReturnLocation.value) router.push(serviceReportReturnLocation.value)
}

function leaveSalesReportDrilldown() {
  queryModelByType.value = { ...queryModelByType.value, sales: {} }
  salesPageCursors.value = {}
  router.replace({ name: route.name, query: { tab: 'sales' } })
}

function returnToSalesReport() {
  if (salesReportReturnLocation.value) router.push(salesReportReturnLocation.value)
}

// 销售订单的范围由统一工具栏驱动：正常数据必须落到后端 normal
// 状态过滤，全部数据才允许使用空状态；不能在前端拿当前页再隐藏。
function normalizeSalesOrderQuery(query = {}) {
  const scope = String(query.dataScope ?? query.data_scope ?? query.scope ?? '').trim()
  let status = String(query.status || '').trim()
  if (scope === 'normal') {
    status = 'normal'
  } else if (scope === 'all') {
    status = String(query.businessStatus ?? query.business_status ?? '').trim()
  } else if (!status) {
    status = String(query.businessStatus ?? query.business_status ?? '').trim() || 'normal'
  }
  return { ...normalizeOrderCenterDateQuery(query), status }
}

function applyPlatformScope(query = {}) {
  if (!isPlatformReadOnly.value) return query
  const { storeIds: ignoredStoreIds, store_ids: ignoredStoreIdsSnakeCase, ...rest } = query
  return platformScopeStoreIds.value.length
    ? { ...rest, storeIds: [...platformScopeStoreIds.value] }
    : rest
}

async function loadPlatformOrderScope() {
  if (!isPlatformReadOnly.value || platformScopePicker.value.loading) return
  platformScopePicker.value = { ...platformScopePicker.value, loading: true }
  try {
    const response = await queryPlatformOrderCenterScope()
    const allowedStoreIds = Array.isArray(response?.allowed_store_ids)
      ? response.allowed_store_ids.map(Number).filter(Boolean)
      : []
    platformScopePicker.value = {
      ...platformScopePicker.value,
      loading: false,
      tree: Array.isArray(response?.tree) ? response.tree : [],
      allowedStoreIds
    }
  } catch (_) {
    // 订单查询仍由服务端数据权限保护。范围树异常时保留“当前权限范围”，
    // 避免把前端失败误解为没有订单数据。
    platformScopePicker.value = { ...platformScopePicker.value, loading: false, tree: [], allowedStoreIds: [] }
  }
}

function changePlatformOrderScope({ storeIds = [], label = '当前权限范围' } = {}) {
  const allowed = new Set(platformScopePicker.value.allowedStoreIds.map(Number).filter(Boolean))
  platformScopeStoreIds.value = [...new Set(storeIds.map(Number).filter((id) => allowed.has(id)))]
  platformScopePicker.value = {
    ...platformScopePicker.value,
    label: platformScopeStoreIds.value.length ? String(label || '已选范围') : '当前权限范围'
  }
  queryRecords({}, true)
}

async function queryRecords(query = {}, resetPage = true) {
  const recordType = activeTabKey.value
  // 只统一读取/刷新，不介入作废和人员调整命令。无新筛选的调用复用最后成功快照。
  const explicitQuery = Object.prototype.hasOwnProperty.call(query, 'topFilters')
  const previous = executedQueryByType.value[recordType] || queryModelByType.value[recordType] || {}
  query = mergeQueryRefresh(previous, query)
  if (!explicitQuery && previous.page) resetPage = false
  if (recordType !== 'sales') {
    const currentQuery = queryModelByType.value[recordType] || {}
    const requestedPageSize = Math.max(1, Number(query.pageSize ?? query.limit) || pageSize.value)
    const pageSizeChanged = Number(currentQuery.pageSize || pageSize.value) !== requestedPageSize
    const targetPage = resetPage || pageSizeChanged ? 1 : Math.max(1, Number(query.page) || page.value)
    const nextQuery = normalizeOrderCenterDateQuery(applyPlatformScope({
      ...currentQuery,
      ...query,
      ...(recordType === 'service' ? reportServiceDateQuery() || {} : {}),
      recordType,
      page: targetPage,
      pageSize: requestedPageSize
    }))
    queryModelByType.value = { ...queryModelByType.value, [recordType]: nextQuery }
    const sequence = ++recordQuerySequence
    isOrderQueryLoading.value = true
    try {
      const result = await requestAction('query-order-center-records', nextQuery)
      const projection = salesOrderProjectionFromResult(result)
      if (projection && sequence === recordQuerySequence && activeTabKey.value === recordType) {
        state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
        saveExecutedQuery(recordType, nextQuery)
        // 删除/作废后的末页可能变空，只回退查询页码，不再次执行业务操作。
        if (!records.value.length && targetPage > 1) return await queryRecords({ ...nextQuery, page: targetPage - 1 }, false)
      }
      return result
    } finally {
      if (sequence === recordQuerySequence) isOrderQueryLoading.value = false
    }
  }
  const currentQuery = queryModelByType.value[recordType] || {}
  const requestedPageSize = Math.max(1, Number(query.pageSize ?? query.limit) || pageSize.value)
  const pageSizeChanged = Number(currentQuery.pageSize || pageSize.value) !== requestedPageSize
  const targetPage = resetPage || pageSizeChanged ? 1 : Math.max(1, Number(query.page) || page.value)
  if (resetPage || pageSizeChanged) salesPageCursors.value = {}
  const cursor = salesPageCursors.value[targetPage] || ''
  // 统一查询在完整授权候选上筛选后分页；仅旧 keyset 响应需要签名游标。
  const unifiedOffset = state.orderCenter?.pagesByType?.sales?.paginationMode === 'offset'
  if (targetPage > 1 && !cursor && !unifiedOffset) {
    return {
      result: {
        status: 'failed',
        code: 'SALES_ORDER_CURSOR_REQUIRED',
        message: '请按顺序翻页；当前页码没有可用的签名游标。'
      }
    }
  }
  const nextQuery = normalizeSalesOrderQuery(applyPlatformScope({
    ...currentQuery,
    ...query,
    ...(reportSalesDateQuery() || {}),
    recordType,
    page: targetPage,
    pageSize: requestedPageSize
  }))
  const cursorQuery = nextSalesOrderQueryWithCursor(nextQuery, cursor)
  queryModelByType.value = { ...queryModelByType.value, sales: cursorQuery }

  const sequence = ++salesQuerySequence
  isOrderQueryLoading.value = true
  try {
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
      saveExecutedQuery(recordType, cursorQuery)
      if (!records.value.length && targetPage > 1) return await queryRecords({ ...nextQuery, page: targetPage - 1 }, false)
    }
    return result
  } finally {
    if (sequence === salesQuerySequence) isOrderQueryLoading.value = false
  }
}

async function refreshRefundRecords() {
  const recordType = 'refund'
  const currentQuery = queryModelByType.value[recordType] || {}
  const nextQuery = {
    ...currentQuery,
    recordType,
    page: 1,
    pageSize: Math.max(1, Number(currentQuery.pageSize) || pageSize.value)
  }
  queryModelByType.value = { ...queryModelByType.value, [recordType]: nextQuery }
  const result = await requestAction('query-order-center-records', nextQuery)
  const projection = salesOrderProjectionFromResult(result)
  if (projection) state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
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
  // V3 responses may be returned either directly or wrapped by the HTTP
  // adapter under `data`; personnel-adjustment writes use the standard
  // `{ result: { status }, data: ... }` envelope. Read all supported layers
  // so a successful write closes its reason dialog instead of appearing stuck.
  return result?.result?.status
    || result?.data?.result?.status
    || result?.status
    || result?.data?.status
    || ''
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

function salesOrderDetailId(detail) {
  if (!detail || typeof detail !== 'object') return ''
  return String(detail.id || detail.orderId || detail.salesOrderId || detail.lifecycleOrderId || '')
}

function salesOrderDetailNo(detail) {
  if (!detail || typeof detail !== 'object') return ''
  return String(detail.salesOrderNo || detail.sales_order_no || detail.orderNo || detail.no || '')
}

function salesDetailBelongsToOrder(detail, orderId, orderNo = '') {
  if (!detail) return false
  if (orderId && salesOrderDetailId(detail) === String(orderId)) return true
  return Boolean(orderNo) && salesOrderDetailNo(detail) === String(orderNo)
}

function salesDetailResponseMessage(result, fallback) {
  return String(
    result?.result?.message
      || result?.data?.result?.message
      || result?.message
      || result?.data?.message
      || fallback
  )
}

function showSalesOrderDetail(payload = {}) {
  salesDetailSequence++
  salesDetailLoadError.value = ''
  const detail = payload?.detail || payload
  const candidateOrderId = detail.orderId
    || detail.salesOrderId
    || detail.record?.orderId
    || detail.record?.salesOrderId
    || detail.record?.id
    || detail.id
  const candidateOrderNo = salesOrderDetailNo(detail)
  const record = candidateOrderId || candidateOrderNo
    ? records.value.find((item) => salesDetailBelongsToOrder(item, candidateOrderId, candidateOrderNo))
    : detail.record || detail
  const orderId = candidateOrderId || record?.id || record?.orderId || record?.salesOrderId
  if (!orderId) return null
  salesDetailFocus.value = {
    lifecycleAction: String(payload.initialLifecycleAction || ''),
    personnelRole: String(payload.focusPersonnelRole || ''),
    personnelLineId: String(payload.focusPersonnelLineId || '')
  }
  salesDetailActionOnly.value = payload.actionOnly === true
  const requestedOrderNo = candidateOrderNo || salesOrderDetailNo(record)
  const backendDetail = salesDetailBelongsToOrder(orderCenter.value.salesOrderDetail, orderId, requestedOrderNo)
    ? orderCenter.value.salesOrderDetail
    : null
  // Keep the requested identity even when a concurrent list refresh has
  // replaced the row before this click handler finishes. Without this
  // placeholder the overlay enters its blank loading state with no order id
  // and can spin forever while the authoritative detail request is pending.
  salesDetailOrder.value = backendDetail || record || {
    id: String(orderId), orderId: String(orderId), salesOrderId: String(orderId)
  }
  isSalesDetailOpen.value = true
  return { record, orderId, orderNo: requestedOrderNo }
}

async function openSalesOrderDetail(payload = {}) {
  const current = showSalesOrderDetail(payload)
  if (!current) return { success: false, message: '未找到销售订单。' }
  const requestSequence = ++salesDetailSequence
  const requestedOrderId = current.orderId
  const requestedOrderNo = current.orderNo || salesOrderDetailNo(salesDetailOrder.value)
  isSalesDetailLoading.value = true
  let timeoutHandle = null
  try {
    const detailRequest = requestAction('open-sales-order-detail', {
      orderId: current.orderId,
      recordVersion: current.record?.revision ?? current.record?.recordVersion
    })
    const timeout = new Promise((resolve) => {
      timeoutHandle = setTimeout(() => resolve({
        result: {
          status: 'failed',
          code: 'SALES_ORDER_DETAIL_TIMEOUT',
          message: '销售订单详情读取超过15秒，已停止等待；请刷新订单列表后重试。'
        }
      }), SALES_DETAIL_REQUEST_TIMEOUT_MS)
    })
    const result = await Promise.race([detailRequest, timeout])
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
    if (canApply) {
      const status = actionStatus(result)
      const detail = projection?.salesOrderDetail
      if (['failed', 'conflict'].includes(status)) {
        salesDetailLoadError.value = salesDetailResponseMessage(result, '订单详情读取失败，请刷新订单列表后重试。')
      } else if (salesDetailBelongsToOrder(detail, requestedOrderId, requestedOrderNo)) {
        salesDetailOrder.value = detail
      } else {
        salesDetailLoadError.value = detail
          ? '订单详情返回的订单标识不一致，请刷新订单列表后重试。'
          : salesDetailResponseMessage(result, '订单详情未返回有效数据，请刷新订单列表后重试。')
      }
    }
    return result
  } catch (error) {
    if (isSalesDetailOpen.value && requestSequence === salesDetailSequence) {
      salesDetailLoadError.value = error?.message || '订单详情读取失败，请刷新订单列表后重试。'
    }
    return {
      result: { status: 'failed', code: 'SALES_ORDER_DETAIL_READ_FAILED', message: salesDetailLoadError.value }
    }
  } finally {
    if (timeoutHandle) clearTimeout(timeoutHandle)
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
  salesDetailLoadError.value = ''
  salesDetailFocus.value = { lifecycleAction: '', personnelRole: '', personnelLineId: '' }
  salesDetailActionOnly.value = false
}

function openRecordDetail(record) {
  if (isPlatformReadOnly.value) return null
  if (activeTabKey.value === 'sales') {
    return openSalesOrderDetail({ orderId: record?.id || record?.orderId || record?.salesOrderId })
  }
  if (activeTabKey.value === 'service' && !canUseCashierV3Operation('cashier.v3.order.service_detail')) return
  genericDetailRecord.value = record
  return null
}

async function printSalesOrderRecord(record = {}) {
  const orderId = String(record?.id || record?.orderId || record?.salesOrderId || '').trim()
  if (!orderId || salesPrintLoading.value[orderId]) return
  servicePrintError.value = ''
  salesPrintLoading.value = { ...salesPrintLoading.value, [orderId]: true }
  try {
    const result = await requestAction('open-sales-order-detail', { orderId })
    if (['failed', 'conflict', 'result_unknown'].includes(actionStatus(result))) {
      servicePrintError.value = result?.result?.message || result?.message || '销售小票读取失败，请稍后重试。'
      return
    }
    const detail = salesOrderProjectionFromResult(result)?.salesOrderDetail
    if (!detail || String(detail.id || detail.orderId || detail.salesOrderId) !== orderId) {
      servicePrintError.value = '销售订单详情尚未完整返回，请稍后重试。'
      return
    }
    const printed = openSalesOrderReceiptPrint(detail)
    if (!printed.ok) servicePrintError.value = printed.message
  } catch (error) {
    servicePrintError.value = error?.message || '销售小票暂时无法生成，请稍后重试。'
  } finally {
    const next = { ...salesPrintLoading.value }
    delete next[orderId]
    salesPrintLoading.value = next
  }
}

function serviceCraftsmanAdjustable(record = {}) {
  const serviceFactId = record.serviceFactId || String(record.id || '').replace(/^service:/, '')
  return /^[1-9][0-9]*$/.test(String(serviceFactId))
    && !record.voidedAt
    && record.serviceStatus !== '已作废'
    && record.serviceStatus !== 'voided'
}

function serviceRecordIsNormal(record = {}) {
  return activeTabKey.value === 'service' && serviceCraftsmanAdjustable(record)
}

async function openServiceCraftsmanAdjustment(record) {
  // 销售单的项目行只提供服务事实 ID；打开、版本校验、提交和
  // 幂等保护仍完整复用服务记录命令，不新建销售单人员修改通道。
  if (!serviceCraftsmanAdjustable(record) || !canUseCashierV3Operation('cashier.v3.order.service_detail')) return
  const serviceFactId = record.serviceFactId || String(record.id || '').replace(/^service:/, '')
  if (!/^[1-9][0-9]*$/.test(String(serviceFactId))) return
  serviceCraftsmanLoading.value = true
  serviceCraftsmanError.value = ''
  serviceCraftsmanRecord.value = record
  try {
    const result = await requestAction('open-service-record-craftsman-adjustment', { serviceFactId })
    const entry = actionData(result).serviceRecordCraftsmanAdjustment
    if (!entry || !['success', 'succeeded'].includes(String(actionStatus(result)))) {
      serviceCraftsmanError.value = result?.result?.message || result?.data?.result?.message || '手艺人分配读取失败，请刷新后重试。'
      return
    }
    const recordVersion = Number(entry.recordVersion)
    const stateContextId = String(state.stateContextId || '')
    if (!Number.isSafeInteger(recordVersion) || recordVersion <= 0 || !stateContextId) {
      serviceCraftsmanError.value = '服务记录版本读取失败，请刷新后重新打开。'
      return
    }
    // 这是刚由服务端读取接口签发的同一条服务记录版本。即使中间响应
    // 没有透传顶层 versions，也必须在打开编辑窗前进入受 stateContextId
    // 约束的公共版本仓，保存仍由后端以该版本再次锁定和复核。
    mergeCashierV3PublicVersions([{
      kind: 'service_record', id: String(entry.serviceFactId), version: recordVersion
    }], stateContextId, { requestStateContextId: stateContextId })
    serviceCraftsmanEntry.value = { ...entry, otherCraftsmanCandidates: [] }
    serviceCraftsmanEditorOpen.value = true
  } catch (error) {
    serviceCraftsmanError.value = error instanceof Error && error.message
      ? error.message
      : '手艺人分配读取失败，请刷新后重试。'
  } finally {
    serviceCraftsmanLoading.value = false
  }
}

async function searchServiceCraftsmen({ scope, keyword, target } = {}) {
  if (scope !== 'cashier_other_craftsmen' || target !== 'otherCraftsmen') return
  const entry = serviceCraftsmanEntry.value
  const value = String(keyword || '').trim()
  if (!entry || value.length < 2) return
  const records = []
  let page = 1
  let total = 0
  try {
    do {
      const result = await requestAction('query-query-entities', {
        entityType: 'person',
        // 服务记录调整从订单中心发起；服务端仍按当前门店和组织范围判权，
        // 不接受前端传入的组织或门店范围。
        selectorEntry: 'order_center',
        selectorContext: {
          scope,
          lineId: `service:${entry.serviceFactId || ''}`,
          lineRole: 'service',
          projectId: entry.projectId || '',
          memberId: '',
          entitlementInstanceId: '',
          entitlementSourceDetailId: ''
        },
        keyword: value,
        page,
        pageSize: 20,
        silent: true
      })
      const data = actionData(result)
      if (!['success', 'succeeded'].includes(String(actionStatus(result))) || !Array.isArray(data.records)) {
        throw new Error(result?.result?.message || result?.data?.result?.message || '支援人员搜索失败，请重试。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page += 1
    } while (records.length < total && page <= 20)
    // 编辑窗可能已关闭或已切换到另一条服务记录，不能把旧搜索结果回填。
    if (serviceCraftsmanEntry.value !== entry) return
    serviceCraftsmanEntry.value = { ...entry, otherCraftsmanCandidates: records }
  } catch (error) {
    if (serviceCraftsmanEntry.value !== entry) return
    serviceCraftsmanError.value = error instanceof Error && error.message
      ? error.message
      : '支援人员搜索失败，请重试。'
  }
}

function handleDetailFieldAction(payload = {}) {
  if (payload.key === 'craftsman') openServiceCraftsmanAdjustment(payload.record || genericDetailRecord.value)
}

function prepareServiceCraftsmanReason(assignment = {}) {
  serviceCraftsmanPendingAssignment.value = assignment
  serviceCraftsmanReason.value = ''
  serviceCraftsmanError.value = ''
  serviceCraftsmanEditorOpen.value = false
}

function cancelServiceCraftsmanReason() {
  if (serviceCraftsmanSubmitting.value) return
  serviceCraftsmanPendingAssignment.value = null
  serviceCraftsmanReason.value = ''
  serviceCraftsmanError.value = ''
  serviceCraftsmanEditorOpen.value = Boolean(serviceCraftsmanEntry.value)
}

function closeServiceCraftsmanAdjustment(force = false) {
  if (serviceCraftsmanSubmitting.value && !force) return
  serviceCraftsmanRecord.value = null
  serviceCraftsmanEntry.value = null
  serviceCraftsmanEditorOpen.value = false
  serviceCraftsmanPendingAssignment.value = null
  serviceCraftsmanReason.value = ''
  serviceCraftsmanError.value = ''
}

async function submitServiceCraftsmanAdjustment() {
  const entry = serviceCraftsmanEntry.value
  const assignment = serviceCraftsmanPendingAssignment.value
  const reason = String(serviceCraftsmanReason.value || '').trim()
  if (!entry || !assignment) return
  if (!reason) {
    serviceCraftsmanError.value = '请填写修改原因。'
    return
  }
  if (reason.length > 255) {
    serviceCraftsmanError.value = '修改原因不能超过255字。'
    return
  }
  const serviceFactId = String(entry.serviceFactId || '')
  const commandKey = `${serviceFactId}:${entry.recordVersion}`
  const idempotencyKey = serviceCraftsmanCommandIds.value[commandKey] || createCashierV3CommandId()
  serviceCraftsmanCommandIds.value = { ...serviceCraftsmanCommandIds.value, [commandKey]: idempotencyKey }
  serviceCraftsmanSubmitting.value = true
  serviceCraftsmanError.value = ''
  try {
    const result = await requestAction('adjust-service-record-craftsmen', {
      serviceFactId: entry.serviceFactId,
      recordVersion: entry.recordVersion,
      allocations: Array.isArray(assignment.craftsmen) ? assignment.craftsmen : [],
      reason,
      idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) serviceCraftsmanCommandIds.value = { ...serviceCraftsmanCommandIds.value, [commandKey]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeServiceCraftsmanAdjustment(true)
      // 销售订单中的购买项目可能关联服务事实，因此会复用
      // 本服务记录调整命令。写入后旧的销售列表签名游标已失效，
      // 必须静默重建首页，不能把回刷拒绝误报为“手艺人修改失败”。
      await queryRecords({ silent: true }, true)
      return
    }
    serviceCraftsmanError.value = result?.result?.message || result?.data?.result?.message || '手艺人修改未完成，请稍后重试。'
  } finally {
    serviceCraftsmanSubmitting.value = false
  }
}

function openServiceVoid(record) {
  // 纯权益组作废交给后端整组事务，不在浏览器逐条提交。
  if (!record?.entitlementOnly || record.orderStatus === '已作废' || record.voidedAt) return
  serviceVoidRecord.value = record
  serviceVoidReason.value = ''
  serviceVoidError.value = ''
}

function closeServiceVoid(force = false) {
  if (serviceVoidSubmitting.value && !force) return
  serviceVoidRecord.value = null
  serviceVoidReason.value = ''
  serviceVoidError.value = ''
}

async function submitServiceVoid() {
  const record = serviceVoidRecord.value
  const reason = String(serviceVoidReason.value || '').trim()
  if (!record) return
  if (!reason) {
    serviceVoidError.value = '请填写作废原因。'
    return
  }
  if (reason.length > 255) {
    serviceVoidError.value = '作废原因不能超过255字。'
    return
  }
  const checkoutRequestId = record.entitlementOnly ? String(record.id || '').replace(/^service:/, '') : ''
  if (!checkoutRequestId) {
    serviceVoidError.value = '消费订单标识无效，请刷新后重试。'
    return
  }
  const key = checkoutRequestId
  const idempotencyKey = serviceVoidCommandIds.value[key] || createCashierV3CommandId()
  serviceVoidCommandIds.value = { ...serviceVoidCommandIds.value, [key]: idempotencyKey }
  serviceVoidSubmitting.value = true
  serviceVoidError.value = ''
  try {
    const result = await requestAction('void-service-record', { checkoutRequestId, reason, idempotencyKey })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) {
      serviceVoidCommandIds.value = { ...serviceVoidCommandIds.value, [key]: null }
    }
    if (status === 'success' || status === 'succeeded') {
      closeServiceVoid(true)
      await queryRecords({}, false)
    } else {
      serviceVoidError.value = result?.result?.message || result?.data?.result?.message || '作废未完成，请稍后重试。'
    }
  } finally {
    serviceVoidSubmitting.value = false
  }
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
  if (['supplement', 'gift'].includes(activeTabKey.value) && genericDetailRecord.value) {
    const id = giftVoidRecordId(genericDetailRecord.value) || String(genericDetailRecord.value.id || '')
    if (activeTabKey.value === 'supplement' && id.startsWith('v3-') && genericDetailRecord.value.paymentStatus !== '已作废' && canUseCashierV3Operation('cashier.v3.order.void')) return ['supplement-void']
    if (activeTabKey.value === 'gift' && id.startsWith('v3-direct-gift:') && genericDetailRecord.value.giftStatus !== '已作废' && canUseCashierV3Operation('cashier.v3.order.void')) return ['gift-void']
    return []
  }
  if (activeTabKey.value !== 'recharge' || !genericDetailRecord.value
    || genericDetailRecord.value.economicsDataStatus !== 'ready'
    || genericDetailRecord.value.orderStatus !== '正常') return []
  return ['refund', 'void']
})

async function handleRechargeLifecycleAction(payload = {}) {
  const record = genericDetailRecord.value || {}
  const action = String(payload.action || '')
  if (action === 'void-order-center-supplement' || action === 'void-order-center-gift') {
    const recordId = action === 'void-order-center-gift' ? giftVoidRecordId(record) : String(record.id || '')
    if (!recordId) return { result: { status: 'failed', message: '赠送记录标识已变化，请刷新后重试。' } }
    const key = `${action}:${recordId}`
    const idempotencyKey = rechargeOrderActionIds.value[key] || createCashierV3CommandId()
    rechargeOrderActionIds.value = { ...rechargeOrderActionIds.value, [key]: idempotencyKey }
    const result = await requestAction(action, { recordId, reason: payload.reason, idempotencyKey })
    if (isTerminalActionStatus(actionStatus(result))) rechargeOrderActionIds.value = { ...rechargeOrderActionIds.value, [key]: null }
    if (['success', 'succeeded'].includes(actionStatus(result))) {
      genericDetailRecord.value = null
      await queryRecords({}, false)
    }
    return result
  }
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

/**
 * 作废成功后先在当前投影内落地终态，再由调用方打开同一订单详情。
 * 作废接口已经在一个事务里完成所有实际业务事件的反向事实；
 * 这里不能再触发整页订单查询或退款列表查询。
 */
function applySalesOrderVoidLocally(orderId, result) {
  const normalizedId = String(orderId || '')
  if (!normalizedId) return
  const currentProjection = orderCenter.value || {}
  const statusPatch = {
    orderStatus: '已作废',
    order_status: '已作废',
    status: 'voided',
    statusLabel: '已作废',
    availableActions: []
  }
  const patchRows = (rows, removeNormal) => {
    if (!Array.isArray(rows)) return rows
    return rows.reduce((next, row) => {
      const rowId = salesOrderDisplayId(row)
      if (rowId !== normalizedId) {
        next.push(row)
        return next
      }
      if (!removeNormal) next.push({ ...row, ...statusPatch })
      return next
    }, [])
  }
  const salesQuery = queryModelByType.value.sales || {}
  const normalScope = String(salesQuery.status || '') === 'normal'
  const existingRows = Array.isArray(currentProjection.recordsByType?.sales)
    ? currentProjection.recordsByType.sales
    : (Array.isArray(currentProjection.salesOrders) ? currentProjection.salesOrders : [])
  const nextRows = patchRows(existingRows, normalScope)
  const nextPages = currentProjection.pagesByType?.sales
    ? {
        ...currentProjection.pagesByType,
        sales: {
          ...currentProjection.pagesByType.sales,
          total: normalScope ? Math.max(0, Number(currentProjection.pagesByType.sales.total || 0) - 1) : currentProjection.pagesByType.sales.total
        }
      }
    : currentProjection.pagesByType
  state.orderCenter = {
    ...currentProjection,
    recordsByType: { ...(currentProjection.recordsByType || {}), sales: nextRows },
    salesOrders: nextRows,
    pagesByType: nextPages,
    total: normalScope ? Math.max(0, Number(currentProjection.total || 0) - 1) : currentProjection.total,
    salesOrderDetail: salesDetailBelongsToOrder(salesDetailOrder.value, normalizedId)
      ? { ...salesDetailOrder.value, ...statusPatch }
      : currentProjection.salesOrderDetail
  }
  if (salesDetailBelongsToOrder(salesDetailOrder.value, normalizedId)) {
    salesDetailOrder.value = { ...salesDetailOrder.value, ...statusPatch }
  }
}

async function handleSalesOrderDetailAction(payload = {}) {
  const action = String(payload.action || '')
  if (!allowedSalesOrderDetailActions.has(action)) {
    return { result: { status: 'failed', code: 'SALES_ORDER_ACTION_NOT_ALLOWED', message: '该订单操作未进入前端允许清单。' } }
  }
  const currentOrderId = salesOrderDisplayId(salesDetailOrder.value)
  const lifecycleOrderId = salesOrderLifecycleId(salesDetailOrder.value)
  const currentRevision = salesDetailOrder.value.revision ?? salesDetailOrder.value.recordVersion
  const usesLifecycleResource = salesOrderLifecycleResourceActions.has(action)
  if (!currentOrderId || (usesLifecycleResource && !lifecycleOrderId)
    || String(payload.orderId || '') !== String(currentOrderId)) {
    return { result: { status: 'failed', code: 'SALES_ORDER_CONTEXT_MISMATCH', message: '订单详情已经变化，请重新打开后操作。' } }
  }
  const requestOrderId = usesLifecycleResource ? lifecycleOrderId : currentOrderId
  const commandKey = `${action}:${requestOrderId}:${currentRevision ?? 'no-version'}:${payload.relationId || ''}`
  const idempotencyKey = salesOrderDetailCommandActions.has(action)
    ? (salesOrderActionIds.value[commandKey] || createCashierV3CommandId())
    : null
  if (idempotencyKey) salesOrderActionIds.value = { ...salesOrderActionIds.value, [commandKey]: idempotencyKey }
  const result = await requestAction(action, {
    orderId: requestOrderId,
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
    refundLineIds: Array.isArray(payload.refundLineIds) ? payload.refundLineIds.map(String) : [],
    replaceWorkspace: payload.replaceWorkspace === true,
    targetOrderLineId: payload.targetOrderLineId,
    targetRole: payload.targetRole,
    personnel: payload.personnel,
    orderNote: payload.orderNote,
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
    if (action === 'void-sales-order') {
      applySalesOrderVoidLocally(currentOrderId, result)
      // 作废成功后的唯一后续动作是读取并展示同一订单的已作废详情；
      // 不刷新整个订单中心，避免把用户带回列表或触发无关查询。
      await openSalesOrderDetail({ orderId: currentOrderId })
    } else {
      // Partial refund still needs the refund tab's authoritative projection;
      // a void is already represented by the returned lifecycle result and
      // must not perform an unrelated full order-center reload.
      await queryRecords({}, false)
      await refreshRefundRecords()
      await openSalesOrderDetail({ orderId: currentOrderId })
    }
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
  executedQueryByType.value = {}
  isOrderQueryLoading.value = false
  isSalesDetailOpen.value = false
  salesDetailOrder.value = {}
  isSalesDetailLoading.value = false
  salesDetailLoadError.value = ''
  genericDetailRecord.value = null
  serviceVoidRecord.value = null
  serviceVoidReason.value = ''
  serviceVoidError.value = ''
  serviceVoidSubmitting.value = false
  serviceVoidCommandIds.value = {}
  closeServiceCraftsmanAdjustment()
  serviceCraftsmanCommandIds.value = {}
  salesPersonnelEntry.value = null
  salesPersonnelTarget.value = null
  salesPersonnelEditorOpen.value = false
  salesPersonnelPendingAssignment.value = null
  salesPersonnelReason.value = ''
  salesPersonnelError.value = ''
  salesPersonnelSubmitting.value = false
  salesPersonnelCommandIds.value = {}
  salesOrderNoteRecord.value = null
  salesOrderNoteValue.value = ''
  salesOrderNoteError.value = ''
  salesOrderNoteSubmitting.value = false
  salesOrderNoteCommandIds.value = {}
  salesOrderActionIds.value = {}
  rechargeOrderActionIds.value = {}
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.addEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetLocalContext)
})

// Vue Router 在首帧可能尚未把嵌套路由 meta 写入 route；若只在 mounted
// 判断，会漏掉平台页的首个查询，页面于是错误地显示 0 条。等只读路由身份
// 已确认后执行一次当天查询，同时保留普通收银入口原有流程。
watch(isPlatformReadOnly, (readOnly) => {
  if (readOnly) {
    void loadPlatformOrderScope()
    queryRecords(initialOrderCenterQuery(), true)
  }
}, { immediate: true })

watch(activeTabKey, () => {
  // 平台页没有可保存的统一查询设置；它与门店页都由当天默认查询直接
  // 驱动，避免工具栏显示值与首屏后端结果再次分叉。
  if (!isPlatformReadOnly.value) activeUnifiedQuery.value?.load({ silent: true })
}, { immediate: true })

watch(
  () => route.fullPath,
  () => {
    const requestedTab = String(route.query.tab || route.query.recordType || '').trim()
    if (!ORDER_TABS.some((item) => item.key === requestedTab)) return
    if (!serviceReportDrilldown.value && queryModelByType.value.service?.servicePerformanceDrilldown) {
      queryModelByType.value = { ...queryModelByType.value, service: {} }
    }
    if (!salesReportDrilldown.value && queryModelByType.value.sales?.salesPerformanceDrilldown) {
      queryModelByType.value = { ...queryModelByType.value, sales: {} }
      salesPageCursors.value = {}
    }
    if (requestedTab !== activeTabKey.value) activeTabKey.value = requestedTab
    if (isPlatformReadOnly.value || state.stateContextId) queryRecords(initialOrderCenterQuery(), true)
  }
)

// 根分区是登录时的通用快照，不能直接当作销售订单的“正常数据”结果。
// 首次拿到当前工作台上下文后主动执行一次后端 normal 查询，避免首屏把
// 已退款／已作废订单带进正常列表；切换账号或门店时同样重新收敛到正常范围。
watch(
  () => state.stateContextId,
  (stateContextId) => {
    if (isPlatformReadOnly.value) return
    if (!stateContextId) return
    // 首屏的页面能力请求可能发生在工作台上下文建立之前。上下文就绪后重试当前
    // 页签，避免安全降级状态把“导出”一直隐藏到手工刷新为止。
    activeUnifiedQuery.value?.load({ silent: true })
    queryRecords(initialOrderCenterQuery(), true)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.removeEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetLocalContext)
})
</script>

<template>
  <section class="order-center-page" aria-label="订单中心">
    <header class="order-center-page__head">
      <nav v-if="!isPlatformReadOnly" class="order-center-tabs" role="tablist" aria-label="订单与业务记录类型">
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
        </button>
      </nav>
      <span v-if="isPlatformReadOnly" class="order-center-page__readonly-note">平台只读查询；业务操作请前往门店端处理</span>
      <button v-else type="button" class="order-center-printer-button" @click="isPrinterSetupOpen = true">
        <Printer :size="17" />
        打印设置
      </button>
    </header>

    <p v-if="!isPlatformReadOnly && serviceVoidNotice" class="order-center-notice" role="status">{{ serviceVoidNotice }}</p>
    <p v-if="!isPlatformReadOnly && servicePrintError" class="order-center-page__inline-error" role="alert">{{ servicePrintError }}</p>
    <p v-if="activeTabKey === 'service' && serviceReportDrilldown" class="order-center-notice" role="status">
      正在查看 {{ serviceReportDateLabel }} 的手艺人消耗对应服务记录，仅显示当前仍有效、未作废的服务。
      <button type="button" class="button button--text" @click="returnToServiceReport">返回报表</button>
      <button type="button" class="button button--text" @click="leaveServiceReportDrilldown">退出报表筛选</button>
    </p>
    <p v-if="activeTabKey === 'sales' && salesReportDrilldown" class="order-center-notice" role="status">
      正在查看 {{ salesReportDateLabel }} 的销售人业绩对应销售订单；订单中的“销售人（业绩）”逐笔解释报表金额。
      <button type="button" class="button button--text" @click="returnToSalesReport">返回报表</button>
      <button type="button" class="button button--text" @click="leaveSalesReportDrilldown">退出报表筛选</button>
    </p>

    <UnifiedQueryToolbar
      v-if="!serviceReportDrilldown && !salesReportDrilldown"
      class="order-center-query-toolbar"
      :key="activeTabKey"
      :search-placeholder="activeTab.searchPlaceholder"
      :status-options="statusOptions"
      :fields="activeQueryFields"
      :settings="querySettings"
      default-sort-field="业务日期"
      lock-store-selector
      :current-store="state.currentStore"
      :page-code="activeTab.pageCode"
      :page-name="activeTab.label"
      :result-count="total"
      :page-size="pageSize"
      :current-page-count="records.length"
      :current-page="page"
      :data-as-of="orderCenter.dataAsOf"
      :executed-query="executedQuery"
      :default-quick-date-ranges="platformOrderCenterDefaultDateRanges"
      :is-query-loading="isOrderQueryLoading"
      :state-context-key="state.stateContextId"
      :query-capability="activeQueryCapability"
      :show-settings-button="!isPlatformReadOnly"
      @query="queryRecords"
      :on-save-settings="saveQuerySettings"
      :on-create-export="createActiveExport"
      :on-query-export-task="queryActiveExportTask"
      :on-download-export="downloadActiveExport"
      compact-keyword-search
      compact-scope-labels
      inline-quick-controls
      export-button-after-settings
      direct-query-export
      @settings-applied="applyQuerySettings"
    >
      <template #leading-controls>
        <OrganizationStoreScopePicker
          v-if="isPlatformReadOnly"
          v-model="platformScopeStoreIds"
          :tree="platformScopePicker.tree"
          :allowed-store-ids="platformScopePicker.allowedStoreIds"
          :label="platformScopePicker.label"
          :loading="platformScopePicker.loading"
          @change="changePlatformOrderScope"
        />
      </template>
    </UnifiedQueryToolbar>

    <main class="order-center-list-wrap">
      <table v-if="activeTabKey === 'sales'" class="order-center-list-table sales-order-query-table" :style="{ '--order-column-count': salesOrderListColumns.length }">
        <thead>
          <tr>
            <th v-for="column in salesOrderListColumns" :key="column">{{ column }}</th>
          </tr>
        </thead>
        <tbody v-for="(record, recordIndex) in records" :key="recordKey(record, recordIndex)" class="sales-order-query-group">
          <tr class="sales-order-query-group__header">
            <td :colspan="salesOrderListColumns.length">
              <!-- 主项固定列槽，业务标签放在来源之后，避免标签或名称长度推移后续字段。 -->
              <span class="sales-order-query-meta sales-order-query-meta--date">销售日期：{{ displayRecordField(record, 'business_date') }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--time">实际下单时间：{{ displayRecordField(record, 'occurred_at') }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--number" :title="displayRecordField(record, 'sales_order_no')">订单编号：{{ displayRecordField(record, 'sales_order_no') }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--store" :title="displayRecordField(record, 'store')">门店：{{ displayRecordField(record, 'store') }}</span>
              <!-- 复用服务记录的会员详情入口和权限投影；游客不按姓名猜测关联。 -->
              <span class="sales-order-query-meta sales-order-query-meta--member" :title="displayRecordField(record, 'member_name')">客户：<button
                v-if="canOpenMemberDetail(record)"
                type="button"
                class="order-link"
                :title="`查看${displayRecordField(record, 'member_name')}的会员详情`"
                @click="openMemberDetail(record)"
              >{{ displayRecordField(record, 'member_name') }}</button><template v-else>{{ displayRecordField(record, 'member_name') }}</template></span>
              <span class="sales-order-query-meta sales-order-query-meta--source" :title="displayRecordField(record, 'source')">来源：{{ displayRecordField(record, 'source') }}</span>
              <span v-if="record.upgradeTypeLabel" class="sales-order-query-group__upgrade-tag">{{ record.upgradeTypeLabel }}</span>
              <!-- 纯权益可以查看/打印及作废服务，但不进入销售退款。 -->
              <span v-if="!isPlatformReadOnly" class="sales-order-query-group__actions">
                <button type="button" class="button button--text" @click="openRecordDetail(record)">详情</button>
                <button
                  v-if="canUseCashierV3Operation('cashier.v3.order.receipt_print')"
                  type="button"
                  class="button button--text"
                  :disabled="Boolean(salesPrintLoading[String(record.id || record.orderId || record.salesOrderId || '')])"
                  @click="printSalesOrderRecord(record)"
                >{{ salesPrintLoading[String(record.id || record.orderId || record.salesOrderId || '')] ? '生成中…' : '打印' }}</button>
                <button
                  v-if="salesOrderActionAvailable(record, 'refund-sales-order', 'cashier.v3.order.refund')"
                  type="button"
                  class="button button--text"
                  @click="openSalesOrderLifecycle(record, 'refund-sales-order', 'cashier.v3.order.refund')"
                >退款</button>
                <button
                  v-if="salesOrderActionAvailable(record, 'void-sales-order', 'cashier.v3.order.void')"
                  type="button"
                  class="button button--text"
                  @click="openSalesOrderLifecycle(record, 'void-sales-order', 'cashier.v3.order.void')"
                >作废</button>
                <button v-if="record.entitlementOnly && record.orderStatus !== '已作废' && canUseCashierV3Operation('cashier.v3.order.service_void')" type="button" class="button button--text" @click="openServiceVoid(record)">作废</button>
                <button
                  v-if="salesOrderActionAvailable(record, 'update-sales-order-note', 'cashier.v3.order_center')"
                  type="button"
                  class="button button--text"
                  @click="openSalesOrderNoteEditor(record)"
                >备注</button>
              </span>
            </td>
          </tr>
          <tr v-for="(item, itemIndex) in salesOrderItems(record)" :key="item.id || item.orderItemId || `${recordKey(record, recordIndex)}-${item.name}-${itemIndex}`">
            <td class="sales-order-query-item-cell">
              <span class="sales-order-query-item-cell__line">
                <!-- 购买/权益和商品类型统一放在名称前，仍使用原字段，不重算商品类型。 -->
                <small v-if="item.businessTag" class="sales-order-query-item-cell__business-tag" :class="`is-${item.businessTag === '权益' ? 'entitlement' : 'purchase'}`">{{ item.businessTag }}</small>
                <small v-if="item.itemType" class="sales-order-query-item-cell__type">{{ item.itemType }}</small>
                <strong class="sales-order-query-item-cell__name">{{ item.name || '未命名商品' }}</strong>
              </span>
            </td>
            <td>{{ itemMoney(item.unitPrice) }}</td>
            <td>x {{ item.quantity ?? '—' }}</td>
            <td>
              <button v-if="salesItemCraftsmanAdjustable(item) || salesPurchaseCraftsmanAdjustable(record, item)" type="button" class="order-link" @click="openSalesItemCraftsmanEditor(record, item)">{{ salesItemCraftsmenText(item) }}</button>
              <span v-else>{{ salesItemCraftsmenText(item) }}</span>
            </td>
            <td>
              <button v-if="salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'salespeople')">{{ salespeoplePerformanceText(item.salespeople) }}</button>
              <span v-else>{{ salespeoplePerformanceText(item.salespeople) }}</span>
            </td>
            <td>
              <button v-if="salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'salesManagers')">{{ personnelNames(item, 'salesManagers') }}</button>
              <span v-else>{{ personnelNames(item, 'salesManagers') }}</span>
            </td>
            <td>
              <button v-if="salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'guides')">{{ personnelNames(item, 'guides') }}</button>
              <span v-else>{{ personnelNames(item, 'guides') }}</span>
            </td>
            <!-- 权益价值仅展示，整单应收/已收继续读取销售资金事实。 -->
            <td>{{ itemMoney(item.businessTag === '权益' ? item.entitlementAmount : item.payableAmount) }}</td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.receivableAmount ?? record.receivable_amount) }}
            </td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.debtAmount ?? record.debt_amount) }}
            </td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.actualReceivedAmount ?? record.actual_received_amount) }}
            </td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell sales-order-query-payment-cell">
              <template v-if="orderPaymentDetails(record).length">
                <span v-for="payment in orderPaymentDetails(record)" :key="payment.id" class="sales-order-query-payment-cell__line">
                  {{ payment.methodName }} {{ itemMoney(payment.amount) }}
                </span>
              </template>
              <span v-else>—</span>
            </td>
            <td>{{ displayRecordField(record, 'store') }}</td>
            <td><span class="order-status" :class="statusClass(recordFieldValue(record, 'order_status'))">{{ displayRecordField(record, 'order_status') }}</span></td>
          </tr>
        </tbody>
      </table>
      <table
        v-else
        class="order-center-list-table"
        :class="{ 'service-record-query-table': activeTabKey === 'service' }"
        :style="{ '--order-column-count': visibleFields.length }"
      >
        <thead>
          <tr>
            <th v-for="fieldItem in visibleFields" :key="fieldItem.key">{{ fieldItem.label }}</th>
            <th v-if="!isPlatformReadOnly">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(record, index) in records" :key="recordKey(record, index)">
            <td v-for="fieldItem in visibleFields" :key="fieldItem.key" :class="tableCellClass(record, fieldItem)">
              <button
                v-if="!isPlatformReadOnly && fieldItem.key === activeTab.primaryField"
                type="button"
                class="order-link"
                @click="openRecordDetail(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <button
                v-else-if="fieldItem.key === 'member_name' && canOpenMemberDetail(record)"
                type="button"
                class="order-link"
                :title="`查看${displayRecordField(record, fieldItem.key)}的会员详情`"
                @click="openMemberDetail(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <button
                v-else-if="!isPlatformReadOnly && fieldItem.key === 'salesperson' && ['recharge', 'supplement'].includes(activeTabKey) && canUseCashierV3Operation('cashier.v3.order.staff_adjust')"
                type="button"
                class="order-link"
                title="修改销售人"
                @click="openRecordPersonnelEditor(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <button
                v-else-if="!isPlatformReadOnly && fieldItem.key === 'craftsman' && serviceRecordIsNormal(record) && canUseCashierV3Operation('cashier.v3.order.service_detail')"
                type="button"
                class="order-link"
                title="修改该服务记录的手艺人分配"
                :data-service-fact-id="record.serviceFactId || record.id"
                @click="openServiceCraftsmanAdjustment(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <span v-else-if="fieldItem.type === 'money'">{{ displayMoneyField(record, fieldItem.key) }}</span>
              <span v-else-if="fieldItem.type === 'status'" class="order-status" :class="statusClass(recordFieldValue(record, fieldItem.key))">
                {{ displayRecordField(record, fieldItem.key) }}
              </span>
              <span v-else>{{ displayRecordField(record, fieldItem.key) }}</span>
            </td>
            <td v-if="!isPlatformReadOnly">
              <button
                v-if="activeTabKey !== 'service' || canUseCashierV3Operation('cashier.v3.order.service_detail')"
                type="button"
                class="button button--text"
                @click="openRecordDetail(record)"
              >{{ activeTabKey === 'service' ? '详情' : '查看详情' }}</button>
              <!-- 服务只保留详情入口；打印与作废统一按消费订单处理，避免拆单操作。 -->
              <button
                v-if="activeTabKey === 'debt' && record.canRepay"
                type="button"
                class="button button--text"
                @click="openDebtRepayment(record)"
              >去还款</button>
              <button
                v-if="canUseCashierV3Operation('cashier.v3.order.void') && ((activeTabKey === 'recharge' && record.economicsDataStatus === 'ready' && record.orderStatus === '正常') || (activeTabKey === 'supplement' && String(record.id || '').startsWith('v3-') && record.paymentStatus !== '已作废') || (activeTabKey === 'gift' && giftVoidRecordId(record).startsWith('v3-direct-gift:') && record.giftStatus !== '已作废'))"
                type="button"
                class="button button--text button--danger"
                @click="openRecordDetail(record)"
              >作废</button>
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
      @void-entitlement-order="(record) => { closeSalesDetail(); openServiceVoid(record) }"
      v-if="!isPlatformReadOnly && isSalesDetailOpen"
      :order="salesDetailOrder"
      :is-loading="isSalesDetailLoading"
      :load-error="salesDetailLoadError"
      :action-only="salesDetailActionOnly"
      :initial-lifecycle-action="salesDetailFocus.lifecycleAction"
      :focus-personnel-role="salesDetailFocus.personnelRole"
      :focus-personnel-line-id="salesDetailFocus.personnelLineId"
      :cart-line-count="Array.isArray(state.cashier?.cart?.lines) ? state.cashier.cart.lines.length : 0"
      :on-action="handleSalesOrderDetailAction"
      @close="closeSalesDetail"
    />

    <PersonnelPerformanceOverlay
      v-if="!isPlatformReadOnly && salesPersonnelEditorOpen && salesPersonnelEntry && salesPersonnelTarget"
      :initial-tab="salesPersonnelTarget.uiRole"
      initial-mode="full"
      :show-craftsmen="salesPersonnelTarget.role === 'craftsman'"
      :show-salespeople="salesPersonnelTarget.role === 'salesperson'"
      :show-guides="salesPersonnelTarget.role === 'guide'"
      :show-sales-managers="salesPersonnelTarget.role === 'sales_manager'"
      :require-craftsmen="salesPersonnelTarget.role === 'craftsman'"
      :guest-customer="Number(salesPersonnelEntry.memberId || 0) === 0"
      :salesperson-candidates="salesPersonnelTarget.role === 'salesperson' ? salesPersonnelCandidates('salesperson') : []"
      :guide-candidates="salesPersonnelTarget.role === 'guide' ? salesPersonnelCandidates('guide') : []"
      :sales-manager-candidates="salesPersonnelTarget.role === 'sales_manager' ? salesPersonnelCandidates('sales_manager') : []"
      :craftsmen-candidates="salesPersonnelTarget.role === 'craftsman' ? salesPersonnelCandidates('craftsman') : []"
      :selected-salespeople="salesPersonnelTarget.role === 'salesperson' ? salesPersonnelInitialSelection('salesperson', salesPersonnelEntry.lines?.[0]) : []"
      :selected-guides="salesPersonnelTarget.role === 'guide' ? salesPersonnelInitialSelection('guide', salesPersonnelEntry.lines?.[0]) : []"
      :selected-sales-managers="salesPersonnelTarget.role === 'sales_manager' ? salesPersonnelInitialSelection('sales_manager', salesPersonnelEntry.lines?.[0]) : []"
      :selected-craftsmen="salesPersonnelTarget.role === 'craftsman' ? salesPersonnelInitialSelection('craftsman', salesPersonnelEntry.lines?.[0]) : []"
      :store-id="state.currentStore?.id || state.currentStore?.storeId || 0"
      :history-adjustment="salesPersonnelTarget.role === 'craftsman'"
      history-adjustment-title="修改销售订单手艺人"
      history-adjustment-subtitle="直接购买项目调整"
      :allocation-total-amount-cents="salesPersonnelTarget.role === 'craftsman' ? (salesPersonnelEntry.lines?.[0]?.currentCraftsmen || []).reduce((sum, item) => sum + Number(item.allocationAmountCents || 0), 0) : 0"
      :performance-base-amount-cents="salesPersonnelEntry.lines?.[0]?.totalAmountCents || 0"
      :saving="salesPersonnelSubmitting"
      :load-error="salesPersonnelError"
      :attribution-search-loading="salesPersonnelSearchLoading"
      :attribution-search-error="salesPersonnelSearchError"
      @close="closeSalesPersonnelEditor"
      @search-personnel="searchSalesPersonnelAttributions"
      @confirm="prepareSalesPersonnelReason"
    />

    <PersonnelPerformanceOverlay
      v-if="!isPlatformReadOnly && recordPersonnelEditorOpen && recordPersonnelEntry && recordPersonnelTarget"
      initial-tab="salespeople"
      initial-mode="full"
      :show-craftsmen="false"
      :show-salespeople="true"
      :salesperson-candidates="recordPersonnelCandidates(recordPersonnelEntry)"
      :selected-salespeople="recordPersonnelInitialSelection(recordPersonnelEntry.lines?.[0])"
      :allocation-total-amount-cents="recordPersonnelEntry.totalAmountCents || 0"
      :performance-base-amount-cents="recordPersonnelEntry.performanceBaseAmountCents || 0"
      :saving="recordPersonnelSubmitting"
      :load-error="recordPersonnelError"
      @close="closeRecordPersonnelEditor"
      @confirm="prepareRecordPersonnelReason"
    />

    <BusinessRecordDetailOverlay
      v-if="!isPlatformReadOnly && genericDetailRecord"
      :title="`${activeTab.label}详情`"
      :record="genericDetailRecord"
      :fields="detailFields"
      :resolve-value="recordFieldValue"
      :lifecycle-actions="rechargeLifecycleActions"
      :on-lifecycle-action="handleRechargeLifecycleAction"
      :field-action-keys="serviceRecordIsNormal(genericDetailRecord) ? ['craftsman'] : []"
      @field-action="handleDetailFieldAction"
      @close="genericDetailRecord = null"
    />

    <p v-if="!isPlatformReadOnly && serviceCraftsmanError && !serviceCraftsmanEditorOpen && !serviceCraftsmanPendingAssignment" class="order-center-page__inline-error" role="alert">
      {{ serviceCraftsmanError }}
    </p>

    <PersonnelPerformanceOverlay
      v-if="!isPlatformReadOnly && serviceCraftsmanEditorOpen && serviceCraftsmanEntry"
      initial-tab="craftsmen"
      :show-craftsmen="true"
      :show-salespeople="false"
      :require-craftsmen="true"
      allow-other-craftsmen
      :craftsmen-candidates="serviceCraftsmanEntry.craftsmenCandidates || []"
      :other-craftsman-candidates="serviceCraftsmanEntry.otherCraftsmanCandidates || []"
      :selected-craftsmen="serviceCraftsmanEntry.allocations || []"
      :store-id="state.currentStore?.id || state.currentStore?.storeId || 0"
      :allocation-total-amount-cents="serviceCraftsmanEntry.allocationTotalAmountCents || 0"
      history-adjustment
      :loading="serviceCraftsmanLoading"
      :saving="serviceCraftsmanSubmitting"
      :load-error="serviceCraftsmanError"
      @close="closeServiceCraftsmanAdjustment"
      @search-personnel="searchServiceCraftsmen"
      @confirm="prepareServiceCraftsmanReason"
    />

    <ReceiptPrinterSetupOverlay
      v-if="!isPlatformReadOnly && isPrinterSetupOpen"
      :store-name="state.currentStore?.name || state.currentStore?.storeName || ''"
      @close="isPrinterSetupOpen = false"
    />

    <div v-if="!isPlatformReadOnly && serviceVoidRecord" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="service-void-title">
      <div class="service-void-modal__backdrop" @click="closeServiceVoid"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="service-void-title">作废</h2>
          <button type="button" class="service-void-modal__close" :disabled="serviceVoidSubmitting" @click="closeServiceVoid">×</button>
        </header>
        <p class="service-void-modal__record">{{ serviceVoidRecord.serviceRecordNo || serviceVoidRecord.orderNo || serviceVoidRecord.serviceFactId }}</p>
        <label class="service-void-modal__label" for="service-void-reason">作废原因</label>
        <textarea id="service-void-reason" v-model="serviceVoidReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入作废原因"></textarea>
        <p v-if="serviceVoidError" class="service-void-modal__error" role="alert">{{ serviceVoidError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="serviceVoidSubmitting" @click="closeServiceVoid">取消</button>
          <button type="button" class="button button--danger" :disabled="serviceVoidSubmitting" @click="submitServiceVoid">{{ serviceVoidSubmitting ? '提交中…' : '确认作废' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="!isPlatformReadOnly && serviceCraftsmanPendingAssignment" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="service-craftsman-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelServiceCraftsmanReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="service-craftsman-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="serviceCraftsmanSubmitting" @click="cancelServiceCraftsmanReason">×</button>
        </header>
        <p class="service-void-modal__record">{{ serviceCraftsmanEntry?.serviceRecordNo }} · {{ serviceCraftsmanEntry?.serviceProject }}</p>
        <label class="service-void-modal__label" for="service-craftsman-reason">修改原因</label>
        <textarea id="service-craftsman-reason" v-model="serviceCraftsmanReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="serviceCraftsmanError" class="service-void-modal__error" role="alert">{{ serviceCraftsmanError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="serviceCraftsmanSubmitting" @click="cancelServiceCraftsmanReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="serviceCraftsmanSubmitting" @click="submitServiceCraftsmanAdjustment">{{ serviceCraftsmanSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="!isPlatformReadOnly && salesPersonnelPendingAssignment && salesPersonnelTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="sales-personnel-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelSalesPersonnelReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="sales-personnel-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="salesPersonnelSubmitting" @click="cancelSalesPersonnelReason">×</button>
        </header>
        <p class="service-void-modal__record">{{ salesPersonnelEntry?.salesOrderNo }} · {{ salesPersonnelEntry?.lines?.[0]?.itemName }}</p>
        <label class="service-void-modal__label" for="sales-personnel-reason">修改原因</label>
        <textarea id="sales-personnel-reason" v-model="salesPersonnelReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="salesPersonnelError" class="service-void-modal__error" role="alert">{{ salesPersonnelError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="salesPersonnelSubmitting" @click="cancelSalesPersonnelReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="salesPersonnelSubmitting" @click="submitSalesPersonnelAdjustment">{{ salesPersonnelSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="!isPlatformReadOnly && recordPersonnelPendingAssignment && recordPersonnelTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="record-personnel-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelRecordPersonnelReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="record-personnel-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="recordPersonnelSubmitting" @click="cancelRecordPersonnelReason">×</button>
        </header>
        <p class="service-void-modal__record">{{ recordPersonnelTarget.type === 'recharge' ? '充值订单' : '补交记录' }} · {{ recordPersonnelTarget.recordId }}</p>
        <label class="service-void-modal__label" for="record-personnel-reason">修改原因</label>
        <textarea id="record-personnel-reason" v-model="recordPersonnelReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="recordPersonnelError" class="service-void-modal__error" role="alert">{{ recordPersonnelError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="recordPersonnelSubmitting" @click="cancelRecordPersonnelReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="recordPersonnelSubmitting" @click="submitRecordPersonnelAdjustment">{{ recordPersonnelSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="!isPlatformReadOnly && salesOrderNoteRecord" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="sales-order-note-title">
      <div class="service-void-modal__backdrop" @click="closeSalesOrderNoteEditor"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="sales-order-note-title">订单备注</h2>
          <button type="button" class="service-void-modal__close" :disabled="salesOrderNoteSubmitting" @click="closeSalesOrderNoteEditor">×</button>
        </header>
        <p class="service-void-modal__record">{{ displayRecordField(salesOrderNoteRecord, 'sales_order_no') }}</p>
        <label class="service-void-modal__label" for="sales-order-note">备注内容</label>
        <textarea id="sales-order-note" v-model="salesOrderNoteValue" class="service-void-modal__textarea" maxlength="500" rows="4" placeholder="请输入订单备注"></textarea>
        <p v-if="salesOrderNoteError" class="service-void-modal__error" role="alert">{{ salesOrderNoteError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="salesOrderNoteSubmitting" @click="closeSalesOrderNoteEditor">取消</button>
          <button type="button" class="button button--primary" :disabled="salesOrderNoteSubmitting" @click="submitSalesOrderNote">{{ salesOrderNoteSubmitting ? '保存中…' : '保存备注' }}</button>
        </footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.order-center-page { position: relative; }
.order-center-page__readonly-note { margin-left: auto; color: #667085; font-size: 13px; }
.order-center-notice { margin: 12px 0 0; padding: 9px 12px; border-left: 3px solid #c83c3c; background: #fff4f4; color: #8a3030; }
.order-center-page__inline-error { position: fixed; right: 20px; bottom: 20px; z-index: 90; max-width: min(420px, calc(100vw - 40px)); margin: 0; padding: 10px 14px; border: 1px solid #fecdca; border-radius: 6px; background: #fff4f4; color: #b42318; box-shadow: 0 8px 24px rgba(16, 24, 40, .14); }
.service-void-modal { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center; }
.service-void-modal__backdrop { position: absolute; inset: 0; background: rgba(16, 24, 40, .42); }
.service-void-modal__panel { position: relative; width: min(460px, calc(100vw - 32px)); padding: 20px; border-radius: 8px; background: #fff; box-shadow: 0 18px 48px rgba(16, 24, 40, .2); }
.service-void-modal__head { display: flex; align-items: center; justify-content: space-between; }
.service-void-modal__head h2 { margin: 0; font-size: 18px; color: #101828; }
.service-void-modal__close { border: 0; background: transparent; color: #667085; font-size: 24px; cursor: pointer; }
.service-void-modal__record { margin: 12px 0 18px; color: #667085; }
.service-void-modal__label { display: block; margin-bottom: 8px; color: #344054; font-weight: 600; }
.service-void-modal__textarea { width: 100%; resize: vertical; box-sizing: border-box; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; font: inherit; }
.service-void-modal__error { margin: 8px 0 0; color: #b42318; }
.service-void-modal__actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.sales-order-query-table { min-width: 1320px; }
/* 销售订单与服务记录共用同一阅读基线：表头和列值都从左侧起排，
 * 不让金额、状态或操作列因内容类型切换对齐方式。 */
.sales-order-query-table th,
.sales-order-query-table td,
.service-record-query-table th,
.service-record-query-table td { text-align: left; }
.sales-order-query-table th,
.sales-order-query-table td { white-space: nowrap; }
.sales-order-query-table th { background: #f6f7f9; }
.sales-order-query-table > thead > tr > th:first-child {
  position: sticky;
  left: 0;
  top: 0;
  z-index: 7;
  background: #f6f7f9;
  box-shadow: 1px 0 0 #dfe5ec;
}
.sales-order-query-table > thead > tr > th {
  position: sticky;
  top: 0;
  z-index: 6;
  background: #f6f7f9;
  box-shadow: 0 1px 0 #dfe5ec;
}
.sales-order-query-table > tbody.sales-order-query-group > tr:not(.sales-order-query-group__header) > td:first-child {
  position: sticky;
  left: 0;
  z-index: 2;
  background: #fff;
  box-shadow: 1px 0 0 #e4e7ec;
}
.sales-order-query-table td:first-child { min-width: 220px; white-space: normal; }
.sales-order-query-table td:first-child strong,
.sales-order-query-table td:first-child small { display: block; }
.sales-order-query-table td:first-child small { margin-top: 4px; color: #98a2b3; }
.sales-order-query-group + .sales-order-query-group { border-top: 14px solid #fff; }
.order-center-list-table .sales-order-query-group__header td {
  padding: 6px 18px;
  background: #e4e7ec;
  color: #344054;
  line-height: 1.35;
  font-size: 13px;
}
.order-center-list-table .sales-order-query-group:not(:first-of-type) .sales-order-query-group__header td { border-top: 1px solid #e4e7ec; }
.sales-order-query-group > tr:not(.sales-order-query-group__header) td {
  padding: 2px 18px;
  background: #fff;
  line-height: 1.15;
  vertical-align: top;
}
.sales-order-query-group > tr:not(.sales-order-query-group__header) .button {
  min-height: 22px;
  padding: 0 6px;
}
.sales-order-query-item-cell__line { display: inline-flex; align-items: center; gap: 8px; }
.sales-order-query-item-cell__name { display: inline-block; line-height: 1.2; }
.sales-order-query-item-cell__business-tag {
  display: inline-block;
  padding: 2px 6px;
  border: 1px solid #b7d4fe;
  border-radius: 5px;
  background: #eff6ff;
  color: #175cd3;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.2;
  flex-shrink: 0;
}
/* 抵消旧商品单元格对 small 的统一上边距，标签与名称保持垂直居中。 */
.sales-order-query-table td:first-child small.sales-order-query-item-cell__business-tag,
.sales-order-query-table td:first-child small.sales-order-query-item-cell__type { margin-top: 0; }
.sales-order-query-item-cell__business-tag.is-entitlement {
  border-color: #abefc6;
  background: #ecfdf3;
  color: #067647;
}
.sales-order-query-item-cell__type {
  display: inline-block;
  color: #98a2b3;
  font-size: 11px;
  line-height: 1.1;
}
.sales-order-query-order-cell { vertical-align: top; }
.sales-order-query-payment-cell { white-space: normal; }
.sales-order-query-payment-cell__line { display: block; white-space: nowrap; }
.sales-order-query-group__header td > span { display: inline-block; margin-right: 24px; }
/* 明确列宽仅用于订单主行；超长值悬停可读，会员按钮与业务操作仍沿用原入口。 */
.sales-order-query-group__header td > .sales-order-query-meta { vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 16px; }
.sales-order-query-meta--date { width: 150px; }
.sales-order-query-meta--time { width: 236px; }
.sales-order-query-meta--number { width: 180px; }
.sales-order-query-meta--store { width: 140px; }
.sales-order-query-meta--member { width: 110px; }
.sales-order-query-meta--source { width: 150px; }
.sales-order-query-group__header .sales-order-query-group__upgrade-tag {
  margin-right: 24px;
  padding: 1px 7px;
  border: 1px solid #b7d4fe;
  border-radius: 4px;
  background: #eff6ff;
  color: #175cd3;
  font-size: 12px;
  font-weight: 600;
}
.sales-order-query-group__header .sales-order-query-group__actions { display: inline-flex; gap: 10px; float: right; margin-right: 0; }
.sales-order-query-group__header .button { padding: 0; color: #6941c6; }

.order-center-page__head {
  display: flex;
  align-items: stretch;
  min-width: 0;
  border-bottom: 1px solid #dfe5ec;
  background: #fff;
}

.order-center-page__head .order-center-tabs {
  flex: 1;
  border-bottom: 0;
}

.order-center-printer-button {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  gap: 7px;
  min-width: 116px;
  margin: 6px 8px;
  padding: 0 14px;
  border: 1px solid #cfd8e3;
  border-radius: 7px;
  background: #fff;
  color: #344054;
  font-weight: 700;
  letter-spacing: 0;
}

.order-center-printer-button:hover { border-color: #84adcf; color: #175cd3; }

/* R37 订单中心视觉皮肤：仅作用本页的颜色与装饰，不改变字段、尺寸布局、滚动定位或业务事件。 */
.order-center-page { background: #f5f7fa; }
.order-center-page__head { border: 1px solid #e3e9f2; border-radius: 10px; }
.order-center-tabs { border-radius: 10px; }
.order-center-tabs__button { color: #42526b; border-radius: 8px 8px 0 0; }
.order-center-tabs__button:hover { color: #315bea; background: #f0f5ff; }
.order-center-tabs__button--active { color: #315bea; background: #e7efff; }
.order-center-tabs__button--active::after { background: #4568f5; }
.order-center-page :deep(.unified-query-toolbar) { background: #fff; border-color: #e3e9f2; box-shadow: 0 1px 3px #21355205; }
.order-center-page :deep(.unified-query-toolbar .button),
.order-center-printer-button { border-radius: 8px; border-color: #dce4ef; color: #344054; background: #fff; box-shadow: 0 1px 2px #25385806; }
.order-center-page :deep(.unified-query-toolbar .button--primary) { background: #4568f5; border-color: #4568f5; color: #fff; }
.order-center-page :deep(.unified-query-toolbar .button--primary:hover) { background: #3555dc; }
.order-center-page :deep(.unified-query-scope) { border-color: #e0e7f1; background: #f0f3f8; }
.order-center-page :deep(.unified-query-scope button) { color: #52627a; }
.order-center-page :deep(.unified-query-scope button[aria-pressed='true']) { background: #4568f5; border-color: #4568f5; color: #fff; }
.order-center-page :deep(.unified-query-toolbar__toggle) { border: 1px solid #cbd7e7; background: #eef2f8; color: #344054; font: inherit; }
/* 查询区控件统一触控高度与装饰；不改变按钮次序、权限或事件。 */
.order-center-page :deep(.unified-query-toolbar__primary) { justify-content: flex-start; gap: 8px; }
.order-center-page :deep(.unified-query-toolbar__primary > .button) { height: 36px; min-height: 36px; padding: 0 14px; font-size: 14px; font-weight: 600; box-shadow: 0 1px 2px #2538580d, inset 0 1px 0 #ffffff80; }
.order-center-page :deep(.unified-query-toolbar__primary > .button:hover) { border-color: #aebfdf; box-shadow: 0 2px 5px #25385814; }
.order-center-page :deep(.unified-query-scope) { height: 36px; min-height: 36px; box-sizing: border-box; border-radius: 8px; }
.order-center-page :deep(.unified-query-scope button) { min-width: 54px; height: 30px; min-height: 30px; border-radius: 6px; font-size: 14px; font-weight: 600; }
.order-center-page :deep(.unified-query-scope button[aria-pressed='true']) { box-shadow: 0 1px 3px #2947ae26, inset 0 1px 0 #ffffff40; }
.order-center-page :deep(.unified-query-toolbar input),
.order-center-page :deep(.unified-query-top-field__entity),
.order-center-page :deep(.uq-period-trigger) { border-color: #dce4ef; background: #fff; color: #344054; border-radius: 8px; }
.order-center-page :deep(button:focus-visible) { outline: 2px solid #4568f5; outline-offset: -2px; }
.order-center-list-table th,
.sales-order-query-table > thead > tr > th,
.sales-order-query-table > thead > tr > th:first-child { background: #f0f4f9; color: #435771; font-weight: 600; }
.order-center-list-table .sales-order-query-group__header td { background: #e6ecf3; color: #465b75; }
.sales-order-query-group > tr:not(.sales-order-query-group__header) td { border-bottom-color: #edf1f6; color: #202c3c; }
.sales-order-query-group > tr:not(.sales-order-query-group__header):hover td { background: #f7faff; }
.order-center-page .button--text,
.sales-order-query-group__header .button { color: #315bea; }
.sales-order-query-table td:first-child small.sales-order-query-item-cell__business-tag.is-purchase { background: #e7effd; color: #3565ac; border-color: transparent; }
.sales-order-query-table td:first-child small.sales-order-query-item-cell__business-tag.is-entitlement { background: #d7f2e8; color: #19735b; border-color: transparent; }
.sales-order-query-group__header .sales-order-query-group__upgrade-tag { background: #eaf1ff; color: #2858b8; border-color: #cbdcf7; }
.sales-order-query-item-cell__type { color: #66758b; }
</style>
