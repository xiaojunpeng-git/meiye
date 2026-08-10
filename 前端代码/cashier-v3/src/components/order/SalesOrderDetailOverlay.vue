<script setup>
import { computed, nextTick, ref } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
import SalesOrderReceiptPrintButton from '@/components/order/SalesOrderReceiptPrintButton.vue'

/**
 * 销售订单详情只消费后端已结算的快照，不在页面重算金额、业绩或权益。
 *
 * 建议 order 的数据契约：
 * {
 *   id, revision, salesOrderNo, orderStatus, paymentStatus,
 *   memberName, phone, storeName, businessDate, occurredAt, paymentCompletedAt,
 *   cashierName, source, orderNote,
 *   amountSummary: {
 *     originalAmount, priceChangeDiscountAmount, couponDiscountAmount,
 *     otherDiscountAmount, payableAmount, debtAmount, actualReceivedAmount,
 *     balancePaymentAmount
 *   },
 *   items: [{
 *     id, itemType, name, purchaseSpec, unitPrice, quantity,
 *     originalAmount, priceChangeDiscountAmount, couponDiscountAmount,
 *     payableAmount, debtAmount, actualReceivedAmount,
 *     salespeople: [{ id, name, salesPerformanceAmount }],
 *     // 仅项目：craftsmen、laborPerformanceAmount、serviceRecipientType
 *     // 卡项：cardBatchId、cardBatchNo、benefitEntryId、benefitSummary
 *   }],
 *   paymentDetails: [{ id, methodName, amount, externalTransactionNo, remark, isBalancePayment }],
 *   cardBatches: [{ id, cardBatchNo, cardName, isCustomCard, benefitEntryId, benefitSummary }],
 *   related: {
 *     debtSettlements, refunds, voids, reopenings, upgrades, gifts, services, writeoffs,
 *     operationLogs
 *   },
 *   availableActions: string[] | Record<string, boolean>
 * }
 *
 * onAction 接收：{ action, orderId, orderNo, revision, ...payload }。
 * 所有动作均为语义动作，由外层决定跳转、权限、版本校验和最终业务处理。
 */
const props = defineProps({
  order: {
    type: Object,
    default: () => ({})
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  onAction: {
    type: Function,
    default: null
  },
  cartLineCount: {
    type: Number,
    default: 0
  }
})

const emit = defineEmits(['close'])
const pendingAction = ref('')
const actionError = ref('')
const adjustmentReason = ref('')
const salesSelections = ref({})
const craftsmanSelections = ref({})
const salesPreSaleSelections = ref({})
const craftsmanPointSelections = ref({})
const activeLifecycleForm = ref('')
const refundAmount = ref('')
const balancePrincipalRefundAmount = ref('0')
const balanceGiftRefundAmount = ref('0')
const refundReason = ref('')
const voidReason = ref('')
const actionPanelRef = ref(null)
const reopenConfirmationVisible = ref(false)

const sourceOrder = computed(() => (props.order && typeof props.order === 'object' ? props.order : {}))
const orderId = computed(() => pickValue(sourceOrder.value, ['id', 'orderId', 'salesOrderId']))
const orderNo = computed(() => pickValue(sourceOrder.value, ['salesOrderNo', 'orderNo', 'no']))
const orderRevision = computed(() => pickValue(sourceOrder.value, ['revision', 'version', 'recordVersion']))
const isGuestOrder = computed(() => sourceOrder.value.isGuest === true
  || Number(sourceOrder.value.memberId || sourceOrder.value.member_id || 0) <= 0)
const itemLines = computed(() => firstList(sourceOrder.value, ['items', 'orderItems', 'lines', 'details']))
const paymentLines = computed(() => firstList(sourceOrder.value, ['paymentDetails', 'paymentLines', 'receipts', 'payments']))
const relatedSource = computed(() => {
  const value = sourceOrder.value.related || sourceOrder.value.relations || {}
  return value && typeof value === 'object' ? value : {}
})

function hasValue(value) {
  if (value === 0 || value === false) return true
  if (value === null || value === undefined) return false
  return typeof value !== 'string' || value.trim() !== ''
}

function pickValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    if (hasValue(source[key])) return source[key]
  }
  return undefined
}

function firstList(source, keys) {
  if (!source || typeof source !== 'object') return []
  let emptyList = []
  for (const key of keys) {
    const value = source[key]
    if (Array.isArray(value)) {
      if (value.length) return value
      emptyList = value
    }
    if (value && typeof value === 'object' && !Array.isArray(value)) return [value]
  }
  return emptyList
}

function asList(value) {
  if (Array.isArray(value)) return value
  return value && typeof value === 'object' ? [value] : []
}

function displayText(value, fallback = '—') {
  return hasValue(value) ? String(value) : fallback
}

function displayAmount(value) {
  if (!hasValue(value)) return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? formatMoney(amount) : String(value)
}

function itemKey(item, index) {
  return pickValue(item, ['id', 'orderItemId', 'lineId', 'itemId']) || `${itemName(item)}-${index}`
}

function itemName(item) {
  return pickValue(item, ['name', 'itemName', 'productName', 'projectName', 'title']) || '未命名商品'
}

function itemType(item) {
  const value = pickValue(item, ['itemTypeLabel', 'itemType', 'kind', 'type'])
  if (hasValue(value)) {
    const normalized = String(value).toLowerCase()
    if (['project', 'service', '项目'].includes(normalized)) return '项目'
    if (['product', 'goods', '商品'].includes(normalized)) return '商品'
    if (['card', 'card_item', '卡项', '定制卡'].includes(normalized)) return '卡项'
    return String(value)
  }
  if (item?.isProject === true || hasValue(item?.serviceRecipientType) || asList(item?.craftsmen).length) return '项目'
  if (hasCardInfo(item)) return '卡项'
  return ''
}

function isProjectItem(item) {
  return itemType(item) === '项目'
}

function hasCardInfo(item) {
  return Boolean(item?.isCard || item?.isCustomCard || pickValue(item, [
    'cardBatchId', 'cardBatchNo', 'benefitEntryId', 'benefitSummary', 'cardName'
  ]))
}

function personName(person) {
  if (typeof person === 'string') return person
  return pickValue(person, ['name', 'staffName', 'employeeName', 'salespersonName', 'craftsmanName']) || '未命名人员'
}

function salespersonDisplayName(person) {
  const name = personName(person)
  const rawRole = String(person?.roleSnapshot ?? person?.role_snapshot ?? person?.employeeType ?? person?.employee_type ?? '').toLowerCase()
  if (rawRole.includes('presale') || rawRole.includes('pre_sale') || rawRole.includes('售前')) return `${name}（售前）`
  if (rawRole.includes('postsale') || rawRole.includes('post_sale') || rawRole.includes('售后')) return `${name}（售后）`
  return name
}

