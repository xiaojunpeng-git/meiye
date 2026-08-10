<script setup>
import { computed, ref } from 'vue'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'

/**
 * 房间状态详情展示壳。
 *
 * detail 必须由后端根据当前账号的数据权限、房间启用状态和最新并发版本返回。
 * 本组件只展示后端快照，不在前端判断房间占用冲突、预约冲突、人员冲突或任何
 * 可执行权限；房间分配、换房、结束服务和结账均由外层回传后端再次裁决。
 *
 * 建议 detail 至少包含：
 * {
 *   id, name, enabled, statusLabel, revision,
 *   serviceOrder: { id, no, revision, member, reservation, serviceStartedAt, expectedEndedAt, actualEndedAt },
 *   pendingAssignments: [], historyReservations: [], upcomingReservations: [],
 *   actions: [{ code, label, disabled, disabledReason }]
 * }
 *
 * onAction 接收：
 * { action, actionCode, roomId, revision, room, serviceOrderId, serviceOrderRevision, reservationId }
 * 外层负责调用后端、处理权限／并发冲突、刷新房态和必要的跳转。
 */
const props = defineProps({
  detail: {
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
  }
})

const emit = defineEmits(['close', 'open-reservation'])

const activeActionKey = ref('')
const actionError = ref('')
const roomDialogRef = ref(null)

const room = computed(() => (props.detail && typeof props.detail === 'object' ? props.detail : {}))
const hasRoom = computed(() => Object.keys(room.value).length > 0)
const roomId = computed(() => firstValue(room.value, ['roomId', 'id']))
const roomName = computed(() => firstValue(room.value, ['roomName', 'name', 'label']) || '—')
const isEnabled = computed(() => {
  const explicit = firstValue(room.value, ['enabled', 'isEnabled', 'active', 'isActive'])
  const status = String(firstValue(room.value, ['status', 'statusCode', 'roomStatus'])).toLowerCase()
  const isExplicitlyDisabled = explicit === false || ['false', '0', 'no'].includes(String(explicit).toLowerCase())
  return !isExplicitlyDisabled && !['disabled', 'stopped', '停用', '已停用'].includes(status)
})
const hasVisibleRoom = computed(() => hasRoom.value && isEnabled.value)

const status = computed(() => normalizeStatus(firstValue(room.value, ['statusLabel', 'statusName', 'status', 'roomStatusLabel', 'roomStatus'])))
const statusText = computed(() => status.value.label)
const roomRevision = computed(() => firstValue(room.value, ['revision', 'recordVersion', 'roomRevision', 'version']))
const serviceOrder = computed(() => firstObject(room.value, ['serviceOrder', 'serviceSession', 'currentService', 'activeServiceOrder']))
const serviceOrderId = computed(() => firstValue(serviceOrder.value, ['serviceOrderId', 'id', 'serviceSessionId']) || firstValue(room.value, ['serviceOrderId', 'serviceSessionId']))
const serviceOrderRevision = computed(() => firstValue(serviceOrder.value, ['revision', 'recordVersion', 'serviceOrderRevision', 'version']) || firstValue(room.value, ['serviceOrderRevision', 'recordVersion', 'version']))
const serviceOrderNo = computed(() => firstValue(serviceOrder.value, ['serviceOrderNo', 'no', 'code', 'serviceNo']) || firstValue(room.value, ['serviceOrderNo', 'serviceNo']))
const reservation = computed(() => firstObject(serviceOrder.value, ['reservation', 'appointment']) || firstObject(room.value, ['reservation', 'appointment']) || {})
const reservationId = computed(() => firstValue(reservation.value, ['reservationId', 'appointmentId', 'id']))
const reservationRevision = computed(() => firstValue(reservation.value, ['revision', 'recordVersion', 'reservationRevision', 'version']) || firstValue(room.value, ['reservationRevision']))
const reservationNo = computed(() => firstValue(reservation.value, ['reservationNo', 'appointmentNo', 'no', 'code']) || firstValue(serviceOrder.value, ['reservationNo', 'appointmentNo']))
const member = computed(() => firstObject(serviceOrder.value, ['member', 'memberInfo', 'customer']) || firstObject(room.value, ['member', 'memberInfo', 'customer']) || {})
const memberName = computed(() => firstValue(member.value, ['name', 'memberName', 'customerName']) || firstValue(serviceOrder.value, ['memberName', 'customerName']) || firstValue(room.value, ['memberName', 'customerName']) || '—')
const memberMeta = computed(() => [
  firstValue(member.value, ['memberNo', 'memberCode', 'code']),
  firstValue(member.value, ['phone', 'mobile', 'memberPhone'])
].filter(Boolean).join(' · '))

