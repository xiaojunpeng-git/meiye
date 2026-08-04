<script setup>
import { computed, ref, watch } from 'vue'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'

/**
 * 预约计划房间或当前服务实际房间的分配／换房／移出弹层。
 *
 * 所有候选房间、可选状态、冲突提示与后续预约均由后端按当前账号权限和最新房态返回。
 * 本组件不推断房态、不计算冲突，也不在前端阻止后端允许的选择；保存时仅回传当前
 * 权威对象版本、原房间、目标房间和填写原因，后端必须再次执行权限、并发和占用校验。
 *
 * assignmentScope 必须由后端明确返回：
 * - reservation_plan：只调整预约计划房间，服务开始前不形成实际房态占用；
 * - active_service：调整已开始服务的实际房间占用。
 *
 * candidates 建议结构：
 * [{
 *   id | roomId, name | roomName, categoryName, statusLabel,
 *   selectable: true | false, disabledReason, conflictSummary,
 *   nextReservation: { startAt, memberName, summary } | string
 * }]
 *
 * request 事件：
 * {
 *   action: 'save-service-room-assignment',
 *   payload: {
 *     assignmentScope, reservationId, reservationVersion,
 *     serviceOrderId, serviceOrderVersion, currentRoomId,
 *     targetRoomId, reason, mode
 *   }
 * }
 */