function craftsmanDisplayName(person) {
  const name = personName(person)
  if (typeof person === 'string') return `${name}(轮)`
  const marked = person?.isPointCustomer ?? person?.is_point_customer ?? person?.marked
  return `${name}(${marked === true || marked === 1 || marked === '1' ? '点' : '轮'})`
}

function peopleFor(item, keys) {
  for (const key of keys) {
    const value = item?.[key]
    if (Array.isArray(value) && value.length) return value
    if (value && typeof value === 'object') return [value]
    if (typeof value === 'string' && value.trim()) return [{ name: value }]
  }
  return []
}

function performanceAmount(person, keys) {
  return pickValue(person, keys)
}

function recipientText(item) {
  const value = pickValue(item, ['serviceRecipientType', 'recipientType', 'serviceFor', 'beneficiaryType'])
  if (!hasValue(value)) return ''
  const normalized = String(value).toLowerCase()
  if (['self', '本人', 'member'].includes(normalized)) return '本人'
  if (['friend', '朋友', 'other'].includes(normalized)) return '朋友'
  return String(value)
}

function isExperienceItem(item) {
  return item?.isExperience === true || Number(item?.isExperience ?? item?.is_experience) === 1
}

function amountRowsForItem(item) {
  const rows = [
    { label: '原价', value: pickValue(item, ['originalAmount', 'originalTotalAmount', 'listAmount']) },
    { label: '改价优惠', value: pickValue(item, ['priceChangeDiscountAmount', 'changePriceDiscountAmount', 'modifiedPriceDiscountAmount']) },
    { label: '优惠券', value: pickValue(item, ['couponDiscountAmount', 'couponAmount']), note: pickValue(item, ['couponName', 'couponLabel']) },
    { label: '应付', value: pickValue(item, ['payableAmount', 'receivableAmount', 'amountDue']) },
    { label: '欠款', value: pickValue(item, ['debtAmount', 'outstandingAmount']) },
    { label: '现金业绩', value: pickValue(item, ['actualReceivedAmount', 'paidAmount', 'receivedAmount']) }
  ]
  return rows.filter((row) => hasValue(row.value) || hasValue(row.note))
}

function basicInfoValue(keys) {
  const direct = pickValue(sourceOrder.value, keys)
  if (hasValue(direct)) return direct
  return pickValue(sourceOrder.value.member, keys)
}

const basicInfoRows = computed(() => [
  { label: '销售订单号', value: orderNo.value },
  { label: '订单状态', value: pickValue(sourceOrder.value, ['orderStatus', 'statusLabel']) },
  { label: '支付状态', value: pickValue(sourceOrder.value, ['paymentStatus', 'paymentStatusLabel']) },
  { label: '会员', value: basicInfoValue(['memberName', 'name']) || (sourceOrder.value.isGuest ? '游客' : undefined) },
  { label: '手机号', value: basicInfoValue(['phone', 'mobile', 'memberPhone']) },
  { label: '销售门店', value: pickValue(sourceOrder.value, ['storeName', 'shopName']) },
  { label: '业务日期', value: pickValue(sourceOrder.value, ['businessDate']) },
  { label: '真实操作时间', value: pickValue(sourceOrder.value, ['occurredAt', 'operatedAt', 'actualOperationAt']) },
  { label: '支付完成时间', value: pickValue(sourceOrder.value, ['paymentCompletedAt', 'settledAt']) },
  { label: '收银员／操作人', value: pickValue(sourceOrder.value, ['cashierName', 'operatorName', 'operatedBy']) },
  { label: '客户来源', value: pickValue(sourceOrder.value, ['source', 'sourceLabel', 'sourceSecondary', 'sourcePrimary']) },
  { label: '订单备注', value: pickValue(sourceOrder.value, ['orderNote', 'remark', 'note']), wide: true }
].filter((row) => hasValue(row.value)))

const amountSummaryRows = computed(() => {
  const summary = sourceOrder.value.amountSummary || sourceOrder.value.summary || sourceOrder.value
  const rows = [
    { label: '原价合计', value: pickValue(summary, ['originalAmount', 'originalTotalAmount', 'listAmount']) },
    { label: '改价优惠', value: pickValue(summary, ['priceChangeDiscountAmount', 'changePriceDiscountAmount', 'modifiedPriceDiscountAmount']) },
    { label: '优惠券优惠', value: pickValue(summary, ['couponDiscountAmount', 'couponAmount']) },
    { label: '其他优惠', value: pickValue(summary, ['otherDiscountAmount', 'manualDiscountAmount']) },
    { label: '应付金额', value: pickValue(summary, ['payableAmount', 'receivableAmount', 'amountDue']) },
    { label: '欠款金额', value: pickValue(summary, ['debtAmount', 'outstandingAmount']) },
    { label: '现金业绩', value: pickValue(summary, ['actualReceivedAmount', 'paidAmount', 'receivedAmount']) },
    { label: '余额支付', value: pickValue(summary, ['balancePaymentAmount', 'balancePaidAmount']) }
  ]
  return rows.filter((row) => hasValue(row.value))
})

function isBalancePayment(line) {
  if (line?.isBalancePayment === true) return true
  const type = pickValue(line, ['paymentSource', 'paymentMethodCode', 'methodCode', 'type'])
  return ['balance', 'member_balance', '余额'].includes(String(type || '').toLowerCase())
}

function paymentMethodName(line) {
  if (isBalancePayment(line)) return '余额支付'
  return pickValue(line, ['methodName', 'paymentMethodName', 'name', 'paymentMethod']) || '未命名收款方式'
}

const visiblePaymentLines = computed(() => paymentLines.value.filter((line) => hasValue(paymentMethodName(line)) || hasValue(pickValue(line, ['amount', 'receivedAmount']))))

function cardEntryFromLine(line) {
  const batch = line?.cardBatch && typeof line.cardBatch === 'object' ? line.cardBatch : {}
  const benefit = line?.benefit && typeof line.benefit === 'object' ? line.benefit : {}
  return {
    ...batch,
    ...line,
    benefitEntryId: pickValue(line, ['benefitEntryId']) || pickValue(benefit, ['id', 'benefitEntryId']),
    benefitSummary: pickValue(line, ['benefitSummary']) || pickValue(benefit, ['summary', 'name', 'benefitSummary'])
  }
}

const cardEntries = computed(() => {
  const direct = firstList(sourceOrder.value, ['cardBatches', 'cardEntries', 'cardBenefits'])
  if (direct.length) return direct
  return itemLines.value.filter(hasCardInfo).map(cardEntryFromLine)
})

function cardKey(entry, index) {
  return pickValue(entry, ['id', 'cardBatchId', 'batchId', 'cardId', 'orderItemId']) || `${cardName(entry)}-${index}`
}

function cardName(entry) {
  return pickValue(entry, ['cardName', 'name', 'itemName', 'productName']) || '卡项'
}

