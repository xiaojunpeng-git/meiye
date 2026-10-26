<script setup>
import { computed, ref, watch } from 'vue'
import { createCashierV3CommandId, formatMoney } from '@/services/cashierV3Bridge'

const props = defineProps({
  member: {
    type: Object,
    default: null
  },
  cartLines: {
    type: Array,
    default: () => []
  },
  summary: {
    type: Object,
    default: () => ({})
  },
  hangOrder: {
    type: Object,
    default: () => ({})
  },
  serviceOrder: {
    type: Object,
    default: null
  },
  onSubmit: {
    type: Function,
    default: null
  },
  onQuery: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close'])
const selectedMode = ref('normal')
const selectedRoomId = ref('')
const submitCommandId = ref(null)
const isCalling = ref(false)
const isQuerying = ref(false)
const submitError = ref('')
const localSafetyStatus = ref('')

const backendStatus = computed(() => normalizeStatus(props.hangOrder.status))
const submissionStatus = computed(() => localSafetyStatus.value || backendStatus.value)
const isProcessing = computed(() => submissionStatus.value === 'processing')
const isPendingConfirmation = computed(() => submissionStatus.value === 'pending_confirmation')
const isResultUnknown = computed(() => submissionStatus.value === 'result_unknown')
const isUncertain = computed(() => isPendingConfirmation.value || isResultUnknown.value)
const isFailed = computed(() => submissionStatus.value === 'failed')
const isLocked = computed(() => isCalling.value || isQuerying.value || isProcessing.value || isUncertain.value)
const hasAuthoritativePreparation = computed(() => (
  props.hangOrder.preparationReady === true
  || props.hangOrder.snapshotReady === true
))
const roomCandidates = computed(() => {
  if (Array.isArray(props.hangOrder.roomCandidates)) return props.hangOrder.roomCandidates
  if (Array.isArray(props.hangOrder.candidates)) return props.hangOrder.candidates
  return []
})
const selectedRoom = computed(() => roomCandidates.value.find((room) => String(room.id || room.roomId) === String(selectedRoomId.value)) || null)
const serviceStartUnavailableReason = computed(() => {
  if (!hasAuthoritativePreparation.value) return '挂单准备数据尚未完整加载。'
  if (props.hangOrder.startServiceAvailable !== true) {
    return props.hangOrder.startServiceUnavailableReason || '当前订单暂不能直接开始服务。'
  }
  return ''
})
const selectedRoomIsValid = computed(() => (
  !selectedRoomId.value
  || Boolean(selectedRoom.value && roomIsSelectable(selectedRoom.value))
))
const canRetry = computed(() => isFailed.value && props.hangOrder.canRetry === true)
const canSubmit = computed(() => (
  hasAuthoritativePreparation.value
  && props.cartLines.length > 0
  && !isLocked.value
  && (submissionStatus.value === 'editing' || canRetry.value)
  && (selectedMode.value !== 'start_service' || (!serviceStartUnavailableReason.value && selectedRoomIsValid.value))
))
const canClose = computed(() => (
  (!isProcessing.value && !isUncertain.value && !isCalling.value && !isQuerying.value)
  && (!isFailed.value || props.hangOrder.canClose === true)
))
const requestNo = computed(() => (
  props.hangOrder.requestNo
  || props.hangOrder.hangRequestNo
  || props.hangOrder.requestId
  || ''
))
const queryResultAction = 'query-hang-order-result'
const originalIdempotencyKey = computed(() => (
  submitCommandId.value
  || props.hangOrder.originalIdempotencyKey
  || ''
))
const canQueryOriginalResult = computed(() => (
  isUncertain.value
  && Boolean(requestNo.value)
  && Boolean(originalIdempotencyKey.value)
  && typeof props.onQuery === 'function'
))

function chooseMode(mode) {
  if (isLocked.value || (mode === 'start_service' && serviceStartUnavailableReason.value)) return
  selectedMode.value = mode
}

function chooseRoom(room) {
  if (isLocked.value || !roomIsSelectable(room)) return
  selectedRoomId.value = room.id || room.roomId
}

function normalizeStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  if (['processing', 'submitting', '处理中', '提交中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) {
    return 'pending_confirmation'
  }
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'result_unknown'
}

function roomIsSelectable(room = {}) {
  return room.selectable === true || room.canSelect === true
}

function resultStatus(result) {
  const nested = result?.data && typeof result.data === 'object' ? result.data : null
  return normalizeStatus(
    result?.result?.status
    || nested?.result?.status
    || nested?.status
    || result?.status
  )
}

function resultMessage(result) {
  return result?.result?.message
    || result?.message
    || result?.data?.result?.message
    || result?.data?.message
    || '暂时无法完成挂单，请检查后重试。'
}

async function submit() {
  if (!canSubmit.value || !props.onSubmit) return
  if (!submitCommandId.value) submitCommandId.value = createCashierV3CommandId('HANG')
  isCalling.value = true
  localSafetyStatus.value = 'processing'
  submitError.value = ''
  try {
    const result = await props.onSubmit({
      mode: selectedMode.value,
      roomId: selectedMode.value === 'start_service' ? selectedRoomId.value || null : null,
      preparationToken: props.hangOrder.preparationToken || props.hangOrder.snapshotToken,
      idempotencyKey: submitCommandId.value
    })
    const status = resultStatus(result)
    if (status === 'failed') {
      localSafetyStatus.value = 'failed'
      submitError.value = resultMessage(result)
      return
    }
    if (status === 'succeeded') {
      emit('close')
      return
    }
    if (status === 'pending_confirmation' || status === 'result_unknown') {
      localSafetyStatus.value = status
      return
    }
    localSafetyStatus.value = 'result_unknown'
    submitError.value = '未收到明确的挂单结果。请查询原请求，禁止再次挂单。'
  } catch (error) {
    localSafetyStatus.value = 'result_unknown'
    submitError.value = error?.message || '网络中断，未能确认挂单结果。请查询原请求，禁止再次挂单。'
  } finally {
    isCalling.value = false
  }
}

async function queryOriginalResult() {
  if (!canQueryOriginalResult.value || isQuerying.value) return
  isQuerying.value = true
  submitError.value = ''
  try {
    const result = await props.onQuery({
      queryResultAction,
      requestNo: requestNo.value,
      originalIdempotencyKey: originalIdempotencyKey.value
    })
    const status = resultStatus(result)
    if (status === 'succeeded') {
      emit('close')
      return
    }
    if (status === 'failed') {
      localSafetyStatus.value = 'failed'
      submitError.value = resultMessage(result)
      return
    }
    localSafetyStatus.value = status === 'pending_confirmation' ? status : 'result_unknown'
  } catch (error) {
    localSafetyStatus.value = 'result_unknown'
    submitError.value = error?.message || '查询原挂单请求失败，请稍后继续查询。'
  } finally {
    isQuerying.value = false
  }
}

function closeOverlay() {
  if (!canClose.value) return
  emit('close')
}

watch(
  backendStatus,
  (status) => {
    if (status !== 'editing') {
      localSafetyStatus.value = status
    } else if (!isCalling.value && !isQuerying.value) {
      localSafetyStatus.value = ''
    }
  },
  { immediate: true }
)

watch(
  () => props.hangOrder.preparationToken || props.hangOrder.snapshotToken,
  (token, previousToken) => {
    if (token !== previousToken && submissionStatus.value === 'editing' && !isLocked.value) {
      submitCommandId.value = null
    }
    if (!token || token === previousToken || isLocked.value) return
    const preferredMode = props.hangOrder.preferredMode === 'start_service' ? 'start_service' : 'normal'
    const preferredRoomId = props.hangOrder.preferredRoomId
    const preferredRoom = roomCandidates.value.find((room) => (
      String(room.id || room.roomId) === String(preferredRoomId)
    ))
    if (preferredMode === 'start_service'
      && !serviceStartUnavailableReason.value
      && preferredRoom
      && roomIsSelectable(preferredRoom)) {
      selectedMode.value = 'start_service'
      selectedRoomId.value = preferredRoom.id || preferredRoom.roomId
      return
    }
    selectedMode.value = 'normal'
    selectedRoomId.value = ''
  },
  { immediate: true }
)

watch(roomCandidates, () => {
  if (selectedRoomId.value && !selectedRoomIsValid.value) selectedRoomId.value = ''
})
</script>

<template>
  <section class="hang-order-overlay" aria-label="挂单">
    <header class="hang-order-overlay__header">
      <div>
        <h2>挂单</h2>
        <span>保存后可在“挂单”菜单继续处理；不会产生正式订单、收款或业绩。</span>
      </div>
      <button type="button" class="button button--secondary" :disabled="!canClose" @click="closeOverlay">返回收银</button>
    </header>

    <main class="hang-order-overlay__content">
      <section class="hang-order-summary-card">
        <div>
          <span>本次会员</span>
          <strong>{{ member ? `${member.name} · ${member.phone}` : '游客订单' }}</strong>
        </div>
        <div>
          <span>已选商品</span>
          <strong>{{ summary.selectedCount || cartLines.length }} 项</strong>
        </div>
        <div>
          <span>应收金额</span>
          <strong>{{ formatMoney(summary.receivableAmount) }}</strong>
        </div>
      </section>

      <section class="hang-order-mode-section">
        <header>
          <h3>选择挂单方式</h3>
          <span>一步选择即可；房间始终可不选。</span>
        </header>

        <div class="hang-order-mode-grid">
          <button
            type="button"
            class="hang-order-mode-card"
            :class="{ 'hang-order-mode-card--active': selectedMode === 'normal' }"
            :disabled="isLocked"
            @click="chooseMode('normal')"
          >
            <strong>普通挂单</strong>
            <span>暂存当前订单，稍后在挂单菜单提单后结账。</span>
          </button>
          <button
            type="button"
            class="hang-order-mode-card"
            :class="{
              'hang-order-mode-card--active': selectedMode === 'start_service',
              'hang-order-mode-card--disabled': serviceStartUnavailableReason
            }"
            :disabled="isLocked || Boolean(serviceStartUnavailableReason)"
            @click="chooseMode('start_service')"
          >
            <strong>挂单并开始服务</strong>
            <span>建立唯一的本次服务单；开始服务后才会实际占用所选房间。</span>
          </button>
        </div>
        <p v-if="serviceStartUnavailableReason" class="hang-order-mode-section__hint">{{ serviceStartUnavailableReason }}</p>
      </section>

      <section v-if="selectedMode === 'start_service'" class="hang-order-room-section">
        <header>
          <div>
            <h3>服务房间 <span>（可不选）</span></h3>
            <p>不选时显示“待分配房间”，不影响开始服务。</p>
          </div>
          <button type="button" class="button button--text" :disabled="isLocked" @click="selectedRoomId = ''">暂不分配</button>
        </header>
        <div class="hang-order-room-grid">
          <button
            type="button"
            class="hang-order-room-card"
            :class="{ 'hang-order-room-card--active': !selectedRoomId }"
            :disabled="isLocked"
            @click="selectedRoomId = ''"
          >
            <strong>待分配房间</strong>
            <span>开始服务后仍可从房态图分配房间。</span>
          </button>
          <button
            v-for="room in roomCandidates"
            :key="room.id || room.roomId"
            type="button"
            class="hang-order-room-card"
            :class="{
              'hang-order-room-card--active': String(selectedRoomId) === String(room.id || room.roomId),
              'hang-order-room-card--disabled': !roomIsSelectable(room)
            }"
            :disabled="isLocked || !roomIsSelectable(room)"
            @click="chooseRoom(room)"
          >
            <strong>{{ room.name }}</strong>
            <span>{{ room.categoryName || room.categoryLabel }} · {{ room.nextReservation || room.availabilityDescription || '后端已确认可安排' }}</span>
            <span v-if="!roomIsSelectable(room)">{{ room.disabledReason || room.conflictReason || '当前不可选择' }}</span>
          </button>
        </div>
        <p v-if="!roomCandidates.length" class="hang-order-room-section__empty">后端当前没有返回可安排房间；仍可选择“待分配房间”。</p>
      </section>

      <section v-if="isProcessing || isUncertain" class="hang-order-overlay__status" aria-live="polite">
        <strong>{{ isProcessing ? '正在保存挂单' : isResultUnknown ? '挂单结果暂时未知' : '正在确认原挂单结果' }}</strong>
        <span>{{ isProcessing ? '请勿关闭或重复操作。' : '只能查询这一次原请求，禁止再次挂单。' }}</span>
        <span v-if="requestNo">请求号：{{ requestNo }}</span>
      </section>
      <p v-if="submitError" class="hang-order-overlay__error">{{ submitError }}</p>
    </main>

    <footer class="hang-order-overlay__footer">
      <template v-if="isUncertain">
        <span v-if="!canQueryOriginalResult" class="hang-order-overlay__locked-tip">原请求信息尚未完整加载，请联系管理员核对；禁止重新挂单。</span>
        <button v-else type="button" class="button button--primary" :disabled="isQuerying" @click="queryOriginalResult">
          {{ isQuerying ? '正在查询…' : '查询原挂单结果' }}
        </button>
      </template>
      <template v-else-if="isProcessing">
        <span class="hang-order-overlay__locked-tip">正在保存，请勿关闭或重复操作。</span>
      </template>
      <template v-else>
        <button type="button" class="button button--secondary" :disabled="!canClose" @click="closeOverlay">取消</button>
        <button type="button" class="button button--primary" :disabled="!canSubmit" @click="submit">
          {{ isCalling ? '正在保存…' : isFailed ? '按原请求重试' : selectedMode === 'start_service' ? '挂单并开始服务' : '确认挂单' }}
        </button>
      </template>
    </footer>
  </section>
</template>
