<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createCashierV3CommandId, formatMoney } from '@/services/cashierV3Bridge'
import { openSalesOrderReceiptPrint, salesOrderReceiptFromCheckout } from '@/services/salesOrderReceiptPrint'

const props = defineProps({
  checkout: {
    type: Object,
    default: () => ({})
  },
  isServiceOrder: {
    type: Boolean,
    default: false
  },
  businessSources: {
    type: Array,
    default: () => []
  },
  businessSourcesLoading: {
    type: Boolean,
    default: false
  },
  businessSourcesLoadError: {
    type: String,
    default: ''
  },
  businessSourceSaving: {
    type: Boolean,
    default: false
  },
  salesDateMax: {
    type: String,
    default: ''
  },
  salesDateSaving: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'close',
  'completed',
  'request',
  'business-source-change',
  'retry-business-sources',
  'sales-date-change'
])

const localStep = ref(1)
const submitCommandId = ref(null)
const isSubmitRequested = ref(false)
const editingPaymentLineId = ref(null)
const dialogRoot = ref(null)
const resultHeading = ref(null)
const paymentLineDraft = ref({
  amount: '',
  externalTransactionNo: '',
  remark: ''
})
// Amount typing is local until the cashier explicitly finishes the field.
// The server remains the only settlement authority; this state only keeps the
// visible selected/remaining amounts in step with the input before that draft
// mutation has returned its rebuilt checkout projection.
const paymentLineAmountDrafts = ref({})
const paymentLineAmountErrors = ref({})
const balancePaymentPromptOpen = ref(false)
const receiptPrintError = ref('')
const pendingPrimarySourceId = ref(0)
const salesDateDraft = ref('')
const salesDateReason = ref('')
let previouslyFocusedElement = null
let backgroundShell = null
let backgroundShellWasInert = false
let backgroundShellHadInertAttribute = false
let backgroundShellAriaHidden = null

const isDebtRepayment = computed(() => props.checkout.businessType === 'debt_repayment')
const allowedPrimaryActions = new Set(['collect_payment', 'complete_service', 'collect_and_complete'])
const composition = computed(() => isRecord(props.checkout.composition) ? props.checkout.composition : {})
const hasSaleLines = computed(() => composition.value.hasSale === true || composition.value.lineRoles?.includes('sale'))
const hasEntitlementLines = computed(() => composition.value.hasEntitlement === true || composition.value.lineRoles?.includes('entitlement_service'))
const primaryAction = computed(() => allowedPrimaryActions.has(composition.value.primaryAction)
  ? composition.value.primaryAction
  : isDebtRepayment.value
    ? 'collect_payment'
    : '')
const primaryActionLabel = computed(() => {
  if (isDebtRepayment.value) return '确认还款'
  if (typeof composition.value.primaryActionLabel === 'string' && composition.value.primaryActionLabel.trim()) {
    return composition.value.primaryActionLabel.trim()
  }
  return {
    collect_payment: '确认收款',
    complete_service: '确认完成服务',
    collect_and_complete: '收款并完成服务'
  }[primaryAction.value] || '确认结账'
})
const steps = computed(() => {
  if (isDebtRepayment.value) {
    return [
      { key: 'order', number: 1, label: '确认欠款' },
      { key: 'payment', number: 2, label: '收款信息' },
      { key: 'final', number: 3, label: '确认还款' },
      { key: 'result', number: 4, label: '处理结果' }
    ]
  }
  const serverSteps = Array.isArray(composition.value.steps) ? composition.value.steps : []
  const valid = serverSteps.length >= 3 && serverSteps.every((step) => (
    isRecord(step)
    && ['order', 'payment', 'final', 'result'].includes(step.key)
    && Number.isInteger(Number(step.number))
    && typeof step.label === 'string'
    && step.label.trim()
  ))
  if (valid) return serverSteps.map((step) => ({ ...step, number: Number(step.number) }))
  return [
    { key: 'order', number: 1, label: isDebtRepayment.value ? '确认欠款' : '确认订单' },
    { key: 'payment', number: 2, label: '收款信息' },
    { key: 'final', number: 3, label: isDebtRepayment.value ? '确认还款' : '确认结账' },
    { key: 'result', number: 4, label: '处理结果' }
  ]
})
const editableSteps = computed(() => steps.value.filter((step) => step.key !== 'result'))

const checkoutStatus = computed(() => normalizeCheckoutStatus(props.checkout.status))
const isProcessing = computed(() => checkoutStatus.value === 'processing')
const isPendingConfirmation = computed(() => checkoutStatus.value === 'pending_confirmation')
const isResultUnknown = computed(() => checkoutStatus.value === 'result_unknown')
const isUncertain = computed(() => isPendingConfirmation.value || isResultUnknown.value)
const isFailed = computed(() => checkoutStatus.value === 'failed')
const isSucceeded = computed(() => checkoutStatus.value === 'succeeded')
const isPaymentSucceededServicePending = computed(() => checkoutStatus.value === 'payment_succeeded_service_pending')
const isResultStep = computed(() => isProcessing.value || isUncertain.value || isFailed.value || isSucceeded.value || isPaymentSucceededServicePending.value)
const isSubmissionLocked = computed(() => isSubmitRequested.value || isProcessing.value || isUncertain.value)
const resultStepNumber = computed(() => steps.value.find((step) => step.key === 'result')?.number || 4)
const currentStep = computed(() => (isResultStep.value ? resultStepNumber.value : localStep.value))
const currentStepPosition = computed(() => {
  const index = steps.value.findIndex((step) => step.number === currentStep.value)
  return index >= 0 ? index : 0
})
const checkoutOrderLines = computed(() => {
  if (Array.isArray(props.checkout.orderLines)) return props.checkout.orderLines
  if (Array.isArray(props.checkout.orderSnapshot?.lines)) return props.checkout.orderSnapshot.lines
  if (Array.isArray(props.checkout.snapshot?.orderLines)) return props.checkout.snapshot.orderLines
  return []
})
const checkoutSummary = computed(() => {
  if (isRecord(props.checkout.summary)) return props.checkout.summary
  if (isRecord(props.checkout.orderSummary)) return props.checkout.orderSummary
  if (isRecord(props.checkout.orderSnapshot?.summary)) return props.checkout.orderSnapshot.summary
  if (isRecord(props.checkout.snapshot?.summary)) return props.checkout.snapshot.summary
  return {}
})
const checkoutMember = computed(() => {
  if (isRecord(props.checkout.member)) return props.checkout.member
  if (isRecord(props.checkout.orderSnapshot?.member)) return props.checkout.orderSnapshot.member
  if (isRecord(props.checkout.snapshot?.member)) return props.checkout.snapshot.member
  return null
})
const hasAuthoritativeOrderSnapshot = computed(() => (
  checkoutOrderLines.value.length > 0
  && hasOwn(checkoutSummary.value, 'receivableAmount')
  && hasOwn(checkoutSummary.value, 'discountAmount')
))
const payment = computed(() => props.checkout.payment || {})
const businessSourceRoots = computed(() => Array.isArray(props.businessSources)
  ? props.businessSources.filter((source) => Number(source?.id) > 0)
  : [])
const selectedPrimarySourceId = computed(() => Number(pendingPrimarySourceId.value || props.checkout.primarySourceId || 0))
const selectedPrimarySource = computed(() => businessSourceRoots.value.find((source) => Number(source.id) === selectedPrimarySourceId.value) || null)
const selectedSecondarySourceId = computed(() => (
  pendingPrimarySourceId.value
  && Number(pendingPrimarySourceId.value) !== Number(props.checkout.primarySourceId || 0)
    ? 0
    : Number(props.checkout.secondarySourceId || 0)
))
const selectedSecondarySources = computed(() => Array.isArray(selectedPrimarySource.value?.children)
  ? selectedPrimarySource.value.children.filter((source) => Number(source?.id) > 0)
  : [])
