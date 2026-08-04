<script setup>
import { computed, ref, watch } from 'vue'
import { createCashierV3CommandId, formatMoney } from '@/services/cashierV3Bridge'

/**
 * 核销“核对并提交”前端壳。
 *
 * writeoff 只提供草稿上下文；正式核对内容只能使用后端返回的 preview。
 * 组件只展示后端已经确认的来源、次数、金额、手艺人与业绩结果，绝不从草稿
 * 推导或补造金额、劳动业绩、消耗业绩或业务状态。
 *
 * 正式提交前，后端必须返回完整且不可伪造复用的确认预览。推荐契约：
 * {
 *   confirmationReady: true,
 *   previewToken, // 后端签发，绑定本次草稿版本；正式提交时必须回传并再次校验
 *   status: 'editing' | 'processing' | 'pending_confirmation' | 'result_unknown' | 'failed' | 'succeeded',
 *   member: { id, name, memberNo }, requestNo, failureReason, successLabel, successDescription,
 *   lines: [{
 *     id, sourceId, sourceType, sourceTypeLabel, sourceName, sourceReference,
 *     projectName, selectedTimes, writeoffAmount,
 *     actualCraftsmenSummary, laborPerformanceAmount, consumptionPerformanceAmount
 *   }],
 *   summary: { selectedTimes, writeoffAmount, laborPerformanceAmount, consumptionPerformanceAmount }
 * }
 *
 * onSubmit 只接收操作上下文、后端确认令牌和本次幂等键；调用方负责向后端提交并用新状态回填 props。
 * onReturn 只负责返回编辑态；本组件不自行回写 writeoff / preview。
 */