const serviceTimes = computed(() => [
  { label: '服务开始', value: firstValue(serviceOrder.value, ['serviceStartedAt', 'actualStartedAt', 'startedAt']) || firstValue(room.value, ['serviceStartedAt', 'actualStartedAt']) || '—' },
  { label: '预计结束', value: firstValue(serviceOrder.value, ['expectedEndedAt', 'estimatedEndedAt', 'expectedEndAt']) || firstValue(room.value, ['expectedEndedAt', 'estimatedEndedAt']) || '—' },
  { label: '实际结束', value: firstValue(serviceOrder.value, ['actualEndedAt', 'serviceEndedAt', 'endedAt']) || firstValue(room.value, ['actualEndedAt', 'serviceEndedAt']) || '未结束' }
])

const pendingAssignments = computed(() => readList(room.value, ['pendingAssignments', 'unassignedServices', 'pendingRoomAssignments']))
const isPendingAssignment = computed(() => room.value.isUnassigned === true
  || room.value.assignmentStatus === 'unassigned'
  || serviceOrder.value?.assignmentStatus === 'unassigned'
  || (!roomId.value && Boolean(serviceOrderId.value)))
const historyReservations = computed(() => readList(room.value, ['historyReservations', 'pastReservations', 'reservationHistory']))
const upcomingReservations = computed(() => readList(room.value, ['upcomingReservations', 'futureReservations', 'nextReservations']))
const activeReservationServices = computed(() => readList(room.value, ['activeReservationServices']))
const roomNote = computed(() => firstValue(room.value, ['note', 'remark', 'description']))

const availableActions = computed(() => {
  const seen = new Set()
  return readList(room.value, ['actions', 'availableActions', 'actionList'])
    .map(normalizeAction)
    .filter(Boolean)
    .filter((action) => {
      if (seen.has(action.key)) return false
      seen.add(action.key)
      return true
    })
})

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return ''
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return ''
}

function firstObject(source, keys) {
  if (!source || typeof source !== 'object') return null
  for (const key of keys) {
    const value = source[key]
    if (value && typeof value === 'object' && !Array.isArray(value)) return value
  }
  return null
}

function readList(source, keys) {
  if (!source || typeof source !== 'object') return []
  for (const key of keys) {
    const value = source[key]
    if (Array.isArray(value)) return value
    if (value && typeof value === 'object') return [value]
  }
  return []
}

function plainText(value, fallback = '—') {
  return value === undefined || value === null || value === '' ? fallback : String(value)
}

function normalizeStatus(value) {
  const raw = String(value || '').trim()
  const normalized = raw.toLowerCase().replace(/[_\s-]/g, '')
  if (['服务中', 'serving', 'inservice', 'service'].includes(raw) || ['服务中', 'serving', 'inservice', 'service'].includes(normalized)) {
    return { key: 'serving', label: '服务中' }
  }
  if (['待结账', 'pendingcheckout', 'checkoutpending', 'awaitingcheckout'].includes(raw) || ['待结账', 'pendingcheckout', 'checkoutpending', 'awaitingcheckout'].includes(normalized)) {
    return { key: 'pending-checkout', label: '待结账' }
  }
  return { key: 'idle', label: '空闲' }
}

function reservationKey(item, index) {
  return firstValue(item, ['id', 'reservationId', 'appointmentId', 'reservationNo', 'appointmentNo', 'no']) || `reservation-${index}`
}

function reservationTitle(item) {
  return firstValue(item, ['memberName', 'customerName', 'name']) || '预约会员'
}