const selectedPrimaryRequiresSecondary = computed(() => (
  selectedPrimarySource.value?.requireSecondary === true
  || Number(selectedPrimarySource.value?.requireSecondary) === 1
))
const customerSourceRequired = computed(() => (
  (props.checkout.sourceEnabled === true || Number(props.checkout.sourceEnabled) === 1)
  && props.checkout.sourceSelectable !== false
))
const customerSourceMissing = computed(() => (
  customerSourceRequired.value
  && (!selectedPrimarySourceId.value
    || (selectedPrimaryRequiresSecondary.value && !selectedSecondarySourceId.value))
))
const salesDateIsHistorical = computed(() => (
  salesDateDraft.value !== ''
  && props.salesDateMax !== ''
  && salesDateDraft.value < props.salesDateMax
))
const salesDateIsDirty = computed(() => salesDateDraft.value !== String(props.checkout.businessDate || ''))
const canSaveSalesDate = computed(() => (
  salesDateIsDirty.value
  && /^\d{4}-\d{2}-\d{2}$/.test(salesDateDraft.value)
  && (!props.salesDateMax || salesDateDraft.value <= props.salesDateMax)
  && (!salesDateIsHistorical.value || salesDateReason.value.trim() !== '')
  && !props.salesDateSaving
))
const selectedPaymentLines = computed(() => Array.isArray(payment.value.selectedLines) ? payment.value.selectedLines : [])
const hasBalancePayment = computed(() => selectedPaymentLines.value.some((line) => line?.kind === 'balance_deduction'))
const hasNonBalancePayment = computed(() => selectedPaymentLines.value.some((line) => line?.kind !== 'balance_deduction'))
const paymentMethods = computed(() => Array.isArray(payment.value.methods) ? payment.value.methods : [])
const paymentSummary = computed(() => payment.value.summary || props.checkout.paymentSummary || {})
const displayedPaymentSummary = computed(() => {
  const summary = paymentSummary.value
  const drafts = paymentLineAmountDrafts.value
  if (!Object.keys(drafts).length) return summary

  const selectedAmount = Number(summary.selectedAmount)
  const remainingAmount = Number(summary.remainingAmount)
  if (!Number.isFinite(selectedAmount) || !Number.isFinite(remainingAmount)) return summary

  let selectedDelta = 0
  for (const line of selectedPaymentLines.value) {
    const draft = drafts[paymentLineAmountKey(line)]
    if (!draft) continue
    const currentAmount = authoritativeWholeYuanAmount(line.amount)
    const draftAmount = wholeYuanAmount(draft.value)
    if (currentAmount === null || draftAmount === null) continue
    selectedDelta += draftAmount - currentAmount
  }
  if (!selectedDelta) return summary

  const previewRemaining = remainingAmount - selectedDelta
  return {
    ...summary,
    selectedAmount: selectedAmount + selectedDelta,
    remainingAmount: Math.max(0, previewRemaining),
    overpaidAmount: Math.max(0, Number(summary.overpaidAmount || 0) - remainingAmount + selectedDelta)
  }
})
const hasPendingPaymentLineAmountDraft = computed(() => Object.keys(paymentLineAmountDrafts.value).length > 0)
const hasNonPositivePaymentLine = computed(() => selectedPaymentLines.value.some((line) => {
  const amount = authoritativeWholeYuanAmount(line?.amount)
  return amount === null || amount <= 0
}))
const paymentAmountValidation = computed(() => {
  const summary = displayedPaymentSummary.value
  const receivable = Number(summary.receivableAmount)
  const selected = Number(summary.selectedAmount)
  if (!Number.isFinite(receivable) || !Number.isFinite(selected)) {
    return { state: 'unavailable', message: '' }
  }
  const delta = receivable - selected
  if (delta > 0) return { state: 'underpaid', message: `还差 ${formatMoney(delta)}` }
  if (delta < 0) return { state: 'overpaid', message: `超出 ${formatMoney(Math.abs(delta))}` }
  if (hasNonPositivePaymentLine.value) return { state: 'invalid', message: '每种收款方式的金额必须大于 0。' }
  return { state: 'balanced', message: '收款金额已与应收金额相等。' }
})
const toggleBalancePayment = () => {
  if (hasBalancePayment.value) {
    request('remove-balance-payment')
    return
  }
  const remaining = Number(displayedPaymentSummary.value.remainingAmount)
  if (hasNonBalancePayment.value && Number.isFinite(remaining) && remaining <= 0) {
    balancePaymentPromptOpen.value = true
    return
  }
  request('open-balance-payment')
}

function closeBalancePaymentPrompt() {
  balancePaymentPromptOpen.value = false
}
const isPaymentDraftReady = computed(() => (
  hasAuthoritativePaymentSnapshot.value
  && !hasPendingPaymentLineAmountDraft.value
  && !hasNonPositivePaymentLine.value
  && paymentAmountValidation.value.state === 'balanced'
))
const paymentResultLines = computed(() => Array.isArray(payment.value.resultLines) ? payment.value.resultLines : selectedPaymentLines.value)
const finalChanges = computed(() => Array.isArray(props.checkout.finalChanges) ? props.checkout.finalChanges : [])
const childResults = computed(() => isRecord(props.checkout.childResults) ? props.checkout.childResults : {})
const hasAuthoritativePaymentSnapshot = computed(() => (
  hasAuthoritativeOrderSnapshot.value
  && Array.isArray(payment.value.methods)
  && Array.isArray(payment.value.selectedLines)
  && isRecord(paymentSummary.value)
  && hasOwn(paymentSummary.value, 'receivableAmount')
  && hasOwn(paymentSummary.value, 'selectedAmount')
  && hasOwn(paymentSummary.value, 'remainingAmount')
))
const hasAuthoritativeFinalSnapshot = computed(() => (
  hasAuthoritativePaymentSnapshot.value
  && hasOwn(props.checkout, 'debtAmount')
  && hasOwn(props.checkout, 'cashPerformanceAmount')
  && hasOwn(props.checkout, 'balancePaymentAmount')
  && Array.isArray(props.checkout.finalChanges)
))
const hasCurrentStepSnapshot = computed(() => {
  if (currentStep.value === 1) return hasAuthoritativeOrderSnapshot.value
  if (currentStep.value === 2) return hasAuthoritativePaymentSnapshot.value
  if (currentStep.value === 3) return hasAuthoritativeFinalSnapshot.value
  return true
})
const checkoutSnapshotWarning = computed(() => {
  if (!hasAuthoritativeOrderSnapshot.value) return isDebtRepayment.value ? '欠款明细和金额' : '订单明细和金额'
  if (!hasAuthoritativePaymentSnapshot.value) return '收款方式和收款汇总'
  return isDebtRepayment.value ? '最终还款金额和变更结果' : '最终结账金额和变更结果'
})
const serviceStartOptions = computed(() => Array.isArray(props.checkout.serviceStartOptions) ? props.checkout.serviceStartOptions : [])
const allowedServiceStartOptionIds = new Set([
  'return_to_start_service',
  'complete_service_and_checkout'
])
const safeServiceStartOptions = computed(() => serviceStartOptions.value.filter((option) => (
  isRecord(option)
  && allowedServiceStartOptionIds.has(String(option.id || option.optionId || ''))
  && typeof option.label === 'string'
  && option.label.trim()
)))
const canPrintReceipt = computed(() => (
  isSucceeded.value
  && !isDebtRepayment.value
  && Boolean(String(props.checkout.salesOrderNo || '').trim())
  && hasAuthoritativeOrderSnapshot.value
))
const hasResultLockedPaymentLine = computed(() => selectedPaymentLines.value.some((line) => paymentLineIsLocked(line)))
const canReturnToPaymentEdit = computed(() => (
  isFailed.value
  && props.checkout.canReturnToPaymentEdit === true
  && !hasResultLockedPaymentLine.value
))
const canReturnToCashierEdit = computed(() => (
  canReturnToPaymentEdit.value
  && props.checkout.canReturnToCashierEdit === true
))
const canRetryCheckout = computed(() => (
  isFailed.value
  && props.checkout.canRetry === true
  && !hasResultLockedPaymentLine.value
))
const canContinuePartialPaymentRecovery = computed(() => (
  isFailed.value
  && props.checkout.canContinuePartialPaymentRecovery === true
))
const isPartialPaymentRecovery = computed(() => (
  canContinuePartialPaymentRecovery.value
  || props.checkout.partialPaymentSucceeded === true
  || paymentResultLines.value.some((line) => paymentLineStatusClass(line) === 'payment-status--succeeded')
    && paymentResultLines.value.some((line) => paymentLineStatusClass(line) === 'payment-status--failed')
))
const canCloseOverlay = computed(() => (
  isSucceeded.value
  || (isFailed.value && props.checkout.canClose === true)
  || (!isResultStep.value && !isSubmissionLocked.value)
))
const checkoutRequestIdentity = computed(() => (
  props.checkout.checkoutRequestId
  || props.checkout.requestId
  || props.checkout.requestNo
  || ''
))
const recoveredCheckoutIdempotencyKey = computed(() => {
  const key = String(props.checkout.originalIdempotencyKey || '')
  return /^CHECKOUT-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(key)
    ? key
    : ''
})
const originalCheckoutIdempotencyKey = computed(() => (
  submitCommandId.value
  || recoveredCheckoutIdempotencyKey.value
  || ''
))
const canQueryCheckoutResult = computed(() => (
  (isUncertain.value || isPaymentSucceededServicePending.value)
  && Boolean(checkoutRequestIdentity.value)
  && Boolean(originalCheckoutIdempotencyKey.value)
))
const resultTitle = computed(() => {
  if (isSucceeded.value) {
    if (isDebtRepayment.value) return '还款成功'
    if (props.checkout.completionLabel) return props.checkout.completionLabel
    if (props.checkout.completionKind === 'service_completed') return '服务完成'
    if (props.checkout.completionKind === 'no_payment') return '无需收款，结账完成'
    if (props.checkout.completionKind === 'debt_recorded') return '已挂账，结账完成'
    return '支付成功'
  }
  if (isPaymentSucceededServicePending.value) return '收款成功，服务待处理'
  if (isFailed.value && isPartialPaymentRecovery.value) return '部分收款已成功'
  if (isFailed.value) return '支付失败'
  if (isResultUnknown.value) return '支付结果暂时未知'
  if (isPendingConfirmation.value) return '正在确认原支付结果'
  return '正在处理'
})
const resultDescription = computed(() => {
  if (isSucceeded.value) return props.checkout.completionDescription || (isDebtRepayment.value ? '本次还款已完成，欠款余额已经重新读取。' : '本单已正式完成，可以继续后续操作。')
  if (isFailed.value && isPartialPaymentRecovery.value) {
    return props.checkout.failureReason || '成功款项不会重复收取，请继续处理剩余收款。'
  }
  if (isFailed.value) return props.checkout.failureReason || '本次支付未成功，已保留原来的收款信息。'
  if (isPaymentSucceededServicePending.value) {
    return props.checkout.completionDescription || '本次收款已经成功，只能继续处理原结账请求，禁止再次收款。'
  }
  if (isResultUnknown.value) return `顾客可能已扣款。只能查询这一次原支付请求，禁止重新${isDebtRepayment.value ? '还款' : '结账'}。`
  if (isPendingConfirmation.value) return `顾客可能已扣款，正在确认原支付结果，请勿关闭或重复${isDebtRepayment.value ? '还款' : '结账'}。`
  return props.checkout.processingLong ? '仍在处理中，请稍候。' : '正在处理，请勿关闭或重复操作。'
})
const nextLabel = computed(() => {
  const currentIndex = editableSteps.value.findIndex((step) => step.number === currentStep.value)
  const nextStep = currentIndex >= 0 ? editableSteps.value[currentIndex + 1] : null
  if (nextStep) return `下一步：${nextStep.label}`
  return isSubmissionLocked.value ? '正在提交…' : primaryActionLabel.value
})

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function hasOwn(record, key) {
  return Object.prototype.hasOwnProperty.call(record, key)
}

function normalizeCheckoutStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  // 最终校验完成但尚未提交的恢复态。它没有发生扣款，重新打开时应回到
  // 最后确认步骤继续使用同一结账请求，不能显示为“支付结果未知”。
  if (['ready_for_submit', 'ready-for-submit', 'prepared_for_submit'].includes(status)) return 'editing'
  if (['processing', 'submitting', 'paying', '处理中', '支付中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) {
    return 'pending_confirmation'
  }
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', '失败'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (['payment_succeeded_service_pending', 'service_pending', '收款成功服务待处理'].includes(status)) {
    return 'payment_succeeded_service_pending'
  }
  if (!status || status === 'editing') return 'editing'
  return 'result_unknown'
}

watch(
  () => props.checkout.activeStep,
  (activeStep) => {
    if ([1, 2, 3].includes(activeStep)) {
      localStep.value = activeStep
    }
  },
  { immediate: true }
)

watch(
  () => [props.checkout.primarySourceId, props.checkout.secondarySourceId],
  () => {
    pendingPrimarySourceId.value = 0
  }
)

watch(
  () => props.checkout.businessDate,
  (businessDate) => {
    salesDateDraft.value = String(businessDate || '')
    salesDateReason.value = String(props.checkout.supplement?.reason || '')
  },
  { immediate: true }
)

function request(action, payload = {}) {
  emit('request', { action, payload })
}

function chooseBusinessSourcePrimary(source) {
  if (props.businessSourceSaving || Number(source?.id) <= 0) return
  pendingPrimarySourceId.value = Number(source.id)
  const children = Array.isArray(source.children) ? source.children.filter((item) => Number(item?.id) > 0) : []
  const requiresSecondary = source.requireSecondary === true || Number(source.requireSecondary) === 1
  if (!requiresSecondary) {
    emit('business-source-change', { primarySourceId: Number(source.id), secondarySourceId: 0 })
  } else if (!children.length) {
    pendingPrimarySourceId.value = 0
  }
}

function chooseBusinessSourceSecondary(secondarySourceId) {
  if (props.businessSourceSaving || !selectedPrimarySourceId.value) return
  emit('business-source-change', {
    primarySourceId: selectedPrimarySourceId.value,
    secondarySourceId: Number(secondarySourceId || 0)
  })
}

function saveSalesDate() {
  if (!canSaveSalesDate.value) return
  emit('sales-date-change', {
    businessDate: salesDateDraft.value,
    reason: salesDateIsHistorical.value ? salesDateReason.value.trim() : ''
  })
}

function paymentLineStatus(line = {}) {
  return line.status || line.paymentStatus || line.resultStatus || '待收款'
}

function checkoutLineRole(line = {}) {
  return ['sale', 'entitlement_service'].includes(line.lineRole) ? line.lineRole : 'unknown'
}

function isEntitlementCheckoutLine(line = {}) {
  return checkoutLineRole(line) === 'entitlement_service'
}

function checkoutLineServiceTags(line = {}) {
  const tags = []
  if (line.isExperience === true || Number(line.isExperience ?? line.is_experience) === 1) tags.push('体验')
  const serviceObject = String(line.serviceObject ?? line.service_object ?? '').toLowerCase()
  if (['self', '本人'].includes(serviceObject)) tags.push('本人')
  if (['friend', '朋友'].includes(serviceObject)) tags.push('朋友')
  return tags
}

function childResultStatusClass(result = {}) {
  const status = String(result.status || '').toLowerCase()
  if (['succeeded', 'success', 'completed'].includes(status)) return 'checkout-child-result--succeeded'
  if (['pending', 'processing', 'result_unknown'].includes(status)) return 'checkout-child-result--pending'
  if (['failed', 'error'].includes(status)) return 'checkout-child-result--failed'
  return 'checkout-child-result--neutral'
}

function childResultStatusLabel(result = {}) {
  if (result.message) return result.message
  const status = String(result.status || '').toLowerCase()
  if (['succeeded', 'success', 'completed'].includes(status)) return '已完成'
  if (['pending', 'processing'].includes(status)) return '待处理'
  if (status === 'result_unknown') return '结果待确认'
  if (['failed', 'error'].includes(status)) return '处理失败'
  return '待确认'
}