const props = defineProps({
  writeoff: {
    type: Object,
    default: () => ({})
  },
  preview: {
    type: Object,
    default: () => ({})
  },
  onSubmit: {
    type: Function,
    default: null
  },
  onReturn: {
    type: Function,
    default: null
  },
  onQuery: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close'])

const submitCommandId = ref(null)
const isSubmitRequested = ref(false)
const isReturning = ref(false)
const isQuerying = ref(false)
const callbackError = ref('')
const localSafetyStatus = ref('')

const backendPreviewStatus = computed(() => normalizeStatus(props.preview.status))
const previewStatus = computed(() => localSafetyStatus.value || backendPreviewStatus.value)
const isProcessing = computed(() => previewStatus.value === 'processing')
const isPendingConfirmation = computed(() => previewStatus.value === 'pending_confirmation')
const isResultUnknown = computed(() => previewStatus.value === 'result_unknown')
const isUncertain = computed(() => isPendingConfirmation.value || isResultUnknown.value)
const isFailed = computed(() => previewStatus.value === 'failed')
const isSucceeded = computed(() => previewStatus.value === 'succeeded')
const isResult = computed(() => isProcessing.value || isUncertain.value || isFailed.value || isSucceeded.value)
const backendPreviewToken = computed(() => readValue(props.preview, ['previewToken', 'confirmationToken', 'serverPreviewToken']))
const backendPreviewLines = computed(() => readReviewLines(props.preview))
const hasCompleteBackendPreview = computed(() => isCompleteBackendPreview(
  props.preview,
  backendPreviewToken.value,
  backendPreviewLines.value
))
const isAwaitingBackendReview = computed(() => !isResult.value && !hasCompleteBackendPreview.value)
const canSubmit = computed(() => (
  previewStatus.value === 'editing'
  && hasCompleteBackendPreview.value
  && !isSubmitRequested.value
  && typeof props.onSubmit === 'function'
))
const canRetry = computed(() => (
  isFailed.value
  && props.preview.canRetry === true
  && hasCompleteBackendPreview.value
  && typeof props.onSubmit === 'function'
))
const canReturnToEdit = computed(() => (
  previewStatus.value === 'editing'
  || (isFailed.value && props.preview.canReturnToEdit === true)
))
const isLocked = computed(() => (
  isSubmitRequested.value
  || isReturning.value
  || isQuerying.value
  || isProcessing.value
  || isUncertain.value
))
const canCloseOverlay = computed(() => (
  isSucceeded.value
  || (isFailed.value && props.preview.canClose === true)
  || (!isResult.value && !isLocked.value)
))
const member = computed(() => (
  isResult.value || hasCompleteBackendPreview.value
    ? props.preview.member || null
    : null
))
const requestNo = computed(() => readValue(props.preview, ['requestNo', 'submissionRequestNo', 'requestId']))
const queryResultAction = 'query-writeoff-result'
const originalIdempotencyKey = computed(() => (
  submitCommandId.value
  || readValue(props.preview, ['originalIdempotencyKey'])
))
const canQueryOriginalResult = computed(() => (
  isUncertain.value
  && Boolean(requestNo.value)
  && Boolean(originalIdempotencyKey.value)
  && typeof props.onQuery === 'function'
))
const reviewSummary = computed(() => hasCompleteBackendPreview.value ? readSummary(props.preview) : [])
const sourceGroups = computed(() => hasCompleteBackendPreview.value ? groupLines(backendPreviewLines.value) : [])
const hasReviewLines = computed(() => sourceGroups.value.length > 0)

const resultTitle = computed(() => {
  if (isSucceeded.value) return props.preview.successLabel || '核销成功'
  if (isFailed.value) return '核销未完成'
  if (isResultUnknown.value) return props.preview.unknownLabel || '核销结果暂时未知'
  if (isPendingConfirmation.value) return props.preview.pendingLabel || '正在确认原核销结果'
  if (isProcessing.value) return props.preview.processingLabel || '正在提交核销'
  if (isAwaitingBackendReview.value) return '正在等待后端核对'
  return '核对本次核销'
})

const resultDescription = computed(() => {
  if (isSucceeded.value) return props.preview.successDescription || '本次核销已完成，相关权益和业绩已按后端结果处理。'
  if (isFailed.value) return props.preview.failureReason || props.preview.message || '本次核销未成功，原选择内容仍保留。'
  if (isResultUnknown.value) return props.preview.unknownDescription || '权益可能已经扣减。只能查询这一次原核销请求，禁止重新提交。'
  if (isPendingConfirmation.value) return props.preview.pendingDescription || '正在确认原核销结果，请勿关闭或重复提交。'
  if (isProcessing.value) return props.preview.processingDescription || '正在提交核销，请勿关闭或重复操作。'
  if (isAwaitingBackendReview.value) return '正在等待后端核对本次来源、次数、手艺人及业绩结果；核对完成前不能正式提交。'
  return '请逐项核对来源、项目、次数、手艺人与业绩；本页不包含收款或销售内容。'
})

watch(
  backendPreviewStatus,
  (status) => {
    if (status !== 'editing') {
      localSafetyStatus.value = status
    } else if (!isSubmitRequested.value && !isQuerying.value) {
      localSafetyStatus.value = ''
    }
    if (status === 'failed' || status === 'succeeded') {
      isSubmitRequested.value = false
    }
  },
  { immediate: true }
)

watch(backendPreviewToken, (token, previousToken) => {
  if (token !== previousToken && previewStatus.value === 'editing' && !isLocked.value) {
    submitCommandId.value = null
  }
})

function normalizeStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  if (['processing', 'submitting', 'submitting_writeoff', '处理中', '提交中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) {
    return 'pending_confirmation'
  }
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', '失败'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'result_unknown'
}

function readValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    if (source[key] !== undefined && source[key] !== null) return source[key]
  }
  return undefined
}

function readFirstArray(source, keys) {
  if (!source || typeof source !== 'object') return null
  for (const key of keys) {
    if (Array.isArray(source[key])) return source[key]
  }
  return null
}

function hasValue(value) {
  return value !== undefined && value !== null && value !== ''
}

function isFiniteNumber(value) {
  return hasValue(value) && Number.isFinite(Number(value))
}

function hasActualCraftsmen(value) {
  if (Array.isArray(value)) return value.length > 0
  return typeof value === 'string'
    ? Boolean(value.trim()) && value.trim() !== '待后端确认'
    : hasValue(value)
}

function readPreviewSummary(preview) {
  if (preview?.summary && typeof preview.summary === 'object') return preview.summary
  if (preview?.totals && typeof preview.totals === 'object') return preview.totals
  return null
}