function cardBatchNo(entry) {
  return pickValue(entry, ['cardBatchNo', 'batchNo', 'batchNumber'])
}

function hasAction(action) {
  const availability = sourceOrder.value.availableActions || sourceOrder.value.actionAvailability || sourceOrder.value.actions
  if (Array.isArray(availability)) return availability.includes(action)
  if (availability && typeof availability === 'object') return availability[action] === true
  return false
}

function relatedRecords(keys) {
  for (const key of keys) {
    const nested = relatedSource.value?.[key]
    const direct = sourceOrder.value?.[key]
    const nestedList = asList(nested)
    if (nestedList.length) return nestedList
    const directList = asList(direct)
    if (directList.length) return directList
  }
  return []
}

const relatedGroups = computed(() => {
  const groups = [
    { key: 'debt', label: '欠款／补交', action: 'open-order-debt-settlements', actionAliases: ['open-order-debt-settlements', 'open-debt-settlements'], records: relatedRecords(['debtSettlements', 'debts', 'supplementPayments']) },
    { key: 'refund', label: '退款', action: 'open-order-refunds', actionAliases: ['open-order-refunds', 'open-refunds'], records: relatedRecords(['refunds', 'refundRecords']) },
    { key: 'void', label: '作废', action: 'open-order-void', actionAliases: ['open-order-void', 'open-void-record'], records: relatedRecords(['voids', 'voidRecords']) },
    { key: 'upgrade', label: '升级', action: 'open-order-upgrades', actionAliases: ['open-order-upgrades', 'open-upgrade-records'], records: relatedRecords(['upgrades', 'upgradeRecords']) },
    { key: 'gift', label: '随单赠送', action: 'open-order-gifts', actionAliases: ['open-order-gifts', 'open-gift-records'], records: relatedRecords(['gifts', 'giftRecords', 'orderGifts']) },
    { key: 'service', label: '服务', action: 'open-order-services', actionAliases: ['open-order-services', 'open-service-records'], records: relatedRecords(['services', 'serviceRecords']) },
    { key: 'writeoff', label: '核销', action: 'open-order-writeoffs', actionAliases: ['open-order-writeoffs', 'open-writeoff-records'], records: relatedRecords(['writeoffs', 'writeoffRecords']) }
  ]
  return groups.filter((group) => {
    // 后端不应为游客下发补交能力；这里再兜底，避免异常旧投影误露入口。
    if (group.key === 'debt' && (sourceOrder.value.isGuest === true || Number(sourceOrder.value.memberId || 0) <= 0)) return false
    return group.records.length || group.actionAliases.some(hasAction)
  })
})

const operationRecords = computed(() => relatedRecords(['operationLogs', 'operations', 'operationRecords']))
const personnelAdjustment = computed(() => sourceOrder.value.personnelAdjustment && typeof sourceOrder.value.personnelAdjustment === 'object' ? sourceOrder.value.personnelAdjustment : null)
const adjustmentLines = computed(() => Array.isArray(personnelAdjustment.value?.lines) ? personnelAdjustment.value.lines : [])
const adjustmentSalespeople = computed(() => Array.isArray(personnelAdjustment.value?.salespeople) ? personnelAdjustment.value.salespeople : [])
const adjustmentCraftsmen = computed(() => Array.isArray(personnelAdjustment.value?.craftsmen) ? personnelAdjustment.value.craftsmen : [])
const canOpenOperationLogs = computed(() => hasAction('open-order-operation-logs') || hasAction('open-operation-logs'))
const quickActions = computed(() => [
  { action: 'open-sales-order-personnel-adjustment', label: '人员调整' },
  { action: 'refund-sales-order', label: '退款并作废' },
  { action: 'void-sales-order', label: '作废订单' },
  { action: 'reopen-sales-order', label: '重开' },
  { action: 'upgrade-sales-order', label: '卡项／项目升级' }
].filter((item) => hasAction(item.action)))

function submitPersonnelAdjustment() {
  const personnel = []
  for (const line of adjustmentLines.value) {
    const salesStaffId = Number(salesSelections.value[line.orderLineId] || 0)
    const craftsmanStaffId = Number(craftsmanSelections.value[line.orderLineId] || 0)
    if (salesStaffId > 0) personnel.push({ orderLineId: line.orderLineId, role: 'salesperson', staffId: salesStaffId, isPreSale: Boolean(salesPreSaleSelections.value[line.orderLineId]) })
    if (craftsmanStaffId > 0) personnel.push({ orderLineId: line.orderLineId, role: 'craftsman', staffId: craftsmanStaffId, isPointCustomer: Boolean(craftsmanPointSelections.value[line.orderLineId]) })
  }
  return runAction('adjust-sales-order-personnel', { reason: adjustmentReason.value, personnel })
}

async function revealActionPanel() {
  await nextTick()
  actionPanelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function openLifecycleForm(action) {
  actionError.value = ''
  if (action === 'refund-sales-order') activeLifecycleForm.value = 'refund'
  if (action === 'void-sales-order') activeLifecycleForm.value = 'void'
  await revealActionPanel()
}

async function runQuickAction(action) {
  if (['refund-sales-order', 'void-sales-order'].includes(action)) {
    await openLifecycleForm(action)
    return
  }
  if (action === 'reopen-sales-order' && props.cartLineCount > 0) {
    reopenConfirmationVisible.value = true
    return
  }
  await runAction(action)
  await revealActionPanel()
}

async function confirmReopenWithCartReplacement() {
  reopenConfirmationVisible.value = false
  await runAction('reopen-sales-order', { replaceWorkspace: true })
  await revealActionPanel()
}

function isValidMoney(value, allowZero = false) {
  const normalized = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) return false
  const amount = Number(normalized)
  return Number.isFinite(amount) && (allowZero ? amount >= 0 : amount > 0)
}

function moneyCents(value) {
  const [yuan, fraction = ''] = String(value ?? '').trim().split('.')
  return Number(yuan) * 100 + Number(fraction.padEnd(2, '0'))
}

function actionStatusFromResponse(response) {
  const envelope = response?.data?.result ? response.data : response
  return String(envelope?.result?.status || envelope?.status || '')
}

function resetRefundForm() {
  refundAmount.value = ''
  balancePrincipalRefundAmount.value = '0'
  balanceGiftRefundAmount.value = '0'
  refundReason.value = ''
}