function reservationMeta(item) {
  return [
    firstValue(item, ['reservationNo', 'appointmentNo', 'no']),
    firstValue(item, ['appointmentStartAt', 'reservationStartAt', 'startAt', 'scheduledAt', 'time']),
    firstValue(item, ['projectSummary', 'projectName', 'serviceSummary', 'summary'])
  ].filter(Boolean).join(' · ')
}

function pendingKey(item, index) {
  return firstValue(item, ['id', 'serviceOrderId', 'serviceNo', 'reservationId', 'reservationNo']) || `pending-${index}`
}

function pendingTitle(item) {
  return firstValue(item, ['memberName', 'customerName', 'name']) || '待分配服务'
}

function pendingMeta(item) {
  return [
    firstValue(item, ['serviceOrderNo', 'serviceNo', 'reservationNo', 'appointmentNo']),
    firstValue(item, ['summary', 'projectSummary', 'projectName']),
    firstValue(item, ['startedAt', 'serviceStartedAt', 'appointmentStartAt', 'time'])
  ].filter(Boolean).join(' · ')
}

function openReservation(service) {
  if (!service?.reservationId || activeActionKey.value) return
  emit('open-reservation', service)
}

const actionMap = {
  '结束服务': { key: 'end-service', label: '结束服务' },
  'end-service': { key: 'end-service', label: '结束服务' },
  end_service: { key: 'end-service', label: '结束服务' },
  endservice: { key: 'end-service', label: '结束服务' },
  'prepare-room-service-completion': { key: 'end-service', label: '结束服务' },
  '去结账': { key: 'go-checkout', label: '去结账' },
  'go-checkout': { key: 'go-checkout', label: '去结账' },
  go_checkout: { key: 'go-checkout', label: '去结账' },
  gocheckout: { key: 'go-checkout', label: '去结账' },
  'open-room-service-checkout': { key: 'go-checkout', label: '去结账' },
  '分配房间': { key: 'assign-room', label: '分配房间' },
  'assign-room': { key: 'assign-room', label: '分配房间' },
  assign_room: { key: 'assign-room', label: '分配房间' },
  assignroom: { key: 'assign-room', label: '分配房间' },
  '换房': { key: 'change-room', label: '换房' },
  'change-room': { key: 'change-room', label: '换房' },
  change_room: { key: 'change-room', label: '换房' },
  changeroom: { key: 'change-room', label: '换房' },
  '移出房间': { key: 'remove-room', label: '移出房间' },
  'remove-room': { key: 'remove-room', label: '移出房间' },
  remove_room: { key: 'remove-room', label: '移出房间' },
  removeroom: { key: 'remove-room', label: '移出房间' },
  '查看服务单': { key: 'view-service-order', label: '查看服务单' },
  'view-service-order': { key: 'view-service-order', label: '查看服务单' },
  view_service_order: { key: 'view-service-order', label: '查看服务单' },
  viewserviceorder: { key: 'view-service-order', label: '查看服务单' },
  'open-room-service-session': { key: 'view-service-order', label: '查看服务单' }
}

function normalizeAction(raw) {
  if (!raw) return null
  const source = typeof raw === 'string' ? { code: raw } : raw
  const rawCode = String(firstValue(source, ['code', 'actionCode', 'action', 'key', 'label', 'name'])).trim()
  if (!rawCode) return null
  const normalizedCode = rawCode.toLowerCase().replace(/\s+/g, '').replace(/_/g, '-')
  const mapped = actionMap[rawCode] || actionMap[normalizedCode] || actionMap[normalizedCode.replace(/-/g, '')]
  if (!mapped) return null

  return {
    ...source,
    key: mapped.key,
    label: firstValue(source, ['label', 'name']) || mapped.label,
    raw: source,
    disabled: source.disabled === true || source.enabled === false,
    disabledReason: firstValue(source, ['disabledReason', 'reason', 'hint'])
  }
}

function isActionResultError(result) {
  return result === false || result?.success === false || result?.ok === false
}

function actionClass(action) {
  if (['end-service', 'go-checkout', 'assign-room'].includes(action.key)) return 'room-detail__button--primary'
  if (action.key === 'change-room') return 'room-detail__button--accent'
  return 'room-detail__button--secondary'
}