function hasCompleteReviewLine(line) {
  const sourceId = readValue(line, ['sourceId', 'benefitSourceId', 'cardId', 'giftId'])
  const sourceType = readValue(line, ['sourceType', 'sourceTypeLabel', 'sourceLabel'])
  const projectId = readValue(line, ['projectId', 'projectCode'])
  const projectName = readValue(line, ['projectName', 'projectLabel'])
  const selectedTimes = readValue(line, ['selectedTimes', 'writeoffTimes', 'times', 'quantity'])
  const actualCraftsmen = readValue(line, ['actualCraftsmenSummary', 'actualCraftsmanSummary', 'actualCraftsmen', 'craftsmenSummary'])
  const writeoffAmount = readValue(line, ['writeoffAmount'])
  const laborPerformanceAmount = readValue(line, ['laborPerformanceAmount', 'laborAmount'])
  const consumptionPerformanceAmount = readValue(line, ['consumptionPerformanceAmount', 'consumptionAmount'])

  return hasValue(sourceId)
    && hasValue(sourceType)
    && hasValue(projectId)
    && hasValue(projectName)
    && isFiniteNumber(selectedTimes)
    && Number(selectedTimes) > 0
    && hasActualCraftsmen(actualCraftsmen)
    && isFiniteNumber(writeoffAmount)
    && isFiniteNumber(laborPerformanceAmount)
    && isFiniteNumber(consumptionPerformanceAmount)
}

function hasCompleteReviewSummary(summary) {
  if (!summary) return false
  const selectedTimes = readValue(summary, ['selectedTimes', 'writeoffTimes', 'times'])
  const writeoffAmount = readValue(summary, ['writeoffAmount'])
  const laborPerformanceAmount = readValue(summary, ['laborPerformanceAmount', 'laborAmount'])
  const consumptionPerformanceAmount = readValue(summary, ['consumptionPerformanceAmount', 'consumptionAmount'])

  return isFiniteNumber(selectedTimes)
    && Number(selectedTimes) > 0
    && isFiniteNumber(writeoffAmount)
    && isFiniteNumber(laborPerformanceAmount)
    && isFiniteNumber(consumptionPerformanceAmount)
}

function hasConfirmedMember(preview) {
  return hasValue(readValue(preview?.member, ['id', 'memberId']))
}

function isCompleteBackendPreview(preview, previewToken, lines) {
  return Boolean(preview && typeof preview === 'object')
    && preview.confirmationReady === true
    && hasValue(previewToken)
    && hasConfirmedMember(preview)
    && Array.isArray(lines)
    && lines.length > 0
    && lines.every(hasCompleteReviewLine)
    && hasCompleteReviewSummary(readPreviewSummary(preview))
}

function sourceTypeLabel(source) {
  const value = readValue(source, ['sourceTypeLabel', 'sourceLabel', 'sourceType'])
  if (value === 'card' || value === '卡项') return '卡项'
  if (value === 'independent_gift' || value === '独立赠送') return '独立赠送'
  if (value === 'batch_gift' || value === '批量赠送') return '批量赠送'
  if (value && String(value).includes('gift')) return '赠送项目'
  return value || '权益来源'
}

function sourceIdentity(line) {
  const name = readValue(line, ['sourceName', 'cardName', 'benefitName', 'name']) || '未命名来源'
  const reference = readValue(line, ['sourceReference', 'reference', 'sourceNo', 'cardNo', 'giftNo'])
  return {
    key: readValue(line, ['sourceId', 'benefitSourceId', 'cardId', 'giftId']) || `${sourceTypeLabel(line)}-${name}-${reference || ''}`,
    label: sourceTypeLabel(line),
    name,
    reference: reference || ''
  }
}

function lineIdentity(line, index) {
  return readValue(line, ['id', 'lineId', 'writeoffLineId', 'projectId']) || `writeoff-line-${index}`
}

function normalizeLine(line, source = {}, index = 0) {
  const projectName = readValue(line, ['projectName', 'name', 'projectLabel'])
  const selectedTimes = readValue(line, ['selectedTimes', 'writeoffTimes', 'times', 'quantity'])
  const actualCraftsmen = readValue(line, ['actualCraftsmenSummary', 'actualCraftsmanSummary', 'actualCraftsmen', 'craftsmenSummary'])
  const writeoffAmount = readValue(line, ['writeoffAmount'])
  const laborPerformanceAmount = readValue(line, ['laborPerformanceAmount', 'laborAmount'])
  const consumptionPerformanceAmount = readValue(line, ['consumptionPerformanceAmount', 'consumptionAmount'])
  const merged = {
    ...source,
    ...line,
    sourceName: readValue(line, ['sourceName', 'cardName', 'benefitName']) || source.name || source.sourceName,
    sourceReference: readValue(line, ['sourceReference', 'reference', 'sourceNo']) || source.reference || source.sourceReference,
    sourceType: readValue(line, ['sourceType']) || source.sourceType,
    sourceTypeLabel: readValue(line, ['sourceTypeLabel', 'sourceLabel']) || source.sourceTypeLabel
  }

  return {
    ...merged,
    key: lineIdentity(merged, index),
    projectName: projectName || '未命名项目',
    selectedTimes,
    actualCraftsmen,
    writeoffAmount,
    laborPerformanceAmount,
    consumptionPerformanceAmount
  }
}

