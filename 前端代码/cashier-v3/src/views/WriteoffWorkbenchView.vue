<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import WriteoffConfirmationOverlay from '@/components/writeoff/WriteoffConfirmationOverlay.vue'
import {
  createCashierV3CommandId,
  formatMoney,
  requestCashierV3Action,
  useCashierV3State
} from '@/services/cashierV3Bridge'

const state = useCashierV3State()
const keyword = ref('')
const sourceFilter = ref('valid')
const serviceObjectSelections = ref({})
const isConfirmationOpen = ref(false)
const confirmationPreview = ref({})
const writeoffPreparationId = ref(null)
const writeoffSession = ref(null)
const isPreparingServiceCompletion = ref(false)
const serviceCompletionPreparationId = ref(null)
const isOpeningServiceCheckout = ref(false)
const serviceCheckoutActionId = ref(null)
const writeoffContextEpoch = ref(0)
const allowedPrimaryServiceActions = new Set([
  'prepare-service-completion',
  'open-service-checkout'
])

const writeoff = computed(() => state.writeoff || {})
const member = computed(() => writeoff.value.member || null)
const sources = computed(() => Array.isArray(writeoff.value.sources) ? writeoff.value.sources : [])
const summary = computed(() => writeoff.value.summary || {})
const serviceSession = computed(() => writeoff.value.activeServiceSession || null)
const supplement = computed(() => writeoff.value.supplement || null)
const writeoffConfirmation = computed(() => writeoffSession.value?.preview || {})
const writeoffOverlayDraft = computed(() => writeoffSession.value?.writeoff || {})
const sourceFilters = computed(() => [
  { key: 'valid', label: '有效卡项', count: sources.value.filter((source) => source.status === '可用' && !source.disabled).length },
  { key: 'expiring', label: '即将到期', count: sources.value.filter((source) => source.expiring === true).length },
  { key: 'selected', label: '只看已选', count: Number(summary.value.selectedSourceCount || 0) },
  { key: 'all', label: '全部', count: sources.value.length }
])

const visibleSources = computed(() => {
  const normalizedKeyword = keyword.value.trim().toLocaleLowerCase()
  return sources.value.filter((source) => {
    const sourceText = `${source.name || ''} ${source.reference || ''} ${(source.projects || []).map((project) => project.name).join(' ')}`
      .toLocaleLowerCase()
    const selectedMatched = sourceFilter.value !== 'selected' || Number(source.selectedProjectCount) > 0
    const statusMatched = sourceFilter.value === 'all'
      || sourceFilter.value === 'selected'
      || (sourceFilter.value === 'expiring' ? source.expiring === true : source.status === '可用' && !source.disabled)
    const keywordMatched = !normalizedKeyword || sourceText.includes(normalizedKeyword)
    return selectedMatched && statusMatched && keywordMatched
  })
})

const hasSelectedProject = computed(() => Number(summary.value.selectedProjectTypeCount) > 0)
const primaryServiceAction = computed(() => {
  if (!serviceSession.value) return null
  if (serviceSession.value.status === '待结账') return { label: '去结账', action: 'open-service-checkout' }
  return { label: '结束服务', action: 'prepare-service-completion' }
})

watch(
  () => [
    serviceSession.value?.id || serviceSession.value?.serviceOrderId || serviceSession.value?.serviceSessionId,
    serviceSession.value?.revision ?? serviceSession.value?.recordVersion,
    writeoff.value.revision
  ],
  () => {
    serviceCompletionPreparationId.value = null
    serviceCheckoutActionId.value = null
  }
)

watch(
  () => Boolean(serviceSession.value),
  (hasActiveServiceSession) => {
    if (hasActiveServiceSession) closeWriteoffConfirmationForActiveService()
  },
  { immediate: true }
)

function sourceLabel(source) {
  if (source.sourceType === 'batch_gift') return '批量赠送'
  if (source.sourceType && source.sourceType.includes('gift')) return '赠送项目'
  return '卡项'
}

function sourceSummary(source) {
  const times = source.remainingTimes === '不限' ? '不限' : `${source.remainingTimes || 0}`
  const amount = source.remainingAmount === null || source.remainingAmount === undefined
    ? '—'
    : formatMoney(source.remainingAmount)
  return `可用项目：${source.availableProjectCount || 0}　余次 ${times}　余额 ${amount}`
}