async function submitRefund() {
  const balancePrincipalAmount = isGuestOrder.value ? '0' : balancePrincipalRefundAmount.value
  const balanceGiftAmount = isGuestOrder.value ? '0' : balanceGiftRefundAmount.value
  if (!isValidMoney(refundAmount.value, true)) {
    actionError.value = '实际退款金额必须大于等于 0，且最多保留两位小数。'
    return
  }
  if (!isValidMoney(balancePrincipalAmount, true)) {
    actionError.value = '余额本金退回金额必须大于等于 0，且最多保留两位小数。'
    return
  }
  if (!isValidMoney(balanceGiftAmount, true)) {
    actionError.value = '赠金退回金额必须大于等于 0，且最多保留两位小数。'
    return
  }
  if (moneyCents(refundAmount.value)
    + moneyCents(balancePrincipalAmount)
    + moneyCents(balanceGiftAmount) <= 0) {
    actionError.value = '实际退款、本金退回和赠金退回至少需要填写一项。'
    return
  }
  if (!refundReason.value.trim()) {
    actionError.value = '请填写退款原因。'
    return
  }
  const response = await runAction('refund-sales-order', {
    // refundAmount remains the authoritative refund-statistics amount.
    refundAmount: refundAmount.value,
    actualRefundAmount: refundAmount.value,
    balancePrincipalRefundAmount: balancePrincipalAmount,
    balanceGiftRefundAmount: balanceGiftAmount,
    reason: refundReason.value
  })
  if (['success', 'succeeded'].includes(actionStatusFromResponse(response))) {
    activeLifecycleForm.value = ''
    resetRefundForm()
  }
}

async function submitVoid() {
  if (!voidReason.value.trim()) {
    actionError.value = '请填写作废原因。'
    return
  }
  const response = await runAction('void-sales-order', { reason: voidReason.value })
  if (['success', 'succeeded'].includes(actionStatusFromResponse(response))) {
    activeLifecycleForm.value = ''
    voidReason.value = ''
  }
}

function relationRecordLabel(record) {
  if (typeof record === 'string') return record
  return pickValue(record, ['label', 'recordNo', 'no', 'orderNo', 'refundNo', 'serviceNo', 'writeoffNo', 'name']) || '查看关联记录'
}

function relationRecordStatus(record) {
  if (!record || typeof record !== 'object') return ''
  const statusLabel = pickValue(record, ['statusLabel', 'status_label'])
  if (statusLabel) return String(statusLabel)
  const status = String(pickValue(record, ['status', 'state']) || '')
  return {
    succeeded: '已完成',
    success: '已完成',
    failed: '失败',
    conflict: '版本冲突',
    result_unknown: '结果未知',
    pending: '处理中',
    processing: '处理中'
  }[status] || (/^[\u4e00-\u9fff]/.test(status) ? status : '')
}

function relationRecordId(record) {
  if (!record || typeof record !== 'object') return undefined
  return pickValue(record, ['id', 'recordId', 'relationId', 'refundId', 'serviceId', 'writeoffId'])
}

function operationKey(record, index) {
  return pickValue(record, ['id', 'operationId', 'logId']) || `${operationLabel(record)}-${index}`
}

function operationLabel(record) {
  if (typeof record === 'string') return record
  return pickValue(record, ['actionLabel', 'operation', 'operationName', 'title']) || '订单操作'
}

function operationTime(record) {
  if (!record || typeof record !== 'object') return ''
  return pickValue(record, ['occurredAt', 'operatedAt', 'createdAt', 'time']) || ''
}

function operationOperator(record) {
  if (!record || typeof record !== 'object') return ''
  return pickValue(record, ['operatorName', 'operator', 'staffName', 'userName']) || ''
}

function operationContent(record) {
  if (!record || typeof record !== 'object') return ''
  return pickValue(record, ['content', 'description', 'remark', 'detail']) || ''
}

async function runAction(action, payload = {}) {
  if (!props.onAction || props.isLoading || pendingAction.value) return
  pendingAction.value = action
  actionError.value = ''
  try {
    const response = await props.onAction({
      action,
      orderId: orderId.value,
      orderNo: orderNo.value,
      revision: orderRevision.value,
      ...payload
    })
    const envelope = response?.data?.result ? response.data : response
    const status = envelope?.result?.status || envelope?.status || ''
    if (['failed', 'conflict', 'result_unknown'].includes(status)) {
      actionError.value = envelope?.result?.message || envelope?.message || '暂时无法完成该操作，请稍后再试。'
    } else if (!['success', 'succeeded'].includes(status)) {
      actionError.value = '系统没有返回可确认的处理结果，本次操作状态未改变。'
    }
    return response
  } catch (error) {
    actionError.value = error?.message || '暂时无法完成该操作，请稍后再试。'
  } finally {
    pendingAction.value = ''
  }
}
</script>