function normalizePreviewGroup(group, groupIndex) {
  const lines = readFirstArray(group, ['lines', 'items', 'projects', 'projectLines']) || []
  return lines.map((line, lineIndex) => normalizeLine(line, group, `${groupIndex}-${lineIndex}`))
}

function readReviewLines(preview) {
  const directLines = readFirstArray(preview, ['lines', 'items', 'projectLines', 'selectedProjects'])
  if (directLines !== null) return directLines.map((line, index) => normalizeLine(line, {}, index))

  const groupedPreview = readFirstArray(preview, ['sourceGroups', 'sources'])
  if (groupedPreview !== null) {
    return groupedPreview.flatMap((group, groupIndex) => normalizePreviewGroup(group, groupIndex))
  }

  // 草稿不能充当确认预览；缺少后端行明细时必须等待后端核对。
  return []
}

function groupLines(lines) {
  const groups = new Map()
  lines.forEach((line) => {
    const identity = sourceIdentity(line)
    if (!groups.has(identity.key)) {
      groups.set(identity.key, { ...identity, lines: [] })
    }
    groups.get(identity.key).lines.push(line)
  })
  return Array.from(groups.values())
}

function readSummary(preview) {
  const source = readPreviewSummary(preview) || {}

  return [
    { key: 'times', label: '本次核销次数', value: readValue(source, ['selectedTimes', 'writeoffTimes', 'times']), type: 'times' },
    { key: 'labor', label: '劳动业绩', value: readValue(source, ['laborPerformanceAmount', 'laborAmount']), type: 'money' },
    { key: 'consumption', label: '消耗业绩', value: readValue(source, ['consumptionPerformanceAmount', 'consumptionAmount']), type: 'money' }
  ]
}

function displayValue(value, type) {
  if (value === undefined || value === null || value === '') return '—'
  if (type === 'money') return formatMoney(value)
  if (type === 'times') return `${value} 次`
  return value
}

function displayCraftsmen(value) {
  if (Array.isArray(value)) {
    const names = value.map((item) => item?.name || item?.staffName || item?.label).filter(Boolean)
    return names.length ? names.join('、') : '待后端确认'
  }
  return value || '待后端确认'
}

function callbackResultError(result, fallback) {
  if (result === false || result?.success === false || result?.ok === false || result?.status === 'failed') {
    return result?.message || result?.errorMessage || result?.error || fallback
  }
  return ''
}

function callbackResultStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return normalizeStatus(response?.result?.status || response?.status)
}

function actionContext() {
  return {
    idempotencyKey: submitCommandId.value,
    writeoffDraftId: readValue(props.writeoff, ['draftId', 'id', 'writeoffDraftId']),
    recordVersion: readValue(props.writeoff, ['revision', 'recordVersion', 'version']),
    confirmationPreviewToken: backendPreviewToken.value,
    confirmationPreviewVersion: readValue(props.preview, ['previewVersion', 'confirmationVersion', 'version'])
  }
}

function queryContext() {
  return {
    queryResultAction,
    requestNo: requestNo.value,
    originalIdempotencyKey: originalIdempotencyKey.value,
    writeoffDraftId: readValue(props.writeoff, ['draftId', 'id', 'writeoffDraftId']),
    recordVersion: readValue(props.writeoff, ['revision', 'recordVersion', 'version'])
  }
}