function paymentLineStatusClass(line = {}) {
  const status = paymentLineStatus(line)
  if (['成功', '已成功', '已支付', 'succeeded', 'success'].includes(status)) return 'payment-status--succeeded'
  if (['处理中', '结果确认中', '结果未知', 'processing', 'pending', 'result_pending', 'pending_confirmation', 'result_unknown'].includes(status)) return 'payment-status--pending'
  if (['失败', '已失败', 'failed'].includes(status)) return 'payment-status--failed'
  return 'payment-status--editing'
}

function paymentLineIsLocked(line = {}) {
  if (line.locked === true || line.canEdit === false) return true
  return ['成功', '已成功', '已支付', '处理中', '结果确认中', '结果未知', 'succeeded', 'success', 'processing', 'pending', 'result_pending', 'pending_confirmation', 'result_unknown'].includes(paymentLineStatus(line))
}

function canEditPaymentLine(line = {}) {
  if (line.canEdit === false || line.editable === false) return false
  return !paymentLineIsLocked(line)
}

function canEditPaymentLineDetails(line = {}) {
  return line.kind !== 'balance_deduction' && canEditPaymentLine(line)
}

function canRemovePaymentLine(line = {}) {
  if (line.canRemove === false || line.removable === false) return false
  return !paymentLineIsLocked(line)
}

function openPaymentLineEditor(line = {}) {
  if (!canEditPaymentLineDetails(line)) return
  editingPaymentLineId.value = line.id
  paymentLineDraft.value = {
    amount: line.amount ?? '',
    externalTransactionNo: line.externalTransactionNo || line.externalTradeNo || '',
    remark: line.remark || line.note || ''
  }
}

function closePaymentLineEditor() {
  editingPaymentLineId.value = null
  paymentLineDraft.value = { amount: '', externalTransactionNo: '', remark: '' }
}

function addPaymentMethod(method = {}) {
  if (method.canAdd === false || !method.id) return
  // Selecting a method always adds an editable draft line. The cashier chooses
  // all methods first and then adjusts their amounts; the only gate is the
  // aggregate amount check before advancing to final confirmation.
  request('add-payment-method', { paymentMethodId: method.id })
}

function savePaymentLine(line) {
  if (!line?.id) return
  request('update-payment-line', {
    paymentLineId: line.id,
    amount: paymentLineDraft.value.amount,
    externalTransactionNo: paymentLineDraft.value.externalTransactionNo,
    remark: paymentLineDraft.value.remark
  })
  closePaymentLineEditor()
}

function savePaymentLineAmount(line, amount) {
  if (!line?.id || !canEditPaymentLine(line)) return
  const key = paymentLineAmountKey(line)
  const draft = paymentLineAmountDrafts.value[key]
  if (!draft || draft.pending === true) return
  const normalizedAmount = normalizeWholeYuanInput(amount)
  if (normalizedAmount === draft.baseAmount) {
    clearPaymentLineAmountDraft(key)
    return
  }
  // A new bookkeeping payment starts at the server-calculated remaining
  // receivable. An explicit zero remains useful while typing, but a saved
  // payment must be a positive whole yuan.
  if (!/^[1-9]\d*$/.test(normalizedAmount)) {
    clearPaymentLineAmountDraft(key)
    setPaymentLineAmountError(key, '收款金额必须为正整数，已恢复原金额。')
    return
  }
  paymentLineAmountDrafts.value = {
    ...paymentLineAmountDrafts.value,
    [key]: { ...draft, value: normalizedAmount, pending: true }
  }
  if (line.kind === 'balance_deduction') {
    request('update-balance-payment', { amount: normalizedAmount })
    return
  }
  request('update-payment-line', {
    paymentLineId: line.id,
    amount: normalizedAmount,
    externalTransactionNo: line.externalTransactionNo || line.externalTradeNo || '',
    remark: line.remark || line.note || ''
  })
}

function paymentLineInputAmount(line = {}) {
  const draft = paymentLineAmountDrafts.value[paymentLineAmountKey(line)]
  if (draft) return draft.value
  return wholeYuanString(line.amount)
}

function paymentLineAmountKey(line = {}) {
  return String(line?.id || '')
}

function wholeYuanAmount(value) {
  const raw = String(value ?? '').trim()
  if (!/^(0|[1-9]\d*)$/.test(raw)) return null
  const amount = Number(raw)
  return Number.isSafeInteger(amount) ? amount : null
}

function authoritativeWholeYuanAmount(value) {
  const raw = String(value ?? '').trim()
  const match = raw.match(/^(0|[1-9]\d*)(?:\.00)?$/)
  if (!match) return null
  const amount = Number(match[1])
  return Number.isSafeInteger(amount) ? amount : null
}

function wholeYuanString(value) {
  const amount = authoritativeWholeYuanAmount(value)
  return amount === null ? '' : String(amount)
}

function normalizeWholeYuanInput(value) {
  return String(value ?? '').trim()
}

function updatePaymentLineAmountDraft(line, event) {
  const key = paymentLineAmountKey(line)
  if (!key || paymentLineAmountDrafts.value[key]?.pending === true) return
  const normalizedAmount = normalizeWholeYuanInput(event?.target?.value)
  paymentLineAmountDrafts.value = {
    ...paymentLineAmountDrafts.value,
    [key]: {
      baseAmount: wholeYuanString(line.amount),
      value: normalizedAmount,
      pending: false
    }
  }
  clearPaymentLineAmountError(key)
}

function clearPaymentLineAmountDraft(key) {
  if (!paymentLineAmountDrafts.value[key]) return
  const { [key]: ignored, ...rest } = paymentLineAmountDrafts.value
  paymentLineAmountDrafts.value = rest
}

function setPaymentLineAmountError(key, message) {
  paymentLineAmountErrors.value = { ...paymentLineAmountErrors.value, [key]: message }
}

function clearPaymentLineAmountError(key) {
  if (!paymentLineAmountErrors.value[key]) return
  const { [key]: ignored, ...rest } = paymentLineAmountErrors.value
  paymentLineAmountErrors.value = rest
}

function handleCheckoutDraftMutationResult(event) {
  const detail = event?.detail || {}
  const action = String(detail.action || '')
  if (!['update-payment-line', 'update-balance-payment'].includes(action)) return
  const key = action === 'update-balance-payment'
    ? 'balance-deduction'
    : String(detail.payload?.paymentLineId || '')
  const draft = paymentLineAmountDrafts.value[key]
  if (!draft || draft.pending !== true) return
  if (['success', 'succeeded'].includes(String(detail.status || ''))) {
    clearPaymentLineAmountDraft(key)
    clearPaymentLineAmountError(key)
    return
  }
  clearPaymentLineAmountDraft(key)
  setPaymentLineAmountError(key, detail.message || '收款金额没有保存，已恢复原金额。')
}

function removePaymentLine(line) {
  if (!canRemovePaymentLine(line)) return
  if (line.removalAction === 'remove-balance-payment' || line.kind === 'balance_deduction') {
    request('remove-balance-payment')
    return
  }
  request('remove-payment-line', { paymentLineId: line.id })
}

function printReceipt() {
  receiptPrintError.value = ''
  const result = openSalesOrderReceiptPrint(salesOrderReceiptFromCheckout(props.checkout))
  if (!result.ok) receiptPrintError.value = result.message
}

function handleServiceStartOption(option) {
  const optionId = String(option?.id || option?.optionId || '')
  if (!allowedServiceStartOptionIds.has(optionId)) return
  if (optionId === 'return_to_start_service') {
    emit('close')
    return
  }
  request('prepare-service-completion', {
    completionIntent: 'checkout',
    serviceStartOption: optionId
  })
}

function goPrevious() {
  if (isResultStep.value) return
  const currentIndex = editableSteps.value.findIndex((step) => step.number === currentStep.value)
  if (currentIndex <= 0) return
  localStep.value = editableSteps.value[currentIndex - 1].number
}