<template>
  <section class="sales-order-detail-overlay" aria-label="销售订单详情">
    <header class="sales-order-detail-overlay__header">
      <div>
        <p>订单中心 · 销售订单</p>
        <h2>销售订单详情</h2>
        <span v-if="orderNo">{{ orderNo }}</span>
      </div>
      <div class="sales-order-detail-overlay__header-actions">
        <button
          v-for="item in quickActions"
          :key="item.action"
          type="button"
          class="sales-order-detail-button sales-order-detail-button--secondary"
          :disabled="isLoading || Boolean(pendingAction)"
          @click="runQuickAction(item.action)"
        >{{ pendingAction === item.action ? '处理中…' : item.label }}</button>
        <SalesOrderReceiptPrintButton
          class="sales-order-detail-button sales-order-detail-button--secondary"
          :order="sourceOrder"
          :disabled="isLoading"
          @error="actionError = $event"
        >
          小票打印
        </SalesOrderReceiptPrintButton>
        <button type="button" class="sales-order-detail-button sales-order-detail-button--secondary" :disabled="isLoading || Boolean(pendingAction)" @click="$emit('close')">关闭</button>
      </div>
    </header>

    <div v-if="reopenConfirmationVisible" class="sales-order-detail-confirm" role="dialog" aria-modal="true" aria-label="确认清空购物车并重开">
      <section>
        <h3>清空购物车并重开？</h3>
        <p>当前购物车有 {{ cartLineCount }} 项未结账内容。确认后将清空这些内容，并按原订单商品重新创建收银草稿。</p>
        <footer>
          <button type="button" class="sales-order-detail-button sales-order-detail-button--text" @click="reopenConfirmationVisible = false">否，取消</button>
          <button type="button" class="sales-order-detail-button sales-order-detail-button--secondary" @click="confirmReopenWithCartReplacement">是，清空并重开</button>
        </footer>
      </section>
    </div>

    <main class="sales-order-detail-overlay__body">
      <div v-if="isLoading" class="sales-order-detail-loading" role="status">
        <span class="sales-order-detail-loading__spinner" />
        正在加载销售订单详情…
      </div>

      <template v-else>
        <section v-if="basicInfoRows.length" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>基本信息</h3></div>
          </header>
          <dl class="sales-order-detail-info-grid">
            <div v-for="row in basicInfoRows" :key="row.label" :class="{ 'sales-order-detail-info-grid__item--wide': row.wide }">
              <dt>{{ row.label }}</dt>
              <dd>{{ displayText(row.value) }}</dd>
            </div>
          </dl>
        </section>

        <section class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>商品明细</h3></div>
          </header>

          <div v-if="itemLines.length" class="sales-order-detail-items">
            <article v-for="(item, index) in itemLines" :key="itemKey(item, index)" class="sales-order-detail-item">
              <header class="sales-order-detail-item__header">
                <div>
                  <span v-if="itemType(item)" class="sales-order-detail-tag">{{ itemType(item) }}</span>
                  <h4>{{ itemName(item) }}</h4>
                  <p v-if="pickValue(item, ['purchaseSpec', 'specification', 'specName', 'packageName', 'cardSpecification'])">购买规格：{{ pickValue(item, ['purchaseSpec', 'specification', 'specName', 'packageName', 'cardSpecification']) }}</p>
                </div>
                <div class="sales-order-detail-item__unit">
                  <span v-if="hasValue(pickValue(item, ['unitPrice', 'price', 'salePrice']))">单价 {{ displayAmount(pickValue(item, ['unitPrice', 'price', 'salePrice'])) }}</span>
                  <span v-if="hasValue(pickValue(item, ['quantity', 'count', 'number']))">数量 {{ displayText(pickValue(item, ['quantity', 'count', 'number'])) }}</span>
                </div>
              </header>

              <dl v-if="amountRowsForItem(item).length" class="sales-order-detail-item__amounts">
                <div v-for="row in amountRowsForItem(item)" :key="row.label">
                  <dt>{{ row.label }}</dt>
                  <dd>
                    <strong v-if="hasValue(row.value)">{{ displayAmount(row.value) }}</strong>
                    <span v-if="hasValue(row.note)">{{ row.note }}</span>
                  </dd>
                </div>
              </dl>

              <section v-if="peopleFor(item, ['salespeople', 'salesPersons', 'salespersonAllocations', 'salesperson']).length" class="sales-order-detail-allocation">
                <h5>销售人及销售业绩</h5>
                <div class="sales-order-detail-person-list">
                  <div v-for="(person, personIndex) in peopleFor(item, ['salespeople', 'salesPersons', 'salespersonAllocations', 'salesperson'])" :key="pickValue(person, ['id', 'staffId', 'employeeId']) || `${personName(person)}-${personIndex}`">
                    <span>{{ salespersonDisplayName(person) }}</span>
                    <strong v-if="hasValue(performanceAmount(person, ['salesPerformanceAmount', 'performanceAmount', 'salesAmount']))">销售业绩 {{ displayAmount(performanceAmount(person, ['salesPerformanceAmount', 'performanceAmount', 'salesAmount'])) }}</strong>
                  </div>
                </div>
              </section>

              <section v-if="isProjectItem(item)" class="sales-order-detail-allocation sales-order-detail-allocation--project">
                <div class="sales-order-detail-allocation__title-row">
                  <h5>项目服务信息</h5>
                  <span v-if="isExperienceItem(item)" class="sales-order-detail-tag">体验</span>
                  <span v-if="recipientText(item)" class="sales-order-detail-tag sales-order-detail-tag--soft">{{ recipientText(item) }}</span>
                </div>
                <div v-if="peopleFor(item, ['craftsmen', 'craftspeople', 'artisans', 'craftsmanAllocations']).length" class="sales-order-detail-person-list">
                  <div v-for="(person, personIndex) in peopleFor(item, ['craftsmen', 'craftspeople', 'artisans', 'craftsmanAllocations'])" :key="pickValue(person, ['id', 'staffId', 'employeeId']) || `${personName(person)}-${personIndex}`">
                    <span>{{ craftsmanDisplayName(person) }}</span>
                    <strong v-if="hasValue(performanceAmount(person, ['laborPerformanceAmount', 'performanceAmount', 'laborAmount']))">劳动业绩 {{ displayAmount(performanceAmount(person, ['laborPerformanceAmount', 'performanceAmount', 'laborAmount'])) }}</strong>
                  </div>
                </div>
                <p v-else-if="hasValue(pickValue(item, ['laborPerformanceAmount', 'laborAmount']))" class="sales-order-detail-allocation__single-performance">劳动业绩 {{ displayAmount(pickValue(item, ['laborPerformanceAmount', 'laborAmount'])) }}</p>
              </section>
            </article>
          </div>
          <div v-else class="sales-order-detail-empty">该订单暂未返回商品明细。</div>
        </section>

        <section v-if="cardEntries.length" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>卡项／定制卡批次与权益</h3><span>查看批次和权益时将跳转至对应业务详情。</span></div>
          </header>
          <div class="sales-order-detail-card-list">
            <article v-for="(entry, index) in cardEntries" :key="cardKey(entry, index)" class="sales-order-detail-card-entry">
              <div>
                <span v-if="entry.isCustomCard" class="sales-order-detail-tag">定制卡</span>
                <strong>{{ cardName(entry) }}</strong>
                <p v-if="cardBatchNo(entry)">批次：{{ cardBatchNo(entry) }}</p>
                <p v-if="pickValue(entry, ['benefitSummary', 'rightsSummary', 'benefitDescription'])">权益：{{ pickValue(entry, ['benefitSummary', 'rightsSummary', 'benefitDescription']) }}</p>
              </div>
              <div class="sales-order-detail-card-entry__actions">
                <button
                  v-if="hasValue(pickValue(entry, ['cardBatchId', 'batchId', 'id']))"
                  type="button"
                  class="sales-order-detail-button sales-order-detail-button--text"
                  :disabled="Boolean(pendingAction)"
                  @click="runAction('open-card-batch', { cardBatchId: pickValue(entry, ['cardBatchId', 'batchId', 'id']) })"
                >查看批次</button>
                <button
                  v-if="hasValue(pickValue(entry, ['benefitEntryId', 'benefitId', 'rightsId']))"
                  type="button"
                  class="sales-order-detail-button sales-order-detail-button--text"
                  :disabled="Boolean(pendingAction)"
                  @click="runAction('open-card-benefits', { benefitEntryId: pickValue(entry, ['benefitEntryId', 'benefitId', 'rightsId']) })"
                >查看权益</button>
              </div>
            </article>
          </div>
        </section>

        <section v-if="amountSummaryRows.length" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>金额汇总</h3></div>
          </header>
          <dl class="sales-order-detail-summary-grid">
            <div v-for="row in amountSummaryRows" :key="row.label">
              <dt>{{ row.label }}</dt>
              <dd>{{ displayAmount(row.value) }}</dd>
            </div>
          </dl>
        </section>

        <section v-if="visiblePaymentLines.length" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>收款明细</h3><span>余额支付仅显示总额；本金、赠金拆分仅在余额变更记录查看。</span></div>
          </header>
          <div class="sales-order-detail-table-wrap">
            <table class="sales-order-detail-table">
              <thead><tr><th>收款方式</th><th>收款金额</th><th>外部流水号</th><th>收款备注</th></tr></thead>
              <tbody>
                <tr v-for="(line, index) in visiblePaymentLines" :key="pickValue(line, ['id', 'paymentId', 'receiptId']) || `${paymentMethodName(line)}-${index}`">
                  <td>{{ paymentMethodName(line) }}</td>
                  <td>{{ displayAmount(pickValue(line, ['amount', 'receivedAmount', 'paymentAmount'])) }}</td>
                  <td>{{ displayText(pickValue(line, ['externalTransactionNo', 'externalNo', 'transactionNo', 'outTradeNo'])) }}</td>
                  <td>{{ displayText(pickValue(line, ['remark', 'note', 'paymentRemark'])) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="relatedGroups.length" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>关联业务</h3><span>关联记录均通过业务单号下钻，不在订单详情内改写历史。</span></div>
          </header>
          <div class="sales-order-detail-related-grid">
            <article v-for="group in relatedGroups" :key="group.key" class="sales-order-detail-related-card">
              <header><h4>{{ group.label }}</h4><span>{{ group.records.length ? '已有关联记录' : '可继续处理' }}</span></header>
              <div v-if="group.records.length" class="sales-order-detail-related-card__records">
                <button
                  v-for="(record, index) in group.records"
                  :key="relationRecordId(record) || `${relationRecordLabel(record)}-${index}`"
                  type="button"
                  :disabled="Boolean(pendingAction)"
                  @click="runAction(group.action, { relationType: group.key, relationId: relationRecordId(record) })"
                >
                  <strong>{{ relationRecordLabel(record) }}</strong>
                  <span v-if="relationRecordStatus(record)">{{ relationRecordStatus(record) }}</span>
                </button>
              </div>
              <button
                v-else
                type="button"
                class="sales-order-detail-button sales-order-detail-button--text"
                :disabled="Boolean(pendingAction)"
                @click="runAction(group.action, { relationType: group.key })"
              >查看{{ group.label }}</button>
            </article>
          </div>
        </section>

        <div ref="actionPanelRef">
          <p v-if="actionError" class="sales-order-detail-error" role="alert">{{ actionError }}</p>

          <section v-if="activeLifecycleForm === 'refund'" class="sales-order-detail-section sales-order-detail-section--actions">
          <header class="sales-order-detail-section__header"><div><h3>退款并作废</h3><span>退款金额由本次人工填写；成功后该订单只能保留查看，不能再次退款或作废。卡项、权益、库存、已完成服务和欠款不会随本次退款回退。</span></div></header>
          <div class="sales-order-detail-lifecycle-form">
            <div class="sales-order-detail-refund-amounts">
              <label>实际退款金额<input v-model.trim="refundAmount" inputmode="decimal" placeholder="输入实际退给客户的金额" /></label>
              <label v-if="!isGuestOrder">余额本金退回金额<input v-model.trim="balancePrincipalRefundAmount" inputmode="decimal" placeholder="输入退回账户本金" /></label>
              <label v-if="!isGuestOrder">赠金退回金额<input v-model.trim="balanceGiftRefundAmount" inputmode="decimal" placeholder="输入退回账户赠金" /></label>
            </div>
            <label>退款原因<textarea v-model.trim="refundReason" maxlength="255" rows="3" placeholder="填写退款原因" /></label>
            <div class="sales-order-detail-quick-actions"><button type="button" class="sales-order-detail-button sales-order-detail-button--text" :disabled="Boolean(pendingAction)" @click="activeLifecycleForm = ''">取消</button><button type="button" class="sales-order-detail-button sales-order-detail-button--secondary" :disabled="Boolean(pendingAction)" @click="submitRefund">确认退款并作废</button></div>
          </div>
          </section>

          <section v-if="activeLifecycleForm === 'void'" class="sales-order-detail-section sales-order-detail-section--actions">
          <header class="sales-order-detail-section__header"><div><h3>作废订单</h3><span>作废会保留原单和操作记录，自动退回原单本金、赠金和未使用卡权益，并取消完全未还欠款。已使用权益或已还欠款时不允许作废。</span></div></header>
          <div class="sales-order-detail-lifecycle-form">
            <label>作废原因<textarea v-model.trim="voidReason" maxlength="255" rows="3" placeholder="填写作废原因" /></label>
            <div class="sales-order-detail-quick-actions"><button type="button" class="sales-order-detail-button sales-order-detail-button--text" :disabled="Boolean(pendingAction)" @click="activeLifecycleForm = ''">取消</button><button type="button" class="sales-order-detail-button sales-order-detail-button--secondary" :disabled="Boolean(pendingAction)" @click="submitVoid">确认作废</button></div>
          </div>
          </section>

          <section v-if="personnelAdjustment" class="sales-order-detail-section sales-order-detail-section--actions">
          <header class="sales-order-detail-section__header"><div><h3>人员调整</h3><span>调整会保留原订单与原业绩快照，并写入新的调整事实。</span></div></header>
          <div class="sales-order-detail-personnel-adjustment">
            <article v-for="line in adjustmentLines" :key="line.orderLineId">
              <strong>{{ line.itemName }}</strong>
              <label>销售人
                <select v-model="salesSelections[line.orderLineId]">
                  <option value="">不调整</option>
                  <option v-for="person in adjustmentSalespeople" :key="person.staffId" :value="person.staffId">{{ person.name }}</option>
                </select>
              </label>
              <label v-if="salesSelections[line.orderLineId]" class="sales-order-detail-personnel-adjustment__flag"><input v-model="salesPreSaleSelections[line.orderLineId]" type="checkbox"> 售前</label>
              <label v-if="line.canAdjustCraftsman">手艺人
                <select v-model="craftsmanSelections[line.orderLineId]">
                  <option value="">不调整</option>
                  <option v-for="person in adjustmentCraftsmen" :key="person.staffId" :value="person.staffId">{{ person.name }}</option>
                </select>
              </label>
              <label v-if="line.canAdjustCraftsman && craftsmanSelections[line.orderLineId]" class="sales-order-detail-personnel-adjustment__flag"><input v-model="craftsmanPointSelections[line.orderLineId]" type="checkbox"> 点客（未勾选为轮）</label>
            </article>
            <label class="sales-order-detail-personnel-adjustment__reason">调整原因
              <textarea v-model.trim="adjustmentReason" maxlength="255" rows="3" placeholder="填写调整原因" />
            </label>
            <div class="sales-order-detail-quick-actions"><button type="button" class="sales-order-detail-button sales-order-detail-button--secondary" :disabled="Boolean(pendingAction)" @click="submitPersonnelAdjustment">确认调整</button></div>
          </div>
          </section>
        </div>

        <section v-if="operationRecords.length || canOpenOperationLogs" class="sales-order-detail-section">
          <header class="sales-order-detail-section__header">
            <div><h3>操作记录</h3><span>记录真实发生时间与操作人，便于追溯。</span></div>
            <button v-if="canOpenOperationLogs" type="button" class="sales-order-detail-button sales-order-detail-button--text" :disabled="Boolean(pendingAction)" @click="runAction('open-order-operation-logs')">查看全部</button>
          </header>
          <div v-if="operationRecords.length" class="sales-order-detail-operation-list">
            <article v-for="(record, index) in operationRecords" :key="operationKey(record, index)">
              <div><strong>{{ operationLabel(record) }}</strong><span v-if="operationContent(record)">{{ operationContent(record) }}</span></div>
              <div><span v-if="operationOperator(record)">{{ operationOperator(record) }}</span><time v-if="operationTime(record)">{{ operationTime(record) }}</time></div>
            </article>
          </div>
          <div v-else class="sales-order-detail-empty">暂无操作记录。</div>
        </section>

      </template>
    </main>
  </section>
</template>

<style scoped>
.sales-order-detail-overlay {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  flex-direction: column;
  background: #f6f8fb;
  color: #1f2937;
}

.sales-order-detail-overlay__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 20px 32px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.sales-order-detail-confirm {
  position: fixed;
  inset: 0;
  z-index: 2;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgba(15, 23, 42, .36);
}

.sales-order-detail-confirm > section {
  width: min(460px, 100%);
  padding: 24px;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 18px 48px rgba(15, 23, 42, .22);
}

.sales-order-detail-confirm h3 { margin: 0; font-size: 18px; }
.sales-order-detail-confirm p { margin: 12px 0 20px; color: #475569; line-height: 1.6; }
.sales-order-detail-confirm footer { display: flex; justify-content: flex-end; gap: 10px; }

.sales-order-detail-overlay__header p,
.sales-order-detail-overlay__header h2,
.sales-order-detail-overlay__header span,
.sales-order-detail-section h3,
.sales-order-detail-section h4,
.sales-order-detail-section h5,
.sales-order-detail-section p {
  margin: 0;
}

.sales-order-detail-overlay__header p {
  margin-bottom: 4px;
  color: #175cd3;
  font-size: 13px;
  font-weight: 700;
}

.sales-order-detail-overlay__header h2 {
  font-size: 23px;
  line-height: 1.3;
}

.sales-order-detail-overlay__header span {
  display: block;
  margin-top: 5px;
  color: #6b7280;
  font-size: 13px;
}

.sales-order-detail-overlay__header-actions,
.sales-order-detail-quick-actions,
.sales-order-detail-card-entry__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.sales-order-detail-personnel-adjustment {
  display: grid;
  gap: 12px;
}

.sales-order-detail-personnel-adjustment article {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) repeat(2, minmax(180px, 1fr));
  gap: 12px;
  align-items: end;
  padding: 12px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.sales-order-detail-personnel-adjustment label {
  display: grid;
  gap: 6px;
  color: #475467;
  font-size: 13px;
}

.sales-order-detail-personnel-adjustment select,
.sales-order-detail-personnel-adjustment textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  padding: 8px 10px;
  color: #1f2937;
  background: #fff;
  font: inherit;
}

.sales-order-detail-personnel-adjustment__reason { max-width: 560px; }

.sales-order-detail-lifecycle-form {
  display: grid;
  gap: 12px;
  max-width: 560px;
}

.sales-order-detail-lifecycle-form label {
  display: grid;
  gap: 6px;
  color: #475467;
  font-size: 13px;
}

.sales-order-detail-refund-amounts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.sales-order-detail-lifecycle-form input,
.sales-order-detail-lifecycle-form textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  padding: 8px 10px;
  color: #1f2937;
  background: #fff;
  font: inherit;
}

@media (max-width: 720px) {
  .sales-order-detail-refund-amounts {
    grid-template-columns: 1fr;
  }
}

.sales-order-detail-overlay__body {
  flex: 1;
  overflow: auto;
  padding: 24px 32px 48px;
  scrollbar-gutter: stable;
}

.sales-order-detail-section {
  max-width: 1180px;
  margin: 0 auto 16px;
  padding: 20px 22px;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  background: #fff;
}

.sales-order-detail-section__header,
.sales-order-detail-item__header,
.sales-order-detail-card-entry,
.sales-order-detail-related-card > header,
.sales-order-detail-operation-list article {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
}

.sales-order-detail-section__header {
  align-items: center;
  margin-bottom: 16px;
}

.sales-order-detail-section__header h3 {
  color: #111827;
  font-size: 17px;
}

.sales-order-detail-section__header span {
  display: block;
  margin-top: 5px;
  color: #6b7280;
  font-size: 13px;
  line-height: 1.55;
}

.sales-order-detail-info-grid,
.sales-order-detail-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0;
  margin: 0;
  border-top: 1px solid #edf0f4;
  border-left: 1px solid #edf0f4;
}