async function performSubmit(isRetry = false) {
  if (isRetry ? !canRetry.value : !canSubmit.value) {
    callbackError.value = '正在等待后端核对，请返回编辑后重新发起核对。'
    return
  }
  if (isLocked.value) return
  callbackError.value = ''

  if (typeof props.onSubmit !== 'function') {
    callbackError.value = '暂未接入核销提交服务。'
    return
  }

  if (!submitCommandId.value) submitCommandId.value = createCashierV3CommandId('WRITEOFF')
  isSubmitRequested.value = true
  localSafetyStatus.value = 'processing'

  try {
    const result = await props.onSubmit(actionContext())
    const status = callbackResultStatus(result)
    const error = callbackResultError(result, '核销提交请求未完成，请稍后重试。')

    if (status === 'failed') {
      localSafetyStatus.value = 'failed'
      callbackError.value = error
      isSubmitRequested.value = false
      return
    }
    if (status === 'succeeded') {
      localSafetyStatus.value = 'succeeded'
      isSubmitRequested.value = false
      return
    }
    if (status === 'pending_confirmation' || status === 'result_unknown') {
      localSafetyStatus.value = status
      return
    }

    localSafetyStatus.value = 'result_unknown'
    callbackError.value = error || '未收到明确的核销结果。请查询原请求，禁止重新提交。'
  } catch (error) {
    localSafetyStatus.value = 'result_unknown'
    callbackError.value = error?.message || '网络中断，未能确认核销结果。请查询原请求，禁止重新提交。'
  }
}

function submit() {
  return performSubmit(false)
}

function retry() {
  if (!canRetry.value || isReturning.value || isQuerying.value) return
  submitCommandId.value = createCashierV3CommandId('WRITEOFF')
  isSubmitRequested.value = false
  localSafetyStatus.value = ''
  return performSubmit(true)
}

async function queryOriginalResult() {
  if (!canQueryOriginalResult.value || isQuerying.value) return
  callbackError.value = ''
  isQuerying.value = true

  try {
    const result = await props.onQuery(queryContext())
    const status = callbackResultStatus(result)
    if (status === 'failed' || status === 'succeeded') {
      localSafetyStatus.value = status
      isSubmitRequested.value = false
      return
    }
    localSafetyStatus.value = status === 'pending_confirmation' ? status : 'result_unknown'
    const error = callbackResultError(result, '暂未查询到明确结果，请稍后继续查询原请求。')
    if (error) callbackError.value = error
  } catch (error) {
    localSafetyStatus.value = 'result_unknown'
    callbackError.value = error?.message || '查询原核销请求失败，请稍后继续查询；禁止重新提交。'
  } finally {
    isQuerying.value = false
  }
}

async function returnToEdit() {
  if (!canReturnToEdit.value || isLocked.value) return
  callbackError.value = ''

  if (typeof props.onReturn !== 'function') {
    emit('close', { reason: 'return' })
    return
  }

  isReturning.value = true
  try {
    const result = await props.onReturn({
      writeoffDraftId: readValue(props.writeoff, ['draftId', 'id', 'writeoffDraftId']),
      recordVersion: readValue(props.writeoff, ['revision', 'recordVersion', 'version'])
    })
    const error = callbackResultError(result, '暂未能返回编辑，请稍后重试。')
    if (error) {
      callbackError.value = error
      return
    }
    emit('close', { reason: 'return' })
  } catch (error) {
    callbackError.value = error?.message || '暂未能返回编辑，请稍后重试。'
  } finally {
    isReturning.value = false
  }
}

function closeOverlay() {
  if (!canCloseOverlay.value) return
  emit('close', { reason: 'close' })
}
</script>