function goNext() {
  if (!hasCurrentStepSnapshot.value) return
  if (currentStep.value === 1) {
    if (customerSourceMissing.value) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', message: '请选择客户来源后再继续。' }
      }))
      return
    }
    if (salesDateIsDirty.value) {
      window.dispatchEvent(new CustomEvent('cashier-v3:ui-result', {
        detail: { status: 'failed', message: '销售日期已修改，请先保存销售日期。' }
      }))
      return
    }
  }
  // Do not let the final-preparation command race a just-finished amount
  // edit. The parent serializes each draft write and this component resumes
  // navigation as soon as its authoritative projection arrives.
  if (currentStep.value === 2 && !isPaymentDraftReady.value) return
  const currentIndex = editableSteps.value.findIndex((step) => step.number === currentStep.value)
  const nextStep = currentIndex >= 0 ? editableSteps.value[currentIndex + 1] : null
  if (nextStep) {
    localStep.value = nextStep.number
    return
  }
  if (isSubmissionLocked.value) return
  if (!submitCommandId.value) {
    submitCommandId.value = recoveredCheckoutIdempotencyKey.value
      || createCashierV3CommandId('CHECKOUT')
  }
  isSubmitRequested.value = true
  request('submit-checkout', { idempotencyKey: submitCommandId.value })
}

function returnToPaymentEdit() {
  if (!canReturnToPaymentEdit.value) return
  submitCommandId.value = null
  isSubmitRequested.value = false
  localStep.value = 2
  request('return-to-payment-edit')
}

function returnToCashierEdit() {
  if (!canReturnToCashierEdit.value) return
  submitCommandId.value = null
  isSubmitRequested.value = false
  request('return-to-payment-edit', { closeAfterEditReturn: true })
}

function handleCheckoutReturnedToPaymentEdit() {
  submitCommandId.value = null
  isSubmitRequested.value = false
  localStep.value = 2
}

function retryCheckout() {
  if (!canRetryCheckout.value) return
  submitCommandId.value = createCashierV3CommandId('CHECKOUT')
  isSubmitRequested.value = true
  request('retry-checkout', { idempotencyKey: submitCommandId.value })
}

function queryOriginalCheckoutResult() {
  if (!canQueryCheckoutResult.value) return
  request('query-checkout-result', {
    checkoutRequestId: props.checkout.checkoutRequestId || props.checkout.requestId,
    requestNo: props.checkout.requestNo,
    originalIdempotencyKey: originalCheckoutIdempotencyKey.value
  })
}

function finishCheckoutAndReturn() {
  if (!isSucceeded.value) return
  emit('completed')
}

function requestClose() {
  if (!canCloseOverlay.value) return
  emit('close')
}

function focusableDialogElements() {
  const root = dialogRoot.value
  if (!root) return []
  return Array.from(root.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )).filter((element) => (
    element instanceof HTMLElement
    && element.getAttribute('aria-hidden') !== 'true'
    && element.getClientRects().length > 0
  ))
}

function handleDialogKeydown(event) {
  if (event.key === 'Escape') {
    if (canCloseOverlay.value) {
      event.preventDefault()
      requestClose()
    }
    return
  }
  if (event.key !== 'Tab') return

  const focusable = focusableDialogElements()
  if (!focusable.length) {
    event.preventDefault()
    dialogRoot.value?.focus()
    return
  }

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement
  if (event.shiftKey && (active === first || active === dialogRoot.value || !dialogRoot.value?.contains(active))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || !dialogRoot.value?.contains(active))) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  checkoutStatus,
  async (status, previousStatus) => {
    if (status === 'failed' || status === 'succeeded') {
      isSubmitRequested.value = false
    }
    if (
      previousStatus !== undefined
      && status !== previousStatus
      && ['processing', 'pending_confirmation', 'result_unknown', 'payment_succeeded_service_pending', 'failed', 'succeeded'].includes(status)
    ) {
      await nextTick()
      resultHeading.value?.focus()
    }
  },
  { immediate: true }
)

watch(
  selectedPaymentLines,
  (lines) => {
    if (editingPaymentLineId.value && !lines.some((line) => line.id === editingPaymentLineId.value)) {
      closePaymentLineEditor()
    }
    for (const [key, draft] of Object.entries(paymentLineAmountDrafts.value)) {
      const line = lines.find((item) => paymentLineAmountKey(item) === key)
      if (!line) {
        clearPaymentLineAmountDraft(key)
        continue
      }
      const authoritativeAmount = wholeYuanString(line.amount)
      if (authoritativeAmount === draft.value) {
        clearPaymentLineAmountDraft(key)
        clearPaymentLineAmountError(key)
      } else if (draft.pending === true && authoritativeAmount !== draft.baseAmount) {
        clearPaymentLineAmountDraft(key)
        setPaymentLineAmountError(key, '收款金额已被更新，已显示最新金额。')
      }
    }
  },
  { deep: true }
)

onMounted(async () => {
  window.addEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)
  window.addEventListener('cashier-v3:checkout-returned-to-payment-edit', handleCheckoutReturnedToPaymentEdit)
  previouslyFocusedElement = document.activeElement instanceof HTMLElement
    ? document.activeElement
    : null
  backgroundShell = document.querySelector('.cashier-shell')
  if (backgroundShell instanceof HTMLElement) {
    backgroundShellWasInert = backgroundShell.inert === true
    backgroundShellHadInertAttribute = backgroundShell.hasAttribute('inert')
    backgroundShellAriaHidden = backgroundShell.getAttribute('aria-hidden')
    backgroundShell.inert = true
    backgroundShell.setAttribute('inert', '')
    backgroundShell.setAttribute('aria-hidden', 'true')
  }
  await nextTick()
  if (isResultStep.value) {
    resultHeading.value?.focus()
  } else {
    dialogRoot.value?.focus()
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:checkout-draft-mutation-result', handleCheckoutDraftMutationResult)
  window.removeEventListener('cashier-v3:checkout-returned-to-payment-edit', handleCheckoutReturnedToPaymentEdit)
  if (backgroundShell instanceof HTMLElement) {
    backgroundShell.inert = backgroundShellWasInert
    if (!backgroundShellHadInertAttribute) backgroundShell.removeAttribute('inert')
    if (backgroundShellAriaHidden === null) {
      backgroundShell.removeAttribute('aria-hidden')
    } else {
      backgroundShell.setAttribute('aria-hidden', backgroundShellAriaHidden)
    }
  }
  const restoreTarget = previouslyFocusedElement
  window.requestAnimationFrame(() => {
    if (restoreTarget?.isConnected) restoreTarget.focus()
  })
})
</script>

