<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BusinessRecordDetailOverlay from '@/components/order/BusinessRecordDetailOverlay.vue'
import SalesOrderDetailOverlay from '@/components/order/SalesOrderDetailOverlay.vue'
import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import { orderRecordTypeLabel, orderRecordTypeVisible } from '@/constants/orderRecordTypes'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import OrderCenterReportQueryToolbar from '@/components/query/OrderCenterReportQueryToolbar.vue'
import { useUnifiedQueryPage } from '@mohe/unified-query-vue3/composable'
import { queryPreferenceKey, readQueryPreferences, mergeQueryRefresh, normalizeUnifiedQuerySettings } from '@mohe/unified-query-vue3'
import {
  createCashierV3CommandId,
  canUseCashierV3Feature,
  canUseCashierV3Operation,
  formatMoney,
  mergeCashierV3PublicVersions,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'
import {
  entitlementCheckoutRequestId,
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
import {
  businessReceiptFromRecord,
  businessReceiptRefreshQuery,
  refreshedBusinessReceiptRecord
} from '@/services/orderCenterBusinessReceipt'
import { orderCenterExportDownloadRequest } from '@/services/cashierV3HttpAdapter'
import { queryPlatformOrderCenterScope } from '@/services/platformOrderCenterApi'
import { loadCheckoutBusinessCatalog } from '@/services/cashierBusinessConfigApi'
import { guideRoundText, personnelEmptyText, salespeoplePerformanceText, serviceCraftsmenPerformanceText } from '@/services/orderCenterPersonnelDisplay'
import { nextWholeYuanInputValue } from '@/services/refundWholeYuanInput'
import { orderAttributionExpectedValue } from '@/services/orderAttributionExpectedValue'
import { orderBusinessSourceFields, orderBusinessSourceQuery, orderDebtFields, orderDebtQuery } from '@/services/orderCenterReportFilters'

const field = (key, label, type = 'text', extra = {}) => ({ key, label, type, defaultVisible: true, ...extra })
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
const recordPersonnelSearchError = ref('')
const recordPersonnelSearchLoading = ref(false)
const recordPersonnelSubmitting = ref(false)
const recordPersonnelCommandIds = ref({})
// 补交归属独立于销售人业绩调整，允许清空并保留完整人员版本。
const supplementAttributionEntry = ref(null)
const supplementAttributionTarget = ref(null)
const supplementAttributionEditorOpen = ref(false)
const supplementAttributionPending = ref(null)
const supplementAttributionReason = ref('')
const supplementAttributionError = ref('')
const supplementAttributionSubmitting = ref(false)
const supplementAttributionCommandIds = ref({})
const supplementAttributionSearchLoading = ref(false)
const supplementAttributionSearchError = ref('')
let supplementAttributionSearchSerial = 0
// 退款导购/经理是独立归属，不能复用会分配退款业绩的销售人编辑状态。
const refundAttributionEntry = ref(null)
const refundAttributionTarget = ref(null)
const refundAttributionEditorOpen = ref(false)
const refundAttributionPending = ref(null)
const refundAttributionReason = ref('')
const refundAttributionError = ref('')
const refundAttributionSubmitting = ref(false)
const refundAttributionCommandIds = ref({})
const refundAttributionSearchLoading = ref(false)
const refundAttributionSearchError = ref('')
let refundAttributionSearchSerial = 0
const salesOrderNoteRecord = ref(null)
const salesOrderNoteValue = ref('')
const salesOrderNoteError = ref('')
const salesOrderNoteSubmitting = ref(false)
const salesOrderNoteCommandIds = ref({})
const salesDetailFocus = ref({ lifecycleAction: '', personnelRole: '', personnelLineId: '' })
const salesDetailActionOnly = ref(false)
// 归属更正仅更新业务日／来源，实际发生与收款时间保持只读；后端再次校验门店、权限和旧值。
const attributionEditor = ref(null)
const attributionSources = ref([])
const sourceFilterSources = ref([])
let sourceFilterRequest = null
const attributionChanges = ref([])
const debtChanges = ref([])
const debtChangesLoading = ref(false)
const debtChangesError = ref('')
let debtChangesSerial = 0
// 统一退款独立于原消费订单退款入口；所有候选和金额在提交时由服务端复核。
const importedCardRefundOpen = ref(false)
const importedCardRefundMember = ref(null)
const importedCardRefundCards = ref([])
const unifiedRefundMode = ref('card')
const unifiedRefundLines = ref([])
const unifiedRefundLineId = ref('')
// 余额候选只供展示；提交携带版本，后端锁定会员钱包后再核对本金与赠金。
const unifiedRefundWallet = ref(null)
// 无原单目录项仅作退款归因，不能复用原销售明细 ID 或剩余可退额度。
const unifiedRefundCatalogItem = ref(null)
const unifiedRefundCatalogItems = ref([])
const unifiedRefundCatalogOpen = ref(false)
const unifiedRefundCatalogLoading = ref(false)
const unifiedRefundCatalogKeyword = ref('')
const unifiedRefundCatalogKind = ref('全部')
const effectiveRefundMode = computed(() => unifiedRefundMode.value === 'document' && unifiedRefundCatalogItem.value ? 'catalog' : unifiedRefundMode.value)
const visibleRefundCatalogItems = computed(() => unifiedRefundCatalogItems.value.filter((item) => {
  const kind = String(item.kind || '')
  const keyword = unifiedRefundCatalogKeyword.value.trim().toLocaleLowerCase()
  return (unifiedRefundCatalogKind.value === '全部' || kind === unifiedRefundCatalogKind.value)
    && (!keyword || [item.name, item.specification, item.code].some((part) => String(part || '').toLocaleLowerCase().includes(keyword)))
}))
const unifiedRefundComponentId = ref('')
const unifiedRefundKeyword = ref('')
const unifiedRefundBalanceAmount = ref('0')
// 退款业务日可补录过去日期；实际提交时间仍由服务端记录，不能由表单回填。
const unifiedRefundBusinessDate = ref('')
// 退款销售人是退款自己的人员草稿；切换退款对象时重新从原单读取默认值。
const unifiedRefundPersonnel = ref(null)
const unifiedRefundSalespeople = ref([])
// 导购/经理与退款销售人分别选择；原单只提供默认草稿，不在前端推导轮次或业绩。
const unifiedRefundGuides = ref([])
const unifiedRefundSalesManagers = ref([])
const unifiedRefundPersonnelLoading = ref(false)
const unifiedRefundPersonnelEditorOpen = ref(false)
const unifiedRefundSalespersonSearchLoading = ref(false)
const unifiedRefundSalespersonSearchError = ref('')
let unifiedRefundSalespersonSearchSerial = 0
const unifiedRefundAttributionEditorOpen = ref(false)
const unifiedRefundAttributionSearchLoading = ref(false)
const unifiedRefundAttributionSearchError = ref('')
let unifiedRefundAttributionSearchSerial = 0
let unifiedRefundPersonnelSerial = 0
const unifiedRefundAttributionLabel = computed(() => [
  unifiedRefundGuides.value.length ? `导购:${unifiedRefundGuides.value.map((person) => person.name).join('、')}` : '',
  unifiedRefundSalesManagers.value.length ? `销售经理:${unifiedRefundSalesManagers.value.map((person) => person.name).join('、')}` : ''
].filter(Boolean).join('；'))
const unifiedRefundVoid = ref(false)
const unifiedRefundValidThrough = ref('')
const importedCardRefundLoading = ref(false)
const importedCardRefundSubmitting = ref(false)
const importedCardRefundError = ref('')
const importedCardRefundHolderId = ref('')
const importedCardRefundAmount = ref('')
const importedCardRefundReason = ref('')
const importedCardRefundPage = ref(1)
const importedCardRefundTotal = ref(0)
const selectedUnifiedRefundCard = computed(() => importedCardRefundCards.value.find(
  (card) => String(card.holderId) === String(importedCardRefundHolderId.value)
))
watch(importedCardRefundHolderId, () => {
  // A shared-count card has one whole-card refund target, not one per project.
  unifiedRefundComponentId.value = selectedUnifiedRefundCard.value?.ruleType === 'choice_count' ? '0' : ''
  const end = Number(selectedUnifiedRefundCard.value?.validThrough || 0)
  unifiedRefundValidThrough.value = end > 0
    ? new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(end * 1000))
    : ''
})
// 收到不确定结果时保留同一请求及输入，重试查询原结果，不能换金额重复退款。
const importedCardRefundUncertain = ref(false)
let importedCardRefundQuerySerial = 0
let importedCardRefundCommandId = ''
const attributionPending = ref(false)
const attributionError = ref('')
let attributionChangesSerial = 0

const ORDER_TABS = [
  {
    key: 'sales',
    pageCode: 'order_center_sales',
    label: orderRecordTypeLabel('sales'),
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
      // 明细集合参与整单筛选；分类按每条项目/商品显示，不能借用卡项外壳或只加无后端映射的表头。
      field('item_name', '商品'), field('product_category', '分类'), field('unit_price', '单价', 'money'), field('quantity', '数量', 'number'),
      field('craftsman', '手艺人（类型，业绩，手工、项目数）', 'person'),
      field('sales_manager', '销售经理', 'person'), field('guide', '导购', 'person'),
      field('line_amount', '金额', 'money'), field('occurred_at', '实际下单时间', 'date'),
      field('item_count', '商品数量', 'number'), field('receivable_amount', '应收金额', 'money'),
      field('discount_amount', '优惠金额', 'money'), field('debt_amount', '欠款', 'money'), field('coupon_discount_amount', '优惠券', 'money'),
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
    label: orderRecordTypeLabel('recharge'),
    stateKey: 'rechargeOrders',
    primaryField: 'recharge_order_no',
    searchPlaceholder: '搜索充值订单号、会员姓名或手机号',
    emptyText: '暂无充值订单。',
    fields: [
      field('recharge_order_no', '充值订单号', 'text', { quickFilterHidden: true }), field('source', '客户来源'),
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
    label: orderRecordTypeLabel('refund'),
    stateKey: 'refundOrders',
    primaryField: 'refund_order_no',
    searchPlaceholder: '搜索退款单号、来源订单、会员姓名或手机号',
    emptyText: '暂无退款记录。',
    // 退款来源由服务端记录投影给出；页面不按会员来源或来源单号猜测。
    fields: [
      field('refund_order_no', '退款单号', 'text', { quickFilterHidden: true }),
      field('source', '来源'),
      field('business_date', '退款日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('source_order_no', '来源订单号'), field('member_name', '会员姓名／游客'),
      field('phone', '手机号'), field('refund_summary', '退款内容'),
      field('product_category', '商品分类'), field('product_name', '商品名称'), field('refund_amount', '退款金额', 'money'),
      field('salesperson', '退款销售人', 'person'),
      field('refund_attribution', '导购/销售经理', 'person'),
      field('refund_method', '退款方式'), field('store', '办理门店', 'store'),
      field('operator', '操作人', 'person'), field('refund_status', '退款状态', 'status'),
      field('refund_completed_at', '退款完成时间', 'date')
    ]
  },
  {
    key: 'debt',
    pageCode: 'order_center_debt',
    label: orderRecordTypeLabel('debt'),
    stateKey: 'debtRecords',
    primaryField: 'debt_no',
    searchPlaceholder: '搜索欠款编号、来源订单、会员姓名或手机号',
    emptyText: '暂无欠款记录。欠款以权威欠款事实为准。',
    fields: [
      field('debt_no', '欠款编号', 'text', { quickFilterHidden: true }),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '客户'), field('phone', '手机号'),
      field('source_type', '欠款来源'), field('source_order_no', '来源订单'), field('product_name', '商品名称'),
      field('original_debt_amount', '原欠款', 'money'), field('repaid_amount', '已还', 'money'),
      field('remaining_amount', '剩余', 'money'), field('debt_status', '状态', 'status'),
      field('store', '欠款门店', 'store'), field('created_at', '创建时间', 'date')
    ]
  },
  {
    key: 'service',
    pageCode: 'order_center_service',
    label: orderRecordTypeLabel('service'),
    stateKey: 'serviceRecords',
    primaryField: 'service_record_no',
    searchPlaceholder: '搜索服务记录号、会员、项目、权益来源、卡号或手艺人',
    emptyText: '暂无服务记录。',
    fields: [
      field('service_record_no', '服务记录号', 'text', { quickFilterHidden: true }),
      field('source', '来源'), field('sales_order_no', '销售单号'),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('service_project', '服务项目'),
      field('entitlement_source', '权益来源'), field('source_card', '来源卡名称'),
      field('source_card_no', '完整卡号'), field('used_times', '本次使用次数', 'number'),
      field('detail_remark', '明细备注', 'text', { defaultVisible: false }),
      field('store', '服务门店', 'store'), field('craftsman', '手艺人（类型，业绩，手工、项目数）', 'person'),
      field('labor_performance_type', '服务业绩类型'),
      // 门店消耗来自项目核销，劳动业绩允许独立调整；两列必须保持各自的事实口径。
      field('labor_performance_ratio', '业绩比例'), field('consumption_performance_amount', '消耗业绩', 'money'),
      field('labor_performance_amount', '劳动业绩', 'money'),
      field('operator', '操作人', 'person'),
      field('service_status', '状态', 'status'), field('service_completed_at', '服务完成时间', 'date'),
      field('voided_at', '作废时间', 'date'), field('void_reason', '作废原因'), field('void_operator', '作废操作人', 'person')
    ]
  },
  {
    key: 'supplement',
    pageCode: 'order_center_supplement',
    label: orderRecordTypeLabel('supplement'),
    stateKey: 'supplementOrders',
    primaryField: 'supplement_order_no',
    searchPlaceholder: '搜索补交单号、欠款编号、来源订单或会员',
    emptyText: '暂无已完成的补交记录。会员未补交的欠款请从总欠款入口查看。',
    fields: [
      field('supplement_order_no', '补交单号', 'text', { quickFilterHidden: true }), field('source', '客户来源'),
      field('business_date', '业务日期', 'date', { defaultQuick: true, quickDateRange: true, quickLabelHidden: true }),
      field('member_name', '会员姓名'), field('phone', '手机号'), field('debt_summary', '欠款摘要'), field('product_category', '商品分类'), field('product_name', '商品名称'),
      field('salesperson', '销售人', 'person'), field('supplement_attribution', '导购/销售经理', 'person'), field('supplement_amount', '补交金额', 'money'), field('payment_method', '收款方式'),
      field('store', '补交门店', 'store'), field('operator', '操作人', 'person'),
      field('payment_status', '支付状态', 'status'), field('payment_completed_at', '支付完成时间', 'date'),
      field('debt_no', '欠款编号'), field('source_order_no', '来源订单号')
    ]
  },
  {
    key: 'gift',
    pageCode: 'order_center_gift',
    label: orderRecordTypeLabel('gift'),
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
    label: orderRecordTypeLabel('card_operation'),
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

// 全部订单复用专用页字段契约；金额仍分业务命名，查询/导出由后台统一执行。
const mixedFields = new Map([
  ['business_type', field('business_type', '业务类型', 'enum', { options: ['消费', '充值', '补交', '退款'] })],
  ['order_no', field('order_no', '订单号', 'text', { defaultQuick: true })],
  ['event_completed_at', field('event_completed_at', '办理时间', 'date')]
])
for (const tab of ORDER_TABS.filter((item) => ['sales', 'recharge', 'supplement', 'refund'].includes(item.key))) {
  for (const item of tab.fields) if (!mixedFields.has(item.key)) mixedFields.set(item.key, item)
}
mixedFields.set('business_date', { ...mixedFields.get('business_date'), label: '业务日期' })
// 全部订单的来源固定放在综合查询右侧；选项由当前实例的有效来源目录提供，空来源是独立值。
mixedFields.set('source', { ...mixedFields.get('source'), quickLabel: '来源', defaultQuick: true,
  quickPlacement: 'afterKeyword', quickAllowEmpty: true, quickAllLabel: '全部来源', quickEmptyLabel: '空来源' })
// 分类只加在全部订单：销售逐明细取项目/商品，补交和退款沿用自身来源分类。
const mixedItemCategoryFields = [...mixedFields.values()]
mixedItemCategoryFields.splice(mixedItemCategoryFields.findIndex((item) => item.key === 'product_category'), 1)
mixedItemCategoryFields.splice(mixedItemCategoryFields.findIndex((item) => item.key === 'item_name') + 1, 0,
  { ...mixedFields.get('product_category'), label: '分类' })
ORDER_TABS.unshift({ key: 'all', pageCode: 'order_center_all', label: orderRecordTypeLabel('all'), stateKey: 'allOrders',
  primaryField: 'order_no', emptyText: '当前条件下暂无订单。', fields: mixedItemCategoryFields })

const FIELD_ALIASES = {
  sales_order_no: ['salesOrderNo', 'orderNo'], business_date: ['businessDate'], member_name: ['memberName'],
  store: ['storeName', 'businessStoreName'], item_summary: ['itemSummary'], item_count: ['itemCount'],
  receivable_amount: ['receivableAmount', 'payableAmount'], discount_amount: ['discountAmount'],
  debt_amount: ['debtAmount', 'outstandingDebtAmount'], coupon_discount_amount: ['couponDiscountAmount'], actual_received_amount: ['actualReceivedAmount'],
  payment_method: ['paymentSummary', 'paymentMethod'], salesperson: ['salespersonSummary', 'salespersonName'],
  refund_attribution: ['refundAttributionSummary'],
  supplement_attribution: ['supplementAttributionSummary'],
  cashier: ['cashierName', 'operatorName'], source: ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary'],
  payment_status: ['paymentStatus'], order_status: ['orderStatus', 'statusLabel'], supplement: ['supplementLabel', 'isSupplement'],
  occurred_at: ['occurredAt'], payment_completed_at: ['paymentCompletedAt', 'completedAt'], recharge_order_no: ['rechargeOrderNo', 'orderNo'],
  recharge_plan: ['rechargePlan', 'planName'], recharge_amount: ['rechargeAmount'], gift_amount: ['giftAmount'],
  operator: ['operatorName', 'operator'], supplement_order_no: ['supplementOrderNo', 'repayNo', 'orderNo'],
  debt_no: ['debtNo'], source_order_no: ['sourceOrderNo'], product_category: ['productCategory'], product_name: ['productName'], debt_summary: ['debtSummary', 'summary'],
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
  consumption_performance_amount: ['consumptionPerformanceAmount'],
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
// 平台只增加组织/门店范围上下文；页面、字段、页签和动作仍走同一份权限投影。
const isPlatformContext = computed(() => route.meta.platformOrderContext === true)
// 开关只控制客户业务来源入口；历史事实和技术来源保持完整，缺省兼容已启用实例。
// Reuse the root projection of the existing instance switch; missing initial
// projection is not an instruction to erase a saved route or historical facts.
// 开关必须由权威根投影明确开启；页面直达或平台上下文尚未拿到投影时
// 一律关闭，不能把“缺少配置”误判成“已启用”而泄漏欠款列和入口。
const orderUiPolicy = computed(() => state.orderCenter?.uiPolicy || {})
const debtPaymentEnabled = computed(() => orderUiPolicy.value.debtPaymentEnabled === true)
// 归属入口关闭仅影响订单管理展示，既有订单事实和退款归属不清除。
const salesAttributionEnabled = computed(() => orderUiPolicy.value.salesAttributionEnabled === true)
// 订单中心共用业务分配弹窗，售前关闭仅收起编辑入口，历史标记保持原值。
const preSaleEnabled = computed(() => orderUiPolicy.value.preSaleEnabled === true)
// 本轮只调整全部订单、消费订单；服务记录及其他入口保留既有展示和业务事实。
const orderLaborFeeVisible = computed(() => orderUiPolicy.value.laborFeeVisible === true || !['all', 'sales'].includes(activeTabKey.value))
const hiddenSalesAttributionKeys = new Set(['sales_manager', 'guide', 'refund_attribution', 'supplement_attribution'])
// 只处理本页的列与旧查询参数；历史归属及用户保存的列偏好仍完整保留。
const orderSalesAttributionFields = fields => fields.filter(field => salesAttributionEnabled.value || !hiddenSalesAttributionKeys.has(field?.key))
function orderSalesAttributionQuery(query) {
  if (salesAttributionEnabled.value) return query
  const result = JSON.parse(JSON.stringify(query || {}))
  const keyOf = item => typeof item === 'string' ? item : item?.field_key || item?.fieldKey || item?.field || item?.key
  for (const key of ['topFilters', 'topFilterConditions', 'filters', 'quickFilters', 'keywordFilters', 'sorts', 'summaries', 'groups', 'groupBy', 'visibleFields', 'quickFields']) {
    if (Array.isArray(result[key])) result[key] = result[key].filter(item => !hiddenSalesAttributionKeys.has(keyOf(item)))
  }
  if (result.querySettings) result.querySettings = orderSalesAttributionQuery(result.querySettings)
  if (result.export && Array.isArray(result.export.fields)) result.export.fields = result.export.fields.filter(key => !hiddenSalesAttributionKeys.has(key))
  return result
}
const orderPresentationFields = fields => orderSalesAttributionFields(orderDebtFields(orderBusinessSourceFields(fields, businessSourceEnabled.value), debtPaymentEnabled.value)).map(item => {
  const presented = item?.key === 'craftsman' && !orderLaborFeeVisible.value
    ? { ...item, label: '手艺人（类型，业绩，项目数）' } : item
  // 实例开关可以合法移除来源、欠款及销售归属字段。首屏能力与业务策略
  // 并行返回时，这些可选字段不能触发整页契约失败并隐藏更多筛选和导出。
  return ['source', 'debt_amount', 'sales_manager', 'guide'].includes(presented?.key)
    ? { ...presented, permissionFiltered: true } : presented
})
const businessSourceEnabled = computed(() => state.businessSourcePolicy?.enabled !== false)
const visibleAttributionChanges = computed(() => attributionChanges.value.filter(change => businessSourceEnabled.value || change.field !== 'source'))
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
  const reportRouteName = isPlatformContext.value
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
  const reportRouteName = isPlatformContext.value
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
const activeTabKey = ref(ORDER_TABS.some((tab) => tab.key === requestedPlatformTab) ? requestedPlatformTab : 'all')
const availableTabs = computed(() => {
  const advertised = Array.isArray(orderCenter.value.businessTypes) ? orderCenter.value.businessTypes : []
  if (!advertised.length) return serviceReportDrilldown.value ? ORDER_TABS : ORDER_TABS.filter((tab) => ['all', 'sales'].includes(tab.key))
  const readyKeys = new Set()
  advertised.forEach((item) => {
    if (item && typeof item === 'object' && item.ready === false) return
    const key = typeof item === 'string' ? item : (item?.key || item?.label || '')
    const matched = ORDER_TABS.find((tab) => tab.key === key || tab.label === key)
    if (matched) readyKeys.add(matched.key)
  })
  // Keep source readiness and role gates; the debt switch only narrows visible tabs.
  const tabs = ORDER_TABS.filter((tab) => readyKeys.has(tab.key) && orderRecordTypeVisible(tab.key, debtPaymentEnabled.value))
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
const initialDetailLifecycleAction = ref('')
const salesPrintLoading = ref({})
const salesOrderActionIds = ref({})
const rechargeOrderActionIds = ref({})
let salesQuerySequence = 0
let salesDetailSequence = 0
let recordQuerySequence = 0
// 全部订单查询失败时不把上次列表伪装成新条件结果，也不允许导出旧快照。
const allOrderQueryError = ref('')

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
const sourceFilterOptions = computed(() => sourceFilterSources.value.flatMap((primary) => {
  const primaryName = String(primary?.name || '').trim()
  if (!primaryName) return []
  const options = [{ value: primaryName, label: primaryName }]
  for (const secondary of Array.isArray(primary.children) ? primary.children : []) {
    const secondaryName = String(secondary?.name || '').trim()
    if (secondaryName) options.push({ value: `${primaryName} / ${secondaryName}`, label: `${primaryName} / ${secondaryName}` })
  }
  return options
}))
const queryFields = computed(() => orderPresentationFields(activeTab.value.fields).map((item) => (
  activeTabKey.value === 'all' && item.key === 'source'
    ? { ...item, type: 'enum', options: sourceFilterOptions.value }
    : item
)))
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
  if (activeTabKey.value === 'debt') {
    // Saved column settings predate this requested debt name; keep it next to
    // the source order without changing any other configured column.
    const nameIndex = fields.findIndex((item) => item.key === 'product_name')
    if (nameIndex >= 0) fields.splice(nameIndex, 1)
    const orderIndex = fields.findIndex((item) => item.key === 'source_order_no')
    fields.splice(orderIndex >= 0 ? orderIndex + 1 : 0, 0, available.get('product_name'))
  }
  if (activeTabKey.value === 'refund') {
    // 旧账号保存的列配置没有商品两列；它们是本次退款金额前的固定识别列。
    for (const key of ['product_category', 'product_name']) {
      const index = fields.findIndex((item) => item.key === key)
      if (index >= 0) fields.splice(index, 1)
    }
    const amountIndex = fields.findIndex((item) => item.key === 'refund_amount')
    fields.splice(amountIndex >= 0 ? amountIndex : fields.length, 0,
      available.get('product_category'), available.get('product_name'))
  }
  if (activeTabKey.value === 'service') {
    // 来源与同次销售单号是服务记录的固定审计列。旧账号保存的列清单可能缺少
    // 新字段，故统一补齐并保持“服务记录号→来源→销售单号”，其余列不重排。
    for (const key of ['source', 'sales_order_no']) {
      const index = fields.findIndex((item) => item.key === key)
      if (index >= 0) fields.splice(index, 1)
    }
    const recordNoIndex = fields.findIndex((item) => item.key === 'service_record_no')
    fields.splice(recordNoIndex >= 0 ? recordNoIndex + 1 : 0, 0, ...['source', 'sales_order_no'].map(key => available.get(key)).filter(Boolean))
  }
  if (activeTabKey.value === 'supplement') {
    // 商品名称来自欠款历史快照，分类则跟随平台端当前商品档案。
    // 旧账号可能缺少新列，因此将两列固定恢复到欠款摘要右侧。
    for (const key of ['product_category', 'product_name']) {
      const index = fields.findIndex((item) => item.key === key)
      if (index >= 0) fields.splice(index, 1)
    }
    const summaryIndex = fields.findIndex((item) => item.key === 'debt_summary')
    fields.splice(summaryIndex >= 0 ? summaryIndex + 1 : 0, 0, available.get('product_category'), available.get('product_name'))
  }
  // 销售人是充值/补交金额的归属字段，固定显示在金额左侧；旧版保存的
  // 查询设置可能仍把它排在金额后面，这里只纠正这两个字段的相对位置。
  if (['recharge', 'supplement'].includes(activeTabKey.value)) {
    // 来源是单号后的固定审计列：旧账号无论保存了缺失或旧位置，都按同一顺序展示。
    const sourceIndex = fields.findIndex((item) => item.key === 'source')
    if (sourceIndex >= 0) fields.splice(sourceIndex, 1)
    const orderNoKey = activeTabKey.value === 'recharge' ? 'recharge_order_no' : 'supplement_order_no'
    const orderNoIndex = fields.findIndex((item) => item.key === orderNoKey)
    if (available.has('source')) fields.splice(orderNoIndex >= 0 ? orderNoIndex + 1 : 0, 0, available.get('source'))
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
  // 混合页详情始终使用原业务字段，不能把整张联合字段表塞进一笔充值/退款。
  const recharge = recordType(genericDetailRecord.value) === 'recharge'
  // 充值赠送来自后端该笔充值事实；只在详情加展示项，不改变列表字段配置。
  const giftFields = recharge ? [field('giftContentSummary', '赠送信息')] : []
  if (activeTabKey.value === 'all') return [...orderPresentationFields(recordTab(genericDetailRecord.value).fields), ...giftFields]
  // 取消信息只补充在该笔欠款详情，不改变其他业务记录的字段。
  if (activeTabKey.value === 'debt' && genericDetailRecord.value?.cancelledAt) {
    return [...visibleFields.value, field('cancelledAmount', '取消金额', 'money'),
      field('cancelReason', '取消原因'), field('cancelOperator', '取消操作人'), field('cancelledAt', '取消时间')]
  }
  if (activeTabKey.value !== 'service') return [...visibleFields.value, ...giftFields]
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
  'open-order-services', 'open-order-writeoffs', 'open-order-operation-logs',
  'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order', 'open-sales-order-personnel-adjustment',
  'adjust-sales-order-personnel', 'update-sales-order-note'
])
const salesOrderDetailCommandActions = new Set([
  'print-sales-order-receipt', 'void-sales-order', 'reopen-sales-order', 'upgrade-sales-order',
  'adjust-sales-order-personnel', 'update-sales-order-note'
])
// Only these actions are backed by the V3 sales-order lifecycle resource.
// Other historical detail affordances keep their existing display-ID contract.
const salesOrderLifecycleResourceActions = new Set([
  'adjust-sales-order-personnel', 'update-sales-order-note',
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
  // 导出只冻结已执行的查询并创建文件任务；其回包重建的收银根状态不含当前
  // 订单分页，不能覆盖仍在展示的列表。保留回包版本和任务结果供后续下载。
  return requestCashierV3Action(action, action === 'create-unified-query-export'
    ? { ...payload, preserveRootState: true }
    : payload)
}

function openImportedCardRefundMemberSelector() {
  importedCardRefundError.value = ''
  window.dispatchEvent(new CustomEvent('cashier-v3:open-member-selector', {
    detail: { context: 'imported-card-refund', requireMember: true }
  }))
}

function changeUnifiedRefundMode(mode) {
  if (importedCardRefundSubmitting.value || importedCardRefundUncertain.value || unifiedRefundMode.value === mode) return
  unifiedRefundMode.value = mode
  // 卡项与单据使用不同的搜索字段，切换时不能沿用上一模式的筛选词。
  unifiedRefundKeyword.value = ''
  importedCardRefundHolderId.value = ''
  unifiedRefundComponentId.value = ''
  unifiedRefundLineId.value = ''
  unifiedRefundCatalogItem.value = null
  unifiedRefundWallet.value = null
  importedCardRefundAmount.value = ''
  unifiedRefundBalanceAmount.value = '0'
  unifiedRefundVoid.value = false
  unifiedRefundValidThrough.value = ''
  unifiedRefundPersonnel.value = null
  unifiedRefundSalespeople.value = []
  unifiedRefundGuides.value = []
  unifiedRefundSalesManagers.value = []
  closeUnifiedRefundAttributionEditor()
  if (importedCardRefundMember.value) void loadImportedCardRefundCards(importedCardRefundMember.value)
}

async function loadImportedCardRefundCards(member, page = 1) {
  if (importedCardRefundSubmitting.value || importedCardRefundUncertain.value) return
  const memberId = Number(member?.memberId || member?.id || 0)
  if (!memberId) return
  const serial = ++importedCardRefundQuerySerial
  importedCardRefundOpen.value = true
  importedCardRefundMember.value = member
  importedCardRefundCards.value = []
  unifiedRefundLines.value = []
  unifiedRefundWallet.value = null
  importedCardRefundHolderId.value = ''
  unifiedRefundComponentId.value = ''
  unifiedRefundLineId.value = ''
  unifiedRefundCatalogItem.value = null
  importedCardRefundAmount.value = ''
  unifiedRefundBalanceAmount.value = '0'
  importedCardRefundReason.value = ''
  unifiedRefundBusinessDate.value = refundBusinessToday()
  unifiedRefundPersonnel.value = null
  unifiedRefundSalespeople.value = []
  unifiedRefundGuides.value = []
  unifiedRefundSalesManagers.value = []
  closeUnifiedRefundAttributionEditor()
  importedCardRefundPage.value = page
  importedCardRefundTotal.value = 0
  importedCardRefundError.value = ''
  importedCardRefundLoading.value = true
  try {
    const response = await requestAction('query-unified-refund-candidates', {
      memberId, mode: unifiedRefundMode.value, page, pageSize: 50,
      keyword: unifiedRefundKeyword.value
    })
    if (serial !== importedCardRefundQuerySerial) return
    if (!['success', 'succeeded'].includes(actionStatus(response))) {
      importedCardRefundError.value = response?.result?.message || response?.data?.result?.message || '未能读取可退款对象。'
      return
    }
    const candidates = actionData(response).unifiedRefundCandidates || {}
    importedCardRefundCards.value = candidates.cards || []
    unifiedRefundLines.value = candidates.lines || []
    unifiedRefundWallet.value = candidates.balance || null
    importedCardRefundTotal.value = Number(candidates.total) || 0
  } catch (error) {
    if (serial === importedCardRefundQuerySerial) importedCardRefundError.value = error?.message || '未能读取可退款对象。'
  } finally {
    if (serial === importedCardRefundQuerySerial) importedCardRefundLoading.value = false
  }
}

// Picker contents come from the store-scoped server catalog; selecting here only drafts a refund label.
async function openUnifiedRefundCatalog() {
  if (importedCardRefundUncertain.value || unifiedRefundCatalogLoading.value) return
  unifiedRefundCatalogOpen.value = true
  unifiedRefundCatalogKeyword.value = ''
  unifiedRefundCatalogKind.value = '全部'
  unifiedRefundCatalogItems.value = []
  unifiedRefundCatalogLoading.value = true
  importedCardRefundError.value = ''
  try {
    const response = await requestAction('query-unified-refund-candidates', {
      memberId: Number(importedCardRefundMember.value?.memberId || importedCardRefundMember.value?.id || 0), mode: 'catalog'
    })
    if (!['success', 'succeeded'].includes(actionStatus(response))) throw new Error(response?.result?.message || '目录读取失败。')
    unifiedRefundCatalogItems.value = actionData(response).unifiedRefundCandidates?.items || []
  } catch (error) {
    importedCardRefundError.value = error?.message || '目录读取失败。'
    unifiedRefundCatalogOpen.value = false
  } finally {
    unifiedRefundCatalogLoading.value = false
  }
}

// A catalog target and an original sale line are mutually exclusive refund identities.
function selectUnifiedRefundCatalogItem(item) {
  unifiedRefundLineId.value = ''
  unifiedRefundCatalogItem.value = item
  unifiedRefundCatalogOpen.value = false
  unifiedRefundBalanceAmount.value = '0'
  unifiedRefundVoid.value = false
  importedCardRefundAmount.value = ''
}

function handleImportedCardRefundMemberSelected(event) {
  if (event?.detail?.context !== 'imported-card-refund') return
  void loadImportedCardRefundCards(event.detail.record)
}

function closeImportedCardRefund() {
  if (importedCardRefundSubmitting.value || importedCardRefundUncertain.value) return
  importedCardRefundQuerySerial++
  importedCardRefundOpen.value = false
  importedCardRefundMember.value = null
  importedCardRefundCards.value = []
  unifiedRefundLines.value = []
  unifiedRefundWallet.value = null
  importedCardRefundLoading.value = false
  importedCardRefundSubmitting.value = false
  importedCardRefundError.value = ''
  importedCardRefundHolderId.value = ''
  unifiedRefundComponentId.value = ''
  unifiedRefundLineId.value = ''
  unifiedRefundCatalogItem.value = null
  unifiedRefundCatalogOpen.value = false
  importedCardRefundAmount.value = ''
  unifiedRefundBalanceAmount.value = '0'
  // 每次新开退款都从卡项模式开始；余额退款不继承上次的账户版本。
  unifiedRefundBusinessDate.value = ''
  unifiedRefundMode.value = 'card'
  unifiedRefundKeyword.value = ''
  unifiedRefundVoid.value = false
  unifiedRefundValidThrough.value = ''
  importedCardRefundReason.value = ''
  unifiedRefundPersonnelSerial++
  unifiedRefundPersonnel.value = null
  unifiedRefundSalespeople.value = []
  unifiedRefundGuides.value = []
  unifiedRefundSalesManagers.value = []
  closeUnifiedRefundPersonnelEditor()
  closeUnifiedRefundAttributionEditor()
  unifiedRefundPersonnelLoading.value = false
  importedCardRefundCommandId = ''
}

watch(
  () => [effectiveRefundMode.value, importedCardRefundHolderId.value, unifiedRefundLineId.value, unifiedRefundCatalogItem.value?.skuId, importedCardRefundMember.value?.memberId || importedCardRefundMember.value?.id],
  async ([mode, holderId, lineId, catalogItemId, memberId]) => {
    const serial = ++unifiedRefundPersonnelSerial
    unifiedRefundPersonnel.value = null
    unifiedRefundSalespeople.value = []
    unifiedRefundGuides.value = []
    unifiedRefundSalesManagers.value = []
    closeUnifiedRefundPersonnelEditor()
    closeUnifiedRefundAttributionEditor()
    // 余额退款没有来源商品；只读取可选员工，不从历史订单猜默认销售人。
    if (!importedCardRefundOpen.value || !memberId
      || (mode === 'card' ? !holderId : mode === 'document' ? !lineId : mode === 'catalog' ? !catalogItemId : false)) return
    unifiedRefundPersonnelLoading.value = true
    try {
      const response = await requestAction('query-unified-refund-salespeople', {
        mode, memberId: Number(memberId), holderId: Number(holderId || 0), lineId: String(lineId || ''), catalogItemId: Number(catalogItemId || 0)
      })
      if (serial !== unifiedRefundPersonnelSerial) return
      if (!['success', 'succeeded'].includes(actionStatus(response))) {
        importedCardRefundError.value = response?.result?.message || response?.data?.result?.message || '原单销售人读取失败。'
        return
      }
      const entry = actionData(response).refundSalespeople
      unifiedRefundPersonnel.value = entry
      unifiedRefundSalespeople.value = Array.isArray(entry?.currentSalespeople) ? entry.currentSalespeople : []
      unifiedRefundGuides.value = Array.isArray(entry?.currentGuides) ? entry.currentGuides : []
      unifiedRefundSalesManagers.value = Array.isArray(entry?.currentSalesManagers) ? entry.currentSalesManagers : []
    } catch (error) {
      if (serial === unifiedRefundPersonnelSerial) importedCardRefundError.value = error?.message || '原单销售人读取失败。'
    } finally {
      if (serial === unifiedRefundPersonnelSerial) unifiedRefundPersonnelLoading.value = false
    }
  }
)

function setUnifiedRefundSalespeople(assignment) {
  unifiedRefundSalespeople.value = Array.isArray(assignment?.salespeople) ? assignment.salespeople : []
  closeUnifiedRefundPersonnelEditor()
}

function openUnifiedRefundPersonnelEditor() {
  if (!unifiedRefundPersonnel.value || unifiedRefundPersonnelLoading.value) return
  unifiedRefundSalespersonSearchError.value = ''
  unifiedRefundPersonnelEditorOpen.value = true
}

function closeUnifiedRefundPersonnelEditor() {
  // A late group search must not populate the next refund target's candidate list.
  unifiedRefundSalespersonSearchSerial++
  unifiedRefundSalespersonSearchLoading.value = false
  unifiedRefundSalespersonSearchError.value = ''
  unifiedRefundPersonnelEditorOpen.value = false
}

// 集团搜索只提供退款分配候选；最终员工身份与权限由退款命令在事务内重验。
async function searchUnifiedRefundOtherSalespeople({ scope, keyword, target } = {}) {
  const value = String(keyword || '').trim()
  if (scope !== 'cashier_other_salespeople' || target !== 'otherSalespeople'
    || !unifiedRefundPersonnelEditorOpen.value || value.length < 2) return
  const entry = unifiedRefundPersonnel.value
  if (!entry) return
  const serial = ++unifiedRefundSalespersonSearchSerial
  unifiedRefundSalespersonSearchLoading.value = true
  unifiedRefundSalespersonSearchError.value = ''
  try {
    const records = []
    let page = 1
    let total = 0
    do {
      const response = await requestAction('query-query-entities', {
        entityType: 'person', selectorEntry: 'order_center',
        selectorContext: { scope: 'cashier_other_salespeople' },
        keyword: value, page, pageSize: 20, silent: true
      })
      const data = actionData(response)
      if (!['success', 'succeeded'].includes(actionStatus(response)) || !Array.isArray(data.records)) {
        throw new Error(response?.result?.message || response?.data?.result?.message || '其他销售人搜索失败，请重试。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page++
    } while (records.length < total && page <= 20)
    if (serial !== unifiedRefundSalespersonSearchSerial || entry !== unifiedRefundPersonnel.value
      || !unifiedRefundPersonnelEditorOpen.value) return
    unifiedRefundPersonnel.value = { ...entry, otherSalespersonCandidates: records }
  } catch (error) {
    if (serial === unifiedRefundSalespersonSearchSerial && unifiedRefundPersonnelEditorOpen.value) {
      unifiedRefundSalespersonSearchError.value = error?.message || '其他销售人搜索失败，请重试。'
    }
  } finally {
    if (serial === unifiedRefundSalespersonSearchSerial) unifiedRefundSalespersonSearchLoading.value = false
  }
}

function setUnifiedRefundAttribution(assignment) {
  unifiedRefundGuides.value = Array.isArray(assignment?.guideSelections) ? assignment.guideSelections : []
  unifiedRefundSalesManagers.value = Array.isArray(assignment?.salesManagerSelections) ? assignment.salesManagerSelections : []
  closeUnifiedRefundAttributionEditor()
}

function openUnifiedRefundAttributionEditor() {
  if (!unifiedRefundPersonnel.value || unifiedRefundPersonnelLoading.value) return
  unifiedRefundAttributionSearchError.value = ''
  unifiedRefundAttributionEditorOpen.value = true
}

function closeUnifiedRefundAttributionEditor() {
  // 关闭后晚到的集团搜索不能覆盖下一次打开的候选与人员草稿。
  unifiedRefundAttributionSearchSerial++
  unifiedRefundAttributionSearchLoading.value = false
  unifiedRefundAttributionSearchError.value = ''
  unifiedRefundAttributionEditorOpen.value = false
}

async function searchUnifiedRefundAttribution({ scope, keyword, target } = {}) {
  if (scope !== 'group_attributions' || !['guide', 'salesManager'].includes(target)
    || !unifiedRefundAttributionEditorOpen.value || String(keyword || '').trim().length < 2) return
  const entry = unifiedRefundPersonnel.value
  if (!entry) return
  const serial = ++unifiedRefundAttributionSearchSerial
  unifiedRefundAttributionSearchLoading.value = true
  unifiedRefundAttributionSearchError.value = ''
  try {
    const records = []
    let page = 1
    let total = 0
    do {
      const response = await requestAction('query-query-entities', {
        entityType: 'person', selectorEntry: 'order_center',
        selectorContext: { scope: 'group_attributions', lineRole: 'sale',
          lineId: String(unifiedRefundLineId.value || entry.sourceSalesLineId || ''),
          memberId: Number(importedCardRefundMember.value?.memberId || importedCardRefundMember.value?.id || 0) },
        keyword: String(keyword).trim(), page, pageSize: 20, silent: true
      })
      const data = actionData(response)
      if (!['success', 'succeeded'].includes(actionStatus(response)) || !Array.isArray(data.records)) {
        throw new Error(response?.result?.message || response?.data?.result?.message || '人员搜索失败。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page += 1
    } while (records.length < total && page <= 20)
    if (serial !== unifiedRefundAttributionSearchSerial || entry !== unifiedRefundPersonnel.value
      || !unifiedRefundAttributionEditorOpen.value) return
    unifiedRefundPersonnel.value = { ...entry,
      ...(target === 'guide' ? { guides: records } : { salesManagers: records }) }
  } catch (error) {
    if (serial === unifiedRefundAttributionSearchSerial) {
      unifiedRefundAttributionSearchError.value = error?.message || '人员搜索失败。'
    }
  } finally {
    if (serial === unifiedRefundAttributionSearchSerial) unifiedRefundAttributionSearchLoading.value = false
  }
}

function refundPersonnelPayload(rows) {
  return (rows || []).map((person) => ({
    staffId: Number(person.staffId || person.id || 0),
    // 原单跨店销售人必须以集团身份回传；服务端据此重新锁定员工资格。
    ...(person.personnelSource === 'other' ? { personnelSource: 'other' } : {}),
    allocationWeight: Number(person.allocationWeight || person.performance || 0),
    isPreSale: Boolean(person.isPreSale || person.marked),
    performanceAmountCents: Math.max(0, Math.trunc(Number(person.performanceAmountCents || 0))),
    performanceAmountManual: Boolean(person.performanceAmountManual),
    positionId: Number(person.positionId || 0),
    performanceIndependent: Boolean(person.performanceIndependent),
    allocationGroupKey: person.allocationGroupKey || ''
  }))
}

function importedCardRefundMoney(cents) {
  return formatMoney((Math.max(0, Number(cents) || 0) / 100).toFixed(2))
}

// Refund amounts are whole yuan only. Reject invalid insertions (including paste)
// instead of stripping punctuation, which could turn "12.5" into "125".
function blockNonDigitRefundInput(event) {
  const inserted = event.data ?? event.dataTransfer?.getData('text/plain')
  if (typeof inserted === 'string' && /[^0-9]/.test(inserted)) event.preventDefault()
}

function updateWholeYuanRefundInput(event, field) {
  const amountRef = field === 'refund' ? importedCardRefundAmount : unifiedRefundBalanceAmount
  const next = nextWholeYuanInputValue(amountRef.value, event.target.value)
  if (event.target.value !== next) event.target.value = next
  amountRef.value = next
}

async function submitImportedCardRefund() {
  if (importedCardRefundSubmitting.value) return
  const card = importedCardRefundCards.value.find((item) => String(item.holderId) === String(importedCardRefundHolderId.value))
  const line = unifiedRefundLines.value.find((item) => item.lineId === unifiedRefundLineId.value)
  const catalogItem = unifiedRefundCatalogItem.value
  const mode = effectiveRefundMode.value
  const amount = String(importedCardRefundAmount.value || '').trim()
  const balanceAmount = String(unifiedRefundBalanceAmount.value || '0').trim()
  const reason = String(importedCardRefundReason.value || '').trim()
  const businessDate = String(unifiedRefundBusinessDate.value || '').trim()
  if (unifiedRefundMode.value === 'card' && (!card?.canRefund || unifiedRefundComponentId.value === '')) {
    importedCardRefundError.value = '请选择一张卡的可退款项目。'; return
  }
  if (mode === 'document' && !line) {
    importedCardRefundError.value = '请选择单据中的一条购买项目或产品明细。'; return
  }
  if (mode === 'document' && !line.canRefund) {
    // Candidate availability is advisory; the server recomputes the tender on submission.
    importedCardRefundError.value = line.unavailableReason || '这条明细不能办理退款。'; return
  }
  if (mode === 'catalog' && !catalogItem?.skuId) {
    importedCardRefundError.value = '请选择退款商品。'; return
  }
  if (mode === 'balance' && !unifiedRefundWallet.value?.accountVersion) {
    importedCardRefundError.value = '请重新查询会员本金和赠金。'; return
  }
  // Card refunds may record zero cash without changing rights; other modes still require positive cash.
  if (!/^(?:0|[1-9][0-9]{0,7})$/.test(amount) || (mode !== 'card' && Number(amount) <= 0)
    || !/^(?:0|[1-9][0-9]{0,7})$/.test(balanceAmount)) {
    importedCardRefundError.value = `本次退款金额和${mode === 'balance' ? '扣减余额' : '转入余额'}只能填写整数元。`; return
  }
  if (mode === 'balance' && Number(balanceAmount) <= 0) {
    importedCardRefundError.value = '扣减余额必须大于 0。'; return
  }
  // Card candidates expose the debt-adjusted amount separately; the gross
  // unconsumed value remains the immutable rights/refund history basis.
  const remaining = mode === 'card'
    ? Number(card?.usableRemainingAmountCents || 0) / 100 : Number(line?.remainingAmountCents || 0) / 100
  const targetRemaining = mode === 'card'
    ? Number(card?.components?.find((item) => String(item.id) === unifiedRefundComponentId.value)?.usableRemainingAmountCents || 0) / 100
    : remaining
  // Only a zero-price card with zero cash refund may grant extra bonus credit;
  // all cash refunds and paid-card bonus transfers retain their source cap.
  const zeroPriceBonusGrant = mode === 'card' && Number(card?.purchaseAmountCents || 0) === 0 && Number(amount) === 0
  if (mode === 'balance' && Number(balanceAmount) > Number(unifiedRefundWallet.value.totalCents) / 100) {
    importedCardRefundError.value = '扣减余额超过当前剩余余额，请重新查询。'; return
  }
  if (!['catalog', 'balance'].includes(mode) && (Number(amount) > targetRemaining || (!zeroPriceBonusGrant && Number(balanceAmount) > remaining))) {
    importedCardRefundError.value = '本次退款金额或转入余额超过实际剩余金额。'; return
  }
  if (!reason || reason.length > 255) { importedCardRefundError.value = '请填写退款原因（不超过 255 字）。'; return }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate) || businessDate > refundBusinessToday()) {
    importedCardRefundError.value = '请选择不晚于今天的有效退款日期。'; return
  }
  // 退款销售人可留空；金额仍由退款命令落账，仅选了人员时才产生员工扣减。
  // 默认人员仍在加载时先等待，避免原单人员草稿晚到后覆盖本次提交选择。
  if (unifiedRefundPersonnelLoading.value) {
    importedCardRefundError.value = '退款销售人信息读取中，请稍后重试。'; return
  }
  if (!importedCardRefundCommandId) importedCardRefundCommandId = createCashierV3CommandId()
  importedCardRefundSubmitting.value = true
  importedCardRefundUncertain.value = true
  importedCardRefundError.value = ''
  try {
    const response = await requestAction('refund-card-or-line', {
      mode,
      holderId: card?.holderId || 0,
      componentId: Number(unifiedRefundComponentId.value || 0),
      lineId: line?.lineId || '',
      catalogItemId: mode === 'catalog' ? Number(catalogItem.skuId) : 0,
      memberId: Number(importedCardRefundMember.value?.memberId || importedCardRefundMember.value?.id || 0),
      refundAmount: amount,
      balanceAmount,
      balanceKind: mode === 'balance' ? 'principal_first' : 'bonus',
      balanceVersion: mode === 'balance' ? Number(unifiedRefundWallet.value.accountVersion) : 0,
      businessDate,
      salespeople: refundPersonnelPayload(unifiedRefundSalespeople.value),
      guideSelections: unifiedRefundGuides.value.map((person) => ({ staffId: Number(person.employeeId || person.staffId || person.id), guideRoundNo: 0 })),
      salesManagerSelections: unifiedRefundSalesManagers.value.map((person) => ({ staffId: Number(person.employeeId || person.staffId || person.id) })),
      voidTarget: unifiedRefundVoid.value,
      validThrough: unifiedRefundMode.value === 'card' && card?.isTimeCard
        ? Math.floor(new Date(`${unifiedRefundValidThrough.value}T23:59:59+08:00`).getTime() / 1000) : 0,
      reason,
      idempotencyKey: importedCardRefundCommandId
    })
    const status = actionStatus(response)
    if (['success', 'succeeded'].includes(status)) {
      const result = actionData(response).unifiedRefund
      if (!result?.operationNo) {
        // 已提交但回执不完整时保留同一幂等键，不能把重试变成第二笔退款。
        importedCardRefundError.value = '退款已登记，但结果未完整返回；请保持当前内容，重试查询原结果。'
        importedCardRefundUncertain.value = true
        return
      }
      importedCardRefundUncertain.value = false
      importedCardRefundSubmitting.value = false
      closeImportedCardRefund()
      // 查询函数以当前页签决定记录类型；成功后先切到退款页并清除旧筛选，
      // 否则仍会查询消费订单，或被上次搜索条件隐藏刚登记的退款。
      activeTabKey.value = 'refund'
      queryModelByType.value = { ...queryModelByType.value, refund: {} }
      executedQueryByType.value = { ...executedQueryByType.value, refund: null }
      // 成功回执已确认，列表刷新失败也不能把已登记的退款当作失败重提。
      try {
        await queryRecords({ ...defaultOrderCenterDateQuery(businessDate), recordType: 'refund', page: 1, keyword: '' }, true)
      } catch { /* 保持退款页，下一次查询仍从权威记录读取。 */ }
    } else {
      if (isTerminalActionStatus(status)) {
        importedCardRefundCommandId = ''
        importedCardRefundUncertain.value = false
      }
      importedCardRefundError.value = response?.result?.message || response?.data?.result?.message || '退款未完成。'
    }
  } catch (error) {
    importedCardRefundError.value = '退款结果尚未确认，请保持原输入并重试确认，避免重复退款。'
  } finally {
    importedCardRefundSubmitting.value = false
  }
}

// The normal list projection remains the order-center authority. This companion
// capability is only used to freeze that successfully executed query for the
// shared async export worker; it never exports browser rows.
const unifiedQueryPages = Object.fromEntries(ORDER_TABS.map((tab) => [tab.key, useUnifiedQueryPage({
  pageCode: tab.pageCode,
  pageName: tab.label,
  baseFields: () => orderPresentationFields(tab.fields),
  requestAction
})]))
const activeUnifiedQuery = computed(() => unifiedQueryPages[activeTabKey.value] || unifiedQueryPages.sales)
// 平台和门店使用同一查询草稿与日期默认值；平台只多组织选择器。
const platformOrderCenterDefaultDateRanges = computed(() => ({}))
const activeQueryCapability = computed(() => {
  return activeUnifiedQuery.value?.capability?.value || {}
})
const activeQueryFields = computed(() => {
  const fields = orderPresentationFields(activeUnifiedQuery.value?.fields?.value || queryFields.value)
  if (activeTabKey.value !== 'all') return fields
  const sourceUi = queryFields.value.find((item) => item.key === 'source')
  // 服务端仍把来源声明为可精确过滤的文本字段；这里只合并下拉选项和页面位置，不能改其查询 key。
  return fields.map((item) => item.key === 'source' && sourceUi
    ? { ...item, type: 'enum', options: sourceUi.options, defaultQuick: sourceUi.defaultQuick,
        quickLabel: sourceUi.quickLabel,
        quickPlacement: sourceUi.quickPlacement, quickAllowEmpty: sourceUi.quickAllowEmpty,
        quickAllLabel: sourceUi.quickAllLabel, quickEmptyLabel: sourceUi.quickEmptyLabel }
    : item)
})
const executedQuery = computed(() => executedQueryByType.value[activeTabKey.value] || null)

// 列表 Provider 在未指定排序时按各业务完成时间倒序；导出计划必须冻结同一
// 排序，否则通用计划只追加 record_id 升序，Excel 行顺序会与查询结果相反。
const ORDER_CENTER_DEFAULT_SORT_FIELD = {
  all: 'event_completed_at', sales: 'payment_completed_at', recharge: 'payment_completed_at',
  refund: 'refund_completed_at', debt: 'created_at', service: 'service_completed_at',
  supplement: 'payment_completed_at', gift: 'created_at', card_operation: 'completed_at'
}

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
    sorts: Array.isArray(rest.sorts) && rest.sorts.length
      ? rest.sorts
      : [{ field: ORDER_CENTER_DEFAULT_SORT_FIELD[recordType], direction: 'desc' }],
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
  const download = orderCenterExportDownloadRequest(endpoint.href, {
    mode: isPlatformContext.value ? 'platform-order-center' : 'store'
  })
  const response = await fetch(download.url, download.init)
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
  // 补交归属只读本笔后端返回的人员快照，不能从原销售单或当前员工资料猜测。
  if (recordType(record) === 'supplement' && ['sales_manager', 'guide'].includes(key) && Array.isArray(record?.attributionPeople)) {
    const people = record.attributionPeople.filter(person => person.role === key)
    return key === 'guide'
      ? (people.length ? guideRoundText(people) : '')
      : people.map(person => String(person.employee_name_snapshot || '').trim()).filter(Boolean).join('、')
  }
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
  // 仅在业务支持归属时将空值显示为未分配；可编辑性仍由原有权限与命令校验决定。
  const assignablePersonnel = (key === 'salesperson' && recordType(record) === 'recharge')
    || (recordType(record) === 'supplement' && record?.attributionEditable === true
      && ['salesperson', 'sales_manager', 'guide', 'supplement_attribution'].includes(key))
  if (assignablePersonnel
    && (Array.isArray(value) ? value.length === 0 : value === undefined || value === null || value === '')) return '未分配'
  if (Array.isArray(value)) return value.length ? value.join('、') : '—'
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
  return memberDetailPayload(record) !== null && canUseCashierV3Feature('cashier.v3.member_list')
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
  return record?.record_id || record?.id || record?.recordId || recordFieldValue(record, activeTab.value.primaryField) || `${activeTabKey.value}-${index}`
}

// 混合页必须使用服务端给出的业务类型；原 ID 专供命令，跨类型行键只用于展示。
function recordType(record) {
  return activeTabKey.value === 'all' ? String(record?.recordType || '') : activeTabKey.value
}
function recordTab(record) {
  return ORDER_TABS.find((tab) => tab.key === recordType(record)) || activeTab.value
}
function recordTypeLabel(record) {
  return { sales: '消费', recharge: '充值', supplement: '补交', refund: '退款' }[recordType(record)] || ''
}
function canEditRecordPersonnel(record) {
  const type = recordType(record)
  return canUseCashierV3Operation('cashier.v3.order.staff_adjust')
    && (type === 'recharge' || (type === 'supplement' && record?.attributionEditable === true)
      || (type === 'refund' && editableRefundPersonnelSourceTypes.has(record?.refundSourceType)))
}
function canEditRecordRoles(record) {
  return canEditRecordPersonnel(record) && (recordType(record) === 'supplement'
    || (recordType(record) === 'refund' && editableRefundSourceTypes.has(record?.refundSourceType)))
}
function openRecordRoles(record) {
  return recordType(record) === 'supplement' ? openSupplementAttributionEditor(record) : openRefundAttributionEditor(record)
}
function recordVoidAction(record) {
  if (!canUseCashierV3Operation('cashier.v3.order.void')) return ''
  const type = recordType(record)
  if (type === 'recharge' && record.economicsDataStatus === 'ready' && record.orderStatus === '正常') return 'void'
  if (type === 'supplement' && String(record.id || '').startsWith('v3-') && record.paymentStatus !== '已作废') return 'supplement-void'
  if (type === 'refund' && editableRefundSourceTypes.has(record.refundSourceType) && record.refundStatus === '已退款') return 'refund-record-void'
  return ''
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
const salesOrderListColumnKeys = ['item_name', 'product_category', 'unit_price', 'quantity', 'craftsman', 'salesperson', 'sales_manager', 'guide', 'line_amount', 'receivable_amount', 'debt_amount', 'coupon_discount_amount', 'actual_received_amount', 'payment_method', 'store', 'order_status']
const salesOrderListColumns = computed(() => {
  return orderPresentationFields(salesOrderListColumnKeys.map(key => ORDER_TABS.find((tab) => tab.key === 'sales').fields.find((item) => item.key === key))).map((item) => item.label)
})

function salesOrderItems(record) {
  if (Array.isArray(record?.items) && record.items.length) return record.items
  return [{
    id: `${recordKey(record, 0)}-summary`,
    name: displayRecordField(record, 'item_summary'),
    itemType: '',
    quantity: displayRecordField(record, 'item_count'),
    unitPrice: null,
    payableAmount: recordFieldValue(record, 'receivable_amount'),
    amountBeforeCoupon: record?.amountBeforeCoupon ?? null,
    craftsmen: [],
    salespeople: [],
    salesManagers: [],
    guides: [],
    assignmentAvailability: {}
  }]
}

function salesOrderItemCustomerTag(item) {
  // Only settled project snapshots may label a counted friend or excluded service.
  if (item?.itemType !== '项目' || !['self', 'friend'].includes(item?.serviceRecipientType)) return ''
  if (item.friendCountsAsCustomer === false || item.friendCountsAsCustomer === 0 || item.friendCountsAsCustomer === '0') return '不算人数人次'
  return item.serviceRecipientType === 'friend' ? '朋友算' : ''
}

function personnelNames(item, key) {
  const records = Array.isArray(item?.[key]) ? item[key] : []
  return records.map((person) => person?.name || person?.employeeName || person?.employee_name_snapshot || '').filter(Boolean).join('、') || personnelEmptyText(item, key)
}

function salesItemSalespeopleText(item) {
  const text = salespeoplePerformanceText(item?.salespeople)
  return text === '—' ? personnelEmptyText(item, 'salespeople') : text
}

function salesItemGuidesText(item) {
  const text = guideRoundText(item?.guides)
  return text === '—' ? personnelEmptyText(item, 'guides') : text
}

function salesItemCraftsmenText(item) {
  // 销售单中的关联服务行与“服务记录”共用同一展示契约，
  // 避免同一服务在两个页签显示不同的业绩、手工费和项目数。
  if (Array.isArray(item?.craftsmenListAllocations)) {
    const text = serviceCraftsmenPerformanceText(item, orderLaborFeeVisible.value)
    return text === '—' ? personnelEmptyText(item, 'craftsmen') : text
  }
  return personnelNames(item, 'craftsmen')
}

function salesItemCraftsmanAdjustable(item = {}) {
  return serviceCraftsmanAdjustable(item)
    && canUseCashierV3Operation('cashier.v3.order.service_detail')
}

function salesPurchaseCraftsmanAdjustable(record = {}, item = {}) {
  return !item?.serviceFactId
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
  const actions = record?.availableActions
  const available = Array.isArray(actions) ? actions.includes(action) : actions?.[action] === true
  return available && (!permission || canUseCashierV3Operation(permission))
}

function attributionIdentity(record, tab = recordType(record)) {
  const raw = String(record?.id || record?.orderId || record?.salesOrderId || '')
  // 纯服务列表的 service: 前缀只是展示分组键；写命令必须使用后端给出的结账请求 ID。
  if (tab === 'sales' && record?.entitlementOnly) return { recordType: 'service', recordId: String(record?.checkoutRequestId || '') }
  if (tab === 'sales') return { recordType: 'sales', recordId: raw }
  if (tab === 'recharge') return { recordType: 'recharge', recordId: raw.replace(/^recharge:/, '') }
  if (tab === 'refund') return { recordType: 'refund', recordId: raw.replace(/^refund:/, '') }
  if (tab === 'supplement') return { recordType: 'supplement', recordId: raw.replace(/^v3-(?:sales-)?supplement:/, '') }
  return null
}

// 三类手工退款共用日期和销售人更正入口；原单关联与否由后端按退款类型处理。
const editableRefundSourceTypes = new Set(['card', 'document', 'catalog', 'balance'])
// 旧卡退款只开放人员改派；缺少原权益快照的日期更正和作废仍保持关闭。
const editableRefundPersonnelSourceTypes = new Set([...editableRefundSourceTypes, 'imported_card'])

// 列表能力仅决定是否展示蓝色入口；后端仍以当前会话门店、权限及旧值独立判定。
function canCorrectAttribution(record, field) {
  if (field === 'source' && !businessSourceEnabled.value) return false
  // 消费订单是页面 feature，不在叶子 operation 快照中；误查 operation 会让有权门店隐藏更正入口。
  // 销售行还须由后端明确授予更正动作，实际写入仍由后端按当前会话与门店重新校验。
  if (!canUseCashierV3Feature('cashier.v3.order_center')) return false
  const type = recordType(record)
  if (type === 'sales') {
    if (record?.entitlementOnly) {
      const action = field === 'source' ? 'correct-service-order-attribution' : 'correct-service-order-date'
      return ['business_date', 'source'].includes(field) && salesOrderActionAvailable(record, action)
    }
    return salesOrderActionAvailable(record, 'correct-sales-order-attribution')
  }
  if (type === 'recharge') return record?.attributionEditable === true
  if (type === 'refund') return field === 'business_date'
    && editableRefundSourceTypes.has(record?.refundSourceType)
  return type === 'supplement' && ['business_date', 'source'].includes(field)
    && record?.attributionEditable === true
}

function attributionAction(type, field) {
  return type === 'sales' ? 'correct-sales-order-attribution'
    : type === 'service' ? (field === 'source' ? 'correct-service-order-attribution' : 'correct-service-order-date')
    : type === 'refund' ? 'correct-refund-date'
    : type === 'recharge' ? 'correct-recharge-order-attribution'
      : field === 'source' ? 'correct-debt-repayment-source' : 'correct-debt-repayment-date'
}

async function readAttributionChanges(record, tab = recordType(record)) {
  // 详情切换时只接收最后一次请求，避免旧订单的审计记录落到新订单详情。
  const serial = ++attributionChangesSerial
  attributionChanges.value = []
  const identity = attributionIdentity(record, tab)
  if (!identity || !['sales', 'service', 'recharge', 'supplement', 'refund'].includes(identity.recordType)) return
  try {
    const response = await requestAction('query-order-attribution-changes', identity)
    if (serial === attributionChangesSerial && ['success', 'succeeded'].includes(actionStatus(response))) {
      attributionChanges.value = actionData(response).attributionChanges?.changes || []
    }
  } catch { /* 详情仍可查看；审计读取失败不应遮蔽订单。 */ }
}

async function readDebtChanges(record) {
  // 详情可快速切换欠款，旧请求不得覆盖当前债务的操作历史。
  const serial = ++debtChangesSerial
  debtChanges.value = []
  debtChangesError.value = ''
  debtChangesLoading.value = true
  try {
    const response = await requestAction('query-order-center-debt-changes', { debtId: record.debtId })
    if (serial !== debtChangesSerial) return
    if (!['success', 'succeeded'].includes(actionStatus(response))) {
      debtChangesError.value = response?.result?.message || '变更记录读取失败。'
      return
    }
    debtChanges.value = actionData(response).debtChanges?.changes || []
  } catch (error) {
    if (serial === debtChangesSerial) debtChangesError.value = error?.message || '变更记录读取失败。'
  } finally {
    if (serial === debtChangesSerial) debtChangesLoading.value = false
  }
}

async function openAttributionEditor(record, field) {
  if (!canCorrectAttribution(record, field)) return
  const identity = attributionIdentity(record)
  if (!identity) return
  attributionError.value = ''
  const displayedValue = String(recordFieldValue(record, field) || '')
  const expectedValue = orderAttributionExpectedValue(record, field, displayedValue)
  attributionEditor.value = {
    ...identity, field, recordNo: displayRecordField(record, recordTab(record).primaryField),
    expectedValue,
    newValue: field === 'business_date' ? displayedValue : '',
    expectedPrimarySourceId: Number(record.businessSourcePrimaryId || 0),
    expectedSecondarySourceId: Number(record.businessSourceSecondaryId || 0),
    primarySourceId: Number(record.businessSourcePrimaryId || 0),
    secondarySourceId: Number(record.businessSourceSecondaryId || 0)
  }
  if (field === 'source' && !attributionSources.value.length) {
    try {
      const response = await requestAction('query-order-attribution-sources', {})
      if (!['success', 'succeeded'].includes(actionStatus(response))) {
        attributionError.value = response?.result?.message || '来源选项读取失败。'
        return
      }
      attributionSources.value = actionData(response).attributionSources?.sources || []
    } catch (error) {
      attributionError.value = error?.message || '来源选项读取失败。'
    }
  }
}

async function loadSourceFilterOptions() {
  if (!businessSourceEnabled.value) return
  if (sourceFilterSources.value.length) return
  if (sourceFilterRequest) return sourceFilterRequest
  // 下拉只读取当前实例的有效业务来源目录，不提交订单命令，也不依赖归属更正权限。
  sourceFilterRequest = loadCheckoutBusinessCatalog()
    .then((catalog) => { sourceFilterSources.value = Array.isArray(catalog.sources) ? catalog.sources : [] })
    .catch(() => { sourceFilterSources.value = [] })
    .finally(() => { sourceFilterRequest = null })
  return sourceFilterRequest
}

const selectedAttributionPrimary = computed(() => attributionSources.value.find(
  (source) => Number(source.id) === Number(attributionEditor.value?.primarySourceId)
))

async function submitAttributionCorrection() {
  const editor = attributionEditor.value
  if (!editor || attributionPending.value) return
  attributionError.value = ''
  if (editor.field === 'business_date' && (!/^\d{4}-\d{2}-\d{2}$/.test(editor.newValue) || editor.newValue === editor.expectedValue)) {
    attributionError.value = '请选择不同于原日期的有效业务日期。'
    return
  }
  if (editor.field === 'source' && !editor.primarySourceId) {
    attributionError.value = '请选择来源。'
    return
  }
  attributionPending.value = true
  try {
    const response = await requestAction(attributionAction(editor.recordType, editor.field), {
      recordType: editor.recordType, recordId: editor.recordId, field: editor.field,
      expectedValue: editor.expectedValue, newValue: editor.newValue,
      expectedPrimarySourceId: editor.expectedPrimarySourceId, expectedSecondarySourceId: editor.expectedSecondarySourceId,
      primarySourceId: editor.primarySourceId, secondarySourceId: editor.secondarySourceId,
      idempotencyKey: createCashierV3CommandId()
    })
    if (!['success', 'succeeded'].includes(actionStatus(response))) {
      attributionError.value = response?.result?.message || response?.data?.result?.message || '更正未完成，请刷新后重试。'
      return
    }
    attributionEditor.value = null
    serviceVoidNotice.value = '归属更正已保存，可在订单详情的变更记录查看。'
    try { await queryRecords({}, false) } catch { serviceVoidNotice.value += ' 列表刷新失败，请手动点击查询。' }
    if (isSalesDetailOpen.value && ['sales', 'service'].includes(editor.recordType)) await readAttributionChanges(salesDetailOrder.value, 'sales')
    if (genericDetailRecord.value && !['sales', 'service'].includes(editor.recordType)) await readAttributionChanges(genericDetailRecord.value, editor.recordType)
  } catch (error) {
    attributionError.value = error?.message || '更正未完成，请稍后重试。'
  } finally {
    attributionPending.value = false
  }
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
  if (record?.entitlementOnly && role === 'guides') {
    await openServiceGuideEditor(record, item)
    return
  }
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

// 纯权益行没有销售主单；由服务端重新读取导购事实及旧值标识后才开放更正。
async function openServiceGuideEditor(record, item) {
  if (!salesOrderActionAvailable(record, 'correct-service-order-guide', 'cashier.v3.order.staff_adjust')
    || !Number(item?.serviceFactId) || !record?.checkoutRequestId) return
  salesPersonnelError.value = ''
  salesPersonnelSearchError.value = ''
  salesPersonnelEntry.value = null
  salesPersonnelTarget.value = null
  const result = await requestAction('open-service-order-guide-correction', {
    checkoutRequestId: record.checkoutRequestId,
    serviceFactId: Number(item.serviceFactId)
  })
  const entry = actionData(result).serviceGuideCorrection
  if (!entry || !['success', 'succeeded'].includes(String(actionStatus(result)))) {
    salesPersonnelError.value = result?.result?.message || result?.data?.result?.message || '导购资料读取失败，请刷新后重试。'
    return
  }
  salesPersonnelTarget.value = {
    orderId: String(entry.checkoutRequestId), lineId: String(entry.sourceLineId),
    serviceFactId: Number(entry.serviceFactId), role: 'guide', uiRole: 'guides', entitlementOnly: true
  }
  salesPersonnelEntry.value = {
    memberId: Number(entry.memberId), salesOrderNo: entry.serviceRecordNo,
    expectedFactIds: entry.expectedFactIds,
    lines: [{ orderLineId: String(entry.sourceLineId), itemName: entry.itemName,
      currentGuides: entry.currentGuides }],
    guides: entry.currentGuides
  }
  salesPersonnelEditorOpen.value = true
}

function salesPersonnelCandidates(role) {
  const entry = salesPersonnelEntry.value || {}
  return role === 'guide' ? (entry.guides || [])
    : role === 'sales_manager' ? (entry.salesManagers || [])
      : role === 'craftsman' ? (entry.craftsmen || []) : (entry.salespeople || [])
}

async function searchSalesPersonnelAttributions({ scope, keyword, target } = {}) {
  const role = target === 'guide' ? 'guide' : target === 'salesManager' ? 'sales_manager'
    : target === 'otherSalespeople' ? 'salesperson' : ''
  const entry = salesPersonnelEntry.value
  const activeTarget = salesPersonnelTarget.value
  const searchKeyword = String(keyword || '').trim()
  if (!['group_attributions', 'cashier_other_salespeople'].includes(scope) || !role || activeTarget?.role !== role
    || (role === 'salesperson' && scope !== 'cashier_other_salespeople')
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
          scope,
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
      ...(role === 'guide' ? { guides: records }
        : role === 'salesperson' ? { otherSalespersonCandidates: records } : { salesManagers: records })
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
    // Cross-store selections must keep their virtual staff identity; matching
    // a local candidate by employee ID would silently change the save source.
    const external = selected.personnelSource === 'other'
    const candidate = external ? null : candidates.find((entry) => Number(entry.employeeId || entry.staffId || entry.id || 0) === employeeId)
    const staffId = external ? Number(selected.staffId || selected.id || 0) : Number(candidate?.staffId || selected.staffId || employeeId)
    return {
      ...selected,
      ...(candidate || {}),
      id: staffId,
      staffId,
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
      ...(item.personnelSource === 'other' ? { personnelSource: 'other' } : {}),
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
    // 纯权益没有销售主单；提交导购与轮次，后端以整组服务事实校验并同步轮次。
    const result = target.entitlementOnly
      ? await requestAction('correct-service-order-guide', {
        checkoutRequestId: target.orderId,
        serviceFactId: target.serviceFactId,
        expectedFactIds: entry.expectedFactIds,
        guideEmployeeIds: personnel.map((item) => item.staffId),
        guideRoundNo: personnel[0]?.guideRoundNo,
        reason,
        idempotencyKey
      })
      : await requestAction('adjust-sales-order-personnel', {
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

function recordPersonnelActionNames(type, refundSourceType = '') {
  return type === 'recharge'
    ? { open: 'open-recharge-personnel-adjustment', adjust: 'adjust-recharge-personnel' }
    : type === 'refund'
      ? { open: 'open-refund-personnel-adjustment', adjust: refundSourceType === 'imported_card'
        ? 'adjust-imported-refund-personnel' : 'adjust-refund-personnel' }
    : { open: 'open-supplement-personnel-adjustment', adjust: 'adjust-supplement-personnel' }
}

function recordPersonnelId(record, type) {
  if (type === 'recharge') return String(record?.rechargeId || String(record?.id || '').replace(/^recharge:/, ''))
  if (type === 'refund') return String(record?.operationId || String(record?.id || '').replace(/^refund:/, ''))
  return String(record?.repaymentId || String(record?.id || '').replace(/^v3-(?:sales-)?supplement:/, ''))
}

function recordPersonnelSelected(line = {}) {
  return Array.isArray(line.currentSalespeople) ? line.currentSalespeople : []
}

function recordPersonnelCandidates(entry = {}) {
  return Array.isArray(entry.salespeople) ? entry.salespeople : []
}

async function searchRecordOtherSalespeople({ scope, keyword, target } = {}) {
  if (scope !== 'cashier_other_salespeople' || target !== 'otherSalespeople') return
  const entry = recordPersonnelEntry.value
  const record = recordPersonnelTarget.value
  const value = String(keyword || '').trim()
  if (!entry || record?.type !== 'refund' || value.length < 2) return
  recordPersonnelSearchError.value = ''
  recordPersonnelSearchLoading.value = true
  try {
    const records = []
    let page = 1
    let total = 0
    do {
      const result = await requestAction('query-query-entities', {
        entityType: 'person', selectorEntry: 'order_center',
        selectorContext: { scope, lineId: record.recordId, lineRole: 'sale' },
        keyword: value, page, pageSize: 20, silent: true
      })
      const data = actionData(result)
      if (!['success', 'succeeded'].includes(String(actionStatus(result))) || !Array.isArray(data.records)) {
        throw new Error(result?.result?.message || result?.data?.result?.message || '其他销售人搜索失败，请重试。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page += 1
    } while (records.length < total && page <= 20)
    // Only update the editor that initiated this search; never replace its saved selection.
    if (recordPersonnelEntry.value === entry && recordPersonnelTarget.value === record) {
      recordPersonnelEntry.value = { ...entry, otherSalespersonCandidates: records }
    }
  } catch (error) {
    if (recordPersonnelEntry.value === entry) recordPersonnelSearchError.value = error?.message || '其他销售人搜索失败，请重试。'
  } finally {
    recordPersonnelSearchLoading.value = false
  }
}

function recordPersonnelInitialSelection(line = {}) {
  const candidates = recordPersonnelCandidates(recordPersonnelEntry.value || {})
  return recordPersonnelSelected(line).map((selected) => {
    const employeeId = Number(selected.employeeId || selected.staffId || selected.id || 0)
    const external = selected.personnelSource === 'other'
    const candidate = external ? null : candidates.find((item) => Number(item.employeeId || item.staffId || item.id || 0) === employeeId)
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
      id: external ? Number(selected.staffId || selected.id || 0) : Number(candidate?.staffId || selected.staffId || employeeId),
      staffId: external ? Number(selected.staffId || selected.id || 0) : Number(candidate?.staffId || selected.staffId || employeeId),
      employeeId,
      performanceAmountCents,
      performanceAmountLocked: Boolean(
        (recordPersonnelTarget.value?.type === 'refund' && Object.prototype.hasOwnProperty.call(selected, 'performanceAmountCents'))
        || (selected.performanceAmountLocked
          ?? selected.performance_amount_locked
          ?? Object.prototype.hasOwnProperty.call(selected, 'amountCents'))
      ),
      selected: true
    }
  })
}

async function openRecordPersonnelEditor(record) {
  const type = recordType(record)
  if (!['recharge', 'supplement', 'refund'].includes(type) || !record
    || !canUseCashierV3Operation('cashier.v3.order.staff_adjust')) return
  if (type === 'refund' && !editableRefundPersonnelSourceTypes.has(record.refundSourceType)) return
  const recordId = recordPersonnelId(record, type)
  if (!recordId) return
  const actions = recordPersonnelActionNames(type, record.refundSourceType)
  recordPersonnelError.value = ''
  recordPersonnelSearchError.value = ''
  recordPersonnelEntry.value = null
  recordPersonnelTarget.value = { type, recordId, refundSourceType: record.refundSourceType,
    recordNo: type === 'refund' ? String(record.refundOrderNo || '') : '' }
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
    if (!Number.isSafeInteger(recordVersion) || recordVersion < (type === 'refund' ? 0 : 1) || (type !== 'refund' && !stateContextId)) {
      recordPersonnelError.value = '销售人记录版本读取失败，请刷新后重新打开。'
      return
    }
    if (type !== 'refund') {
      const kind = type === 'recharge' ? 'recharge_order' : 'debt_repayment'
      mergeCashierV3PublicVersions([{ kind, id: String(entry.recordId || recordId), version: recordVersion }], stateContextId, { requestStateContextId: stateContextId })
    }
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
  recordPersonnelSearchError.value = ''
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
    ...(item.personnelSource === 'other' ? { personnelSource: 'other' } : {}),
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
  const actions = recordPersonnelActionNames(target.type, target.refundSourceType)
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

// 补交列表值仅用于定位，编辑时从补交归属版本重新读取人员和并发版本。
async function openSupplementAttributionEditor(record) {
  if (recordType(record) !== 'supplement' || !canUseCashierV3Operation('cashier.v3.order.staff_adjust')) return
  const recordId = recordPersonnelId(record, 'supplement')
  if (!recordId || !String(record?.id || '').startsWith('v3-')) return
  supplementAttributionError.value = ''
  supplementAttributionTarget.value = { recordId, recordNo: String(record.supplementOrderNo || '') }
  try {
    const response = await requestAction('open-supplement-attribution-adjustment', { recordId })
    const entry = actionData(response).supplementAttributionAdjustment
    if (!['success', 'succeeded'].includes(String(actionStatus(response))) || !entry
      || !Number.isSafeInteger(Number(entry.recordVersion)) || Number(entry.recordVersion) < 0) {
      throw new Error(response?.result?.message || '补交归属读取失败，请刷新后重试。')
    }
    supplementAttributionEntry.value = entry
    supplementAttributionEditorOpen.value = true
  } catch (error) {
    supplementAttributionError.value = error?.message || '补交归属读取失败，请刷新后重试。'
  }
}

function closeSupplementAttributionEditor(force = false) {
  if (supplementAttributionSubmitting.value && !force) return
  supplementAttributionSearchSerial++
  supplementAttributionEditorOpen.value = false
  supplementAttributionEntry.value = null
  supplementAttributionTarget.value = null
  supplementAttributionPending.value = null
  supplementAttributionReason.value = ''
  supplementAttributionError.value = ''
}

function prepareSupplementAttributionReason(assignment = {}) {
  supplementAttributionPending.value = assignment
  supplementAttributionEntry.value = { ...supplementAttributionEntry.value,
    currentGuides: assignment.guideSelections || [],
    currentSalesManagers: assignment.salesManagerSelections || [] }
  supplementAttributionReason.value = ''
  supplementAttributionError.value = ''
  supplementAttributionEditorOpen.value = false
}

function cancelSupplementAttributionReason() {
  if (supplementAttributionSubmitting.value) return
  supplementAttributionPending.value = null
  supplementAttributionReason.value = ''
  supplementAttributionEditorOpen.value = Boolean(supplementAttributionEntry.value)
}

async function searchSupplementAttribution({ scope, keyword, target } = {}) {
  if (scope !== 'group_attributions' || !['guide', 'salesManager'].includes(target)
    || !supplementAttributionEditorOpen.value || String(keyword || '').trim().length < 2) return
  const entry = supplementAttributionEntry.value
  const serial = ++supplementAttributionSearchSerial
  supplementAttributionSearchLoading.value = true
  supplementAttributionSearchError.value = ''
  try {
    const people = []
    let page = 1
    let total = 0
    do {
      const response = await requestAction('query-query-entities', {
        entityType: 'person', selectorEntry: 'order_center',
        selectorContext: { scope: 'group_attributions', lineRole: 'sale',
          memberId: Number(entry.memberId || 0) },
        keyword: String(keyword).trim(), page, pageSize: 20, silent: true
      })
      const data = actionData(response)
      if (!['success', 'succeeded'].includes(String(actionStatus(response))) || !Array.isArray(data.records)) {
        throw new Error(response?.result?.message || '人员搜索失败。')
      }
      people.push(...data.records)
      total = Math.max(0, Number(data.total) || people.length)
      page++
    } while (people.length < total && page <= 20)
    if (serial !== supplementAttributionSearchSerial || entry !== supplementAttributionEntry.value) return
    supplementAttributionEntry.value = { ...entry,
      [target === 'guide' ? 'guides' : 'salesManagers']: people }
  } catch (error) {
    if (serial === supplementAttributionSearchSerial) supplementAttributionSearchError.value = error?.message || '人员搜索失败。'
  } finally {
    if (serial === supplementAttributionSearchSerial) supplementAttributionSearchLoading.value = false
  }
}

async function submitSupplementAttributionAdjustment() {
  const entry = supplementAttributionEntry.value
  const target = supplementAttributionTarget.value
  const reason = String(supplementAttributionReason.value || '').trim()
  if (!entry || !target) return
  if (!reason || reason.length > 255) { supplementAttributionError.value = '请填写不超过255字的修改原因。'; return }
  const selected = supplementAttributionPending.value || {}
  const guideSelections = (selected.guideSelections || []).map((item) => ({
    staffId: Number(item.employeeId || item.staffId || item.id || 0), guideRoundNo: Number(item.guideRoundNo || 0)
  }))
  const salesManagerSelections = (selected.salesManagerSelections || []).map((item) => ({
    staffId: Number(item.employeeId || item.staffId || item.id || 0)
  }))
  const key = `${target.recordId}:${entry.recordVersion}`
  const idempotencyKey = supplementAttributionCommandIds.value[key] || createCashierV3CommandId()
  supplementAttributionCommandIds.value = { ...supplementAttributionCommandIds.value, [key]: idempotencyKey }
  supplementAttributionSubmitting.value = true
  supplementAttributionError.value = ''
  try {
    const response = await requestAction('adjust-supplement-attribution', {
      recordId: target.recordId, recordVersion: entry.recordVersion,
      guideSelections, salesManagerSelections, reason, idempotencyKey
    })
    const status = actionStatus(response)
    if (isTerminalActionStatus(status)) supplementAttributionCommandIds.value = { ...supplementAttributionCommandIds.value, [key]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeSupplementAttributionEditor(true)
      await queryRecords({}, false)
      return
    }
    supplementAttributionError.value = response?.result?.message || '补交归属修改未完成，请稍后重试。'
  } catch (error) {
    supplementAttributionError.value = error?.message || '补交归属修改未完成，请稍后重试。'
  } finally {
    supplementAttributionSubmitting.value = false
  }
}

// 列表值只负责定位记录；当前人员与并发版本必须重新从退款权威记录读取。
async function openRefundAttributionEditor(record) {
  if (recordType(record) !== 'refund' || !editableRefundSourceTypes.has(record?.refundSourceType)
    || !canUseCashierV3Operation('cashier.v3.order.staff_adjust')) return
  const recordId = recordPersonnelId(record, 'refund')
  if (!recordId) return
  refundAttributionError.value = ''
  refundAttributionEntry.value = null
  refundAttributionTarget.value = { recordId, recordNo: String(record.refundOrderNo || '') }
  try {
    const response = await requestAction('open-refund-attribution-adjustment', { recordId })
    const entry = actionData(response).refundAttributionAdjustment
    if (!['success', 'succeeded'].includes(String(actionStatus(response))) || !entry
      || !Number.isSafeInteger(Number(entry.recordVersion)) || Number(entry.recordVersion) < 0) {
      throw new Error(response?.result?.message || response?.data?.result?.message || '退款归属读取失败，请刷新后重试。')
    }
    refundAttributionEntry.value = entry
    refundAttributionEditorOpen.value = true
  } catch (error) {
    refundAttributionError.value = error?.message || '退款归属读取失败，请刷新后重试。'
  }
}

function closeRefundAttributionEditor(force = false) {
  if (refundAttributionSubmitting.value && !force) return
  refundAttributionSearchSerial++
  refundAttributionSearchLoading.value = false
  refundAttributionSearchError.value = ''
  refundAttributionEditorOpen.value = false
  refundAttributionEntry.value = null
  refundAttributionTarget.value = null
  refundAttributionPending.value = null
  refundAttributionReason.value = ''
}

function prepareRefundAttributionReason(assignment = {}) {
  refundAttributionPending.value = assignment
  // 原选择器在原因弹窗出现时会卸载；返回修改仍应保留尚未提交的人员草稿。
  refundAttributionEntry.value = { ...refundAttributionEntry.value,
    currentGuides: assignment.guideSelections || [],
    currentSalesManagers: assignment.salesManagerSelections || [] }
  refundAttributionReason.value = ''
  refundAttributionError.value = ''
  refundAttributionEditorOpen.value = false
}

function cancelRefundAttributionReason() {
  if (refundAttributionSubmitting.value) return
  refundAttributionPending.value = null
  refundAttributionReason.value = ''
  refundAttributionError.value = ''
  refundAttributionEditorOpen.value = Boolean(refundAttributionEntry.value)
}

async function searchRefundAttribution({ scope, keyword, target } = {}) {
  if (scope !== 'group_attributions' || !['guide', 'salesManager'].includes(target)
    || !refundAttributionEditorOpen.value || String(keyword || '').trim().length < 2) return
  const entry = refundAttributionEntry.value
  if (!entry) return
  const serial = ++refundAttributionSearchSerial
  refundAttributionSearchLoading.value = true
  refundAttributionSearchError.value = ''
  try {
    const records = []
    let page = 1
    let total = 0
    do {
      const response = await requestAction('query-query-entities', {
        entityType: 'person', selectorEntry: 'order_center',
        selectorContext: { scope: 'group_attributions', lineRole: 'sale',
          lineId: String(entry.sourceSalesLineId || ''), memberId: Number(entry.memberId || 0) },
        keyword: String(keyword).trim(), page, pageSize: 20, silent: true
      })
      const data = actionData(response)
      if (!['success', 'succeeded'].includes(String(actionStatus(response))) || !Array.isArray(data.records)) {
        throw new Error(response?.result?.message || response?.data?.result?.message || '人员搜索失败。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page++
    } while (records.length < total && page <= 20)
    if (serial !== refundAttributionSearchSerial || entry !== refundAttributionEntry.value
      || !refundAttributionEditorOpen.value) return
    refundAttributionEntry.value = { ...entry,
      ...(target === 'guide' ? { guides: records } : { salesManagers: records }) }
  } catch (error) {
    if (serial === refundAttributionSearchSerial) refundAttributionSearchError.value = error?.message || '人员搜索失败。'
  } finally {
    if (serial === refundAttributionSearchSerial) refundAttributionSearchLoading.value = false
  }
}

// 仅提交人员身份、当前版本和审计原因，不从浏览器回写原单或退款业绩。
async function submitRefundAttributionAdjustment() {
  const entry = refundAttributionEntry.value
  const target = refundAttributionTarget.value
  const reason = String(refundAttributionReason.value || '').trim()
  if (!entry || !target) return
  if (!reason || reason.length > 255) { refundAttributionError.value = '请填写不超过255字的修改原因。'; return }
  const selected = refundAttributionPending.value || {}
  const guideSelections = (selected.guideSelections || []).map((item) => ({
    staffId: Number(item.staffId || item.id || 0), guideRoundNo: 0
  }))
  const salesManagerSelections = (selected.salesManagerSelections || []).map((item) => ({
    staffId: Number(item.staffId || item.id || 0)
  }))
  const key = `${target.recordId}:${entry.recordVersion}`
  const idempotencyKey = refundAttributionCommandIds.value[key] || createCashierV3CommandId()
  refundAttributionCommandIds.value = { ...refundAttributionCommandIds.value, [key]: idempotencyKey }
  refundAttributionSubmitting.value = true
  refundAttributionError.value = ''
  try {
    const response = await requestAction('adjust-refund-attribution', {
      recordId: target.recordId, recordVersion: entry.recordVersion,
      guideSelections, salesManagerSelections, reason, idempotencyKey
    })
    const status = actionStatus(response)
    if (isTerminalActionStatus(status)) refundAttributionCommandIds.value = { ...refundAttributionCommandIds.value, [key]: null }
    if (['success', 'succeeded'].includes(String(status))) {
      closeRefundAttributionEditor(true)
      await queryRecords({}, false)
      return
    }
    refundAttributionError.value = response?.result?.message || response?.data?.result?.message || '退款归属修改未完成，请稍后重试。'
  } catch (error) {
    refundAttributionError.value = error?.message || '退款归属修改未完成，请稍后重试。'
  } finally {
    refundAttributionSubmitting.value = false
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

function orderCenterToday() {
  const now = new Date()
  const offset = now.getTimezoneOffset() * 60000
  return new Date(now.getTime() - offset).toISOString().slice(0, 10)
}

// 退款业务日和服务端事实统一按门店经营时区，不依赖浏览器所处时区。
function refundBusinessToday() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
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

function defaultOrderCenterDateQuery(date = orderCenterToday()) {
  // 首屏默认当天；补录退款成功后改用本次选定业务日，避免新记录被当天筛选隐藏。
  return {
    dataScope: 'normal',
    businessStatus: '',
    dateFrom: date,
    dateTo: date,
    businessDateFrom: date,
    businessDateTo: date,
    topFilters: [
      { field: 'business_date', operator: 'gte', value: date },
      { field: 'business_date', operator: 'lte', value: date }
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
  // 首屏与工具栏共用配置归一化，空配置/旧 and 不能绕过 all/any 契约；不修改本地偏好或业务命令。
  const settings = normalizeUnifiedQuerySettings(querySettings.value)
  return reportServiceDateQuery() || reportSalesDateQuery() || {
    ...defaultOrderCenterDateQuery(),
    filters: settings.filters || [], filterRelation: settings.filterRelation,
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
  if (!isPlatformContext.value) return query
  const { storeIds: ignoredStoreIds, store_ids: ignoredStoreIdsSnakeCase, ...rest } = query
  return platformScopeStoreIds.value.length
    ? { ...rest, storeIds: [...platformScopeStoreIds.value] }
    : rest
}

async function loadPlatformOrderScope() {
  if (!isPlatformContext.value || platformScopePicker.value.loading) return
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
  query = orderSalesAttributionQuery(orderDebtQuery(orderBusinessSourceQuery(mergeQueryRefresh(previous, query), businessSourceEnabled.value), debtPaymentEnabled.value))
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
    if (recordType === 'all') allOrderQueryError.value = ''
    try {
      const result = await requestAction('query-order-center-records', nextQuery)
      const projection = salesOrderProjectionFromResult(result)
      if (!projection && recordType === 'all' && sequence === recordQuerySequence && activeTabKey.value === recordType) {
        allOrderQueryError.value = result?.result?.message || result?.data?.result?.message || '订单查询失败，请重新点击查询。'
        executedQueryByType.value = { ...executedQueryByType.value, all: null }
      }
      if (projection && sequence === recordQuerySequence && activeTabKey.value === recordType) {
        state.orderCenter = mergeSalesOrderCenterProjection(state.orderCenter, projection)
        saveExecutedQuery(recordType, nextQuery)
        // 删除/作废后的末页可能变空，只回退查询页码，不再次执行业务操作。
        if (!records.value.length && targetPage > 1) return await queryRecords({ ...nextQuery, page: targetPage - 1 }, false)
      }
      return result
    } catch (error) {
      if (recordType !== 'all') throw error
      if (sequence === recordQuerySequence && activeTabKey.value === recordType) {
        allOrderQueryError.value = '订单查询失败，请重新点击查询。'
        executedQueryByType.value = { ...executedQueryByType.value, all: null }
      }
      return null
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
  void readAttributionChanges(current.record || { id: current.orderId }, 'sales')
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
  attributionChangesSerial++
  attributionChanges.value = []
}

function closeSalaryLinkedSalesDetail() {
  const returnTo = route.query.salary_detail_return_to
  closeSalesDetail()
  if (typeof returnTo !== 'string' || !returnTo.startsWith('/') || returnTo.startsWith('//') || returnTo.length > 4096) return
  const resolved = router.resolve(returnTo)
  // Only the salary-detail link may return to a store report; other order-detail closes stay here.
  if (route.name === 'cashier-v3-order-center'
    && route.query.salary_detail_order_id
    && resolved.name === 'cashier-v3-store-business-reports'
    && resolved.params.report === 'phase_six_salary_detail') void router.replace(resolved.fullPath)
}

function closeGenericDetail() {
  attributionChangesSerial++
  attributionChanges.value = []
  debtChangesSerial++
  debtChanges.value = []
  debtChangesLoading.value = false
  debtChangesError.value = ''
  genericDetailRecord.value = null
  initialDetailLifecycleAction.value = ''
}

function openRecordDetail(record, initialAction = '') {
  if (recordType(record) === 'sales') {
    return openSalesOrderDetail({ orderId: record?.id || record?.orderId || record?.salesOrderId })
  }
  if (activeTabKey.value === 'service' && !canUseCashierV3Operation('cashier.v3.order.service_detail')) return
  initialDetailLifecycleAction.value = initialAction
  genericDetailRecord.value = record
  void readAttributionChanges(record)
  if (activeTabKey.value === 'debt') void readDebtChanges(record)
  return null
}

// 列表作废入口与详情操作共用同一权限、状态及 V3 身份边界。
function canVoidProjectReplacement(record) {
  return activeTabKey.value === 'card_operation'
    && String(record?.id || '').startsWith('card-operation:')
    && record?.operationType === 'project_replacement'
    && record?.recordStatus === '已完成'
    && canUseCashierV3Operation('cashier.v3.order.void')
}

function openProjectReplacementVoid(record) {
  if (!canVoidProjectReplacement(record)) return
  openRecordDetail(record, 'replacement-void')
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

function canPrintBusinessRecord(record) {
  return ['recharge', 'supplement', 'refund'].includes(recordType(record))
    && canUseCashierV3Operation('cashier.v3.order.receipt_print')
}

async function printBusinessRecord(record = {}) {
  const type = recordType(record)
  const query = businessReceiptRefreshQuery(type, record)
  const recordId = String(record.id || '').trim()
  if (!query) {
    servicePrintError.value = '单据缺少业务单号，暂无法生成小票。'
    return
  }
  if (salesPrintLoading.value[recordId]) return
  servicePrintError.value = ''
  salesPrintLoading.value = { ...salesPrintLoading.value, [recordId]: true }
  try {
    // 打印前重读服务端权限投影，避免将旧列表中的作废状态或金额印成现状。
    const response = await requestAction('query-order-center-records', query)
    const partition = salesOrderProjectionFromResult(response)
    const current = refreshedBusinessReceiptRecord(partition, type, record)
    if (!current) {
      servicePrintError.value = '单据已变化或当前无权读取，请刷新列表后重试。'
      return
    }
    const receipt = businessReceiptFromRecord(type, current)
    if (!receipt) {
      servicePrintError.value = '单据金额或单号不完整，暂无法生成小票。'
      return
    }
    const printed = openSalesOrderReceiptPrint(receipt)
    if (!printed.ok) servicePrintError.value = printed.message
  } catch (error) {
    servicePrintError.value = error?.message || '小票暂时无法生成，请稍后重试。'
  } finally {
    const next = { ...salesPrintLoading.value }
    delete next[recordId]
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
  // 一条权益服务同样是完整的纯权益消费单；使用后端组标识提交一次整单冲销。
  const checkoutRequestId = entitlementCheckoutRequestId(record)
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
    const result = await requestAction('void-service-record', {
      checkoutRequestId,
      orderId: `service:${checkoutRequestId}`,
      reason,
      idempotencyKey
    })
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

// 取消以列表当前金额作为确认快照，提交期间若有补交由后端拒绝，不扩大免除金额。
const debtCancelRecord = ref(null)
const debtCancelReason = ref('')
const debtCancelError = ref('')
const debtCancelPending = ref(false)
const debtCancelKeys = ref({})
function openDebtCancellation(record) {
  debtCancelRecord.value = record
  debtCancelReason.value = ''
  debtCancelError.value = ''
}
async function submitDebtCancellation() {
  if (debtCancelPending.value || !debtCancelRecord.value) return
  const record = debtCancelRecord.value
  const reason = debtCancelReason.value.trim()
  if (!reason || reason.length > 255) { debtCancelError.value = '请填写取消原因（不超过255字）。'; return }
  const key = String(record.debtId)
  const idempotencyKey = debtCancelKeys.value[key] || createCashierV3CommandId()
  debtCancelKeys.value[key] = idempotencyKey
  debtCancelPending.value = true
  debtCancelError.value = ''
  try {
    const result = await requestAction('cancel-order-center-debt', {
      debtId: record.debtId, remainingAmount: record.remainingAmount, reason, idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) delete debtCancelKeys.value[key]
    if (['success', 'succeeded'].includes(status)) {
      debtCancelRecord.value = null
      // 正常范围隐藏已取消欠款；定位本欠款并清除旧状态筛选，让恢复入口立即可见。
      await queryRecords({ dataScope: 'all', status: '', businessStatus: '', keyword: record.debtNo }, true)
      // 返回收银时需重新读取总欠款；不在浏览器手工减金额。
      window.dispatchEvent(new CustomEvent('cashier-v3:debt-cancelled', { detail: { memberId: record.memberId } }))
    } else debtCancelError.value = result?.result?.message || result?.data?.result?.message || '取消未完成。'
  } catch (error) { debtCancelError.value = error?.message || '取消未完成，请稍后重试。' }
  finally { debtCancelPending.value = false }
}

const debtRestorePending = ref({})
const debtRestoreKeys = ref({})
const debtRestoreError = ref('')
async function restoreDebt(record) {
  if (!record?.canRestore || !record.cancellationId || debtRestorePending.value[record.debtId]) return
  const key = String(record.debtId)
  const idempotencyKey = debtRestoreKeys.value[key] || createCashierV3CommandId()
  debtRestoreKeys.value[key] = idempotencyKey
  debtRestorePending.value = { ...debtRestorePending.value, [key]: true }
  debtRestoreError.value = ''
  try {
    const result = await requestAction('restore-order-center-debt', {
      debtId: record.debtId, cancellationId: record.cancellationId, idempotencyKey
    })
    const status = actionStatus(result)
    if (isTerminalActionStatus(status)) delete debtRestoreKeys.value[key]
    if (['success', 'succeeded'].includes(status)) {
      await queryRecords({ dataScope: 'all', status: '', businessStatus: '', keyword: record.debtNo }, true)
      // 沿用会员欠款快照刷新通知；读回服务端余额及权益，不在页面推算。
      window.dispatchEvent(new CustomEvent('cashier-v3:debt-cancelled', { detail: { memberId: record.memberId } }))
    } else debtRestoreError.value = result?.result?.message || result?.data?.result?.message || '恢复欠款未完成。'
  } catch (error) { debtRestoreError.value = error?.message || '恢复欠款未完成，请稍后重试。' }
  finally { debtRestorePending.value = { ...debtRestorePending.value, [key]: false } }
}

const rechargeLifecycleActions = computed(() => {
  const type = recordType(genericDetailRecord.value)
  // 退款作废只开放给仍生效的 V3 退款；旧退款缺少权益与余额快照，不能推测恢复。
  if (type === 'refund' && genericDetailRecord.value) {
    return String(genericDetailRecord.value.id || '').startsWith('refund:')
      && editableRefundSourceTypes.has(genericDetailRecord.value.refundSourceType)
      && genericDetailRecord.value.refundStatus === '已退款'
      && canUseCashierV3Operation('cashier.v3.order.void') ? ['refund-record-void'] : []
  }
  // 卡操作只开放 V3 项目替换的反向操作；旧版记录缺少可核对的目标权益快照。
  if (type === 'card_operation' && genericDetailRecord.value) {
    return canVoidProjectReplacement(genericDetailRecord.value) ? ['replacement-void'] : []
  }
  if (['supplement', 'gift'].includes(type) && genericDetailRecord.value) {
    const id = giftVoidRecordId(genericDetailRecord.value) || String(genericDetailRecord.value.id || '')
    if (type === 'supplement' && id.startsWith('v3-') && genericDetailRecord.value.paymentStatus !== '已作废' && canUseCashierV3Operation('cashier.v3.order.void')) return ['supplement-void']
    if (type === 'gift' && id.startsWith('v3-direct-gift:') && genericDetailRecord.value.giftStatus !== '已作废' && canUseCashierV3Operation('cashier.v3.order.void')) return ['gift-void']
    return []
  }
  if (type !== 'recharge' || !genericDetailRecord.value
    || genericDetailRecord.value.economicsDataStatus !== 'ready'
    || genericDetailRecord.value.orderStatus !== '正常') return []
  return ['refund', 'void']
})

async function handleRechargeLifecycleAction(payload = {}) {
  const record = genericDetailRecord.value || {}
  const action = String(payload.action || '')
  if (action === 'void-order-center-supplement' || action === 'void-order-center-gift'
    || action === 'void-order-center-project-replacement' || action === 'void-order-center-refund') {
    const recordId = action === 'void-order-center-gift' ? giftVoidRecordId(record) : String(record.id || '')
    if (!recordId) return { result: { status: 'failed', message: '记录标识已变化，请刷新后重试。' } }
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
      if ((row.recordType && row.recordType !== 'sales') || rowId !== normalizedId) {
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
  // 全部订单保留原销售终态补丁；只减少确实在当前页移除的消费行，其他业务不受同号影响。
  if (activeTabKey.value === 'all') {
    const allNormal = (queryModelByType.value.all?.dataScope || 'normal') === 'normal'
    const allRows = currentProjection.recordsByType?.all || []
    const nextAll = patchRows(allRows, allNormal)
    state.orderCenter = { ...state.orderCenter,
      recordsByType: { ...state.orderCenter.recordsByType, all: nextAll },
      pagesByType: { ...state.orderCenter.pagesByType, all: { ...currentProjection.pagesByType?.all,
        total: Math.max(0, Number(currentProjection.pagesByType?.all?.total || 0) - (allRows.length - nextAll.length)) } } }
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
  closeImportedCardRefund()
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.addEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetLocalContext)
  window.addEventListener('cashier-v3:member-selector-selected', handleImportedCardRefundMemberSelected)
  void loadSourceFilterOptions()
})

// Vue Router 在首帧可能尚未把嵌套路由 meta 写入 route；若只在 mounted
// 判断，会漏掉平台页的首个查询，页面于是错误地显示 0 条。等平台路由身份
// 已确认后执行一次当天查询，同时保留普通收银入口原有流程。
watch(isPlatformContext, (platformContext) => {
  if (platformContext) {
    void loadPlatformOrderScope()
    queryRecords(initialOrderCenterQuery(), true)
  }
}, { immediate: true })

watch(activeTabKey, () => {
  // 平台页也必须读取当前页签的服务端字段白名单，否则“更多筛选”
  // 会因缺少可验证的筛选字段而隐藏；查询、导出和操作复用同一能力。
  activeUnifiedQuery.value?.load({ silent: true })
}, { immediate: true })

watch(
  [() => route.fullPath, debtPaymentEnabled],
  () => {
    const requestedTab = String(route.query.tab || route.query.recordType || '').trim()
    if (!ORDER_TABS.some((item) => item.key === requestedTab)) return
    // Closing the switch hides both navigation and stale/bookmarked debt tabs.
    if (!orderRecordTypeVisible(requestedTab, debtPaymentEnabled.value)) {
      activeTabKey.value = 'all'
      void router.replace({ query: { ...route.query, tab: 'all', recordType: undefined } })
      return
    }
    if (!serviceReportDrilldown.value && queryModelByType.value.service?.servicePerformanceDrilldown) {
      queryModelByType.value = { ...queryModelByType.value, service: {} }
    }
    if (!salesReportDrilldown.value && queryModelByType.value.sales?.salesPerformanceDrilldown) {
      queryModelByType.value = { ...queryModelByType.value, sales: {} }
      salesPageCursors.value = {}
    }
    // 顶部分类路由切换沿用原页签的详情清理边界，避免把上一类型的弹窗带入新查询。
    if (requestedTab !== activeTabKey.value) {
      refundAttributionError.value = ''
      genericDetailRecord.value = null
      closeSalesDetail()
      activeTabKey.value = requestedTab
    }
    if (isPlatformContext.value || state.stateContextId) queryRecords(initialOrderCenterQuery(), true)
  },
  { immediate: true }
)

let openedSalaryDetailOrderId = ''
watch(
  [() => route.query.salary_detail_order_id, () => state.stateContextId, () => route.name],
  ([rawOrderId, contextId, routeName]) => {
    if (routeName !== 'cashier-v3-order-center' || !contextId || activeTabKey.value !== 'sales') return
    const orderId = String(rawOrderId || '').trim()
    if (!/^(?:service:)?[A-Za-z0-9_:-]{1,96}$/.test(orderId) || orderId === openedSalaryDetailOrderId) return
    // The route is only a read request; the detail API enforces the current operator's store scope.
    openedSalaryDetailOrderId = orderId
    void openSalesOrderDetail({ orderId })
  },
  { immediate: true }
)

// 登录根分区只提供八类入口，不加载业务记录。当前工作台上下文就绪后，
// 仅为当前页签查询默认当天的正常数据（报表下钻保留指定日期）；切换账号
// 或门店时按相同口径重新读取。
watch(
  () => state.stateContextId,
  (stateContextId) => {
    if (isPlatformContext.value) return
    if (!stateContextId) return
    // 首屏的页面能力请求可能发生在工作台上下文建立之前。上下文就绪后重试当前
    // 页签，避免安全降级状态把“导出”一直隐藏到手工刷新为止。
    activeUnifiedQuery.value?.load({ silent: true })
    void loadSourceFilterOptions()
    queryRecords(initialOrderCenterQuery(), true)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-sales-order-detail', showSalesOrderDetail)
  window.removeEventListener('cashier-v3:state-context-changing', resetLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetLocalContext)
  window.removeEventListener('cashier-v3:member-selector-selected', handleImportedCardRefundMemberSelected)
})
</script>

<template>
  <section class="order-center-page" aria-label="订单中心">
    <p v-if="serviceVoidNotice" class="order-center-notice" role="status">{{ serviceVoidNotice }}</p>
    <p v-if="servicePrintError" class="order-center-page__inline-error" role="alert">{{ servicePrintError }}</p>
    <p v-if="activeTabKey === 'refund' && refundAttributionError && !refundAttributionEditorOpen && !refundAttributionPending" class="order-center-page__inline-error" role="alert">{{ refundAttributionError }}</p>
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

    <!-- 门店与平台订单页复用同一最新版动态筛选；平台仍由后端强制权限且保持只读。 -->
    <OrderCenterReportQueryToolbar
      v-if="!serviceReportDrilldown && !salesReportDrilldown"
      :key="`${state.stateContextId}:${activeTabKey}`"
      :page-code="activeTab.pageCode"
      :page-name="activeTab.label"
      :source-options="sourceFilterOptions"
      :business-source-enabled="businessSourceEnabled"
      :debt-payment-enabled="debtPaymentEnabled"
      :fields="activeQueryFields"
      :executed-query="executedQuery"
      :is-query-loading="isOrderQueryLoading"
      :page-size="pageSize"
      :query-capability="activeQueryCapability"
      :settings="querySettings"
      :result-count="total"
      :current-page-count="records.length"
      :data-as-of="orderCenter.dataAsOf"
      :on-create-export="createActiveExport"
      :on-query-export-task="queryActiveExportTask"
      :on-download-export="downloadActiveExport"
      @query="queryRecords"
    >
      <template #primary-actions>
        <OrganizationStoreScopePicker
          v-if="isPlatformContext"
          v-model="platformScopeStoreIds"
          :tree="platformScopePicker.tree"
          :allowed-store-ids="platformScopePicker.allowedStoreIds"
          :label="platformScopePicker.label"
          :loading="platformScopePicker.loading"
          @change="changePlatformOrderScope"
        />
        <button
          v-if="activeTabKey === 'refund' && canUseCashierV3Operation('cashier.v3.order.refund')"
          type="button"
          class="button button--secondary"
          @click="openImportedCardRefundMemberSelector"
        >退款</button>
      </template>
    </OrderCenterReportQueryToolbar>
    <UnifiedQueryToolbar
      v-else-if="!serviceReportDrilldown && !salesReportDrilldown"
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
      :show-query-loading="activeTabKey === 'all'"
      :state-context-key="state.stateContextId"
      :query-capability="activeQueryCapability"
      :show-settings-button="true"
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
          v-if="isPlatformContext"
          v-model="platformScopeStoreIds"
          :tree="platformScopePicker.tree"
          :allowed-store-ids="platformScopePicker.allowedStoreIds"
          :label="platformScopePicker.label"
          :loading="platformScopePicker.loading"
          @change="changePlatformOrderScope"
        />
      </template>
    </UnifiedQueryToolbar>

    <p v-if="activeTabKey === 'debt' && debtRestoreError" class="order-center-page__inline-error" role="alert">{{ debtRestoreError }}</p>

    <main class="order-center-list-wrap" :class="{ 'order-center-list-wrap--loading': activeTabKey === 'all' && (isOrderQueryLoading || allOrderQueryError) }" :aria-busy="activeTabKey === 'all' && isOrderQueryLoading">
      <p v-if="activeTabKey === 'all' && isOrderQueryLoading" class="order-center-query-pending" role="status">正在查询订单…</p>
      <p v-else-if="activeTabKey === 'all' && allOrderQueryError" class="order-center-query-pending order-center-query-pending--error" role="alert">{{ allOrderQueryError }}</p>
      <table v-if="['all', 'sales'].includes(activeTabKey)" class="order-center-list-table sales-order-query-table" :style="{ '--order-column-count': salesOrderListColumns.length }">
        <thead>
          <tr>
            <th v-for="column in salesOrderListColumns" :key="column">{{ column }}</th>
          </tr>
        </thead>
        <tbody v-for="(record, recordIndex) in records" :key="recordKey(record, recordIndex)" class="sales-order-query-group">
          <tr class="sales-order-query-group__header">
            <td :colspan="salesOrderListColumns.length">
              <!-- 业务标签位于日期前，显示分组身份；实际操作仍由原业务 ID 定位。 -->
              <span class="order-business-tag" :class="`is-${recordType(record)}`">{{ recordTypeLabel(record) }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--date">{{ activeTabKey === 'all' ? '业务日期' : '销售日期' }}：<button v-if="canCorrectAttribution(record, 'business_date')" type="button" class="order-link" @click="openAttributionEditor(record, 'business_date')">{{ displayRecordField(record, 'business_date') }}</button><template v-else>{{ displayRecordField(record, 'business_date') }}</template></span>
              <span class="sales-order-query-meta sales-order-query-meta--time">{{ recordType(record) === 'sales' ? '实际下单时间' : '办理时间' }}：{{ displayRecordField(record, recordType(record) === 'sales' ? 'occurred_at' : 'event_completed_at') }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--number" :title="displayRecordField(record, recordTab(record).primaryField)">订单编号：{{ displayRecordField(record, recordTab(record).primaryField) }}</span>
              <span class="sales-order-query-meta sales-order-query-meta--store" :title="displayRecordField(record, 'store')">门店：{{ displayRecordField(record, 'store') }}</span>
              <!-- 复用服务记录的会员详情入口和权限投影；游客不按姓名猜测关联。 -->
              <span class="sales-order-query-meta sales-order-query-meta--member" :title="displayRecordField(record, 'member_name')">客户：<button
                v-if="canOpenMemberDetail(record)"
                type="button"
                class="order-link"
                :title="`查看${displayRecordField(record, 'member_name')}的会员详情`"
                @click="openMemberDetail(record)"
              >{{ displayRecordField(record, 'member_name') }}</button><template v-else>{{ displayRecordField(record, 'member_name') }}</template></span>
              <span v-if="businessSourceEnabled" class="sales-order-query-meta sales-order-query-meta--source" :title="displayRecordField(record, 'source')">来源：<button v-if="canCorrectAttribution(record, 'source')" type="button" class="order-link" @click="openAttributionEditor(record, 'source')">{{ displayRecordField(record, 'source') }}</button><template v-else>{{ displayRecordField(record, 'source') }}</template></span>
              <span v-if="record.upgradeTypeLabel" class="sales-order-query-group__upgrade-tag">{{ record.upgradeTypeLabel }}</span>
              <!-- 纯权益可以查看/打印及作废服务，但不进入销售退款。 -->
              <span class="sales-order-query-group__actions">
                <button type="button" class="button button--text" @click="openRecordDetail(record)">详情</button>
                <button
                  v-if="recordType(record) === 'sales' && canUseCashierV3Operation('cashier.v3.order.receipt_print')"
                  type="button"
                  class="button button--text"
                  :disabled="Boolean(salesPrintLoading[String(record.id || record.orderId || record.salesOrderId || '')])"
                  @click="printSalesOrderRecord(record)"
                >{{ salesPrintLoading[String(record.id || record.orderId || record.salesOrderId || '')] ? '生成中…' : '打印' }}</button>
                <button
                  v-if="canPrintBusinessRecord(record)"
                  type="button"
                  class="button button--text"
                  :disabled="Boolean(salesPrintLoading[String(record.id || '')])"
                  @click="printBusinessRecord(record)"
                >{{ salesPrintLoading[String(record.id || '')] ? '生成中…' : '打印' }}</button>
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
                <button v-if="recordVoidAction(record)" type="button" class="button button--text button--danger" @click="openRecordDetail(record, recordVoidAction(record))">作废</button>
                <button v-if="recordType(record) === 'recharge' && record.economicsDataStatus === 'ready' && record.orderStatus === '正常' && canUseCashierV3Operation('cashier.v3.order.refund')" type="button" class="button button--text" @click="openRecordDetail(record, 'refund')">退款</button>
              </span>
            </td>
          </tr>
          <tr v-if="recordType(record) !== 'sales'" class="order-business-summary-row">
            <td class="order-center-list-table__summary">
              {{ displayRecordField(record, recordType(record) === 'recharge' ? 'recharge_plan' : 'product_name') }}
              <small v-if="recordType(record) === 'recharge'" class="order-business-summary-note">充值 {{ displayMoneyField(record, 'recharge_amount') }} · 赠送 {{ displayMoneyField(record, 'gift_amount') }}</small>
              <small v-else class="order-business-summary-note">{{ displayRecordField(record, recordType(record) === 'refund' ? 'refund_summary' : 'debt_summary') }}</small>
            </td>
            <td v-if="activeTabKey === 'all'">{{ recordType(record) === 'recharge' ? '—' : (recordFieldValue(record, 'product_category') || '未分类') }}</td>
            <td>—</td><td>—</td><td>—</td>
            <td><button v-if="canEditRecordPersonnel(record)" type="button" class="order-link" title="修改销售人" @click="openRecordPersonnelEditor(record)">{{ displayRecordField(record, 'salesperson') }}</button><span v-else>{{ displayRecordField(record, 'salesperson') }}</span></td>
            <td v-if="salesAttributionEnabled"><button v-if="canEditRecordRoles(record)" type="button" class="order-link" title="修改销售经理" @click="openRecordRoles(record)">{{ displayRecordField(record, 'sales_manager') }}</button><span v-else>{{ displayRecordField(record, 'sales_manager') }}</span></td>
            <td v-if="salesAttributionEnabled"><button v-if="canEditRecordRoles(record)" type="button" class="order-link" title="修改导购" @click="openRecordRoles(record)">{{ displayRecordField(record, 'guide') }}</button><span v-else>{{ displayRecordField(record, 'guide') }}</span></td>
            <td>{{ displayMoneyField(record, `${recordType(record)}_amount`) }}</td>
            <!-- Keep the non-sales placeholders aligned with the same debt switch used by headers and sales lines. -->
            <td>—</td><td v-if="debtPaymentEnabled">—</td><td>—</td>
            <td>{{ recordType(record) === 'recharge' ? displayMoneyField(record, 'actual_received_amount') : '—' }}</td>
            <td>{{ displayRecordField(record, recordType(record) === 'refund' ? 'refund_method' : 'payment_method') }}</td>
            <td>{{ displayRecordField(record, 'store') }}</td>
            <!-- 汇总行与消费明细共用状态样式映射，只统一标签外观，不修改后端状态。 -->
            <td><span class="order-status" :class="statusClass(recordFieldValue(record, recordType(record) === 'refund' ? 'refund_status' : recordType(record) === 'supplement' ? 'payment_status' : 'order_status'))">{{ displayRecordField(record, recordType(record) === 'refund' ? 'refund_status' : recordType(record) === 'supplement' ? 'payment_status' : 'order_status') }}</span></td>
          </tr>
          <template v-else>
          <tr v-for="(item, itemIndex) in salesOrderItems(record)" :key="item.id || item.orderItemId || `${recordKey(record, recordIndex)}-${item.name}-${itemIndex}`">
            <td class="sales-order-query-item-cell">
              <span class="sales-order-query-item-cell__line">
                <!-- 购买/权益和商品类型统一放在名称前，仍使用原字段，不重算商品类型。 -->
                <small v-if="item.businessTag" class="sales-order-query-item-cell__business-tag" :class="`is-${item.businessTag === '权益' ? 'entitlement' : 'purchase'}`">{{ item.businessTag }}</small>
                <!-- 类型展示称为产品，保留接口商品值供预售、出库等业务判断使用。 -->
                <small v-if="item.itemType" class="sales-order-query-item-cell__type">{{ item.itemType === '商品' ? '产品' : item.itemType }}</small>
                <strong class="sales-order-query-item-cell__name">{{ item.name || '未命名商品' }}</strong>
                <!-- 预售只展示正式商品行的结账快照，不能给同名普通购买或权益服务追加标签。 -->
                <small v-if="item.itemType === '商品' && item.isPresale === true" class="sales-order-query-item-cell__service-tag">预售</small>
                <!-- 预售已含不出库语义；普通商品只按正式明细标记展示，不从库存流水反推。 -->
                <small v-else-if="item.itemType === '商品' && item.isNoOutbound === true" class="sales-order-query-item-cell__service-tag">不出库</small>
                <small v-if="item.itemType === '项目' && item.isExperience" class="sales-order-query-item-cell__service-tag">体验</small>
                <small v-if="salesOrderItemCustomerTag(item)" class="sales-order-query-item-cell__service-tag is-customer">{{ salesOrderItemCustomerTag(item) }}</small>
              </span>
            </td>
            <td>{{ item.category || '未分类' }}</td>
            <td>{{ itemMoney(item.unitPrice) }}</td>
            <td>x {{ item.quantity ?? '—' }}</td>
            <td>
              <button v-if="salesItemCraftsmanAdjustable(item) || salesPurchaseCraftsmanAdjustable(record, item)" type="button" class="order-link" @click="openSalesItemCraftsmanEditor(record, item)">{{ salesItemCraftsmenText(item) }}</button>
              <span v-else>{{ salesItemCraftsmenText(item) }}</span>
            </td>
            <td>
              <button v-if="salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'salespeople')">{{ salesItemSalespeopleText(item) }}</button>
              <span v-else>{{ salesItemSalespeopleText(item) }}</span>
            </td>
            <td v-if="salesAttributionEnabled">
              <button v-if="salesOrderActionAvailable(record, 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust')" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'salesManagers')">{{ personnelNames(item, 'salesManagers') }}</button>
              <span v-else>{{ personnelNames(item, 'salesManagers') }}</span>
            </td>
            <td v-if="salesAttributionEnabled">
              <button v-if="salesOrderActionAvailable(record, record.entitlementOnly ? 'correct-service-order-guide' : 'open-sales-order-personnel-adjustment', 'cashier.v3.order.staff_adjust') && !item.voidedAt" type="button" class="order-link" @click="openSalesOrderPersonnelEditor(record, item, 'guides')">{{ salesItemGuidesText(item) }}</button>
              <span v-else>{{ salesItemGuidesText(item) }}</span>
            </td>
            <!-- 购买明细展示用券前金额；权益价值与整单资金继续读取各自的权威投影。 -->
            <td>{{ itemMoney(item.businessTag === '权益' ? item.entitlementAmount : item.amountBeforeCoupon) }}</td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.receivableAmount ?? record.receivable_amount) }}
            </td>
            <td v-if="debtPaymentEnabled && itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.debtAmount ?? record.debt_amount) }}
            </td>
            <td v-if="itemIndex === 0" :rowspan="salesOrderItems(record).length" class="sales-order-query-order-cell">
              {{ itemMoney(record.couponDiscountAmount ?? record.coupon_discount_amount) }}
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
          </template>
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
                v-else-if="fieldItem.key === 'salesperson' && ['recharge', 'supplement', 'refund'].includes(activeTabKey) && (activeTabKey !== 'refund' || editableRefundPersonnelSourceTypes.has(record.refundSourceType)) && canUseCashierV3Operation('cashier.v3.order.staff_adjust')"
                type="button"
                class="order-link"
                title="修改销售人"
                @click="openRecordPersonnelEditor(record)"
              >
                {{ displayRecordField(record, fieldItem.key) }}
              </button>
              <button
                v-else-if="fieldItem.key === 'supplement_attribution' && activeTabKey === 'supplement' && String(record?.id || '').startsWith('v3-') && canUseCashierV3Operation('cashier.v3.order.staff_adjust')"
                type="button"
                class="order-link"
                title="修改补交导购/销售经理"
                @click="openSupplementAttributionEditor(record)"
              >{{ displayRecordField(record, fieldItem.key) }}</button>
              <button
                v-else-if="fieldItem.key === 'refund_attribution' && activeTabKey === 'refund' && editableRefundSourceTypes.has(record.refundSourceType) && canUseCashierV3Operation('cashier.v3.order.staff_adjust')"
                type="button"
                class="order-link"
                title="修改退款导购/销售经理"
                @click="openRefundAttributionEditor(record)"
              >{{ displayRecordField(record, fieldItem.key) }}</button>
              <button
                v-else-if="['business_date', 'source'].includes(fieldItem.key) && canCorrectAttribution(record, fieldItem.key)"
                type="button" class="order-link" @click="openAttributionEditor(record, fieldItem.key)"
              >{{ displayRecordField(record, fieldItem.key) }}</button>
              <button
                v-else-if="fieldItem.key === 'craftsman' && serviceRecordIsNormal(record) && canUseCashierV3Operation('cashier.v3.order.service_detail')"
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
            <td>
              <button
                v-if="activeTabKey !== 'service' || canUseCashierV3Operation('cashier.v3.order.service_detail')"
                type="button"
                class="button button--text"
                @click="openRecordDetail(record)"
              >{{ activeTabKey === 'service' ? '详情' : '查看详情' }}</button>
              <button v-if="canPrintBusinessRecord(record)" type="button" class="button button--text"
                :disabled="Boolean(salesPrintLoading[String(record.id || '')])"
                @click="printBusinessRecord(record)"
              >{{ salesPrintLoading[String(record.id || '')] ? '生成中…' : '打印' }}</button>
              <button v-if="activeTabKey === 'refund' && editableRefundSourceTypes.has(record.refundSourceType) && record.refundStatus === '已退款' && canUseCashierV3Operation('cashier.v3.order.void')"
                type="button" class="button button--text button--danger" @click="openRecordDetail(record, 'refund-record-void')">作废</button>
              <button v-if="canVoidProjectReplacement(record)" type="button"
                class="button button--text button--danger" @click="openProjectReplacementVoid(record)">作废</button>
              <!-- 服务只保留详情入口；打印与作废统一按消费订单处理，避免拆单操作。 -->
              <button
                v-if="activeTabKey === 'debt' && record.canRepay"
                type="button"
                class="button button--text"
                @click="openDebtRepayment(record)"
              >去还款</button>
              <button v-if="activeTabKey === 'debt' && record.canCancel && canUseCashierV3Operation('cashier.v3.order.void')"
                type="button" class="button button--text button--danger" @click="openDebtCancellation(record)">取消欠款</button>
              <button v-if="activeTabKey === 'debt' && record.canRestore && canUseCashierV3Operation('cashier.v3.order.void')"
                type="button" class="button button--text" :disabled="!!debtRestorePending[record.debtId]" @click="restoreDebt(record)">恢复欠款</button>
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
      <div v-if="!records.length && !(activeTabKey === 'all' && (isOrderQueryLoading || allOrderQueryError))" class="order-center-list-empty">{{ activeTab.emptyText }}</div>
    </main>

    <TablePagination
      :total="total"
      :show-page-count="activeTabKey === 'all'"
      :page="page"
      :page-size="pageSize"
      :sequential="activeTabKey === 'sales'"
      :has-more="hasMore"
      @change="(pagination) => queryRecords(pagination, false)"
    />

    <SalesOrderDetailOverlay
      @void-entitlement-order="(record) => { closeSalesDetail(); openServiceVoid(record) }"
      v-if="isSalesDetailOpen"
      :order="salesDetailOrder"
      :is-loading="isSalesDetailLoading"
      :load-error="salesDetailLoadError"
      :attribution-changes="visibleAttributionChanges"
      :business-source-enabled="businessSourceEnabled"
      :debt-payment-enabled="debtPaymentEnabled"
      :action-only="salesDetailActionOnly"
      :initial-lifecycle-action="salesDetailFocus.lifecycleAction"
      :focus-personnel-role="salesDetailFocus.personnelRole"
      :focus-personnel-line-id="salesDetailFocus.personnelLineId"
      :cart-line-count="Array.isArray(state.cashier?.cart?.lines) ? state.cashier.cart.lines.length : 0"
      :on-action="handleSalesOrderDetailAction"
      @close="closeSalaryLinkedSalesDetail"
    />

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="salesPersonnelEditorOpen && salesPersonnelEntry && salesPersonnelTarget"
      :initial-tab="salesPersonnelTarget.uiRole"
      :show-labor-fee="orderLaborFeeVisible"
      :initial-mode="salesPersonnelTarget.entitlementOnly ? 'simple' : 'full'"
      :simple-only="Boolean(salesPersonnelTarget.entitlementOnly)"
      :show-craftsmen="salesPersonnelTarget.role === 'craftsman'"
      :show-salespeople="salesPersonnelTarget.role === 'salesperson'"
      :allow-other-salespeople="salesPersonnelTarget.role === 'salesperson'"
      :other-salesperson-candidates="salesPersonnelEntry.otherSalespersonCandidates || []"
      :grouped-allocation-summary="salesPersonnelTarget.role === 'salesperson'"
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
      :show-pre-sale="preSaleEnabled"
      v-if="recordPersonnelEditorOpen && recordPersonnelEntry && recordPersonnelTarget"
      initial-tab="salespeople"
      initial-mode="full"
      :show-craftsmen="false"
      :show-salespeople="true"
      :allow-other-salespeople="recordPersonnelTarget.type === 'refund'"
      :other-salesperson-candidates="recordPersonnelEntry.otherSalespersonCandidates || []"
      :salesperson-candidates="recordPersonnelCandidates(recordPersonnelEntry)"
      :selected-salespeople="recordPersonnelInitialSelection(recordPersonnelEntry.lines?.[0])"
      :allocation-total-amount-cents="recordPersonnelEntry.totalAmountCents || 0"
      :performance-base-amount-cents="recordPersonnelEntry.performanceBaseAmountCents || 0"
      :refund-whole-yuan-tail="recordPersonnelTarget.type === 'refund'"
      :saving="recordPersonnelSubmitting"
      :load-error="recordPersonnelError"
      :attribution-search-loading="recordPersonnelSearchLoading"
      :attribution-search-error="recordPersonnelSearchError"
      @close="closeRecordPersonnelEditor"
      @search-personnel="searchRecordOtherSalespeople"
      @confirm="prepareRecordPersonnelReason"
    />

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="supplementAttributionEditorOpen && supplementAttributionEntry"
      initial-tab="guides"
      :show-craftsmen="false"
      :show-salespeople="false"
      :show-guides="true"
      :show-sales-managers="true"
      :guide-candidates="supplementAttributionEntry.guides || []"
      :sales-manager-candidates="supplementAttributionEntry.salesManagers || []"
      :selected-guides="supplementAttributionEntry.currentGuides || []"
      :selected-sales-managers="supplementAttributionEntry.currentSalesManagers || []"
      :attribution-search-loading="supplementAttributionSearchLoading"
      :attribution-search-error="supplementAttributionSearchError"
      :saving="supplementAttributionSubmitting"
      :load-error="supplementAttributionError"
      @search-personnel="searchSupplementAttribution"
      @close="closeSupplementAttributionEditor"
      @confirm="prepareSupplementAttributionReason"
    />

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="refundAttributionEditorOpen && refundAttributionEntry"
      initial-tab="guides"
      :show-craftsmen="false"
      :show-salespeople="false"
      :show-guides="true"
      :show-sales-managers="true"
      hide-guide-rounds
      :guide-candidates="refundAttributionEntry.guides || []"
      :sales-manager-candidates="refundAttributionEntry.salesManagers || []"
      :selected-guides="refundAttributionEntry.currentGuides || []"
      :selected-sales-managers="refundAttributionEntry.currentSalesManagers || []"
      :attribution-search-loading="refundAttributionSearchLoading"
      :attribution-search-error="refundAttributionSearchError"
      :saving="refundAttributionSubmitting"
      :load-error="refundAttributionError"
      @search-personnel="searchRefundAttribution"
      @close="closeRefundAttributionEditor"
      @confirm="prepareRefundAttributionReason"
    />

    <BusinessRecordDetailOverlay
      v-if="genericDetailRecord"
      :title="`${recordTab(genericDetailRecord).label}详情`"
      :record="genericDetailRecord"
      :fields="detailFields"
      :resolve-value="recordFieldValue"
      :lifecycle-actions="rechargeLifecycleActions"
      :initial-lifecycle-action="initialDetailLifecycleAction"
      :on-lifecycle-action="handleRechargeLifecycleAction"
      :field-action-keys="serviceRecordIsNormal(genericDetailRecord) ? ['craftsman'] : []"
      :attribution-changes="visibleAttributionChanges"
      :show-debt-changes="activeTabKey === 'debt'"
      :debt-changes="debtChanges"
      :debt-changes-loading="debtChangesLoading"
      :debt-changes-error="debtChangesError"
      @field-action="handleDetailFieldAction"
      @close="closeGenericDetail"
    />

    <div v-if="importedCardRefundOpen" class="service-void-modal" role="dialog" aria-modal="true" aria-label="退款">
      <div class="service-void-modal__backdrop" @click="closeImportedCardRefund"></div>
      <form class="service-void-modal__panel imported-card-refund-dialog" @submit.prevent="submitImportedCardRefund">
        <header class="service-void-modal__head">
          <div><h2>退款</h2><p>{{ importedCardRefundMember?.name || importedCardRefundMember?.realName || '已选会员' }}</p></div>
        </header>
        <div class="unified-refund-dialog__body">
        <div class="unified-refund-dialog__mode" role="group" aria-label="退款类型">
          <button type="button" class="button" :class="unifiedRefundMode === 'card' ? 'button--primary' : 'button--secondary'" @click="changeUnifiedRefundMode('card')">卡项退款</button>
          <button type="button" class="button" :class="unifiedRefundMode === 'document' ? 'button--primary' : 'button--secondary'" @click="changeUnifiedRefundMode('document')">单据退款</button>
          <button type="button" class="button" :class="unifiedRefundMode === 'balance' ? 'button--primary' : 'button--secondary'" @click="changeUnifiedRefundMode('balance')">余额退款</button>
        </div>
        <p v-if="importedCardRefundError" class="service-void-modal__error" role="alert">{{ importedCardRefundError }}</p>
        <div v-if="unifiedRefundMode !== 'balance'" class="unified-refund-dialog__search">
          <input v-model.trim="unifiedRefundKeyword" type="search" class="service-void-modal__textarea" :aria-label="unifiedRefundMode === 'card' ? '搜索卡项' : '搜索单据号'" :placeholder="unifiedRefundMode === 'card' ? '搜索卡号、卡名或来源单号' : '搜索单据号'" :readonly="importedCardRefundUncertain" @keydown.enter.prevent="loadImportedCardRefundCards(importedCardRefundMember)" />
          <button type="button" class="button button--secondary" :disabled="importedCardRefundLoading || importedCardRefundUncertain" @click="loadImportedCardRefundCards(importedCardRefundMember)">查询</button>
          <button v-if="unifiedRefundMode === 'document'" type="button" class="button button--secondary" :disabled="importedCardRefundUncertain" @click="openUnifiedRefundCatalog">选择商品</button>
        </div>
        <div v-if="importedCardRefundLoading" class="imported-card-refund-dialog__empty">正在读取可退款对象…</div>
        <template v-else-if="unifiedRefundMode === 'card'">
          <div class="unified-refund-dialog__card-workspace">
            <section class="unified-refund-dialog__card-column" aria-label="卡项">
              <div class="unified-refund-dialog__column-head"><h3>卡项</h3><span>共 {{ importedCardRefundTotal }} 张</span></div>
              <div v-if="!importedCardRefundCards.length" class="imported-card-refund-dialog__empty">该会员没有可退款卡项。</div>
              <div v-else class="imported-card-refund-dialog__cards">
                <label v-for="card in importedCardRefundCards" :key="card.holderId" class="imported-card-refund-dialog__card" :class="{ 'imported-card-refund-dialog__card--disabled': !card.canRefund }">
                  <input v-model="importedCardRefundHolderId" type="radio" name="unified-card-refund" :value="String(card.holderId)" :disabled="!card.canRefund || importedCardRefundUncertain" />
                  <span class="imported-card-refund-dialog__card-main">
                    <strong>{{ card.cardName }}</strong>
                    <small>卡号：{{ card.cardNo || '—' }}</small>
                    <span class="imported-card-refund-dialog__card-metrics">
                      <span>成交金额 <strong>{{ importedCardRefundMoney(card.purchaseAmountCents) }}</strong></span>
                      <span>实际可用次数 <strong>{{ card.remainingTimes }}</strong></span>
                      <span>实际剩余金额 <strong>{{ importedCardRefundMoney(card.usableRemainingAmountCents) }}</strong></span>
                      <span>历史退款 <strong>{{ importedCardRefundMoney(card.historicalRefundCents) }}</strong></span>
                    </span>
                    <small v-if="card.unavailableReason" class="service-void-modal__error">{{ card.unavailableReason }}</small>
                  </span>
                </label>
              </div>
            </section>
            <section class="unified-refund-dialog__component-panel" aria-label="选择卡内项目">
              <div class="unified-refund-dialog__column-head"><h3>{{ selectedUnifiedRefundCard?.ruleType === 'choice_count' ? '整卡共享次数' : '卡内项目' }}</h3><span v-if="selectedUnifiedRefundCard">共 {{ selectedUnifiedRefundCard.components.length }} 项</span></div>
              <template v-if="selectedUnifiedRefundCard">
                <div class="unified-refund-dialog__component-list" role="radiogroup" aria-label="卡内项目">
                  <label v-for="component in selectedUnifiedRefundCard.components" :key="component.id" class="unified-refund-dialog__component" :class="{ 'unified-refund-dialog__component--disabled': component.usableRemainingAmountCents <= 0 && !(selectedUnifiedRefundCard.purchaseAmountCents === 0 && component.remainingTimes > 0) }">
                    <input v-model="unifiedRefundComponentId" type="radio" name="unified-refund-component" :value="String(component.id)" :disabled="(component.usableRemainingAmountCents <= 0 && !(selectedUnifiedRefundCard.purchaseAmountCents === 0 && component.remainingTimes > 0)) || importedCardRefundUncertain" />
                    <span class="unified-refund-dialog__component-name">{{ component.name }}</span>
                    <span class="unified-refund-dialog__component-remaining"><strong>{{ importedCardRefundMoney(component.usableRemainingAmountCents) }}</strong><small>剩余 {{ component.remainingTimes }} 次</small></span>
                  </label>
                </div>
                <div v-if="selectedUnifiedRefundCard.isTimeCard" class="unified-refund-dialog__field">
                  <label class="service-void-modal__label" for="unified-refund-valid-through">退款后有效期</label>
                  <input id="unified-refund-valid-through" v-model="unifiedRefundValidThrough" type="date" class="service-void-modal__textarea" required :readonly="importedCardRefundUncertain" />
                </div>
              </template>
              <div v-else class="unified-refund-dialog__component-empty">未选择卡项</div>
            </section>
          </div>
        </template>
        <div v-else-if="unifiedRefundMode === 'balance'" class="unified-refund-dialog__balance" aria-label="会员剩余余额">
          <span>剩余本金 <strong>{{ importedCardRefundMoney(unifiedRefundWallet?.principalCents || 0) }}</strong></span>
          <span>剩余赠金 <strong>{{ importedCardRefundMoney(unifiedRefundWallet?.giftCents || 0) }}</strong></span>
        </div>
        <template v-else>
          <div v-if="unifiedRefundCatalogItem" class="unified-refund-dialog__selected-catalog">
            <strong>{{ unifiedRefundCatalogItem.name }}</strong>
            <span>{{ unifiedRefundCatalogItem.kind }} · 无原单退款</span>
            <button type="button" class="button button--text" :disabled="importedCardRefundUncertain" @click="unifiedRefundCatalogItem = null">移除</button>
          </div>
          <div v-else-if="!unifiedRefundLines.length" class="imported-card-refund-dialog__empty">没有可退款的购买项目或产品明细。</div>
          <div v-else class="imported-card-refund-dialog__cards">
            <label v-for="line in unifiedRefundLines" :key="line.lineId" class="imported-card-refund-dialog__card" :class="{ 'imported-card-refund-dialog__card--disabled': !line.canRefund }">
              <input v-model="unifiedRefundLineId" type="radio" name="unified-document-line" :value="line.lineId" :disabled="!line.canRefund || importedCardRefundUncertain" @change="unifiedRefundCatalogItem = null" />
              <span class="imported-card-refund-dialog__card-main"><strong>{{ line.name }}</strong><small>单据号：{{ line.sourceOrderNo }}　{{ line.itemType === 'project' ? '购买项目' : '产品' }}　实际剩余金额：{{ importedCardRefundMoney(line.remainingAmountCents) }}</small></span>
              <small v-if="line.unavailableReason && !line.canRefund" class="service-void-modal__error">{{ line.unavailableReason }}</small>
            </label>
          </div>
        </template>
        <div v-if="unifiedRefundMode === 'card' && importedCardRefundTotal > 50" class="service-void-modal__actions" aria-label="卡项分页">
          <button type="button" class="button button--secondary" :disabled="importedCardRefundLoading || importedCardRefundUncertain || importedCardRefundPage <= 1" @click="loadImportedCardRefundCards(importedCardRefundMember, importedCardRefundPage - 1)">上一页</button>
          <span>第 {{ importedCardRefundPage }} / {{ Math.ceil(importedCardRefundTotal / 50) }} 页</span>
          <button type="button" class="button button--secondary" :disabled="importedCardRefundLoading || importedCardRefundUncertain || importedCardRefundPage * 50 >= importedCardRefundTotal" @click="loadImportedCardRefundCards(importedCardRefundMember, importedCardRefundPage + 1)">下一页</button>
        </div>
        <div class="unified-refund-dialog__field-row">
          <div class="unified-refund-dialog__field">
            <label class="service-void-modal__label" for="imported-card-refund-amount">本次退款金额</label>
            <input id="imported-card-refund-amount" :value="importedCardRefundAmount" class="service-void-modal__textarea" inputmode="numeric" :pattern="effectiveRefundMode === 'card' ? '(?:0|[1-9][0-9]*)' : '[1-9][0-9]*'" placeholder="整数元" :readonly="importedCardRefundUncertain" required @beforeinput="blockNonDigitRefundInput" @input="updateWholeYuanRefundInput($event, 'refund')" />
          </div>
          <div v-if="effectiveRefundMode !== 'catalog'" class="unified-refund-dialog__field">
            <label class="service-void-modal__label" for="unified-refund-balance">{{ effectiveRefundMode === 'balance' ? '扣减余额' : '转入余额' }}</label>
            <input id="unified-refund-balance" :value="unifiedRefundBalanceAmount" class="service-void-modal__textarea" inputmode="numeric" pattern="(?:0|[1-9][0-9]*)" placeholder="整数元，可填 0" :readonly="importedCardRefundUncertain" @beforeinput="blockNonDigitRefundInput" @input="updateWholeYuanRefundInput($event, 'balance')" />
          </div>
        </div>
        <div class="unified-refund-dialog__field-row">
          <div class="unified-refund-dialog__field">
            <label class="service-void-modal__label" for="unified-refund-business-date">退款日期</label>
            <input id="unified-refund-business-date" v-model="unifiedRefundBusinessDate" type="date" class="service-void-modal__textarea" :max="refundBusinessToday()" :readonly="importedCardRefundUncertain" required />
          </div>
          <div class="unified-refund-dialog__field">
            <label class="service-void-modal__label">退款销售人</label>
            <button type="button" class="button button--secondary service-void-modal__textarea unified-refund-dialog__personnel-button" :title="unifiedRefundSalespeople.map((person) => person.name).join('、') || '选择销售人'" :disabled="importedCardRefundUncertain || unifiedRefundPersonnelLoading || !unifiedRefundPersonnel" @click="openUnifiedRefundPersonnelEditor">
              {{ unifiedRefundPersonnelLoading ? '读取中…' : unifiedRefundSalespeople.length ? unifiedRefundSalespeople.map((person) => person.name).join('、') : '选择销售人' }}
            </button>
          </div>
        </div>
        <div class="unified-refund-dialog__field-row">
          <div class="unified-refund-dialog__choices" role="group" aria-label="作废选择">
            <template v-if="!['catalog', 'balance'].includes(effectiveRefundMode)">
              <label><input v-model="unifiedRefundVoid" type="radio" :value="false" /> 不作废</label>
              <label><input v-model="unifiedRefundVoid" type="radio" :value="true" /> {{ unifiedRefundMode === 'card' ? '作废本卡' : '作废明细' }}</label>
            </template>
          </div>
          <div class="unified-refund-dialog__field">
            <label class="service-void-modal__label">导购/销售经理</label>
            <button type="button" class="button button--secondary service-void-modal__textarea unified-refund-dialog__personnel-button" :title="unifiedRefundAttributionLabel || '选择导购/销售经理'" :disabled="importedCardRefundUncertain || unifiedRefundPersonnelLoading || !unifiedRefundPersonnel" @click="openUnifiedRefundAttributionEditor">
              {{ unifiedRefundPersonnelLoading ? '读取中…' : unifiedRefundAttributionLabel || '选择导购/销售经理' }}
            </button>
          </div>
        </div>
        <div class="unified-refund-dialog__field">
          <label class="service-void-modal__label" for="imported-card-refund-reason">退款原因</label>
          <textarea id="imported-card-refund-reason" v-model.trim="importedCardRefundReason" class="service-void-modal__textarea" maxlength="255" rows="3" placeholder="填写退款原因" :readonly="importedCardRefundUncertain" required></textarea>
        </div>
        </div>
        <footer class="service-void-modal__actions">
          <button type="button" class="button button--secondary" :disabled="importedCardRefundUncertain" @click="closeImportedCardRefund">取消</button>
          <button type="submit" class="button button--primary" :disabled="importedCardRefundSubmitting || importedCardRefundLoading || unifiedRefundPersonnelLoading || (unifiedRefundMode === 'card' ? !importedCardRefundCards.length : unifiedRefundMode === 'balance' ? !unifiedRefundWallet : !unifiedRefundLines.length && !unifiedRefundCatalogItem)">{{ importedCardRefundSubmitting ? '提交中…' : importedCardRefundUncertain ? '重试确认' : '确认退款' }}</button>
        </footer>
      </form>
    </div>

    <div v-if="unifiedRefundCatalogOpen" class="service-void-modal unified-refund-catalog-modal" role="dialog" aria-modal="true" aria-label="选择商品">
      <div class="service-void-modal__backdrop" @click="!unifiedRefundCatalogLoading && (unifiedRefundCatalogOpen = false)"></div>
      <section class="service-void-modal__panel unified-refund-catalog-modal__panel">
        <header class="service-void-modal__head"><h2>选择商品</h2><button type="button" class="button button--secondary" @click="unifiedRefundCatalogOpen = false">关闭</button></header>
        <div class="unified-refund-catalog-modal__filters">
          <input v-model="unifiedRefundCatalogKeyword" type="search" class="service-void-modal__textarea" placeholder="搜索名称、规格或编码" aria-label="搜索商品" />
          <select v-model="unifiedRefundCatalogKind" class="service-void-modal__textarea" aria-label="商品类型"><option v-for="kind in ['全部', '项目', '产品', '卡项']" :key="kind">{{ kind }}</option></select>
        </div>
        <div class="unified-refund-catalog-modal__list">
          <p v-if="unifiedRefundCatalogLoading">正在读取商品…</p>
          <p v-else-if="!visibleRefundCatalogItems.length">没有匹配的商品。</p>
          <button v-for="item in visibleRefundCatalogItems" :key="item.skuId" type="button" class="unified-refund-catalog-modal__item" @click="selectUnifiedRefundCatalogItem(item)"><strong>{{ item.name }}</strong><span>{{ item.kind }}{{ item.specification ? ` · ${item.specification}` : '' }}</span></button>
        </div>
      </section>
    </div>

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="unifiedRefundPersonnelEditorOpen && unifiedRefundPersonnel"
      initial-tab="salespeople"
      initial-mode="full"
      :show-craftsmen="false"
      :show-salespeople="true"
      allow-other-salespeople
      :other-salesperson-candidates="unifiedRefundPersonnel.otherSalespersonCandidates || []"
      :salesperson-candidates="unifiedRefundPersonnel.salespeople || []"
      :selected-salespeople="unifiedRefundSalespeople"
      :performance-base-amount-cents="Number(importedCardRefundAmount || 0) * 100"
      refund-whole-yuan-tail
      :allocation-total-amount-cents="Number(importedCardRefundAmount || 0) * 100"
      :attribution-search-loading="unifiedRefundSalespersonSearchLoading"
      :attribution-search-error="unifiedRefundSalespersonSearchError"
      @search-personnel="searchUnifiedRefundOtherSalespeople"
      @close="closeUnifiedRefundPersonnelEditor"
      @confirm="setUnifiedRefundSalespeople"
    />

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="unifiedRefundAttributionEditorOpen && unifiedRefundPersonnel"
      initial-tab="guides"
      :show-craftsmen="false"
      :show-salespeople="false"
      :show-guides="true"
      :show-sales-managers="true"
      hide-guide-rounds
      :guide-candidates="unifiedRefundPersonnel.guides || []"
      :sales-manager-candidates="unifiedRefundPersonnel.salesManagers || []"
      :selected-guides="unifiedRefundGuides"
      :selected-sales-managers="unifiedRefundSalesManagers"
      :attribution-search-loading="unifiedRefundAttributionSearchLoading"
      :attribution-search-error="unifiedRefundAttributionSearchError"
      @search-personnel="searchUnifiedRefundAttribution"
      @close="closeUnifiedRefundAttributionEditor"
      @confirm="setUnifiedRefundAttribution"
    />

    <div v-if="attributionEditor && (attributionEditor.field !== 'source' || businessSourceEnabled)" class="service-void-modal" role="dialog" aria-modal="true" aria-label="更正订单归属">
      <div class="service-void-modal__backdrop"></div>
      <form class="service-void-modal__panel" @submit.prevent="submitAttributionCorrection">
        <header class="service-void-modal__head"><h2>更正{{ attributionEditor.field === 'source' ? '来源' : '业务日期' }}</h2></header>
        <p class="service-void-modal__record">{{ attributionEditor.recordNo }} · 当前{{ attributionEditor.field === 'source' ? '来源' : '日期' }}：{{ attributionEditor.expectedValue || '—' }}</p>
        <template v-if="attributionEditor.field === 'source'">
          <label class="service-void-modal__label">一级来源</label>
          <select v-model.number="attributionEditor.primarySourceId" class="service-void-modal__textarea" @change="attributionEditor.secondarySourceId = 0">
            <option :value="0">请选择</option>
            <option v-for="source in attributionSources" :key="source.id" :value="source.id">{{ source.name }}</option>
          </select>
          <template v-if="selectedAttributionPrimary?.children?.length">
            <label class="service-void-modal__label">二级来源</label>
            <select v-model.number="attributionEditor.secondarySourceId" class="service-void-modal__textarea">
              <option :value="0">请选择</option>
              <option v-for="source in selectedAttributionPrimary.children" :key="source.id" :value="source.id">{{ source.name }}</option>
            </select>
          </template>
        </template>
        <template v-else>
          <label class="service-void-modal__label">更正后日期</label>
          <input v-model="attributionEditor.newValue" type="date" class="service-void-modal__textarea" required />
        </template>
        <p v-if="attributionError" class="service-void-modal__error" role="alert">{{ attributionError }}</p>
        <footer class="service-void-modal__actions"><button type="button" class="button button--secondary" :disabled="attributionPending" @click="attributionEditor = null">取消</button>
          <button type="submit" class="button button--primary" :disabled="attributionPending">{{ attributionPending ? '保存中…' : '确认更正' }}</button></footer>
      </form>
    </div>

    <div v-if="debtCancelRecord" class="service-void-modal" role="dialog" aria-modal="true" aria-label="取消欠款">
      <div class="service-void-modal__backdrop"></div>
      <form class="service-void-modal__panel" @submit.prevent="submitDebtCancellation">
        <header class="service-void-modal__head"><h2>取消欠款</h2></header>
        <p class="service-void-modal__record">确认取消剩余欠款 {{ itemMoney(debtCancelRecord.remainingAmount) }}？取消后不再需要补交。</p>
        <label class="service-void-modal__label" for="debt-cancel-reason">取消原因</label>
        <textarea id="debt-cancel-reason" v-model="debtCancelReason" class="service-void-modal__textarea" maxlength="255" rows="3" :disabled="debtCancelPending" />
        <p v-if="debtCancelError" class="service-void-modal__error" role="alert">{{ debtCancelError }}</p>
        <footer class="service-void-modal__actions"><button type="button" class="button button--secondary" :disabled="debtCancelPending" @click="debtCancelRecord = null">取消</button>
          <button type="submit" class="button button--primary" :disabled="debtCancelPending">确认取消欠款</button></footer>
      </form>
    </div>

    <p v-if="serviceCraftsmanError && !serviceCraftsmanEditorOpen && !serviceCraftsmanPendingAssignment" class="order-center-page__inline-error" role="alert">
      {{ serviceCraftsmanError }}
    </p>

    <PersonnelPerformanceOverlay
      :show-pre-sale="preSaleEnabled"
      v-if="serviceCraftsmanEditorOpen && serviceCraftsmanEntry"
      :show-labor-fee="orderLaborFeeVisible"
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
      :project-count-total="serviceCraftsmanEntry.writeoffQuantity || 0"
      history-adjustment
      :loading="serviceCraftsmanLoading"
      :saving="serviceCraftsmanSubmitting"
      :load-error="serviceCraftsmanError"
      @close="closeServiceCraftsmanAdjustment"
      @search-personnel="searchServiceCraftsmen"
      @confirm="prepareServiceCraftsmanReason"
    />



    <div v-if="serviceVoidRecord" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="service-void-title">
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

    <div v-if="serviceCraftsmanPendingAssignment" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="service-craftsman-reason-title">
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

    <div v-if="salesPersonnelPendingAssignment && salesPersonnelTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="sales-personnel-reason-title">
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

    <div v-if="recordPersonnelPendingAssignment && recordPersonnelTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="record-personnel-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelRecordPersonnelReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="record-personnel-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="recordPersonnelSubmitting" @click="cancelRecordPersonnelReason">×</button>
        </header>
        <p class="service-void-modal__record">{{ recordPersonnelTarget.type === 'recharge' ? '充值订单' : recordPersonnelTarget.type === 'refund' ? '退款记录' : '补交记录' }} · {{ recordPersonnelTarget.recordNo || recordPersonnelTarget.recordId }}</p>
        <label class="service-void-modal__label" for="record-personnel-reason">修改原因</label>
        <textarea id="record-personnel-reason" v-model="recordPersonnelReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="recordPersonnelError" class="service-void-modal__error" role="alert">{{ recordPersonnelError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="recordPersonnelSubmitting" @click="cancelRecordPersonnelReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="recordPersonnelSubmitting" @click="submitRecordPersonnelAdjustment">{{ recordPersonnelSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="supplementAttributionPending && supplementAttributionTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="supplement-attribution-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelSupplementAttributionReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="supplement-attribution-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="supplementAttributionSubmitting" @click="cancelSupplementAttributionReason">×</button>
        </header>
        <p class="service-void-modal__record">补交记录 · {{ supplementAttributionTarget.recordNo || supplementAttributionTarget.recordId }}</p>
        <label class="service-void-modal__label" for="supplement-attribution-reason">修改原因</label>
        <textarea id="supplement-attribution-reason" v-model="supplementAttributionReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="supplementAttributionError" class="service-void-modal__error" role="alert">{{ supplementAttributionError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="supplementAttributionSubmitting" @click="cancelSupplementAttributionReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="supplementAttributionSubmitting" @click="submitSupplementAttributionAdjustment">{{ supplementAttributionSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="refundAttributionPending && refundAttributionTarget" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="refund-attribution-reason-title">
      <div class="service-void-modal__backdrop" @click="cancelRefundAttributionReason"></div>
      <section class="service-void-modal__panel">
        <header class="service-void-modal__head">
          <h2 id="refund-attribution-reason-title">填写修改原因</h2>
          <button type="button" class="service-void-modal__close" :disabled="refundAttributionSubmitting" @click="cancelRefundAttributionReason">×</button>
        </header>
        <p class="service-void-modal__record">退款记录 · {{ refundAttributionTarget.recordNo || refundAttributionTarget.recordId }}</p>
        <label class="service-void-modal__label" for="refund-attribution-reason">修改原因</label>
        <textarea id="refund-attribution-reason" v-model="refundAttributionReason" class="service-void-modal__textarea" maxlength="255" rows="4" placeholder="请输入修改原因"></textarea>
        <p v-if="refundAttributionError" class="service-void-modal__error" role="alert">{{ refundAttributionError }}</p>
        <footer class="service-void-modal__actions">
          <button type="button" class="button" :disabled="refundAttributionSubmitting" @click="cancelRefundAttributionReason">返回修改</button>
          <button type="button" class="button button--primary" :disabled="refundAttributionSubmitting" @click="submitRefundAttributionAdjustment">{{ refundAttributionSubmitting ? '保存中…' : '确认保存' }}</button>
        </footer>
      </section>
    </div>

    <div v-if="salesOrderNoteRecord" class="service-void-modal" role="dialog" aria-modal="true" aria-labelledby="sales-order-note-title">
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
/* 查询未完成时遮住旧结果及其操作，避免把旧列表误当成新条件结果。 */
.order-center-list-wrap--loading { position: relative; }
.order-center-list-wrap--loading > table { visibility: hidden; }
.order-center-query-pending { position: sticky; top: 0; left: 0; margin: 0; padding: 32px 16px; text-align: center; color: #465b75; }
.order-center-query-pending--error { color: #af3240; }
/* 类型标签使用固定尺寸和高对比配色，区别于明细标签，不挤动主项日期。 */
.sales-order-query-group__header td > .order-business-tag { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; width: 44px; height: 24px; vertical-align: middle; margin-right: 16px; padding: 0 6px; border-radius: 4px; background: var(--order-type-color, #185bb5); color: #fff; font-size: 12px; line-height: 1; font-weight: 700; white-space: nowrap; }
.order-business-tag.is-recharge { --order-type-color: #187451; }
.order-business-tag.is-supplement { --order-type-color: #885a0a; }
.order-business-tag.is-refund { --order-type-color: #af3240; }
.order-business-summary-note { display: block; max-width: 300px; margin-top: 4px; font-size: 11px; color: #62738a; white-space: normal; overflow-wrap: anywhere; }
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
.sales-order-query-table td:first-child small.sales-order-query-item-cell__service-tag { display: inline-block; flex-shrink: 0; margin-top: 0; border: 1px solid #b7d4fe; border-radius: 4px; padding: 1px 5px; color: #175cd3; background: #eff6ff; font-size: 11px; line-height: 1.2; }
.sales-order-query-table td:first-child small.sales-order-query-item-cell__service-tag.is-customer { border-color: #abefc6; color: #067647; background: #ecfdf3; }
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
  justify-content: flex-end;
  min-width: 0;
  border-bottom: 1px solid #dfe5ec;
  background: #fff;
}




/* R37 订单中心视觉皮肤：仅作用本页的颜色与装饰，不改变字段、尺寸布局、滚动定位或业务事件。 */
.order-center-page { background: #f5f7fa; }
.order-center-page__head { border: 1px solid #e3e9f2; border-radius: 10px; }
.order-center-page :deep(.unified-query-toolbar) { background: #fff; border-color: #e3e9f2; box-shadow: 0 1px 3px #21355205; }
.order-center-page :deep(.unified-query-toolbar .button),
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
.imported-card-refund-dialog { display: flex; flex-direction: column; box-sizing: border-box; width: min(1120px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); padding: 0; overflow: hidden; }
.imported-card-refund-dialog > .service-void-modal__head { flex: none; padding: 20px 24px 16px; border-bottom: 1px solid #e7ecf2; }
.imported-card-refund-dialog > .service-void-modal__head p { margin: 6px 0 0; color: #53657c; }
.unified-refund-dialog__body { display: flex; flex-direction: column; gap: 16px; min-height: 0; padding: 18px 24px 20px; overflow-y: auto; }
.unified-refund-dialog__body > .service-void-modal__error { margin: 0; }
.unified-refund-dialog__mode { display: flex; flex-wrap: wrap; gap: 8px; }
.unified-refund-dialog__balance { display: flex; flex-wrap: wrap; gap: 12px 24px; padding: 12px 0; border-bottom: 1px solid #e7ecf2; color: #53657c; }
.unified-refund-dialog__balance strong { margin-left: 6px; color: #1d2939; }
.unified-refund-dialog__search { display: flex; align-items: center; gap: 8px; }
.unified-refund-dialog__search .service-void-modal__textarea { flex: 1; min-width: 0; width: auto; }
.unified-refund-dialog__search .button { flex: none; min-width: 72px; margin-left: auto; }
.unified-refund-dialog__search .button + .button { margin-left: 0; }
.unified-refund-dialog__selected-catalog { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid #b8d1f3; border-radius: 6px; background: #f3f8ff; }
.unified-refund-dialog__selected-catalog span { color: #667085; }
.unified-refund-dialog__selected-catalog .button { margin-left: auto; }
.unified-refund-catalog-modal { z-index: 90; }
.unified-refund-catalog-modal__panel { width: min(680px, calc(100vw - 32px)); }
.unified-refund-catalog-modal__filters { display: flex; gap: 8px; margin: 16px 0; }
.unified-refund-catalog-modal__filters input { flex: 1; min-width: 0; }
.unified-refund-catalog-modal__filters select { width: 112px; }
.unified-refund-catalog-modal__list { display: grid; gap: 6px; max-height: min(55vh, 480px); overflow-y: auto; }
.unified-refund-catalog-modal__item { display: flex; justify-content: space-between; align-items: center; gap: 10px; width: 100%; padding: 10px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; color: #1d2939; text-align: left; cursor: pointer; }
.unified-refund-catalog-modal__item:hover { border-color: #2d74c4; background: #f3f8ff; }
.unified-refund-catalog-modal__item span { color: #667085; }
.unified-refund-dialog__card-workspace { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; flex: none; height: clamp(240px, 38dvh, 380px); min-height: 0; }
.unified-refund-dialog__card-column { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.unified-refund-dialog__column-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex: none; margin-bottom: 10px; }
.unified-refund-dialog__column-head h3 { margin: 0; color: #2c3c51; font-size: 14px; }
.unified-refund-dialog__column-head > span { color: #66758b; font-size: 12px; white-space: nowrap; }
.imported-card-refund-dialog__cards { display: grid; gap: 8px; max-height: 240px; padding-right: 4px; overflow: auto; }
.unified-refund-dialog__card-column > .imported-card-refund-dialog__cards { flex: 1; min-height: 0; max-height: none; align-content: start; }
.imported-card-refund-dialog__card { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid #dce4ef; border-radius: 6px; cursor: pointer; }
.imported-card-refund-dialog__card:has(input:checked) { border-color: #4568f5; background: #f3f6ff; }
.imported-card-refund-dialog__card--disabled { cursor: not-allowed; opacity: .7; }
.imported-card-refund-dialog__card-main { display: grid; gap: 4px; min-width: 0; }
.imported-card-refund-dialog__card-main small { color: #667085; }
.imported-card-refund-dialog__card-metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 16px; color: #667085; font-size: 12px; }
.imported-card-refund-dialog__card-metrics > span { min-width: 0; }
.imported-card-refund-dialog__card-metrics strong { color: #344054; font-weight: 600; white-space: nowrap; }
.imported-card-refund-dialog__empty { padding: 28px 12px; text-align: center; color: #667085; }
.unified-refund-dialog__component-panel { display: flex; flex-direction: column; min-width: 0; min-height: 0; padding: 14px 16px; border: 1px solid #dbe3ed; border-radius: 6px; background: #f8fafc; }
.unified-refund-dialog__component-list { flex: 1; min-height: 0; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; }
.unified-refund-dialog__component-empty { display: grid; flex: 1; place-items: center; color: #8a98a9; }
.unified-refund-dialog__component { display: grid; grid-template-columns: 18px minmax(0, 1fr) auto; align-items: center; gap: 12px; min-height: 48px; padding: 9px 12px; cursor: pointer; }
.unified-refund-dialog__component + .unified-refund-dialog__component { border-top: 1px solid #edf1f5; }
.unified-refund-dialog__component:has(input:checked) { background: #eaf2ff; }
.unified-refund-dialog__component--disabled { opacity: .55; cursor: not-allowed; }
.unified-refund-dialog__component input { margin: 0; }
.unified-refund-dialog__component-name { min-width: 0; color: #26364b; font-weight: 600; overflow-wrap: anywhere; }
.unified-refund-dialog__component-remaining { display: flex; align-items: baseline; gap: 12px; white-space: nowrap; text-align: right; }
.unified-refund-dialog__component-remaining strong { color: #1f5e99; }
.unified-refund-dialog__component-remaining small { color: #66758b; }
.unified-refund-dialog__field .service-void-modal__label { margin-bottom: 6px; }
.unified-refund-dialog__field-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.unified-refund-dialog__field-row > .unified-refund-dialog__field { min-width: 0; }
.unified-refund-dialog__personnel-button { height: 39px; overflow: hidden; text-align: left; text-overflow: ellipsis; white-space: nowrap; }
.unified-refund-dialog__component-panel .unified-refund-dialog__field { margin-top: 14px; }
.unified-refund-dialog__choices { display: flex; flex-wrap: wrap; align-items: center; align-self: end; min-height: 39px; gap: 8px 20px; }
.unified-refund-dialog__choices label { display: inline-flex; align-items: center; gap: 6px; }
.unified-refund-dialog__choices input { margin: 0; }
.imported-card-refund-dialog > .service-void-modal__actions { flex: none; margin: 0; padding: 14px 24px; border-top: 1px solid #e7ecf2; background: #fff; }
@media (max-width: 900px) {
  .unified-refund-dialog__card-workspace { display: flex; flex-direction: column; height: auto; }
  .unified-refund-dialog__card-column > .imported-card-refund-dialog__cards { flex: none; max-height: 220px; }
  .unified-refund-dialog__component-panel { min-height: 130px; }
  .unified-refund-dialog__component-list { flex: none; max-height: 200px; }
}
@media (max-width: 560px) {
  .imported-card-refund-dialog > .service-void-modal__head { padding: 16px 18px 12px; }
  .unified-refund-dialog__body { gap: 14px; padding: 16px 18px; }
  .unified-refund-dialog__field-row { grid-template-columns: minmax(0, 1fr); }
  .unified-refund-dialog__component-remaining { flex-direction: column; align-items: flex-end; gap: 0; }
  .imported-card-refund-dialog > .service-void-modal__actions { padding: 12px 18px; }
}
</style>