<template>
  <div class="writeoff-confirmation" role="presentation" @click.self="closeOverlay">
    <section class="writeoff-confirmation__dialog" role="dialog" aria-modal="true" aria-label="核对并提交核销">
      <header class="writeoff-confirmation__header">
        <div>
          <p class="writeoff-confirmation__eyebrow">核销</p>
          <h2>核对并提交</h2>
          <span>仅展示本次核销、手艺人与业绩结果。</span>
        </div>
        <button type="button" class="writeoff-confirmation__close" :disabled="!canCloseOverlay" aria-label="关闭核销核对" @click="closeOverlay">×</button>
      </header>

      <section class="writeoff-confirmation__member" aria-label="本次核销会员">
        <span>会员</span>
        <strong>{{ member?.name || member?.memberName || '未选择会员' }}</strong>
        <span v-if="member?.phone || member?.mobile">{{ member.phone || member.mobile }}</span>
        <span v-if="member?.memberNo || member?.memberCode">{{ member.memberNo || member.memberCode }}</span>
      </section>

      <section v-if="isResult" class="writeoff-confirmation__result" :class="`writeoff-confirmation__result--${previewStatus}`" aria-live="polite">
        <span v-if="isProcessing || isPendingConfirmation" class="writeoff-confirmation__spinner" aria-hidden="true" />
        <span v-else class="writeoff-confirmation__result-icon" aria-hidden="true">{{ isSucceeded ? '✓' : isResultUnknown ? '?' : '!' }}</span>
        <div>
          <strong>{{ resultTitle }}</strong>
          <p>{{ resultDescription }}</p>
          <small v-if="requestNo">请求号：{{ requestNo }}</small>
        </div>
      </section>

      <main class="writeoff-confirmation__content">
        <div v-if="!isResult" class="writeoff-confirmation__hint">{{ resultDescription }}</div>
        <p v-if="callbackError" class="writeoff-confirmation__message" role="alert">{{ callbackError }}</p>

        <section v-if="hasReviewLines" class="writeoff-confirmation__review" aria-label="本次核销明细">
          <section v-for="group in sourceGroups" :key="group.key" class="writeoff-confirmation__source">
            <header class="writeoff-confirmation__source-header">
              <div>
                <span class="writeoff-confirmation__source-tag">{{ group.label }}</span>
                <strong>{{ group.name }}</strong>
              </div>
              <span v-if="group.reference">{{ group.reference }}</span>
            </header>

            <div class="writeoff-confirmation__table-wrap">
              <table class="writeoff-confirmation__table">
                <thead>
                  <tr>
                    <th>核销项目</th>
                    <th>本次次数</th>
                    <th>实际手艺人</th>
                    <th>劳动业绩</th>
                    <th>消耗业绩</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="line in group.lines" :key="line.key">
                    <td><strong>{{ line.projectName }}</strong></td>
                    <td>{{ displayValue(line.selectedTimes, 'times') }}</td>
                    <td>{{ displayCraftsmen(line.actualCraftsmen) }}</td>
                    <td>{{ displayValue(line.laborPerformanceAmount, 'money') }}</td>
                    <td>{{ displayValue(line.consumptionPerformanceAmount, 'money') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </section>

        <section v-else class="writeoff-confirmation__empty">
          <strong>暂无可核对的核销项目</strong>
          <span>请返回编辑页选择具体卡项或赠送项目。</span>
        </section>

        <section class="writeoff-confirmation__summary" aria-label="核销汇总">
          <div v-for="item in reviewSummary" :key="item.key">
            <span>{{ item.label }}</span>
            <strong>{{ displayValue(item.value, item.type) }}</strong>
          </div>
        </section>
      </main>

      <footer class="writeoff-confirmation__footer">
        <template v-if="isSucceeded">
          <button type="button" class="writeoff-confirmation__button writeoff-confirmation__button--primary" @click="closeOverlay">完成</button>
        </template>
        <template v-else-if="isFailed">
          <span v-if="!canReturnToEdit && !canRetry" class="writeoff-confirmation__locked-tip">后端尚未明确允许返回或重试，请按失败原因处理。</span>
          <button v-if="canReturnToEdit" type="button" class="writeoff-confirmation__button" :disabled="isLocked" @click="returnToEdit">返回编辑</button>
          <button v-if="canRetry" type="button" class="writeoff-confirmation__button writeoff-confirmation__button--primary" :disabled="isLocked || !hasReviewLines" @click="retry">重新提交</button>
        </template>
        <template v-else-if="isUncertain">
          <span v-if="!canQueryOriginalResult" class="writeoff-confirmation__locked-tip">原核销请求信息尚未完整加载，禁止关闭或重新提交，请联系管理员核对。</span>
          <button v-else type="button" class="writeoff-confirmation__button writeoff-confirmation__button--primary" :disabled="isQuerying" @click="queryOriginalResult">
            {{ isQuerying ? '正在查询…' : '查询原核销结果' }}
          </button>
        </template>
        <template v-else-if="isProcessing">
          <span class="writeoff-confirmation__locked-tip">正在提交，请勿关闭或重复操作。</span>
        </template>
        <template v-else>
          <button type="button" class="writeoff-confirmation__button" :disabled="isLocked" @click="returnToEdit">返回编辑</button>
          <button type="button" class="writeoff-confirmation__button writeoff-confirmation__button--primary" :disabled="isLocked || !canSubmit || !hasReviewLines" @click="submit">
            {{ isSubmitRequested ? '正在提交…' : '确认并提交' }}
          </button>
        </template>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.writeoff-confirmation {
  position: fixed;
  z-index: 80;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgba(13, 25, 38, .48);
}

.writeoff-confirmation__dialog {
  display: flex;
  flex-direction: column;
  width: min(1120px, 100%);
  max-height: min(820px, calc(100vh - 48px));
  overflow: hidden;
  border: 1px solid #dfe5ec;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 24px 72px rgba(22, 36, 53, .24);
}

.writeoff-confirmation__header,
.writeoff-confirmation__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
}

.writeoff-confirmation__header {
  border-bottom: 1px solid #e9edf2;
}

.writeoff-confirmation__eyebrow {
  margin: 0 0 4px;
  color: #7a8795;
  font-size: 12px;
  letter-spacing: .08em;
}

.writeoff-confirmation__header h2 {
  margin: 0;
  color: #1f2d3d;
  font-size: 22px;
  line-height: 1.25;
}

.writeoff-confirmation__header span {
  display: block;
  margin-top: 5px;
  color: #7a8795;
  font-size: 13px;
}

.writeoff-confirmation__close {
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 8px;
  color: #637180;
  background: transparent;
  font-size: 26px;
  line-height: 1;
  cursor: pointer;
}

.writeoff-confirmation__close:hover:not(:disabled) {
  color: #263544;
  background: #f2f5f8;
}

.writeoff-confirmation__close:disabled {
  cursor: not-allowed;
  opacity: .45;
}

.writeoff-confirmation__member {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 54px;
  padding: 0 24px;
  border-bottom: 1px solid #edf0f4;
  color: #667482;
  font-size: 13px;
}

.writeoff-confirmation__member strong {
  color: #1f2d3d;
  font-size: 15px;
}

.writeoff-confirmation__member span:not(:first-child)::before {
  margin-right: 10px;
  color: #c2cad3;
  content: '·';
}

.writeoff-confirmation__result {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin: 16px 24px 0;
  padding: 14px 16px;
  border: 1px solid #dce4ed;
  border-radius: 10px;
  color: #4d5c6b;
  background: #f7f9fb;
}

.writeoff-confirmation__result--succeeded {
  border-color: #c6ead8;
  background: #f1fbf5;
}

.writeoff-confirmation__result--failed {
  border-color: #f0cdcd;
  background: #fff7f7;
}

.writeoff-confirmation__result--pending_confirmation,
.writeoff-confirmation__result--result_unknown {
  border-color: #f0d39d;
  background: #fffaf0;
}

.writeoff-confirmation__result strong {
  display: block;
  color: #233241;
  font-size: 15px;
}

.writeoff-confirmation__result p {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.6;
}

.writeoff-confirmation__result small {
  display: block;
  margin-top: 6px;
  color: #7a8795;
  font-size: 12px;
}

.writeoff-confirmation__result-icon {
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  color: #fff;
  background: #e17f7f;
  font-weight: 700;
}

.writeoff-confirmation__result--succeeded .writeoff-confirmation__result-icon {
  background: #42a871;
}

.writeoff-confirmation__result--result_unknown .writeoff-confirmation__result-icon {
  background: #d8942f;
}

.writeoff-confirmation__spinner {
  flex: 0 0 auto;
  width: 20px;
  height: 20px;
  border: 2px solid #c9d3dd;
  border-top-color: #3c76b8;
  border-radius: 50%;
  animation: writeoff-confirmation-spin .8s linear infinite;
}

@keyframes writeoff-confirmation-spin {
  to { transform: rotate(360deg); }
}

.writeoff-confirmation__content {
  flex: 1;
  overflow: auto;
  padding: 20px 24px 24px;
}

.writeoff-confirmation__hint,
.writeoff-confirmation__message {
  margin: 0 0 16px;
  padding: 12px 14px;
  border-radius: 8px;
  color: #5f6c79;
  background: #f4f7fa;
  font-size: 13px;
  line-height: 1.6;
}

.writeoff-confirmation__message {
  border: 1px solid #f0cdcd;
  color: #b14d4d;
  background: #fff8f8;
}

.writeoff-confirmation__review {
  display: grid;
  gap: 16px;
}

.writeoff-confirmation__source {
  overflow: hidden;
  border: 1px solid #e0e6ec;
  border-radius: 10px;
}

.writeoff-confirmation__source-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 48px;
  padding: 0 16px;
  border-bottom: 1px solid #e8edf2;
  background: #fafbfd;
  color: #73808d;
  font-size: 13px;
}