function sourceExpiryText(source) {
  return source.expiryText || source.expiresAt || source.expireAt || ''
}

function projectSelectionKey(source, project) {
  return `${source.id}:${project.id}`
}

function serviceObjectFor(source, project) {
  return serviceObjectSelections.value[projectSelectionKey(source, project)] || project.serviceObject || '本人'
}

function selectServiceObject(source, project, serviceObject) {
  if (!project.selected || project.disabled) return
  serviceObjectSelections.value = {
    ...serviceObjectSelections.value,
    [projectSelectionKey(source, project)]: serviceObject
  }
}

function exportedServiceObjects() {
  return Object.entries(serviceObjectSelections.value).map(([key, serviceObject]) => ({ key, serviceObject }))
}

function clonePlain(value = {}) {
  return JSON.parse(JSON.stringify(value && typeof value === 'object' ? value : {}))
}

function matchingWriteoffContext(contexts, draftId, draftVersion) {
  return contexts.some((context) => (
    context?.kind === 'writeoff_draft'
    && String(context.id) === String(draftId)
    && Number(context.expectedVersion ?? context.expected_version) === Number(draftVersion)
  ))
}

function isCompleteWriteoffPreparation(preview, preparationRequestId) {
  if (!preview || typeof preview !== 'object') return false
  if (preview.confirmationReady !== true) return false
  if (String(preview.preparationRequestId || '') !== String(preparationRequestId)) return false
  if (!preview.previewToken && !preview.confirmationToken && !preview.serverPreviewToken) return false
  const contexts = Array.isArray(preview.commandContexts) ? preview.commandContexts : []
  if (!matchingWriteoffContext(contexts, writeoff.value.draftId, writeoff.value.revision)) return false
  const previewMemberId = preview.member?.id || preview.member?.memberId
  const currentMemberId = member.value?.id || member.value?.memberId
  if (!previewMemberId || !currentMemberId || String(previewMemberId) !== String(currentMemberId)) return false
  return true
}

async function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

function serviceSessionPayload() {
  if (!serviceSession.value) return {}
  return {
    serviceOrderId: serviceSession.value.id || serviceSession.value.serviceOrderId || serviceSession.value.serviceSessionId,
    serviceOrderVersion: serviceSession.value.revision ?? serviceSession.value.recordVersion,
    writeoffDraftId: writeoff.value.draftId,
    writeoffDraftVersion: writeoff.value.revision
  }
}

async function requestPrimaryServiceAction() {
  const requestEpoch = writeoffContextEpoch.value
  if (!primaryServiceAction.value) return { success: false, message: '当前没有可继续处理的本次服务单。' }
  if (!allowedPrimaryServiceActions.has(primaryServiceAction.value.action)) {
    return { result: { status: 'failed', code: 'WRITEOFF_SERVICE_ACTION_NOT_ALLOWED', message: '该服务操作未进入前端允许清单。' } }
  }
  const payload = serviceSessionPayload()
  if (primaryServiceAction.value.action !== 'prepare-service-completion') {
    if (isOpeningServiceCheckout.value) return { success: false, message: '正在打开结账，请勿重复操作。' }
    if (!serviceCheckoutActionId.value) serviceCheckoutActionId.value = createCashierV3CommandId('SERVICE_CHECKOUT')
    isOpeningServiceCheckout.value = true
    try {
      const result = await requestAction(primaryServiceAction.value.action, {
        ...payload,
        idempotencyKey: serviceCheckoutActionId.value
      })
      if (requestEpoch !== writeoffContextEpoch.value) {
        return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次结账准备结果已忽略。' } }
      }
      if (['failed', 'conflict'].includes(resultStatus(result))) serviceCheckoutActionId.value = null
      return result
    } finally {
      isOpeningServiceCheckout.value = false
    }
  }
  if (!payload.serviceOrderId) return { success: false, message: '未找到本次服务单，请刷新后重试。' }
  if (isPreparingServiceCompletion.value) return { success: false, message: '正在准备服务确认，请勿重复操作。' }

  if (!serviceCompletionPreparationId.value) {
    serviceCompletionPreparationId.value = createCashierV3CommandId('SERVICE_PREPARE')
  }
  const preparationRequestId = serviceCompletionPreparationId.value
  window.dispatchEvent(new CustomEvent('cashier-v3:register-service-completion-request', {
    detail: { preparationRequestId, serviceOrderId: payload.serviceOrderId }
  }))
  isPreparingServiceCompletion.value = true
  try {
    const result = await requestAction(primaryServiceAction.value.action, {
      ...payload,
      preparationRequestId,
      idempotencyKey: preparationRequestId
    })
    if (requestEpoch !== writeoffContextEpoch.value) {
      return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次服务准备结果已忽略。' } }
    }
    if (['failed', 'conflict'].includes(resultStatus(result))) {
      serviceCompletionPreparationId.value = null
    }
    return result
  } finally {
    isPreparingServiceCompletion.value = false
  }
}