async function triggerAction(action) {
  if (!props.onAction || action.disabled || activeActionKey.value || !hasVisibleRoom.value) return

  activeActionKey.value = action.key
  actionError.value = ''
  try {
    const result = await props.onAction({
      action: action.raw,
      actionCode: action.key,
      roomId: roomId.value || null,
      revision: roomRevision.value || null,
      room: room.value,
      serviceOrderId: serviceOrderId.value || null,
      serviceOrderRevision: serviceOrderRevision.value || null,
      reservationId: reservationId.value || null,
      reservationRevision: reservationRevision.value || null
    })
    if (isActionResultError(result)) {
      actionError.value = result?.message || result?.errorMessage || result?.error || '操作未完成，请刷新房态后重试。'
    }
  } catch (error) {
    actionError.value = error?.message || '操作未完成，请刷新房态后重试。'
  } finally {
    activeActionKey.value = ''
  }
}

function requestClose() {
  if (activeActionKey.value) return
  emit('close')
}

useModalFocusTrap({
  containerRef: roomDialogRef,
  canClose: () => activeActionKey.value === '',
  onClose: requestClose
})
</script>

<template>
  <Teleport to="body">
    <div class="room-detail" role="presentation" @click.self="requestClose">
      <section ref="roomDialogRef" class="room-detail__dialog" role="dialog" aria-modal="true" aria-labelledby="room-detail-title" tabindex="-1">
      <header class="room-detail__header">
        <div>
          <div class="room-detail__eyebrow">房间详情</div>
          <h2 id="room-detail-title">{{ roomName }}</h2>
          <div v-if="hasVisibleRoom" class="room-detail__status-row">
            <span class="room-detail__status" :class="`room-detail__status--${status.key}`">{{ statusText }}</span>
            <span>{{ activeReservationServices.length ? '房间页按服务中的预约实时展示。' : '房间实际占用以服务开始后的后端房态为准。' }}</span>
          </div>
        </div>
        <button type="button" class="room-detail__close" :disabled="activeActionKey !== ''" aria-label="关闭房间详情" @click="requestClose">×</button>
      </header>

      <main class="room-detail__body" :aria-busy="isLoading">
        <div v-if="isLoading" class="room-detail__loading">
          <span class="room-detail__loading-dot" />
          正在加载房间详情…
        </div>

        <div v-else-if="!hasRoom" class="room-detail__empty">
          <strong>暂无房间详情</strong>
          <span>请返回房态图后重新打开房间。</span>
        </div>

        <div v-else-if="!isEnabled" class="room-detail__empty">
          <strong>该房间已停用</strong>
          <span>停用房间不显示在房态图中，也不能在此处理服务或预约。</span>
        </div>

        <template v-else>
          <section v-if="isPendingAssignment || pendingAssignments.length" class="room-detail__pending-card">
            <div>
              <strong>待分配房间</strong>
              <p>未选择房间的服务可正常开始；分配或换房时由后端按最新房态校验。</p>
            </div>
            <ul v-if="pendingAssignments.length">
              <li v-for="(item, index) in pendingAssignments" :key="pendingKey(item, index)">
                <strong>{{ pendingTitle(item) }}</strong>
                <span>{{ pendingMeta(item) || '待后端分配房间' }}</span>
              </li>
            </ul>
          </section>

          <section class="room-detail__section room-detail__section--overview">
            <header class="room-detail__section-header">
              <h3>当前服务</h3>
              <span v-if="status.key === 'idle'">当前没有实际占用</span>
              <span v-else-if="activeReservationServices.length">当前有 {{ activeReservationServices.length }} 笔预约服务中</span>
              <span v-else>当前房间仅展示一张本次服务单</span>
            </header>

            <ul v-if="activeReservationServices.length" class="room-detail__active-reservation-list">
              <li v-for="(item, index) in activeReservationServices" :key="reservationKey(item, index)">
                <div>
                  <strong>{{ reservationTitle(item) }}</strong>
                  <span>{{ reservationMeta(item) || '—' }}</span>
                </div>
                <span>开始服务：{{ item.serviceStartedAt || '—' }}</span>
                <button type="button" class="room-detail__button room-detail__button--secondary" @click="openReservation(item)">查看预约</button>
              </li>
            </ul>
            <div v-else-if="status.key !== 'idle' || serviceOrderId || memberName !== '—'" class="room-detail__service-card">
              <div class="room-detail__member">
                <span>当前会员</span>
                <strong>{{ memberName }}</strong>
                <small>{{ memberMeta || '—' }}</small>
              </div>
              <dl class="room-detail__service-meta">
                <div><dt>服务单</dt><dd>{{ serviceOrderNo || '—' }}</dd></div>
                <div><dt>关联预约</dt><dd>{{ reservationNo || '无预约' }}</dd></div>
                <div><dt>当前状态</dt><dd>{{ statusText }}</dd></div>
              </dl>
            </div>
            <div v-else class="room-detail__empty-inline">当前房间空闲，暂无服务中的会员。</div>
          </section>

          <section class="room-detail__section room-detail__section--two-columns">
            <div>
              <header class="room-detail__section-header"><h3>服务时间</h3></header>
              <dl class="room-detail__info-list">
                <div v-for="row in serviceTimes" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div>
              </dl>
            </div>
            <div>
              <header class="room-detail__section-header"><h3>房间占用规则</h3></header>
              <p class="room-detail__rule">预约创建和选择房间不会限制后续选择。预约进入服务中后，房间页按预约状态展示当前服务。</p>
              <p class="room-detail__rule room-detail__rule--muted">同一房间可同时展示多笔服务中的预约；结束预约服务后，该预约不再显示在当前服务中。</p>
            </div>
          </section>

          <section class="room-detail__section">
            <header class="room-detail__section-header">
              <h3>历史预约</h3>
              <span>仅供查看，不占用房间</span>
            </header>
            <ul v-if="historyReservations.length" class="room-detail__reservation-list">
              <li v-for="(item, index) in historyReservations" :key="reservationKey(item, index)">
                <strong>{{ reservationTitle(item) }}</strong>
                <span>{{ reservationMeta(item) || '—' }}</span>
                <em>不占用</em>
              </li>
            </ul>
            <div v-else class="room-detail__empty-inline">暂无历史预约</div>
          </section>

          <section class="room-detail__section">
            <header class="room-detail__section-header">
              <h3>即将预约</h3>
              <span>仅作后续安排，不占用房间</span>
            </header>
            <ul v-if="upcomingReservations.length" class="room-detail__reservation-list">
              <li v-for="(item, index) in upcomingReservations" :key="reservationKey(item, index)">
                <strong>{{ reservationTitle(item) }}</strong>
                <span>{{ reservationMeta(item) || '—' }}</span>
                <em>不占用</em>
              </li>
            </ul>
            <div v-else class="room-detail__empty-inline">暂无后续预约</div>
          </section>

          <p v-if="roomNote" class="room-detail__note">{{ roomNote }}</p>
        </template>
      </main>

      <footer class="room-detail__footer">
        <p v-if="actionError" class="room-detail__action-error" role="alert">{{ actionError }}</p>
        <p v-else-if="activeReservationServices.length" class="room-detail__backend-tip">预约服务请在预约详情中结束，结束后房态自动更新。</p>
        <p v-else class="room-detail__backend-tip">操作前会由后端按当前权限、最新房态和版本再次校验。</p>
        <div class="room-detail__actions">
          <button type="button" class="room-detail__button room-detail__button--secondary" :disabled="activeActionKey !== ''" @click="$emit('close')">关闭</button>
          <template v-for="action in availableActions" :key="action.key">
            <button
              type="button"
              class="room-detail__button"
              :class="actionClass(action)"
              :disabled="!onAction || action.disabled || activeActionKey !== '' || !hasVisibleRoom"
              :title="action.disabledReason || ''"
              @click="triggerAction(action)"
            >
              {{ activeActionKey === action.key ? '处理中…' : action.label }}
            </button>
          </template>
        </div>
      </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.room-detail {
  position: fixed;
  z-index: 1260;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 28px;
  background: rgb(15 23 42 / 42%);
}

