<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createCashierV3CommandId, formatMoney } from '@/services/cashierV3Bridge'

/**
 * 服务确认的金额与业绩只展示后端试算的预览结果。
 *
 * 不能把购物车成交金额当作后台服务计价依据，也不能由前端补算消耗业绩或劳动业绩；
 * 三者都可能受卡型、权益槽位、赠送、最终成交与人员分配规则影响。
 * 正式确认前，后端至少需要返回本次完成项目数、后台计价依据和两类业绩结果。
 */

const props = defineProps({
  serviceOrder: {
    type: Object,
    default: () => ({})
  },
  cartLines: {
    type: Array,
    default: () => []
  },
  isActionSubmitting: {
    type: Boolean,
    default: false
  },
  returnFocusTo: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['close', 'request'])
const submitCommandId = ref(null)
const isSubmitting = ref(false)
const dialogRoot = ref(null)
const resultHeading = ref(null)
let previouslyFocusedElement = null

const sections = computed(() => Array.isArray(props.serviceOrder.sections) ? props.serviceOrder.sections : [])
const completion = computed(() => props.serviceOrder.completion || {})
const completionPreview = computed(() => isRecord(completion.value.preview) ? completion.value.preview : null)
const snapshotToken = computed(() => completion.value.snapshotToken || completion.value.previewToken || completion.value.confirmationToken || '')
const isServerConfirmationReady = computed(() => completion.value.confirmationReady === true && Boolean(snapshotToken.value))
const completionStatus = computed(() => normalizeCompletionStatus(completion.value.status))
const originalIdempotencyKey = computed(() => submitCommandId.value || completion.value.originalIdempotencyKey || '')
const isProcessing = computed(() => ['processing', 'pending', 'pending_confirmation', 'result_unknown'].includes(completionStatus.value))
const isResultUnknown = computed(() => ['pending_confirmation', 'result_unknown'].includes(completionStatus.value))
const isFailed = computed(() => completionStatus.value === 'failed')
const isSucceeded = computed(() => ['succeeded', 'success'].includes(completionStatus.value))
const isLocked = computed(() => isSubmitting.value || props.isActionSubmitting || isProcessing.value)
const canEditServiceLines = computed(() => !isLocked.value && !isSucceeded.value)
const requiredPreviewFields = [
  'completedProjectCount',
  'writeoffAmount',
  'consumptionPerformanceAmount',
  'laborPerformanceAmount'
]
const previewFieldLabels = {
  completedProjectCount: '实际完成项目',
  writeoffAmount: '服务计价结果',
  consumptionPerformanceAmount: '消耗业绩',
  laborPerformanceAmount: '劳动业绩'
}
const missingPreviewFields = computed(() => requiredPreviewFields.filter((field) => !hasExplicitPreviewValue(completionPreview.value?.[field])))
const serviceLines = computed(() => (Array.isArray(props.cartLines) ? props.cartLines : [])
  .filter((line) => line && line.isServiceProject === true))
const hasCompleteConfirmationLines = computed(() => serviceLines.value.length > 0 && serviceLines.value.every(hasCompleteConfirmationLine))
const hasCompleteConfirmationPreview = computed(() => Boolean(completionPreview.value)
  && isServerConfirmationReady.value
  && missingPreviewFields.value.length === 0
  && hasCompleteConfirmationLines.value)
const isAwaitingBackendConfirmation = computed(() => !isProcessing.value && !isSucceeded.value && !hasCompleteConfirmationPreview.value)
// 服务确认成功后是否进入结账，必须由后端按本次服务单的待收款状态决定。
// 纯卡内项目完成后应直接释放房间／完成服务，不能被前端强行带去结账。
const allowedSuccessNextActions = ['continue-service-checkout', 'finish-service-completion']
const successNextAction = computed(() => allowedSuccessNextActions.includes(completion.value.nextAction)
  ? completion.value.nextAction
  : '')
const successNextLabel = computed(() => completion.value.nextActionLabel
  || (successNextAction.value === 'continue-service-checkout' ? '继续结账' : successNextAction.value === 'finish-service-completion' ? '完成并返回' : '等待后端确认'))
const confirmationPreviewHint = computed(() => {
  const missingParts = []
  if (completion.value.confirmationReady !== true) missingParts.push('后端确认状态')
  if (!snapshotToken.value) missingParts.push('确认快照令牌')
  if (!hasCompleteConfirmationLines.value) missingParts.push('完整项目确认明细')
  if (!completionPreview.value) {
    missingParts.push('服务结果与业绩预览')
  } else if (missingPreviewFields.value.length) {
    missingParts.push(missingPreviewFields.value.map((field) => previewFieldLabels[field]).join('、'))
  }
  return `后端尚未返回或确认${missingParts.join('、')}，当前不能确认服务。`
})
const canSubmit = computed(() => !isLocked.value && hasCompleteConfirmationPreview.value)
const lineMap = computed(() => new Map(serviceLines.value.map((line) => [lineIdentity(line), line])))

const sectionGroups = computed(() => {
  if (!sections.value.length) {
    return serviceLines.value.length
      ? [{ key: 'service-confirmation-lines', label: '本次服务项目', description: '后端确认快照中的全部项目。', lines: serviceLines.value }]
      : []
  }

  return sections.value.map((section) => ({
    ...section,
    lines: (section.lineIds || [])
      .map((id) => lineMap.value.get(id))
      .filter(Boolean)
  })).filter((section) => section.lines.length || section.emptyHint)
})

const resultTitle = computed(() => {
  if (isSucceeded.value) return completion.value.successLabel || '服务已确认'
  if (isFailed.value) return '服务确认未完成'
  if (isResultUnknown.value) return '正在确认服务结果'
  if (isProcessing.value) return '正在确认服务'
  return ''
})

const resultDescription = computed(() => {
  if (isSucceeded.value) return completion.value.successDescription || '已按实际完成项目处理服务与必要核销，请按后端确认的下一步继续处理。'
  if (isFailed.value) return completion.value.failureReason || '本次服务未确认成功，已保留原内容。'
  if (isResultUnknown.value) return '服务确认结果尚未明确，请查询原请求结果，不要重复提交。'
  if (isProcessing.value) return '正在确认实际完成项目和必要核销，请勿重复提交。'
  return ''
})

function normalizeCompletionStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  if (!status || status === 'editing') return 'editing'
  if (['processing', 'submitting', '处理中', '提交中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) return 'pending_confirmation'
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  return 'result_unknown'
}

watch(
  completionStatus,
  async (status, previousStatus) => {
    if (['failed', 'succeeded', 'success'].includes(status)) isSubmitting.value = false
    if (previousStatus !== undefined && status !== previousStatus) {
      await nextTick()
      if (isProcessing.value || isResultUnknown.value || isFailed.value || isSucceeded.value) {
        resultHeading.value?.focus()
      }
    }
  },
  { immediate: true }
)

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
    if (!isLocked.value) {
      event.preventDefault()
      emit('close')
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

onMounted(async () => {
  previouslyFocusedElement = props.returnFocusTo instanceof HTMLElement
    ? props.returnFocusTo
    : document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
  await nextTick()
  if (isProcessing.value || isResultUnknown.value || isFailed.value || isSucceeded.value) {
    resultHeading.value?.focus()
  } else {
    dialogRoot.value?.focus()
  }
})

onBeforeUnmount(() => {
  const restoreTarget = previouslyFocusedElement
  window.requestAnimationFrame(() => {
    if (restoreTarget?.isConnected) restoreTarget.focus()
  })
})

function request(action, payload = {}) {
  emit('request', { action, payload })
}

function submit() {
  if (!canSubmit.value) return
  if (!submitCommandId.value) submitCommandId.value = createCashierV3CommandId('SERVICE')
  isSubmitting.value = true
  request('confirm-service-completion', { idempotencyKey: submitCommandId.value, snapshotToken: snapshotToken.value })
}

function retry() {
  if (!canSubmit.value) return
  submitCommandId.value = createCashierV3CommandId('SERVICE')
  isSubmitting.value = true
  request('retry-service-completion', { idempotencyKey: submitCommandId.value, snapshotToken: snapshotToken.value })
}

function runSuccessNextAction() {
  if (successNextAction.value === 'continue-service-checkout') {
    request('continue-service-checkout')
  } else if (successNextAction.value === 'finish-service-completion') {
    request('finish-service-completion')
  }
}

function groupLineMeta(line) {
  return [line.serviceSource, line.serviceRole].filter(Boolean).join(' · ') || '本次服务项目'
}

function lineValue(line, keys) {
  for (const key of keys) {
    if (line?.[key] !== undefined && line?.[key] !== null && line?.[key] !== '') return line[key]
  }
  return undefined
}

function lineIdentity(line) {
  return lineValue(line, ['id', 'lineId', 'serviceLineId', 'projectLineId'])
}

function lineCompletedQuantity(line) {
  return lineValue(line, ['actualCompletedQuantity', 'completedQuantity', 'actualQuantity'])
}

function lineCompletionStatus(line) {
  return lineValue(line, ['completionStatus', 'serviceCompletionStatus', 'actualCompletionStatus'])
}

function lineCompletionStatusLabel(line) {
  const raw = String(lineCompletionStatus(line) || '').trim()
  const normalized = raw.toLowerCase().replace(/[\s-]/g, '_')
  if (['completed', 'complete', '本次已完成'].includes(normalized) || raw === '本次已完成') return '本次已完成'
  if (['partial', 'partially_completed', '部分完成'].includes(normalized) || raw === '部分完成') return '部分完成'
  if (['not_served', 'unserved', 'notserved', '本次未服务'].includes(normalized) || raw === '本次未服务') return '本次未服务'
  return raw || '待后端确认'
}

function isNotServedLine(line) {
  const status = String(lineCompletionStatus(line) || '').toLowerCase()
  return ['not_served', 'unserved', 'notserved', '本次未服务'].includes(status)
}

function actualCraftsmen(line) {
  return Array.isArray(line?.actualCraftsmen) ? line.actualCraftsmen : []
}

function actualCraftsmenSummary(line) {
  const names = actualCraftsmen(line)
    .map((craftsman) => craftsman?.name || craftsman?.staffName || craftsman?.employeeName || '')
    .filter(Boolean)
  return names.length ? names.join('、') : (isNotServedLine(line) ? '本次未服务，无需分配' : '待后端确认')
}

function entitlementSourceText(line) {
  const source = line?.entitlementSource
  if (typeof source === 'string' && source) return source
  if (source && typeof source === 'object') return source.label || source.name || source.reference || '权益来源已确认'
  if (line?.entitlementSourceLabel) return line.entitlementSourceLabel
  return ['本次购买', '本次新买'].includes(line?.serviceSource) ? '本次购买项目' : '待后端确认'
}

function unservedReasonText(line) {
  const value = lineValue(line, ['unservedReason', 'notServedReason', 'unfinishedReason'])
  const labels = {
    customer_cancelled: '客户取消',
    insufficient_time: '时间不足',
    other: '其他'
  }
  if (!value) return ''
  const note = lineValue(line, ['unservedReasonNote', 'notServedReasonNote', 'unfinishedReasonNote'])
  return [labels[value] || value, note].filter(Boolean).join('：')
}

function hasLineId(line) {
  return Boolean(lineIdentity(line))
}

function hasLineEntitlementSource(line) {
  return Object.prototype.hasOwnProperty.call(line || {}, 'entitlementSource')
    || Boolean(line?.entitlementSourceLabel)
}

function hasCompleteConfirmationLine(line) {
  const completedQuantity = lineCompletedQuantity(line)
  const totalQuantity = lineValue(line, ['quantity', 'serviceQuantity', 'selectedTimes'])
  const status = lineCompletionStatus(line)
  const hasMetrics = ['writeoffAmount', 'consumptionPerformanceAmount', 'laborPerformanceAmount']
    .every((field) => hasExplicitPreviewValue(line?.[field]))
  if (!hasLineId(line) || line?.isServiceProject !== true || !line?.name || !line?.serviceSource || !line?.serviceRole) return false
  if (!hasExplicitPreviewValue(totalQuantity) || !hasExplicitPreviewValue(completedQuantity) || !status || !hasLineEntitlementSource(line) || !hasMetrics) return false
  const hasUnfinishedPart = Number(completedQuantity) < Number(totalQuantity)
  if (hasUnfinishedPart && !unservedReasonText(line)) return false
  if (Number(completedQuantity) > 0) {
    return actualCraftsmen(line).length > 0
      && actualCraftsmen(line).every((craftsman) => craftsman?.id || craftsman?.staffId || craftsman?.employeeId)
  }
  return isNotServedLine(line) && Boolean(unservedReasonText(line)) && Array.isArray(line?.actualCraftsmen)
}

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function hasExplicitPreviewValue(value) {
  if (value === undefined || value === null || value === '') return false
  return !(typeof value === 'number' && !Number.isFinite(value))
}

function displayPreviewMoney(value) {
  return hasExplicitPreviewValue(value) ? formatMoney(value) : '待后端确认'
}

function displayPreviewCount(value) {
  return hasExplicitPreviewValue(value) ? `${value} 项` : '待后端确认'
}
</script>

<template>
  <section
    ref="dialogRoot"
    class="service-completion-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="service-completion-dialog-title"
    aria-describedby="service-completion-dialog-description"
    :aria-busy="isProcessing || isResultUnknown"
    tabindex="-1"
    @keydown="handleDialogKeydown"
  >
    <header class="service-completion-overlay__header">
      <div>
        <h2 id="service-completion-dialog-title">确认本次服务</h2>
        <span id="service-completion-dialog-description">{{ serviceOrder.serviceNo || serviceOrder.id || '本次服务单' }} · {{ serviceOrder.roomName || '待分配房间' }}</span>
      </div>
      <button type="button" class="button button--secondary" :disabled="isLocked" @click="$emit('close')">返回修改</button>
    </header>

    <main v-if="!isProcessing && !isSucceeded" class="service-completion-overlay__content">
      <div class="service-completion-overlay__hint">请逐项确认本次实际完成情况。未服务项目需填写原因；实际手艺人和劳动业绩由后端试算后确认。</div>

      <div v-if="isAwaitingBackendConfirmation" class="service-completion-overlay__hint" role="alert">
        <strong>待后端确认</strong>
        <span>{{ confirmationPreviewHint }}</span>
      </div>

      <section v-for="section in sectionGroups" :key="section.key" class="service-completion-section">
        <header>
          <div>
            <h3>{{ section.label }}</h3>
            <span>{{ section.description || '按实际完成项目确认服务结果。' }}</span>
          </div>
        </header>

        <article v-for="line in section.lines" :key="lineIdentity(line)" class="service-completion-line">
          <div class="service-completion-line__identity">
            <strong>{{ line.name }}</strong>
            <span>{{ groupLineMeta(line) }} · 数量 {{ line.quantity || 1 }}</span>
            <span>权益来源：{{ entitlementSourceText(line) }}</span>
          </div>
          <div class="service-completion-line__result">
            <span>实际完成 {{ lineCompletedQuantity(line) ?? '待后端确认' }} / {{ line.quantity || '待后端确认' }}</span>
            <span>{{ isNotServedLine(line) ? `本次未服务${unservedReasonText(line) ? `：${unservedReasonText(line)}` : ''}` : (Number(lineCompletedQuantity(line)) < Number(line.quantity || 0) ? `未完成部分：${unservedReasonText(line) || '待后端确认'}` : '本次已完成') }}</span>
            <span>消耗业绩 {{ displayPreviewMoney(line.consumptionPerformanceAmount) }}</span>
            <span>劳动业绩 {{ displayPreviewMoney(line.laborPerformanceAmount) }}</span>
          </div>
          <button type="button" class="button button--text" :disabled="!canEditServiceLines" @click="request('open-service-line-staff-allocation', { lineId: lineIdentity(line) })">
            实际手艺人：{{ actualCraftsmenSummary(line) }}
          </button>
          <button type="button" class="button button--secondary" :disabled="!canEditServiceLines" @click="request('open-service-line-completion', { lineId: lineIdentity(line) })">
            {{ lineCompletionStatusLabel(line) }}
          </button>
        </article>
      </section>

      <section v-if="completionPreview" class="service-completion-preview">
        <h3>本次确认预览</h3>
        <dl>
          <div><dt>实际完成项目</dt><dd>{{ displayPreviewCount(completionPreview.completedProjectCount) }}</dd></div>
          <div><dt>消耗业绩</dt><dd>{{ displayPreviewMoney(completionPreview.consumptionPerformanceAmount) }}</dd></div>
          <div><dt>劳动业绩</dt><dd>{{ displayPreviewMoney(completionPreview.laborPerformanceAmount) }}</dd></div>
        </dl>
      </section>
    </main>

    <main v-else class="service-completion-result">
      <span v-if="isProcessing" class="checkout-spinner" />
      <span v-else class="service-completion-result__icon">{{ isSucceeded ? '✓' : '!' }}</span>
      <h3 ref="resultHeading" tabindex="-1">{{ resultTitle }}</h3>
      <p>{{ resultDescription }}</p>
      <span v-if="completion.requestNo">服务确认请求号：{{ completion.requestNo }}</span>
    </main>

    <footer class="service-completion-overlay__footer">
      <template v-if="isSucceeded">
        <button type="button" class="button button--primary" :disabled="isLocked || !successNextAction" @click="runSuccessNextAction">{{ successNextLabel }}</button>
      </template>
      <template v-else-if="isFailed">
        <button type="button" class="button button--secondary" :disabled="isLocked" @click="request('return-to-service-edit')">返回修改</button>
        <button type="button" class="button button--primary" :disabled="!canSubmit" :title="isAwaitingBackendConfirmation ? confirmationPreviewHint : ''" @click="retry">
          {{ isAwaitingBackendConfirmation ? '待后端确认' : '重新确认' }}
        </button>
      </template>
      <template v-else-if="isProcessing">
        <span class="service-completion-overlay__locked-tip">{{ isResultUnknown ? '结果确认中，请勿关闭或重复操作。' : '正在确认，请勿关闭或重复操作。' }}</span>
        <button
          v-if="completion.requestNo && originalIdempotencyKey"
          type="button"
          class="button button--secondary"
          @click="request('query-service-completion-result', { requestNo: completion.requestNo, originalIdempotencyKey })"
        >查询处理结果</button>
      </template>
      <template v-else>
        <button type="button" class="button button--secondary" @click="$emit('close')">返回修改</button>
        <button type="button" class="button button--primary" :disabled="!canSubmit" :title="isAwaitingBackendConfirmation ? confirmationPreviewHint : ''" @click="submit">
          {{ isAwaitingBackendConfirmation ? '待后端确认' : '确认本次服务' }}
        </button>
      </template>
    </footer>
  </section>
</template>