async function toggleProject(source, project) {
  await requestAction('toggle-writeoff-project', {
    sourceId: source.id,
    projectId: project.id
  })
}

async function changeProjectTimes(source, project, delta) {
  await requestAction('change-writeoff-project-times', {
    sourceId: source.id,
    projectId: project.id,
    delta
  })
}

function resultStatus(result) {
  const response = result?.data && typeof result.data === 'object' ? result.data : result
  return response?.result?.status || response?.status || ''
}

function closeWriteoffConfirmationForActiveService() {
  isConfirmationOpen.value = false
  confirmationPreview.value = {}
  writeoffPreparationId.value = null
  writeoffSession.value = null
}

async function openWriteoffConfirmation() {
  const requestEpoch = writeoffContextEpoch.value
  // 关联了本次服务单时，核销必须先进入全局“确认本次服务”。
  // 不能直接 prepare/submit-writeoff 绕过实际完成项目和实际手艺人确认。
  if (serviceSession.value) return requestPrimaryServiceAction()
  const preparationRequestId = createCashierV3CommandId('WRITEOFF_PREPARE')
  writeoffPreparationId.value = preparationRequestId
  writeoffSession.value = null
  const result = await requestAction('prepare-writeoff', {
    preparationRequestId,
    serviceObjects: exportedServiceObjects()
  })
  if (requestEpoch !== writeoffContextEpoch.value) {
    return { result: { status: 'failed', code: 'STATE_CONTEXT_CHANGED', message: '账号或门店已经切换，本次核销准备结果已忽略。' } }
  }
  // 准备请求返回前可能已经关联本次服务单；晚到的核销预览不得重新打开确认层。
  if (serviceSession.value) {
    closeWriteoffConfirmationForActiveService()
    return result
  }
  if (!['failed', 'conflict'].includes(resultStatus(result))) {
    const preview = clonePlain(writeoff.value.confirmation || {})
    if (!isCompleteWriteoffPreparation(preview, preparationRequestId)) {
      return {
        result: {
          status: 'failed',
          code: 'WRITEOFF_PREPARATION_INCOMPLETE',
          message: '核销确认数据尚未完整加载，请刷新后重试。'
        }
      }
    }
    confirmationPreview.value = preview
    writeoffSession.value = Object.freeze({
      stateContextId: String(state.stateContextId || ''),
      preparationRequestId,
      previewToken: preview.previewToken || preview.confirmationToken || preview.serverPreviewToken,
      commandContexts: clonePlain(preview.commandContexts),
      preview: Object.freeze(preview),
      writeoff: Object.freeze(clonePlain(writeoff.value))
    })
    isConfirmationOpen.value = true
  }
  return result
}

function handleOpenWriteoffConfirmation(event) {
  // 后端事件可能晚于服务单状态更新到达，不能用晚到事件绕过服务确认。
  if (serviceSession.value) {
    closeWriteoffConfirmationForActiveService()
    return
  }
  const preview = clonePlain(writeoff.value.confirmation || {})
  const preparationRequestId = event?.detail?.preparationRequestId || preview.preparationRequestId
  if (
    !writeoffPreparationId.value
    || String(preparationRequestId || '') !== String(writeoffPreparationId.value)
    || !isCompleteWriteoffPreparation(preview, writeoffPreparationId.value)
  ) return
  confirmationPreview.value = preview
  writeoffSession.value = Object.freeze({
    stateContextId: String(state.stateContextId || ''),
    preparationRequestId: writeoffPreparationId.value,
    previewToken: preview.previewToken || preview.confirmationToken || preview.serverPreviewToken,
    commandContexts: clonePlain(preview.commandContexts),
    preview: Object.freeze(preview),
    writeoff: Object.freeze(clonePlain(writeoff.value))
  })
  isConfirmationOpen.value = true
}