.room-detail__dialog {
  display: flex;
  flex-direction: column;
  width: min(920px, 100%);
  max-height: min(860px, calc(100vh - 56px));
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 24px 70px rgb(15 23 42 / 25%);
}

.room-detail__header,
.room-detail__footer {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 22px 26px;
}

.room-detail__header {
  border-bottom: 1px solid #edf1f5;
}

.room-detail__eyebrow {
  margin-bottom: 4px;
  color: #8090a5;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .08em;
}

.room-detail h2,
.room-detail h3,
.room-detail p {
  margin: 0;
}

.room-detail h2 {
  color: #172033;
  font-size: 24px;
}

.room-detail__status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 9px;
  color: #738197;
  font-size: 13px;
}

.room-detail__status {
  padding: 4px 9px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.room-detail__status--idle {
  color: #267154;
  background: #e8f7ef;
}

.room-detail__status--serving {
  color: #2764ad;
  background: #eaf3ff;
}

.room-detail__status--pending-checkout {
  color: #a05a17;
  background: #fff2df;
}

.room-detail__close {
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 9px;
  color: #68778c;
  background: #f3f6f9;
  font-size: 24px;
  line-height: 1;
  cursor: pointer;
}

.room-detail__close:disabled,
.room-detail__button:disabled {
  cursor: not-allowed;
  opacity: .55;
}

.room-detail__body {
  flex: 1;
  overflow: auto;
  padding: 22px 26px 28px;
  scrollbar-gutter: stable;
}

.room-detail__loading,
.room-detail__empty {
  display: grid;
  min-height: 180px;
  place-content: center;
  justify-items: center;
  gap: 8px;
  color: #758397;
  text-align: center;
}

.room-detail__empty strong {
  color: #2b3545;
  font-size: 16px;
}

.room-detail__loading-dot {
  width: 28px;
  height: 28px;
  border: 3px solid #d9e7fb;
  border-top-color: #2d74c9;
  border-radius: 50%;
  animation: room-detail-spin .8s linear infinite;
}

.room-detail__pending-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(230px, .85fr);
  gap: 18px;
  margin-bottom: 18px;
  padding: 16px 18px;
  border: 1px solid #d7e6fa;
  border-radius: 12px;
  color: #3c577a;
  background: #f5f9ff;
}