.sales-order-detail-info-grid > div,
.sales-order-detail-summary-grid > div {
  min-height: 76px;
  padding: 13px 14px;
  border-right: 1px solid #edf0f4;
  border-bottom: 1px solid #edf0f4;
}

.sales-order-detail-info-grid__item--wide {
  grid-column: span 2;
}

.sales-order-detail-info-grid dt,
.sales-order-detail-summary-grid dt,
.sales-order-detail-item__amounts dt {
  margin-bottom: 7px;
  color: #7a8494;
  font-size: 12px;
}

.sales-order-detail-info-grid dd,
.sales-order-detail-summary-grid dd,
.sales-order-detail-item__amounts dd {
  margin: 0;
  color: #273244;
  font-size: 14px;
  line-height: 1.55;
  overflow-wrap: anywhere;
}

.sales-order-detail-summary-grid dd {
  color: #111827;
  font-size: 16px;
  font-weight: 700;
}

.sales-order-detail-items {
  display: grid;
  gap: 12px;
}

.sales-order-detail-item {
  padding: 16px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fcfdff;
}

.sales-order-detail-item__header {
  padding-bottom: 13px;
  border-bottom: 1px solid #edf0f4;
}

.sales-order-detail-item__header > div:first-child {
  min-width: 0;
}