.writeoff-confirmation__source-header > div {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.writeoff-confirmation__source-header strong {
  overflow: hidden;
  color: #263544;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.writeoff-confirmation__source-tag {
  flex: 0 0 auto;
  padding: 3px 6px;
  border-radius: 4px;
  color: #4a78a8;
  background: #eaf2fb;
  font-size: 12px;
}

.writeoff-confirmation__table-wrap {
  overflow-x: auto;
}

.writeoff-confirmation__table {
  width: 100%;
  min-width: 850px;
  border-collapse: collapse;
  table-layout: fixed;
}

.writeoff-confirmation__table th,
.writeoff-confirmation__table td {
  padding: 13px 16px;
  border-bottom: 1px solid #edf1f4;
  color: #52606e;
  font-size: 13px;
  text-align: left;
  vertical-align: middle;
}

.writeoff-confirmation__table th {
  color: #7b8793;
  background: #fff;
  font-weight: 500;
}

.writeoff-confirmation__table th:nth-child(1) { width: 21%; }
.writeoff-confirmation__table th:nth-child(2) { width: 13%; }
.writeoff-confirmation__table th:nth-child(3) { width: 28%; }
.writeoff-confirmation__table th:nth-child(4),
.writeoff-confirmation__table th:nth-child(5) { width: 19%; }

.writeoff-confirmation__table tbody tr:last-child td {
  border-bottom: 0;
}

.writeoff-confirmation__table td strong {
  color: #2b3947;
}

.writeoff-confirmation__empty {
  display: grid;
  gap: 6px;
  place-items: center;
  min-height: 180px;
  padding: 24px;
  border: 1px dashed #cad3dd;
  border-radius: 10px;
  color: #748190;
  text-align: center;
}

.writeoff-confirmation__empty strong {
  color: #445260;
}

.writeoff-confirmation__summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 18px;
}