const props = defineProps({
  assignmentScope: {
    type: String,
    required: true,
    validator: (value) => ['reservation_plan', 'active_service'].includes(value)
  },
  mode: {
    type: String,
    required: true,
    validator: (value) => ['assign', 'change', 'remove'].includes(value)
  },
  serviceOrder: {
    type: Object,
    default: () => ({})
  },
  reservation: {
    type: Object,
    default: () => ({})
  },
  currentRoom: {
    type: Object,
    default: () => ({})
  },
  candidates: {
    type: Array,
    default: () => []
  },
  isSubmitting: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close', 'request'])

const UNASSIGNED_TARGET = '__unassigned_room__'
const selectedTarget = ref(null)
const reason = ref('')
const validationMessage = ref('')
const assignmentDialogRef = ref(null)
const removeConfirmed = ref(false)

const normalizedMode = computed(() => ['assign', 'change', 'remove'].includes(props.mode) ? props.mode : '')
const normalizedAssignmentScope = computed(() => ['reservation_plan', 'active_service'].includes(props.assignmentScope)
  ? props.assignmentScope
  : '')
const isReservationPlan = computed(() => normalizedAssignmentScope.value === 'reservation_plan')
const serviceOrderId = computed(() => firstValue(props.serviceOrder, ['serviceOrderId', 'id', 'serviceSessionId', 'serviceNo']))
const serviceOrderVersion = computed(() => firstValue(props.serviceOrder, ['revision', 'recordVersion', 'version', 'serviceOrderVersion']))
const serviceOrderNo = computed(() => firstValue(props.serviceOrder, ['serviceOrderNo', 'no', 'code', 'serviceNo']) || '本次服务单')
const reservationId = computed(() => firstValue(props.reservation, ['reservationId', 'id', 'reservationNo']))
const reservationVersion = computed(() => firstValue(props.reservation, ['revision', 'recordVersion', 'version', 'reservationVersion']))
const reservationNo = computed(() => firstValue(props.reservation, ['reservationNo', 'no', 'code']) || '本次预约')
const assignmentSubjectId = computed(() => isReservationPlan.value ? reservationId.value : serviceOrderId.value)
const assignmentSubjectNo = computed(() => isReservationPlan.value ? reservationNo.value : serviceOrderNo.value)
const memberName = computed(() => firstValue(isReservationPlan.value ? props.reservation?.member : props.serviceOrder?.member, ['name', 'memberName', 'customerName'])
  || firstValue(isReservationPlan.value ? props.reservation : props.serviceOrder, ['memberName', 'customerName'])
  || firstValue(props.serviceOrder?.member, ['name', 'memberName', 'customerName'])
  || firstValue(props.serviceOrder, ['memberName', 'customerName'])
  || '—')
const currentRoomId = computed(() => firstValue(props.currentRoom, ['roomId', 'id'])
)
const currentRoomName = computed(() => firstValue(props.currentRoom, ['roomName', 'name', 'label'])
  || '待分配房间')
const normalizedCandidates = computed(() => (Array.isArray(props.candidates) ? props.candidates : [])
  .filter((candidate) => candidate && typeof candidate === 'object')
  .map((candidate, index) => ({
    raw: candidate,
    key: candidateKey(candidate, index),
    id: candidateId(candidate),
    name: candidateName(candidate),
    category: firstValue(candidate, ['categoryName', 'roomCategoryName', 'typeName', 'groupName']),
    status: firstValue(candidate, ['statusLabel', 'roomStatusLabel', 'statusName', 'status']),
    selectable: candidate.selectable === true,
    disabledReason: firstValue(candidate, ['disabledReason', 'reason', 'unavailableReason']),
    conflictSummary: firstValue(candidate, ['conflictSummary', 'conflictMessage', 'conflictHint']),
    nextReservation: candidate.nextReservation || candidate.upcomingReservation || candidate.nextAppointment || null
  })))
const hasSelection = computed(() => selectedTarget.value !== null)
const selectedTargetId = computed(() => selectedTarget.value === UNASSIGNED_TARGET ? null : selectedTarget.value?.id ?? null)
const isUnassignedSelected = computed(() => selectedTarget.value === UNASSIGNED_TARGET)
const isRemoveMode = computed(() => normalizedMode.value === 'remove')
const dialogTitle = computed(() => ({
  assign: '分配房间',
  change: '换房',
  remove: '移出房间'
}[normalizedMode.value] || '房间安排'))
const dialogDescription = computed(() => ({
  assign: isReservationPlan.value
    ? '请选择预约计划房间；服务开始前不会占用当前房态，也可以保留为待分配房间。'
    : '请选择服务实际使用的房间；也可以明确保留为待分配房间。',
  change: isReservationPlan.value
    ? '请选择新的预约计划房间；服务开始前不会占用当前房态。'
    : '请选择新的实际服务房间；保存时系统会按最新房态再次确认。',
  remove: isReservationPlan.value
    ? '移除后，本次预约会恢复为待分配房间，不影响预约本身。'
    : '移出后，本次服务会显示为待分配房间，不影响已开始的服务。'
}[normalizedMode.value] || '当前房间安排信息不完整，请关闭后重新打开。'))

watch(
  () => normalizedCandidates.value.map((candidate) => candidate.id).filter(Boolean).join('|'),
  () => {
    if (!selectedTarget.value || selectedTarget.value === UNASSIGNED_TARGET) return
    const selectedId = selectedTarget.value.id
    if (!normalizedCandidates.value.some((candidate) => String(candidate.id) === String(selectedId))) {
      selectedTarget.value = null
    }
  }
)
const primaryLabel = computed(() => {
  if (props.isSubmitting) return '正在保存…'
  if (isRemoveMode.value) return removeConfirmed.value ? '确认移出' : '移出房间'
  return normalizedMode.value === 'change' ? '确认换房' : '确认分配'
})

watch(
  [normalizedMode, normalizedAssignmentScope, assignmentSubjectId],
  () => resetDraft(),
  { immediate: true }
)

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return ''
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return ''
}

function candidateId(candidate) {
  return firstValue(candidate, ['roomId', 'id', 'value'])
}

function candidateKey(candidate, index) {
  return String(candidateId(candidate) || firstValue(candidate, ['code', 'roomCode', 'name', 'roomName']) || `candidate-${index}`)
}

function candidateName(candidate) {
  return firstValue(candidate, ['roomName', 'name', 'label']) || '未命名房间'
}

function reservationText(reservation) {
  if (!reservation) return ''
  if (typeof reservation === 'string') return reservation
  if (typeof reservation !== 'object') return String(reservation)
  return [
    firstValue(reservation, ['startAt', 'appointmentStartAt', 'reservationStartAt', 'time']),
    firstValue(reservation, ['memberName', 'customerName', 'name']),
    firstValue(reservation, ['summary', 'projectSummary', 'projectName'])
  ].filter(Boolean).join(' · ')
}

function resetDraft() {
  selectedTarget.value = null
  reason.value = ''
  validationMessage.value = ''
  removeConfirmed.value = false
}

function selectCandidate(candidate) {
  if (props.isSubmitting || !candidate.selectable) return
  selectedTarget.value = candidate
  validationMessage.value = ''
}

function selectUnassigned() {
  if (props.isSubmitting) return
  selectedTarget.value = UNASSIGNED_TARGET
  validationMessage.value = ''
}

function submit() {
  validationMessage.value = ''

  if (!normalizedMode.value || !normalizedAssignmentScope.value || !assignmentSubjectId.value) {
    validationMessage.value = isReservationPlan.value
      ? '未找到本次预约，请关闭后重新打开。'
      : '未找到本次服务单，请关闭后重新打开。'
    return
  }

  if (isRemoveMode.value) {
    if (!currentRoomId.value) {
      validationMessage.value = '当前没有已分配房间，无需移出。'
      return
    }
    if (!removeConfirmed.value) {
      removeConfirmed.value = true
      return
    }
  } else if (!hasSelection.value) {
    validationMessage.value = '请选择房间，或选择“待分配房间”。'
    return
  } else if (!isUnassignedSelected.value && !selectedTargetId.value) {
    validationMessage.value = '所选房间信息不完整，请关闭后重新打开。'
    return
  }

  emit('request', {
    action: 'save-service-room-assignment',
    payload: {
      assignmentScope: normalizedAssignmentScope.value,
      reservationId: reservationId.value || null,
      reservationVersion: reservationVersion.value || null,
      serviceOrderId: serviceOrderId.value || null,
      serviceOrderVersion: serviceOrderVersion.value || null,
      currentRoomId: currentRoomId.value || null,
      targetRoomId: isRemoveMode.value ? null : selectedTargetId.value,
      reason: reason.value.trim() || null,
      mode: normalizedMode.value
    }
  })
}

function requestClose() {
  if (props.isSubmitting) return
  emit('close')
}

useModalFocusTrap({
  containerRef: assignmentDialogRef,
  canClose: () => !props.isSubmitting,
  onClose: requestClose
})
</script>

<template>
  <Teleport to="body">
    <div class="room-assignment" role="presentation" @click.self="requestClose">
      <section ref="assignmentDialogRef" class="room-assignment__dialog" role="dialog" aria-modal="true" aria-labelledby="room-assignment-title" tabindex="-1">
      <header class="room-assignment__header">
        <div>
          <span class="room-assignment__eyebrow">{{ assignmentSubjectNo }}</span>
          <h2 id="room-assignment-title">{{ dialogTitle }}</h2>
          <p>{{ dialogDescription }}</p>
        </div>
        <button type="button" class="room-assignment__close" :disabled="isSubmitting" aria-label="关闭房间安排" @click="requestClose">×</button>
      </header>

      <main class="room-assignment__body">
        <dl class="room-assignment__context">
          <div><dt>会员</dt><dd>{{ memberName }}</dd></div>
          <div><dt>{{ isReservationPlan ? '预约计划房间' : '当前服务房间' }}</dt><dd>{{ currentRoomName }}</dd></div>
        </dl>

        <template v-if="!isRemoveMode">
          <section class="room-assignment__section">
            <header class="room-assignment__section-header">
              <div>
                <h3>选择房间</h3>
                <p>灰色房间暂不可选，原因以系统返回为准。</p>
              </div>
              <span v-if="normalizedCandidates.length">共 {{ normalizedCandidates.length }} 个</span>
            </header>

            <button
              type="button"
              class="room-assignment__unassigned"
              :class="{ 'room-assignment__unassigned--selected': isUnassignedSelected }"
              :disabled="isSubmitting"
              @click="selectUnassigned"
            >
              <span class="room-assignment__choice-dot" :class="{ 'room-assignment__choice-dot--selected': isUnassignedSelected }" aria-hidden="true" />
              <span>
                <strong>待分配房间</strong>
                <small>{{ isReservationPlan ? '暂不安排计划房间，预约仍可正常保存。' : '暂不指定实际房间，服务仍可继续。' }}</small>
              </span>
            </button>

            <div v-if="normalizedCandidates.length" class="room-assignment__candidate-list">
              <button
                v-for="candidate in normalizedCandidates"
                :key="candidate.key"
                type="button"
                class="room-assignment__candidate"
                :class="{
                  'room-assignment__candidate--selected': selectedTarget?.key === candidate.key,
                  'room-assignment__candidate--disabled': !candidate.selectable
                }"
                :disabled="isSubmitting || !candidate.selectable"
                :title="candidate.disabledReason || candidate.conflictSummary || ''"
                @click="selectCandidate(candidate)"
              >
                <span class="room-assignment__choice-dot" :class="{ 'room-assignment__choice-dot--selected': selectedTarget?.key === candidate.key }" aria-hidden="true" />
                <span class="room-assignment__candidate-main">
                  <strong>{{ candidate.name }}</strong>
                  <small>{{ [candidate.category, candidate.status].filter(Boolean).join(' · ') || '房间信息由系统提供' }}</small>
                </span>
                <span class="room-assignment__candidate-note">
                  <span v-if="candidate.disabledReason || candidate.conflictSummary">{{ candidate.disabledReason || candidate.conflictSummary }}</span>
                  <span v-else-if="reservationText(candidate.nextReservation)">下个预约：{{ reservationText(candidate.nextReservation) }}</span>
                  <span v-else-if="candidate.selectable">可选择</span>
                  <span v-else>暂不可选</span>
                </span>
              </button>
            </div>
            <div v-else class="room-assignment__empty">暂无可展示房间；如无需指定房间，可选择“待分配房间”。</div>
          </section>
        </template>

        <section v-else class="room-assignment__remove-panel">
          <strong>{{ isReservationPlan ? '确认移除预约计划房间' : '确认移出当前服务房间' }}</strong>
          <p>
            {{ isReservationPlan
              ? '移除后预约将显示为“待分配房间”，不会影响预约状态，也不会产生实际房态占用。'
              : '移出后服务单将显示为“待分配房间”，不会在前端判断服务、预约或房态冲突，保存时由系统再次确认。' }}
          </p>
          <p v-if="removeConfirmed" class="room-assignment__remove-confirmed" role="status">请再次点击“确认移出”完成操作。</p>
        </section>

        <section class="room-assignment__reason-section">
          <label for="room-assignment-reason">原因（选填）</label>
          <textarea
            id="room-assignment-reason"
            v-model="reason"
            :disabled="isSubmitting"
            maxlength="200"
            rows="3"
            placeholder="例如：会员希望更换房间"
          />
        </section>

        <p v-if="validationMessage" class="room-assignment__validation" role="alert">{{ validationMessage }}</p>
        <p v-else class="room-assignment__backend-tip">
          {{ isReservationPlan
            ? '保存前，系统会按当前权限、预约版本和完整预约时段再次校验；计划房间不会提前占用当前房态。'
            : '保存前，系统会按当前权限、服务单版本和最新房态再次校验。' }}
        </p>
      </main>

      <footer class="room-assignment__footer">
        <button type="button" class="room-assignment__button room-assignment__button--secondary" :disabled="isSubmitting" @click="requestClose">取消</button>
        <button type="button" class="room-assignment__button room-assignment__button--primary" :disabled="isSubmitting" @click="submit">{{ primaryLabel }}</button>
      </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.room-assignment {
  position: fixed;
  z-index: 1280;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(15 23 42 / 48%);
}