.room-detail__pending-card strong {
  color: #274d7e;
}

.room-detail__pending-card p {
  margin-top: 6px;
  color: #6380a5;
  font-size: 13px;
  line-height: 1.65;
}

.room-detail__pending-card ul,
.room-detail__reservation-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.room-detail__pending-card li {
  display: grid;
  gap: 3px;
  padding: 8px 0;
  border-top: 1px dashed #d3e0f3;
  font-size: 13px;
}

.room-detail__pending-card li:first-child {
  padding-top: 0;
  border-top: 0;
}

.room-detail__pending-card li span,
.room-detail__reservation-list li span {
  color: #78879b;
}

.room-detail__section {
  padding: 20px 0;
  border-bottom: 1px solid #edf1f5;
}

.room-detail__section:first-of-type {
  padding-top: 0;
}

.room-detail__section-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 13px;
}

.room-detail__section-header h3 {
  color: #243044;
  font-size: 15px;
}

.room-detail__section-header span {
  color: #8491a3;
  font-size: 12px;
}

.room-detail__service-card {
  display: grid;
  grid-template-columns: minmax(180px, .85fr) minmax(0, 1.4fr);
  gap: 18px;
  padding: 17px;
  border-radius: 12px;
  background: #f8fafc;
}

.room-detail__member {
  display: grid;
  align-content: start;
  gap: 5px;
}

.room-detail__member > span,
.room-detail__member small {
  color: #78869a;
  font-size: 12px;
}

.room-detail__member strong {
  color: #1f2d42;
  font-size: 18px;
}

.room-detail__service-meta,
.room-detail__info-list {
  display: grid;
  gap: 9px;
  margin: 0;
}

.room-detail__service-meta {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.room-detail__service-meta div,
.room-detail__info-list div {
  min-width: 0;
}

.room-detail__service-meta dt,
.room-detail__info-list dt {
  margin-bottom: 4px;
  color: #8794a7;
  font-size: 12px;
}

.room-detail__service-meta dd,
.room-detail__info-list dd {
  overflow-wrap: anywhere;
  margin: 0;
  color: #334155;
  font-size: 13px;
}

.room-detail__section--two-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 38px;
}