.writeoff-confirmation__summary > div {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border: 1px solid #e3e8ee;
  border-radius: 9px;
  background: #fbfcfd;
}

.writeoff-confirmation__summary span {
  color: #7a8794;
  font-size: 12px;
}

.writeoff-confirmation__summary strong {
  color: #293846;
  font-size: 16px;
}

.writeoff-confirmation__footer {
  justify-content: flex-end;
  min-height: 76px;
  border-top: 1px solid #e8edf2;
  background: #fff;
}

.writeoff-confirmation__button {
  min-width: 104px;
  height: 38px;
  padding: 0 16px;
  border: 1px solid #cfd8e1;
  border-radius: 7px;
  color: #445260;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
}

.writeoff-confirmation__button:hover:not(:disabled) {
  border-color: #94adc8;
  color: #2f5d8e;
}

.writeoff-confirmation__button--primary {
  border-color: #3976b4;
  color: #fff;
  background: #3976b4;
}

.writeoff-confirmation__button--primary:hover:not(:disabled) {
  border-color: #2f659d;
  color: #fff;
  background: #2f659d;
}

.writeoff-confirmation__button:disabled {
  cursor: not-allowed;
  opacity: .48;
}

.writeoff-confirmation__locked-tip {
  color: #708090;
  font-size: 13px;
}

@media (max-width: 760px) {
  .writeoff-confirmation {
    padding: 0;
  }

  .writeoff-confirmation__dialog {
    width: 100%;
    max-height: 100vh;
    min-height: 100vh;
    border: 0;
    border-radius: 0;
  }

  .writeoff-confirmation__header,
  .writeoff-confirmation__footer,
  .writeoff-confirmation__content {
    padding-right: 16px;
    padding-left: 16px;
  }

  .writeoff-confirmation__member {
    flex-wrap: wrap;
    min-height: auto;
    padding: 12px 16px;
  }

  .writeoff-confirmation__result {
    margin-right: 16px;
    margin-left: 16px;
  }

  .writeoff-confirmation__summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