.room-assignment__dialog {
  display: flex;
  flex-direction: column;
  width: min(760px, 100%);
  max-height: min(760px, calc(100vh - 48px));
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 24px 70px rgb(15 23 42 / 24%);
}

.room-assignment__header,
.room-assignment__footer {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 26px;
}

.room-assignment__header {
  border-bottom: 1px solid #edf1f5;
}

.room-assignment__eyebrow {
  display: block;
  margin-bottom: 4px;
  color: #8290a3;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .06em;
}

.room-assignment h2,
.room-assignment h3,
.room-assignment p {
  margin: 0;
}

.room-assignment h2 {
  color: #1d2a3d;
  font-size: 23px;
}

.room-assignment__header p {
  margin-top: 7px;
  color: #748298;
  font-size: 13px;
  line-height: 1.6;
}

.room-assignment__close {
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

.room-assignment__body {
  flex: 1;
  overflow: auto;
  padding: 22px 26px 26px;
}

.room-assignment__context {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin: 0 0 20px;
}

.room-assignment__context div {
  padding: 12px 14px;
  border-radius: 10px;
  background: #f7f9fc;
}

.room-assignment__context dt {
  color: #8592a5;
  font-size: 12px;
}

.room-assignment__context dd {
  margin: 4px 0 0;
  overflow: hidden;
  color: #2b3749;
  font-size: 14px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.room-assignment__section {
  padding: 2px 0 22px;
}

.room-assignment__section-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 12px;
}

.room-assignment__section-header h3,
.room-assignment__reason-section label {
  color: #263348;
  font-size: 15px;
}

.room-assignment__section-header p {
  margin-top: 4px;
  color: #8290a3;
  font-size: 12px;
}

.room-assignment__section-header > span {
  flex: none;
  color: #8290a3;
  font-size: 12px;
}

.room-assignment__unassigned,
.room-assignment__candidate {
  display: grid;
  width: 100%;
  border: 1px solid #dde5ee;
  border-radius: 11px;
  color: inherit;
  background: #fff;
  text-align: left;
  cursor: pointer;
}

.room-assignment__unassigned {
  grid-template-columns: auto minmax(0, 1fr);
  gap: 11px;
  align-items: center;
  padding: 13px 14px;
  background: #fbfcfe;
}

.room-assignment__unassigned strong,
.room-assignment__candidate strong {
  display: block;
  color: #28364a;
  font-size: 14px;
}

.room-assignment__unassigned small,
.room-assignment__candidate small {
  display: block;
  margin-top: 3px;
  color: #8290a3;
  font-size: 12px;
  line-height: 1.45;
}

.room-assignment__unassigned--selected,
.room-assignment__candidate--selected {
  border-color: #377cc7;
  background: #f1f7ff;
  box-shadow: inset 0 0 0 1px #377cc7;
}

.room-assignment__choice-dot {
  width: 15px;
  height: 15px;
  border: 1px solid #b6c2d1;
  border-radius: 50%;
  background: #fff;
}

.room-assignment__choice-dot--selected {
  border: 4px solid #2e73c0;
}

.room-assignment__candidate-list {
  display: grid;
  gap: 9px;
  margin-top: 10px;
}

.room-assignment__candidate {
  grid-template-columns: auto minmax(0, 1fr) minmax(150px, .8fr);
  gap: 11px;
  align-items: center;
  padding: 13px 14px;
}

.room-assignment__candidate-main {
  min-width: 0;
}

.room-assignment__candidate-note {
  color: #667990;
  font-size: 12px;
  line-height: 1.45;
  text-align: right;
}

.room-assignment__candidate--disabled {
  border-color: #e5e9ef;
  color: #96a1b0;
  background: #f6f7f9;
  cursor: not-allowed;
}

.room-assignment__candidate--disabled strong,
.room-assignment__candidate--disabled small,
.room-assignment__candidate--disabled .room-assignment__candidate-note {
  color: #96a1b0;
}

.room-assignment__candidate:disabled,
.room-assignment__unassigned:disabled,
.room-assignment__button:disabled,
.room-assignment__close:disabled {
  cursor: not-allowed;
  opacity: .62;
}

.room-assignment__empty {
  margin-top: 10px;
  padding: 18px;
  border: 1px dashed #d8e0ea;
  border-radius: 10px;
  color: #77869a;
  font-size: 13px;
  text-align: center;
}

.room-assignment__remove-panel {
  padding: 17px;
  border: 1px solid #f0d6bb;
  border-radius: 12px;
  color: #7e4c1d;
  background: #fff8f0;
}

.room-assignment__remove-panel strong {
  font-size: 15px;
}

.room-assignment__remove-panel p {
  margin-top: 7px;
  color: #956a3a;
  font-size: 13px;
  line-height: 1.65;
}

.room-assignment__remove-confirmed {
  color: #b15313 !important;
  font-weight: 700;
}

.room-assignment__reason-section {
  display: grid;
  gap: 8px;
  margin-top: 20px;
}

.room-assignment__reason-section textarea {
  box-sizing: border-box;
  width: 100%;
  resize: vertical;
  min-height: 76px;
  padding: 10px 12px;
  border: 1px solid #dbe3ed;
  border-radius: 9px;
  color: #2b3749;
  font: inherit;
  font-size: 13px;
  line-height: 1.5;
  outline: none;
}

.room-assignment__reason-section textarea:focus {
  border-color: #4a85c9;
  box-shadow: 0 0 0 3px rgb(74 133 201 / 12%);
}

.room-assignment__validation,
.room-assignment__backend-tip {
  margin-top: 14px !important;
  font-size: 12px;
  line-height: 1.55;
}

.room-assignment__validation {
  color: #bb3f3f;
}

.room-assignment__backend-tip {
  color: #758499;
}

.room-assignment__footer {
  align-items: center;
  padding-top: 18px;
  padding-bottom: 20px;
  border-top: 1px solid #edf1f5;
}

.room-assignment__footer::before {
  content: '房间分配以保存后的系统结果为准';
  color: #8492a4;
  font-size: 12px;
}

.room-assignment__button {
  min-width: 100px;
  padding: 10px 15px;
  border: 1px solid transparent;
  border-radius: 9px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}

.room-assignment__button--secondary {
  margin-left: auto;
  border-color: #dbe3ed;
  color: #56667b;
  background: #fff;
}

.room-assignment__button--primary {
  color: #fff;
  background: #2e73c0;
}

@media (max-width: 640px) {
  .room-assignment {
    padding: 12px;
  }

  .room-assignment__header,
  .room-assignment__footer,
  .room-assignment__body {
    padding-right: 18px;
    padding-left: 18px;
  }

  .room-assignment__candidate {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .room-assignment__candidate-note {
    grid-column: 2;
    text-align: left;
  }

  .room-assignment__context {
    grid-template-columns: 1fr;
  }

  .room-assignment__footer::before {
    display: none;
  }
}
</style>