.room-detail__info-list div {
  display: grid;
  grid-template-columns: 82px minmax(0, 1fr);
  gap: 8px;
}

.room-detail__info-list dt {
  margin: 0;
}

.room-detail__rule {
  color: #53647a;
  font-size: 13px;
  line-height: 1.7;
}

.room-detail__rule--muted {
  margin-top: 8px;
  color: #8491a2;
}

.room-detail__reservation-list {
  display: grid;
  gap: 8px;
}

.room-detail__active-reservation-list {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.room-detail__active-reservation-list li {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) minmax(160px, .85fr) auto;
  gap: 12px;
  align-items: center;
  padding: 12px 14px;
  border: 1px solid #ffd9a8;
  border-radius: 9px;
  background: #fffaf1;
}

.room-detail__active-reservation-list li > div {
  display: grid;
  gap: 4px;
}

.room-detail__active-reservation-list li strong {
  color: #6e4c18;
}

.room-detail__active-reservation-list li span {
  color: #7f6a46;
  font-size: 12px;
}

.room-detail__reservation-list li {
  display: grid;
  grid-template-columns: minmax(130px, .7fr) minmax(0, 1.6fr) auto;
  gap: 12px;
  align-items: center;
  padding: 11px 13px;
  border-radius: 9px;
  background: #f8fafc;
  font-size: 13px;
}

.room-detail__reservation-list li strong {
  color: #344055;
}

.room-detail__reservation-list li em {
  padding: 3px 7px;
  border-radius: 999px;
  color: #6c7e94;
  background: #eaf0f7;
  font-size: 11px;
  font-style: normal;
}

.room-detail__empty-inline {
  padding: 13px 14px;
  border-radius: 9px;
  color: #8491a3;
  background: #f8fafc;
  font-size: 13px;
}

.room-detail__note {
  margin-top: 18px;
  padding: 11px 13px;
  border-left: 3px solid #aac5e7;
  color: #657890;
  background: #f8fbff;
  font-size: 13px;
  line-height: 1.65;
}

.room-detail__footer {
  align-items: center;
  border-top: 1px solid #edf1f5;
}

.room-detail__backend-tip,
.room-detail__action-error {
  max-width: 50%;
  margin: 0;
  color: #8390a2;
  font-size: 12px;
  line-height: 1.55;
}

.room-detail__action-error {
  color: #c44949;
}

.room-detail__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 9px;
}

.room-detail__button {
  min-height: 34px;
  padding: 7px 13px;
  border: 1px solid #d7e0eb;
  border-radius: 8px;
  color: #435269;
  background: #fff;
  font-size: 13px;
  cursor: pointer;
}

.room-detail__button--secondary:hover:not(:disabled) {
  border-color: #aabbd1;
  background: #f8fafc;
}

.room-detail__button--accent {
  border-color: #c7ddf6;
  color: #2769b4;
  background: #f1f7ff;
}

.room-detail__button--primary {
  border-color: #2d74c9;
  color: #fff;
  background: #2d74c9;
}

.room-detail__button--primary:hover:not(:disabled) {
  background: #1f63ad;
}

@keyframes room-detail-spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 720px) {
  .room-detail {
    padding: 12px;
  }

  .room-detail__dialog {
    max-height: calc(100vh - 24px);
    border-radius: 13px;
  }

  .room-detail__header,
  .room-detail__body,
  .room-detail__footer {
    padding-right: 17px;
    padding-left: 17px;
  }

  .room-detail__pending-card,
  .room-detail__section--two-columns,
  .room-detail__service-card {
    grid-template-columns: 1fr;
  }

  .room-detail__service-meta,
  .room-detail__active-reservation-list li,
  .room-detail__reservation-list li {
    grid-template-columns: 1fr;
  }

  .room-detail__footer {
    align-items: stretch;
    flex-direction: column;
  }

  .room-detail__backend-tip,
  .room-detail__action-error {
    max-width: none;
  }

  .room-detail__actions {
    justify-content: flex-start;
  }
}
</style>