.sales-order-detail-item__header h4 {
  display: inline;
  margin-left: 8px;
  color: #111827;
  font-size: 16px;
}

.sales-order-detail-item__header p {
  margin-top: 8px;
  color: #6b7280;
  font-size: 13px;
}

.sales-order-detail-item__unit {
  display: flex;
  flex: 0 0 auto;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 12px;
  color: #4b5563;
  font-size: 13px;
}

.sales-order-detail-tag {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  background: #eff8ff;
  color: #175cd3;
  font-size: 12px;
  font-weight: 700;
  line-height: 22px;
}

.sales-order-detail-tag--soft {
  background: #ecfdf5;
  color: #047857;
}

.sales-order-detail-item__amounts {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 8px;
  margin: 14px 0 0;
}

.sales-order-detail-item__amounts > div {
  min-width: 0;
  padding: 10px;
  border-radius: 8px;
  background: #f5f7fb;
}

.sales-order-detail-item__amounts dd {
  display: grid;
  gap: 4px;
}

.sales-order-detail-item__amounts strong {
  color: #273244;
}

.sales-order-detail-item__amounts span {
  color: #7a8494;
  font-size: 12px;
}

.sales-order-detail-allocation {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed #dfe4ec;
}

.sales-order-detail-allocation h5 {
  color: #4b5563;
  font-size: 13px;
}