async function submitWriteoffConfirmation(command = {}) {
  // 提交瞬间再次核对权威页面状态；后端仍必须对 activeServiceSession 做同等拒绝。
  if (serviceSession.value) {
    closeWriteoffConfirmationForActiveService()
    return {
      success: false,
      status: 'blocked',
      message: '该核销已关联本次服务单，请先通过“结束服务”确认实际完成情况。'
    }
  }
  const session = writeoffSession.value
  if (
    !session
    || session.stateContextId !== String(state.stateContextId || '')
    || session.preparationRequestId !== writeoffPreparationId.value
  ) {
    return { result: { status: 'failed', code: 'WRITEOFF_SESSION_EXPIRED', message: '核销确认会话已失效，请返回后重新核对。' } }
  }
  return requestAction('submit-writeoff', {
    ...command,
    writeoffDraftId: session.writeoff.draftId,
    recordVersion: session.writeoff.revision,
    preparationRequestId: session.preparationRequestId,
    confirmationPreviewToken: session.previewToken,
    commandContexts: session.commandContexts
  })
}

async function returnToWriteoffEdit(command = {}) {
  const session = writeoffSession.value
  if (!session || session.stateContextId !== String(state.stateContextId || '')) {
    return { result: { status: 'failed', code: 'WRITEOFF_SESSION_EXPIRED', message: '核销确认会话已失效，请刷新后重试。' } }
  }
  return requestAction('return-to-writeoff-edit', {
    ...command,
    writeoffDraftId: session.writeoff.draftId,
    recordVersion: session.writeoff.revision,
    commandContexts: session.commandContexts
  })
}

async function queryWriteoffConfirmation(command = {}) {
  const originalIdempotencyKey = command.originalIdempotencyKey
  const session = writeoffSession.value
  if (!session || session.stateContextId !== String(state.stateContextId || '') || !originalIdempotencyKey) {
    return {
      success: false,
      status: 'blocked',
      message: '未找到原核销请求标识，请勿重新提交。'
    }
  }
  const { queryAction: ignoredQueryAction, idempotencyKey: ignoredIdempotencyKey, ...payload } = command
  return requestAction('query-writeoff-result', {
    ...payload,
    originalIdempotencyKey,
    preparationRequestId: session.preparationRequestId,
    writeoffDraftId: session.writeoff.draftId
  })
}

function closeWriteoffConfirmation() {
  closeWriteoffConfirmationForActiveService()
}

function resetWriteoffLocalContext() {
  writeoffContextEpoch.value += 1
  closeWriteoffConfirmationForActiveService()
  isPreparingServiceCompletion.value = false
  serviceCompletionPreparationId.value = null
  isOpeningServiceCheckout.value = false
  serviceCheckoutActionId.value = null
  serviceObjectSelections.value = {}
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-writeoff-confirmation', handleOpenWriteoffConfirmation)
  window.addEventListener('cashier-v3:state-context-changing', resetWriteoffLocalContext)
  window.addEventListener('cashier-v3:state-context-changed', resetWriteoffLocalContext)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-writeoff-confirmation', handleOpenWriteoffConfirmation)
  window.removeEventListener('cashier-v3:state-context-changing', resetWriteoffLocalContext)
  window.removeEventListener('cashier-v3:state-context-changed', resetWriteoffLocalContext)
})
</script>