<template>
  <section
    ref="dialogRoot"
    class="checkout-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="checkout-dialog-title"
    aria-describedby="checkout-dialog-description"
    :aria-busy="isProcessing || isPendingConfirmation"
    tabindex="-1"
    @keydown="handleDialogKeydown"
  >
    <header class="checkout-overlay__header">
      <div>
        <h2 id="checkout-dialog-title">{{ isDebtRepayment ? '欠款还款' : '结账' }}</h2>
        <span id="checkout-dialog-description">{{ isDebtRepayment ? '请按步骤核对本条欠款和收款信息。' : hasEntitlementLines ? '请按步骤核对本次购买与使用权益。' : '请按步骤核对订单和收款信息。' }}</span>
      </div>
      <button
        type="button"
        class="button button--secondary"
        :disabled="!canCloseOverlay"
        @click="requestClose"
      >
        {{ isDebtRepayment ? '返回欠款明细' : '返回收银' }}
      </button>
    </header>

    <ol class="checkout-steps" :aria-label="isDebtRepayment ? '还款步骤' : '结账步骤'">
      <li
        v-for="(step, stepIndex) in steps"
        :key="step.number"
        :aria-current="currentStep === step.number ? 'step' : undefined"
        :class="{
          'checkout-steps__item--active': currentStep === step.number,
          'checkout-steps__item--done': currentStepPosition > stepIndex
        }"
      >
        <span aria-hidden="true">{{ currentStepPosition > stepIndex ? '✓' : stepIndex + 1 }}</span>
        <strong>
          <span v-if="currentStepPosition > stepIndex" class="sr-only">已完成：</span>
          {{ step.label }}
        </strong>
      </li>
    </ol>

    <main class="checkout-overlay__content">
      <section v-if="!hasCurrentStepSnapshot && !isResultStep" class="checkout-change-warning">
        <strong>{{ isDebtRepayment ? '还款快照尚未完整加载' : '结账快照尚未完整加载' }}</strong>
        <span>{{ checkoutSnapshotWarning }}必须来自后端本次{{ isDebtRepayment ? '还款' : '结账' }}快照；完整加载前不能继续或提交。</span>
      </section>

      <section v-if="currentStep === 1" class="checkout-card checkout-order-confirmation">
        <div class="checkout-card__title">
          <h3>{{ isDebtRepayment ? '确认欠款' : hasEntitlementLines ? '确认本次内容' : '确认订单' }}</h3>
          <span v-if="checkoutMember" :title="`${checkoutMember.name || ''} · ${checkoutMember.phone || ''}`">{{ checkoutMember.name }} · {{ checkoutMember.phone }}</span>
          <span v-else>游客开单</span>
        </div>

        <div v-if="hasEntitlementLines" class="checkout-service-hint">本次包含会员已有权益；权益服务与销售收款分别处理，权益行不计入本次应付。</div>

        <section v-if="checkout.sourceEnabled" class="checkout-business-sources" aria-label="客户来源">
          <div class="checkout-business-sources__heading">
            <strong>客户来源</strong>
            <span v-if="checkout.sourceSelectable === false">{{ checkout.sourceLabel || '继承原订单来源' }}</span>
            <span v-else-if="businessSourceSaving">正在保存…</span>
            <span v-else>{{ checkout.sourceLabel || '请选择客户来源' }}</span>
          </div>
          <template v-if="checkout.sourceSelectable !== false">
            <div v-if="businessSourcesLoading" class="checkout-business-sources__state">正在加载客户来源…</div>
            <div v-else-if="businessSourcesLoadError" class="checkout-business-sources__state checkout-business-sources__state--error" role="alert">
              <span>{{ businessSourcesLoadError }}</span>
              <button type="button" class="button button--text" @click="emit('retry-business-sources')">重新加载</button>
            </div>
            <div v-else-if="businessSourceRoots.length" class="checkout-business-sources__choices" aria-label="客户来源">
              <button
                v-for="source in businessSourceRoots"
                :key="source.id"
                type="button"
                :class="{ 'is-selected': Number(source.id) === selectedPrimarySourceId }"
                :disabled="businessSourceSaving"
                @click="chooseBusinessSourcePrimary(source)"
              >{{ source.name }}</button>
            </div>
            <div v-else class="checkout-business-sources__state">当前没有可用的客户来源，请联系平台管理员配置后重试。</div>
            <div v-if="selectedPrimarySource && selectedSecondarySources.length" class="checkout-business-sources__secondary">
              <strong>二级来源<span v-if="selectedPrimaryRequiresSecondary">（必选）</span></strong>
              <div class="checkout-business-sources__choices" aria-label="二级客户来源">
                <button
                  v-if="!selectedPrimaryRequiresSecondary"
                  type="button"
                  :class="{ 'is-selected': !selectedSecondarySourceId }"
                  :disabled="businessSourceSaving"
                  @click="chooseBusinessSourceSecondary(0)"
                >不选二级</button>
                <button
                  v-for="source in selectedSecondarySources"
                  :key="source.id"
                  type="button"
                  :class="{ 'is-selected': Number(source.id) === selectedSecondarySourceId }"
                  :disabled="businessSourceSaving"
                  @click="chooseBusinessSourceSecondary(source.id)"
                >{{ source.name }}</button>
              </div>
            </div>
          </template>
        </section>

        <dl class="checkout-order-details checkout-order-details--source-date">
          <div v-if="checkout.businessDate" class="checkout-sales-date">
            <dt>销售日期</dt>
            <dd>
              <input v-model="salesDateDraft" type="date" :max="salesDateMax || undefined" :disabled="salesDateSaving" aria-label="销售日期">
              <input
                v-if="salesDateIsHistorical"
                v-model.trim="salesDateReason"
                type="text"
                maxlength="255"
                :disabled="salesDateSaving"
                aria-label="补单原因"
                placeholder="历史日期请填写补单原因"
                @keydown.enter.prevent="saveSalesDate"
              >
              <button v-if="salesDateIsDirty" type="button" class="button button--secondary" :disabled="!canSaveSalesDate" @click="saveSalesDate">
                {{ salesDateSaving ? '保存中…' : '保存日期' }}
              </button>
            </dd>
          </div>
        </dl>

        <div class="checkout-order-lines">
          <article v-for="line in checkoutOrderLines" :key="line.id" class="checkout-order-line">
            <div>
              <strong :title="line.name">{{ line.name }}</strong>
              <span v-if="line.serviceSource || line.serviceRole || isEntitlementCheckoutLine(line)">
                {{ [isEntitlementCheckoutLine(line) ? '本次使用权益' : '本次购买', line.entitlementSourceName, line.fullCardNo, line.serviceRole].filter(Boolean).join(' · ') }}
              </span>
              <span v-if="checkoutLineServiceTags(line).length">{{ checkoutLineServiceTags(line).join(' · ') }}</span>
            </div>
            <span>×{{ line.quantity || 1 }}</span>
            <strong v-if="!isEntitlementCheckoutLine(line)">{{ formatMoney(line.finalAmount ?? line.amount) }}</strong>
            <strong v-else>不计应付</strong>
          </article>
        </div>

        <dl class="checkout-order-details">
          <div v-if="checkout.orderNote"><dt>订单备注</dt><dd>{{ checkout.orderNote }}</dd></div>
        </dl>

        <section v-if="checkout.sourceInvalidMessage" class="checkout-change-warning">
          <strong>业务来源需要重新确认</strong>
          <span>{{ checkout.sourceInvalidMessage }}</span>
        </section>

        <section v-if="checkout.serviceStartResolutionRequired" class="checkout-service-start-resolution">
          <div>
            <strong>{{ checkout.serviceStartResolutionTitle || '本单服务项目尚未开始服务' }}</strong>
            <span>{{ checkout.serviceStartResolutionDescription || '请选择处理方式后再继续结账。' }}</span>
          </div>
          <div class="checkout-service-start-resolution__actions">
            <button
              v-for="option in safeServiceStartOptions"
              :key="option.id || option.optionId"
              type="button"
              class="button button--secondary"
              @click="handleServiceStartOption(option)"
            >{{ option.label }}</button>
          </div>
        </section>
      </section>

      <section v-else-if="currentStep === 2" class="checkout-payment-layout">
        <section class="checkout-card checkout-payment-methods">
          <div class="checkout-card__title">
            <h3>收款信息</h3>
            <span>可先选择全部收款方式，再调整金额；合计必须等于应收。</span>
          </div>

          <section v-if="checkoutMember && payment.balanceAvailable !== false && Number(payment.availableBalance) > 0" class="checkout-balance-section">
            <div class="checkout-balance-section__title">
              <strong>余额支付</strong>
              <span>可用余额 {{ formatMoney(payment.availableBalance) }}</span>
            </div>
            <div class="checkout-balance-section__actions">
              <button
                type="button"
                class="button button--secondary"
                :disabled="payment.balanceAvailable === false"
                @click="toggleBalancePayment"
              >{{ hasBalancePayment ? '取消余额支付' : '使用余额支付' }}</button>
              <button
                v-if="payment.balanceVerification?.required"
                type="button"
                class="button button--text"
                @click="request('open-balance-payment-identity-verification')"
              >{{ payment.balanceVerification?.label || '验证会员身份' }}</button>
            </div>
            <span v-if="payment.balanceVerification?.required" class="checkout-balance-section__verification" :class="`checkout-balance-section__verification--${payment.balanceVerification?.status || 'pending'}`">
              {{ payment.balanceVerification?.description || '本门店开启了余额支付身份验证，扣款前需完成验证。' }}
            </span>
          </section>

          <div class="checkout-payment-methods__header">
            <strong>记账收款</strong>
            <span v-if="selectedPaymentLines.length > 1" class="checkout-payment-methods__combination-label">组合收款</span>
          </div>
          <div class="checkout-payment-methods__grid">
            <button
              v-for="method in paymentMethods"
              :key="method.id"
              type="button"
              class="payment-method-card"
              :disabled="method.canAdd === false"
              :title="method.disabledReason || ''"
              @click="addPaymentMethod(method)"
            >
              {{ method.name }}
            </button>
          </div>
        </section>

        <section class="checkout-card checkout-payment-selected">
          <div class="checkout-card__title">
            <h3>本次收款</h3>
            <span>应收 {{ formatMoney(checkoutSummary.receivableAmount) }}</span>
          </div>
          <div v-if="selectedPaymentLines.length" class="checkout-payment-lines">
            <article v-for="line in selectedPaymentLines" :key="line.id" class="checkout-payment-line">
              <div>
                <strong>{{ line.name }}</strong>
                <span class="checkout-payment-line__status" :class="paymentLineStatusClass(line)">{{ paymentLineStatus(line) }}</span>
                <span v-if="line.lockReason" class="checkout-payment-line__lock-reason">{{ line.lockReason }}</span>
              </div>
              <div class="checkout-payment-line__amount">
                <input
                  v-if="canEditPaymentLine(line)"
                  class="checkout-payment-line__amount-input"
                  :value="paymentLineInputAmount(line)"
                  :disabled="paymentLineAmountDrafts[paymentLineAmountKey(line)]?.pending === true"
                  type="text"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  autocomplete="off"
                  aria-label="收款金额"
                  @input="updatePaymentLineAmountDraft(line, $event)"
                  @blur="savePaymentLineAmount(line, paymentLineInputAmount(line))"
                  @keydown.enter.prevent="savePaymentLineAmount(line, paymentLineInputAmount(line))"
                >
                <span v-if="paymentLineAmountErrors[paymentLineAmountKey(line)]" class="checkout-payment-line__amount-error" role="alert">
                  {{ paymentLineAmountErrors[paymentLineAmountKey(line)] }}
                </span>
                <strong v-if="!canEditPaymentLine(line)">{{ formatMoney(line.amount) }}</strong>
                <button v-if="canEditPaymentLineDetails(line)" type="button" class="button button--text" @click="openPaymentLineEditor(line)">编辑流水与备注</button>
                <button v-if="canRemovePaymentLine(line)" type="button" class="button button--text checkout-payment-line__remove" @click="removePaymentLine(line)">{{ line.kind === 'balance_deduction' ? '取消余额支付' : '删除' }}</button>
              </div>

              <section v-if="editingPaymentLineId === line.id" class="checkout-payment-line-editor" aria-label="编辑收款明细">
                <label>
                  <span>外部流水号（选填）</span>
                  <input v-model.trim="paymentLineDraft.externalTransactionNo" type="text" maxlength="50" placeholder="仅作备注记录，不校验外部渠道">
                </label>
                <label>
                  <span>收款备注（选填）</span>
                  <input v-model.trim="paymentLineDraft.remark" type="text" maxlength="200" placeholder="填写本笔收款说明">
                </label>
                <div class="checkout-payment-line-editor__actions">
                  <button type="button" class="button button--secondary" @click="closePaymentLineEditor">取消</button>
                  <button type="button" class="button button--primary" @click="savePaymentLine(line)">保存本笔</button>
                </div>
              </section>
            </article>
          </div>
          <div v-else class="checkout-empty-state">请在左侧选择收款方式。</div>
          <dl v-if="Object.keys(displayedPaymentSummary).length" class="checkout-payment-summary">
            <div v-if="displayedPaymentSummary.receivableAmount !== undefined"><dt>应收</dt><dd>{{ formatMoney(displayedPaymentSummary.receivableAmount) }}</dd></div>
            <div v-if="displayedPaymentSummary.selectedAmount !== undefined"><dt>已选收款</dt><dd>{{ formatMoney(displayedPaymentSummary.selectedAmount) }}</dd></div>
            <div v-if="displayedPaymentSummary.remainingAmount !== undefined"><dt>待收</dt><dd>{{ formatMoney(displayedPaymentSummary.remainingAmount) }}</dd></div>
            <div v-if="paymentAmountValidation.message" :class="`checkout-payment-summary__validation checkout-payment-summary__validation--${paymentAmountValidation.state}`"><dt>校验提示</dt><dd>{{ paymentAmountValidation.message }}</dd></div>
          </dl>
        </section>
      </section>

      <section v-else-if="currentStep === 3" class="checkout-card checkout-final-confirmation">
        <div class="checkout-card__title">
          <h3>{{ isDebtRepayment ? '确认还款' : primaryActionLabel }}</h3>
          <span>{{ hasSaleLines || isDebtRepayment ? '请核对最终金额与收款内容。' : '请核对本次使用权益与服务内容。' }}</span>
        </div>
        <dl class="checkout-final-summary">
          <div v-if="hasSaleLines || isDebtRepayment"><dt>应收</dt><dd>{{ formatMoney(checkoutSummary.receivableAmount) }}</dd></div>
          <div v-if="hasSaleLines"><dt>优惠</dt><dd>{{ formatMoney(checkoutSummary.discountAmount) }}</dd></div>
          <div v-if="hasSaleLines || isDebtRepayment"><dt>欠款</dt><dd>{{ formatMoney(checkout.debtAmount) }}</dd></div>
          <div v-if="hasSaleLines || isDebtRepayment"><dt>现金业绩</dt><dd>{{ formatMoney(checkout.cashPerformanceAmount) }}</dd></div>
          <div v-if="hasSaleLines || isDebtRepayment"><dt>余额支付</dt><dd>{{ formatMoney(checkout.balancePaymentAmount) }}</dd></div>
          <div v-if="hasEntitlementLines"><dt>权益服务</dt><dd>{{ checkoutOrderLines.filter(isEntitlementCheckoutLine).length }} 项</dd></div>
          <div v-if="checkout.cardUpgradeDeductionAmount !== undefined"><dt>卡升级抵扣</dt><dd>{{ formatMoney(checkout.cardUpgradeDeductionAmount) }}</dd></div>
          <div v-if="checkout.projectUpgradeDeductionAmount !== undefined"><dt>项目升级抵扣</dt><dd>{{ formatMoney(checkout.projectUpgradeDeductionAmount) }}</dd></div>
          <div v-if="checkout.businessDate"><dt>销售日期</dt><dd>{{ checkout.businessDate }}</dd></div>
        </dl>
        <section v-if="finalChanges.length || checkout.finalValidationMessage" class="checkout-final-changes">
          <div>
            <strong>结账前变更核对</strong>
            <span>{{ checkout.finalValidationMessage || '以下内容由后端按最新权益、价格和资源重新校验，请确认后继续。' }}</span>
          </div>
          <ul v-if="finalChanges.length">
            <li v-for="change in finalChanges" :key="change.id || change.field || change.label">
              <strong>{{ change.label || change.field }}</strong>
              <span>{{ change.description || change.message }}</span>
            </li>
          </ul>
          <button v-if="checkout.finalChangesConfirmationRequired" type="button" class="button button--text" @click="request('confirm-checkout-final-changes')">我已确认变更</button>
        </section>
        <div v-if="checkout.debtConfirmationRequired" class="checkout-debt-warning">
          <strong>本单含欠款</strong>
          <span>请确认已核对各欠款明细和现金业绩。</span>
          <button type="button" class="button button--text" @click="request('confirm-debt-warning')">我已确认</button>
        </div>
      </section>

      <section
        v-else
        class="checkout-result"
        :role="isFailed || isResultUnknown ? 'alert' : 'status'"
        aria-live="polite"
        aria-atomic="true"
      >
        <div :class="['checkout-result__icon', `checkout-result__icon--${checkoutStatus}`]" aria-hidden="true">
          <span v-if="isProcessing || isPendingConfirmation" class="checkout-spinner" aria-hidden="true" />
          <span v-else>{{ isSucceeded ? '✓' : isFailed ? '!' : '…' }}</span>
        </div>
        <h3 ref="resultHeading" tabindex="-1">{{ resultTitle }}</h3>
        <p>{{ resultDescription }}</p>
        <span v-if="checkout.requestNo" class="checkout-result__request">{{ isDebtRepayment ? '还款请求号' : '结账请求号' }}：{{ checkout.requestNo }}</span>
        <span v-if="isSucceeded && !isDebtRepayment && checkout.salesOrderNo" class="checkout-result__order">销售订单号：{{ checkout.salesOrderNo }}</span>
        <div v-if="Object.keys(childResults).length" class="checkout-child-results" aria-label="本次业务处理结果">
          <div v-for="(result, key) in childResults" :key="key" :class="childResultStatusClass(result)">
            <span>{{ result.label || key }}</span>
            <strong>{{ childResultStatusLabel(result) }}</strong>
          </div>
        </div>
        <div v-if="paymentResultLines.length" class="checkout-result-payment-lines" aria-label="本次收款处理结果">
          <strong>收款处理结果</strong>
          <div v-for="line in paymentResultLines" :key="line.id" class="checkout-result-payment-lines__item">
            <span :title="line.name">{{ line.name }}</span>
            <span>{{ formatMoney(line.amount) }}</span>
            <em :class="paymentLineStatusClass(line)">{{ paymentLineStatus(line) }}</em>
          </div>
        </div>
        <p v-if="receiptPrintError" class="checkout-result__print-error" role="alert">{{ receiptPrintError }}</p>
      </section>
    </main>

    <div
      v-if="balancePaymentPromptOpen"
      class="checkout-inline-modal-backdrop"
      role="presentation"
      @click.self="closeBalancePaymentPrompt"
    >
      <section
        class="checkout-inline-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="balance-payment-prompt-title"
      >
        <header>
          <h3 id="balance-payment-prompt-title">无法使用余额支付</h3>
          <button type="button" class="button button--text" aria-label="关闭" @click="closeBalancePaymentPrompt">×</button>
        </header>
        <p>当前收款方式已填满应收，请先调整其他收款金额后再使用余额支付。</p>
        <footer>
          <button type="button" class="button button--primary" @click="closeBalancePaymentPrompt">知道了</button>
        </footer>
      </section>
    </div>

    <footer class="checkout-overlay__footer">
      <template v-if="isSucceeded">
        <button v-if="!isDebtRepayment && checkout.salesOrderId" type="button" class="button button--secondary" @click="request('view-sales-order')">查看销售订单</button>
        <button v-if="canPrintReceipt" type="button" class="button button--secondary" @click="printReceipt">打印小票</button>
        <button type="button" class="button button--primary" @click="finishCheckoutAndReturn">
          {{ isServiceOrder || hasEntitlementLines ? '完成并返回收银台' : '返回收银台' }}
        </button>
      </template>
      <template v-else-if="isPaymentSucceededServicePending">
        <span v-if="!canQueryCheckoutResult" class="checkout-overlay__locked-tip">原结账请求标识尚未完整加载；本次收款已成功，禁止重新收款，请联系管理员恢复原请求。</span>
        <button v-else type="button" class="button button--primary" @click="queryOriginalCheckoutResult">继续处理本次服务</button>
      </template>
      <template v-else-if="isFailed">
        <span v-if="!canReturnToPaymentEdit && !canRetryCheckout && !canContinuePartialPaymentRecovery" class="checkout-overlay__locked-tip">
          后端尚未明确允许修改或重试；请保留本页并按失败原因处理。
        </span>
        <button v-if="canReturnToCashierEdit" type="button" class="button button--secondary" @click="returnToCashierEdit">返回收银补选手艺人</button>
        <button v-else-if="canReturnToPaymentEdit" type="button" class="button button--secondary" @click="returnToPaymentEdit">返回修改收款</button>
        <button v-if="canRetryCheckout" type="button" class="button button--primary" :disabled="isSubmissionLocked" @click="retryCheckout">重新支付</button>
        <button v-if="canContinuePartialPaymentRecovery" type="button" class="button button--primary" @click="request('continue-partial-payment-recovery')">继续处理剩余收款</button>
      </template>
      <template v-else-if="isUncertain">
        <span v-if="!canQueryCheckoutResult" class="checkout-overlay__locked-tip">原{{ isDebtRepayment ? '还款' : '结账' }}请求号尚未加载，禁止关闭或重复提交，请联系管理员核对原请求。</span>
        <button v-else type="button" class="button button--primary" @click="queryOriginalCheckoutResult">查询原支付结果</button>
      </template>
      <template v-else-if="isProcessing">
        <span class="checkout-overlay__locked-tip">正在处理，请勿关闭或重复操作。</span>
      </template>
      <template v-else>
        <button type="button" class="button button--secondary" :disabled="currentStepPosition === 0" @click="goPrevious">上一步</button>
        <button type="button" class="button button--primary" :disabled="isSubmissionLocked || !hasCurrentStepSnapshot || (currentStep === 2 && !isPaymentDraftReady)" @click="goNext">{{ nextLabel }}</button>
      </template>
    </footer>
  </section>