.sales-order-detail-allocation__title-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sales-order-detail-person-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 9px;
}

.sales-order-detail-person-list > div {
  display: flex;
  align-items: center;
  gap: 9px;
  min-height: 30px;
  padding: 0 10px;
  border: 1px solid #dbe4f0;
  border-radius: 7px;
  background: #fff;
  color: #475569;
  font-size: 13px;
}

.sales-order-detail-person-list strong,
.sales-order-detail-allocation__single-performance {
  color: #1d4ed8;
  font-weight: 700;
}

.sales-order-detail-allocation__single-performance {
  margin-top: 8px;
  font-size: 13px;
}

.sales-order-detail-card-list,
.sales-order-detail-related-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.sales-order-detail-card-entry,
.sales-order-detail-related-card {
  min-width: 0;
  padding: 15px;
  border: 1px solid #e3e8f0;
  border-radius: 10px;
  background: #fbfcfe;
}

.sales-order-detail-card-entry strong,
.sales-order-detail-related-card h4 {
  color: #1f2937;
  font-size: 14px;
}

.sales-order-detail-card-entry p {
  margin-top: 6px;
  color: #64748b;
  font-size: 13px;
  line-height: 1.5;
}

.sales-order-detail-card-entry .sales-order-detail-tag {
  margin-right: 7px;
}

.sales-order-detail-table-wrap {
  overflow-x: auto;
}

.sales-order-detail-table {
  width: 100%;
  min-width: 700px;
  border-collapse: collapse;
  color: #334155;
  font-size: 13px;
}

.sales-order-detail-table th,
.sales-order-detail-table td {
  padding: 12px 13px;
  border-bottom: 1px solid #e9edf3;
  text-align: left;
  vertical-align: top;
}

.sales-order-detail-table th {
  background: #f7f9fc;
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
}

.sales-order-detail-related-card > header {
  align-items: center;
}

.sales-order-detail-related-card > header span {
  color: #7a8494;
  font-size: 12px;
}

.sales-order-detail-related-card__records {
  display: grid;
  gap: 7px;
  margin-top: 12px;
}

.sales-order-detail-related-card__records button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding: 9px 10px;
  border: 1px solid #dbe4f0;
  border-radius: 7px;
  background: #fff;
  color: #334155;
  cursor: pointer;
  text-align: left;
}

.sales-order-detail-related-card__records button:hover:not(:disabled) {
  border-color: #b2ddff;
  background: #f5faff;
}

.sales-order-detail-related-card__records span {
  flex: 0 0 auto;
  color: #7a8494;
  font-size: 12px;
}

.sales-order-detail-operation-list {
  display: grid;
  gap: 0;
  border-top: 1px solid #edf0f4;
}

.sales-order-detail-operation-list article {
  padding: 13px 2px;
  border-bottom: 1px solid #edf0f4;
}

.sales-order-detail-operation-list article > div {
  display: grid;
  gap: 5px;
}

.sales-order-detail-operation-list strong {
  color: #334155;
  font-size: 14px;
}

.sales-order-detail-operation-list span,
.sales-order-detail-operation-list time {
  color: #7a8494;
  font-size: 12px;
}

.sales-order-detail-operation-list article > div:last-child {
  flex: 0 0 auto;
  justify-items: end;
  text-align: right;
}

.sales-order-detail-button {
  min-height: 34px;
  padding: 0 13px;
  border: 1px solid transparent;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 650;
  cursor: pointer;
}

.sales-order-detail-button:disabled,
.sales-order-detail-related-card button:disabled {
  cursor: not-allowed;
  opacity: .58;
}

.sales-order-detail-button--secondary {
  border-color: #cfd8e5;
  background: #fff;
  color: #334155;
}

.sales-order-detail-button--secondary:hover:not(:disabled) {
  border-color: #b2ddff;
  background: #f5faff;
  color: #175cd3;
}

.sales-order-detail-button--text {
  min-height: auto;
  padding: 2px 0;
  background: transparent;
  color: #175cd3;
  text-align: left;
}

.sales-order-detail-button--text:hover:not(:disabled) {
  color: #0b4ea2;
  text-decoration: underline;
}

.sales-order-detail-empty {
  padding: 20px;
  border: 1px dashed #cbd5e1;
  border-radius: 9px;
  color: #7a8494;
  font-size: 13px;
  text-align: center;
}

.sales-order-detail-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 260px;
  color: #64748b;
  font-size: 14px;
}

.sales-order-detail-loading__spinner {
  width: 18px;
  height: 18px;
  border: 2px solid #d1e9ff;
  border-top-color: #2e90fa;
  border-radius: 50%;
  animation: sales-order-detail-spin .7s linear infinite;
}

.sales-order-detail-error {
  max-width: 1180px;
  margin: 0 auto;
  padding: 11px 13px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fff1f2;
  color: #b91c1c;
  font-size: 13px;
}

@keyframes sales-order-detail-spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 760px) {
  .sales-order-detail-overlay__header,
  .sales-order-detail-section__header {
    align-items: flex-start;
    flex-direction: column;
  }

  .sales-order-detail-info-grid,
  .sales-order-detail-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .sales-order-detail-item__amounts {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .sales-order-detail-overlay__header,
  .sales-order-detail-overlay__body {
    padding-right: 16px;
    padding-left: 16px;
  }

  .sales-order-detail-overlay__header-actions,
  .sales-order-detail-overlay__header-actions .sales-order-detail-button {
    width: 100%;
  }

  .sales-order-detail-section {
    padding: 16px;
  }

  .sales-order-detail-info-grid,
  .sales-order-detail-summary-grid,
  .sales-order-detail-card-list,
  .sales-order-detail-related-grid,
  .sales-order-detail-item__amounts {
    grid-template-columns: 1fr;
  }

  .sales-order-detail-info-grid__item--wide {
    grid-column: span 1;
  }

  .sales-order-detail-item__header,
  .sales-order-detail-card-entry,
  .sales-order-detail-operation-list article {
    flex-direction: column;
  }

  .sales-order-detail-item__unit,
  .sales-order-detail-operation-list article > div:last-child {
    justify-content: flex-start;
    justify-items: start;
    text-align: left;
  }
}
</style>