<template>
  <section class="writeoff-workbench" aria-label="核销工作台">
    <div v-if="supplement" class="supplement-banner">
      <div>
        <strong>正在补单核销</strong>
        <span>业务日期：{{ supplement.businessDate }}</span>
      </div>
      <div class="supplement-banner__actions">
        <button type="button" class="button button--text" @click="requestAction('change-writeoff-supplement-date')">修改日期</button>
        <button type="button" class="button button--text" @click="requestAction('exit-writeoff-supplement')">退出补单</button>
      </div>
    </div>

    <div v-if="serviceSession" class="service-session-banner">
      <div>
        <strong>本次服务单 {{ serviceSession.serviceNo }}</strong>
        <span>{{ serviceSession.status }} · {{ serviceSession.roomName || '待分配房间' }}</span>
      </div>
      <button v-if="primaryServiceAction" type="button" class="button button--secondary" :disabled="isPreparingServiceCompletion || isOpeningServiceCheckout" @click="requestPrimaryServiceAction">
        {{ isPreparingServiceCompletion ? '正在准备服务确认…' : isOpeningServiceCheckout ? '正在打开结账…' : primaryServiceAction.label }}
      </button>
    </div>

    <div class="writeoff-workbench__body">
      <aside class="writeoff-sources-panel" aria-label="会员与权益来源">
        <div class="writeoff-source-tools">
          <label class="search-field">
            <span class="sr-only">请选择客户需要服务的项目</span>
            <input v-model="keyword" type="search" placeholder="请选择客户需要服务的项目" autocomplete="off">
          </label>
          <div class="writeoff-source-filters" role="group" aria-label="卡项范围">
            <button
              v-for="filter in sourceFilters"
              :key="filter.key"
              type="button"
              :class="{ 'writeoff-source-filter--active': sourceFilter === filter.key }"
              @click="sourceFilter = filter.key"
            >{{ filter.label }}<span>{{ filter.count }}</span></button>
          </div>
        </div>

        <div class="writeoff-source-list">
          <button
            v-for="source in visibleSources"
            :key="source.id"
            type="button"
            class="writeoff-source-card"
            :class="{
              'writeoff-source-card--selected': Number(source.selectedProjectCount) > 0,
              'writeoff-source-card--disabled': source.disabled || source.status !== '可用'
            }"
            :disabled="source.disabled || source.status !== '可用'"
            @click="requestAction('focus-writeoff-source', { sourceId: source.id })"
          >
            <span class="writeoff-source-card__header">
              <span>
                <em>{{ sourceLabel(source) }}</em>
                <strong :title="source.name">{{ source.name }}</strong>
              </span>
              <span>{{ source.status }}</span>
            </span>
            <span class="writeoff-source-card__reference" :title="source.reference">{{ source.reference }}</span>
            <span class="writeoff-source-card__summary">{{ sourceSummary(source) }}</span>
            <span v-if="sourceExpiryText(source)" class="writeoff-source-card__expiry" :class="{ 'writeoff-source-card__expiry--warning': source.expiring }">{{ sourceExpiryText(source) }}</span>
            <span v-if="source.disabledReason" class="writeoff-source-card__reason">{{ source.disabledReason }}</span>
          </button>

          <div v-if="!visibleSources.length" class="writeoff-source-empty">
            暂无符合条件的权益来源。
          </div>
        </div>
      </aside>

      <main class="writeoff-projects-panel" aria-label="核销项目">
        <div class="writeoff-projects-panel__header">
          <div>
            <h2>选择核销项目</h2>
            <p>每个项目按具体卡项或赠送记录分别核销。</p>
          </div>
          <button type="button" class="button button--text" :disabled="!hasSelectedProject" @click="requestAction('clear-writeoff-selection')">清空已选</button>
        </div>

        <div v-if="member" class="writeoff-source-groups">
          <section v-for="source in visibleSources" :key="source.id" class="writeoff-source-group">
            <header class="writeoff-source-group__header" :class="{ 'writeoff-source-group__header--selected': Number(source.selectedProjectCount) > 0 }">
              <div>
                <strong :title="source.name">{{ source.name }}</strong>
                <span :title="source.reference">{{ source.reference }}</span>
              </div>
              <span>已选 {{ source.selectedProjectCount || 0 }} 项 / {{ source.selectedTimes || 0 }} 次</span>
            </header>

            <div class="writeoff-project-list">
              <div class="writeoff-project-list__header" aria-hidden="true">
                <span />
                <span>项目</span>
                <span>剩余/总次</span>
                <span>本次次数</span>
                <span>服务对象</span>
                <span>手艺人分配</span>
              </div>
              <article v-for="project in source.projects || []" :key="project.id" class="writeoff-project-row" :class="{ 'writeoff-project-row--selected': project.selected, 'writeoff-project-row--disabled': project.disabled }">
                <button
                  type="button"
                  class="writeoff-project-row__check"
                  :class="{ 'writeoff-project-row__check--selected': project.selected }"
                  :disabled="project.disabled"
                  :aria-label="`${project.selected ? '取消选择' : '选择'} ${project.name}`"
                  @click="toggleProject(source, project)"
                >
                  {{ project.selected ? '✓' : '' }}
                </button>
                <div class="writeoff-project-row__info">
                  <strong>{{ project.name }}</strong>
                  <span v-if="project.disabledReason" class="writeoff-project-row__reason">{{ project.disabledReason }}</span>
                  <span v-else>{{ project.sourceType || sourceLabel(source) }}<template v-if="project.reservedTimes"> · 预约占用 {{ project.reservedTimes }}</template></span>
                </div>
                <span class="writeoff-project-row__remaining"><strong>{{ project.remainingTimes || 0 }}</strong>/{{ project.totalTimes || 0 }}</span>
                <div class="quantity-stepper" aria-label="本次核销次数">
                  <button type="button" aria-label="减少本次核销次数" :disabled="!project.selected" @click="changeProjectTimes(source, project, -1)">−</button>
                  <span>{{ project.selectedTimes || 0 }}次</span>
                  <button type="button" aria-label="增加本次核销次数" :disabled="!project.selected" @click="changeProjectTimes(source, project, 1)">＋</button>
                </div>
                <div class="writeoff-project-row__service-object" aria-label="服务对象">
                  <button type="button" :class="{ active: serviceObjectFor(source, project) === '本人' }" :disabled="!project.selected || project.disabled" @click="selectServiceObject(source, project, '本人')">本人</button>
                  <button type="button" :class="{ active: serviceObjectFor(source, project) === '朋友' }" :disabled="!project.selected || project.disabled" @click="selectServiceObject(source, project, '朋友')">朋友</button>
                </div>
                <button type="button" class="writeoff-project-row__staff" :disabled="project.disabled" @click="requestAction('open-writeoff-staff-allocation', { sourceId: source.id, projectId: project.id })">
                  手艺人:{{ project.craftsmenSummary || '待分配' }}
                </button>
              </article>
            </div>
          </section>
        </div>

        <div v-else class="writeoff-project-empty">
          <strong>请先选择会员</strong>
          <span>选择会员后，会在左侧加载可核销的卡项和赠送项目。</span>
        </div>
      </main>
    </div>

    <footer class="writeoff-bottom-bar">
      <div class="writeoff-bottom-bar__summary">
        <span>已选来源<strong>{{ summary.selectedSourceCount || 0 }}</strong></span>
        <span>项目<strong>{{ summary.selectedProjectTypeCount || 0 }}</strong></span>
        <span>本次次数<strong>{{ summary.selectedTimes || 0 }}次</strong></span>
      </div>
      <div class="writeoff-bottom-bar__actions">
        <button type="button" class="button button--secondary" @click="requestAction('open-writeoff-more-actions')">更多操作</button>
        <button type="button" class="button button--secondary" :disabled="!hasSelectedProject || Boolean(serviceSession)" @click="requestAction('start-service-from-writeoff')">开始服务</button>
        <button
          type="button"
          class="button button--primary"
          :disabled="!hasSelectedProject || Boolean(serviceSession) || isPreparingServiceCompletion || isOpeningServiceCheckout"
          :title="serviceSession ? '关联本次服务单时，请先点击“结束服务”确认实际完成情况。' : ''"
          @click="openWriteoffConfirmation"
        >核对并提交</button>
      </div>
    </footer>

    <WriteoffConfirmationOverlay
      v-if="isConfirmationOpen && !serviceSession"
      :writeoff="writeoffOverlayDraft"
      :preview="writeoffConfirmation"
      :on-submit="submitWriteoffConfirmation"
      :on-return="returnToWriteoffEdit"
      :on-query="queryWriteoffConfirmation"
      @close="closeWriteoffConfirmation"
    />
  </section>
</template>