</template>

<style scoped>
.checkout-payment-methods__combination-label {
  color: #2563eb;
  font-size: 12px;
  font-weight: 600;
}

.checkout-payment-summary__validation dd {
  font-weight: 600;
}

.checkout-payment-summary__validation--balanced dd {
  color: #16803c;
}

.checkout-payment-summary__validation--underpaid dd,
.checkout-payment-summary__validation--overpaid dd,
.checkout-payment-summary__validation--invalid dd {
  color: #c2410c;
}

.checkout-business-sources {
  display: grid;
  gap: 10px;
  margin-top: 16px;
  padding: 13px 14px;
  border: 1px solid #e3e8ef;
  border-radius: 7px;
  background: #fafbfc;
}

.checkout-business-sources__heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.checkout-business-sources__heading strong,
.checkout-business-sources__secondary > strong {
  color: #303133;
  font-size: 13px;
}

.checkout-business-sources__heading span,
.checkout-business-sources__state,
.checkout-business-sources__secondary > strong span {
  color: #7b8798;
  font-size: 12px;
}

.checkout-business-sources__choices {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.checkout-business-sources__choices button {
  min-height: 34px;
  max-width: 100%;
  padding: 6px 13px;
  border: 1px solid #cfd7e3;
  border-radius: 5px;
  background: #fff;
  color: #3f4c5c;
  cursor: pointer;
  line-height: 20px;
  overflow-wrap: anywhere;
}

.checkout-business-sources__choices button:hover:not(:disabled) {
  border-color: #409eff;
  color: #1677cc;
}

.checkout-business-sources__choices button.is-selected {
  border-color: #2981e5;
  background: #eaf4ff;
  color: #175fb3;
}

.checkout-business-sources__choices button:disabled {
  cursor: wait;
  opacity: .65;
}

.checkout-business-sources__secondary {
  display: grid;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px solid #e5eaf0;
}

.checkout-business-sources__state {
  min-height: 34px;
  line-height: 34px;
}

.checkout-business-sources__state--error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  color: #c63434;
  line-height: 1.5;
}

.checkout-order-details > .checkout-sales-date {
  grid-column: 1 / -1;
  align-items: flex-start;
  padding-top: 10px;
  padding-bottom: 10px;
}

.checkout-sales-date dd {
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  overflow: visible;
  white-space: normal;
}

.checkout-sales-date input {
  min-height: 34px;
  box-sizing: border-box;
  border: 1px solid #cfd7e3;
  border-radius: 5px;
  background: #fff;
  color: #303133;
  font: inherit;
}

.checkout-sales-date input[type='date'] {
  width: 156px;
  padding: 0 9px;
}

.checkout-sales-date input[type='text'] {
  flex: 1 1 240px;
  min-width: 180px;
  padding: 0 10px;
}

@media (max-width: 640px) {
  .checkout-business-sources__heading,
  .checkout-business-sources__state--error {
    align-items: flex-start;
    flex-direction: column;
  }

  .checkout-business-sources__choices button {
    flex: 1 1 calc(50% - 8px);
  }

  .checkout-order-details > .checkout-sales-date {
    flex-direction: column;
  }

  .checkout-sales-date dd,
  .checkout-sales-date input[type='date'],
  .checkout-sales-date input[type='text'] {
    width: 100%;
  }
}
</style>
